<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Export\ExportBasis;
use MyInvoice\Service\Export\ExportDocumentSelector;
use MyInvoice\Service\Export\ExportPeriodResolver;
use MyInvoice\Service\Export\ExportSummaryCsv;
use MyInvoice\Service\Export\MonthlyExportService;
use MyInvoice\Tests\Support\VatStatusFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Yearly bulk export with the payment-date basis (income tax of a sole trader) against a real
 * database. Synthetic documents in an isolated year (2097) under the first supplier; everything
 * is removed in tearDown and the supplier's VAT status history is restored.
 *
 * The dataset (year 2097; "paid" = fully paid, paid_at set):
 *
 *   issued     B  DUZP 2097-04-10  paid 2097-05-02            in the year's payments
 *              C  DUZP 2096-12-28  paid 2097-01-10            in (issued last year, paid this year)
 *              E  credit note, DUZP 2097-05-01, paid 05-20    in (negative)
 *              K  DUZP 2097-11-05  paid 2097-11-20            in (a month the supplier is no payer)
 *              A  DUZP 2097-12-20  paid 2098-01-05            outside: paid next year
 *              D  DUZP 2097-06-01  unpaid                     outside: unpaid
 *              F  proforma, "paid"                            never (not income)
 *              G  draft                                       never
 *   received   P1 DUZP 2097-07-01  paid 2097-07-15            in
 *              P4 credit note (stored positive), paid 09-10   in (negative)
 *              P2 DUZP 2097-08-01  unpaid                     outside: unpaid
 *              P3 DUZP 2097-12-30  paid 2098-01-03            outside: paid next year
 *
 *   VAT status: payer from 2097-03-15 to 2097-09-30, otherwise not.
 *
 * Soft-skips without cfg.php (CI runner without a database).
 */
#[Group('integration')]
final class MonthlyExportYearlyTest extends TestCase
{
    private const YEAR = 2097;

    private Connection $db;
    private ExportDocumentSelector $selector;
    private MonthlyExportService $export;
    private ImportJobRepository $jobs;
    private ExportPeriodResolver $resolver;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $customerId = 0;
    private int $vendorId = 0;
    /** @var array<string,int> */
    private array $inv = [];
    /** @var array<string,int> */
    private array $pur = [];
    /** @var list<int> */
    private array $jobIds = [];
    /** @var list<array{valid_from: string, status: string, note: ?string}>|null */
    private ?array $origVatHistory = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->selector = $c->get(ExportDocumentSelector::class);
            $this->export = $c->get(MonthlyExportService::class);
            $this->jobs = $c->get(ImportJobRepository::class);
            $this->resolver = new ExportPeriodResolver();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->currencyId, $this->vatRateId, $this->userId, $this->czId], true)) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $this->origVatHistory = VatStatusFixture::snapshot($pdo, $this->supplierId);
        VatStatusFixture::setHistory($pdo, $this->supplierId, [
            ['2000-01-01', 'non_payer'],
            [self::YEAR . '-03-15', 'payer'],
            [self::YEAR . '-10-01', 'non_payer'],
        ]);
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->jobIds as $jobId) {
            $job = $this->jobs->findById($jobId);
            if ($job !== null && !empty($job['result_path'])) {
                @unlink($this->export->resolveResultPath((string) $job['result_path']));
            }
            $pdo->prepare('DELETE FROM import_jobs WHERE id = ?')->execute([$jobId]);
        }
        foreach ($this->inv as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->pur as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ([$this->customerId, $this->vendorId] as $id) {
            if ($id) {
                $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
            }
        }
        if ($this->origVatHistory !== null) {
            VatStatusFixture::restore($pdo, $this->supplierId, $this->origVatHistory);
        }
        $this->db->close();
    }

    public function testPaymentBasisSelectsExactlyTheDocumentsPaidInTheYear(): void
    {
        $period = $this->year(self::YEAR);

        self::assertEqualsCanonicalizing(
            [$this->inv['B'], $this->inv['C'], $this->inv['E'], $this->inv['K']],
            $this->selector->paidSalesIds($this->supplierId, $period),
            'paid in 2097: B, C (issued 2096), credit note E, K; not A (paid 2098), D (unpaid), proforma F, draft G',
        );
        self::assertEqualsCanonicalizing(
            [$this->pur['P1'], $this->pur['P4']],
            $this->selector->paidPurchaseIds($this->supplierId, $period),
        );
    }

    public function testPaymentDateOrdersTheSelection(): void
    {
        $ids = $this->selector->paidSalesIds($this->supplierId, $this->year(self::YEAR));

        self::assertSame(
            [$this->inv['C'], $this->inv['B'], $this->inv['E'], $this->inv['K']],
            $ids,
            'by payment date: Jan 10, May 2, May 20, Nov 20',
        );
    }

    public function testDocumentPaidNextYearBelongsToTheNextYear(): void
    {
        $next = $this->year(self::YEAR + 1);

        self::assertSame([$this->inv['A']], $this->selector->paidSalesIds($this->supplierId, $next));
        self::assertSame([$this->pur['P3']], $this->selector->paidPurchaseIds($this->supplierId, $next));
    }

    public function testOutsideDocumentsAreTheUnpaidAndTheOnesPaidOutsideThePeriod(): void
    {
        $period = $this->year(self::YEAR);
        $paidSales = $this->selector->paidSalesIds($this->supplierId, $period);
        $paidPurchase = $this->selector->paidPurchaseIds($this->supplierId, $period);

        self::assertEqualsCanonicalizing(
            [$this->inv['A'], $this->inv['D']],
            $this->selector->outsideSalesIds($this->supplierId, $period, $paidSales),
            'DUZP in 2097 but not paid in it; C has DUZP 2096, F is a proforma, G a draft',
        );
        self::assertEqualsCanonicalizing(
            [$this->pur['P2'], $this->pur['P3']],
            $this->selector->outsidePurchaseIds($this->supplierId, $period, $paidPurchase),
        );
    }

    public function testSummaryRowsCarrySignStateAndCountedFlag(): void
    {
        $period = $this->year(self::YEAR);

        $rows = $this->selector->salesSummaryRows(
            $this->supplierId, [$this->inv['B'], $this->inv['E'], $this->inv['F']], $period,
        );
        self::assertSame('Faktura', $rows[0]->type);
        self::assertSame(1000.0, $rows[0]->base);
        self::assertSame('2097-05-02', $rows[0]->paidAt);
        self::assertSame('Uhrazeno', $rows[0]->state);
        self::assertSame('Dobropis', $rows[1]->type);
        self::assertSame(-500.0, $rows[1]->base, 'credit note reduces the income');
        self::assertSame(-605.0, $rows[1]->total);
        self::assertSame('Proforma', $rows[2]->type);
        self::assertFalse($rows[2]->counted, 'a proforma is listed but not summed');

        $outside = $this->selector->salesSummaryRows($this->supplierId, [$this->inv['A'], $this->inv['D']], $period, true);
        self::assertSame('Uhrazeno po období', $outside[0]->state);
        self::assertSame('Neuhrazeno', $outside[1]->state);
        self::assertNull($outside[1]->paidAt);

        $purchases = $this->selector->purchaseSummaryRows(
            $this->supplierId, [$this->pur['P1'], $this->pur['P4']], $period,
        );
        self::assertSame(484.0, $purchases[0]->total);
        self::assertSame('Dobropis', $purchases[1]->type);
        self::assertSame(-121.0, $purchases[1]->total, 'received credit note is negative whatever sign was stored');
    }

    public function testSummaryCsvTotalsMatchThePaidDocuments(): void
    {
        $period = $this->year(self::YEAR);
        $ids = $this->selector->paidSalesIds($this->supplierId, $period);

        $csv = ExportSummaryCsv::render($this->selector->salesSummaryRows($this->supplierId, $ids, $period));
        $lines = explode("\r\n", rtrim($csv, "\r\n"));
        $totals = explode(';', end($lines));

        // B 1000 + C 2000 + E -500 + K 700 = 3200 base; VAT 210 + 0 - 105 + 0 = 105
        self::assertSame('3200,00', $totals[9]);
        self::assertSame('105,00', $totals[10]);
        self::assertSame('3305,00', $totals[11]);
    }

    public function testVatBookMonthsAreOnlyThoseWithPayerTime(): void
    {
        self::assertSame(
            [[self::YEAR, 3], [self::YEAR, 4], [self::YEAR, 5], [self::YEAR, 6], [self::YEAR, 7], [self::YEAR, 8], [self::YEAR, 9]],
            $this->selector->vatBookMonths($this->supplierId, $this->year(self::YEAR)),
            'payer from March 15th (March counts) until September 30th',
        );
        self::assertSame(
            [[self::YEAR, 3]],
            $this->selector->vatBookMonths($this->supplierId, $this->resolver->resolve(['period' => 'quarterly', 'year' => self::YEAR, 'quarter' => 1])),
            'Q1: January and February are before the payer time',
        );
        self::assertSame(
            [],
            $this->selector->vatBookMonths($this->supplierId, $this->resolver->resolve(['period' => 'monthly', 'year' => self::YEAR, 'month' => 12])),
        );
    }

    public function testPreviewCountsFollowTheBasis(): void
    {
        $period = $this->year(self::YEAR);

        $payment = $this->export->previewCounts($this->supplierId, $period, ExportBasis::Payment);
        self::assertSame(4, $payment['counts']['sales_isdoc']);
        self::assertSame(2, $payment['counts']['purchase_isdoc']);
        self::assertSame(['sales' => 2, 'purchase' => 2], $payment['outside']);
        self::assertSame(7, $payment['counts']['dph_book']);
        self::assertSame(4 + 2 + 2 + 2, $payment['counts']['summary_csv']);

        $duzp = $this->export->previewCounts($this->supplierId, $period, ExportBasis::Duzp);
        // DUZP in 2097: A, B, D, E, K and the proforma F (the export has always contained it); C is 2096.
        self::assertSame(6, $duzp['counts']['sales_isdoc']);
        self::assertSame(4, $duzp['counts']['purchase_isdoc']);
        self::assertSame(['sales' => 0, 'purchase' => 0], $duzp['outside']);
    }

    public function testMonthlyExportKeepsTheDuzpBasisByDefault(): void
    {
        $month = $this->resolver->resolve(['period' => 'monthly', 'year' => self::YEAR, 'month' => 12]);

        $counts = $this->export->previewCounts(
            $this->supplierId, $month, $this->resolver->resolveBasis([], $month),
        )['counts'];

        self::assertSame(1, $counts['sales_isdoc'], 'A: DUZP December, paid only in January');
        self::assertSame(1, $counts['purchase_isdoc'], 'P3: DUZP December 30th');
        self::assertSame(0, $counts['dph_book'], 'the supplier is no payer in December');
    }

    public function testYearlyExportZipHasTheDocumentsCsvReadmeAndPayerMonthBooksOnly(): void
    {
        $zip = $this->runExport(['period' => 'yearly', 'year' => self::YEAR, 'parts' => ['sales_isdoc', 'purchase_isdoc', 'summary_csv', 'dph_book']]);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        // ISDOC of exactly the invoices paid in the year
        $sales = array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'Vystavene-faktury/ISDOC/')));
        self::assertCount(4, $sales, implode(', ', $names));
        foreach (['2097B', '2096C', '2097E', '2097K'] as $vs) {
            self::assertNotEmpty(array_filter($sales, static fn (string $n): bool => str_contains($n, $vs)), "missing {$vs}");
        }
        foreach (['2098A', '2097D', '2097F', '2097G'] as $vs) {
            self::assertEmpty(array_filter($sales, static fn (string $n): bool => str_contains($n, $vs)), "unexpected {$vs}");
        }
        self::assertCount(2, array_filter($names, static fn (string $n): bool => str_starts_with($n, 'Prijate-faktury/ISDOC/')));

        // VAT books: only months of payer time (March..September), never November/December
        $books = array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'Kniha-DPH/')));
        self::assertNotEmpty($books, 'at least the months with documents have a VAT book');
        foreach ($books as $book) {
            self::assertMatchesRegularExpression('#^Kniha-DPH/kniha-dph-2097-0[3-9]\.pdf$#', $book);
            self::assertStringStartsWith('%PDF', (string) $zip->getFromName($book));
        }

        // Summary CSVs
        self::assertContains('Prehled-vydanych-faktur.csv', $names);
        self::assertContains('Prehled-prijatych-faktur.csv', $names);
        $issued = (string) $zip->getFromName('Prehled-vydanych-faktur.csv');
        self::assertStringStartsWith("\xEF\xBB\xBF", $issued);
        self::assertStringContainsString('Celkem (v CZK);;;;;;;;;3200,00;105,00;3305,00;CZK', $issued);
        self::assertStringContainsString('Neuhrazeno', $issued, 'the unpaid invoice D is listed');
        self::assertStringContainsString('Uhrazeno po období', $issued, 'invoice A paid next year is listed');
        $received = (string) $zip->getFromName('Prehled-prijatych-faktur.csv');
        self::assertStringContainsString('Celkem (v CZK);;;;;;;;;300,00;63,00;363,00;CZK', $received);

        // README explains the basis
        $readme = (string) $zip->getFromName('README.txt');
        self::assertStringContainsString('podle DATA ÚHRADY', $readme);
        self::assertStringContainsString('Kniha DPH: vždy podle DUZP', $readme);
        self::assertStringContainsString('Vystavené: 2 (z toho zcela neuhrazené 1)', $readme);
        $zip->close();
    }

    public function testExportKeepsNoTemporaryFilesBehind(): void
    {
        $zip = $this->runExport(['period' => 'yearly', 'year' => self::YEAR, 'parts' => ['summary_csv']]);
        $zip->close();

        $leftovers = glob($this->export->storageBaseDir() . '/sup-' . $this->supplierId . '/tmp-*') ?: [];
        self::assertSame([], $leftovers);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function year(int $year): \MyInvoice\Service\Export\ExportPeriod
    {
        return $this->resolver->resolve(['period' => 'yearly', 'year' => $year]);
    }

    /** @param array<string,mixed> $params */
    private function runExport(array $params): ZipArchive
    {
        $jobId = $this->jobs->create($this->supplierId, 'monthly_export', $params, $this->userId);
        $this->jobIds[] = $jobId;
        // mPDF's font-table parsing emits unpack() warnings on this PHP version; the PDFs are fine
        // (asserted below), so the vendor noise does not turn the test into a "warning" run.
        set_error_handler(static fn (int $no, string $str, string $file): bool => str_contains(str_replace('\\', '/', $file), '/vendor/mpdf/'));
        try {
            $this->export->run($jobId);
        } finally {
            restore_error_handler();
        }

        $job = $this->jobs->findById($jobId);
        self::assertSame('completed', $job['status'] ?? null, (string) ($job['last_error'] ?? 'job did not complete'));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->export->resolveResultPath((string) $job['result_path'])) === true);
        return $zip;
    }

    private function seed(): void
    {
        $this->customerId = $this->client('Odběratel Export', customer: true);
        $this->vendorId = $this->client('Dodavatel Export', vendor: true);
        $y = self::YEAR;

        $this->inv['B'] = $this->sale("{$y}B", 'invoice', "{$y}-04-10", 'paid', "{$y}-05-02", 1000, 210);
        $this->inv['C'] = $this->sale(($y - 1) . 'C', 'invoice', ($y - 1) . '-12-28', 'paid', "{$y}-01-10", 2000, 0);
        $this->inv['E'] = $this->sale("{$y}E", 'credit_note', "{$y}-05-01", 'paid', "{$y}-05-20", -500, -105);
        $this->inv['K'] = $this->sale("{$y}K", 'invoice', "{$y}-11-05", 'paid', "{$y}-11-20", 700, 0);
        $this->inv['A'] = $this->sale(($y + 1) . 'A', 'invoice', "{$y}-12-20", 'paid', ($y + 1) . '-01-05', 900, 0);
        $this->inv['D'] = $this->sale("{$y}D", 'invoice', "{$y}-06-01", 'issued', null, 3000, 630);
        $this->inv['F'] = $this->sale("{$y}F", 'proforma', "{$y}-02-01", 'paid', "{$y}-02-05", 800, 0);
        $this->inv['G'] = $this->sale("{$y}G", 'invoice', "{$y}-07-01", 'draft', null, 100, 0);

        $this->pur['P1'] = $this->purchase('P1', 'invoice', "{$y}-07-01", 'paid', "{$y}-07-15", 400, 84);
        // Stored positive on purpose: a received credit note must still reduce the cost.
        $this->pur['P4'] = $this->purchase('P4', 'credit_note', "{$y}-09-01", 'paid', "{$y}-09-10", 100, 21);
        $this->pur['P2'] = $this->purchase('P2', 'invoice', "{$y}-08-01", 'received', null, 250, 0);
        $this->pur['P3'] = $this->purchase('P3', 'invoice', "{$y}-12-30", 'paid', ($y + 1) . '-01-03', 600, 0);
    }

    private function client(string $name, bool $customer = false, bool $vendor = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, ic, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "12345678", "CZ12345678", "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function sale(string $varsymbol, string $type, string $date, string $status, ?string $paidAt, float $base, float $vat): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, paid_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $varsymbol, $type, $this->customerId, $date, $date, $date,
            $this->currencyId, $base, $vat, $base + $vat, $status, $paidAt, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->items('invoice_items', 'invoice_id', $id, $base, $vat);
        return $id;
    }

    private function purchase(string $number, string $kind, string $date, string $status, ?string $paidAt, float $base, float $vat): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, paid_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $this->vendorId, 'EXP-' . self::YEAR . '-' . $number, $kind, $date, $date, $date, $date,
            $this->currencyId, $base, $vat, $base + $vat, $status, $paidAt, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->items('purchase_invoice_items', 'purchase_invoice_id', $id, $base, $vat);
        return $id;
    }

    private function items(string $table, string $fk, int $id, float $base, float $vat): void
    {
        $rate = $base != 0.0 && $vat != 0.0 ? 21 : 0;
        $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $base + $vat]);
    }
}
