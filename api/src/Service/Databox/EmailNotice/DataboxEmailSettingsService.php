<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\EmailNotice;

use MyInvoice\Repository\BankEmailNoticeRepository;
use MyInvoice\Repository\DataboxEmailNoticeRepository;
use MyInvoice\Service\Databox\DataboxException;

/**
 * Settings page of the notification e-mail watcher: which mailbox to read, where to send alerts, the manual scan.
 * The mailbox is one of the IMAP accounts already set up for the bank notices; its credentials never leave that table.
 */
final class DataboxEmailSettingsService
{
    public function __construct(
        private readonly DataboxEmailNoticeRepository $notices,
        private readonly BankEmailNoticeRepository $imapAccounts,
        private readonly DataboxEmailNoticeScanner $scanner,
    ) {}

    /**
     * @return array{settings:array<string,mixed>, imap_accounts:list<array<string,mixed>>}
     */
    public function present(int $supplierId): array
    {
        $accounts = array_map(static fn (array $a): array => [
            'id' => $a['id'], 'name' => $a['name'], 'host' => $a['host'], 'username' => $a['username'], 'has_password' => $a['has_password'],
        ], $this->imapAccounts->imapAccounts($supplierId));
        return ['settings' => $this->notices->settings($supplierId), 'imap_accounts' => $accounts];
    }

    /**
     * @param array{imap_account_id?:mixed, folder?:mixed, enabled?:mixed, alert_email?:mixed} $input fields that are absent keep their value
     * @return array{settings:array<string,mixed>, imap_accounts:list<array<string,mixed>>}
     * @throws DataboxException
     */
    public function save(int $supplierId, array $input): array
    {
        $in = $input + $this->notices->settings($supplierId);

        $accountId = self::intOrNull($in['imap_account_id']);
        if ($accountId !== null && $this->imapAccounts->imapAccount($supplierId, $accountId) === null) {
            throw new DataboxException('validation_failed', 'Vybraný IMAP účet neexistuje.', 400);
        }

        $folder = trim((string) $in['folder']);
        if ($folder === '' || mb_strlen($folder) > 190 || preg_match('/[\x00-\x1F\x7F]/', $folder) === 1) {
            throw new DataboxException('validation_failed', 'Zadejte název složky v poštovní schránce (nejvýše 190 znaků).', 400);
        }

        $enabled = filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN);
        if ($enabled && $accountId === null) {
            throw new DataboxException('validation_failed', 'Pro zapnutí kontroly vyberte IMAP účet.', 400);
        }

        $alert = trim((string) $in['alert_email']);
        if ($alert !== '' && (mb_strlen($alert) > 255 || filter_var($alert, FILTER_VALIDATE_EMAIL) === false)) {
            throw new DataboxException('validation_failed', 'E-mailová adresa pro upozornění není platná.', 400);
        }

        $this->notices->saveSettings($supplierId, $accountId, $folder, $enabled, $alert !== '' ? $alert : null);
        return $this->present($supplierId);
    }

    /**
     * "Zkontrolovat teď": one scan with the saved settings, whether or not the cron is switched on.
     *
     * @return array{summary:array<string,mixed>, settings:array<string,mixed>, imap_accounts:list<array<string,mixed>>}
     */
    public function scan(int $supplierId): array
    {
        return ['summary' => $this->scanner->scanSupplier($supplierId)] + $this->present($supplierId);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
