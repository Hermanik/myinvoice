<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Databox\DeliveryDates;
use MyInvoice\Service\Databox\TaxOfficeMessageClassifier;

/**
 * Stored message envelopes, ZFO blobs and the "tax filing -> message" links
 * (tables databox_messages, databox_message_files, tax_submission_databox_messages).
 */
final class DataboxMessageRepository
{
    /** Columns of a list row: everything but the bulky delivery_info_xml. */
    private const LIST_COLUMNS = 'm.id, m.databox_id, m.direction, m.source, m.dm_id, m.sender_box_id, m.sender_name, m.sender_type,
        m.recipient_box_id, m.recipient_name, m.annotation, m.to_hands, m.sender_ref_number, m.recipient_ref_number,
        m.sender_ident, m.recipient_ident, m.personal_delivery, m.allow_subst_delivery, m.message_type, m.status,
        m.attachment_size_kb, m.delivery_time, m.acceptance_time, m.is_tax_office, m.tax_office_kind, m.first_seen_at,
        m.last_seen_at, m.acknowledged_at, m.acknowledged_by, m.delivery_info_at, m.sent_by, m.sent_payload_json,
        EXISTS (SELECT 1 FROM databox_message_files f WHERE f.message_id = m.id AND f.kind = \'zfo\') AS has_zfo,
        EXISTS (SELECT 1 FROM databox_message_files f WHERE f.message_id = m.id AND f.kind = \'delivery_zfo\') AS has_delivery_zfo,
        (m.delivery_info_xml IS NOT NULL) AS has_delivery_info';

    /** Keeps the state of a row that is further along (4 in box, 5 fiction, 6 delivered, 7 read) than the one being written. */
    private const FORWARD_ONLY_STATUS = 'CASE WHEN VALUES(status) IS NULL THEN status WHEN status IN (%1$s) AND VALUES(status) IN (%1$s)'
        . ' THEN GREATEST(status, VALUES(status)) ELSE VALUES(status) END';

    /** After this many failed receipt fetches the message is left alone. */
    public const MAX_RECEIPT_ATTEMPTS = 8;

    public function __construct(private readonly Connection $db) {}

    /**
     * Insert a new envelope or refresh an existing one in a single statement (the unique key is
     * databox_id + direction + dm_id). A fresh envelope never erases something already known - e.g. a list that lacks the
     * acceptance time yet - and never touches acknowledgement, stored files or the delivery receipt.
     *
     * $source says who vouches for the envelope. The synchronisation ('isds') is the live truth: it replaces the source and
     * the state outright. A signed ZFO upload ('zfo') is authoritative for the data it carries but may be older than what the
     * row already knows, so it never moves the state backwards (4 -> 5 -> 6 -> 7) and never takes the source away from 'isds'.
     *
     * @param array<string,mixed> $envelope as returned by IsdsResponseParser::messageRecords()
     * @param 'isds'|'zfo' $source
     * @return array{id:int, is_new:bool, is_tax_office:bool}
     */
    public function upsertEnvelope(int $boxId, string $direction, array $envelope, \DateTimeImmutable $now, string $source = 'isds'): array
    {
        $values = [
            'sender_box_id' => $envelope['sender_box_id'] ?? null,
            'sender_name' => $this->cut($envelope['sender_name'] ?? null, 255),
            'sender_type' => $envelope['sender_type'] ?? null,
            'recipient_box_id' => $envelope['recipient_box_id'] ?? null,
            'recipient_name' => $this->cut($envelope['recipient_name'] ?? null, 255),
            'annotation' => $this->cut($envelope['annotation'] ?? null, 255),
            'to_hands' => $this->cut($envelope['to_hands'] ?? null, 30),
            'sender_ref_number' => $this->cut($envelope['sender_ref_number'] ?? null, 50),
            'recipient_ref_number' => $this->cut($envelope['recipient_ref_number'] ?? null, 50),
            'sender_ident' => $this->cut($envelope['sender_ident'] ?? null, 50),
            'recipient_ident' => $this->cut($envelope['recipient_ident'] ?? null, 50),
            'personal_delivery' => (int) ($envelope['personal_delivery'] ?? false),
            'allow_subst_delivery' => isset($envelope['allow_subst_delivery']) ? (int) $envelope['allow_subst_delivery'] : null,
            'message_type' => $envelope['message_type'] ?? null,
            'status' => $envelope['status'] ?? null,
            'attachment_size_kb' => $envelope['attachment_size_kb'] ?? null,
            'delivery_time' => $this->dt($envelope['delivery_time'] ?? null),
            'acceptance_time' => $this->dt($envelope['acceptance_time'] ?? null),
        ];
        // Only a real envelope names its sender; the classification is not recomputed from a status-only refresh.
        $isTaxOffice = false;
        if ($direction === 'received' && ($values['sender_box_id'] !== null || $values['sender_name'] !== null)) {
            $class = TaxOfficeMessageClassifier::classify($envelope);
            $isTaxOffice = $class['is_tax_office'];
            $values['is_tax_office'] = (int) $isTaxOffice;
            $values['tax_office_kind'] = $class['kind'];
        }

        $ts = $now->format('Y-m-d H:i:s');
        $row = ['databox_id' => $boxId, 'direction' => $direction, 'source' => $source, 'dm_id' => (string) $envelope['dm_id']] + $values
            + ['first_seen_at' => $ts, 'last_seen_at' => $ts];
        $updates = ['id = LAST_INSERT_ID(id)'];
        foreach (array_keys($values) as $column) {
            $updates[] = $column === 'status' && $source !== 'isds'
                ? 'status = ' . sprintf(self::FORWARD_ONLY_STATUS, implode(', ', DeliveryDates::PROGRESS))
                : "{$column} = COALESCE(VALUES({$column}), {$column})";
        }
        $updates[] = 'last_seen_at = VALUES(last_seen_at)';
        // A message first announced by e-mail is now confirmed by the data box system itself; an uploaded ZFO completes
        // an e-mail row but never replaces the box system's own confirmation.
        $updates[] = "source = IF(source = 'isds', 'isds', VALUES(source))";

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO databox_messages (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        );
        $stmt->execute(array_values($row));
        // Affected rows: 1 = inserted, 2 = updated, 0 = identical values.
        return ['id' => (int) $this->db->pdo()->lastInsertId(), 'is_new' => $stmt->rowCount() === 1, 'is_tax_office' => $isTaxOffice];
    }

    /**
     * Record a received message announced by a notification e-mail. Everything the e-mail does not say stays empty. A message
     * the box already has (e.g. from the synchronisation or an uploaded ZFO) is left exactly as it is.
     *
     * @param array{dm_id:string, sender_box_id:string, sender_name:?string, sender_type:?int, recipient_box_id:string,
     *              recipient_name:?string, annotation:?string, personal_delivery?:bool, delivery_time:\DateTimeInterface} $notice
     * @param bool $acknowledged the notice is history (its fiction day is over): the message is stored as already dealt with
     * @return array{id:int, is_new:bool, is_tax_office:bool}
     */
    public function insertFromEmailNotice(int $boxId, array $notice, \DateTimeImmutable $now, bool $acknowledged = false): array
    {
        $class = TaxOfficeMessageClassifier::classify($notice);
        $ts = $now->format('Y-m-d H:i:s');
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO databox_messages
                (databox_id, direction, source, dm_id, sender_box_id, sender_name, sender_type, recipient_box_id, recipient_name,
                 annotation, personal_delivery, status, delivery_time, is_tax_office, tax_office_kind, first_seen_at, last_seen_at, acknowledged_at)
             VALUES (?, 'received', 'email', ?, ?, ?, ?, ?, ?, ?, ?, " . DeliveryDates::STATE_DELIVERED_TO_BOX . ", ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $stmt->execute([
            $boxId, $notice['dm_id'], $notice['sender_box_id'], $this->cut($notice['sender_name'], 255), $notice['sender_type'],
            $notice['recipient_box_id'], $this->cut($notice['recipient_name'], 255), $this->cut($notice['annotation'], 255),
            (int) ($notice['personal_delivery'] ?? false), $this->dt($notice['delivery_time']), (int) $class['is_tax_office'], $class['kind'], $ts, $ts, $acknowledged ? $ts : null,
        ]);
        // Affected rows: 1 = inserted, 0 = the message was there already.
        return ['id' => (int) $this->db->pdo()->lastInsertId(), 'is_new' => $stmt->rowCount() === 1, 'is_tax_office' => $class['is_tax_office']];
    }

    /**
     * @param ?int $boxId one box, or null for every box of $supplierId (the combined view)
     * @param array{direction?:?string, unread_only?:bool, tax_office_only?:bool, search?:?string} $filters
     * @param ?int $supplierId when set, only boxes of this supplier are listed (tenant scope in SQL)
     * @return array{items:list<array<string,mixed>>, total:int}
     */
    public function list(?int $boxId, array $filters, int $limit, int $offset, ?int $supplierId = null): array
    {
        [$where, $params] = $this->boxScope($boxId, $supplierId);
        if (!empty($filters['direction'])) {
            $where[] = 'm.direction = ?';
            $params[] = $filters['direction'];
        }
        if (!empty($filters['unread_only'])) {
            $where[] = "m.direction = 'received' AND m.acknowledged_at IS NULL";
        }
        if (!empty($filters['tax_office_only'])) {
            $where[] = 'm.is_tax_office = 1';
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $where[] = '(m.annotation LIKE ? OR m.sender_name LIKE ? OR m.recipient_name LIKE ? OR m.dm_id LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $count = $this->db->pdo()->prepare("SELECT COUNT(*) FROM databox_messages m WHERE {$whereSql}");
        $count->execute($params);

        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::LIST_COLUMNS . " FROM databox_messages m WHERE {$whereSql}
              ORDER BY COALESCE(m.delivery_time, m.first_seen_at) DESC, m.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($params);
        return [
            'items' => array_map($this->normalize(...), $stmt->fetchAll(\PDO::FETCH_ASSOC)),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** @return array<string,mixed>|null the row plus the owning box's supplier_id */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::LIST_COLUMNS . ', b.supplier_id, m.delivery_info_xml
               FROM databox_messages m JOIN databoxes b ON b.id = m.databox_id WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->normalize($row);
    }

    /** @return array<string,mixed>|null */
    public function findByDmId(int $boxId, string $direction, string $dmId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM databox_messages WHERE databox_id = ? AND direction = ? AND dm_id = ?');
        $stmt->execute([$boxId, $direction, $dmId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : $this->find((int) $id);
    }

    /**
     * Runs the writes of $work as one unit: all of them are kept or none.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function atomically(callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function storeFile(int $messageId, string $kind, string $bytes): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO databox_message_files (message_id, kind, content, size_bytes, sha256, stored_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE content = VALUES(content), size_bytes = VALUES(size_bytes), sha256 = VALUES(sha256), stored_at = NOW()'
        );
        $stmt->bindValue(1, $messageId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $kind);
        $stmt->bindValue(3, $bytes, \PDO::PARAM_LOB);
        $stmt->bindValue(4, strlen($bytes), \PDO::PARAM_INT);
        $stmt->bindValue(5, hash('sha256', $bytes));
        $stmt->execute();
    }

    public function fileBytes(int $messageId, string $kind): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT content FROM databox_message_files WHERE message_id = ? AND kind = ?');
        $stmt->execute([$messageId, $kind]);
        $bytes = $stmt->fetchColumn();
        return $bytes === false ? null : (string) $bytes;
    }

    /** @return array{size_bytes:int, sha256:string, stored_at:string}|null */
    public function fileMeta(int $messageId, string $kind): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT size_bytes, sha256, stored_at FROM databox_message_files WHERE message_id = ? AND kind = ?');
        $stmt->execute([$messageId, $kind]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : ['size_bytes' => (int) $row['size_bytes'], 'sha256' => (string) $row['sha256'], 'stored_at' => (string) $row['stored_at']];
    }

    /**
     * Sent messages (of the last 90 days, ISDS drops older ones) whose delivery receipt is missing or older than the delivery it describes.
     *
     * @return list<array{id:int, dm_id:string}>
     */
    public function sentNeedingReceipt(int $boxId, int $limit, \DateTimeImmutable $now): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT m.id, m.dm_id FROM databox_messages m
              WHERE m.databox_id = ? AND m.direction = 'sent' AND m.status IN (4, 5, 6, 7, 8)
                AND COALESCE(m.delivery_time, m.first_seen_at) > DATE_SUB(?, INTERVAL 90 DAY)
                AND m.receipt_attempts < " . self::MAX_RECEIPT_ATTEMPTS . "
                AND (m.receipt_retry_at IS NULL OR m.receipt_retry_at <= ?)
                AND (m.delivery_info_at IS NULL
                     OR (m.acceptance_time IS NOT NULL AND m.delivery_info_at < m.acceptance_time)
                     OR (m.status IN (5, 6, 7, 8)
                         AND NOT EXISTS (SELECT 1 FROM databox_message_files f WHERE f.message_id = m.id AND f.kind = 'delivery_zfo')))
              ORDER BY m.receipt_attempts ASC, m.id DESC LIMIT " . max(1, $limit)
        );
        $ts = $now->format('Y-m-d H:i:s');
        $stmt->execute([$boxId, $ts, $ts]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'dm_id' => (string) $r['dm_id']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Lifecycle data learned from a delivery receipt. */
    public function updateState(int $messageId, ?int $status, ?\DateTimeInterface $deliveredToBox, ?\DateTimeInterface $accepted): void
    {
        $this->db->pdo()->prepare(
            'UPDATE databox_messages SET status = COALESCE(?, status), delivery_time = COALESCE(?, delivery_time),
                    acceptance_time = COALESCE(?, acceptance_time), last_seen_at = NOW() WHERE id = ?'
        )->execute([$status, $this->dt($deliveredToBox), $this->dt($accepted), $messageId]);
    }

    public function saveDeliveryInfo(int $messageId, string $xml): void
    {
        $this->db->pdo()->prepare('UPDATE databox_messages SET delivery_info_xml = ?, delivery_info_at = NOW() WHERE id = ?')->execute([$xml, $messageId]);
    }

    /** Both parts of the receipt (readable XML and sealed copy) are stored: forget the failures. */
    public function clearReceiptRetry(int $messageId): void
    {
        $this->db->pdo()->prepare('UPDATE databox_messages SET receipt_attempts = 0, receipt_retry_at = NULL WHERE id = ?')->execute([$messageId]);
    }

    /**
     * A receipt fetch failed: wait 1 h, 2 h, 4 h ... (at most 24 h) before the next try, and give up after
     * {@see MAX_RECEIPT_ATTEMPTS}. A message that never yields a receipt (e.g. already erased in ISDS, error 1219) must not
     * occupy the small per-run batch forever.
     */
    public function recordReceiptFailure(int $messageId, \DateTimeImmutable $now): void
    {
        $ts = $now->format('Y-m-d H:i:s');
        // retry_at is assigned before the counter so it uses the number of failures that came before this one.
        $this->db->pdo()->prepare(
            'UPDATE databox_messages SET receipt_retry_at = DATE_ADD(?, INTERVAL LEAST(3600 * POW(2, LEAST(receipt_attempts, 10)), 86400) SECOND),
                    receipt_attempts = LEAST(receipt_attempts + 1, 65000) WHERE id = ?'
        )->execute([$ts, $messageId]);
    }

    /** @param array<string,mixed> $payload */
    public function saveSentMeta(int $messageId, int $userId, array $payload): void
    {
        $this->db->pdo()->prepare('UPDATE databox_messages SET sent_by = ?, sent_payload_json = ? WHERE id = ?')
            ->execute([$this->userOrNull($userId), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $messageId]);
    }

    public function acknowledge(int $messageId, int $userId): void
    {
        $this->db->pdo()->prepare('UPDATE databox_messages SET acknowledged_at = COALESCE(acknowledged_at, NOW()), acknowledged_by = COALESCE(acknowledged_by, ?) WHERE id = ?')
            ->execute([$this->userOrNull($userId), $messageId]);
    }

    public function acknowledgeAll(?int $boxId, int $userId, ?int $supplierId = null): int
    {
        [$where, $params] = $this->boxScope($boxId, $supplierId);
        $stmt = $this->db->pdo()->prepare(
            "UPDATE databox_messages m SET m.acknowledged_at = NOW(), m.acknowledged_by = ?
              WHERE " . implode(' AND ', $where) . " AND m.direction = 'received' AND m.acknowledged_at IS NULL"
        );
        $stmt->execute([$this->userOrNull($userId), ...$params]);
        return $stmt->rowCount();
    }

    /**
     * WHERE parts limiting messages to one box and/or the boxes of one supplier.
     *
     * @return array{0:list<string>, 1:list<int>}
     */
    private function boxScope(?int $boxId, ?int $supplierId): array
    {
        if ($boxId === null && $supplierId === null) {
            throw new \InvalidArgumentException('A box or a supplier is required.');
        }
        $where = [];
        $params = [];
        if ($boxId !== null) {
            $where[] = 'm.databox_id = ?';
            $params[] = $boxId;
        }
        if ($supplierId !== null) {
            $where[] = 'm.databox_id IN (SELECT id FROM databoxes WHERE supplier_id = ?)';
            $params[] = $supplierId;
        }
        return [$where, $params];
    }

    /**
     * Unread received messages of the supplier for the dashboard feed. "Undelivered" counts the messages in state 4 except
     * those that exist only as a notification e-mail and whose fiction day has passed: they are presumed delivered
     * ({@see DeliveryDates::presumedDeliveryDate()}).
     *
     * @return array{unread:int, tax_office:int, undelivered:int, box_id:?int, latest_tax_office_annotation:?string}
     */
    public function unreadSummary(int $supplierId): array
    {
        $scope = "b.supplier_id = ? AND b.is_active = 1 AND m.direction = 'received' AND m.acknowledged_at IS NULL";
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS unread,
                    COALESCE(SUM(m.is_tax_office), 0) AS tax_office,
                    COALESCE(SUM(m.status = 4), 0) AS undelivered
               FROM databox_messages m JOIN databoxes b ON b.id = m.databox_id
              WHERE {$scope}"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Whether the fiction day of a message known from a notice has passed depends on the Czech working days: decided here.
        $notices = $this->db->pdo()->prepare(
            "SELECT m.status, m.source, m.delivery_time, m.allow_subst_delivery
               FROM databox_messages m JOIN databoxes b ON b.id = m.databox_id
              WHERE {$scope} AND m.status = 4 AND m.source = 'email'"
        );
        $notices->execute([$supplierId]);
        $undelivered = (int) ($row['undelivered'] ?? 0);
        $now = new \DateTimeImmutable();
        foreach ($notices->fetchAll(\PDO::FETCH_ASSOC) as $notice) {
            if (DeliveryDates::presumedDeliveryDate($this->normalize($notice), $now) !== null) {
                $undelivered--;
            }
        }

        $latest = $this->db->pdo()->prepare(
            "SELECT m.databox_id, m.annotation FROM databox_messages m JOIN databoxes b ON b.id = m.databox_id
              WHERE b.supplier_id = ? AND b.is_active = 1 AND m.direction = 'received' AND m.acknowledged_at IS NULL AND m.is_tax_office = 1
              ORDER BY COALESCE(m.delivery_time, m.first_seen_at) DESC LIMIT 1"
        );
        $latest->execute([$supplierId]);
        $l = $latest->fetch(\PDO::FETCH_ASSOC) ?: null;

        return [
            'unread' => (int) ($row['unread'] ?? 0),
            'tax_office' => (int) ($row['tax_office'] ?? 0),
            'undelivered' => $undelivered,
            'box_id' => $l !== null ? (int) $l['databox_id'] : null,
            'latest_tax_office_annotation' => $l['annotation'] ?? null,
        ];
    }

    /** Boxes of the supplier whose background sync is broken - shown on the dashboard. @return list<array{id:int, label:string, message:?string}> */
    public function brokenSyncs(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, label, last_sync_message FROM databoxes
              WHERE supplier_id = ? AND is_active = 1 AND last_sync_status IN ('error','paused') ORDER BY label"
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => (string) $r['label'], 'message' => $r['last_sync_message']], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    // ---- tax filing links ----

    /** Sends of a filing that are not known to have failed (rejected / undeliverable ones do not count). @return list<array<string,mixed>> */
    public function activeSendsOfSubmission(int $submissionId, int $supplierId): array
    {
        $links = $this->linksForSubmissions([$submissionId], $supplierId)[$submissionId] ?? [];
        return array_values(array_filter($links, static fn (array $l): bool => !DeliveryDates::isFailed($l['status'])));
    }

    public function linkSubmission(int $submissionId, int $messageId, int $userId): void
    {
        $this->db->pdo()->prepare('INSERT IGNORE INTO tax_submission_databox_messages (submission_id, message_id, created_by) VALUES (?, ?, ?)')
            ->execute([$submissionId, $messageId, $this->userOrNull($userId)]);
    }

    /**
     * Sent messages of the box that carry an archived submission whose filing they created and that is still only "filed"
     * (no receipt yet): the ones whose later state in ISDS still decides that filing.
     *
     * @return list<int>
     */
    public function openFilingMessages(int $boxId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT m.id FROM databox_messages m
               JOIN tax_submission_databox_messages l ON l.message_id = m.id
               JOIN tax_submissions s ON s.id = l.submission_id
              WHERE m.databox_id = ? AND m.direction = 'sent' AND s.filing_status = 'filed'
                AND s.filing_channel = 'data_box' AND s.filing_reference = m.dm_id"
        );
        $stmt->execute([$boxId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Archived submissions of the supplier whose stored XML has one of the given SHA-256 checksums (lower-case hex), oldest
     * first. This is the only evidence an uploaded sent message is matched to a filing by.
     *
     * @param list<string> $hashes
     * @return list<array{id:int, filing_status:string, filing_channel:?string, filing_reference:?string}>
     */
    public function submissionsByXmlHash(int $supplierId, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, filing_status, filing_channel, filing_reference FROM tax_submissions
              WHERE supplier_id = ? AND xml_sha256 IN (' . implode(',', array_fill(0, count($hashes), '?')) . ') ORDER BY id'
        );
        $stmt->execute(array_merge([$supplierId], array_values($hashes)));
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'filing_status' => (string) $r['filing_status'],
            'filing_channel' => $r['filing_channel'], 'filing_reference' => $r['filing_reference'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<int> archived submissions the message carried */
    public function submissionsOfMessage(int $messageId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT submission_id FROM tax_submission_databox_messages WHERE message_id = ?');
        $stmt->execute([$messageId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Messages that carried the given archived submissions.
     *
     * @param list<int> $submissionIds
     * @return array<int, list<array<string,mixed>>> submission id -> messages (newest first); only submissions of $supplierId
     */
    public function linksForSubmissions(array $submissionIds, int $supplierId): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.submission_id, m.id AS message_id, m.databox_id, m.dm_id, m.recipient_box_id, m.recipient_name, m.status,
                    m.delivery_time, m.acceptance_time, m.first_seen_at, l.created_at,
                    EXISTS (SELECT 1 FROM databox_message_files f WHERE f.message_id = m.id AND f.kind = \'delivery_zfo\') AS has_delivery_zfo,
                    (m.delivery_info_xml IS NOT NULL) AS has_delivery_info
               FROM tax_submission_databox_messages l
               JOIN databox_messages m ON m.id = l.message_id
               JOIN tax_submissions s ON s.id = l.submission_id
              WHERE s.supplier_id = ? AND l.submission_id IN (' . implode(',', array_fill(0, count($submissionIds), '?')) . ')
              ORDER BY l.id DESC'
        );
        $stmt->execute(array_merge([$supplierId], array_values($submissionIds)));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row['message_id'] = (int) $row['message_id'];
            $row['databox_id'] = (int) $row['databox_id'];
            $row['status'] = $row['status'] !== null ? (int) $row['status'] : null;
            $row['has_delivery_zfo'] = (bool) $row['has_delivery_zfo'];
            $row['has_delivery_info'] = (bool) $row['has_delivery_info'];
            $out[(int) $row['submission_id']][] = $row;
        }
        return $out;
    }

    // ---- helpers ----

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        foreach (['id', 'databox_id', 'supplier_id', 'acknowledged_by', 'sent_by', 'sender_type', 'status', 'attachment_size_kb'] as $int) {
            if (isset($row[$int])) {
                $row[$int] = (int) $row[$int];
            }
        }
        foreach (['personal_delivery', 'allow_subst_delivery', 'is_tax_office', 'has_zfo', 'has_delivery_zfo', 'has_delivery_info'] as $bool) {
            if (isset($row[$bool])) {
                $row[$bool] = (bool) $row[$bool];
            }
        }
        if (isset($row['sent_payload_json'])) {
            $row['sent_payload'] = json_decode((string) $row['sent_payload_json'], true) ?: null;
        }
        unset($row['sent_payload_json']);
        return $row;
    }

    private function userOrNull(int $userId): ?int
    {
        return $userId > 0 ? $userId : null;
    }

    private function dt(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : null;
    }

    private function cut(mixed $value, int $max): ?string
    {
        return $value === null ? null : mb_substr((string) $value, 0, $max);
    }
}
