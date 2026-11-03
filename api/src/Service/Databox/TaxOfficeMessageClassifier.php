<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

use MyInvoice\Service\Export\ExportFilename;
use MyInvoice\Service\Report\CzechWorkingDays;

/**
 * Recognises messages from the tax administration (finanční úřad, Specializovaný finanční úřad, GFŘ, OFŘ) and, for
 * the one document type the application can explain with confidence, derives the statutory deadline hints.
 *
 * The sender name in an ISDS envelope is filled in by ISDS from the box register, the sender cannot choose it, so a
 * name match is as reliable as the box ID.
 */
final class TaxOfficeMessageClassifier
{
    public const KIND_KH_CALL = 'kh_call';   // call concerning the control statement (kontrolní hlášení)
    public const KIND_CALL = 'call';         // any other call ("výzva")
    public const KIND_OTHER = 'other';

    /**
     * @param array<string,mixed> $message envelope fields (sender_box_id, sender_name, annotation)
     * @return array{is_tax_office:bool, kind:?string}
     */
    public static function classify(array $message): array
    {
        $isTaxOffice = TaxOfficeCodebook::isTaxOfficeBox($message['sender_box_id'] ?? null)
            || preg_match(
                '/^(specializovany financni urad|financni urad\b|financni sprava\b|generalni financni reditelstvi|odvolaci financni reditelstvi)/',
                self::fold((string) ($message['sender_name'] ?? '')),
            ) === 1;
        if (!$isTaxOffice) {
            return ['is_tax_office' => false, 'kind' => null];
        }

        $text = self::fold((string) ($message['annotation'] ?? ''));
        $isCall = preg_match('/\bvyzv|odstraneni pochybnosti|odstraneni vad/', $text) === 1;
        $kind = match (true) {
            $isCall && str_contains($text, 'kontroln') => self::KIND_KH_CALL,
            $isCall => self::KIND_CALL,
            default => self::KIND_OTHER,
        };
        return ['is_tax_office' => true, 'kind' => $kind];
    }

    /**
     * Deadline hints for the UI. They are orientation only - the binding period is the one in the document itself.
     *
     * Control statement calls (§ 101g of the VAT act, 235/2004 Sb.; text of 2026-01-01 on zakonyprolidi.cz):
     *  - call to FILE a missing control statement: substitute period "do 5 dnů od oznámení výzvy" (odst. 1),
     *  - call to change / complete / confirm data: "do 17 dnů ode dne dodání výzvy do datové schránky" (odst. 3).
     * A period that ends on a weekend or holiday moves to the next working day (§ 33 odst. 4 tax code).
     *
     * @param array<string,mixed> $message stored row (kind, delivery_time, acceptance_time as 'Y-m-d H:i:s' strings)
     * @return list<array{code:string, law:string, due:?string, delivered_by:?string}>
     */
    public static function deadlineHints(array $message): array
    {
        if (($message['tax_office_kind'] ?? null) !== self::KIND_KH_CALL) {
            return [];
        }
        $hints = [];

        $delivered = self::date($message['delivery_time'] ?? null);
        $hints[] = [
            'code' => 'kh_change_17d',
            'law' => '§ 101g odst. 3 ZDPH',
            'due' => $delivered !== null ? self::shift($delivered->modify('+17 days')) : null,
            'delivered_by' => 'box',
        ];

        $accepted = self::date($message['acceptance_time'] ?? null);
        $hints[] = [
            'code' => 'kh_file_5d',
            'law' => '§ 101g odst. 1 ZDPH',
            'due' => $accepted !== null ? self::shift($accepted->modify('+5 days')) : null,
            'delivered_by' => 'notice',
        ];
        return $hints;
    }

    private static function shift(\DateTimeImmutable $d): string
    {
        return CzechWorkingDays::shiftToWorkingDay($d)->format('Y-m-d');
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTime(0, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Lower-case ASCII fold of Czech text for keyword matching. */
    private static function fold(string $text): string
    {
        return mb_strtolower(ExportFilename::transliterate($text));
    }
}
