<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

final class IsdsTransportResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {}
}
