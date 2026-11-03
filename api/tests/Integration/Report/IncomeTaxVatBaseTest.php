<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\IncomeTaxBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use MyInvoice\Tests\Support\VatStatusFixture;

/**
 * Daň z příjmů (DPFO/DPPO) — základ tržeb/nákladů musí respektovat plátcovství DPH.
 *
 * Pro plátce DPH je DPH průběžná položka vypořádaná zvlášť přiznáním k DPH, do
 * základu daně z příjmů nevstupuje → tržby i náklady BEZ DPH (total_without_vat).
 * Pro neplátce je DPH součástí ceny a odečíst ji nelze → částky VČETNĚ DPH
 * (total_with_vat). Pin proti regresi: dříve se vždy bralo total_with_vat.
 *
 * Vytvoří faktury + přijaté faktury v izolovaném roce (2098) pod existujícím
 * supplierem, přepne is_vat_payer pro oba scénáře a vše uklidí v tearDown
 * (vč. obnovení původního is_vat_payer).
 *
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class IncomeTaxVatBaseTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private IncomeTaxBuilder $builder;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    /** @var list<array{valid_from: string, status: string, note: ?string}> */
    private ?array $origVatHistory = null;

    private int $customerId = 0;
    private int $vendorId = 0;
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $purchaseIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection (CI runner skipne).');
        }
        try {
            $container     = Bootstrap::buildApp()->getContainer();
            $this->db      = $container->get(Connection::class);
            $this->builder = new IncomeTaxBuilder($this->db);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = $this->countryId('CZ');

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $this->origVatHistory = VatStatusFixture::snapshot($pdo, $this->supplierId);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ([$this->customerId, $this->vendorId] as $id) {
            if ($id) {
                $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
            }
        }
        // Restore the supplier's original VAT status history.
        if ($this->origVatHistory !== null) {
            VatStatusFixture::restore($pdo, $this->supplierId, $this->origVatHistory);
        }
        $this->db->close();
    }

    public function testRevenueAndCostsBaseDependsOnVatPayerStatus(): void
    {
        $this->customerId = $this->client('Odběratel DPI', customer: true);
        $this->vendorId   = $this->client('Dodavatel DPI', vendor: true);

        $d = fn (int $m) => sprintf('%04d-%02d-15', self::YEAR, $m);

        // Vydané (tržby): základ 100 000 + DPH 21 000 = 121 000 vč. DPH.
        $this->sale('2098010001', $this->customerId, $d(1), [[60000, 12600, 21]]);
        $this->sale('2098020001', $this->customerId, $d(2), [[40000, 8400, 21]]);
        // Přijaté (náklady): základ 30 000 + DPH 6 300 = 36 300 vč. DPH.
        $this->purchase('P-2098-01', $this->vendorId, $d(3), [[30000, 6300, 21]]);

        $expectedBaseRevenue  = 100000.0; // bez DPH
        $expectedGrossRevenue = 121000.0; // vč. DPH
        $expectedBaseCosts    = 30000.0;
        $expectedGrossCosts   = 36300.0;

        // ── Plátce DPH → částky BEZ DPH ──────────────────────────────────────
        $this->setVatPayer(true);
        $vat = $this->builder->build($this->supplierId, self::YEAR, 'fo')['summary'];
        // Electronic filing (§ 136/2 a) DŘ, 1 May moved past the holiday) is the main deadline, paper filing (1 April) the second one.
        self::assertSame('2099-05-04', $vat['submission_deadline']);
        self::assertSame('2099-04-01', $vat['paper_deadline']);
        $this->assertTrue($vat['is_vat_payer'], 'summary.is_vat_payer musí být true pro plátce');
        $this->assertEqualsWithDelta($expectedBaseRevenue, $vat['revenue_orientacni'], 0.01,
            'Plátce DPH: tržby BEZ DPH (total_without_vat)');
        $this->assertEqualsWithDelta($expectedBaseCosts, $vat['costs_orientacni'], 0.01,
            'Plátce DPH: náklady BEZ DPH (total_without_vat)');
        $this->assertEqualsWithDelta(
            $expectedBaseRevenue - $expectedBaseCosts, $vat['profit_orientacni'], 0.01);

        // ── Neplátce → částky VČETNĚ DPH ─────────────────────────────────────
        $this->setVatPayer(false);
        $non = $this->builder->build($this->supplierId, self::YEAR, 'fo')['summary'];
        $this->assertFalse($non['is_vat_payer'], 'summary.is_vat_payer musí být false pro neplátce');
        $this->assertEqualsWithDelta($expectedGrossRevenue, $non['revenue_orientacni'], 0.01,
            'Neplátce: tržby VČETNĚ DPH (total_with_vat)');
        $this->assertEqualsWithDelta($expectedGrossCosts, $non['costs_orientacni'], 0.01,
            'Neplátce: náklady VČETNĚ DPH (total_with_vat)');
        $this->assertEqualsWithDelta(
            $expectedGrossRevenue - $expectedGrossCosts, $non['profit_orientacni'], 0.01);
    }

    /**
     * Daňově neuznatelný náklad (tax_deductible=0, např. reprezentace) se NEzahrne
     * do nákladů v dani z příjmů. Nezávislé na DPH.
     */
    public function testNonDeductibleCostExcludedFromIncomeTax(): void
    {
        $this->customerId = $this->client('Odběratel ND', customer: true);
        $this->vendorId   = $this->client('Dodavatel ND', vendor: true);
        $d = fn (int $m) => sprintf('%04d-%02d-15', self::YEAR, $m);

        $this->purchase('P-2098-10', $this->vendorId, $d(3), [[30000, 6300, 21]]);                       // uznatelný
        $this->purchase('P-2098-11', $this->vendorId, $d(4), [[5000, 1050, 21]], taxDeductible: false);  // neuznatelný

        $this->setVatPayer(true);
        $sum = $this->builder->build($this->supplierId, self::YEAR, 'fo')['summary'];
        $this->assertEqualsWithDelta(30000.0, $sum['costs_orientacni'], 0.01,
            'Daňově neuznatelný náklad (tax_deductible=0) se nezahrne do nákladů daně z příjmů');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function setVatPayer(bool $payer): void
    {
        VatStatusFixture::set($this->db->pdo(), $this->supplierId, $payer);
    }

    private function countryId(string $iso2): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$iso2]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function client(string $name, bool $customer = false, bool $vendor = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "CZ11111118", "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    private function sale(string $varsymbol, int $clientId, string $date, array $items): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?)'
        );
        $stmt->execute([
            $this->supplierId, $varsymbol, $clientId, $date, $date, $date,
            $this->currencyId, $base, $vat, $with, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->insertItems('invoice_items', 'invoice_id', $id, $items);
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    private function purchase(string $number, int $vendorId, string $date, array $items, bool $taxDeductible = true): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, tax_deductible, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, "received", ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date,
            $this->currencyId, $base, $vat, $with, $taxDeductible ? 1 : 0, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;
        $this->insertItems('purchase_invoice_items', 'purchase_invoice_id', $id, $items);
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items
     * @return array{0:float,1:float,2:float} [base, vat, with]
     */
    private function sumItems(array $items): array
    {
        $base = 0.0; $vat = 0.0;
        foreach ($items as $it) { $base += $it[0]; $vat += $it[1]; }
        return [$base, $vat, $base + $vat];
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items
     */
    private function insertItems(string $table, string $fk, int $id, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => $it) {
            [$base, $vat, $snapshot] = $it;
            $stmt->execute([$id, $base, $this->vatRateId, $snapshot, $base, $vat, $base + $vat, $i]);
        }
    }
}
