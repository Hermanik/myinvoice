<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Middleware\ApiScopeMiddleware;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\RoleMiddleware;
use MyInvoice\Service\Cron\CronCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Structural guarantees of the data box feature that must not regress silently:
 * who can reach it, what the unattended jobs may call, that nothing sends on its own.
 */
final class DataboxSafetyTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';
    private const ROOT = __DIR__ . '/../../..';

    private function ok(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }

    /** @return array<string, array{string, string}> */
    public static function databoxRoutes(): array
    {
        return [
            'list boxes' => ['GET', '/api/databox/boxes'],
            'add a box' => ['POST', '/api/databox/boxes'],
            'messages of all boxes' => ['GET', '/api/databox/messages'],
            'acknowledge all boxes' => ['POST', '/api/databox/acknowledge-all'],
            'message detail' => ['GET', '/api/databox/messages/12'],
            'open a message' => ['POST', '/api/databox/messages/12/open'],
            'prepare a send' => ['POST', '/api/databox/messages/prepare'],
            'confirm a send' => ['POST', '/api/databox/messages/send'],
            'submission prefill' => ['GET', '/api/databox/submissions/3/prefill'],
            'e-mail notice settings' => ['GET', '/api/databox/email-settings'],
            'save e-mail notice settings' => ['PUT', '/api/databox/email-settings'],
            'scan the notice mailbox' => ['POST', '/api/databox/email-settings/scan'],
            'import signed ZFO files' => ['POST', '/api/databox/import-zfo'],
        ];
    }

    #[DataProvider('databoxRoutes')]
    public function testAnApiTokenCanNeverReachTheDataBoxRoutes(string $method, string $path): void
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read_write']);
        $response = (new ApiScopeMiddleware(new ResponseFactory()))->process($request, $this->ok());

        self::assertSame(403, $response->getStatusCode(), 'neither MCP nor any integration may read or send data box messages');
        self::assertStringContainsString('token_endpoint_forbidden', (string) $response->getBody());
    }

    #[DataProvider('databoxRoutes')]
    public function testOnlyAdminsReachTheDataBoxRoutes(string $method, string $path): void
    {
        $resolver = $this->createStub(\MyInvoice\Service\Tenant\SupplierAccessResolver::class);
        $resolver->method('resolve')->willReturn(new \MyInvoice\Service\Tenant\SupplierAccess(0, false, null));
        $middleware = new RoleMiddleware(new ResponseFactory(), $resolver);
        foreach (['readonly' => 403, 'accountant' => 403, 'admin' => 204] as $role => $expected) {
            $request = (new ServerRequestFactory())->createServerRequest($method, $path)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => $role]);
            self::assertSame($expected, $middleware->process($request, $this->ok())->getStatusCode(), "{$role} {$method} {$path}");
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function authMethods(): array
    {
        return ['browser session' => ['session', true], 'API token' => ['bearer', false], 'no method' => ['', false]];
    }

    /**
     * GET /api/v1/crm/action-items is open to API tokens; the data box items (who writes to the company's box, the tax
     * office above all) may only be built for a browser session.
     */
    #[DataProvider('authMethods')]
    public function testTheDashboardFeedAsksForDataBoxItemsOnlyForABrowserSession(string $method, bool $expected): void
    {
        $crm = $this->createMock(\MyInvoice\Service\Crm\CrmAggregationService::class);
        $crm->expects(self::once())->method('actionItems')
            ->with(self::anything(), self::anything(), null, 'admin', $expected)
            ->willReturn(['items' => [], 'total' => 0]);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/crm/action-items')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 1, 'role' => 'admin']);
        if ($method !== '') {
            $request = $request->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
        }
        (new \MyInvoice\Action\Crm\CrmDashboardAction($crm))->actionItems($request, (new ResponseFactory())->createResponse());
    }

    public function testTheAggregationBuildsDataBoxItemsOnlyForInteractiveAdmins(): void
    {
        $source = (string) file_get_contents(self::SRC . '/Service/Crm/CrmAggregationService.php');
        self::assertStringContainsString("if (\$userRole === 'admin' && \$interactive) {\n            foreach (\$this->databoxItems(", $source);
        self::assertSame(1, substr_count($source, '$this->databoxItems('), 'databoxItems() must have a single, gated call site');
    }

    public function testEveryDataBoxRouteIsRegisteredBehindTheDataboxPrefixOnly(): void
    {
        $routes = (string) file_get_contents(self::SRC . '/Routes.php');
        preg_match_all('/\$app->(?:get|post|put|delete)\s*\(\s*\'([^\']+)\'\s*,\s*\[Databox/', $routes, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $path) {
            self::assertStringStartsWith('/api/databox/', $path);
        }
    }

    public function testOnlyTheSendServiceCreatesMessagesAndOnlyOnConfirmation(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/Service/Databox/IsdsClient.php') || str_contains($path, '/Service/Databox/DataboxSendService.php')) {
                continue;
            }
            if (preg_match('/->createMessage\s*\(/', (string) file_get_contents($path)) === 1) {
                $offenders[] = $path;
            }
        }
        self::assertSame([], $offenders, 'createMessage() may only be called from DataboxSendService::send()');

        $send = (string) file_get_contents(self::SRC . '/Service/Databox/DataboxSendService.php');
        self::assertSame(1, substr_count($send, '->createMessage('));
        // The send path demands the flag, a token and consumes the staged payload before the ISDS call.
        self::assertLessThan(strpos($send, '->createMessage('), strpos($send, "'confirmation_required'"));
        self::assertLessThan(strpos($send, '->createMessage('), strpos($send, '$this->outbox->consume($token, $now)'));
    }

    public function testNoCronJobOrCliCanSend(): void
    {
        foreach (['cron-databox-sync.php', 'cron-databox-email-notices.php', 'databox-check.php'] as $script) {
            $source = (string) file_get_contents(self::SRC . '/../bin/' . $script);
            foreach (['createMessage', 'DataboxSendService', 'SubmissionDataboxService', 'downloadReceived', 'DataboxMessageService'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, "{$script} must stay read-only and must not reference {$forbidden}");
            }
        }
    }

    public function testTheCheckCliNeverListsReceivedMessagesOfAReadingLoginWithoutTheFlag(): void
    {
        $source = (string) file_get_contents(self::SRC . '/../bin/databox-check.php');
        self::assertStringContainsString('$privileges->deliversOnList() && !$allowDelivery', $source);
        self::assertLessThan(
            strpos($source, '$isds->listReceived('),
            strpos($source, '$privileges->deliversOnList() && !$allowDelivery'),
            'the delivery guard comes before the received list call',
        );
    }

    public function testTheSyncChecksTheDeliverySafetyBeforeItListsAnything(): void
    {
        $source = (string) file_get_contents(self::SRC . '/Service/Databox/DataboxSyncService.php');
        self::assertLessThan(strpos($source, '$this->window('), strpos($source, '$this->refusal($box)'));
        self::assertSame(1, substr_count($source, '$this->isds->listReceived('), 'a single received-list call site, behind the guard');
    }

    public function testTheSyncReadsTheLiveRightsBeforeItDecidesAnything(): void
    {
        $source = (string) file_get_contents(self::SRC . '/Service/Databox/DataboxSyncService.php');
        $fresh = strpos($source, '$box = $this->withFreshRights($box);');
        self::assertNotFalse($fresh);
        self::assertLessThan(strpos($source, '$this->refusal($box)'), $fresh, 'the cached rights are never what the refusal rests on');
        self::assertLessThan(strpos($source, '$this->window('), $fresh);
        self::assertSame(1, substr_count($source, '$this->service->refreshRights('), 'the rights are re-read with the read-only GetUserInfoFromLogin2 only');
        // ... and that call is the read-only one: nothing in it lists, downloads or sends.
        $service = (string) file_get_contents(self::SRC . '/Service/Databox/DataboxService.php');
        preg_match('/function refreshRights\(.*?\n    }\n/s', $service, $m);
        self::assertStringContainsString('$this->isds->userInfo(', $m[0] ?? '');
        self::assertDoesNotMatchRegularExpression('/->(list|download|create)\w*\(/', $m[0] ?? '');
    }

    public function testEveryIsdsCallGoesThroughTheOneWrapperThatRecordsRefusedLogins(): void
    {
        $callers = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/IsdsTransport\b|->transport->post\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $callers[] = basename($file->getPathname());
            }
        }
        sort($callers);
        // The interface, its Guzzle implementation, the container wiring and the only caller.
        self::assertSame(['Bootstrap.php', 'GuzzleIsdsTransport.php', 'IsdsClient.php', 'IsdsTransport.php'], $callers);

        $client = (string) file_get_contents(self::SRC . '/Service/Databox/IsdsClient.php');
        self::assertSame(1, substr_count($client, '->transport->post('), 'a single choke point');
        self::assertLessThan(strpos($client, '($access->onAuthFailure)($e)'), strpos($client, '->transport->post('));
        self::assertStringContainsString('if ($e->isAuthFailure() && $access->onAuthFailure !== null)', $client);

        // A stored login is only ever handed out by accessFor(), which binds the hook and honours the flag.
        $service = (string) file_get_contents(self::SRC . '/Service/Databox/DataboxService.php');
        self::assertSame(1, substr_count($service, '->decrypt('), 'one place decrypts a stored password');
        self::assertStringContainsString('fn (IsdsException $e) => $this->registerAuthFailure($boxId, $purpose, $e)', $service);
        self::assertStringContainsString('if (!$ignoreFlag && $this->isFlagged($cred)) {', $service);
        self::assertSame(1, substr_count($service, 'accessFor($box, $purpose, true)'), 'only the connection test may ignore the flag');
        $constructing = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC . '/Service/Databox', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains((string) file_get_contents($file->getPathname()), 'new IsdsAccess(')) {
                $constructing[] = $file->getFilename();
            }
        }
        self::assertSame(['DataboxService.php'], $constructing, 'every other service gets its access from DataboxService::accessFor()');
    }

    public function testTheFilingBridgeGoesThroughTheFilingServiceAndTouchesOnlyItsOwnFilings(): void
    {
        $source = (string) file_get_contents(self::SRC . '/Service/Databox/SubmissionFilingBridge.php');
        foreach (['markFiled(', 'clearFiling(', 'setReceipt(', 'clearReceipt(', 'setFiledAt('] as $write) {
            self::assertStringNotContainsString('$this->submissions->' . $write, $source, 'filing state changes only through TaxSubmissionFilingService');
        }
        self::assertStringNotContainsString('IsdsClient', $source, 'the bridge never talks to ISDS');
        // A filing is only followed by the message that created it: channel data_box and its message number as the reference.
        self::assertStringContainsString("\$submission['filing_channel'] === 'data_box' && (string) \$submission['filing_reference'] === (string) \$message['dm_id']", $source);
    }

    public function testTheDatabaseDumpOfTheBackupCanCarryAWholeSignedMessage(): void
    {
        $source = (string) file_get_contents(self::SRC . '/../bin/cron-backup.php');
        self::assertSame(1, substr_count($source, 'escapeshellcmd($tool)'), 'a single dump command');
        self::assertStringContainsString('--single-transaction --quick --max-allowed-packet=512M', $source, 'a ZFO is one LONGBLOB row of up to ~26 MB');
    }

    public function testTheBackgroundJobIsInTheCronCatalogWithBothWrappers(): void
    {
        self::assertContains('cron-databox-sync', CronCatalog::scripts());
        foreach (['api/bin/cron-databox-sync.php', 'cmd/cron-databox-sync.sh', 'cmd/cron-databox-sync.cmd'] as $file) {
            self::assertFileExists(self::ROOT . '/' . $file);
        }
        self::assertTrue(is_executable(self::ROOT . '/cmd/cron-databox-sync.sh'));
    }

    public function testTheEmailWatcherIsInTheCronCatalogWithBothWrappers(): void
    {
        self::assertContains('cron-databox-email-notices', CronCatalog::scripts());
        foreach (['api/bin/cron-databox-email-notices.php', 'cmd/cron-databox-email-notices.sh', 'cmd/cron-databox-email-notices.cmd'] as $file) {
            self::assertFileExists(self::ROOT . '/' . $file);
        }
        self::assertTrue(is_executable(self::ROOT . '/cmd/cron-databox-email-notices.sh'));
    }

    /**
     * A box watched by notification e-mails is never logged in to: nothing under EmailNotice may reach ISDS or the logins,
     * and the mailbox is only read (no flag, no move, no "seen").
     */
    public function testTheEmailWatcherNeverTalksToIsdsAndNeverTouchesTheMailbox(): void
    {
        $files = array_merge(glob(self::SRC . '/Service/Databox/EmailNotice/*.php') ?: [], [self::SRC . '/../bin/cron-databox-email-notices.php']);
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['IsdsClient', 'IsdsAccess', 'IsdsTransport', 'accessFor', 'DataboxService', 'DataboxSendService', 'DataboxMessageService', 'createMessage', 'postProcess('] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, basename($file) . ' must not use ' . $forbidden);
            }
        }
        $client = (string) file_get_contents(self::SRC . '/Service/Bank/EmailNotice/WebklexImapMailboxClient.php');
        self::assertStringContainsString('->leaveUnread()', $client, 'the shared mailbox client reads without marking messages');
    }

    public function testTheEmailWatcherOnlyImportsAfterTheAuthenticityCheck(): void
    {
        $source = (string) file_get_contents(self::SRC . '/Service/Databox/EmailNotice/DataboxEmailNoticeScanner.php');
        self::assertSame(1, substr_count($source, '->insertFromEmailNotice('), 'a single place creates messages');
        self::assertLessThan(strpos($source, '->insertFromEmailNotice('), strpos($source, '$this->authenticator->verify('));
        self::assertLessThan(strpos($source, '->insertFromEmailNotice('), strpos($source, '!$auth->passed()'));
    }

    /**
     * The ZFO upload works on files the owner hands over: it never reaches ISDS, never uses a login and stores nothing before the
     * signature has verified. A tax filing is only ever recorded through the filing bridge, on a checksum match.
     */
    public function testTheZfoImportNeverTalksToIsdsAndStoresOnlyVerifiedFiles(): void
    {
        $files = array_merge(glob(self::SRC . '/Service/Databox/Zfo/*.php') ?: [], [self::SRC . '/Service/Databox/DataboxZfoImportService.php']);
        self::assertNotEmpty(glob(self::SRC . '/Service/Databox/Zfo/*.php'));
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['IsdsClient', 'IsdsAccess', 'IsdsTransport', 'accessFor', 'DataboxService', 'DataboxSendService', 'createMessage', 'downloadReceived'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, basename($file) . ' must not use ' . $forbidden);
            }
        }

        $import = (string) file_get_contents(self::SRC . '/Service/Databox/DataboxZfoImportService.php');
        $verify = strpos($import, '$this->verifier->verify(');
        self::assertNotFalse($verify);
        self::assertLessThan(strpos($import, '$this->store('), $verify, 'nothing is stored before the signature verified');
        self::assertSame(1, substr_count($import, '->upsertEnvelope('), 'a single place writes the envelope');
        self::assertSame(1, substr_count($import, '->filings->sent('), 'a filing is recorded only through the filing bridge');
        self::assertStringNotContainsString('->markFiled(', $import);
        self::assertStringContainsString('submissionsByXmlHash(', $import, 'the only evidence for a filing is the checksum of the archived XML');

        $verifier = (string) file_get_contents(self::SRC . '/Service/Databox/Zfo/ZfoSignatureVerifier.php');
        self::assertStringContainsString('openssl_cms_verify(', $verifier);
        self::assertStringContainsString('OPENSSL_ENCODING_DER', $verifier);
        self::assertStringNotContainsString('OPENSSL_CMS_NOSIGS', $verifier, 'the signature is always checked');
        self::assertStringNotContainsString('OPENSSL_CMS_NO_CONTENT_VERIFY', $verifier, 'so is the digest of the content');
    }

    public function testNoSecretIsLoggedOrReturned(): void
    {
        foreach (glob(self::SRC . '/Service/Databox/*.php') as $file) {
            $source = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/logger->\w+\([^;]*password/i', $source, basename($file) . ' must not log a password');
        }
        foreach (glob(self::SRC . '/Action/Databox/*.php') as $file) {
            self::assertStringNotContainsString("'password_enc'", (string) file_get_contents($file), basename($file));
        }
    }
}
