<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Crm;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Crm\CrmAggregationService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use MyInvoice\Tests\Support\VatStatusFixture;

/**
 * „Dopředné" tržby aktuálního měsíce v overview()['current_month_pipeline']:
 *   - koncepty: vydané faktury ve stavu draft (NEsmí být v ostrých tržbách)
 *   - nespárované proformy: otevřené proformy bez dceřiného ostrého dokladu
 *
 * Doklady jsou datované DNES (aktuální měsíc), takže se reálná data testovací DB
 * řeší DELTou proti baseline. Soft-skip bez cfg.php / DB.
 */
#[Group('integration')]
final class CrmPipelineTest extends TestCase
{
    private Connection $db;
    private CrmAggregationService $crm;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    /** @var list<array{valid_from: string, status: string, note: ?string}> */
    private ?array $origVatHistory = null;
    /** @var int[] */
    private array $createdInvoices = [];

    private string $today;
    private int $vsSeq = 0;

    protected function setUp(): void
    {
        $this->today = (new \DateTimeImmutable('today'))->format('Y-m-d');
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
            if ($this->origVatHistory !== null) {
                VatStatusFixture::restore($this->db->pdo(), $this->supplierId, $this->origVatHistory);
            }
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->createdInvoices as $id) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        $this->createdInvoices = [];
    }

    private function setVatPayer(bool $payer): void
    {
        VatStatusFixture::set($this->db->pdo(), $this->supplierId, $payer);
    }

    /** Unikátní VS (uq_inv_supplier_varsymbol je per supplier+varsymbol). */
    private function nextVs(): string
    {
        return 'CRMPIPE' . (++$this->vsSeq);
    }

    private function insertInvoice(string $type, string $status, float $net, float $gross, ?int $parentId = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, parent_invoice_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $type, $this->nextVs(), $this->clientId, $this->supplierId, $parentId,
            $this->today, $this->today, $this->today, $this->currencyId, $status, $net, $gross, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->createdInvoices[] = $id;
        return $id;
    }

    /** Sečte CZK pipeline složky aktuálního měsíce z overview(). */
    private function pipelineCzk(): array
    {
        $ov = $this->crm->overview($this->supplierId);
        $draft = 0.0; $proforma = 0.0; $draftCnt = 0; $profCnt = 0;
        foreach ($ov['current_month_pipeline'] as $r) {
            if ((string) $r['currency'] !== 'CZK') continue;
            $draft += (float) $r['draft_revenue'];
            $proforma += (float) $r['proforma_revenue'];
            $draftCnt += (int) $r['draft_count'];
            $profCnt += (int) $r['proforma_count'];
        }
        return ['draft' => $draft, 'proforma' => $proforma, 'draftCount' => $draftCnt, 'proformaCount' => $profCnt];
    }

    /** Ostré tržby aktuálního měsíce (CZK) — koncepty/proformy do nich NEsmí téct. */
    private function firmRevenueCzk(): float
    {
        $ov = $this->crm->overview($this->supplierId);
        $rev = 0.0;
        foreach ($ov['current_month'] as $r) {
            if ((string) $r['currency'] === 'CZK') $rev += (float) $r['revenue'];
        }
        return $rev;
    }

    public function testKonceptyANesparovaneProformyJsouVPipelineAleNeVTrzbach(): void
    {
        $this->setVatPayer(true); // plátce → net báze
        $base = $this->pipelineCzk();
        $baseRev = $this->firmRevenueCzk();

        // Koncept vydané faktury + nespárovaná proforma, oba datované dnes.
        $this->insertInvoice('invoice', 'draft', 1000.0, 1210.0);
        $this->insertInvoice('proforma', 'issued', 2000.0, 2420.0);

        $after = $this->pipelineCzk();
        self::assertEqualsWithDelta($base['draft'] + 1000.0, $after['draft'], 0.01, 'Koncept se započítá do pipeline (net pro plátce)');
        self::assertEqualsWithDelta($base['proforma'] + 2000.0, $after['proforma'], 0.01, 'Nespárovaná proforma se započítá do pipeline (net)');
        self::assertSame($base['draftCount'] + 1, $after['draftCount'], 'Počet konceptů +1');
        self::assertSame($base['proformaCount'] + 1, $after['proformaCount'], 'Počet proforem +1');

        // Ostré tržby se NESMÍ změnit (draft ani proforma nejsou daňový doklad).
        self::assertEqualsWithDelta($baseRev, $this->firmRevenueCzk(), 0.01, 'Koncept/proforma nesmí ovlivnit ostré tržby');
    }

    public function testSparovanaProformaSeNezapocitava(): void
    {
        $this->setVatPayer(true);
        $base = $this->pipelineCzk();

        // Proforma + dceřiný ostrý doklad (invoice) → proforma je „spárovaná" = mimo pipeline.
        $parentId = $this->insertInvoice('proforma', 'issued', 3000.0, 3630.0);
        $this->insertInvoice('invoice', 'issued', 3000.0, 3630.0, $parentId);

        $after = $this->pipelineCzk();
        self::assertEqualsWithDelta($base['proforma'], $after['proforma'], 0.01, 'Spárovaná proforma se do pipeline nezapočítává');
    }
}
