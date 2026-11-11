<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * One-time invitation links ("set your own password") for new and existing users.
 *
 * The token is 32 random bytes (64 hex chars) handed out exactly once; only its SHA-256 is stored
 * and the raw value is never logged. A link is valid for TTL_DAYS and works once - issuing a new
 * invite for the same user invalidates every earlier unused one. All time comparisons are done by
 * the database clock (NOW()) so a PHP/DB timezone difference cannot shift the expiry.
 */
final class UserInviteService
{
    public const TTL_DAYS = 7;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * Issues a new invite and invalidates the user's previous unused ones.
     *
     * @return array{token:string,expires_at:string} raw token (shown once) and its expiry (DB time)
     */
    public function issue(int $userId, ?int $createdBy): array
    {
        $raw = bin2hex(random_bytes(32));
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Serialise concurrent invites of one user, otherwise two requests could each leave a live token.
            $lock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([$userId]);
            if ($lock->fetchColumn() === false) {
                throw new \DomainException('Uživatel neexistuje.');
            }
            $this->revokeUnused($userId);
            $pdo->prepare(
                'INSERT INTO user_invites (user_id, token_hash, expires_at, created_by)
                 VALUES (?, ?, NOW() + INTERVAL ' . self::TTL_DAYS . ' DAY, ?)'
            )->execute([$userId, hash('sha256', $raw), $createdBy]);
            $id = (int) $pdo->lastInsertId();
            $expires = $pdo->prepare('SELECT expires_at FROM user_invites WHERE id = ?');
            $expires->execute([$id]);
            $expiresAt = (string) $expires->fetchColumn();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['token' => $raw, 'expires_at' => $expiresAt];
    }

    public function link(string $rawToken): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/') . '/invite?token=' . $rawToken;
    }

    /** Invalidates every unused invite of the user. Returns the number of invalidated rows. */
    public function revokeUnused(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE user_invites SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * The (active) user a still-valid token belongs to, or null when the token is unknown, used,
     * expired or its user was deactivated. Read-only - does not consume the token.
     *
     * @return array<string,mixed>|null
     */
    public function peek(string $rawToken): ?array
    {
        if (!self::isTokenShape($rawToken)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT u.id, u.email, u.name, u.role, u.locale, u.totp_enabled, u.mfa_mode,
                    u.session_lock_after_minutes
               FROM user_invites i
               JOIN users u ON u.id = i.user_id
              WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > NOW() AND u.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([hash('sha256', $rawToken)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Atomically spends the token: sets the password, burns every unused invite of the user and
     * drops the credentials tied to the old password (trusted devices, pending email codes). Two
     * concurrent calls with the same token cannot both succeed (row lock).
     *
     * @return array<string,mixed>|null the user row, or null when the token is not (or no longer) valid
     */
    public function accept(string $rawToken, string $passwordHash): ?array
    {
        if (!self::isTokenShape($rawToken)) {
            return null;
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                'SELECT i.id, i.user_id
                   FROM user_invites i
                   JOIN users u ON u.id = i.user_id
                  WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > NOW() AND u.is_active = 1
                  LIMIT 1
                  FOR UPDATE'
            );
            $lock->execute([hash('sha256', $rawToken)]);
            $invite = $lock->fetch(\PDO::FETCH_ASSOC);
            if ($invite === false) {
                $pdo->rollBack();
                return null;
            }
            $userId = (int) $invite['user_id'];

            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$passwordHash, $userId]);
            $this->revokeUnused($userId);
            $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM login_otps WHERE user_id = ?')->execute([$userId]);

            $user = $pdo->prepare(
                'SELECT id, email, name, role, locale, totp_enabled, mfa_mode, session_lock_after_minutes
                   FROM users WHERE id = ?'
            );
            $user->execute([$userId]);
            $row = $user->fetch(\PDO::FETCH_ASSOC);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $row === false ? null : $row;
    }

    /**
     * Expiry of the newest still-valid invite per user, for the admin user list.
     *
     * @return array<int,string> user id => expires_at
     */
    public function pendingExpiries(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT user_id, MAX(expires_at) AS expires_at
               FROM user_invites
              WHERE used_at IS NULL AND expires_at > NOW()
              GROUP BY user_id'
        );
        $map = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $map[(int) $row['user_id']] = (string) $row['expires_at'];
        }
        return $map;
    }

    private static function isTokenShape(string $rawToken): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $rawToken) === 1;
    }
}
