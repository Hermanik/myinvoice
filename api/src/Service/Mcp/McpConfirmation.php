<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Infrastructure\Config\Config;
use Psr\Clock\ClockInterface;

/**
 * Short-lived confirmation tokens for the write tools that change accounting data.
 *
 * `confirm: true` alone is a single boolean a prompt-injected model can set by itself. The unconfirmed call
 * returns a preview plus one of these tokens; the confirmed call has to present it together with exactly the
 * same arguments. The token is an HMAC over the API token id, the tool name, the canonical arguments, a
 * fingerprint of the state the user was shown (e.g. what is already paid) and the expiry, keyed by an app
 * secret. It cannot be forged, reused for another tool, other arguments or another API token, and it stops
 * working as soon as the invoice changes, which also makes a confirmed call effectively single-use.
 */
final class McpConfirmation
{
    /** Seconds a confirmation stays valid. */
    private const TTL = 600;

    /** Argument names that carry the confirmation itself and are not part of what is signed. */
    private const CONFIRMATION_FIELDS = ['confirm' => 1, 'confirmation_token' => 1];

    public function __construct(
        private readonly Config $config,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $state     Fingerprint of the previewed state
     * @return array{token: string, expires_at: string}
     */
    public function issue(int $apiTokenId, string $tool, array $arguments, array $state = []): array
    {
        $expires = $this->clock->now()->getTimestamp() + self::TTL;

        return [
            'token'      => $expires . '.' . $this->sign($apiTokenId, $tool, $arguments, $state, $expires),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
        ];
    }

    /**
     * True when the call carries `confirm: true` and a token that was issued for exactly this API token, tool,
     * these arguments and this state, and has not expired.
     *
     * @param array<string,mixed> $arguments Raw tool arguments including `confirm` and `confirmation_token`
     * @param array<string,mixed> $state
     */
    public function verify(int $apiTokenId, string $tool, array $arguments, array $state = []): bool
    {
        $token = $arguments['confirmation_token'] ?? '';
        if (($arguments['confirm'] ?? false) !== true || !is_string($token)
            || preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $token, $m) !== 1) {
            return false;
        }
        $expires = (int) $m[1];

        return $expires >= $this->clock->now()->getTimestamp()
            && hash_equals($this->sign($apiTokenId, $tool, $arguments, $state, $expires), $m[2]);
    }

    /**
     * @param array<string,mixed> $arguments
     * @param array<string,mixed> $state
     */
    private function sign(int $apiTokenId, string $tool, array $arguments, array $state, int $expires): string
    {
        $payload = json_encode(
            [$apiTokenId, $tool, self::canonical(array_diff_key($arguments, self::CONFIRMATION_FIELDS)), self::canonical($state), $expires],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash_hmac('sha256', $payload, $this->key());
    }

    /** Sorts object keys and folds integral floats to ints, so `5` and `5.0` or the key order do not matter. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_float($value) && $value == floor($value) && abs($value) < 1e15 ? (int) $value : $value;
        }
        $value = array_map(self::canonical(...), $value);
        ksort($value);
        return $value;
    }

    /** Domain-separated from every other use of the pepper. */
    private function key(): string
    {
        $pepper = (string) $this->config->get('app.pepper', '');
        if ($pepper === '') {
            throw new \RuntimeException('Chybí app.pepper, nelze podepsat potvrzení.');
        }

        return hash_hkdf('sha256', $pepper, 32, 'mcp-confirmation');
    }
}
