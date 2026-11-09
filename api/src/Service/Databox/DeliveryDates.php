<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Delivery rules of ISDS, as the application needs them to explain a message to the user.
 *
 * Message states (WS spec 1.5): 4 = put into the box (not yet delivered), 5 = delivered by fiction,
 * 6 = delivered by login of a user who may read it, 7 = read, 8 = undeliverable (box closed), 9 = content erased.
 */
final class DeliveryDates
{
    public const STATE_REJECTED = 3;
    public const STATE_DELIVERED_TO_BOX = 4;
    public const STATE_FICTION = 5;
    public const STATE_DELIVERED = 6;
    public const STATE_READ = 7;
    public const STATE_UNDELIVERABLE = 8;
    public const STATE_ERASED = 9;

    /** The states of a received message in the order it passes through them: in the box, by fiction, by login, read. */
    public const PROGRESS = [self::STATE_DELIVERED_TO_BOX, self::STATE_FICTION, self::STATE_DELIVERED, self::STATE_READ];

    /**
     * Presentation-only state (never stored): a message known from a notification e-mail only, still "put into the box" in
     * our data, whose fiction day has passed. Nobody told the application whether it was opened earlier in the portal, but
     * at the latest it is delivered by fiction.
     */
    public const STATE_NAME_PRESUMED_DELIVERED = 'delivered_presumed';

    /** UI state name of an ISDS message state. */
    public static function stateName(?int $status): string
    {
        return match ($status) {
            null => 'unknown',
            self::STATE_DELIVERED_TO_BOX => 'in_box',
            self::STATE_FICTION => 'fiction',
            self::STATE_DELIVERED, self::STATE_READ => 'delivered',
            self::STATE_UNDELIVERABLE => 'undeliverable',
            self::STATE_ERASED => 'erased',
            self::STATE_REJECTED => 'rejected',
            default => 'other',
        };
    }

    /**
     * The day a message that exists only as a notification e-mail was delivered at the latest, or null when that does not
     * apply (the box system confirmed it with a real state, it is not in state 4, the sender excluded the fiction, the
     * delivery time is unknown, or the fiction day is not over yet).
     *
     * @param array<string,mixed> $message stored row (status, source, delivery_time, allow_subst_delivery)
     */
    public static function presumedDeliveryDate(array $message, \DateTimeInterface $now): ?\DateTimeImmutable
    {
        if (($message['status'] ?? null) !== self::STATE_DELIVERED_TO_BOX || ($message['source'] ?? null) !== 'email') {
            return null;
        }
        $fiction = self::fictionDateOf($message);
        return $fiction !== null && self::isBeforeToday($fiction, $now) ? $fiction : null;
    }

    /** The fiction day of a message put into the box on $deliveredToBox is before today (that day itself is not over until midnight). */
    public static function isFictionOver(\DateTimeInterface $deliveredToBox, \DateTimeInterface $now): bool
    {
        return self::isBeforeToday(self::fictionDate($deliveredToBox), $now);
    }

    /**
     * The fiction day of a stored message, or null when it has none: the delivery time is unknown or the sender excluded
     * the fiction (dmAllowSubstDelivery = false).
     *
     * @param array<string,mixed> $message stored row (delivery_time, allow_subst_delivery)
     */
    public static function fictionDateOf(array $message): ?\DateTimeImmutable
    {
        if (($message['allow_subst_delivery'] ?? true) === false || ($message['delivery_time'] ?? null) === null) {
            return null;
        }
        return self::fictionDate(new \DateTimeImmutable((string) $message['delivery_time']));
    }

    private static function isBeforeToday(\DateTimeInterface $day, \DateTimeInterface $now): bool
    {
        return $day < \DateTimeImmutable::createFromInterface($now)->setTime(0, 0);
    }

    /**
     * Day when a message put into the box on $deliveredToBox is delivered by fiction when nobody who may read
     * it logs in before: the first WORKING day on or after delivery date + 10 days (WS spec 1.8.3, in force since
     * 2023-01-02). Applies unless the OVM sender disabled the fiction (dmAllowSubstDelivery = false).
     */
    public static function fictionDate(\DateTimeInterface $deliveredToBox): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromInterface($deliveredToBox)->setTime(0, 0);
        return CzechWorkingDays::shiftToWorkingDay($day->modify('+10 days'));
    }

    /** ISDS did not take the message: rejected (e.g. by the virus check) or undeliverable. Such a send carried nothing. */
    public static function isFailed(?int $status): bool
    {
        return $status === self::STATE_REJECTED || $status === self::STATE_UNDELIVERABLE;
    }

    /** Legally delivered: by fiction, by login, or read. */
    public static function isDelivered(?int $status): bool
    {
        return in_array($status, [self::STATE_FICTION, self::STATE_DELIVERED, self::STATE_READ], true);
    }

    /** True when the message is not yet legally delivered (reading it with a read-capable login delivers it). */
    public static function isUndelivered(?int $status): bool
    {
        return $status === self::STATE_DELIVERED_TO_BOX;
    }

    /**
     * The message content can be downloaded only after delivery; states 4 and 5 are refused by ISDS (error 1222), so a
     * read-capable login has to "log in" (list the received messages) first.
     */
    public static function needsLoginBeforeDownload(?int $status): bool
    {
        return $status === self::STATE_DELIVERED_TO_BOX || $status === self::STATE_FICTION;
    }
}
