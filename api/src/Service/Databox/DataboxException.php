<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * Application-level failure of a data box operation (validation, missing login right, confirmation missing ...).
 * Distinct from {@see IsdsException}, which is a failure of the ISDS call itself.
 */
final class DataboxException extends \RuntimeException
{
    /** @param array<string,mixed> $extra machine readable details for the API error body */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
