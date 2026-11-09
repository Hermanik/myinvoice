<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;

/**
 * The standing orders and the settings of a supplier's timeline (migration 9012) as the database had them before a
 * test: {@see stash()} removes them so that the test works on a clean state, {@see restore()} removes what the test
 * created and puts the original rows back — a development database may hold the owner's real standing orders.
 */
final class TimelinePaymentsState
{
    /** @var list<array<string,mixed>> */
    private array $orders = [];
    /** @var array<string,mixed>|null */
    private ?array $settings = null;

    public function __construct(private readonly PDO $pdo, private readonly int $supplierId) {}

    public function stash(): void
    {
        $this->orders = $this->rows('timeline_standing_orders');
        $this->settings = $this->rows('timeline_settings')[0] ?? null;
        $this->clear();
    }

    public function restore(): void
    {
        $this->clear();
        foreach ($this->orders as $row) {
            $this->insert('timeline_standing_orders', $row);
        }
        if ($this->settings !== null) {
            $this->insert('timeline_settings', $this->settings);
        }
        $this->orders = [];
        $this->settings = null;
    }

    private function clear(): void
    {
        foreach (['timeline_standing_orders', 'timeline_settings'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE supplier_id = ?")->execute([$this->supplierId]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $table): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $row */
    private function insert(string $table, array $row): void
    {
        $columns = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $this->pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$marks})")->execute(array_values($row));
    }
}
