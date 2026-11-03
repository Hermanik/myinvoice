<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

/**
 * A VAT status history rule was violated. Carries the API error code and HTTP status
 * so the settings endpoints can map it straight onto Json::error().
 */
final class VatStatusException extends \DomainException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'validation_failed',
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
