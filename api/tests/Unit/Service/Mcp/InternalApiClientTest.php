<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Service\Mcp\InternalApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class InternalApiClientTest extends TestCase
{
    private function outer(): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://faktury.example.test/api/mcp?x=1', ['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('Authorization', 'Bearer mi_pat_abc')
            ->withHeader('X-Supplier-Id', '3')
            ->withHeader('Accept-Language', 'cs')
            ->withHeader('User-Agent', 'claude-code/2.1')
            ->withHeader('Cookie', '__Host-myinvoice_session=SESSION-SECRET')
            ->withHeader('Origin', 'https://evil.example')
            ->withHeader('Content-Length', '9999')
            ->withHeader('X-Other', 'leak');
    }

    /** @return array{0: InternalApiClient, 1: \ArrayObject<int, ServerRequestInterface>} */
    private function client(int $status = 200, string $body = '{"ok":true}'): array
    {
        $seen = new \ArrayObject();
        $handler = new class ($seen, $status, $body) implements RequestHandlerInterface {
            public function __construct(private \ArrayObject $seen, private int $status, private string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen[] = $request;
                $response = (new ResponseFactory())->createResponse($this->status);
                $response->getBody()->write($this->body);
                return $response;
            }
        };
        return [new InternalApiClient($handler), $seen];
    }

    public function testForwardsOnlyTheAllowedHeadersAndNeverCookies(): void
    {
        // Slim's own factory would copy these process-wide values into the new request.
        $_SERVER['HTTP_COOKIE'] = '__Host-myinvoice_session=PROCESS-SECRET';
        try {
            [$client, $seen] = $this->client();
            $client->request($this->outer(), 'GET', '/api/invoices', ['filter' => ['status' => 'paid,sent'], 'page' => 2]);
        } finally {
            unset($_SERVER['HTTP_COOKIE']);
        }

        $request = $seen[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/api/invoices', $request->getUri()->getPath());
        self::assertSame(['filter' => ['status' => 'paid,sent'], 'page' => '2'], $request->getQueryParams());
        self::assertSame('Bearer mi_pat_abc', $request->getHeaderLine('Authorization'));
        self::assertSame('3', $request->getHeaderLine('X-Supplier-Id'));
        self::assertSame('cs', $request->getHeaderLine('Accept-Language'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('MyInvoice-MCP (claude-code/2.1)', $request->getHeaderLine('User-Agent'));
        self::assertSame([], $request->getCookieParams());
        foreach (['Cookie', 'Origin', 'Content-Length', 'X-Other'] as $header) {
            self::assertFalse($request->hasHeader($header), $header);
        }
        self::assertSame('203.0.113.9', $request->getServerParams()['REMOTE_ADDR']);
        self::assertSame('', (string) $request->getBody());
    }

    public function testPostSendsJsonBody(): void
    {
        [$client, $seen] = $this->client(201, '{"id":5}');

        $result = $client->request($this->outer(), 'POST', '/api/invoices', [], ['client_id' => 2, 'note' => 'Děkujeme / ok']);

        self::assertSame('application/json', $seen[0]->getHeaderLine('Content-Type'));
        self::assertSame('{"client_id":2,"note":"Děkujeme / ok"}', (string) $seen[0]->getBody());
        self::assertTrue($result->ok());
        self::assertSame(201, $result->status);
        self::assertSame(['id' => 5], $result->data);
    }

    public function testErrorEnvelopeIsExposed(): void
    {
        [$client] = $this->client(400, '{"error":{"code":"validation_failed","message":"Validace selhala","fields":{"a":["b"]}}}');

        $result = $client->request($this->outer(), 'POST', '/api/x', [], []);

        self::assertFalse($result->ok());
        self::assertSame('validation_failed', $result->errorCode());
        self::assertSame('Validace selhala', $result->errorMessage());
        self::assertSame(['a' => ['b']], $result->errorFields());
    }

    public function testServerErrorsHideDetails(): void
    {
        [$client] = $this->client(500, '{"message":"Slim Application Error","exception":[{"message":"SQLSTATE secret"}]}');

        $result = $client->request($this->outer(), 'GET', '/api/x');

        self::assertSame('Interní chyba serveru (HTTP 500).', $result->errorMessage());
    }

    public function testNonJsonBodyAndThrowingHandlerBecomeErrors(): void
    {
        [$client] = $this->client(502, '<html>bad gateway</html>');
        $html = $client->request($this->outer(), 'GET', '/api/x');
        self::assertSame([], $html->data);
        self::assertFalse($html->ok());

        $throwing = new InternalApiClient(new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        });
        self::assertSame(500, $throwing->request($this->outer(), 'GET', '/api/x')->status);
    }
}
