<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Repository\ObligationTimelineRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Report\CzechWorkingDays;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Service\Tax\VatPaymentService;
use MyInvoice\Service\Timeline\ObligationTimelineService;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The obligations timeline on synthetic data (years 2096-2099, 71 years after the real situation it mirrors):
 * a natural person in the flat-tax regime (band 1) who passes 2 000 000 Kč in 2097 — by DUZP on 18. 12. 2097, by
 * payment in the same year — and becomes a monthly VAT payer on 1. 1. 2098. The application then owes a registration
 * (10 working days from 18. 12. = 7. 1. 2098, over Christmas and New Year), the flat-tax regime is lost for 2097
 * (return + insurance statements), and 2098 is a regular year with monthly VAT and insurance advances.
 * 2097-12-19 is "today" unless a test says otherwise.
 */
#[Group('integration')]
final class ObligationTimelineTest extends TestCase
{
    private const NOW = '2097-12-19';
    private const FROM = '2097-11-01';
    private const TO = '2098-12-31';

    private ?FilingFixture $fx = null;
    private ObligationTimelineService $timeline;
    private ObligationTimelineRepository $repo;
    private TaxProfileRepository $profiles;
    private int $client = 0;
    private int $invoiceNo = 0;
    /** @var list<int> */
    private array $customIds = [];
    /** What the tests below made the timeline emit: the item types, note codes and link labels (see the last test). */
    private static array $emitted = ['types' => [], 'notes' => [], 'links' => []];

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $c = $this->fx->container;
        $this->timeline = $c->get(ObligationTimelineService::class);
        $this->repo = $c->get(ObligationTimelineRepository::class);
        $this->profiles = $c->get(TaxProfileRepository::class);
        if (!$this->repo->isAvailable()) {
            self::markTestSkipped('Migrace 9011 (obligations timeline) není aplikovaná.');
        }
        $this->fx->configureSupplier(['taxpayer_type' => 'fo', 'vat_period' => 'monthly', 'flat_tax_band' => 'band1']);
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2098-01-01', 'payer']]);
        $this->client = $this->fx->client('Český odběratel');
    }

    protected function tearDown(): void
    {
        if ($this->fx !== null) {
            $sid = $this->fx->supplierId;
            $this->fx->pdo->prepare('DELETE FROM tax_profiles WHERE supplier_id = ? AND year BETWEEN 2096 AND 2099')->execute([$sid]);
            $this->fx->pdo->prepare("DELETE FROM timeline_marks WHERE supplier_id = ? AND item_key REGEXP ':(2096|2097|2098|2099)'")->execute([$sid]);
            foreach ($this->customIds as $id) {
                $this->repo->deleteCustom($sid, $id);
            }
            $this->fx->restore();
        }
    }

    /** @return array<string, array<string,mixed>> items keyed by their key */
    private function items(string $now = self::NOW, string $from = self::FROM, string $to = self::TO): array
    {
        $out = [];
        foreach ($this->timeline->build($this->fx->supplierId, new \DateTimeImmutable($now), $from, $to)['items'] as $item) {
            $out[$item['key']] = $item;
            self::$emitted['types'][$item['type']] = true;
            foreach ($item['notes'] as $note) {
                self::$emitted['notes'][$note['code']] = true;
            }
            foreach ($item['links'] as $link) {
                self::$emitted['links'][$link['label']] = true;
            }
        }
        return $out;
    }

    /** An issued invoice (no VAT) paid on the given day: counts for the turnover by DUZP and for the income by payment. */
    private function paidSale(string $taxDate, float $amount, ?string $paidAt = null): int
    {
        $id = $this->fx->sale(sprintf('%d%05d', substr($taxDate, 0, 4), ++$this->invoiceNo), $this->client, $taxDate, $amount, 0, 0, '1', $paidAt === null ? 'issued' : 'paid');
        if ($paidAt !== null) {
            $this->fx->pdo->prepare('UPDATE invoices SET paid_at = ? WHERE id = ?')->execute([$paidAt . ' 10:00:00', $id]);
        }
        return $id;
    }

    /** The year of the real scenario: 2 100 000 Kč invoiced in three steps, the limit passed on 18. 12. */
    private function crossTwoMillion(): void
    {
        $this->paidSale('2097-03-05', 900000, '2097-03-20');
        $this->paidSale('2097-06-10', 900000, '2097-06-30');
        $this->paidSale('2097-12-18', 300000, '2097-12-19');
    }

    /** @param list<string> $prefixes */
    private function keysStartingWith(array $items, array $prefixes): array
    {
        return array_values(array_filter(array_keys($items), static function (string $key) use ($prefixes): bool {
            foreach ($prefixes as $p) {
                if (str_starts_with($key, $p)) {
                    return true;
                }
            }
            return false;
        }));
    }

    // ── the real-life scenario ──────────────────────────────────────────────

    public function testVatRegistrationIsDueTenWorkingDaysAfterTheLimitWasPassed(): void
    {
        $this->crossTwoMillion();
        $items = $this->items();

        $reg = $items['vat_registration:2097'];
        self::assertSame('registration', $reg['category']);
        self::assertSame('2098-01-07', $reg['due_date'], '18. 12. + 10 working days, Christmas, a weekend and New Year in between');
        self::assertSame('2097-12-18', $reg['start_date']);
        self::assertSame('2097-12-18', $reg['params']['exceeded_on']);
        self::assertSame('2098-01-01', $reg['params']['payer_from']);
        self::assertSame('upcoming', $reg['status']);
        self::assertFalse($reg['tracked'], 'the application does not see the application being filed');
        self::assertSame([], $reg['notes'], 'payer from 1. 1. is already in the VAT history');
        self::assertArrayNotHasKey('vat_limit_immediate:2097', $items, '2 536 500 Kč was not passed');
        self::assertArrayNotHasKey('vat_registration_expected:2097', $items);

        // The same limit passed on a Monday: the shift is plain counting.
        self::assertSame('2098-01-07', CzechWorkingDays::addWorkingDays(new \DateTimeImmutable('2097-12-18'), 10)->format('Y-m-d'));
    }

    public function testRegistrationNotInTheHistoryIsFlaggedAndTheImmediateLimitIsAWarning(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);
        $this->paidSale('2097-02-02', 2600000);

        $items = $this->items('2097-03-01', '2097-01-01', '2097-12-31');

        $reg = $items['vat_registration:2097'];
        self::assertSame(CzechWorkingDays::addWorkingDays(new \DateTimeImmutable('2097-02-02'), 10)->format('Y-m-d'), $reg['due_date']);
        self::assertSame(['registration_not_in_history'], array_column($reg['notes'], 'code'));
        $immediate = $items['vat_limit_immediate:2097'];
        self::assertSame('2097-02-02', $immediate['due_date']);
        self::assertSame('info', $immediate['status']);
        self::assertSame('warning', $immediate['severity']);
    }

    public function testExpectedRegistrationWhileTheLimitIsNotPassedYet(): void
    {
        $this->paidSale('2097-02-01', 1900000);

        $items = $this->items('2097-11-09');

        $expected = $items['vat_registration_expected:2097'];
        self::assertSame(100000.0, $expected['amount'], 'the remaining amount to the limit');
        self::assertTrue($expected['amount_estimated']);
        // 1 900 000 Kč in the ten completed months = 190 000 Kč a month: the November invoices (DUZP at the month end)
        // carry the turnover over the limit, so the estimate is 30. 11., not a day-by-day pace in the middle of November.
        self::assertSame('2097-11-30', $expected['params']['estimated_on']);
        self::assertSame(CzechWorkingDays::addWorkingDays(new \DateTimeImmutable('2097-11-30'), 10)->format('Y-m-d'), $expected['due_date']);
        self::assertSame([], $expected['notes']);
        self::assertArrayNotHasKey('vat_registration:2097', $items);

        // At the pace of this year the limit is not reached by 31. 12.: the estimate stops at the year end.
        $this->paidSale('2097-02-02', -1700000);
        $slow = $this->items('2097-11-09')['vat_registration_expected:2097'];
        self::assertSame('2097-12-31', $slow['params']['estimated_on']);
        self::assertSame(['registration_expected_voluntary'], array_column($slow['notes'], 'code'));
    }

    public function testNoRegistrationItemsForAPayer(): void
    {
        $this->fx->payer();
        $this->paidSale('2097-02-01', 2600000);

        self::assertSame([], $this->keysStartingWith($this->items('2097-11-09'), ['vat_registration', 'vat_limit']));
    }

    public function testFlatTaxRegimeIsLostWhenThePaidIncomePassesTheLimit(): void
    {
        $this->crossTwoMillion();
        $items = $this->items();

        $lost = $items['flat_tax_lost:2097'];
        self::assertSame('2097-12-31', $lost['due_date']);
        self::assertSame('info', $lost['status']);
        self::assertSame('warning', $lost['severity']);
        self::assertSame('income', $lost['params']['reason']);
        self::assertSame(2100000.0, $lost['params']['income']);

        // The return and the statements for the lost year; the flat advances stay with a hint to verify them.
        $tax = $items['income_tax:2097'];
        self::assertSame('2098-05-02', $tax['due_date'], '1. 5. is a holiday; the electronic deadline');
        self::assertSame('2098-04-01', $tax['params']['paper_due_date']);
        self::assertSame('2098-07-01', $tax['params']['advisor_due_date']);
        self::assertSame('dpfdp5', $tax['params']['form']);
        self::assertSame(['income_tax_regime_lost', 'estimate_basis', 'estimate_no_profile'], array_column($tax['notes'], 'code'), 'the estimate and what it had to assume (no tax profile of 2097)');
        self::assertSame('unfiled', $tax['filing']['status']);
        self::assertSame(['pausal_regime_lost'], array_column($items['pausal:2097-12']['notes'], 'code'));
        self::assertSame(2097, $items['pausal:2097-12']['params']['year'], 'the text of the note names the year');
        self::assertSame([], $this->keysStartingWith($items, ['pausal:2098']), 'a VAT payer cannot be in the flat-tax regime');
    }

    public function testVatReturnControlStatementAndPaymentFollowThePayerPeriods(): void
    {
        $this->crossTwoMillion();
        $items = $this->items();

        self::assertSame([], $this->keysStartingWith($items, ['dph:2097', 'kh:2097', 'vat_payment:2097']), 'December 2097 is not a payer period');
        foreach (['2098-01' => '2098-02-25', '2098-02' => '2098-03-25', '2098-03' => '2098-04-25', '2098-04' => '2098-05-26'] as $period => $due) {
            foreach (['dph', 'kh', 'vat_payment'] as $type) {
                self::assertSame($due, $items["$type:$period"]['due_date'] ?? null, "$type $period");
            }
        }
        self::assertSame('dph', $items['vat_payment:2098-01']['category']);
        // The title of the payment is rendered from the period: the params carry what the DPH return's do.
        self::assertSame([1, null, 2098], [$items['vat_payment:2098-01']['params']['month'], $items['vat_payment:2098-01']['params']['quarter'], $items['vat_payment:2098-01']['params']['year']]);
        self::assertCount(11, $this->keysStartingWith($items, ['dph:']), 'January to November 2098 are due within the range');
        self::assertSame(['income_tax:2097'], $this->keysStartingWith($items, ['income_tax:']));
    }

    public function testInsuranceStatementsAndAdvancesOfTheRegularYear(): void
    {
        $this->crossTwoMillion();
        $items = $this->items();

        foreach (['social', 'health'] as $kind) {
            $overview = $items["ins_overview_$kind:2097"];
            self::assertSame('2098-06-02', $overview['due_date'], 'one month after the electronic deadline of the return, 2. 6.');
            self::assertSame('insurance', $overview['category']);
            self::assertSame('2098-06-10', $items["ins_surcharge_$kind:2097"]['due_date'], 'eight days later');
            self::assertSame('info', $items["ins_surcharge_$kind:2097"]['status']);
        }

        // 2097 is a lost year: its advances were covered by the flat payments. 2098 is regular: January is paid in February.
        self::assertSame([], $this->keysStartingWith($items, ['ins_social:2097', 'ins_health:2097']));
        $social = $items['ins_social:2098-01'];
        $health = $items['ins_health:2098-01'];
        self::assertSame('2098-02-20', $social['due_date'], 'the 20th of the following month');
        self::assertSame('2098-02-08', $health['due_date'], 'the 8th of the following month (a Sunday: hint to pay earlier)');
        self::assertContains('non_working_day', array_column($health['notes'], 'code'));
        self::assertNotContains('non_working_day', array_column($social['notes'], 'code'));
        self::assertFalse($social['tracked']);

        $c = $this->fx->container->get(TaxConstantsRepository::class)->forYear(2098);
        self::assertSame((float) ceil($c['social_min_base_main'] / 12 * $c['social_rate']), $social['amount']);
        self::assertSame((float) ceil($c['health_min_base'] / 12 * $c['health_rate']), $health['amount']);
        self::assertTrue($social['amount_estimated']);
        $codes = array_column($social['notes'], 'code');
        self::assertContains('advance_minimum', $codes);
        self::assertContains('advance_changes', $codes);
        self::assertContains('advance_constants_fallback', $codes, '2098 has no constants of its own: the latest year is used and says so');

        // Eleven advances of each kind are due within the range (January to November 2098).
        self::assertCount(11, $this->keysStartingWith($items, ['ins_social:']));
        self::assertCount(11, $this->keysStartingWith($items, ['ins_health:']));
    }

    public function testIncomeTaxAdvancesAreOnlyAReminderForAClosedYearWithoutAProfile(): void
    {
        $this->crossTwoMillion();
        // In 2098 the year 2097 is closed, but without a tax profile the optimizer would only guess the activity and
        // the deductions: the item reminds to work the advances out from the filed return.
        $items = $this->items('2098-02-10', '2098-01-01', '2098-12-31');

        $info = $items['tax_advance_info:2097'];
        self::assertSame('2098-05-02', $info['due_date'], 'right at the deadline of the return');
        self::assertSame('info', $info['status']);
        self::assertSame([], $this->keysStartingWith($items, ['tax_advance:']));
    }

    /** Income of 2096 (the base year) => [expected frequency, share in %], from the smallest income up. */
    private const ADVANCE_CASES = [
        'tax under 30 000: none' => [300000.0, null, 0],
        'tax between 30 000 and 150 000: twice a year' => [2000000.0, 'semiannual', 40],
        'tax over 150 000: quarterly' => [3500000.0, 'quarterly', 25],
    ];

    public function testIncomeTaxAdvancesFollowTheLastKnownTax(): void
    {
        $this->fx->configureSupplier(['flat_tax_band' => 'none']);
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);
        $this->profiles->upsert($this->fx->supplierId, 2096, ['activity_rate' => '60', 'flat_tax_band' => 'none']);

        $expectedDates = [
            CzechWorkingDays::shiftToWorkingDay(new \DateTimeImmutable('2097-12-15'))->format('Y-m-d'),
            CzechWorkingDays::shiftToWorkingDay(new \DateTimeImmutable('2098-03-15'))->format('Y-m-d'),
        ];
        $income = 0.0;
        foreach (self::ADVANCE_CASES as $label => [$target, $frequency, $share]) {
            // Top the 2096 income up to the case's amount (cases are processed from the smallest up).
            $this->paidSale('2096-05-05', $target - $income, '2096-05-20');
            $income = $target;

            $items = $this->items();
            $advances = array_filter($items, static fn (array $i): bool => $i['type'] === 'income_tax_advance');
            if ($frequency === null) {
                self::assertSame([], array_keys($advances), $label);
                continue;
            }
            $first = $items['tax_advance:2097-12'];
            self::assertSame($expectedDates[0], $first['due_date'], $label);
            self::assertSame(2096, $first['params']['base_year'], $label);
            self::assertSame($frequency, $first['params']['frequency'], $label);
            self::assertSame($share, $first['params']['share'], $label);
            self::assertSame(round($first['params']['tax'] * $share / 100, 0), $first['amount'], $label);
            self::assertTrue($first['amount_estimated'], $label);
            self::assertContains('advance_estimate', array_column($first['notes'], 'code'), $label);
            if ($frequency === 'quarterly') {
                self::assertSame(['tax_advance:2097-12', 'tax_advance:2098-03'], array_keys($advances), $label);
                self::assertSame($expectedDates[1], $items['tax_advance:2098-03']['due_date'], $label);
                self::assertGreaterThan(150000, $first['params']['tax']);
            } else {
                self::assertSame(['tax_advance:2097-12'], array_keys($advances), 'the second semi-annual advance (15. 6.) belongs to the next base year');
                self::assertGreaterThan(30000, $first['params']['tax']);
                self::assertLessThanOrEqual(150000, $first['params']['tax']);
            }
        }
    }

    public function testDeductionDocumentsOnlyWhenTheProfileClaimsSomething(): void
    {
        $this->crossTwoMillion();
        self::assertArrayNotHasKey('deduction_docs:2097', $this->items());

        $this->profiles->upsert($this->fx->supplierId, 2097, ['activity_rate' => '60', 'flat_tax_band' => 'band1']);
        self::assertArrayNotHasKey('deduction_docs:2097', $this->items(), 'a profile without deductions or children');

        $this->profiles->upsert($this->fx->supplierId, 2097, ['activity_rate' => '60', 'flat_tax_band' => 'band1', 'children_count' => 1, 'mortgage_interest' => 80000]);
        $docs = $this->items()['deduction_docs:2097'];
        self::assertSame('2098-02-15', $docs['due_date']);
        self::assertSame('recommended', $docs['obligation']);
        self::assertEqualsCanonicalizing(['mortgage', 'children'], $docs['params']['claims']);
    }

    public function testInvoicingIsDueOnTheLastDayOfTheMonthAndDoneByAnInvoiceWithADuzpInIt(): void
    {
        $this->crossTwoMillion();
        $items = $this->items();

        self::assertSame('2097-11-30', $items['invoicing:2097-11']['due_date'], 'the last day of the month: the owner issues invoices on it');
        self::assertSame('2097-11-01', $items['invoicing:2097-11']['start_date'], 'the whole month is the window');
        self::assertSame('overdue', $items['invoicing:2097-11']['status'], 'no invoice with a DUZP in November');
        self::assertSame('recommended', $items['invoicing:2097-11']['obligation']);
        self::assertSame('done', $items['invoicing:2097-12']['status'], 'the invoice of 18. 12.');
        self::assertSame('auto', $items['invoicing:2097-12']['done_source']);
        self::assertSame('2097-12-31', $items['invoicing:2097-12']['due_date']);
        self::assertSame('upcoming', $items['invoicing:2098-01']['status']);
        self::assertSame('2098-01-31', $items['invoicing:2098-01']['due_date']);
        self::assertArrayNotHasKey('invoicing:2097-10', $items, 'due on 31. 10., before the range');
    }

    public function testNoInvoicingItemsBeforeTheFirstInvoice(): void
    {
        $this->paidSale('2097-12-18', 1000);
        $items = $this->items('2097-12-19', '2097-01-01', '2098-03-31');

        self::assertSame(['invoicing:2097-12', 'invoicing:2098-01', 'invoicing:2098-02', 'invoicing:2098-03'], $this->keysStartingWith($items, ['invoicing:']));
    }

    // ── status, marks, custom items ─────────────────────────────────────────

    public function testStatusesFromTheDistanceToTheDeadline(): void
    {
        $this->crossTwoMillion();
        $items = $this->items('2098-02-20', '2098-02-01', '2098-04-30');

        self::assertSame('due_soon', $items['dph:2098-01']['status'], '25. 2. is five days away');
        self::assertSame(5, $items['dph:2098-01']['days_left']);
        self::assertSame('upcoming', $items['dph:2098-02']['status']);
        // The registration application is past its date and cannot be seen being filed.
        $late = $this->items('2098-01-20', '2097-12-01', '2098-02-28');
        self::assertSame('overdue', $late['vat_registration:2097']['status']);
        self::assertSame(-13, $late['vat_registration:2097']['days_left']);
        self::assertFalse($late['vat_registration:2097']['tracked']);
    }

    public function testAnOldItemWithoutEvidenceIsUnverifiedNotOverdue(): void
    {
        $this->crossTwoMillion();
        $items = $this->items('2098-03-15', '2098-01-01', '2098-04-30');

        // The registration (67 days past) and the January health advance (35 days past): nothing in the application
        // shows whether they were done, so they are not claimed to be missed.
        self::assertSame('unverified', $items['vat_registration:2097']['status']);
        self::assertSame('unverified', $items['ins_health:2098-01']['status']);
        // 23 days past is still a nudge.
        self::assertSame('overdue', $items['ins_social:2098-01']['status']);
        // What the application can see stays overdue however old it is.
        self::assertSame('overdue', $items['invoicing:2098-01']['status']);
    }

    public function testManualMarkFinishesAnItemWithoutEvidence(): void
    {
        $this->crossTwoMillion();
        $sid = $this->fx->supplierId;
        $key = 'ins_social:2098-01';
        self::assertSame('upcoming', $this->items()[$key]['status']);

        $this->repo->saveMark($sid, $key, true, 'zaplaceno z účtu', $this->fx->userId);
        $item = $this->items()[$key];
        self::assertSame('done', $item['status']);
        self::assertSame('manual', $item['done_source']);
        self::assertSame('zaplaceno z účtu', $item['mark']['note']);
        self::assertNotNull($item['mark']['by']);

        // A note alone does not finish the item.
        $this->repo->saveMark($sid, $key, false, 'ověřit částku', $this->fx->userId);
        $noted = $this->items()[$key];
        self::assertSame('upcoming', $noted['status']);
        self::assertSame('ověřit částku', $noted['mark']['note']);

        self::assertTrue($this->repo->deleteMark($sid, $key));
        self::assertNull($this->items()[$key]['mark']);
    }

    public function testManualMarkOnAnItemWithEvidenceDoesNotOverrideIt(): void
    {
        $this->crossTwoMillion();
        // Filed outside the application: the return of January is marked by hand.
        $this->repo->saveMark($this->fx->supplierId, 'dph:2098-01', true, null, $this->fx->userId);
        $dph = $this->items('2098-02-20')['dph:2098-01'];
        self::assertSame('done', $dph['status']);
        self::assertSame('manual', $dph['done_source']);

        // Filed in the application too: the automatic evidence wins and the source says so.
        $id = $this->fx->dphSubmission(2098, 1, null, 5000);
        $this->fx->container->get(TaxSubmissionFilingService::class)->markFiled($this->fx->supplierId, $id, '2020-01-01 10:00', 'epo', null, null);
        self::assertSame('auto', $this->items('2098-02-20')['dph:2098-01']['done_source']);
    }

    public function testVatPaymentFollowsTheFiledReturnAndItsPayment(): void
    {
        $this->crossTwoMillion();
        $now = '2098-02-20';
        $filing = $this->fx->container->get(TaxSubmissionFilingService::class);
        $payments = $this->fx->container->get(VatPaymentService::class);

        $open = $this->items($now)['vat_payment:2098-01'];
        self::assertSame('due_soon', $open['status']);
        self::assertNull($open['amount']);
        self::assertSame(['vat_payment_amount_unknown'], array_column($open['notes'], 'code'));

        $id = $this->fx->dphSubmission(2098, 1, null, 5000);
        $filing->markFiled($this->fx->supplierId, $id, '2020-01-01 10:00', 'epo', null, null);
        $filed = $this->items($now);
        self::assertSame('done', $filed['dph:2098-01']['status']);
        self::assertSame('filed', $filed['dph:2098-01']['filing']['status']);
        self::assertSame('due_soon', $filed['vat_payment:2098-01']['status'], 'filed, not paid');
        self::assertSame(5000.0, $filed['vat_payment:2098-01']['amount']);

        $payments->markPaid($this->fx->supplierId, $id, '2098-02-12', null, $this->fx->userId);
        $paid = $this->items($now)['vat_payment:2098-01'];
        self::assertSame('done', $paid['status']);
        self::assertSame('auto', $paid['done_source']);
        self::assertSame(5000.0, $paid['amount']);
    }

    public function testAReturnWithNothingToPayLeavesNoOpenPayment(): void
    {
        $this->crossTwoMillion();
        $id = $this->fx->dphSubmission(2098, 1, null, -3000);
        $this->fx->container->get(TaxSubmissionFilingService::class)->markFiled($this->fx->supplierId, $id, '2020-01-01 10:00', 'epo', null, null);

        $payment = $this->items('2098-02-20')['vat_payment:2098-01'];
        self::assertSame('done', $payment['status']);
        self::assertSame(['vat_payment_none'], array_column($payment['notes'], 'code'));
    }

    public function testCustomItemsAreInTheTimelineAndCanBeDone(): void
    {
        $sid = $this->fx->supplierId;
        $id = $this->repo->createCustom($sid, [
            'title' => 'Oznámit klientům DPH a nový účet', 'due_date' => '2097-12-28', 'start_date' => '2097-12-01',
            'category' => 'registration', 'note' => 'e-mailem', 'url' => '/clients', 'done_at' => null, 'done_by' => null,
        ], $this->fx->userId);
        $this->customIds[] = $id;

        $item = $this->items()["custom:$id"];
        self::assertSame('registration', $item['category']);
        self::assertSame('upcoming', $item['status'], '9 days left');
        self::assertSame('2097-12-01', $item['start_date']);
        self::assertSame('Oznámit klientům DPH a nový účet', $item['custom']['title']);
        self::assertSame('/clients', $item['custom']['url']);

        $stored = $this->repo->findCustom($sid, $id);
        $this->repo->updateCustom($sid, $id, [
            'title' => $stored['title'], 'due_date' => $stored['due_date'], 'start_date' => $stored['start_date'],
            'category' => $stored['category'], 'note' => $stored['note'], 'url' => $stored['url'],
            'done_at' => '2097-12-19 09:00:00', 'done_by' => $this->fx->userId,
        ]);
        $done = $this->items()["custom:$id"];
        self::assertSame('done', $done['status']);
        self::assertSame('manual', $done['done_source']);

        // Outside the range it is not listed.
        self::assertArrayNotHasKey("custom:$id", $this->items(self::NOW, '2098-01-01', '2098-02-01'));
        self::assertTrue($this->repo->deleteCustom($sid, $id));
        self::assertArrayNotHasKey("custom:$id", $this->items());
    }

    // ── other kinds of suppliers ────────────────────────────────────────────

    public function testPlainFlatTaxSupplierGetsAdvancesOnly(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);
        $this->paidSale('2097-04-01', 400000, '2097-04-15');

        $items = $this->items();

        self::assertSame([], $this->keysStartingWith($items, [
            'income_tax:', 'ins_', 'tax_advance', 'deduction_docs', 'flat_tax_lost', 'vat_', 'dph:', 'kh:',
        ]), 'no return, no statements, no regular advances, nothing about VAT');
        self::assertSame(
            array_map(static fn (int $m): string => sprintf('pausal:2098-%02d', $m), range(1, 12)),
            array_values(array_filter($this->keysStartingWith($items, ['pausal:']), static fn (string $k): bool => str_starts_with($k, 'pausal:2098'))),
        );
        self::assertArrayHasKey('pausal:2097-12', $items);
        self::assertSame([], $items['pausal:2097-12']['notes']);
        self::assertGreaterThan(0, $items['pausal:2097-12']['amount']);
        self::assertFalse($items['pausal:2097-12']['tracked']);
    }

    public function testLegalEntityGetsTheCorporateReturnAndNoPersonalItems(): void
    {
        $this->fx->configureSupplier(['taxpayer_type' => 'po', 'flat_tax_band' => 'none']);
        $this->crossTwoMillion();

        $items = $this->items();

        self::assertSame('dppdp9', $items['income_tax:2097']['params']['form']);
        self::assertSame([], $this->keysStartingWith($items, ['ins_', 'tax_advance', 'deduction_docs', 'flat_tax_lost', 'pausal:']));
    }

    public function testSummaryCountsTheStatuses(): void
    {
        $this->crossTwoMillion();
        $result = $this->timeline->build($this->fx->supplierId, new \DateTimeImmutable(self::NOW), self::FROM, self::TO);

        self::assertSame(count($result['items']), $result['summary']['total']);
        self::assertSame($result['summary']['total'], array_sum($result['summary']['by_status']));
        self::assertSame($result['summary']['total'], array_sum($result['summary']['by_category']));
        $dates = array_column($result['items'], 'due_date');
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates, 'sorted by date');
        self::assertSame(self::FROM, $result['from']);
        self::assertSame(self::NOW, $result['today']);
    }

    public function testDefaultRangeRunsFromThirtyDaysBackToTheEndOfNextYear(): void
    {
        self::assertSame(['2026-10-10', '2027-12-31'], ObligationTimelineService::defaultRange(new \DateTimeImmutable('2026-11-09')));
    }

    /**
     * The web client renders `type`, the note codes and the link labels from its catalogue: whatever the scenarios above
     * made the server send must have a text in both languages. (A subset check, so it also holds under --filter.)
     */
    public function testEveryEmittedTypeNoteAndLinkHasACatalogueText(): void
    {
        $this->crossTwoMillion();
        $this->items();
        $root = dirname(__DIR__, 4) . '/web/src/i18n/';
        foreach (['cs', 'en'] as $lang) {
            $catalogue = json_decode((string) file_get_contents("{$root}{$lang}.json"), true, flags: JSON_THROW_ON_ERROR)['timeline'];
            foreach (array_diff(array_keys(self::$emitted['types']), ['custom']) as $type) { // a custom item carries its own title
                self::assertArrayHasKey($type, $catalogue['item'], "{$lang}: item type {$type}");
                self::assertNotEmpty($catalogue['item'][$type]['title'] ?? null, "{$lang}: title of {$type}");
            }
            foreach (array_keys(self::$emitted['notes']) as $code) {
                self::assertArrayHasKey($code, $catalogue['note'], "{$lang}: note {$code}");
            }
            foreach (array_keys(self::$emitted['links']) as $label) {
                self::assertArrayHasKey($label, $catalogue['link'], "{$lang}: link {$label}");
            }
        }
        self::assertGreaterThan(15, count(self::$emitted['types']), 'the scenarios above exercise most item types');
    }
}
