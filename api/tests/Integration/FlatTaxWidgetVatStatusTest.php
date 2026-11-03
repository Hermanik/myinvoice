<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Action\Dashboard\SummaryAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\VatStatusFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard flat-tax (paušál) widget is hidden for a year in which the supplier is a VAT payer
 * for at least part of it — a payer cannot be in the flat tax regime (§ 7a ZDP), whatever band is set.
 */
#[Group('integration')]
final class FlatTaxWidgetVatStatusTest extends TestCase
{
    private Connection $db;
    private SummaryAction $action;
    private int $supplierId = 0;
    private string $origBand = 'none';
    /** @var list<array{valid_from: string, status: string, note: ?string}> */
    private ?array $origVatHistory = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(SummaryAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $this->origBand = (string) $pdo->query("SELECT flat_tax_band FROM supplier WHERE id = {$this->supplierId}")->fetchColumn();
        $this->origVatHistory = VatStatusFixture::snapshot($pdo, $this->supplierId);
        $pdo->exec("UPDATE supplier SET flat_tax_band = 'band1' WHERE id = {$this->supplierId}");
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET flat_tax_band = ? WHERE id = ?')->execute([$this->origBand, $this->supplierId]);
        if ($this->origVatHistory !== null) {
            VatStatusFixture::restore($pdo, $this->supplierId, $this->origVatHistory);
        }
        $this->db->close();
    }

    private function widget(int $year): array
    {
        return (new \ReflectionMethod(SummaryAction::class, 'flatTaxThreshold'))
            ->invoke($this->action, $this->db->pdo(), $year, $this->supplierId);
    }

    public function testWidgetIsHiddenInAYearWithAnyPayerDayAndShownOtherwise(): void
    {
        VatStatusFixture::setHistory($this->db->pdo(), $this->supplierId, [['2000-01-01', 'non_payer'], ['2096-07-01', 'payer']]);

        $this->assertTrue($this->widget(2095)['applicable'], 'non-payer all year → the flat tax limit is watched');
        $this->assertFalse($this->widget(2096)['applicable'], 'registration year (payer from July) → no flat tax');
        $this->assertFalse($this->widget(2097)['applicable'], 'payer all year');
    }
}
