<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

/**
 * Internal control-flow exception of the DKIM verifier. It never leaves
 * {@see DkimVerifier::verify()}, which turns it into a {@see DkimResult}.
 */
final class DkimException extends \RuntimeException
{
    /**
     * @param string $detail Stable failure code (e.g. `dkim_body_hash_mismatch`).
     * @param bool $temporary True when a retry later may succeed (DNS outage).
     */
    public function __construct(public readonly string $detail, public readonly bool $temporary = false)
    {
        parent::__construct($detail);
    }
}
