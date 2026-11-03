<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\LateInterest;

use MyInvoice\Service\LateInterest\LateInterestCalculator;
use MyInvoice\Service\LateInterest\LateInterestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hand-computed expectations (see the formula in each comment). The repo history is the official CNB
 * list https://www.cnb.cz/cs/casto-kladene-dotazy/Jak-se-vyvijela-dvoutydenni-repo-sazba-CNB/
 */
final class LateInterestCalculatorTest extends TestCase
{
    /** @var array<string, float> valid_from => repo % */
    private const REPO = [
        '2012-11-02' => 0.05, '2017-08-04' => 0.25, '2017-11-03' => 0.50, '2018-02-02' => 0.75,
        '2018-06-28' => 1.00, '2018-08-03' => 1.25, '2018-09-27' => 1.50, '2018-11-02' => 1.75,
        '2019-05-03' => 2.00, '2020-02-07' => 2.25, '2020-03-17' => 1.75, '2020-03-27' => 1.00,
        '2020-05-11' => 0.25, '2021-06-24' => 0.50, '2021-08-06' => 0.75, '2021-10-01' => 1.50,
        '2021-11-05' => 2.75, '2021-12-23' => 3.75, '2022-02-04' => 4.50, '2022-04-01' => 5.00,
        '2022-05-06' => 5.75, '2022-06-23' => 7.00, '2023-12-22' => 6.75, '2024-02-09' => 6.25,
        '2024-03-21' => 5.75, '2024-05-03' => 5.25, '2024-06-28' => 4.75, '2024-08-02' => 4.50,
        '2024-09-26' => 4.25, '2024-11-08' => 4.00, '2025-02-07' => 3.75, '2025-05-09' => 3.50,
        '2026-06-19' => 3.75,
    ];

    private LateInterestCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new LateInterestCalculator();
    }

    /** @return array{valid_from: string, rate_percent: float}|null */
    private static function repoOn(string $date): ?array
    {
        $found = null;
        foreach (self::REPO as $from => $rate) {
            if ($from <= $date) {
                $found = ['valid_from' => $from, 'rate_percent' => $rate];
            }
        }
        return $found;
    }

    /** @param list<array{date: string, amount: float}> $payments */
    private function interest(float $principal, string $due, string $asOf, array $payments = [], ?string $billFrom = null): array
    {
        return $this->calc->calculate($principal, $due, $payments, $asOf, self::repoOn(...), $billFrom);
    }

    // ───────────── rate: repo rate of the half-year in which the delay began, + 8 p.p. ─────────────

    #[DataProvider('halfYearBoundaries')]
    public function testRateIsTheRepoRateOfTheHalfYearInWhichTheDelayBegan(string $due, string $halfYearStart, float $repo): void
    {
        $r = $this->interest(10000.0, $due, '2030-01-01');

        self::assertSame($halfYearStart, $r['repo']['half_year_start']);
        self::assertSame($repo, $r['repo']['rate_percent']);
        self::assertSame($repo + 8.0, $r['annual_rate_percent']);
    }

    public static function halfYearBoundaries(): array
    {
        return [
            // The delay begins the day AFTER the due date.
            'due 29.6.2024 -> delay 30.6. = still H1, repo of 1.1.2024 (6.75)'  => ['2024-06-29', '2024-01-01', 6.75],
            'due 30.6.2024 -> delay 1.7. = H2, repo of 1.7.2024 (4.75)'         => ['2024-06-30', '2024-07-01', 4.75],
            'due 30.12.2023 -> delay 31.12. = H2 2023, repo of 1.7.2023 (7.00)' => ['2023-12-30', '2023-07-01', 7.00],
            'due 31.12.2023 -> delay 1.1.2024 = H1, repo of 1.1.2024 (6.75)'    => ['2023-12-31', '2024-01-01', 6.75],
            'due 29.6.2026, rate rose on 19.6.2026 -> H1 still 3.50'            => ['2026-06-29', '2026-01-01', 3.50],
            'due 30.6.2026 -> H2 2026 = 3.75 after the 19.6.2026 increase'      => ['2026-06-30', '2026-07-01', 3.75],
        ];
    }

    public function testRateStaysFixedForTheWholeDelayEvenAcrossHalfYearsAndRateChanges(): void
    {
        // Delay begins 2.6.2026 (H1 2026, repo 3.50 -> 11.50 %). The CNB raised the rate to 3.75 on
        // 19.6.2026 and a new half-year starts on 1.7.2026, but the rate of this delay does not change.
        // 2.6. - 30.9.2026 = 29 + 31 + 31 + 30 = 121 days: 10 000 * 0.115 * 121 / 365 = 381.23
        $r = $this->interest(10000.0, '2026-06-01', '2026-09-30');

        self::assertSame(11.5, $r['annual_rate_percent']);
        self::assertSame(121, $r['days']);
        self::assertSame(381.23, $r['interest']);
        self::assertCount(1, $r['segments']);
    }

    public function testExampleFromTheCnbPageRepo005GivesRate805(): void
    {
        // https://www.cnb.cz/cs/casto-kladene-dotazy/Vypocet-uroku-z-prodleni : repo 0.05 % -> 8.05 %
        $r = $this->interest(1000.0, '2013-12-31', '2014-01-31');

        self::assertSame(8.05, $r['annual_rate_percent']);
        self::assertSame('2012-11-02', $r['repo']['valid_from']);
    }

    // ───────────── day count ─────────────

    public function testInterestAccruesFromTheDayAfterTheDueDateAndCountsAsOfInclusive(): void
    {
        // due 15.9.2026, as of 16.9.2026 = 1 day: 10 000 * 0.1175 / 365 = 3.2192 -> 3.22
        $r = $this->interest(10000.0, '2026-09-15', '2026-09-16');
        self::assertSame(1, $r['days']);
        self::assertSame('2026-09-16', $r['accrual_from']);
        self::assertSame(3.22, $r['interest']);

        // The number of accrued days equals the "days overdue" a reminder shows (as_of - due).
        $r = $this->interest(10000.0, '2026-09-15', '2026-11-03');
        self::assertSame(49, $r['days']);
        self::assertSame(157.74, $r['interest']); // 10 000 * 0.1175 * 49 / 365
    }

    public function testNoInterestOnOrBeforeTheDueDate(): void
    {
        foreach (['2026-09-15', '2026-09-01'] as $asOf) {
            $r = $this->interest(10000.0, '2026-09-15', $asOf);
            self::assertSame(0, $r['days']);
            self::assertSame(0.0, $r['interest']);
            self::assertSame([], $r['segments']);
            self::assertNull($r['annual_rate_percent']);
        }
    }

    public function testLeapYearUses366AndANormalYear365(): void
    {
        // 2024 (leap): due 14.2. -> delay 15.2.-15.3. = 15 (Feb has 29 days) + 15 = 30 days, rate 6.75 + 8 = 14.75 %
        // 10 000 * 0.1475 * 30 / 366 = 120.90
        $leap = $this->interest(10000.0, '2024-02-14', '2024-03-15');
        self::assertSame(366, $leap['segments'][0]['year_days']);
        self::assertSame(30, $leap['days']);
        self::assertSame(120.90, $leap['interest']);

        // 2025: delay 15.2.-15.3. = 14 + 15 = 29 days, repo 4.00 + 8 = 12 %: 10 000 * 0.12 * 29 / 365 = 95.34
        $normal = $this->interest(10000.0, '2025-02-14', '2025-03-15');
        self::assertSame(365, $normal['segments'][0]['year_days']);
        self::assertSame(29, $normal['days']);
        self::assertSame(95.34, $normal['interest']);
    }

    public function testDelayCrossingNewYearIsSplitAndTheTotalIsTheSumOfTheRoundedSegments(): void
    {
        // Delay begins 21.12.2023 (H2 2023, repo 7.00 -> 15 %), as of 10.1.2024.
        // 21.-31.12.2023 = 11 days / 365: 10 000 * 0.15 * 11 / 365 = 45.21
        // 1.-10.1.2024   = 10 days / 366: 10 000 * 0.15 * 10 / 366 = 40.98
        $r = $this->interest(10000.0, '2023-12-20', '2024-01-10');

        self::assertCount(2, $r['segments']);
        self::assertSame(['2023-12-21', '2023-12-31', 11, 365, 45.21], [
            $r['segments'][0]['from'], $r['segments'][0]['to'], $r['segments'][0]['days'], $r['segments'][0]['year_days'], $r['segments'][0]['interest'],
        ]);
        self::assertSame(['2024-01-01', '2024-01-10', 10, 366, 40.98], [
            $r['segments'][1]['from'], $r['segments'][1]['to'], $r['segments'][1]['days'], $r['segments'][1]['year_days'], $r['segments'][1]['interest'],
        ]);
        self::assertSame(21, $r['days']);
        self::assertSame(86.19, $r['interest']);
        self::assertSame(15.0, $r['annual_rate_percent'], 'the rate is not re-fixed on 1.1.2024');
    }

    // ───────────── payments ─────────────

    public function testPartialPaymentLowersTheBalanceFromTheDayAfterThePayment(): void
    {
        // Rate 11.75 %. 16.9.-10.10. = 25 days on 10 000 (the payment day still accrues on the old balance):
        //   10 000 * 0.1175 * 25 / 365 = 80.48
        // 11.10.-3.11. = 24 days on 6 000: 6 000 * 0.1175 * 24 / 365 = 46.36
        $r = $this->interest(10000.0, '2026-09-15', '2026-11-03', [['date' => '2026-10-10', 'amount' => 4000.0]]);

        self::assertCount(2, $r['segments']);
        self::assertSame([25, 10000.0, 80.48], [$r['segments'][0]['days'], $r['segments'][0]['balance'], $r['segments'][0]['interest']]);
        self::assertSame([24, 6000.0, 46.36], [$r['segments'][1]['days'], $r['segments'][1]['balance'], $r['segments'][1]['interest']]);
        self::assertSame(126.84, $r['interest']);
        self::assertSame(49, $r['days']);
        self::assertSame(6000.0, $r['outstanding']);
        self::assertSame(11.75, $r['annual_rate_percent'], 'one rate for the remainder as well');
    }

    public function testPaymentMadeOnTheDueDateReducesTheBalanceFromTheFirstDayOfTheDelay(): void
    {
        // 7 000 outstanding for 49 days: 7 000 * 0.1175 * 49 / 365 = 110.42
        $r = $this->interest(10000.0, '2026-09-15', '2026-11-03', [['date' => '2026-09-15', 'amount' => 3000.0]]);

        self::assertSame(110.42, $r['interest']);
        self::assertSame(7000.0, $r['segments'][0]['balance']);
    }

    public function testFullPaymentStopsTheInterestAndReportsTheSettlementDate(): void
    {
        // Paid in full on 5.10.: 16.9.-5.10. = 15 + 5 = 20 days: 10 000 * 0.1175 * 20 / 365 = 64.38
        $r = $this->interest(10000.0, '2026-09-15', '2026-11-03', [['date' => '2026-10-05', 'amount' => 10000.0]]);

        self::assertSame(20, $r['days']);
        self::assertSame(64.38, $r['interest']);
        self::assertSame('2026-10-05', $r['accrual_to']);
        self::assertSame('2026-10-05', $r['settled_on']);
        self::assertSame(0.0, $r['outstanding']);
    }

    public function testPaidOneDayLateCostsOneDayOfInterest(): void
    {
        $r = $this->interest(10000.0, '2026-09-15', '2026-11-03', [['date' => '2026-09-16', 'amount' => 10000.0]]);

        self::assertSame(1, $r['days']);
        self::assertSame(3.22, $r['interest']);
    }

    public function testPaidOnTimeHasNoInterest(): void
    {
        $r = $this->interest(10000.0, '2026-09-15', '2026-11-03', [['date' => '2026-09-15', 'amount' => 10000.0]]);

        self::assertSame(0, $r['days']);
        self::assertSame(0.0, $r['interest']);
    }

    public function testRemainderWithinThePaidToleranceCountsAsSettled(): void
    {
        // Same 0.05 tolerance that flips an invoice to "paid".
        $r = $this->interest(10000.0, '2026-09-15', '2026-11-03', [['date' => '2026-10-05', 'amount' => 9999.97]]);

        self::assertSame('2026-10-05', $r['settled_on']);
        self::assertSame(64.38, $r['interest']);
    }

    public function testPaymentAfterTheAsOfDateIsIgnored(): void
    {
        $r = $this->interest(10000.0, '2026-09-15', '2026-09-30', [['date' => '2026-10-05', 'amount' => 10000.0]]);

        self::assertSame(15, $r['days']);
        self::assertSame(10000.0, $r['outstanding']);
    }

    // ───────────── currency ─────────────

    public function testAmountsStayInTheInvoiceCurrencyWithoutConversion(): void
    {
        // 1 000 EUR, 49 days at 11.75 %: 1 000 * 0.1175 * 49 / 365 = 15.77 (EUR)
        $r = $this->interest(1000.0, '2026-09-15', '2026-11-03');

        self::assertSame(15.77, $r['interest']);
        self::assertSame(1000.0, $r['principal']);
    }

    // ───────────── billing window ─────────────

    public function testBillFromReportsOnlyTheLaterDaysButKeepsTheBalanceAndRate(): void
    {
        $payments = [['date' => '2026-10-10', 'amount' => 4000.0]];

        $second = $this->interest(10000.0, '2026-09-15', '2026-11-03', $payments, '2026-10-11');
        self::assertCount(1, $second['segments']);
        self::assertSame(46.36, $second['interest']);
        self::assertSame('2026-10-11', $second['accrual_from']);
        self::assertSame(6000.0, $second['segments'][0]['balance']);

        // From the middle of a segment: 1.-10.10. = 10 days on 10 000 -> 32.19
        $mid = $this->interest(10000.0, '2026-09-15', '2026-10-10', $payments, '2026-10-01');
        self::assertSame(10, $mid['days']);
        self::assertSame(32.19, $mid['interest']);
        self::assertSame(11.75, $mid['annual_rate_percent']);
    }

    // ───────────── out of scope / missing data ─────────────

    public function testDelaysBeforeTheRegulationAreRefused(): void
    {
        $this->expectException(LateInterestException::class);
        $this->interest(1000.0, '2013-12-30', '2014-02-01'); // the delay began on 31.12.2013 (NV 142/1994 applies)
    }

    public function testMissingRepoRateIsAnError(): void
    {
        $this->expectException(LateInterestException::class);
        $this->calc->calculate(1000.0, '2026-09-15', [], '2026-11-03', static fn (string $d): ?array => null);
    }

    public function testHalfYearStart(): void
    {
        self::assertSame('2026-01-01', LateInterestCalculator::halfYearStart('2026-06-30'));
        self::assertSame('2026-07-01', LateInterestCalculator::halfYearStart('2026-07-01'));
        self::assertSame('2026-01-01', LateInterestCalculator::halfYearStart('2026-01-01'));
        self::assertSame('2026-07-01', LateInterestCalculator::halfYearStart('2026-12-31'));
    }

    public function testRegulationConstants(): void
    {
        self::assertSame(8.0, LateInterestCalculator::ADDITION_POINTS);
        self::assertSame(1200.0, LateInterestCalculator::FIXED_COST_CZK);
    }
}
