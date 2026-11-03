<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

/**
 * A filing-lifecycle or VAT-payment rule was violated. Carries the API error code and HTTP status
 * so the actions can map it straight onto Json::error().
 */
final class TaxFilingException extends \DomainException
{
    /** @param array<string,mixed> $extra additional error payload (e.g. the conflicting submission) */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'validation_failed',
        public readonly int $httpStatus = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Záznam nenalezen.', 'not_found', 404);
    }
}
