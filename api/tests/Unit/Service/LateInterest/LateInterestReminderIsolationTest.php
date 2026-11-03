<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\LateInterest;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\RepoRateRepository;
use MyInvoice\Service\LateInterest\LateInterestCalculator;
use MyInvoice\Service\LateInterest\LateInterestService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** The interest block is an optional extra on a reminder: nothing that goes wrong in it may reach the reminder. */
final class LateInterestReminderIsolationTest extends TestCase
{
    private function service(Connection $db, LoggerInterface $logger): LateInterestService
    {
        return new LateInterestService($db, new RepoRateRepository($db), new LateInterestCalculator(), $logger);
    }

    public function testADatabaseFailureIsLoggedAndTheReminderBlockIsSimplyAbsent(): void
    {
        // E.g. migration 9005 not applied yet: the new columns do not exist.
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willThrowException(new \PDOException("Unknown column 's.late_interest_enabled'"));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('Late-interest block skipped'),
            self::callback(static fn (array $ctx): bool => $ctx['invoice_id'] === 42 && str_contains($ctx['error'], 'late_interest_enabled')),
        );

        $block = $this->service($db, $logger)->forReminder([
            'id' => 42, 'supplier_id' => 1, 'client_id' => 1, 'invoice_type' => 'invoice', 'status' => 'sent',
            'amount_to_pay' => 1000, 'due_date' => '2026-09-15',
        ], '2026-11-03');

        self::assertNull($block);
    }

    public function testCheapExitsTouchNeitherTheDatabaseNorTheLogger(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $service = $this->service($db, $logger);

        self::assertNull($service->forReminder(['id' => 1, 'invoice_type' => 'proforma'], '2026-11-03'), 'proforma');
        self::assertNull($service->forReminder(['id' => 1, 'invoice_type' => 'invoice', 'late_interest' => false], '2026-11-03'), 'invoice override off');
    }
}
