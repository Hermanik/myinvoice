<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use Psr\Clock\ClockInterface;

/** Controllable clock for expiry tests. */
final class FakeClock implements ClockInterface
{
    /** 2027-01-15T08:00:00Z */
    private int $timestamp = 1_800_000_000;

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@' . $this->timestamp);
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
