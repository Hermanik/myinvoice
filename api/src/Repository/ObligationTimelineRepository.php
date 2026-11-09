<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Persistence of the obligations timeline: manual marks on computed items (`timeline_marks`) and the user's own items
 * (`timeline_custom_items`, migration 9011), the standing orders the owner pays by (`timeline_standing_orders`) and the
 * per-supplier settings (`timeline_settings`, migration 9012). The timeline itself is computed on the fly.
 */
final class ObligationTimelineRepository
{
    /** In the order the timeline lists items of the same day. */
    public const CATEGORIES = ['registration', 'dph', 'income_tax', 'pausal', 'insurance', 'invoicing', 'custom'];

    public const INVOICING_MONTH_END = 'month_end';
    public const INVOICING_WORKING_DAY = 'working_day_n';
    public const DEFAULT_SETTINGS = ['invoicing_due' => self::INVOICING_MONTH_END, 'invoicing_working_day' => 5];

    public function __construct(private readonly Connection $db) {}

    /** False before migration 9011 ran; the timeline then simply has no marks and no custom items. */
    public function isAvailable(): bool
    {
        return $this->db->hasTable('timeline_marks') && $this->db->hasTable('timeline_custom_items');
    }

    /** False before migration 9012 (which creates the standing orders and the settings together) ran: no orders, default settings. */
    public function hasPaymentTables(): bool
    {
        return $this->db->hasTable('timeline_standing_orders');
    }

    // ── marks ───────────────────────────────────────────────────────────────

    /**
     * Marks of the supplier by item key.
     *
     * @return array<string, array{done: bool, note: ?string, by: ?string, at: string}>
     */
    public function marks(int $supplierId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT m.item_key, m.is_done, m.note, m.marked_at, u.name AS marked_by_name
               FROM timeline_marks m
          LEFT JOIN users u ON u.id = m.marked_by
              WHERE m.supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $marks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $marks[(string) $r['item_key']] = [
                'done' => (bool) $r['is_done'],
                'note' => $r['note'] !== null ? (string) $r['note'] : null,
                'by'   => $r['marked_by_name'] !== null ? (string) $r['marked_by_name'] : null,
                'at'   => (string) $r['marked_at'],
            ];
        }
        return $marks;
    }

    public function saveMark(int $supplierId, string $key, bool $done, ?string $note, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO timeline_marks (supplier_id, item_key, is_done, note, marked_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_done = VALUES(is_done), note = VALUES(note), marked_by = VALUES(marked_by), marked_at = NOW()'
        )->execute([$supplierId, $key, $done ? 1 : 0, $note, $userId]);
    }

    public function deleteMark(int $supplierId, string $key): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM timeline_marks WHERE supplier_id = ? AND item_key = ?');
        $stmt->execute([$supplierId, $key]);
        return $stmt->rowCount() > 0;
    }

    // ── custom items ────────────────────────────────────────────────────────

    /**
     * Custom items due within the inclusive range.
     *
     * @return list<array<string,mixed>>
     */
    public function customItemsBetween(int $supplierId, string $from, string $to): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            self::CUSTOM_SELECT . ' WHERE c.supplier_id = ? AND c.due_date BETWEEN ? AND ? ORDER BY c.due_date, c.id'
        );
        $stmt->execute([$supplierId, $from, $to]);
        return array_map($this->castCustom(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function findCustom(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::CUSTOM_SELECT . ' WHERE c.supplier_id = ? AND c.id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castCustom($row);
    }

    /** @param array{title:string, due_date:string, start_date:?string, category:string, note:?string, url:?string, done_at:?string, done_by:?int} $data */
    public function createCustom(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO timeline_custom_items (supplier_id, title, due_date, start_date, category, note, url, done_at, done_by, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $data['title'], $data['due_date'], $data['start_date'], $data['category'], $data['note'], $data['url'],
            $data['done_at'], $data['done_by'], $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array{title:string, due_date:string, start_date:?string, category:string, note:?string, url:?string, done_at:?string, done_by:?int} $data */
    public function updateCustom(int $supplierId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE timeline_custom_items
                SET title = ?, due_date = ?, start_date = ?, category = ?, note = ?, url = ?, done_at = ?, done_by = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            $data['title'], $data['due_date'], $data['start_date'], $data['category'], $data['note'], $data['url'],
            $data['done_at'], $data['done_by'], $id, $supplierId,
        ]);
    }

    public function deleteCustom(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM timeline_custom_items WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    // ── standing orders ─────────────────────────────────────────────────────

    /**
     * Standing orders of the supplier, oldest first.
     *
     * @return list<array{id:int, type:string, amount:float, day_of_month:int, valid_from:string, valid_to:?string, note:?string}>
     */
    public function standingOrders(int $supplierId): array
    {
        if (!$this->hasPaymentTables()) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(self::ORDER_SELECT . ' WHERE supplier_id = ? ORDER BY valid_from, id');
        $stmt->execute([$supplierId]);
        return array_map($this->castOrder(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed>|null */
    public function findStandingOrder(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(self::ORDER_SELECT . ' WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->castOrder($row);
    }

    /** @param array{type:string, amount:float, day_of_month:int, valid_from:string, valid_to:?string, note:?string} $data */
    public function createStandingOrder(int $supplierId, array $data, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO timeline_standing_orders (supplier_id, type, amount, day_of_month, valid_from, valid_to, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$supplierId, ...self::orderValues($data), $userId]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array{type:string, amount:float, day_of_month:int, valid_from:string, valid_to:?string, note:?string} $data */
    public function updateStandingOrder(int $supplierId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE timeline_standing_orders
                SET type = ?, amount = ?, day_of_month = ?, valid_from = ?, valid_to = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([...self::orderValues($data), $id, $supplierId]);
    }

    public function deleteStandingOrder(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM timeline_standing_orders WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    // ── settings ────────────────────────────────────────────────────────────

    /** @return array{invoicing_due: string, invoicing_working_day: int} the stored settings, or the defaults */
    public function settings(int $supplierId): array
    {
        if (!$this->hasPaymentTables()) {
            return self::DEFAULT_SETTINGS;
        }
        $stmt = $this->db->pdo()->prepare('SELECT invoicing_due, invoicing_working_day FROM timeline_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? self::DEFAULT_SETTINGS
            : ['invoicing_due' => (string) $row['invoicing_due'], 'invoicing_working_day' => (int) $row['invoicing_working_day']];
    }

    public function saveSettings(int $supplierId, string $invoicingDue, int $invoicingWorkingDay): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO timeline_settings (supplier_id, invoicing_due, invoicing_working_day)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE invoicing_due = VALUES(invoicing_due), invoicing_working_day = VALUES(invoicing_working_day)'
        )->execute([$supplierId, $invoicingDue, $invoicingWorkingDay]);
    }

    /** Cleanup for supplier deletion, which runs with FOREIGN_KEY_CHECKS=0 (no cascade). */
    public function deleteForSupplier(int $supplierId): void
    {
        foreach (['timeline_marks', 'timeline_custom_items', 'timeline_standing_orders', 'timeline_settings'] as $table) {
            if ($this->db->hasTable($table)) {
                $this->db->pdo()->prepare("DELETE FROM {$table} WHERE supplier_id = ?")->execute([$supplierId]);
            }
        }
    }

    private const CUSTOM_SELECT = 'SELECT c.id, c.title, c.due_date, c.start_date, c.category, c.note, c.url,
                                          c.done_at, c.done_by AS done_by_id, u.name AS done_by_name
                                     FROM timeline_custom_items c
                                LEFT JOIN users u ON u.id = c.done_by';

    /**
     * @param array{type:string, amount:float, day_of_month:int, valid_from:string, valid_to:?string, note:?string} $data
     * @return list<string|float|int|null> in the order of the columns of the statements above
     */
    private static function orderValues(array $data): array
    {
        return [$data['type'], $data['amount'], $data['day_of_month'], $data['valid_from'], $data['valid_to'], $data['note']];
    }

    private const ORDER_SELECT = 'SELECT id, type, amount, day_of_month, valid_from, valid_to, note FROM timeline_standing_orders';

    /** @param array<string,mixed> $r @return array{id:int, type:string, amount:float, day_of_month:int, valid_from:string, valid_to:?string, note:?string} */
    private function castOrder(array $r): array
    {
        return [
            'id'           => (int) $r['id'],
            'type'         => (string) $r['type'],
            'amount'       => (float) $r['amount'],
            'day_of_month' => (int) $r['day_of_month'],
            'valid_from'   => (string) $r['valid_from'],
            'valid_to'     => $r['valid_to'] !== null ? (string) $r['valid_to'] : null,
            'note'         => $r['note'] !== null ? (string) $r['note'] : null,
        ];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function castCustom(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'title'      => (string) $r['title'],
            'due_date'   => (string) $r['due_date'],
            'start_date' => $r['start_date'] !== null ? (string) $r['start_date'] : null,
            'category'   => (string) $r['category'],
            'note'       => $r['note'] !== null ? (string) $r['note'] : null,
            'url'        => $r['url'] !== null ? (string) $r['url'] : null,
            'done_at'    => $r['done_at'] !== null ? (string) $r['done_at'] : null,
            'done_by_id' => $r['done_by_id'] !== null ? (int) $r['done_by_id'] : null,
            'done_by'    => $r['done_by_name'] !== null ? (string) $r['done_by_name'] : null,
        ];
    }
}
