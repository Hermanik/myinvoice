<?php

declare(strict_types=1);

namespace MyInvoice\Service\LateInterest;

use DateTimeImmutable;
use MyInvoice\Service\Invoice\InvoicePaymentService;

/**
 * Statutory late-payment interest (úrok z prodlení) on an overdue invoice. Pure computation: it
 * knows nothing about the database, the rate lookup is injected.
 *
 * Sources
 *  - Civil Code (Act No. 89/2012 Sb.) § 1970: the creditor of a money debt in delay may claim
 *    interest; its amount is set by a government regulation and applies unless the parties agreed
 *    otherwise. https://www.zakonyprolidi.cz/cs/2012-89
 *  - Government regulation No. 351/2013 Sb. § 2(1): "Výše úroku z prodlení odpovídá ročně výši repo
 *    sazby stanovené Českou národní bankou pro první den kalendářního pololetí, v němž došlo
 *    k prodlení, zvýšené o 8 procentních bodů." https://www.zakonyprolidi.cz/cs/2013-351
 *    The regulation applies to delays that began on or after 2014-01-01 (§ 19; earlier delays follow
 *    NV 142/1994 Sb., which this class does not model).
 *  - § 3 of the same regulation: between entrepreneurs the minimum cost of claiming each receivable
 *    is CZK 1,200 (a fixed amount, Civil Code § 1972).
 *
 * Rules implemented
 *  1. The rate is fixed for the WHOLE delay: the repo rate valid on the first day of the calendar
 *     half-year in which the delay began (the day after the due date) + 8 percentage points. A later
 *     CNB rate change, or the delay running into the next half-year, does not change it (the
 *     regulation names a single reference day; the CNB explains the rule the same way:
 *     https://www.cnb.cz/cs/casto-kladene-dotazy/Vypocet-uroku-z-prodleni).
 *  2. Interest accrues from the day AFTER the due date. Each day accrues on the balance outstanding
 *     at the start of that day, so a payment dated D still accrues for day D (the debtor was late
 *     until it arrived) and reduces the balance from day D+1. An unpaid invoice therefore accrues
 *     exactly `as_of - due_date` days — the number shown as "days overdue" in a reminder.
 *  3. Day count: the regulation only says "ročně" (per annum) and prescribes no day-count
 *     convention. We use the actual number of days over the actual length of the calendar year
 *     (365, or 366 in a leap year); a delay crossing New Year is split there. This is the common
 *     practice, NOT something the regulation spells out.
 *  4. Partial payments: every payment lowers the balance for the following days; the same rate
 *     applies to the remainder. A balance within InvoicePaymentService::TOLERANCE of zero counts as
 *     settled (the same tolerance that flips an invoice to "paid"). Payments are applied to the
 *     principal only. Civil Code § 1932(1) would apply a business debtor's payment to the costs, then
 *     the default interest and only then the principal (a consumer's: costs, principal, interest, default
 *     interest, § 1932(2)); treating it as principal-only is a deliberate simplification in the debtor's
 *     favour for businesses. https://www.zakonyprolidi.cz/cs/2012-89
 *  5. Currency: computed in the invoice currency on the invoice amounts. The statutory rate is a
 *     Czech-law rate and is applied as is to a foreign-currency receivable. The CZK fixed cost is
 *     converted by the caller.
 *  6. Rounding: every segment (constant balance within one calendar year) is rounded to 2 decimals
 *     and the total is the sum of the rounded segments, so the document always adds up.
 */
final class LateInterestCalculator
{
    /** § 2(1) NV 351/2013 Sb. */
    public const ADDITION_POINTS = 8.0;

    /** § 3 NV 351/2013 Sb. — minimum fixed cost of claiming a receivable between entrepreneurs, CZK. */
    public const FIXED_COST_CZK = 1200.0;

    /** NV 351/2013 Sb. took effect on this date; delays that began earlier are out of scope. */
    public const REGULATION_EFFECTIVE = '2014-01-01';

    /** First day of the calendar half-year containing $date (Y-m-d). */
    public static function halfYearStart(string $date): string
    {
        $d = new DateTimeImmutable($date);
        return $d->format('Y') . '-' . ((int) $d->format('n') <= 6 ? '01' : '07') . '-01';
    }

    /**
     * @param float                                        $principal   amount to pay on the invoice (invoice currency)
     * @param string                                       $dueDate     Y-m-d; the delay begins the day after
     * @param list<array{date: string, amount: float}>     $payments    payments received against the invoice
     * @param string                                       $asOf        Y-m-d; last day (inclusive) to accrue
     * @param callable(string): (array{valid_from: string, rate_percent: float}|null) $repoRateOn
     *        the CNB 2T repo rate in force on the given date
     * @param string|null                                  $billFrom    only report days from this date on (earlier
     *        days are still needed for the balance); null = from the first day of the delay
     *
     * @return array{
     *   principal: float, due_date: string, as_of: string, delay_from: string,
     *   accrual_from: ?string, accrual_to: ?string, days: int, interest: float, outstanding: float,
     *   settled_on: ?string, annual_rate_percent: ?float,
     *   repo: ?array{rate_percent: float, valid_from: string, half_year_start: string},
     *   segments: list<array{from: string, to: string, days: int, balance: float, year_days: int, interest: float}>
     * }
     * @throws LateInterestException when the delay predates the regulation or no repo rate is known
     */
    public function calculate(
        float $principal,
        string $dueDate,
        array $payments,
        string $asOf,
        callable $repoRateOn,
        ?string $billFrom = null,
    ): array {
        $delayFrom = (new DateTimeImmutable($dueDate))->modify('+1 day')->format('Y-m-d');
        $outstanding = self::balanceAfter($principal, $payments, $asOf, true);

        $result = [
            'principal'           => round($principal, 2),
            'due_date'            => $dueDate,
            'as_of'               => $asOf,
            'delay_from'          => $delayFrom,
            'accrual_from'        => null,
            'accrual_to'          => null,
            'days'                => 0,
            'interest'            => 0.0,
            'outstanding'         => $outstanding,
            'settled_on'          => null,
            'annual_rate_percent' => null,
            'repo'                => null,
            'segments'            => [],
        ];

        if ($asOf < $delayFrom) {
            return $result;
        }
        if ($delayFrom < self::REGULATION_EFFECTIVE) {
            throw new LateInterestException(
                'Úrok z prodlení podle nařízení vlády č. 351/2013 Sb. lze počítat jen u prodlení vzniklého od 1. 1. 2014.'
            );
        }

        $halfYear = self::halfYearStart($delayFrom);
        $repo = $repoRateOn($halfYear);
        if ($repo === null) {
            throw new LateInterestException("V číselníku repo sazeb ČNB chybí sazba platná k {$halfYear}.");
        }
        $annual = round($repo['rate_percent'] + self::ADDITION_POINTS, 2);
        $result['annual_rate_percent'] = $annual;
        $result['repo'] = [
            'rate_percent'    => $repo['rate_percent'],
            'valid_from'      => $repo['valid_from'],
            'half_year_start' => $halfYear,
        ];

        $outputFrom = $billFrom !== null && $billFrom > $delayFrom ? $billFrom : $delayFrom;
        $boundaries = $this->boundaries($delayFrom, $asOf, $payments);

        $start = $delayFrom;
        foreach ($boundaries as $next) {
            $end = (new DateTimeImmutable($next))->modify('-1 day')->format('Y-m-d');
            $balance = self::balanceAfter($principal, $payments, $start, false);
            if ($balance <= InvoicePaymentService::TOLERANCE) {
                $result['settled_on'] = self::lastPaymentDate($payments, $start);
                break;
            }
            $from = $start < $outputFrom ? $outputFrom : $start;
            if ($from <= $end) {
                $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($end))->days + 1;
                $yearDays = self::daysInYear((int) substr($from, 0, 4));
                $amount = round($balance * $annual / 100 * $days / $yearDays, 2);
                $result['segments'][] = [
                    'from'      => $from,
                    'to'        => $end,
                    'days'      => $days,
                    'balance'   => round($balance, 2),
                    'year_days' => $yearDays,
                    'interest'  => $amount,
                ];
                $result['days'] += $days;
                $result['interest'] = round($result['interest'] + $amount, 2);
            }
            $start = $next;
        }

        if ($result['segments'] !== []) {
            $result['accrual_from'] = $result['segments'][0]['from'];
            $result['accrual_to'] = $result['segments'][count($result['segments']) - 1]['to'];
        }
        return $result;
    }

    /**
     * Days on which the balance or the day-count basis changes, ascending, ending with the sentinel
     * `as_of + 1 day`. A segment runs from one boundary (inclusive) to the next (exclusive).
     *
     * @param list<array{date: string, amount: float}> $payments
     * @return list<string>
     */
    private function boundaries(string $delayFrom, string $asOf, array $payments): array
    {
        $sentinel = (new DateTimeImmutable($asOf))->modify('+1 day')->format('Y-m-d');
        $set = [$sentinel => true];

        foreach ($payments as $p) {
            $effective = (new DateTimeImmutable($p['date']))->modify('+1 day')->format('Y-m-d');
            if ($effective > $delayFrom && $effective < $sentinel) {
                $set[$effective] = true;
            }
        }
        for ($y = (int) substr($delayFrom, 0, 4) + 1; $y <= (int) substr($asOf, 0, 4); $y++) {
            $set["{$y}-01-01"] = true;
        }

        $days = array_keys($set);
        sort($days);
        return $days;
    }

    /**
     * Outstanding amount: the principal less the payments dated before $date, or up to and
     * including $date when $inclusive is set.
     *
     * @param list<array{date: string, amount: float}> $payments
     */
    private static function balanceAfter(float $principal, array $payments, string $date, bool $inclusive): float
    {
        $paid = 0.0;
        foreach ($payments as $p) {
            if ($p['date'] < $date || ($inclusive && $p['date'] === $date)) {
                $paid += $p['amount'];
            }
        }
        return max(0.0, round($principal - $paid, 2));
    }

    /**
     * Date of the latest payment before $before (the one that settled the invoice).
     *
     * @param list<array{date: string, amount: float}> $payments
     */
    private static function lastPaymentDate(array $payments, string $before): ?string
    {
        $last = null;
        foreach ($payments as $p) {
            if ($p['date'] < $before && ($last === null || $p['date'] > $last)) {
                $last = $p['date'];
            }
        }
        return $last;
    }

    private static function daysInYear(int $year): int
    {
        return (new DateTimeImmutable("{$year}-12-31"))->format('z') + 1;
    }
}
