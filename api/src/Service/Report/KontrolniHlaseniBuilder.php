<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Vat\VatStatus;
use MyInvoice\Service\Vat\VatStatusHistory;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Builder XML pro Kontrolní hlášení (DPHKH1) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * Periodicita (§ 101e zákona 235/2004 Sb.):
 *   - **PO** (právnická osoba) — VŽDY měsíčně (odst. 1).
 *   - **FO** (fyzická osoba/OSVČ) — ve lhůtě přiznání k DPH; pro kvartální plátce
 *     lze podávat kvartálně (odst. 2).
 *
 * Sekce KH:
 *   - **A.1** Plnění v režimu přenesené daňové povinnosti (dodavatel)
 *   - **A.2** Přijatá plnění od osoby neusazené v tuzemsku, kde daň přiznává příjemce
 *     (pořízení zboží § 25, služba § 24/§ 9/1, zboží s instalací). Patří sem
 *     i dodavatel bez EU DIČ (3. země, neplátce z EU) — jen s prázdnou identifikací,
 *     viz komentář u emisního bloku VetaA2.
 *   - **A.3** Plnění uskutečněná § 92a/b (dodání investičního zlata)
 *   - **A.4** Tuzemská plnění s DPH nad 10 000 Kč (vystavené)
 *   - **A.5** Tuzemská plnění s DPH **do** 10 000 Kč (sumace)
 *   - **B.1** Plnění v režimu přenesené daňové povinnosti (odběratel)
 *   - **B.2** Tuzemská přijatá plnění s DPH nad 10 000 Kč
 *   - **B.3** Tuzemská přijatá plnění s DPH **do** 10 000 Kč (sumace)
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní.
 */
final class KontrolniHlaseniBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
        // Limit A.4/A.5 a B.2/B.3 (10 000 Kč) + práh základní/snížená sazba — per
        // rok období z číselníku daňových konstant (admin override), ne natvrdo.
        private readonly TaxConstantsRepository $taxConstants,
        private readonly VatStatusService $vatStatus,
    ) {}

    /**
     * Kódy států pro VetaA2.k_stat — tabulka „Daňová identifikační čísla členských států
     * EU“ z dphkh1.xsd. Řecko má DPH kód EL (ne ISO GR), XI je Severní Irsko.
     *
     * GB v seznamu ZÁMĚRNĚ není, přestože ho tabulka v XSD pořád nese: po Brexitu už
     * britské DIČ není DIČ registrace k DPH v členském státě. Dodavatel z GB tak skoří
     * na prázdnou identifikaci, což je pro plnění ze 3. země správně.
     */
    private const KH_MEMBER_STATE_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];


    /**
     * @param KhFiling|null $filing the filing form (B / O / N / E), the date the reasons were found and the call of the
     *   tax office it answers (reference number, quick answer); regular by default
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, int $month, string $period = 'monthly', ?KhFiling $filing = null): array
    {
        $filing ??= new KhFiling();
        $form = $filing->form;
        $callReference = $filing->callReference;
        $supplier = $this->loadSupplier($supplierId);

        // A quarter ends on the last day of month quarter*3, INDEPENDENT of the passed $month (see ReportPeriod).
        $quarter = $period === 'quarterly' ? (int) ceil($month / 3) : null;
        [$start, $end] = ReportPeriod::range($year, $month, $period);
        $periodLabel = $quarter !== null ? sprintf('%04d-Q%d', $year, $quarter) : sprintf('%04d-%02d', $year, $month);
        // Filing deadline: the 25th of the month after the period, moved to a working day (§ 33/4 DŘ).
        // For a quarterly filing the end of the quarter decides, NOT the passed $month
        // (build(..., 4, 'quarterly') = Q2 gives 25. 7., not 25. 5.).
        $deadline = ReportPeriod::filingDeadline($year, $month, $period);

        // VAT status over the filing period from the dated history (not the live flag).
        $warnings = $this->validateSupplier($supplier, $period, $this->vatStatus->history($supplierId), $start, $end);

        if ($filing->isQuickAnswer()) {
            return $this->buildQuickAnswer($supplier, $year, $month, $period, $quarter, $filing, $periodLabel, $deadline, $warnings);
        }

        // Všechny sekce z jedné projekce kanonických řádků (VatLedgerService).
        ['a1' => $a1, 'a2' => $a2, 'a4' => $a4, 'a5' => $a5, 'b1' => $b1, 'b2' => $b2, 'b3' => $b3,
         'missing_rates' => $missingRates, 'estimated_codes' => $estimatedCodes]
            = $this->collectSections($supplierId, $start, $end);
        // Zahraniční RC bez explicitní klasifikace — fallback odhadl SLUŽBU (24e/24).
        // Jde-li o zboží, patří plnění na jiný řádek přiznání (23 → ř. 3, 25 → ř. 7)
        // → uživatel musí kód zvolit ručně; proto adresný warning per doklad.
        foreach ($estimatedCodes as $e) {
            $warnings[] = sprintf(
                '%s (%s, %s): klasifikace odhadnuta — kód %s (%s). %s',
                $e['doc'], $e['vendor'], $e['country'], $e['code'],
                $e['code'] === '24e' ? 'služba z EU, ř. 5' : 'služba ze 3. země, ř. 12',
                $e['code'] === '24e'
                    ? 'U pořízení zboží z EU zvol ručně kód 23.'
                    : 'U dovozu zboží ze 3. země zvol ručně kód 25.',
            );
        }
        // #238: doklady v cizí měně bez kurzu — akce je při stažení doplní z ČNB.
        if ($missingRates !== []) {
            $warnings[] = 'Chybí kurz u dokladů v cizí měně: '
                . implode(', ', VatLedgerService::missingExchangeRateLabels($missingRates))
                . '. Při stažení XML se doplní z ČNB.';
        }
        $a1 = $this->filterReverseChargeRowsWithDic($a1, 'A.1', $warnings);
        $b1 = $this->filterReverseChargeRowsWithDic($b1, 'B.1', $warnings);
        // A.2 — doplnění identifikace dodavatele. Řádek se nevyřazuje ani bez ní; musí
        // předcházet rekapitulaci VetaC, ať celk_zd_a2 sedí s tím, co odešlo.
        $a2 = $this->resolveA2Identification($a2, $warnings);
        $a4 = $this->filterKhAttributeConflicts($a4, 'A.4', $warnings);
        $b2 = $this->filterKhAttributeConflicts($b2, 'B.2', $warnings);

        [$dom, $dphkh] = $this->startDocument($supplier, $year, $month, $period, $quarter, $filing);

        // VetaA1 — Přenesená daňová povinnost (dodavatel).
        // XSD vyžaduje: dic_odb, c_evid_dd, duzp (NE "dppd"), zakl_dane1, kod_pred_pl.
        // kod_pred_pl '5' = obecný tuzemský reverse charge (defaultní hodnota, MFČR
        // číselník Kód předmětů plnění; ideálně by mělo přicházet z vat_classification_code).
        $rowNum = 0;
        foreach ($a1 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            $rowNum++;
            $v = $dom->createElement('VetaA1');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('duzp', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base']));
            $v->setAttribute('kod_pred_pl', $this->resolveKodPredPl($r['kod_pred_pl'] ?? null, $warnings));
            $dphkh->appendChild($v);
        }

        // VetaA2 — přijatá plnění s místem plnění v tuzemsku, u kterých daň přiznává příjemce
        // (§ 108): pořízení zboží z JČS § 25, přijetí služby § 24 / § 9 odst. 1, zboží
        // s instalací. Per XSD: k_stat (kód člen. státu), vatid_dod (VAT ID bez prefixu
        // země), c_evid_dd, dppd (required), zakl_dane1/dan1 (21%), zakl_dane2/dan2 (12%).
        // Plnění je z definice samovyměřené (vendor fakturuje bez DPH, my si daň přiznáme
        // sami) — `dan1`/`dan2` = base × sazba/100, ne pii.total_vat (které je 0 pro RC).
        //
        // Dodavatel BEZ EU DIČ (3. země, neplátce z EU) sem PATŘÍ, jen s prázdnou
        // identifikací. Metodická informace GFŘ k vyplnění KH i dokumentace `vatid_dod`
        // v dphkh1.xsd shodně říkají, že u dodavatele bez VAT ID (včetně „identifikace
        // zahraniční osoby povinné k dani“) pole „Identifikace dodavatele“ ZŮSTÁVÁ PRÁZDNÉ;
        // obě položky jsou v XSD `use="optional"` s `minLength="0"`. EPO na takový řádek
        // vypíše chyby č. 58 (kód státu) a č. 60 (VAT ID), ty jsou ale PROPUSTNÉ a podání
        // nebrání. Vyřazování těchto řádků rozbíjelo křížovou kontrolu `celk_zd_a2` proti
        // ř. 5/6/12/13 přiznání (tolerance ±1000 Kč) — viz issue #53.
        //
        // Daňový dopad = 0: DPHDP3 se řídí `dphdp3_line`, ne `kh_section`, takže
        // samovyměření i zrcadlový odpočet jsou naplněné nezávisle na téhle sekci.
        // Identifikaci doplňuje {@see resolveA2Identification()} / {@see a2Identification()}.
        $rowNum = 0;
        $celkA2 = 0.0;
        $celkA2Vat = 0.0;
        foreach ($a2 as $r) {
            $rowNum++;
            $v = $dom->createElement('VetaA2');
            $v->setAttribute('c_radku', (string) $rowNum);
            // Prázdné řetězce jsou záměr, ne chybějící data — viz komentář výše.
            $v->setAttribute('k_stat', (string) ($r['k_stat'] ?? ''));
            $v->setAttribute('vatid_dod', (string) ($r['vatid_dod'] ?? ''));
            $celkA2 += (float) $r['base21'] + (float) $r['base12'];
            $celkA2Vat += (float) $r['vat21'] + (float) $r['vat12'];
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1',       $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2',       $this->formatAmount($r['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaA4 — tuzemská plnění nad 10 000 Kč (vystavené). Bez `dic_odb` řádek neexistuje
        // (stejná past jako A.2) — směrování do A.4 proto vyžaduje DIČ už v buildSections()
        // a tenhle guard je pojistka. Aby vyřazený řádek nemohl zůstat v rekapitulaci,
        // sčítá se obrat23/obrat5 z REÁLNĚ emitovaných vět, ne z kandidátů.
        $rowNum = 0;
        $obrat23 = 0.0; $obrat5 = 0.0;
        $obratDan23 = 0.0; $obratDan5 = 0.0;
        foreach ($a4 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $obrat23 += (float) $r['base21']; $obrat5 += (float) $r['base12'];
            $obratDan23 += (float) $r['vat21']; $obratDan5 += (float) $r['vat12'];
            $rowNum++;
            $taxDate = $this->formatDate($r['tax_date']);
            $v = $dom->createElement('VetaA4');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('c_evid_dd', (string) $r['varsymbol']);
            $v->setAttribute('dppd', $taxDate);
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            $v->setAttribute('kod_rezim_pl', (string) ($r['kh_regime_code'] ?? '0'));
            $v->setAttribute('zdph_44', (string) ($r['kh_bad_debt'] ?? 'N'));
            $dphkh->appendChild($v);
        }

        // VetaA5 — tuzemská plnění do 10 000 Kč (sumace, 1 řádek)
        if ($a5['count'] > 0) {
            $v = $dom->createElement('VetaA5');
            $v->setAttribute('zakl_dane1', $this->formatAmount($a5['base21']));
            $v->setAttribute('dan1', $this->formatAmount($a5['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($a5['base12']));
            $v->setAttribute('dan2', $this->formatAmount($a5['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaB1 — Přenesená daňová povinnost (odběratel)
        $rowNum = 0;
        foreach ($b1 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            $rowNum++;
            $v = $dom->createElement('VetaB1');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_dod', $cleanDic);
            // XSD VetaB1 zná atribut 'duzp' (NE 'dppd' jako A.2/B.2) — odběratel v režimu
            // přenesení přiznává daň ke DUZP. Zároveň B.1 vykazuje SAMOVYMĚŘENOU daň
            // (dan1/dan2), ne jen základ — příjemce si daň sám přiznává (a odečítá).
            $v->setAttribute('duzp', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            $v->setAttribute('kod_pred_pl', $this->resolveKodPredPl($r['kod_pred_pl'] ?? null, $warnings));
            $dphkh->appendChild($v);
        }

        // VetaB2 — přijatá tuzemská nad 10 000 Kč.
        // XSD vyžaduje: pomer (A/N — poměrný odpočet podle §75) a zdph_44
        // (N = běžné, P = oprava nedobytné pohledávky podle §74b, A = §44 do 31.3.2019).
        // Default: oba 'N' (běžný odpočet, žádná oprava). Bez `dic_dod` řádek neexistuje —
        // pln23/pln5 se proto (jako u A.4) sčítá z reálně emitovaných vět.
        $rowNum = 0;
        $pln23 = 0.0; $pln5 = 0.0;
        $plnDan23 = 0.0; $plnDan5 = 0.0;
        foreach ($b2 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $pln23 += (float) $r['base21']; $pln5 += (float) $r['base12'];
            $plnDan23 += (float) $r['vat21']; $plnDan5 += (float) $r['vat12'];
            $rowNum++;
            $v = $dom->createElement('VetaB2');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('dic_dod', $cleanDic);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            // pomer = A když byl uplatněn poměrný odpočet §75 (částky jsou už zkrácené ve VatLedgerService).
            $v->setAttribute('pomer', !empty($r['is_pomer']) ? 'A' : 'N');
            $v->setAttribute('zdph_44', (string) ($r['kh_bad_debt'] ?? 'N'));
            $dphkh->appendChild($v);
        }

        // VetaB3 — přijatá tuzemská do 10 000 Kč (sumace)
        if ($b3['count'] > 0) {
            $v = $dom->createElement('VetaB3');
            $v->setAttribute('zakl_dane1', $this->formatAmount($b3['base21']));
            $v->setAttribute('dan1', $this->formatAmount($b3['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($b3['base12']));
            $v->setAttribute('dan2', $this->formatAmount($b3['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaC — rekapitulace plnění za období (obrat = uskutečněná, pln = přijatá).
        // Sumace všech sekcí: A4+A5 (sales), B2+B3 (purchases), A1 (RC sales),
        // B1 (RC purchases), A2 (samovyměření od plátce z JČS → celk_zd_a2).
        //
        // Pravidlo: rekapitulace smí obsahovat JEN to, co reálně odešlo jako věta. Sekce
        // s povinným identifikátorem (A.1/B.1 dic, A.4/B.2 dic_odb/dic_dod, A.2 k_stat +
        // vatid_dod) proto sčítají v emisních blocích výše; sumační A.5/B.3 nemají co
        // vyřadit a přičítají se tady.
        $obrat23 += (float) ($a5['base21'] ?? 0); $obrat5 += (float) ($a5['base12'] ?? 0);
        $obratDan23 += (float) ($a5['vat21'] ?? 0); $obratDan5 += (float) ($a5['vat12'] ?? 0);
        $pln23 += (float) ($b3['base21'] ?? 0); $pln5 += (float) ($b3['base12'] ?? 0);
        $plnDan23 += (float) ($b3['vat21'] ?? 0); $plnDan5 += (float) ($b3['vat12'] ?? 0);
        $plnRezPren = 0.0; foreach ($a1 as $r) { $plnRezPren += (float) $r['base']; }
        $rezPren23 = 0.0; $rezPren5 = 0.0;
        $rezPrenDan23 = 0.0; $rezPrenDan5 = 0.0;
        foreach ($b1 as $r) {
            $rezPren23 += (float) $r['base21'];
            $rezPren5 += (float) $r['base12'];
            $rezPrenDan23 += (float) $r['vat21'];
            $rezPrenDan5 += (float) $r['vat12'];
        }
        $vetaC = $dom->createElement('VetaC');
        $vetaC->setAttribute('obrat23',      $this->formatAmount($obrat23));
        $vetaC->setAttribute('obrat5',       $this->formatAmount($obrat5));
        $vetaC->setAttribute('pln23',        $this->formatAmount($pln23));
        $vetaC->setAttribute('pln5',         $this->formatAmount($pln5));
        $vetaC->setAttribute('pln_rez_pren', $this->formatAmount($plnRezPren));
        $vetaC->setAttribute('rez_pren23',   $this->formatAmount($rezPren23));
        $vetaC->setAttribute('rez_pren5',    $this->formatAmount($rezPren5));
        // celk_zd_a2 = celkový základ sekce A.2. Sčítá se z REÁLNĚ EMITOVANÝCH vět
        // VetaA2 (viz emisní blok výše), ne z kandidátů — plnění, které se do A.2
        // nevejde (dodavatel bez EU registrace k DPH), nesmí zůstat v rekapitulaci.
        // Za období, kde je jen samovyměření ze 3. země, tedy vyjde 0.
        $vetaC->setAttribute('celk_zd_a2',   $this->formatAmount($celkA2));
        $dphkh->appendChild($vetaC);

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => [
                'period'              => $periodLabel,
                'a1_count'            => count($a1),
                'a2_count'            => count($a2),
                'a4_count'            => count($a4),
                'a5_count_aggregated' => $a5['count'],
                'b1_count'            => count($b1),
                'b2_count'            => count($b2),
                'b3_count_aggregated' => $b3['count'],
                'form'                => $form,
                'archive_form'        => $filing->archiveForm(),
                'call'                => $callReference === null ? null : ['reference' => $callReference, 'answer' => null],
                'submission_deadline' => $deadline,
                // What the VetaC recapitulation reports, plus the tax — compared with the DPH
                // return by the cross-check (A.4+A.5 <-> rows 1/2, B.2+B.3 <-> 40/41, A.1 <-> 25,
                // B.1 <-> 10/11, A.2 <-> 3-6, 12, 13).
                'totals'              => [
                    'a4_a5' => ['base21' => round($obrat23, 2), 'vat21' => round($obratDan23, 2), 'base12' => round($obrat5, 2), 'vat12' => round($obratDan5, 2)],
                    'b2_b3' => ['base21' => round($pln23, 2), 'vat21' => round($plnDan23, 2), 'base12' => round($pln5, 2), 'vat12' => round($plnDan5, 2)],
                    'a1'    => ['base' => round($plnRezPren, 2)],
                    'b1'    => ['base21' => round($rezPren23, 2), 'vat21' => round($rezPrenDan23, 2), 'base12' => round($rezPren5, 2), 'vat12' => round($rezPrenDan5, 2)],
                    'a2'    => ['base' => round($celkA2, 2), 'vat' => round($celkA2Vat, 2)],
                ],
            ],
            'warnings' => $warnings,
            'missing_rates' => $missingRates,
        ];
    }

    /**
     * The quick answer to a call of the tax office: header and payer only — rows in A, B or C would make the filing
     * ineffective. The summary has the shape of a full KH (zero counts and totals), so its consumers need no special case.
     *
     * @param array<string,mixed> $supplier
     * @param list<string> $warnings
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>, missing_rates: list<mixed>}
     */
    private function buildQuickAnswer(array $supplier, int $year, int $month, string $period, ?int $quarter, KhFiling $filing, string $periodLabel, string $deadline, array $warnings): array
    {
        [$dom] = $this->startDocument($supplier, $year, $month, $period, $quarter, $filing);
        $zeroBase = ['base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => [
                'period'              => $periodLabel,
                'a1_count' => 0, 'a2_count' => 0, 'a4_count' => 0, 'a5_count_aggregated' => 0,
                'b1_count' => 0, 'b2_count' => 0, 'b3_count_aggregated' => 0,
                'form'                => $filing->form,
                'archive_form'        => $filing->archiveForm(),
                'call'                => ['reference' => $filing->callReference, 'answer' => $filing->callAnswer],
                'submission_deadline' => $deadline,
                'totals'              => ['a4_a5' => $zeroBase, 'b2_b3' => $zeroBase, 'a1' => ['base' => 0.0], 'b1' => $zeroBase, 'a2' => ['base' => 0.0, 'vat' => 0.0]],
            ],
            'warnings' => array_merge($warnings, [
                $filing->callAnswer === 'P'
                    ? 'Rychlá odpověď na výzvu: potvrzujete správnost naposledy podaného kontrolního hlášení, podání neobsahuje žádné řádky oddílů A, B ani C.'
                    : 'Rychlá odpověď na výzvu: sdělujete, že vám za toto období nevznikla povinnost podat kontrolní hlášení (nulové KH), podání neobsahuje žádné řádky oddílů A, B ani C.',
                'Lhůta na odpověď je 17 dnů ode dne dodání výzvy do datové schránky, jinak 5 pracovních dnů od oznámení výzvy (§ 101g odst. 3 ZDPH).',
            ]),
            'missing_rates' => [],
        ];
    }

    /**
     * The document up to and including VetaD and VetaP (identical for the full KH and the quick answer to a call).
     *
     * @param array<string,mixed> $supplier
     * @return array{0: \DOMDocument, 1: \DOMElement} the document and its DPHKH1 element
     */
    private function startDocument(array $supplier, int $year, int $month, string $period, ?int $quarter, KhFiling $filing): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        $dphkh = $dom->createElement('DPHKH1');
        $dphkh->setAttribute('verzePis', '03.01');
        $pisemnost->appendChild($dphkh);

        // VetaD — identifikační údaje (mesic pro měsíční, ctvrt pro kvartální)
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('dokument', 'KH1');
        $vetaD->setAttribute('k_uladis', 'DPH');
        if ($period === 'quarterly' && $quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        $vetaD->setAttribute('rok', (string) $year);
        $vetaD->setAttribute('d_poddp', date('d.m.Y')); // datum podání (dnes)
        // B řádné / O opravné (§ 101f/1) / N následné (§ 101f/2) / E následné-opravné.
        $vetaD->setAttribute('khdph_forma', $filing->form);
        // Date the reasons for a subsequent KH were found — EPO requires it for N/E unless the reference number of a
        // call is given instead. Optional for O.
        if ($filing->dZjist !== null) {
            $vetaD->setAttribute('d_zjist', $filing->dZjist);
        }
        // The call the filing answers: the change (N/E) or the quick answer carry its reference number.
        if ($filing->callReference !== null) {
            $vetaD->setAttribute('c_jed_vyzvy', $filing->callReference);
        }
        if ($filing->callAnswer !== null) {
            $vetaD->setAttribute('vyzva_odp', $filing->callAnswer);
        }
        $dphkh->appendChild($vetaD);

        // VetaP — identification of the payer (shared with DPHDP3 via EpoSupplierBlockBuilder)
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphkh->appendChild($vetaP);

        return [$dom, $dphkh];
    }

    /**
     * KH section a ledger row is projected to: 'A.1' / 'A.2' / 'B.1' (the explicit classification),
     * 'DOM' for the domestic taxable supplies (A.4/A.5 sales, B.2/B.3 purchases) and null for a row
     * that is not in the KH at all.
     *
     * A sale is always a candidate, a purchase only with the right to deduct (dphdp3_line != NULL):
     * a purchase without it (code 42) is in no KH and the DPHDP3 skips it too. Reverse-charge rows
     * without a KH section (import from a 3rd country, supplies to the EU) never go to DOM.
     *
     * @param array<string,mixed> $row a {@see VatLedgerService::rows()} row
     */
    public static function routeRow(array $row): ?string
    {
        $section = $row['kh_section'] ?? null;
        if (in_array($section, ['A.1', 'A.2', 'B.1'], true)) {
            return $section;
        }
        $eligible = ($row['source'] ?? '') === 'sale' || ($row['dphdp3_line'] ?? null) !== null;
        return $eligible && empty($row['is_reverse_charge']) ? 'DOM' : null;
    }

    /**
     * Projekce kanonických řádků (VatLedgerService) na sekce KH. Nahrazuje 5 původních
     * SQL kolektorů + loadInvoiceVatBreakdown. Per faktura agregujeme základ/daň po
     * sazbách + příznaky sekce, pak směrujeme:
     *   - A.1 = vystavený reverse charge
     *   - A.2 = pořízení zboží z JČS (kh_section A.2; samovyměřená daň ze služby)
     *   - A.4/A.5 = vystavená tuzemská zdanitelná (nad/do limitu + DIČ)
     *   - B.1 = přijatý tuzemský RC (ne A.2)
     *   - B.2/B.3 = přijatá tuzemská zdanitelná
     * Práh `abs()`, plnění bez DIČ → sumace, bez zdanitelného základu → vyloučeno.
     *
     * @return array{a1:list<array<string,mixed>>, a2:list<array<string,mixed>>,
     *   a4:list<array<string,mixed>>, a5:array<string,mixed>, b1:list<array<string,mixed>>,
     *   b2:list<array<string,mixed>>, b3:array<string,mixed>}
     */
    private function collectSections(int $supplierId, string $start, string $end): array
    {
        // Konstanty pro rok OBDOBÍ výkazu (ne aktuální) — zpětně generované KH za
        // staré období musí použít tehdejší limit/sazby.
        $periodYear = (int) substr($start, 0, 4);
        $itemThreshold = $this->taxConstants->khItemThreshold($periodYear);
        $bucket = $this->taxConstants->vatBucketThreshold($periodYear);

        // Agregace kanonických řádků per (zdroj, faktura).
        $ledgerRows = $this->ledger->rows($supplierId, $start, $end, includeDrafts: false);
        // Daňová pojistka (issue #238): non-CZK doklad bez kurzu by se do KH dostal
        // s náhradním kurzem 1.0 (cizí měna jako CZK). NEházíme chybu — vrátíme doklady
        // bez kurzu, akce je při stažení doplní z ČNB (náhled jen varuje).
        $missingRates = VatLedgerService::missingExchangeRateRows($ledgerRows);
        // Doklady s jen ODHADNUTÝM kódem (zahraniční RC bez klasifikace) — per doklad
        // jednou, pro adresné warningy v preview (zboží vs. služba nelze z dat poznat).
        $estimatedCodes = [];
        foreach ($ledgerRows as $r) {
            if (!empty($r['code_estimated']) && $r['code'] !== null) {
                $doc = (string) ($r['vendor_invoice_number'] ?? $r['doc_number'] ?? ('#' . $r['invoice_id']));
                $estimatedCodes[$r['source'] . ':' . $r['invoice_id']] = [
                    'doc'     => $doc,
                    'vendor'  => (string) ($r['counterparty_name'] ?? ''),
                    'country' => (string) ($r['country_iso2'] ?? ''),
                    'code'    => (string) $r['code'],
                ];
            }
        }
        $estimatedCodes = array_values($estimatedCodes);
        $inv = [];
        foreach ($ledgerRows as $r) {
            $key = $r['source'] . ':' . $r['invoice_id'];
            if (!isset($inv[$key])) {
                $inv[$key] = [
                    'source'                => $r['source'],
                    'varsymbol'             => $r['doc_number'],
                    'vendor_invoice_number' => $r['vendor_invoice_number'],
                    'tax_date'              => $r['tax_date'],
                    'dic'                   => self::cleanDic($r['counterparty_dic']),
                    'dic_raw'               => $r['counterparty_dic'], // syrové VAT ID pro A.2 (EU alfanum.)
                    'country_iso2'          => $r['country_iso2'],
                    'country_is_eu'         => $r['country_is_eu'],
                    'total_czk'             => (float) $r['total_with_vat_czk'],
                    // KH kód předmětu plnění (RC) z klasifikace. Doklad může nést VÍC režimů
                    // § 92 najednou (stavební práce + odpad), a XSD chce větu A.1/B.1 per kód —
                    // proto se základ i daň sčítají PER KÓD, ne do jednoho čísla za doklad.
                    // Dřív tu byl skalár, který přepsala poslední neprázdná hodnota, takže
                    // celý doklad odešel pod jedním (často cizím) kódem.
                    'a1_by_code'            => [],
                    'b1_by_code'            => [],
                    'is_rc' => false, 'has_a1' => false, 'has_a2' => false, 'has_b1' => false, 'is_pomer' => false,
                    'dom_base21' => 0.0, 'dom_vat21' => 0.0, 'dom_base12' => 0.0, 'dom_vat12' => 0.0,
                    'a2_base21' => 0.0, 'a2_vat21' => 0.0, 'a2_base12' => 0.0, 'a2_vat12' => 0.0,
                    'kh_regime_codes' => [], 'kh_bad_debt_codes' => [],
                ];
            }
            $g = &$inv[$key];
            if ($r['is_reverse_charge']) $g['is_rc'] = true;
            if ($r['kh_section'] === 'A.1') $g['has_a1'] = true;
            if ($r['kh_section'] === 'A.2') $g['has_a2'] = true;
            if ($r['kh_section'] === 'B.1') $g['has_b1'] = true;
            if (!empty($r['vat_deduction_partial'])) $g['is_pomer'] = true;
            $rowKodPredPl = (string) ($r['kod_pred_pl'] ?? '');
            $base = (float) $r['base_czk'];
            $vat  = (float) $r['vat_czk'];
            $is21 = $r['vat_rate'] >= $bucket;
            // Rozřazení základu/daně do KH kbelíků PODLE SEKCE klasifikace — každá položka
            // přispěje jen do JEDNÉ sekce. Tím se mixed faktura (např. §92 RC řádek +
            // běžný 21% řádek) rozdělí správně (RC část do A.1/B.1, zdanitelná do A.4/B.2),
            // místo aby celý součet spadl do jedné sekce (issue — audit KH/DPH 2026-07).
            switch (self::routeRow($r)) {
                case 'A.1': // domestic § 92 supplier — base only (VetaA1 has no rate columns)
                    $g['a1_by_code'][$rowKodPredPl] = ($g['a1_by_code'][$rowKodPredPl] ?? 0.0) + $base;
                    break;
                case 'A.2': // cross-border self-assessed (§ 24 services, § 25 acquisition of goods from another member state)
                    if ($is21) { $g['a2_base21'] += $base; $g['a2_vat21'] += $vat; }
                    elseif ($r['vat_rate'] > 0) { $g['a2_base12'] += $base; $g['a2_vat12'] += $vat; }
                    break;
                case 'B.1': // domestic § 92 recipient — self-assessed tax (vat from rcSelfAssess)
                    $b1sums = $g['b1_by_code'][$rowKodPredPl]
                        ?? ['base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
                    if ($is21) { $b1sums['base21'] += $base; $b1sums['vat21'] += $vat; }
                    elseif ($r['vat_rate'] > 0) { $b1sums['base12'] += $base; $b1sums['vat12'] += $vat; }
                    $g['b1_by_code'][$rowKodPredPl] = $b1sums;
                    break;
                case 'DOM':
                    // Domestic taxable supplies (A.4/A.5, B.2/B.3). Reverse-charge rows without a KH
                    // section (import from a 3rd country, code 25; supplies to the EU, codes 20/22)
                    // must NOT get here — they belong to DPHDP3/SHV only (see routeRow()).
                    $g['kh_regime_codes'][(string) ($r['kh_regime_code'] ?? '0')] = true;
                    $g['kh_bad_debt_codes'][(string) ($r['kh_bad_debt'] ?? 'N')] = true;
                    if ($is21) { $g['dom_base21'] += $base; $g['dom_vat21'] += $vat; }
                    elseif ($r['vat_rate'] > 0) { $g['dom_base12'] += $base; $g['dom_vat12'] += $vat; }
                    break;
            }
            unset($g);
        }

        $a1 = []; $a2 = []; $a4 = []; $b1 = []; $b2 = [];
        $a5 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        $b3 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];

        foreach ($inv as $g) {
            $hasDic = $g['dic'] !== '';
            // § 101e: „nad 10 000 Kč" = OSTŘE více → přesně 10 000 patří do sumace
            // A.5/B.3, ne do jednotlivé A.4/B.2. Proto '>' (ne '>=').
            $overLimit = abs($g['total_czk']) > $itemThreshold;
            // Tuzemská zdanitelná část faktury (může být 0 u čistě RC/osvobozeného dokladu).
            // Faktura může přispět SOUČASNĚ do RC sekce (A.1/B.1/A.2) i do A.4/A.5/B.2/B.3
            // (mixed doklad) — proto žádný `continue`, sekce se vyhodnocují nezávisle.
            $domZero = abs($g['dom_base21']) < 0.005 && abs($g['dom_base12']) < 0.005;

            if ($g['source'] === 'sale') {
                // A.1 — tuzemský režim přenesení (§ 92a–92e, kód 25s). Jen položky sekce A.1.
                if ($g['has_a1']) {
                    // Věta per kód předmětu plnění (XSD) — doklad se dvěma režimy § 92
                    // (stavební práce + odpad) dřív odešel jako jedna věta pod jedním kódem.
                    foreach ($g['a1_by_code'] as $code => $codeBase) {
                        // PHP číselný klíč pole automaticky přetypuje na int — zpět na string.
                        $code = (string) $code;
                        if (abs($codeBase) < 0.005) {
                            continue;
                        }
                        $a1[] = ['counterparty_dic' => $g['dic_raw'], 'vendor_invoice_number' => $g['varsymbol'],
                                 'tax_date' => $g['tax_date'], 'base' => $codeBase,
                                 'kod_pred_pl' => $code !== '' ? $code : null];
                    }
                }
                // A.4/A.5 — tuzemská zdanitelná část (RC/osvobozené/EU dodání/vývoz nepřispěly).
                if (!$domZero) {
                    $regimeCodes = array_keys($g['kh_regime_codes']);
                    $badDebtCodes = array_keys($g['kh_bad_debt_codes']);
                    $row = ['varsymbol' => $g['varsymbol'], 'tax_date' => $g['tax_date'], 'counterparty_dic' => $g['dic'],
                            'base21' => $g['dom_base21'], 'vat21' => $g['dom_vat21'],
                            'base12' => $g['dom_base12'], 'vat12' => $g['dom_vat12'],
                            'kh_regime_code' => count($regimeCodes) === 1 ? $regimeCodes[0] : null,
                            'kh_bad_debt' => count($badDebtCodes) === 1 ? $badDebtCodes[0] : null,
                            'kh_attribute_conflict' => count($regimeCodes) > 1 || count($badDebtCodes) > 1];
                    if (($overLimit || $row['kh_bad_debt'] === 'P') && $hasDic) {
                        $a4[] = $row;
                    } else {
                        $a5['count']++; $a5['base21'] += $g['dom_base21']; $a5['vat21'] += $g['dom_vat21'];
                        $a5['base12'] += $g['dom_base12']; $a5['vat12'] += $g['dom_vat12'];
                    }
                }
            } else { // purchase
                // A.2 — přijatá plnění od OSOBY NEUSAZENÉ V TUZEMSKU, kde daň přiznává
                // příjemce (§ 108): pořízení zboží z JČS (§ 25), přijetí služby (§ 24 /
                // § 9 odst. 1), zboží s instalací. Rozhoduje režim plnění, ne sídlo
                // dodavatele — samovyměření od dodavatele ze 3. země sem patří stejně
                // jako od dodavatele z EU, jen bez identifikace ({@see a2Identification()},
                // issue #53). Historie: migrace 0129 to z A.2 vyřadila, 0130 vrátila zpět;
                // pravidlo proto drží KÓD, ne řádek v číselníku — seed `vat_classifications`
                // u kódu 24 drží `kh_section = 'A.2'` a novou přepínací migraci nepřidávat.
                if ($g['has_a2']) {
                    $a2[] = ['vendor_invoice_number' => $g['vendor_invoice_number'], 'tax_date' => $g['tax_date'],
                             'counterparty_dic' => $g['dic_raw'], 'country_iso2' => $g['country_iso2'],
                             'country_is_eu' => $g['country_is_eu'],
                             'base21' => $g['a2_base21'], 'vat21' => $g['a2_vat21'],
                             'base12' => $g['a2_base12'], 'vat12' => $g['a2_vat12']];
                }
                // B.1 — tuzemský režim přenesení (§ 92a–92e) příjemce. Per-sazbové agregáty
                // nesou i samovyměřenou daň (vat z rcSelfAssess) — B.1 ji vykazuje, ne jen základ.
                if ($g['has_b1']) {
                    // Věta per kód předmětu plnění (XSD) — viz A.1 výš.
                    foreach ($g['b1_by_code'] as $code => $sums) {
                        // PHP číselný klíč pole automaticky přetypuje na int — zpět na string.
                        $code = (string) $code;
                        $b1[] = ['counterparty_dic' => $g['dic_raw'], 'vendor_invoice_number' => $g['vendor_invoice_number'],
                                 'tax_date' => $g['tax_date'], 'base' => $sums['base21'] + $sums['base12'],
                                 'base21' => $sums['base21'], 'vat21' => $sums['vat21'],
                                 'base12' => $sums['base12'], 'vat12' => $sums['vat12'],
                                 'kod_pred_pl' => $code !== '' ? $code : null];
                    }
                }
                // B.2/B.3 — tuzemská přijatá zdanitelná (s nárokem). RC bez KH sekce (dovoz
                // ze 3. země kód 25) a plnění bez nároku (kód 42) do dom_* nepřispěly.
                if (!$domZero) {
                    $badDebtCodes = array_keys($g['kh_bad_debt_codes']);
                    $row = ['vendor_invoice_number' => $g['vendor_invoice_number'], 'tax_date' => $g['tax_date'],
                            'counterparty_dic' => $g['dic'], 'base21' => $g['dom_base21'], 'vat21' => $g['dom_vat21'],
                            'base12' => $g['dom_base12'], 'vat12' => $g['dom_vat12'], 'is_pomer' => $g['is_pomer'],
                            'kh_bad_debt' => count($badDebtCodes) === 1 ? $badDebtCodes[0] : null,
                            'kh_attribute_conflict' => count($badDebtCodes) > 1];
                    if (($overLimit || $row['kh_bad_debt'] === 'P') && $hasDic) {
                        $b2[] = $row;
                    } else {
                        $b3['count']++; $b3['base21'] += $g['dom_base21']; $b3['vat21'] += $g['dom_vat21'];
                        $b3['base12'] += $g['dom_base12']; $b3['vat12'] += $g['dom_vat12'];
                    }
                }
            }
        }

        return ['a1' => $a1, 'a2' => $a2, 'a4' => $a4, 'a5' => $a5, 'b1' => $b1, 'b2' => $b2, 'b3' => $b3,
                'missing_rates' => $missingRates, 'estimated_codes' => $estimatedCodes];
    }

    /** @return list<string> warnings */
    private function validateSupplier(array $s, string $period, VatStatusHistory $history, string $start, string $end): array
    {
        $w = [];
        if (!$history->hasPayerTimeBetween($start, $end)) {
            // Identifikovaná osoba (§ 6g–6l, issue #94) KH nepodává NIKDY (§ 101c
            // jen plátci) — přeshraniční povinnosti pokrývá DPHDP3 typ I + SHV.
            $w[] = $history->hasStatusBetween($start, $end, [VatStatus::IDENTIFIED])
                ? 'Identifikovaná osoba kontrolní hlášení nepodává (§ 101c — jen plátci DPH). Přeshraniční plnění patří do přiznání DPH (typ I) a souhrnného hlášení.'
                : 'V tomto období nebyl dodavatel plátcem DPH — KH nemusí být relevantní.';
        } elseif ($history->hasStatusBetween($start, $end, [VatStatus::NON_PAYER, VatStatus::IDENTIFIED])) {
            $w[] = 'Dodavatel byl plátcem DPH jen část období — do hlášení se započítávají jen doklady s DUZP v době plátcovství.';
        }
        if ($period === 'quarterly' && ($s['taxpayer_type'] ?? '') === 'po') {
            $w[] = 'Právnické osoby podávají kontrolní hlášení VŽDY měsíčně (§ 101e odst. 1 zákona 235/2004 Sb.). Kvartální podání je povoleno pouze fyzickým osobám.';
        }
        if (empty($s['financial_office_code'])) $w[] = 'Chybí kód finančního úřadu.';
        if (empty($s['dic'])) $w[] = 'Chybí DIČ.';
        return $w;
    }

    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic,
                    s.taxpayer_type, s.vat_period, s.financial_office_code,
                    s.workplace_code, s.data_box_id,
                    s.email, s.phone, s.cz_nace_code,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni,
                    s.sest_jmeno, s.sest_prijmeni, s.sest_telefon, s.sest_email, s.sest_funkce
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        return $row;
    }

    private function loadAppVersion(): ?string
    {
        $verFile = __DIR__ . '/../../../../VERSION';
        return is_file($verFile) ? trim((string) file_get_contents($verFile)) : null;
    }

    /**
     * KH „Kód předmětu plnění" (VetaA1/VetaB1.kod_pred_pl) z klasifikace. Není-li na
     * klasifikaci vyplněn, spadne na '5' (odpad/šrot §92c) + jednorázový warning —
     * nejčastější tuzemský RC jsou ale stavební/montážní práce (§92e, kód '4').
     *
     * @param list<string> $warnings by-ref
     */
    private function resolveKodPredPl(?string $value, array &$warnings): string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        $w = 'U některých plnění v přenesené povinnosti není na klasifikaci vyplněn kód '
           . 'předmětu plnění — použit default „5" (odpad/šrot §92c). Ověřte správný kód '
           . '(stavební/montážní práce = „4").';
        if (!in_array($w, $warnings, true)) {
            $warnings[] = $w;
        }
        return '5';
    }

    /**
     * Tuzemské RC vyžaduje číselnou kmenovou část DIČ. Neplatný řádek nesmí zůstat
     * v rekapitulaci VetaC, když jej nelze emitovat do A.1/B.1.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function filterReverseChargeRowsWithDic(array $rows, string $section, array &$warnings): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($section, &$warnings): bool {
            if (self::isValidCzechDic($row['counterparty_dic'] ?? '')) {
                return true;
            }
            $number = (string) ($row['vendor_invoice_number'] ?? 'bez čísla');
            $warnings[] = "Doklad {$number} nelze uvést v KH {$section}: chybí platné české DIČ protistrany. Doplňte DIČ před podáním.";
            return false;
        }));
    }

    /**
     * Doplní řádkům A.2 identifikaci dodavatele (`k_stat` + `vatid_dod`).
     * Řádek se NEVYŘAZUJE ani tehdy, když identifikaci sestavit nejde — prázdné
     * „Identifikace dodavatele“ je podle metodiky GFŘ správný stav, ne vada
     * ({@see a2Identification()} a komentář u emisního bloku VetaA2).
     *
     * Varování dostane jen dodavatel SE SÍDLEM V EU bez použitelného VAT ID: tam jde
     * skoro vždy o nekompletní údaj na kontaktu, který lze před podáním doplnit. U 3. země
     * je prázdná identifikace běžný stav a uživatel nemá co doplnit — varování na každý
     * zahraniční SaaS doklad by bylo jen šum.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $warnings by-ref
     * @return list<array<string,mixed>>
     */
    private function resolveA2Identification(array $rows, array &$warnings): array
    {
        $out = [];
        foreach ($rows as $row) {
            $ident = self::a2Identification(
                $row['country_iso2'] ?? null,
                !empty($row['country_is_eu']),
                $row['counterparty_dic'] ?? null,
            );
            if ($ident['vatid_dod'] === '' && !empty($row['country_is_eu'])) {
                $number = (string) ($row['vendor_invoice_number'] ?? '') ?: 'bez čísla';
                $warnings[] = "Doklad {$number} půjde do KH A.2 bez identifikace dodavatele: "
                    . 'u dodavatele z EU chybí DIČ registrace k DPH (VAT ID). Doplňte ho na '
                    . 'kontaktu před podáním; EPO jinak vypíše propustné chyby č. 58 a č. 60.';
            }
            $out[] = $row + $ident;
        }
        return $out;
    }

    /**
     * Identifikace dodavatele pro KH oddíl A.2 (VetaA2.k_stat + vatid_dod), s prázdnými
     * řetězci tam, kde ji sestavit nejde.
     *
     * NEVRACÍ `null` a není to filtr: plnění od dodavatele bez EU DIČ do A.2 PATŘÍ —
     * dokumentace `vatid_dod` v dphkh1.xsd i metodická informace GFŘ k vyplnění KH
     * shodně uvádějí, že včetně „identifikace zahraniční osoby povinné k dani“ pole
     * „Identifikace dodavatele“ zůstává prázdné, a EPO na to reaguje jen PROPUSTNýMI
     * chybami č. 58 / č. 60 (issue #53).
     *
     * Rozhoduje EXISTENCE DIČ registrace k DPH v členském státě, ne sídlo dodavatele:
     * osoba se sídlem ve 3. zemi může být registrovaná k DPH v EU (a pak identifikaci má),
     * neplátce se sídlem v EU registraci nemá (a pak ji nemá). U sídla mimo EU se proto
     * kód státu bere z prefixu samotného VAT ID, ne ze země kontaktu.
     *
     * Public static: stejné pravidlo potřebuje i Kniha DPH (sloupec „KH“), ať se výkaz
     * a Kniha nerozejdou.
     *
     * @return array{k_stat: string, vatid_dod: string} prázdné řetězce = bez identifikace
     */
    public static function a2Identification(?string $countryIso2, bool $countryIsEu, ?string $vatId): array
    {
        $none = ['k_stat' => '', 'vatid_dod' => ''];
        // Prefix se stráhá podle TÉHOŽ hodnoty, která jde do `k_stat`, jinak by v čísle
        // zůstal cizi prefix (Řecko: ISO GR vs. DPH kód EL).
        $seat = $countryIsEu ? self::khCountryCode($countryIso2) : '';
        if ($seat !== '') {
            $clean = self::cleanEuVatId($vatId, $countryIso2);
            return $clean === '' ? $none : ['k_stat' => $seat, 'vatid_dod' => $clean];
        }
        // Sídlo mimo EU (nebo neznámé): jediná použitelná identifikace je registrace
        // v členském státě, kterou nese prefix samotného VAT ID.
        $kStat = self::euVatIdPrefix($vatId);
        if ($kStat === '') {
            return $none;
        }
        $clean = self::cleanEuVatId($vatId, $kStat);
        return $clean === '' ? $none : ['k_stat' => $kStat, 'vatid_dod' => $clean];
    }

    /**
     * Kód členského státu z prefixu VAT ID, nebo '' když prefix není kód členského státu.
     * Slouží dodavateli se sídlem mimo EU, který je přesto registrovaný k DPH v některém
     * členském státě — `k_stat` je „kód státu, který přidělil DIČ registrace k DPH“,
     * takže se řídí registrací, ne sídlem.
     */
    private static function euVatIdPrefix(?string $vatId): string
    {
        $s = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $vatId))) ?? '';
        $prefix = substr($s, 0, 2);
        return strlen($s) > 2 && in_array($prefix, self::KH_MEMBER_STATE_CODES, true) ? $prefix : '';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function filterKhAttributeConflicts(array $rows, string $section, array &$warnings): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($section, &$warnings): bool {
            if (empty($row['kh_attribute_conflict'])) {
                return true;
            }
            $number = (string) ($row['varsymbol'] ?? $row['vendor_invoice_number'] ?? 'bez čísla');
            $warnings[] = "Doklad {$number} nelze uvést v KH {$section}: položky mají rozdílný režim plnění nebo příznak opravy nedobytné pohledávky. Sjednoťte klasifikaci před podáním.";
            return false;
        }));
    }

    private static function isValidCzechDic(?string $dic): bool
    {
        $value = strtoupper(trim((string) $dic));
        return preg_match('/^(?:CZ)?[0-9]{1,10}$/', $value) === 1;
    }

    /** DIČ pro KH XML — odstraní CZ prefix, jen číslice. */
    /** Public static: stejnou normalizaci DIČ používá DphBookBuilder pro efektivní KH sekci. */
    public static function cleanDic(?string $dic): string
    {
        if (!$dic) return '';
        // CZ12345678 → 12345678. Pattern v XSD je [0-9]{1,10}, takže strip vše ne-digit po prefixu.
        $clean = preg_replace('/^CZ/i', '', strtoupper(trim($dic))) ?? '';
        return preg_replace('/[^0-9]/', '', $clean) ?? '';
    }

    /**
     * VAT ID dodavatele z jiného členského státu pro VetaA2.vatid_dod (KH oddíl A.2).
     *
     * Na rozdíl od českého DIČ (jen číslice, viz cleanDic) je EU VAT ID alfanumerické a
     * u řady států obsahuje písmena (IE 1234567X, AT U12345678, NL 123456789B01, …).
     * XSD vyžaduje formát BEZ kódu členského státu — odstraníme prefix země, mezery a
     * oddělovače, zachováme alfanumerickou kmenovou část.
     *
     * Strháváme JEN prefix odpovídající kódu země — buď ISO 3166 (country_iso2), nebo
     * VIES/DPH kód (u Řecka se liší: ISO "GR" vs VIES "EL", takže akceptujeme obojí).
     * NEstrháváme libovolná 2 písmena — některá DIČ mají alfanumerickou vnitrostátní
     * část (FR: „FRAB123456789" → „AB123456789", ne „123456789"). Issue #238.
     */
    public static function cleanEuVatId(?string $vatId, ?string $countryIso2): string
    {
        if (!$vatId) return '';
        $s = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($vatId))) ?? '';
        $iso  = strtoupper(trim((string) $countryIso2)); // ISO 3166 (GR)
        $vies = self::khCountryCode($countryIso2);        // VIES/DPH kód (EL pro Řecko)
        foreach ([$vies, $iso] as $prefix) {
            if ($prefix !== '' && str_starts_with($s, $prefix)) {
                return substr($s, strlen($prefix));
            }
        }
        return $s;
    }

    /**
     * Kód státu pro KH (VetaA2.k_stat / prefix VAT ID). Vychází z ISO 3166-1 alpha-2,
     * ale s odchylkami EU registru DPH: Řecko má ISO "GR", ale DPH kód "EL".
     */
    public static function khCountryCode(?string $iso2): string
    {
        $c = strtoupper(trim((string) $iso2));
        return $c === 'GR' ? 'EL' : $c;
    }

    /** Date pro KH XML — convert YYYY-MM-DD na DD.MM.YYYY (EPO datum format). */
    private function formatDate(?string $isoDate): string
    {
        if (!$isoDate) return '';
        try {
            return (new \DateTimeImmutable($isoDate))->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
