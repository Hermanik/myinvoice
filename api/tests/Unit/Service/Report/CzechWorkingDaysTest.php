<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\CzechWorkingDays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Shift of filing deadlines to a working day (§ 33 odst. 4 of the Tax Procedure Code)
 * + the Czech public holidays including the movable Easter ones.
 */
final class CzechWorkingDaysTest extends TestCase
{
    /** @return list<array{0:string, 1:string, 2:string}> [input, expected shift, label] */
    public static function shiftProvider(): array
    {
        return [
            // The case that motivated the fix: KH 06/2026 — 25. 7. 2026 is a Saturday.
            ['2026-07-25', '2026-07-27', 'sobota → pondělí'],
            ['2026-07-26', '2026-07-27', 'neděle → pondělí'],
            ['2026-07-27', '2026-07-27', 'pracovní den se nemění'],
            // 25. 10. 2026 is a Sunday, Monday 26. 10. is a working day.
            ['2026-10-25', '2026-10-26', 'neděle → pondělí'],
            // 28. 10. (a public holiday) 2026 falls on a Wednesday.
            ['2026-10-28', '2026-10-29', 'svátek uprostřed týdne → čtvrtek'],
            // Christmas 2026: 25. 12. Friday (holiday), 26. 12. Saturday (holiday), 27. 12. Sunday.
            ['2026-12-25', '2026-12-28', 'vánoční řetěz svátků a víkendu → pondělí'],
            // 1. 1. 2027 is a Friday (holiday) → Monday 4. 1.
            ['2027-01-01', '2027-01-04', 'Nový rok pátek → pondělí'],
            // Easter 2026: Sunday 5. 4. → Good Friday 3. 4., Monday 6. 4.
            ['2026-04-03', '2026-04-07', 'Velký pátek → úterý po Velikonočním pondělí'],
            ['2026-04-06', '2026-04-07', 'Velikonoční pondělí → úterý'],
            // An ordinary 25th on a working day — no shift (KH 05/2026, a Monday).
            ['2026-05-25', '2026-05-25', 'pondělí beze změny'],
        ];
    }

    #[DataProvider('shiftProvider')]
    public function testShiftToWorkingDay(string $input, string $expected, string $label): void
    {
        $shifted = CzechWorkingDays::shiftToWorkingDay(new \DateTimeImmutable($input));
        $this->assertSame($expected, $shifted->format('Y-m-d'), $label);
    }

    public function testEasterSunday(): void
    {
        // Reference dates of Easter Sunday (Gregorian calendar).
        $this->assertSame('2024-03-31', CzechWorkingDays::easterSunday(2024)->format('Y-m-d'));
        $this->assertSame('2025-04-20', CzechWorkingDays::easterSunday(2025)->format('Y-m-d'));
        $this->assertSame('2026-04-05', CzechWorkingDays::easterSunday(2026)->format('Y-m-d'));
        $this->assertSame('2027-03-28', CzechWorkingDays::easterSunday(2027)->format('Y-m-d'));
        $this->assertSame('2038-04-25', CzechWorkingDays::easterSunday(2038)->format('Y-m-d'));
    }

    public function testFixedHolidays(): void
    {
        foreach (['01-01', '05-01', '05-08', '07-05', '07-06', '09-28', '10-28', '11-17', '12-24', '12-25', '12-26'] as $md) {
            $this->assertTrue(
                CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable("2026-{$md}")),
                "2026-{$md} má být svátek",
            );
        }
        $this->assertFalse(CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable('2026-07-27')));
    }

    /** @return list<array{0:string, 1:int, 2:string, 3:string}> [start, days, expected, label] */
    public static function addProvider(): array
    {
        return [
            // The registration deadline of 30. 11. 2026 (a Monday): ten working days, no holiday in between.
            ['2026-11-30', 10, '2026-12-14', 'ten working days across two weekends'],
            // Christmas (24.-26. 12.), a weekend and New Year (1. 1. 2027) in the way.
            ['2026-12-18', 10, '2027-01-06', 'holidays and a weekend are skipped'],
            // The starting day itself does not count, even a weekend one.
            ['2026-11-28', 1, '2026-11-30', 'Saturday + 1 working day = Monday'],
            ['2026-11-30', 0, '2026-11-30', 'zero days leave the date alone'],
        ];
    }

    #[DataProvider('addProvider')]
    public function testAddWorkingDays(string $start, int $days, string $expected, string $label): void
    {
        $this->assertSame($expected, CzechWorkingDays::addWorkingDays(new \DateTimeImmutable($start), $days)->format('Y-m-d'), $label);
    }

    public function testNthWorkingDayOfMonth(): void
    {
        // January 2027: 1. 1. is a holiday (Friday), 2.-3. weekend -> 4., 5., 6., 7., 8. are the first five working days.
        $this->assertSame('2027-01-08', CzechWorkingDays::nthWorkingDayOfMonth(new \DateTimeImmutable('2027-01-20'), 5)->format('Y-m-d'));
        // December 2026 starts on Tuesday: the 1st is a working day and counts.
        $this->assertSame('2026-12-01', CzechWorkingDays::nthWorkingDayOfMonth(new \DateTimeImmutable('2026-12-15'), 1)->format('Y-m-d'));
        $this->assertSame('2026-12-07', CzechWorkingDays::nthWorkingDayOfMonth(new \DateTimeImmutable('2026-12-31'), 5)->format('Y-m-d'));
    }
}
