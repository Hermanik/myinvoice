<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\LateInterest\LateInterestCalculator;
use PDO;

/**
 * Global codebook of the CNB two-week (2T) repo rate. A row means "from this date the rate is X";
 * the rate in force on a day is the row with the greatest `valid_from` not after that day.
 *
 * The codebook is APPEND-ONLY by design: existing history is never rewritten, because past
 * interest computations (reminders, interest invoices) must stay reproducible. New rows must be
 * dated after the latest row. The only deletable rows are the ones an admin typed in by hand
 * (`source = 'manual'`), so a typo can be corrected.
 *
 * Official history: https://www.cnb.cz/cs/casto-kladene-dotazy/Jak-se-vyvijela-dvoutydenni-repo-sazba-CNB/
 */
final class RepoRateRepository
{
    /** app_meta key: the codebook is known to be complete up to this date (Y-m-d). */
    public const META_VERIFIED_ON = 'late_interest.repo_rates_verified_on';

    public function __construct(private readonly Connection $db) {}

    /** @return list<array{valid_from: string, rate_percent: float, source: string}> ascending by date */
    public function all(): array
    {
        $rows = $this->db->pdo()
            ->query('SELECT valid_from, rate_percent, source FROM cnb_repo_rates ORDER BY valid_from')
            ->fetchAll(PDO::FETCH_ASSOC);
        return array_map(self::cast(...), $rows);
    }

    /** The rate in force on $date, or null when the date predates the codebook. */
    public function rateOn(string $date): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT valid_from, rate_percent, source FROM cnb_repo_rates
              WHERE valid_from <= ? ORDER BY valid_from DESC LIMIT 1'
        );
        $stmt->execute([$date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function find(string $validFrom): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT valid_from, rate_percent, source FROM cnb_repo_rates WHERE valid_from = ?');
        $stmt->execute([$validFrom]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    public function latestValidFrom(): ?string
    {
        $v = $this->db->pdo()->query('SELECT MAX(valid_from) FROM cnb_repo_rates')->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    /**
     * Appends a rate. Refuses anything that would alter history: the date must be later than the
     * latest row. Never updates an existing row.
     *
     * @throws \InvalidArgumentException invalid date / rate or a date that is not after the latest row
     */
    public function append(string $validFrom, float $ratePercent, string $source): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $validFrom) || date('Y-m-d', (int) strtotime($validFrom)) !== $validFrom) {
            throw new \InvalidArgumentException('Neplatné datum platnosti sazby.');
        }
        if ($ratePercent < 0 || $ratePercent > 50) {
            throw new \InvalidArgumentException('Sazba musí být v rozmezí 0 až 50 % p.a.');
        }
        if (!in_array($source, ['cnb', 'manual'], true)) {
            throw new \InvalidArgumentException('Neplatný zdroj sazby.');
        }
        $latest = $this->latestValidFrom();
        if ($latest !== null && $validFrom <= $latest) {
            throw new \InvalidArgumentException(
                "Nová sazba musí platit od data pozdějšího než poslední záznam ({$latest}); historie se nepřepisuje."
            );
        }
        $this->db->pdo()
            ->prepare('INSERT INTO cnb_repo_rates (valid_from, rate_percent, source) VALUES (?, ?, ?)')
            ->execute([$validFrom, number_format($ratePercent, 2, '.', ''), $source]);
    }

    /** Removes a hand-typed row (typo correction). Seeded / CNB rows are immutable. */
    public function deleteManual(string $validFrom): bool
    {
        $stmt = $this->db->pdo()->prepare("DELETE FROM cnb_repo_rates WHERE valid_from = ? AND source = 'manual'");
        $stmt->execute([$validFrom]);
        return $stmt->rowCount() > 0;
    }

    /** Date up to which the codebook is known to be complete (Y-m-d), or null when never verified. */
    public function verifiedOn(): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT v FROM app_meta WHERE k = ?');
        $stmt->execute([self::META_VERIFIED_ON]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null || $v === '' ? null : (string) $v;
    }

    /**
     * Can the rate for a delay beginning on $date be trusted? Only when the codebook was verified on or
     * after the first day of that delay's half-year — otherwise the CNB may have changed the rate since.
     */
    public function isCurrentFor(string $date): bool
    {
        $verified = $this->verifiedOn();
        return $verified !== null && $verified >= LateInterestCalculator::halfYearStart($date);
    }

    /** Moves the verification stamp forward; it never moves back. */
    public function markVerified(string $date): void
    {
        $current = $this->verifiedOn();
        if ($current !== null && $current >= $date) {
            return;
        }
        $this->db->pdo()
            ->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute([self::META_VERIFIED_ON, $date]);
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array
    {
        return [
            'valid_from'   => (string) $row['valid_from'],
            'rate_percent' => (float) $row['rate_percent'],
            'source'       => (string) $row['source'],
        ];
    }
}
