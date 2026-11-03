<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/** Which environment to talk to and as whom. */
final class IsdsAccess
{
    public function __construct(
        public readonly string $environment,
        public readonly IsdsCredentials $credentials,
    ) {}
}
