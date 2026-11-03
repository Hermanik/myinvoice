<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp\Tool;

use MyInvoice\Service\Invoice\InvoicePaymentService;
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

    /** Document types a payment can be recorded on (same as InvoicePaymentService). */
    private const PAYABLE_TYPES = ['invoice', 'proforma'];

    /** Default `limit` of list_unpaid_invoices. */
    private const UNPAID_DEFAULT_LIMIT = 50;

    /** Bounds that keep a draft within the database columns (quantity DECIMAL(10,3), prices/totals DECIMAL(12,2)). */
    private const MAX_ITEMS = 200;
    private const MAX_QUANTITY = 1_000_000;
    private const MAX_AMOUNT = 1_000_000_000;

    private const CONFIRMATION_PROPERTIES = [
        'confirm' => [
            'type' => 'boolean',
            'description' => 'Must be exactly true, together with `confirmation_token`, and only after explicit user approval.',
        ],
        'confirmation_token' => [
            'type' => 'string',
            'maxLength' => 100,
            'description' => 'The `confirmation_token` returned by the unconfirmed call with exactly the same arguments (valid for 10 minutes).',
        ],
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
                . '(see get_supplier_settings). Prices are without VAT unless `prices_include_vat` is true. Automatic '
                . 'payment reminders (e-mails to the client) are OFF for drafts created here unless `auto_send_reminders` is true.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'Client (customer) id, see list_clients.'],
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => self::MAX_ITEMS,
                        'description' => 'Invoice lines (at most ' . self::MAX_ITEMS . ').',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => 'Line text.'],
                                'quantity' => ['type' => 'number', 'minimum' => -self::MAX_QUANTITY, 'maximum' => self::MAX_QUANTITY, 'description' => 'Quantity, must not be 0 (negative only for discount lines).'],
                                'unit' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Unit, e.g. ks, h, měs (default ks).'],
                                'unit_price_without_vat' => ['type' => 'number', 'minimum' => -self::MAX_AMOUNT, 'maximum' => self::MAX_AMOUNT, 'description' => 'Unit price without VAT (with VAT when `prices_include_vat` is true).'],
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
                    'advance_paid_amount' => ['type' => 'number', 'minimum' => 0, 'maximum' => self::MAX_AMOUNT, 'description' => 'Advance payment to deduct from the total.'],
                    'auto_send_reminders' => ['type' => 'boolean', 'description' => 'Let the reminder job e-mail the client when the invoice is overdue (default false).'],
                    'note_above_items' => ['type' => 'string', 'maxLength' => 5000, 'description' => 'Text above the items.'],
                    'note_below_items' => ['type' => 'string', 'maxLength' => 5000, 'description' => 'Text below the items.'],
                ],
                'required' => ['client_id', 'items'],
                'additionalProperties' => false,
            ],
            write: true,
            destructive: false,
            handler: function (array $a, McpToolContext $c): array {
                // Everything that could make the create fail half-way is rejected before the first call.
                $this->assertAmountsInRange($a['items']);
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
                    // The app defaults new drafts to automatic reminders; here they are opt-in.
                    $created = $c->post('/api/invoices', array_replace(['auto_send_reminders' => false], $a, ['items' => $items]));
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
                . 'This is irreversible except by cancelling or crediting the invoice, and it does not send e-mail itself. '
                . 'If automatic reminders are on for the invoice (`auto_send_reminders`, off for drafts made by '
                . 'create_draft_invoice unless requested), the reminder job may e-mail the client once it is overdue. '
                . 'Two steps: the first call only returns a preview and a `confirmation_token`; after the user has '
                . 'explicitly approved issuing THIS invoice, repeat the call with `confirm: true` and that token. '
                . 'Editing the draft in between invalidates the token.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'invoice_id' => ['type' => 'integer', 'description' => 'Draft invoice id (from create_draft_invoice or list_invoices).'],
                ] + self::CONFIRMATION_PROPERTIES,
                'required' => ['invoice_id'],
                'additionalProperties' => false,
            ],
            write: true,
            handler: function (array $a, McpToolContext $c): array {
                $id = (int) $a['invoice_id'];
                $invoice = $c->get('/api/invoices/' . $id);
                if (($invoice['status'] ?? null) !== 'draft') {
                    throw new McpToolError('Lze vystavit jen koncept (draft); faktura má stav ' . ($invoice['status'] ?? '?') . '.', 'invalid_state', 409);
                }
                $pending = $this->confirmationPending(
                    'issue_invoice',
                    $a,
                    $c,
                    $invoice,
                    'Vystavení faktury je nevratné (přidělí se číslo a doklad se zamkne).',
                    ['total_with_vat' => $invoice['total_with_vat'] ?? null, 'client_id' => $invoice['client_id'] ?? null, 'updated_at' => $invoice['updated_at'] ?? null],
                );
                if ($pending !== null) {
                    return $pending;
                }

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
                . 'once the payments cover it. A payment above the remaining amount, or one identical (amount and date) '
                . 'to an existing payment, is refused (`allow_duplicate: true` overrides the duplicate check only). '
                . 'Two steps: the first call only returns a preview and a `confirmation_token`; after the user has '
                . 'confirmed the payment (date and amount), repeat the call with `confirm: true` and that token. '
                . 'Any payment recorded in between invalidates the token.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'invoice_id' => ['type' => 'integer', 'description' => 'Invoice id.'],
                    'paid_at' => ['type' => 'string', 'format' => 'date', 'description' => 'Payment date YYYY-MM-DD (default today).'],
                    'amount' => ['type' => 'number', 'minimum' => 0.01, 'maximum' => self::MAX_AMOUNT, 'description' => 'Paid amount in the invoice currency; omit to pay the full remainder.'],
                    'allow_duplicate' => ['type' => 'boolean', 'description' => 'Record a payment even though one with the same amount and date already exists (default false).'],
                ] + self::CONFIRMATION_PROPERTIES,
                'required' => ['invoice_id'],
                'additionalProperties' => false,
            ],
            write: true,
            handler: function (array $a, McpToolContext $c): array {
                $id = (int) $a['invoice_id'];
                $paidAt = (string) ($a['paid_at'] ?? date('Y-m-d'));
                $amount = isset($a['amount']) ? round((float) $a['amount'], 2) : null;
                $invoice = $c->get('/api/invoices/' . $id);

                $this->assertOpenForPayment($invoice);
                if ($amount !== null) {
                    $payments = $c->get('/api/invoices/' . $id . '/payments');
                    $this->assertPaymentAllowed($invoice, $payments, $amount, $paidAt, !empty($a['allow_duplicate']));
                }
                $pending = $this->confirmationPending(
                    'mark_invoice_paid',
                    $a,
                    $c,
                    $invoice,
                    'Zaevidování úhrady změní stav faktury.',
                    // Bound to what is already paid and to the resolved date, so a changed invoice needs a new preview.
                    ['paid_total' => $invoice['paid_total'] ?? null, 'amount_to_pay' => $invoice['amount_to_pay'] ?? null, 'paid_at' => $paidAt],
                    ['paid_at' => $paidAt, 'amount' => $amount],
                );
                if ($pending !== null) {
                    return $pending;
                }

                if ($amount !== null) {
                    $result = $c->post('/api/invoices/' . $id . '/payments', ['amount' => $amount, 'paid_on' => $paidAt]);
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
     * Rejects a draft whose lines could overflow the DECIMAL columns, so the create endpoint is never
     * reached with a payload that would leave a draft without items.
     *
     * @param list<array<string,mixed>> $items
     */
    private function assertAmountsInRange(array $items): void
    {
        $total = 0.0;
        foreach ($items as $i => $item) {
            $total += abs((float) $item['quantity'] * (float) $item['unit_price_without_vat']);
            if ($total > self::MAX_AMOUNT) {
                throw new McpToolError(
                    'Položka #' . ($i + 1) . ' je mimo rozsah: součet řádků nesmí překročit ' . number_format(self::MAX_AMOUNT, 0, ',', ' ') . '.',
                    'invalid_arguments',
                );
            }
        }
    }

    /**
     * A payment can only be recorded on an invoice that is open (issued, sent or reminded). Checked before a
     * confirmation token is handed out, so the user is never asked to approve something that is refused.
     *
     * @param array<string,mixed> $invoice GET /api/invoices/{id}
     */
    private function assertOpenForPayment(array $invoice): void
    {
        $status = (string) ($invoice['status'] ?? '');
        if (!in_array($status, McpFormat::UNPAID_STATUSES, true)) {
            throw new McpToolError('Platbu lze evidovat jen u vystavené, odeslané nebo upomínané faktury; faktura má stav ' . $status . '.', 'invalid_state', 409);
        }
    }

    /**
     * Guards against double booking and overpayment before a partial payment is recorded.
     *
     * @param array<string,mixed> $invoice  GET /api/invoices/{id}
     * @param array<string,mixed> $payments GET /api/invoices/{id}/payments
     */
    private function assertPaymentAllowed(array $invoice, array $payments, float $amount, string $paidAt, bool $allowDuplicate): void
    {
        if (!in_array((string) ($invoice['invoice_type'] ?? ''), self::PAYABLE_TYPES, true)) {
            throw new McpToolError('Platby lze evidovat jen u faktur a zálohových faktur.', 'invalid_state', 409);
        }

        $remaining = (float) ($payments['remaining'] ?? 0);
        if (round($amount - $remaining, 2) > InvoicePaymentService::TOLERANCE) {
            throw new McpToolError(
                'Částka ' . McpFormat::money($amount) . ' přesahuje zbývající k úhradě ' . McpFormat::money(max(0.0, $remaining)) . ' (přeplatek se neeviduje).',
                'overpayment',
                409,
            );
        }

        if (!$allowDuplicate) {
            foreach ((array) ($payments['payments'] ?? []) as $existing) {
                if (abs((float) ($existing['amount'] ?? 0) - $amount) < 0.005 && ($existing['paid_on'] ?? null) === $paidAt) {
                    throw new McpToolError(
                        'Platba ' . McpFormat::money($amount) . ' ze dne ' . $paidAt . ' už u této faktury existuje. '
                        . 'Pokud jde o další stejnou platbu, zopakuj volání s `allow_duplicate: true`.',
                        'duplicate_payment',
                        409,
                    );
                }
            }
        }
    }

    /**
     * Gate shared by the write tools that change accounting data. Returns null when the call carries
     * `confirm: true` plus a valid confirmation token for exactly these arguments and this state. Otherwise it
     * returns the result to hand back instead of acting: a preview with a fresh token and the instruction to
     * ask the user first. A preview is a normal result, not an error: nothing failed, nothing was done.
     *
     * @param array<string,mixed> $args
     * @param array<string,mixed> $invoice
     * @param array<string,mixed> $state   Fingerprint the token is bound to
     * @param array<string,mixed> $planned What the confirmed call is going to do, shown in the preview
     * @return array<string,mixed>|null
     */
    private function confirmationPending(string $tool, array $args, McpToolContext $c, array $invoice, string $why, array $state, array $planned = []): ?array
    {
        if ($c->isConfirmed($tool, $args, $state)) {
            return null;
        }

        $confirmation = $c->issueConfirmation($tool, $args, $state);
        $preview = McpFormat::invoiceRow($invoice) + ['auto_send_reminders' => (bool) ($invoice['auto_send_reminders'] ?? false)];
        $summary = sprintf(
            'faktura %s (id %d), klient %s, %s %s, splatnost %s, stav %s, k úhradě %s %s, automatické upomínky %s',
            $preview['number'] ?? 'bez čísla',
            $preview['id'],
            $preview['client']['name'] ?? '?',
            McpFormat::money($preview['total_with_vat']),
            $preview['currency'],
            $preview['due_date'] ?? '-',
            $preview['status'],
            McpFormat::money($preview['outstanding'] ?: $preview['amount_to_pay']),
            $preview['currency'],
            $preview['auto_send_reminders'] ? 'ano' : 'ne',
        );

        return [
            'executed'                => false,
            'requires_confirmation'   => true,
            'message'                 => $why . ' Nic se nezměnilo. Ukaž uživateli náhled (' . $summary . ') a po jeho výslovném souhlasu zavolej nástroj '
                . 'znovu se stejnými argumenty, `confirm: true` a `confirmation_token: ' . $confirmation['token'] . '` '
                . '(platí do ' . $confirmation['expires_at'] . ').',
            'preview'                 => $preview,
            'planned'                 => $planned,
            'confirmation_token'      => $confirmation['token'],
            'confirmation_expires_at' => $confirmation['expires_at'],
        ];
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
