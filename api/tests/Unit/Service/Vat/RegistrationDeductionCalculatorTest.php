<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Vat;

use MyInvoice\Service\Vat\RegistrationDeductionCalculator as Calc;
use PHPUnit\Framework\TestCase;

/** § 79 / § 79a ZDPH: the 12-month window and the amounts of the deduction on registration and of its reduction. */
final class RegistrationDeductionCalculatorTest extends TestCase
{
    public function testWindowIsTheTwelveMonthsBeforeTheDayOfRegistration(): void
    {
        self::assertSame(['start' => '2026-01-01', 'end' => '2026-12-31'], Calc::window('2027-01-01'));
        self::assertSame(['start' => '2026-07-15', 'end' => '2027-07-14'], Calc::window('2027-07-15'));
    }

    public function testWindowBoundaries(): void
    {
        self::assertTrue(Calc::isInWindow('2026-01-01', '2027-01-01'), 'the first day of the window');
        self::assertTrue(Calc::isInWindow('2026-12-31', '2027-01-01'), 'the day before the registration');
        self::assertFalse(Calc::isInWindow('2025-12-31', '2027-01-01'), 'one day too early');
        self::assertFalse(Calc::isInWindow('2027-01-01', '2027-01-01'), 'the day of the registration is already a payer day');
    }

    public function testInventoryAndOtherAssetsAreDeductedInFull(): void
    {
        self::assertSame(2100.0, Calc::registrationAmount(Calc::TYPE_INVENTORY, 2100.0, '2026-05-01', '2027-01-01', null, true));
        self::assertSame(840.5, Calc::registrationAmount(Calc::TYPE_OTHER, 840.5, '2026-11-20', '2027-01-01', null, true));
    }

    public function testAssetThatIsNoLongerBusinessPropertyHasNoDeduction(): void
    {
        self::assertSame(0.0, Calc::registrationAmount(Calc::TYPE_INVENTORY, 2100.0, '2026-05-01', '2027-01-01', null, false));
        self::assertSame(0.0, Calc::registrationAmount(Calc::TYPE_LONG_TERM, 21000.0, '2026-05-01', '2027-01-01', 5, false));
    }

    public function testLongTermAssetIsDeductedForTheYearsLeftOfTheAdjustmentPeriod(): void
    {
        // Acquired in 2026, period 2026-2030, registration in 2027: 4 of 5 years left (2027 counts).
        self::assertSame(16800.0, Calc::registrationAmount(Calc::TYPE_LONG_TERM, 21000.0, '2026-09-01', '2027-01-01', 5, true));
        // Buildings / land: 10 years, 9 left.
        self::assertSame(90000.0, Calc::registrationAmount(Calc::TYPE_LONG_TERM, 100000.0, '2026-03-01', '2027-01-01', 10, true));
        // Registration mid-year, acquired in the same calendar year: nothing of the period is used up.
        self::assertSame(21000.0, Calc::registrationAmount(Calc::TYPE_LONG_TERM, 21000.0, '2027-03-01', '2027-07-01', 5, true));
        // An unknown period length falls back to 5 years.
        self::assertSame(16800.0, Calc::registrationAmount(Calc::TYPE_LONG_TERM, 21000.0, '2026-09-01', '2027-01-01', null, true));
    }

    public function testReductionOfInventoryIsTheWholeDeduction(): void
    {
        self::assertSame(2100.0, Calc::reductionAmount(Calc::TYPE_INVENTORY, 2100.0, '2026-05-01', '2029-01-01', null, true));
        self::assertSame(0.0, Calc::reductionAmount(Calc::TYPE_INVENTORY, 2100.0, '2026-05-01', '2029-01-01', null, false));
    }

    public function testReductionOfLongTermAssetUsesTheYearsLeftAtTheLastDayOfRegistration(): void
    {
        // Deregistered from 1. 1. 2029: the last registered day is 31. 12. 2028; period 2026-2030 leaves 3 years.
        self::assertSame(12600.0, Calc::reductionAmount(Calc::TYPE_LONG_TERM, 21000.0, '2026-09-01', '2029-01-01', 5, true));
        // Past the end of the period: nothing to reduce.
        self::assertSame(0.0, Calc::reductionAmount(Calc::TYPE_LONG_TERM, 21000.0, '2026-09-01', '2032-01-01', 5, true));
    }

    public function testReductionOfOtherAssetIsOneTwelfthPerMonthTheAssetWasNotYetOwned(): void
    {
        // Window of 12 calendar months: January - December 2028 (deregistered from 1. 1. 2029).
        self::assertSame(2250.0, Calc::reductionAmount(Calc::TYPE_OTHER, 3000.0, '2028-10-15', '2029-01-01', null, true), 'bought in October: 9 whole months before');
        self::assertSame(2750.0, Calc::reductionAmount(Calc::TYPE_OTHER, 3000.0, '2028-12-20', '2029-01-01', null, true), 'bought in the last month: 11 months');
        self::assertSame(0.0, Calc::reductionAmount(Calc::TYPE_OTHER, 3000.0, '2028-01-20', '2029-01-01', null, true), 'bought in the first month: owned the whole period');
        self::assertSame(0.0, Calc::reductionAmount(Calc::TYPE_OTHER, 3000.0, '2027-12-31', '2029-01-01', null, true), 'older than the 12 months: § 79a odst. 3 does not apply');
    }

    public function testReductionOfOtherAssetNeedsADeductionOfAtLeast2100(): void
    {
        self::assertSame(0.0, Calc::reductionAmount(Calc::TYPE_OTHER, 2099.0, '2028-12-20', '2029-01-01', null, true));
        self::assertSame(1925.0, Calc::reductionAmount(Calc::TYPE_OTHER, 2100.0, '2028-12-20', '2029-01-01', null, true));
    }

    public function testLastDayOfRegistrationIsTheDayBeforeTheNonPayerStatus(): void
    {
        self::assertSame('2028-12-31', Calc::lastDayOfRegistration('2029-01-01'));
        self::assertSame('2028-02-29', Calc::lastDayOfRegistration('2028-03-01'));
    }
}
