<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Bank;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeScanner;
use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface;
use MyInvoice\Service\Bank\EmailNotice\Parser\BankEmailNoticeParserRepository;
use MyInvoice\Service\Bank\StatementMatcher;
use MyInvoice\Tests\Support\Dkim\ArrayDkimKeyResolver;
use MyInvoice\Tests\Support\Dkim\DkimTestSigner;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Bank e-mail notice scanner with the authenticity check on, for a mailbox whose provider adds no
 * Authentication-Results (Seznam): the notice has to prove itself with its own DKIM signature.
 *
 * The IMAP side is faked, DNS is faked; the parser, mapping, bank transaction creation and invoice
 * matching run for real. Synthetic data only (year 2099, own invoice / IMAP account, removed afterwards).
 */
#[Group('integration')]
final class BankEmailNoticeDkimScannerTest extends TestCase
{
    private const MARKER = '__dkimscan__';
    private const VS = '2099000261';
    private const AMOUNT = '1,00';

    private Connection $db;
    private BankEmailNoticeRepository $repo;
    private BankEmailNoticeParserRepository $parsers;
    private StatementMatcher $matcher;
    private ClockInterface $clock;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $clientId = 0;
    private int $userId = 0;
    private string $account = '';
    private string $bankCode = '';
    private int $accountId = 0;
    private int $invoiceId = 0;
    /** @var array<string,mixed>|null */
    private ?array $previousMapping = null;

    /** @var array{private:\OpenSSLAsymmetricKey,txt:string} */
    private static array $bankKey;
    /** @var array{private:\OpenSSLAsymmetricKey,txt:string} */
    private static array $evilKey;

    public static function setUpBeforeClass(): void
    {
        self::$bankKey = DkimTestSigner::rsaKey(2048);
        self::$evilKey = DkimTestSigner::rsaKey(2048);
    }

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing - the test needs a DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->repo = $c->get(BankEmailNoticeRepository::class);
            $this->parsers = $c->get(BankEmailNoticeParserRepository::class);
            $this->matcher = $c->get(StatementMatcher::class);
            $this->clock = $c->get(ClockInterface::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB unavailable: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $cur = $pdo->query(
            "SELECT id, supplier_id, account_number, bank_code FROM currencies
              WHERE code = 'CZK' AND account_number IS NOT NULL AND account_number <> '' AND bank_code IS NOT NULL
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $this->markTestSkipped('No CZK currency with an account number and bank code.');
        }
        $this->currencyId = (int) $cur['id'];
        $this->supplierId = (int) $cur['supplier_id'];
        $this->account = (string) $cur['account_number'];
        $this->bankCode = (string) $cur['bank_code'];
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $countryId === 0) {
            $this->markTestSkipped('No user / country in the database.');
        }

        $this->cleanup();

        // The seeded database has no clients, so the test brings its own (removed in tearDown).
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, self::MARKER, 'Testovaci 1', 'Praha', '11000', $countryId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $account = $this->repo->saveImapAccount($this->supplierId, [
            'name' => self::MARKER, 'enabled' => true, 'host' => 'imap.example.invalid', 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p',
            'require_email_auth' => true, 'failure_action' => 'add_flag',
        ]);
        $this->accountId = (int) $account['id'];

        $previous = $pdo->prepare('SELECT * FROM bank_email_account_mappings WHERE currency_id = ?');
        $previous->execute([$this->currencyId]);
        $this->previousMapping = $previous->fetch(PDO::FETCH_ASSOC) ?: null;
        $this->repo->saveAccountMappings($this->supplierId, [[
            'currency_id' => $this->currencyId, 'imap_account_id' => $this->accountId,
            'provider_ref' => 'system:airbank', 'enabled' => true, 'amount_tolerance' => 0.05,
        ]], $this->parsers->systemProviderCodes());

        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('invoice', ?, ?, ?, '2099-07-01', '2099-07-01', '2099-07-31', ?, 'issued', 1, 1, 0, ?)"
        )->execute([self::VS, $this->clientId, $this->supplierId, $this->currencyId, $this->userId]);
        $this->invoiceId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            if ($this->previousMapping !== null) {
                $this->db->pdo()->prepare(
                    'INSERT INTO bank_email_account_mappings (supplier_id, currency_id, imap_account_id, provider_id, enabled, amount_tolerance)
                     VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE imap_account_id = VALUES(imap_account_id), provider_id = VALUES(provider_id),
                        enabled = VALUES(enabled), amount_tolerance = VALUES(amount_tolerance)'
                )->execute([
                    $this->previousMapping['supplier_id'], $this->previousMapping['currency_id'], $this->previousMapping['imap_account_id'],
                    $this->previousMapping['provider_id'], $this->previousMapping['enabled'], $this->previousMapping['amount_tolerance'],
                ]);
            }
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $ids = $pdo->prepare('SELECT id FROM bank_email_imap_settings WHERE name = ?');
        $ids->execute([self::MARKER]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $pdo->prepare('DELETE FROM bank_email_processed_messages WHERE imap_account_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_email_account_mappings WHERE imap_account_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM bank_email_imap_settings WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare("DELETE FROM bank_statements WHERE source = 'email_notice' AND source_ref LIKE ?")->execute(['imap-monthly:%|2099-%']);
        $pdo->prepare('DELETE FROM payment_matches WHERE supplier_id = ? AND invoice_id IN (SELECT id FROM invoices WHERE varsymbol = ?)')
            ->execute([$this->supplierId, self::VS]);
        $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id IN (SELECT id FROM invoices WHERE varsymbol = ?)')->execute([self::VS]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND varsymbol = ?')->execute([$this->supplierId, self::VS]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ? AND company_name = ?')->execute([$this->supplierId, self::MARKER]);
    }

    // ------------------------------------------------------------------ fixtures

    private function bodyText(string $amount = self::AMOUNT): string
    {
        return "Dobrý den,\r\n\r\nzůstatek na účtu Podnikatelský účet CZK číslo {$this->account}/{$this->bankCode} se zvýšil o částku {$amount} CZK. "
            . "Dostupný zůstatek k 24.07.2099 v 15:20 je 2,00 CZK.\r\n\r\nPříchozí úhrada z účtu Firma Demo s.r.o. číslo 2000145399/0300\r\n"
            . "Částka: {$amount} CZK\r\nDatum zaúčtování: 24.07.2099\r\nVariabilní symbol: " . self::VS . "\r\nKód transakce: 100000000001\r\n";
    }

    private function rawNotice(string $bodyText, string $from = 'Air Bank<info@airbank.cz>', string $messageId = '<notice-1@airbank.cz>'): string
    {
        return DkimTestSigner::sampleMessage($from, $bodyText, $messageId);
    }

    /**
     * @param list<string> $authResults
     */
    private function message(string $raw, string $bodyText, array $authResults = [], string $sender = 'Air Bank <info@airbank.cz>', string $messageId = '<notice-1@airbank.cz>', string $subject = 'Zvýšení zůstatku na účtu Podnikatelský účet CZK'): BankEmailNoticeMessage
    {
        // The mailbox client hands over the raw header block (with its blank line) and the raw body.
        $cut = (int) strpos($raw, "\r\n\r\n") + 4;
        return new BankEmailNoticeMessage(
            uid: 101,
            messageId: $messageId,
            date: new \DateTimeImmutable('2099-07-24 15:20:00'),
            sender: $sender,
            subject: $subject,
            text: $bodyText,
            raw: substr($raw, $cut),
            authResults: $authResults,
            rawHeader: substr($raw, 0, $cut),
        );
    }

    /**
     * @param list<BankEmailNoticeMessage> $messages
     * @param array<string,list<string>|null>|null $zone
     * @return array{0:BankEmailNoticeScanner,1:object,2:ArrayDkimKeyResolver} scanner, the fake mailbox and the fake DNS
     */
    private function scanner(array $messages, ?array $zone = null): array
    {
        $zone ??= ['ab._domainkey.airbank.cz' => [self::$bankKey['txt']]];
        $imap = new class ($messages) implements ImapMailboxClientInterface {
            /** @var list<array{string,string}> */
            public array $postProcessed = [];

            /** @param list<BankEmailNoticeMessage> $messages */
            public function __construct(public array $messages) {}

            public function latest(array $settings, int $limit): array
            {
                return $this->messages;
            }

            public function test(array $settings): array
            {
                return ['ok' => true, 'message' => 'ok'];
            }

            public function postProcess(array $settings, BankEmailNoticeMessage $message, string $kind): ?string
            {
                $this->postProcessed[] = [(string) $message->messageId, $kind];
                return null;
            }
        };
        $dns = new ArrayDkimKeyResolver($zone);
        $auth = new EmailAuthenticationVerifier(new DkimVerifier($dns, $this->clock));
        return [new BankEmailNoticeScanner($this->repo, $this->parsers, $imap, $this->matcher, $auth), $imap, $dns];
    }

    /**
     * @return array<string,mixed>
     */
    private function scan(BankEmailNoticeScanner $scanner): array
    {
        $summary = $scanner->scanSupplier($this->supplierId);
        foreach ($summary['accounts'] as $accountSummary) {
            if (($accountSummary['imap_account_id'] ?? null) === $this->accountId) {
                return $accountSummary;
            }
        }
        self::fail('the test IMAP account was not scanned');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function row(string $messageId = '<notice-1@airbank.cz>'): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM bank_email_processed_messages WHERE imap_account_id = ? AND message_id = ?');
        $stmt->execute([$this->accountId, $messageId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function transactionCount(): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM bank_transactions WHERE source = 'email_notice' AND source_ref LIKE ?");
        $stmt->execute(['imap-' . $this->accountId . ':%']);
        return (int) $stmt->fetchColumn();
    }

    private function paidTotal(): float
    {
        return (float) $this->db->pdo()->query("SELECT paid_total FROM invoices WHERE id = {$this->invoiceId}")->fetchColumn();
    }

    /**
     * Saves the authserv-id (and optionally the authenticity requirement) of the test IMAP account.
     */
    private function configureAccount(?string $authServId, bool $requireAuth = true): void
    {
        $this->repo->saveImapAccount($this->supplierId, [
            'name' => self::MARKER, 'enabled' => true, 'host' => 'imap.example.invalid', 'encryption' => 'ssl', 'username' => 'u',
            'require_email_auth' => $requireAuth, 'email_auth_serv_id' => $authServId, 'failure_action' => 'add_flag',
        ], $this->accountId);
    }

    private function signedNotice(string $body): string
    {
        return DkimTestSigner::sign($this->rawNotice($body), ['key' => self::$bankKey['private'], 'canon' => 'relaxed/relaxed']);
    }

    // ------------------------------------------------------------------ scenarios

    public function testGenuineSignedNoticeWithoutAuthenticationResultsIsProcessed(): void
    {
        $body = $this->bodyText();
        [$scanner, $imap] = $this->scanner([$this->message($this->signedNotice($body), $body)]);

        $summary = $this->scan($scanner);

        self::assertSame(1, $summary['processed'], json_encode($summary['details']));
        self::assertSame(1, $summary['matched']);
        self::assertSame(0, $summary['security_rejected']);
        self::assertSame(1, $this->transactionCount(), 'the bank transaction was created');
        $row = $this->row();
        self::assertSame('processed_success', $row['status']);
        self::assertSame('dkim_local', $row['auth_method']);
        self::assertSame('dkim_local_pass:airbank.cz', $row['auth_detail']);
        self::assertSame([['<notice-1@airbank.cz>', 'success']], $imap->postProcessed);
        self::assertEquals(1.0, $this->paidTotal(), 'the invoice was marked as paid by the verified notice');
    }

    public function testNoticeSignedByAnUnrelatedDomainIsRejected(): void
    {
        $body = $this->bodyText();
        $raw = DkimTestSigner::sign($this->rawNotice($body), [
            'key' => self::$evilKey['private'], 'domain' => 'evil.example', 'canon' => 'relaxed/relaxed',
        ]);
        [$scanner, $imap, $dns] = $this->scanner([$this->message($raw, $body)], ['ab._domainkey.evil.example' => [self::$evilKey['txt']]]);

        $summary = $this->scan($scanner);

        self::assertSame(1, $summary['security_rejected']);
        self::assertSame(0, $summary['processed']);
        self::assertSame(0, $this->transactionCount(), 'a forged notice must not create a transaction');
        $row = $this->row();
        self::assertSame('security_rejected', $row['status']);
        self::assertSame('dkim_local', $row['auth_method']);
        self::assertSame('dkim_domain_not_aligned', $row['auth_detail']);
        self::assertSame([['<notice-1@airbank.cz>', 'failure']], $imap->postProcessed);
        self::assertSame([], $dns->lookups, 'no DNS lookup for a domain that cannot align');
        self::assertEquals(0.0, $this->paidTotal());
    }

    public function testUnsignedForgedNoticeIsRejected(): void
    {
        $body = $this->bodyText();
        [$scanner] = $this->scanner([$this->message($this->rawNotice($body), $body)]);

        $summary = $this->scan($scanner);

        self::assertSame(1, $summary['security_rejected']);
        self::assertSame(0, $this->transactionCount());
        self::assertSame('dkim_no_signature', $this->row()['auth_detail']);
    }

    public function testForgedAuthenticationResultsHeaderDoesNotBypassTheCheck(): void
    {
        // What an attacker mails to a Seznam mailbox: no signature, but a made-up verdict header.
        $body = $this->bodyText();
        $forged = ['mx.seznam.cz; spf=pass dkim=pass dmarc=pass header.d=airbank.cz'];
        [$scanner] = $this->scanner([$this->message($this->rawNotice($body), $body, $forged)]);

        $summary = $this->scan($scanner);

        self::assertSame(1, $summary['security_rejected']);
        self::assertSame(0, $summary['processed']);
        self::assertSame(0, $this->transactionCount());
        self::assertEquals(0.0, $this->paidTotal());
        $row = $this->row();
        self::assertSame('dkim_local', $row['auth_method']);
        self::assertSame('dkim_no_signature', $row['auth_detail']);
    }

    public function testForgedAuthenticationResultsHeaderDoesNotRescueABrokenSignature(): void
    {
        $genuineBody = $this->bodyText();
        $raw = $this->signedNotice($genuineBody);
        $forgedBody = $this->bodyText('100 000,00');
        [$scanner] = $this->scanner([$this->message(
            str_replace($genuineBody, $forgedBody, $raw),
            $forgedBody,
            ['mx.seznam.cz; dkim=pass dmarc=pass header.d=airbank.cz'],
        )]);

        $this->scan($scanner);

        self::assertSame(0, $this->transactionCount());
        $row = $this->row();
        self::assertSame('security_rejected', $row['status']);
        self::assertSame('dkim_body_hash_mismatch', $row['auth_detail']);
    }

    public function testGenuineSignatureOnATamperedAmountIsRejected(): void
    {
        $genuineBody = $this->bodyText();
        $raw = $this->signedNotice($genuineBody);
        // Somebody rewrote the amount after the bank signed it; the app parses the rewritten text.
        $forgedBody = $this->bodyText('100 000,00');
        $forgedRaw = str_replace($genuineBody, $forgedBody, $raw);
        self::assertNotSame($raw, $forgedRaw);
        [$scanner] = $this->scanner([$this->message($forgedRaw, $forgedBody)]);

        $summary = $this->scan($scanner);

        self::assertSame(1, $summary['security_rejected']);
        self::assertSame(0, $this->transactionCount());
        self::assertSame('dkim_body_hash_mismatch', $this->row()['auth_detail']);
    }

    public function testTrustedAuthservIdWithMatchingPassingHeaderIsAccepted(): void
    {
        $this->configureAccount('mx.example.cz');
        $body = $this->bodyText();
        // Not signed at all, but the configured receiving server vouches for it.
        $message = $this->message($this->rawNotice($body), $body, ['mx.example.cz; dkim=pass header.d=airbank.cz']);
        [$scanner, , $dns] = $this->scanner([$message]);

        $summary = $this->scan($scanner);

        self::assertSame(1, $summary['processed']);
        $row = $this->row();
        self::assertSame('authentication_results', $row['auth_method']);
        self::assertSame('dkim_pass_aligned', $row['auth_detail']);
        self::assertSame([], $dns->lookups);
    }

    public function testTrustedAuthservIdWithFailingHeaderIsRejectedEvenForAValidSignature(): void
    {
        $this->configureAccount('mx.example.cz');
        $body = $this->bodyText();
        [$scanner] = $this->scanner([$this->message($this->signedNotice($body), $body, ['mx.example.cz; dkim=fail dmarc=fail'])]);

        $summary = $this->scan($scanner);

        self::assertSame(1, $summary['security_rejected']);
        self::assertSame('auth_failed', $this->row()['auth_detail']);
    }

    public function testConfiguredAuthservIdThatDoesNotMatchFallsBackToTheOwnDkimCheck(): void
    {
        $this->configureAccount('mx.example.cz');
        $body = $this->bodyText();
        $foreign = ['attacker.example; dkim=pass dmarc=pass header.d=airbank.cz'];

        // Forged: the foreign header is ignored, there is no signature.
        [$scanner] = $this->scanner([$this->message($this->rawNotice($body), $body, $foreign)]);
        self::assertSame(1, $this->scan($scanner)['security_rejected']);
        self::assertSame('dkim_no_signature', $this->row()['auth_detail']);
        self::assertSame(0, $this->transactionCount());

        // Genuine: signed by the bank, accepted by the own check (new Message-ID so it is not deduplicated).
        $other = str_replace('100000000001', '100000000002', $body);
        $raw = DkimTestSigner::sign($this->rawNotice($other, messageId: '<notice-2@airbank.cz>'), ['key' => self::$bankKey['private']]);
        [$scanner] = $this->scanner([$this->message($raw, $other, $foreign, messageId: '<notice-2@airbank.cz>')]);
        self::assertSame(1, $this->scan($scanner)['processed']);
        self::assertSame('dkim_local', $this->row('<notice-2@airbank.cz>')['auth_method']);
    }

    public function testMailThatNoBankProviderWouldTakeIsNotCheckedAndCostsNoDnsLookup(): void
    {
        // A catch-all mailbox also receives plain mail; it goes the normal way and ends as parse_failed.
        $body = "Ahoj,\r\nnewsletter text\r\n";
        $raw = $this->rawNotice($body, 'News <news@newsletter.example>', '<news-1@newsletter.example>');
        $message = $this->message($raw, $body, [], 'News <news@newsletter.example>', '<news-1@newsletter.example>', 'Novinky');
        [$scanner, $imap, $dns] = $this->scanner([$message]);

        $summary = $this->scan($scanner);

        self::assertSame(0, $summary['security_rejected']);
        self::assertSame(1, $summary['errors']);
        self::assertSame([], $dns->lookups, 'no DNS lookups for unrelated mail');
        $row = $this->row('<news-1@newsletter.example>');
        self::assertSame('parse_failed', $row['status']);
        self::assertNull($row['auth_method']);
        self::assertNull($row['auth_detail']);
    }

    public function testSpoofedSenderOfAnotherDomainNeverReachesAProvider(): void
    {
        $body = $this->bodyText();
        $raw = DkimTestSigner::sign($this->rawNotice($body, 'Evil<info@evil.example>'), ['key' => self::$evilKey['private'], 'domain' => 'evil.example']);
        [$scanner] = $this->scanner([$this->message($raw, $body, [], 'Evil <info@evil.example>')], ['ab._domainkey.evil.example' => [self::$evilKey['txt']]]);

        $this->scan($scanner);

        self::assertSame('parse_failed', $this->row()['status'], 'the Air Bank parser does not take a notice from evil.example');
        self::assertSame(0, $this->transactionCount());
    }

    public function testTemporaryDnsFailureLeavesTheNoticeForTheNextScan(): void
    {
        $body = $this->bodyText();
        $message = $this->message($this->signedNotice($body), $body);

        [$scanner, $imap] = $this->scanner([$message], ['ab._domainkey.airbank.cz' => null]);
        $summary = $this->scan($scanner);
        self::assertSame(0, $summary['security_rejected']);
        self::assertSame(1, $summary['auth_deferred']);
        self::assertSame(0, $summary['errors']);
        self::assertSame('auth_deferred', $summary['details'][0]['status']);
        self::assertNull($this->row(), 'no record, so the message is looked at again');
        self::assertSame([], $imap->postProcessed, 'and the message in the mailbox is left alone');

        // DNS is back on the next run.
        [$scanner] = $this->scanner([$message]);
        $summary = $this->scan($scanner);
        self::assertSame(1, $summary['processed']);
        self::assertSame(0, $summary['auth_deferred']);
    }

    public function testRejectedNoticeCanBeReprocessedByDeletingItsRecord(): void
    {
        $body = $this->bodyText();
        $message = $this->message($this->signedNotice($body), $body);

        // First scan: the bank's key is not published (yet): rejected.
        [$scanner] = $this->scanner([$message], []);
        $this->scan($scanner);
        $row = $this->row();
        self::assertSame('security_rejected', $row['status']);
        self::assertSame('dkim_key_not_found', $row['auth_detail']);

        // A plain re-scan does not touch it again (deduplicated)...
        [$scanner] = $this->scanner([$message]);
        self::assertSame(1, $this->scan($scanner)['known_skipped']);
        self::assertSame(0, $this->transactionCount());

        // ...but deleting the record ("Smazat záznam") makes the next scan read the mail again.
        self::assertTrue($this->repo->deleteProcessedMessage($this->supplierId, (int) $row['id']));
        $summary = $this->scan($scanner);
        self::assertSame(1, $summary['processed']);
        self::assertSame(1, $this->transactionCount());
        self::assertSame('processed_success', $this->row()['status']);
    }

    public function testAuthResultIsRecordedOnEveryRecordPath(): void
    {
        $body = $this->bodyText();
        // Verified, but the target account is not mapped (mapping removed): match_failed.
        $this->db->pdo()->prepare('DELETE FROM bank_email_account_mappings WHERE imap_account_id = ?')->execute([$this->accountId]);
        [$scanner] = $this->scanner([$this->message($this->signedNotice($body), $body)]);
        $this->scan($scanner);
        $row = $this->row();
        self::assertSame('match_failed', $row['status']);
        self::assertSame('dkim_local', $row['auth_method']);
        self::assertSame('dkim_local_pass:airbank.cz', $row['auth_detail']);

        // Verified, but the parser cannot extract the payment: parse_failed.
        $unparsable = "zůstatek se zvýšil o částku 1,00 CZK.\r\nČástka: 1,00 CZK\r\n";
        $raw = DkimTestSigner::sign($this->rawNotice($unparsable, messageId: '<notice-3@airbank.cz>'), ['key' => self::$bankKey['private']]);
        [$scanner] = $this->scanner([$this->message($raw, $unparsable, [], messageId: '<notice-3@airbank.cz>')]);
        $this->scan($scanner);
        $row = $this->row('<notice-3@airbank.cz>');
        self::assertSame('parse_failed', $row['status']);
        self::assertSame('dkim_local_pass:airbank.cz', $row['auth_detail']);
    }

    public function testVerificationDetailIsExposedInTheProcessedMessageList(): void
    {
        $body = $this->bodyText();
        // A second, unsigned notice (its text differs, otherwise it would be deduplicated as the same mail).
        $otherBody = str_replace('100000000001', '100000000002', $body);
        [$scanner] = $this->scanner([
            $this->message($this->signedNotice($body), $body),
            $this->message($this->rawNotice($otherBody, messageId: '<notice-2@airbank.cz>'), $otherBody, [], messageId: '<notice-2@airbank.cz>'),
        ]);
        $this->scan($scanner);

        $byMessage = [];
        foreach ($this->repo->processedMessages($this->supplierId, 100) as $item) {
            if ($item['imap_account_id'] === $this->accountId) {
                $byMessage[$item['message_id']] = $item;
            }
        }
        self::assertSame('dkim_local', $byMessage['<notice-1@airbank.cz>']['auth_method']);
        self::assertSame('dkim_local_pass:airbank.cz', $byMessage['<notice-1@airbank.cz>']['auth_detail']);
        self::assertSame('security_rejected', $byMessage['<notice-2@airbank.cz>']['status']);
        self::assertSame('dkim_no_signature', $byMessage['<notice-2@airbank.cz>']['auth_detail']);
    }

    public function testNoAuthenticityRequirementMeansNoVerification(): void
    {
        $this->configureAccount(null, requireAuth: false);
        $body = $this->bodyText();
        [$scanner, , $dns] = $this->scanner([$this->message($this->rawNotice($body), $body)]);

        $summary = $this->scan($scanner);

        self::assertSame(1, $summary['processed']);
        self::assertSame([], $dns->lookups);
        $row = $this->row();
        self::assertNull($row['auth_method']);
        self::assertNull($row['auth_detail']);
    }
}
