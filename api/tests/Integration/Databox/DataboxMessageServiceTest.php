<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\IsdsException;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

/**
 * The explicit "open a message" action and its legal guard rails.
 */
final class DataboxMessageServiceTest extends DataboxIntegrationTestCase
{
    /** @var array{name:string, mime:string, bytes:string} */
    private array $attachment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attachment = ['name' => 'vyzva.pdf', 'mime' => 'application/pdf', 'bytes' => "%PDF-1.4 synthetic\n"];
    }

    /** A box with a list-only sync login and a reading action login, synced once. @return array{box:array<string,mixed>, tax:int, normal:int, fiction:int} */
    private function syncedBox(string $actionUser = 'user_info_reader'): array
    {
        $box = $this->connectBox('user_info_list_only', $actionUser);
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_empty'));
        $this->sync->syncBox($this->storedBox($box['id']));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_empty'));
        $this->forgetOperations();
        return [
            'box' => $box,
            'tax' => $this->messages->findByDmId($box['id'], 'received', '4101001')['id'],
            'normal' => $this->messages->findByDmId($box['id'], 'received', '4101002')['id'],
            'fiction' => $this->messages->findByDmId($box['id'], 'received', '4101003')['id'],
        ];
    }

    private function deliveredListAfterLogin(): string
    {
        return str_replace(
            ['<q:dmMessageStatus>4</q:dmMessageStatus>', '<q:dmAcceptanceTime xsi:nil="true"/>', '<q:dmMessageStatus>5</q:dmMessageStatus>'],
            ['<q:dmMessageStatus>6</q:dmMessageStatus>', '<q:dmAcceptanceTime>2026-11-03T10:00:00.000+01:00</q:dmAcceptanceTime>', '<q:dmMessageStatus>6</q:dmMessageStatus>'],
            DataboxFixtures::xml('list_received'),
        );
    }

    public function testDetailExplainsWhatOpeningAnUndeliveredMessageWouldDo(): void
    {
        $ctx = $this->syncedBox();
        $detail = $this->messageService->detail($this->supplierId, $ctx['tax']);

        self::assertSame('in_box', $detail['state']);
        self::assertTrue($detail['open']['needs_acknowledgement']);
        self::assertTrue($detail['open']['undelivered']);
        self::assertSame('2026-11-06', $detail['open']['fiction_date'], 'put into the box Tue 27.10. + 10 days = Fri 6.11.');
        self::assertTrue($detail['open']['has_capable_login']);
        self::assertNull($detail['open']['blocked_reason']);
        self::assertSame('kh_change_17d', $detail['tax_office_hints'][0]['code']);
        self::assertNull($detail['attachments']);
        self::assertSame([], $this->isds->requests, 'reading the detail never calls ISDS');
    }

    public function testOpeningAnUndeliveredMessageWithoutAcknowledgementIsRefusedBeforeAnyIsdsCall(): void
    {
        $ctx = $this->syncedBox();
        try {
            $this->messageService->open($this->supplierId, $ctx['tax'], $this->userId, false);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('delivery_ack_required', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertTrue($e->extra['undelivered']);
            self::assertSame('2026-11-06', $e->extra['fiction_date']);
        }
        self::assertSame([], $this->isds->requests, 'nothing was delivered');
        self::assertFalse($this->messages->find($ctx['tax'])['has_zfo']);
    }

    public function testOpeningWithAcknowledgementDeliversByLoginThenDownloadsAndStores(): void
    {
        $ctx = $this->syncedBox();
        $zfo = DataboxFixtures::zfo('4101001', [$this->attachment, ['name' => 'priloha.txt', 'mime' => 'text/plain', 'bytes' => 'text']], 'Výzva k odstranění pochybností');
        $this->isds->queue('GetListOfReceivedMessages', $this->deliveredListAfterLogin());
        $this->isds->queue('SignedMessageDownload', DataboxFixtures::signedResponse('SignedMessageDownload', $zfo));

        $detail = $this->messageService->open($this->supplierId, $ctx['tax'], $this->userId, true);

        // The delivering "login" came first, with the reading action login, and only then the download.
        self::assertSame(['GetListOfReceivedMessages', 'SignedMessageDownload'], $this->isds->operations());
        self::assertSame('action-user', $this->isds->requests[0]['username']);
        // The delivering listing is as narrow as the second-level timestamps allow: other messages must not be delivered with it.
        $body = $this->isds->bodyOf('GetListOfReceivedMessages');
        self::assertMatchesRegularExpression('#<p:dmFromTime>2026-10-27T09:15:28[^<]*</p:dmFromTime>#', $body);
        self::assertMatchesRegularExpression('#<p:dmToTime>2026-10-27T09:15:32[^<]*</p:dmToTime>#', $body);

        self::assertSame('delivered', $detail['state']);
        self::assertSame('2026-11-03 10:00:00', $detail['acceptance_time']);
        self::assertTrue($detail['has_zfo']);
        self::assertSame($zfo, $this->messages->fileBytes($ctx['tax'], 'zfo'), 'the signed message is stored byte for byte');
        self::assertSame(hash('sha256', $zfo), $detail['file']['sha256']);
        self::assertNotNull($detail['acknowledged_at'], 'opening clears the dashboard item');
        self::assertCount(2, $detail['attachments']);
        self::assertSame(['index' => 0, 'name' => 'vyzva.pdf', 'mime' => 'application/pdf', 'meta_type' => 'main', 'size' => strlen($this->attachment['bytes'])], $detail['attachments'][0]);

        $file = $this->messageService->attachment($this->supplierId, $ctx['tax'], 0);
        self::assertSame($this->attachment['bytes'], $file['bytes']);
        self::assertSame('vyzva.pdf', $file['name']);

        // The other envelopes the delivering list returned are refreshed too (they may have been delivered by the same call).
        self::assertSame(6, $this->messages->find($ctx['fiction'])['status']);
    }

    public function testAnUndeliveredMessageWithoutDeliveryTimeIsNeverOpened(): void
    {
        $ctx = $this->syncedBox();
        $this->db->pdo()->prepare('UPDATE databox_messages SET delivery_time = NULL WHERE id = ?')->execute([$ctx['tax']]);
        self::assertSame('delivery_time_unknown', $this->messageService->detail($this->supplierId, $ctx['tax'])['open']['blocked_reason'], 'the page can say so before the click');

        foreach ([false, true] as $acknowledged) {
            try {
                $this->messageService->open($this->supplierId, $ctx['tax'], $this->userId, $acknowledged);
                self::fail('expected DataboxException');
            } catch (DataboxException $e) {
                self::assertSame('delivery_time_unknown', $e->errorCode);
                self::assertSame(409, $e->httpStatus);
            }
        }
        self::assertSame([], $this->isds->requests, 'a wide listing would deliver other messages too, so nothing is called');
        self::assertFalse($this->messages->find($ctx['tax'])['has_zfo']);
        self::assertSame(4, $this->messages->find($ctx['tax'])['status']);
    }

    public function testAnAlreadyDeliveredMessageIsDownloadedWithoutAnyListCall(): void
    {
        $ctx = $this->syncedBox();
        $zfo = DataboxFixtures::zfo('4101002', [$this->attachment]);
        $this->isds->queue('SignedMessageDownload', DataboxFixtures::signedResponse('SignedMessageDownload', $zfo));

        $detail = $this->messageService->open($this->supplierId, $ctx['normal'], $this->userId, false);

        self::assertSame(['SignedMessageDownload'], $this->isds->operations(), 'state 6: no login, nothing can be delivered');
        self::assertTrue($detail['has_zfo']);
    }

    public function testAMessageDeliveredByFictionNeedsALoginButItsLegalDateStaysPut(): void
    {
        $ctx = $this->syncedBox();
        $detail = $this->messageService->detail($this->supplierId, $ctx['fiction']);
        self::assertSame('fiction', $detail['state']);
        self::assertTrue($detail['open']['needs_acknowledgement'], 'ISDS refuses to download state 5, so a login is required');
        self::assertFalse($detail['open']['undelivered'], 'but it is already delivered by fiction, the login changes nothing legally');
        self::assertNull($detail['open']['fiction_date']);
    }

    public function testAPersonalDeliveryMessageNeedsTheRightToReadPersonalMessages(): void
    {
        $ctx = $this->syncedBox('user_info_reader'); // 1 = read, not "do vlastních rukou"
        try {
            $this->messageService->open($this->supplierId, $ctx['fiction'], $this->userId, true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('missing_right', $e->errorCode);
            self::assertSame('Číst přijaté zprávy do vlastních rukou', $e->extra['missing_right']);
        }
        self::assertSame([], $this->isds->requests);
    }

    public function testWithoutAnyReadingLoginNothingIsSent(): void
    {
        $ctx = $this->syncedBox('user_info_sender');
        $detail = $this->messageService->detail($this->supplierId, $ctx['tax']);
        self::assertFalse($detail['open']['has_capable_login']);

        try {
            $this->messageService->open($this->supplierId, $ctx['tax'], $this->userId, true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('missing_right', $e->errorCode);
        }
        self::assertSame([], $this->isds->requests);
    }

    public function testIsdsRefusalOfAnUndeliveredDownloadIsExplained(): void
    {
        $ctx = $this->syncedBox();
        // The list did not mark the message delivered (e.g. a race) -> we do not even try to download.
        $this->isds->queue('GetListOfReceivedMessages', DataboxFixtures::xml('list_received'));
        try {
            $this->messageService->open($this->supplierId, $ctx['tax'], $this->userId, true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('delivery_not_confirmed', $e->errorCode);
        }
        self::assertSame(['GetListOfReceivedMessages'], $this->isds->operations());

        // And the server-side guard 1222 on a download is translated as well.
        $this->forgetOperations();
        $this->isds->queue('SignedMessageDownload', DataboxFixtures::xml('status_1222'));
        try {
            $this->messageService->open($this->supplierId, $ctx['normal'], $this->userId, false);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('delivery_not_confirmed', $e->errorCode);
        }
    }

    public function testAnIsdsFailureWhileDeliveringIsPropagated(): void
    {
        $ctx = $this->syncedBox();
        $this->isds->queue('GetListOfReceivedMessages', 'down', 503);
        $this->expectException(IsdsException::class);
        $this->messageService->open($this->supplierId, $ctx['tax'], $this->userId, true);
    }

    public function testAStoredMessageIsOpenedAgainWithoutIsds(): void
    {
        $ctx = $this->syncedBox();
        $this->isds->queue('SignedMessageDownload', DataboxFixtures::signedResponse('SignedMessageDownload', DataboxFixtures::zfo('4101002', [$this->attachment])));
        $this->messageService->open($this->supplierId, $ctx['normal'], $this->userId, false);
        $this->forgetOperations();

        $again = $this->messageService->open($this->supplierId, $ctx['normal'], $this->userId, false);
        self::assertTrue($again['has_zfo']);
        self::assertSame([], $this->isds->requests);
    }

    public function testASentMessageIsOpenedWithTheSendingLogin(): void
    {
        $box = $this->connectBox('user_info_list_only', 'user_info_sender');
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_empty'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_sent'));
        $this->isds->always('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->always('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'receipt'));
        $this->sync->syncBox($this->storedBox($box['id']));
        $sent = $this->messages->findByDmId($box['id'], 'sent', '4102001');
        $this->forgetOperations();

        $this->isds->queue('SignedSentMessageDownload', DataboxFixtures::signedResponse('SignedSentMessageDownload', DataboxFixtures::zfo('4102001', [$this->attachment])));
        $detail = $this->messageService->open($this->supplierId, $sent['id'], $this->userId, false);

        self::assertTrue($detail['has_zfo']);
        self::assertSame(['SignedSentMessageDownload'], $this->isds->operations());
        self::assertSame('action-user', $this->isds->requests[0]['username']);
        self::assertCount(3, $detail['receipt']['events']);
    }

    public function testTheDeliveryReceiptOfASentMessageCanBeRefreshed(): void
    {
        $box = $this->connectBox('user_info_list_only');
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_empty'));
        $this->isds->always('GetListOfSentMessages', DataboxFixtures::xml('list_sent'));
        $this->isds->always('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->always('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', 'receipt-v2'));
        $this->sync->syncBox($this->storedBox($box['id']));
        $sent = $this->messages->findByDmId($box['id'], 'sent', '4102001');

        $detail = $this->messageService->refreshReceipt($this->supplierId, $sent['id']);
        self::assertSame('receipt-v2', $this->messages->fileBytes($sent['id'], 'delivery_zfo'));
        self::assertSame('delivered', $detail['state']);
        self::assertSame('2026-10-24 10:00:05', $detail['acceptance_time']);
    }

    public function testReceiptRefreshIsOnlyForSentMessages(): void
    {
        $ctx = $this->syncedBox();
        $this->expectException(DataboxException::class);
        $this->messageService->refreshReceipt($this->supplierId, $ctx['tax']);
    }

    public function testAMessageOfAnotherSupplierIsInvisible(): void
    {
        if ($this->otherSupplierId === 0) {
            self::markTestSkipped('The database has only one supplier.');
        }
        $ctx = $this->syncedBox();
        foreach ([
            fn () => $this->messageService->detail($this->otherSupplierId, $ctx['tax']),
            fn () => $this->messageService->open($this->otherSupplierId, $ctx['tax'], $this->userId, true),
            fn () => $this->messageService->attachment($this->otherSupplierId, $ctx['tax'], 0),
            fn () => $this->messageService->file($this->otherSupplierId, $ctx['tax'], 'zfo'),
            fn () => $this->messageService->list($this->otherSupplierId, $ctx['box']['id'], [], 1, 50),
        ] as $call) {
            try {
                $call();
                self::fail('expected DataboxException');
            } catch (DataboxException $e) {
                self::assertSame(404, $e->httpStatus);
            }
        }
        self::assertSame([], $this->isds->requests);
    }

    public function testListingFiltersAndDecorates(): void
    {
        $ctx = $this->syncedBox();
        $list = $this->messageService->list($this->supplierId, $ctx['box']['id'], ['direction' => 'received', 'tax_office_only' => true], 1, 50);
        self::assertSame(1, $list['total']);
        self::assertSame('in_box', $list['items'][0]['state']);

        $search = $this->messageService->list($this->supplierId, $ctx['box']['id'], ['search' => 'Objednávka'], 1, 50);
        self::assertSame(['4101002'], array_column($search['items'], 'dm_id'));

        self::assertSame(3, $this->messageService->acknowledgeAll($this->supplierId, $ctx['box']['id'], $this->userId));
        self::assertSame(0, $this->messageService->list($this->supplierId, $ctx['box']['id'], ['unread_only' => true], 1, 50)['total']);
    }

    public function testTheCombinedViewListsAndAcknowledgesEveryBoxOfTheSupplierOnly(): void
    {
        $ctx = $this->syncedBox();
        $emailBox = $this->connectEmailBox('emxabcn', 'FO');
        $this->messages->insertFromEmailNotice($emailBox['id'], [
            'dm_id' => '4209001', 'sender_box_id' => 'abc1234', 'sender_name' => 'Testovací úřad', 'sender_type' => 10,
            'recipient_box_id' => 'emxabcn', 'recipient_name' => 'Jana Testovací', 'annotation' => 'Oznámení e-mailem',
            'delivery_time' => new \DateTimeImmutable('2026-11-04 09:00:00'),
        ], new \DateTimeImmutable('2026-11-04 09:05:00'));
        $foreign = $this->connectEmailBox('frgabcu', 'FO', $this->otherSupplierId);
        $this->messages->insertFromEmailNotice($foreign['id'], [
            'dm_id' => '4209002', 'sender_box_id' => 'abc1234', 'sender_name' => 'Testovací úřad', 'sender_type' => 10,
            'recipient_box_id' => 'frgabcu', 'recipient_name' => 'Cizí dodavatel', 'annotation' => 'Cizí zpráva',
            'delivery_time' => new \DateTimeImmutable('2026-11-04 09:00:00'),
        ], new \DateTimeImmutable('2026-11-04 09:05:00'));

        $all = $this->messageService->list($this->supplierId, null, ['direction' => 'received'], 1, 50);
        self::assertSame(4, $all['total']);
        self::assertEqualsCanonicalizing([$ctx['box']['id'], $emailBox['id']], array_values(array_unique(array_column($all['items'], 'databox_id'))));
        self::assertNotContains('4209002', array_column($all['items'], 'dm_id'));
        self::assertSame(1, $this->messageService->list($this->supplierId, null, ['search' => 'e-mailem'], 1, 50)['total']);

        self::assertSame(4, $this->messageService->acknowledgeAll($this->supplierId, null, $this->userId));
        self::assertSame(0, $this->messageService->list($this->supplierId, null, ['unread_only' => true], 1, 50)['total']);
        self::assertSame(1, $this->messageService->list($this->otherSupplierId, null, ['unread_only' => true], 1, 50)['total']);
    }
}
