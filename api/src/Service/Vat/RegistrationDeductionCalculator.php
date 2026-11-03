<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

/**
 * Amounts and time windows of the VAT deduction on registration (§ 79 ZDPH) and of the opposite
 * reduction on deregistration (§ 79a ZDPH), in the wording valid from 1. 1. 2026 (unchanged in the
 * wording of 1. 1. 2027). Source: zákon č. 235/2004 Sb.,
 * https://www.zakonyprolidi.cz/cs/2004-235#p79 and #p79a ; adjustment of the deduction for long-term
 * assets § 78 – § 78d, https://www.zakonyprolidi.cz/cs/2004-235#p78 .
 *
 * Pure functions, no I/O. Amounts are in CZK with two decimals; the return rounds the total to whole CZK.
 *
 *   § 79/1   a person who became a payer may deduct the VAT of taxable supplies received in the 12 consecutive
 *            months before the day of registration, provided the supply is part of the business assets on that day;
 *   § 79/6   the deduction is claimed under § 72 – § 78d; for LONG-TERM assets the amount is computed like the
 *            one-off adjustment of § 78d odst. 2: one fifth (one tenth for buildings, units and land) of the input
 *            VAT times the years left in the adjustment period, the registration year included;
 *   § 79/7   claimed in the return of the period containing the day of registration;
 *   § 79a    on deregistration the deduction claimed for assets still held is reduced: inventory in full, long-term
 *            assets by the same one-off adjustment formula, other assets (deduction of at least 2 100 Kč acquired
 *            in the last 12 calendar months) by 1/12 of the deduction for every whole month of that period
 *            the asset was not yet in the business assets; the reduction goes to the last period of registration.
 */
final class RegistrationDeductionCalculator
{
    public const TYPE_INVENTORY = 'inventory';
    public const TYPE_LONG_TERM = 'long_term_asset';
    public const TYPE_OTHER = 'other';
    public const TYPES = [self::TYPE_INVENTORY, self::TYPE_LONG_TERM, self::TYPE_OTHER];

    /** Adjustment period of long-term assets in years: 5, or 10 for buildings, units and land (§ 78 odst. 3). */
    public const ADJUSTMENT_YEARS = [5, 10];

    /** Minimum deduction for the pro-rata reduction of "other" assets on deregistration (§ 79a odst. 3). */
    public const OTHER_ASSET_MIN_DEDUCTION = 2100.0;

    /**
     * The acquisition window of § 79 odst. 1: the 12 consecutive months before the day the person became a
     * payer, i.e. [registration date - 12 months, registration date - 1 day].
     *
     * @return array{start: string, end: string}
     */
    public static function window(string $registrationDate): array
    {
        $registration = new \DateTimeImmutable($registrationDate);
        return [
            'start' => $registration->modify('-12 months')->format('Y-m-d'),
            'end'   => $registration->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /** True when the supply was received inside the § 79/1 window. */
    public static function isInWindow(string $acquiredOn, string $registrationDate): bool
    {
        $window = self::window($registrationDate);
        return $acquiredOn >= $window['start'] && $acquiredOn <= $window['end'];
    }

    /**
     * Deduction on registration (§ 79) for one item. Zero when the asset is no longer in the business assets
     * on the day of registration (§ 79/1 písm. b).
     */
    public static function registrationAmount(string $type, float $vat, string $acquiredOn, string $registrationDate, ?int $adjustmentYears, bool $inBusinessAssets): float
    {
        if (!$inBusinessAssets || $vat <= 0) {
            return 0.0;
        }
        if ($type !== self::TYPE_LONG_TERM) {
            return round($vat, 2);
        }
        return round($vat / self::years($adjustmentYears) * self::remainingYears($acquiredOn, $adjustmentYears, (int) substr($registrationDate, 0, 4)), 2);
    }

    /**
     * Reduction on deregistration (§ 79a) for one item. `$vat` is the deduction claimed for inventory and other
     * assets, the full input VAT for a long-term asset (§ 78d odst. 2 works from the input VAT). Always >= 0.
     */
    public static function reductionAmount(string $type, float $vat, string $acquiredOn, string $deregistrationDate, ?int $adjustmentYears, bool $inBusinessAssets): float
    {
        if (!$inBusinessAssets || $vat <= 0) {
            return 0.0;
        }
        $lastDay = self::lastDayOfRegistration($deregistrationDate);
        return match ($type) {
            self::TYPE_INVENTORY => round($vat, 2),
            self::TYPE_LONG_TERM => round($vat / self::years($adjustmentYears) * self::remainingYears($acquiredOn, $adjustmentYears, (int) substr($lastDay, 0, 4)), 2),
            default              => self::otherAssetReduction($vat, $acquiredOn, $lastDay),
        };
    }

    /**
     * § 79a odst. 3: a deduction of at least 2 100 Kč on an asset acquired in the 11 calendar months before the
     * month of deregistration or in that month; 1/12 of the deduction per WHOLE calendar month of this 12-month
     * period in which the asset was not yet part of the business assets (the month of acquisition is not whole).
     */
    private static function otherAssetReduction(float $deduction, string $acquiredOn, string $lastDay): float
    {
        if ($deduction < self::OTHER_ASSET_MIN_DEDUCTION) {
            return 0.0;
        }
        $monthIndex = static fn (string $date): int => (int) substr($date, 0, 4) * 12 + (int) substr($date, 5, 2);
        $monthsBefore = $monthIndex($acquiredOn) - ($monthIndex($lastDay) - 11);
        if ($monthsBefore < 0) {
            return 0.0; // acquired before the 12-month period: no reduction under § 79a odst. 3
        }
        return round($deduction / 12 * min(11, $monthsBefore), 2);
    }

    /**
     * The last day of the registration: the day before the first day of the non-payer status. The reduction
     * belongs to the tax period containing it ("poslední zdaňovací období registrace", § 79a odst. 5).
     */
    public static function lastDayOfRegistration(string $deregistrationDate): string
    {
        return (new \DateTimeImmutable($deregistrationDate))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Years of the adjustment period left, the year of the registration / deregistration included (§ 78d odst. 2).
     * The period starts with the calendar year of the acquisition (§ 78 odst. 3).
     */
    private static function remainingYears(string $acquiredOn, ?int $adjustmentYears, int $eventYear): int
    {
        $years = self::years($adjustmentYears);
        return max(0, min($years, (int) substr($acquiredOn, 0, 4) + $years - $eventYear));
    }

    private static function years(?int $adjustmentYears): int
    {
        return in_array($adjustmentYears, self::ADJUSTMENT_YEARS, true) ? $adjustmentYears : 5;
    }
}
