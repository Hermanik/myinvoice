<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * The rows of the DPH return (DPHDP3) the app generates: their XML attributes and the arithmetic of rows 62 / 63 / 66.
 * One definition for the builder, the supplementary return (differences) and the payment liability.
 *
 * Rows 3-13 and 40-47 follow the official form (EPO structure
 * https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_detail.faces?zkratka=DPHDP3 ).
 *
 * Row 45 (`odp_rez_nar`, column "V plné výši") is fed only by the agenda "Odpočet při registraci" (§ 79 / § 79a
 * ZDPH) — see {@see \MyInvoice\Service\Vat\RegistrationDeductionService}; it has no tax base column.
 */
final class Dphdp3Lines
{
    /** Row => XML attributes; `base` / `vat` null = the row has no such column. */
    public const MAP = [
        '1'  => ['veta' => 1, 'base' => 'obrat23',    'vat' => 'dan23'],
        '2'  => ['veta' => 1, 'base' => 'obrat5',     'vat' => 'dan5'],
        '3'  => ['veta' => 1, 'base' => 'p_zb23',     'vat' => 'dan_pzb23'],
        '4'  => ['veta' => 1, 'base' => 'p_zb5',      'vat' => 'dan_pzb5'],
        '5'  => ['veta' => 1, 'base' => 'p_sl23_e',   'vat' => 'dan_psl23_e'],
        '6'  => ['veta' => 1, 'base' => 'p_sl5_e',    'vat' => 'dan_psl5_e'],
        '7'  => ['veta' => 1, 'base' => 'dov_zb23',   'vat' => 'dan_dzb23'],
        '8'  => ['veta' => 1, 'base' => 'dov_zb5',    'vat' => 'dan_dzb5'],
        '10' => ['veta' => 1, 'base' => 'rez_pren23', 'vat' => 'dan_rpren23'],
        '11' => ['veta' => 1, 'base' => 'rez_pren5',  'vat' => 'dan_rpren5'],
        '12' => ['veta' => 1, 'base' => 'p_sl23_z',   'vat' => 'dan_psl23_z'],
        '13' => ['veta' => 1, 'base' => 'p_sl5_z',    'vat' => 'dan_psl5_z'],
        '20' => ['veta' => 2, 'base' => 'dod_zb',      'vat' => null],
        '21' => ['veta' => 2, 'base' => 'pln_sluzby',  'vat' => null],
        '22' => ['veta' => 2, 'base' => 'pln_vyvoz',   'vat' => null],
        '23' => ['veta' => 2, 'base' => 'dod_dop_nrg', 'vat' => null],
        '24' => ['veta' => 2, 'base' => 'pln_zaslani', 'vat' => null],
        '25' => ['veta' => 2, 'base' => 'pln_rez_pren','vat' => null],
        '26' => ['veta' => 2, 'base' => 'pln_ost',     'vat' => null],
        '30' => ['veta' => 3, 'base' => 'tri_pozb',   'vat' => null],
        '31' => ['veta' => 3, 'base' => 'tri_dozb',   'vat' => null],
        '50' => ['veta' => 5, 'base' => 'plnosv_kf',  'vat' => null],
        '40' => ['veta' => 4, 'base' => 'pln23',      'vat' => 'odp_tuz23_nar'],
        '41' => ['veta' => 4, 'base' => 'pln5',       'vat' => 'odp_tuz5_nar'],
        '42' => ['veta' => 4, 'base' => 'dov_cu',     'vat' => 'odp_cu_nar'],
        '43' => ['veta' => 4, 'base' => 'nar_zdp23',  'vat' => 'od_zdp23'],
        '44' => ['veta' => 4, 'base' => 'nar_zdp5',   'vat' => 'od_zdp5'],
        '45' => ['veta' => 4, 'base' => null,         'vat' => 'odp_rez_nar'],
        '47' => ['veta' => 4, 'base' => 'nar_maj',    'vat' => null],
    ];

    /** Does the row carry a tax (an amount that enters rows 62 / 63), as opposed to a base-only row? */
    public static function carriesTax(string $line): bool
    {
        return (self::MAP[$line]['vat'] ?? null) !== null;
    }

    /** Output tax rows (DPH na výstupu): 1-29. Input tax rows (odpočet): 40 and above. */
    public static function isOutput(string $line): bool
    {
        return (int) $line < 40;
    }

    /**
     * Rows 62 / 63 and their difference (row 64 - 65, or row 66 of a supplementary return): the tax of the rows that
     * carry one, every row rounded to whole CZK the way it is entered on the form. Rows the builder cannot render
     * (not in {@see MAP}) are neither shown nor counted.
     *
     * @param array<string, array{base?:float, vat:float}> $lines
     * @return array{output: int, input: int, tax: int}
     */
    public static function totals(array $lines): array
    {
        $output = $input = 0;
        foreach ($lines as $row => $data) {
            if (!self::carriesTax((string) $row)) {
                continue;
            }
            if (self::isOutput((string) $row)) {
                $output += (int) round((float) $data['vat']);
            } else {
                $input += (int) round((float) $data['vat']);
            }
        }
        return ['output' => $output, 'input' => $input, 'tax' => $output - $input];
    }
}
