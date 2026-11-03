<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Databox;

use MyInvoice\Service\Databox\IsdsCredentials;
use MyInvoice\Service\Databox\IsdsTransport;
use MyInvoice\Service\Databox\IsdsTransportResponse;

/**
 * Scripted ISDS: answers each SOAP operation with recorded / handwritten fixtures and remembers every request, so a
 * test can assert exactly which operations were (not) called. It never touches the network.
 */
final class FakeIsdsTransport implements IsdsTransport
{
    /** @var list<array{url:string, username:string, operation:string, body:string}> */
    public array $requests = [];
    /** @var array<string, list<IsdsTransportResponse>> */
    private array $queues = [];
    /** @var array<string, IsdsTransportResponse> */
    private array $defaults = [];
    /** @var array<string, array<string, IsdsTransportResponse>> operation => login name => answer */
    private array $defaultsByUser = [];

    /** One-shot answer for the next call of $operation. */
    public function queue(string $operation, IsdsTransportResponse|string $response, int $status = 200): self
    {
        $this->queues[$operation][] = $this->wrap($response, $status);
        return $this;
    }

    /** Answer for every call of $operation that has no queued one. */
    public function always(string $operation, IsdsTransportResponse|string $response, int $status = 200): self
    {
        $this->defaults[$operation] = $this->wrap($response, $status);
        return $this;
    }

    /** Answer for every call of $operation made with the login $username that has no queued answer (beats {@see always()}). */
    public function alwaysFor(string $operation, string $username, IsdsTransportResponse|string $response, int $status = 200): self
    {
        $this->defaultsByUser[$operation][$username] = $this->wrap($response, $status);
        return $this;
    }

    public function post(string $url, IsdsCredentials $credentials, string $soapEnvelope): IsdsTransportResponse
    {
        $operation = $this->operationOf($soapEnvelope);
        $this->requests[] = ['url' => $url, 'username' => $credentials->username, 'operation' => $operation, 'body' => $soapEnvelope];
        if (!empty($this->queues[$operation])) {
            return array_shift($this->queues[$operation]);
        }
        return $this->defaultsByUser[$operation][$credentials->username] ?? $this->defaults[$operation] ?? throw new \LogicException("The test scripted no answer for ISDS operation {$operation}.");
    }

    /** @return list<string> operations called so far, in order */
    public function operations(): array
    {
        return array_column($this->requests, 'operation');
    }

    public function count(string $operation): int
    {
        return count(array_filter($this->operations(), static fn (string $o): bool => $o === $operation));
    }

    /** The request body of the n-th call of $operation (0-based). */
    public function bodyOf(string $operation, int $n = 0): string
    {
        $found = array_values(array_filter($this->requests, static fn (array $r): bool => $r['operation'] === $operation));
        return $found[$n]['body'] ?? throw new \OutOfBoundsException("No call #{$n} of {$operation}.");
    }

    private function wrap(IsdsTransportResponse|string $response, int $status): IsdsTransportResponse
    {
        if ($response instanceof IsdsTransportResponse) {
            return $response;
        }
        return new IsdsTransportResponse($status, $response);
    }

    private function operationOf(string $envelope): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($envelope);
        $body = $doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0);
        foreach ($body->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                return $node->localName;
            }
        }
        throw new \LogicException('Request without an operation element.');
    }
}
