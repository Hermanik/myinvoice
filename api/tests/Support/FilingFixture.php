<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\Assert;

/**
 * Integration-test fixture for the filing workflow: boots the container, picks the first supplier,
 * snapshots everything the tests may change on it (VAT history, tax settings) and removes every row
 * the test created. Tests only ever work in the years 2096-2099, so real data is never touched.
 *
 * Usage: `$this->fx = FilingFixture::boot($this)` in setUp() — it skips the test when the
 * environment is unusable BEFORE it snapshots anything; `$this->fx?->restore()` in tearDown()
 * restores only if the snapshot was actually taken.
 */
final class FilingFixture
{
    public ContainerInterface $container;
    public Connection $db;
    public PDO $pdo;
    public int $supplierId = 0;
    public int $currencyId = 0;
    public int $vatRateId = 0;
    public int $userId = 0;
    public int $czId = 0;
    public int $skId = 0;

    /** @var list<array{valid_from: string, status: string, note: ?string}>|null */
    private ?array $vatHistory = null;
    /** @var array<string,mixed>|null */
    private ?array $supplierRow = null;

    /** @var list<int> */
    private array $submissionIds = [];
    /** @var list<int> */
    private array $invoiceIds = [];
    /** @var list<int> */
    private array $purchaseIds = [];
    /** @var list<int> */
    private array $clientIds = [];
    /** @var list<int> */
    private array $statementIds = [];
    /** @var list<int> */
    private array $transactionIds = [];
    /** @var list<int> */
    private array $activityIds = [];
    /** @var list<int> */
    private array $deductionIds = [];
    /** Highest activity_log id at boot: log rows the code under test wrote afterwards are removed again. */
    private int $activityWatermark = 0;

    private function __construct() {}

    /** Boots the fixture, or skips the running test when the environment cannot support it. */
    public static function boot(\PHPUnit\Framework\TestCase $test): self
    {
        $fx = new self();
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            Assert::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $fx->container = Bootstrap::buildApp()->getContainer();
            $fx->db = $fx->container->get(Connection::class);
            $fx->pdo = $fx->db->pdo();
        } catch (\Throwable $e) {
            Assert::markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $fx->supplierId = (int) ($fx->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $fx->currencyId = (int) ($fx->pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $fx->vatRateId = (int) ($fx->pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $fx->userId = (int) ($fx->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $fx->czId = (int) ($fx->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ'")->fetchColumn() ?: 0);
        $fx->skId = (int) ($fx->pdo->query("SELECT id FROM countries WHERE iso2 = 'SK'")->fetchColumn() ?: 0);
        if ($fx->supplierId === 0 || $fx->currencyId === 0 || $fx->vatRateId === 0 || $fx->userId === 0 || $fx->czId === 0) {
            Assert::markTestSkipped('Chybí základní data (supplier/currency/vat_rate/user/country) v DB.');
        }
        if (!$fx->db->hasColumn('tax_submissions', 'filing_status')) {
            Assert::markTestSkipped('Migrace 9004 (tax_submissions filing) není aplikovaná.');
        }

        // Snapshot only now, after every skip check.
        $fx->activityWatermark = (int) ($fx->pdo->query('SELECT COALESCE(MAX(id), 0) FROM activity_log')->fetchColumn() ?: 0);
        $fx->vatHistory = VatStatusFixture::snapshot($fx->pdo, $fx->supplierId);
        $fx->supplierRow = $fx->pdo->query(
            "SELECT dic, financial_office_code, taxpayer_type, vat_period, flat_tax_band
               FROM supplier WHERE id = {$fx->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return $fx;
    }

    /** Sets the supplier columns the tests care about. */
    public function configureSupplier(array $columns): void
    {
        foreach ($columns as $name => $value) {
            if (!in_array($name, ['dic', 'financial_office_code', 'taxpayer_type', 'vat_period', 'flat_tax_band'], true)) {
                throw new \InvalidArgumentException("Column {$name} is not restorable.");
            }
            $this->pdo->prepare("UPDATE supplier SET {$name} = ? WHERE id = ?")->execute([$value, $this->supplierId]);
        }
    }

    /** Payer from the beginning of time. */
    public function payer(): void
    {
        VatStatusFixture::set($this->pdo, $this->supplierId, true);
    }

    /** Replaces the VAT history with [valid_from, status] rows. */
    public function vatHistory(array $rows): void
    {
        VatStatusFixture::setHistory($this->pdo, $this->supplierId, $rows);
    }

    public function restore(): void
    {
        if ($this->supplierRow === null) {
            return; // the snapshot was never taken (test skipped in boot())
        }
        $pdo = $this->pdo;
        foreach ($this->activityIds as $id) {
            $pdo->prepare('DELETE FROM activity_log WHERE id = ?')->execute([$id]);
        }
        // Entries of the endpoints exercised through their actions (report downloads, registration deductions).
        $pdo->prepare("DELETE FROM activity_log WHERE id > ? AND (action LIKE 'report.dph%\_downloaded' OR action LIKE 'tax.registration\_deduction\_%')")
            ->execute([$this->activityWatermark]);
        if ($this->submissionIds !== []) {
            $in = implode(',', $this->submissionIds);
            $pdo->exec("DELETE FROM activity_log WHERE entity_type = 'tax_submission' AND entity_id IN ({$in})");
            $pdo->exec("DELETE FROM tax_submissions WHERE id IN ({$in})");
        }
        if ($this->deductionIds !== []) {
            $pdo->exec('DELETE FROM vat_registration_deductions WHERE id IN (' . implode(',', $this->deductionIds) . ')');
        }
        foreach ($this->invoiceIds as $id) {
            $pdo->exec("DELETE FROM activity_log WHERE entity_type = 'invoice' AND entity_id = {$id}");
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $pdo->exec("DELETE FROM activity_log WHERE entity_type = 'purchase_invoice' AND entity_id = {$id}");
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->transactionIds as $id) {
            $pdo->exec("DELETE FROM activity_log WHERE entity_type = 'bank_transaction' AND entity_id = {$id}");
        }
        foreach ($this->statementIds as $id) {
            $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$id]); // cascades to transactions
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->invoiceIds = $this->purchaseIds = $this->submissionIds = $this->clientIds = $this->statementIds = $this->transactionIds = $this->activityIds = $this->deductionIds = [];

        $cols = $this->supplierRow;
        $pdo->prepare('UPDATE supplier SET dic = ?, financial_office_code = ?, taxpayer_type = ?, vat_period = ?, flat_tax_band = ? WHERE id = ?')
            ->execute([$cols['dic'], $cols['financial_office_code'], $cols['taxpayer_type'], $cols['vat_period'], $cols['flat_tax_band'], $this->supplierId]);
        if ($this->vatHistory !== null) {
            VatStatusFixture::restore($pdo, $this->supplierId, $this->vatHistory);
        }
        $this->db->close();
    }

    // ── archive rows ────────────────────────────────────────────────────────

    /**
     * An archived submission. `$summary` is stored as the summary JSON.
     *
     * @param array<string,mixed> $summary
     */
    public function submission(string $formCode, int $year, ?int $month, ?int $quarter, string $form = 'B', array $summary = []): int
    {
        $xml = '<x/>';
        $this->pdo->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter, submission_form,
                 xml_content, xml_size_bytes, xml_sha256, validation_status, summary_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'skipped', ?)"
        )->execute([
            $this->supplierId, $formCode, $year, $month, $quarter, $form,
            $xml, strlen($xml), hash('sha256', $xml), json_encode($summary),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->submissionIds[] = $id;
        return $id;
    }

    /** Registers a registration-deduction item created by the code under test, so that it is removed again. */
    public function trackDeduction(int $id): void
    {
        $this->deductionIds[] = $id;
    }

    /** Registers an archive row created by the code under test, so that it is removed again. */
    public function trackSubmission(int $id): void
    {
        $this->submissionIds[] = $id;
    }

    /** A DPH return archive row with the given own tax liability (row 64) or excess deduction (row 66). */
    public function dphSubmission(int $year, ?int $month, ?int $quarter, int $tax, string $form = 'B'): int
    {
        return $this->submission('dphdp3', $year, $month, $quarter, $form, [
            'line_64_dano_da' => max(0, $tax),
            'line_66_dano_no' => max(0, -$tax),
            'tax_due' => $tax,
        ]);
    }

    // ── documents ───────────────────────────────────────────────────────────

    public function client(string $name, ?int $countryId = null, ?string $dic = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $name, $countryId ?? $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /**
     * An issued sales invoice with one item. `$createdAt` back-dates created_at (default = now).
     */
    public function sale(string $varsymbol, int $clientId, string $taxDate, float $base, float $vat, float $rate, ?string $code = '1', string $status = 'issued', ?string $createdAt = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by, created_at)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))'
        )->execute([
            $this->supplierId, $varsymbol, $clientId, $taxDate, $taxDate, $taxDate, $this->currencyId,
            $base, $vat, $base + $vat, $status, $code, $this->userId, $createdAt,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->invoiceIds[] = $id;
        $this->items('invoice_items', 'invoice_id', $id, $base, $vat, $rate);
        return $id;
    }

    public function purchase(string $number, int $vendorId, string $taxDate, float $base, float $vat, float $rate, ?string $code = '40', string $status = 'received', ?string $createdAt = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 is_fixed_asset, vat_deduction, vat_deduction_percent, created_by, created_at)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", ?, ?, ?, ?, ?, 0, "full", 100, ?, COALESCE(?, NOW()))'
        )->execute([
            $this->supplierId, $vendorId, $number, $taxDate, $taxDate, $taxDate, $taxDate, $this->currencyId,
            $base, $vat, $base + $vat, $status, $code, $this->userId, $createdAt,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->purchaseIds[] = $id;
        $this->items('purchase_invoice_items', 'purchase_invoice_id', $id, $base, $vat, $rate);
        return $id;
    }

    private function items(string $table, string $fk, int $id, float $base, float $vat, float $rate): void
    {
        $this->pdo->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, 0)"
        )->execute([$id, $base, $this->vatRateId, $rate, $base, $vat, $base + $vat]);
    }

    /** An activity-log row for a document at an exact time (so "after filing" can be tested deterministically). */
    public function log(string $action, string $entityType, ?int $entityId, string $at, ?array $payload = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO activity_log (supplier_id, user_id, action, entity_type, entity_id, payload, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $this->userId, $action, $entityType, $entityId, $payload === null ? null : json_encode($payload), $at]);
        $this->activityIds[] = (int) $this->pdo->lastInsertId();
    }

    // ── bank ────────────────────────────────────────────────────────────────

    /**
     * The CZK account of the supplier (account_number + bank_code), or skips the test.
     *
     * @return array{account_number:string, bank_code:?string}
     */
    public function bankAccountOrSkip(): array
    {
        $row = $this->pdo->query(
            "SELECT account_number, bank_code FROM currencies
              WHERE supplier_id = {$this->supplierId} AND code = 'CZK' AND account_number IS NOT NULL AND account_number <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            Assert::markTestSkipped('Chybí CZK currency s account_number.');
        }
        return ['account_number' => (string) $row['account_number'], 'bank_code' => $row['bank_code'] !== null ? (string) $row['bank_code'] : null];
    }

    /**
     * An outgoing bank transaction on the supplier's account. Returns the transaction id.
     *
     * @param array{account_number:string, bank_code:?string} $account
     */
    public function outgoingTransaction(array $account, float $amount, string $postedAt, ?string $vs, ?string $counterparty, ?string $counterpartyBank = '0710', string $source = 'statement'): int
    {
        $this->pdo->prepare(
            "INSERT INTO bank_statements (file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES (?, ?, ?, ?, 'CZK', ?)"
        )->execute(['__filing_test__' . bin2hex(random_bytes(4)), bin2hex(random_bytes(32)), $account['account_number'], $account['bank_code'], $postedAt]);
        $statementId = (int) $this->pdo->lastInsertId();
        $this->statementIds[] = $statementId;
        $this->pdo->prepare(
            "INSERT INTO bank_transactions
                (source, source_ref, statement_id, posted_at, amount, currency, variable_symbol, counterparty_account, counterparty_bank, counterparty_name)
             VALUES (?, ?, ?, ?, ?, 'CZK', ?, ?, ?, 'Finanční úřad')"
        )->execute([$source, bin2hex(random_bytes(6)), $statementId, $postedAt, number_format(-abs($amount), 2, '.', ''), $vs, $counterparty, $counterpartyBank]);
        $id = (int) $this->pdo->lastInsertId();
        $this->transactionIds[] = $id;
        return $id;
    }

    /** @return array<string,mixed> */
    public function transaction(int $id): array
    {
        return $this->pdo->query("SELECT * FROM bank_transactions WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    public function submissionRow(int $id): array
    {
        return $this->pdo->query("SELECT * FROM tax_submissions WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
