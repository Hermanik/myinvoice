<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Service\Mcp\McpProtocol;
use PHPUnit\Framework\TestCase;

/** JSON-RPC / MCP transport semantics of McpServer (both protocol eras). */
final class McpServerProtocolTest extends TestCase
{
    use McpTestHarness;

    private const META = [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'],
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];

    /** @return array<string,mixed> */
    private function modernMessage(string $method, array $params = [], int|string $id = 1): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params + ['_meta' => self::META]];
    }

    /** @return array<string,string> */
    private function modernHeaders(string $method, ?string $name = null): array
    {
        return ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method] + ($name !== null ? ['Mcp-Name' => $name] : []);
    }

    public function testInitializeEchoesSupportedLegacyVersionAndMintsNoSession(): void
    {
        foreach (['2025-11-25', '2025-06-18', '2025-03-26'] as $version) {
            $response = $this->server()->handle($this->request(), [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => ['protocolVersion' => $version, 'capabilities' => [], 'clientInfo' => ['name' => 'c', 'version' => '1']],
            ]);
            $result = $this->ok($response);

            self::assertSame($version, $result['protocolVersion']);
            self::assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
            self::assertSame('myinvoice', $result['serverInfo']['name']);
            self::assertSame('9.9.9', $result['serverInfo']['version']);
            self::assertNotSame('', $result['instructions']);
            self::assertSame(1, $response->body['id']);
        }
    }

    public function testInitializeWithUnknownVersionOffersNewestHandshakeRevision(): void
    {
        $result = $this->ok($this->server()->handle($this->request(), [
            'jsonrpc' => '2.0', 'id' => 'a', 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01'],
        ]));

        self::assertSame('2025-11-25', $result['protocolVersion']);
    }

    public function testInitializeWithoutProtocolVersionIsInvalidParams(): void
    {
        $response = $this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);

        self::assertSame(McpProtocol::INVALID_PARAMS, $response->body['error']['code']);
    }

    public function testNotificationsAreAcceptedWithoutBody(): void
    {
        foreach (['notifications/initialized', 'notifications/cancelled', 'something/unknown'] as $method) {
            $response = $this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'method' => $method]);

            self::assertSame(202, $response->status, $method);
            self::assertNull($response->body, $method);
        }
    }

    public function testNotificationNeverExecutesATool(): void
    {
        $response = $this->server()->handle($this->request(), [
            'jsonrpc' => '2.0', 'method' => 'tools/call',
            'params' => ['name' => 'issue_invoice', 'arguments' => ['invoice_id' => 7, 'confirm' => true]],
        ]);

        self::assertSame(202, $response->status);
        self::assertSame([], $this->api->calls);
    }

    public function testClientSentJsonRpcResponseIsAcknowledged(): void
    {
        $response = $this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 4, 'result' => []]);

        self::assertSame(202, $response->status);
    }

    public function testPingReturnsEmptyObject(): void
    {
        $response = $this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping']);

        self::assertSame(200, $response->status);
        self::assertEquals(new \stdClass(), $response->body['result']);
        self::assertSame('{"jsonrpc":"2.0","id":9,"result":{}}', json_encode($response->body));
    }

    public function testToolsListReturnsAllToolsForWriteTokenWithSchemasAndAnnotations(): void
    {
        $result = $this->ok($this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        $names = array_column($result['tools'], 'name');
        self::assertSame([
            'list_invoices', 'get_invoice', 'list_unpaid_invoices', 'create_draft_invoice', 'issue_invoice',
            'mark_invoice_paid', 'list_clients', 'get_client', 'list_purchase_invoices', 'revenue_summary',
            'list_recurring_templates', 'get_supplier_settings',
        ], $names);

        foreach ($result['tools'] as $tool) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $tool['name']);
            self::assertSame('object', $tool['inputSchema']['type'], $tool['name']);
            self::assertNotSame('', $tool['description'], $tool['name']);
            self::assertArrayHasKey('readOnlyHint', $tool['annotations'], $tool['name']);
        }
        // `{}`, not `[]`, for a tool without arguments.
        self::assertStringContainsString('"properties":{}', json_encode($result['tools'][11]['inputSchema']));
    }

    public function testReadOnlyTokenDoesNotSeeWriteTools(): void
    {
        $result = $this->ok($this->server()->handle($this->request('read'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        $names = array_column($result['tools'], 'name');
        self::assertNotContains('create_draft_invoice', $names);
        self::assertNotContains('issue_invoice', $names);
        self::assertNotContains('mark_invoice_paid', $names);
        self::assertContains('list_invoices', $names);
        self::assertCount(9, $names);
    }

    public function testReadonlyRoleDoesNotSeeWriteToolsEvenWithWriteScope(): void
    {
        $result = $this->ok($this->server()->handle($this->request('read_write', 'readonly'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        self::assertCount(9, $result['tools']);
    }

    public function testToolsListRejectsForeignCursor(): void
    {
        $response = $this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['cursor' => 'abc']]);

        self::assertSame(McpProtocol::INVALID_PARAMS, $response->body['error']['code']);
    }

    public function testUnknownMethodIsMethodNotFound(): void
    {
        $response = $this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list']);

        self::assertSame(200, $response->status);
        self::assertSame(McpProtocol::METHOD_NOT_FOUND, $response->body['error']['code']);
        self::assertSame(3, $response->body['id']);
    }

    public function testToolsCallInvalidParams(): void
    {
        $cases = [
            'missing name'      => ['arguments' => []],
            'empty name'        => ['name' => ''],
            'numeric name'      => ['name' => 5],
            'list arguments'    => ['name' => 'list_clients', 'arguments' => [1, 2]],
            'scalar arguments'  => ['name' => 'list_clients', 'arguments' => 'x'],
            'unknown tool'      => ['name' => 'drop_database', 'arguments' => []],
        ];
        foreach ($cases as $label => $params) {
            $response = $this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params]);

            self::assertSame(McpProtocol::INVALID_PARAMS, $response->body['error']['code'] ?? null, $label);
        }
    }

    public function testParamsMustBeAnObject(): void
    {
        $response = $this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => 'x']);

        self::assertSame(McpProtocol::INVALID_PARAMS, $response->body['error']['code']);
    }

    public function testMalformedEnvelopesAreInvalidRequests(): void
    {
        $cases = [
            'not an object'     => 'hello',
            'empty object'      => [],
            'batch'             => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']],
            'no jsonrpc'        => ['id' => 1, 'method' => 'ping'],
            'wrong jsonrpc'     => ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'],
            'no method'         => ['jsonrpc' => '2.0', 'id' => 1],
            'method not string' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 7],
            'null id'           => ['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'],
            'float id'          => ['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping'],
            'array id'          => ['jsonrpc' => '2.0', 'id' => [1], 'method' => 'ping'],
        ];
        foreach ($cases as $label => $payload) {
            $response = $this->server()->handle($this->request(), $payload);

            self::assertSame(400, $response->status, $label);
            self::assertSame(McpProtocol::INVALID_REQUEST, $response->body['error']['code'], $label);
            self::assertNull($response->body['id'], $label);
        }
    }

    public function testStringIdsAreEchoed(): void
    {
        $response = $this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 'req-7', 'method' => 'ping']);

        self::assertSame('req-7', $response->body['id']);
    }

    // ---- 2026-07-28 (stateless) -----------------------------------------------------------

    public function testServerDiscoverListsSupportedVersions(): void
    {
        $request = $this->request('read_write', 'admin', $this->modernHeaders('server/discover'));
        $result = $this->ok($this->server()->handle($request, $this->modernMessage('server/discover')));

        self::assertSame(['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'], $result['supportedVersions']);
        self::assertSame('complete', $result['resultType']);
        self::assertSame('myinvoice', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        self::assertArrayHasKey('tools', $result['capabilities']);
    }

    public function testModernToolsListAndCallCarryResultType(): void
    {
        $this->api = new FakeApi(['GET /api/clients' => [200, ['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'per_page' => 25, 'pages' => 0]]]]);

        $list = $this->ok($this->server()->handle(
            $this->request('read_write', 'admin', $this->modernHeaders('tools/list')),
            $this->modernMessage('tools/list'),
        ));
        self::assertSame('complete', $list['resultType']);
        self::assertCount(12, $list['tools']);

        $call = $this->ok($this->server()->handle(
            $this->request('read_write', 'admin', $this->modernHeaders('tools/call', 'list_clients')),
            $this->modernMessage('tools/call', ['name' => 'list_clients', 'arguments' => []]),
        ));
        self::assertSame('complete', $call['resultType']);
        self::assertFalse($call['isError']);
    }

    public function testModernRequestWithoutProtocolVersionHeaderIsHeaderMismatch(): void
    {
        $request = $this->request('read_write', 'admin', ['Mcp-Method' => 'tools/list']);
        $response = $this->server()->handle($request, $this->modernMessage('tools/list'));

        self::assertSame(400, $response->status);
        self::assertSame(McpProtocol::HEADER_MISMATCH, $response->body['error']['code']);
    }

    public function testModernMethodHeaderMustMatchBody(): void
    {
        $request = $this->request('read_write', 'admin', $this->modernHeaders('tools/call'));
        $response = $this->server()->handle($request, $this->modernMessage('tools/list'));

        self::assertSame(400, $response->status);
        self::assertSame(McpProtocol::HEADER_MISMATCH, $response->body['error']['code']);
    }

    public function testModernNameHeaderMustMatchToolNameIncludingBase64Sentinel(): void
    {
        $mismatch = $this->server()->handle(
            $this->request('read_write', 'admin', $this->modernHeaders('tools/call', 'other_tool')),
            $this->modernMessage('tools/call', ['name' => 'list_clients', 'arguments' => []]),
        );
        self::assertSame(400, $mismatch->status);
        self::assertSame(McpProtocol::HEADER_MISMATCH, $mismatch->body['error']['code']);

        $this->api = new FakeApi(['GET /api/clients' => [200, ['data' => [], 'meta' => []]]]);
        $encoded = $this->server()->handle(
            $this->request('read_write', 'admin', $this->modernHeaders('tools/call', '=?base64?' . base64_encode('list_clients') . '?=')),
            $this->modernMessage('tools/call', ['name' => 'list_clients', 'arguments' => []]),
        );
        self::assertSame(200, $encoded->status);
    }

    public function testModernHeaderVersionMustMatchMetaVersion(): void
    {
        $request = $this->request('read_write', 'admin', ['MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/list']);
        $response = $this->server()->handle($request, $this->modernMessage('tools/list'));

        self::assertSame(400, $response->status);
        self::assertSame(McpProtocol::HEADER_MISMATCH, $response->body['error']['code']);
    }

    public function testModernRequestMissingClientCapabilitiesIsInvalidParams(): void
    {
        $meta = self::META;
        unset($meta['io.modelcontextprotocol/clientCapabilities']);
        $response = $this->server()->handle(
            $this->request('read_write', 'admin', $this->modernHeaders('tools/list')),
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => $meta]],
        );

        self::assertSame(400, $response->status);
        self::assertSame(McpProtocol::INVALID_PARAMS, $response->body['error']['code']);
    }

    public function testModernHeaderWithoutMetaVersionIsInvalidParams(): void
    {
        $response = $this->server()->handle(
            $this->request('read_write', 'admin', $this->modernHeaders('tools/list')),
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        );

        self::assertSame(400, $response->status);
        self::assertSame(McpProtocol::INVALID_PARAMS, $response->body['error']['code']);
    }

    public function testUnsupportedProtocolVersionListsSupportedOnes(): void
    {
        $response = $this->server()->handle(
            $this->request('read_write', 'admin', ['MCP-Protocol-Version' => '1900-01-01']),
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
        );

        self::assertSame(400, $response->status);
        self::assertSame(McpProtocol::UNSUPPORTED_VERSION, $response->body['error']['code']);
        self::assertSame('1900-01-01', $response->body['error']['data']['requested']);
        self::assertContains('2026-07-28', $response->body['error']['data']['supported']);
        self::assertContains('2025-06-18', $response->body['error']['data']['supported']);
    }

    public function testModernUnknownMethodIsHttp404(): void
    {
        $response = $this->server()->handle(
            $this->request('read_write', 'admin', $this->modernHeaders('resources/list')),
            $this->modernMessage('resources/list'),
        );

        self::assertSame(404, $response->status);
        self::assertSame(McpProtocol::METHOD_NOT_FOUND, $response->body['error']['code']);
    }

    public function testLegacyRequestWithoutHeaderIsServedAsDefaultRevision(): void
    {
        $result = $this->ok($this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        self::assertArrayNotHasKey('resultType', $result);
        self::assertCount(12, $result['tools']);
    }

    public function testLegacyRequestWithSupportedHeaderIsServed(): void
    {
        $request = $this->request('read_write', 'admin', ['MCP-Protocol-Version' => '2025-06-18']);
        $result = $this->ok($this->server()->handle($request, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));

        self::assertArrayNotHasKey('resultType', $result);
    }
}
