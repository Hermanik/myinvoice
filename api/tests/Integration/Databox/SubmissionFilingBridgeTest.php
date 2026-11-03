<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Tax\TaxFilingException;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

/**
 * Sending an archived filing through the data box drives its filing lifecycle: sent -> filed (channel data_box,
 * reference = message ID), delivery info -> filed_at = the delivery time, sealed receipt -> confirmed.
 */
final class SubmissionFilingBridgeTest extends DataboxIntegrationTestCase
{
    private const DELIVERED_TO_BOX = '2026-10-24 10:00:00';

    private function sendingBox(): array
    {
        return $this->connectBox('user_info_list_only', 'user_info_sender');
    }

    /** A CMS the receipt validation accepts, like the real sealed delivery info. */
    private function sealedReceipt(): string
    {
        return DataboxFixtures::zfo('4102002', [['name' => 'dorucenka.xml', 'mime' => 'application/xml', 'bytes' => '<receipt/>']]);
    }

    /** @param bool $receiptAtOnce false: ISDS does not have the delivery info yet when the message is sent */
    private function scriptSend(bool $receiptAtOnce = true, ?string $sealed = null): void
    {
        $this->isds->queue('CreateMessage', DataboxFixtures::xml('create_message_ok'));
        if ($receiptAtOnce) {
            $this->isds->queue('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
            $this->isds->queue('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', $sealed ?? $this->sealedReceipt()));
        } else {
            $this->isds->queue('GetDeliveryInfo', 'not yet', 503);
        }
        $this->isds->queue('SignedSentMessageDownload', DataboxFixtures::signedResponse('SignedSentMessageDownload', DataboxFixtures::zfo('4102002', [['name' => 'x.xml', 'mime' => 'application/xml', 'bytes' => '<a/>']])));
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        return $this->submissionRepo->findMeta($id, $this->supplierId) ?? throw new \LogicException('submission vanished');
    }

    /** @return array<string,mixed> the send result */
    private function sendFiling(int $submissionId, int $boxId, bool $resend = false): array
    {
        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $submissionId, $boxId);
        return $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true, $resend);
    }

    public function testSendingMarksTheSubmissionFiledThroughTheDataBoxChannel(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->scriptSend(false);
        $before = new \DateTimeImmutable();

        $result = $this->sendFiling($id, $box['id']);
        $row = $this->row($id);

        self::assertSame(['status' => 'recorded', 'message' => null], $result['filing']);
        self::assertSame('filed', $row['filing_status']);
        self::assertSame('data_box', $row['filing_channel']);
        self::assertSame('4102002', $row['filing_reference']);
        self::assertSame($this->userId, $row['filed_by']);
        self::assertGreaterThanOrEqual($before->getTimestamp() - 1, strtotime((string) $row['filed_at']), 'the send time until ISDS reports the delivery');
        self::assertFalse($result['receipt']);
    }

    public function testTheDeliveryTimeReplacesTheSendTimeWhenTheDeliveryInfoArrives(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->scriptSend(false);
        $result = $this->sendFiling($id, $box['id']);
        self::assertNotSame(self::DELIVERED_TO_BOX, $this->row($id)['filed_at']);

        $this->isds->queue('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->queue('GetSignedDeliveryInfo', 'down', 503);
        try {
            $this->messageService->refreshReceipt($this->supplierId, $result['message_id']);
        } catch (\Throwable) {
            // the sealed copy failed; the readable delivery info is stored and already drives the filing
        }

        $row = $this->row($id);
        self::assertSame(self::DELIVERED_TO_BOX, $row['filed_at'], 'dmDeliveryTime: when the message reached the tax office box');
        self::assertSame('filed', $row['filing_status'], 'no sealed receipt yet');
    }

    public function testTheSealedDeliveryReceiptBecomesTheSubmissionReceiptAndConfirmsIt(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->scriptSend();

        $result = $this->sendFiling($id, $box['id']);
        $row = $this->row($id);
        $stored = $this->submissionRepo->receipt($id, $this->supplierId);

        self::assertTrue($result['receipt']);
        self::assertSame('confirmed', $row['filing_status']);
        self::assertSame(self::DELIVERED_TO_BOX, $row['filed_at']);
        self::assertSame('datova-zprava-4102002-dorucenka.zfo', $row['receipt_name']);
        self::assertSame($this->sealedReceipt(), $stored['content'], 'the sealed ZFO byte for byte');
        self::assertSame(hash('sha256', $this->sealedReceipt()), $row['receipt_sha256']);
        self::assertNotNull($row['confirmed_at']);
    }

    public function testTheCronSyncFinishesTheFilingOnceTheReceiptExists(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->scriptSend(false);
        $this->sendFiling($id, $box['id']);
        self::assertSame('filed', $this->row($id)['filing_status']);

        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_empty'));
        // The sent list of ISDS now carries the message sent above (4102002) with its state.
        $this->isds->always('GetListOfSentMessages', str_replace('4102001', '4102002', DataboxFixtures::xml('list_sent')));
        $this->isds->always('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->always('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', $this->sealedReceipt()));
        $report = $this->sync->syncBox($this->storedBox($box['id']));

        self::assertGreaterThanOrEqual(1, $report['receipts']);
        $row = $this->row($id);
        self::assertSame('confirmed', $row['filing_status']);
        self::assertSame(self::DELIVERED_TO_BOX, $row['filed_at']);
    }

    public function testAReceiptThatIsNotAValidZfoLeavesTheSubmissionFiledNotConfirmed(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->scriptSend(true, 'not a cms');

        $result = $this->sendFiling($id, $box['id']);

        self::assertTrue($result['receipt']);
        self::assertSame('filed', $this->row($id)['filing_status']);
        self::assertNull($this->submissionRepo->receipt($id, $this->supplierId));
        self::assertSame(self::DELIVERED_TO_BOX, $this->row($id)['filed_at'], 'the delivery time still applies');
    }

    public function testTheSyncTakesBackAFilingWhoseMessageISDSRejectedWithoutAnyReceipt(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->scriptSend(false);
        $this->sendFiling($id, $box['id']);
        self::assertSame('filed', $this->row($id)['filing_status']);

        // The sent list now shows the message as rejected (3); a rejected message has no receipt to fetch.
        $rejected = str_replace(['4102001', '<q:dmMessageStatus>6</q:dmMessageStatus>'], ['4102002', '<q:dmMessageStatus>3</q:dmMessageStatus>'], DataboxFixtures::xml('list_sent'));
        $this->isds->always('GetListOfReceivedMessages', DataboxFixtures::xml('list_empty'));
        $this->isds->always('GetListOfSentMessages', $rejected);
        $receiptCalls = $this->isds->count('GetDeliveryInfo');
        $this->sync->syncBox($this->storedBox($box['id']));

        self::assertSame('generated', $this->row($id)['filing_status']);
        self::assertSame($receiptCalls, $this->isds->count('GetDeliveryInfo'), 'no receipt was fetched: the state alone took the filing back');
    }

    public function testAMessageThatWasRejectedTakesTheFilingBack(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->scriptSend(false);
        $result = $this->sendFiling($id, $box['id']);
        self::assertSame('filed', $this->row($id)['filing_status']);

        $rejected = str_replace('<q:dmMessageStatus>6</q:dmMessageStatus>', '<q:dmMessageStatus>3</q:dmMessageStatus>', DataboxFixtures::xml('delivery_info'));
        $this->isds->queue('GetDeliveryInfo', $rejected);
        $this->isds->queue('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', $this->sealedReceipt()));
        $this->messageService->refreshReceipt($this->supplierId, $result['message_id']);

        $row = $this->row($id);
        self::assertSame('generated', $row['filing_status'], 'a message ISDS rejected did not file anything');
        self::assertNull($row['filing_channel']);
    }

    // ---- the filing rules still hold ----

    public function testASubmissionThatIsAlreadyFiledNeedsTheResendConfirmationAndKeepsItsFiling(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->filing->markFiled($this->supplierId, $id, '2026-10-20 09:00:00', 'epo', 'EPO-1', $this->userId);

        $prefill = $this->submissions->prefill($this->supplierId, $id);
        self::assertContains('already_filed', $prefill['warnings']);

        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
        self::assertTrue($prepared['summary']['requires_resend_confirmation'], 'a second filing of the same slot is a "send again"');
        $this->forgetOperations();
        try {
            $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('resend_confirmation_required', $e->errorCode);
        }
        self::assertSame([], $this->isds->requests);

        $this->scriptSend();
        $result = $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true, true);

        self::assertSame('kept', $result['filing']['status']);
        $row = $this->row($id);
        self::assertSame('epo', $row['filing_channel'], 'the EPO filing is not overwritten by the re-send');
        self::assertSame('EPO-1', $row['filing_reference']);
        self::assertSame('2026-10-20 09:00:00', $row['filed_at']);
        self::assertSame('filed', $row['filing_status'], 'the receipt of the re-sent message is not this filing\'s receipt');
        self::assertNull($this->submissionRepo->receipt($id, $this->supplierId));
    }

    public function testTheReceiptOfAReSentMessageDoesNotTouchTheFilingOfTheFirstOne(): void
    {
        $box = $this->sendingBox();
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->scriptSend(false);
        $first = $this->sendFiling($id, $box['id']);
        $sendTime = $this->row($id)['filed_at'];

        // The second message is 4102003 and its receipt is available at once.
        $this->isds->queue('CreateMessage', str_replace('4102002', '4102003', DataboxFixtures::xml('create_message_ok')));
        $this->isds->queue('GetDeliveryInfo', DataboxFixtures::xml('delivery_info'));
        $this->isds->queue('GetSignedDeliveryInfo', DataboxFixtures::signedResponse('GetSignedDeliveryInfo', $this->sealedReceipt()));
        $this->isds->queue('SignedSentMessageDownload', DataboxFixtures::signedResponse('SignedSentMessageDownload', DataboxFixtures::zfo('4102003', [['name' => 'x.xml', 'mime' => 'application/xml', 'bytes' => '<a/>']])));
        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $id, $box['id']);
        $second = $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true, true);

        self::assertNotSame($first['message_id'], $second['message_id']);
        $row = $this->row($id);
        self::assertSame('4102002', $row['filing_reference'], 'the filing stays the first message\'s');
        self::assertSame($sendTime, $row['filed_at'], 'the second message\'s delivery info does not move it');
        self::assertSame('filed', $row['filing_status']);
        self::assertNull($this->submissionRepo->receipt($id, $this->supplierId));
    }

    public function testAnotherSubmissionOfTheSameSlotBeingFiledBlocksTheSendBeforeAnythingLeaves(): void
    {
        $box = $this->sendingBox();
        $first = $this->archiveSubmission('dphdp3', self::epoXml());
        $second = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->filing->markFiled($this->supplierId, $first, '2026-10-20 09:00:00', 'epo', null, $this->userId);

        self::assertSame('duplicate_filing', $this->submissions->prefill($this->supplierId, $second)['blocked_reason']);
        $this->forgetOperations();
        try {
            $this->submissions->prepare($this->supplierId, $this->userId, $second, $box['id']);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('duplicate_filing', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertStringContainsString('#' . $first, $e->getMessage());
        }
        self::assertSame([], $this->isds->requests);
    }

    public function testTheSlotBeingFiledMeanwhileStopsAStagedSendWithoutBurningIt(): void
    {
        $box = $this->sendingBox();
        $first = $this->archiveSubmission('dphdp3', self::epoXml());
        $second = $this->archiveSubmission('dphdp3', self::epoXml());
        $prepared = $this->submissions->prepare($this->supplierId, $this->userId, $second, $box['id']);
        $this->filing->markFiled($this->supplierId, $first, '2026-10-20 09:00:00', 'epo', null, $this->userId);

        $this->forgetOperations();
        try {
            $this->sender->send($this->supplierId, $this->userId, $prepared['token'], true, true);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame('duplicate_filing', $e->errorCode);
        }
        self::assertSame(0, $this->isds->count('CreateMessage'), 'a filing that could not be recorded is not sent');
        self::assertNotNull($this->outbox->peek($prepared['token'], new \DateTimeImmutable()));
    }

    public function testAFilingThatCannotBeRecordedAfterTheSendIsReportedNotHidden(): void
    {
        $first = $this->archiveSubmission('dphdp3', self::epoXml());
        $second = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->filing->markFiled($this->supplierId, $first, '2026-10-20 09:00:00', 'epo', null, $this->userId);

        // The message is already out (the guard before the send passed, the sibling was filed in between).
        $outcome = $this->filings->sent($this->supplierId, $second, '4102002', $this->userId, new \DateTimeImmutable());

        self::assertSame('not_recorded', $outcome['status']);
        self::assertStringContainsString('#' . $first, (string) $outcome['message']);
        self::assertSame('generated', $this->row($second)['filing_status']);
    }

    public function testAdjustingTheFilingTimeNeverReordersTheReturnsOfAPeriod(): void
    {
        $original = $this->archiveSubmission('dphdp3', self::epoXml(), 'passed', 2026, 9, null, null, 'B');
        $correction = $this->archiveSubmission('dphdp3', self::epoXml(), 'passed', 2026, 9, null, null, 'O');
        $this->filing->markFiled($this->supplierId, $original, '2026-10-20 09:00:00', 'epo', null, $this->userId);
        $this->filing->markFiled($this->supplierId, $correction, '2026-10-22 09:00:00', 'data_box', '4102002', $this->userId);

        // Moving the correction before the original would make the original the return in force.
        try {
            $this->filing->adjustFiledAt($this->supplierId, $correction, '2026-10-19 09:00:00', $this->userId);
            self::fail('expected TaxFilingException');
        } catch (TaxFilingException $e) {
            self::assertSame('order_would_change', $e->errorCode);
        }
        self::assertSame('2026-10-22 09:00:00', $this->row($correction)['filed_at']);

        $this->filing->adjustFiledAt($this->supplierId, $correction, '2026-10-22 09:00:05', $this->userId);
        self::assertSame('2026-10-22 09:00:05', $this->row($correction)['filed_at']);
    }

    public function testTheFutureIsNotAFilingTime(): void
    {
        $id = $this->archiveSubmission('dphdp3', self::epoXml());
        $this->filing->markFiled($this->supplierId, $id, '2026-10-20 09:00:00', 'data_box', '1', $this->userId);
        $this->expectException(TaxFilingException::class);
        $this->filing->adjustFiledAt($this->supplierId, $id, (new \DateTimeImmutable('+2 days'))->format('Y-m-d H:i:s'), $this->userId);
    }
}
