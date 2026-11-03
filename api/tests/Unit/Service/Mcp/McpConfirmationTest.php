<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Mcp\McpConfirmation;
use PHPUnit\Framework\TestCase;

final class McpConfirmationTest extends TestCase
{
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock();
    }

    /** @param array<string,mixed> $app */
    private function confirmation(array $app = ['pepper' => 'pepper-0123456789abcdef']): McpConfirmation
    {
        return new McpConfirmation(new Config(['app' => $app]), $this->clock);
    }

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $extra
     * @return array<string,mixed> the arguments as sent on the confirmed call
     */
    private static function confirmed(array $arguments, string $token, array $extra = []): array
    {
        return $extra + $arguments + ['confirm' => true, 'confirmation_token' => $token];
    }

    public function testIssuedTokenVerifiesForTheSameCall(): void
    {
        $c = $this->confirmation();
        $issued = $c->issue(5, 'issue_invoice', ['invoice_id' => 7], ['status' => 'draft']);

        self::assertTrue($c->verify(5, 'issue_invoice', self::confirmed(['invoice_id' => 7], $issued['token']), ['status' => 'draft']));
        self::assertSame('2027-01-15T08:10:00Z', $issued['expires_at']);
    }

    public function testBothConfirmationFieldsAreRequired(): void
    {
        $c = $this->confirmation();
        $token = $c->issue(5, 't', ['a' => 1])['token'];

        self::assertFalse($c->verify(5, 't', ['a' => 1, 'confirmation_token' => $token]), 'no confirm');
        self::assertFalse($c->verify(5, 't', ['a' => 1, 'confirm' => false, 'confirmation_token' => $token]));
        self::assertFalse($c->verify(5, 't', ['a' => 1, 'confirm' => 'true', 'confirmation_token' => $token]));
        self::assertFalse($c->verify(5, 't', ['a' => 1, 'confirm' => true]), 'no token');
        self::assertFalse($c->verify(5, 't', ['a' => 1, 'confirm' => true, 'confirmation_token' => 5]));
    }

    public function testTokenIsBoundToEveryComponent(): void
    {
        $c = $this->confirmation();
        $args = ['invoice_id' => 7, 'note' => 'a'];
        $token = $c->issue(5, 'issue_invoice', $args, ['total' => 100])['token'];
        $sent = self::confirmed($args, $token);

        self::assertTrue($c->verify(5, 'issue_invoice', $sent, ['total' => 100]));
        self::assertFalse($c->verify(6, 'issue_invoice', $sent, ['total' => 100]), 'other API token');
        self::assertFalse($c->verify(5, 'mark_invoice_paid', $sent, ['total' => 100]), 'other tool');
        self::assertFalse($c->verify(5, 'issue_invoice', self::confirmed(['invoice_id' => 8, 'note' => 'a'], $token), ['total' => 100]), 'other argument value');
        self::assertFalse($c->verify(5, 'issue_invoice', self::confirmed(['invoice_id' => 7], $token), ['total' => 100]), 'missing argument');
        self::assertFalse($c->verify(5, 'issue_invoice', $sent + ['extra' => 1], ['total' => 100]), 'extra argument');
        self::assertFalse($c->verify(5, 'issue_invoice', $sent, ['total' => 101]), 'changed state');
        self::assertFalse($c->verify(5, 'issue_invoice', $sent, []), 'missing state');
    }

    public function testRepresentationDoesNotChangeTheSignature(): void
    {
        $c = $this->confirmation();
        $token = $c->issue(5, 'm', ['invoice_id' => 7, 'amount' => 500, 'nested' => ['b' => 1, 'a' => [2, 3]]], ['paid' => 0])['token'];

        self::assertTrue($c->verify(5, 'm', [
            'confirm' => true, 'confirmation_token' => $token, 'nested' => ['a' => [2, 3], 'b' => 1.0], 'amount' => 500.0, 'invoice_id' => 7.0,
        ], ['paid' => 0.0]));
        self::assertFalse($c->verify(5, 'm', self::confirmed(['invoice_id' => 7, 'amount' => 500, 'nested' => ['b' => 1, 'a' => [3, 2]]], $token), ['paid' => 0]), 'list order matters');
    }

    public function testTokenExpiresAfterTenMinutes(): void
    {
        $c = $this->confirmation();
        $sent = self::confirmed([], $c->issue(5, 't', [])['token']);

        $this->clock->advance(600);
        self::assertTrue($c->verify(5, 't', $sent), 'still valid at the expiry second');
        $this->clock->advance(1);
        self::assertFalse($c->verify(5, 't', $sent));
    }

    public function testExpiryCannotBeExtendedAndMalformedTokensAreRejected(): void
    {
        $c = $this->confirmation();
        [$expires, $mac] = explode('.', $c->issue(5, 't', [])['token']);

        self::assertFalse($c->verify(5, 't', self::confirmed([], ($expires + 3600) . '.' . $mac)));
        foreach (['', 'x', $expires, $expires . '.', '.' . $mac, $expires . '.' . strtoupper($mac), $expires . '.' . substr($mac, 1), "$expires.$mac "] as $bad) {
            self::assertFalse($c->verify(5, 't', self::confirmed([], $bad)), $bad);
        }
    }

    public function testDifferentSecretsDoNotAcceptEachOthersTokens(): void
    {
        $sent = self::confirmed([], $this->confirmation(['pepper' => 'one-0123456789'])->issue(5, 't', [])['token']);

        self::assertFalse($this->confirmation(['pepper' => 'two-0123456789'])->verify(5, 't', $sent));
        self::assertTrue($this->confirmation(['pepper' => 'one-0123456789'])->verify(5, 't', $sent));
    }

    public function testNoSecretConfiguredIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->confirmation([])->issue(5, 't', []);
    }
}
