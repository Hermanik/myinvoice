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

    /** @return array<string,mixed> */
    private static function notice(array $override = []): array
    {
        return $override + ['status' => 4, 'source' => 'email', 'delivery_time' => '2026-10-27 09:15:30', 'allow_subst_delivery' => null];
    }

    public function testANoticeOnlyMessageIsPresumedDeliveredFromTheDayAfterTheFictionDay(): void
    {
        // Put into the box Tue 27.10.2026 -> fiction Fri 6.11.2026.
        $presumed = static fn (string $now): ?string => DeliveryDates::presumedDeliveryDate(self::notice(), new \DateTimeImmutable($now))?->format('Y-m-d');

        self::assertNull($presumed('2026-11-05 23:59:59'), 'before the fiction day');
        self::assertNull($presumed('2026-11-06 12:00:00'), 'the fiction day itself is not over until midnight');
        self::assertSame('2026-11-06', $presumed('2026-11-07 00:00:00'));
        self::assertSame('2026-11-06', $presumed('2027-03-01 08:00:00'));
    }

    public function testPresumedDeliveryOnlyConcernsNoticeOnlyMessagesInStateFour(): void
    {
        $later = new \DateTimeImmutable('2027-01-01');

        self::assertNull(DeliveryDates::presumedDeliveryDate(self::notice(['source' => 'isds']), $later), 'ISDS reports the real state itself');
        self::assertNull(DeliveryDates::presumedDeliveryDate(self::notice(['source' => 'zfo']), $later));
        self::assertNull(DeliveryDates::presumedDeliveryDate(self::notice(['status' => 6]), $later));
        self::assertNull(DeliveryDates::presumedDeliveryDate(self::notice(['status' => 5]), $later));
        self::assertNull(DeliveryDates::presumedDeliveryDate(self::notice(['status' => null]), $later));
        self::assertNull(DeliveryDates::presumedDeliveryDate(self::notice(['delivery_time' => null]), $later));
        self::assertNull(DeliveryDates::presumedDeliveryDate(self::notice(['allow_subst_delivery' => false]), $later), 'the sender excluded the fiction');
        self::assertNotNull(DeliveryDates::presumedDeliveryDate(self::notice(['allow_subst_delivery' => true]), $later));
    }

    public function testIsFictionOverUsesTheWorkingDayRule(): void
    {
        // Sat 7.11.2026 + 10 days = Tue 17.11.2026 (holiday) -> fiction Wed 18.11.2026.
        $box = new \DateTimeImmutable('2026-11-07 10:00:00');
        self::assertFalse(DeliveryDates::isFictionOver($box, new \DateTimeImmutable('2026-11-18 23:00:00')));
        self::assertTrue(DeliveryDates::isFictionOver($box, new \DateTimeImmutable('2026-11-19 00:00:00')));
    }
}
