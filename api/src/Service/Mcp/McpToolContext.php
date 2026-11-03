<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Per-call facade handed to tool handlers: authenticated internal API access
 * for the caller of the current MCP request.
 */
final class McpToolContext
{
    /** Page size used when a tool has to walk a whole listing. */
    private const WALK_PER_PAGE = 200;

    /** Hard stop for walked listings (pages x 200 rows) so one call stays bounded. */
    private const WALK_MAX_PAGES = 10;

    public function __construct(
        private readonly Request $request,
        private readonly InternalApiClient $api,
        private readonly string $appUrl,
    ) {}

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->unwrap($this->api->request($this->request, 'GET', $path, $query));
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->unwrap($this->api->request($this->request, 'POST', $path, [], $body));
    }

    /**
     * Walks a month-grouped listing (`data[].invoices[]`) page by page and
     * returns the flattened rows.
     *
     * @param array<string,mixed> $query
     * @return array{rows: list<array<string,mixed>>, truncated: bool}
     */
    public function walkInvoiceListing(string $path, array $query): array
    {
        $rows = [];
        $pages = 1;
        for ($page = 1; $page <= $pages && $page <= self::WALK_MAX_PAGES; $page++) {
            $result = $this->get($path, $query + ['page' => $page, 'per_page' => self::WALK_PER_PAGE]);
            foreach (self::flattenGroups($result) as $row) {
                $rows[] = $row;
            }
            $pages = (int) ($result['meta']['pages'] ?? 1);
        }

        return ['rows' => $rows, 'truncated' => $pages > self::WALK_MAX_PAGES];
    }

    /**
     * Month groups (`data[].invoices[]`) to a flat list of rows.
     *
     * @param array<string,mixed> $listing
     * @return list<array<string,mixed>>
     */
    public static function flattenGroups(array $listing): array
    {
        $rows = [];
        foreach ((array) ($listing['data'] ?? []) as $group) {
            foreach ((array) ($group['invoices'] ?? []) as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function appUrl(): string
    {
        return $this->appUrl;
    }

    /**
     * @return array<string,mixed>
     */
    private function unwrap(InternalApiResponse $response): array
    {
        if (!$response->ok()) {
            throw new McpToolError(
                $response->errorMessage(),
                $response->errorCode(),
                $response->status,
                $response->errorFields(),
            );
        }
        return $response->data;
    }
}
