<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Vat;

use MyInvoice\Service\Vat\VatStatus;
use MyInvoice\Service\Vat\VatStatusHistory;
use PHPUnit\Framework\TestCase;

final class VatStatusHistoryTest extends TestCase
{
    /** The owner's case: non-payer since forever, VAT payer from 2027-01-01. */
    private function registration(): VatStatusHistory
    {
        return new VatStatusHistory([
            ['valid_from' => '2000-01-01', 'status' => VatStatus::NON_PAYER],
            ['valid_from' => '2027-01-01', 'status' => VatStatus::PAYER],
        ]);
    }

    public function testStatusTheDayBeforeOnAndAfterAChange(): void
    {
        $h = $this->registration();

        self::assertSame(VatStatus::NON_PAYER, $h->statusAt('2026-12-31'), 'day before');
        self::assertSame(VatStatus::PAYER, $h->statusAt('2027-01-01'), 'on the change date');
        self::assertSame(VatStatus::PAYER, $h->statusAt('2027-01-02'), 'day after');
    }

    public function testFutureRowDoesNotApplyToday(): void
    {
        $h = $this->registration();

        // A row entered in November 2026 for 1.1.2027 is a scheduled change, not today's status.
        self::assertFalse($h->isPayerAt('2026-11-03'));
        self::assertSame(['valid_from' => '2027-01-01', 'status' => VatStatus::PAYER], $h->nextChangeAfter('2026-11-03'));
        self::assertNull($h->nextChangeAfter('2027-01-01'), 'nothing is scheduled once the last change is in force');
    }

    public function testSeededRowIsTheFloorForEarlierDates(): void
    {
        $h = VatStatusHistory::fromFlags(true, false);

        self::assertSame(VatStatus::PAYER, $h->statusAt('2000-01-01'));
        self::assertSame(VatStatus::PAYER, $h->statusAt('1995-06-15'), 'a date before the first row takes the first row');
        self::assertSame(VatStatus::PAYER, $h->statusAt('2099-12-31'));
    }

    public function testFlagsMapStatusToLegacyBooleans(): void
    {
        self::assertSame(['is_vat_payer' => false, 'is_identified' => true], VatStatusHistory::fromFlags(false, true)->flagsAt('2026-01-01'));
        self::assertSame(['is_vat_payer' => false, 'is_identified' => false], VatStatusHistory::fromFlags(false, false)->flagsAt('2026-01-01'));
        self::assertSame(VatStatus::IDENTIFIED, VatStatus::fromFlags(false, true));
        self::assertSame(VatStatus::PAYER, VatStatus::fromFlags(true, true), 'payer wins over a stale identified flag');
    }

    public function testRowOrderAndDateTimeValuesAreNormalised(): void
    {
        $h = new VatStatusHistory([
            ['valid_from' => '2027-01-01 00:00:00', 'status' => VatStatus::PAYER],
            ['valid_from' => '2000-01-01', 'status' => VatStatus::NON_PAYER],
        ]);

        self::assertSame(VatStatus::NON_PAYER, $h->statusAt('2026-12-31 23:59:59'));
        self::assertSame(VatStatus::PAYER, $h->statusAt('2027-01-01 00:00:00'));
    }

    public function testRedundantRowsDoNotCountAsAChange(): void
    {
        $h = new VatStatusHistory([
            ['valid_from' => '2000-01-01', 'status' => VatStatus::PAYER],
            ['valid_from' => '2026-06-01', 'status' => VatStatus::PAYER],
        ]);

        self::assertNull($h->nextChangeAfter('2026-01-01'), 'a row repeating the current status is not a change');
        self::assertTrue($h->hasPayerTimeBetween('2026-01-01', '2026-12-31'));
    }

    public function testPeriodInclusionRules(): void
    {
        $h = $this->registration();

        self::assertFalse($h->hasPayerTimeBetween('2026-12-01', '2026-12-31'), 'December 2026: no payer time');
        self::assertTrue($h->hasPayerTimeBetween('2027-01-01', '2027-01-31'), 'January 2027');
        self::assertTrue($h->hasPayerTimeBetween('2026-10-01', '2027-03-31'), 'a quarter that straddles the registration');
        self::assertTrue($h->hasPayerTimeBetween('2027-01-01', '2027-01-01'), 'a single payer day is enough');
        self::assertFalse($h->hasPayerTimeBetween('2026-01-01', '2026-12-31'), 'whole of 2026');
        self::assertTrue($h->hasPayerTimeBetween('2026-01-01', '2027-12-31'), 'payer for part of the year range');
        self::assertFalse($h->hasPayerTimeBetween('2027-02-01', '2027-01-01'), 'an inverted range is empty');
    }

    public function testIdentifiedPersonCountsAsHavingVatDutiesButNotAsPayer(): void
    {
        $h = new VatStatusHistory([
            ['valid_from' => '2000-01-01', 'status' => VatStatus::IDENTIFIED],
            ['valid_from' => '2027-01-01', 'status' => VatStatus::PAYER],
        ]);

        self::assertFalse($h->hasPayerTimeBetween('2026-01-01', '2026-12-31'));
        self::assertTrue($h->hasVatDutiesBetween('2026-01-01', '2026-12-31'));
        self::assertSame(VatStatus::IDENTIFIED, $h->statusAt('2026-05-05'));
        self::assertFalse((new VatStatusHistory([['valid_from' => '2000-01-01', 'status' => VatStatus::NON_PAYER]]))->hasVatDutiesBetween('2026-01-01', '2026-12-31'));
    }
}
