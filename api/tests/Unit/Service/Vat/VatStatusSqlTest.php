<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Vat;

use MyInvoice\Service\Vat\VatStatusSql;
use PDO;
use PHPUnit\Framework\TestCase;

/** The per-document revenue/cost expression, evaluated on SQLite (plain SQL, no engine-specific syntax). */
final class VatStatusSqlTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, is_vat_payer INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE supplier_vat_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, valid_from TEXT NOT NULL, status TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, issue_date TEXT NOT NULL, tax_date TEXT NULL, total_without_vat REAL NOT NULL, total_with_vat REAL NOT NULL)');

        // Supplier 1: non-payer, payer from 2027-01-01. Supplier 2: no history rows, cache says payer.
        $this->pdo->exec("INSERT INTO supplier (id, is_vat_payer) VALUES (1, 0), (2, 1)");
        $this->pdo->exec("INSERT INTO supplier_vat_status_history (supplier_id, valid_from, status) VALUES (1, '2000-01-01', 'non_payer'), (1, '2027-01-01', 'payer')");
    }

    private function amountOf(int $supplierId, string $issueDate, ?string $taxDate): float
    {
        $this->pdo->prepare('DELETE FROM invoices')->execute();
        $this->pdo->prepare('INSERT INTO invoices (supplier_id, issue_date, tax_date, total_without_vat, total_with_vat) VALUES (?, ?, ?, 1000, 1210)')
            ->execute([$supplierId, $issueDate, $taxDate]);
        return (float) $this->pdo->query('SELECT ' . VatStatusSql::amount('invoices') . ' AS a FROM invoices')->fetchColumn();
    }

    public function testRevenueFollowsTheStatusOnTheDuzpNotOnTheIssueDate(): void
    {
        // Issued in January 2027 but DUZP in December 2026 → still a non-payer document: gross.
        self::assertSame(1210.0, $this->amountOf(1, '2027-01-05', '2026-12-31'));
        // DUZP on/after the registration → payer: base without VAT.
        self::assertSame(1000.0, $this->amountOf(1, '2027-01-31', '2027-01-31'));
        self::assertSame(1000.0, $this->amountOf(1, '2027-01-01', '2027-01-01'));
    }

    public function testMissingDuzpFallsBackToTheIssueDate(): void
    {
        self::assertSame(1210.0, $this->amountOf(1, '2026-12-31', null));
        self::assertSame(1000.0, $this->amountOf(1, '2027-02-01', null));
    }

    public function testDateBeforeTheFirstRowTakesTheFirstRowsStatus(): void
    {
        self::assertSame(1210.0, $this->amountOf(1, '1999-05-05', null));
    }

    public function testSupplierWithoutHistoryFallsBackToTheCachedFlag(): void
    {
        self::assertSame(1000.0, $this->amountOf(2, '2026-05-05', null));
    }
}
