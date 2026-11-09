<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\EmailNotice;

use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Repository\DataboxEmailNoticeRepository;
use MyInvoice\Repository\DataboxMessageRepository;
use MyInvoice\Repository\DataboxRepository;
use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\ImapMailboxClientInterface;
use MyInvoice\Service\Databox\IsdsEnvironment;
use Psr\Log\LoggerInterface;

/**
 * Watches the data boxes of a supplier through the notification e-mails of the data box system.
 *
 * Reads the latest messages of the configured mailbox folder WITHOUT marking them read, recognises each notice
 * ({@see DatovkaNoticeParser}), proves it genuine ({@see DatovkaNoticeAuthenticator}) and records the announced message in the
 * box named as its recipient. Nothing here logs in to the data box system, so nothing is ever delivered by reading.
 *
 * Every e-mail is logged once (table databox_email_notices) and then skipped, except the two outcomes that can change
 * by themselves: a notice for a box that has not been added yet, and a notice the parser could not read.
 */
final class DataboxEmailNoticeScanner
{
    /** Latest e-mails examined per run; far more than a notification folder collects between two runs. */
    public const MAX_MESSAGES = 100;
    private const RETRIED_STATUSES = ['unknown_box', 'parse_failed'];

    public function __construct(
        private readonly DataboxEmailNoticeRepository $notices,
        private readonly BankEmailNoticeRepository $imapAccounts,
        private readonly ImapMailboxClientInterface $imap,
        private readonly DatovkaNoticeParser $parser,
        private readonly DatovkaNoticeAuthenticator $authenticator,
        private readonly DataboxRepository $boxes,
        private readonly DataboxMessageRepository $messages,
        private readonly DataboxEmailNoticeAlert $alert,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{supplier_id:int, status:string, message:?string, fetched:int, created:int, linked:int, known_skipped:int,
     *               ignored:int, rejected:int, unknown_box:int, parse_failed:int, auth_deferred:int, errors:int,
     *               alerts_sent:int, alert_errors:int, details:list<array<string,mixed>>}
     */
    public function scanSupplier(int $supplierId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $summary = [
            'supplier_id' => $supplierId, 'status' => 'ok', 'message' => null, 'fetched' => 0, 'created' => 0, 'linked' => 0,
            'known_skipped' => 0, 'ignored' => 0, 'rejected' => 0, 'unknown_box' => 0, 'parse_failed' => 0, 'auth_deferred' => 0,
            'errors' => 0, 'alerts_sent' => 0, 'alert_errors' => 0, 'details' => [],
        ];

        $settings = $this->notices->settings($supplierId);
        try {
            $imapSettings = $this->mailboxSettings($supplierId, $settings);
        } catch (\RuntimeException $e) {
            return $this->finish($summary, $now, 'error', $e->getMessage());
        }

        try {
            $messages = $this->imap->latest($imapSettings, self::MAX_MESSAGES);
        } catch (\Throwable $e) {
            return $this->finish($summary, $now, 'error', 'Schránku e-mailů se nepodařilo přečíst: ' . $e->getMessage());
        }
        $summary['fetched'] = count($messages);

        // Oldest first, so the messages (and their alerts) are created in the order they arrived.
        foreach (array_reverse($messages) as $message) {
            try {
                $this->process($supplierId, $settings, $message, $now, $summary);
            } catch (\Throwable $e) {
                $summary['errors']++;
                $summary['details'][] = ['status' => 'error', 'reason' => $e->getMessage()];
                $this->logger->warning('Data box notice e-mail could not be processed', ['supplier_id' => $supplierId, 'error' => $e->getMessage()]);
            }
        }

        return $this->finish($summary, $now, 'ok', self::digest($summary));
    }

    /**
     * The IMAP account of the bank notices (with its decrypted password), pointed at the data box folder.
     * A folder with notices of both kinds works as well: each scanner skips what it does not understand.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     * @throws \RuntimeException with a Czech message for the settings page
     */
    private function mailboxSettings(int $supplierId, array $settings): array
    {
        if ($settings['imap_account_id'] === null) {
            throw new \RuntimeException('Není vybraný IMAP účet.');
        }
        $account = $this->imapAccounts->imapAccount($supplierId, $settings['imap_account_id'], true);
        if ($account === null) {
            throw new \RuntimeException('Vybraný IMAP účet už neexistuje.');
        }
        foreach (['host', 'username', 'password'] as $required) {
            if (trim((string) ($account[$required] ?? '')) === '') {
                throw new \RuntimeException("IMAP účet není nakonfigurován: chybí {$required}.");
            }
        }
        // The raw header block is what DKIM hashes; the client only captures it when the authenticity check is on.
        return ['folder' => $settings['folder'], 'require_email_auth' => true] + $account;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $summary
     */
    private function process(int $supplierId, array $settings, BankEmailNoticeMessage $message, \DateTimeImmutable $now, array &$summary): void
    {
        $key = self::dedupKey($message);
        $known = $this->notices->notice($supplierId, $key);
        if ($known !== null && !in_array($known['status'], self::RETRIED_STATUSES, true)) {
            $summary['known_skipped']++;
            return;
        }

        $log = [
            'supplier_id' => $supplierId, 'imap_account_id' => $settings['imap_account_id'], 'dedup_key' => $key,
            'message_id' => $message->messageId, 'received_at' => $message->date !== null ? self::local($message->date)->format('Y-m-d H:i:s') : null,
        ];
        $note = function (string $status, string $detail, array $more = []) use (&$summary, $log): void {
            $this->notices->recordNotice($log + ['status' => $status, 'detail' => $detail] + $more);
            $summary[$status]++;
            $summary['details'][] = ['status' => $status, 'reason' => $detail] + array_intersect_key($more, ['dm_id' => 1]);
        };

        $parse = $this->parser->parse($message->subject, $message->rawHeader, $message->raw, $message->date);
        if ($parse->outcome === DatovkaNoticeParse::IGNORED) {
            $note('ignored', $parse->detail);
            return;
        }
        if ($parse->outcome === DatovkaNoticeParse::FAILED) {
            $note('parse_failed', $parse->detail);
            return;
        }
        $notice = $parse->notice;

        $box = $this->boxes->findByBoxId($supplierId, IsdsEnvironment::PRODUCTION, $notice->recipientBoxId);
        if ($box === null) {
            // Nothing is imported or trusted yet; the notice is looked at again once the box has been added.
            $note('unknown_box', 'recipient:' . $notice->recipientBoxId, ['dm_id' => $notice->dmId]);
            return;
        }

        $auth = $this->authenticator->verify($message->sender, $message->rawHeader, $message->raw);
        if ($auth->isDeferred()) {
            // Temporary DNS failure: leave no record, so the next run examines the e-mail again instead of rejecting it for good.
            $summary['auth_deferred']++;
            $summary['details'][] = ['status' => 'auth_deferred', 'reason' => $auth->detail];
            return;
        }
        if (!$auth->passed()) {
            $note('rejected', $auth->detail, ['dm_id' => $notice->dmId]);
            return;
        }

        $arrivedAt = self::local($notice->noticedAt ?? $now);
        $stored = $this->messages->insertFromEmailNotice((int) $box['id'], [
            'dm_id' => $notice->dmId,
            'sender_box_id' => $notice->senderBoxId,
            'sender_name' => $notice->senderName,
            // dmSenderType 0 = issued by the system itself; the e-mail does not tell the type of any other sender.
            'sender_type' => $notice->kind === DatovkaNotice::KIND_SYSTEM ? 0 : null,
            'recipient_box_id' => $notice->recipientBoxId,
            'recipient_name' => $notice->recipientName,
            'annotation' => $notice->annotation,
            'delivery_time' => $arrivedAt,
        ], $now);
        $note('linked', $stored['is_new'] ? 'created' : 'already_known', ['dm_id' => $notice->dmId, 'databox_message_id' => $stored['id']]);
        if (!$stored['is_new']) {
            return;
        }
        $summary['created']++;

        if ($settings['alert_email'] !== null && $settings['alert_email'] !== '') {
            try {
                $this->alert->send((string) $settings['alert_email'], $box, $notice, $stored['id'], $arrivedAt, $stored['is_tax_office']);
                $summary['alerts_sent']++;
            } catch (\Throwable $e) {
                // The message is stored and shows on the dashboard; a mail failure must not stop the scan.
                $summary['alert_errors']++;
                $summary['details'][] = ['status' => 'alert_failed', 'reason' => $e->getMessage()];
                $this->logger->warning('Data box alert e-mail failed', ['supplier_id' => $supplierId, 'dm_id' => $notice->dmId, 'error' => $e->getMessage()]);
            }
        }
    }

    /** The key a mailbox e-mail is remembered by: its Message-ID, or a hash of the whole e-mail when it has none. */
    private static function dedupKey(BankEmailNoticeMessage $message): string
    {
        $id = trim((string) $message->messageId);
        return $id !== '' ? hash('sha256', 'id:' . $id) : hash('sha256', 'raw:' . $message->rawHeader . "\r\n" . $message->raw);
    }

    private static function local(\DateTimeInterface $time): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function finish(array $summary, \DateTimeImmutable $now, string $status, ?string $message): array
    {
        $summary['status'] = $status;
        $summary['message'] = $message;
        $this->notices->updateScanStatus($summary['supplier_id'], $status, $message, $now);
        return $summary;
    }

    /** What a clean-looking scan still wants the owner to know, or null. @param array<string,mixed> $summary */
    private static function digest(array $summary): ?string
    {
        $parts = [];
        foreach ([
            'rejected' => 'odmítnuto pro neplatný podpis',
            'unknown_box' => 'upozornění pro schránku, která není přidaná',
            'parse_failed' => 'nečitelných upozornění',
            'errors' => 'chyb zpracování',
            'alert_errors' => 'neodeslaných e-mailových upozornění',
        ] as $key => $label) {
            if ($summary[$key] > 0) {
                $parts[] = "{$label}: {$summary[$key]}";
            }
        }
        return $parts === [] ? null : ucfirst(implode(', ', $parts)) . '.';
    }
}
