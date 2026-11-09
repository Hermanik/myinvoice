<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ObligationTimelineRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Report\ReportPeriod;
use MyInvoice\Service\Tax\FiledReturnChain;
use MyInvoice\Service\Tax\TaxCalendarService;
use MyInvoice\Service\Tax\VatPaymentService;
use MyInvoice\Service\Vat\VatStatusHistory;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Timeline of everything the owner has to do (or should do) for taxes, insurance and invoicing, with the deadlines and
 * what is already done. Computed on the fly from the supplier's settings and data — the same rules for every
 * supplier — and enriched with the manual marks and the user's own items (migration 9011).
 *
 * Item types (`type`, the key of the title in the web catalogue) and the rule of their date:
 *
 *   dph / kh / sh        {@see TaxCalendarService}: 25th of the month after the period, next working day; done = filed
 *   vat_payment          the same date as the DPH return of a payer period; done = paid (VatPaymentService)
 *   income_tax           income tax return, electronic deadline (the paper and the adviser one are in the params);
 *                        not for a year in the flat-tax regime; done = filed
 *   pausal               flat-tax advance on the 20th, next working day; manual
 *   vat_registration     {@see VatRegistrationItems}: 10 working days after the 2 M turnover was passed; manual
 *   vat_registration_expected, vat_limit_immediate   see there
 *   flat_tax_lost, income_tax_advance(_info), deduction_documents   {@see IncomeTaxItems}
 *   insurance_*          {@see InsuranceItems}; manual
 *   invoicing            5th working day of the month after; done = an invoice with a DUZP in the month exists
 *   custom               the user's own item
 *
 * Status: done (automatic evidence or a manual mark), overdue, due_soon (within 7 days), upcoming, info (informational,
 * never overdue) and unverified (the application has no evidence and the item is more than 30 days past — it is not
 * claimed to be missed, the owner is asked to confirm).
 */
final class ObligationTimelineService
{
    public const DUE_SOON_DAYS = 7;
    public const UNVERIFIED_AFTER_DAYS = 30;
    public const MAX_RANGE_DAYS = 1830;
    private const INVOICING_WORKING_DAY = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly VatStatusService $vatStatus,
        private readonly TaxCalendarService $calendar,
        private readonly VatPaymentService $vatPayments,
        private readonly TaxYearFacts $facts,
        private readonly VatRegistrationItems $registration,
        private readonly InsuranceItems $insurance,
        private readonly IncomeTaxItems $incomeTax,
        private readonly ObligationTimelineRepository $repo,
    ) {}

    /**
     * Default range: 30 days back until the end of the next year.
     *
     * @return array{0: string, 1: string}
     */
    public static function defaultRange(\DateTimeImmutable $now): array
    {
        return [$now->modify('-30 days')->format('Y-m-d'), ((int) $now->format('Y') + 1) . '-12-31'];
    }

    /**
     * @return array{today: string, from: string, to: string, items: list<array<string,mixed>>, summary: array<string,mixed>}
     */
    public function build(int $supplierId, \DateTimeImmutable $now, string $from, string $to): array
    {
        $today = $now->setTime(0, 0);
        $supplier = $this->supplier($supplierId);
        $isFo = ($supplier['taxpayer_type'] ?? '') === 'fo';
        $history = $this->vatStatus->history($supplierId);

        $regimes = [];
        if ($isFo) {
            $years = range((int) substr($from, 0, 4) - 2, (int) substr($to, 0, 4));
            $regimes = $this->facts->regimes($supplierId, (string) ($supplier['flat_tax_band'] ?? 'none'), $history, $today, $years);
        }

        $items = [
            ...$this->calendarItems($supplierId, $supplier, $history, $regimes, $today, $from, $to),
            ...$this->registration->items($supplierId, $history, $today, $from, $to),
            ...($isFo ? $this->insurance->items($supplierId, $regimes, $from, $to) : []),
            ...($isFo ? $this->incomeTax->items($supplierId, $regimes, $history, $today, $from, $to) : []),
            ...$this->invoicingItems($supplierId, $today, $from, $to),
        ];

        $marks = $this->repo->marks($supplierId);
        $todayYmd = $today->format('Y-m-d');
        $items = array_map(fn (array $i): array => $this->finish($i, $marks[$i['key']] ?? null, $todayYmd), $items);
        foreach ($this->repo->customItemsBetween($supplierId, $from, $to) as $custom) {
            $items[] = $this->customItem($custom, $todayYmd);
        }

        $order = array_flip(ObligationTimelineRepository::CATEGORIES);
        usort($items, static fn (array $a, array $b): int => [$a['due_date'], $order[$a['category']] ?? 99, $a['key']] <=> [$b['due_date'], $order[$b['category']] ?? 99, $b['key']]);

        return [
            'today'   => $todayYmd,
            'from'    => $from,
            'to'      => $to,
            'items'   => $items,
            'summary' => $this->summary($items),
        ];
    }

    // ── items from the tax calendar ─────────────────────────────────────────

    /**
     * DPH / KH / SH / income tax / flat-tax advances of the calendar and the VAT payments of the payer periods.
     *
     * @param array<string,mixed> $supplier
     * @param array<int, array{regime:string}> $regimes
     * @return list<array<string,mixed>>
     */
    private function calendarItems(int $supplierId, array $supplier, VatStatusHistory $history, array $regimes, \DateTimeImmutable $today, string $from, string $to): array
    {
        $out = [];
        $dphPeriods = [];
        foreach ($this->calendar->itemsBetween($supplierId, $today, $from, $to) as $c) {
            $filed = ($c['filing']['status'] ?? 'unfiled') !== 'unfiled';
            $links = [$c['type'] === 'pausal' ? 'optimizer' : $c['type']];
            $year = (int) $c['year'];
            switch ($c['type']) {
                case 'dph':
                case 'kh':
                case 'sh':
                    [$periodStart, $periodEnd] = ReportPeriod::range($year, (int) ($c['month'] ?? $c['quarter'] * 3), $c['period']);
                    $out[] = TimelineItem::make($c['key'], $c['type'], 'dph', $c['due_date'], [
                        'start'  => (new \DateTimeImmutable($periodEnd))->modify('+1 day')->format('Y-m-d'),
                        'params' => ['period' => $c['period'], 'year' => $year, 'month' => $c['month'], 'quarter' => $c['quarter']],
                        'legal'  => ['dph' => '§ 101 ZDPH', 'kh' => '§ 101c, § 101e ZDPH', 'sh' => '§ 102 ZDPH'][$c['type']],
                        'links'  => $links,
                        'auto_done' => $filed,
                    ]) + ['filing' => $c['filing']];
                    if ($c['type'] === 'dph' && $history->hasPayerTimeBetween($periodStart, $periodEnd)) {
                        $dphPeriods[] = $c;
                    }
                    break;
                case 'income_tax':
                    $regime = $regimes[$year]['regime'] ?? TaxYearFacts::NONE;
                    if ($regime === TaxYearFacts::FLAT) {
                        break; // in the flat-tax regime no return is filed
                    }
                    $out[] = TimelineItem::make($c['key'], 'income_tax', 'income_tax', $c['due_date'], [
                        'start'  => ($year + 1) . '-01-01',
                        'params' => [
                            'year' => $year, 'form' => $c['form_code'],
                            'paper_due_date' => $c['paper_due_date'], 'advisor_due_date' => ReportPeriod::incomeTaxAdvisorDeadline($year),
                        ],
                        'notes'  => $regime === TaxYearFacts::LOST ? ['income_tax_regime_lost'] : [],
                        'legal'  => '§ 136 DŘ',
                        'links'  => $links,
                        'auto_done' => $filed,
                    ]) + ['filing' => $c['filing']];
                    break;
                case 'pausal':
                    $out[] = TimelineItem::make($c['key'], 'pausal', 'pausal', $c['due_date'], [
                        'params' => ['year' => $year, 'month' => $c['month'], 'band' => $c['band']],
                        'notes'  => ($regimes[$year]['regime'] ?? '') === TaxYearFacts::LOST ? ['pausal_regime_lost'] : [],
                        'legal'  => '§ 7a ZDP',
                        'amount' => $c['amount'],
                        'links'  => $links,
                    ]);
                    break;
            }
        }
        return [...$out, ...$this->vatPaymentItems($supplierId, $supplier, $dphPeriods)];
    }

    /**
     * VAT payment of every payer period: due with the return, paid when the filed return in force is marked paid
     * (by hand or recognised on the bank statement).
     *
     * @param array<string,mixed> $supplier
     * @param list<array<string,mixed>> $dphItems DPH items of the calendar for payer periods
     * @return list<array<string,mixed>>
     */
    private function vatPaymentItems(int $supplierId, array $supplier, array $dphItems): array
    {
        if ($dphItems === []) {
            return [];
        }
        $filed = $this->vatPayments->filedByPeriod($supplierId);

        $items = [];
        foreach ($dphItems as $c) {
            $rows = $filed[TaxSubmissionRepository::slotKey(TaxSubmissionRepository::FORM_DPH, $c['year'], $c['month'], $c['quarter'])] ?? [];
            $pay = $this->paymentState($rows, $supplier);
            $items[] = TimelineItem::make('vat_payment:' . substr($c['key'], 4), 'vat_payment', 'dph', $c['due_date'], [
                'start'  => $c['due_date'],
                'params' => ['period' => $c['period'], 'year' => $c['year'], 'month' => $c['month'], 'quarter' => $c['quarter']],
                'notes'  => $pay['notes'],
                'legal'  => '§ 98 ZDPH',
                'amount' => $pay['amount'],
                'links'  => ['submissions'],
                'auto_done' => $pay['done'],
            ]);
        }
        return $items;
    }

    /**
     * @param list<array<string,mixed>> $rows filed returns of the period, oldest first
     * @param array<string,mixed> $supplier
     * @return array{done: bool, amount: ?float, notes: list<string>}
     */
    private function paymentState(array $rows, array $supplier): array
    {
        if ($rows === []) {
            return ['done' => false, 'amount' => null, 'notes' => ['vat_payment_amount_unknown']];
        }
        $unpaid = 0.0;
        $paid = 0.0;
        $isUnpaid = false;
        foreach ((new FiledReturnChain($rows))->inForce() as $row) {
            $card = $this->vatPayments->card($row, false, $rows, $supplier);
            if ($card['state'] === 'unpaid') {
                $isUnpaid = true;
                $unpaid += (float) $card['amount_due'];
            } elseif ($card['state'] === 'paid') {
                $paid += (float) $card['amount'];
            }
        }
        if ($isUnpaid) {
            return ['done' => false, 'amount' => $unpaid, 'notes' => []];
        }
        return $paid > 0
            ? ['done' => true, 'amount' => $paid, 'notes' => []]
            : ['done' => true, 'amount' => null, 'notes' => ['vat_payment_none']];
    }

    // ── invoicing ───────────────────────────────────────────────────────────

    /**
     * "Issue the invoices for the month": a recommendation for every month since the first invoice, due on the 5th
     * working day of the next month, done once an invoice with a DUZP in the month exists.
     *
     * @return list<array<string,mixed>>
     */
    private function invoicingItems(int $supplierId, \DateTimeImmutable $today, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATE_FORMAT(COALESCE(tax_date, issue_date), '%Y-%m') AS ym, COUNT(*) AS n
               FROM invoices
              WHERE supplier_id = ?
                AND status IN ('issued', 'sent', 'reminded', 'paid')
                AND invoice_type IN ('invoice', 'tax_document')
           GROUP BY ym"
        );
        $stmt->execute([$supplierId]);
        $perMonth = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'n', 'ym');
        if ($perMonth === []) {
            return [];
        }
        $firstMonth = min(array_keys($perMonth));

        $items = [];
        // A month is due in the next one, so the months to look at start one month before the range.
        $month = (new \DateTimeImmutable($from))->modify('first day of last month');
        $last = new \DateTimeImmutable($to);
        for (; $month <= $last; $month = $month->modify('first day of next month')) {
            $ym = $month->format('Y-m');
            if ($ym < $firstMonth) {
                continue;
            }
            $due = CzechWorkingDays::nthWorkingDayOfMonth($month->modify('first day of next month'), self::INVOICING_WORKING_DAY)->format('Y-m-d');
            if ($due < $from || $due > $to) {
                continue;
            }
            $count = (int) ($perMonth[$ym] ?? 0);
            $items[] = TimelineItem::make("invoicing:$ym", 'invoicing', 'invoicing', $due, [
                'start'  => $month->modify('last day of this month')->format('Y-m-d'),
                'params' => ['period' => $ym, 'count' => $count],
                'obligation' => 'recommended',
                'links'  => ['new_invoice', 'invoices'],
                'auto_done' => $count > 0,
            ]);
        }
        return $items;
    }

    // ── status ──────────────────────────────────────────────────────────────

    /**
     * Adds the status, the done source and the manual mark to a computed item.
     *
     * @param array<string,mixed> $item
     * @param array{done: bool, note: ?string, by: ?string, at: string}|null $mark
     * @return array<string,mixed>
     */
    private function finish(array $item, ?array $mark, string $today): array
    {
        $autoDone = $item['auto_done'];
        $tracked = $autoDone !== null;
        $source = $autoDone === true ? 'auto' : (($mark['done'] ?? false) ? 'manual' : null);
        $days = $this->daysBetween($today, $item['due_date']);
        unset($item['auto_done']);

        return $item + [
            'days_left'   => $days,
            'tracked'     => $tracked,
            'done_source' => $source,
            'status'      => $this->status($source !== null, (bool) $item['info'], $tracked, $days),
            'mark'        => $mark,
        ];
    }

    private function status(bool $done, bool $info, bool $tracked, int $days): string
    {
        return match (true) {
            $done => 'done',
            $info => 'info',
            $days < 0 => !$tracked && $days < -self::UNVERIFIED_AFTER_DAYS ? 'unverified' : 'overdue',
            $days <= self::DUE_SOON_DAYS => 'due_soon',
            default => 'upcoming',
        };
    }

    /**
     * A custom item as a timeline item: it carries its own text and URL; its completion is shown like a manual mark.
     *
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function customItem(array $c, string $today): array
    {
        // Always "tracked" (the application knows the flag); a completion is shown like a manual mark.
        $item = TimelineItem::make('custom:' . $c['id'], 'custom', $c['category'], $c['due_date'], [
            'start' => $c['start_date'],
            'obligation' => 'recommended',
            'auto_done' => false,
        ]);
        $mark = $c['done_at'] !== null ? ['done' => true, 'note' => null, 'by' => $c['done_by'], 'at' => $c['done_at']] : null;
        return $this->finish($item, $mark, $today) + [
            'custom' => ['id' => $c['id'], 'title' => $c['title'], 'note' => $c['note'], 'url' => $c['url']],
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function summary(array $items): array
    {
        $byStatus = array_fill_keys(['done', 'overdue', 'due_soon', 'upcoming', 'info', 'unverified'], 0);
        $byCategory = [];
        foreach ($items as $i) {
            $byStatus[$i['status']]++;
            $byCategory[$i['category']] = ($byCategory[$i['category']] ?? 0) + 1;
        }
        return ['total' => count($items), 'by_status' => $byStatus, 'by_category' => $byCategory];
    }

    private function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }

    /** @return array<string,mixed> */
    private function supplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT taxpayer_type, flat_tax_band, company_name, dic, financial_office_code FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
