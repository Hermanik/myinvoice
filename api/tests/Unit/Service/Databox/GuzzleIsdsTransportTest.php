<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Service\Databox\GuzzleIsdsTransport;
use MyInvoice\Service\Databox\IsdsCredentials;
use MyInvoice\Service\Databox\IsdsException;
use PHPUnit\Framework\TestCase;

final class GuzzleIsdsTransportTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function transport(MockHandler $mock): GuzzleIsdsTransport
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        return new GuzzleIsdsTransport(new Client(['handler' => $stack, 'http_errors' => false]));
    }

    public function testSendsStatelessBasicAuthAndSoap11Headers(): void
    {
        $transport = $this->transport(new MockHandler([new Response(200, [], '<ok/>')]));
        $result = $transport->post('https://ws1.datovka.gov.cz/DS/dx', new IsdsCredentials('user@x', 'p:a:ss'), '<soap/>');

        self::assertSame(200, $result->status);
        self::assertSame('<ok/>', $result->body);
        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('Basic ' . base64_encode('user@x:p:a:ss'), $request->getHeaderLine('Authorization'));
        self::assertSame('text/xml; charset=UTF-8', $request->getHeaderLine('Content-Type'));
        self::assertSame('""', $request->getHeaderLine('SOAPAction'));
        self::assertSame('MyInvoice-Databox/1.0', $request->getHeaderLine('User-Agent'));
        self::assertSame('<soap/>', (string) $request->getBody());
        self::assertSame('', $request->getHeaderLine('Cookie'), 'the Basic channel is cookie-less');
    }

    public function testHttpErrorStatusesAreReturnedNotThrown(): void
    {
        $transport = $this->transport(new MockHandler([new Response(401, [], 'denied')]));
        $result = $transport->post('https://ws1.datovka.gov.cz/DS/dx', new IsdsCredentials('u', 'p'), '<soap/>');
        self::assertSame(401, $result->status);
        self::assertSame('denied', $result->body);
    }

    public function testNetworkFailureBecomesANetworkErrorWithoutLeakingTheSecret(): void
    {
        $transport = $this->transport(new MockHandler([new ConnectException('cURL error 7: https://u:S3cret-pass!@host unreachable', new Request('POST', 'https://x'))]));
        try {
            $transport->post('https://ws1.datovka.gov.cz/DS/dx', new IsdsCredentials('u', 'S3cret-pass!'), '<soap/>');
            self::fail('expected IsdsException');
        } catch (IsdsException $e) {
            self::assertSame('network', $e->reason);
            self::assertTrue($e->isTransient());
            self::assertStringNotContainsString('S3cret-pass!', $e->getMessage());
        }
    }
}
