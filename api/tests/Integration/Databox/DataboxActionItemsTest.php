<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Bootstrap;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

/**
 * New messages raise items in the dashboard action feed; a message from the tax office is a high severity item.
 */
final class DataboxActionItemsTest extends DataboxIntegrationTestCase
{
    private CrmAggregationService $crm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crm = Bootstrap::buildApp()->getContainer()->get(CrmAggregationService::class);
    }

    /** @return array<string, array<string,mixed>> items of the data boxes, keyed by type */
    private function databoxItems(): array
    {
        $items = [];
        foreach ($this->crm->actionItems($this->supplierId, $this->userId, null, 'admin', true)['items'] as $item) {
            if (str_starts_with((string) $item['type'], 'databox_')) {
                $items[$item['type']] = $item;
            }
        }
        return $items;
    }

    private function sync(int $boxId): void
    {
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_sent'));
        $this->isds->always('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->always('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'receipt'));
        $this->sync->syncBox($this->storedBox($boxId));
    }

    public function testNoBoxNoItems(): void
    {
        self::assertSame([], $this->databoxItems());
    }

    public function testAMessageFromTheTaxOfficeIsAHighSeverityItemAndOthersAreCountedSeparately(): void
    {
        $box = $this->connectBox();
        $this->sync($box['id']);

        $items = $this->databoxItems();
        self::assertSame('high', $items['databox_tax_office']['severity']);
        self::assertSame(1, $items['databox_tax_office']['count']);
        self::assertSame('/databox?box=' . $box['id'] . '&filter=tax_office', $items['databox_tax_office']['link']);
        self::assertStringContainsString('kontrolní hlášení', $items['databox_tax_office']['hint']);
        self::assertSame('medium', $items['databox_unread']['severity']);
        self::assertSame(2, $items['databox_unread']['count'], 'the two other received messages');
        self::assertArrayNotHasKey('databox_sync_error', $items);
    }

    public function testAcknowledgingClearsTheItems(): void
    {
        $box = $this->connectBox();
        $this->sync($box['id']);

        $tax = $this->messages->findByDmId($box['id'], 'received', '4101001');
        $this->messageService->acknowledge($this->supplierId, $tax['id'], $this->userId);
        self::assertArrayNotHasKey('databox_tax_office', $this->databoxItems());

        $this->messageService->acknowledgeAll($this->supplierId, $box['id'], $this->userId);
        self::assertSame([], $this->databoxItems());
    }

    public function testAnInactiveBoxRaisesNothing(): void
    {
        $box = $this->connectBox();
        $this->sync($box['id']);
        $this->service->update($this->supplierId, $box['id'], ['is_active' => false]);
        self::assertSame([], $this->databoxItems());
    }

    public function testABrokenSyncIsReportedSoTheOwnerFixesTheLogin(): void
    {
        $box = $this->connectBox();
        $this->isds->queue('GetListOfReceivedMessages', file_get_contents(DataboxFixtures::path('http_401_bad_password.html')), 401);
        $this->sync->syncBox($this->storedBox($box['id']));

        $items = $this->databoxItems();
        self::assertArrayHasKey('databox_sync_error', $items);
        self::assertStringContainsString('Test box', $items['databox_sync_error']['hint']);
    }

    public function testAnExpiringPasswordIsReportedAWeekAhead(): void
    {
        $box = $this->connectBox();
        self::assertArrayNotHasKey('databox_password_expiry', $this->databoxItems(), 'the fixture password expires in 2027');

        $soon = (new \DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s');
        $this->db->pdo()->prepare('UPDATE databox_credentials SET password_expires_at = ? WHERE databox_id = ?')->execute([$soon, $box['id']]);
        $item = $this->databoxItems()['databox_password_expiry'];
        self::assertSame('medium', $item['severity']);
        self::assertStringContainsString(substr($soon, 0, 10), $item['hint']);
    }

    public function testOnlyAdminsSeeTheDataBoxItems(): void
    {
        $box = $this->connectBox();
        $this->sync($box['id']);
        foreach (['accountant', 'readonly', null] as $role) {
            $types = array_column($this->crm->actionItems($this->supplierId, $this->userId, null, $role, true)['items'], 'type');
            self::assertSame([], array_filter($types, static fn (string $t): bool => str_starts_with($t, 'databox_')), (string) $role);
        }
    }

    public function testANonInteractiveCallerNeverSeesTheDataBoxItemsEvenAsAdmin(): void
    {
        $box = $this->connectBox();
        $this->sync($box['id']);
        self::assertNotSame([], $this->databoxItems(), 'precondition: the session view does list them');

        $types = array_column($this->crm->actionItems($this->supplierId, $this->userId, null, 'admin')['items'], 'type');
        self::assertSame([], array_filter($types, static fn (string $t): bool => str_starts_with($t, 'databox_')));
    }

    public function testTheItemsCanBeDismissedLikeTheOthers(): void
    {
        $box = $this->connectBox();
        $this->sync($box['id']);
        $this->crm->dismissActionItem($this->supplierId, $this->userId, 'databox_tax_office', 'day');
        try {
            self::assertArrayNotHasKey('databox_tax_office', $this->databoxItems());
            self::assertArrayHasKey('databox_unread', $this->databoxItems());
        } finally {
            $this->crm->restoreActionItem($this->supplierId, $this->userId, 'databox_tax_office');
        }
    }

    public function testAnotherSuppliersMessagesDoNotAppear(): void
    {
        if ($this->otherSupplierId === 0) {
            self::markTestSkipped('The database has only one supplier.');
        }
        $box = $this->connectBox();
        $this->sync($box['id']);
        $items = [];
        foreach ($this->crm->actionItems($this->otherSupplierId, $this->userId, null, 'admin', true)['items'] as $item) {
            $items[] = $item['type'];
        }
        self::assertNotContains('databox_tax_office', $items);
    }
}
