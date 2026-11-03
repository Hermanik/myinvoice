<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Inclusive date range of a VAT filing period: one calendar month, or the whole
 * quarter containing $month for the quarterly period.
 */
final class ReportPeriod
{
    /** @return array{0: string, 1: string} [first day, last day] as Y-m-d */
    public static function range(int $year, int $month, string $period): array
    {
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $start = sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1);
            $lastMonthOfQuarter = sprintf('%04d-%02d-01', $year, $quarter * 3);
            return [$start, (new \DateTimeImmutable($lastMonthOfQuarter))->modify('last day of this month')->format('Y-m-d')];
        }
        $start = sprintf('%04d-%02d-01', $year, $month);
        return [$start, (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d')];
    }

    /**
     * Statutory filing deadline of a VAT period (DPH return, KH, SH): the 25th of the month
     * after the period ends, moved to the next working day when it falls on a weekend or
     * holiday (§ 33/4 DŘ). A VAT liability is due on the same day (§ 98/1 ZDPH).
     */
    public static function filingDeadline(int $year, int $month, string $period): string
    {
        [, $end] = self::range($year, $month, $period);
        $nextMonth = (new \DateTimeImmutable($end))->modify('first day of next month');
        return CzechWorkingDays::shiftToWorkingDay(
            new \DateTimeImmutable($nextMonth->format('Y-m-') . '25')
        )->format('Y-m-d');
    }

    /**
     * Basic deadline of the income tax return (DPFO/DPPO) for a calendar tax year:
     * 1 April of the following year (§ 136/1 DŘ), moved to the next working day (§ 33/4 DŘ).
     */
    public static function incomeTaxDeadline(int $taxYear): string
    {
        return CzechWorkingDays::shiftToWorkingDay(
            new \DateTimeImmutable(sprintf('%04d-04-01', $taxYear + 1))
        )->format('Y-m-d');
    }
}
