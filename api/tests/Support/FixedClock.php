<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use Psr\Clock\ClockInterface;

/** A PSR clock that says the moment it was set to, for the rules that depend on "today" (filing deadlines). */
final class FixedClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $moment)
    {
        $this->now = new \DateTimeImmutable($moment);
    }

    public function set(string $moment): void
    {
        $this->now = new \DateTimeImmutable($moment);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
