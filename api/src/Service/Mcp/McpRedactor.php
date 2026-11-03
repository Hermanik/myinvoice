<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/**
 * Last line of defence: removes secret-looking keys from every tool result.
 * The tools already project payloads through allowlists; this guards against a
 * future tool (or an endpoint gaining a column) leaking tokens or credentials.
 */
final class McpRedactor
{
    private const SECRET_KEY = '/token|password|passwd|passphrase|secret|api_?key|private_?key|credential|_enc$/i';

    /**
     * @param array<array-key,mixed> $data
     * @return array<array-key,mixed>
     */
    public static function redact(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                continue;
            }
            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }
        return $clean;
    }
}
