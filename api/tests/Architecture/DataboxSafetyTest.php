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
            'message detail' => ['GET', '/api/databox/messages/12'],
            'open a message' => ['POST', '/api/databox/messages/12/open'],
            'prepare a send' => ['POST', '/api/databox/messages/prepare'],
            'confirm a send' => ['POST', '/api/databox/messages/send'],
            'submission prefill' => ['GET', '/api/databox/submissions/3/prefill'],
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
        foreach (['cron-databox-sync.php', 'databox-check.php'] as $script) {
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

    public function testTheBackgroundJobIsInTheCronCatalogWithBothWrappers(): void
    {
        self::assertContains('cron-databox-sync', CronCatalog::scripts());
        foreach (['api/bin/cron-databox-sync.php', 'cmd/cron-databox-sync.sh', 'cmd/cron-databox-sync.cmd'] as $file) {
            self::assertFileExists(self::ROOT . '/' . $file);
        }
        self::assertTrue(is_executable(self::ROOT . '/cmd/cron-databox-sync.sh'));
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
