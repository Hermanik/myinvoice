<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Repository\ObligationTimelineRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Tax\PausalSchedule;
use MyInvoice\Service\Tax\TaxCalendarService;
use MyInvoice\Service\Tax\TaxOptimizer;
use MyInvoice\Service\Timeline\ObligationTimelineService;
use MyInvoice\Tests\Support\FilingFixture;
use MyInvoice\Tests\Support\TimelinePaymentsState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Standing orders, the invoicing deadline and the estimates of a year without the flat-tax regime on the obligations
 * timeline, on synthetic data of the years 2096-2099. A natural person in flat-tax band 1 invoices 2 100 000 Kč paid in
 * 2097 (over the 2 000 000 Kč limit: the regime is lost) and becomes a monthly VAT payer on 1. 1. 2098; 2097-11-10 is
 * "today" unless a test says otherwise. Invoices made by the fixture are due on their DUZP.
 */
#[Group('integration')]
final class TimelinePaymentsTest extends TestCase
{
    private const NOW = '2097-11-10';
    private const FROM = '2097-10-01';
    private const TO = '2098-12-31';

    private ?FilingFixture $fx = null;
    private ?TimelinePaymentsState $payments = null;
    private ObligationTimelineService $timeline;
    private ObligationTimelineRepository $repo;
    private TaxProfileRepository $profiles;
    private int $client = 0;
    private int $invoiceNo = 0;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $c = $this->fx->container;
        $this->timeline = $c->get(ObligationTimelineService::class);
        $this->repo = $c->get(ObligationTimelineRepository::class);
        $this->profiles = $c->get(TaxProfileRepository::class);
        if (!$this->repo->isAvailable() || !$this->repo->hasPaymentTables()) {
            self::markTestSkipped('Migrace 9011 / 9012 (obligations timeline) není aplikovaná.');
        }
        $this->payments = new TimelinePaymentsState($this->fx->pdo, $this->fx->supplierId);
        $this->payments->stash();
        $this->fx->configureSupplier(['taxpayer_type' => 'fo', 'vat_period' => 'monthly', 'flat_tax_band' => 'band1']);
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2098-01-01', 'payer']]);
        $this->client = $this->fx->client('Český odběratel');
    }

    protected function tearDown(): void
    {
        if ($this->fx !== null) {
            $sid = $this->fx->supplierId;
            $this->fx->pdo->prepare('DELETE FROM tax_profiles WHERE supplier_id = ? AND year BETWEEN 2096 AND 2099')->execute([$sid]);
            $this->payments?->restore();
            $this->fx->pdo->prepare("DELETE FROM timeline_marks WHERE supplier_id = ? AND item_key REGEXP ':(2096|2097|2098|2099)'")->execute([$sid]);
            $this->fx->restore();
        }
    }

    /** @return array<string,mixed> */
    private function build(string $now = self::NOW, string $from = self::FROM, string $to = self::TO): array
    {
        return $this->timeline->build($this->fx->supplierId, new \DateTimeImmutable($now), $from, $to);
    }

    /** @return array<string, array<string,mixed>> items keyed by their key */
    private function items(string $now = self::NOW, string $from = self::FROM, string $to = self::TO): array
    {
        return array_column($this->build($now, $from, $to)['items'], null, 'key');
    }

    /** A sale (no VAT) of the given DUZP and due date; paid on `$paidAt` or in the given unpaid status. */
    private function sale(string $date, float $amount, ?string $paidAt = null, string $status = 'issued'): int
    {
        $id = $this->fx->sale(sprintf('%d%05d', substr($date, 0, 4), ++$this->invoiceNo), $this->client, $date, $amount, 0, 0, '1', $paidAt === null ? $status : 'paid');
        if ($paidAt !== null) {
            $this->fx->pdo->prepare('UPDATE invoices SET paid_at = ? WHERE id = ?')->execute([$paidAt . ' 10:00:00', $id]);
        }
        return $id;
    }

    /** 2 100 000 Kč paid in 2097: the flat-tax regime is lost for the year. */
    private function lostYear(): void
    {
        $this->sale('2097-03-05', 900000, '2097-03-20');
        $this->sale('2097-06-10', 900000, '2097-06-30');
        $this->sale('2097-10-01', 300000, '2097-10-05');
    }

    private function order(string $type, float $amount, int $day = 12, string $from = '2097-01-01', ?string $to = null): int
    {
        return $this->repo->createStandingOrder($this->fx->supplierId, [
            'type' => $type, 'amount' => $amount, 'day_of_month' => $day, 'valid_from' => $from, 'valid_to' => $to, 'note' => null,
        ], $this->fx->userId);
    }

    /** @return array<string,mixed> */
    private function constants(int $year = 2097): array
    {
        return $this->fx->container->get(TaxConstantsRepository::class)->forYear($year);
    }

    // ── standing orders ─────────────────────────────────────────────────────

    public function testAPastPaymentOfAStandingOrderMakesTheAdvanceDone(): void
    {
        $this->lostYear();
        $this->order('pausal', 9984.0);

        $october = $this->items()['pausal:2097-10'];

        self::assertSame(TaxCalendarService::pausalDueDate(2097, 10), $october['due_date'], 'the 20th, moved to a working day');
        self::assertSame('done', $october['status']);
        self::assertSame('standing_order', $october['done_source']);
        self::assertTrue($october['tracked']);
        self::assertSame('2097-10-12', $october['params']['paid_on']);
        self::assertSame(9984.0, $october['params']['paid_amount']);
        self::assertSame(2097, $october['params']['year'], 'the params of the item stay');
        self::assertNull($october['mark']);
    }

    public function testAFuturePaymentIsUpcomingWithANote(): void
    {
        $this->lostYear();
        $this->order('pausal', 9984.0);

        $november = $this->items()['pausal:2097-11'];

        self::assertSame(TaxCalendarService::pausalDueDate(2097, 11), $november['due_date']);
        self::assertSame('upcoming', $november['status'], 'covered, even though the due date is within the next ten days');
        self::assertNull($november['done_source']);
        self::assertSame('2097-11-12', $november['params']['paid_on']);
        self::assertContains('standing_order_scheduled', array_column($november['notes'], 'code'));
        // ... and it is done from the day of the payment on.
        self::assertSame('done', $this->items('2097-11-12')['pausal:2097-11']['status']);
    }

    public function testTheOrderAmountAgainstTheAdvanceDueIsReportedBothWays(): void
    {
        $this->lostYear();
        $due = $this->items()['pausal:2097-10']['amount'];
        self::assertGreaterThan(0, $due);

        $over = $this->order('pausal', $due + 822);
        $notes = $this->items()['pausal:2097-10']['notes'];
        $note = $notes[array_search('standing_order_overpaid', array_column($notes, 'code'), true)];
        self::assertSame(822.0, $note['params']['diff']);

        $this->fx->pdo->prepare('DELETE FROM timeline_standing_orders WHERE id = ?')->execute([$over]);
        $this->order('pausal', $due - 100);
        $notes = $this->items()['pausal:2097-10']['notes'];
        $note = $notes[array_search('standing_order_underpaid', array_column($notes, 'code'), true)];
        self::assertSame(100.0, $note['params']['diff']);
        self::assertNotContains('standing_order_overpaid', array_column($notes, 'code'));

        $this->fx->pdo->prepare('DELETE FROM timeline_standing_orders WHERE supplier_id = ?')->execute([$this->fx->supplierId]);
        $this->order('pausal', $due);
        self::assertSame(['pausal_regime_lost'], array_column($this->items()['pausal:2097-10']['notes'], 'code'), 'the same amount: the order adds no note');
    }

    public function testTheValidityWindowOfTheOrderDecidesWhichItemsItCovers(): void
    {
        $this->lostYear();
        $this->order('pausal', 9984.0, 12, '2097-02-01', '2097-10-31');

        $items = $this->items('2097-11-10', '2097-01-01', '2097-12-31');

        self::assertNotSame('standing_order', $items['pausal:2097-01']['done_source'], 'the payment day 12. 1. is before the start');
        self::assertSame('standing_order', $items['pausal:2097-02']['done_source']);
        self::assertSame('standing_order', $items['pausal:2097-10']['done_source']);
        self::assertNull($items['pausal:2097-11']['done_source'], 'the order ended on 31. 10.');
        self::assertArrayNotHasKey('paid_on', (array) $items['pausal:2097-11']['params']);
    }

    public function testAStandingOrderBeatsAManualMarkAndOtherAdvancesAreCoveredToo(): void
    {
        $this->lostYear();
        $this->order('pausal', 9984.0);
        $this->repo->saveMark($this->fx->supplierId, 'pausal:2097-10', true, 'zaplaceno', $this->fx->userId);

        $october = $this->items()['pausal:2097-10'];
        self::assertSame('standing_order', $october['done_source']);
        self::assertSame('zaplaceno', $october['mark']['note'], 'the mark stays');

        // 2098 is a regular year of a VAT payer: insurance advances are due on the 20th (social) and the 8th (health).
        $this->order('insurance_social', 5000.0, 12, '2098-01-01');
        $this->order('insurance_health', 3000.0, 12, '2098-01-01');
        $items = $this->items('2098-03-01', '2098-01-01', '2098-04-30');
        self::assertSame('standing_order', $items['ins_social:2098-01']['done_source']);
        self::assertSame('2098-02-12', $items['ins_social:2098-01']['params']['paid_on'], 'the 12th of the month of the due date 20. 2.');
        self::assertSame('standing_order', $items['ins_health:2098-02']['done_source']);
        self::assertSame('2098-02-12', $items['ins_health:2098-02']['params']['paid_on'], 'due 8. 3.: the 12th of the month before');
        self::assertNull($items['ins_social:2098-02']['done_source'], 'paid on 12. 3., still to come');
        self::assertSame('upcoming', $items['ins_social:2098-02']['status']);
    }

    public function testStandingOrderIsAvailableOnlyToItsSupplier(): void
    {
        $this->order('pausal', 9984.0);
        self::assertCount(1, $this->repo->standingOrders($this->fx->supplierId));
        self::assertSame([], $this->repo->standingOrders($this->fx->supplierId + 1000000));
    }

    // ── invoicing ───────────────────────────────────────────────────────────

    public function testInvoicingIsDueOnTheNthWorkingDayOfTheNextMonthWhenSoSet(): void
    {
        $this->lostYear();
        self::assertSame(['invoicing_due' => 'month_end', 'invoicing_working_day' => 5], $this->build()['settings'], 'the default');

        $this->repo->saveSettings($this->fx->supplierId, 'working_day_n', 3);
        $result = $this->build();
        $november = array_column($result['items'], null, 'key')['invoicing:2097-11'];

        self::assertSame(CzechWorkingDays::nthWorkingDayOfMonth(new \DateTimeImmutable('2097-12-01'), 3)->format('Y-m-d'), $november['due_date']);
        self::assertSame('2097-11-30', $november['start_date'], 'the window opens after the month');
        self::assertSame(3, $november['params']['working_day']);
        self::assertSame('working_day_n', $november['params']['rule']);
        self::assertSame(['invoicing_due' => 'working_day_n', 'invoicing_working_day' => 3], $result['settings']);

        $this->repo->saveSettings($this->fx->supplierId, 'month_end', 3);
        self::assertSame('2097-11-30', $this->items()['invoicing:2097-11']['due_date'], 'the working day does not matter for month_end');
    }

    public function testAVatPayerIsToldTheDeadlineOfTheTaxDocument(): void
    {
        $this->lostYear();
        $items = $this->items();

        self::assertNotContains('invoicing_vat_payer', array_column($items['invoicing:2097-12']['notes'], 'code'), 'not a payer in 2097');
        self::assertSame(['invoicing_vat_payer'], array_column($items['invoicing:2098-01']['notes'], 'code'), 'a payer from 1. 1. 2098');
        self::assertSame(['invoicing_vat_payer'], array_column($items['invoicing:2098-06']['notes'], 'code'));
    }

    // ── estimates of a year without the flat-tax regime ─────────────────────

    /** @return array<string,mixed> the estimate of 2097 from the timeline */
    private function estimate2097(string $now = self::NOW): array
    {
        $estimates = array_column($this->build($now)['estimates'], null, 'year');
        self::assertArrayHasKey(2097, $estimates);
        return $estimates[2097];
    }

    public function testTheProjectionAddsUnpaidInvoicesDueInTheYearAndNothingElse(): void
    {
        $this->lostYear();
        $this->sale('2097-12-15', 100000);                    // issued, due in the year
        $this->sale('2097-12-31', 50000, null, 'sent');       // sent, due on the last day of the year
        $this->sale('2097-11-20', 20000, null, 'reminded');   // reminded
        $this->sale('2098-01-15', 70000);                     // due next year: expected to be paid next year
        $this->sale('2097-12-01', 30000, null, 'draft');      // not issued
        $this->sale('2097-12-02', 40000, null, 'cancelled');  // cancelled

        $estimate = $this->estimate2097();

        self::assertFalse($estimate['closed']);
        self::assertSame(2100000, $estimate['income']['paid']);
        self::assertSame(170000, $estimate['income']['unpaid'], '100 000 + 50 000 + 20 000');
        self::assertSame(2270000, $estimate['income']['total']);
        self::assertSame(['paid' => 2100000, 'unpaid' => 170000], array_intersect_key($this->profileIncome(), ['paid' => 1, 'unpaid' => 1]));
    }

    /** @return array{paid: int, unpaid: int} what the profile repository counts for 2097 */
    private function profileIncome(): array
    {
        return [
            'paid' => (int) $this->profiles->annualIncome($this->fx->supplierId, 2097),
            'unpaid' => (int) $this->profiles->unpaidIncomeDueInYear($this->fx->supplierId, 2097),
        ];
    }

    public function testALostYearCreditsTheFlatTaxPartsAndShowsTheSurcharges(): void
    {
        $this->lostYear();
        $this->sale('2097-12-15', 150000);
        $this->profiles->upsert($this->fx->supplierId, 2097, ['activity_rate' => '60', 'flat_tax_band' => 'band1']);
        $c = $this->constants();

        $estimate = $this->estimate2097();

        $regular = (new TaxOptimizer())->compare(['activity_rate' => 60, 'is_vat_payer' => false], 2250000.0, $c)['regular'];
        self::assertSame('lost', $estimate['regime']);
        self::assertSame('income', $estimate['reason']);
        self::assertFalse($estimate['assumed_profile']);
        self::assertFalse($estimate['advances_assumed']);
        self::assertSame(2250000, $estimate['income']['total']);
        self::assertSame((int) $regular['income_tax'], $estimate['income_tax']['expected']);
        self::assertSame((int) $regular['social'], $estimate['social']['expected']);
        self::assertSame((int) $regular['health'], $estimate['health']['expected']);

        // Twelve flat-tax payments of the band in force at the start of 2097: 100 Kč tax, the minimum health advance,
        // the rest social insurance. (Constants of a future year carry the latest rate for the whole year.)
        $monthly = (float) PausalSchedule::monthlyAt(2097, 1, PausalSchedule::normalize($c['pausal_monthly']))['band1'];
        $health = 12 * (int) ceil($c['health_min_base'] / 12 * $c['health_rate']);
        self::assertSame(12, $estimate['credited_months']);
        self::assertSame(1200, $estimate['income_tax']['credited']);
        self::assertSame($health, $estimate['health']['credited']);
        self::assertSame((int) round(12 * $monthly) - 1200 - $health, $estimate['social']['credited']);

        foreach (['income_tax', 'social', 'health'] as $part) {
            self::assertSame($estimate[$part]['expected'] - $estimate[$part]['credited'], $estimate[$part]['surcharge'], $part);
        }
        self::assertSame('2098-05-02', $estimate['income_tax']['due_date'], 'the electronic deadline of the return');
        self::assertSame('2098-06-10', $estimate['social']['due_date'], 'eight days after the statements deadline 2. 6.');
        self::assertSame('2098-06-10', $estimate['health']['due_date']);
        self::assertNull($estimate['standing'], 'no standing order');
    }

    public function testTheEstimateIsOnTheItemsOfTheLostYear(): void
    {
        $this->lostYear();
        $estimate = $this->estimate2097();
        $items = $this->items();

        $lost = $items['flat_tax_lost:2097'];
        self::assertSame($estimate, json_decode(json_encode($lost['params']['estimate']), true), 'the flat-tax item carries the whole estimate');
        self::assertContains('estimate_no_profile', array_column($lost['notes'], 'code'));
        self::assertSame('info', $lost['status']);

        $tax = $items['income_tax:2097'];
        self::assertSame((float) max(0, $estimate['income_tax']['surcharge']), $tax['amount']);
        self::assertTrue($tax['amount_estimated']);
        self::assertSame('estimate_basis', array_column($tax['notes'], 'code')[1]);

        foreach (['social', 'health'] as $kind) {
            self::assertSame((float) max(0, $estimate[$kind]['surcharge']), $items["ins_overview_$kind:2097"]['amount'], "overview $kind");
            self::assertSame((float) max(0, $estimate[$kind]['surcharge']), $items["ins_surcharge_$kind:2097"]['amount'], "surcharge $kind");
            self::assertTrue($items["ins_overview_$kind:2097"]['amount_estimated']);
            self::assertSame('info', $items["ins_surcharge_$kind:2097"]['status']);
        }

        // The notes of the flat-tax advances name the year.
        self::assertSame(['pausal_regime_lost'], array_column($items['pausal:2097-12']['notes'], 'code'));
    }

    public function testAnOverpaymentIsNotAnAmountToPayButANote(): void
    {
        // 2 100 000 Kč paid but very high actual expenses: the tax and the insurance stay under what the flat tax credits.
        $this->lostYear();
        $this->profiles->upsert($this->fx->supplierId, 2097, [
            'activity_rate' => '60', 'flat_tax_band' => 'band1', 'use_actual_expenses' => true, 'actual_expenses' => 2000000,
        ]);

        $estimate = $this->estimate2097();
        $tax = $this->items()['income_tax:2097'];

        // A profit of 100 000 Kč is taxed at 15 % = 15 000 Kč, which the 30 840 Kč credit wipes out: no tax, 1 200 Kč credited.
        self::assertSame(-1200, $estimate['income_tax']['surcharge']);
        self::assertSame(0.0, $tax['amount']);
        $note = $tax['notes'][array_search('estimate_overpaid', array_column($tax['notes'], 'code'), true)];
        self::assertSame(-$estimate['income_tax']['surcharge'], $note['params']['diff']);
    }

    public function testStandingOrderOfTheFlatTaxShowsWhatWasPaidAndTheOverpayment(): void
    {
        $this->lostYear();
        $due = $this->items()['pausal:2097-10']['amount'];
        $this->order('pausal', $due + 822);

        $standing = $this->estimate2097()['standing'];

        self::assertSame(12, $standing['months'], 'the payments to come until the year end count too');
        self::assertSame((int) round(12 * ($due + 822)), $standing['paid']);
        self::assertSame((int) round(12 * 822), $standing['overpaid']);

        // An order from March: ten months.
        $this->fx->pdo->prepare('DELETE FROM timeline_standing_orders WHERE supplier_id = ?')->execute([$this->fx->supplierId]);
        $this->order('pausal', $due, 12, '2097-03-01');
        self::assertSame(['months' => 10, 'paid' => (int) round(10 * $due), 'overpaid' => 0], $this->estimate2097()['standing']);
    }

    public function testAClosedYearCountsOnlyThePaidIncome(): void
    {
        $this->lostYear();
        $this->sale('2097-12-15', 150000);
        $this->profiles->upsert($this->fx->supplierId, 2097, ['activity_rate' => '60', 'flat_tax_band' => 'band1']);

        $estimates = array_column($this->build('2098-02-10', '2098-01-01', '2098-12-31')['estimates'], null, 'year');

        self::assertTrue($estimates[2097]['closed']);
        self::assertSame(['paid' => 2100000, 'unpaid' => 0, 'total' => 2100000], $estimates[2097]['income'], 'the invoice due in 2097 is not part of a closed year');
    }

    public function testAFutureYearHasNoEstimate(): void
    {
        $this->lostYear();
        $this->sale('2098-03-05', 400000, '2098-03-20'); // already paid income of the future year

        $years = array_column($this->build()['estimates'], 'year');

        self::assertSame([2097], $years, '2098 is in the future');
    }

    public function testAnEstimateIsDroppedOnceEverythingItCoversIsDue(): void
    {
        $this->lostYear();
        $this->profiles->upsert($this->fx->supplierId, 2097, ['activity_rate' => '60', 'flat_tax_band' => 'band1']);

        self::assertSame([2097], array_column($this->build('2098-06-10', '2098-06-01', '2098-12-31')['estimates'], 'year'), 'the last surcharge is due on 10. 6.');
        self::assertSame([], $this->build('2098-06-11', '2098-06-01', '2098-12-31')['estimates']);
    }

    public function testTheInsuranceAdvancesOfTheNextYearFollowTheEstimateAfterTheStatements(): void
    {
        $this->lostYear();
        $this->sale('2097-12-15', 150000);
        $estimate = $this->estimate2097();
        $c = $this->constants(2098);
        $minimumSocial = (float) ceil($c['social_min_base_main'] / 12 * $c['social_rate']);
        $minimumHealth = (float) ceil($c['health_min_base'] / 12 * $c['health_rate']);

        $items = $this->items();

        // The statements are due on 2. 6. 2098: the advances for the months up to June stay minimal, July on is 1/12.
        foreach (['2098-01', '2098-05', '2098-06'] as $period) {
            self::assertSame($minimumSocial, $items["ins_social:$period"]['amount'], $period);
            self::assertSame($minimumHealth, $items["ins_health:$period"]['amount'], $period);
            self::assertContains('advance_minimum', array_column($items["ins_social:$period"]['notes'], 'code'), $period);
        }
        foreach (['2098-07', '2098-11'] as $period) {
            $social = $items["ins_social:$period"];
            self::assertSame((float) ceil($estimate['social']['expected'] / 12), $social['amount'], $period);
            self::assertSame((float) ceil($estimate['health']['expected'] / 12), $items["ins_health:$period"]['amount'], $period);
            self::assertTrue($social['amount_estimated']);
            self::assertSame('advance_from_statement', $social['notes'][0]['code'], $period);
            self::assertNotContains('advance_minimum', array_column($social['notes'], 'code'), $period);
            self::assertSame(2097, $social['notes'][0]['params']['base_year']);
        }
        self::assertGreaterThan($minimumSocial, $items['ins_social:2098-07']['amount'], '2 250 000 Kč of income is far above the minimum base');
    }

    public function testIncomeTaxAdvancesFollowTheProjectedTaxOfTheYear(): void
    {
        $this->lostYear();
        $this->sale('2097-12-15', 150000);
        $this->profiles->upsert($this->fx->supplierId, 2097, ['activity_rate' => '60', 'flat_tax_band' => 'band1']);
        $tax = $this->estimate2097()['income_tax']['expected'];
        self::assertGreaterThan(30000, $tax);
        self::assertLessThanOrEqual(150000, $tax);

        $items = $this->items();

        // The advance period starts after the return of 2097 (2. 5. 2098): twice a year, 40 % on 15. 6. and 15. 12.
        $advances = array_filter($items, static fn (array $i): bool => $i['type'] === 'income_tax_advance');
        self::assertSame(['tax_advance:2098-06', 'tax_advance:2098-12'], array_keys($advances));
        $june = $advances['tax_advance:2098-06'];
        self::assertSame(CzechWorkingDays::shiftToWorkingDay(new \DateTimeImmutable('2098-06-15'))->format('Y-m-d'), $june['due_date']);
        self::assertSame(round($tax * 0.4, 0), $june['amount']);
        self::assertTrue($june['amount_estimated']);
        self::assertSame(2097, $june['params']['base_year']);
        self::assertSame('semiannual', $june['params']['frequency']);
        self::assertSame(['advance_estimate_projected'], array_column($june['notes'], 'code'));
        self::assertArrayNotHasKey('tax_advance_info:2097', $items, 'the projected tax replaces the reminder');
    }

    public function testQuarterlyAdvancesWhenTheProjectedTaxIsOver150000(): void
    {
        $this->lostYear();
        $this->sale('2097-12-15', 600000);
        $this->profiles->upsert($this->fx->supplierId, 2097, ['activity_rate' => '60', 'flat_tax_band' => 'band1']);
        $tax = $this->estimate2097()['income_tax']['expected'];
        self::assertGreaterThan(150000, $tax);

        $advances = array_filter($this->items(), static fn (array $i): bool => $i['type'] === 'income_tax_advance');

        // 15. 3. 2098 is before the deadline of the return (2. 5. 2098): the first one is in June.
        self::assertSame(['tax_advance:2098-06', 'tax_advance:2098-09', 'tax_advance:2098-12'], array_keys($advances));
        self::assertSame(round($tax * 0.25, 0), $advances['tax_advance:2098-06']['amount']);
        self::assertSame(25, $advances['tax_advance:2098-06']['params']['share']);
    }

    public function testAYearWithoutAProfileAssumesTheUsualFlatRateExpenses(): void
    {
        $this->lostYear();

        $estimate = $this->estimate2097();

        self::assertTrue($estimate['assumed_profile']);
        $regular = (new TaxOptimizer())->compare(['activity_rate' => 60, 'is_vat_payer' => false], 2100000.0, $this->constants())['regular'];
        self::assertSame((int) $regular['income_tax'], $estimate['income_tax']['expected']);
        self::assertSame((int) $regular['expenses'], $estimate['expenses']);
        self::assertSame(1200000, $estimate['expenses'], '60 % of 2 100 000 Kč, capped at 1 200 000 Kč');
    }

    public function testARegularYearIsEstimatedWithMinimumAdvancesAndNoTaxAdvances(): void
    {
        $this->fx->configureSupplier(['flat_tax_band' => 'none']);
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);
        $this->sale('2097-03-05', 400000, '2097-03-20');
        $c = $this->constants();

        $estimate = $this->estimate2097();

        self::assertSame('none', $estimate['regime']);
        self::assertNull($estimate['credited_months']);
        self::assertTrue($estimate['advances_assumed']);
        self::assertSame(0, $estimate['income_tax']['credited']);
        self::assertSame(12 * (int) ceil($c['social_min_base_main'] / 12 * $c['social_rate']), $estimate['social']['credited']);
        self::assertSame(12 * (int) ceil($c['health_min_base'] / 12 * $c['health_rate']), $estimate['health']['credited']);
        $tax = $this->items()['income_tax:2097'];
        self::assertContains('estimate_advances_assumed', array_column($tax['notes'], 'code'));
        self::assertNotContains('income_tax_regime_lost', array_column($tax['notes'], 'code'));
    }

    public function testALostYearWithoutFlatTaxItemsInTheRangeStillCarriesTheEstimateOnItsReturn(): void
    {
        $this->lostYear();
        // A range that holds the return of 2097 but not the end of the year: no flat_tax_lost item, the estimate is on the return.
        $items = $this->items('2097-11-10', '2098-04-01', '2098-06-30');

        self::assertArrayNotHasKey('flat_tax_lost:2097', $items);
        self::assertTrue($items['income_tax:2097']['amount_estimated']);
    }
}
