<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Invoice\OverduePolicy;
use MyInvoice\Service\Mcp\InternalApiClient;
use MyInvoice\Service\Mcp\McpConfirmation;
use MyInvoice\Service\Mcp\McpResponse;
use MyInvoice\Service\Mcp\McpServer;
use MyInvoice\Service\Mcp\McpToolRegistry;
use MyInvoice\Service\Mcp\Tool\ClientTools;
use MyInvoice\Service\Mcp\Tool\CompanyTools;
use MyInvoice\Service\Mcp\Tool\InvoiceTools;
use MyInvoice\Service\Mcp\Tool\PurchaseInvoiceTools;
use MyInvoice\Service\Mcp\Tool\RevenueTools;
use MyInvoice\Service\Update\VersionService;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

/** Builds an McpServer on top of a FakeApi plus authenticated PSR-7 requests. */
trait McpTestHarness
{
    protected FakeApi $api;

    protected FakeClock $clock;

    /** @var array<string,mixed> Extra config merged over the test defaults */
    protected array $config = [];

    protected function server(): McpServer
    {
        $this->api ??= new FakeApi();
        $this->clock ??= new FakeClock();

        $version = $this->createStub(VersionService::class);
        $version->method('getCurrentVersion')->willReturn('9.9.9');
        $config = new Config($this->config + ['app' => ['url' => 'https://faktury.example.test', 'pepper' => 'test-pepper-0123456789abcdef']]);

        return new McpServer(
            new McpToolRegistry(new InvoiceTools(new OverduePolicy($config)), new ClientTools(), new PurchaseInvoiceTools(), new RevenueTools(), new CompanyTools()),
            new InternalApiClient($this->api),
            new McpConfirmation($config, $this->clock),
            $version,
            $config,
            new NullLogger(),
        );
    }

    /**
     * @param array<string,string> $headers
     */
    protected function request(string $scope = 'read_write', string $role = 'admin', array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://faktury.example.test/api/mcp', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('Authorization', 'Bearer mi_pat_test')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN, ['id' => 5, 'scope' => $scope, 'supplier_id' => null])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 3, 'role' => $role]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    protected function call(string $tool, array $arguments = [], ?ServerRequestInterface $request = null): array
    {
        return $this->ok($this->server()->handle($request ?? $this->request(), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]));
    }

    /**
     * Runs the two-step flow of a guarded write tool: an unconfirmed call to obtain the confirmation token,
     * then the confirmed call with the same arguments. Returns the result of the confirmed call.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    protected function confirmedCall(string $tool, array $arguments, ?ServerRequestInterface $request = null): array
    {
        $preview = $this->call($tool, $arguments, $request);
        self::assertTrue($preview['structuredContent']['requires_confirmation'] ?? false, json_encode($preview));

        return $this->call($tool, $arguments + ['confirm' => true, 'confirmation_token' => $preview['structuredContent']['confirmation_token']], $request);
    }

    /** @return array<string,array<string,mixed>> The advertised tools by name, as the default caller sees them */
    protected function toolsByName(): array
    {
        return array_column($this->ok($this->server()->handle($this->request(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']))['tools'], null, 'name');
    }

    /**
     * @param list<array<string,mixed>> $payments
     */
    protected function stubPayments(int $invoiceId = 7, array $payments = [], float $remaining = 1210.0): void
    {
        $this->api->on('GET /api/invoices/' . $invoiceId . '/payments', [200, ['payments' => $payments, 'remaining' => $remaining]]);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    protected function draftLine(array $overrides = []): array
    {
        return $overrides + ['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 1, 'vat_rate_id' => 1];
    }

    /** @return array<string,mixed> The JSON-RPC `result` of a successful response */
    protected function ok(McpResponse $response): array
    {
        self::assertSame(200, $response->status);
        self::assertArrayHasKey('result', $response->body, json_encode($response->body));
        return $response->body['result'];
    }

    /** @return array<string,mixed> */
    protected function invoiceFixture(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7, 'supplier_id' => 1, 'varsymbol' => '2611001', 'invoice_type' => 'invoice', 'status' => 'issued',
            'payment_status' => 'unpaid', 'client_id' => 2, 'client_company_name' => 'Test Klient s.r.o.',
            'project_name' => null, 'issue_date' => '2026-11-01', 'tax_date' => '2026-11-01', 'due_date' => '2026-11-15',
            'paid_at' => null, 'currency' => 'CZK', 'total_without_vat' => 1000.0, 'total_vat' => 210.0,
            'total_with_vat' => 1210.0, 'amount_to_pay' => 1210.0, 'paid_total' => 0.0, 'month_bucket' => '2026-11',
            'items' => [[
                'description' => 'Konzultace', 'quantity' => 1, 'unit' => 'h', 'unit_price_without_vat' => 1000,
                'vat_rate_snapshot' => 21, 'total_without_vat' => 1000, 'total_vat' => 210, 'total_with_vat' => 1210,
            ]],
            'vat_breakdown' => [['rate' => 21, 'base' => 1000, 'vat' => 210]],
            // Secret-bearing columns the real endpoint returns; no tool may expose them.
            'public_token' => 'PUBLIC-TOKEN-SECRET', 'approval_token' => 'APPROVAL-TOKEN-SECRET',
        ];
    }

    /** @param list<array<string,mixed>> $invoices */
    protected function listing(array $invoices, ?int $total = null): array
    {
        return [
            'data' => [['month' => '2026-11', 'count' => count($invoices), 'totals_per_currency' => [], 'invoices' => $invoices]],
            'meta' => ['total' => $total ?? count($invoices), 'page' => 1, 'per_page' => 200, 'pages' => 1],
        ];
    }
}
