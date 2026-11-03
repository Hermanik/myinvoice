<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * How a kontrolní hlášení is filed: the form (`khdph_forma`: B regular, O corrective § 101f/1, N subsequent § 101f/2, E
 * subsequent/corrective), the date the reasons were found (`d_zjist`) and the call of the tax office it answers (§ 101g
 * ZDPH): the reference number (`c_jed_vyzvy`) and the quick answer (`vyzva_odp`). A validated value object.
 *
 * Quick answer to a call ("rychlá odpověď na výzvu"): B = "nemám povinnost podat KH" (nil KH), P = "potvrzuji správnost
 * naposledy podaného KH". Filed WITHOUT the sections A, B and C (EPO check since 1. 1. 2017: rows in them make the
 * filing ineffective) but with the call reference; written with `khdph_forma="B"` and archived as form V.
 *
 * Sources: EPO structure https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPHKH1 ,
 * https://financnisprava.gov.cz/cs/dane/dane/dan-z-pridane-hodnoty/kontrolni-hlaseni-dph/aktuality/2016/uprava-xml-struktury-epo-formulare-kontrolni-hlaseni-od-1-1-2017-rychla-odpoved-na-vyzvu
 */
final class KhFiling
{
    public const FORMS = ['B', 'O', 'N', 'E'];
    /** Forms for which EPO requires `d_zjist` OR the call reference ("musí být vyplněna buď d_zjist anebo č.j. výzvy"). */
    public const FORMS_REQUIRING_REASON_DATE = ['N', 'E'];
    public const CALL_ANSWERS = ['B', 'P'];
    /** Archive form of the quick answer, so that it never collides with the regular KH of the period. */
    public const QUICK_ANSWER_ARCHIVE_FORM = 'V';
    /** Reference number of the call as EPO wants it (`c_jed_vyzvy`): 99999999/99/9999-99999-999999 incl. the separators. */
    public const CALL_REFERENCE_PATTERN = '#^\d{1,8}/\d{2}/\d{4}-\d{5}-\d{6}$#';

    public readonly string $form;
    public readonly ?string $dZjist;
    public readonly ?string $callReference;
    /** 'B' | 'P' for a quick answer, else null. */
    public readonly ?string $callAnswer;

    /** @throws \InvalidArgumentException */
    public function __construct(string $form = 'B', ?string $dZjist = null, ?string $callReference = null, ?string $callAnswer = null)
    {
        $form = strtoupper(trim($form)) ?: 'B';
        if (!in_array($form, self::FORMS, true)) {
            throw new \InvalidArgumentException("Neplatná forma hlášení '{$form}' (povolené: " . implode(', ', self::FORMS) . ').');
        }
        $dZjist = ReportFormParams::cleanEpoDate($dZjist);
        $callReference = trim((string) $callReference) ?: null;
        $callAnswer = strtoupper(trim((string) $callAnswer)) ?: null;

        if ($callReference !== null && preg_match(self::CALL_REFERENCE_PATTERN, $callReference) !== 1) {
            throw new \InvalidArgumentException('Číslo jednací výzvy musí mít tvar 99999999/99/9999-99999-999999 (včetně lomítek a pomlček).');
        }
        if ($callAnswer !== null) {
            if (!in_array($callAnswer, self::CALL_ANSWERS, true)) {
                throw new \InvalidArgumentException("Neplatná rychlá odpověď na výzvu '{$callAnswer}' (povolené: " . implode(', ', self::CALL_ANSWERS) . ').');
            }
            if ($callReference === null) {
                throw new \InvalidArgumentException('Rychlá odpověď na výzvu vyžaduje číslo jednací výzvy (č. j. výzvy).');
            }
            if ($form !== 'B') {
                throw new \InvalidArgumentException("Rychlá odpověď na výzvu se podává samostatně, bez sekcí A, B a C — nelze ji kombinovat s formou {$form}.");
            }
        }
        if ($dZjist === null && $callReference === null && in_array($form, self::FORMS_REQUIRING_REASON_DATE, true)) {
            throw new \InvalidArgumentException('Následné kontrolní hlášení vyžaduje datum zjištění důvodů (d_zjist) nebo číslo jednací výzvy.');
        }
        $this->form = $form;
        $this->dZjist = $form === 'B' ? null : $dZjist;
        $this->callReference = $callReference;
        $this->callAnswer = $callAnswer;
    }

    public function isQuickAnswer(): bool
    {
        return $this->callAnswer !== null;
    }

    /** The form the submission is archived (and filed) under. */
    public function archiveForm(): string
    {
        return $this->isQuickAnswer() ? self::QUICK_ANSWER_ARCHIVE_FORM : $this->form;
    }
}
