<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp\Tool;

use MyInvoice\Service\Mcp\McpFormat;
use MyInvoice\Service\Mcp\McpTool;
use MyInvoice\Service\Mcp\McpToolContext;
use MyInvoice\Service\Mcp\McpToolProvider;

/** Client (customer / vendor) tools. */
final class ClientTools implements McpToolProvider
{
    public function tools(): array
    {
        return [$this->listClients(), $this->getClient()];
    }

    private function listClients(): McpTool
    {
        return new McpTool(
            name: 'list_clients',
            title: 'List clients',
            description: 'Lists clients (customers and vendors) of the current company with IČO/DIČ, e-mail, lifetime '
                . 'revenue and invoice count. Search by name, IČO or DIČ with `query`. Archived clients are hidden '
                . 'unless `archived` is true.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Text search: part of the name, or IČO / DIČ prefix.'],
                    'role' => ['type' => 'string', 'enum' => ['all', 'customers', 'vendors'], 'description' => 'customers = invoiced by us, vendors = we receive invoices from (default all).'],
                    'archived' => ['type' => 'boolean', 'description' => 'true = list archived clients instead of active ones.'],
                ] + McpFormat::pagingSchema(),
                'additionalProperties' => false,
            ],
            write: false,
            handler: function (array $a, McpToolContext $c): array {
                $query = McpFormat::pagingQuery($a) + McpFormat::searchQuery($a) + ['role' => $a['role'] ?? 'all'];
                if (!empty($a['archived'])) {
                    $query['filter'] = ['archived' => 1];
                }

                $result = $c->get('/api/clients', $query);
                $clients = [];
                foreach ((array) ($result['data'] ?? []) as $r) {
                    $clients[] = [
                        'id'                => (int) $r['id'],
                        'name'              => $r['company_name'] ?? null,
                        'ic'                => $r['ic'] ?? null,
                        'dic'               => $r['dic'] ?? null,
                        'email'             => $r['main_email'] ?? null,
                        'country'           => $r['country_iso2'] ?? null,
                        'currency'          => $r['currency_default'] ?? null,
                        'is_customer'       => (bool) ($r['is_customer'] ?? false),
                        'is_vendor'         => (bool) ($r['is_vendor'] ?? false),
                        'archived'          => ($r['archived_at'] ?? null) !== null,
                        'invoice_count'     => (int) ($r['invoice_count'] ?? 0),
                        'revenue'           => (float) ($r['revenue'] ?? 0),
                        'last_invoice_date' => $r['last_invoice_date'] ?? null,
                    ];
                }

                return ['clients' => $clients, 'paging' => McpFormat::paging((array) ($result['meta'] ?? []))];
            },
        );
    }

    private function getClient(): McpTool
    {
        return new McpTool(
            name: 'get_client',
            title: 'Get client detail',
            description: 'Returns one client: identification (IČO, DIČ), address, contact e-mail, billing defaults '
                . '(currency, payment terms, hourly rate), projects, revenue per year and unpaid / overdue totals.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'client_id' => ['type' => 'integer', 'description' => 'Client id, see list_clients.'],
                ],
                'required' => ['client_id'],
                'additionalProperties' => false,
            ],
            write: false,
            handler: function (array $a, McpToolContext $c): array {
                $r = $c->get('/api/clients/' . (int) $a['client_id']);

                $projects = [];
                foreach ((array) ($r['projects'] ?? []) as $p) {
                    $projects[] = [
                        'id'          => (int) $p['id'],
                        'name'        => $p['name'] ?? null,
                        'status'      => $p['status'] ?? null,
                        'currency'    => $p['currency'] ?? null,
                        'hourly_rate' => isset($p['hourly_rate']) ? (float) $p['hourly_rate'] : null,
                    ];
                }

                return ['client' => [
                    'id'                 => (int) $r['id'],
                    'name'               => $r['company_name'] ?? null,
                    'first_name'         => $r['first_name'] ?? null,
                    'last_name'          => $r['last_name'] ?? null,
                    'ic'                 => $r['ic'] ?? null,
                    'dic'                => $r['dic'] ?? null,
                    'tax_number'         => $r['tax_number'] ?? null,
                    'is_vat_payer'       => (bool) ($r['is_vat_payer'] ?? false),
                    'address'            => [
                        'street'  => $r['street'] ?? null,
                        'city'    => $r['city'] ?? null,
                        'zip'     => $r['zip'] ?? null,
                        'country' => $r['country_iso2'] ?? null,
                    ],
                    'email'              => $r['main_email'] ?? null,
                    'phone'              => $r['phone'] ?? null,
                    'language'           => $r['language'] ?? null,
                    'currency'           => $r['currency_default'] ?? null,
                    'reverse_charge'     => (bool) ($r['reverse_charge'] ?? false),
                    'is_customer'        => (bool) ($r['is_customer'] ?? false),
                    'is_vendor'          => (bool) ($r['is_vendor'] ?? false),
                    'payment_due_default' => isset($r['payment_due_default']) ? (int) $r['payment_due_default'] : null,
                    'payment_due_unit'   => $r['payment_due_unit'] ?? null,
                    'hourly_rate'        => (float) ($r['hourly_rate'] ?? 0),
                    'note'               => $r['note'] ?? null,
                    'archived'           => ($r['archived_at'] ?? null) !== null,
                    'invoices_count'     => (int) ($r['invoices_count'] ?? 0),
                    'purchase_invoices_count' => (int) ($r['purchase_invoices_count'] ?? 0),
                    'projects'           => $projects,
                    'revenue_by_year'    => (array) ($r['revenue_by_year'] ?? []),
                    'unpaid_summary'     => (array) ($r['unpaid_summary'] ?? []),
                ]];
            },
        );
    }
}
