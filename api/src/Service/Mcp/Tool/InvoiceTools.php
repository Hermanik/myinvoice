<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp\Tool;

use MyInvoice\Service\Invoice\OverduePolicy;
use MyInvoice\Service\Mcp\McpFormat;
use MyInvoice\Service\Mcp\McpTool;
use MyInvoice\Service\Mcp\McpToolContext;
use MyInvoice\Service\Mcp\McpToolError;
use MyInvoice\Service\Mcp\McpToolProvider;

/** Issued-invoice tools: listing, detail, receivables and the guarded write operations. */
final class InvoiceTools implements McpToolProvider
{
    private const STATUSES = ['draft', 'issued', 'sent', 'reminded', 'paid', 'cancelled'];
    private const TYPES = ['invoice', 'proforma', 'credit_note', 'tax_document', 'cancellation'];
    private const CREATE_TYPES = ['invoice', 'proforma', 'credit_note'];
    private const PAYMENT_METHODS = ['bank_transfer', 'card', 'cash', 'other'];

    /** Default `limit` of list_unpaid_invoices. */
    private const UNPAID_DEFAULT_LIMIT = 50;

    private const CONFIRM_SCHEMA = [
        'type' => 'boolean',
        'description' => 'Must be exactly true to proceed; only after explicit user approval.',
    ];

    public function __construct(private readonly OverduePolicy $overdue) {}

    public function tools(): array
    {
        return [
            $this->listInvoices(),
            $this->getInvoice(),
            $this->listUnpaidInvoices(),
            $this->createDraftInvoice(),
            $this->issueInvoice(),
            $this->markInvoicePaid(),
        ];
    }

    private function listInvoices(): McpTool
    {
        return new McpTool(
            name: 'list_invoices',
            title: 'List issued invoices',
            description: 'Lists issued invoices (faktury vydané) of the current company, newest first. Filter by status, '
                . 'type, client, date range (by DUZP = tax date, falling back to issue date) or text (invoice number / '
                . 'variabilní symbol, or client name). Amounts are in the invoice currency. Paged; check `paging.pages`.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'status' => McpFormat::multiValueSchema(
                        'One status or a list. Values: ' . implode(', ', self::STATUSES) . '. '
                        . '"issued"/"sent"/"reminded" are unpaid; "paid" is settled.',
                    ),
                    'type' => McpFormat::multiValueSchema(
                        'Document type or list. Values: ' . implode(', ', self::TYPES) . '. '
                        . '"proforma" is an advance (zálohová) invoice, "credit_note" a dobropis.',
                    ),
                    'client_id' => ['type' => 'integer', 'description' => 'Only invoices of this client (see list_clients).'],
                    'query' => ['type' => 'string', 'description' => 'Text search: invoice number prefix or part of the client name.'],
                ] + McpFormat::periodSchema() + McpFormat::pagingSchema(),
                'additionalProperties' => false,
            ],
            write: false,
            handler: function (array $a, McpToolContext $c): array {
                $filter = McpFormat::compact([
                    'status'    => McpFormat::csvOf($a['status'] ?? null, self::STATUSES, 'status'),
                    'type'      => McpFormat::csvOf($a['type'] ?? null, self::TYPES, 'type'),
                    'client_id' => $a['client_id'] ?? null,
                    'date_from' => $a['date_from'] ?? null,
                    'date_to'   => $a['date_to'] ?? null,
                    'year'      => $a['year'] ?? null,
                    'month'     => $a['month'] ?? null,
                    'currency'  => $a['currency'] ?? null,
                ]);

                $result = $c->get('/api/invoices', McpFormat::pagingQuery($a) + McpFormat::searchQuery($a) + ['filter' => $filter]);
                return [
                    'invoices' => array_map(McpFormat::invoiceRow(...), McpToolContext::flattenGroups($result)),
                    'paging'   => McpFormat::paging((array) ($result['meta'] ?? [])),
                ];
            },
        );
    }

    private function getInvoice(): McpTool
    {
        return new McpTool(
            name: 'get_invoice',
            title: 'Get invoice detail',
            description: 'Returns one issued invoice with line items, VAT breakdown, bank account, payment state and '
                . 'recorded payments. Identify it by `invoice_id` or by invoice `number` (variabilní symbol). '
                . '`outstanding` is what is still unpaid; `url` opens it in the web app.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'invoice_id' => ['type' => 'integer', 'description' => 'Internal invoice id (from list_invoices).'],
                    'number' => ['type' => 'string', 'description' => 'Invoice number (variabilní symbol), e.g. "2610002".'],
                ],
                'additionalProperties' => false,
            ],
            write: false,
            handler: function (array $a, McpToolContext $c): array {
                $id = isset($a['invoice_id']) ? (int) $a['invoice_id'] : null;
                $number = trim((string) ($a['number'] ?? ''));
                if (($id === null) === ($number === '')) {
                    throw new McpToolError('Zadej právě jeden z argumentů `invoice_id` nebo `number`.', 'invalid_arguments');
                }
                $id ??= $this->resolveNumber($c, $number);

                return ['invoice' => $this->loadDetail($c, $id)];
            },
        );
    }

    private function listUnpaidInvoices(): McpTool
    {
        return new McpTool(
            name: 'list_unpaid_invoices',
            title: 'List unpaid invoices',
            description: 'Lists receivables (pohledávky): issued, sent or reminded invoices that are not fully paid, '
                . 'overdue ones first (most overdue at the top), then by due date. Includes `days_overdue`, '
                . '`outstanding` per invoice and totals per currency. Credit notes appear with a negative amount. '
                . 'Proformas that already have a final invoice are not counted.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'Only this client.'],
                    'currency' => ['type' => 'string', 'description' => 'Currency code filter, e.g. CZK.'],
                    'only_overdue' => ['type' => 'boolean', 'description' => 'true = skip invoices that are not yet due; totals then cover only the overdue ones (default false).'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Maximum rows returned (default ' . self::UNPAID_DEFAULT_LIMIT . '). Totals always cover all unpaid invoices.'],
                ],
                'additionalProperties' => false,
            ],
            write: false,
            handler: function (array $a, McpToolContext $c): array {
                $filter = McpFormat::compact([
                    'client_id' => $a['client_id'] ?? null,
                    'currency'  => $a['currency'] ?? null,
                ]);

                // Overdue invoices are a subset of the unpaid ones, so one walk is enough. The comparison
                // follows the same OverduePolicy the listing endpoint uses (`overdue_includes_today`).
                $listing = $c->walkInvoiceListing('/api/invoices', ['filter' => $filter + ['unpaid_only' => 1]]);
                $today = date('Y-m-d');
                $inclusive = $this->overdue->includesToday();

                $rows = [];
                foreach ($listing['rows'] as $row) {
                    $item = McpFormat::invoiceRow($row);
                    $due = (string) $item['due_date'];
                    $item['overdue'] = $due !== '' && ($inclusive ? $due <= $today : $due < $today);
                    $item['days_overdue'] = $item['overdue']
                        ? max(0, (int) (new \DateTimeImmutable($due))->diff(new \DateTimeImmutable($today))->format('%r%a'))
                        : 0;
                    if ($item['overdue'] || empty($a['only_overdue'])) {
                        $rows[] = $item;
                    }
                }

                usort($rows, static fn (array $x, array $y): int => [(int) !$x['overdue'], $x['due_date'], $x['id']]
                    <=> [(int) !$y['overdue'], $y['due_date'], $y['id']]);

                $totals = [];
                foreach ($rows as $item) {
                    $cur = (string) $item['currency'];
                    $totals[$cur] ??= ['currency' => $cur, 'count' => 0, 'outstanding' => 0.0, 'overdue_count' => 0, 'overdue_outstanding' => 0.0];
                    $totals[$cur]['count']++;
                    $totals[$cur]['outstanding'] += $item['outstanding'];
                    if ($item['overdue']) {
                        $totals[$cur]['overdue_count']++;
                        $totals[$cur]['overdue_outstanding'] += $item['outstanding'];
                    }
                }

                $limit = (int) ($a['limit'] ?? self::UNPAID_DEFAULT_LIMIT);
                return [
                    'invoices'    => array_slice($rows, 0, $limit),
                    'returned'    => min($limit, count($rows)),
                    'total_count' => count($rows),
                    'totals'      => array_map(static fn (array $t): array => [
                        'currency'            => $t['currency'],
                        'count'               => $t['count'],
                        'outstanding'         => round($t['outstanding'], 2),
                        'overdue_count'       => $t['overdue_count'],
                        'overdue_outstanding' => round($t['overdue_outstanding'], 2),
                    ], array_values($totals)),
                    'truncated'   => $listing['truncated'],
                ];
            },
        );
    }

    private function createDraftInvoice(): McpTool
    {
        return new McpTool(
            name: 'create_draft_invoice',
            title: 'Create draft invoice',
            description: 'Creates an invoice in DRAFT state for an existing client. It never issues it: the invoice gets '
                . 'no number and is not sent to anyone; issuing is a separate step (issue_invoice). Payload semantics '
                . 'match POST /api/v1/invoices. Dates default to today, due date to the client/project/company payment '
                . 'terms, currency to the project/client/company default. Every item needs a VAT rate: pass `vat_rate` '
                . '(percent, e.g. 21; resolved to the rate valid on the document date) or an explicit `vat_rate_id` '
                . '(see get_supplier_settings). Prices are without VAT unless `prices_include_vat` is true.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'Client (customer) id, see list_clients.'],
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'description' => 'Invoice lines.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => 'Line text.'],
                                'quantity' => ['type' => 'number', 'description' => 'Quantity, must not be 0 (negative only for discount lines).'],
                                'unit' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Unit, e.g. ks, h, měs (default ks).'],
                                'unit_price_without_vat' => ['type' => 'number', 'description' => 'Unit price without VAT (with VAT when `prices_include_vat` is true).'],
                                'vat_rate' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'VAT rate in percent (e.g. 21, 12, 0). Alternative to `vat_rate_id`.'],
                                'vat_rate_id' => ['type' => 'integer', 'description' => 'VAT rate id from get_supplier_settings; takes precedence over `vat_rate`.'],
                            ],
                            'required' => ['description', 'quantity', 'unit_price_without_vat'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'project_id' => ['type' => 'integer', 'description' => 'Project (zakázka) of the client; its currency and payment terms become defaults.'],
                    'invoice_type' => ['type' => 'string', 'enum' => self::CREATE_TYPES, 'description' => 'invoice (default), proforma (advance invoice) or credit_note (needs `parent_invoice_id`).'],
                    'parent_invoice_id' => ['type' => 'integer', 'description' => 'Original invoice, only for credit_note.'],
                    'issue_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Issue date YYYY-MM-DD (default today).'],
                    'tax_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Date of taxable supply, DUZP (default today; ignored for proforma).'],
                    'due_date' => ['type' => 'string', 'format' => 'date', 'description' => 'Due date YYYY-MM-DD (default from payment terms).'],
                    'currency' => ['type' => 'string', 'description' => 'Currency code, e.g. CZK, EUR.'],
                    'language' => ['type' => 'string', 'enum' => ['cs', 'en'], 'description' => 'Document language (default: client language).'],
                    'payment_method' => ['type' => 'string', 'enum' => self::PAYMENT_METHODS, 'description' => 'Payment method (default bank_transfer).'],
                    'reverse_charge' => ['type' => 'boolean', 'description' => 'Reverse charge (přenesená daňová povinnost); default from the client.'],
                    'prices_include_vat' => ['type' => 'boolean', 'description' => 'Item prices already include VAT.'],
                    'discount_percent' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'Whole-invoice discount in percent.'],
                    'advance_paid_amount' => ['type' => 'number', 'minimum' => 0, 'description' => 'Advance payment to deduct from the total.'],
                    'note_above_items' => ['type' => 'string', 'description' => 'Text above the items.'],
                    'note_below_items' => ['type' => 'string', 'description' => 'Text below the items.'],
                ],
                'required' => ['client_id', 'items'],
                'additionalProperties' => false,
            ],
            write: true,
            handler: function (array $a, McpToolContext $c): array {
                $documentDate = (string) ($a['tax_date'] ?? $a['issue_date'] ?? date('Y-m-d'));

                $items = [];
                $rates = null;
                foreach ($a['items'] as $i => $item) {
                    $rateId = $item['vat_rate_id'] ?? null;
                    if ($rateId === null) {
                        if (!isset($item['vat_rate'])) {
                            throw new McpToolError('Položka #' . ($i + 1) . ' musí mít `vat_rate` nebo `vat_rate_id`.', 'invalid_arguments');
                        }
                        $rates ??= $c->get('/api/codebooks/vat-rates', ['country' => 'CZ', 'active_on' => $documentDate]);
                        $rateId = $this->resolveVatRateId($rates, (float) $item['vat_rate'], $documentDate);
                    }
                    $items[] = [
                        'description'            => (string) $item['description'],
                        'quantity'               => $item['quantity'],
                        'unit'                   => (string) ($item['unit'] ?? 'ks'),
                        'unit_price_without_vat' => $item['unit_price_without_vat'],
                        'vat_rate_id'            => (int) $rateId,
                    ];
                }

                try {
                    $created = $c->post('/api/invoices', array_replace($a, ['items' => $items]));
                } catch (McpToolError $e) {
                    // An unknown client surfaces as an invalid currency; name the real cause when that is what failed.
                    throw $e->httpStatus === 400 ? $this->explainBadRequest($c, (int) $a['client_id'], $e) : $e;
                }

                return [
                    'created' => true,
                    'invoice' => McpFormat::invoiceDetail($created, [], $c->appUrl()),
                    'next_step' => 'Faktura je uložena jako koncept (draft) bez čísla a nikam se neodeslala. '
                        . 'Vystavení provede nástroj issue_invoice až po souhlasu uživatele.',
                ];
            },
        );
    }

    private function issueInvoice(): McpTool
    {
        return new McpTool(
            name: 'issue_invoice',
            title: 'Issue a draft invoice',
            description: 'Issues a draft invoice (draft -> issued): assigns the invoice number and locks the document. '
                . 'This is irreversible except by cancelling or crediting the invoice, and it does not send e-mail. '
                . 'Requires `confirm: true`; only set it after the user has explicitly approved issuing THIS invoice. '
                . 'Without confirmation the tool returns a preview and changes nothing.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'invoice_id' => ['type' => 'integer', 'description' => 'Draft invoice id (from create_draft_invoice or list_invoices).'],
                    'confirm' => self::CONFIRM_SCHEMA,
                ],
                'required' => ['invoice_id'],
                'additionalProperties' => false,
            ],
            write: true,
            handler: function (array $a, McpToolContext $c): array {
                $id = $this->confirmedInvoiceId($a, $c, 'Vystavení faktury je nevratné (přidělí se číslo a doklad se zamkne).');

                $issued = $c->post('/api/invoices/' . $id . '/issue');
                return ['issued' => true, 'invoice' => McpFormat::invoiceRow($issued) + ['url' => McpFormat::invoiceUrl($c->appUrl(), $id)]];
            },
        );
    }

    private function markInvoicePaid(): McpTool
    {
        return new McpTool(
            name: 'mark_invoice_paid',
            title: 'Record payment of an invoice',
            description: 'Records a payment on an issued/sent/reminded invoice. Without `amount` the whole remaining '
                . 'amount is marked paid; with `amount` a (partial) payment is recorded and the invoice becomes paid '
                . 'once the payments cover it. Requires `confirm: true`; only set it after the user has confirmed the '
                . 'payment (date and amount). Without confirmation the tool returns a preview and changes nothing.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'invoice_id' => ['type' => 'integer', 'description' => 'Invoice id.'],
                    'confirm' => self::CONFIRM_SCHEMA,
                    'paid_at' => ['type' => 'string', 'format' => 'date', 'description' => 'Payment date YYYY-MM-DD (default today).'],
                    'amount' => ['type' => 'number', 'minimum' => 0.01, 'description' => 'Paid amount in the invoice currency; omit to pay the full remainder.'],
                ],
                'required' => ['invoice_id'],
                'additionalProperties' => false,
            ],
            write: true,
            handler: function (array $a, McpToolContext $c): array {
                $id = $this->confirmedInvoiceId($a, $c, 'Zaevidování úhrady změní stav faktury.');

                $paidAt = (string) ($a['paid_at'] ?? date('Y-m-d'));
                if (isset($a['amount'])) {
                    $result = $c->post('/api/invoices/' . $id . '/payments', ['amount' => $a['amount'], 'paid_on' => $paidAt]);
                    return [
                        'recorded'    => true,
                        'became_paid' => (bool) ($result['became_paid'] ?? false),
                        'remaining'   => (float) ($result['remaining'] ?? 0),
                        'invoice'     => McpFormat::invoiceRow((array) ($result['invoice'] ?? [])),
                    ];
                }

                $paid = $c->post('/api/invoices/' . $id . '/mark-paid', ['paid_at' => $paidAt]);
                return [
                    'recorded'    => true,
                    'became_paid' => ($paid['status'] ?? null) === 'paid',
                    'remaining'   => 0.0,
                    'invoice'     => McpFormat::invoiceRow($paid),
                ];
            },
        );
    }

    /**
     * Guard shared by the write tools that change accounting data: returns the invoice id only when the
     * call carries `confirm: true`; otherwise throws a preview telling the model to ask the user first.
     *
     * @param array<string,mixed> $args
     */
    private function confirmedInvoiceId(array $args, McpToolContext $c, string $why): int
    {
        $id = (int) $args['invoice_id'];
        if (($args['confirm'] ?? false) !== true) {
            throw $this->confirmationRequired($c->get('/api/invoices/' . $id), $why);
        }
        return $id;
    }

    /** Replaces a generic 400 by "client not found" when the client is the actual problem. */
    private function explainBadRequest(McpToolContext $c, int $clientId, McpToolError $original): McpToolError
    {
        try {
            $c->get('/api/clients/' . $clientId);
        } catch (McpToolError $e) {
            if ($e->httpStatus === 404) {
                return new McpToolError('Klient #' . $clientId . ' nenalezen. Najdi ho přes list_clients.', 'not_found', 404);
            }
        }
        return $original;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function confirmationRequired(array $invoice, string $why): McpToolError
    {
        $row = McpFormat::invoiceRow($invoice);
        $preview = sprintf(
            'faktura %s (id %d), klient %s, %s %s, splatnost %s, stav %s, k úhradě %s %s',
            $row['number'] ?? 'bez čísla',
            $row['id'],
            $row['client']['name'] ?? '?',
            number_format($row['total_with_vat'], 2, ',', ' '),
            $row['currency'],
            $row['due_date'] ?? '-',
            $row['status'],
            number_format($row['outstanding'] ?: $row['amount_to_pay'], 2, ',', ' '),
            $row['currency'],
        );

        return new McpToolError(
            $why . ' Nic se nezměnilo. Ukaž uživateli náhled (' . $preview . ') a po jeho výslovném souhlasu zavolej '
            . 'nástroj znovu se stejnými argumenty a `confirm: true`.',
            'confirmation_required',
        );
    }

    private function resolveNumber(McpToolContext $c, string $number): int
    {
        $result = $c->get('/api/invoices', ['q' => $number, 'page' => 1, 'per_page' => 50]);
        $matches = array_values(array_filter(
            McpToolContext::flattenGroups($result),
            static fn (array $r): bool => strcasecmp(trim((string) ($r['varsymbol'] ?? '')), $number) === 0,
        ));

        if ($matches === []) {
            throw new McpToolError('Faktura s číslem ' . $number . ' nenalezena.', 'not_found', 404);
        }
        if (count($matches) > 1) {
            throw new McpToolError(
                'Číslu ' . $number . ' odpovídá více dokladů (id ' . implode(', ', array_map(static fn (array $r): int => (int) $r['id'], $matches))
                . '); použij `invoice_id`.',
                'ambiguous',
            );
        }
        return (int) $matches[0]['id'];
    }

    /** @return array<string,mixed> */
    private function loadDetail(McpToolContext $c, int $id): array
    {
        $invoice = $c->get('/api/invoices/' . $id);
        $payments = $c->get('/api/invoices/' . $id . '/payments');
        return McpFormat::invoiceDetail($invoice, $payments, $c->appUrl());
    }

    /**
     * Picks the domestic VAT rate with the given percentage from the rates valid on the document date.
     *
     * @param list<array<string,mixed>> $rates GET /api/codebooks/vat-rates?country=CZ&active_on=...
     */
    private function resolveVatRateId(array $rates, float $percent, string $date): int
    {
        $valid = array_values(array_filter($rates, static fn (array $r): bool => empty($r['is_reverse_charge'])));

        $matching = array_values(array_filter($valid, static fn (array $r): bool => abs((float) $r['rate_percent'] - $percent) < 0.005));
        if ($matching === []) {
            $available = array_unique(array_map(static fn (array $r): string => (string) (float) $r['rate_percent'], $valid));
            throw new McpToolError(
                'Sazba DPH ' . $percent . ' % není k datu ' . $date . ' dostupná. Platné sazby: ' . implode(', ', $available)
                . ' %; pro jiné případy použij `vat_rate_id` z get_supplier_settings.',
                'invalid_arguments',
            );
        }

        usort($matching, static fn (array $x, array $y): int => (int) !empty($y['is_default']) <=> (int) !empty($x['is_default']));
        return (int) $matching[0]['id'];
    }
}
