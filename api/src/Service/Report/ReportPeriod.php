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
}
