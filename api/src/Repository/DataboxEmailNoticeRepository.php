<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Settings of the notification e-mail watcher and the log of the e-mails it processed
 * (tables databox_email_settings, databox_email_notices). The log never holds e-mail content.
 */
final class DataboxEmailNoticeRepository
{
    public const STATUSES = ['linked', 'ignored', 'rejected', 'unknown_box', 'parse_failed'];

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed> the supplier's settings, or the disabled defaults when none were saved yet */
    public function settings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM databox_email_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'supplier_id' => $supplierId, 'imap_account_id' => null, 'folder' => 'INBOX', 'enabled' => 0, 'alert_email' => null,
            'last_scan_at' => null, 'last_scan_status' => null, 'last_scan_message' => null,
        ];
        return [
            'supplier_id' => (int) $row['supplier_id'],
            'imap_account_id' => $row['imap_account_id'] !== null ? (int) $row['imap_account_id'] : null,
            'folder' => (string) $row['folder'],
            'enabled' => (bool) $row['enabled'],
            'alert_email' => $row['alert_email'],
            'last_scan_at' => $row['last_scan_at'],
            'last_scan_status' => $row['last_scan_status'],
            'last_scan_message' => $row['last_scan_message'],
        ];
    }

    /** @return list<int> suppliers whose watcher is switched on */
    public function enabledSupplierIds(): array
    {
        return array_map('intval', $this->db->pdo()->query('SELECT supplier_id FROM databox_email_settings WHERE enabled = 1 ORDER BY supplier_id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function saveSettings(int $supplierId, ?int $imapAccountId, string $folder, bool $enabled, ?string $alertEmail): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO databox_email_settings (supplier_id, imap_account_id, folder, enabled, alert_email) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE imap_account_id = VALUES(imap_account_id), folder = VALUES(folder),
                    enabled = VALUES(enabled), alert_email = VALUES(alert_email)'
        )->execute([$supplierId, $imapAccountId, $folder, (int) $enabled, $alertEmail]);
    }

    public function updateScanStatus(int $supplierId, string $status, ?string $message, \DateTimeImmutable $now): void
    {
        $this->db->pdo()->prepare(
            'UPDATE databox_email_settings SET last_scan_at = ?, last_scan_status = ?, last_scan_message = ? WHERE supplier_id = ?'
        )->execute([$now->format('Y-m-d H:i:s'), $status, $message !== null ? mb_substr($message, 0, 500) : null, $supplierId]);
    }

    /** @return array<string,mixed>|null the log row of an e-mail */
    public function notice(int $supplierId, string $dedupKey): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM databox_email_notices WHERE supplier_id = ? AND dedup_key = ?');
        $stmt->execute([$supplierId, $dedupKey]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Log the outcome of one e-mail. An e-mail that is looked at again (unknown box added since, parser fixed) updates its row.
     *
     * @param array{supplier_id:int, imap_account_id:?int, dedup_key:string, message_id:?string, received_at:?string,
     *              status:string, detail:?string, dm_id?:?string, databox_message_id?:?int} $data
     */
    public function recordNotice(array $data): void
    {
        if (!in_array($data['status'], self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown notice status ' . $data['status']);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO databox_email_notices
                (supplier_id, imap_account_id, dedup_key, message_id, received_at, status, detail, dm_id, databox_message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE imap_account_id = VALUES(imap_account_id), status = VALUES(status), detail = VALUES(detail),
                    dm_id = VALUES(dm_id), databox_message_id = VALUES(databox_message_id)'
        )->execute([
            $data['supplier_id'], $data['imap_account_id'], $data['dedup_key'],
            $data['message_id'] !== null ? mb_substr($data['message_id'], 0, 255) : null,
            $data['received_at'], $data['status'],
            $data['detail'] !== null ? mb_substr($data['detail'], 0, 255) : null,
            $data['dm_id'] ?? null, $data['databox_message_id'] ?? null,
        ]);
    }
}
