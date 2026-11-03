<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

/**
 * SQL fragments that resolve the supplier's VAT status PER DOCUMENT DATE inside
 * aggregate queries (dashboards, stats), where loading the history into PHP and
 * deciding row by row is not possible.
 *
 * The status is the row of `supplier_vat_status_history` with the greatest
 * valid_from <= the document date; before the first row the first row applies,
 * and a supplier without any rows falls back to the cached supplier flag (the
 * same rules as {@see VatStatusHistory} / the repository).
 */
final class VatStatusSql
{
    /** Date a document counts on: DUZP, falling back to the issue date. */
    public static function documentDate(string $alias): string
    {
        return "COALESCE({$alias}.tax_date, {$alias}.issue_date)";
    }

    /**
     * Boolean SQL expression: the supplier of the row is a VAT payer on $dateExpr.
     *
     * @param string $supplierIdExpr e.g. `i.supplier_id`
     * @param string $dateExpr       e.g. `COALESCE(i.tax_date, i.issue_date)`
     */
    public static function payerAt(string $supplierIdExpr, string $dateExpr): string
    {
        $t = 'supplier_vat_status_history';
        return "(COALESCE("
            . "(SELECT vh.status FROM {$t} vh WHERE vh.supplier_id = {$supplierIdExpr} AND vh.valid_from <= {$dateExpr} ORDER BY vh.valid_from DESC LIMIT 1), "
            . "(SELECT vh0.status FROM {$t} vh0 WHERE vh0.supplier_id = {$supplierIdExpr} ORDER BY vh0.valid_from ASC LIMIT 1), "
            . "(SELECT CASE WHEN vs.is_vat_payer = 1 THEN 'payer' ELSE 'non_payer' END FROM supplier vs WHERE vs.id = {$supplierIdExpr})"
            . ") = 'payer')";
    }

    /**
     * Revenue/cost amount of a document: base without VAT when the supplier was a VAT payer
     * on the document date (VAT is a pass-through item), the total including VAT otherwise.
     *
     * @param string $alias table alias of invoices / purchase_invoices (needs supplier_id, tax_date, issue_date)
     */
    public static function amount(string $alias): string
    {
        return '(CASE WHEN ' . self::payerAt("{$alias}.supplier_id", self::documentDate($alias))
            . " THEN {$alias}.total_without_vat ELSE {$alias}.total_with_vat END)";
    }
}
