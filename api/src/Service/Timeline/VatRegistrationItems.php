<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Vat\VatStatusHistory;
use MyInvoice\Service\Vat\VatStatusSql;

/**
 * VAT registration duty of a non-payer (§ 6 and § 94 ZDPH).
 *
 * The turnover is watched per calendar year by the DUZP of the issued invoices (the definition of the dashboard
 * revenue: invoices, credit notes and tax documents that are issued, sent, reminded or paid; in CZK):
 *   - over 2 000 000 Kč (`vat_limit_low`) on day X: the person becomes a payer on 1 January of the next year
 *     (§ 6 odst. 1) and has to file the registration application within 10 working days of X (§ 94 odst. 1);
 *   - over 2 536 500 Kč (`vat_limit_high`) on day X2: a payer from the day after X2 (§ 6 odst. 2).
 * The status "payer from 1 January" lives in the dated VAT status history; the application itself is not tracked, so
 * the item can only be ticked off by hand.
 */
final class VatRegistrationItems
{
    /** Working days to file the registration application after the limit was exceeded (§ 94 odst. 1 ZDPH). */
    public const APPLICATION_WORKING_DAYS = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $constants,
    ) {}

    /**
     * @return list<array<string,mixed>> items due within [$from, $to]
     */
    public function items(int $supplierId, VatStatusHistory $history, \DateTimeImmutable $now, string $from, string $to): array
    {
        $today = $now->format('Y-m-d');
        $currentYear = (int) $now->format('Y');
        // The application can be due in the year after the limit was exceeded.
        $firstYear = (int) substr($from, 0, 4) - 1;
        $lastYear = (int) substr($to, 0, 4);
        $years = [];
        for ($year = $firstYear; $year <= $lastYear; $year++) {
            if (!$history->isPayerAt("$year-01-01")) {
                $years[] = $year;
            }
        }
        if ($years === []) {
            return [];
        }
        $daily = $this->dailyTurnover($supplierId, min($years) . '-01-01', max($years) . '-12-31');

        $items = [];
        foreach ($years as $year) {
            $limits = $this->constants->forYear($year);
            $low = (float) $limits['vat_limit_low'];
            $high = (float) $limits['vat_limit_high'];
            $nextStart = ($year + 1) . '-01-01';
            $payerNextYear = $history->isPayerAt($nextStart);

            [$lowDay, $highDay, $total] = $this->crossings($daily, $year, $low, $high);
            if ($lowDay !== null && !$history->isPayerAt($lowDay)) {
                $due = CzechWorkingDays::addWorkingDays(new \DateTimeImmutable($lowDay), self::APPLICATION_WORKING_DAYS)->format('Y-m-d');
                if ($due >= $from && $due <= $to) {
                    $items[] = TimelineItem::make("vat_registration:$year", 'vat_registration', 'registration', $due, [
                        'start'  => $lowDay,
                        'params' => ['year' => $year, 'exceeded_on' => $lowDay, 'limit' => $low, 'payer_from' => $nextStart, 'turnover' => $total],
                        'notes'  => $payerNextYear ? [] : ['registration_not_in_history'],
                        'legal'  => '§ 6 odst. 1, § 94 odst. 1 ZDPH',
                        'links'  => ['vat_status'],
                    ]);
                }
            }
            if ($highDay !== null && !$history->isPayerAt($highDay) && $highDay >= $from && $highDay <= $to) {
                $items[] = TimelineItem::make("vat_limit_immediate:$year", 'vat_limit_immediate', 'registration', $highDay, [
                    'params' => ['year' => $year, 'exceeded_on' => $highDay, 'limit' => $high],
                    'legal'  => '§ 6 odst. 2 ZDPH',
                    'links'  => ['vat_status'],
                    'severity' => 'warning',
                    'info'   => true,
                ]);
            }
            if ($lowDay === null && $year === $currentYear && $payerNextYear && !$history->isPayerAt($today)) {
                $expected = $this->expectedItem($daily, $year, $low, $nextStart, $today, $from, $to);
                if ($expected !== null) {
                    $items[] = $expected;
                }
            }
        }
        return $items;
    }

    /**
     * The registration is already planned (payer from 1 January in the history) but the limit has not been passed yet:
     * the remaining amount and an estimated deadline. Turnover counts by DUZP, and services are typically invoiced with
     * the last day of the month as DUZP, so the estimate is the end of the first month in which the monthly average of the
     * completed months of the year would carry the turnover over the limit (not a day-by-day pace, which would point to
     * the middle of a month no invoice is dated in).
     *
     * @param array<string,float> $daily
     * @return array<string,mixed>|null
     */
    private function expectedItem(array $daily, int $year, float $limit, string $payerFrom, string $today, string $from, string $to): ?array
    {
        $yearStart = "$year-01-01";
        $monthStart = substr($today, 0, 7) . '-01';
        $sofar = 0.0;
        $completed = 0.0;
        foreach ($daily as $day => $amount) {
            if ($day >= $yearStart && $day <= $today) {
                $sofar += $amount;
                if ($day < $monthStart) {
                    $completed += $amount;
                }
            }
        }
        $remaining = max(0.0, $limit - $sofar);
        $currentMonth = (int) substr($today, 5, 2);
        // The monthly average of the completed months; in January the month so far stands in for it.
        $perMonth = $currentMonth > 1 ? $completed / ($currentMonth - 1) : $sofar;
        $month = $perMonth > 0 ? $currentMonth - 1 + max(1, (int) ceil(($limit - $completed) / $perMonth)) : 13;
        $capped = $month > 12;
        $estimate = $capped ? "$year-12-31" : (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('Y-m-t');
        $due = CzechWorkingDays::addWorkingDays(new \DateTimeImmutable($estimate), self::APPLICATION_WORKING_DAYS)->format('Y-m-d');
        if ($due < $from || $due > $to) {
            return null;
        }
        return TimelineItem::make("vat_registration_expected:$year", 'vat_registration_expected', 'registration', $due, [
            'start'  => $estimate,
            'params' => [
                'year' => $year, 'limit' => $limit, 'turnover' => round($sofar, 2), 'remaining' => round($remaining, 2),
                'estimated_on' => $estimate, 'payer_from' => $payerFrom,
            ],
            'notes'  => $capped ? ['registration_expected_voluntary'] : [],
            'legal'  => '§ 6 odst. 1, § 94 odst. 1 ZDPH',
            'amount' => round($remaining, 2),
            'amount_estimated' => true,
            'links'  => ['vat_status'],
        ]);
    }

    /**
     * Days on which the calendar-year turnover first exceeded the two limits, and the turnover at the end of the year.
     *
     * @param array<string,float> $daily DUZP => turnover of the day, ascending
     * @return array{0: ?string, 1: ?string, 2: float}
     */
    private function crossings(array $daily, int $year, float $low, float $high): array
    {
        $lowDay = $highDay = null;
        $sum = 0.0;
        foreach ($daily as $day => $amount) {
            if (substr($day, 0, 4) !== (string) $year) {
                continue;
            }
            $sum += $amount;
            $lowDay ??= $sum > $low ? $day : null;
            $highDay ??= $sum > $high ? $day : null;
        }
        return [$lowDay, $highDay, round($sum, 2)];
    }

    /**
     * Turnover per DUZP day in CZK: the amount a person without VAT duties invoiced (with VAT, which a non-payer does
     * not charge anyway), the base for a payer day.
     *
     * @return array<string,float> ascending by day
     */
    private function dailyTurnover(int $supplierId, string $from, string $to): array
    {
        $amount = VatStatusSql::amount('i');
        $day = VatStatusSql::documentDate('i');
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$day} AS d, SUM({$amount} * COALESCE(IF(cur.code = 'CZK', 1, i.exchange_rate), 1)) AS total
               FROM invoices i
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                AND i.invoice_type IN ('invoice', 'credit_note', 'tax_document')
                AND {$day} BETWEEN ? AND ?
           GROUP BY d
           ORDER BY d"
        );
        $stmt->execute([$supplierId, $from, $to]);
        $daily = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $daily[(string) $r['d']] = (float) $r['total'];
        }
        return $daily;
    }
}
