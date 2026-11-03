<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\MeAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\NavFavoritesService;
use MyInvoice\Service\Auth\SessionLockPolicy;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class MeActionTest extends TestCase
{
    public function testResponseContainsMfaAndAuthoritativeSessionState(): void
    {
        $supplierStatement = $this->createMock(\PDOStatement::class);
        $supplierStatement->expects(self::once())
            ->method('fetchAll')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn([]);
        $supplierStatement->expects(self::once())->method('execute')->with([])->willReturn(true);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())
            ->method('prepare')
            ->willReturn($supplierStatement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('hasColumn')->with('supplier', 'oss_enabled')->willReturn(true);
        $db->expects(self::once())->method('pdo')->willReturn($pdo);

        $credentials = $this->createMock(PasskeyCredentialRepository::class);
        $credentials->expects(self::once())
            ->method('countActiveForUser')
            ->with(17)
            ->willReturn(1);
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $config = new Config([
            'session' => ['lock_after_minutes' => 15],
            'auth' => [
                'require_mfa' => true,
                'require_totp' => false,
                'allowed_mfa_methods' => ['passkey', 'totp'],
            ],
        ]);
        // Uživatel role admin → membership se ani nedotazuje (vidí všechny firmy).
        $userSuppliers = $this->createMock(\MyInvoice\Repository\UserSupplierRepository::class);
        $userSuppliers->expects(self::never())->method('allowedSupplierIds');

        $action = new MeAction(
            $db,
            $config,
            $credentials,
            $userSuppliers,
            new MfaPolicyService($config),
            new SessionLockPolicy($config),
            $clock,
            new NavFavoritesService($this->favoritesConnection(17, '["/invoices","/stats"]')),
            $this->createStub(\MyInvoice\Service\Vat\VatStatusService::class),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/me')
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => 17,
                'email' => 'synthetic@example.invalid',
                'name' => 'Synthetic User',
                'role' => 'admin',
                'locale' => 'cs',
                'totp_enabled' => true,
                'session_lock_after_minutes' => 5,
            ])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, [
                'csrf_token' => str_repeat('b', 64),
                'assurance_level' => 'strong',
                'last_user_activity_at' => '2026-07-24 11:58:00.000000',
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);

        $response = $action($request, (new ResponseFactory())->createResponse());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['passkey', 'totp'], $body['user']['mfa_methods']);
        self::assertTrue($body['user']['mfa_enabled']);
        self::assertSame(1, $body['user']['passkey_count']);
        self::assertFalse($body['user']['must_setup_mfa']);
        self::assertTrue($body['require_mfa']);
        self::assertSame(['passkey', 'totp'], $body['allowed_mfa_methods']);
        self::assertSame('active', $body['session_state']);
        self::assertSame(5, $body['lock_after_minutes']);
        self::assertSame('2026-07-24T12:00:00.000000Z', $body['server_time']);
        self::assertSame('2026-07-24T12:03:00.000000Z', $body['idle_expires_at']);
        self::assertSame(str_repeat('b', 64), $body['csrf_token']);
        self::assertSame(['/invoices', '/stats'], $body['user']['nav_favorites']);
    }

    public function testSupplierCarriesTodaysVatFlagsAndTheDatedHistory(): void
    {
        // Cache columns still say "payer", but the history says non-payer today and payer from 2099.
        $supplierStatement = $this->createMock(\PDOStatement::class);
        $supplierStatement->method('execute')->willReturn(true);
        $supplierStatement->method('fetchAll')->willReturn([[
            'id' => '3', 'company_name' => 'Synthetic s.r.o.', 'ic' => null, 'is_vat_payer' => '1', 'is_identified' => '0',
            'oss_enabled' => '0', 'taxpayer_type' => null, 'default_payment_due_days' => '14',
            'default_payment_due_unit' => 'days', 'default_prices_include_vat' => '0', 'auto_send_reminders' => '1',
            'payment_thanks_enabled' => '0', 'payment_thanks_default_checked' => '0',
        ]]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($supplierStatement);
        $db = $this->createMock(Connection::class);
        $db->method('hasColumn')->willReturn(true);
        $db->method('pdo')->willReturn($pdo);

        $history = [
            ['valid_from' => '2000-01-01', 'status' => 'non_payer'],
            ['valid_from' => '2099-01-01', 'status' => 'payer'],
        ];
        $vatStatus = $this->createStub(\MyInvoice\Service\Vat\VatStatusService::class);
        $vatStatus->method('history')->willReturn(new \MyInvoice\Service\Vat\VatStatusHistory($history));

        $config = new Config(['session' => ['lock_after_minutes' => 0], 'auth' => ['allowed_mfa_methods' => ['totp']]]);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-24 12:00:00 UTC'));
        $credentials = $this->createStub(PasskeyCredentialRepository::class);
        $action = new MeAction(
            $db, $config, $credentials,
            $this->createStub(\MyInvoice\Repository\UserSupplierRepository::class),
            new MfaPolicyService($config), new SessionLockPolicy($config), $clock,
            new NavFavoritesService($this->favoritesConnection(17, '[]')),
            $vatStatus,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/me')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17, 'email' => 'synthetic@example.invalid', 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_SESSION, ['csrf_token' => 'x', 'assurance_level' => 'strong'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 3);

        $body = json_decode((string) $action($request, (new ResponseFactory())->createResponse())->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($body['suppliers'][0]['is_vat_payer'], 'today = non-payer per the history, not the stale cache');
        self::assertFalse($body['suppliers'][0]['is_identified']);
        self::assertSame($history, $body['suppliers'][0]['vat_history']);
    }

    private function favoritesConnection(int $userId, string $stored): Connection
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([$userId]);
        $statement->method('fetchColumn')->willReturn($stored);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('hasColumn')->with('users', 'nav_favorites')->willReturn(true);
        $db->method('pdo')->willReturn($pdo);

        return $db;
    }
}
