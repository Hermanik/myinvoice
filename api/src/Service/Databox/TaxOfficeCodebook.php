<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * Data boxes of the Czech tax offices, keyed by the EPO "c_ufo" code that the application already stores
 * (supplier.financial_office_code, the c_ufo attribute of every archived EPO XML).
 *
 * Sources (verified 2026-11-03):
 *  - c_ufo -> office: the official EPO codebook "ufo" (Territorial financial bodies),
 *    https://adisspr.mfcr.cz/mepo/pub/api/ciselniky/soubor/ufo (columns c_ufo|nazu_ufo|...|k_ufo_vema), description at
 *    https://mojedane.gov.cz/pmd/dokumentace/ciselniky/ukazka/ufo
 *  - office -> data box ID: the contact page of the Financni sprava (field "ID datove schranky" of each office),
 *    https://financnisprava.gov.cz/cs/kontakty  (e.g. https://financnisprava.gov.cz/cs/financni-sprava/kontakty/4000-specializovany-financni-urad);
 *    the office number ("Cislo uradu", k_ufo_vema) is the key of that page. The Specializovany financni urad also
 *    publishes it in its information duty https://financnisprava.gov.cz/assets/PAW/Document/BoardFiles/252627_10624954_20250923145746.pdf
 *  - Since 2023-07-01 filings go to the box of the office (urad), not to a territorial workplace (those boxes were
 *    closed), https://financnisprava.gov.cz/cs/financni-sprava/novinky/novinky-2023/datove-schranky-zrusenych-fu
 *
 * Every ID passes the official Luhn mod 32 check ({@see DataboxId::isValid()}); a unit test guards that.
 * Refreshing: read the "ID datove schranky" of the office on the contact page above and update the table.
 */
final class TaxOfficeCodebook
{
    /** @var array<string, array{name:string, box_id:string, office_number:string}> */
    private const OFFICES = [
        '451' => ['name' => 'Finanční úřad pro hlavní město Prahu', 'box_id' => '7nyn2d9', 'office_number' => '2000'],
        '452' => ['name' => 'Finanční úřad pro Středočeský kraj', 'box_id' => '6sxny3p', 'office_number' => '2100'],
        '453' => ['name' => 'Finanční úřad pro Jihočeský kraj', 'box_id' => 'scdnz6b', 'office_number' => '2200'],
        '454' => ['name' => 'Finanční úřad pro Plzeňský kraj', 'box_id' => 'gf9n2e3', 'office_number' => '2300'],
        '455' => ['name' => 'Finanční úřad pro Karlovarský kraj', 'box_id' => 'q9in2ev', 'office_number' => '2400'],
        '456' => ['name' => 'Finanční úřad pro Ústecký kraj', 'box_id' => 'qjfn2bj', 'office_number' => '2500'],
        '457' => ['name' => 'Finanční úřad pro Liberecký kraj', 'box_id' => 'zcqn2bf', 'office_number' => '2600'],
        '458' => ['name' => 'Finanční úřad pro Královéhradecký kraj', 'box_id' => 'fj8ny4i', 'office_number' => '2700'],
        '459' => ['name' => 'Finanční úřad pro Pardubický kraj', 'box_id' => '95zn2bb', 'office_number' => '2800'],
        '460' => ['name' => 'Finanční úřad pro Kraj Vysočina', 'box_id' => 'tqjn2cy', 'office_number' => '2900'],
        '461' => ['name' => 'Finanční úřad pro Jihomoravský kraj', 'box_id' => 'qdhny4c', 'office_number' => '3000'],
        '462' => ['name' => 'Finanční úřad pro Olomoucký kraj', 'box_id' => '25nnz67', 'office_number' => '3100'],
        '463' => ['name' => 'Finanční úřad pro Moravskoslezský kraj', 'box_id' => '4hun2cu', 'office_number' => '3200'],
        '464' => ['name' => 'Finanční úřad pro Zlínský kraj', 'box_id' => 'n69nz5y', 'office_number' => '3300'],
        '13' => ['name' => 'Specializovaný finanční úřad', 'box_id' => '7cs8cge', 'office_number' => '4000'],
    ];

    /** Bodies of the tax administration that are not a filing target but send mail (senders only). */
    private const OTHER_SENDERS = [
        '0' => ['name' => 'Generální finanční ředitelství', 'box_id' => 'p9iwj4f', 'office_number' => '7000'],
    ];

    /** @return array{name:string, box_id:string, office_number:string}|null */
    public static function forUfo(?string $cUfo): ?array
    {
        $key = ltrim(trim((string) $cUfo), '0');
        return self::OFFICES[$key] ?? null;
    }

    /** True for any box ID that belongs to the tax administration (offices incl. the GFR). */
    public static function isTaxOfficeBox(?string $dbId): bool
    {
        return self::nameForBox($dbId) !== null;
    }

    public static function nameForBox(?string $dbId): ?string
    {
        if ($dbId === null || $dbId === '') {
            return null;
        }
        $dbId = DataboxId::normalize($dbId);
        foreach (self::OFFICES + self::OTHER_SENDERS as $office) {
            if ($office['box_id'] === $dbId) {
                return $office['name'];
            }
        }
        return null;
    }

    /** @return array<string, array{name:string, box_id:string, office_number:string}> keyed by c_ufo */
    public static function all(): array
    {
        return self::OFFICES;
    }
}
