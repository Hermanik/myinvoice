<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Tests\Support\VatStatusFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CRM tržby/náklady/zisk se prezentují BEZ DPH pro plátce (DPH je průběžná položka)
 * a S DPH pro neplátce (nemůže si vstupní DPH odečíst). Regrese k auditu 4.19.2.
 *
 * Izolace: doklady v roce 2000 (mimo dnešní okna), asertuje se DELTA proti baseline,
 * takže případná reálná data testovací DB nevadí. Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class CrmVatBasisTest extends TestCase
{
    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    /** @var list<array{valid_from: string, status: string, note: ?string}> */
    private array $origVatHistory = [];
    /** @var int[] */
    private array $createdInvoices = [];
    /** @var int[] */
    private array $createdPurchases = [];

    private const D = '2000-06-15';

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
        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/CZK currency/user.');
        }
        $this->origVatHistory = VatStatusFixture::snapshot($pdo, $this->supplierId);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            VatStatusFixture::restore($this->db->pdo(), $this->supplierId, $this->origVatHistory);
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->createdInvoices as $id) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdPurchases as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        $this->createdInvoices = [];
        $this->createdPurchases = [];
    }

    private function setVatPayer(bool $payer): void
    {
        VatStatusFixture::set($this->db->pdo(), $this->supplierId, $payer);
    }

    private function insertInvoice(float $net, float $gross): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?)"
        )->execute([
            'CRMTEST2000', $this->clientId, $this->supplierId,
            self::D, self::D, self::D, $this->currencyId, $net, $gross, $this->userId,
        ]);
        $this->createdInvoices[] = (int) $pdo->lastInsertId();
    }

    private function insertPurchase(float $net, float $gross): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot,
                 status, total_without_vat, total_with_vat, created_by)
             VALUES (?, ?, 'CRMTEST2000', 'CRMTEST2000', 'invoice', ?, ?, ?, ?, ?, '{}', 'received', ?, ?, ?)"
        )->execute([
            $this->supplierId, $this->clientId, self::D, self::D, self::D, self::D,
            $this->currencyId, $net, $gross, $this->userId,
        ]);
        $this->createdPurchases[] = (int) $pdo->lastInsertId();
    }

    /** Vrátí řádek roku 2000 v CZK z yearlyHistory, nebo nuly. */
    private function year2000(): array
    {
        foreach ($this->crm->yearlyHistory($this->supplierId, 'CZK') as $r) {
            if ((int) $r['year'] === 2000) {
                return ['revenue' => (float) $r['revenue'], 'costs' => (float) $r['costs'], 'profit' => (float) $r['profit']];
            }
        }
        return ['revenue' => 0.0, 'costs' => 0.0, 'profit' => 0.0];
    }

    public function testPlatceVidiTrzbyANakladyBezDph(): void
    {
        $this->setVatPayer(true);
        $base = $this->year2000();

        $this->insertInvoice(1000.0, 1210.0);
        $this->insertPurchase(500.0, 605.0);

        $after = $this->year2000();
        self::assertEqualsWithDelta($base['revenue'] + 1000.0, $after['revenue'], 0.01, 'Plátce: tržby bez DPH (net), ne 1210');
        self::assertEqualsWithDelta($base['costs'] + 500.0, $after['costs'], 0.01, 'Plátce: náklady bez DPH (net), ne 605');
        self::assertEqualsWithDelta(($base['revenue'] + 1000.0) - ($base['costs'] + 500.0), $after['profit'], 0.01, 'Zisk = net tržby − net náklady');
    }

    public function testNeplatceVidiTrzbyANakladySDph(): void
    {
        $this->setVatPayer(false);
        $base = $this->year2000();

        $this->insertInvoice(1000.0, 1210.0);
        $this->insertPurchase(500.0, 605.0);

        $after = $this->year2000();
        self::assertEqualsWithDelta($base['revenue'] + 1210.0, $after['revenue'], 0.01, 'Neplátce: tržby s DPH (gross)');
        self::assertEqualsWithDelta($base['costs'] + 605.0, $after['costs'], 0.01, 'Neplátce: náklady s DPH (gross)');
    }
}
