<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/** A group of related tools contributed to the registry. */
interface McpToolProvider
{
    /** @return list<McpTool> */
    public function tools(): array;
}
