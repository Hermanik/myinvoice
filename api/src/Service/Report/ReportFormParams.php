<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Parsers of the query parameters of the DPH / KH report endpoints into the validated filing value objects
 * ({@see DphFiling}, {@see KhFiling}), plus the EPO date helpers they share. An invalid combination throws
 * \InvalidArgumentException with a Czech message (the actions answer it with 400).
 */
final class ReportFormParams
{
    /**
     * `form`, `d_zjist`, `reason`.
     *
     * @param array<string,mixed> $query
     * @throws \InvalidArgumentException
     */
    public static function dph(array $query): DphFiling
    {
        return new DphFiling((string) ($query['form'] ?? 'B'), self::str($query, 'd_zjist'), self::str($query, 'reason'));
    }

    /**
     * `form`, `d_zjist`, `c_jed_vyzvy`, `vyzva_odp`.
     *
     * @param array<string,mixed> $query
     * @throws \InvalidArgumentException
     */
    public static function kh(array $query): KhFiling
    {
        return new KhFiling((string) ($query['form'] ?? 'B'), self::str($query, 'd_zjist'), self::str($query, 'c_jed_vyzvy'), self::str($query, 'vyzva_odp'));
    }

    /** An EPO date (DD.MM.YYYY) trimmed, null when empty. @throws \InvalidArgumentException when it is not a real date */
    public static function cleanEpoDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (!self::isValidEpoDate($value)) {
            throw new \InvalidArgumentException("Neplatné datum zjištění '{$value}' — očekává se formát DD.MM.YYYY.");
        }
        return $value;
    }

    /** Exact format DD.MM.YYYY + a real date (no 31.02.). */
    public static function isValidEpoDate(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        return $dt !== false && $dt->format('d.m.Y') === $value;
    }

    /** DD.MM.YYYY (EPO date) -> YYYY-MM-DD; anything else is returned as it is. */
    public static function epoDateToIso(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!d.m.Y', $date);
        return $parsed !== false ? $parsed->format('Y-m-d') : $date;
    }

    /** @param array<string,mixed> $query */
    private static function str(array $query, string $key): ?string
    {
        return isset($query[$key]) ? (string) $query[$key] : null;
    }
}
