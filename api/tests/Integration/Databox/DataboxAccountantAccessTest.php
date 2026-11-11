<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Action\Databox\DataboxBoxAction;
use MyInvoice\Action\Databox\DataboxEmailAction;
use MyInvoice\Action\Databox\DataboxMessageAction;
use MyInvoice\Action\Databox\DataboxSendAction;
use MyInvoice\Bootstrap;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Dashboard\AccountantDashboardService;
use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\EmailNotice\DataboxEmailSettingsService;
use MyInvoice\Service\Document\DocumentStorage;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;
use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures as F;
use MyInvoice\Tests\Support\Databox\ZfoFixtures;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The owner has two boxes - a business one he opens to the accountant and a personal one he does not. The accountant
 * role reads exactly the first, read-only; the second does not exist for her (404, never 403), in the service layer
 * as well as through the HTTP actions. Synthetic boxes and messages only.
 */
final class DataboxAccountantAccessTest extends DataboxIntegrationTestCase
{
    private DataboxBoxAction $boxAction;
    private DataboxMessageAction $messageAction;
    private DataboxSendAction $sendAction;
    private DataboxEmailAction $emailAction;
    private AccountantDashboardService $dashboard;

    /** @var array<string,mixed> */
    private array $business = [];
    /** @var array<string,mixed> */
    private array $personal = [];
    private int $businessMessage = 0;
    private int $businessStored = 0;
    private int $personalMessage = 0;
    private int $personalStored = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $container = Bootstrap::buildApp()->getContainer();
        $activity = $this->createStub(ActivityLogger::class);
        $ip = $this->createStub(IpMatcher::class);
        $ip->method('clientIpFromRequest')->willReturn('127.0.0.1');
        $this->boxAction = new DataboxBoxAction($activity, $ip, $this->service, $this->messageService, $this->sync, $this->client);
        $this->messageAction = new DataboxMessageAction($activity, $ip, $this->messageService, $this->zfoImport, new DocumentStorage(\MyInvoice\Infrastructure\Config\Config::load(dirname(__DIR__, 4))));
        $this->sendAction = new DataboxSendAction($activity, $ip, $this->sender, $this->messageService, $this->submissions);
        $this->emailAction = new DataboxEmailAction($activity, $ip, $container->get(DataboxEmailSettingsService::class));
        $this->dashboard = $container->get(AccountantDashboardService::class);

        $this->business = $this->connectEmailBox(ZfoFixtures::boxId('tstbiz'), 'PFO');
        $this->personal = $this->connectEmailBox(ZfoFixtures::boxId('tstper'), 'FO');
        $this->businessMessage = $this->notice($this->business, '9100001', '2026-11-09 09:00:00', 'Výzva finančního úřadu');
        $this->businessStored = $this->notice($this->business, '9100002', '2026-11-08 09:00:00', 'Uložená zpráva', stored: true);
        $this->personalMessage = $this->notice($this->personal, '9200001', '2026-11-10 09:00:00', 'Soukromá zpráva');
        $this->personalStored = $this->notice($this->personal, '9200002', '2026-11-07 09:00:00', 'Soukromá uložená', stored: true);
    }

    /** A received message of the box; $stored = its signed ZFO (one attachment) is in the database. */
    private function notice(array $box, string $dmId, string $delivery, string $subject, bool $stored = false): int
    {
        $id = $this->messages->insertFromEmailNotice($box['id'], [
            'dm_id' => $dmId, 'sender_box_id' => F::SENDER_BOX, 'sender_name' => 'Synthetic Sender', 'sender_type' => null,
            'recipient_box_id' => $box['db_id'], 'recipient_name' => 'Synthetic Recipient', 'annotation' => $subject,
            'delivery_time' => new \DateTimeImmutable($delivery),
        ], new \DateTimeImmutable('2026-11-09 10:00:00'))['id'];
        if ($stored) {
            $this->messages->storeFile($id, 'zfo', DataboxFixtures::zfo($dmId, [['name' => "{$dmId}.pdf", 'mime' => 'application/pdf', 'bytes' => "%PDF-1.4 {$dmId}\n"]], $subject));
        }
        return $id;
    }

    private function open(array $box, bool $visible = true): void
    {
        $this->service->update($this->supplierId, $box['id'], ['accountant_visible' => $visible]);
    }

    private function request(string $method, string $uri, string $role, ?array $json = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        return $json !== null ? $request->withParsedBody($json) : $request;
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertNotFound(callable $call, string $message): void
    {
        try {
            $call();
        } catch (DataboxException $e) {
            self::assertSame('not_found', $e->errorCode);
            self::assertSame(404, $e->httpStatus, 'a hidden box must look exactly like a missing one');
            self::assertSame($message, $e->getMessage());
            return;
        }
        self::fail('the call was not answered with not_found');
    }

    // ---- the flag ----

    public function testABoxIsHiddenFromTheAccountantUntilTheOwnerOpensIt(): void
    {
        self::assertFalse($this->business['accountant_visible']);
        self::assertNotContains($this->business['id'], array_column($this->service->listForAccountant($this->supplierId), 'id'));
        self::assertFalse($this->storedBox($this->business['id'])['accountant_visible']);

        $updated = $this->service->update($this->supplierId, $this->business['id'], ['accountant_visible' => true]);

        self::assertTrue($updated['accountant_visible']);
        self::assertTrue($this->storedBox($this->business['id'])['accountant_visible']);
        self::assertTrue($this->boxes->hasAccountantVisible($this->supplierId));
        self::assertFalse($this->storedBox($this->personal['id'])['accountant_visible'], 'the other box is untouched');
    }

    public function testOnlyTheAdminCanChangeTheFlag(): void
    {
        foreach (['accountant', 'readonly'] as $role) {
            $response = $this->boxAction->update(
                $this->request('PUT', "/api/databox/boxes/{$this->personal['id']}", $role, ['accountant_visible' => true]),
                $this->response(),
                ['id' => (string) $this->personal['id']],
            );
            self::assertSame(403, $response->getStatusCode(), $role);
        }
        self::assertFalse($this->storedBox($this->personal['id'])['accountant_visible']);

        $response = $this->boxAction->update(
            $this->request('PUT', "/api/databox/boxes/{$this->personal['id']}", 'admin', ['accountant_visible' => true]),
            $this->response(),
            ['id' => (string) $this->personal['id']],
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->json($response)['accountant_visible']);
        self::assertTrue($this->storedBox($this->personal['id'])['accountant_visible']);
    }

    // ---- boxes ----

    public function testTheAccountantSeesOnlyTheVisibleBoxAndNeverItsLogins(): void
    {
        $this->open($this->business);

        $ids = array_column($this->service->listForAccountant($this->supplierId), 'id');
        self::assertContains($this->business['id'], $ids);
        self::assertNotContains($this->personal['id'], $ids);

        $response = $this->boxAction->list($this->request('GET', '/api/databox/boxes', 'accountant'), $this->response());
        self::assertSame(200, $response->getStatusCode());
        $boxes = array_column($this->json($response), null, 'id');
        self::assertArrayHasKey($this->business['id'], $boxes);
        self::assertArrayNotHasKey($this->personal['id'], $boxes);
        $view = $boxes[$this->business['id']];
        self::assertSame(['id', 'label', 'environment', 'connection_mode', 'db_id', 'db_type', 'owner_name', 'is_active', 'accountant_visible'], array_keys($view));
        self::assertTrue($view['accountant_visible']);
    }

    public function testTheLoginsOfAVisibleBoxAreNotEvenReadForTheAccountant(): void
    {
        $isds = $this->connectBox('user_info_list_only', 'user_info_reader');
        $this->open($isds);
        self::assertNotSame([], $this->storedBox($isds['id'])['credentials'], 'the box does have logins');

        $row = array_column($this->boxes->listAccountantVisible($this->supplierId), null, 'id')[$isds['id']];
        self::assertSame([], $row['credentials']);

        $body = (string) $this->boxAction->list($this->request('GET', '/api/databox/boxes', 'accountant'), $this->response())->getBody();
        foreach (['S3cret', 'password', 'sync-user', 'action-user', 'credentials', 'privileges'] as $secret) {
            self::assertStringNotContainsString($secret, $body);
        }
        $one = (string) $this->boxAction->get($this->request('GET', "/api/databox/boxes/{$isds['id']}", 'accountant'), $this->response(), ['id' => (string) $isds['id']])->getBody();
        foreach (['S3cret', 'password', 'sync-user', 'action-user', 'credentials', 'privileges'] as $secret) {
            self::assertStringNotContainsString($secret, $one);
        }
    }

    public function testTheAdminStillSeesEveryBoxWithTheFlag(): void
    {
        $this->open($this->business);

        $boxes = array_column($this->json($this->boxAction->list($this->request('GET', '/api/databox/boxes', 'admin'), $this->response())), null, 'id');

        self::assertTrue($boxes[$this->business['id']]['accountant_visible']);
        self::assertFalse($boxes[$this->personal['id']]['accountant_visible']);
        self::assertArrayHasKey('credentials', $boxes[$this->personal['id']]);
    }

    public function testAHiddenBoxIsA404ForTheAccountantEvenWhenItExists(): void
    {
        $this->open($this->business);

        $hidden = $this->boxAction->get($this->request('GET', "/api/databox/boxes/{$this->personal['id']}", 'accountant'), $this->response(), ['id' => (string) $this->personal['id']]);
        $missing = $this->boxAction->get($this->request('GET', '/api/databox/boxes/2147483000', 'accountant'), $this->response(), ['id' => '2147483000']);
        self::assertSame(404, $hidden->getStatusCode());
        self::assertSame((string) $missing->getBody(), (string) $hidden->getBody(), 'indistinguishable from a box that does not exist');

        $visible = $this->boxAction->get($this->request('GET', "/api/databox/boxes/{$this->business['id']}", 'accountant'), $this->response(), ['id' => (string) $this->business['id']]);
        self::assertSame(200, $visible->getStatusCode());
        self::assertSame($this->business['db_id'], $this->json($visible)['db_id']);

        $hiddenForAdmin = $this->boxAction->get($this->request('GET', "/api/databox/boxes/{$this->personal['id']}", 'admin'), $this->response(), ['id' => (string) $this->personal['id']]);
        self::assertSame(200, $hiddenForAdmin->getStatusCode());
    }

    // ---- messages (service layer) ----

    public function testTheCombinedMessageListIsScopedToTheVisibleBoxes(): void
    {
        $this->open($this->business);

        $accountant = array_column($this->messageService->list($this->supplierId, null, [], 1, 50, true)['items'], 'id');
        self::assertContains($this->businessMessage, $accountant);
        self::assertContains($this->businessStored, $accountant);
        self::assertNotContains($this->personalMessage, $accountant);
        self::assertNotContains($this->personalStored, $accountant);

        $admin = array_column($this->messageService->list($this->supplierId, null, [], 1, 50)['items'], 'id');
        self::assertContains($this->personalMessage, $admin, 'the owner is unaffected');
        self::assertContains($this->businessMessage, $admin);
    }

    public function testTheTotalOfTheCombinedListDoesNotCountHiddenMessages(): void
    {
        $this->open($this->business);

        $visible = $this->messageService->list($this->supplierId, $this->business['id'], [], 1, 50, true);
        $combined = $this->messageService->list($this->supplierId, null, [], 1, 50, true);

        self::assertSame(2, $visible['total']);
        self::assertGreaterThanOrEqual($visible['total'], $combined['total']);
        $search = $this->messageService->list($this->supplierId, null, ['search' => 'Soukromá'], 1, 50, true);
        self::assertSame(0, $search['total'], 'searching cannot reveal a hidden message either');
    }

    public function testAHiddenBoxAndItsMessagesAreNotFoundForTheAccountant(): void
    {
        $this->open($this->business);

        $this->assertNotFound(fn () => $this->messageService->list($this->supplierId, $this->personal['id'], [], 1, 50, true), 'Datová schránka nenalezena.');
        $this->assertNotFound(fn () => $this->messageService->detail($this->supplierId, $this->personalMessage, true), 'Zpráva nenalezena.');
        $this->assertNotFound(fn () => $this->messageService->attachment($this->supplierId, $this->personalStored, 0, true), 'Zpráva nenalezena.');
        $this->assertNotFound(fn () => $this->messageService->file($this->supplierId, $this->personalStored, 'zfo', true), 'Zpráva nenalezena.');
        // The very same answer a message that does not exist gets.
        $this->assertNotFound(fn () => $this->messageService->detail($this->supplierId, 2147483000, true), 'Zpráva nenalezena.');
    }

    public function testTheAccountantReadsADetailAnAttachmentAndTheZfoOfAVisibleBox(): void
    {
        $this->open($this->business);

        $detail = $this->messageService->detail($this->supplierId, $this->businessStored, true);
        self::assertSame('Uložená zpráva', $detail['annotation']);
        self::assertCount(1, $detail['attachments']);

        $attachment = $this->messageService->attachment($this->supplierId, $this->businessStored, 0, true);
        self::assertSame("%PDF-1.4 9100002\n", $attachment['bytes']);
        $zfo = $this->messageService->file($this->supplierId, $this->businessStored, 'zfo', true);
        self::assertSame('datova-zprava-9100002.zfo', $zfo['filename']);
        self::assertNotSame('', $zfo['bytes']);
    }

    public function testClosingABoxAgainHidesItsMessagesAtOnce(): void
    {
        $this->open($this->business);
        self::assertSame('Uložená zpráva', $this->messageService->detail($this->supplierId, $this->businessStored, true)['annotation']);

        $this->open($this->business, false);

        $this->assertNotFound(fn () => $this->messageService->detail($this->supplierId, $this->businessStored, true), 'Zpráva nenalezena.');
        self::assertNotContains($this->businessMessage, array_column($this->messageService->list($this->supplierId, null, [], 1, 50, true)['items'], 'id'));
    }

    public function testTheOtherSuppliersBoxesStayUnreachableEvenWhenFlagged(): void
    {
        if ($this->otherSupplierId === 0) {
            self::markTestSkipped('The database has only one supplier.');
        }
        $foreign = $this->connectEmailBox(ZfoFixtures::boxId('tstfrn'), 'PFO', $this->otherSupplierId);
        $foreignMessage = $this->notice($foreign, '9300001', '2026-11-09 09:00:00', 'Cizí');
        $this->service->update($this->otherSupplierId, $foreign['id'], ['accountant_visible' => true]);

        $this->assertNotFound(fn () => $this->service->requireBox($this->supplierId, $foreign['id'], true), 'Datová schránka nenalezena.');
        $this->assertNotFound(fn () => $this->messageService->detail($this->supplierId, $foreignMessage, true), 'Zpráva nenalezena.');
        self::assertNotContains($foreign['id'], array_column($this->service->listForAccountant($this->supplierId), 'id'));
    }

    // ---- messages (HTTP actions) ----

    public function testTheMessageRoutesAnswerTheAccountantForTheVisibleBoxOnly(): void
    {
        $this->open($this->business);
        $b = (string) $this->businessStored;
        $p = (string) $this->personalStored;

        $list = $this->messageAction->list($this->request('GET', '/api/databox/messages', 'accountant'), $this->response(), []);
        self::assertSame(200, $list->getStatusCode());
        self::assertNotContains($this->personalMessage, array_column($this->json($list)['items'], 'id'));

        $hiddenList = $this->messageAction->list($this->request('GET', "/api/databox/boxes/{$this->personal['id']}/messages", 'accountant'), $this->response(), ['id' => (string) $this->personal['id']]);
        self::assertSame(404, $hiddenList->getStatusCode());

        self::assertSame(200, $this->messageAction->get($this->request('GET', "/api/databox/messages/{$b}", 'accountant'), $this->response(), ['id' => $b])->getStatusCode());
        self::assertSame(404, $this->messageAction->get($this->request('GET', "/api/databox/messages/{$p}", 'accountant'), $this->response(), ['id' => $p])->getStatusCode());

        $attachment = $this->messageAction->attachment($this->request('GET', "/api/databox/messages/{$b}/attachments/0", 'accountant'), $this->response(), ['id' => $b, 'index' => '0']);
        self::assertSame(200, $attachment->getStatusCode());
        self::assertSame("%PDF-1.4 9100002\n", (string) $attachment->getBody());
        self::assertSame('application/octet-stream', $attachment->getHeaderLine('Content-Type'), 'never rendered inline');
        self::assertSame(404, $this->messageAction->attachment($this->request('GET', "/api/databox/messages/{$p}/attachments/0", 'accountant'), $this->response(), ['id' => $p, 'index' => '0'])->getStatusCode());

        self::assertSame(200, $this->messageAction->zfo($this->request('GET', "/api/databox/messages/{$b}/zfo", 'accountant'), $this->response(), ['id' => $b])->getStatusCode());
        $hiddenZfo = $this->messageAction->zfo($this->request('GET', "/api/databox/messages/{$p}/zfo", 'accountant'), $this->response(), ['id' => $p]);
        self::assertSame(404, $hiddenZfo->getStatusCode());
        self::assertStringNotContainsString('9200002', (string) $hiddenZfo->getBody());
    }

    public function testReadonlyUsersGetNothingEvenForAVisibleBox(): void
    {
        $this->open($this->business);
        $b = (string) $this->businessStored;
        $calls = [
            fn () => $this->boxAction->list($this->request('GET', '/api/databox/boxes', 'readonly'), $this->response()),
            fn () => $this->boxAction->get($this->request('GET', "/api/databox/boxes/{$this->business['id']}", 'readonly'), $this->response(), ['id' => (string) $this->business['id']]),
            fn () => $this->messageAction->list($this->request('GET', '/api/databox/messages', 'readonly'), $this->response(), []),
            fn () => $this->messageAction->get($this->request('GET', "/api/databox/messages/{$b}", 'readonly'), $this->response(), ['id' => $b]),
            fn () => $this->messageAction->attachment($this->request('GET', "/api/databox/messages/{$b}/attachments/0", 'readonly'), $this->response(), ['id' => $b, 'index' => '0']),
            fn () => $this->messageAction->zfo($this->request('GET', "/api/databox/messages/{$b}/zfo", 'readonly'), $this->response(), ['id' => $b]),
        ];
        foreach ($calls as $i => $call) {
            self::assertSame(403, $call()->getStatusCode(), "call {$i}");
        }
    }

    public function testTheAccountantCannotDoAnythingButRead(): void
    {
        $this->open($this->business);
        $id = (string) $this->businessMessage;
        $box = (string) $this->business['id'];
        $r = 'accountant';
        $writes = [
            'acknowledge' => fn () => $this->messageAction->acknowledge($this->request('POST', "/api/databox/messages/{$id}/acknowledge", $r, []), $this->response(), ['id' => $id]),
            'open' => fn () => $this->messageAction->open($this->request('POST', "/api/databox/messages/{$id}/open", $r, ['acknowledge_delivery' => true]), $this->response(), ['id' => $id]),
            'receipt' => fn () => $this->messageAction->refreshReceipt($this->request('POST', "/api/databox/messages/{$id}/receipt", $r, []), $this->response(), ['id' => $id]),
            'import ZFO' => fn () => $this->messageAction->importZfo($this->request('POST', '/api/databox/import-zfo', $r, []), $this->response()),
            'acknowledge all boxes' => fn () => $this->boxAction->acknowledgeAll($this->request('POST', '/api/databox/acknowledge-all', $r, []), $this->response(), []),
            'acknowledge one box' => fn () => $this->boxAction->acknowledgeAll($this->request('POST', "/api/databox/boxes/{$box}/acknowledge-all", $r, []), $this->response(), ['id' => $box]),
            'connect a box' => fn () => $this->boxAction->create($this->request('POST', '/api/databox/boxes', $r, []), $this->response()),
            'edit a box' => fn () => $this->boxAction->update($this->request('PUT', "/api/databox/boxes/{$box}", $r, ['label' => 'x', 'is_active' => false]), $this->response(), ['id' => $box]),
            'delete a box' => fn () => $this->boxAction->delete($this->request('DELETE', "/api/databox/boxes/{$box}?confirm_delete_messages=1", $r), $this->response(), ['id' => $box]),
            'verify' => fn () => $this->boxAction->verify($this->request('POST', "/api/databox/boxes/{$box}/verify", $r, []), $this->response(), ['id' => $box]),
            'sync' => fn () => $this->boxAction->syncNow($this->request('POST', "/api/databox/boxes/{$box}/sync", $r, []), $this->response(), ['id' => $box]),
            'lookup' => fn () => $this->boxAction->lookup($this->request('GET', "/api/databox/boxes/{$box}/lookup?q=abc", $r), $this->response(), ['id' => $box]),
            'set a login' => fn () => $this->boxAction->setCredential($this->request('PUT', "/api/databox/boxes/{$box}/credentials/sync", $r, ['username' => 'u', 'password' => 'p']), $this->response(), ['id' => $box, 'purpose' => 'sync']),
            'remove a login' => fn () => $this->boxAction->removeCredential($this->request('DELETE', "/api/databox/boxes/{$box}/credentials/action", $r), $this->response(), ['id' => $box, 'purpose' => 'action']),
            'prepare a send' => fn () => $this->sendAction->prepare($this->request('POST', '/api/databox/messages/prepare', $r, []), $this->response()),
            'send' => fn () => $this->sendAction->send($this->request('POST', '/api/databox/messages/send', $r, ['token' => 'x', 'confirmed' => true]), $this->response()),
            'e-mail notice settings' => fn () => $this->emailAction->get($this->request('GET', '/api/databox/email-settings', $r), $this->response()),
            'save e-mail notice settings' => fn () => $this->emailAction->update($this->request('PUT', '/api/databox/email-settings', $r, ['enabled' => true]), $this->response()),
            'scan the notice mailbox' => fn () => $this->emailAction->scan($this->request('POST', '/api/databox/email-settings/scan', $r, []), $this->response()),
        ];
        foreach ($writes as $what => $call) {
            self::assertSame(403, $call()->getStatusCode(), $what);
        }

        // Nothing changed and nothing was sent to ISDS.
        self::assertSame([], $this->isds->requests);
        self::assertNull($this->messages->find($this->businessMessage)['acknowledged_at'], 'the message is still unhandled');
        $stored = $this->storedBox($this->business['id']);
        self::assertSame('E-mail box', $stored['label']);
        self::assertTrue($stored['is_active']);
        self::assertTrue($stored['accountant_visible']);
    }

    public function testTheAdminIsUnchanged(): void
    {
        $id = (string) $this->personalMessage;

        $detail = $this->messageAction->get($this->request('GET', "/api/databox/messages/{$id}", 'admin'), $this->response(), ['id' => $id]);
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame('Soukromá zpráva', $this->json($detail)['annotation']);

        $ack = $this->messageAction->acknowledge($this->request('POST', "/api/databox/messages/{$id}/acknowledge", 'admin', []), $this->response(), ['id' => $id]);
        self::assertSame(200, $ack->getStatusCode());
        self::assertNotNull($this->messages->find($this->personalMessage)['acknowledged_at']);
    }

    // ---- dashboard card ----

    public function testTheDashboardHasNoDataboxCardUntilABoxIsOpened(): void
    {
        $card = $this->dashboard->build($this->supplierId, new \DateTimeImmutable('2026-11-11'), true, true)['databox'];

        self::assertNull($card);
        self::assertNull($this->dashboard->build($this->supplierId, new \DateTimeImmutable('2026-11-11'), true)['databox'], 'only asked for by the accountant role');
    }

    public function testTheDashboardCardCountsAndListsOnlyTheVisibleBoxes(): void
    {
        $this->open($this->business);
        $this->notice($this->business, '9100003', '2026-11-10 12:00:00', 'Nejnovější');
        $this->notice($this->business, '9100004', '2026-11-06 12:00:00', 'Starší');
        $this->messages->acknowledge($this->businessStored, $this->userId);

        $card = $this->dashboard->build($this->supplierId, new \DateTimeImmutable('2026-11-11'), true, true)['databox'];

        self::assertSame(3, $card['unhandled'], '4 messages of the visible box, one handled; the personal box is not counted');
        self::assertCount(AccountantDashboardService::DATABOX_LIMIT, $card['latest']);
        self::assertSame(['Nejnovější', 'Výzva finančního úřadu', 'Uložená zpráva'], array_column($card['latest'], 'annotation'), 'newest first, never a personal message');
        self::assertSame([true, true, false], array_column($card['latest'], 'unhandled'));
        self::assertSame([$this->business['id']], array_values(array_unique(array_column($card['latest'], 'databox_id'))));
        self::assertSame(['id', 'databox_id', 'received_at', 'sender_name', 'annotation', 'is_tax_office', 'unhandled'], array_keys($card['latest'][0]));
    }

    public function testTheDashboardCardIsNotBuiltForNonAccountants(): void
    {
        $this->open($this->business);

        self::assertNull($this->dashboard->build($this->supplierId, new \DateTimeImmutable('2026-11-11'), true)['databox']);

        $action = Bootstrap::buildApp()->getContainer()->get(\MyInvoice\Action\Dashboard\AccountantSummaryAction::class);
        foreach (['admin' => false, 'readonly' => false, 'accountant' => true] as $role => $hasCard) {
            $data = $this->json($action($this->request('GET', '/api/dashboard/accountant', $role), $this->response()));
            self::assertSame($hasCard, $data['databox'] !== null, $role);
        }
    }
}
