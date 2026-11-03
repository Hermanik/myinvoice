<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp\Tool;

use MyInvoice\Service\Mcp\McpFormat;
use MyInvoice\Service\Mcp\McpTool;
use MyInvoice\Service\Mcp\McpToolContext;
use MyInvoice\Service\Mcp\McpToolProvider;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;

/** Received (purchase) invoice tools. */
final class PurchaseInvoiceTools implements McpToolProvider
{
    public function tools(): array
    {
        return [$this->listPurchaseInvoices()];
    }

    private function listPurchaseInvoices(): McpTool
    {
        return new McpTool(
            name: 'list_purchase_invoices',
            title: 'List received invoices',
            description: 'Lists received invoices (faktury přijaté, costs) of the current company, newest first. Filter by '
                . 'status, document kind, vendor, date range or text. `unpaid_only` / `overdue` select payables.',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'status' => McpFormat::multiValueSchema(
                        'One status or a list. Values: ' . implode(', ', PurchaseInvoiceValidation::ALLOWED_STATUSES) . '.',
                    ),
                    'document_kind' => McpFormat::multiValueSchema(
                        'Document kind or list. Values: ' . implode(', ', PurchaseInvoiceValidation::ALLOWED_DOC_KINDS) . '.',
                    ),
                    'vendor_id' => ['type' => 'integer', 'description' => 'Only invoices from this vendor (a client with the vendor role).'],
                    'query' => ['type' => 'string', 'description' => 'Text search: our number, vendor invoice number or vendor name.'],
                    'unpaid_only' => ['type' => 'boolean', 'description' => 'Only invoices still to be paid.'],
                    'overdue' => ['type' => 'boolean', 'description' => 'Only invoices past their due date.'],
                ] + McpFormat::periodSchema() + McpFormat::pagingSchema(),
                'additionalProperties' => false,
            ],
            write: false,
            handler: function (array $a, McpToolContext $c): array {
                $filter = McpFormat::compact([
                    'status'        => McpFormat::csvOf($a['status'] ?? null, PurchaseInvoiceValidation::ALLOWED_STATUSES, 'status'),
                    'document_kind' => McpFormat::csvOf($a['document_kind'] ?? null, PurchaseInvoiceValidation::ALLOWED_DOC_KINDS, 'document_kind'),
                    'vendor_id'     => $a['vendor_id'] ?? null,
                    'date_from'     => $a['date_from'] ?? null,
                    'date_to'       => $a['date_to'] ?? null,
                    'year'          => $a['year'] ?? null,
                    'month'         => $a['month'] ?? null,
                    'currency'      => $a['currency'] ?? null,
                    'unpaid_only'   => !empty($a['unpaid_only']) ? 1 : null,
                    'overdue'       => !empty($a['overdue']) ? 1 : null,
                ]);

                $query = McpFormat::pagingQuery($a) + McpFormat::searchQuery($a) + ['filter' => $filter];
                $result = $c->get('/api/purchase-invoices', $query);
                return [
                    'purchase_invoices' => array_map(McpFormat::purchaseInvoiceRow(...), McpToolContext::flattenGroups($result)),
                    'paging'            => McpFormat::paging((array) ($result['meta'] ?? [])),
                ];
            },
        );
    }
}
