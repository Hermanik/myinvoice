<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Repository\TaxSubmissionRepository;

/**
 * The filed / confirmed returns of ONE type and period in the order of filing, and which of them are in force.
 *
 * A regular (B) or corrective (O) DPH return REPLACES everything filed before it; a supplementary return (D, § 141 DŘ)
 * only adds to the return it was computed against (it states the difference against the last known tax). So the DPH
 * returns in force are the last non-supplementary one and the supplementary ones filed after it. For every other type
 * (KH, SH, ...) each filing replaces the earlier ones: the last is in force.
 *
 * One definition for the supplementary builder, the filing guards, the payment cards and the pre-filing checks.
 */
final class FiledReturnChain
{
    /** @param list<array<string,mixed>> $filed repository rows ({@see TaxSubmissionRepository::filedForPeriod()}), oldest filing first */
    public function __construct(private readonly array $filed) {}

    /** A supplementary DPH return (form D). */
    public static function isSupplementary(array $submission): bool
    {
        return ($submission['form_code'] ?? '') === TaxSubmissionRepository::FORM_DPH
            && ($submission['submission_form'] ?? '') === TaxSubmissionRepository::DPH_SUPPLEMENTARY;
    }

    /** The last filed return of any form: the one a supplementary return is computed against. */
    public function last(): ?array
    {
        return array_last($this->filed);
    }

    /** The regular / corrective return in force (the last non-supplementary one); null when none was filed in the app. */
    public function base(): ?array
    {
        $base = null;
        foreach ($this->filed as $row) {
            if (!self::isSupplementary($row)) {
                $base = $row;
            }
        }
        return $base;
    }

    /**
     * The returns in force: from the base to the end. Without a base (filed elsewhere) every filed return is in force.
     *
     * @return list<array<string,mixed>>
     */
    public function inForce(): array
    {
        $start = 0;
        foreach ($this->filed as $i => $row) {
            if (!self::isSupplementary($row)) {
                $start = $i;
            }
        }
        return array_slice($this->filed, $start);
    }

    public function isInForce(int $id): bool
    {
        return in_array($id, array_column($this->inForce(), 'id'), true);
    }

    /**
     * The supplementary returns in force.
     *
     * @return list<array<string,mixed>>
     */
    public function supplements(): array
    {
        return array_values(array_filter($this->inForce(), self::isSupplementary(...)));
    }

    /** Is a supplementary return filed after this return? */
    public function hasSupplementAfter(array $row): bool
    {
        foreach ($this->filed as $i => $candidate) {
            if ((int) $candidate['id'] === (int) $row['id']) {
                return array_any(array_slice($this->filed, $i + 1), self::isSupplementary(...));
            }
        }
        return false;
    }

    /**
     * A supplementary return is filed AFTER the return it states the difference against: with an earlier or equal
     * `filed_at` it would sort before it, count as superseded and its difference would be lost.
     *
     * @param string $filedAt the filing time of the supplementary return, `Y-m-d H:i:s`
     * @throws TaxFilingException
     */
    public function assertFiledAfterBase(string $filedAt): void
    {
        $last = $this->last();
        if ($last !== null && $filedAt <= (string) $last['filed_at']) {
            throw new TaxFilingException(
                sprintf(
                    'Dodatečné přiznání musí být podané později než přiznání, ke kterému se vztahuje (podané %s). Zadej pozdější datum a čas podání.',
                    substr((string) $last['filed_at'], 0, 16),
                ),
                'filed_before_base',
                409,
            );
        }
    }

    /**
     * A supplementary return may only be filed on top of the return it was computed against.
     *
     * @param array<string,mixed> $supplement the generated D (summary decoded)
     * @throws TaxFilingException when the base is no longer the last filed return of the period
     */
    public function assertCurrentBaseOf(array $supplement): void
    {
        $basedOn = (int) ($supplement['summary']['supplementary']['based_on']['submission_id'] ?? 0);
        $last = $this->last();
        if ($last === null || $basedOn === 0 || (int) $last['id'] !== $basedOn) {
            throw new TaxFilingException(
                'Dodatečné přiznání bylo vypočteno vůči jinému podanému přiznání, než které je nyní poslední za toto období. Vygeneruj ho znovu.',
                'stale_supplementary',
                409,
            );
        }
    }
}
