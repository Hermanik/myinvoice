<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Dashboard;

use MyInvoice\Service\Dashboard\AccountantDashboardService;
use MyInvoice\Service\Report\ReportPeriod;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The accountant view of the dashboard on synthetic data (year 2097): the VAT card of the last closed period with
 * the filing state and the figures of the return, the open obligations, the issued and received invoices and the
 * empty states. "Today" is 2097-03-12, so the last closed month is February 2097.
 */
#[Group('integration')]
final class AccountantDashboardServiceTest extends TestCase
{
    private const TODAY = '2097-03-12';

    private ?FilingFixture $fx = null;
    private AccountantDashboardService $dashboard;
    private int $client = 0;
    private int $vendor = 0;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $this->dashboard = $this->fx->container->get(AccountantDashboardService::class);
        $this->fx->configureSupplier(['taxpayer_type' => 'fo', 'vat_period' => 'monthly', 'flat_tax_band' => 'none']);
        $this->fx->payer();
        $this->client = $this->fx->client('Odběratel Dashboard');
        $this->vendor = $this->fx->client('Dodavatel Dashboard');
    }

    protected function tearDown(): void
    {
        if ($this->fx !== null) {
            $this->fx->pdo->prepare("DELETE FROM timeline_marks WHERE supplier_id = ? AND item_key REGEXP ':(2096|2097|2098)'")->execute([$this->fx->supplierId]);
        }
        $this->fx?->restore();
    }

    /** @return array<string,mixed> */
    private function build(string $today = self::TODAY, bool $withObligations = true): array
    {
        return $this->dashboard->build($this->fx->supplierId, new \DateTimeImmutable($today), $withObligations);
    }

    public function testVatCardShowsDeadlineFilingStateAndTheFiguresOfTheReturn(): void
    {
        $this->fx->sale('A97001', $this->client, '2097-02-10', 1000.0, 210.0, 21.0);
        $this->fx->purchase('P97001', $this->vendor, '2097-02-12', 400.0, 84.0, 21.0);

        $vat = $this->build()['vat'];

        self::assertTrue($vat['applicable']);
        self::assertSame(['type' => 'monthly', 'year' => 2097, 'month' => 2, 'quarter' => null, 'start' => '2097-02-01', 'end' => '2097-02-28'], $vat['period']);
        self::assertSame(ReportPeriod::filingDeadline(2097, 2, 'monthly'), $vat['deadline']);
        self::assertSame((int) (new \DateTimeImmutable(self::TODAY))->diff(new \DateTimeImmutable($vat['deadline']))->format('%r%a'), $vat['days_left']);
        self::assertSame(['dph', 'kh'], array_column($vat['forms'], 'type'));
        self::assertSame(['unfiled', 'unfiled'], array_column($vat['forms'], 'status'));
        self::assertEquals(['output_vat' => 210.0, 'input_vat' => 84.0, 'tax_due' => 126.0], $vat['return']);
    }

    public function testFilingStateComesFromTheArchive(): void
    {
        $dph = $this->fx->dphSubmission(2097, 2, null, 126);
        $this->fx->container->get(TaxSubmissionFilingService::class)
            ->markFiled($this->fx->supplierId, $dph, '2020-01-01 10:00', 'epo', null, null);

        $forms = array_column($this->build()['vat']['forms'], null, 'type');

        self::assertSame('filed', $forms['dph']['status']);
        self::assertSame($dph, $forms['dph']['submission_id']);
        self::assertSame('unfiled', $forms['kh']['status']);
    }

    public function testVatCardIsNotApplicableBeforeTheSupplierBecomesAPayer(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2098-01-01', 'payer']]);

        $vat = $this->build()['vat'];

        self::assertFalse($vat['applicable']);
        self::assertSame('2098-01-01', $vat['payer_since']);
        self::assertSame(2, $vat['period']['month']);
        self::assertArrayNotHasKey('deadline', $vat);
    }

    public function testNoPayerSinceForASupplierThatNeverRegisters(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);

        self::assertNull($this->build()['vat']['payer_since']);
    }

    public function testQuarterlyPayerGetsTheLastClosedQuarter(): void
    {
        $this->fx->configureSupplier(['vat_period' => 'quarterly']);

        $vat = $this->build('2097-05-10')['vat'];

        self::assertTrue($vat['applicable']);
        self::assertSame(['type' => 'quarterly', 'year' => 2097, 'month' => 3, 'quarter' => 1, 'start' => '2097-01-01', 'end' => '2097-03-31'], $vat['period']);
        self::assertSame(ReportPeriod::filingDeadline(2097, 3, 'quarterly'), $vat['deadline']);
    }

    public function testQuarterlyPayerInTheFirstMonthOfAQuarterStillReportsThePreviousQuarter(): void
    {
        $this->fx->configureSupplier(['vat_period' => 'quarterly']);

        $period = $this->build('2097-04-02')['vat']['period'];

        self::assertSame([2097, 3, 1], [$period['year'], $period['month'], $period['quarter']]);
    }

    public function testJanuaryLooksBackToDecemberOfThePreviousYear(): void
    {
        $result = $this->build('2097-01-15');

        self::assertSame(['year' => 2096, 'month' => 12], $result['export']);
        self::assertSame([2096, 12], [$result['vat']['period']['year'], $result['vat']['period']['month']]);
    }

    public function testExportPresetIsTheLastClosedMonth(): void
    {
        self::assertSame(['year' => 2097, 'month' => 2], $this->build()['export']);
    }

    public function testIssuedInvoicesOfTheLastTwoMonthsExcludeDraftsAndCancelled(): void
    {
        $this->fx->sale('A97010', $this->client, '2097-02-03', 1000.0, 210.0, 21.0);
        $this->fx->sale('A97011', $this->client, '2097-02-20', 500.0, 105.0, 21.0, '1', 'paid');
        $this->fx->sale('A97012', $this->client, '2097-02-21', 9999.0, 2099.79, 21.0, '1', 'draft');
        $this->fx->sale('A97013', $this->client, '2097-02-22', 9999.0, 2099.79, 21.0, '1', 'cancelled');
        $this->fx->sale('A97014', $this->client, '2097-03-02', 300.0, 63.0, 21.0);
        $this->fx->sale('A97015', $this->client, '2097-01-31', 7777.0, 1633.17, 21.0);

        $issued = $this->build()['issued'];

        self::assertEquals(['year' => 2097, 'month' => 2, 'count' => 2, 'base' => 1500.0, 'vat' => 315.0], $issued['previous']);
        self::assertEquals(['year' => 2097, 'month' => 3, 'count' => 1, 'base' => 300.0, 'vat' => 63.0], $issued['current']);
    }

    public function testReceivedInvoicesCountOnlyBookedDocumentsAndFlagWhatNeedsAttention(): void
    {
        $before = $this->build()['purchase']['attention'];
        $this->fx->purchase('P97010', $this->vendor, '2097-02-05', 400.0, 84.0, 21.0, '40', 'received');
        $this->fx->purchase('P97011', $this->vendor, '2097-02-06', 100.0, 21.0, 21.0, '40', 'booked');
        $this->fx->purchase('P97012', $this->vendor, '2097-02-07', 5000.0, 1050.0, 21.0, '40', 'draft');
        $this->fx->purchase('P97013', $this->vendor, '2097-02-08', 5000.0, 1050.0, 21.0, '40', 'cancelled');
        $this->fx->purchase('P97014', $this->vendor, '2097-03-04', 200.0, 42.0, 21.0, '40', 'paid');

        $purchase = $this->build()['purchase'];

        self::assertEquals(['year' => 2097, 'month' => 2, 'count' => 2, 'base' => 500.0, 'vat' => 105.0], $purchase['previous']);
        self::assertEquals(['year' => 2097, 'month' => 3, 'count' => 1, 'base' => 200.0, 'vat' => 42.0], $purchase['current']);
        self::assertSame($before['drafts'] + 1, $purchase['attention']['drafts']);
        self::assertSame($before['to_book'] + 1, $purchase['attention']['to_book']);
    }

    public function testUnpaidAfterTheDueDateCountsOnlyOpenReceivables(): void
    {
        $before = $this->build()['issued']['overdue'];
        $overdue = $this->fx->sale('A97020', $this->client, '2097-01-10', 1000.0, 210.0, 21.0);
        $paid = $this->fx->sale('A97021', $this->client, '2097-01-10', 1000.0, 210.0, 21.0, '1', 'paid');
        $notDue = $this->fx->sale('A97022', $this->client, '2097-03-20', 1000.0, 210.0, 21.0);
        // amount_to_pay is generated from the totals (1 210 Kč each); only the due dates differ.
        foreach ([$overdue, $paid, $notDue] as $id) {
            $this->fx->pdo->prepare('UPDATE invoices SET due_date = ? WHERE id = ?')
                ->execute([$id === $notDue ? '2097-03-30' : '2097-01-24', $id]);
        }

        $after = $this->build()['issued']['overdue'];

        self::assertSame($before['count'] + 1, $after['count']);
        self::assertEqualsWithDelta($before['total'] + 1210.0, $after['total'], 0.01);
        self::assertGreaterThanOrEqual(47, $after['oldest_days']); // 2097-01-24 .. 2097-03-12
    }

    public function testEmptySupplierGetsZerosInsteadOfErrors(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);

        $result = $this->build('2097-06-15');

        self::assertFalse($result['vat']['applicable']);
        foreach (['previous', 'current'] as $month) {
            self::assertSame(0, $result['issued'][$month]['count']);
            self::assertSame(0, $result['purchase'][$month]['count']);
            self::assertEquals(0.0, $result['issued'][$month]['base']);
        }
        self::assertSame('CZK', $result['currency']);
    }

    public function testObligationsListOnlyOpenWorkAndLeaveOutWhatIsFiled(): void
    {
        $dph = $this->fx->dphSubmission(2097, 2, null, 126);
        $this->fx->container->get(TaxSubmissionFilingService::class)
            ->markFiled($this->fx->supplierId, $dph, '2020-01-01 10:00', 'epo', null, null);

        $obligations = $this->build()['obligations'];

        self::assertLessThanOrEqual(AccountantDashboardService::OBLIGATION_LIMIT, count($obligations['items']));
        self::assertNotSame([], $obligations['items']);
        self::assertGreaterThanOrEqual(count($obligations['items']), $obligations['open_count']);
        foreach ($obligations['items'] as $item) {
            self::assertContains($item['status'], ['overdue', 'due_soon', 'upcoming']);
        }
        self::assertNotContains('dph:2097-02', array_column($obligations['items'], 'key'), 'a filed return is not open work');
        $due = array_column($obligations['items'], 'due_date');
        $sorted = $due;
        sort($sorted);
        self::assertSame($sorted, $due, 'the next obligations come in date order');
        $shownOverdue = count(array_filter($obligations['items'], static fn (array $i): bool => $i['status'] === 'overdue'));
        self::assertGreaterThanOrEqual($shownOverdue, $obligations['overdue_count']);
    }

    public function testMissedObligationsComeFirstAndAreCounted(): void
    {
        // Nothing of February is filed on 2097-03-30, so its returns are overdue.
        $obligations = $this->build('2097-03-30')['obligations'];

        $first = $obligations['items'][0];
        self::assertSame('overdue', $first['status']);
        self::assertGreaterThanOrEqual(1, $obligations['overdue_count']);
    }

    public function testObligationsAreLeftOutForARoleThatMayNotReadTheTimeline(): void
    {
        self::assertNull($this->build(withObligations: false)['obligations']);
    }
}
