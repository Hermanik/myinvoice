<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Databox;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DataboxMessageRepository;
use MyInvoice\Repository\DataboxOutboxRepository;
use MyInvoice\Repository\DataboxRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Databox\DataboxMessageService;
use MyInvoice\Service\Databox\DataboxReceiptService;
use MyInvoice\Service\Databox\DataboxSendService;
use MyInvoice\Service\Databox\DataboxService;
use MyInvoice\Service\Databox\DataboxSyncService;
use MyInvoice\Service\Databox\IsdsClient;
use MyInvoice\Service\Databox\SubmissionDataboxService;
use MyInvoice\Service\Databox\SubmissionFilingBridge;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Service\Document\ZfoExtractor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Wires the data box services to the real database and a scripted ISDS ({@see FakeIsdsTransport}). Every box (and so,
 * by cascade, every message / file / outbox row) created by a test is removed again in tearDown, and so is every
 * archived submission, so a developer's database is left untouched (same contract as VatStatusFixture).
 *
 * Soft-skip without cfg.php / the 9003 migration, like the other integration tests.
 */
#[Group('integration')]
abstract class DataboxIntegrationTestCase extends TestCase
{
    protected Connection $db;
    protected FakeIsdsTransport $isds;
    protected IsdsClient $client;
    protected DataboxRepository $boxes;
    protected DataboxMessageRepository $messages;
    protected DataboxOutboxRepository $outbox;
    protected SecretEncryption $crypto;
    protected DataboxService $service;
    protected DataboxReceiptService $receipts;
    protected DataboxSyncService $sync;
    protected DataboxMessageService $messageService;
    protected DataboxSendService $sender;
    protected SubmissionDataboxService $submissions;
    protected SubmissionFilingBridge $filings;
    protected TaxSubmissionFilingService $filing;
    protected TaxSubmissionRepository $submissionRepo;

    protected int $supplierId = 0;
    protected int $otherSupplierId = 0;
    protected int $userId = 0;

    /** @var list<int> */
    private array $createdBoxes = [];
    /** @var list<int> */
    private array $createdSubmissions = [];
    private int $boxCountBefore = 0;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Prague');
        $root = dirname(__DIR__, 4);
        if (!is_file($root . '/cfg.php')) {
            $this->markTestSkipped('cfg.php does not exist - the test needs the database.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->crypto = $container->get(SecretEncryption::class);
            $pdo = $this->db->pdo();
            if ($pdo->query("SHOW TABLES LIKE 'databoxes'")->fetch() === false) {
                $this->markTestSkipped('Migration 9003 (data boxes) was not applied.');
            }
            $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            $this->otherSupplierId = (int) ($pdo->query("SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
            $this->userId = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
            $this->boxCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM databoxes')->fetchColumn();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI / DB unavailable: ' . $e->getMessage());
        }
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('The database has no supplier / admin user.');
        }

        $this->isds = new FakeIsdsTransport();
        $this->client = new IsdsClient($this->isds);
        $logger = new NullLogger();
        $this->boxes = new DataboxRepository($this->db);
        $this->messages = new DataboxMessageRepository($this->db);
        $this->outbox = new DataboxOutboxRepository($this->db);
        $this->submissionRepo = new TaxSubmissionRepository($this->db);
        $this->service = new DataboxService($this->boxes, $this->crypto, $this->client, $logger);
        $this->filing = $container->get(TaxSubmissionFilingService::class);
        $this->filings = new SubmissionFilingBridge($this->submissionRepo, $this->filing, $this->messages, $logger);
        $this->receipts = new DataboxReceiptService($this->client, $this->messages, $this->filings);
        $this->sync = new DataboxSyncService($this->boxes, $this->messages, $this->service, $this->receipts, $this->filings, $this->client, $logger);
        $this->messageService = new DataboxMessageService($this->boxes, $this->messages, $this->service, $this->receipts, $this->client, new ZfoExtractor(), $logger);
        $this->sender = new DataboxSendService($this->boxes, $this->messages, $this->outbox, $this->service, $this->receipts, $this->filings, $this->client, $logger);
        $this->submissions = new SubmissionDataboxService($this->submissionRepo, $this->messages, $this->service, $this->sender, $this->filings, $this->db);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->createdSubmissions as $id) {
            $pdo->prepare('DELETE FROM tax_submissions WHERE id = ?')->execute([$id]);
        }
        foreach ($this->createdBoxes as $id) {
            $pdo->prepare('DELETE FROM databoxes WHERE id = ?')->execute([$id]);
        }
        $after = (int) $pdo->query('SELECT COUNT(*) FROM databoxes')->fetchColumn();
        self::assertSame($this->boxCountBefore, $after, 'the test must leave the databoxes table as it found it');
    }

    /**
     * Connect a box through the real service with scripted login answers.
     *
     * @param string $syncUser fixture of GetUserInfoFromLogin2 for the sync login (user_info_list_only | user_info_reader | ...)
     * @param string|null $actionUser fixture for the optional action login
     * @return array<string,mixed> public view
     */
    protected function connectBox(string $syncUser = 'user_info_list_only', ?string $actionUser = null, string $owner = 'owner_info_pfo', bool $allowDelivering = false, ?int $supplierId = null): array
    {
        $this->scriptLogin($owner, $syncUser);
        if ($actionUser !== null) {
            $this->scriptLogin($owner, $actionUser);
        }
        $input = [
            'label' => 'Test box',
            'sync' => ['username' => 'sync-user', 'password' => 'S3cret-sync-pass'],
            'allow_delivering_sync' => $allowDelivering,
        ];
        if ($actionUser !== null) {
            $input['action'] = ['username' => 'action-user', 'password' => 'S3cret-action-pass'];
        }
        $box = $this->service->add($supplierId ?? $this->supplierId, $input);
        $this->createdBoxes[] = $box['id'];
        // Every sync re-reads the rights of its login (GetUserInfoFromLogin2): by default it still sees what the login had.
        $this->isds->alwaysFor('GetUserInfoFromLogin2', 'sync-user', DataboxFixtures::xml($syncUser));
        if ($actionUser !== null) {
            $this->isds->alwaysFor('GetUserInfoFromLogin2', 'action-user', DataboxFixtures::xml($actionUser));
        }
        return $box;
    }

    /** Have tearDown remove a box the test created without {@see connectBox()} / {@see connectEmailBox()}. */
    protected function trackBox(int $boxId): void
    {
        $this->createdBoxes[] = $boxId;
    }

    /**
     * Connect a box that is watched by notification e-mails only (no login); removed in tearDown.
     *
     * @return array<string,mixed> public view
     */
    protected function connectEmailBox(string $dbId = DatovkaNoticeFixtures::RECIPIENT_BOX, string $dbType = 'PFO', ?int $supplierId = null): array
    {
        $box = $this->service->add($supplierId ?? $this->supplierId, ['mode' => 'email', 'label' => 'E-mail box', 'db_id' => $dbId, 'db_type' => $dbType]);
        $this->trackBox($box['id']);
        return $box;
    }

    protected function scriptLogin(string $owner, string $user): void
    {
        $this->isds->queue('GetOwnerInfoFromLogin2', DataboxFixtures::xml($owner));
        $this->isds->queue('GetUserInfoFromLogin2', DataboxFixtures::xml($user));
        $this->isds->queue('GetPasswordInfo', DataboxFixtures::xml('password_info'));
    }

    /** @return array<string,mixed> the stored box (with logins) */
    protected function storedBox(int $boxId): array
    {
        return $this->boxes->find($boxId) ?? throw new \LogicException('box vanished');
    }

    protected function forgetOperations(): void
    {
        $this->isds->requests = [];
    }

    /** Archive an EPO XML like TaxSubmissionArchiver does; removed in tearDown. */
    protected function archiveSubmission(string $formCode, string $xml, string $validation = 'passed', int $year = 2026, ?int $month = 9, ?int $quarter = null, ?int $supplierId = null, string $submissionForm = 'B'): int
    {
        $id = $this->submissionRepo->archive($supplierId ?? $this->supplierId, $formCode, $year, $month, $quarter, $xml, ['test' => true], $validation, [], $this->userId, $submissionForm);
        $this->createdSubmissions[] = $id;
        return $id;
    }

    protected static function epoXml(string $cUfo = '451'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Pisemnost nazevSW="MyInvoice" verzeSW="1"><DPHDP3 verzePis="01.02.16"><VetaD dapdph_forma="B" rok="2026" mesic="9"/>'
            . '<VetaP c_ufo="' . $cUfo . '" c_pracufo="2001" dic="12345678" typ_ds="F"/></DPHDP3></Pisemnost>';
    }
}
