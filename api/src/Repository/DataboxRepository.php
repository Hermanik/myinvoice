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
        'label', 'is_active', 'allow_delivering_sync', 'accountant_visible',
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

    /**
     * Boxes the owner opened to the accountant role. Deliberately WITHOUT their logins (`credentials` is empty): that role
     * never needs the encrypted passwords, so they are not even read.
     *
     * @return list<array<string,mixed>>
     */
    public function listAccountantVisible(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM databoxes WHERE supplier_id = ? AND accountant_visible = 1 ORDER BY label, id');
        $stmt->execute([$supplierId]);
        return array_map(fn (array $box): array => $this->normalize($box) + ['credentials' => []], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function hasAccountantVisible(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM databoxes WHERE supplier_id = ? AND accountant_visible = 1 LIMIT 1');
        $stmt->execute([$supplierId]);
        return $stmt->fetchColumn() !== false;
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

    /** @param 'isds'|'email' $connectionMode */
    public function insert(int $supplierId, string $label, string $environment, string $dbId, string $dbType, ?string $ownerName, bool $allowDeliveringSync, string $connectionMode = 'isds'): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO databoxes (supplier_id, label, environment, connection_mode, db_id, db_type, owner_name, allow_delivering_sync)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $label, $environment, $connectionMode, $dbId, $dbType, $ownerName, (int) $allowDeliveringSync]);
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
    public function saveCredential(int $boxId, string $purpose, string $username, #[\SensitiveParameter] string $passwordEnc): void
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
            // A working login clears the lockout protection (sticky flag, failure counter, wait time).
            $sets .= ', auth_failures = 0, retry_after = NULL, user_type = ?, user_privils = ?, password_expires_at = ?';
            array_push($params, $info['user_type'] ?? null, $info['user_privils'] ?? null, $info['password_expires_at'] ?? null);
        }
        $params[] = $boxId;
        $params[] = $purpose;
        $this->db->pdo()->prepare("UPDATE databox_credentials SET {$sets} WHERE databox_id = ? AND purpose = ?")->execute($params);
    }

    /**
     * ISDS refused a login. Counts the failure and sets the wait before the next MANUAL attempt (5 min, then doubling up
     * to 24 h; for a locked account at least until ISDS unblocks it). Pass `$status` to flag the stored login (sticky until
     * a successful login) or null when the refused password was a replacement the owner just typed.
     */
    public function registerAuthFailure(int $boxId, string $purpose, ?string $status, string $message, \DateTimeImmutable $now, ?\DateTimeImmutable $blockedUntil = null): void
    {
        $ts = $now->format('Y-m-d H:i:s');
        // retry_after is assigned before auth_failures so it uses the count of the failures that came before this one.
        $sql = 'UPDATE databox_credentials SET last_check_at = ?,
                    retry_after = GREATEST(DATE_ADD(?, INTERVAL LEAST(300 * POW(2, LEAST(auth_failures, 10)), 86400) SECOND), ?),
                    auth_failures = LEAST(auth_failures + 1, 65000)';
        $params = [$ts, $ts, ($blockedUntil ?? $now)->format('Y-m-d H:i:s')];
        if ($status !== null) {
            $sql .= ', last_check_status = ?, last_check_message = ?';
            array_push($params, $status, mb_substr($message, 0, 500));
        }
        $this->db->pdo()->prepare($sql . ' WHERE databox_id = ? AND purpose = ?')->execute([...$params, $boxId, $purpose]);
    }

    /** Cached rights of a login, refreshed by a read-only GetUserInfoFromLogin2 call. */
    public function saveRights(int $boxId, string $purpose, ?string $userType, int $privileges): void
    {
        $this->db->pdo()->prepare('UPDATE databox_credentials SET user_type = ?, user_privils = ? WHERE databox_id = ? AND purpose = ?')
            ->execute([$userType, $privileges, $boxId, $purpose]);
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
        return array_map(function (array $box) use ($byBox): array {
            $box = $this->normalize($box);
            $box['credentials'] = $byBox[$box['id']] ?? [];
            return $box;
        }, $boxes);
    }

    /** @param array<string,mixed> $box a databoxes row @return array<string,mixed> */
    private function normalize(array $box): array
    {
        $box['id'] = (int) $box['id'];
        $box['supplier_id'] = (int) $box['supplier_id'];
        $box['is_active'] = (bool) $box['is_active'];
        $box['allow_delivering_sync'] = (bool) $box['allow_delivering_sync'];
        $box['accountant_visible'] = (bool) $box['accountant_visible'];
        return $box;
    }
}
