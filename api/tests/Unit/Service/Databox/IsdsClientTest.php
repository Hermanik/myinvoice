<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use MyInvoice\Service\Databox\IsdsAccess;
use MyInvoice\Service\Databox\IsdsClient;
use MyInvoice\Service\Databox\IsdsCredentials;
use MyInvoice\Service\Databox\IsdsEnvironment;
use MyInvoice\Service\Databox\IsdsException;
use MyInvoice\Service\Databox\IsdsTransportResponse;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\FakeIsdsTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsdsClientTest extends TestCase
{
    private FakeIsdsTransport $isds;
    private IsdsClient $client;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Prague');
        $this->isds = new FakeIsdsTransport();
        $this->client = new IsdsClient($this->isds);
    }

    private function access(string $environment = IsdsEnvironment::PRODUCTION): IsdsAccess
    {
        return new IsdsAccess($environment, new IsdsCredentials('sync-user', 'S3cret-pass!'));
    }

    public function testEveryOperationGoesToItsServiceEndpoint(): void
    {
        $this->isds
            ->queue('GetOwnerInfoFromLogin2', DataboxFixtures::xml('owner_info_pfo'))
            ->queue('GetListOfReceivedMessages', DataboxFixtures::xml('list_empty'))
            ->queue('SignedMessageDownload', DataboxFixtures::signedResponse('SignedMessageDownload', 'zfo'))
            ->queue('CreateMessage', DataboxFixtures::xml('create_message_ok'))
            ->queue('FindDataBox2', DataboxFixtures::xml('find_databox_found'));

        $a = $this->access();
        $this->client->ownerInfo($a);
        $this->client->listReceived($a, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
        $this->client->downloadReceived($a, '4101001');
        $this->client->createMessage($a, ['recipient_box_id' => '7nyn2d9', 'annotation' => 'x'], [['name' => 'a.txt', 'mime' => 'text/plain', 'meta_type' => 'main', 'bytes' => 'x']]);
        $this->client->findDataBox($a, ['db_id' => '7nyn2d9']);

        self::assertSame([
            'https://ws1.datovka.gov.cz/DS/DsManage',
            'https://ws1.datovka.gov.cz/DS/dx',
            'https://ws1.datovka.gov.cz/DS/dz',
            'https://ws1.datovka.gov.cz/DS/dz',
            'https://ws1.datovka.gov.cz/DS/df',
        ], array_column($this->isds->requests, 'url'));
    }

    public function testTestEnvironmentUsesTheNewTestDomain(): void
    {
        $this->isds->queue('GetOwnerInfoFromLogin2', DataboxFixtures::xml('owner_info_pfo'));
        $this->client->ownerInfo($this->access(IsdsEnvironment::TEST));
        self::assertSame('https://ws1.datovka-test.gov.cz/DS/DsManage', $this->isds->requests[0]['url']);
    }

    public function testCredentialsReachTheTransportButNeverTheRequestBody(): void
    {
        $this->isds->queue('GetPasswordInfo', DataboxFixtures::xml('password_info'));
        $this->client->passwordExpiry($this->access());
        self::assertSame('sync-user', $this->isds->requests[0]['username']);
        self::assertStringNotContainsString('S3cret-pass!', $this->isds->requests[0]['body']);
    }

    public function testListsAreReturnedAsEnvelopes(): void
    {
        $this->isds->queue('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $list = $this->client->listReceived($this->access(), new \DateTimeImmutable('-30 days'), new \DateTimeImmutable());
        self::assertCount(3, $list);
        self::assertSame(['4101001', '4101002', '4101003'], array_column($list, 'dm_id'));
    }

    public function testDeliveryInfoAndSignedReceipt(): void
    {
        $this->isds->queue('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->queue('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'sealed-receipt'));
        self::assertSame(6, $this->client->deliveryInfo($this->access(), '4102001')['status']);
        self::assertSame('sealed-receipt', $this->client->signedDeliveryInfo($this->access(), '4102001'));
    }

    public function testFindDataBoxKeepsTheInformationStatus(): void
    {
        $this->isds->queue('FindDataBox2', DataboxFixtures::xml('find_databox_none'));
        $r = $this->client->findDataBox($this->access(), ['name' => 'Neexistuje']);
        self::assertSame('0002', $r['status_code']);
        self::assertSame([], $r['boxes']);
    }

    /** @return array<string, array{string, int, string}> */
    public static function httpFailures(): array
    {
        return [
            'wrong password' => ['http_401_bad_password.html', 401, 'bad_credentials'],
            'locked account' => ['http_401_locked.html', 401, 'account_locked'],
            'blocked address' => ['http_401_ip_blocked.html', 401, 'ip_blocked'],
            'maintenance' => ['http_401_bad_password.html', 503, 'maintenance'],
            'gateway error' => ['http_401_bad_password.html', 502, 'server_error'],
            'teapot' => ['http_401_bad_password.html', 418, 'http_error'],
        ];
    }

    #[DataProvider('httpFailures')]
    public function testHttpErrorsAreMappedToReasons(string $fixture, int $status, string $reason): void
    {
        $this->isds->queue('GetListOfReceivedMessages', file_get_contents(DataboxFixtures::path($fixture)), $status);
        try {
            $this->client->listReceived($this->access(), new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($status, $e->httpStatus);
            self::assertStringNotContainsString('S3cret-pass!', $e->getMessage());
        }
    }

    /** @return array<string, array{string, \Closure(IsdsClient, IsdsAccess): mixed}> every ISDS operation the application can make */
    public static function everyOperation(): array
    {
        $from = new \DateTimeImmutable('-1 day');
        $to = new \DateTimeImmutable();
        $file = [['name' => 'a.txt', 'mime' => 'text/plain', 'meta_type' => 'main', 'bytes' => 'x']];
        return [
            'GetOwnerInfoFromLogin2' => ['GetOwnerInfoFromLogin2', static fn (IsdsClient $c, IsdsAccess $a) => $c->ownerInfo($a)],
            'GetUserInfoFromLogin2' => ['GetUserInfoFromLogin2', static fn (IsdsClient $c, IsdsAccess $a) => $c->userInfo($a)],
            'GetPasswordInfo' => ['GetPasswordInfo', static fn (IsdsClient $c, IsdsAccess $a) => $c->passwordExpiry($a)],
            'GetListOfReceivedMessages' => ['GetListOfReceivedMessages', static fn (IsdsClient $c, IsdsAccess $a) => $c->listReceived($a, $from, $to)],
            'GetListOfSentMessages' => ['GetListOfSentMessages', static fn (IsdsClient $c, IsdsAccess $a) => $c->listSent($a, $from, $to)],
            'GetDeliveryInfo' => ['GetDeliveryInfo', static fn (IsdsClient $c, IsdsAccess $a) => $c->deliveryInfo($a, '1')],
            'GetSignedDeliveryInfo' => ['GetSignedDeliveryInfo', static fn (IsdsClient $c, IsdsAccess $a) => $c->signedDeliveryInfo($a, '1')],
            'SignedMessageDownload' => ['SignedMessageDownload', static fn (IsdsClient $c, IsdsAccess $a) => $c->downloadReceived($a, '1')],
            'SignedSentMessageDownload' => ['SignedSentMessageDownload', static fn (IsdsClient $c, IsdsAccess $a) => $c->downloadSent($a, '1')],
            'CreateMessage' => ['CreateMessage', static fn (IsdsClient $c, IsdsAccess $a) => $c->createMessage($a, ['recipient_box_id' => '7nyn2d9', 'annotation' => 'x'], $file)],
            'FindDataBox2' => ['FindDataBox2', static fn (IsdsClient $c, IsdsAccess $a) => $c->findDataBox($a, ['db_id' => '7nyn2d9'])],
        ];
    }

    /** @param \Closure(IsdsClient, IsdsAccess): mixed $operation */
    #[DataProvider('everyOperation')]
    public function testARefusedLoginIsReportedToTheAccessHookByEveryOperation(string $name, \Closure $operation): void
    {
        $seen = [];
        $access = new IsdsAccess(IsdsEnvironment::PRODUCTION, new IsdsCredentials('sync-user', 'S3cret-pass!'), function (IsdsException $e) use (&$seen): void {
            $seen[] = $e->reason;
        });
        $this->isds->queue($name, (string) file_get_contents(DataboxFixtures::path('http_401_bad_password.html')), 401);

        try {
            $operation($this->client, $access);
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('bad_credentials', $e->reason);
        }
        self::assertSame(['bad_credentials'], $seen, 'exactly once, and the caller still gets the exception');
    }

    /** @return array<string, array{string, int, bool}> */
    public static function hookCases(): array
    {
        return [
            'wrong password' => ['http_401_bad_password.html', 401, true],
            'locked account' => ['http_401_locked.html', 401, true],
            'blocked address' => ['http_401_ip_blocked.html', 401, true],
            'maintenance' => ['http_401_bad_password.html', 503, false],
            'gateway error' => ['http_401_bad_password.html', 502, false],
            'other client error' => ['http_401_bad_password.html', 418, false],
        ];
    }

    #[DataProvider('hookCases')]
    public function testOnlyARefusedLoginReachesTheHook(string $fixture, int $status, bool $expected): void
    {
        $calls = 0;
        $access = new IsdsAccess(IsdsEnvironment::PRODUCTION, new IsdsCredentials('u', 'p'), function () use (&$calls): void {
            $calls++;
        });
        $this->isds->queue('GetListOfReceivedMessages', (string) file_get_contents(DataboxFixtures::path($fixture)), $status);
        try {
            $this->client->listReceived($access, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
        } catch (IsdsException) {
        }
        self::assertSame($expected ? 1 : 0, $calls);
    }

    public function testASuccessfulCallAndAMissingRightNeverReachTheHook(): void
    {
        $calls = 0;
        $access = new IsdsAccess(IsdsEnvironment::PRODUCTION, new IsdsCredentials('u', 'p'), function () use (&$calls): void {
            $calls++;
        });
        $this->isds->queue('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $this->isds->queue('GetListOfReceivedMessages', DataboxFixtures::xml('status_1004'));
        $this->client->listReceived($access, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
        try {
            $this->client->listReceived($access, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
            self::fail('expected the missing privilege');
        } catch (IsdsException $e) {
            self::assertSame('forbidden', $e->reason, 'a missing right is not a refused password');
        }
        self::assertSame(0, $calls);
    }

    public function testLockedAccountReportsUntilWhen(): void
    {
        $this->isds->queue('GetListOfReceivedMessages', file_get_contents(DataboxFixtures::path('http_401_locked.html')), 401);
        try {
            $this->client->listReceived($this->access(), new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('13:04:39', $e->blockedUntilTime);
            self::assertSame('2026-11-03 13:04:39', $e->blockedUntil(new \DateTimeImmutable('2026-11-03 12:00:00'))->format('Y-m-d H:i:s'));
            self::assertSame('2026-11-04 13:04:39', $e->blockedUntil(new \DateTimeImmutable('2026-11-03 14:00:00'))->format('Y-m-d H:i:s'), 'already past today: the next occurrence');
            self::assertStringContainsString('(do 13:04)', $e->getMessage());
            self::assertTrue($e->isAuthFailure());
            self::assertFalse($e->isTransient());
        }
    }

    public function testSoapFaultInAnHttp500IsReportedWithItsText(): void
    {
        $this->isds->queue('GetListOfReceivedMessages', DataboxFixtures::xml('soap_fault'), 500);
        try {
            $this->client->listReceived($this->access(), new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('server_error', $e->reason);
            self::assertStringContainsString('Internal processing error', $e->getMessage());
            self::assertTrue($e->isTransient());
        }
    }

    public function testDownloadOfAnUndeliveredMessageIsMappedToNotDelivered(): void
    {
        $this->isds->queue('SignedMessageDownload', DataboxFixtures::xml('status_1222'));
        try {
            $this->client->downloadReceived($this->access(), '4101001');
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('not_delivered', $e->reason);
            self::assertSame('1222', $e->isdsCode);
        }
    }

    public function testRightsErrorIsForbidden(): void
    {
        $this->isds->queue('GetListOfReceivedMessages', DataboxFixtures::xml('status_1004'));
        try {
            $this->client->listReceived($this->access(), new \DateTimeImmutable('-1 day'), new \DateTimeImmutable());
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('forbidden', $e->reason);
        }
    }

    public function testGarbageBodyWith200IsAnInvalidResponse(): void
    {
        $this->isds->queue('GetOwnerInfoFromLogin2', new IsdsTransportResponse(200, '<html>not soap</html>'));
        $this->expectException(IsdsException::class);
        $this->client->ownerInfo($this->access());
    }

    public function testCredentialsAreHiddenFromDumpsAndSerialization(): void
    {
        $credentials = new IsdsCredentials('user', 'S3cret-pass!');
        self::assertStringNotContainsString('S3cret-pass!', print_r($credentials, true));
        self::assertStringNotContainsString('S3cret-pass!', var_export($credentials, true));
        $this->expectException(\LogicException::class);
        serialize($credentials);
    }
}
