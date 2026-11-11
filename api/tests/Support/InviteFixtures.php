<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Mail\Mailer;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Shared scaffolding of the invitation / email-mode integration tests. Boots the real DI container
 * (real services, real DB) with the mailer replaced by a recording stub - no e-mail ever leaves - and
 * with optional config overrides. Every user it creates is synthetic and removed again in tearDown.
 */
trait InviteFixtures
{
    private const TEST_PASSWORD = 'Synthetic-test-password-42';
    private const TEST_IP = '127.0.0.1';

    /** @var array<string,string> bcrypt cost 12 is slow, hash each known test password once per process */
    private static array $testPasswordHash = [];

    private ContainerInterface $c;
    private Connection $db;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<array{template:string,locale:string,to:array<int,string>,vars:array<string,mixed>}> */
    private array $sentMails = [];

    /** When true the stubbed mailer throws, to exercise the failed-delivery path. */
    private bool $mailFails = false;

    /** Previous value of MYINVOICE_AUTH_REQUIRE_TOTP (false = was unset), restored in tearDown. */
    private string|false $previousRequireTotp = false;

    /**
     * Boots the app container. The MFA policy is forced through the same env switch a deployment would
     * use (legacy `auth.require_totp`, as production runs) BEFORE the container is built, because services
     * resolved while building it keep the config they were created with.
     */
    private function bootApp(bool $mfaRequired = true): void
    {
        $rootDir = dirname(__DIR__, 3);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing — test needs the database.');
        }
        $this->previousRequireTotp = getenv('MYINVOICE_AUTH_REQUIRE_TOTP');
        putenv('MYINVOICE_AUTH_REQUIRE_TOTP=' . ($mfaRequired ? '1' : '0'));
        try {
            $this->c = Bootstrap::buildApp()->getContainer();
            $this->c->set(Mailer::class, $this->recordingMailer());
            $this->db = $this->c->get(Connection::class);
            $this->db->pdo()->query('SELECT 1 FROM user_invites LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB unavailable or migration 9013 missing: ' . $e->getMessage());
        }
    }

    private function recordingMailer(): Mailer
    {
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendTemplate')->willReturnCallback(
            function (string $template, string $locale, array $to, array $vars): string {
                if ($this->mailFails) {
                    throw new \RuntimeException('SMTP down');
                }
                $this->sentMails[] = ['template' => $template, 'locale' => $locale, 'to' => $to, 'vars' => $vars];
                return '250 queued';
            }
        );
        return $mailer;
    }

    protected function tearDown(): void
    {
        putenv($this->previousRequireTotp === false
            ? 'MYINVOICE_AUTH_REQUIRE_TOTP'
            : 'MYINVOICE_AUTH_REQUIRE_TOTP=' . $this->previousRequireTotp);
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->userIds as $id) {
            $pdo->prepare('DELETE FROM activity_log WHERE (entity_type = ? AND entity_id = ?) OR user_id = ?')
                ->execute(['user', $id, $id]);
            $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM mfa_step_up_proofs WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM login_otps WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        $pdo->exec("DELETE FROM login_attempts WHERE email LIKE 'invite-test-%' OR email = 'invite-link'");
        $this->db->close();
    }

    /**
     * @return array{id:int,email:string,name:string,role:string,locale:string,mfa_mode:string}
     */
    private function makeUser(string $role = 'accountant', string $mfaMode = 'default', ?string $password = self::TEST_PASSWORD): array
    {
        $email = 'invite-test-' . bin2hex(random_bytes(5)) . '@example.invalid';
        $hash = $password === null
            ? password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT, ['cost' => 4]) // nobody knows it
            : (self::$testPasswordHash[$password] ??= $this->c->get(PasswordHasher::class)->hash($password));
        $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, mfa_mode, is_active) VALUES (?,?,?,?,?,?,1)'
        )->execute([$email, $hash, 'Test ' . ucfirst($role), $role, 'cs', $mfaMode]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;

        return ['id' => $id, 'email' => $email, 'name' => 'Test ' . ucfirst($role), 'role' => $role, 'locale' => 'cs', 'mfa_mode' => $mfaMode];
    }

    /** @return array{id:int,email:string,name:string,role:string,locale:string,mfa_mode:string} */
    private function makeAdmin(): array
    {
        return $this->makeUser('admin');
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, string $path, array $body = [], ?array $actor = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path, ['REMOTE_ADDR' => self::TEST_IP])
            ->withHeader('User-Agent', 'PHPUnit');
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        if ($actor !== null) {
            $request = $request
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withAttribute(AuthMiddleware::ATTR_USER, $actor);
        }
        return $request;
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function errorCode(ResponseInterface $response): ?string
    {
        $error = $this->json($response)['error'] ?? null;
        return is_array($error) ? ($error['code'] ?? null) : null;
    }

    /** Value of a cookie set by the response, or null. */
    private function cookie(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (str_starts_with($header, $name . '=')) {
                return explode(';', substr($header, strlen($name) + 1))[0];
            }
        }
        return null;
    }

    /** @return array{assurance_level:string,auth_method:string}|null */
    private function sessionRow(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT assurance_level, auth_method FROM sessions WHERE id = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<string> actions logged for the user entity, oldest first */
    private function loggedActions(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT action FROM activity_log WHERE entity_type = 'user' AND entity_id = ? ORDER BY id"
        );
        $stmt->execute([$userId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Raw token out of an `…/invite?token=…` link. */
    private function tokenFromLink(string $link): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        return (string) ($query['token'] ?? '');
    }
}
