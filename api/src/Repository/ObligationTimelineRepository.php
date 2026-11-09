<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Persistence of the obligations timeline (migration 9011): manual marks on computed items
 * (`timeline_marks`) and the user's own items (`timeline_custom_items`). The timeline itself is computed on the fly.
 */
final class ObligationTimelineRepository
{
    /** In the order the timeline lists items of the same day. */
    public const CATEGORIES = ['registration', 'dph', 'income_tax', 'pausal', 'insurance', 'invoicing', 'custom'];

    public function __construct(private readonly Connection $db) {}

    /** False before migration 9011 ran; the timeline then simply has no marks and no custom items. */
    public function isAvailable(): bool
    {
        return $this->db->hasTable('timeline_marks') && $this->db->hasTable('timeline_custom_items');
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

    /** Cleanup for supplier deletion, which runs with FOREIGN_KEY_CHECKS=0 (no cascade). */
    public function deleteForSupplier(int $supplierId): void
    {
        if ($this->isAvailable()) {
            foreach (['timeline_marks', 'timeline_custom_items'] as $table) {
                $this->db->pdo()->prepare("DELETE FROM {$table} WHERE supplier_id = ?")->execute([$supplierId]);
            }
        }
    }

    private const CUSTOM_SELECT = 'SELECT c.id, c.title, c.due_date, c.start_date, c.category, c.note, c.url,
                                          c.done_at, c.done_by AS done_by_id, u.name AS done_by_name
                                     FROM timeline_custom_items c
                                LEFT JOIN users u ON u.id = c.done_by';

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
