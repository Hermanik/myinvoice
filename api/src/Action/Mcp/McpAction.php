<?php

declare(strict_types=1);

namespace MyInvoice\Action\Mcp;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Mcp\McpProtocol;
use MyInvoice\Service\Mcp\McpResponse;
use MyInvoice\Service\Mcp\McpServer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/mcp — Model Context Protocol endpoint (Streamable HTTP, JSON responses).
 *
 * Bearer API tokens only: AI assistants authenticate with the same personal
 * access tokens as the REST API, so scopes, supplier binding and rate limits
 * are shared. GET and DELETE answer 405 (no server-initiated SSE stream, no
 * sessions to terminate), as the Streamable HTTP transport permits.
 */
final class McpAction
{
    /** A JSON-RPC message is tiny; anything bigger is not a legitimate MCP call. */
    private const MAX_BODY_BYTES = 1_048_576;

    public function __construct(
        private readonly McpServer $server,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'bearer') {
            return Json::error(
                $response->withHeader('WWW-Authenticate', 'Bearer realm="MyInvoice MCP"'),
                'bearer_required',
                'MCP endpoint vyžaduje API token v hlavičce Authorization: Bearer.',
                401,
            );
        }

        // Streamable HTTP: protect against DNS rebinding from browsers. Native MCP clients send no Origin.
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && !$this->originAllowed($origin)) {
            return $this->write($response, McpResponse::error(null, McpProtocol::INVALID_REQUEST, 'Nepovolený původ požadavku (Origin).', 403));
        }

        $raw = (string) $request->getBody();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->write($response, McpResponse::error(null, McpProtocol::INVALID_REQUEST, 'Požadavek je příliš velký.', 413));
        }

        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->write($response, McpResponse::error(null, McpProtocol::PARSE_ERROR, 'Tělo požadavku není platný JSON.', 400));
        }

        return $this->write($response, $this->server->handle($request, $payload));
    }

    /** GET / DELETE: this server offers neither an SSE stream nor sessions. */
    public function notAllowed(Request $request, Response $response): Response
    {
        $result = McpResponse::error(null, McpProtocol::INVALID_REQUEST, 'Metoda není povolena, použij POST.', 405);
        return $this->write($response, $result)->withHeader('Allow', 'POST');
    }

    private function originAllowed(string $origin): bool
    {
        $allowed = array_map(
            static fn ($o): string => rtrim(strtolower((string) $o), '/'),
            (array) $this->config->get('mcp.allowed_origins', []),
        );

        $app = parse_url((string) $this->config->get('app.url', ''));
        if (isset($app['scheme'], $app['host'])) {
            $allowed[] = strtolower($app['scheme'] . '://' . $app['host'] . (isset($app['port']) ? ':' . $app['port'] : ''));
        }

        return in_array(rtrim(strtolower($origin), '/'), $allowed, true);
    }

    private function write(Response $response, McpResponse $result): Response
    {
        $response = $response->withStatus($result->status)->withHeader('Cache-Control', 'no-store');
        if ($result->body === null) {
            return $response;
        }

        $response->getBody()->write(json_encode(
            $result->body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
