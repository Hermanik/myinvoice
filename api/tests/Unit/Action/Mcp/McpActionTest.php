<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Mcp;

use MyInvoice\Action\Mcp\McpAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Tests\Unit\Service\Mcp\McpTestHarness;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

/** HTTP-level behaviour of the MCP endpoint: bearer-only, Origin, parsing, 405. */
final class McpActionTest extends TestCase
{
    use McpTestHarness;

    /** @param array<string,mixed> $cfg */
    private function action(array $cfg = []): McpAction
    {
        return new McpAction($this->server(), new Config($cfg + ['app' => ['url' => 'https://faktury.example.test']]));
    }

    private function post(string $json, ?ServerRequestInterface $request = null): ServerRequestInterface
    {
        return ($request ?? $this->request())->withBody((new StreamFactory())->createStream($json));
    }

    private function invoke(McpAction $action, ServerRequestInterface $request): ResponseInterface
    {
        return $action($request, (new ResponseFactory())->createResponse());
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testPingRoundTripSetsJsonContentType(): void
    {
        $response = $this->invoke($this->action(), $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', (string) $response->getBody());
    }

    public function testNotificationYields202WithEmptyBody(): void
    {
        $response = $this->invoke($this->action(), $this->post('{"jsonrpc":"2.0","method":"notifications/initialized"}'));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testSessionAuthenticatedRequestIsRejected(): void
    {
        $session = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withoutAttribute(AuthMiddleware::ATTR_API_TOKEN);

        $response = $this->invoke($this->action(), $session);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('bearer_required', $this->json($response)['error']['code']);
        self::assertStringContainsString('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testRequestWithoutAnyAuthMethodIsRejected(): void
    {
        $anonymous = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}')->withoutAttribute(AuthMiddleware::ATTR_METHOD);

        self::assertSame(401, $this->invoke($this->action(), $anonymous)->getStatusCode());
    }

    public function testInvalidJsonIsParseError(): void
    {
        foreach (['', '{bad', '{"jsonrpc":"2.0",'] as $body) {
            $response = $this->invoke($this->action(), $this->post($body));

            self::assertSame(400, $response->getStatusCode(), $body);
            self::assertSame(-32700, $this->json($response)['error']['code'], $body);
        }
    }

    public function testBatchIsRejected(): void
    {
        $response = $this->invoke($this->action(), $this->post('[{"jsonrpc":"2.0","id":1,"method":"ping"}]'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(-32600, $this->json($response)['error']['code']);
    }

    public function testOversizedBodyIsRejected(): void
    {
        $response = $this->invoke($this->action(), $this->post(str_repeat('a', 1_048_577)));

        self::assertSame(413, $response->getStatusCode());
    }

    public function testForeignOriginIsForbidden(): void
    {
        $request = $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}')->withHeader('Origin', 'https://evil.example');

        $response = $this->invoke($this->action(), $request);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->json($response)['id']);
    }

    public function testAppOriginAndConfiguredOriginsAreAllowed(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

        $app = $this->invoke($this->action(), $this->post($body)->withHeader('Origin', 'https://faktury.example.test'));
        self::assertSame(200, $app->getStatusCode());

        $extra = $this->invoke(
            $this->action(['mcp' => ['allowed_origins' => ['https://inspector.example.test/']]]),
            $this->post($body)->withHeader('Origin', 'https://inspector.example.test'),
        );
        self::assertSame(200, $extra->getStatusCode());
    }

    public function testNoOriginHeaderIsFineForNativeClients(): void
    {
        $response = $this->invoke($this->action(), $this->post('{"jsonrpc":"2.0","id":1,"method":"ping"}'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testGetAndDeleteAnswer405WithAllowPost(): void
    {
        $response = $this->action()->notAllowed($this->request(), (new ResponseFactory())->createResponse());

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }
}
