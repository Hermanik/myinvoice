<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/**
 * Compact, allowlist-based projections of API payloads for the model.
 *
 * Tools return only what an assistant needs to answer questions; internal
 * columns (approval/public tokens, snapshots, import ids) never pass through.
 */
final class McpFormat
{
    private const UNPAID_STATUSES = ['issued', 'sent', 'reminded'];

    /**
     * Row of an invoice listing (also usable for a full invoice detail).
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function invoiceRow(array $r): array
    {
        $unpaid = in_array($r['status'] ?? '', self::UNPAID_STATUSES, true);

        return [
            'id'               => (int) $r['id'],
            'number'           => $r['varsymbol'] ?? null,
            'type'             => $r['invoice_type'] ?? null,
            'status'           => $r['status'] ?? null,
            'payment_status'   => $r['payment_status'] ?? null,
            'client'           => ['id' => (int) ($r['client_id'] ?? 0), 'name' => $r['client_company_name'] ?? null],
            'project'          => $r['project_name'] ?? null,
            'issue_date'       => $r['issue_date'] ?? null,
            'tax_date'         => $r['tax_date'] ?? null,
            'due_date'         => $r['due_date'] ?? null,
            'paid_at'          => $r['paid_at'] ?? null,
            'currency'         => $r['currency'] ?? null,
            'total_without_vat' => (float) ($r['total_without_vat'] ?? 0),
            'total_vat'        => (float) ($r['total_vat'] ?? 0),
            'total_with_vat'   => (float) ($r['total_with_vat'] ?? 0),
            'amount_to_pay'    => (float) ($r['amount_to_pay'] ?? 0),
            'paid_total'       => (float) ($r['paid_total'] ?? 0),
            'outstanding'      => $unpaid
                ? round((float) ($r['amount_to_pay'] ?? 0) - (float) ($r['paid_total'] ?? 0), 2)
                : 0.0,
        ];
    }

    /**
     * Full invoice detail: row + items, VAT breakdown, notes, payments.
     *
     * @param array<string,mixed> $invoice  GET /api/invoices/{id}
     * @param array<string,mixed> $payments GET /api/invoices/{id}/payments
     * @return array<string,mixed>
     */
    public static function invoiceDetail(array $invoice, array $payments, string $appUrl): array
    {
        $items = [];
        foreach ((array) ($invoice['items'] ?? []) as $item) {
            $items[] = [
                'description'            => $item['description'] ?? null,
                'quantity'               => (float) ($item['quantity'] ?? 0),
                'unit'                   => $item['unit'] ?? null,
                'unit_price_without_vat' => (float) ($item['unit_price_without_vat'] ?? 0),
                'vat_rate_percent'       => (float) ($item['vat_rate_snapshot'] ?? 0),
                'total_without_vat'      => (float) ($item['total_without_vat'] ?? 0),
                'total_vat'              => (float) ($item['total_vat'] ?? 0),
                'total_with_vat'         => (float) ($item['total_with_vat'] ?? 0),
            ];
        }

        $paymentRows = [];
        foreach ((array) ($payments['payments'] ?? []) as $p) {
            $paymentRows[] = [
                'id'              => (int) $p['id'],
                'amount'          => (float) ($p['amount'] ?? 0),
                'paid_on'         => $p['paid_on'] ?? null,
                'source'          => $p['source'] ?? null,
                'variable_symbol' => $p['variable_symbol'] ?? null,
                'bank_reference'  => $p['bank_reference'] ?? null,
                'note'            => $p['note'] ?? null,
            ];
        }

        return self::invoiceRow($invoice) + [
            'url'               => self::invoiceUrl($appUrl, (int) $invoice['id']),
            'parent_invoice_id' => isset($invoice['parent_invoice_id']) ? (int) $invoice['parent_invoice_id'] : null,
            'language'          => $invoice['language'] ?? null,
            'payment_method'    => $invoice['payment_method'] ?? null,
            'reverse_charge'    => (bool) ($invoice['reverse_charge'] ?? false),
            'prices_include_vat' => (bool) ($invoice['prices_include_vat'] ?? false),
            'discount_percent'  => (float) ($invoice['discount_percent'] ?? 0),
            'exchange_rate'     => isset($invoice['exchange_rate']) ? (float) $invoice['exchange_rate'] : null,
            'note_above_items'  => $invoice['note_above_items'] ?? null,
            'note_below_items'  => $invoice['note_below_items'] ?? null,
            'client_ic'         => $invoice['client_ic'] ?? null,
            'client_dic'        => $invoice['client_dic'] ?? null,
            'bank_account'      => [
                'account_number' => $invoice['bank_account_number'] ?? null,
                'bank_code'      => $invoice['bank_code'] ?? null,
                'iban'           => $invoice['bank_iban'] ?? null,
            ],
            'items'             => $items,
            'vat_breakdown'     => (array) ($invoice['vat_breakdown'] ?? []),
            'payments'          => $paymentRows,
            'remaining_to_pay'  => (float) ($payments['remaining'] ?? 0),
        ];
    }

    /**
     * Row of a received (purchase) invoice listing.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function purchaseInvoiceRow(array $r): array
    {
        return [
            'id'                    => (int) $r['id'],
            'number'                => $r['varsymbol'] ?? null,
            'vendor_invoice_number' => $r['vendor_invoice_number'] ?? null,
            'document_kind'         => $r['document_kind'] ?? null,
            'status'                => $r['status'] ?? null,
            'vendor'                => ['id' => (int) ($r['vendor_id'] ?? 0), 'name' => $r['vendor_company_name'] ?? null, 'ic' => $r['vendor_ic'] ?? null],
            'issue_date'            => $r['issue_date'] ?? null,
            'tax_date'              => $r['tax_date'] ?? null,
            'due_date'              => $r['due_date'] ?? null,
            'received_at'           => $r['received_at'] ?? null,
            'paid_at'               => $r['paid_at'] ?? null,
            'currency'              => $r['currency'] ?? null,
            'total_without_vat'     => (float) ($r['total_without_vat'] ?? 0),
            'total_vat'             => (float) ($r['total_vat'] ?? 0),
            'total_with_vat'        => (float) ($r['total_with_vat'] ?? 0),
            'amount_to_pay'         => (float) ($r['amount_to_pay'] ?? 0),
            'vat_deduction'         => $r['vat_deduction'] ?? null,
            'tax_deductible'        => isset($r['tax_deductible']) ? (bool) $r['tax_deductible'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $meta
     * @return array{page: int, per_page: int, pages: int, total: int}
     */
    public static function paging(array $meta): array
    {
        return [
            'page'     => (int) ($meta['page'] ?? 1),
            'per_page' => (int) ($meta['per_page'] ?? 0),
            'pages'    => (int) ($meta['pages'] ?? 1),
            'total'    => (int) ($meta['total'] ?? 0),
        ];
    }

    /**
     * Normalises a "single value or list" argument to the comma-separated form
     * the listing endpoints expect, rejecting values outside the allowed set.
     *
     * @param string|list<string>|null $value
     * @param list<string>             $allowed
     */
    public static function csvOf(string|array|null $value, array $allowed, string $argument): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }
        $values = is_array($value) ? $value : array_map('trim', explode(',', $value));
        foreach ($values as $v) {
            if (!in_array($v, $allowed, true)) {
                throw new McpToolError(
                    'Argument `' . $argument . '`: neznámá hodnota `' . $v . '`, povolené jsou ' . implode(', ', $allowed) . '.',
                    'invalid_arguments',
                );
            }
        }
        return implode(',', $values);
    }

    /** Web app page of an invoice. */
    public static function invoiceUrl(string $appUrl, int $id): string
    {
        return rtrim($appUrl, '/') . '/invoices/' . $id;
    }

    /**
     * Drops null and empty-string entries so only real filters reach the query string.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function compact(array $values): array
    {
        return array_filter($values, static fn ($v): bool => $v !== null && $v !== '');
    }

    /**
     * The `q` text-search parameter of the listing endpoints from a tool's `query` argument.
     *
     * @param array<string,mixed> $args
     * @return array{q?: string}
     */
    public static function searchQuery(array $args): array
    {
        $text = trim((string) ($args['query'] ?? ''));
        return $text === '' ? [] : ['q' => $text];
    }

    /**
     * JSON Schema of a "single value or list" filter argument; allowed values are checked by csvOf().
     *
     * @return array<string,mixed>
     */
    public static function multiValueSchema(string $description): array
    {
        return ['type' => ['string', 'array'], 'items' => ['type' => 'string'], 'description' => $description];
    }

    /**
     * JSON Schema properties of the period/currency filters shared by the invoice listings.
     *
     * @return array<string,mixed>
     */
    public static function periodSchema(): array
    {
        return [
            'date_from' => ['type' => 'string', 'format' => 'date', 'description' => 'From date (YYYY-MM-DD), inclusive.'],
            'date_to' => ['type' => 'string', 'format' => 'date', 'description' => 'To date (YYYY-MM-DD), inclusive.'],
            'year' => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100, 'description' => 'Calendar year.'],
            'month' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'Month 1-12 (use together with `year`).'],
            'currency' => ['type' => 'string', 'description' => 'Currency code, e.g. CZK or EUR.'],
        ];
    }

    /**
     * JSON Schema properties shared by all paged listings; the bounds are enforced by the
     * argument validator, so pagingQuery() only casts.
     *
     * @return array<string,mixed>
     */
    public static function pagingSchema(): array
    {
        return [
            'page'     => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number, starting at 1 (default 1).'],
            'per_page' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 100, 'description' => 'Rows per page, 5-100 (default 25).'],
        ];
    }

    /**
     * The `page`/`per_page` query parameters of a listing.
     *
     * @param array<string,mixed> $args Validated tool arguments
     * @return array{page: int, per_page: int}
     */
    public static function pagingQuery(array $args): array
    {
        return ['page' => (int) ($args['page'] ?? 1), 'per_page' => (int) ($args['per_page'] ?? 25)];
    }
}
