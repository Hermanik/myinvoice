<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * Login of one ISDS user (HTTP Basic). The password never leaves this object except into the transport. It is kept
 * in a closure, so var_export / print_r / var_dump / json_encode / serialize cannot reveal it, and the constructor
 * argument is redacted from stack traces (#[\SensitiveParameter]).
 */
final class IsdsCredentials
{
    private readonly \Closure $secret;

    public function __construct(
        public readonly string $username,
        #[\SensitiveParameter] string $password,
    ) {
        $this->secret = static fn (): string => $password;
    }

    public function password(): string
    {
        return ($this->secret)();
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => '***'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('IsdsCredentials must not be serialized.');
    }
}
