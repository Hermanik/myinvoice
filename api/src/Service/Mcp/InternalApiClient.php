<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request as SlimRequest;

/**
 * Dispatches requests to the app's own `/api/*` routes through the complete
 * Slim middleware stack (the same one a direct `/api/v1/*` call goes through).
 *
 * MCP tools never touch repositories themselves: every tool is a call to an
 * existing endpoint, so validation, RBAC, token scope and supplier scoping
 * are applied by the very same code as for the public REST API. The caller's
 * `Authorization` bearer token (and `X-Supplier-Id`) is forwarded, so the
 * stack re-authenticates the token on every internal call; cookies are never
 * forwarded.
 */
final class InternalApiClient
{
    /** Headers copied from the MCP request to the internal request. */
    private const FORWARDED_HEADERS = ['Authorization', SupplierScopeMiddleware::HEADER_NAME, 'Accept-Language'];

    public function __construct(private readonly RequestHandlerInterface $app) {}

    /**
     * @param array<string,mixed>      $query
     * @param array<string,mixed>|null $body  JSON body for POST/PUT
     */
    public function request(
        Request $outer,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
    ): InternalApiResponse {
        $uri = $outer->getUri()
            ->withPath($path)
            ->withQuery(http_build_query($query))
            ->withFragment('');

        $payload = $body === null
            ? ''
            : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Built by hand: Slim's ServerRequestFactory would copy the current PHP process's headers and
        // cookies (the MCP request itself) into the new request. Only what is listed here is passed on.
        $request = (new SlimRequest(
            $method,
            $uri,
            new Headers(),
            [],
            $outer->getServerParams(),
            (new StreamFactory())->createStream($payload),
        ))
            ->withHeader('Accept', 'application/json')
            // Marks audit-log entries (activity_log.user_agent) as MCP-originated.
            ->withHeader('User-Agent', 'MyInvoice-MCP (' . $outer->getHeaderLine('User-Agent') . ')');

        foreach (self::FORWARDED_HEADERS as $name) {
            if ($outer->hasHeader($name)) {
                $request = $request->withHeader($name, $outer->getHeaderLine($name));
            }
        }

        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        try {
            $response = $this->app->handle($request);
        } catch (\Throwable) {
            return new InternalApiResponse(500, []);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        return new InternalApiResponse($response->getStatusCode(), is_array($decoded) ? $decoded : []);
    }
}
