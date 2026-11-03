<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use MyInvoice\Tests\Support\VatStatusFixture;

/**
 * Daňové termíny v dashboard "Akce pro tebe" respektují periodicitu DPH dodavatele
 * (supplier.vat_period + taxpayer_type). Regrese k issue #156:
 *   - Čtvrtletní plátce NESMÍ v půlce kvartálu dostat výzvu "DPH za uplynulý měsíc".
 *   - DPH za kvartál se ohlásí až po skončení kvartálu (Q2 → kolem 25. 7.).
 *   - KH se u právnické osoby (PO) podává VŽDY měsíčně → u čtvrtletní PO je KH
 *     samostatná měsíční položka oddělená od čtvrtletního DPH.
 *
 * Izolace: mění jen vat_period/taxpayer_type/is_vat_payer prvního dodavatele a
 * v tearDown je vrací zpět. userId = null → žádné dismissals. Soft-skip bez DB.
 */
#[Group('integration')]
final class CrmTaxDeadlineTest extends TestCase
{
    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;
    /** @var list<array{valid_from: string, status: string, note: ?string}> */
    private ?array $origVatHistory = null;
    private ?string $origTaxpayerType = null;
    private ?string $origVatPeriod = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->crm = $c->get(CrmAggregationService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $row = $pdo->query("SELECT taxpayer_type, vat_period FROM supplier WHERE id = {$this->supplierId}")
            ->fetch(\PDO::FETCH_ASSOC) ?: [];
        $this->origVatHistory = VatStatusFixture::snapshot($pdo, $this->supplierId);
        $this->origTaxpayerType = $row['taxpayer_type'] ?? null;
        $this->origVatPeriod = $row['vat_period'] ?? null;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->supplierId > 0) {
            $stmt = $this->db->pdo()->prepare('UPDATE supplier SET taxpayer_type = ?, vat_period = ? WHERE id = ?');
            $stmt->execute([$this->origTaxpayerType, $this->origVatPeriod, $this->supplierId]);
            if ($this->origVatHistory !== null) {
                VatStatusFixture::restore($this->db->pdo(), $this->supplierId, $this->origVatHistory);
            }
        }
    }

    private function configure(string $vatPeriod, string $taxpayerType): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE supplier SET vat_period = ?, taxpayer_type = ? WHERE id = ?');
        $stmt->execute([$vatPeriod, $taxpayerType, $this->supplierId]);
        VatStatusFixture::set($this->db->pdo(), $this->supplierId, true);
    }

    /** @return array<string, array<string,mixed>> položky daňových termínů klíčované typem */
    private function taxItems(string $today): array
    {
        $res = $this->crm->actionItems($this->supplierId, null, new \DateTimeImmutable($today));
        $out = [];
        foreach ($res['items'] as $item) {
            if (in_array($item['type'], ['tax_deadline', 'kh_deadline'], true)) {
                $out[(string) $item['type']] = $item;
            }
        }
        return $out;
    }

    public function testMonthlyZobraziDphAKhZaMesic(): void
    {
        $this->configure('monthly', 'po');
        $items = $this->taxItems('2026-06-19');
        self::assertArrayHasKey('tax_deadline', $items, 'Měsíční plátce dostane výzvu DPH+KH.');
        self::assertSame('DPH + KH za uplynulý měsíc', $items['tax_deadline']['title']);
        self::assertArrayNotHasKey('kh_deadline', $items, 'Měsíčně je KH sloučené s DPH, ne samostatně.');
    }

    public function testQuarterlyFoNezobraziVPuliKvartalu(): void
    {
        $this->configure('quarterly', 'fo');
        $items = $this->taxItems('2026-06-19');
        self::assertArrayNotHasKey('tax_deadline', $items, 'Čtvrtletní FO nemá v červnu výzvu k DPH.');
        self::assertArrayNotHasKey('kh_deadline', $items, 'Čtvrtletní FO nemá v červnu ani KH (kopíruje DPH periodu).');
    }

    public function testQuarterlyFoZobraziPoKonciKvartalu(): void
    {
        $this->configure('quarterly', 'fo');
        $items = $this->taxItems('2026-07-20'); // 5 dní do 25. 7.
        self::assertArrayHasKey('tax_deadline', $items, 'Čtvrtletní FO dostane výzvu po skončení Q2.');
        self::assertSame('DPH + KH za 2. čtvrtletí 2026', $items['tax_deadline']['title']);
        self::assertArrayNotHasKey('kh_deadline', $items, 'FO má KH sloučené s DPH (čtvrtletně).');
    }

    public function testQuarterlyPoMaKhMesicneOddeleneOdDph(): void
    {
        $this->configure('quarterly', 'po');

        // V půlce kvartálu: KH měsíčně ANO, DPH čtvrtletní NE.
        $june = $this->taxItems('2026-06-19');
        self::assertArrayHasKey('kh_deadline', $june, 'Čtvrtletní PO má KH každý měsíc.');
        self::assertSame('Kontrolní hlášení za uplynulý měsíc', $june['kh_deadline']['title']);
        self::assertArrayNotHasKey('tax_deadline', $june, 'DPH se u čtvrtletní PO v červnu nezobrazí.');

        // Po skončení kvartálu: obě položky (KH měsíční + DPH čtvrtletní).
        $july = $this->taxItems('2026-07-20');
        self::assertArrayHasKey('kh_deadline', $july, 'KH za červen.');
        self::assertArrayHasKey('tax_deadline', $july, 'DPH za Q2.');
        self::assertSame('DPH za 2. čtvrtletí 2026', $july['tax_deadline']['title']);
    }
}
