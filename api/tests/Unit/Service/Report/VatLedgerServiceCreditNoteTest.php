<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Znaménko dobropisů v DPH evidenci (oprava 2026-07).
 *
 * Konvence uložení:
 *   - PŘIJATÝ dobropis (purchase_invoices.document_kind='credit_note') existuje
 *     v DB v OBOU znaménkových konvencích (ruční/AI záporně, část importů kladně
 *     — jen soft warning credit_note_positive_total) → fetchPurchases normalizuje
 *     přes -ABS() vždy na záporno. Bez toho kladný dobropis odpočet ZVÝŠÍ
 *     (chyba v4.51.0) a prosté ×(−1) by zase dvojitě negovalo záporné.
 *   - VYDANÝ dobropis (invoices.invoice_type='credit_note') má v DB ZÁPORNÉ
 *     částky (záporné quantity, viz CancelInvoiceAction) → fetchSales znaménko
 *     NEUPRAVUJE.
 *   - Zálohová výzva (document_kind='advance') není daňový doklad → mimo evidenci.
 */
final class VatLedgerServiceCreditNoteTest extends TestCase
{
    private PDO $pdo;
    private VatLedgerService $ledger;
    private KontrolniHlaseniBuilder $kh;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seed();

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $conn = new Connection($config);
        (new \ReflectionClass($conn))->getProperty('pdo')->setValue($conn, $this->pdo);

        $taxConstants = new TaxConstantsRepository($conn);
        $vatStatus = VatStatusService::forConnection($conn);
        $this->ledger = new VatLedgerService($conn, $taxConstants, $vatStatus);
        $this->kh = new KontrolniHlaseniBuilder($conn, $this->ledger, $taxConstants, $vatStatus);
    }

    public function testReceivedCreditNoteNetsPairedInvoiceToZero(): void
    {
        // Přijatá faktura 2 654,79 / 557,51 + dobropis na STEJNOU částku (v DB kladně)
        // → součet základů i daní v evidenci musí být 0 (reálný scénář PF2602004).
        $this->insertPurchase(10, '2026-02-10', 2654.79, 557.51, 'invoice', '40');
        $this->insertPurchase(11, '2026-02-26', 2654.79, 557.51, 'credit_note', '40');

        $rows = $this->purchaseRows('2026-02-01', '2026-02-28');
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(0.0, array_sum(array_column($rows, 'base_czk')), 0.001);
        $this->assertEqualsWithDelta(0.0, array_sum(array_column($rows, 'vat_czk')), 0.001);
    }

    public function testReceivedCreditNoteStoredNegativeIsNotDoubleNegated(): void
    {
        // V DB žijí OBĚ konvence: ruční pořízení / AI import ukládá přijaté dobropisy
        // ZÁPORNĚ (qty −1), část importů KLADNĚ. -ABS() musí obě srovnat na záporno —
        // prosté ×(−1) by záporný dobropis dvojitě negovalo a odpočet ZVÝŠILO.
        $this->insertPurchase(17, '2026-03-15', -2654.79, -557.51, 'credit_note', '40');

        $rows = $this->purchaseRows('2026-03-01', '2026-03-31');
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(-2654.79, $rows[0]['base_czk'], 0.001, 'záporné uložení zůstává záporné');
        $this->assertEqualsWithDelta(-557.51, $rows[0]['vat_czk'], 0.001);
    }

    public function testReceivedCreditNoteAloneHasNegativeBaseAndVat(): void
    {
        // Dobropis bez párové faktury → záporný základ i daň (i total_with_vat_czk).
        $this->insertPurchase(12, '2026-03-05', 2654.79, 557.51, 'credit_note', '40');

        $rows = $this->purchaseRows('2026-03-01', '2026-03-31');
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(-2654.79, $rows[0]['base_czk'], 0.001);
        $this->assertEqualsWithDelta(-557.51, $rows[0]['vat_czk'], 0.001);
        $this->assertEqualsWithDelta(-3212.30, $rows[0]['total_with_vat_czk'], 0.001);
    }

    public function testReceivedCreditNoteOver10kGoesToB2AsNegativeRow(): void
    {
        // Dobropis nad 10 000 Kč vč. DPH → samostatný ZÁPORNÝ řádek v KH B.2
        // (práh počítá abs hodnotu), NE příspěvek do sumace B.3.
        $this->insertPurchase(13, '2026-04-10', 20000.0, 4200.0, 'credit_note', '40');

        $xml = new \SimpleXMLElement($this->kh->build(1, 2026, 4, 'monthly')['xml']);
        $b2 = $xml->xpath('//VetaB2');
        $b3 = $xml->xpath('//VetaB3');
        $this->assertCount(1, $b2, 'dobropis nad limit patří do B.2 jako samostatný řádek');
        $this->assertCount(0, $b3, 'do B.3 sumace nesmí přispět');
        $this->assertSame('-20000.00', (string) $b2[0]['zakl_dane1']);
        $this->assertSame('-4200.00', (string) $b2[0]['dan1']);
        // Rekapitulace VetaC musí záporný základ propsat.
        $c = $xml->xpath('//VetaC');
        $this->assertSame('-20000.00', (string) $c[0]['pln23']);
    }

    public function testReceivedCreditNoteUnder10kReducesB3(): void
    {
        // Faktura 8 000/1 680 + dobropis 3 000/630 (oba pod limit) → B.3 = 5 000/1 050.
        $this->insertPurchase(14, '2026-05-03', 8000.0, 1680.0, 'invoice', '40');
        $this->insertPurchase(15, '2026-05-20', 3000.0, 630.0, 'credit_note', '40');

        $xml = new \SimpleXMLElement($this->kh->build(1, 2026, 5, 'monthly')['xml']);
        $b3 = $xml->xpath('//VetaB3');
        $this->assertCount(1, $b3);
        $this->assertSame('5000.00', (string) $b3[0]['zakl_dane1']);
        $this->assertSame('1050.00', (string) $b3[0]['dan1']);
    }

    public function testIssuedCreditNoteKeepsStoredNegativeSign(): void
    {
        // Vydaný dobropis je v DB ZÁPORNÝ — ledger ho NESMÍ znovu negovat
        // (dvojitá negace by plnění přičetla). Párová faktura + dobropis = 0.
        $this->insertSale(20, '2026-02-05', 12650.0, 2656.50, 'invoice', '1');
        $this->insertSale(21, '2026-02-20', -12650.0, -2656.50, 'credit_note', '1');

        $rows = array_values(array_filter(
            $this->ledger->rows(1, '2026-02-01', '2026-02-28'),
            static fn (array $r) => $r['source'] === 'sale',
        ));
        $this->assertCount(2, $rows);
        $creditNote = $rows[1];
        $this->assertSame('credit_note', $creditNote['document_kind']);
        $this->assertEqualsWithDelta(-12650.0, $creditNote['base_czk'], 0.001, 'záporné uložení se zachovává 1:1');
        $this->assertEqualsWithDelta(0.0, array_sum(array_column($rows, 'base_czk')), 0.001);
        $this->assertEqualsWithDelta(0.0, array_sum(array_column($rows, 'vat_czk')), 0.001);
    }

    public function testIssuedCreditNoteOver10kGoesToA4AsNegativeRow(): void
    {
        // Analogicky výstupní strana: vydaný dobropis nad limit → samostatný
        // záporný řádek v A.4 (odběratel s DIČ), ne sumace A.5.
        $this->insertSale(22, '2026-06-15', -15000.0, -3150.0, 'credit_note', '1');

        $xml = new \SimpleXMLElement($this->kh->build(1, 2026, 6, 'monthly')['xml']);
        $a4 = $xml->xpath('//VetaA4');
        $a5 = $xml->xpath('//VetaA5');
        $this->assertCount(1, $a4, 'vydaný dobropis nad limit → samostatný řádek A.4');
        $this->assertCount(0, $a5);
        $this->assertSame('-15000.00', (string) $a4[0]['zakl_dane1']);
        $this->assertSame('-3150.00', (string) $a4[0]['dan1']);
    }

    public function testAdvanceStaysExcluded(): void
    {
        // Zálohová výzva (advance) není daňový doklad → nesmí do evidence,
        // ani se záporným znaménkem, ani kladně.
        $this->insertPurchase(16, '2026-06-01', 3875.91, 813.94, 'advance', '40');

        $this->assertSame([], $this->purchaseRows('2026-06-01', '2026-06-30'));
    }

    // ───── VAT status on the DUZP (dated history) ─────────────────────────

    /** Non-payer since forever, payer from 2027-01-01 (case: a December invoice issued in January). */
    private function registerAsPayerFrom2027(): void
    {
        $this->pdo->exec("UPDATE supplier SET is_vat_payer = 0 WHERE id = 1");
        $this->pdo->exec("INSERT INTO supplier_vat_status_history (supplier_id, valid_from, status) VALUES
            (1, '2000-01-01', 'non_payer'), (1, '2027-01-01', 'payer')");
    }

    public function testDocumentsDatedBeforeRegistrationAreOutsideVatTime(): void
    {
        $this->registerAsPayerFrom2027();
        // December invoice issued in January: DUZP 2026-12-31 = non-payer → outside the VAT records.
        $this->insertSale(30, '2026-12-31', 1000.0, 0.0, 'invoice', '1');
        // January 2027: payer.
        $this->insertSale(31, '2027-01-31', 1000.0, 210.0, 'invoice', '1');
        // Received document with a pre-registration DUZP = no right to deduct.
        $this->insertPurchase(32, '2026-12-20', 500.0, 105.0, 'invoice', '40');
        $this->insertPurchase(33, '2027-01-10', 500.0, 105.0, 'invoice', '40');

        $dec = $this->ledger->rows(1, '2026-12-01', '2026-12-31');
        $jan = $this->ledger->rows(1, '2027-01-01', '2027-01-31');

        $this->assertSame([], $dec, 'prosinec 2026: žádné období plátcovství → žádné řádky');
        $this->assertEqualsWithDelta(1500.0, array_sum(array_column($jan, 'base_czk')), 0.001);
        $this->assertSame([31, 33], array_map(static fn (array $r) => $r['invoice_id'], $jan));
    }

    public function testPurchaseIssuedAfterRegistrationButDatedBeforeItGetsNoDeduction(): void
    {
        $this->registerAsPayerFrom2027();
        // DUZP 2026-12-28, issued 2027-01-05: the deduction period is January (the later date),
        // but he was not a payer at the DUZP → the row does not belong in the January return.
        $this->pdo->prepare("INSERT INTO purchase_invoices (id, supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind, issue_date, tax_date, currency_id, exchange_rate, reverse_charge, status, vat_classification_code, total_with_vat)
            VALUES (40, 1, 201, 'PF40', 'VD40', 'invoice', '2027-01-05', '2026-12-28', 1, 1, 0, 'received', '40', 605)")->execute();
        $this->pdo->exec("INSERT INTO purchase_invoice_items (id, purchase_invoice_id, vat_rate_snapshot, description, total_without_vat, total_vat, vat_classification_code)
            VALUES (40, 40, 21.0, 'plnění', 500, 105, '40')");

        $this->assertSame([], $this->purchaseRows('2027-01-01', '2027-01-31'));
    }

    public function testControlStatementKeepsOnlyPayerTimeDocumentsAndWarnsOutsideIt(): void
    {
        $this->registerAsPayerFrom2027();
        $this->insertSale(30, '2026-12-31', 15000.0, 0.0, 'invoice', '1');
        $this->insertSale(31, '2027-01-31', 15000.0, 3150.0, 'invoice', '1');

        $dec = $this->kh->build(1, 2026, 12, 'monthly');
        $this->assertCount(0, (new \SimpleXMLElement($dec['xml']))->xpath('//VetaA4'));
        $this->assertStringContainsString('nebyl dodavatel plátcem', implode("\n", $dec['warnings']));

        $jan = $this->kh->build(1, 2027, 1, 'monthly');
        $a4 = (new \SimpleXMLElement($jan['xml']))->xpath('//VetaA4');
        $this->assertCount(1, $a4);
        $this->assertSame('15000.00', (string) $a4[0]['zakl_dane1']);
        $this->assertStringNotContainsString('nebyl dodavatel plátcem', implode("\n", $jan['warnings']));
    }

    public function testQuarterStraddlingTheRegistrationWarnsAndCountsOnlyPayerTime(): void
    {
        $this->pdo->exec("UPDATE supplier SET is_vat_payer = 0 WHERE id = 1");
        $this->pdo->exec("INSERT INTO supplier_vat_status_history (supplier_id, valid_from, status) VALUES
            (1, '2000-01-01', 'non_payer'), (1, '2027-02-15', 'payer')");
        $this->insertSale(30, '2027-02-10', 15000.0, 0.0, 'invoice', '1');
        $this->insertSale(31, '2027-03-10', 15000.0, 3150.0, 'invoice', '1');

        $q1 = $this->kh->build(1, 2027, 3, 'quarterly');

        $a4 = (new \SimpleXMLElement($q1['xml']))->xpath('//VetaA4');
        $this->assertCount(1, $a4, 'jen doklad s DUZP v době plátcovství');
        $this->assertSame('3150.00', (string) $a4[0]['dan1']);
        $this->assertStringContainsString('jen část období', implode("\n", $q1['warnings']));
    }

    // ───── helpers ───────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> jen purchase řádky ledgeru */
    private function purchaseRows(string $start, string $end): array
    {
        return array_values(array_filter(
            $this->ledger->rows(1, $start, $end),
            static fn (array $r) => $r['source'] === 'purchase',
        ));
    }

    private function createSchema(): void
    {
        $this->pdo->exec("CREATE TABLE currencies (id INTEGER PRIMARY KEY, code TEXT NOT NULL)");
        $this->pdo->exec("INSERT INTO currencies (id, code) VALUES (1, 'CZK')");
        $this->pdo->exec("CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT NOT NULL, is_eu INTEGER NOT NULL DEFAULT 0)");
        $this->pdo->exec("INSERT INTO countries (id, iso2, is_eu) VALUES (1,'CZ',1), (4,'DE',1), (9,'US',0)");
        $this->pdo->exec("CREATE TABLE tax_constants (year INTEGER PRIMARY KEY, data TEXT NOT NULL)");
        // Plné sloupce pro KontrolniHlaseniBuilder::loadSupplier (VetaP).
        $this->pdo->exec("CREATE TABLE supplier (
            id INTEGER PRIMARY KEY, company_name TEXT NOT NULL DEFAULT '', street TEXT NULL,
            city TEXT NULL, zip TEXT NULL, country_id INTEGER NULL, ic TEXT NULL, dic TEXT NULL,
            is_vat_payer INTEGER NOT NULL DEFAULT 1, is_identified INTEGER NOT NULL DEFAULT 0,
            taxpayer_type TEXT NULL, vat_period TEXT NULL, financial_office_code TEXT NULL,
            workplace_code TEXT NULL, data_box_id TEXT NULL, email TEXT NULL, phone TEXT NULL,
            cz_nace_code TEXT NULL, street_number_pop TEXT NULL, street_number_orient TEXT NULL,
            opr_jmeno TEXT NULL, opr_prijmeni TEXT NULL, opr_postaveni TEXT NULL,
            sest_jmeno TEXT NULL, sest_prijmeni TEXT NULL, sest_telefon TEXT NULL,
            sest_email TEXT NULL, sest_funkce TEXT NULL
        )");
        $this->pdo->exec("INSERT INTO supplier (id, company_name, country_id, ic, dic, is_vat_payer, taxpayer_type, financial_office_code)
            VALUES (1,'Test s.r.o.',1,'12345678','CZ12345678',1,'po','451')");
        $this->pdo->exec("CREATE TABLE supplier_vat_status_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, valid_from TEXT NOT NULL,
            status TEXT NOT NULL, note TEXT NULL, created_at TEXT NULL, created_by INTEGER NULL
        )");
        $this->pdo->exec("CREATE TABLE clients (
            id INTEGER PRIMARY KEY, company_name TEXT NOT NULL DEFAULT '', dic TEXT NULL, country_id INTEGER NULL
        )");
        $this->pdo->exec("INSERT INTO clients (id, company_name, dic, country_id) VALUES
            (200,'Odběratel CZ','CZ22222220',1), (201,'Dodavatel CZ','CZ33333330',1)");
        $this->pdo->exec("CREATE TABLE vat_classifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NULL, code TEXT NOT NULL, label TEXT NOT NULL,
            direction TEXT NOT NULL, dphdp3_line TEXT NULL, dphdp3_line_secondary TEXT NULL, kh_section TEXT NULL,
            vat_rate REAL NULL, is_reverse_charge INTEGER NOT NULL DEFAULT 0, kod_pred_pl TEXT NULL,
            kh_regime_code TEXT NULL, kh_bad_debt TEXT NULL, display_order INTEGER NOT NULL DEFAULT 0,
            archived INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE purchase_invoices (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, vendor_id INTEGER NOT NULL, varsymbol TEXT NULL,
            vendor_invoice_number TEXT NULL, document_kind TEXT NULL, issue_date TEXT NOT NULL, tax_date TEXT NULL,
            currency_id INTEGER NOT NULL, exchange_rate REAL NULL, reverse_charge INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'received', vat_classification_code TEXT NULL,
            vat_deduction TEXT NOT NULL DEFAULT 'full', vat_deduction_percent REAL NOT NULL DEFAULT 100,
            is_fixed_asset INTEGER NOT NULL DEFAULT 0, total_with_vat REAL NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE purchase_invoice_items (
            id INTEGER PRIMARY KEY, purchase_invoice_id INTEGER NOT NULL, vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL, total_without_vat REAL NOT NULL, total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL, is_fixed_asset INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE invoices (
            id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL, client_id INTEGER NULL, varsymbol TEXT NULL,
            issue_date TEXT NOT NULL, tax_date TEXT NULL, currency_id INTEGER NOT NULL, exchange_rate REAL NULL,
            reverse_charge INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'issued',
            invoice_type TEXT NOT NULL DEFAULT 'invoice', vat_classification_code TEXT NULL, total_with_vat REAL NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE invoice_items (
            id INTEGER PRIMARY KEY, invoice_id INTEGER NOT NULL, vat_rate_snapshot REAL NOT NULL,
            description TEXT NULL, total_without_vat REAL NOT NULL, total_vat REAL NOT NULL,
            vat_classification_code TEXT NULL, oss_applicable INTEGER NOT NULL DEFAULT 0
        )");
    }

    private function seed(): void
    {
        $rows = [
            ['1',  'Sale 21 %',              'sale',     '1',  null, 'A.4', 21.0, 0],
            ['40', 'Tuzemský odpočet 21 %',  'purchase', '40', null, 'B.2', 21.0, 0],
        ];
        $stmt = $this->pdo->prepare("INSERT INTO vat_classifications
            (supplier_id, code, label, direction, dphdp3_line, dphdp3_line_secondary, kh_section, vat_rate, is_reverse_charge, display_order, archived)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
        foreach ($rows as $r) $stmt->execute($r);
    }

    private function insertSale(int $id, string $date, float $base, float $vat, string $type, string $code): void
    {
        $this->pdo->prepare("INSERT INTO invoices (id, supplier_id, client_id, varsymbol, issue_date, tax_date, currency_id, exchange_rate, status, invoice_type, total_with_vat)
            VALUES (?, 1, 200, ?, ?, ?, 1, 1, 'issued', ?, ?)")
            ->execute([$id, 'FV' . $id, $date, $date, $type, $base + $vat]);
        $this->pdo->prepare("INSERT INTO invoice_items (id, invoice_id, vat_rate_snapshot, description, total_without_vat, total_vat, vat_classification_code)
            VALUES (?, ?, 21.0, 'plnění', ?, ?, ?)")
            ->execute([$id, $id, $base, $vat, $code]);
    }

    private function insertPurchase(int $id, string $date, float $base, float $vat, string $kind, string $code): void
    {
        $this->pdo->prepare("INSERT INTO purchase_invoices (id, supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind, issue_date, tax_date, currency_id, exchange_rate, reverse_charge, status, vat_classification_code, total_with_vat)
            VALUES (?, 1, 201, ?, ?, ?, ?, ?, 1, 1, 0, 'received', ?, ?)")
            ->execute([$id, 'PF' . $id, 'VD' . $id, $kind, $date, $date, $code, $base + $vat]);
        $this->pdo->prepare("INSERT INTO purchase_invoice_items (id, purchase_invoice_id, vat_rate_snapshot, description, total_without_vat, total_vat, vat_classification_code)
            VALUES (?, ?, 21.0, 'plnění', ?, ?, ?)")
            ->execute([$id, $id, $base, $vat, $code]);
    }
}
