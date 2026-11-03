<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Invoice\InvoiceVatAdvisor;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Vat\VatStatusService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The approval flows (UpdateApprovalStatusAction, PublicApprovalDecideAction) issue and send the invoice
 * through AutoIssueAndSendService. A payer-time domestic draft with every line on 0 % must be held there
 * exactly like the recurring generator holds it: left as a draft, nothing sent. Synthetic data only.
 */
final class AutoIssueAndSendHoldTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;
    /** @var array<string,mixed> */
    private array $invoice;
    private bool $clientDomestic = true;
    private InvoicePdfRenderer $renderer;
    private Mailer $mailer;
    private ActivityLogger $logger;
    private AutoIssueAndSendService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, is_vat_payer INTEGER NOT NULL DEFAULT 0, is_identified INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('INSERT INTO supplier (id, is_vat_payer) VALUES (7, 1)');
        $this->pdo->exec('CREATE TABLE supplier_vat_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NOT NULL, valid_from TEXT NOT NULL, status TEXT NOT NULL, note TEXT NULL, created_at TEXT NULL, created_by INTEGER NULL)');
        $this->pdo->exec("INSERT INTO supplier_vat_status_history (supplier_id, valid_from, status) VALUES (7, '2000-01-01', 'non_payer'), (7, '2027-01-01', 'payer')");
        $this->pdo->exec('CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, supplier_id INTEGER NULL, user_id INTEGER NULL, action TEXT, entity_type TEXT NULL, entity_id INTEGER NULL, payload TEXT NULL, ip BLOB NULL, user_agent TEXT NULL, created_at TEXT NULL)');
        // ActivityLogger resolves the supplier of an invoice entity from this table.
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER NOT NULL)');
        $this->pdo->exec('INSERT INTO invoices (id, supplier_id) VALUES (31, 7)');
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($this->pdo);
        $db->method('hasTable')->willReturn(true);
        $this->db = $db;

        $this->invoice = [
            'id' => 31, 'supplier_id' => 7, 'client_id' => 5, 'status' => 'draft', 'invoice_type' => 'invoice',
            'parent_invoice_id' => null, 'varsymbol' => null, 'issue_date' => '2027-02-05', 'tax_date' => '2027-01-31',
            'items' => [['vat_rate_snapshot' => 0.0], ['vat_rate_snapshot' => 0.0]],
        ];
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('find')->willReturnCallback(fn (): array => $this->invoice);
        $repo->method('clientIsDomestic')->willReturnCallback(fn (): bool => $this->clientDomestic);

        // Anything that would issue, render or send must stay untouched when the draft is held.
        $this->renderer = $this->createMock(InvoicePdfRenderer::class);
        $this->renderer->expects(self::never())->method('render');
        $this->renderer->expects(self::never())->method('invalidate');
        $this->mailer = $this->createMock(Mailer::class);
        $this->mailer->expects(self::never())->method('sendTemplate');
        $this->logger = new ActivityLogger($this->db);

        $this->service = new AutoIssueAndSendService(
            $repo,
            $this->db,
            $this->createStub(VarsymbolGenerator::class),
            $this->createStub(SnapshotBuilder::class),
            $this->renderer,
            $this->mailer,
            $this->createStub(InvoiceEmailVarsBuilder::class),
            $this->logger,
            $this->createStub(StatsRecomputer::class),
            $this->createStub(PdfArchiveService::class),
            $this->createStub(RecipientResolver::class),
            new InvoiceVatAdvisor($repo, VatStatusService::forConnection($this->db)),
        );
    }

    public function testPayerTimeZeroRatedDomesticDraftIsHeldNotIssuedNorSent(): void
    {
        $result = $this->service->run(31, 4, '127.0.0.1', 'test');

        self::assertFalse($result['issued']);
        self::assertSame([], $result['sent_to']);
        self::assertTrue($result['held']);
        self::assertSame('payer_zero_rated', $result['warnings'][0]['code']);
        $logged = $this->pdo->query('SELECT action, payload FROM activity_log')->fetchAll();
        self::assertSame(['invoice.auto_issue_held'], array_column($logged, 'action'), 'the hold is auditable');
        self::assertSame('payer_zero_rated', json_decode($logged[0]['payload'], true)['reason']);
        self::assertSame('draft', $this->invoice['status']);
    }

    public function testTheHoldAppliesWithoutUserToo(): void
    {
        // The public approval link has no logged-in user.
        $result = $this->service->run(31, null, '203.0.113.9', 'browser');

        self::assertTrue($result['held']);
        self::assertFalse($result['issued']);
    }
}
