<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Repository\TaxSubmissionRepository;

/**
 * How a DPH return is filed (`dapdph_forma`): B regular, O corrective (§ 138 DŘ), D supplementary (§ 141 DŘ). A validated value
 * object — every way to build one (query string, tests) goes through the same rules.
 *
 * A supplementary return needs the date the reasons were found (`d_zjist`, not in the future) and the reasons themselves
 * (§ 141 odst. 5 DŘ). The combined supplementary/corrective form E is not supported.
 */
final class DphFiling
{
    public const FORMS = ['B', 'O', 'D'];
    public const SUPPLEMENTARY = TaxSubmissionRepository::DPH_SUPPLEMENTARY;

    public readonly string $form;
    /** DD.MM.YYYY; dropped for a regular return. */
    public readonly ?string $dZjist;
    /** Only for a supplementary return. */
    public readonly ?string $reason;

    /** @throws \InvalidArgumentException */
    public function __construct(string $form = 'B', ?string $dZjist = null, ?string $reason = null)
    {
        $form = strtoupper(trim($form)) ?: 'B';
        if (!in_array($form, self::FORMS, true)) {
            throw new \InvalidArgumentException("Neplatná forma přiznání '{$form}' (povolené: " . implode(', ', self::FORMS) . ').');
        }
        $dZjist = ReportFormParams::cleanEpoDate($dZjist);
        $reason = trim((string) $reason);

        if ($form === self::SUPPLEMENTARY) {
            if ($dZjist === null) {
                throw new \InvalidArgumentException('Dodatečné přiznání vyžaduje datum zjištění důvodů (d_zjist, formát DD.MM.YYYY).');
            }
            if (ReportFormParams::epoDateToIso($dZjist) > date('Y-m-d')) {
                throw new \InvalidArgumentException('Datum zjištění důvodů nesmí být v budoucnosti.');
            }
            if ($reason === '') {
                throw new \InvalidArgumentException('Dodatečné přiznání vyžaduje důvody pro jeho podání (§ 141 odst. 5 daňového řádu).');
            }
            if (mb_strlen($reason) > DphSupplementaryDifference::REASON_MAX_LENGTH) {
                throw new \InvalidArgumentException('Důvody dodatečného přiznání mohou mít nejvýše ' . DphSupplementaryDifference::REASON_MAX_LENGTH . ' znaků.');
            }
        }
        $this->form = $form;
        // A date on a regular return would only confuse the tax office: dropped.
        $this->dZjist = $form === 'B' ? null : $dZjist;
        $this->reason = $form === self::SUPPLEMENTARY ? $reason : null;
    }

    public function isSupplementary(): bool
    {
        return $this->form === self::SUPPLEMENTARY;
    }
}
