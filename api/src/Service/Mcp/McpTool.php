<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/** One MCP tool: metadata for `tools/list` plus the handler for `tools/call`. */
final class McpTool
{
    /**
     * @param array<string,mixed> $inputSchema JSON Schema (draft 2020-12 subset, see McpArgumentValidator)
     * @param \Closure(array<string,mixed>, McpToolContext): array<string,mixed> $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly bool $write,
        private readonly \Closure $handler,
    ) {}

    /**
     * @param array<string,mixed> $arguments Already validated against the input schema
     * @return array<string,mixed>
     */
    public function execute(array $arguments, McpToolContext $context): array
    {
        return ($this->handler)($arguments, $context);
    }

    /** @return array<string,mixed> Entry of the `tools/list` result */
    public function toListEntry(): array
    {
        return [
            'name'        => $this->name,
            'title'       => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'annotations' => $this->write
                // Issuing and payment changes are not idempotent; nothing here deletes data.
                ? ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]
                : ['readOnlyHint' => true, 'openWorldHint' => false],
        ];
    }
}
