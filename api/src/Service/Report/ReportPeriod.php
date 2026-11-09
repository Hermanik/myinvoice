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
     * Deadline of the income tax return (DPFO/DPPO) for a calendar tax year filed on PAPER: three months after
     * the end of the tax period, i.e. 1 April of the following year (§ 136 odst. 1 DŘ), moved to the next
     * working day (§ 33 odst. 4 DŘ).
     */
    public static function incomeTaxPaperDeadline(int $taxYear): string
    {
        return self::incomeTaxDeadlineOn($taxYear, '04-01');
    }

    /**
     * Deadline of the income tax return (DPFO/DPPO) for a calendar tax year filed ELECTRONICALLY: § 136
     * odst. 2 písm. a) DŘ (since 1. 1. 2021) extends the three-month period by one month, i.e. 1 May of the
     * following year — moved to the next working day (§ 33 odst. 4 DŘ; 1 May is a public holiday, so it is
     * always the 2nd or later: 4 May 2026, 3 May 2027). Whether the electronic filing is mandatory (data box)
     * or voluntary does not matter. It does not apply to a return filed by a tax adviser or lawyer
     * (§ 136 odst. 2 písm. b): 1 July) or by a taxpayer with a mandatory audit — those are not modelled.
     * The tax itself is due on the last day of the deadline (§ 135 odst. 3 DŘ).
     *
     * Source: Generální finanční ředitelství, metodický pokyn k aplikaci lhůty pro podání daňového přiznání,
     * č. j. 22387/21/7700-10123-500852 (financnisprava.gov.cz).
     */
    public static function incomeTaxElectronicDeadline(int $taxYear): string
    {
        return self::incomeTaxDeadlineOn($taxYear, '05-01');
    }

    /**
     * Deadline of the income tax return (DPFO/DPPO) for a calendar tax year when the return is filed by a tax adviser or
     * a lawyer: 1 July of the following year (§ 136 odst. 2 písm. b) DŘ), moved to the next working day. It applies
     * only to returns covered by a power of attorney filed with the tax office by the end of the regular
     * (3-month) deadline, i.e. by 1 April.
     */
    public static function incomeTaxAdvisorDeadline(int $taxYear): string
    {
        return self::incomeTaxDeadlineOn($taxYear, '07-01');
    }

    private static function incomeTaxDeadlineOn(int $taxYear, string $monthDay): string
    {
        return CzechWorkingDays::shiftToWorkingDay(
            new \DateTimeImmutable(sprintf('%04d-%s', $taxYear + 1, $monthDay))
        )->format('Y-m-d');
    }
}
