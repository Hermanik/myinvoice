<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Service\Mcp\Tool\ClientTools;
use MyInvoice\Service\Mcp\Tool\CompanyTools;
use MyInvoice\Service\Mcp\Tool\InvoiceTools;
use MyInvoice\Service\Mcp\Tool\PurchaseInvoiceTools;
use MyInvoice\Service\Mcp\Tool\RevenueTools;

/**
 * Registry of all MCP tools. To add a tool, implement it in a McpToolProvider
 * (or add it to an existing one); `tools/list` order is the registration order.
 */
final class McpToolRegistry
{
    /** @var array<string,McpTool>|null */
    private ?array $tools = null;

    public function __construct(
        private readonly InvoiceTools $invoices,
        private readonly ClientTools $clients,
        private readonly PurchaseInvoiceTools $purchaseInvoices,
        private readonly RevenueTools $revenue,
        private readonly CompanyTools $company,
    ) {}

    public function find(string $name): ?McpTool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Tools visible to a caller. Write tools are hidden from read-only tokens
     * and roles; `tools/call` enforces the same rule independently.
     *
     * @return list<McpTool>
     */
    public function visible(bool $canWrite): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (McpTool $tool): bool => $canWrite || !$tool->write,
        ));
    }

    /** @return array<string,McpTool> */
    private function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            foreach ([$this->invoices, $this->clients, $this->purchaseInvoices, $this->revenue, $this->company] as $provider) {
                foreach ($provider->tools() as $tool) {
                    $this->tools[$tool->name] = $tool;
                }
            }
        }
        return $this->tools;
    }
}
