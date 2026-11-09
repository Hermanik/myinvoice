<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Report\ReportPeriod;
use MyInvoice\Service\Vat\VatStatusHistory;

/**
 * Income tax items of a natural person (FO) beyond the return itself, which comes from the tax calendar.
 *
 *   - Warning that the flat-tax regime does not hold for a year (the person owes a regular return and the insurance
 *     statements; what was paid as flat tax is credited).
 *   - Income tax advances (§ 38a ZDP) for the advance period that follows the deadline of a year's return: the advance
 *     is derived from the last known tax. Over 150 000 Kč it is paid quarterly (1/4 on 15 March, June, September and
 *     December), over 30 000 Kč twice a year (40 % on 15 June and 15 December), below that not at all. Dates move to the
 *     next working day (§ 33 odst. 4 DŘ). The tax is the optimizer's computation of a closed year with a saved tax
 *     profile; without one the item only reminds to work the advances out from the filed return.
 *   - Collecting the documents for the deductions and credits claimed in the return (mortgage interest, pension and life
 *     insurance, gifts, children) — a recommendation due on 15 February.
 */
final class IncomeTaxItems
{
    private const QUARTERLY_ABOVE = 150000;
    private const SEMIANNUAL_ABOVE = 30000;
    private const QUARTERLY_MONTHS = [3, 6, 9, 12];
    private const SEMIANNUAL_MONTHS = [6, 12];

    public function __construct(
        private readonly TaxYearFacts $facts,
        private readonly TaxProfileRepository $profiles,
    ) {}

    /**
     * @param array<int, array{regime:string, reason:?string, income:?float, limit:?float}> $regimes by year
     * @return list<array<string,mixed>> items due within [$from, $to]
     */
    public function items(int $supplierId, array $regimes, VatStatusHistory $history, \DateTimeImmutable $now, string $from, string $to): array
    {
        $items = [];
        foreach ($regimes as $year => $facts) {
            if ($facts['regime'] === TaxYearFacts::LOST) {
                $date = "$year-12-31";
                if ($date >= $from && $date <= $to) {
                    $items[] = TimelineItem::make("flat_tax_lost:$year", 'flat_tax_lost', 'income_tax', $date, [
                        'params' => ['year' => $year, 'reason' => $facts['reason'], 'income' => $facts['income'], 'limit' => $facts['limit']],
                        'notes'  => ['flat_tax_lost_insurance'],
                        'legal'  => '§ 7a ZDP',
                        'links'  => ['optimizer'],
                        'severity' => 'warning',
                        'info'   => true,
                    ]);
                }
            }
            if ($facts['regime'] !== TaxYearFacts::FLAT) {
                array_push($items, ...$this->advances($supplierId, $year, $history, $now, $from, $to), ...$this->deductionDocuments($supplierId, $year, $from, $to));
            }
        }
        return $items;
    }

    /**
     * Advances paid on the basis of the tax of $baseYear, in the advance period after the deadline of that year's return.
     *
     * @return list<array<string,mixed>>
     */
    private function advances(int $supplierId, int $baseYear, VatStatusHistory $history, \DateTimeImmutable $now, string $from, string $to): array
    {
        $periodStart = ReportPeriod::incomeTaxElectronicDeadline($baseYear);
        $periodEnd = ReportPeriod::incomeTaxElectronicDeadline($baseYear + 1);
        if ($periodEnd < $from || $periodStart > $to) {
            return [];
        }
        $liability = $this->facts->incomeTaxLiability($supplierId, $baseYear, $history, $now);
        if ($liability === null) {
            return $periodStart >= $from && $periodStart <= $to ? [TimelineItem::make("tax_advance_info:$baseYear", 'income_tax_advance_info', 'income_tax', $periodStart, [
                'params' => ['base_year' => $baseYear],
                'legal'  => '§ 38a ZDP',
                'links'  => ['optimizer'],
                'info'   => true,
            ])] : [];
        }

        $tax = $liability['tax'];
        if ($tax <= self::SEMIANNUAL_ABOVE) {
            return []; // below the limit (or a tax bonus) no advances are paid
        }
        [$months, $share, $frequency] = $tax > self::QUARTERLY_ABOVE
            ? [self::QUARTERLY_MONTHS, 25, 'quarterly']
            : [self::SEMIANNUAL_MONTHS, 40, 'semiannual'];
        $items = [];
        // The advance period spans from spring of year+1 to spring of year+2; its payments fall in those two years.
        foreach ([$baseYear + 1, $baseYear + 2] as $year) {
            foreach ($months as $month) {
                $date = CzechWorkingDays::shiftToWorkingDay(new \DateTimeImmutable(sprintf('%04d-%02d-15', $year, $month)))->format('Y-m-d');
                if ($date <= $periodStart || $date > $periodEnd || $date < $from || $date > $to) {
                    continue;
                }
                $items[] = TimelineItem::make(sprintf('tax_advance:%04d-%02d', $year, $month), 'income_tax_advance', 'income_tax', $date, [
                    'params' => ['base_year' => $baseYear, 'share' => $share, 'frequency' => $frequency, 'tax' => round($tax, 0)],
                    'notes'  => ['advance_estimate'],
                    'legal'  => '§ 38a ZDP',
                    'amount' => round($tax * $share / 100, 0),
                    'amount_estimated' => true,
                    'links'  => ['optimizer'],
                ]);
            }
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function deductionDocuments(int $supplierId, int $year, string $from, string $to): array
    {
        $date = ($year + 1) . '-02-15';
        if ($date < $from || $date > $to) {
            return [];
        }
        $profile = $this->profiles->find($supplierId, $year);
        if ($profile === null) {
            return [];
        }
        $claims = array_filter([
            'mortgage'  => $profile['mortgage_interest'] > 0,
            'pension'   => $profile['pension_contrib'] > 0,
            'life'      => $profile['life_insurance'] > 0,
            'donations' => $profile['donations'] > 0,
            'children'  => $profile['children_count'] > 0,
            'spouse'    => $profile['spouse_credit'],
        ]);
        if ($claims === []) {
            return [];
        }
        return [TimelineItem::make("deduction_docs:$year", 'deduction_documents', 'income_tax', $date, [
            'start'  => "$year-12-31",
            'params' => ['year' => $year, 'claims' => array_keys($claims)],
            'legal'  => '§ 15, § 35ba, § 35c ZDP',
            'obligation' => 'recommended',
            'links'  => ['optimizer'],
        ])];
    }
}
