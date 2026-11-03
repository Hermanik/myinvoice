<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use MyInvoice\Tests\Support\VatStatusFixture;

/**
 * Identifikovaná osoba (§ 6g–6l ZDPH, issue #94) v DPH výkazech.
 *
 * Kontrakt DPHDP3 typu I:
 *   - VetaD typ_platce='I', vždy měsíčně (kvartální volba se ignoruje s warningem)
 *   - jen řádky samovyměření z přeshraničních přijatých plnění (ř. 3-6, 12-13)
 *   - ŽÁDNÁ Veta4 — bez nároku na odpočet, zrcadlový ř. 43 z klasifikace se
 *     tiše zahodí (to JE pointa režimu), daň se platí reálně (Veta6 dano_da)
 *   - tuzemské/jiné řádky (ř. 1, oddíl C…) se zahodí s warningem
 *   - KH: warning, že IO kontrolní hlášení nepodává
 *
 * Test dočasně přepne první supplier na neplátce+IO; tearDown vrací původní
 * hodnoty. Izolované období 2097-06 (KhDphTaxScenariosTest používá 2099).
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class IdentifiedPersonDphTest extends TestCase
{
    private const YEAR = 2097;
    private const MONTH = 6;

    private Connection $db;
    private DphPriznaniBuilder $dph;
    private KontrolniHlaseniBuilder $kh;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $deId = 0;
    /** @var list<array{valid_from: string, status: string, note: ?string}> */
    private array $origVatHistory = [];

    /** @var int[] */
    private array $clientIds = [];
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
            $container = Bootstrap::buildApp()->getContainer();
            $this->db  = $container->get(Connection::class);
            $this->dph = $container->get(DphPriznaniBuilder::class);
            $this->kh  = $container->get(KontrolniHlaseniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $row = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->supplierId = (int) ($row['id'] ?? 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = $this->countryId('CZ');
        $this->deId = $this->countryId('DE');
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0 || $this->deId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }

        $this->origVatHistory = VatStatusFixture::snapshot($pdo, $this->supplierId);
        VatStatusFixture::set($pdo, $this->supplierId, false, true);
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
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        VatStatusFixture::restore($pdo, $this->supplierId, $this->origVatHistory);
        $this->db->close();
    }

    public function testIdentifiedPersonDphPriznaniTypeI(): void
    {
        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $euVend = $this->client('EU dodavatel IO', $this->deId, 'DE303030303', vendor: true);
        $czCust = $this->client('CZ odběratel IO', $this->czId, 'CZ40404044', customer: true);

        // Přijetí služby z EU (kód 24 → ř.12*, RC z kódu) — samovyměření 10000 × 21 %.
        // (* kód 24 mapuje na ř.12 — vědomá konvence projektu, viz session_state.)
        $this->purchase('IO-2097-001', $euVend, '24', false, $d(10), $d(10), [[10000, 0, 21]]);
        // Pořízení zboží z JČS (kód 23 → ř.3, RC) — samovyměření 8000 × 21 %.
        $this->purchase('IO-2097-002', $euVend, '23', true, $d(11), $d(11), [[8000, 0, 21]]);
        // Tuzemská vystavená s (chybnou) klasifikací kódu 1 — IO ji nevyplňuje,
        // musí vypadnout s warningem a NEsmí se objevit na ř.1.
        $this->sale('2097060001', $czCust, '1', $d(12), $d(12), [[5000, 1050, 21]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $dp = (new \SimpleXMLElement($result['xml']))->DPHDP3;

        // ── VetaD: typ_platce I ──
        self::assertSame('I', (string) $dp->VetaD['typ_platce'], 'IO podává přiznání typu I');
        self::assertSame('I', (string) $result['summary']['typ_platce']);

        // ── Veta1: jen samovyměření (ř.3 + ř.12), žádný tuzemský výstup ř.1 ──
        self::assertSame('8000', (string) $dp->Veta1['p_zb23'], 'ř.3 pořízení zboží z JČS');
        self::assertSame('1680', (string) $dp->Veta1['dan_pzb23'], 'ř.3 samovyměřená daň 8000 × 21 %');
        self::assertSame('10000', (string) $dp->Veta1['p_sl23_z'], 'ř.12 přijetí služby');
        self::assertSame('2100', (string) $dp->Veta1['dan_psl23_z'], 'ř.12 samovyměřená daň');
        self::assertSame('', (string) $dp->Veta1['obrat23'], 'ř.1 tuzemský výstup IO nevyplňuje');

        // ── ŽÁDNÁ Veta4: bez nároku na odpočet (zrcadlový ř.43 zahozen) ──
        self::assertCount(0, $dp->Veta4, 'IO nemá nárok na odpočet — Veta4 nesmí existovat');

        // ── Veta6: daň se platí reálně (žádný odpočet ji nevynuluje) ──
        self::assertSame('3780', (string) $dp->Veta6['dan_zocelk'], 'ř.62 = 1680 + 2100');
        self::assertSame('0', (string) $dp->Veta6['odp_zocelk'], 'ř.63 odpočet = 0');
        self::assertSame('3780', (string) $dp->Veta6['dano_da'], 'ř.64 vlastní daň = plné samovyměření');

        // ── Warnings: IO info + vyhozený tuzemský řádek; ř.43 BEZ warningu (tichý) ──
        $warnings = implode("\n", $result['warnings']);
        self::assertStringContainsString('identifikované osoby', $warnings, 'IO info warning');
        self::assertStringContainsString('Řádek 1', $warnings, 'vyhozený tuzemský ř.1 hlásí warning');
        self::assertStringNotContainsString('Řádek 43', $warnings, 'tiché zahození mirror ř.43 (očekávané chování, ne chyba)');
    }

    public function testIdentifiedPersonAlwaysMonthly(): void
    {
        $result = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'quarterly');
        self::assertSame('monthly', $result['summary']['period_type'], 'IO podává vždy měsíčně');
        self::assertStringContainsString('kalendářní měsíc', implode("\n", $result['warnings']));
        // VetaD musí mít mesic, ne ctvrt.
        $dp = (new \SimpleXMLElement($result['xml']))->DPHDP3;
        self::assertSame((string) self::MONTH, (string) $dp->VetaD['mesic']);
        self::assertSame('', (string) $dp->VetaD['ctvrt']);
    }

    public function testKontrolniHlaseniWarnsIdentifiedPersonNeverFiles(): void
    {
        $result = $this->kh->build($this->supplierId, self::YEAR, self::MONTH);
        self::assertStringContainsString(
            'Identifikovaná osoba kontrolní hlášení nepodává',
            implode("\n", $result['warnings']),
        );
    }

    public function testPayerKeepsTypePAndDeduction(): void
    {
        // Kontrola, že IO režim NEprosákl plátcům: přepni zpět na plátce a ověř P + Veta4.
        $pdo = $this->db->pdo();
        VatStatusFixture::set($pdo, $this->supplierId, true);

        $d = fn (int $day) => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
        $euVend = $this->client('EU dodavatel plátce', $this->deId, 'DE505050505', vendor: true);
        $this->purchase('IO-2097-100', $euVend, '23', true, $d(10), $d(10), [[8000, 0, 21]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $dp = (new \SimpleXMLElement($result['xml']))->DPHDP3;
        self::assertSame('P', (string) $dp->VetaD['typ_platce']);
        self::assertSame('8000', (string) $dp->Veta4['nar_zdp23'], 'plátce má zrcadlový odpočet ř.43 (nar_zdp23, ne odp_rezim)');
        self::assertSame('1680', (string) $dp->Veta4['odp_sum_nar'], 'plátce má ř.46 součtový odpočet = ř.43');
    }

    // ── helpers (vzor KhDphTaxScenariosTest) ─────────────────────────────────

    private function countryId(string $iso2): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM countries WHERE iso2 = ? LIMIT 1');
        $stmt->execute([$iso2]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function client(string $name, int $countryId, ?string $dic, bool $customer = false, bool $vendor = false): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /** @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot] */
    private function sale(string $varsymbol, int $clientId, ?string $code, string $issue, string $tax, array $items): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $varsymbol, $clientId, $issue, $tax, $issue,
            $this->currencyId, $base, $vat, $with, $code, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->insertItems('invoice_items', 'invoice_id', $id, $items);
    }

    /** @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot] */
    private function purchase(string $number, int $vendorId, ?string $code, bool $rc, string $issue, ?string $tax, array $items): void
    {
        [$base, $vat, $with] = $this->sumItems($items);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, ?, "{}", ?, ?, ?, "received", ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $issue, $tax, $issue, $issue,
            $this->currencyId, $rc ? 1 : 0, $base, $vat, $with, $code, $this->userId,
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

    /** @param list<array{0:float,1:float,2:float}> $items */
    private function insertItems(string $table, string $fk, int $id, array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka IO', 1, 'ks', ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $i => $it) {
            [$base, $vat, $snapshot] = $it;
            $stmt->execute([$id, $base, $this->vatRateId, $snapshot, $base, $vat, $base + $vat, $i]);
        }
    }
}
