<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Mcp;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\ApiTokenService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Factory\UriFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request as SlimRequest;

/**
 * The MCP endpoint through the real middleware stack and database (in-process, no HTTP server):
 * bearer-only auth, token scope and supplier binding, and the confirm guards of the write tools.
 *
 * Creates its own tokens, clients and invoices (unique names) and removes them afterwards.
 * Skipped without cfg.php / a seeded database (php api/bin/ci-seed.php).
 */
#[Group('integration')]
final class McpEndpointTest extends TestCase
{
    private App $app;
    private PDO $pdo;
    private int $adminId = 0;
    /** @var list<int> */
    private array $supplierIds = [];
    private string $tag = '';

    /** @var list<int> */
    private array $clientIds = [];
    /** @var list<int> */
    private array $invoiceIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing — test needs a database.');
        }
        try {
            $this->app = Bootstrap::buildApp();
            $this->pdo = $this->app->getContainer()->get(Connection::class)->pdo();
            $this->adminId = (int) $this->pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
            $this->supplierIds = array_map('intval', $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: ' . $e->getMessage());
        }
        if ($this->adminId === 0 || $this->supplierIds === []) {
            $this->markTestSkipped('No admin user / supplier in the database (run api/bin/ci-seed.php).');
        }
        $this->tag = 'mcp-it-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM api_tokens WHERE name LIKE ?')->execute([$this->tag . '%']);
        foreach ($this->invoiceIds as $id) {
            $this->pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $this->pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
    }

    // ---- helpers --------------------------------------------------------------------------

    private function token(string $scope, ?int $supplierId = null, ?int $userId = null): string
    {
        $svc = $this->app->getContainer()->get(ApiTokenService::class);
        return $svc->generate($userId ?? $this->adminId, $supplierId, $this->tag . '-' . $scope, $scope)['plaintext'];
    }

    /** @param array<string,string> $headers */
    private function send(string $method, string $path, ?string $rawBody, ?string $token, array $headers = []): ResponseInterface
    {
        $request = new SlimRequest(
            $method,
            (new UriFactory())->createUri('http://localhost' . $path),
            new Headers(),
            [],
            ['REMOTE_ADDR' => '127.0.0.1'],
            (new StreamFactory())->createStream($rawBody ?? ''),
        );
        if ($rawBody !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
        }
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $this->app->handle($request);
    }

    /**
     * @param array<string,mixed>  $params
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    private function rpc(?string $token, string $method, array $params = [], array $headers = [], int $expectStatus = 200): array
    {
        $response = $this->send('POST', '/api/mcp', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]), $token, $headers);
        self::assertSame($expectStatus, $response->getStatusCode(), (string) $response->getBody());
        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * @param array<string,mixed>  $arguments
     * @param array<string,string> $headers
     * @return array<string,mixed> tools/call result
     */
    private function tool(string $token, string $name, array $arguments = [], array $headers = []): array
    {
        $body = $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $arguments], $headers);
        self::assertArrayHasKey('result', $body, json_encode($body));
        return $body['result'];
    }

    private function createClient(string $adminToken, int $supplierId, string $name): int
    {
        $response = $this->send('POST', '/api/clients', json_encode([
            'company_name' => $name, 'street' => 'Testovací 1', 'city' => 'Praha', 'zip' => '11000', 'country_id' => $this->czId(),
            'is_customer' => true,
        ]), $adminToken, ['X-Supplier-Id' => (string) $supplierId]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = (int) json_decode((string) $response->getBody(), true)['id'];
        $this->clientIds[] = $id;
        return $id;
    }

    private function czId(): int
    {
        return (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
    }

    /** @return int a valid VAT rate id of the supplier behind the token */
    private function vatRateId(string $token, array $headers = []): int
    {
        $settings = $this->tool($token, 'get_supplier_settings', [], $headers);
        $rates = $settings['structuredContent']['vat_rates'];
        self::assertNotEmpty($rates);
        return (int) $rates[0]['id'];
    }

    // ---- authentication -------------------------------------------------------------------

    public function testRejectsMissingInvalidAndNonBearerCredentials(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

        self::assertSame(401, $this->send('POST', '/api/mcp', $body, null)->getStatusCode());
        self::assertSame(401, $this->send('POST', '/api/mcp', $body, 'mi_pat_not_a_real_token_value_123456')->getStatusCode());
        self::assertSame(401, $this->send('POST', '/api/mcp', $body, null, ['Cookie' => '__Host-myinvoice_session=forged'])->getStatusCode());
    }

    public function testApiV1AliasReachesTheSameEndpoint(): void
    {
        $token = $this->token('read');

        $response = $this->send('POST', '/api/v1/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}', $token);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('X-API-Version'));
    }

    public function testGetAndDeleteAreNotAllowed(): void
    {
        $token = $this->token('read');

        foreach (['GET', 'DELETE'] as $method) {
            $response = $this->send($method, '/api/mcp', null, $token);

            self::assertSame(405, $response->getStatusCode(), $method);
            self::assertSame('POST', $response->getHeaderLine('Allow'), $method);
        }
        self::assertSame(401, $this->send('GET', '/api/mcp', null, null)->getStatusCode());
    }

    public function testHandshakeListAndReadToolWork(): void
    {
        $token = $this->token('read');

        $init = $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]);
        self::assertSame('2025-06-18', $init['result']['protocolVersion']);
        self::assertSame('myinvoice', $init['result']['serverInfo']['name']);

        $notification = $this->send('POST', '/api/mcp', '{"jsonrpc":"2.0","method":"notifications/initialized"}', $token);
        self::assertSame(202, $notification->getStatusCode());
        self::assertSame('', (string) $notification->getBody());

        $list = $this->rpc($token, 'tools/list');
        self::assertCount(9, $list['result']['tools'], 'read token sees only the read tools');

        $result = $this->tool($token, 'list_clients', ['per_page' => 5]);
        self::assertFalse($result['isError']);
        self::assertArrayHasKey('clients', $result['structuredContent']);
    }

    // ---- scope, role, supplier binding ----------------------------------------------------

    public function testReadOnlyTokenCannotWrite(): void
    {
        $admin = $this->token('read_write');
        $clientId = $this->createClient($admin, $this->supplierIds[0], $this->tag . ' ro');
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();

        $read = $this->token('read');
        $result = $this->tool($read, 'create_draft_invoice', [
            'client_id' => $clientId, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);

        self::assertTrue($result['isError']);
        self::assertSame('insufficient_scope', $result['structuredContent']['error']['code']);
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    }

    public function testReadonlyUserWithWriteTokenCannotWrite(): void
    {
        $this->pdo->prepare("INSERT INTO users (email, password_hash, name, role, locale) VALUES (?, 'x', 'MCP viewer', 'readonly', 'cs')")
            ->execute([$this->tag . '@example.invalid']);
        $viewerId = (int) $this->pdo->lastInsertId();
        try {
            $token = $this->token('read_write', null, $viewerId);

            $names = array_column($this->rpc($token, 'tools/list')['result']['tools'], 'name');
            $denied = $this->tool($token, 'issue_invoice', ['invoice_id' => 1, 'confirm' => true]);

            self::assertNotContains('issue_invoice', $names);
            self::assertTrue($denied['isError']);
            // Reads still work for this role.
            self::assertFalse($this->tool($token, 'list_clients')['isError']);
        } finally {
            $this->pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$viewerId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$viewerId]);
        }
    }

    public function testSupplierBoundTokenOnlySeesItsOwnCompany(): void
    {
        if (count($this->supplierIds) < 2) {
            $this->markTestSkipped('Needs two suppliers (php api/bin/ci-seed.php creates them).');
        }
        [$sA, $sB] = $this->supplierIds;
        $admin = $this->token('read_write');
        $clientA = $this->createClient($admin, $sA, $this->tag . ' alfa');
        $clientB = $this->createClient($admin, $sB, $this->tag . ' beta');

        $boundToB = $this->token('read_write', $sB);

        $list = $this->tool($boundToB, 'list_clients', ['query' => $this->tag]);
        self::assertSame([$clientB], array_column($list['structuredContent']['clients'], 'id'));

        // The header cannot hop to another company when the token is bound.
        $hop = $this->tool($boundToB, 'list_clients', ['query' => $this->tag], ['X-Supplier-Id' => (string) $sA]);
        self::assertSame([$clientB], array_column($hop['structuredContent']['clients'], 'id'));

        self::assertTrue($this->tool($boundToB, 'get_client', ['client_id' => $clientA])['isError']);

        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        $draft = $this->tool($boundToB, 'create_draft_invoice', [
            'client_id' => $clientA, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1]],
        ]);
        self::assertTrue($draft['isError'], 'a client of another company must not be invoiceable');
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn());

        // An unbound token selects the company with X-Supplier-Id, as for the REST API.
        $unbound = $this->token('read');
        $viaHeader = $this->tool($unbound, 'list_clients', ['query' => $this->tag], ['X-Supplier-Id' => (string) $sA]);
        self::assertSame([$clientA], array_column($viaHeader['structuredContent']['clients'], 'id'));
    }

    // ---- write flow -----------------------------------------------------------------------

    public function testDraftIssuePayFlowWithConfirmGuardsAndAuditTrail(): void
    {
        $token = $this->token('read_write');
        $clientId = $this->createClient($token, $this->supplierIds[0], $this->tag . ' flow');
        $rateId = $this->vatRateId($token);

        $created = $this->tool($token, 'create_draft_invoice', [
            'client_id' => $clientId,
            'items' => [['description' => 'Konzultace', 'quantity' => 2, 'unit' => 'h', 'unit_price_without_vat' => 1000, 'vat_rate_id' => $rateId]],
            'note_below_items' => $this->tag,
        ]);
        self::assertFalse($created['isError'], json_encode($created));
        $invoice = $created['structuredContent']['invoice'];
        $id = (int) $invoice['id'];
        $this->invoiceIds[] = $id;
        self::assertSame('draft', $invoice['status']);
        self::assertNull($invoice['number']);
        self::assertSame(2000.0, (float) $invoice['total_without_vat']);

        // Issuing is refused without confirm: true, and nothing changes.
        foreach ([[], ['confirm' => false]] as $extra) {
            $refused = $this->tool($token, 'issue_invoice', ['invoice_id' => $id] + $extra);
            self::assertTrue($refused['isError']);
            self::assertSame('confirmation_required', $refused['structuredContent']['error']['code']);
        }
        self::assertSame('draft', $this->tool($token, 'get_invoice', ['invoice_id' => $id])['structuredContent']['invoice']['status']);

        $issued = $this->tool($token, 'issue_invoice', ['invoice_id' => $id, 'confirm' => true]);
        self::assertFalse($issued['isError'], json_encode($issued));
        $number = $issued['structuredContent']['invoice']['number'];
        self::assertNotEmpty($number);
        self::assertSame('issued', $issued['structuredContent']['invoice']['status']);

        // Lookup by number resolves to the same invoice.
        $byNumber = $this->tool($token, 'get_invoice', ['number' => $number]);
        self::assertSame($id, $byNumber['structuredContent']['invoice']['id']);

        // Payment: refused without confirm, partial with amount, remainder without.
        self::assertTrue($this->tool($token, 'mark_invoice_paid', ['invoice_id' => $id, 'amount' => 100])['isError']);
        $partial = $this->tool($token, 'mark_invoice_paid', ['invoice_id' => $id, 'confirm' => true, 'amount' => 100, 'paid_at' => date('Y-m-d')]);
        self::assertFalse($partial['isError'], json_encode($partial));
        self::assertFalse($partial['structuredContent']['became_paid']);
        $full = $this->tool($token, 'mark_invoice_paid', ['invoice_id' => $id, 'confirm' => true]);
        self::assertFalse($full['isError'], json_encode($full));
        self::assertTrue($full['structuredContent']['became_paid']);

        $final = $this->tool($token, 'get_invoice', ['invoice_id' => $id])['structuredContent']['invoice'];
        self::assertSame('paid', $final['status']);
        self::assertSame(0.0, (float) $final['outstanding']);
        self::assertCount(2, $final['payments']);

        // Mutations are attributed to MCP in the audit log.
        $stmt = $this->pdo->prepare("SELECT user_agent FROM activity_log WHERE entity_type = 'invoice' AND entity_id = ? AND action IN ('invoice.created','invoice.issued','invoice.paid')");
        $stmt->execute([$id]);
        $agents = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(3, $agents);
        foreach ($agents as $agent) {
            self::assertStringStartsWith('MyInvoice-MCP', (string) $agent);
        }
    }

    // ---- secrets --------------------------------------------------------------------------

    public function testNoSecretsLeakThroughTools(): void
    {
        $token = $this->token('read_write');
        $supplierId = $this->supplierIds[0];
        $clientId = $this->createClient($token, $supplierId, $this->tag . ' secrets');
        $rateId = $this->vatRateId($token);
        $created = $this->tool($token, 'create_draft_invoice', [
            'client_id' => $clientId, 'items' => [['description' => 'x', 'quantity' => 1, 'unit_price_without_vat' => 10, 'vat_rate_id' => $rateId]],
        ]);
        $id = (int) $created['structuredContent']['invoice']['id'];
        $this->invoiceIds[] = $id;

        $marker = 'MCP-SECRET-' . $this->tag;
        $original = $this->pdo->query("SELECT anthropic_api_key_enc, idoklad_access_token, fakturoid_api_key_enc FROM supplier WHERE id = {$supplierId}")->fetch(PDO::FETCH_ASSOC);
        $this->pdo->prepare('UPDATE supplier SET anthropic_api_key_enc = ?, idoklad_access_token = ?, fakturoid_api_key_enc = ? WHERE id = ?')
            ->execute([$marker, $marker, $marker, $supplierId]);
        $this->pdo->prepare('UPDATE invoices SET public_token = ?, approval_token = ? WHERE id = ?')->execute([$marker, $marker, $id]);

        try {
            foreach ([
                ['get_supplier_settings', []],
                ['get_invoice', ['invoice_id' => $id]],
                ['list_invoices', ['query' => '']],
                ['get_client', ['client_id' => $clientId]],
                ['list_clients', []],
                ['list_recurring_templates', []],
                ['list_purchase_invoices', []],
                ['list_unpaid_invoices', []],
                ['revenue_summary', ['year' => (int) date('Y'), 'by_client' => true]],
            ] as [$name, $args]) {
                $result = $this->tool($token, $name, $args);
                self::assertFalse($result['isError'], $name . ': ' . json_encode($result));

                $text = json_encode($result);
                self::assertStringNotContainsString($marker, $text, $name);
                self::assertDoesNotMatchRegularExpression('/smtp|imap|password|passphrase|api_key|_enc"/i', $text, $name);
            }
        } finally {
            $this->pdo->prepare('UPDATE supplier SET anthropic_api_key_enc = ?, idoklad_access_token = ?, fakturoid_api_key_enc = ? WHERE id = ?')
                ->execute([$original['anthropic_api_key_enc'], $original['idoklad_access_token'], $original['fakturoid_api_key_enc'], $supplierId]);
        }
    }
}
