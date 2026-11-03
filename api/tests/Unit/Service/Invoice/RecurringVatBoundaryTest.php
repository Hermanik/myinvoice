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
    private Connection $db;
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

        $this->db = $db;
        $this->generator = $this->generator();
    }

    /**
     * @param array<class-string, object> $collaborators replaces the default stub of a constructor dependency
     */
    private function generator(array $collaborators = []): RecurringInvoiceGenerator
    {
        $reflection = new \ReflectionClass(RecurringInvoiceGenerator::class);
        $args = [];
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType()->getName();
            $args[] = $collaborators[$type] ?? match ($type) {
                Connection::class => $this->db,
                VatStatusService::class => VatStatusService::forConnection($this->db),
                default => $this->createStub($type),
            };
        }
        return $reflection->newInstanceArgs($args);
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

    // ── payer-time invoice with every line on 0 % is held as a draft ──────────────────

    /** @return array{0: RecurringInvoiceGenerator, 1: \PHPUnit\Framework\MockObject\MockObject, 2: \PHPUnit\Framework\MockObject\MockObject} */
    private function generatorWithInvoice(float $rate): array
    {
        $invoices = $this->createStub(\MyInvoice\Repository\InvoiceRepository::class);
        $invoices->method('clientIsDomestic')->willReturn(true);
        $invoices->method('find')->willReturn([
            'id' => 50, 'status' => 'draft', 'invoice_type' => 'invoice', 'client_id' => 8, 'tax_date' => '2027-01-31', 'issue_date' => '2027-02-05',
            'items' => [['vat_rate_snapshot' => $rate]],
        ]);
        $templates = $this->createMock(\MyInvoice\Repository\RecurringTemplateRepository::class);
        $issueAndSend = $this->createMock(\MyInvoice\Service\Invoice\AutoIssueAndSendService::class);
        $generator = $this->generator([
            \MyInvoice\Repository\InvoiceRepository::class => $invoices,
            \MyInvoice\Repository\RecurringTemplateRepository::class => $templates,
            \MyInvoice\Service\Invoice\AutoIssueAndSendService::class => $issueAndSend,
            \MyInvoice\Service\Invoice\InvoiceVatAdvisor::class => new \MyInvoice\Service\Invoice\InvoiceVatAdvisor($invoices, VatStatusService::forConnection($this->db)),
        ]);
        return [$generator, $templates, $issueAndSend];
    }

    private function performIssue(RecurringInvoiceGenerator $generator): array
    {
        $template = ['id' => 12, 'supplier_id' => 7, 'auto_issue' => true, 'auto_send_email' => true];
        return (new \ReflectionMethod(RecurringInvoiceGenerator::class, 'performIssue'))
            ->invoke($generator, 50, $template, 3, '127.0.0.1', 'phpunit');
    }

    public function testPayerTimeInvoiceOnZeroPercentIsNotIssuedOrSent(): void
    {
        [$generator, $templates, $issueAndSend] = $this->generatorWithInvoice(0.0);
        $issueAndSend->expects(self::never())->method('run');
        $templates->expects(self::once())->method('setLastError')->with(12, self::stringContains('zůstala jako koncept'));

        $result = $this->performIssue($generator);

        self::assertFalse($result['issued']);
        self::assertSame([], $result['sent_to']);
        self::assertNull($result['varsymbol']);
        self::assertSame('payer_zero_rated', $result['warnings'][0]['code']);
    }

    public function testPayerTimeInvoiceWithARealRateIsIssuedAsBefore(): void
    {
        [$generator, $templates, $issueAndSend] = $this->generatorWithInvoice(21.0);
        $templates->expects(self::never())->method('setLastError');
        $issueAndSend->expects(self::once())->method('run')->willReturn(['issued' => true, 'sent_to' => ['a@example.invalid'], 'varsymbol' => '2701001']);

        $result = $this->performIssue($generator);

        self::assertTrue($result['issued']);
        self::assertSame([], $result['warnings']);
    }
}
