<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Tax\FiledReturnChain;
use MyInvoice\Service\Tax\TaxFilingException;
use MyInvoice\Service\Vat\RegistrationDeductionService;
use MyInvoice\Service\Vat\VatStatus;
use MyInvoice\Service\Vat\VatStatusHistory;
use MyInvoice\Service\Vat\VatStatusService;
use Psr\Clock\ClockInterface;

/**
 * Builder XML pro DPH přiznání (DPHDP3) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní
 *    nebo daňovým poradcem. Aplikace nezaručuje regulatorní správnost.
 *
 * Schema: https://adisspr.mfcr.cz/dpr/adis/idpr_pub/dpr_info/schemas.faces
 */
final class DphPriznaniBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatClassificationMapper $mapper,
        private readonly VatStatusService $vatStatus,
        private readonly TaxSubmissionRepository $submissions,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Builds the XML of the DPH return of a month / quarter.
     *
     * @param string $period 'monthly' (default) or 'quarterly' (sums the whole quarter)
     * @param DphFiling|null $filing the filing form (B regular, O corrective, D supplementary) and its dates / reasons; regular by default
     * @return array{xml: string, summary: array<string, mixed>, warnings: list<string>}
     * @throws TaxFilingException form D cannot be built (no filed return for the period, nothing changed since)
     */
    public function build(int $supplierId, int $year, int $month, ?string $period = null, ?DphFiling $filing = null): array
    {
        $filing ??= new DphFiling();
        $form = $filing->form;
        $dZjist = $filing->dZjist;
        $supplier = $this->loadSupplier($supplierId);
        $period = self::normalizePeriod($period, $supplier['vat_period'] ?? null);
        // The VAT status comes from the dated history over the filing period (never the
        // live flag): the supplier may become a payer in the middle of it.
        // Identifikovaná osoba (§ 6g–6l ZDPH, issue #94): přiznání typu 'I' —
        // vyplňuje JEN samovyměření z přeshraničních přijatých plnění (ř. 3-6
        // pořízení zboží / služby z EU, ř. 12-13 služby ze 3. zemí), vždy měsíčně,
        // a jen za měsíce, kdy povinnost vznikla. BEZ nároku na odpočet (žádná
        // Veta4 vč. zrcadlového ř. 43), bez tuzemských výstupů (ř. 1/2) i oddílu C
        // (ř. 20-26 — služby do EU vykazuje jen v souhrnném hlášení).
        $history = $this->vatStatus->history($supplierId);
        [$periodStart, $periodEnd] = ReportPeriod::range($year, $month, $period);
        $hasPayerTime = $history->hasPayerTimeBetween($periodStart, $periodEnd);
        $isIdentified = !$hasPayerTime && $history->hasStatusBetween($periodStart, $periodEnd, [VatStatus::IDENTIFIED]);

        $warnings = [];
        if ($isIdentified) {
            $warnings[] = 'Přiznání identifikované osoby (typ I): jen samovyměření z přeshraničních plnění, bez nároku na odpočet. Podává se pouze za měsíce, kdy povinnost vznikla (do 25. dne následujícího měsíce).';
        } elseif (!$hasPayerTime) {
            $warnings[] = 'V tomto období nebyl dodavatel evidovaný jako plátce DPH — výkaz nemusí být relevantní.';
        } elseif ($history->hasStatusBetween($periodStart, $periodEnd, [VatStatus::NON_PAYER, VatStatus::IDENTIFIED])) {
            $warnings[] = 'Dodavatel byl plátcem DPH jen část období — do přiznání se započítávají jen doklady s DUZP v době plátcovství.';
        }
        if (empty($supplier['financial_office_code'])) {
            $warnings[] = 'Chybí kód finančního úřadu — XML nemusí projít validací EPO.';
        }
        if (empty($supplier['ic'])) {
            $warnings[] = 'Chybí IČO tenanta.';
        }
        if (empty($supplier['dic'])) {
            $warnings[] = 'Chybí DIČ tenanta.';
        }

        if ($isIdentified && $period !== 'monthly') {
            // IO má zdaňovací období VŽDY kalendářní měsíc (§ 99 se na ni nevztahuje,
            // povinnost vzniká per měsíc dle § 101 odst. 5).
            $period = 'monthly';
            $warnings[] = 'Identifikovaná osoba podává vždy za kalendářní měsíc — kvartální volba ignorována.';
            // The narrower month may be a plain non-payer month although the quarter had identified time.
            [$periodStart, $periodEnd] = ReportPeriod::range($year, $month, $period);
            $isIdentified = $history->hasStatusBetween($periodStart, $periodEnd, [VatStatus::IDENTIFIED]);
        }

        // The ledger rows plus the deduction on registration / its reduction (rows 45, 47) — the same projection the trend and the forecast use.
        ['lines' => $lines, 'deduction' => $deduction] = $this->mapper->dphLinesWithDeduction($supplierId, $year, $month, $period, $history);
        array_push($warnings, ...RegistrationDeductionService::missingItemsWarnings($deduction['events_without_items']));
        // #238: doklady v cizí měně bez kurzu — NEházíme chybu, vrátíme je v
        // `missing_rates` a akce je při stažení doplní z ČNB (náhled jen varuje).
        $missingRates = $this->mapper->missingRatesForPeriod($supplierId, $year, $month, $period);
        if ($missingRates !== []) {
            $warnings[] = 'Chybí kurz u dokladů v cizí měně: '
                . implode(', ', \MyInvoice\Service\Report\VatLedgerService::missingExchangeRateLabels($missingRates))
                . '. Při stažení XML se doplní z ČNB.';
        }
        $this->appendSalesDataWarnings($supplierId, $year, $month, $period, $history, $warnings);
        if ($isIdentified) {
            $lines = $this->filterLinesForIdentified($lines, $warnings);
        }
        $quarter = $period === 'quarterly' ? (int) ceil($month / 3) : null;

        // A supplementary return lists only the differences against the last known return of the period.
        $fullLines = $lines;
        $supplementary = null;
        if ($filing->isSupplementary()) {
            $supplementary = $this->supplementaryBase($supplierId, $year, $month, $period, $quarter, $lines);
            $lines = $supplementary['difference']['lines'];
            $warnings[] = 'Dodatečné přiznání obsahuje jen rozdíly proti poslednímu podanému přiznání za toto období (§ 141 odst. 5 daňového řádu).';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        // Root: <Pisemnost nazevSW="MyInvoice.cz" verzeSW="X.Y.Z">
        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        // <DPHDP3 verzePis="03.01">
        $dphdp3 = $dom->createElement('DPHDP3');
        $dphdp3->setAttribute('verzePis', '03.01');
        $pisemnost->appendChild($dphdp3);

        // ── VetaD: identifikační údaje (typ podání + perioda) ─────────
        // Per EPO XSD: typ_platce je v VetaD, typ_ds v VetaP.
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        if ($quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        // B = regular (default), O = corrective, D = supplementary (differences only, see FORMS).
        $vetaD->setAttribute('dapdph_forma', $form);
        // Date the reasons were found (§ 141 DŘ): required for D, passed through for O when the user fills it in.
        if ($dZjist !== null && $dZjist !== '') {
            $vetaD->setAttribute('d_zjist', $dZjist);
        }
        $vetaD->setAttribute('dokument', 'DP3');   // identifikace typu výkazu
        // P = plátce DPH (default), I = identifikovaná osoba (S = skupina, N = neplátce)
        $vetaD->setAttribute('typ_platce', $isIdentified ? 'I' : 'P');
        // CZ-NACE klasifikace (hlavní ekonomická činnost) — vyplňuje se
        // z `supplier.cz_nace_code`, kanonizuje se proti snapshotu číselníku
        // ČINNOSTI (viz EpoOkecCodebook); expirace/neznámý kód se hlásí
        // ve warnings completeness checku, build neblokuje.
        $okec = EpoSupplierBlockBuilder::normalizeOkec((string) ($supplier['cz_nace_code'] ?? ''));
        if ($okec !== null) {
            $vetaD->setAttribute('c_okec', $okec);
        }
        $vetaD->setAttribute('d_poddp', date('d.m.Y')); // datum podání (dnes)
        // trans: „existují údaje pro oddíl C?" — spočteme níže, až budou Vety sestavené.
        // Spočteme níže po sestavení Veta6 a setneme přes setAttribute.
        $dphdp3->appendChild($vetaD);

        // ── VetaP: identifikace daňového subjektu ─────────────────────
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphdp3->appendChild($vetaP);

        // ── Veta1 / Veta4: namapování řádků 1-13 (Veta1) a 40-47 (Veta4) ──
        //
        // Mapping odpovídá EPO XSD (api/xsd/dphdp3.xsd), reálně podanému
        // přiznání a oficiálnímu MFČR DPHDP3 formuláři (verze 03.01):
        //
        // Veta1 — DPH na výstupu (vč. samovyměřené u RC):
        //   ř.1  obrat23/dan23           = sale 21 %
        //   ř.2  obrat5/dan5             = sale 12 %
        //   ř.3  p_zb23/dan_pzb23        = pořízení zboží z JČS 21 % (EU)
        //   ř.4  p_zb5/dan_pzb5          = pořízení zboží z JČS 12 % (EU)
        //   ř.5  p_sl23_e/dan_psl23_e    = přijetí služby z EU 21 %
        //   ř.6  p_sl5_e/dan_psl5_e      = přijetí služby z EU 12 %
        //   ř.7  dov_zb23/dan_dzb23      = dovoz zboží 21 %
        //   ř.8  dov_zb5/dan_dzb5        = dovoz zboží 12 %
        //   ř.10 rez_pren23/dan_rpren23  = tuzemský reverse charge 21 %
        //   ř.11 rez_pren5/dan_rpren5    = tuzemský reverse charge 12 %
        //   ř.12 p_sl23_z/dan_psl23_z    = přijetí služby ze 3. země 21 %
        //   ř.13 p_sl5_z/dan_psl5_z      = přijetí služby ze 3. země 12 %
        //
        // Veta4 — Nárok na odpočet daně:
        //   ř.40 pln23/odp_tuz23_nar     = tuzemsko 21 %
        //   ř.41 pln5/odp_tuz5_nar       = tuzemsko 12 %
        //   ř.42 dov_cu/odp_cu_nar       = dovoz CÚ
        //   ř.43 nar_zdp23/od_zdp23      = odpočet ze samovyměřených plnění (ř. 3-13),
        //                                  sloupec „V plné výši", ZÁKLADNÍ sazba (21 %).
        //   ř.44 nar_zdp5/od_zdp5        = the same at the REDUCED rate (12 %) — a reverse-charge row
        //                                  with the 12 % rate is remapped here in VatLedgerService (S3).
        //   ř.45 —/odp_rez_nar           = corrections of the deduction (§ 75, § 77, § 77a, § 79 - § 79c, § 79e),
        //                                  column "V plné výši", no base column. Only the deduction on registration
        //                                  and its reduction on deregistration (§ 79, § 79a) are generated, from the
        //                                  agenda "Odpočet při registraci" (positive in the return of the period of
        //                                  registration, negative in the last return of the registration); a custom
        //                                  classification code aimed at row 45 is neither rendered nor counted.
        //   ř.46 odp_sum_nar             = total of the deduction (rows 40-45, "V plné výši")
        //   ř.47 nar_maj/—               = value of the acquired assets (supplementary data; the builder emits the base
        //                                  only, the tax is not entered). Includes the long-term assets claimed on registration.
        $veta1Attrs = [];
        $veta2Attrs = [];
        $veta3Attrs = [];
        $veta4Attrs = [];
        $veta5Attrs = [];

        foreach ($lines as $lineNum => $data) {
            $lineKey = (string) $lineNum;
            // A row outside Dphdp3Lines::MAP (builder ho neumí vykreslit — např. custom kód na ř.45)
            // se NEvykreslí ANI nezapočítá do rekapitulace. Dřív se tiše přičítal do
            // ř.46/62/63, aniž by byl v detailu → EPO hlásilo nekonzistenci (audit 2026-07).
            if (!isset(Dphdp3Lines::MAP[$lineKey])) {
                continue;
            }
            $m = Dphdp3Lines::MAP[$lineKey];
            $target = &${'veta' . $m['veta'] . 'Attrs'};
            if ($m['base'] !== null) {
                $target[$m['base']] = $this->formatAmount($data['base']);
            }
            if ($m['vat'] !== null) {
                $target[$m['vat']] = $this->formatAmount($data['vat']);
            }
            unset($target);
        }
        // Recapitulation (rows 62 / 63 / difference) only from the rows that CARRY a tax: base-only rows — section C
        // (ř.20-31), exempt supplies (ř.50), assets (ř.47) — are not part of it. Summed rounded to whole CZK, as entered.
        $totals = Dphdp3Lines::totals($lines);
        $totalDanZdanitelne = (float) $totals['output'];
        $totalDanOdpocitatelne = (float) $totals['input'];
        if (!empty($veta1Attrs)) {
            $veta1 = $dom->createElement('Veta1');
            foreach ($veta1Attrs as $k => $v) $veta1->setAttribute($k, $v);
            $dphdp3->appendChild($veta1);
        }
        // Veta2 — oddíl B (ř.20-26). XSD vyžaduje pořadí Veta1 → Veta2 → Veta3 → Veta4.
        if (!empty($veta2Attrs)) {
            $veta2 = $dom->createElement('Veta2');
            foreach ($veta2Attrs as $k => $v) $veta2->setAttribute($k, $v);
            $dphdp3->appendChild($veta2);
        }
        // Veta3 — oddíl C, doplňující údaje (ř.30/31 třístranný obchod prostřední osobou).
        if (!empty($veta3Attrs)) {
            $veta3 = $dom->createElement('Veta3');
            foreach ($veta3Attrs as $k => $v) $veta3->setAttribute($k, $v);
            $dphdp3->appendChild($veta3);
        }
        if (!empty($veta4Attrs)) {
            // ř.46 (odp_sum_nar) = součtový řádek 40-45 ve sloupci „V plné výši" =
            // celkový nárok na odpočet. Bez něj EPO portál hlásí propustnou chybu
            // („Odpočet daně celkem nevyplněn"). Rovná se ř.63 (odp_zocelk) — proto
            // používáme totéž $totalDanOdpocitatelne, které ř.47 (doplňující údaj
            // k majetku) správně nezapočítává.
            $veta4Attrs['odp_sum_nar'] = $this->formatAmount($totalDanOdpocitatelne);
            $veta4 = $dom->createElement('Veta4');
            foreach ($veta4Attrs as $k => $v) $veta4->setAttribute($k, $v);
            $dphdp3->appendChild($veta4);
        }
        // Veta5 — oddíl B, ř.50 (osvobozená plnění bez nároku na odpočet, §76 koeficient).
        // XSD pořadí Veta4 → Veta5 → Veta6; emit jen když je co vykázat (jako Veta2/3/4).
        if (!empty($veta5Attrs)) {
            $veta5 = $dom->createElement('Veta5');
            foreach ($veta5Attrs as $k => $v) $veta5->setAttribute($k, $v);
            $dphdp3->appendChild($veta5);
        }

        $vlastniDan = (float) $totals['tax'];
        // Own tax liability (row 64, `dano_da`) and excess deduction (row 65, `dano_no`) in whole CZK, written to
        // Veta6 and to the summary alike (the VAT payment card reads the summary). The summary keys keep their
        // historic names: `line_66_dano_no` IS row 65 of the official form; row 66 is `dano`, see below.
        // A supplementary return has neither: it reports the signed difference on row 66 instead.
        $isSupplementary = $filing->isSupplementary();
        $line64 = !$isSupplementary && $vlastniDan > 0 ? (int) round($vlastniDan) : 0;
        $line66 = !$isSupplementary && $vlastniDan < 0 ? (int) round(-$vlastniDan) : 0;

        // ── Veta6: recapitulation (XSD order Veta4 → Veta6 → VetaR) ───────
        // ř.62 dan_zocelk = output tax in total, ř.63 odp_zocelk = deduction in total,
        // ř.64 dano_da = own tax liability (only when output > deduction),
        // ř.65 dano_no = excess deduction (positive number, only when deduction > output),
        // ř.66 dano = (supplementary return only) signed difference against the last known tax = ř.62 - ř.63.
        // EPO recomputes the recapitulation on import, but a complete file is more correct.
        $veta6 = $dom->createElement('Veta6');
        $veta6->setAttribute('dan_zocelk', $this->formatAmount($totalDanZdanitelne));
        $veta6->setAttribute('odp_zocelk', $this->formatAmount($totalDanOdpocitatelne));
        if ($isSupplementary) {
            $veta6->setAttribute('dano', $this->formatAmount($vlastniDan));
        } elseif ($vlastniDan > 0) {
            $veta6->setAttribute('dano_da', (string) $line64);
        } elseif ($vlastniDan < 0) {
            $veta6->setAttribute('dano_no', (string) $line66);
        }
        $dphdp3->appendChild($veta6);

        // ── VetaR: text attachment lines. A regular return keeps the empty placeholder line; the reasons of a
        // supplementary return (§ 141 odst. 5 DŘ) are the lines of section D, at most 72 characters each.
        if ($isSupplementary) {
            foreach (DphSupplementaryDifference::reasonLines((string) $filing->reason) as $i => $text) {
                $vetaR = $dom->createElement('VetaR');
                $vetaR->setAttribute('poradi', (string) ($i + 1));
                $vetaR->setAttribute('kod_sekce', 'D');
                $vetaR->setAttribute('t_prilohy', $text);
                $dphdp3->appendChild($vetaR);
            }
        } else {
            $vetaR = $dom->createElement('VetaR');
            $vetaR->setAttribute('poradi', '1');
            $dphdp3->appendChild($vetaR);
        }

        // ⚠️ `trans` NENÍ znaménko daně, ale zaškrtávátko „Neexistují-li údaje
        // pro C. oddíl". EPO podle něj oddíl C buď vykreslí, nebo PŘEŠKRTNE —
        // a přeškrtnutý oddíl obsahovou kontrolou neprojde:
        //
        //   CHYBA 36 — JE ZAŠKRTNUTO, ŽE NEEXISTUJÍ ÚDAJE PRO C. ODDÍL,
        //   NESMÍ BÝT VYPLNĚNY ÚDAJE V ODDÍLE C.
        //
        // Dokud se odvozovalo jen ze znaménka vlastní daně, dostalo přiznání
        // složené výhradně ze samovyměření (daň na výstupu i zrcadlový odpočet
        // ve stejné výši, ř. 64 = 0) hodnotu `N` — a bylo bez ručního zásahu
        // nepodatelné, přestože Veta1 i Veta4 byly vyplněné správně. Totéž
        // hrozilo u nadměrného odpočtu.
        //
        // Proto: máme-li v oddílu C cokoliv vyplněné, `trans` je `A`. `N`
        // zůstává pro období, ve kterém se opravdu nic nestalo.
        $sectionCHasData = !empty($veta1Attrs) || !empty($veta2Attrs) || !empty($veta3Attrs)
            || !empty($veta4Attrs) || !empty($veta5Attrs);
        $vetaD->setAttribute('trans', ($vlastniDan > 0 || $sectionCHasData) ? 'A' : 'N');

        // Filing deadline: the 25th of the month after the period, moved to a working day (§ 33/4 DŘ).
        // A supplementary return is due by the end of the month after the discovery (§ 141/1 DŘ).
        $deadline = $isSupplementary
            ? DphSupplementaryDifference::deadline(ReportFormParams::epoDateToIso((string) $filing->dZjist))
            : ReportPeriod::filingDeadline($year, $month, $period);

        $summary = [
            'period'                  => sprintf('%04d-%02d', $year, $month),
            'period_type'             => $period,
            'form'                    => $form,
            'typ_platce'              => $isIdentified ? 'I' : 'P',
            'quarter'                 => $quarter,
            'lines'                   => $lines,
            'total_vat_output'        => round($totalDanZdanitelne, 2),
            'total_vat_input'         => round($totalDanOdpocitatelne, 2),
            'tax_due'                 => round($vlastniDan, 2),
            'line_64_dano_da'         => $line64,
            'line_66_dano_no'         => $line66,
            'is_excess_deduction'     => $vlastniDan < 0,
            'submission_deadline'     => $deadline,
            'supplier_vat_period'     => (string) ($supplier['vat_period'] ?? ''),
            // What the return asks the taxpayer to pay (or to be refunded), kept in the summary so that the payment
            // card does not have to re-derive it per form (row 64 / 65, row 66 of a supplementary return).
            'liability'               => self::liability($isSupplementary ? (int) $vlastniDan : $line64 - $line66),
        ];
        if ($supplementary !== null) {
            // `lines` above are the differences printed on the form; `full_lines` are the whole state of the period
            // this return brings the last known tax to - the next supplementary return is computed against them.
            $summary['full_lines'] = $fullLines;
            $summary['line_66_dano'] = (int) $vlastniDan;
            $summary['supplementary'] = [
                'd_zjist'   => (string) $filing->dZjist,
                'reason'    => $filing->reason,
                'based_on'  => $supplementary['based_on'],
                'known_tax' => $supplementary['known_tax'],
                'new_tax'   => $supplementary['new_tax'],
                'difference' => (int) $vlastniDan,
            ];
        }

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => $summary,
            'warnings' => $warnings,
            'missing_rates' => $missingRates,
        ];
    }

    /**
     * The last known return of the period and the differences of the current state against it (form D).
     *
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $current
     * @return array{based_on: array<string,mixed>, known_tax: int, new_tax: int, difference: array<string,mixed>}
     * @throws TaxFilingException there is no filed return of the period, or nothing changed since
     */
    private function supplementaryBase(int $supplierId, int $year, int $month, string $period, ?int $quarter, array $current): array
    {
        // Until the regular deadline of the period a return is corrected by a CORRECTIVE return (§ 138 DŘ); the
        // supplementary one (§ 141 DŘ) is for the time after it.
        $deadline = ReportPeriod::filingDeadline($year, $month, $period);
        $today = $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
        if ($today <= $deadline) {
            throw new TaxFilingException(
                sprintf(
                    'Lhůta pro podání přiznání za toto období ještě neuplynula (do %s). Do té doby se přiznání opravuje opravným přiznáním (forma Opravné, § 138 daňového řádu); dodatečné přiznání (§ 141) lze podat až po uplynutí lhůty.',
                    (new \DateTimeImmutable($deadline))->format('j. n. Y'),
                ),
                'corrective_return_required',
            );
        }
        $last = (new FiledReturnChain(
            $this->submissions->filedForPeriod($supplierId, TaxSubmissionRepository::FORM_DPH, $year, $quarter === null ? $month : null, $quarter),
        ))->last();
        if ($last === null) {
            throw new TaxFilingException(
                'Za toto období není podané žádné přiznání k DPH. Dodatečné přiznání lze podat jen k již podanému přiznání — nejdřív označte řádné přiznání jako podané v Archivu podání.',
                'no_filed_return',
            );
        }

        if (!DphSupplementaryDifference::hasKnownLines($last)) {
            // Returns archived before the summary carried the rows: a difference against nothing would restate the whole return.
            throw new TaxFilingException(
                'Poslední podané přiznání za toto období nemá v archivu uložené řádky, takže z něj nejde spočítat rozdíly. Dodatečné přiznání sestav ručně na portálu EPO.',
                'base_without_lines',
            );
        }
        $known = DphSupplementaryDifference::knownLines($last);
        $difference = DphSupplementaryDifference::compute($current, $known);
        if ($difference['lines'] === []) {
            throw new TaxFilingException(
                'Od posledního podaného přiznání za toto období se údaje nezměnily — není co dodatečně přiznat.',
                'nothing_changed',
            );
        }
        $knownTax = Dphdp3Lines::totals($known)['tax'];
        return [
            'based_on'   => [
                'submission_id'   => (int) $last['id'],
                'submission_form' => (string) $last['submission_form'],
                'filed_at'        => (string) $last['filed_at'],
            ],
            'known_tax'  => $knownTax,
            'new_tax'    => $knownTax + $difference['tax'],
            'difference' => $difference,
        ];
    }

    /** @return array{kind: 'payment'|'refund'|'none', amount: int} */
    private static function liability(int $signedTax): array
    {
        return ['kind' => $signedTax > 0 ? 'payment' : ($signedTax < 0 ? 'refund' : 'none'), 'amount' => abs($signedTax)];
    }

    /**
     * @param list<string> $warnings
     */
    private function appendSalesDataWarnings(int $supplierId, int $year, int $month, string $period, VatStatusHistory $history, array &$warnings): void
    {
        [$start, $end] = ReportPeriod::range($year, $month, $period);
        // Only documents with their DUZP in VAT-duty time count (a pre-registration document is in no return).
        $inVatTime = static fn (array $r): bool => $history->hasVatDutiesAt((string) $r['duzp']);

        // Čistě OSS dobropis nesnižuje tuzemskou daň na výstupu (jeho záporná DPH je zahraniční),
        // takže by § 42 varování jen mátlo. Vyžadujeme aspoň jeden ne-OSS řádek.
        $creditNoteOssFilter = $this->db->hasColumn('invoice_items', 'oss_applicable')
            ? "AND EXISTS (SELECT 1 FROM invoice_items cii
                            WHERE cii.invoice_id = invoices.id
                              AND COALESCE(cii.oss_applicable, 0) = 0)"
            : '';
        $creditNotes = $this->db->pdo()->prepare(
            "SELECT varsymbol, COALESCE(tax_date, issue_date) AS duzp
               FROM invoices
              WHERE supplier_id = ?
                AND status NOT IN ('draft', 'cancelled')
                AND invoice_type = 'credit_note'
                AND (total_without_vat < 0 OR total_vat < 0)
                AND COALESCE(tax_date, issue_date) BETWEEN ? AND ?
                {$creditNoteOssFilter}
           ORDER BY COALESCE(tax_date, issue_date), id"
        );
        $creditNotes->execute([$supplierId, $start, $end]);
        foreach (array_filter($creditNotes->fetchAll(\PDO::FETCH_ASSOC), $inVatTime) as ['varsymbol' => $number]) {
            $warnings[] = "Dobropis {$number} snižuje daň na výstupu. Ověřte, že datum zařazení odpovídá doručení opravného daňového dokladu nebo vynaložení rozumného úsilí o jeho doručení (§ 42 ZDPH).";
        }

        $ossFilter = $this->db->hasColumn('invoice_items', 'oss_applicable')
            ? 'AND COALESCE(ii.oss_applicable, 0) = 0'
            : '';
        $unclassifiedZero = $this->db->pdo()->prepare(
            "SELECT DISTINCT i.varsymbol, COALESCE(i.tax_date, i.issue_date) AS duzp
               FROM invoices i
               JOIN invoice_items ii ON ii.invoice_id = i.id
              WHERE i.supplier_id = ?
                AND i.status NOT IN ('draft', 'cancelled')
                AND i.invoice_type <> 'proforma'
                AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
                AND COALESCE(i.reverse_charge, 0) = 0
                AND ii.vat_rate_snapshot = 0
                {$ossFilter}
                AND ii.vat_classification_code IS NULL
                AND i.vat_classification_code IS NULL
           ORDER BY i.varsymbol"
        );
        $unclassifiedZero->execute([$supplierId, $start, $end]);
        foreach (array_filter($unclassifiedZero->fetchAll(\PDO::FETCH_ASSOC), $inVatTime) as ['varsymbol' => $number]) {
            $warnings[] = "Doklad {$number} obsahuje neklasifikovaný řádek se sazbou 0 %. Řádek nebyl zahrnut na ř. 50; zvolte výslovnou klasifikaci DPH.";
        }
    }

    /**
     * Řádky povolené identifikované osobě (§ 6g–6l, issue #94): jen samovyměření
     * z přeshraničních přijatých plnění. Cokoli jiného (tuzemské výstupy ř. 1/2,
     * oddíl C ř. 20-31, odpočty ř. 40+ vč. zrcadlového ř. 43 z RC mirroru) IO
     * nevyplňuje — vyhazujeme s warningem, ať uživatel ví, co a proč vypadlo.
     *
     * Vyloučené řádky se vznikem povinnosti, které IO věcně nemá:
     *   ř. 7/8 (dovoz zboží — DPH u neplátce vybírá celní úřad),
     *   ř. 10/11 (tuzemský RC § 92a — jen mezi plátci).
     *
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $lines
     * @param list<string> $warnings by-ref
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     */
    private function filterLinesForIdentified(array $lines, array &$warnings): array
    {
        $allowed = ['3', '4', '5', '6', '12', '13'];
        // Zrcadlový odpočet ř. 43 (dphdp3_line_secondary klasifikací 23/24/25)
        // a navázaný doplňující ř. 47 vznikají u IO automaticky z klasifikace —
        // jejich vyřazení JE pointa režimu (IO nemá nárok na odpočet), žádný warning.
        $silentDrop = ['43', '47'];
        $kept = [];
        foreach ($lines as $line => $data) {
            $key = (string) $line;
            if (in_array($key, $allowed, true)) {
                $kept[$line] = $data;
                continue;
            }
            if (in_array($key, $silentDrop, true)) {
                continue;
            }
            $warnings[] = sprintf(
                'Řádek %s (%s, základ %s Kč) identifikovaná osoba nevyplňuje — vynechán. Zkontroluj klasifikaci dokladů.',
                $key,
                $data['label'],
                number_format($data['base'], 0, ',', ' '),
            );
        }
        return $kept;
    }

    /** Period of the filing: the requested one, else supplier.vat_period, else 'monthly'. */
    public static function normalizePeriod(?string $requested, ?string $supplierVatPeriod): string
    {
        $period = $requested ?? (string) ($supplierVatPeriod ?? 'monthly');
        return in_array($period, ['monthly', 'quarterly'], true) ? $period : 'monthly';
    }

    /**
     * Načti tax-relevantní info o tenantovi.
     * @return array<string,mixed>
     */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic,
                    s.taxpayer_type, s.vat_period, s.financial_office_code,
                    s.workplace_code, s.cz_nace_code, s.data_box_id,
                    s.email, s.phone,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni,
                    s.sest_jmeno, s.sest_prijmeni, s.sest_telefon, s.sest_email, s.sest_funkce
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        }
        return $row;
    }

    private function loadAppVersion(): ?string
    {
        $verFile = __DIR__ . '/../../../../VERSION';
        if (is_file($verFile)) {
            return trim((string) file_get_contents($verFile)) ?: null;
        }
        return null;
    }

    // VetaP a normalizeOkec přesunuto do EpoSupplierBlockBuilder (sdíleno s KH/SHV).

    /**
     * Formátování částky pro EPO XML — celé číslo Kč (zaokrouhleno).
     */
    private function formatAmount(float $amount): string
    {
        return (string) (int) round($amount);
    }
}
