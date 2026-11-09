<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

/**
 * Standing orders (trvalé příkazy) the owner pays regular obligations by. The application cannot see the bank account,
 * so the owner records the order and the timeline takes the items it covers as paid on the payment day.
 *
 * An item with due date D is covered by an order when the order is valid on its payment date P: the day of the month
 * of the order (clamped to the length of the month — 31 in February is the 28th) in the latest month in which that day
 * is not after D, and not more than 31 days before it. Among several orders covering the same item the one that
 * started last wins (an old order that was never ended must not hide the new one); the order id breaks a tie.
 */
final class StandingOrders
{
    /** Order types, the value of `timeline_standing_orders.type`. */
    public const TYPES = ['pausal', 'insurance_social', 'insurance_health', 'income_tax_advance'];

    /** Timeline item type => the order type that pays it. */
    public const ITEM_TYPES = [
        'pausal'                   => 'pausal',
        'insurance_advance_social' => 'insurance_social',
        'insurance_advance_health' => 'insurance_health',
        'income_tax_advance'       => 'income_tax_advance',
    ];

    private const MAX_GAP_DAYS = 31;

    /**
     * The date the order pays the obligation due on $dueDate, or null when no payment of the order falls into the
     * 31 days up to the due date.
     */
    public static function paymentDate(int $dayOfMonth, string $dueDate): ?string
    {
        $due = new \DateTimeImmutable($dueDate);
        foreach ([$due, $due->modify('first day of last month')] as $month) {
            $payment = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min($dayOfMonth, (int) $month->format('t')));
            if ($payment <= $due) {
                return $payment >= $due->modify('-' . self::MAX_GAP_DAYS . ' days') ? $payment->format('Y-m-d') : null;
            }
        }
        return null;
    }

    /**
     * The order that pays an item of the given order type due on $dueDate.
     *
     * @param list<array{id:int, type:string, amount:float, day_of_month:int, valid_from:string, valid_to:?string}> $orders
     * @return array{order_id:int, paid_on:string, amount:float}|null
     */
    public static function match(array $orders, string $type, string $dueDate): ?array
    {
        $best = null;
        foreach ($orders as $order) {
            if ($order['type'] !== $type) {
                continue;
            }
            $paidOn = self::paymentDate($order['day_of_month'], $dueDate);
            if ($paidOn === null || $paidOn < $order['valid_from'] || ($order['valid_to'] !== null && $paidOn > $order['valid_to'])) {
                continue;
            }
            if ($best === null || [$order['valid_from'], $order['id']] > [$best['valid_from'], $best['id']]) {
                $best = $order + ['paid_on' => $paidOn];
            }
        }
        return $best === null ? null : ['order_id' => $best['id'], 'paid_on' => $best['paid_on'], 'amount' => $best['amount']];
    }

    /**
     * The order covering a timeline item (null for an item type no order can pay), with the notes the covering adds:
     * a payment still to come, and a difference between the order and the expected amount of at least one crown.
     *
     * @param array<string,mixed> $item a computed timeline item
     * @param list<array<string,mixed>> $orders
     * @return array{order_id:int, paid_on:string, amount:float, paid:bool, notes:list<array{code:string, params?:array<string,mixed>}>}|null
     */
    public static function cover(array $item, array $orders, string $today): ?array
    {
        $type = self::ITEM_TYPES[$item['type']] ?? null;
        $match = $type === null ? null : self::match($orders, $type, $item['due_date']);
        if ($match === null) {
            return null;
        }
        $paid = $match['paid_on'] <= $today;
        $notes = $paid ? [] : [['code' => 'standing_order_scheduled']];
        $diff = $item['amount'] === null ? 0.0 : round($match['amount'] - (float) $item['amount'], 2);
        if (abs($diff) >= 0.5) {
            $notes[] = ['code' => $diff > 0 ? 'standing_order_overpaid' : 'standing_order_underpaid', 'params' => ['diff' => abs($diff)]];
        }
        return $match + ['paid' => $paid, 'notes' => $notes];
    }
}
