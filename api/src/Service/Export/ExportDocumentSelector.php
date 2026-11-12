<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Document selection of the bulk export for the payment basis and for the summary CSVs.
 * The DUZP basis (VAT-style) stays in {@see MonthlyExportService}.
 *
 * Payment basis = income-tax view: a document belongs to the period in which it was paid in
 * full. It mirrors how the tax optimiser counts income (status 'paid' with a paid_at date, no
 * proformas), so the export and the optimiser agree on what a year contains.
 */
final class ExportDocumentSelector
{
    /** Sales documents that are income (a proforma is only a request for an advance). */
    private const INCOME_TYPES = "('invoice','credit_note','tax_document')";

    private const SALES_TYPE_LABELS = [
        'invoice' => 'Faktura', 'proforma' => 'Proforma', 'credit_note' => 'Dobropis',
        'cancellation' => 'Storno', 'tax_document' => 'Daňový doklad k platbě',
    ];

    private const PURCHASE_KIND_LABELS = [
        'invoice' => 'Faktura', 'receipt' => 'Účtenka', 'credit_note' => 'Dobropis', 'advance' => 'Záloha',
    ];

    private const IN_CHUNK = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly VatStatusService $vatStatus,
    ) {}

    /** SQL date a received invoice counts on (VAT-style): the later of DUZP / issue date; foreign reverse charge by DUZP. */
    public static function purchaseVatDateExpr(): string
    {
        // CASE instead of GREATEST for portability (SQLite has no GREATEST).
        return 'CASE'
            . " WHEN pi.reverse_charge = 1 AND COALESCE(co.iso2, 'CZ') <> 'CZ'"
            . ' THEN COALESCE(pi.tax_date, pi.issue_date)'
            . ' WHEN pi.tax_date IS NULL THEN pi.issue_date'
            . ' WHEN pi.issue_date IS NULL THEN pi.tax_date'
            . ' WHEN pi.tax_date >= pi.issue_date THEN pi.tax_date ELSE pi.issue_date END';
    }

    /** @return list<int> issued documents paid in full within the period, by payment date */
    public function paidSalesIds(int $supplierId, ExportPeriod $period): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM invoices
              WHERE supplier_id = ?
                AND status = 'paid'
                AND paid_at IS NOT NULL
                AND paid_at >= ? AND paid_at < ?
                AND invoice_type IN " . self::INCOME_TYPES . '
           ORDER BY paid_at, id'
        );
        $stmt->execute([$supplierId, $period->dateFrom, $period->dateToExclusive]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Received documents paid in full within the period, by payment date, with the columns the
     * export needs to name and attach their files.
     *
     * @return list<array<string,mixed>>
     */
    public function paidPurchaseRows(int $supplierId, ExportPeriod $period): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.pdf_path,
                    c.company_name AS vendor_company_name
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.vendor_id
              WHERE pi.supplier_id = ?
                AND pi.status = 'paid'
                AND pi.paid_at IS NOT NULL
                AND pi.paid_at >= ? AND pi.paid_at < ?
           ORDER BY pi.paid_at, pi.id"
        );
        $stmt->execute([$supplierId, $period->dateFrom, $period->dateToExclusive]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<int> */
    public function paidPurchaseIds(int $supplierId, ExportPeriod $period): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->paidPurchaseRows($supplierId, $period));
    }

    /**
     * Issued income documents whose DUZP is in the period but which were not paid in it
     * (unpaid, or paid before / after the period). The accountant sees what is missing.
     *
     * @param list<int> $paidIds {@see paidSalesIds()}
     * @return list<int>
     */
    public function outsideSalesIds(int $supplierId, ExportPeriod $period, array $paidIds): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM invoices
              WHERE supplier_id = ?
                AND COALESCE(tax_date, issue_date) >= ? AND COALESCE(tax_date, issue_date) < ?
                AND status IN ('issued','sent','reminded','paid')
                AND invoice_type IN " . self::INCOME_TYPES . '
           ORDER BY COALESCE(tax_date, issue_date), id'
        );
        $stmt->execute([$supplierId, $period->dateFrom, $period->dateToExclusive]);
        return array_values(array_diff(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), $paidIds));
    }

    /**
     * Received documents whose VAT date is in the period but which were not paid in it.
     * An advance already settled by the final invoice is not a cost of its own.
     *
     * @param list<int> $paidIds {@see paidPurchaseIds()}
     * @return list<int>
     */
    public function outsidePurchaseIds(int $supplierId, ExportPeriod $period, array $paidIds): array
    {
        $dateExpr = self::purchaseVatDateExpr();
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id
               FROM purchase_invoices pi
               JOIN clients c ON c.id = pi.vendor_id
          LEFT JOIN countries co ON co.id = c.country_id
              WHERE pi.supplier_id = ?
                AND {$dateExpr} >= ? AND {$dateExpr} < ?
                AND pi.status IN ('received', 'booked', 'paid')
                AND NOT (COALESCE(pi.document_kind, '') = 'advance' AND " . self::settledAdvanceSql('pi') . ")
           ORDER BY {$dateExpr}, pi.id"
        );
        $stmt->execute([$supplierId, $period->dateFrom, $period->dateToExclusive]);
        return array_values(array_diff(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)), $paidIds));
    }

    /**
     * Months of the period (`[year, month]`) in which the supplier was a VAT payer on at least
     * one day. The VAT book is only produced for those; it is always by DUZP, whatever basis
     * the documents are assigned by.
     *
     * @return list<array{0:int,1:int}>
     */
    public function vatBookMonths(int $supplierId, ExportPeriod $period): array
    {
        $history = $this->vatStatus->history($supplierId);
        $months = [];
        $cur = new \DateTimeImmutable($period->dateFrom);
        $end = new \DateTimeImmutable($period->dateToExclusive);
        while ($cur < $end) {
            $first = $cur->format('Y-m-d');
            $last = $cur->modify('last day of this month')->format('Y-m-d');
            if ($history->hasPayerTimeBetween($first, $last)) {
                $months[] = [(int) $cur->format('Y'), (int) $cur->format('n')];
            }
            $cur = $cur->modify('first day of next month');
        }
        return $months;
    }

    /**
     * Summary rows of issued documents, in the order of $ids.
     *
     * @param list<int> $ids
     * @param bool $relativeToPeriod label an unpaid-in-period document by when it was paid
     *        (before / after the period) instead of the plain paid / unpaid
     * @return list<ExportSummaryRow>
     */
    public function salesSummaryRows(int $supplierId, array $ids, ExportPeriod $period, bool $relativeToPeriod = false): array
    {
        $rows = $this->fetchByIds($ids, "SELECT i.id, i.varsymbol, i.invoice_type, i.status, i.issue_date, i.tax_date, i.due_date,
                        i.paid_at, i.paid_total, i.total_without_vat, i.total_vat, i.total_with_vat,
                        i.exchange_rate, COALESCE(cur.code, 'CZK') AS currency,
                        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.client_snapshot, '\$.company_name')), ''), c.company_name) AS partner,
                        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.client_snapshot, '\$.ic')), ''), c.ic) AS ic,
                        COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.client_snapshot, '\$.dic')), ''), c.dic) AS dic
                   FROM invoices i
                   JOIN clients c ON c.id = i.client_id
              LEFT JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.supplier_id = ? AND i.id IN (%s)", $supplierId);
        return $this->inOrder($ids, array_map(fn (array $r): ExportSummaryRow => $this->salesRow($r, $period, $relativeToPeriod), $rows));
    }

    /**
     * Summary rows of received documents, in the order of $ids.
     *
     * @param list<int> $ids
     * @return list<ExportSummaryRow>
     */
    public function purchaseSummaryRows(int $supplierId, array $ids, ExportPeriod $period, bool $relativeToPeriod = false): array
    {
        $rows = $this->fetchByIds($ids, "SELECT pi.id, pi.vendor_invoice_number, pi.varsymbol, pi.document_kind, pi.status,
                        pi.issue_date, pi.tax_date, pi.due_date, pi.paid_at,
                        pi.total_without_vat, pi.total_vat, pi.total_with_vat, pi.exchange_rate,
                        COALESCE(cur.code, 'CZK') AS currency,
                        c.company_name AS partner, c.ic AS ic, c.dic AS dic,
                        (COALESCE(pi.document_kind, '') = 'advance' AND " . self::settledAdvanceSql('pi') . ") AS advance_settled
                   FROM purchase_invoices pi
                   JOIN clients c ON c.id = pi.vendor_id
              LEFT JOIN currencies cur ON cur.id = pi.currency_id
                  WHERE pi.supplier_id = ? AND pi.id IN (%s)", $supplierId);
        return $this->inOrder($ids, array_map(fn (array $r): ExportSummaryRow => $this->purchaseRow($r, $period, $relativeToPeriod), $rows));
    }

    /**
     * Runs a query with an `IN (%s)` placeholder over $ids in chunks (the first bound parameter is
     * the supplier) and returns the rows keyed by their `id`.
     *
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    private function fetchByIds(array $ids, string $sqlWithInPlaceholder, int $supplierId): array
    {
        $byId = [];
        foreach (array_chunk($ids, self::IN_CHUNK) as $chunk) {
            $stmt = $this->db->pdo()->prepare(sprintf($sqlWithInPlaceholder, implode(',', array_fill(0, count($chunk), '?'))));
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $byId[(int) $r['id']] = $r;
            }
        }
        return $byId;
    }

    /** A paid advance that a final invoice settles (the cost is counted once, on the final invoice). */
    private static function settledAdvanceSql(string $alias): string
    {
        return "EXISTS (SELECT 1 FROM purchase_invoices adv_s WHERE adv_s.advance_purchase_invoice_id = {$alias}.id)";
    }

    /** @param array<string,mixed> $r */
    private function salesRow(array $r, ExportPeriod $period, bool $relativeToPeriod): ExportSummaryRow
    {
        $type = (string) $r['invoice_type'];
        // A credit note reduces the income; an import may store it positively (same rule as the VAT ledger).
        $sign = $type === 'credit_note' && (float) $r['total_with_vat'] > 0 ? -1.0 : 1.0;
        $paid = (string) $r['status'] === 'paid' && $r['paid_at'] !== null;
        $partlyPaid = !$paid && (float) $r['paid_total'] > 0;
        $isProforma = $type === 'proforma';

        $state = $this->state($paid, $r['paid_at'] !== null ? (string) $r['paid_at'] : null, $period, $relativeToPeriod, $partlyPaid);
        if ($isProforma) {
            $state .= ' – nezapočteno (záloha)';
        }

        return new ExportSummaryRow(
            number: (string) ($r['varsymbol'] ?? ''),
            type: self::SALES_TYPE_LABELS[$type] ?? $type,
            partner: (string) ($r['partner'] ?? ''),
            ic: (string) ($r['ic'] ?? ''),
            dic: (string) ($r['dic'] ?? ''),
            issueDate: $this->date($r['issue_date']),
            taxDate: $this->date($r['tax_date'] ?? $r['issue_date']),
            dueDate: $this->date($r['due_date']),
            paidAt: $paid ? $this->date($r['paid_at']) : null,
            base: $sign * (float) $r['total_without_vat'],
            vat: $sign * (float) $r['total_vat'],
            total: $sign * (float) $r['total_with_vat'],
            currency: strtoupper((string) $r['currency']),
            rate: $r['exchange_rate'] !== null ? (float) $r['exchange_rate'] : null,
            state: $state,
            counted: !$isProforma,
        );
    }

    /** @param array<string,mixed> $r */
    private function purchaseRow(array $r, ExportPeriod $period, bool $relativeToPeriod): ExportSummaryRow
    {
        $kind = (string) ($r['document_kind'] ?? 'invoice');
        // A received credit note always reduces the cost, whatever sign the import stored.
        $sign = $kind === 'credit_note' ? -1.0 : 1.0;
        $paid = (string) $r['status'] === 'paid' && $r['paid_at'] !== null;
        $settledAdvance = (bool) $r['advance_settled'];

        $state = $this->state($paid, $r['paid_at'] !== null ? (string) $r['paid_at'] : null, $period, $relativeToPeriod, false);
        if ($settledAdvance) {
            $state .= ' – nezapočteno (záloha vyúčtovaná fakturou)';
        }

        return new ExportSummaryRow(
            number: (string) ($r['vendor_invoice_number'] ?? $r['varsymbol'] ?? ''),
            type: self::PURCHASE_KIND_LABELS[$kind] ?? $kind,
            partner: (string) ($r['partner'] ?? ''),
            ic: (string) ($r['ic'] ?? ''),
            dic: (string) ($r['dic'] ?? ''),
            issueDate: $this->date($r['issue_date']),
            taxDate: $this->date($r['tax_date'] ?? $r['issue_date']),
            dueDate: $this->date($r['due_date']),
            paidAt: $paid ? $this->date($r['paid_at']) : null,
            base: $sign * abs((float) $r['total_without_vat']),
            vat: $sign * abs((float) $r['total_vat']),
            total: $sign * abs((float) $r['total_with_vat']),
            currency: strtoupper((string) $r['currency']),
            rate: $r['exchange_rate'] !== null ? (float) $r['exchange_rate'] : null,
            state: $state,
            counted: !$settledAdvance,
        );
    }

    private function state(bool $paid, ?string $paidAt, ExportPeriod $period, bool $relativeToPeriod, bool $partlyPaid): string
    {
        if (!$paid) {
            return $partlyPaid ? 'Částečně uhrazeno' : 'Neuhrazeno';
        }
        if ($relativeToPeriod && $paidAt !== null) {
            $day = substr($paidAt, 0, 10);
            if ($day < $period->dateFrom) {
                return 'Uhrazeno před obdobím';
            }
            if ($day >= $period->dateToExclusive) {
                return 'Uhrazeno po období';
            }
        }
        return 'Uhrazeno';
    }

    private function date(mixed $value): ?string
    {
        return $value !== null && $value !== '' ? substr((string) $value, 0, 10) : null;
    }

    /**
     * @param list<int> $ids
     * @param array<int,ExportSummaryRow> $byId
     * @return list<ExportSummaryRow>
     */
    private function inOrder(array $ids, array $byId): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }
        return $out;
    }
}
