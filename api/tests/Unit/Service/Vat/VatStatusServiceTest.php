<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Vat;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SupplierVatStatusRepository;
use MyInvoice\Service\Vat\VatStatus;
use MyInvoice\Service\Vat\VatStatusException;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Rules of the dated VAT status history on an in-memory SQLite database
 * (synthetic data only): rows, deletion, the cache, legacy flag translation and the
 * year-aware "payer vs flat tax" rule.
 */
final class VatStatusServiceTest extends TestCase
{
    private PDO $pdo;
    private VatStatusService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, is_vat_payer INTEGER NOT NULL DEFAULT 0, is_identified INTEGER NOT NULL DEFAULT 0, flat_tax_band TEXT NOT NULL DEFAULT \'none\')');
        $this->pdo->exec('CREATE TABLE supplier_vat_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, valid_from TEXT NOT NULL, status TEXT NOT NULL, note TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INTEGER NULL, UNIQUE (supplier_id, valid_from))');
        $this->pdo->exec('CREATE TABLE tax_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, year INTEGER NOT NULL, flat_tax_band TEXT NOT NULL DEFAULT \'none\')');

        $conn = new Connection($this->createStub(\MyInvoice\Infrastructure\Config\Config::class));
        (new \ReflectionClass($conn))->getProperty('pdo')->setValue($conn, $this->pdo);
        $this->service = new VatStatusService(new SupplierVatStatusRepository($conn));
    }

    /** Supplier 1: non-payer since the seeded floor row. */
    private function nonPayerSupplier(string $band = 'none'): void
    {
        $this->pdo->exec("INSERT INTO supplier (id, is_vat_payer, is_identified, flat_tax_band) VALUES (1, 0, 0, '$band')");
        $this->service->seedInitialRow(1, false, false);
    }

    /** @return list<array{string, string}> [valid_from, status] */
    private function rows(): array
    {
        return array_map(
            static fn (array $r): array => [$r['valid_from'], $r['status']],
            $this->pdo->query('SELECT valid_from, status FROM supplier_vat_status_history WHERE supplier_id = 1 ORDER BY valid_from')->fetchAll(),
        );
    }

    private function cache(): array
    {
        return $this->pdo->query('SELECT is_vat_payer, is_identified FROM supplier WHERE id = 1')->fetch();
    }

    // ── seeding / reads ──────────────────────────────────────────────

    public function testSeedCreatesExactlyOneFloorRowAndIsIdempotent(): void
    {
        $this->pdo->exec('INSERT INTO supplier (id, is_vat_payer, is_identified) VALUES (1, 0, 1)');

        $this->service->seedInitialRow(1, false, true);
        $this->service->seedInitialRow(1, true, false); // a second call must not add or override anything

        self::assertSame([['2000-01-01', VatStatus::IDENTIFIED]], $this->rows());
    }

    public function testSupplierWithoutRowsFallsBackToCachedFlags(): void
    {
        $this->pdo->exec('INSERT INTO supplier (id, is_vat_payer, is_identified) VALUES (1, 1, 0)');

        self::assertSame(VatStatus::PAYER, $this->service->statusAt(1, '2026-01-01'));
    }

    public function testDocumentDateUsesDuzpThenIssueDateThenToday(): void
    {
        self::assertSame('2026-12-31', VatStatusService::documentDate('2026-12-31', '2027-01-05'));
        self::assertSame('2027-01-05', VatStatusService::documentDate(null, '2027-01-05'));
        self::assertSame('2027-01-05', VatStatusService::documentDate('', '2027-01-05'));
        self::assertSame(date('Y-m-d'), VatStatusService::documentDate(null, null));
    }

    // ── add / delete ──────────────────────────────────────────────────

    public function testAddingAFutureRowDoesNotTouchTodaysCache(): void
    {
        $this->nonPayerSupplier();

        $row = $this->service->add(1, '2099-01-01', VatStatus::PAYER, ' registrace ', 7);

        self::assertSame('2099-01-01', $row['valid_from']);
        self::assertSame('registrace', $row['note']);
        self::assertSame(7, $row['created_by']);
        self::assertSame(0, (int) $this->cache()['is_vat_payer'], 'a future row must not flip the cache');
        $overview = $this->service->overview(1);
        self::assertSame(VatStatus::NON_PAYER, $overview['status']);
        self::assertSame(['valid_from' => '2099-01-01', 'status' => VatStatus::PAYER], $overview['next_change']);
        self::assertTrue($overview['history'][1]['is_future']);
    }

    public function testAddingARowInForceTodayRecomputesTheCache(): void
    {
        $this->nonPayerSupplier();

        $this->service->add(1, '2020-03-01', VatStatus::PAYER, null, null);

        self::assertSame(['is_vat_payer' => 1, 'is_identified' => 0], array_map('intval', $this->cache()));
    }

    public function testDuplicateDateIsRefused(): void
    {
        $this->nonPayerSupplier();
        $this->service->add(1, '2099-01-01', VatStatus::PAYER, null, null);

        try {
            $this->service->add(1, '2099-01-01', VatStatus::NON_PAYER, null, null);
            self::fail('Expected a duplicate-date refusal');
        } catch (VatStatusException $e) {
            self::assertSame(409, $e->httpStatus);
            self::assertSame('vat_status_duplicate', $e->errorCode);
        }
    }

    public function testInvalidInputIsRefused(): void
    {
        $this->nonPayerSupplier();
        foreach ([
            ['2027-13-01', VatStatus::PAYER],
            ['01.01.2027', VatStatus::PAYER],
            ['1999-12-31', VatStatus::PAYER],
            ['2027-01-01', 'bogus'],
        ] as [$date, $status]) {
            try {
                $this->service->add(1, $date, $status, null, null);
                self::fail("Expected a refusal for $date / $status");
            } catch (VatStatusException) {
                self::assertCount(1, $this->rows(), 'nothing may be stored');
            }
        }
    }

    public function testEarliestRowCannotBeDeleted(): void
    {
        $this->nonPayerSupplier();
        $earliestId = (int) $this->pdo->query('SELECT id FROM supplier_vat_status_history')->fetchColumn();

        try {
            $this->service->delete(1, $earliestId);
            self::fail('Expected the earliest row to be protected');
        } catch (VatStatusException $e) {
            self::assertSame('vat_status_earliest', $e->errorCode);
        }
        self::assertCount(1, $this->rows());
    }

    public function testDeletingARowRecomputesTheCache(): void
    {
        $this->nonPayerSupplier();
        $row = $this->service->add(1, '2020-03-01', VatStatus::PAYER, null, null);
        self::assertSame(1, (int) $this->cache()['is_vat_payer']);

        $this->service->delete(1, $row['id']);

        self::assertSame(0, (int) $this->cache()['is_vat_payer']);
        self::assertSame([['2000-01-01', VatStatus::NON_PAYER]], $this->rows());
    }

    public function testRowOfAnotherSupplierIsNotFound(): void
    {
        $this->nonPayerSupplier();
        $this->service->add(1, '2099-01-01', VatStatus::PAYER, null, null);
        $id = (int) $this->pdo->query("SELECT id FROM supplier_vat_status_history WHERE valid_from = '2099-01-01'")->fetchColumn();

        try {
            $this->service->delete(2, $id);
            self::fail('Expected 404');
        } catch (VatStatusException $e) {
            self::assertSame(404, $e->httpStatus);
        }
    }

    // ── cache refresh (the daily cron) ───────────────────────────────

    public function testCacheFlipsOnTheChangeDate(): void
    {
        $this->nonPayerSupplier();
        $this->service->add(1, '2027-01-01', VatStatus::PAYER, null, null);

        self::assertFalse($this->service->refreshCache(1, '2026-12-31'), 'day before: nothing changes');
        self::assertSame(0, (int) $this->cache()['is_vat_payer']);

        $report = $this->service->refreshAllCaches('2027-01-01');

        self::assertSame(['checked' => 1, 'changed' => 1, 'changed_supplier_ids' => [1]], $report);
        self::assertSame(1, (int) $this->cache()['is_vat_payer'], 'cron run on the change date flips the cache');
        self::assertSame(0, $this->service->refreshAllCaches('2027-01-02')['changed'], 'idempotent');
    }

    public function testCacheFollowsAnIdentifiedPeriod(): void
    {
        $this->pdo->exec('INSERT INTO supplier (id, is_vat_payer, is_identified) VALUES (1, 0, 0)');
        $this->service->seedInitialRow(1, false, false);
        $this->service->add(1, '2030-01-01', VatStatus::IDENTIFIED, null, null);

        $this->service->refreshCache(1, '2030-06-01');

        self::assertSame(['is_vat_payer' => 0, 'is_identified' => 1], array_map('intval', $this->cache()));
    }

    // ── legacy is_vat_payer / is_identified in the settings PUT ───────

    public function testLegacyFlagChangeBecomesARowValidFromToday(): void
    {
        $this->nonPayerSupplier();

        $status = $this->service->applyFlagChange(1, true, null, 5, null, '2026-11-03');

        self::assertSame(VatStatus::PAYER, $status);
        self::assertSame([['2000-01-01', VatStatus::NON_PAYER], ['2026-11-03', VatStatus::PAYER]], $this->rows());
        self::assertSame(VatStatus::NON_PAYER, $this->service->statusAt(1, '2026-11-02'), 'the past is not rewritten');
    }

    public function testUnchangedLegacyFlagsAddNothing(): void
    {
        $this->nonPayerSupplier();

        self::assertNull($this->service->applyFlagChange(1, false, false, null, null, '2026-11-03'));
        self::assertNull($this->service->applyFlagChange(1, null, null, null, null, '2026-11-03'));
        self::assertCount(1, $this->rows());
    }

    public function testSecondLegacyChangeOnTheSameDayFlipsOrDropsTodaysRow(): void
    {
        $this->nonPayerSupplier();
        $this->service->applyFlagChange(1, true, null, null, null, '2026-11-03');

        // Switch to an identified person the same day: today's row is flipped, not duplicated.
        $this->service->applyFlagChange(1, false, true, null, null, '2026-11-03');
        self::assertSame([['2000-01-01', VatStatus::NON_PAYER], ['2026-11-03', VatStatus::IDENTIFIED]], $this->rows());

        // Undoing it (back to the status before) leaves no redundant row behind.
        $this->service->applyFlagChange(1, false, false, null, null, '2026-11-03');
        self::assertSame([['2000-01-01', VatStatus::NON_PAYER]], $this->rows());
    }

    public function testPayerAndIdentifiedTogetherIsRefused(): void
    {
        $this->nonPayerSupplier();

        $this->expectException(VatStatusException::class);
        $this->service->planFlagChange(1, true, true);
    }

    // ── payer vs flat tax, year-aware ─────────────────────────────────

    public function testFuturePayerRowIsAllowedWhileTheCurrentYearHasFlatTax(): void
    {
        $this->nonPayerSupplier('band1');

        $this->service->add(1, '2027-01-01', VatStatus::PAYER, null, null);

        self::assertCount(2, $this->rows());
        self::assertSame([], $this->service->flatTaxConflictYears(1, $this->service->history(1), null, 2026));
    }

    public function testPayerRowInAFlatTaxYearIsRefused(): void
    {
        $this->nonPayerSupplier('band1');

        try {
            // Mid-year registration: payer for part of 2026, the year that still has the band.
            $this->service->add(1, '2026-12-01', VatStatus::PAYER, null, null);
            self::fail('Expected the flat tax rule to refuse the row');
        } catch (VatStatusException $e) {
            self::assertSame('vat_flat_tax_conflict', $e->errorCode);
            self::assertStringContainsString('2026', $e->getMessage());
        }
        self::assertCount(1, $this->rows());
    }

    public function testSavedTaxProfileOfAFutureYearBlocksAPayerRowThatYear(): void
    {
        $this->nonPayerSupplier('none');
        $this->pdo->exec("INSERT INTO tax_profiles (supplier_id, year, flat_tax_band) VALUES (1, 2027, 'band2')");

        $this->expectException(VatStatusException::class);
        $this->service->add(1, '2027-07-01', VatStatus::PAYER, null, null);
    }

    public function testFlatTaxBandIsRefusedOnlyForAYearWithPayerTime(): void
    {
        $this->nonPayerSupplier();
        $this->service->add(1, '2027-01-01', VatStatus::PAYER, null, null);

        // 2026 (today's year in this call) has no payer day → the band may be set.
        $this->service->assertFlatTaxAllowed(1, 'band1', '2026-11-03');

        // Once 2027 is the current year it is a payer year → refused.
        $this->expectException(VatStatusException::class);
        $this->service->assertFlatTaxAllowed(1, 'band1', '2027-02-01');
    }

    public function testPreExistingConflictDoesNotBlockUnrelatedChanges(): void
    {
        // State after the registration date passed with the band left on: a payer in a band year.
        $this->nonPayerSupplier('band1');
        $this->pdo->exec("INSERT INTO supplier_vat_status_history (supplier_id, valid_from, status) VALUES (1, '2026-01-01', 'payer')");
        self::assertSame([2026], $this->service->flatTaxConflictYears(1, $this->service->history(1), null, 2026));

        // An unrelated row in another year is still accepted (only NEW conflicts are refused).
        $this->service->add(1, '2030-01-01', VatStatus::NON_PAYER, null, null);

        self::assertCount(3, $this->rows());
    }

    public function testDeletingAFutureNonPayerRowThatWouldExtendPayerTimeIntoAFlatTaxYearIsRefused(): void
    {
        $this->nonPayerSupplier();
        $this->service->add(1, '2026-01-01', VatStatus::PAYER, null, null);
        $this->service->add(1, '2026-06-01', VatStatus::NON_PAYER, null, null);
        $this->pdo->exec("INSERT INTO tax_profiles (supplier_id, year, flat_tax_band) VALUES (1, 2027, 'band1')");
        $stopId = (int) $this->pdo->query("SELECT id FROM supplier_vat_status_history WHERE valid_from = '2026-06-01'")->fetchColumn();

        try {
            // Without the 2026-06-01 stop the supplier would stay a payer through 2027 (a band year).
            $this->service->delete(1, $stopId);
            self::fail('Expected the flat tax rule to refuse the deletion');
        } catch (VatStatusException $e) {
            self::assertSame('vat_flat_tax_conflict', $e->errorCode);
        }
    }

    public function testLegacyPayerFlagIsRefusedWhenTheSameRequestSetsAFlatTaxBand(): void
    {
        $this->nonPayerSupplier();

        $this->expectException(VatStatusException::class);
        $this->service->planFlagChange(1, true, null, 'band1', '2026-11-03');
    }

    public function testLegacyPayerFlagIsAcceptedWhenTheSameRequestTurnsFlatTaxOff(): void
    {
        $this->nonPayerSupplier('band1');

        self::assertSame(VatStatus::PAYER, $this->service->planFlagChange(1, true, null, 'none', '2026-11-03'));
    }

    // ── filing guard ──────────────────────────────────────────────────

    public function testVatReportsAreRefusedForAPeriodWithoutAnyVatDuties(): void
    {
        $this->nonPayerSupplier();
        $this->service->add(1, '2027-01-01', VatStatus::PAYER, null, null);

        $this->service->assertVatDutiesInPeriod(1, '2027-01-01', '2027-01-31');
        $this->service->assertVatDutiesInPeriod(1, '2026-10-01', '2027-03-31'); // straddles the registration

        try {
            $this->service->assertVatDutiesInPeriod(1, '2026-12-01', '2026-12-31');
            self::fail('Expected a refusal for December 2026');
        } catch (VatStatusException $e) {
            self::assertSame('not_vat_payer_in_period', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    // ── a supplier without stored rows ────────────────────────────────

    public function testFirstRowOnASupplierWithoutStoredRowsMaterialisesTheCachedFloor(): void
    {
        // Created by raw SQL / never seeded: the history is derived from the cached flags.
        $this->pdo->exec('INSERT INTO supplier (id, is_vat_payer, is_identified) VALUES (1, 0, 0)');

        $this->service->add(1, '2099-01-01', VatStatus::PAYER, null, null);

        // A lone future payer row must not make every earlier date a payer date.
        self::assertSame([['2000-01-01', VatStatus::NON_PAYER], ['2099-01-01', VatStatus::PAYER]], $this->rows());
        self::assertSame(VatStatus::NON_PAYER, $this->service->statusAt(1, '2026-12-31'));
        self::assertSame(VatStatus::PAYER, $this->service->statusAt(1, '2099-01-01'));
    }

    public function testLegacyFlagChangeOnASupplierWithoutStoredRowsKeepsTheEarlierStatus(): void
    {
        $this->pdo->exec('INSERT INTO supplier (id, is_vat_payer, is_identified) VALUES (1, 0, 1)');

        $this->service->applyFlagChange(1, true, false, null, null, '2026-11-03');

        self::assertSame([['2000-01-01', VatStatus::IDENTIFIED], ['2026-11-03', VatStatus::PAYER]], $this->rows());
        self::assertSame(VatStatus::IDENTIFIED, $this->service->statusAt(1, '2026-11-02'));
    }

    // ── migration 9002 not applied ────────────────────────────────────

    public function testWritesWithoutTheHistoryTableFailWithAClearMigrationError(): void
    {
        $this->pdo->exec('DROP TABLE supplier_vat_status_history');
        $this->pdo->exec('INSERT INTO supplier (id, is_vat_payer, is_identified) VALUES (1, 0, 0)');
        // Connection caches the schema probe per instance — build a fresh service over the table-less DB.
        $conn = new Connection($this->createStub(\MyInvoice\Infrastructure\Config\Config::class));
        (new \ReflectionClass($conn))->getProperty('pdo')->setValue($conn, $this->pdo);
        $service = new VatStatusService(new SupplierVatStatusRepository($conn));

        foreach ([
            fn () => $service->add(1, '2099-01-01', VatStatus::PAYER, null, null),
            fn () => $service->delete(1, 5),
            fn () => $service->planFlagChange(1, true, null, null, '2026-11-03'),
        ] as $write) {
            try {
                $write();
                self::fail('Expected the missing-table refusal');
            } catch (VatStatusException $e) {
                self::assertSame('migration_required', $e->errorCode);
                self::assertSame(409, $e->httpStatus);
                self::assertStringContainsString('migrace', $e->getMessage());
            }
        }
        // Reads keep working from the cached flags.
        self::assertSame(VatStatus::NON_PAYER, $service->statusAt(1, '2026-01-01'));
    }
}
