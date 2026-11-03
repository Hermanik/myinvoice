<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

/** Decoded result of an internal API dispatch. */
final class InternalApiResponse
{
    /** @param array<string,mixed> $data Decoded JSON body ([] when empty or not JSON) */
    public function __construct(
        public readonly int $status,
        public readonly array $data,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** App error code from the `{error: {code, message}}` envelope. */
    public function errorCode(): string
    {
        $code = $this->data['error']['code'] ?? null;
        return is_string($code) && $code !== '' ? $code : 'http_' . $this->status;
    }

    /**
     * Message shown to the model. 5xx details are never forwarded: with
     * `app.debug` the error middleware renders stack traces.
     */
    public function errorMessage(): string
    {
        if ($this->status >= 500) {
            return 'Interní chyba serveru (HTTP ' . $this->status . ').';
        }
        $message = $this->data['error']['message'] ?? $this->data['message'] ?? null;
        return is_string($message) && $message !== ''
            ? $message
            : 'Požadavek selhal (HTTP ' . $this->status . ').';
    }

    /** @return array<string,mixed> */
    public function errorFields(): array
    {
        $fields = $this->data['error']['fields'] ?? [];
        return is_array($fields) ? $fields : [];
    }
}
