<?php

declare(strict_types=1);

namespace MyInvoice\Service\Dashboard;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\OverduePolicy;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\ReportPeriod;
use MyInvoice\Service\Tax\TaxCalendarService;
use MyInvoice\Service\Timeline\ObligationTimelineService;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Service\Vat\VatStatusSql;

/**
 * Data of the accountant view of the dashboard: what an external accountant needs after signing in - the VAT of
 * the last closed period with the state of its filings, the next open obligations of the timeline, the issued and
 * received invoices of the last two months and the month the monthly export should be taken for.
 *
 * Every part reuses an existing service or the same query rules as the owner's dashboard
 * ({@see \MyInvoice\Action\Dashboard\SummaryAction}); money is in CZK (foreign documents at their own rate). Each
 * part copes with a supplier that has no data yet (VAT starts later, no invoices): the empty state is part of the
 * contract, not an error.
 */
final class AccountantDashboardService
{
    /** Open obligations shown on the dashboard. */
    public const OBLIGATION_LIMIT = 8;
    /** The timeline window searched for open obligations: a missed one stays visible for this long. */
    private const OBLIGATIONS_DAYS_BACK = 60;
    private const OBLIGATIONS_DAYS_AHEAD = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly VatStatusService $vatStatus,
        private readonly TaxCalendarService $calendar,
        private readonly DphPriznaniBuilder $dphReturn,
        private readonly ObligationTimelineService $timeline,
        private readonly OverduePolicy $overduePolicy,
    ) {}

    /**
     * @param bool $withObligations the timeline is for admin and accountant only (readonly users get `null`)
     * @return array<string,mixed>
     */
    public function build(int $supplierId, \DateTimeImmutable $today, bool $withObligations): array
    {
        $today = $today->setTime(0, 0);
        $closed = $this->twoMonths($today)[0];

        return [
            'today'       => $today->format('Y-m-d'),
            'currency'    => 'CZK',
            'vat'         => $this->vat($supplierId, $today),
            'obligations' => $withObligations ? $this->obligations($supplierId, $today) : null,
            'issued'      => $this->issued($supplierId, $today),
            'purchase'    => $this->purchase($supplierId, $today),
            'export'      => ['year' => $closed['year'], 'month' => $closed['month']],
        ];
    }

    // ── VAT of the last closed period ───────────────────────────────────────

    /**
     * The last closed VAT period (the previous month, or the previous quarter for a quarterly payer): the filing
     * deadline, the state of the DPH / KH / SH filings from the archive and the output / input VAT of the return.
     *
     * @return array<string,mixed>
     */
    private function vat(int $supplierId, \DateTimeImmutable $today): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT vat_period FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $periodType = DphPriznaniBuilder::normalizePeriod(null, (string) ($stmt->fetchColumn() ?: ''));

        // The first day of the current month, or of the current quarter; the day before it ends the last closed period.
        $periodStart = $today->modify('first day of this month');
        if ($periodType === 'quarterly') {
            $monthsIntoQuarter = ((int) $periodStart->format('n') - 1) % 3;
            $periodStart = $periodStart->modify("-{$monthsIntoQuarter} months");
        }
        $last = $periodStart->modify('-1 day');
        $year = (int) $last->format('Y');
        $month = (int) $last->format('n');
        [$start, $end] = ReportPeriod::range($year, $month, $periodType);
        $period = [
            'type' => $periodType, 'year' => $year, 'month' => $month,
            'quarter' => $periodType === 'quarterly' ? intdiv($month - 1, 3) + 1 : null,
            'start' => $start, 'end' => $end,
        ];

        $history = $this->vatStatus->history($supplierId);
        if (!$history->hasPayerTimeBetween($start, $end)) {
            $next = $history->nextChangeAfter($today->format('Y-m-d'));
            return [
                'applicable'  => false,
                'period'      => $period,
                // The date the supplier becomes a payer, so the dashboard can say "VAT starts on ...".
                'payer_since' => $next !== null && $next['status'] === 'payer' ? $next['valid_from'] : null,
            ];
        }

        $deadline = ReportPeriod::filingDeadline($year, $month, $periodType);
        $forms = array_map(
            static fn (array $i): array => [
                'type' => $i['type'], 'status' => $i['filing']['status'] ?? 'unfiled',
                'filed_at' => $i['filing']['filed_at'] ?? null, 'submission_id' => $i['filing']['submission_id'] ?? null,
            ],
            $this->calendar->itemsBetween($supplierId, $today, $deadline, $deadline, ['dph', 'kh', 'sh']),
        );

        return [
            'applicable' => true,
            'period'     => $period,
            'deadline'   => $deadline,
            'days_left'  => (int) $today->diff(new \DateTimeImmutable($deadline))->format('%r%a'),
            'forms'      => $forms,
            'return'     => $this->returnFigures($supplierId, $year, $month, $periodType),
        ];
    }

    /**
     * Output and input VAT as the DPH return computes them. A period the return cannot be built for (incomplete
     * data) still leaves the rest of the card usable, so the figures are then simply missing.
     *
     * @return array{output_vat: float, input_vat: float, tax_due: float}|null
     */
    private function returnFigures(int $supplierId, int $year, int $month, string $periodType): ?array
    {
        try {
            $summary = $this->dphReturn->build($supplierId, $year, $month, $periodType)['summary'];
        } catch (\Throwable) {
            return null;
        }

        return [
            'output_vat' => (float) $summary['total_vat_output'],
            'input_vat'  => (float) $summary['total_vat_input'],
            'tax_due'    => (float) $summary['tax_due'],
        ];
    }

    // ── obligations ─────────────────────────────────────────────────────────

    /**
     * The next open items of the obligations timeline, the missed ones first (the timeline is date-ordered). Done,
     * informational and unverified items are not open work.
     *
     * @return array{items: list<array<string,mixed>>, open_count: int, overdue_count: int}
     */
    private function obligations(int $supplierId, \DateTimeImmutable $today): array
    {
        $timeline = $this->timeline->build(
            $supplierId,
            $today,
            $today->modify('-' . self::OBLIGATIONS_DAYS_BACK . ' days')->format('Y-m-d'),
            $today->modify('+' . self::OBLIGATIONS_DAYS_AHEAD . ' days')->format('Y-m-d'),
        );
        $open = array_values(array_filter(
            $timeline['items'],
            static fn (array $i): bool => in_array($i['status'], ['overdue', 'due_soon', 'upcoming'], true),
        ));

        return [
            'items'         => array_slice($open, 0, self::OBLIGATION_LIMIT),
            'open_count'    => count($open),
            'overdue_count' => count(array_filter($open, static fn (array $i): bool => $i['status'] === 'overdue')),
        ];
    }

    // ── issued invoices ─────────────────────────────────────────────────────

    /**
     * Issued invoices of the last month and of this month by their taxable-supply date (the same documents the
     * owner's revenue counts: invoices, credit notes and tax documents that are not drafts or cancelled), and the
     * receivables unpaid after the due date.
     *
     * @return array<string,mixed>
     */
    private function issued(int $supplierId, \DateTimeImmutable $today): array
    {
        [$previous, $current] = $this->twoMonths($today);
        $rate = "IF(cur.code = 'CZK' OR i.exchange_rate IS NULL, 1, i.exchange_rate)";
        $date = VatStatusSql::documentDate('i');
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATE_FORMAT({$date}, '%Y-%m') AS ym, COUNT(*) AS cnt,
                    SUM(i.total_without_vat * {$rate}) AS base, SUM(i.total_vat * {$rate}) AS vat
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued','sent','reminded','paid')
                AND i.invoice_type IN ('invoice','credit_note','tax_document')
                AND {$date} BETWEEN ? AND ?
              GROUP BY ym"
        );
        $stmt->execute([$supplierId, $previous['start'], $current['end']]);
        $byMonth = $this->indexByMonth($stmt->fetchAll(\PDO::FETCH_ASSOC));

        // A receivable is a document with something still to pay; the proforma that already has its final invoice is
        // counted through that invoice (same rules as the owner's dashboard overdue list).
        $overdueOperator = $this->overduePolicy->comparisonOperator();
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS cnt, SUM((i.amount_to_pay - i.paid_total) * {$rate}) AS total,
                    MAX(DATEDIFF(?, i.due_date)) AS oldest_days
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued','sent','reminded')
                AND i.due_date {$overdueOperator} ?
                AND (i.invoice_type IN ('invoice','credit_note','tax_document')
                     OR (i.invoice_type = 'proforma'
                         AND NOT EXISTS (SELECT 1 FROM invoices ch WHERE ch.parent_invoice_id = i.id AND ch.invoice_type = 'invoice')))
                AND (i.invoice_type NOT IN ('invoice','proforma','tax_document') OR i.amount_to_pay - i.paid_total > 0)"
        );
        $day = $today->format('Y-m-d');
        $stmt->execute([$day, $supplierId, $day]);
        $overdue = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'previous' => $this->monthFigures($previous, $byMonth),
            'current'  => $this->monthFigures($current, $byMonth),
            'overdue'  => [
                'count'       => (int) ($overdue['cnt'] ?? 0),
                'total'       => round((float) ($overdue['total'] ?? 0), 2),
                'oldest_days' => (int) ($overdue['oldest_days'] ?? 0),
            ],
        ];
    }

    // ── received invoices ───────────────────────────────────────────────────

    /**
     * Received invoices of the last month and of this month by their issue date, and what still needs the
     * accountant's hand: drafts (not confirmed yet) and received documents that are not booked.
     *
     * @return array<string,mixed>
     */
    private function purchase(int $supplierId, \DateTimeImmutable $today): array
    {
        [$previous, $current] = $this->twoMonths($today);
        $rate = "IF(cur.code = 'CZK' OR pi.exchange_rate IS NULL, 1, pi.exchange_rate)";
        // An advance document is counted once, through the final invoice it is settled by (same as the owner's costs).
        $notAdvanceDuplicate = "NOT (COALESCE(pi.document_kind, '') = 'advance'
                AND (pi.status <> 'paid'
                     OR EXISTS (SELECT 1 FROM purchase_invoices adv_s WHERE adv_s.advance_purchase_invoice_id = pi.id)))";
        $stmt = $this->db->pdo()->prepare(
            "SELECT DATE_FORMAT(pi.issue_date, '%Y-%m') AS ym, COUNT(*) AS cnt,
                    SUM(pi.total_without_vat * {$rate}) AS base, SUM(pi.total_vat * {$rate}) AS vat
               FROM purchase_invoices pi
          LEFT JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.supplier_id = ?
                AND pi.status NOT IN ('draft','cancelled')
                AND {$notAdvanceDuplicate}
                AND pi.issue_date BETWEEN ? AND ?
              GROUP BY ym"
        );
        $stmt->execute([$supplierId, $previous['start'], $current['end']]);
        $byMonth = $this->indexByMonth($stmt->fetchAll(\PDO::FETCH_ASSOC));

        $stmt = $this->db->pdo()->prepare(
            "SELECT SUM(status = 'draft') AS drafts, SUM(status = 'received') AS to_book
               FROM purchase_invoices WHERE supplier_id = ?"
        );
        $stmt->execute([$supplierId]);
        $attention = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'previous'  => $this->monthFigures($previous, $byMonth),
            'current'   => $this->monthFigures($current, $byMonth),
            'attention' => ['drafts' => (int) ($attention['drafts'] ?? 0), 'to_book' => (int) ($attention['to_book'] ?? 0)],
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * The previous and the current calendar month.
     *
     * @return array{0: array{ym: string, year: int, month: int, start: string, end: string}, 1: array{ym: string, year: int, month: int, start: string, end: string}}
     */
    private function twoMonths(\DateTimeImmutable $today): array
    {
        $month = static fn (\DateTimeImmutable $first): array => [
            'ym' => $first->format('Y-m'), 'year' => (int) $first->format('Y'), 'month' => (int) $first->format('n'),
            'start' => $first->format('Y-m-d'), 'end' => $first->modify('last day of this month')->format('Y-m-d'),
        ];
        $current = $today->modify('first day of this month');

        return [$month($current->modify('-1 month')), $month($current)];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string, array{cnt: int, base: float, vat: float}>
     */
    private function indexByMonth(array $rows): array
    {
        $index = [];
        foreach ($rows as $r) {
            $index[(string) $r['ym']] = ['cnt' => (int) $r['cnt'], 'base' => (float) $r['base'], 'vat' => (float) $r['vat']];
        }

        return $index;
    }

    /**
     * @param array{ym: string, year: int, month: int} $month
     * @param array<string, array{cnt: int, base: float, vat: float}> $byMonth
     * @return array{year: int, month: int, count: int, base: float, vat: float}
     */
    private function monthFigures(array $month, array $byMonth): array
    {
        $f = $byMonth[$month['ym']] ?? ['cnt' => 0, 'base' => 0.0, 'vat' => 0.0];

        return [
            'year' => $month['year'], 'month' => $month['month'],
            'count' => $f['cnt'], 'base' => round($f['base'], 2), 'vat' => round($f['vat'], 2),
        ];
    }
}
