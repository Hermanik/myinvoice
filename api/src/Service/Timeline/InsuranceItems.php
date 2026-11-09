<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Report\ReportPeriod;
use MyInvoice\Service\Tax\TaxConstants;

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
 * The advance amount is the statutory minimum of the year derived from the tax constants; the real advance follows the
 * last statement, which the application does not know — the item says so.
 */
final class InsuranceItems
{
    private const CSSZ_DAY = 20;
    private const HEALTH_DAY = 8;
    private const SURCHARGE_DAYS = 8;

    public function __construct(
        private readonly TaxConstantsRepository $constants,
        private readonly TaxProfileRepository $profiles,
    ) {}

    /**
     * @param array<int, array{regime:string}> $regimes tax-year facts by year ({@see TaxYearFacts::regimes()})
     * @return list<array<string,mixed>> items due within [$from, $to]
     */
    public function items(int $supplierId, array $regimes, string $from, string $to): array
    {
        $items = [];
        foreach ($regimes as $year => $facts) {
            if ($facts['regime'] === TaxYearFacts::FLAT) {
                continue;
            }
            // A year with a regime LOST has its statements; the monthly advances of the lost year are covered by what was
            // paid as flat tax (the warning item tells to verify the advances after leaving the regime).
            array_push($items, ...$this->statements($year, $from, $to));
            if ($facts['regime'] === TaxYearFacts::NONE) {
                array_push($items, ...$this->advances($supplierId, $year, $from, $to));
            }
        }
        return $items;
    }

    /**
     * Date of the statements of a tax year: one month after the electronic deadline of the return, on a working day.
     */
    public static function statementDeadline(int $year): string
    {
        $returnDeadline = new \DateTimeImmutable(ReportPeriod::incomeTaxElectronicDeadline($year));
        return CzechWorkingDays::shiftToWorkingDay($returnDeadline->modify('+1 month'))->format('Y-m-d');
    }

    /** @return list<array<string,mixed>> */
    private function statements(int $year, string $from, string $to): array
    {
        $deadline = self::statementDeadline($year);
        $advisor = CzechWorkingDays::shiftToWorkingDay((new \DateTimeImmutable(ReportPeriod::incomeTaxAdvisorDeadline($year)))->modify('+1 month'))->format('Y-m-d');
        $surcharge = (new \DateTimeImmutable($deadline))->modify('+' . self::SURCHARGE_DAYS . ' days')->format('Y-m-d');
        $start = ($year + 1) . '-01-01';
        $items = [];
        foreach (['social' => '§ 15 zákona č. 589/1992 Sb.', 'health' => '§ 24 zákona č. 592/1992 Sb.'] as $kind => $legal) {
            if ($deadline >= $from && $deadline <= $to) {
                $items[] = TimelineItem::make("ins_overview_$kind:$year", "insurance_overview_$kind", 'insurance', $deadline, [
                    'start'  => $start,
                    'params' => ['year' => $year, 'advisor_due_date' => $advisor],
                    'legal'  => $legal,
                    'links'  => ['optimizer'],
                ]);
            }
            if ($surcharge >= $from && $surcharge <= $to) {
                $items[] = TimelineItem::make("ins_surcharge_$kind:$year", "insurance_surcharge_$kind", 'insurance', $surcharge, [
                    'start'  => $deadline,
                    'params' => ['year' => $year],
                    'notes'  => $this->nonWorkingDayNote($surcharge),
                    'legal'  => $kind === 'social' ? 'zákon č. 589/1992 Sb.' : 'zákon č. 592/1992 Sb.',
                    'info'   => true,
                ]);
            }
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function advances(int $supplierId, int $year, string $from, string $to): array
    {
        // The advance of the last month of the year is due in January of the next one.
        $firstDue = $year . '-02-' . sprintf('%02d', self::HEALTH_DAY);
        $lastDue = ($year + 1) . '-01-' . sprintf('%02d', self::CSSZ_DAY);
        if ($lastDue < $from || $firstDue > $to) {
            return [];
        }
        $amounts = $this->minimumAdvances($supplierId, $year);
        $yearNotes = [['code' => 'advance_minimum'], ['code' => 'advance_changes']];
        if ($amounts['secondary']) {
            $yearNotes[] = ['code' => 'advance_secondary'];
        }
        if ($amounts['constants_year'] !== $year) {
            $yearNotes[] = ['code' => 'advance_constants_fallback', 'params' => ['constants_year' => $amounts['constants_year']]];
        }

        $items = [];
        for ($month = 1; $month <= 12; $month++) {
            $due = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $next = $due->modify('first day of next month');
            $period = $due->format('Y-m');
            foreach (['social' => [self::CSSZ_DAY, '§ 14a zákona č. 589/1992 Sb.'], 'health' => [self::HEALTH_DAY, 'zákon č. 592/1992 Sb.']] as $kind => [$day, $legal]) {
                $date = $next->format('Y-m-') . sprintf('%02d', $day);
                if ($date < $from || $date > $to) {
                    continue;
                }
                $items[] = TimelineItem::make("ins_$kind:$period", "insurance_advance_$kind", 'insurance', $date, [
                    'start'  => $due->format('Y-m-d'),
                    'params' => ['period' => $period, 'year' => $year, 'month' => $month],
                    'notes'  => [...$yearNotes, ...$this->nonWorkingDayNote($date)],
                    'legal'  => $legal,
                    'amount' => $amounts[$kind],
                    'amount_estimated' => true,
                ]);
            }
        }
        return $items;
    }

    /**
     * Statutory minimum monthly advances of the year from the tax constants (minimum assessment base / 12 × rate, rounded
     * up to a whole crown), or null amounts when the constants do not carry them.
     *
     * @return array{social: ?float, health: ?float, secondary: bool, constants_year: int}
     */
    private function minimumAdvances(int $supplierId, int $year): array
    {
        $c = $this->constants->forYear($year);
        $secondary = (bool) ($this->profiles->find($supplierId, $year)['is_secondary'] ?? false);
        $socialBase = $c[$secondary ? 'social_min_base_secondary' : 'social_min_base_main'] ?? null;
        $healthBase = $c['health_min_base'] ?? null;
        $known = in_array($year, TaxConstants::availableYears(), true) || $this->constants->override($year) !== null;
        return [
            'social' => $socialBase !== null && isset($c['social_rate']) ? (float) ceil((float) $socialBase / 12 * (float) $c['social_rate']) : null,
            'health' => $healthBase !== null && isset($c['health_rate']) ? (float) ceil((float) $healthBase / 12 * (float) $c['health_rate']) : null,
            'secondary' => $secondary,
            'constants_year' => $known ? $year : $this->latestKnownYear($year),
        ];
    }

    private function latestKnownYear(int $year): int
    {
        $below = array_filter(TaxConstants::availableYears(), static fn (int $y): bool => $y < $year);
        return $below !== [] ? max($below) : min(TaxConstants::availableYears());
    }

    /** @return list<string> */
    private function nonWorkingDayNote(string $date): array
    {
        return CzechWorkingDays::isWorkingDay(new \DateTimeImmutable($date)) ? [] : ['non_working_day'];
    }
}
