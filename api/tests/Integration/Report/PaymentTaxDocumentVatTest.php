<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use MyInvoice\Tests\Support\VatStatusFixture;

/**
 * Daňová správnost částečných úhrad záloh (#89) end-to-end:
 *
 *   1. Zálohová faktura (proforma) 17 700 Kč ve dvou sazbách (21 % + 12 %) —
 *      NIKDY nevstupuje do DPH evidence.
 *   2. Částečná platba 6 000 Kč (září) → daňový doklad k přijaté platbě
 *      (invoice_type='tax_document'): DUZP = den platby, platba rozdělená mezi
 *      sazby poměrně dle brutto vah, DPH SHORA koeficientem (§ 37). Po vystavení
 *      vstupuje do Knihy DPH / DPHDP3 (ř.1 + ř.2) / KH (A.5) za ZÁŘÍ.
 *   3. Doplatek 11 700 Kč (říjen) → proforma paid → finální doklad (§ 37a):
 *      plné položky + záporné odpočtové řádky za daňový doklad per sazba.
 *      Říjnové výkazy nesou jen ZBYTEK základů/daní.
 *   4. Invariant: září + říjen = přesně původní základy a daně proformy
 *      (žádné dvojí zdanění, žádný výpadek).
 *
 * Izolace: období 09–10/2099, vlastní klient + doklady, úklid v tearDown.
 * Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class PaymentTaxDocumentVatTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private InvoicePaymentService $payments;
    private PaymentTaxDocumentCreator $taxDocCreator;
    private FinalFromProformaCreator $finalCreator;
    private DphBookBuilder $book;
    private DphPriznaniBuilder $dph;
    private KontrolniHlaseniBuilder $kh;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    /** @var int[] vytvořené faktury — mažou se v opačném pořadí (děti dřív) */
    private array $invoiceIds = [];
    private ?array $origVatFlags = null;
    /** @var list<array{valid_from: string, status: string, note: ?string}> */
    private array $origVatHistory = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db            = $c->get(Connection::class);
            $this->payments      = $c->get(InvoicePaymentService::class);
            $this->taxDocCreator = $c->get(PaymentTaxDocumentCreator::class);
            $this->finalCreator  = $c->get(FinalFromProformaCreator::class);
            $this->book          = $c->get(DphBookBuilder::class);
            $this->dph           = $c->get(DphPriznaniBuilder::class);
            $this->kh            = $c->get(KontrolniHlaseniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user).');
        }

        // Daňový doklad k platbě vystavuje jen plátce — vynutit a v tearDown vrátit.
        $this->origVatFlags = $pdo->query(
            "SELECT is_vat_payer, is_identified FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->origVatHistory = VatStatusFixture::snapshot($pdo, $this->supplierId);
        VatStatusFixture::set($pdo, $this->supplierId, true);

        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $stmt = $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer)
             VALUES (?, "Záloha odběratel #89", "Test 1", "Praha", "11000", ?, "CZ11111118",
                     "test89@example.com", "cs", ?, 1)'
        );
        $stmt->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origVatFlags !== null && $this->supplierId > 0) {
            VatStatusFixture::restore($pdo, $this->supplierId, $this->origVatHistory);
        }
        // Děti (final, tax_document) dřív než proforma; invoice_payments mažou FK cascade.
        foreach (array_reverse($this->invoiceIds) as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    public function testPartialAdvancePaymentTaxFlowAcrossPeriods(): void
    {
        $pdo = $this->db->pdo();

        // ── 1. Proforma 17 700 (10 000 @21 % + 5 000 @12 %), vystavená v září ──
        $proformaId = $this->seedProforma('2099090901', '2099-09-01', [
            [10000.00, 2100.00, 21.0],
            [5000.00, 600.00, 12.0],
        ]);

        // ── 2. Částečná platba 6 000 dne 15. 9. ──
        $rec = $this->payments->recordPayment($proformaId, 6000.00, '2099-09-15', ['source' => 'manual']);
        self::assertFalse($rec['became_paid'], 'Podplatba nesmí proformu překlopit na paid.');
        self::assertSame(11700.00, $rec['remaining']);
        self::assertSame('issued', $this->col($proformaId, 'status'), 'Částečně uhrazená proforma zůstává pohledávkou.');
        self::assertSame(6000.00, (float) $this->col($proformaId, 'paid_total'));

        // ── 3. Daňový doklad k přijaté platbě ──
        $taxDocId = $this->taxDocCreator->createForPayment($rec['payment_id'], $this->userId);
        $this->invoiceIds[] = $taxDocId;

        self::assertSame($taxDocId, $this->taxDocCreator->createForPayment($rec['payment_id'], $this->userId),
            'Opakované volání musí být idempotentní (vrací existující doklad).');

        $td = $pdo->query("SELECT * FROM invoices WHERE id = {$taxDocId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('tax_document', $td['invoice_type']);
        self::assertSame('draft', $td['status']);
        self::assertSame((string) $proformaId, (string) $td['parent_invoice_id']);
        self::assertSame('2099-09-15', $td['tax_date'], 'DUZP = den přijetí úplaty.');
        self::assertSame(1, (int) $td['prices_include_vat'], 'DPH shora koeficientem (§ 37).');
        self::assertEqualsWithDelta(6000.00, (float) $td['total_with_vat'], 0.001, 'Brutto dokladu = přijatá platba.');
        self::assertEqualsWithDelta(0.0, (float) $td['amount_to_pay'], 0.001, 'Doklad je platbou pokrytý.');

        // Položky per sazba: 6000 × 12100/17700 = 4101,69 @21 %; 6000 × 5600/17700 = 1898,31 @12 %.
        $tdItems = $pdo->query(
            "SELECT vat_rate_snapshot, total_without_vat, total_vat, total_with_vat
               FROM invoice_items WHERE invoice_id = {$taxDocId} ORDER BY vat_rate_snapshot DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $tdItems);
        self::assertEqualsWithDelta(4101.69, (float) $tdItems[0]['total_with_vat'], 0.001);
        self::assertEqualsWithDelta(711.86, (float) $tdItems[0]['total_vat'], 0.001, '4101,69 × 21/121 koeficient');
        self::assertEqualsWithDelta(1898.31, (float) $tdItems[1]['total_with_vat'], 0.001);
        self::assertEqualsWithDelta(203.39, (float) $tdItems[1]['total_vat'], 0.001, '1898,31 × 12/112 koeficient');

        // „Vystavení" dokladu (ledger ignoruje drafty) — číslo + status paid (auto-paid sémantika).
        $pdo->prepare("UPDATE invoices SET varsymbol = '2099090902', status = 'paid', paid_at = tax_date WHERE id = ?")
            ->execute([$taxDocId]);

        // ── ZÁŘÍ: doklad k platbě JE v evidenci, proforma NE ──
        $sep = $this->bookSections(9);
        self::assertArrayHasKey('36.001', $sep, 'ř.1 (21 %) za září');
        self::assertEqualsWithDelta(3389.83, $sep['36.001']['subtotal_base'], 0.01);
        self::assertEqualsWithDelta(711.86, $sep['36.001']['subtotal_vat'], 0.01);
        self::assertArrayHasKey('36.002', $sep, 'ř.2 (12 %) za září');
        self::assertEqualsWithDelta(1694.92, $sep['36.002']['subtotal_base'], 0.01);
        self::assertEqualsWithDelta(203.39, $sep['36.002']['subtotal_vat'], 0.01);
        foreach ($sep as $section) {
            foreach ($section['rows'] as $row) {
                self::assertNotSame('2099090901', (string) $row['doc_number'],
                    'Proforma NIKDY nesmí vstoupit do Knihy DPH.');
            }
        }

        $dpSep = (new \SimpleXMLElement($this->dph->build($this->supplierId, self::YEAR, 9, 'monthly')['xml']))->DPHDP3;
        self::assertSame('3390', (string) $dpSep->Veta1['obrat23'], 'DPHDP3/09 ř.1 základ (zaokr.)');
        self::assertSame('712', (string) $dpSep->Veta1['dan23'], 'DPHDP3/09 ř.1 daň');
        self::assertSame('1695', (string) $dpSep->Veta1['obrat5'], 'DPHDP3/09 ř.2 základ (snížená)');
        self::assertSame('203', (string) $dpSep->Veta1['dan5'], 'DPHDP3/09 ř.2 daň');

        // KH září: 6 000 vč. DPH pod limitem 10 000 → A.5 sumace v obou sazbách.
        $khSep = new \SimpleXMLElement($this->kh->build($this->supplierId, self::YEAR, 9)['xml']);
        self::assertCount(0, $khSep->DPHKH1->VetaA4, 'KH/09: pod limitem → nic v A.4');
        self::assertSame('3389.83', (string) $khSep->DPHKH1->VetaA5['zakl_dane1']);
        self::assertSame('711.86', (string) $khSep->DPHKH1->VetaA5['dan1']);
        self::assertSame('1694.92', (string) $khSep->DPHKH1->VetaA5['zakl_dane2']);
        self::assertSame('203.39', (string) $khSep->DPHKH1->VetaA5['dan2']);

        // ── 4. Doplatek 11 700 dne 3. 10. → proforma paid ──
        $rec2 = $this->payments->recordPayment($proformaId, 11700.00, '2099-10-03', ['source' => 'manual']);
        self::assertTrue($rec2['became_paid']);
        self::assertSame('paid', $this->col($proformaId, 'status'));
        self::assertSame('2099-10-03', $this->col($proformaId, 'paid_at'), 'paid_at = datum poslední platby.');

        // ── 5. Finální doklad (§ 37a) s DUZP 5. 10. ──
        $finalId = $this->finalCreator->create($proformaId, $this->userId, '2099-10-05', '2099-10-05');
        $this->invoiceIds[] = $finalId;

        $fin = $pdo->query("SELECT * FROM invoices WHERE id = {$finalId}")->fetch(PDO::FETCH_ASSOC);
        self::assertEqualsWithDelta(11700.00, (float) $fin['advance_paid_amount'], 0.001,
            'Odpočet „zálohy" = platby BEZ daňového dokladu (17 700 − 6 000).');
        self::assertEqualsWithDelta(11700.00, (float) $fin['total_with_vat'], 0.001,
            'Brutto finálu = zbytek po odpočtových řádcích.');
        self::assertEqualsWithDelta(0.0, (float) $fin['amount_to_pay'], 0.001);

        $finItems = $pdo->query(
            "SELECT description, vat_rate_snapshot, total_without_vat, total_vat
               FROM invoice_items WHERE invoice_id = {$finalId} ORDER BY order_index"
        )->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(4, $finItems, '2 původní položky + 2 záporné odpočtové řádky per sazba.');
        self::assertStringContainsString('2099090902', (string) $finItems[2]['description'],
            'Odpočtový řádek odkazuje číslo daňového dokladu.');
        self::assertEqualsWithDelta(-3389.83, (float) $finItems[2]['total_without_vat'], 0.01);
        self::assertEqualsWithDelta(-711.86, (float) $finItems[2]['total_vat'], 0.05,
            '§ 37a: daň z rozdílu základů (haléřová odchylka proti koeficientu je legální)');
        self::assertEqualsWithDelta(-1694.92, (float) $finItems[3]['total_without_vat'], 0.01);

        // „Vystavení" finálu.
        $pdo->prepare("UPDATE invoices SET varsymbol = '2099100903', status = 'paid', paid_at = '2099-10-05' WHERE id = ?")
            ->execute([$finalId]);

        // ── ŘÍJEN: jen zbytek základů/daní ──
        $oct = $this->bookSections(10);
        self::assertEqualsWithDelta(10000.00 - 3389.83, $oct['36.001']['subtotal_base'], 0.02);
        self::assertEqualsWithDelta(2100.00 - 711.86, $oct['36.001']['subtotal_vat'], 0.05);
        self::assertEqualsWithDelta(5000.00 - 1694.92, $oct['36.002']['subtotal_base'], 0.02);
        self::assertEqualsWithDelta(600.00 - 203.39, $oct['36.002']['subtotal_vat'], 0.05);

        // ── Invariant: září + říjen = přesně proforma (žádné dvojí zdanění/výpadek) ──
        $base21 = $sep['36.001']['subtotal_base'] + $oct['36.001']['subtotal_base'];
        $vat21  = $sep['36.001']['subtotal_vat'] + $oct['36.001']['subtotal_vat'];
        $base12 = $sep['36.002']['subtotal_base'] + $oct['36.002']['subtotal_base'];
        $vat12  = $sep['36.002']['subtotal_vat'] + $oct['36.002']['subtotal_vat'];
        self::assertEqualsWithDelta(10000.00, $base21, 0.02, 'Σ základ 21 % = původní plnění');
        self::assertEqualsWithDelta(2100.00, $vat21, 0.05, 'Σ daň 21 % = původní daň (± § 37a halíře)');
        self::assertEqualsWithDelta(5000.00, $base12, 0.02, 'Σ základ 12 % = původní plnění');
        self::assertEqualsWithDelta(600.00, $vat12, 0.05, 'Σ daň 12 % = původní daň');
    }

    public function testReverseChargeProformaRefusesPaymentTaxDocument(): void
    {
        $proformaId = $this->seedProforma('2099090911', '2099-09-02', [[8000.00, 0.00, 21.0]], reverseCharge: true);
        $rec = $this->payments->recordPayment($proformaId, 1000.00, '2099-09-10', ['source' => 'manual']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/přenesené daňové povinnosti/');
        $this->taxDocCreator->createForPayment($rec['payment_id'], $this->userId);
    }

    public function testDeletePaymentRevertsPaidProforma(): void
    {
        $proformaId = $this->seedProforma('2099090921', '2099-09-03', [[1000.00, 210.00, 21.0]]);
        $rec = $this->payments->recordPayment($proformaId, 1210.00, '2099-09-12', ['source' => 'manual']);
        self::assertTrue($rec['became_paid']);
        self::assertSame('paid', $this->col($proformaId, 'status'));

        $res = $this->payments->deletePayment($rec['payment_id']);
        self::assertTrue($res['became_unpaid']);
        self::assertSame('issued', $this->col($proformaId, 'status'), 'Smazání platby vrací doklad mezi pohledávky.');
        self::assertSame(0.0, (float) $this->col($proformaId, 'paid_total'));
        self::assertNull($this->col($proformaId, 'paid_at'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param list<array{0:float,1:float,2:float}> $items [base, vat, rate] */
    private function seedProforma(string $varsymbol, string $issueDate, array $items, bool $reverseCharge = false): int
    {
        $pdo = $this->db->pdo();
        $base = 0.0;
        $vat = 0.0;
        foreach ($items as $it) {
            $base += $it[0];
            $vat += $it[1];
        }
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, created_by)
             VALUES (?, ?, "proforma", ?, ?, NULL, ?, ?, ?, ?, ?, ?, "issued", ?)'
        )->execute([
            $this->supplierId, $varsymbol, $this->clientId, $issueDate, $issueDate,
            $this->currencyId, $reverseCharge ? 1 : 0, $base, $vat, $base + $vat, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Zálohová položka", 1, "ks", ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $i => $it) {
            [$b, $v, $rate] = $it;
            $stmt->execute([$id, $b, $this->vatRateId, $rate, $b, $v, $b + $v, $i]);
        }
        return $id;
    }

    private function col(int $invoiceId, string $column): mixed
    {
        return $this->db->pdo()
            ->query("SELECT {$column} FROM invoices WHERE id = {$invoiceId}")
            ->fetchColumn();
    }

    /** @return array<string, array<string,mixed>> sekce Knihy DPH dle key */
    private function bookSections(int $month): array
    {
        $book = $this->book->build($this->supplierId, self::YEAR, $month);
        $sec = [];
        foreach ($book['sections'] as $s) {
            $sec[$s['key']] = $s;
        }
        return $sec;
    }
}
