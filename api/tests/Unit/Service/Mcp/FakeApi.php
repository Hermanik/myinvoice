<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Stand-in for the Slim app: answers internal API dispatches from a route table
 * ("GET /api/clients" => [status, body]) and records every request it sees.
 */
final class FakeApi implements RequestHandlerInterface
{
    /** @var list<array{method: string, path: string, query: array<string,mixed>, body: mixed, headers: array<string,string>}> */
    public array $calls = [];

    /** @param array<string, array{0:int, 1:array<string,mixed>}|callable> $routes */
    public function __construct(private array $routes = []) {}

    /** @param array{0:int, 1:array<string,mixed>}|callable $response */
    public function on(string $route, array|callable $response): void
    {
        $this->routes[$route] = $response;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $request->getBody();
        $this->calls[] = [
            'method'  => $request->getMethod(),
            'path'    => $request->getUri()->getPath(),
            'query'   => $request->getQueryParams(),
            'body'    => $body === '' ? null : json_decode($body, true),
            'headers' => array_map(static fn (array $v): string => implode(',', $v), $request->getHeaders()),
        ];

        $route = $this->routes[$request->getMethod() . ' ' . $request->getUri()->getPath()] ?? null;
        $answer = is_callable($route) ? $route($request) : ($route ?? [404, ['error' => ['code' => 'not_found', 'message' => 'Nenalezeno.']]]);

        $response = (new ResponseFactory())->createResponse($answer[0]);
        $response->getBody()->write(json_encode($answer[1], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @return list<array<string,mixed>> */
    public function callsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $c): bool => $c['method'] === $method && $c['path'] === $path,
        ));
    }

    public function wroteAnything(): bool
    {
        foreach ($this->calls as $call) {
            if ($call['method'] !== 'GET') {
                return true;
            }
        }
        return false;
    }
}
