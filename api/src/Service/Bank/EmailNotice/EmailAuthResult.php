<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

/**
 * Outcome of the authenticity check of one bank e-mail notice.
 */
final readonly class EmailAuthResult
{
    /**
     * @param string $detail Stable machine-readable code (`dkim_local_pass:airbank.cz`, `dkim_body_hash_mismatch`, `dmarc_pass`, …).
     */
    public function __construct(
        public AuthVerdict $verdict,
        public AuthMethod $method,
        public string $detail,
    ) {}

    public function passed(): bool
    {
        return $this->verdict === AuthVerdict::Pass;
    }

    public function isDeferred(): bool
    {
        return $this->verdict === AuthVerdict::Deferred;
    }

    /**
     * Columns of `bank_email_processed_messages` describing the check.
     *
     * @return array{auth_method:string,auth_detail:string}
     */
    public function toPayload(): array
    {
        return ['auth_method' => $this->method->value, 'auth_detail' => $this->detail];
    }
}
