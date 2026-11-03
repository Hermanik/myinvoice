<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/**
 * Thrown by tool handlers to report a failed tool execution. Becomes an MCP
 * tool result with `isError: true` (not a JSON-RPC protocol error), so the
 * model can read the message and retry with corrected arguments.
 */
final class McpToolError extends \RuntimeException
{
    /**
     * @param array<string,mixed> $fields Per-field validation messages from the app
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $httpStatus = null,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
