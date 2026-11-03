<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use MyInvoice\Service\Databox\DeliveryDates;
use PHPUnit\Framework\TestCase;

final class DeliveryDatesTest extends TestCase
{
    public function testFictionDateExampleFromTheSpecification(): void
    {
        // WS spec 1.8.3: put into the box 2.2.2023 -> 12.2.2023 is a Sunday -> delivered by fiction Monday 13.2.2023.
        self::assertSame('2023-02-13', DeliveryDates::fictionDate(new \DateTimeImmutable('2023-02-02 08:31:05'))->format('Y-m-d'));
    }

    public function testFictionOnAWorkingDayStaysOnIt(): void
    {
        self::assertSame('2026-11-06', DeliveryDates::fictionDate(new \DateTimeImmutable('2026-10-27 09:15:30'))->format('Y-m-d'));
    }

    public function testFictionOnAStateHolidayMovesToTheNextWorkingDay(): void
    {
        // 7.11.2026 + 10 days = Tue 17.11.2026 (state holiday).
        self::assertSame('2026-11-18', DeliveryDates::fictionDate(new \DateTimeImmutable('2026-11-07 10:00:00'))->format('Y-m-d'));
    }

    public function testStatesThatNeedALoginBeforeDownload(): void
    {
        self::assertTrue(DeliveryDates::needsLoginBeforeDownload(4));
        self::assertTrue(DeliveryDates::needsLoginBeforeDownload(5), 'ISDS refuses to download state 5 as well (error 1222)');
        self::assertFalse(DeliveryDates::needsLoginBeforeDownload(6));
        self::assertFalse(DeliveryDates::needsLoginBeforeDownload(7));
        self::assertFalse(DeliveryDates::needsLoginBeforeDownload(null));
    }

    public function testOnlyStateFourIsLegallyUndelivered(): void
    {
        self::assertTrue(DeliveryDates::isUndelivered(4));
        self::assertFalse(DeliveryDates::isUndelivered(5), 'by fiction it is already delivered; a login changes nothing legally');
        self::assertFalse(DeliveryDates::isUndelivered(6));
    }
}
