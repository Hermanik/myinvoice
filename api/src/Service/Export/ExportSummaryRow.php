<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

/**
 * One document line of the summary CSV (issued or received invoice).
 *
 * Amounts are in the document currency with the sign already normalised (a credit note is
 * negative); `$rate` converts them to CZK (CZK per unit, null for CZK documents and for foreign
 * documents without a stored rate). `$counted` = false lists the row without adding it to the
 * totals (proforma, an advance already settled by the final invoice).
 */
final readonly class ExportSummaryRow
{
    public function __construct(
        public string $number,
        public string $type,
        public string $partner,
        public string $ic,
        public string $dic,
        public ?string $issueDate,
        public ?string $taxDate,
        public ?string $dueDate,
        public ?string $paidAt,
        public float $base,
        public float $vat,
        public float $total,
        public string $currency,
        public ?float $rate,
        public string $state,
        public bool $counted = true,
    ) {}

    public function isForeign(): bool
    {
        return strtoupper($this->currency) !== 'CZK';
    }

    /** A foreign document cannot enter the CZK totals without a rate. */
    public function hasCzkEquivalent(): bool
    {
        return !$this->isForeign() || ($this->rate !== null && $this->rate > 0);
    }

    public function toCzk(float $amount): float
    {
        return $this->isForeign() ? round($amount * (float) $this->rate, 2) : round($amount, 2);
    }
}
