<?php

declare(strict_types=1);

namespace MyInvoice\Service\LateInterest;

/** The interest cannot be computed reliably (unsupported period, missing rate). */
final class LateInterestException extends \DomainException
{
}
