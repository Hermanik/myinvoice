<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Report\ReportPeriod;

/**
 * Social and health insurance of a self-employed natural person (OSVČ) for the years in which the person is NOT in the
 * flat-tax regime (in that regime the monthly flat payment covers the insurance and no statements are filed).
 *
 *   - Statements (přehledy) for the year: one month after the deadline of the income tax return (ČSSZ: § 15 zákona
 *     589/1992 Sb.; health insurance company: § 24 zákona 592/1992 Sb.), moved to a working day. The electronic deadline
 *     of the return is used; with a tax adviser the return is due on 1 July and the statements one month later.
 *   - Surcharge (doplatek): due within 8 days of filing the statement — here counted from the statement deadline.
 *   - Monthly advances: social by the 20th (§ 14a 589/1992), health by the 8th of the month FOLLOWING the month the
 *     advance is for. A date on a weekend or holiday is not moved (the payment has to be credited by the date, so the
 *     item carries a hint to pay on the working day before).
 *
 * The advance amount is the statutory minimum of the year derived from the tax constants ({@see InsuranceAdvanceAmounts});
 * the real advance follows the last statement, which the application does not know — the item says so. When the
 * previous year has an estimate ({@see TaxYearEstimates}), the advances of the months after the month of its
 * statements deadline are that year's social / health insurance divided by 12, as they are after a real statement.
 * The statements and their surcharges carry the estimated surcharge.
 */
final class InsuranceItems
{
    private const CSSZ_DAY = 20;
    private const HEALTH_DAY = 8;

    public function __construct(private readonly InsuranceAdvanceAmounts $amounts) {}

    /**
     * @param array<int, array{regime:string}> $regimes tax-year facts by year ({@see TaxYearFacts::regimes()})
     * @param array<int, array<string,mixed>> $estimates estimates by year ({@see TaxYearEstimates})
     * @return list<array<string,mixed>> items due within [$from, $to]
     */
    public function items(int $supplierId, array $regimes, array $estimates, string $from, string $to): array
    {
        $items = [];
        foreach ($regimes as $year => $facts) {
            if ($facts['regime'] === TaxYearFacts::FLAT) {
                continue;
            }
            // A year with a regime LOST has its statements; the monthly advances of the lost year are covered by what was
            // paid as flat tax (the warning item tells to keep paying it until the year end).
            array_push($items, ...$this->statements($year, $estimates[$year] ?? null, $from, $to));
            if ($facts['regime'] === TaxYearFacts::NONE) {
                array_push($items, ...$this->advances($supplierId, $year, $estimates[$year - 1] ?? null, $from, $to));
            }
        }
        return $items;
    }

    /**
     * @param array<string,mixed>|null $estimate
     * @return list<array<string,mixed>>
     */
    private function statements(int $year, ?array $estimate, string $from, string $to): array
    {
        $deadline = ReportPeriod::insuranceStatementsDeadline($year);
        $advisor = CzechWorkingDays::shiftToWorkingDay((new \DateTimeImmutable(ReportPeriod::incomeTaxAdvisorDeadline($year)))->modify('+1 month'))->format('Y-m-d');
        $surcharge = ReportPeriod::insuranceSurchargeDeadline($year);
        $start = ($year + 1) . '-01-01';
        $items = [];
        foreach (['social' => '§ 15 zákona č. 589/1992 Sb.', 'health' => '§ 24 zákona č. 592/1992 Sb.'] as $kind => $legal) {
            $expected = TaxYearEstimates::itemOptions($estimate, $kind);
            if ($deadline >= $from && $deadline <= $to) {
                $items[] = TimelineItem::make("ins_overview_$kind:$year", "insurance_overview_$kind", 'insurance', $deadline, [
                    'start'  => $start,
                    'params' => ['year' => $year, 'advisor_due_date' => $advisor],
                    'notes'  => $expected['notes'],
                    'legal'  => $legal,
                    'amount' => $expected['amount'],
                    'amount_estimated' => $expected['amount_estimated'],
                    'links'  => ['optimizer'],
                ]);
            }
            if ($surcharge >= $from && $surcharge <= $to) {
                $items[] = TimelineItem::make("ins_surcharge_$kind:$year", "insurance_surcharge_$kind", 'insurance', $surcharge, [
                    'start'  => $deadline,
                    'params' => ['year' => $year],
                    'notes'  => [...$expected['notes'], ...$this->nonWorkingDayNote($surcharge)],
                    'legal'  => $kind === 'social' ? 'zákon č. 589/1992 Sb.' : 'zákon č. 592/1992 Sb.',
                    'amount' => $expected['amount'],
                    'amount_estimated' => $expected['amount_estimated'],
                    'info'   => true,
                ]);
            }
        }
        return $items;
    }

    /**
     * @param array<string,mixed>|null $statement estimate of the previous year, the base of the advances after its statements
     * @return list<array<string,mixed>>
     */
    private function advances(int $supplierId, int $year, ?array $statement, string $from, string $to): array
    {
        // The advance of the last month of the year is due in January of the next one.
        $firstDue = $year . '-02-' . sprintf('%02d', self::HEALTH_DAY);
        $lastDue = ($year + 1) . '-01-' . sprintf('%02d', self::CSSZ_DAY);
        if ($lastDue < $from || $firstDue > $to) {
            return [];
        }
        $amounts = $this->amounts->minimum($supplierId, $year);
        $yearNotes = [['code' => 'advance_minimum'], ['code' => 'advance_changes']];
        if ($amounts['secondary']) {
            $yearNotes[] = ['code' => 'advance_secondary'];
        }
        if ($amounts['constants_year'] !== $year) {
            $yearNotes[] = ['code' => 'advance_constants_fallback', 'params' => ['constants_year' => $amounts['constants_year']]];
        }
        // The month the statements of the previous year are due in; the advances for the months after it follow them.
        $statementMonth = $statement === null ? null : substr(ReportPeriod::insuranceStatementsDeadline($year - 1), 0, 7);

        $items = [];
        for ($month = 1; $month <= 12; $month++) {
            $due = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $next = $due->modify('first day of next month');
            $period = $due->format('Y-m');
            $afterStatement = $statementMonth !== null && $period > $statementMonth;
            foreach (['social' => [self::CSSZ_DAY, '§ 14a zákona č. 589/1992 Sb.'], 'health' => [self::HEALTH_DAY, 'zákon č. 592/1992 Sb.']] as $kind => [$day, $legal]) {
                $date = $next->format('Y-m-') . sprintf('%02d', $day);
                if ($date < $from || $date > $to) {
                    continue;
                }
                $items[] = TimelineItem::make("ins_$kind:$period", "insurance_advance_$kind", 'insurance', $date, [
                    'start'  => $due->format('Y-m-d'),
                    'params' => ['period' => $period, 'year' => $year, 'month' => $month],
                    'notes'  => [
                        ...($afterStatement ? [['code' => 'advance_from_statement', 'params' => ['base_year' => $year - 1]]] : $yearNotes),
                        ...$this->nonWorkingDayNote($date),
                    ],
                    'legal'  => $legal,
                    'amount' => $afterStatement ? InsuranceAdvanceAmounts::forYearTotal($statement[$kind]['expected']) : $amounts[$kind],
                    'amount_estimated' => true,
                ]);
            }
        }
        return $items;
    }

    /** @return list<string> */
    private function nonWorkingDayNote(string $date): array
    {
        return CzechWorkingDays::isWorkingDay(new \DateTimeImmutable($date)) ? [] : ['non_working_day'];
    }
}
