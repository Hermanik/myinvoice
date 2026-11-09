<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\SupplierVatStatusRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\ReportPeriod;
use MyInvoice\Service\Vat\VatStatusHistory;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Tax deadlines of one supplier derived from its settings — the single source for the dashboard
 * "Daňový kalendář" widget and for the tax items of the "Akce pro tebe" feed (CRM).
 *
 *   - DPH return: monthly or quarterly per `supplier.vat_period` (an identified person always monthly),
 *     due on the 25th of the month after the period.
 *   - Kontrolní hlášení: a legal entity (PO) always monthly, a natural person (FO) follows the VAT
 *     period (§ 101e ZDPH). Only for a payer — an identified person files no KH.
 *   - Souhrnné hlášení: only for periods with EU supplies. Supplies of goods are reported monthly;
 *     a quarterly payer with services only files quarterly (§ 102 ZDPH).
 *   - Income tax return (DPFO / DPPO): 1 April of the following year.
 *   - Flat-tax (paušální daň) advance: the 20th of every month, only in a year in which the supplier
 *     is in a flat-tax band (the per-year tax profile, else the supplier setting) and is not a VAT
 *     payer on any day of it (§ 7a ZDP does not allow both).
 * VAT items exist only for periods in which the supplier had VAT duties (payer / identified person,
 * decided by the dated status history). A filed/confirmed badge comes from the submission archive.
 *
 * {@see items()} serves the dashboard widget and the CRM feed (a window around today, filed items and passed flat-tax
 * advances dropped); {@see itemsBetween()} returns everything of an explicit date range for the obligations timeline.
 */
final class TaxCalendarService
{
    public const LINKS = [
        'dph' => '/reports/dph', 'kh' => '/reports/kh', 'sh' => '/reports/shv',
        'income_tax' => '/reports/income-tax', 'pausal' => '/tax',
    ];

    private const FORM_CODES = ['dph' => 'dphdp3', 'kh' => 'dphkh1', 'sh' => 'dphshv'];

    public function __construct(
        private readonly Connection $db,
        private readonly VatStatusService $vatStatus,
        private readonly TaxSubmissionRepository $submissions,
        private readonly TaxConstantsRepository $taxConstants,
        private readonly SupplierVatStatusRepository $flatTaxSettings,
    ) {}

    /**
     * Deadlines due between `-$daysBack` and `+$daysAhead` days from $now. Items that are already filed
     * and past their date are dropped; unfiled ones stay while they are overdue (within $daysBack).
     *
     * @param list<string>|null $types restrict to some item types (dph, kh, sh, income_tax, pausal)
     * @return list<array<string,mixed>> sorted by due date
     */
    public function items(int $supplierId, \DateTimeImmutable $now, int $daysAhead = 60, int $daysBack = 14, ?array $types = null): array
    {
        $today = $now->setTime(0, 0);
        $items = $this->itemsBetween(
            $supplierId,
            $now,
            $today->modify("-{$daysBack} days")->format('Y-m-d'),
            $today->modify("+{$daysAhead} days")->format('Y-m-d'),
            $types,
        );

        // A filed return needs no more attention once its date has passed, and whether a flat-tax advance was
        // paid is not tracked, so a passed date is not nagged about.
        return array_values(array_filter(
            $items,
            static fn (array $i): bool => $i['type'] === 'pausal'
                ? $i['days_left'] >= 0
                : ($i['days_left'] >= 0 || ($i['filing']['status'] ?? 'unfiled') === 'unfiled'),
        ));
    }

    /**
     * Every deadline due within the inclusive date range, filed or not and past or not (the obligations timeline
     * shows what is done as well). `days_left` is counted from $now.
     *
     * @param list<string>|null $types restrict to some item types (dph, kh, sh, income_tax, pausal)
     * @return list<array<string,mixed>> sorted by due date
     */
    public function itemsBetween(int $supplierId, \DateTimeImmutable $now, string $from, string $to, ?array $types = null): array
    {
        $supplier = $this->supplier($supplierId);
        $today = $now->setTime(0, 0);
        $want = static fn (string $type): bool => $types === null || in_array($type, $types, true);

        // The archive is only read once an item in the range needs its badge.
        $filed = null;
        $badgeFor = function (string $slot) use (&$filed, $supplierId): array {
            $filed ??= $this->filedIndex($supplierId);
            return $this->badge($filed[$slot] ?? null);
        };
        $inRange = static function (string $due) use ($today, $from, $to): ?int {
            return $due >= $from && $due <= $to ? (int) $today->diff(new \DateTimeImmutable($due))->format('%r%a') : null;
        };

        $history = $this->vatStatus->history($supplierId);
        $vatPeriod = DphPriznaniBuilder::normalizePeriod(null, $supplier['vat_period'] ?? null);
        $isPo = ($supplier['taxpayer_type'] ?? '') === 'po';
        $flatTaxBands = $want('pausal') ? $this->flatTaxSettings->profileFlatTaxBands($supplierId) : [];

        $items = [];
        $currentYear = (int) $today->format('Y');
        $firstYear = (int) substr($from, 0, 4);
        $lastYear = (int) substr($to, 0, 4);
        // A period (and a tax year) can be due in the year after its own.
        for ($year = $firstYear - 1; $year <= $lastYear; $year++) {
            if ($want('dph') || $want('kh') || $want('sh')) {
                foreach (['monthly' => range(1, 12), 'quarterly' => [3, 6, 9, 12]] as $period => $months) {
                    foreach ($months as $month) {
                        [$start, $end] = ReportPeriod::range($year, $month, $period);
                        $due = ReportPeriod::filingDeadline($year, $month, $period);
                        $days = $inRange($due);
                        if ($days === null || !$history->hasVatDutiesBetween($start, $end)) {
                            continue;
                        }
                        $isPayer = $history->hasPayerTimeBetween($start, $end);
                        // DPH: the supplier's period; an identified person files monthly.
                        if ($want('dph') && $period === ($isPayer ? $vatPeriod : 'monthly')) {
                            $items[] = $this->item('dph', $year, $month, $period, $due, $days, $badgeFor);
                        }
                        // KH: PO monthly, FO follows the VAT period; payers only.
                        if ($want('kh') && $isPayer && $period === ($isPo ? 'monthly' : $vatPeriod)) {
                            $items[] = $this->item('kh', $year, $month, $period, $due, $days, $badgeFor);
                        }
                        if ($want('sh') && $this->shDue($supplierId, $year, $month, $period, $vatPeriod)) {
                            $items[] = $this->item('sh', $year, $month, $period, $due, $days, $badgeFor);
                        }
                    }
                }
            }

            if ($want('income_tax') && in_array($supplier['taxpayer_type'] ?? '', ['fo', 'po'], true)) {
                // The main date is the electronic one (§ 136 odst. 2 písm. a) DŘ), the paper one (§ 136 odst. 1) is informative.
                $due = ReportPeriod::incomeTaxElectronicDeadline($year);
                $days = $inRange($due);
                if ($days !== null) {
                    $form = $supplier['taxpayer_type'] === 'po' ? 'dppdp9' : 'dpfdp5';
                    $items[] = [
                        'type' => 'income_tax', 'key' => 'income_tax:' . $year, 'year' => $year, 'month' => null,
                        'quarter' => null, 'period' => 'yearly', 'period_label' => (string) $year,
                        'form_code' => $form, 'due_date' => $due, 'paper_due_date' => ReportPeriod::incomeTaxPaperDeadline($year),
                        'days_left' => $days, 'link' => self::LINKS['income_tax'],
                        'filing' => $badgeFor(TaxSubmissionRepository::slotKey($form, $year, null, null)),
                    ];
                }
            }

            // The flat-tax advance is due in its own year.
            if ($want('pausal') && $year >= $firstYear) {
                $band = self::flatTaxBandOf($year, $currentYear, $flatTaxBands, (string) ($supplier['flat_tax_band'] ?? 'none'), $history);
                if ($band !== null) {
                    $segments = null;
                    for ($month = 1; $month <= 12; $month++) {
                        $due = self::pausalDueDate($year, $month);
                        $days = $inRange($due);
                        if ($days === null) {
                            continue;
                        }
                        $segments ??= PausalSchedule::normalize($this->taxConstants->forYear($year)['pausal_monthly'] ?? []);
                        $items[] = [
                            'type' => 'pausal', 'key' => sprintf('pausal:%04d-%02d', $year, $month), 'year' => $year,
                            'month' => $month, 'quarter' => null, 'period' => 'monthly',
                            'period_label' => sprintf('%d-%02d', $year, $month), 'form_code' => null,
                            'due_date' => $due, 'days_left' => $days, 'link' => self::LINKS['pausal'],
                            'filing' => null, 'band' => $band,
                            'amount' => (float) (PausalSchedule::monthlyAt($year, $month, $segments)[$band] ?? 0),
                        ];
                    }
                }
            }
        }

        usort($items, static fn (array $a, array $b): int => [$a['due_date'], $a['type']] <=> [$b['due_date'], $b['type']]);
        return $items;
    }

    /** The flat-tax advance of a month is due on the 20th, moved to the next working day. */
    public static function pausalDueDate(int $year, int $month): string
    {
        return CzechWorkingDays::shiftToWorkingDay(new \DateTimeImmutable(sprintf('%04d-%02d-20', $year, $month)))->format('Y-m-d');
    }

    /**
     * The flat-tax band set for a year (none = 'none'): the saved tax profile of the year wins; without one the
     * supplier's setting counts from the current year on.
     *
     * @param array<int,string> $profileBands saved tax-profile band per year
     */
    public static function declaredFlatTaxBand(int $year, int $currentYear, array $profileBands, string $supplierBand): string
    {
        return $profileBands[$year] ?? ($year >= $currentYear ? $supplierBand : 'none');
    }

    /**
     * The flat-tax band that applies to a year, or null when the year is not a flat-tax year. A VAT payer cannot be
     * in the flat-tax regime (§ 7a ZDP), whatever band is set.
     *
     * @param array<int,string> $profileBands saved tax-profile band per year
     */
    public static function flatTaxBandOf(int $year, int $currentYear, array $profileBands, string $supplierBand, VatStatusHistory $history): ?string
    {
        $band = self::declaredFlatTaxBand($year, $currentYear, $profileBands, $supplierBand);
        if (!in_array($band, PausalSchedule::BANDS, true) || $history->hasPayerTimeBetween("$year-01-01", "$year-12-31")) {
            return null;
        }
        return $band;
    }

    /**
     * Does a souhrnné hlášení have to be filed for this VAT period? (§ 102 ZDPH)
     *
     * A monthly payer files a monthly SH for every month with an EU supply. A quarterly payer:
     *  - goods supplied in a month  -> a monthly SH for that month (supplies of goods are never quarterly),
     *  - services only in a month   -> reported in the monthly SH of that month too when the quarter
     *                                   contains goods in any month,
     *  - a quarter with services only (no goods in any of its months) -> ONE quarterly SH.
     * `$month` is the last month of the quarter for the quarterly period.
     */
    private function shDue(int $supplierId, int $year, int $month, string $period, string $vatPeriod): bool
    {
        if ($period === 'monthly') {
            $supplies = $this->euSupplies($supplierId, ...ReportPeriod::range($year, $month, 'monthly'));
            if ($vatPeriod === 'monthly') {
                return $supplies['goods'] || $supplies['services'];
            }
            return $supplies['goods']
                || ($supplies['services'] && $this->euSupplies($supplierId, ...ReportPeriod::range($year, $month, 'quarterly'))['goods']);
        }
        if ($vatPeriod !== 'quarterly') {
            return false;
        }
        $quarter = $this->euSupplies($supplierId, ...ReportPeriod::range($year, $month, 'quarterly'));
        return !$quarter['goods'] && $quarter['services'];
    }

    /**
     * EU B2B supplies of the supplier within the range: goods (codes 20 / 31) and services (22, or a
     * reverse-charge supply without a code) to an EU customer with a VAT ID.
     *
     * @return array{goods: bool, services: bool}
     */
    public function euSupplies(int $supplierId, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(CASE WHEN COALESCE(ii.vat_classification_code, i.vat_classification_code) IN ('20','31') THEN 1 ELSE 0 END) AS goods,
                    MAX(CASE WHEN COALESCE(ii.vat_classification_code, i.vat_classification_code) = '22'
                               OR (COALESCE(ii.vat_classification_code, i.vat_classification_code) IS NULL AND i.reverse_charge = 1)
                             THEN 1 ELSE 0 END) AS services
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN countries co ON co.id = c.country_id
               JOIN invoice_items ii ON ii.invoice_id = i.id
              WHERE i.supplier_id = ?
                AND i.status IN ('issued','sent','reminded','paid')
                AND i.invoice_type IN ('invoice','credit_note','tax_document')
                AND COALESCE(co.is_eu, 0) = 1
                AND COALESCE(co.iso2, 'CZ') <> 'CZ'
                AND c.dic IS NOT NULL AND c.dic <> ''
                AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
                AND (COALESCE(ii.vat_classification_code, i.vat_classification_code) IN ('20','22','31')
                     OR i.reverse_charge = 1)"
        );
        $stmt->execute([$supplierId, $start, $end]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['goods' => (bool) ($row['goods'] ?? false), 'services' => (bool) ($row['services'] ?? false)];
    }

    /**
     * @param callable(string): array<string,mixed> $badgeFor slot key => filing badge
     * @return array<string,mixed>
     */
    private function item(string $type, int $year, int $month, string $period, string $due, int $days, callable $badgeFor): array
    {
        $quarter = $period === 'quarterly' ? intdiv($month - 1, 3) + 1 : null;
        $form = self::FORM_CODES[$type];
        return [
            'type' => $type, 'key' => $type . ':' . ($quarter !== null ? sprintf('%04d-Q%d', $year, $quarter) : sprintf('%04d-%02d', $year, $month)),
            'year' => $year, 'month' => $quarter === null ? $month : null, 'quarter' => $quarter, 'period' => $period,
            'period_label' => $quarter !== null ? sprintf('%d Q%d', $year, $quarter) : sprintf('%d-%02d', $year, $month),
            'form_code' => $form, 'due_date' => $due, 'days_left' => $days, 'link' => self::LINKS[$type],
            'filing' => $badgeFor(TaxSubmissionRepository::slotKey($form, $year, $quarter === null ? $month : null, $quarter)),
        ];
    }

    /**
     * The latest filed/confirmed submission per slot ({@see TaxSubmissionRepository::slotKey()}).
     *
     * @return array<string,array<string,mixed>>
     */
    private function filedIndex(int $supplierId): array
    {
        $index = [];
        foreach ($this->submissions->filedByTypes($supplierId, ['dphdp3', 'dphkh1', 'dphshv', 'dpfdp5', 'dppdp9'], withSummary: false) as $row) {
            $index[TaxSubmissionRepository::slotKey($row['form_code'], $row['period_year'], $row['period_month'], $row['period_quarter'])] = $row;
        }
        return $index;
    }

    /** @return array{status:'unfiled'|'filed'|'confirmed', submission_id:?int, filed_at:?string} */
    private function badge(?array $submission): array
    {
        return $submission === null
            ? ['status' => 'unfiled', 'submission_id' => null, 'filed_at' => null]
            : ['status' => (string) $submission['filing_status'], 'submission_id' => (int) $submission['id'], 'filed_at' => $submission['filed_at']];
    }

    /** @return array<string,mixed> */
    private function supplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT taxpayer_type, vat_period, flat_tax_band FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
