<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use Rikudou\Iban\Iban\CzechIbanAdapter;

/**
 * Codebook of the tax payment accounts of the Czech tax offices (VAT account per office).
 *
 * A tax payment account is `[prefix]-[matrika]/0710`:
 *   - the prefix (předčíslí) defines the kind of tax — 705 = daň z přidané hodnoty (all offices,
 *     incl. the Specializovaný finanční úřad); account 705 must NOT be used for the OSS regime,
 *   - the matrika (the number after the dash) defines the office,
 *   - 0710 is the ČNB bank code.
 * The variable symbol of a VAT payment is the root of the DIČ — the digits after "CZ"
 * (for a natural person with a rodné číslo: those 9–10 digits). No specific symbol is used
 * (that is only for the § 109a ZDPH security payments, prefix 80039).
 *
 * The app stores the office as the EPO `c_ufo` code (supplier.financial_office_code). It is the
 * number of the "Územní finanční orgány" (ufo) codebook: 451–464 for the 14 regional offices and
 * 13 for the Specializovaný finanční úřad.
 *
 * Sources (state of 1 Jan 2026 — re-check them when the Finanční správa publishes new annexes):
 *   - Jak správně zaplatit daň finančnímu úřadu v roce 2026 (variable symbol rules, bank code):
 *     https://financnisprava.gov.cz/assets/cs/prilohy/d-placeni-dani/Jak_spravne_zaplatit_dan_FU_v_roce_2026.pdf
 *   - Příloha č. 1 — matriky bankovních účtů FÚ a SFÚ:
 *     https://financnisprava.gov.cz/assets/cs/prilohy/d-placeni-dani/Priloha_1_bankovni_ucty_fu_sfu_01012026.pdf
 *   - Příloha č. 2 — předčíslí účtů FÚ (daň z přidané hodnoty = 705):
 *     https://financnisprava.gov.cz/assets/cs/prilohy/d-placeni-dani/Priloha_2_pbu_fu_mimo_sfu_01012026.pdf
 *   - Příloha č. 3 — účty Specializovaného FÚ (daň z přidané hodnoty = 705-77620021):
 *     https://financnisprava.gov.cz/assets/cs/prilohy/d-placeni-dani/Priloha_3_Bankovni_ucty_SFU_12062026.pdf
 *   - Příloha č. 4 / č. 5 — the most used accounts incl. IBAN (cross-check of the table below):
 *     https://financnisprava.gov.cz/assets/cs/prilohy/d-placeni-dani/priloha_4_bu_fu_nejcastejsi_dane_01012026.pdf
 *     https://financnisprava.gov.cz/assets/cs/prilohy/d-placeni-dani/priloha_5_bu_fu_nejcastejsi_dane_IBAN_01012026.pdf
 *   - overview page: https://financnisprava.gov.cz/cs/dane/placeni-dani/bankovni-ucty-financnich-uradu
 *   - c_ufo codes: https://podpora.mojedane.gov.cz/cs/seznam-okruhu/rozhrani-pro-treti-strany/informace-k-ciselniku-ufo-platnem-od-1-1-4382
 */
final class FinancialOfficeAccounts
{
    public const BANK_CODE = '0710';
    /** Předčíslí of "Daň z přidané hodnoty" (not valid for the OSS special regime). */
    public const VAT_PREFIX = '705';

    /** @var array<string, array{name:string, matrika:string}> keyed by the EPO c_ufo code */
    private const OFFICES = [
        '451' => ['name' => 'Finanční úřad pro hlavní město Prahu', 'matrika' => '77628031'],
        '452' => ['name' => 'Finanční úřad pro Středočeský kraj', 'matrika' => '77628111'],
        '453' => ['name' => 'Finanční úřad pro Jihočeský kraj', 'matrika' => '77627231'],
        '454' => ['name' => 'Finanční úřad pro Plzeňský kraj', 'matrika' => '77627311'],
        '455' => ['name' => 'Finanční úřad pro Karlovarský kraj', 'matrika' => '77629341'],
        '456' => ['name' => 'Finanční úřad pro Ústecký kraj', 'matrika' => '77621411'],
        '457' => ['name' => 'Finanční úřad pro Liberecký kraj', 'matrika' => '77628461'],
        '458' => ['name' => 'Finanční úřad pro Královéhradecký kraj', 'matrika' => '77626511'],
        '459' => ['name' => 'Finanční úřad pro Pardubický kraj', 'matrika' => '77622561'],
        '460' => ['name' => 'Finanční úřad pro Kraj Vysočina', 'matrika' => '67626681'],
        '461' => ['name' => 'Finanční úřad pro Jihomoravský kraj', 'matrika' => '77628621'],
        '462' => ['name' => 'Finanční úřad pro Olomoucký kraj', 'matrika' => '47623811'],
        '463' => ['name' => 'Finanční úřad pro Moravskoslezský kraj', 'matrika' => '77621761'],
        '464' => ['name' => 'Finanční úřad pro Zlínský kraj', 'matrika' => '47620661'],
        '13'  => ['name' => 'Specializovaný finanční úřad', 'matrika' => '77620021'],
    ];

    /** The c_ufo as stored in settings, normalised (digits only, no leading zeros), or null. */
    public static function normalizeCode(?string $code): ?string
    {
        $digits = ltrim(preg_replace('/\D+/', '', (string) $code) ?? '', '0');
        return $digits === '' ? null : $digits;
    }

    /** @return array{code:string, name:string, matrika:string}|null */
    public static function office(?string $code): ?array
    {
        $code = self::normalizeCode($code);
        if ($code === null || !isset(self::OFFICES[$code])) {
            return null;
        }
        return ['code' => $code] + self::OFFICES[$code];
    }

    /** @return list<string> the known c_ufo codes */
    public static function codes(): array
    {
        // PHP turns numeric-string array keys into ints.
        return array_map('strval', array_keys(self::OFFICES));
    }

    /**
     * The VAT account of the office with the given c_ufo, or null for an unknown code.
     *
     * @return array{office_code:string, office_name:string, prefix:string, number:string, bank_code:string, display:string, iban:string}|null
     */
    public static function vatAccount(?string $code): ?array
    {
        $office = self::office($code);
        if ($office === null) {
            return null;
        }
        return [
            'office_code' => $office['code'],
            'office_name' => $office['name'],
            'prefix'      => self::VAT_PREFIX,
            'number'      => $office['matrika'],
            'bank_code'   => self::BANK_CODE,
            'display'     => self::VAT_PREFIX . '-' . $office['matrika'] . '/' . self::BANK_CODE,
            'iban'        => self::iban(self::VAT_PREFIX, $office['matrika'], self::BANK_CODE),
        ];
    }

    /**
     * The variable symbol of a VAT payment: the DIČ root (digits after "CZ"). Null when the DIČ
     * is missing or is not a Czech one — the caller must not guess a symbol.
     */
    public static function variableSymbol(?string $dic): ?string
    {
        $dic = strtoupper(preg_replace('/\s+/', '', (string) $dic) ?? '');
        if (preg_match('/^(?:CZ)?(\d{8,10})$/', $dic, $m) !== 1) {
            return null;
        }
        return $m[1];
    }

    /** Czech IBAN of a domestic account (the library computes the ISO 13616 check digits). */
    public static function iban(string $prefix, string $number, string $bankCode): string
    {
        return (new CzechIbanAdapter($prefix . '-' . $number, $bankCode))->asString();
    }

    /**
     * Splits a domestic account as seen on a bank statement into prefix + number (both without
     * leading zeros). Understands `705-77628031`, `705-77628031/0710`, the 16-digit padded GPC
     * form and a Czech IBAN. A statement without a dash and with up to 10 digits has no prefix.
     *
     * @return array{prefix:string, number:string, bank_code:?string}|null
     */
    public static function parseAccount(string $raw, ?string $bankCode = null): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ibanPart = AccountNumberNormalizer::czechIbanAccountPart($raw);
        if ($ibanPart !== null) {
            return [
                'prefix'    => ltrim(substr($ibanPart, 0, 6), '0'),
                'number'    => ltrim(substr($ibanPart, 6), '0'),
                'bank_code' => AccountNumberNormalizer::czechIbanBankCode($raw),
            ];
        }
        $compact = strtoupper((string) preg_replace('/\s+/', '', $raw));
        $bank = $bankCode !== null && trim($bankCode) !== '' ? trim($bankCode) : null;
        if (str_contains($compact, '/')) {
            [$compact, $suffix] = explode('/', $compact, 2);
            $bank ??= preg_match('/^\d{4}$/', $suffix) === 1 ? $suffix : null;
        }
        if (str_contains($compact, '-')) {
            [$prefix, $number] = explode('-', $compact, 2);
        } else {
            $digits = (string) preg_replace('/\D+/', '', $compact);
            if (strlen($digits) > 10) {
                $prefix = substr($digits, 0, -10);
                $number = substr($digits, -10);
            } else {
                $prefix = '';
                $number = $digits;
            }
        }
        $prefix = ltrim((string) preg_replace('/\D+/', '', $prefix), '0');
        $number = ltrim((string) preg_replace('/\D+/', '', $number), '0');
        if ($number === '') {
            return null;
        }
        return ['prefix' => $prefix, 'number' => $number, 'bank_code' => $bank];
    }

    /**
     * Does a statement's counterparty account (+ optional bank code) denote the given tax account?
     *
     * @param array{prefix:string, number:string, bank_code:string} $account from {@see vatAccount()}
     */
    public static function accountMatches(string $rawAccount, ?string $rawBankCode, array $account): bool
    {
        $parsed = self::parseAccount($rawAccount, $rawBankCode);
        if ($parsed === null) {
            return false;
        }
        if ($parsed['bank_code'] !== null && $parsed['bank_code'] !== $account['bank_code']) {
            return false;
        }
        return $parsed['prefix'] === ltrim($account['prefix'], '0')
            && $parsed['number'] === ltrim($account['number'], '0');
    }
}
