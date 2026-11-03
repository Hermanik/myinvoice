<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxProfileRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Příznak income_tax_exempt: vydaná faktura označená „osvobozeno od daně z příjmů"
 * se NEzapočítá do ročního příjmu (annualIncome → základ DPFO/DPPO i optimalizátor,
 * a tím ani do vyměřovacího základu SP/ZP), ale objeví se v annualExemptIncome
 * (transparentní „z toho vyloučeno"). DPH/tržby řeší jiné dotazy a nejsou dotčeny.
 *
 * Soft-skip bez cfg.php / DB (CI runner).
 */
#[Group('integration')]
final class IncomeTaxExemptTest extends TestCase
{
    private Connection $db;
    private TaxProfileRepository $repo;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    /** @var int[] */
    private array $created = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->repo = $c->get(TaxProfileRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        // ověř, že sloupec po migraci 0087 existuje (jinak skip)
        $hasCol = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'income_tax_exempt'")->fetch();
        if ($hasCol === false) {
            $this->markTestSkipped('Migrace 0087 (income_tax_exempt) neproběhla.');
        }
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->clientId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/client/CZK currency/user.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->created as $id) {
            $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
    }

    public function testExemptInvoiceIsExcludedFromIncomeButReportedSeparately(): void
    {
        // Rok daleko v budoucnu → izolace od reálných dat v testovací DB.
        $year = 2099;
        $base0 = $this->repo->annualIncome($this->supplierId, $year);
        $exempt0 = $this->repo->annualExemptIncome($this->supplierId, $year);

        $normalId = $this->insertPaidInvoice($year, 10000.0, 0);
        $exemptId = $this->insertPaidInvoice($year, 350000.0, 1);

        $base1 = $this->repo->annualIncome($this->supplierId, $year);
        $exempt1 = $this->repo->annualExemptIncome($this->supplierId, $year);

        // Do příjmu vstoupila jen NEosvobozená faktura (10 000), osvobozená (350 000) ne.
        self::assertEqualsWithDelta($base0 + 10000.0, $base1, 0.01, 'Osvobozená faktura se nesmí započítat do příjmu.');
        // Osvobozená částka se objeví v annualExemptIncome.
        self::assertEqualsWithDelta($exempt0 + 350000.0, $exempt1, 0.01, 'Osvobozená částka chybí v annualExemptIncome.');

        // sanity: bez příznaku by se 350 000 do příjmu započítalo
        $this->db->pdo()->prepare('UPDATE invoices SET income_tax_exempt = 0 WHERE id = ?')->execute([$exemptId]);
        $base2 = $this->repo->annualIncome($this->supplierId, $year);
        self::assertEqualsWithDelta($base1 + 350000.0, $base2, 0.01, 'Po zrušení příznaku se částka musí do příjmu vrátit.');

        unset($normalId); // jen pro čitelnost — úklid řeší tearDown přes $this->created
    }

    private function insertPaidInvoice(int $year, float $base, int $exempt): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, paid_at, total_without_vat, total_with_vat, income_tax_exempt, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?, ?, ?)"
        )->execute([
            $this->clientId, $this->supplierId,
            "$year-06-01", "$year-06-01", "$year-06-15",
            $this->currencyId, "$year-06-10 12:00:00",
            $base, $base, $exempt, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->created[] = $id;
        return $id;
    }
}
