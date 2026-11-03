<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceVatAdvisor;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The non-blocking "payer-time invoice with every line on 0 %" hint. Synthetic data only.
 */
final class InvoiceVatAdvisorTest extends TestCase
{
    private InvoiceVatAdvisor $advisor;
    private bool $clientDomestic = true;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, is_vat_payer INTEGER NOT NULL DEFAULT 0, is_identified INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('INSERT INTO supplier (id) VALUES (7)');
        $pdo->exec('CREATE TABLE supplier_vat_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, valid_from TEXT NOT NULL, status TEXT NOT NULL, note TEXT NULL, created_at TEXT NULL, created_by INTEGER NULL)');
        // Non-payer since forever, payer from 2027-01-01.
        $pdo->exec("INSERT INTO supplier_vat_status_history (supplier_id, valid_from, status) VALUES (7, '2000-01-01', 'non_payer'), (7, '2027-01-01', 'payer')");
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $db->method('hasTable')->willReturn(true);

        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('clientIsDomestic')->willReturnCallback(fn (): bool => $this->clientDomestic);
        $this->advisor = new InvoiceVatAdvisor($invoices, VatStatusService::forConnection($db));
    }

    /**
     * A stored draft; items carry the rate snapshot saved with the line.
     *
     * @return array<string,mixed>
     */
    private function invoice(array $overrides = [], float $rate = 0.0): array
    {
        return $overrides + [
            'status'       => 'draft',
            'invoice_type' => 'invoice',
            'client_id'    => 5,
            'issue_date'   => '2027-02-05',
            'tax_date'     => '2027-01-31',
            'items'        => [['vat_rate_snapshot' => $rate], ['vat_rate_snapshot' => $rate]],
        ];
    }

    public function testPayerTimeDomesticInvoiceWithEveryLineOnZeroPercentIsFlagged(): void
    {
        $warnings = $this->advisor->warnings(7, $this->invoice());

        self::assertCount(1, $warnings);
        self::assertSame('payer_zero_rated', $warnings[0]['code']);
        self::assertStringContainsString('0 %', $warnings[0]['message']);
    }

    public function testOneTaxedLineClearsTheHint(): void
    {
        $inv = $this->invoice();
        $inv['items'][1]['vat_rate_snapshot'] = 21.0;

        self::assertSame([], $this->advisor->warnings(7, $inv));
    }

    public function testNoHintForANonPayerDuzp(): void
    {
        // Issued in payer time but DUZP before the registration → a legitimate non-payer invoice.
        self::assertSame([], $this->advisor->warnings(7, $this->invoice(['tax_date' => '2026-12-31', 'issue_date' => '2027-01-05'])));
    }

    public function testProformaIsJudgedByTheIssueDate(): void
    {
        self::assertCount(1, $this->advisor->warnings(7, $this->invoice(['invoice_type' => 'proforma', 'tax_date' => null, 'issue_date' => '2027-02-05'])));
        self::assertSame([], $this->advisor->warnings(7, $this->invoice(['invoice_type' => 'proforma', 'tax_date' => null, 'issue_date' => '2026-12-05'])));
    }

    public function testReverseChargeOssForeignClientAndCreditNoteAreNotFlagged(): void
    {
        self::assertSame([], $this->advisor->warnings(7, $this->invoice(['reverse_charge' => 1])));
        self::assertSame([], $this->advisor->warnings(7, $this->invoice(['invoice_type' => 'credit_note'])));

        $oss = $this->invoice();
        $oss['items'][0]['oss_applicable'] = 1;
        self::assertSame([], $this->advisor->warnings(7, $oss));

        $this->clientDomestic = false;
        self::assertSame([], $this->advisor->warnings(7, $this->invoice()));
    }

    public function testEmptyInvoiceAndLinesWithoutARateSnapshotAreNotFlagged(): void
    {
        self::assertSame([], $this->advisor->warnings(7, $this->invoice(['items' => []])));
        self::assertSame([], $this->advisor->warnings(7, $this->invoice(['items' => [['vat_rate_id' => 3]]])));
    }

    public function testAnIssuedInvoiceIsNeverFlagged(): void
    {
        self::assertSame([], $this->advisor->warnings(7, $this->invoice(['status' => 'issued'])));
    }
}
