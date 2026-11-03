<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

/**
 * Immutable, in-memory view of one supplier's dated VAT status history.
 *
 * Status at date D = the row with the greatest valid_from <= D. A date before the
 * first row takes the status of the first row (the first row is a floor, not a
 * statement that nothing applied earlier). Dates are `Y-m-d` strings; anything
 * longer (a DATETIME) is cut to the date part.
 */
final class VatStatusHistory
{
    /** @var list<array{valid_from: string, status: string}> sorted ascending by valid_from */
    private array $rows = [];

    /** @param iterable<array{valid_from: string, status: string}> $rows */
    public function __construct(iterable $rows)
    {
        foreach ($rows as $row) {
            $this->rows[] = [
                'valid_from' => self::day((string) $row['valid_from']),
                'status'     => (string) $row['status'],
            ];
        }
        usort($this->rows, static fn (array $a, array $b): int => strcmp($a['valid_from'], $b['valid_from']));
    }

    /** History of a supplier that only has the legacy flags (single row on the floor date). */
    public static function fromFlags(bool $isVatPayer, bool $isIdentified): self
    {
        return new self([[
            'valid_from' => VatStatus::EARLIEST_DATE,
            'status'     => VatStatus::fromFlags($isVatPayer, $isIdentified),
        ]]);
    }

    /** @return list<array{valid_from: string, status: string}> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function statusAt(string $date): string
    {
        $date = self::day($date);
        $status = $this->rows[0]['status'] ?? VatStatus::NON_PAYER;
        foreach ($this->rows as $row) {
            if ($row['valid_from'] > $date) {
                break;
            }
            $status = $row['status'];
        }
        return $status;
    }

    public function isPayerAt(string $date): bool
    {
        return $this->statusAt($date) === VatStatus::PAYER;
    }

    /** @return array{is_vat_payer: bool, is_identified: bool} */
    public function flagsAt(string $date): array
    {
        return VatStatus::toFlags($this->statusAt($date));
    }

    /** True when any day of the inclusive range [$from, $to] has one of the given statuses. */
    public function hasStatusBetween(string $from, string $to, array $statuses): bool
    {
        $from = self::day($from);
        $to = self::day($to);
        if ($from > $to) {
            return false;
        }
        if (in_array($this->statusAt($from), $statuses, true)) {
            return true;
        }
        foreach ($this->rows as $row) {
            if ($row['valid_from'] > $from && $row['valid_from'] <= $to && in_array($row['status'], $statuses, true)) {
                return true;
            }
        }
        return false;
    }

    /** The supplier is a VAT payer on at least one day of [$from, $to]. */
    public function hasPayerTimeBetween(string $from, string $to): bool
    {
        return $this->hasStatusBetween($from, $to, [VatStatus::PAYER]);
    }

    /** The supplier is a payer or an identified person on at least one day of [$from, $to]. */
    public function hasVatDutiesBetween(string $from, string $to): bool
    {
        return $this->hasStatusBetween($from, $to, [VatStatus::PAYER, VatStatus::IDENTIFIED]);
    }

    /**
     * The first status change strictly after $date, comparing STATUSES (a redundant row
     * repeating the current status is not a change).
     *
     * @return array{valid_from: string, status: string}|null
     */
    public function nextChangeAfter(string $date): ?array
    {
        $date = self::day($date);
        $current = $this->statusAt($date);
        foreach ($this->rows as $row) {
            if ($row['valid_from'] > $date && $row['status'] !== $current) {
                return $row;
            }
        }
        return null;
    }

    public static function day(string $date): string
    {
        return substr($date, 0, 10);
    }
}
