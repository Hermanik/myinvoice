<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Report\ReportPeriod;
use MyInvoice\Service\Tax\PausalSchedule;
use MyInvoice\Service\Tax\TaxCalendarService;
use MyInvoice\Service\Tax\TaxOptimizer;
use MyInvoice\Service\Vat\VatStatusHistory;

/**
 * Estimated income tax, social and health insurance of a year that is NOT in the flat-tax regime (it lost it, or it is
 * a regular year from the start), and what is left to pay once the advances are credited.
 *
 *   Income        a closed year: the income paid in it (the base of the flat-tax limit). The current year: what has
 *                 been paid so far plus the unpaid issued invoices (issued, sent, reminded — "overdue" is not a status
 *                 of its own) whose due date falls in the same year, as they are expected to be paid in it.
 *   Liability     {@see TaxOptimizer::compare()}, the `regular` computation, with the tax profile of the year; without
 *                 a profile (the current year only) the usual 60 % flat-rate expenses and no deductions.
 *   Credited      a LOST year: the flat-tax payments of the year, by their parts ({@see FlatTaxParts}) — the tax part
 *                 on the income tax, the others on the insurance; the payments keep going until the end of the year.
 *                 A regular year: twelve minimum insurance advances and no income tax advances (the application does
 *                 not know the advances really paid — the estimate says so).
 *   Surcharge     liability - credited, per component; negative means an overpayment.
 *
 * Whole crowns throughout. A year in the flat-tax regime, a future year and a closed year without a tax profile have
 * no estimate (the optimizer would only guess the activity and the deductions).
 */
final class TaxYearEstimates
{
    /** Profile of a year without a saved one. */
    private const ASSUMED_PROFILE = ['activity_rate' => 60];

    public function __construct(
        private readonly TaxProfileRepository $profiles,
        private readonly TaxConstantsRepository $constants,
        private readonly TaxOptimizer $optimizer,
        private readonly InsuranceAdvanceAmounts $advances,
    ) {}

    /**
     * The estimate of a year is read until the end of the income tax advance period based on it (the deadline of the
     * next year's return, which is also after the insurance advances of the next year) and until its last surcharge is
     * due; a year past both is skipped, so a wide range does not estimate years nothing uses.
     *
     * @param array<int, array{regime:string, reason:?string, income:?float, band:?string}> $regimes {@see TaxYearFacts::regimes()}
     * @param list<array<string,mixed>> $orders standing orders of the supplier
     * @return array<int, array<string,mixed>> estimates by year (years that have one)
     */
    public function forYears(int $supplierId, array $regimes, VatStatusHistory $history, \DateTimeImmutable $now, string $from, array $orders): array
    {
        $currentYear = (int) $now->format('Y');
        $today = $now->format('Y-m-d');
        $out = [];
        foreach ($regimes as $year => $facts) {
            if ($facts['regime'] === TaxYearFacts::FLAT || $year > $currentYear
                || (ReportPeriod::incomeTaxElectronicDeadline($year + 1) < $from && ReportPeriod::insuranceSurchargeDeadline($year) < $today)) {
                continue;
            }
            $estimate = $this->estimate($supplierId, $year, $facts, $year < $currentYear, $history, $orders);
            if ($estimate !== null) {
                $out[$year] = $estimate;
            }
        }
        return $out;
    }

    /**
     * Amount and notes an item gets from the estimate of its year: the surcharge of the component (`income_tax`,
     * `social`, `health`) as an estimated amount, an overpayment as a note. Without an estimate the item keeps its own.
     *
     * @param array<string,mixed>|null $estimate
     * @return array{amount: ?float, amount_estimated: bool, notes: list<array{code:string, params?:array<string,mixed>}>}
     */
    public static function itemOptions(?array $estimate, string $component): array
    {
        if ($estimate === null) {
            return ['amount' => null, 'amount_estimated' => false, 'notes' => []];
        }
        $surcharge = $estimate[$component]['surcharge'];
        $notes = self::basisNotes($estimate);
        if ($surcharge < 0) {
            $notes[] = ['code' => 'estimate_overpaid', 'params' => ['diff' => -$surcharge]];
        }
        return ['amount' => (float) max(0, $surcharge), 'amount_estimated' => true, 'notes' => $notes];
    }

    /**
     * What the estimate is based on, as notes: the income, then the caveats of {@see caveatNotes()}.
     *
     * @param array<string,mixed> $estimate
     * @return list<array{code:string, params:array<string,mixed>}>
     */
    private static function basisNotes(array $estimate): array
    {
        $income = $estimate['income'];
        $basis = $estimate['closed']
            ? ['code' => 'estimate_basis_closed', 'params' => ['year' => $estimate['year'], 'income' => $income['total']]]
            : ['code' => 'estimate_basis', 'params' => ['year' => $estimate['year'], 'income' => $income['total'], 'paid' => $income['paid'], 'unpaid' => $income['unpaid']]];
        return [$basis, ...self::caveatNotes($estimate)];
    }

    /**
     * What the estimate had to assume: a profile of the year that is not saved, the advances that are not known.
     *
     * @param array<string,mixed> $estimate
     * @return list<array{code:string, params:array<string,mixed>}>
     */
    public static function caveatNotes(array $estimate): array
    {
        $notes = [];
        if ($estimate['assumed_profile']) {
            $notes[] = self::noProfileNote($estimate['year']);
        }
        if ($estimate['advances_assumed']) {
            $notes[] = ['code' => 'estimate_advances_assumed', 'params' => ['year' => $estimate['year']]];
        }
        return $notes;
    }

    /** @return array{code:string, params:array{year:int}} the year's tax profile is not saved, a default one is assumed */
    public static function noProfileNote(int $year): array
    {
        return ['code' => 'estimate_no_profile', 'params' => ['year' => $year]];
    }

    /**
     * @param array{regime:string, reason:?string, income:?float, band:?string} $facts
     * @param list<array<string,mixed>> $orders
     * @return array<string,mixed>|null
     */
    private function estimate(int $supplierId, int $year, array $facts, bool $closed, VatStatusHistory $history, array $orders): ?array
    {
        $profile = $this->profiles->find($supplierId, $year);
        if ($closed && $profile === null) {
            return null;
        }
        // A year that lost the regime by income already has its paid income worked out.
        $paid = $facts['income'] ?? $this->profiles->annualIncome($supplierId, $year);
        $unpaid = $closed ? 0.0 : $this->profiles->unpaidIncomeDueInYear($supplierId, $year);
        if ($paid + $unpaid <= 0) {
            return null;
        }

        $constants = $this->constants->forYear($year);
        $liability = $this->optimizer->compare(
            ($profile ?? self::ASSUMED_PROFILE) + ['is_vat_payer' => $history->hasPayerTimeBetween("$year-01-01", "$year-12-31")],
            $paid + $unpaid,
            $constants,
        )['regular'];

        $lost = $facts['regime'] === TaxYearFacts::LOST;
        if ($lost) {
            $months = $this->flatTaxMonths($year, $facts, $history);
            $credited = FlatTaxParts::credited($year, (string) $facts['band'], $constants, $months);
            $standing = $this->standingPayments($orders, $year, (string) $facts['band'], $constants, $months);
        } else {
            $months = $standing = null;
            $credited = $this->minimumAdvances($supplierId, $year);
        }

        return [
            'year'             => $year,
            'closed'           => $closed,
            'regime'           => $facts['regime'],
            'reason'           => $facts['reason'],
            'assumed_profile'  => $profile === null,
            'advances_assumed' => !$lost,
            'income'           => ['paid' => (int) round($paid), 'unpaid' => (int) round($unpaid), 'total' => (int) round($paid + $unpaid)],
            'expenses'         => (int) $liability['expenses'],
            'income_tax'       => self::component($liability['income_tax'], $credited['income_tax'], ReportPeriod::incomeTaxElectronicDeadline($year)),
            'social'           => self::component($liability['social'], $credited['social'], ReportPeriod::insuranceSurchargeDeadline($year)),
            'health'           => self::component($liability['health'], $credited['health'], ReportPeriod::insuranceSurchargeDeadline($year)),
            'credited_months'  => $months === null ? null : count($months),
            'standing'         => $standing,
        ];
    }

    /** @return array{expected:int, credited:int, surcharge:int, due_date:string} */
    private static function component(float|int $expected, int $credited, string $dueDate): array
    {
        return ['expected' => (int) $expected, 'credited' => $credited, 'surcharge' => (int) $expected - $credited, 'due_date' => $dueDate];
    }

    /**
     * Months of a lost year in which the flat tax was paid: all of them when the income passed the limit (the advances
     * go on until the year end), the months before the VAT payer status when that ended the regime.
     *
     * @param array{reason:?string} $facts
     * @return list<int>
     */
    private function flatTaxMonths(int $year, array $facts, VatStatusHistory $history): array
    {
        $months = range(1, 12);
        if ($facts['reason'] !== TaxYearFacts::REASON_VAT_PAYER) {
            return $months;
        }
        return array_values(array_filter($months, static function (int $month) use ($year, $history): bool {
            $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            return !$history->hasPayerTimeBetween($first->format('Y-m-d'), $first->format('Y-m-t'));
        }));
    }

    /** @return array{income_tax:int, social:int, health:int} twelve minimum advances, no income tax advances */
    private function minimumAdvances(int $supplierId, int $year): array
    {
        $minimum = $this->advances->minimum($supplierId, $year);
        return ['income_tax' => 0, 'social' => (int) (12 * ($minimum['social'] ?? 0)), 'health' => (int) (12 * ($minimum['health'] ?? 0))];
    }

    /**
     * What the standing orders for the flat tax pay in the months of the year (the ones to come included) and how much
     * of it is more than the payment due.
     *
     * @param list<array<string,mixed>> $orders
     * @param array<string,mixed> $constants
     * @param list<int> $months
     * @return array{months:int, paid:int, overpaid:int}|null null without a covering order
     */
    private function standingPayments(array $orders, int $year, string $band, array $constants, array $months): ?array
    {
        $segments = PausalSchedule::normalize($constants['pausal_monthly'] ?? []);
        $covered = 0;
        $paid = 0.0;
        $overpaid = 0.0;
        foreach ($months as $month) {
            $match = StandingOrders::match($orders, 'pausal', TaxCalendarService::pausalDueDate($year, $month));
            if ($match === null) {
                continue;
            }
            $covered++;
            $paid += $match['amount'];
            $overpaid += max(0.0, $match['amount'] - (float) (PausalSchedule::monthlyAt($year, $month, $segments)[$band] ?? 0));
        }
        return $covered === 0 ? null : ['months' => $covered, 'paid' => (int) round($paid), 'overpaid' => (int) round($overpaid)];
    }
}
