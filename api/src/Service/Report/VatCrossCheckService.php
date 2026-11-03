<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Service\Vat\VatStatusException;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * "Kontrola souladu": compares the totals the three VAT outputs of one period report about the same
 * supplies — the DPH return (DPHDP3), the kontrolní hlášení (KH) and the souhrnné hlášení (SH) —
 * before the XML is downloaded.
 *
 * The pairs follow the official links between the KH sections and the rows of the return (GFŘ,
 * "Metodická informace k vyplnění kontrolního hlášení", section 1.1 and the A.2 / A.4 / B.2 notes:
 * https://financnisprava.gov.cz/assets/cs/prilohy/d-seznam-dani/Metodicka_informace_k_vyplneni_KH_20240101.pdf):
 *
 *   rows 1, 2   (output, 21 % / 12 %)       <-> KH A.4 + A.5
 *   row 25      (RC, supplier)              <-> KH A.1
 *   rows 10, 11 (RC, recipient, 21 / 12 %)  <-> KH B.1
 *   rows 40, 41 (input, 21 % / 12 %)        <-> KH B.2 + B.3
 *   rows 3-6, 12, 13 (self-assessed, EU / 3rd country) <-> KH A.2
 *   rows 20, 21, 31 (supplies to the EU)    <-> SH (goods 0, services 3, triangular 2)
 *
 * Both outputs are built from the same ledger, so a mismatch means a document is routed to a
 * different place in the two (an explicit KH section on the classification that disagrees with the
 * return row, a missing VAT ID of an EU customer, ...). The documents that cause it are listed.
 *
 * Severity: the difference of one pair is `ok` within rounding (the XML amounts are whole CZK per
 * row, the return rounds the sums), a `warning` up to {@see self::TOLERANCE} CZK — the tolerance
 * the app already applies to the KH <-> return control — and an `error` above.
 */
final class VatCrossCheckService
{
    public const TOLERANCE = 1000.0;
    private const MAX_DOCUMENTS = 50;

    /** DPHDP3 row -> the KH group it must be reported in. */
    private const LINE_GROUP = [
        '1' => 'DOM', '2' => 'DOM', '40' => 'DOM', '41' => 'DOM',
        '25' => 'A.1', '10' => 'B.1', '11' => 'B.1',
        '3' => 'A.2', '4' => 'A.2', '5' => 'A.2', '6' => 'A.2', '12' => 'A.2', '13' => 'A.2',
    ];

    /** DPHDP3 row -> id of the check it takes part in. */
    private const LINE_CHECK = [
        '1' => 'output_21', '2' => 'output_12', '25' => 'rc_supplier',
        '10' => 'rc_recipient_21', '11' => 'rc_recipient_12', '40' => 'input_21', '41' => 'input_12',
        '3' => 'a2', '4' => 'a2', '5' => 'a2', '6' => 'a2', '12' => 'a2', '13' => 'a2',
        '20' => 'eu_goods', '21' => 'eu_services', '31' => 'eu_triangular',
    ];

    /** KH group -> the checks a surplus document (in KH, not in the return) distorts. */
    private const GROUP_CHECKS_SALE = ['DOM' => ['output_21', 'output_12'], 'A.1' => ['rc_supplier']];
    private const GROUP_CHECKS_PURCHASE = ['DOM' => ['input_21', 'input_12'], 'B.1' => ['rc_recipient_21', 'rc_recipient_12'], 'A.2' => ['a2']];

    public function __construct(
        private readonly DphPriznaniBuilder $dph,
        private readonly KontrolniHlaseniBuilder $kh,
        private readonly SouhrnneHlaseniBuilder $sh,
        private readonly VatLedgerService $ledger,
        private readonly VatStatusService $vatStatus,
    ) {}

    /**
     * @param 'monthly'|'quarterly' $period
     * @return array<string,mixed>
     */
    public function check(int $supplierId, int $year, int $month, string $period): array
    {
        $result = ['period' => ['year' => $year, 'month' => $month, 'period' => $period], 'tolerance' => self::TOLERANCE,
                   'applicable' => true, 'reason' => null, 'status' => 'ok', 'checks' => [], 'documents' => [],
                   'documents_truncated' => false];
        try {
            $this->vatStatus->assertVatDutiesInReportPeriod($supplierId, $year, $month, $period);
        } catch (VatStatusException) {
            return ['applicable' => false, 'reason' => 'not_vat_payer_in_period'] + $result;
        }
        try {
            $dph = $this->dph->build($supplierId, $year, $month, $period)['summary'];
            $kh = $this->kh->build($supplierId, $year, $month, $period)['summary'];
            $sh = $this->sh->build($supplierId, $year, $month, $period)['summary'];
        } catch (\Throwable $e) {
            return ['applicable' => false, 'reason' => 'build_failed', 'message' => $e->getMessage()] + $result;
        }
        if (($dph['typ_platce'] ?? 'P') === 'I') {
            // An identified person files no KH and no deductions — nothing to compare.
            return ['applicable' => false, 'reason' => 'identified_person'] + $result;
        }

        $result['checks'] = self::buildChecks((array) ($dph['lines'] ?? []), (array) ($kh['totals'] ?? []), (array) ($sh['rows'] ?? []));
        $statuses = array_column($result['checks'], 'status');
        $result['status'] = in_array('error', $statuses, true) ? 'error' : (in_array('warning', $statuses, true) ? 'warning' : 'ok');

        if ($result['status'] !== 'ok') {
            [$start, $end] = ReportPeriod::range($year, $month, $period);
            $documents = self::causingDocuments($this->ledger->rows($supplierId, $start, $end, includeDrafts: false));
            $result['documents_truncated'] = count($documents) > self::MAX_DOCUMENTS;
            $result['documents'] = array_slice($documents, 0, self::MAX_DOCUMENTS);
        }
        return $result;
    }

    /**
     * The compared pairs with differences and severities. Pairs with nothing on either side are dropped.
     *
     * @param array<string,array<string,mixed>> $lines DPHDP3 summary lines (row => base/vat/count)
     * @param array<string,array<string,float>> $khTotals KH summary totals
     * @param list<array<string,mixed>> $shRows SH summary rows (sh_type, amount)
     * @return list<array<string,mixed>>
     */
    public static function buildChecks(array $lines, array $khTotals, array $shRows): array
    {
        $dphSum = static function (array $rows) use ($lines): array {
            $base = $vat = 0.0;
            $count = 0;
            foreach ($rows as $row) {
                $base += (float) ($lines[$row]['base'] ?? 0);
                $vat += (float) ($lines[$row]['vat'] ?? 0);
                $count += (int) ($lines[$row]['count'] ?? 0);
            }
            return ['lines' => $rows, 'base' => round($base, 2), 'vat' => round($vat, 2), 'count' => $count];
        };
        $kh = static fn (string $group, string $rate): array => [
            'base' => (float) ($khTotals[$group]['base' . $rate] ?? 0),
            'vat'  => (float) ($khTotals[$group]['vat' . $rate] ?? 0),
        ];
        $shSum = static function (string $type) use ($shRows): float {
            $sum = 0.0;
            foreach ($shRows as $row) {
                if ((string) ($row['sh_type'] ?? '') === $type) {
                    $sum += (float) ($row['amount'] ?? 0);
                }
            }
            return round($sum, 2);
        };

        $a2 = $khTotals['a2'] ?? [];
        $pairs = [
            ['output_21', $dphSum(['1']), 'KH', 'A.4 + A.5', $kh('a4_a5', '21')],
            ['output_12', $dphSum(['2']), 'KH', 'A.4 + A.5', $kh('a4_a5', '12')],
            ['rc_supplier', $dphSum(['25']), 'KH', 'A.1', ['base' => (float) ($khTotals['a1']['base'] ?? 0), 'vat' => null]],
            ['rc_recipient_21', $dphSum(['10']), 'KH', 'B.1', $kh('b1', '21')],
            ['rc_recipient_12', $dphSum(['11']), 'KH', 'B.1', $kh('b1', '12')],
            ['input_21', $dphSum(['40']), 'KH', 'B.2 + B.3', $kh('b2_b3', '21')],
            ['input_12', $dphSum(['41']), 'KH', 'B.2 + B.3', $kh('b2_b3', '12')],
            ['a2', $dphSum(['3', '4', '5', '6', '12', '13']), 'KH', 'A.2', ['base' => (float) ($a2['base'] ?? 0), 'vat' => (float) ($a2['vat'] ?? 0)]],
            ['eu_goods', $dphSum(['20']), 'SH', 'k_pln_eu 0', ['base' => $shSum('0'), 'vat' => null]],
            ['eu_services', $dphSum(['21']), 'SH', 'k_pln_eu 3', ['base' => $shSum('3'), 'vat' => null]],
            ['eu_triangular', $dphSum(['31']), 'SH', 'k_pln_eu 2', ['base' => $shSum('2'), 'vat' => null]],
        ];

        $checks = [];
        foreach ($pairs as [$id, $dph, $target, $section, $other]) {
            $dphVat = $id === 'rc_supplier' || str_starts_with($id, 'eu_') ? null : $dph['vat'];
            $otherVat = $dphVat === null ? null : $other['vat'];
            if (abs($dph['base']) < 0.005 && abs($other['base']) < 0.005
                && abs((float) $dphVat) < 0.005 && abs((float) $otherVat) < 0.005
            ) {
                continue;
            }
            $diffBase = round($dph['base'] - $other['base'], 2);
            $diffVat = $dphVat === null ? null : round($dphVat - (float) $otherVat, 2);
            $checks[] = [
                'id'     => $id,
                'dph'    => ['lines' => $dph['lines'], 'base' => $dph['base'], 'vat' => $dphVat],
                'other'  => ['document' => $target, 'section' => $section, 'base' => round($other['base'], 2), 'vat' => $otherVat !== null ? round((float) $otherVat, 2) : null],
                'diff'   => ['base' => $diffBase, 'vat' => $diffVat],
                'status' => self::severity(max(abs($diffBase), abs((float) $diffVat)), $dph['count']),
            ];
        }
        return $checks;
    }

    /**
     * ok = within rounding (grows with the number of documents), warning = up to the tolerance, error = above.
     *
     * @return 'ok'|'warning'|'error'
     */
    public static function severity(float $absDifference, int $documentCount): string
    {
        $rounding = max(1.0, (float) ceil($documentCount / 2));
        if ($absDifference <= $rounding) {
            return 'ok';
        }
        return $absDifference <= self::TOLERANCE ? 'warning' : 'error';
    }

    /**
     * Documents that are reported in a different place in the DPH return and in the KH / SH.
     *
     * @param list<array<string,mixed>> $rows VatLedgerService rows
     * @return list<array<string,mixed>>
     */
    public static function causingDocuments(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (abs((float) $r['base_czk']) < 0.005) {
                continue;
            }
            $source = (string) $r['source'];
            $line = $r['dphdp3_line'] !== null ? (string) $r['dphdp3_line'] : null;
            $expected = $line !== null ? (self::LINE_GROUP[$line] ?? null) : null;
            $route = KontrolniHlaseniBuilder::routeRow($r);
            // A 0 % row adds nothing to the domestic KH sections.
            if ($route === 'DOM' && (float) $r['vat_rate'] <= 0) {
                $route = null;
            }

            $problem = null;
            $checkIds = [];
            $routeChecks = $route === null ? [] : (($source === 'sale' ? self::GROUP_CHECKS_SALE : self::GROUP_CHECKS_PURCHASE)[$route] ?? []);
            if ($expected !== null && $route !== $expected) {
                // On the wrong KH section: it is missing where the return has it and is a surplus where it landed.
                $problem = 'missing_in_kh';
                $checkIds = array_values(array_unique([self::LINE_CHECK[$line], ...$routeChecks]));
            } elseif ($expected === null && $route !== null) {
                $problem = 'extra_in_kh';
                $checkIds = $routeChecks;
            } elseif (in_array($line, ['20', '21', '31'], true) && $source === 'sale' && SouhrnneHlaseniBuilder::classifyRow($r) === null) {
                $problem = 'missing_in_sh';
                $checkIds = [self::LINE_CHECK[$line]];
            }
            if ($problem === null || $checkIds === []) {
                continue;
            }
            $key = $source . ':' . $r['invoice_id'] . ':' . $problem;
            $out[$key] ??= [
                'source'       => $source,
                'invoice_id'   => (int) $r['invoice_id'],
                'doc_number'   => $r['vendor_invoice_number'] ?? $r['doc_number'],
                'tax_date'     => $r['tax_date'],
                'counterparty' => $r['counterparty_name'],
                'problem'      => $problem,
                'dph_line'     => $line,
                'kh_section'   => $route,
                'base_czk'     => 0.0,
                'checks'       => $checkIds,
            ];
            $out[$key]['base_czk'] = round($out[$key]['base_czk'] + (float) $r['base_czk'], 2);
        }
        return array_values($out);
    }
}
