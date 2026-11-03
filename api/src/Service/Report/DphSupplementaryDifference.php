<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Arithmetic of the supplementary DPH return (dodatečné přiznání, DPHDP3 form D, § 141 daňového řádu).
 *
 * "V dodatečném přiznání se uvádí pouze rozdíly od údajů, ze kterých byla stanovena poslední známá daň" — the
 * form lists only the DIFFERENCES against the last known return of the period, row by row, and row 66 (`dano`) the
 * difference of the tax (`dano_da` / `dano_no`, rows 64 / 65, stay empty). A positive row 66 is paid in the
 * substitute payment period of § 141 odst. 7 DŘ, a negative one can be reclaimed (§ 155 DŘ).
 *
 * The last known return is the last FILED one of the period; its full figures are `full_lines` of its summary when
 * it is itself a D (the cumulative state), else `lines` (B / O carry the whole return). Every figure is compared in
 * whole CZK, the way it is entered on the form.
 *
 * Sources: https://www.zakonyprolidi.cz/cs/2009-280#p141 ; EPO structure of DPHDP3
 * https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPHDP3 (VetaD
 * `dapdph_forma`, `d_zjist`; Veta6 `dano`; VetaR `kod_sekce` D = reasons of the supplementary return).
 *
 * Pure functions, no I/O.
 */
final class DphSupplementaryDifference
{
    /** Longest line of the text attachment `VetaR@t_prilohy` (EPO XSD). */
    public const REASON_LINE_LENGTH = 72;

    public const REASON_MAX_LENGTH = 2000;

    /**
     * Rows that differ between the current state of the period and the last known return.
     *
     * @param array<string, array{base:float, vat:float, count?:int, label?:string}> $current full rows now
     * @param array<string, array{base:float, vat:float}> $known full rows of the last known return
     * @return array{
     *   lines: array<string, array{base:float, vat:float, count:int, label:string}>,
     *   output: int, input: int, tax: int
     * } `output` / `input` are the differences of rows 62 / 63, `tax` = output - input = row 66
     */
    public static function compute(array $current, array $known): array
    {
        $lines = [];
        foreach (array_unique([...array_keys($current), ...array_keys($known)]) as $row) {
            $row = (string) $row;
            $carriesTax = Dphdp3Lines::carriesTax($row);
            $baseDiff = (int) round((float) ($current[$row]['base'] ?? 0)) - (int) round((float) ($known[$row]['base'] ?? 0));
            $vatDiff = $carriesTax ? (int) round((float) ($current[$row]['vat'] ?? 0)) - (int) round((float) ($known[$row]['vat'] ?? 0)) : 0;
            if ($baseDiff === 0 && $vatDiff === 0) {
                continue;
            }
            $lines[$row] = [
                'base'  => (float) $baseDiff,
                'vat'   => (float) $vatDiff,
                'count' => (int) ($current[$row]['count'] ?? 0),
                'label' => (string) ($current[$row]['label'] ?? $known[$row]['label'] ?? ''),
            ];
        }
        ksort($lines, SORT_NATURAL);
        return ['lines' => $lines] + Dphdp3Lines::totals($lines);
    }

    /**
     * Full rows of a filed return: the cumulative state of a D, the rows of a B / O.
     *
     * @param array<string,mixed> $submission a repository row (summary decoded)
     * @return array<string, array{base:float, vat:float}>
     */
    public static function knownLines(array $submission): array
    {
        $summary = (array) ($submission['summary'] ?? []);
        return (array) ($summary['full_lines'] ?? $summary['lines'] ?? []);
    }

    /**
     * The reasons as the lines of the text attachment (≤ 72 characters, word wrapped; a longer word is cut).
     *
     * @return list<string>
     */
    public static function reasonLines(string $reason): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', trim($reason)) ?: [] as $paragraph) {
            $paragraph = trim((string) preg_replace('/\s+/u', ' ', $paragraph));
            if ($paragraph === '') {
                continue;
            }
            $current = '';
            foreach (explode(' ', $paragraph) as $word) {
                while (mb_strlen($word) > self::REASON_LINE_LENGTH) {
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }
                    $lines[] = mb_substr($word, 0, self::REASON_LINE_LENGTH);
                    $word = mb_substr($word, self::REASON_LINE_LENGTH);
                }
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if (mb_strlen($candidate) > self::REASON_LINE_LENGTH) {
                    $lines[] = $current;
                    $candidate = $word;
                }
                $current = $candidate;
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }
        return $lines;
    }

    /**
     * Deadline of a supplementary return and of the payment of the additional tax: the end of the month following
     * the month in which the reasons were found (§ 141 odst. 1 and 7 DŘ), moved to the next working day (§ 33 odst. 4 DŘ).
     *
     * @param string $discoveredOn Y-m-d
     */
    public static function deadline(string $discoveredOn): string
    {
        $end = (new \DateTimeImmutable($discoveredOn))->modify('last day of next month');
        return CzechWorkingDays::shiftToWorkingDay($end)->format('Y-m-d');
    }
}
