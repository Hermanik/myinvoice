<?php

declare(strict_types=1);

namespace MyInvoice\Service\Vat;

/**
 * Supplier VAT status values and their mapping to the legacy boolean flags.
 *
 *   payer       VAT payer (plátce DPH)
 *   non_payer   non-payer (neplátce)
 *   identified  VAT-identified person (identifikovaná osoba, § 6g–6l ZDPH) — a non-payer domestically with
 *               cross-border duties; legacy flags `is_vat_payer=0, is_identified=1`
 */
final class VatStatus
{
    public const PAYER = 'payer';
    public const NON_PAYER = 'non_payer';
    public const IDENTIFIED = 'identified';

    public const ALL = [self::PAYER, self::NON_PAYER, self::IDENTIFIED];

    /** Date of the seeded first history row; also the lower bound for new rows. */
    public const EARLIEST_DATE = '2000-01-01';

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function isPayer(string $status): bool
    {
        return $status === self::PAYER;
    }

    /** A payer or an identified person has VAT duties at all (non-payers do not). */
    public static function hasVatDuties(string $status): bool
    {
        return $status === self::PAYER || $status === self::IDENTIFIED;
    }

    public static function fromFlags(bool $isVatPayer, bool $isIdentified): string
    {
        if ($isVatPayer) {
            return self::PAYER;
        }
        return $isIdentified ? self::IDENTIFIED : self::NON_PAYER;
    }

    /** @return array{is_vat_payer: bool, is_identified: bool} */
    public static function toFlags(string $status): array
    {
        return [
            'is_vat_payer'  => $status === self::PAYER,
            'is_identified' => $status === self::IDENTIFIED,
        ];
    }
}
