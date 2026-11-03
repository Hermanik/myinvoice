<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Tests\Support\VatStatusFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The data-quality warnings of the DPH return (credit notes lowering output VAT, unclassified 0 % lines)
 * only concern documents whose DUZP lies in VAT-duty time — a pre-registration document is in no return.
 * Isolated period 2096-05; the supplier's VAT history is replaced for the test and restored afterwards.
 */
#[Group('integration')]
final class VatTimeWarningsTest extends TestCase
{
    private Connection $db;
    private DphPriznaniBuilder $dph;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    /** @var list<array{valid_from: string, status: string, note: ?string}> */
    private ?array $origVatHistory = null;
    /** @var int[] */
    private array $invoiceIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db  = $container->get(Connection::class);
            $this->dph = $container->get(DphPriznaniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel VAT čas", "Test 1", "Praha", "11000", ?, "CZ11111118", "t@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $this->origVatHistory = VatStatusFixture::snapshot($pdo, $this->supplierId);
        // Non-payer since forever, a payer from 2096-05-15 (mid-month registration).
        VatStatusFixture::setHistory($pdo, $this->supplierId, [['2000-01-01', 'non_payer'], ['2096-05-15', 'payer']]);
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
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        if ($this->origVatHistory !== null) {
            VatStatusFixture::restore($pdo, $this->supplierId, $this->origVatHistory);
        }
        $this->db->close();
    }

    public function testCreditNoteAndUnclassifiedZeroLineWarningsIgnorePreRegistrationDocuments(): void
    {
        // Before the registration (DUZP 2096-05-10): neither warning may be raised for these.
        $this->document('CN-PRE', 'credit_note', '2096-05-10', -1000.0, 0.0, 0.0);
        $this->document('ZERO-PRE', 'invoice', '2096-05-12', 1000.0, 0.0, 0.0);
        // In VAT time (DUZP 2096-05-20): both warnings apply.
        $this->document('CN-POST', 'credit_note', '2096-05-20', -1000.0, -210.0, 21.0);
        $this->document('ZERO-POST', 'invoice', '2096-05-22', 1000.0, 0.0, 0.0);

        $warnings = implode("\n", $this->dph->build($this->supplierId, 2096, 5, 'monthly')['warnings']);

        $this->assertStringNotContainsString('CN-PRE', $warnings);
        $this->assertStringNotContainsString('ZERO-PRE', $warnings);
        $this->assertStringContainsString('CN-POST', $warnings);
        $this->assertStringContainsString('ZERO-POST', $warnings);
        $this->assertStringContainsString('jen část období', $warnings);
    }

    private function document(string $varsymbol, string $type, string $duzp, float $base, float $vat, float $rate): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id,
                                   reverse_charge, total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", ?)'
        )->execute([$this->supplierId, $varsymbol, $type, $this->clientId, $duzp, $duzp, $duzp, $this->currencyId, $base, $vat, $base + $vat, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->invoiceIds[] = $id;
        $pdo->prepare(
            "INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                        total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test', 1, 'ks', ?, ?, ?, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $base + $vat]);
    }
}
