<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/**
 * Transport-neutral result of handling one MCP HTTP message: status code and
 * optional JSON-RPC body (null = empty body, e.g. 202 for notifications).
 */
final class McpResponse
{
    /** @param array<string,mixed>|null $body */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body = null,
    ) {}

    public static function accepted(): self
    {
        return new self(202);
    }

    /** @param array<string,mixed>|\stdClass $result stdClass for an empty `{}` result */
    public static function result(mixed $id, array|\stdClass $result): self
    {
        return new self(200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    public static function error(mixed $id, int $code, string $message, int $status = 200, mixed $data = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return new self($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
    }
}
