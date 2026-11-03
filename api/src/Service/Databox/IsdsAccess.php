<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * Which environment to talk to and as whom.
 *
 * `$onAuthFailure` is how lockout protection is centralised: {@see IsdsClient} calls it whenever ISDS refuses the login
 * of this access (any operation), so the failure is recorded against the stored login no matter which feature made the call.
 */
final class IsdsAccess
{
    /** @param (\Closure(IsdsException):void)|null $onAuthFailure */
    public function __construct(
        public readonly string $environment,
        public readonly IsdsCredentials $credentials,
        public readonly ?\Closure $onAuthFailure = null,
    ) {}
}
