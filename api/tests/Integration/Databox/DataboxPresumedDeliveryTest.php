<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures as F;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;

/**
 * A message known only from its notification e-mail stays "put into the box" in the stored data for good. Presented
 * honestly, it is "delivered at the latest" once its fiction day has passed; the stored state is never changed.
 */
final class DataboxPresumedDeliveryTest extends DataboxIntegrationTestCase
{
    /** @return int the id of a received message in state 4 put into the box $daysAgo days ago */
    private function inBox(int $boxId, string $dmId, int $daysAgo, string $source = 'email', ?bool $allowFiction = null): int
    {
        $at = (new \DateTimeImmutable())->modify("-{$daysAgo} days")->setTime(9, 30);
        if ($source === 'email') {
            return $this->messages->insertFromEmailNotice($boxId, [
                'dm_id' => $dmId, 'sender_box_id' => F::SENDER_BOX, 'sender_name' => 'Úřad', 'sender_type' => null,
                'recipient_box_id' => F::RECIPIENT_BOX, 'recipient_name' => 'Jana', 'annotation' => 'Věc ' . $dmId, 'delivery_time' => $at,
            ], new \DateTimeImmutable())['id'];
        }
        return $this->messages->upsertEnvelope($boxId, 'received', [
            'dm_id' => $dmId, 'sender_box_id' => F::SENDER_BOX, 'sender_name' => 'Úřad', 'annotation' => 'Věc ' . $dmId, 'status' => 4,
            'delivery_time' => $at, 'allow_subst_delivery' => $allowFiction,
        ], new \DateTimeImmutable(), $source)['id'];
    }

    /** @return array<string,mixed> */
    private function detail(int $id): array
    {
        return $this->messageService->detail($this->supplierId, $id);
    }

    public function testANoticeOnlyMessageIsPresentedAsDeliveredOnceItsFictionDayHasPassed(): void
    {
        $box = $this->connectEmailBox();
        $old = $this->inBox($box['id'], '7700001', 40);
        $fresh = $this->inBox($box['id'], '7700002', 2);

        $oldDetail = $this->detail($old);
        self::assertSame('delivered_presumed', $oldDetail['state']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $oldDetail['presumed_delivery_date']);
        self::assertSame(4, $oldDetail['status'], 'the stored state is untouched');
        self::assertFalse($oldDetail['open']['undelivered']);
        self::assertNull($oldDetail['open']['fiction_date']);

        $freshDetail = $this->detail($fresh);
        self::assertSame('in_box', $freshDetail['state']);
        self::assertNull($freshDetail['presumed_delivery_date']);
        self::assertTrue($freshDetail['open']['undelivered']);
        self::assertNotNull($freshDetail['open']['fiction_date']);

        $listed = array_column($this->messageService->list($this->supplierId, $box['id'], ['direction' => 'received'], 1, 50)['items'], null, 'id');
        self::assertSame('delivered_presumed', $listed[$old]['state']);
        self::assertSame('in_box', $listed[$fresh]['state']);
    }

    public function testMessagesTheBoxSystemOrAFileVouchesForAreNeverPresumed(): void
    {
        $box = $this->connectBox();
        $isds = $this->inBox($box['id'], '7700003', 40, 'isds');
        $zfo = $this->inBox($box['id'], '7700004', 40, 'zfo');

        self::assertSame('in_box', $this->detail($isds)['state'], 'ISDS says 4: that is the state');
        self::assertSame('in_box', $this->detail($zfo)['state']);
        self::assertSame('zfo', $this->sourceOf($zfo));
    }

    public function testASenderThatExcludedTheFictionKeepsTheMessageUndelivered(): void
    {
        $box = $this->connectBox();
        $id = $this->inBox($box['id'], '7700005', 40, 'zfo', false);

        self::assertSame('in_box', $this->detail($id)['state']);
    }

    public function testPresumedMessagesAreNotCountedAsUndelivered(): void
    {
        $box = $this->connectEmailBox();
        $before = $this->messages->unreadSummary($this->supplierId);
        $this->inBox($box['id'], '7700006', 40);
        $this->inBox($box['id'], '7700007', 2);

        $after = $this->messages->unreadSummary($this->supplierId);

        self::assertSame($before['unread'] + 2, $after['unread'], 'both are still unread');
        self::assertSame($before['undelivered'] + 1, $after['undelivered'], 'only the one inside its fiction period can still be undelivered');
    }

    public function testRealStateFourMessagesStillCountAsUndelivered(): void
    {
        $box = $this->connectBox();
        $before = $this->messages->unreadSummary($this->supplierId);
        $this->inBox($box['id'], '7700008', 40, 'isds');
        $this->inBox($box['id'], '7700009', 40, 'zfo');

        $after = $this->messages->unreadSummary($this->supplierId);

        self::assertSame($before['undelivered'] + 2, $after['undelivered']);
    }

    public function testTheMessageTurnsIntoItsRealStateOnceTheBoxSystemOrAReceiptSaysSo(): void
    {
        $box = $this->connectEmailBox();
        $id = $this->inBox($box['id'], '7700010', 40);
        self::assertSame('delivered_presumed', $this->detail($id)['state']);

        $this->messages->upsertEnvelope($box['id'], 'received', ['dm_id' => '7700010', 'status' => 6], new \DateTimeImmutable());

        $detail = $this->detail($id);
        self::assertSame('delivered', $detail['state']);
        self::assertNull($detail['presumed_delivery_date']);
    }
}
