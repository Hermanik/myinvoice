<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Timeline\TimelineAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ObligationTimelineRepository;
use MyInvoice\Tests\Support\FilingFixture;
use MyInvoice\Tests\Support\TimelinePaymentsState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The HTTP layer of the obligations timeline: range validation, roles, manual marks and the CRUD of custom items.
 * Works with keys and dates of the year 2099; marks and custom items are removed in tearDown.
 */
#[Group('integration')]
final class TimelineActionTest extends TestCase
{
    private ?FilingFixture $fx = null;
    private ?TimelinePaymentsState $payments = null;
    private TimelineAction $action;
    private ObligationTimelineRepository $repo;
    /** @var list<int> */
    private array $customIds = [];
    private int $logWatermark = 0;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $this->repo = $this->fx->container->get(ObligationTimelineRepository::class);
        if (!$this->repo->isAvailable()) {
            self::markTestSkipped('Migrace 9011 (obligations timeline) není aplikovaná.');
        }
        $this->action = $this->fx->container->get(TimelineAction::class);
        if ($this->repo->hasPaymentTables()) {
            $this->payments = new TimelinePaymentsState($this->fx->pdo, $this->fx->supplierId);
            $this->payments->stash();
        }
        $this->logWatermark = (int) $this->fx->pdo->query('SELECT COALESCE(MAX(id), 0) FROM activity_log')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->fx !== null) {
            $sid = $this->fx->supplierId;
            $this->repo->deleteMark($sid, 'ins_social:2099-01');
            foreach ($this->customIds as $id) {
                $this->repo->deleteCustom($sid, $id);
            }
            $this->payments?->restore();
            $this->fx->pdo->prepare("DELETE FROM activity_log WHERE id > ? AND action LIKE 'timeline.%'")->execute([$this->logWatermark]);
            $this->fx->restore();
        }
    }

    private function request(string $method, string $role, ?array $body = null, array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/timeline')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->fx->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fx->userId, 'role' => $role])
            ->withQueryParams($query);
        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testListValidatesTheRangeAndKnowsTheDefault(): void
    {
        $ok = $this->action->list($this->request('GET', 'admin'), new Response());
        self::assertSame(200, $ok->getStatusCode());
        $body = $this->json($ok);
        self::assertSame(date('Y-m-d', strtotime('-30 days')), $body['from']);
        self::assertSame((date('Y') + 1) . '-12-31', $body['to']);
        self::assertArrayHasKey('by_status', $body['summary']);

        foreach ([
            ['from' => '2099-13-01'], ['to' => 'zítra'], ['from' => '2099-02-01', 'to' => '2099-01-01'],
            ['from' => '2090-01-01', 'to' => '2099-01-01'],
        ] as $query) {
            $bad = $this->action->list($this->request('GET', 'admin', null, $query), new Response());
            self::assertSame(422, $bad->getStatusCode(), json_encode($query));
            self::assertSame('validation_failed', $this->json($bad)['error']['code']);
        }
    }

    public function testReadonlyUserGetsNothingAndAccountantMayWork(): void
    {
        self::assertSame(403, $this->action->list($this->request('GET', 'readonly'), new Response())->getStatusCode());
        self::assertSame(403, $this->action->setMark($this->request('PUT', 'readonly', ['key' => 'ins_social:2099-01']), new Response())->getStatusCode());
        self::assertSame(403, $this->action->createCustom($this->request('POST', 'readonly', ['title' => 'x', 'due_date' => '2099-01-01']), new Response())->getStatusCode());

        self::assertSame(200, $this->action->list($this->request('GET', 'accountant'), new Response())->getStatusCode());
        self::assertSame(200, $this->action->setMark($this->request('PUT', 'accountant', ['key' => 'ins_social:2099-01']), new Response())->getStatusCode());
    }

    public function testMarkCanBeSetNotedAndCleared(): void
    {
        $key = 'ins_social:2099-01';
        $set = $this->json($this->action->setMark($this->request('PUT', 'admin', ['key' => $key, 'done' => true, 'note' => 'zaplaceno']), new Response()));
        self::assertTrue($set['mark']['done']);
        self::assertSame('zaplaceno', $set['mark']['note']);

        $noted = $this->json($this->action->setMark($this->request('PUT', 'admin', ['key' => $key, 'done' => false, 'note' => 'ověřit']), new Response()));
        self::assertFalse($noted['mark']['done']);

        $cleared = $this->action->clearMark($this->request('DELETE', 'admin', null, ['key' => $key]), new Response());
        self::assertSame(200, $cleared->getStatusCode());
        self::assertNull($this->json($cleared)['mark']);
        self::assertSame([], array_filter($this->repo->marks($this->fx->supplierId), static fn (string $k): bool => $k === $key, ARRAY_FILTER_USE_KEY));
    }

    public function testMarkKeyAndNoteAreValidated(): void
    {
        foreach (['', 'nokey', 'DPH:2099-01', 'custom:5', "dph:2099-01'; DROP", str_repeat('a', 130) . ':1'] as $key) {
            $bad = $this->action->setMark($this->request('PUT', 'admin', ['key' => $key]), new Response());
            self::assertSame(422, $bad->getStatusCode(), $key);
        }
        $long = $this->action->setMark($this->request('PUT', 'admin', ['key' => 'ins_social:2099-01', 'note' => str_repeat('x', 501)]), new Response());
        self::assertSame(422, $long->getStatusCode());
        self::assertSame(422, $this->action->clearMark($this->request('DELETE', 'admin', null, ['key' => 'x']), new Response())->getStatusCode());
    }

    public function testCustomItemLifecycle(): void
    {
        $created = $this->action->createCustom($this->request('POST', 'admin', [
            'title' => '  Oznámit klientům DPH  ', 'due_date' => '2099-03-01', 'start_date' => '2099-02-01',
            'category' => 'registration', 'note' => 'e-mailem', 'url' => '/clients',
        ]), new Response());
        self::assertSame(201, $created->getStatusCode());
        $item = $this->json($created);
        $this->customIds[] = $item['id'];
        self::assertSame('Oznámit klientům DPH', $item['title']);
        self::assertNull($item['done_at']);

        // Mark done: the stamp appears; an edit that does not touch the flag keeps it.
        $done = $this->json($this->action->updateCustom($this->request('PUT', 'accountant', ['done' => true]), new Response(), ['id' => (string) $item['id']]));
        self::assertNotNull($done['done_at']);
        self::assertSame($this->fx->userId, $this->repo->findCustom($this->fx->supplierId, $item['id'])['done_by_id']);
        $renamed = $this->json($this->action->updateCustom($this->request('PUT', 'admin', ['title' => 'Nový název']), new Response(), ['id' => (string) $item['id']]));
        self::assertSame('Nový název', $renamed['title']);
        self::assertSame($done['done_at'], $renamed['done_at']);
        self::assertSame('2099-03-01', $renamed['due_date'], 'missing fields keep their value');

        $reopened = $this->json($this->action->updateCustom($this->request('PUT', 'admin', ['done' => false]), new Response(), ['id' => (string) $item['id']]));
        self::assertNull($reopened['done_at']);

        self::assertSame(200, $this->action->deleteCustom($this->request('DELETE', 'admin'), new Response(), ['id' => (string) $item['id']])->getStatusCode());
        self::assertSame(404, $this->action->deleteCustom($this->request('DELETE', 'admin'), new Response(), ['id' => (string) $item['id']])->getStatusCode());
        self::assertSame(404, $this->action->updateCustom($this->request('PUT', 'admin', ['title' => 'x']), new Response(), ['id' => (string) $item['id']])->getStatusCode());
    }

    public function testCustomItemValidation(): void
    {
        $bad = $this->action->createCustom($this->request('POST', 'admin', [
            'title' => '', 'due_date' => '2099-02-30', 'start_date' => '2099-01-01', 'category' => 'nonsense',
            'url' => 'javascript:alert(1)', 'note' => str_repeat('x', 1001),
        ]), new Response());
        self::assertSame(422, $bad->getStatusCode());
        self::assertEqualsCanonicalizing(['title', 'due_date', 'category', 'url', 'note'], array_keys($this->json($bad)['error']['fields']));

        foreach (['//evil.example/x', '/\\evil.example', 'ftp://x', 'data:text/html,x'] as $url) {
            $res = $this->action->createCustom($this->request('POST', 'admin', ['title' => 'x', 'due_date' => '2099-03-01', 'url' => $url]), new Response());
            self::assertSame(422, $res->getStatusCode(), $url);
        }
        $afterDue = $this->action->createCustom($this->request('POST', 'admin', ['title' => 'x', 'due_date' => '2099-03-01', 'start_date' => '2099-03-02']), new Response());
        self::assertSame(422, $afterDue->getStatusCode());

        $ok = $this->action->createCustom($this->request('POST', 'admin', ['title' => 'x', 'due_date' => '2099-03-01', 'url' => 'https://example.com/a?b=1']), new Response());
        self::assertSame(201, $ok->getStatusCode());
        $this->customIds[] = $this->json($ok)['id'];
        self::assertSame('custom', $this->json($ok)['category'], 'the default category');
    }

    // ── standing orders and settings ────────────────────────────────────────

    private function requirePaymentTables(): void
    {
        if ($this->payments === null) {
            self::markTestSkipped('Migrace 9012 (timeline standing orders) není aplikovaná.');
        }
    }

    public function testStandingOrderLifecycle(): void
    {
        $this->requirePaymentTables();
        $created = $this->action->createOrder($this->request('POST', 'admin', [
            'type' => 'pausal', 'amount' => '9984.00', 'day_of_month' => 12, 'valid_from' => '2099-01-01', 'note' => '  Air Bank  ',
        ]), new Response());
        self::assertSame(201, $created->getStatusCode());
        $order = $this->json($created);
        self::assertEquals(['pausal', 9984.0, 12, '2099-01-01', null, 'Air Bank'], [$order['type'], $order['amount'], $order['day_of_month'], $order['valid_from'], $order['valid_to'], $order['note']]);

        $listed = $this->json($this->action->listOrders($this->request('GET', 'accountant'), new Response()))['items'];
        self::assertSame([$order], $listed);

        // An edit that sends only some fields keeps the others.
        $updated = $this->json($this->action->updateOrder($this->request('PUT', 'admin', ['amount' => 9162, 'valid_to' => '2099-12-31']), new Response(), ['id' => (string) $order['id']]));
        self::assertEquals([9162.0, 12, '2099-12-31', 'pausal'], [$updated['amount'], $updated['day_of_month'], $updated['valid_to'], $updated['type']]);

        self::assertSame(200, $this->action->deleteOrder($this->request('DELETE', 'admin'), new Response(), ['id' => (string) $order['id']])->getStatusCode());
        self::assertSame(404, $this->action->deleteOrder($this->request('DELETE', 'admin'), new Response(), ['id' => (string) $order['id']])->getStatusCode());
        self::assertSame(404, $this->action->updateOrder($this->request('PUT', 'admin', ['amount' => 1]), new Response(), ['id' => (string) $order['id']])->getStatusCode());
        self::assertSame([], $this->json($this->action->listOrders($this->request('GET', 'admin'), new Response()))['items']);
    }

    public function testStandingOrderValidation(): void
    {
        $this->requirePaymentTables();
        $bad = $this->action->createOrder($this->request('POST', 'admin', [
            'type' => 'rent', 'amount' => -5, 'day_of_month' => 32, 'valid_from' => '2099-02-30', 'note' => str_repeat('x', 256),
        ]), new Response());
        self::assertSame(422, $bad->getStatusCode());
        self::assertEqualsCanonicalizing(['type', 'amount', 'day_of_month', 'valid_from', 'note'], array_keys($this->json($bad)['error']['fields']));

        $valid = ['type' => 'insurance_health', 'amount' => 3306, 'day_of_month' => 31, 'valid_from' => '2099-01-01'];
        foreach ([['amount' => 0], ['amount' => 'abc'], ['amount' => 10000000], ['day_of_month' => 0], ['day_of_month' => 'x'], ['valid_to' => '2098-12-31'], ['valid_to' => 'soon']] as $override) {
            $res = $this->action->createOrder($this->request('POST', 'admin', $override + $valid), new Response());
            self::assertSame(422, $res->getStatusCode(), json_encode($override));
        }
        $ok = $this->action->createOrder($this->request('POST', 'admin', $valid + ['valid_to' => '2099-01-01']), new Response());
        self::assertSame(201, $ok->getStatusCode(), 'day 31 is allowed (the last day of a shorter month), the window may be one day');
        self::assertSame([], array_diff_key($this->json($ok), array_flip(['id', 'type', 'amount', 'day_of_month', 'valid_from', 'valid_to', 'note'])));
    }

    public function testStandingOrdersAndSettingsAreForAdminAndAccountantOnly(): void
    {
        $this->requirePaymentTables();
        $body = ['type' => 'pausal', 'amount' => 100, 'day_of_month' => 1, 'valid_from' => '2099-01-01'];
        self::assertSame(403, $this->action->listOrders($this->request('GET', 'readonly'), new Response())->getStatusCode());
        self::assertSame(403, $this->action->createOrder($this->request('POST', 'readonly', $body), new Response())->getStatusCode());
        self::assertSame(403, $this->action->getSettings($this->request('GET', 'readonly'), new Response())->getStatusCode());
        self::assertSame(403, $this->action->saveSettings($this->request('PUT', 'readonly', ['invoicing_due' => 'working_day_n']), new Response())->getStatusCode());
        self::assertSame([], $this->repo->standingOrders($this->fx->supplierId));
        self::assertSame(201, $this->action->createOrder($this->request('POST', 'accountant', $body), new Response())->getStatusCode());
    }

    public function testSettingsDefaultsAreSavedValidatedAndKeptWhenNotSent(): void
    {
        $this->requirePaymentTables();
        self::assertSame(['invoicing_due' => 'month_end', 'invoicing_working_day' => 5], $this->json($this->action->getSettings($this->request('GET', 'admin'), new Response())));

        $saved = $this->json($this->action->saveSettings($this->request('PUT', 'admin', ['invoicing_due' => 'working_day_n', 'invoicing_working_day' => 3]), new Response()));
        self::assertSame(['invoicing_due' => 'working_day_n', 'invoicing_working_day' => 3], $saved);

        $partial = $this->json($this->action->saveSettings($this->request('PUT', 'admin', ['invoicing_due' => 'month_end']), new Response()));
        self::assertSame(['invoicing_due' => 'month_end', 'invoicing_working_day' => 3], $partial, 'the working day is kept');

        foreach ([['invoicing_due' => 'never'], ['invoicing_working_day' => 0], ['invoicing_working_day' => 21], ['invoicing_working_day' => 'x']] as $bad) {
            $res = $this->action->saveSettings($this->request('PUT', 'admin', $bad), new Response());
            self::assertSame(422, $res->getStatusCode(), json_encode($bad));
        }
        self::assertSame(['invoicing_due' => 'month_end', 'invoicing_working_day' => 3], $this->repo->settings($this->fx->supplierId), 'nothing invalid was stored');
    }

    public function testTheTimelineCarriesTheSettingsAndTheEstimates(): void
    {
        $this->requirePaymentTables();
        $body = $this->json($this->action->list($this->request('GET', 'admin'), new Response()));

        self::assertSame(['invoicing_due' => 'month_end', 'invoicing_working_day' => 5], $body['settings']);
        self::assertIsArray($body['estimates']);
    }
}
