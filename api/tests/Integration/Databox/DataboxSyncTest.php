<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

final class DataboxSyncTest extends DataboxIntegrationTestCase
{
    /** Operations a delivery-safe sync may use: lists and delivery receipts, never a download / send. */
    private const SAFE_OPERATIONS = [
        'GetOwnerInfoFromLogin2', 'GetUserInfoFromLogin2', 'GetPasswordInfo',
        'GetListOfReceivedMessages', 'GetListOfSentMessages', 'GetDeliveryInfo', 'GetSignedDeliveryInfo',
    ];

    private function scriptMailbox(): void
    {
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_sent'));
        $this->isds->always('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->always('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'sealed-receipt'));
    }

    public function testStoresEnvelopesAndFlagsTheTaxOffice(): void
    {
        $box = $this->connectBox();
        $this->scriptMailbox();

        $report = $this->sync->syncBox($this->storedBox($box['id']), new \DateTimeImmutable('2026-11-03 10:00:00'));

        self::assertSame('ok', $report['status']);
        self::assertSame(3, $report['received_new']);
        self::assertSame(1, $report['received_new_tax_office']);
        self::assertSame(1, $report['sent_new']);

        $received = $this->messages->list($box['id'], ['direction' => 'received'], 50, 0);
        self::assertSame(3, $received['total']);
        $byDm = array_column($received['items'], null, 'dm_id');

        $tax = $byDm['4101001'];
        self::assertTrue($tax['is_tax_office']);
        self::assertSame('kh_call', $tax['tax_office_kind']);
        self::assertSame(4, $tax['status']);
        self::assertSame('2026-10-27 09:15:30', $tax['delivery_time']);
        self::assertNull($tax['acceptance_time']);
        self::assertNull($tax['acknowledged_at']);
        self::assertFalse($tax['has_zfo'], 'the sync never downloads content');

        self::assertFalse($byDm['4101002']['is_tax_office']);
        self::assertTrue($byDm['4101003']['personal_delivery']);

        $stored = $this->storedBox($box['id']);
        self::assertSame('ok', $stored['last_sync_status']);
        self::assertSame('2026-11-03 10:00:00', $stored['last_sync_at']);
    }

    public function testASecondRunUpdatesInsteadOfDuplicating(): void
    {
        $box = $this->connectBox();
        $this->scriptMailbox();
        $first = $this->sync->syncBox($this->storedBox($box['id']));
        $this->messages->acknowledge($this->messages->list($box['id'], ['direction' => 'received'], 50, 0)['items'][0]['id'], $this->userId);

        $second = $this->sync->syncBox($this->storedBox($box['id']));

        self::assertSame(3, $first['received_new']);
        self::assertSame(0, $second['received_new'], 'known messages are not new again');
        self::assertSame(0, $second['sent_new']);
        self::assertSame(3, $this->messages->list($box['id'], ['direction' => 'received'], 50, 0)['total']);
        self::assertSame(1, $this->messages->list($box['id'], ['direction' => 'sent'], 50, 0)['total']);
        $unread = $this->messages->list($box['id'], ['unread_only' => true], 50, 0);
        self::assertSame(2, $unread['total'], 'the acknowledgement survives a refresh');
    }

    public function testAStatusChangeIsPickedUpWithoutLosingTheKnownTimes(): void
    {
        $box = $this->connectBox();
        $this->scriptMailbox();
        $this->sync->syncBox($this->storedBox($box['id']));

        // The owner logged in to the portal: the tax office message is now delivered (state 6).
        $delivered = str_replace(
            ['<q:dmMessageStatus>4</q:dmMessageStatus>', '<q:dmAcceptanceTime xsi:nil="true"/>'],
            ['<q:dmMessageStatus>6</q:dmMessageStatus>', '<q:dmAcceptanceTime>2026-10-28T08:00:00.000+01:00</q:dmAcceptanceTime>'],
            DataboxFixtures::xml('list_received'),
        );
        $this->isds->always('GetListOfReceivedMessages', $delivered);
        $this->sync->syncBox($this->storedBox($box['id']));

        $tax = $this->messages->findByDmId($box['id'], 'received', '4101001');
        self::assertSame(6, $tax['status']);
        self::assertSame('2026-10-28 08:00:00', $tax['acceptance_time']);
        self::assertSame('2026-10-27 09:15:30', $tax['delivery_time']);
    }

    public function testASafeSyncUsesOnlyListAndReceiptOperations(): void
    {
        $box = $this->connectBox();
        $this->scriptMailbox();
        $this->forgetOperations();

        $this->sync->syncBox($this->storedBox($box['id']));

        self::assertGreaterThan(0, $this->isds->count('GetListOfReceivedMessages'));
        self::assertSame([], array_diff(array_unique($this->isds->operations()), self::SAFE_OPERATIONS), 'no download, no send, no search');
    }

    public function testFirstRunReadsBackFurtherThanLaterRuns(): void
    {
        $box = $this->connectBox();
        $this->scriptMailbox();
        $now = new \DateTimeImmutable('2026-11-03 10:00:00');
        $this->forgetOperations();

        $this->sync->syncBox($this->storedBox($box['id']), $now);
        $firstFrom = $this->firstFromTime();
        $this->forgetOperations();
        $this->sync->syncBox($this->storedBox($box['id']), $now->modify('+1 hour'));
        $laterFrom = $this->firstFromTime();

        self::assertStringStartsWith('2026-07-26T00:00:00', $firstFrom, '100 days before the first run');
        self::assertStringStartsWith('2026-09-29T00:00:00', $laterFrom, '35 days before a later run');
    }

    private function firstFromTime(): string
    {
        preg_match('#<p:dmFromTime>([^<]+)</p:dmFromTime>#', $this->isds->bodyOf('GetListOfReceivedMessages'), $m);
        return $m[1];
    }

    public function testAReadingLoginIsNeverUsedForTheSyncWithoutTheOptIn(): void
    {
        $box = $this->connectBox('user_info_reader');
        $this->scriptMailbox();
        $this->forgetOperations();

        $report = $this->sync->syncBox($this->storedBox($box['id']));

        self::assertSame('refused', $report['status']);
        self::assertStringContainsString('doručovala', $report['message']);
        self::assertSame([], $this->isds->requests, 'not a single ISDS call - listing would have delivered the messages');
        self::assertSame(0, $this->messages->list($box['id'], [], 50, 0)['total']);
        self::assertSame('error', $this->storedBox($box['id'])['last_sync_status']);
    }

    public function testTheOwnersPrimaryLoginIsRefusedToo(): void
    {
        $box = $this->connectBox('user_info_primary');
        $this->forgetOperations();
        self::assertSame('refused', $this->sync->syncBox($this->storedBox($box['id']))['status']);
        self::assertSame([], $this->isds->requests);
    }

    public function testAReadingLoginRunsAfterTheExplicitOptIn(): void
    {
        $box = $this->connectBox('user_info_reader', null, 'owner_info_pfo', true);
        $this->scriptMailbox();

        $report = $this->sync->syncBox($this->storedBox($box['id']));

        self::assertSame('ok', $report['status']);
        self::assertSame(3, $report['received_new']);
    }

    public function testALoginWithoutTheListRightIsRefused(): void
    {
        $box = $this->connectBox('user_info_send_only');
        $this->forgetOperations();
        $report = $this->sync->syncBox($this->storedBox($box['id']));
        self::assertSame('refused', $report['status']);
        self::assertStringContainsString('Zobrazovat seznamy', $report['message']);
        self::assertSame([], $this->isds->requests);
    }

    public function testUnknownPrivilegesAreFetchedBeforeAnyListCall(): void
    {
        $box = $this->connectBox();
        $this->db->pdo()->prepare('UPDATE databox_credentials SET user_privils = NULL, user_type = NULL WHERE databox_id = ?')->execute([$box['id']]);
        $this->scriptMailbox();
        $this->scriptLogin('owner_info_pfo', 'user_info_reader');
        $this->forgetOperations();

        $report = $this->sync->syncBox($this->storedBox($box['id']));

        self::assertSame('refused', $report['status'], 'the freshly learned read right stops the sync');
        self::assertSame(0, $this->isds->count('GetListOfReceivedMessages'));
        self::assertGreaterThan(0, $this->isds->count('GetUserInfoFromLogin2'));
    }

    public function testARefusedLoginPausesTheBoxSoCronDoesNotHammerIsds(): void
    {
        $box = $this->connectBox();
        $this->isds->queue('GetListOfReceivedMessages', file_get_contents(DataboxFixtures::path('http_401_bad_password.html')), 401);
        $now = new \DateTimeImmutable('2026-11-03 10:00:00');

        $first = $this->sync->syncBox($this->storedBox($box['id']), $now);
        self::assertSame('paused', $first['status']);
        $stored = $this->storedBox($box['id']);
        self::assertSame('paused', $stored['last_sync_status']);
        self::assertSame('2026-11-03 16:00:00', $stored['sync_paused_until']);
        self::assertSame('bad_credentials', $stored['credentials']['sync']['last_check_status']);

        $this->forgetOperations();
        $second = $this->sync->syncBox($this->storedBox($box['id']), $now->modify('+30 minutes'));
        self::assertSame('paused', $second['status']);
        self::assertSame([], $this->isds->requests, 'no login attempt while paused');

        // After the pause it still refuses the known-bad password until the owner fixes it.
        $third = $this->sync->syncBox($this->storedBox($box['id']), $now->modify('+7 hours'));
        self::assertSame('refused', $third['status']);
        self::assertSame([], $this->isds->requests);

        // The owner enters the right password: the box works again.
        $this->scriptLogin('owner_info_pfo', 'user_info_list_only');
        $this->service->setCredential($this->supplierId, $box['id'], 'sync', 'sync-user', 'new-good-password');
        $this->scriptMailbox();
        self::assertSame('ok', $this->sync->syncBox($this->storedBox($box['id']), $now->modify('+8 hours'))['status']);
    }

    public function testALockedAccountIsLeftAloneUntilItsBlockEnds(): void
    {
        $box = $this->connectBox();
        $this->isds->queue('GetListOfReceivedMessages', file_get_contents(DataboxFixtures::path('http_401_locked.html')), 401);
        $report = $this->sync->syncBox($this->storedBox($box['id']), new \DateTimeImmutable('2026-11-03 12:00:00'));

        self::assertSame('paused', $report['status']);
        self::assertSame('locked', $this->storedBox($box['id'])['credentials']['sync']['last_check_status']);
        self::assertStringStartsWith('2026-11-03 13:04:39', $this->storedBox($box['id'])['sync_paused_until']);
    }

    public function testATransientErrorDoesNotPauseTheBox(): void
    {
        $box = $this->connectBox();
        $this->isds->queue('GetListOfReceivedMessages', 'maintenance', 503);
        $report = $this->sync->syncBox($this->storedBox($box['id']));

        self::assertSame('error', $report['status']);
        $stored = $this->storedBox($box['id']);
        self::assertNull($stored['sync_paused_until']);
        self::assertSame('ok', $stored['credentials']['sync']['last_check_status']);

        $this->scriptMailbox();
        self::assertSame('ok', $this->sync->syncBox($this->storedBox($box['id']))['status']);
    }

    public function testDeliveryReceiptsOfSentMessagesAreStoredOnceTheyExist(): void
    {
        $box = $this->connectBox();
        $this->scriptMailbox();

        $first = $this->sync->syncBox($this->storedBox($box['id']));
        self::assertSame(1, $first['receipts']);
        $sent = $this->messages->findByDmId($box['id'], 'sent', '4102001');
        self::assertTrue($sent['has_delivery_info']);
        self::assertTrue($sent['has_delivery_zfo']);
        self::assertStringContainsString('EV11', (string) $sent['delivery_info_xml']);
        self::assertSame('sealed-receipt', $this->messages->fileBytes($sent['id'], 'delivery_zfo'));

        $second = $this->sync->syncBox($this->storedBox($box['id']));
        self::assertSame(0, $second['receipts'], 'a receipt that is complete is not fetched again');
    }

    public function testInactiveBoxesAreNotSynchronised(): void
    {
        $box = $this->connectBox();
        $this->service->update($this->supplierId, $box['id'], ['is_active' => false]);
        $ids = array_column($this->boxes->allActive(), 'id');
        self::assertNotContains($box['id'], $ids);
    }
}
