<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Timeline;

use MyInvoice\Service\Timeline\StandingOrders;
use PHPUnit\Framework\TestCase;

/**
 * Which items a standing order pays and when: the payment day of the order in the latest month in which it is not after
 * the due date, clamped to the length of the month, inside the validity window of the order. Synthetic dates.
 */
final class StandingOrdersTest extends TestCase
{
    /** @return array{id:int, type:string, amount:float, day_of_month:int, valid_from:string, valid_to:?string} */
    private function order(int $day = 12, float $amount = 9984.0, string $type = 'pausal', string $from = '2097-01-01', ?string $to = null, int $id = 1): array
    {
        return ['id' => $id, 'type' => $type, 'amount' => $amount, 'day_of_month' => $day, 'valid_from' => $from, 'valid_to' => $to];
    }

    public function testPaymentDayIsInTheMonthOfTheDueDateWhenNotAfterIt(): void
    {
        // The flat-tax advance is due on the 20th; an order on the 12th pays it on 12. 10.
        self::assertSame('2097-10-12', StandingOrders::paymentDate(12, '2097-10-20'));
        self::assertSame('2097-10-20', StandingOrders::paymentDate(20, '2097-10-20'), 'the due date itself is in time');
    }

    public function testPaymentDayFallsBackToThePreviousMonthWhenItIsAfterTheDueDate(): void
    {
        // The health advance is due on the 8th: the order of the 12th paid it on the 12th of the month before.
        self::assertSame('2097-09-12', StandingOrders::paymentDate(12, '2097-10-08'));
        self::assertSame('2096-12-12', StandingOrders::paymentDate(12, '2097-01-08'), 'across the year end');
    }

    public function testDayBeyondTheEndOfAMonthIsTheLastDayOfIt(): void
    {
        self::assertSame('2098-02-28', StandingOrders::paymentDate(31, '2098-03-01'), 'February 2098 has 28 days');
        self::assertSame('2096-02-29', StandingOrders::paymentDate(31, '2096-03-01'), 'a leap year');
        self::assertSame('2098-02-28', StandingOrders::paymentDate(31, '2098-02-28'), 'the clamped day is in the due month itself');
        self::assertSame('2098-01-31', StandingOrders::paymentDate(31, '2098-02-27'), 'the 28th is after the 27th, so the month before');
        self::assertSame('2097-04-30', StandingOrders::paymentDate(31, '2097-05-08'), 'April has 30 days');
    }

    public function testOrderMustBeValidOnThePaymentDayNotOnTheDueDate(): void
    {
        $orders = [$this->order(12, 9984.0, 'pausal', '2097-10-01', '2097-10-15')];
        self::assertSame('2097-10-12', StandingOrders::match($orders, 'pausal', '2097-10-20')['paid_on'], 'paid on the 12th, inside the window');

        self::assertNull(StandingOrders::match([$this->order(12, 9984.0, 'pausal', '2097-10-13')], 'pausal', '2097-10-20'), 'started after the payment day');
        self::assertNull(StandingOrders::match([$this->order(12, 9984.0, 'pausal', '2097-01-01', '2097-10-11')], 'pausal', '2097-10-20'), 'ended before it');
        self::assertNotNull(StandingOrders::match([$this->order(12, 9984.0, 'pausal', '2097-10-12', '2097-10-12')], 'pausal', '2097-10-20'), 'both ends of the window are inclusive');
    }

    public function testOnlyAnOrderOfTheSameTypeCovers(): void
    {
        $orders = [$this->order(12, 3000.0, 'insurance_health')];
        self::assertNull(StandingOrders::match($orders, 'pausal', '2097-10-20'));
        self::assertNull(StandingOrders::match($orders, 'insurance_social', '2097-10-20'));
        self::assertSame(3000.0, StandingOrders::match($orders, 'insurance_health', '2097-10-20')['amount']);
    }

    public function testTheNewestOrderWinsAndTheIdBreaksATie(): void
    {
        $old = $this->order(12, 9984.0, 'pausal', '2097-01-01', null, 1);
        $new = $this->order(12, 9162.0, 'pausal', '2097-07-01', null, 2);
        self::assertSame(2, StandingOrders::match([$old, $new], 'pausal', '2097-10-20')['order_id']);
        self::assertSame(2, StandingOrders::match([$new, $old], 'pausal', '2097-10-20')['order_id'], 'not a matter of the order in the list');
        self::assertSame(1, StandingOrders::match([$old, $new], 'pausal', '2097-06-20')['order_id'], 'the new one is not valid yet');

        $twin = $this->order(12, 5000.0, 'pausal', '2097-07-01', null, 3);
        self::assertSame(3, StandingOrders::match([$new, $twin], 'pausal', '2097-10-20')['order_id']);
    }

    public function testPaymentInThePastIsPaid(): void
    {
        $item = ['type' => 'pausal', 'due_date' => '2097-10-20', 'amount' => 9984.0];
        $cover = StandingOrders::cover($item, [$this->order()], '2097-11-09');

        self::assertSame(['order_id' => 1, 'paid_on' => '2097-10-12', 'amount' => 9984.0, 'paid' => true, 'notes' => []], $cover);
    }

    public function testPaymentTodayIsPaidAndInTheFutureIsOnlyScheduled(): void
    {
        $item = ['type' => 'pausal', 'due_date' => '2097-12-20', 'amount' => 9984.0];

        self::assertTrue(StandingOrders::cover($item, [$this->order()], '2097-12-12')['paid'], 'the payment day itself');
        $future = StandingOrders::cover($item, [$this->order()], '2097-11-09');
        self::assertFalse($future['paid']);
        self::assertSame('2097-12-12', $future['paid_on']);
        self::assertSame([['code' => 'standing_order_scheduled']], $future['notes']);
    }

    public function testAHigherOrderIsAnOverpaymentAndALowerOneAShortfall(): void
    {
        $item = ['type' => 'pausal', 'due_date' => '2097-10-20', 'amount' => 9162.0];

        $over = StandingOrders::cover($item, [$this->order(12, 9984.0)], '2097-11-09');
        self::assertSame([['code' => 'standing_order_overpaid', 'params' => ['diff' => 822.0]]], $over['notes']);

        $under = StandingOrders::cover($item, [$this->order(12, 9000.0)], '2097-11-09');
        self::assertSame([['code' => 'standing_order_underpaid', 'params' => ['diff' => 162.0]]], $under['notes']);

        // A payment to come carries both notes.
        $both = StandingOrders::cover($item, [$this->order(12, 9984.0)], '2097-10-01');
        self::assertSame(['standing_order_scheduled', 'standing_order_overpaid'], array_column($both['notes'], 'code'));
    }

    public function testADifferenceUnderACrownAndAnUnknownAmountAreNotReported(): void
    {
        $item = ['type' => 'pausal', 'due_date' => '2097-10-20', 'amount' => 9984.0];
        self::assertSame([], StandingOrders::cover($item, [$this->order(12, 9984.4)], '2097-11-09')['notes']);
        self::assertSame([], StandingOrders::cover(['amount' => null] + $item, [$this->order(12, 5.0)], '2097-11-09')['notes'], 'an item without an expected amount has nothing to compare with');
    }

    public function testEachPayableItemTypeMapsToItsOrderTypeAndOthersToNone(): void
    {
        $orders = [];
        foreach (StandingOrders::TYPES as $i => $type) {
            $orders[] = $this->order(12, 100.0, $type, '2097-01-01', null, $i + 1);
        }
        foreach (StandingOrders::ITEM_TYPES as $itemType => $orderType) {
            $cover = StandingOrders::cover(['type' => $itemType, 'due_date' => '2097-10-20', 'amount' => 100.0], $orders, '2097-11-09');
            self::assertNotNull($cover, $itemType);
            self::assertSame(array_search($orderType, StandingOrders::TYPES, true) + 1, $cover['order_id'], $itemType);
        }
        self::assertNull(StandingOrders::cover(['type' => 'dph', 'due_date' => '2097-10-20', 'amount' => 100.0], $orders, '2097-11-09'));
        self::assertNull(StandingOrders::cover(['type' => 'pausal', 'due_date' => '2097-10-20', 'amount' => 1.0], [], '2097-11-09'));
    }
}
