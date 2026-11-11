<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Onboarding;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Onboarding\UserOnboardingService;
use MyInvoice\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class UserOnboardingServiceTest extends TestCase
{
    public function testTourIsPendingUntilTheUserFinishesIt(): void
    {
        $service = $this->service($this->database(['tour_status' => null, 'tour_finished_at' => null]));

        self::assertSame(['status' => 'pending', 'finished_at' => null], $service->tourState(17));
    }

    public function testStoredOutcomeIsReportedWithAnIsoUtcTime(): void
    {
        $service = $this->service($this->database(['tour_status' => 'skipped', 'tour_finished_at' => '2026-11-11 09:30:00']));

        self::assertSame(['status' => 'skipped', 'finished_at' => '2026-11-11T09:30:00Z'], $service->tourState(17));
    }

    public function testUnknownUserCountsAsPending(): void
    {
        self::assertSame(['status' => 'pending', 'finished_at' => null], $this->service($this->database(false))->tourState(404));
    }

    public function testCorruptedStoredStatusFallsBackToPending(): void
    {
        $service = $this->service($this->database(['tour_status' => 'maybe', 'tour_finished_at' => '2026-11-11 09:30:00']));

        self::assertSame(['status' => 'pending', 'finished_at' => null], $service->tourState(17));
    }

    public function testTourDoesNotNagBeforeTheMigrationRuns(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('hasColumn')->with('users', 'tour_status')->willReturn(false);
        $db->expects(self::never())->method('pdo');

        self::assertSame(['status' => 'completed', 'finished_at' => null], $this->service($db)->tourState(17));
    }

    public function testFinishingStoresTheOutcomeWithTheCurrentUtcTime(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['completed', '2026-11-11 08:00:00', 17]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects(self::once())->method('prepare')
            ->with(self::stringContains('UPDATE users SET tour_status'))
            ->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        // 09:00 in Prague is 08:00 UTC.
        $state = (new UserOnboardingService($db, new FixedClock('2026-11-11T09:00:00+01:00')))->finishTour(17, 'completed');

        self::assertSame(['status' => 'completed', 'finished_at' => '2026-11-11T08:00:00Z'], $state);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidOutcomes(): iterable
    {
        yield 'pending is not an outcome' => ['pending'];
        yield 'unknown word' => ['done'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['completed']];
        yield 'number' => [1];
    }

    #[DataProvider('invalidOutcomes')]
    public function testFinishRejectsAnythingButCompletedOrSkippedBeforeWriting(mixed $outcome): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $this->expectException(\InvalidArgumentException::class);
        $this->service($db)->finishTour(17, $outcome);
    }

    private function service(Connection $db): UserOnboardingService
    {
        return new UserOnboardingService($db, new FixedClock('2026-11-11T08:00:00+00:00'));
    }

    /** @param array<string,mixed>|false $row the fetched users row, false = no such user */
    private function database(array|false $row): Connection
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetch')->willReturn($row);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('hasColumn')->willReturn(true);
        $db->method('pdo')->willReturn($pdo);

        return $db;
    }
}
