<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\UserInviteService;
use MyInvoice\Service\Auth\UserMfaMode;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Konsolidovaný admin endpoint pro users:
 *   GET    /api/admin/users           — list
 *   POST   /api/admin/users           — create
 *   PUT    /api/admin/users/{id}      — update
 *   DELETE /api/admin/users/{id}      — soft delete (is_active=0)
 *
 * Pro routovací jednoduchost máme jednu třídu se 4 metodami; routes mapují přímo.
 */
final class UserAdminAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly UserInviteService $invites,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $statement = $this->db->pdo()->query(
            'SELECT id, email, name, role, locale, is_active, mfa_mode, created_at, last_login_at
               FROM users ORDER BY id ASC'
        );
        if ($statement === false) {
            throw new \RuntimeException('Seznam uživatelů se nepodařilo načíst.');
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $pendingInvites = $this->invites->pendingExpiries();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['is_active'] = (bool) $r['is_active'];
            $r['invite_expires_at'] = $pendingInvites[$r['id']] ?? null;
            $r['invite_pending'] = $r['invite_expires_at'] !== null;
        }
        return Json::ok($response, $rows);
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $name  = trim((string) ($body['name'] ?? ''));
        $role  = (string) ($body['role'] ?? 'readonly');
        $locale = (string) ($body['locale'] ?? 'cs');
        $password = (string) ($body['password'] ?? '');
        $mfaMode = (string) ($body['mfa_mode'] ?? UserMfaMode::DEFAULT);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return Json::error($response, 'validation_failed', 'Neplatný email.', 400);
        if ($name === '') return Json::error($response, 'validation_failed', 'Jméno je povinné.', 400);
        if (!in_array($role, ['admin', 'accountant', 'readonly'], true)) return Json::error($response, 'validation_failed', 'Neplatná role.', 400);
        if (!in_array($locale, ['cs', 'en'], true)) return Json::error($response, 'validation_failed', 'Neplatný locale.', 400);
        if (($error = $this->validateMfaMode($response, $role, $mfaMode)) !== null) return $error;

        // No password = the user sets their own through an invitation link. Until then the account
        // carries a random password nobody knows (same as a hand-created account with a throwaway one).
        $withoutPassword = $password === '';
        if ($withoutPassword) {
            $password = bin2hex(random_bytes(24));
        }
        try {
            $this->hasher->validate($password);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $hash = $this->hasher->hash($password);
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO users (email, password_hash, name, role, locale, mfa_mode, is_active)
                 VALUES (?,?,?,?,?,?,1)'
            );
            $stmt->execute([$email, $hash, $name, $role, $locale, $mfaMode]);
        } catch (\PDOException $e) {
            if (str_contains((string) $e->getMessage(), 'uq_users_email')) {
                return Json::error($response, 'email_taken', 'Email je už registrovaný.', 409);
            }
            throw $e;
        }
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->log($request, 'user.created', $id, [
            'email' => $email, 'role' => $role, 'mfa_mode' => $mfaMode, 'without_password' => $withoutPassword,
        ]);
        if ($mfaMode !== UserMfaMode::DEFAULT) {
            $this->log($request, 'user.mfa_mode_changed', $id, ['from' => UserMfaMode::DEFAULT, 'to' => $mfaMode]);
        }
        return Json::ok($response, $this->fetchUser($id), 201);
    }

    /**
     * @param array<string,string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetchUser($id);
        if (!$row) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);

        $body = (array) ($request->getParsedBody() ?? []);
        $sets = [];
        $params = [];
        if (isset($body['name'])) {
            $sets[] = 'name = ?';
            $params[] = trim((string) $body['name']);
        }
        if (isset($body['role'])) {
            if (!in_array($body['role'], ['admin', 'accountant', 'readonly'], true)) {
                return Json::error($response, 'validation_failed', 'Neplatná role.', 400);
            }
            $sets[] = 'role = ?';
            $params[] = (string) $body['role'];
        }
        if (isset($body['locale'])) {
            if (!in_array($body['locale'], ['cs', 'en'], true)) {
                return Json::error($response, 'validation_failed', 'Neplatný locale.', 400);
            }
            $sets[] = 'locale = ?';
            $params[] = (string) $body['locale'];
        }
        if (isset($body['is_active'])) {
            $sets[] = 'is_active = ?';
            $params[] = ((bool) $body['is_active']) ? 1 : 0;
        }
        // Second-factor mode. An admin can never use the mailbox factor: an explicit request is refused,
        // and promoting an email-mode user to admin silently falls back to the default mode.
        $targetRole = isset($body['role']) ? (string) $body['role'] : (string) $row['role'];
        $targetMfaMode = (string) $row['mfa_mode'];
        if (isset($body['mfa_mode'])) {
            $targetMfaMode = (string) $body['mfa_mode'];
            if (($error = $this->validateMfaMode($response, $targetRole, $targetMfaMode)) !== null) return $error;
        } elseif (!UserMfaMode::isAllowedForRole($targetRole, $targetMfaMode)) {
            $targetMfaMode = UserMfaMode::DEFAULT;
        }
        $mfaModeChanged = $targetMfaMode !== (string) $row['mfa_mode'];
        if ($mfaModeChanged) {
            $sets[] = 'mfa_mode = ?';
            $params[] = $targetMfaMode;
        }
        if (!empty($body['password'])) {
            try {
                $this->hasher->validate((string) $body['password']);
            } catch (\InvalidArgumentException $e) {
                return Json::error($response, 'validation_failed', $e->getMessage(), 400);
            }
            $sets[] = 'password_hash = ?';
            $params[] = $this->hasher->hash((string) $body['password']);
        }
        if (empty($sets)) return Json::ok($response, $row);

        // Guard: nesmí dojít k odebrání posledního aktivního admina
        $willBeAdmin = isset($body['role']) ? ($body['role'] === 'admin') : ($row['role'] === 'admin');
        $willBeActive = isset($body['is_active']) ? (bool) $body['is_active'] : (bool) $row['is_active'];
        $isLosingAdminStatus = $row['role'] === 'admin' && $row['is_active'] && (!$willBeAdmin || !$willBeActive);
        if ($isLosingAdminStatus && $this->countActiveAdmins() <= 1) {
            return Json::error($response, 'last_admin', 'Nelze odebrat admin roli ani deaktivovat posledního aktivního admina.', 409);
        }

        $params[] = $id;
        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->pdo()->prepare($sql)->execute($params);
        // A session earned through the old mode (e.g. a strong session from an emailed code) must not outlive it.
        $mustRevokeSessions = !empty($body['password']) || $mfaModeChanged
            || (array_key_exists('is_active', $body) && !(bool) $body['is_active']);
        if ($mustRevokeSessions) {
            $this->sessions->destroyAllForUser($id);
        }
        $this->log($request, 'user.updated', $id, ['fields' => array_keys($body)]);
        if ($mfaModeChanged) {
            $this->log($request, 'user.mfa_mode_changed', $id, [
                'from' => (string) $row['mfa_mode'], 'to' => $targetMfaMode,
                'reason' => isset($body['mfa_mode']) ? 'admin_request' : 'role_change',
            ]);
        }
        return Json::ok($response, $this->fetchUser($id));
    }

    /**
     * @param array<string,string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->guard($request, $response)) !== null) return $error;
        $id = (int) ($args['id'] ?? 0);
        $row = $this->fetchUser($id);
        if (!$row) return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if ((int) ($user['id'] ?? 0) === $id) {
            return Json::error($response, 'self_delete_forbidden', 'Nelze smazat vlastní účet.', 409);
        }
        // Guard: poslední aktivní admin
        if ($row['role'] === 'admin' && $row['is_active'] && $this->countActiveAdmins() <= 1) {
            return Json::error($response, 'last_admin', 'Nelze deaktivovat posledního aktivního admina.', 409);
        }

        $stmt = $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
        $stmt->execute([$id]);
        $this->sessions->destroyAllForUser($id);
        $this->log($request, 'user.deactivated', $id, []);
        return Json::ok($response, ['deactivated' => true]);
    }

    private function countActiveAdmins(): int
    {
        $statement = $this->db->pdo()->query(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
        );
        if ($statement === false) {
            throw new \RuntimeException('Počet administrátorů se nepodařilo načíst.');
        }
        return (int) $statement->fetchColumn();
    }

    private function validateMfaMode(Response $response, string $role, string $mode): ?Response
    {
        if (!UserMfaMode::isValid($mode)) {
            return Json::error($response, 'validation_failed', 'Neplatný režim druhého ověření.', 400);
        }
        if (!UserMfaMode::isAllowedForRole($role, $mode)) {
            return Json::error($response, 'validation_failed', 'Správce nemůže používat druhé ověření kódem e-mailem.', 400);
        }
        return null;
    }

    private function guard(Request $request, Response $response): ?Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchUser(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, email, name, role, locale, is_active, mfa_mode, created_at, last_login_at
               FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['id'] = (int) $r['id'];
        $r['is_active'] = (bool) $r['is_active'];
        return $r;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0), 'user', $entityId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
