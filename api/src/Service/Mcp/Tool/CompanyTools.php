<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp\Tool;

use MyInvoice\Service\Mcp\McpFormat;
use MyInvoice\Service\Mcp\McpTool;
use MyInvoice\Service\Mcp\McpToolContext;
use MyInvoice\Service\Mcp\McpToolProvider;

/** Company (supplier) settings and recurring invoicing templates. */
final class CompanyTools implements McpToolProvider
{
    public function tools(): array
    {
        return [$this->listRecurringTemplates(), $this->getSupplierSettings()];
    }

    private function listRecurringTemplates(): McpTool
    {
        return new McpTool(
            name: 'list_recurring_templates',
            title: 'List recurring invoice templates',
            description: 'Lists recurring invoicing templates (pravidelné fakturace): client, frequency, next run date, '
                . 'amount with VAT, whether they auto-issue / auto-send, and the last error if a run failed.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'Only templates of this client.'],
                    'status' => ['type' => 'string', 'enum' => ['active', 'paused', 'expired'], 'description' => 'Template status (default: all).'],
                ] + McpFormat::pagingSchema(),
                'additionalProperties' => false,
            ],
            write: false,
            handler: function (array $a, McpToolContext $c): array {
                $query = McpFormat::pagingQuery($a) + McpFormat::compact([
                    'client_id' => $a['client_id'] ?? null,
                    'status'    => $a['status'] ?? null,
                ]);

                $result = $c->get('/api/recurring', $query);
                $templates = [];
                foreach ((array) ($result['data'] ?? []) as $r) {
                    $templates[] = [
                        'id'                  => (int) $r['id'],
                        'name'                => $r['name'] ?? null,
                        'status'              => $r['status'] ?? null,
                        'client'              => ['id' => (int) ($r['client_id'] ?? 0), 'name' => $r['client_company_name'] ?? null],
                        'project'             => $r['project_name'] ?? null,
                        'frequency'           => $r['frequency'] ?? null,
                        'day_of_month'        => $r['day_of_month'] ?? null,
                        'end_of_month'        => (bool) ($r['end_of_month'] ?? false),
                        'next_run_date'       => $r['next_run_date'] ?? null,
                        'last_run_date'       => $r['last_run_date'] ?? null,
                        'end_date'            => $r['end_date'] ?? null,
                        'invoice_type'        => $r['invoice_type'] ?? null,
                        'currency'            => $r['currency'] ?? null,
                        'total_with_vat'      => (float) ($r['total_with_vat'] ?? 0),
                        'payment_due_days'    => $r['payment_due_days'] ?? null,
                        'auto_issue'          => (bool) ($r['auto_issue'] ?? false),
                        'auto_send_email'     => (bool) ($r['auto_send_email'] ?? false),
                        'invoices_generated'  => (int) ($r['invoices_generated_count'] ?? 0),
                        'last_error'          => $r['last_error'] ?? null,
                    ];
                }

                return [
                    'templates' => $templates,
                    'paging'    => McpFormat::paging((array) ($result['meta'] ?? [])),
                    'status_counts' => (array) ($result['meta']['status_counts'] ?? []),
                ];
            },
        );
    }

    private function getSupplierSettings(): McpTool
    {
        return new McpTool(
            name: 'get_supplier_settings',
            title: 'Get company settings',
            description: 'Returns the issuing company (dodavatel): name, IČO/DIČ, address, VAT payer status, invoicing '
                . 'defaults, numbering formats, currencies with bank accounts and the currently valid Czech VAT rates '
                . 'with their ids (use them as `vat_rate_id` in create_draft_invoice). Only non-secret fields are returned; '
                . 'credentials, SMTP/IMAP settings and API keys are never exposed.',
            inputSchema: ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            write: false,
            thirdPartyText: false,
            handler: function (array $a, McpToolContext $c): array {
                $s = $c->get('/api/settings/supplier');
                $rates = $c->get('/api/codebooks/vat-rates', ['country' => 'CZ', 'active_on' => date('Y-m-d')]);
                $currencies = $c->get('/api/settings/currencies');

                $vatRates = array_map(static fn (array $r): array => [
                    'id'                => (int) $r['id'],
                    'rate_percent'      => (float) $r['rate_percent'],
                    'label'             => $r['label_cs'] ?? $r['code'] ?? null,
                    'is_default'        => (bool) ($r['is_default'] ?? false),
                    'is_reverse_charge' => (bool) ($r['is_reverse_charge'] ?? false),
                ], $rates);

                $accounts = [];
                foreach ($currencies as $cur) {
                    if (empty($cur['is_active'])) {
                        continue;
                    }
                    $accounts[] = [
                        'id'             => (int) $cur['id'],
                        'code'           => $cur['code'] ?? null,
                        'is_default'     => (bool) ($cur['is_default'] ?? false),
                        'account_number' => $cur['account_number'] ?? null,
                        'bank_code'      => $cur['bank_code'] ?? null,
                        'iban'           => $cur['iban'] ?? null,
                    ];
                }

                return [
                    'company' => [
                        'id'                  => (int) $s['id'],
                        'name'                => $s['company_name'] ?? null,
                        'display_name'        => $s['display_name'] ?? null,
                        'ic'                  => $s['ic'] ?? null,
                        'dic'                 => $s['dic'] ?? null,
                        'address'             => [
                            'street'  => $s['street'] ?? null,
                            'city'    => $s['city'] ?? null,
                            'zip'     => $s['zip'] ?? null,
                            'country' => $s['country_iso'] ?? null,
                        ],
                        'email'               => $s['email'] ?? null,
                        'phone'               => $s['phone'] ?? null,
                        'web'                 => $s['web'] ?? null,
                        'commercial_register' => $s['commercial_register'] ?? null,
                        'is_vat_payer'        => (bool) ($s['is_vat_payer'] ?? false),
                        'is_identified_person' => (bool) ($s['is_identified'] ?? false),
                        'oss_enabled'         => (bool) ($s['oss_enabled'] ?? false),
                        'taxpayer_type'       => $s['taxpayer_type'] ?? null,
                        'vat_period'          => $s['vat_period'] ?? null,
                        'flat_tax_band'       => $s['flat_tax_band'] ?? null,
                        'financial_office_code' => $s['financial_office_code'] ?? null,
                        'cz_nace_code'        => $s['cz_nace_code'] ?? null,
                    ],
                    'defaults' => [
                        'currency'             => $s['default_currency'] ?? null,
                        'vat_rate_id'          => (int) ($s['default_vat_rate_id'] ?? 0),
                        'payment_due_days'     => (int) ($s['default_payment_due_days'] ?? 0),
                        'payment_due_unit'     => $s['default_payment_due_unit'] ?? 'days',
                        'hourly_rate'          => (float) ($s['default_hourly_rate'] ?? 0),
                        'prices_include_vat'   => (bool) ($s['default_prices_include_vat'] ?? false),
                        'auto_send_reminders'  => (bool) ($s['auto_send_reminders'] ?? false),
                        'reminder_days_after_due' => (int) ($s['reminder_days_after_due'] ?? 0),
                    ],
                    'numbering' => [
                        'invoice_number_format'          => $s['invoice_number_format'] ?? null,
                        'proforma_number_format'         => $s['proforma_number_format'] ?? null,
                        'credit_note_number_format'      => $s['credit_note_number_format'] ?? null,
                        'purchase_invoice_number_format' => $s['purchase_invoice_number_format'] ?? null,
                        'invoice_number_period'          => $s['invoice_number_period'] ?? null,
                    ],
                    'currencies' => $accounts,
                    'vat_rates'  => $vatRates,
                ];
            },
        );
    }
}
