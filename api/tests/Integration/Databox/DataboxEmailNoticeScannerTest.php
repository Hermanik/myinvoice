<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Databox;

use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Repository\DataboxEmailNoticeRepository;
use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use MyInvoice\Service\Databox\EmailNotice\DataboxEmailNoticeScanner;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeAuthenticator;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeParser;
use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures as F;
use MyInvoice\Tests\Support\Databox\DataboxIntegrationTestCase;
use MyInvoice\Tests\Support\Databox\FakeNoticeMailbox;
use MyInvoice\Tests\Support\Databox\RecordingEmailNoticeAlert;
use MyInvoice\Tests\Support\Dkim\ArrayDkimKeyResolver;
use MyInvoice\Tests\Support\Dkim\DkimTestSigner;
use MyInvoice\Tests\Support\FixedClock;
use Psr\Log\NullLogger;

/**
 * The notification e-mail scanner against the real database, with a fake mailbox and a fake DNS. Synthetic data only
 * (invented names, box IDs and message numbers; keys generated here); the box, the IMAP account and the log rows the
 * tests create are removed again.
 */
final class DataboxEmailNoticeScannerTest extends DataboxIntegrationTestCase
{
    private const MARKER = '__databox_email_scan__';

    /** @var array{private:\OpenSSLAsymmetricKey,txt:string} */
    private static array $key;

    private DataboxEmailNoticeRepository $notices;
    private BankEmailNoticeRepository $imapAccounts;
    private ArrayDkimKeyResolver $dns;
    private RecordingEmailNoticeAlert $alert;
    private DataboxEmailNoticeScanner $scanner;
    private FakeNoticeMailbox $mailbox;
    private bool $ready = false;
    private int $accountId = 0;
    /** @var array<string,mixed>|null */
    private ?array $previousSettings = null;

    public static function setUpBeforeClass(): void
    {
        self::$key = DkimTestSigner::rsaKey(2048);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->notices = new DataboxEmailNoticeRepository($this->db);
        $this->imapAccounts = new BankEmailNoticeRepository($this->db, $this->crypto);
        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'databox_email_notices'")->fetch() === false) {
            $this->markTestSkipped('Migration 9009 (data box e-mail notices) was not applied.');
        }

        $previous = $pdo->prepare('SELECT * FROM databox_email_settings WHERE supplier_id = ?');
        $previous->execute([$this->supplierId]);
        $this->previousSettings = $previous->fetch(\PDO::FETCH_ASSOC) ?: null;
        $this->cleanup();

        $this->accountId = (int) $this->imapAccounts->saveImapAccount($this->supplierId, [
            'name' => self::MARKER, 'enabled' => false, 'host' => 'imap.example.invalid', 'encryption' => 'ssl',
            'username' => 'owner@example.invalid', 'password' => 'not-a-real-password',
        ])['id'];
        $this->notices->saveSettings($this->supplierId, $this->accountId, 'Datovka', true, 'alerts@example.invalid');

        $this->mailbox = new FakeNoticeMailbox();
        $this->dns = new ArrayDkimKeyResolver(['20230317._domainkey.datovka.gov.cz' => [self::$key['txt']]]);
        $this->alert = new RecordingEmailNoticeAlert();
        $this->scanner = new DataboxEmailNoticeScanner(
            $this->notices,
            $this->imapAccounts,
            $this->mailbox,
            new DatovkaNoticeParser(),
            new DatovkaNoticeAuthenticator(new EmailAuthenticationVerifier(new DkimVerifier($this->dns, new FixedClock('2026-11-09 10:00:00 UTC')))),
            $this->boxes,
            $this->messages,
            $this->alert,
            new NullLogger(),
        );
        $this->ready = true;
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            $this->cleanup();
            if ($this->previousSettings !== null) {
                $p = $this->previousSettings;
                $this->notices->saveSettings((int) $p['supplier_id'], $p['imap_account_id'] !== null ? (int) $p['imap_account_id'] : null, (string) $p['folder'], (bool) $p['enabled'], $p['alert_email']);
            }
        }
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM databox_email_notices WHERE supplier_id = ? AND imap_account_id IN (SELECT id FROM bank_email_imap_settings WHERE name = ?)')
            ->execute([$this->supplierId, self::MARKER]);
        $pdo->prepare('DELETE FROM databox_email_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        $pdo->prepare('DELETE FROM bank_email_imap_settings WHERE supplier_id = ? AND name = ?')->execute([$this->supplierId, self::MARKER]);
    }

    /** A genuine, signed notice. @param array<string,mixed> $options */
    private function genuine(array $options = [], ?string $html = null): string
    {
        return F::signed(F::raw($options + ['html' => $html ?? F::html()]), self::$key);
    }

    /** @return array<string,mixed>|null */
    private function stored(int $boxId, string $dmId = F::DM_ID): ?array
    {
        return $this->messages->findByDmId($boxId, 'received', $dmId);
    }

    /** @return array<string,mixed>|null */
    private function logRow(string $messageId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM databox_email_notices WHERE supplier_id = ? AND dedup_key = ?');
        $stmt->execute([$this->supplierId, hash('sha256', 'id:' . $messageId)]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function scan(): array
    {
        return $this->scanner->scanSupplier($this->supplierId, new \DateTimeImmutable('2026-11-09 10:00:00'));
    }

    public function testAGenuineNoticeCreatesTheMessageAndAlertsOnce(): void
    {
        $box = $this->connectEmailBox();
        $unreadBefore = $this->messages->unreadSummary($this->supplierId);
        $this->mailbox->fill([$this->genuine(['message_id' => '<n-1@example.invalid>'])]);

        $summary = $this->scan();

        self::assertSame('ok', $summary['status'], (string) $summary['message']);
        self::assertSame(1, $summary['fetched']);
        self::assertSame(1, $summary['created']);
        self::assertSame(1, $summary['alerts_sent']);

        $message = $this->stored($box['id']);
        self::assertNotNull($message);
        self::assertSame('received', $message['direction']);
        self::assertSame('email', $this->sourceOf($message['id']));
        self::assertSame(F::SENDER_BOX, $message['sender_box_id']);
        self::assertSame('Finanční úřad pro Testovací kraj (Územní pracoviště v Testově)', $message['sender_name']);
        self::assertSame(F::RECIPIENT_BOX, $message['recipient_box_id']);
        self::assertSame('Jana Testovací - podnikající fyzická osoba', $message['recipient_name']);
        self::assertSame('Výzva k podání kontrolního hlášení', $message['annotation']);
        self::assertSame(4, $message['status'], 'put into the box, not legally delivered');
        self::assertSame('2026-11-09 09:30:00', $message['delivery_time'], 'the e-mail Date in the application time zone');
        self::assertNull($message['acceptance_time']);
        self::assertNull($message['acknowledged_at']);
        self::assertNull($message['sender_type']);
        self::assertFalse($message['has_zfo']);

        // Tax office highlighting and the fiction date reach the dashboard and the detail exactly as for a synchronised message.
        self::assertTrue($message['is_tax_office']);
        self::assertSame('kh_call', $message['tax_office_kind']);
        $unread = $this->messages->unreadSummary($this->supplierId);
        self::assertSame($unreadBefore['unread'] + 1, $unread['unread']);
        self::assertSame($unreadBefore['tax_office'] + 1, $unread['tax_office']);
        self::assertSame($unreadBefore['undelivered'] + 1, $unread['undelivered']);
        $detail = $this->messageService->detail($this->supplierId, $message['id']);
        self::assertSame('2026-11-19', $detail['open']['fiction_date'], 'delivery day + 10 days, a working day');
        self::assertCount(2, $detail['tax_office_hints']);

        self::assertCount(1, $this->alert->sent);
        self::assertSame('alerts@example.invalid', $this->alert->sent[0]['to']);
        self::assertSame($message['id'], $this->alert->sent[0]['message_id']);
        self::assertTrue($this->alert->sent[0]['is_tax_office']);

        $log = $this->logRow('<n-1@example.invalid>');
        self::assertSame('linked', $log['status']);
        self::assertSame('created', $log['detail']);
        self::assertSame($message['id'], (int) $log['databox_message_id']);
        self::assertSame(F::DM_ID, $log['dm_id']);
        self::assertSame('2026-11-09 09:30:00', $log['received_at']);

        $settings = $this->notices->settings($this->supplierId);
        self::assertSame('ok', $settings['last_scan_status']);
        self::assertSame('2026-11-09 10:00:00', $settings['last_scan_at']);
    }

    private function sourceOf(int $messageId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT source FROM databox_messages WHERE id = ?');
        $stmt->execute([$messageId]);
        return (string) $stmt->fetchColumn();
    }

    public function testTheMailboxIsReadWithoutTouchingIt(): void
    {
        $this->connectEmailBox();
        $this->notices->saveSettings($this->supplierId, $this->accountId, 'Moje/Datovka', true, null);
        $this->mailbox->fill([$this->genuine()]);

        $this->scan();

        self::assertCount(1, $this->mailbox->reads);
        $read = $this->mailbox->reads[0];
        self::assertSame('Moje/Datovka', $read['settings']['folder'], 'the folder of the data box settings, not the bank one');
        self::assertTrue($read['settings']['require_email_auth'], 'the raw header block is needed for the signature');
        self::assertSame('not-a-real-password', $read['settings']['password']);
        self::assertSame(100, $read['limit']);
        self::assertSame(0, $this->mailbox->postProcessed, 'nothing is marked, flagged or moved');
    }

    public function testASecondScanDoesNotRepeatAnything(): void
    {
        $box = $this->connectEmailBox();
        $this->mailbox->fill([$this->genuine(['message_id' => '<n-2@example.invalid>'])]);
        $this->scan();

        $second = $this->scan();

        self::assertSame(1, $second['known_skipped']);
        self::assertSame(0, $second['created']);
        self::assertCount(1, $this->alert->sent, 'the alert is sent once per message');
        self::assertSame(1, $this->messages->list($box['id'], ['direction' => 'received'], 50, 0)['total']);
    }

    public function testAMessageTheBoxAlreadyHasIsLeftAsItIsAndNotAlertedAgain(): void
    {
        $box = $this->connectEmailBox();
        $this->messages->upsertEnvelope($box['id'], 'received', [
            'dm_id' => F::DM_ID, 'sender_box_id' => F::SENDER_BOX, 'sender_name' => 'Name known from ISDS', 'annotation' => 'Annotation known from ISDS',
            'status' => 6, 'delivery_time' => new \DateTimeImmutable('2026-11-09 09:29:58'), 'acceptance_time' => new \DateTimeImmutable('2026-11-09 11:00:00'),
        ], new \DateTimeImmutable('2026-11-09 09:31:00'));
        $this->mailbox->fill([$this->genuine(['message_id' => '<n-3@example.invalid>'])]);

        $summary = $this->scan();

        self::assertSame(0, $summary['created']);
        self::assertSame(1, $summary['linked']);
        self::assertSame([], $this->alert->sent);
        $message = $this->stored($box['id']);
        self::assertSame('Annotation known from ISDS', $message['annotation']);
        self::assertSame('Name known from ISDS', $message['sender_name']);
        self::assertSame(6, $message['status']);
        self::assertSame('2026-11-09 09:29:58', $message['delivery_time']);
        self::assertSame('isds', $this->sourceOf($message['id']));
        $log = $this->logRow('<n-3@example.invalid>');
        self::assertSame('linked', $log['status']);
        self::assertSame('already_known', $log['detail']);
        self::assertSame($message['id'], (int) $log['databox_message_id']);
    }

    public function testASynchronisationLaterConfirmsAMessageAnnouncedByEmail(): void
    {
        $box = $this->connectEmailBox();
        $this->mailbox->fill([$this->genuine()]);
        $this->scan();
        $id = $this->stored($box['id'])['id'];
        self::assertSame('email', $this->sourceOf($id));

        $this->messages->upsertEnvelope($box['id'], 'received', [
            'dm_id' => F::DM_ID, 'status' => 6, 'delivery_time' => new \DateTimeImmutable('2026-11-09 09:29:58'),
        ], new \DateTimeImmutable('2026-11-09 12:00:00'));

        self::assertSame('isds', $this->sourceOf($id));
        self::assertSame('2026-11-09 09:29:58', $this->stored($box['id'])['delivery_time']);
    }

    public function testANoticeForABoxThatIsNotAddedIsKeptAsUnknownAndImportedOnceTheBoxExists(): void
    {
        $this->mailbox->fill([$this->genuine(['message_id' => '<n-4@example.invalid>'])]);

        $first = $this->scan();

        self::assertSame(1, $first['unknown_box']);
        self::assertSame(0, $first['created']);
        self::assertSame([], $this->alert->sent);
        $log = $this->logRow('<n-4@example.invalid>');
        self::assertSame('unknown_box', $log['status']);
        self::assertSame('recipient:' . F::RECIPIENT_BOX, $log['detail']);
        self::assertNull($log['databox_message_id']);
        self::assertStringContainsString('není přidaná', (string) $first['message']);

        $box = $this->connectEmailBox();
        $second = $this->scan();

        self::assertSame(1, $second['created']);
        self::assertSame(0, $second['unknown_box']);
        self::assertNotNull($this->stored($box['id']));
        self::assertCount(1, $this->alert->sent);
        self::assertSame('linked', $this->logRow('<n-4@example.invalid>')['status']);
    }

    public function testANoticeIsOnlyImportedIntoTheBoxOfTheSameSupplierAndEnvironment(): void
    {
        if ($this->otherSupplierId === 0) {
            self::markTestSkipped('The database has a single supplier.');
        }
        $this->connectEmailBox(F::RECIPIENT_BOX, 'PFO', $this->otherSupplierId);
        $this->mailbox->fill([$this->genuine(['message_id' => '<n-5@example.invalid>'])]);

        $summary = $this->scan();

        self::assertSame(1, $summary['unknown_box'], 'the box belongs to another supplier');
    }

    public function testForgedNoticesAreRejectedAndNeverImported(): void
    {
        $box = $this->connectEmailBox();
        $genuine = $this->genuine(['message_id' => '<n-6@example.invalid>']);
        [$signedHeader] = F::split($genuine);
        [, $otherBody] = F::split(F::raw(['html' => F::html(['annotation' => 'Výzva k zaplacení všeho', 'dm_id' => '999'])]));
        $this->mailbox->fill([
            F::raw(['message_id' => '<unsigned@example.invalid>']),
            $signedHeader . $otherBody,
            F::signed(F::raw(['message_id' => '<other-domain@example.invalid>']), self::$key, 'evil.example'),
            F::signed(F::raw(['message_id' => '<other-sender@example.invalid>', 'from' => 'notifikace@evil.example']), self::$key),
        ]);

        $summary = $this->scan();

        self::assertSame(4, $summary['rejected']);
        self::assertSame(0, $summary['created']);
        self::assertSame([], $this->alert->sent);
        self::assertSame(0, $this->messages->list($box['id'], ['direction' => 'received'], 50, 0)['total']);
        self::assertSame('dkim_no_signature', $this->logRow('<unsigned@example.invalid>')['detail']);
        self::assertSame('rejected', $this->logRow('<unsigned@example.invalid>')['status']);
        self::assertSame('dkim_domain_not_aligned', $this->logRow('<other-domain@example.invalid>')['detail']);
        self::assertSame('sender_not_allowed', $this->logRow('<other-sender@example.invalid>')['detail']);
        self::assertStringContainsString('Odmítnuto pro neplatný podpis: 4', (string) $summary['message']);

        // A rejected e-mail is final: it is not looked up again on the next run.
        $this->dns->lookups = [];
        self::assertSame(4, $this->scan()['known_skipped']);
        self::assertSame([], $this->dns->lookups);
    }

    public function testATemporaryDnsFailureLeavesNoRecordAndIsRetried(): void
    {
        $box = $this->connectEmailBox();
        $this->mailbox->fill([$this->genuine(['message_id' => '<n-7@example.invalid>'])]);
        $this->dns->zone['20230317._domainkey.datovka.gov.cz'] = null;

        $first = $this->scan();

        self::assertSame(1, $first['auth_deferred']);
        self::assertNull($this->logRow('<n-7@example.invalid>'), 'no record, so the next run examines it again');
        self::assertNull($this->stored($box['id']));

        $this->dns->zone['20230317._domainkey.datovka.gov.cz'] = [self::$key['txt']];
        $second = $this->scan();

        self::assertSame(1, $second['created']);
        self::assertNotNull($this->stored($box['id']));
    }

    public function testAddressVerificationAndUnrecognisedEmailsAreIgnoredAndNeverStored(): void
    {
        $this->connectEmailBox();
        $verification = F::signed(F::raw([
            'message_id' => '<verify@example.invalid>',
            'subject' => 'Vložení nebo změna e-mailové adresy pro notifikace (schránka: ' . F::RECIPIENT_BOX . ')',
            'html' => "<p>Ověřovací kód: <b>482913</b></p>\n",
        ]), self::$key);
        $other = F::raw(['message_id' => '<other@example.invalid>', 'subject' => 'Nabídka', 'html' => "<p>Skvělá nabídka</p>\n"]);
        $this->mailbox->fill([$verification, $other]);

        $summary = $this->scan();

        self::assertSame(2, $summary['ignored']);
        self::assertSame(0, $summary['created']);
        self::assertSame('address_verification', $this->logRow('<verify@example.invalid>')['detail']);
        self::assertSame('not_a_message_notice', $this->logRow('<other@example.invalid>')['detail']);
        $everything = json_encode([$summary, $this->db->pdo()->query('SELECT * FROM databox_email_notices')->fetchAll(\PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('482913', (string) $everything, 'the one-time code is neither stored nor reported');
    }

    public function testAnUnreadableNoticeIsLoggedAsFailedAndTriedAgain(): void
    {
        $this->connectEmailBox();
        $broken = F::raw(['message_id' => '<n-8@example.invalid>', 'html' => F::html(['line' => 'V datové schránce je nová datová zpráva: <b>Něco</b>'])]);
        $this->mailbox->fill([F::signed($broken, self::$key)]);

        $first = $this->scan();

        self::assertSame(1, $first['parse_failed']);
        self::assertSame('parse_failed', $this->logRow('<n-8@example.invalid>')['status']);
        self::assertStringContainsString('Nečitelných upozornění: 1', (string) $first['message']);
        self::assertSame(1, $this->scan()['parse_failed'], 'looked at again by the next run, e.g. after the parser is fixed');
    }

    public function testASystemMessageHasTheSystemSenderType(): void
    {
        $box = $this->connectEmailBox();
        $html = F::html(['kind' => 'system', 'annotation' => 'Upozornění na vypršení hesla', 'sender_name' => 'Systém datových schránek', 'sender_box' => 'aaaaaaa', 'dm_id' => '4242']);
        $this->mailbox->fill([$this->genuine(['message_id' => '<n-9@example.invalid>'], $html)]);

        $this->scan();

        $message = $this->stored($box['id'], '4242');
        self::assertSame(0, $message['sender_type']);
        self::assertFalse($message['is_tax_office']);
        self::assertSame('aaaaaaa', $message['sender_box_id']);
        self::assertFalse($this->alert->sent[0]['is_tax_office']);
    }

    public function testTheEmailDateIsConvertedToTheApplicationTimeZone(): void
    {
        $box = $this->connectEmailBox();
        $this->mailbox->fill([$this->genuine(['message_id' => '<n-10@example.invalid>', 'date' => 'Mon, 09 Nov 2026 08:30:00 +0000'])]);

        $this->scan();

        self::assertSame('2026-11-09 09:30:00', $this->stored($box['id'])['delivery_time']);
    }

    public function testALongAnnotationIsCutToTheColumn(): void
    {
        $box = $this->connectEmailBox();
        $this->mailbox->fill([$this->genuine(['message_id' => '<n-11@example.invalid>'], F::html(['annotation' => str_repeat('Ž', 400)]))]);

        $this->scan();

        self::assertSame(str_repeat('Ž', 255), $this->stored($box['id'])['annotation']);
    }

    public function testNoAlertIsSentWithoutAnAlertAddress(): void
    {
        $this->connectEmailBox();
        $this->notices->saveSettings($this->supplierId, $this->accountId, 'Datovka', true, null);
        $this->mailbox->fill([$this->genuine()]);

        $summary = $this->scan();

        self::assertSame(1, $summary['created']);
        self::assertSame(0, $summary['alerts_sent']);
        self::assertSame([], $this->alert->sent);
    }

    public function testAFailingAlertDoesNotBreakTheScan(): void
    {
        $box = $this->connectEmailBox();
        $this->alert->failWith = new \RuntimeException('SMTP server unreachable');
        $this->mailbox->fill([
            $this->genuine(['message_id' => '<n-12@example.invalid>']),
            $this->genuine(['message_id' => '<n-13@example.invalid>'], F::html(['dm_id' => '777'])),
        ]);

        $summary = $this->scan();

        self::assertSame('ok', $summary['status']);
        self::assertSame(2, $summary['created'], 'both messages are stored');
        self::assertSame(2, $summary['alert_errors']);
        self::assertSame(0, $summary['alerts_sent']);
        self::assertNotNull($this->stored($box['id']));
        self::assertContainsEquals(['status' => 'alert_failed', 'reason' => 'SMTP server unreachable'], $summary['details']);
        self::assertStringContainsString('Neodeslaných e-mailových upozornění: 2', (string) $summary['message']);
        self::assertSame('ok', $this->notices->settings($this->supplierId)['last_scan_status']);
    }

    public function testMessagesAreCreatedOldestFirst(): void
    {
        $box = $this->connectEmailBox();
        // A mailbox lists the newest first.
        $this->mailbox->fill([
            $this->genuine(['message_id' => '<new@example.invalid>', 'date' => 'Mon, 09 Nov 2026 12:00:00 +0100'], F::html(['dm_id' => '222'])),
            $this->genuine(['message_id' => '<old@example.invalid>', 'date' => 'Mon, 09 Nov 2026 08:00:00 +0100'], F::html(['dm_id' => '111'])),
        ]);

        $this->scan();

        self::assertSame(['111', '222'], array_column($this->alert->sent, 'dm_id'));
        self::assertLessThan($this->stored($box['id'], '222')['id'], $this->stored($box['id'], '111')['id']);
    }

    public function testAMailboxProblemIsReportedOnTheSettings(): void
    {
        $this->mailbox->failWith = new \RuntimeException('Login refused');

        $summary = $this->scan();

        self::assertSame('error', $summary['status']);
        self::assertStringContainsString('Login refused', (string) $summary['message']);
        $settings = $this->notices->settings($this->supplierId);
        self::assertSame('error', $settings['last_scan_status']);
        self::assertStringContainsString('Login refused', (string) $settings['last_scan_message']);
    }

    public function testWithoutAMailboxAccountThereIsNothingToRead(): void
    {
        $this->notices->saveSettings($this->supplierId, null, 'Datovka', false, null);

        $summary = $this->scan();

        self::assertSame('error', $summary['status']);
        self::assertSame('Není vybraný IMAP účet.', $summary['message']);
        self::assertSame([], $this->mailbox->reads);
    }
}
