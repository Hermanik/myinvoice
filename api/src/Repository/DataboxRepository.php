<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Connected data boxes and their ISDS logins (tables databoxes, databox_credentials).
 * Passwords are stored already encrypted (see DataboxService); this class never decrypts.
 */
final class DataboxRepository
{
    private const BOX_UPDATABLE = [
        'label', 'is_active', 'allow_delivering_sync',
        'last_sync_at', 'last_sync_status', 'last_sync_message', 'sync_paused_until',
    ];

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> boxes of the supplier, each with its credentials (password_enc included) */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM databoxes WHERE supplier_id = ? ORDER BY label, id');
        $stmt->execute([$supplierId]);
        return $this->withCredentials($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, ?int $supplierId = null): ?array
    {
        $sql = 'SELECT * FROM databoxes WHERE id = ?';
        $params = [$id];
        if ($supplierId !== null) {
            $sql .= ' AND supplier_id = ?';
            $params[] = $supplierId;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->withCredentials([$row])[0];
    }

    /** @return array<string,mixed>|null */
    public function findByBoxId(int $supplierId, string $environment, string $dbId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM databoxes WHERE supplier_id = ? AND environment = ? AND db_id = ?');
        $stmt->execute([$supplierId, $environment, $dbId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->find((int) $id);
    }

    /** Active boxes of every supplier - the cron worklist. @return list<array<string,mixed>> */
    public function allActive(): array
    {
        $rows = $this->db->pdo()->query('SELECT * FROM databoxes WHERE is_active = 1 ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        return $this->withCredentials($rows);
    }

    public function insert(int $supplierId, string $label, string $environment, string $dbId, string $dbType, ?string $ownerName, bool $allowDeliveringSync): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO databoxes (supplier_id, label, environment, db_id, db_type, owner_name, allow_delivering_sync)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $label, $environment, $dbId, $dbType, $ownerName, (int) $allowDeliveringSync]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $fields only columns from BOX_UPDATABLE are written */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $params = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::BOX_UPDATABLE, true)) {
                throw new \InvalidArgumentException("Column {$column} is not updatable.");
            }
            $sets[] = "{$column} = ?";
            $params[] = is_bool($value) ? (int) $value : $value;
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $this->db->pdo()->prepare('UPDATE databoxes SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    public function delete(int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM databoxes WHERE id = ?')->execute([$id]);
    }

    public function hasActive(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM databoxes WHERE supplier_id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    public function messageCount(int $boxId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM databox_messages WHERE databox_id = ?');
        $stmt->execute([$boxId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Logins whose ISDS password expires within $days (or already has): the web services refuse an expired password.
     *
     * @return list<array{box_id:int, label:string, purpose:string, expires_at:string}>
     */
    public function expiringLogins(int $supplierId, int $days): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT b.id, b.label, c.purpose, c.password_expires_at
               FROM databox_credentials c JOIN databoxes b ON b.id = c.databox_id
              WHERE b.supplier_id = ? AND b.is_active = 1 AND c.password_expires_at IS NOT NULL
                AND c.password_expires_at < DATE_ADD(NOW(), INTERVAL ' . max(1, $days) . ' DAY)
              ORDER BY c.password_expires_at'
        );
        $stmt->execute([$supplierId]);
        return array_map(
            static fn (array $r): array => ['box_id' => (int) $r['id'], 'label' => (string) $r['label'], 'purpose' => (string) $r['purpose'], 'expires_at' => (string) $r['password_expires_at']],
            $stmt->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /** Insert or replace the login of one purpose. The check columns are reset by the caller via {@see recordCheck()}. */
    public function saveCredential(int $boxId, string $purpose, string $username, string $passwordEnc): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO databox_credentials (databox_id, purpose, username, password_enc)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE username = VALUES(username), password_enc = VALUES(password_enc)'
        );
        $stmt->execute([$boxId, $purpose, $username, $passwordEnc]);
    }

    public function deleteCredential(int $boxId, string $purpose): void
    {
        $this->db->pdo()->prepare('DELETE FROM databox_credentials WHERE databox_id = ? AND purpose = ?')->execute([$boxId, $purpose]);
    }

    /**
     * Result of a connection test of one login.
     *
     * @param array{user_type?:?string, user_privils?:?int, password_expires_at?:?string} $info only on success
     */
    public function recordCheck(int $boxId, string $purpose, string $status, ?string $message, array $info = []): void
    {
        $sets = 'last_check_at = NOW(), last_check_status = ?, last_check_message = ?';
        $params = [$status, $message !== null ? mb_substr($message, 0, 500) : null];
        if ($status === 'ok') {
            $sets .= ', user_type = ?, user_privils = ?, password_expires_at = ?';
            array_push($params, $info['user_type'] ?? null, $info['user_privils'] ?? null, $info['password_expires_at'] ?? null);
        }
        $params[] = $boxId;
        $params[] = $purpose;
        $this->db->pdo()->prepare("UPDATE databox_credentials SET {$sets} WHERE databox_id = ? AND purpose = ?")->execute($params);
    }

    /**
     * @param list<array<string,mixed>> $boxes
     * @return list<array<string,mixed>>
     */
    private function withCredentials(array $boxes): array
    {
        if ($boxes === []) {
            return [];
        }
        $ids = array_map(static fn (array $b): int => (int) $b['id'], $boxes);
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM databox_credentials WHERE databox_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);
        $byBox = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $cred) {
            $byBox[(int) $cred['databox_id']][(string) $cred['purpose']] = $cred;
        }
        foreach ($boxes as &$box) {
            $box['id'] = (int) $box['id'];
            $box['supplier_id'] = (int) $box['supplier_id'];
            $box['is_active'] = (bool) $box['is_active'];
            $box['allow_delivering_sync'] = (bool) $box['allow_delivering_sync'];
            $box['credentials'] = $byBox[$box['id']] ?? [];
        }
        unset($box);
        return $boxes;
    }
}
