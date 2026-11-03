<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Vat\VatStatus;
use MyInvoice\Service\Vat\VatStatusHistory;
use PDO;

/**
 * Integration-test helper for the supplier's dated VAT status.
 *
 * Date-based code reads `supplier_vat_status_history`, so a test must not flip the cached
 * `supplier.is_vat_payer` / `is_identified` columns directly. {@see set()} replaces the whole
 * history with one floor row (and keeps the cache in sync); {@see snapshot()} / {@see restore()}
 * bring the original history back in tearDown, so a developer's database stays untouched.
 */
final class VatStatusFixture
{
    /** @return list<array{valid_from: string, status: string, note: ?string}> */
    public static function snapshot(PDO $pdo, int $supplierId): array
    {
        $stmt = $pdo->prepare('SELECT valid_from, status, note FROM supplier_vat_status_history WHERE supplier_id = ? ORDER BY valid_from');
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One floor row (2000-01-01) with the given status; cache columns follow. */
    public static function set(PDO $pdo, int $supplierId, bool $isVatPayer, bool $isIdentified = false): void
    {
        self::replace($pdo, $supplierId, [['valid_from' => VatStatus::EARLIEST_DATE, 'status' => VatStatus::fromFlags($isVatPayer, $isIdentified), 'note' => null]]);
    }

    /** @param list<array{valid_from: string, status: string, note: ?string}> $rows */
    public static function restore(PDO $pdo, int $supplierId, array $rows): void
    {
        // Migration 9002 seeds a floor row for every supplier, so an empty snapshot is a test
        // bug (e.g. restoring before snapshot() ran); restoring it would wipe the history.
        if ($rows === []) {
            throw new \LogicException("Refusing to restore an empty VAT history for supplier {$supplierId}.");
        }
        self::replace($pdo, $supplierId, $rows);
    }

    /** Replaces the history with explicit rows (for tests that need several dated rows). */
    public static function setHistory(PDO $pdo, int $supplierId, array $rows): void
    {
        self::replace($pdo, $supplierId, array_map(
            static fn (array $r): array => ['valid_from' => $r[0], 'status' => $r[1], 'note' => $r[2] ?? null],
            $rows,
        ));
    }

    /** @param list<array{valid_from: string, status: string, note: ?string}> $rows */
    private static function replace(PDO $pdo, int $supplierId, array $rows): void
    {
        $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ?')->execute([$supplierId]);
        $insert = $pdo->prepare('INSERT INTO supplier_vat_status_history (supplier_id, valid_from, status, note) VALUES (?, ?, ?, ?)');
        foreach ($rows as $r) {
            $insert->execute([$supplierId, $r['valid_from'], $r['status'], $r['note'] ?? null]);
        }
        // Cache = status today, resolved by the production rules.
        $flags = (new VatStatusHistory($rows))->flagsAt(date('Y-m-d'));
        $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ? WHERE id = ?')
            ->execute([(int) $flags['is_vat_payer'], (int) $flags['is_identified'], $supplierId]);
    }
}
