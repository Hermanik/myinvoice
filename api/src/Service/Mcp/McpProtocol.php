<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/**
 * Constants of the Model Context Protocol subset implemented by the server.
 *
 * The endpoint is stateless and answers with plain JSON (no SSE), which is
 * valid for every revision below:
 *   - 2026-07-28: per-request metadata (`_meta`), no `initialize`, no sessions
 *   - 2025-11-25 / 2025-06-18 / 2025-03-26: `initialize` handshake, optional sessions
 */
final class McpProtocol
{
    /** Latest revision, served statelessly (no handshake). */
    public const MODERN_VERSION = '2026-07-28';

    /** Handshake-based revisions, newest first. */
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /** Assumed when a legacy client sends no `MCP-Protocol-Version` header. */
    public const DEFAULT_LEGACY_VERSION = '2025-03-26';

    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const HEADER_MISMATCH = -32020;
    public const UNSUPPORTED_VERSION = -32022;

    /** @return list<string> */
    public static function supportedVersions(): array
    {
        return [self::MODERN_VERSION, ...self::LEGACY_VERSIONS];
    }

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::supportedVersions(), true);
    }

    public static function isModern(string $version): bool
    {
        return $version === self::MODERN_VERSION;
    }
}
