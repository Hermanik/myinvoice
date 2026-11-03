<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Vat\VatStatusHistory;
use PDO;

/**
 * Persistence for `supplier_vat_status_history` (migration 9002) and the
 * `supplier.is_vat_payer` / `supplier.is_identified` cache of "status today".
 */
final class SupplierVatStatusRepository
{
    public const TABLE = 'supplier_vat_status_history';

    public function __construct(private readonly Connection $db) {}

    /** False before migration 9002 ran; callers then fall back to the cached flags. */
    public function hasTable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /**
     * Stored rows, oldest first.
     *
     * @return list<array{id: int, supplier_id: int, valid_from: string, status: string, note: ?string, created_at: string, created_by: ?int}>
     */
    public function rows(int $supplierId): array
    {
        if (!$this->hasTable()) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, valid_from, status, note, created_at, created_by
               FROM ' . self::TABLE . ' WHERE supplier_id = ? ORDER BY valid_from ASC, id ASC'
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'supplier_id' => (int) $r['supplier_id'],
            'valid_from'  => (string) $r['valid_from'],
            'status'      => (string) $r['status'],
            'note'        => $r['note'] !== null ? (string) $r['note'] : null,
            'created_at'  => (string) $r['created_at'],
            'created_by'  => $r['created_by'] !== null ? (int) $r['created_by'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The supplier's history. A supplier without stored rows (table not migrated yet,
     * or a supplier inserted by raw SQL) is derived from the cached flags so every
     * date-based reader keeps working; a supplier that does not exist counts as a payer.
     */
    public function history(int $supplierId): VatStatusHistory
    {
        $rows = $this->rows($supplierId);
        if ($rows !== []) {
            return new VatStatusHistory($rows);
        }
        try {
            $flags = $this->cachedFlags($supplierId);
        } catch (\PDOException) {
            $flags = null; // schema without a supplier table (slim test databases)
        }
        // An unknown supplier counts as a payer, like every other "no supplier data" fallback in the code base.
        return $flags === null
            ? VatStatusHistory::fromFlags(true, false)
            : VatStatusHistory::fromFlags($flags['is_vat_payer'], $flags['is_identified']);
    }

    /** @return array{id: int, supplier_id: int, valid_from: string, status: string, note: ?string, created_at: string, created_by: ?int}|null */
    public function find(int $supplierId, int $rowId): ?array
    {
        foreach ($this->rows($supplierId) as $row) {
            if ($row['id'] === $rowId) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Runs $fn atomically (joins an already open transaction instead of nesting).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transactional(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function insert(int $supplierId, string $validFrom, string $status, ?string $note, ?int $userId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (supplier_id, valid_from, status, note, created_by) VALUES (?, ?, ?, ?, ?)'
        )->execute([$supplierId, $validFrom, $status, $note, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public function updateStatus(int $supplierId, int $rowId, string $status): void
    {
        $this->db->pdo()->prepare('UPDATE ' . self::TABLE . ' SET status = ? WHERE id = ? AND supplier_id = ?')
            ->execute([$status, $rowId, $supplierId]);
    }

    public function delete(int $supplierId, int $rowId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$rowId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Cleanup for supplier deletion, which runs with FOREIGN_KEY_CHECKS=0 (no cascade). */
    public function deleteForSupplier(int $supplierId): void
    {
        if ($this->hasTable()) {
            $this->db->pdo()->prepare('DELETE FROM ' . self::TABLE . ' WHERE supplier_id = ?')->execute([$supplierId]);
        }
    }

    /** Whether the supplier already has stored history rows. */
    public function hasRows(int $supplierId): bool
    {
        return $this->rows($supplierId) !== [];
    }

    /** @return array{is_vat_payer: bool, is_identified: bool}|null null = unknown supplier */
    public function cachedFlags(int $supplierId): ?array
    {
        // SELECT * keeps this tolerant of slim supplier tables (unit tests) without is_identified.
        $stmt = $this->db->pdo()->prepare('SELECT * FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['is_vat_payer' => (bool) ($row['is_vat_payer'] ?? false), 'is_identified' => (bool) ($row['is_identified'] ?? false)];
    }

    public function writeCache(int $supplierId, bool $isVatPayer, bool $isIdentified): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ? WHERE id = ?')
            ->execute([$isVatPayer ? 1 : 0, $isIdentified ? 1 : 0, $supplierId]);
    }

    /** @return list<int> */
    public function supplierIds(): array
    {
        return array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Flat tax settings consulted by the "payer vs flat tax" rule. */
    public function supplierFlatTaxBand(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT flat_tax_band FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) ($stmt->fetchColumn() ?: 'none');
    }

    /**
     * Saved tax-profile band per year (years with an explicit tax_profiles row).
     *
     * @return array<int, string> year => flat_tax_band
     */
    public function profileFlatTaxBands(int $supplierId): array
    {
        if (!$this->db->hasTable('tax_profiles')) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT year, flat_tax_band FROM tax_profiles WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $bands = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $bands[(int) $r['year']] = (string) $r['flat_tax_band'];
        }
        return $bands;
    }
}
