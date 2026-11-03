<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Persistence for `vat_registration_deductions` (migration 9006): the items of the VAT deduction on
 * registration (§ 79 ZDPH) and of the reduction on deregistration (§ 79a ZDPH).
 */
final class VatRegistrationDeductionRepository
{
    private const COLUMNS = 'd.id, d.supplier_id, d.kind, d.event_date, d.description, d.asset_type, d.acquired_on,
                d.tax_base, d.vat_amount, d.adjustment_years, d.in_business_assets, d.deduction_amount,
                d.purchase_invoice_id, pi.vendor_invoice_number AS purchase_invoice_number,
                d.source_item_id, d.note, d.created_at, d.updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> items of the supplier, newest event first */
    public function all(int $supplierId): array
    {
        return $this->select('WHERE d.supplier_id = ? ORDER BY d.event_date DESC, d.kind, d.acquired_on, d.id', [$supplierId]);
    }

    /**
     * Items of the given events.
     *
     * @param list<array{kind: string, event_date: string}> $events
     * @return list<array<string,mixed>>
     */
    public function forEvents(int $supplierId, array $events): array
    {
        if ($events === []) {
            return [];
        }
        $conditions = [];
        $params = [$supplierId];
        foreach ($events as $event) {
            $conditions[] = '(d.kind = ? AND d.event_date = ?)';
            $params[] = $event['kind'];
            $params[] = $event['event_date'];
        }
        return $this->select('WHERE d.supplier_id = ? AND (' . implode(' OR ', $conditions) . ') ORDER BY d.acquired_on, d.id', $params);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        return $this->select('WHERE d.supplier_id = ? AND d.id = ?', [$supplierId, $id])[0] ?? null;
    }

    /** The reduction item of $sourceId on the given deregistration event, if one exists. */
    public function findReversal(int $supplierId, int $sourceId, string $eventDate): ?array
    {
        return $this->select(
            "WHERE d.supplier_id = ? AND d.kind = 'deregistration' AND d.source_item_id = ? AND d.event_date = ?",
            [$supplierId, $sourceId, $eventDate],
        )[0] ?? null;
    }

    /** @param array<string,mixed> $data columns of the table without id / timestamps */
    public function insert(int $supplierId, array $data, ?int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO vat_registration_deductions
                (supplier_id, kind, event_date, description, asset_type, acquired_on, tax_base, vat_amount,
                 adjustment_years, in_business_assets, deduction_amount, purchase_invoice_id, source_item_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $data['kind'], $data['event_date'], $data['description'], $data['asset_type'], $data['acquired_on'],
            $data['tax_base'], $data['vat_amount'], $data['adjustment_years'], $data['in_business_assets'] ? 1 : 0,
            $data['deduction_amount'], $data['purchase_invoice_id'], $data['source_item_id'], $data['note'], $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data same columns as {@see insert()}; kind / event_date / source stay as they are */
    public function update(int $supplierId, int $id, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE vat_registration_deductions
                SET description = ?, asset_type = ?, acquired_on = ?, tax_base = ?, vat_amount = ?, adjustment_years = ?,
                    in_business_assets = ?, deduction_amount = ?, purchase_invoice_id = ?, note = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            $data['description'], $data['asset_type'], $data['acquired_on'], $data['tax_base'], $data['vat_amount'],
            $data['adjustment_years'], $data['in_business_assets'] ? 1 : 0, $data['deduction_amount'],
            $data['purchase_invoice_id'], $data['note'], $id, $supplierId,
        ]);
    }

    public function delete(int $supplierId, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM vat_registration_deductions WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    private function select(string $tail, array $params): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM vat_registration_deductions d
               LEFT JOIN purchase_invoices pi ON pi.id = d.purchase_invoice_id ' . $tail
        );
        $stmt->execute($params);
        return array_map($this->normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'id'                      => (int) $row['id'],
            'supplier_id'             => (int) $row['supplier_id'],
            'kind'                    => (string) $row['kind'],
            'event_date'              => (string) $row['event_date'],
            'description'             => (string) $row['description'],
            'asset_type'              => (string) $row['asset_type'],
            'acquired_on'             => (string) $row['acquired_on'],
            'tax_base'                => (float) $row['tax_base'],
            'vat_amount'              => (float) $row['vat_amount'],
            'adjustment_years'        => $row['adjustment_years'] !== null ? (int) $row['adjustment_years'] : null,
            'in_business_assets'      => (bool) $row['in_business_assets'],
            'deduction_amount'        => (float) $row['deduction_amount'],
            'purchase_invoice_id'     => $row['purchase_invoice_id'] !== null ? (int) $row['purchase_invoice_id'] : null,
            'purchase_invoice_number' => $row['purchase_invoice_number'] !== null ? (string) $row['purchase_invoice_number'] : null,
            'source_item_id'          => $row['source_item_id'] !== null ? (int) $row['source_item_id'] : null,
            'note'                    => $row['note'] !== null ? (string) $row['note'] : null,
            'created_at'              => (string) $row['created_at'],
            'updated_at'              => (string) $row['updated_at'],
        ];
    }
}
