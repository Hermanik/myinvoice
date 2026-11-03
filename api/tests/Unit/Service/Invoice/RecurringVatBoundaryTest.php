<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\RecurringInvoiceGenerator;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Recurring generation across the VAT registration date: the rate coercion for a
 * non-payer is decided by the dated status history on the invoice's DUZP, never by
 * today's flag. Synthetic in-memory database only.
 */
final class RecurringVatBoundaryTest extends TestCase
{
    private PDO $pdo;
    private RecurringInvoiceGenerator $generator;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, is_vat_payer INTEGER NOT NULL DEFAULT 0, is_identified INTEGER NOT NULL DEFAULT 0)');
        // The cache already says "payer" (it is January 2027 or later) — the history must still win per date.
        $this->pdo->exec('INSERT INTO supplier (id, is_vat_payer) VALUES (7, 1)');
        $this->pdo->exec('CREATE TABLE supplier_vat_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, valid_from TEXT NOT NULL, status TEXT NOT NULL, note TEXT NULL, created_at TEXT NULL, created_by INTEGER NULL)');
        $this->pdo->exec("INSERT INTO supplier_vat_status_history (supplier_id, valid_from, status) VALUES (7, '2000-01-01', 'non_payer'), (7, '2027-01-01', 'payer')");
        $this->pdo->exec('CREATE TABLE vat_rates (id INTEGER PRIMARY KEY, rate_percent REAL, is_reverse_charge INTEGER, valid_from TEXT, valid_to TEXT)');
        $this->pdo->exec("INSERT INTO vat_rates VALUES (1, 21, 0, '2024-01-01', NULL), (3, 0, 0, '2024-01-01', NULL)");

        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $db->method('hasTable')->willReturn(true);

        $reflection = new \ReflectionClass(RecurringInvoiceGenerator::class);
        $args = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType()->getName();
            $args[] = match ($type) {
                Connection::class => $db,
                VatStatusService::class => VatStatusService::forConnection($db),
                default => $this->createStub($type),
            };
        }
        $this->generator = $reflection->newInstanceArgs($args);
    }

    /** @return list<array<string,mixed>> */
    private function coerce(string $referenceDate): array
    {
        $method = new \ReflectionMethod(RecurringInvoiceGenerator::class, 'coerceNonVatPayerRates');
        return $method->invoke($this->generator, $this->pdo, 7, [['description' => 'Paušál', 'vat_rate_id' => 1]], $referenceDate);
    }

    public function testInvoiceWithDuzpBeforeRegistrationIsSwitchedToTheZeroRate(): void
    {
        self::assertSame(3, $this->coerce('2026-12-31')[0]['vat_rate_id']);
    }

    public function testInvoiceWithDuzpOnOrAfterRegistrationKeepsItsRate(): void
    {
        self::assertSame(1, $this->coerce('2027-01-01')[0]['vat_rate_id']);
        self::assertSame(1, $this->coerce('2027-01-31')[0]['vat_rate_id']);
    }
}
