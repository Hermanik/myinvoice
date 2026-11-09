<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Tax\TaxCalendarService;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard tax calendar: which deadlines a supplier gets for a monthly vs. quarterly VAT period,
 * for legal entities vs. natural persons, for years without VAT duties and with a flat-tax band,
 * and the filed badge. Dates are fixed in the year 2099 (25. 6. is a Thursday, 25. 7. a Saturday).
 */
#[Group('integration')]
final class TaxCalendarTest extends TestCase
{
    private ?FilingFixture $fx = null;
    private TaxCalendarService $calendar;
    private TaxSubmissionFilingService $filing;
    private bool $profileCreated = false;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $this->calendar = $this->fx->container->get(TaxCalendarService::class);
        $this->filing = $this->fx->container->get(TaxSubmissionFilingService::class);
        $this->fx->configureSupplier(['taxpayer_type' => 'fo', 'vat_period' => 'monthly', 'flat_tax_band' => 'none']);
        $this->fx->payer();
    }

    protected function tearDown(): void
    {
        if ($this->fx !== null && $this->profileCreated) {
            $this->fx->pdo->prepare('DELETE FROM tax_profiles WHERE supplier_id = ? AND year = 2099')->execute([$this->fx->supplierId]);
        }
        $this->fx?->restore();
    }

    /**
     * @param list<string>|null $types
     * @return array<string, array<string,mixed>> items keyed by their key
     */
    private function items(string $today, int $ahead = 60, ?array $types = null, int $back = 14): array
    {
        $out = [];
        foreach ($this->calendar->items($this->fx->supplierId, new \DateTimeImmutable($today), $ahead, $back, $types) as $item) {
            $out[$item['key']] = $item;
        }
        return $out;
    }

    public function testMonthlyPayerGetsDphAndKhForEveryMonth(): void
    {
        $items = $this->items('2099-06-10', 30, ['dph', 'kh']);

        self::assertSame(['dph:2099-05', 'kh:2099-05'], array_keys($items));
        self::assertSame('2099-06-25', $items['dph:2099-05']['due_date']);
        self::assertSame(15, $items['dph:2099-05']['days_left']);
        self::assertSame('/reports/dph', $items['dph:2099-05']['link']);
        self::assertSame('/reports/kh', $items['kh:2099-05']['link']);
        self::assertSame('unfiled', $items['dph:2099-05']['filing']['status']);

        $wide = $this->items('2099-06-10', 60, ['dph']);
        self::assertSame(['dph:2099-05', 'dph:2099-06'], array_keys($wide), 'June is due on Monday 27. 7.');
        self::assertSame('2099-07-27', $wide['dph:2099-06']['due_date']);
    }

    public function testQuarterlyNaturalPersonFilesDphAndKhQuarterly(): void
    {
        $this->fx->configureSupplier(['taxpayer_type' => 'fo', 'vat_period' => 'quarterly']);

        self::assertSame([], $this->items('2099-06-10', 30, ['dph', 'kh']), 'no deadline in the middle of a quarter');
        $july = $this->items('2099-07-10', 30, ['dph', 'kh']);
        self::assertSame(['dph:2099-Q2', 'kh:2099-Q2'], array_keys($july));
        self::assertSame('quarterly', $july['dph:2099-Q2']['period']);
        self::assertSame('2099-07-27', $july['dph:2099-Q2']['due_date']);
        self::assertSame(2, $july['kh:2099-Q2']['quarter']);
    }

    public function testQuarterlyLegalEntityFilesKhMonthlyButDphQuarterly(): void
    {
        $this->fx->configureSupplier(['taxpayer_type' => 'po', 'vat_period' => 'quarterly']);

        $june = $this->items('2099-06-10', 30, ['dph', 'kh']);
        self::assertSame(['kh:2099-05'], array_keys($june), 'a PO files KH monthly even when the VAT period is quarterly');
        $july = $this->items('2099-07-10', 20, ['dph', 'kh']);
        self::assertSame(['dph:2099-Q2', 'kh:2099-06'], array_keys($july));
    }

    public function testNoVatItemsForPeriodsWithoutVatDuties(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);
        self::assertSame([], $this->items('2099-06-10', 60, ['dph', 'kh', 'sh']), 'a non-payer year has no VAT deadlines');

        // Payer from 1. 7.: the June period has no duties, July is the first one.
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2099-07-01', 'payer']]);
        $items = $this->items('2099-06-20', 90, ['dph', 'kh']);
        self::assertSame(['dph:2099-07', 'kh:2099-07'], array_keys($items));
        self::assertSame('2099-08-25', $items['dph:2099-07']['due_date']);
    }

    public function testIdentifiedPersonFilesDphMonthlyButNoKh(): void
    {
        $this->fx->configureSupplier(['vat_period' => 'quarterly']);
        $this->fx->vatHistory([['2000-01-01', 'identified']]);

        $items = $this->items('2099-06-10', 30, ['dph', 'kh']);

        self::assertSame(['dph:2099-05'], array_keys($items), 'identified person: monthly return, no kontrolní hlášení');
    }

    public function testIncomeTaxReturnForTheNaturalPersonOrTheLegalEntity(): void
    {
        $items = $this->items('2099-03-20', 60, ['income_tax']);
        self::assertSame(['income_tax:2098'], array_keys($items));
        // The electronic filing (1 May moved past the holiday) is the main date, the paper one (1 April) the secondary.
        self::assertSame('2099-05-04', $items['income_tax:2098']['due_date']);
        self::assertSame('2099-04-01', $items['income_tax:2098']['paper_due_date']);
        self::assertSame(45, $items['income_tax:2098']['days_left']);
        self::assertSame('dpfdp5', $items['income_tax:2098']['form_code']);
        self::assertSame('/reports/income-tax', $items['income_tax:2098']['link']);

        $this->fx->configureSupplier(['taxpayer_type' => 'po']);
        self::assertSame('dppdp9', $this->items('2099-03-20', 60, ['income_tax'])['income_tax:2098']['form_code']);

        $this->fx->configureSupplier(['taxpayer_type' => null]);
        self::assertSame([], $this->items('2099-03-20', 60, ['income_tax']), 'unknown taxpayer type');
    }

    public function testIncomeTaxStaysOnTheCalendarAfterThePaperDeadlineUntilTheElectronicOne(): void
    {
        $items = $this->items('2099-04-10', 30, ['income_tax']);

        self::assertSame(['income_tax:2098'], array_keys($items));
        self::assertSame(24, $items['income_tax:2098']['days_left'], 'counted to the electronic deadline');
        self::assertSame(-6, $this->items('2099-05-10', 30, ['income_tax'])['income_tax:2098']['days_left'], 'still listed when missed');
    }

    public function testFlatTaxAdvanceOnlyInAYearWithABandAndWithoutVatDuties(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);
        self::assertSame([], $this->items('2099-05-10', 20, ['pausal']), 'no flat-tax band');

        $this->fx->configureSupplier(['flat_tax_band' => 'band1']);
        $items = $this->items('2099-05-10', 20, ['pausal']);
        self::assertSame(['pausal:2099-05'], array_keys($items));
        self::assertSame('2099-05-20', $items['pausal:2099-05']['due_date']);
        self::assertSame('band1', $items['pausal:2099-05']['band']);
        self::assertGreaterThan(0, $items['pausal:2099-05']['amount']);
        self::assertNull($items['pausal:2099-05']['filing'], 'an advance is not a filing');
        self::assertSame('2099-06-22', $this->items('2099-06-10', 20, ['pausal'])['pausal:2099-06']['due_date'], '20. 6. 2099 is a Saturday');

        // A year with payer days cannot be a flat-tax year, whatever the band says.
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2099-07-01', 'payer']]);
        self::assertSame([], $this->items('2099-05-10', 20, ['pausal']));

        // The per-year tax profile wins over the supplier setting.
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);
        $this->profileCreated = true;
        $this->fx->container->get(TaxProfileRepository::class)->upsert($this->fx->supplierId, 2099, ['activity_rate' => '60', 'flat_tax_band' => 'none']);
        self::assertSame([], $this->items('2099-05-10', 20, ['pausal']), 'the 2099 profile has no band');
    }

    public function testFiledBadgeAndFiledItemsDisappearAfterTheirDate(): void
    {
        $dph = $this->fx->submission('dphdp3', 2099, 5, null);
        $kh = $this->fx->submission('dphkh1', 2099, 5, null);
        $this->filing->markFiled($this->fx->supplierId, $dph, '2020-01-01 10:00', 'epo', null, null);
        $this->filing->markFiled($this->fx->supplierId, $kh, '2020-01-01 10:00', 'epo', null, null);
        $this->filing->attachReceipt($this->fx->supplierId, $kh, "%PDF-1.4\nx", 'a.pdf', null);

        $items = $this->items('2099-06-10', 30, ['dph', 'kh']);
        self::assertSame('filed', $items['dph:2099-05']['filing']['status']);
        self::assertSame($dph, $items['dph:2099-05']['filing']['submission_id']);
        self::assertSame('confirmed', $items['kh:2099-05']['filing']['status']);

        // After 25. 6. the filed ones are done; the unfiled June return is overdue only after 27. 7.
        $later = $this->items('2099-06-26', 30, ['dph', 'kh']);
        self::assertArrayNotHasKey('dph:2099-05', $later);
        self::assertArrayNotHasKey('kh:2099-05', $later);

        $this->filing->revertFiling($this->fx->supplierId, $dph, null);
        $overdue = $this->items('2099-06-26', 30, ['dph']);
        self::assertSame(-1, $overdue['dph:2099-05']['days_left'], 'an unfiled return stays while it is overdue');
        self::assertSame('unfiled', $overdue['dph:2099-05']['filing']['status']);
    }

    public function testItemsBetweenKeepsWhatTheDashboardCalendarDrops(): void
    {
        $dph = $this->fx->submission('dphdp3', 2099, 5, null);
        $this->filing->markFiled($this->fx->supplierId, $dph, '2020-01-01 10:00', 'epo', null, null);
        $now = new \DateTimeImmutable('2099-06-26');

        $between = [];
        foreach ($this->calendar->itemsBetween($this->fx->supplierId, $now, '2099-06-01', '2099-07-31', ['dph', 'kh']) as $item) {
            $between[$item['key']] = $item;
        }
        // The filed return of May (25. 6.) is past its date: the dashboard drops it, the timeline shows it done.
        self::assertSame(['dph:2099-05', 'kh:2099-05', 'dph:2099-06', 'kh:2099-06'], array_keys($between));
        self::assertSame('filed', $between['dph:2099-05']['filing']['status']);
        self::assertSame(-1, $between['dph:2099-05']['days_left']);
        self::assertSame(['kh:2099-05', 'dph:2099-06', 'kh:2099-06'], array_keys($this->items('2099-06-26', 40, ['dph', 'kh'])));

        // A flat-tax advance that is past stays in the range as well (nothing tracks its payment).
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);
        $this->fx->configureSupplier(['flat_tax_band' => 'band1']);
        $pausal = $this->calendar->itemsBetween($this->fx->supplierId, $now, '2099-06-01', '2099-07-31', ['pausal']);
        self::assertSame(['pausal:2099-06', 'pausal:2099-07'], array_column($pausal, 'key'));
        self::assertSame(-4, $pausal[0]['days_left'], '20. 6. 2099 is a Saturday: due Monday 22. 6.');
        self::assertSame(['pausal:2099-07'], array_keys($this->items('2099-06-26', 40, ['pausal'])));
    }

    public function testSouhrnneHlaseniOnlyForPeriodsWithEuSupplies(): void
    {
        self::assertSame([], $this->items('2099-06-10', 30, ['sh']), 'no EU supplies');

        $client = $this->fx->client('EU odběratel', $this->fx->skId, 'SK2020123456');
        $this->fx->sale('2099050001', $client, '2099-05-12', 1000, 0, 0, '20');
        $items = $this->items('2099-06-10', 30, ['sh']);
        self::assertSame(['sh:2099-05'], array_keys($items));
        self::assertSame('/reports/shv', $items['sh:2099-05']['link']);

        // A quarterly payer: goods make the month a monthly SH ...
        $this->fx->configureSupplier(['vat_period' => 'quarterly']);
        self::assertSame(['sh:2099-05'], array_keys($this->items('2099-06-10', 30, ['sh'])));
        self::assertSame([], $this->items('2099-07-10', 30, ['sh']), 'the quarterly SH is not due when the quarter has goods');
    }

    public function testQuarterlyPayerWithServicesOnlyFilesAQuarterlySouhrnneHlaseni(): void
    {
        $this->fx->configureSupplier(['vat_period' => 'quarterly']);
        $client = $this->fx->client('EU odběratel', $this->fx->skId, 'SK2020123456');
        $this->fx->sale('2099050002', $client, '2099-05-12', 1000, 0, 0, '22');

        self::assertSame([], $this->items('2099-06-10', 30, ['sh']), 'services only: no monthly SH');
        $july = $this->items('2099-07-10', 30, ['sh']);
        self::assertSame(['sh:2099-Q2'], array_keys($july));
        self::assertSame('quarterly', $july['sh:2099-Q2']['period']);
    }

    public function testQuarterWithGoodsAndAServicesOnlyMonthFilesMonthlyForBoth(): void
    {
        // § 102 ZDPH: goods in April make April a monthly SH; May has services only, but the quarter
        // contains goods, so May is reported in its own monthly SH too; there is no quarterly SH.
        $this->fx->configureSupplier(['vat_period' => 'quarterly']);
        $client = $this->fx->client('EU odběratel', $this->fx->skId, 'SK2020123456');
        $this->fx->sale('2099040001', $client, '2099-04-12', 1000, 0, 0, '20');
        $this->fx->sale('2099050001', $client, '2099-05-12', 1000, 0, 0, '22');

        $items = $this->items('2099-05-10', 80, ['sh']);

        self::assertSame(['sh:2099-04', 'sh:2099-05'], array_keys($items), 'no quarterly SH for a quarter with goods, no SH for the empty June');
        self::assertSame('monthly', $items['sh:2099-05']['period']);
        self::assertSame('2099-06-25', $items['sh:2099-05']['due_date']);
    }

    public function testQuarterOfServicesOnlyStillFilesOneQuarterlySouhrnneHlaseniEvenWithTwoServiceMonths(): void
    {
        $this->fx->configureSupplier(['vat_period' => 'quarterly']);
        $client = $this->fx->client('EU odběratel', $this->fx->skId, 'SK2020123456');
        $this->fx->sale('2099040002', $client, '2099-04-12', 1000, 0, 0, '22');
        $this->fx->sale('2099050002', $client, '2099-05-12', 1000, 0, 0, '22');

        self::assertSame(['sh:2099-Q2'], array_keys($this->items('2099-05-10', 80, ['sh'])));
    }

    public function testDashboardFeedUsesTheCalendarAndStopsOnceFiled(): void
    {
        $crm = $this->fx->container->get(CrmAggregationService::class);
        $titles = fn (): array => array_column(array_filter(
            $crm->actionItems($this->fx->supplierId, null, new \DateTimeImmutable('2099-06-20'))['items'],
            static fn (array $i): bool => in_array($i['type'], ['tax_deadline', 'kh_deadline'], true),
        ), 'title');

        self::assertSame(['DPH + KH za uplynulý měsíc'], array_values($titles()));

        $dph = $this->fx->submission('dphdp3', 2099, 5, null);
        $kh = $this->fx->submission('dphkh1', 2099, 5, null);
        $this->filing->markFiled($this->fx->supplierId, $dph, '2020-01-01 10:00', 'epo', null, null);
        self::assertSame(['DPH + KH za uplynulý měsíc'], array_values($titles()), 'KH is still open');
        $this->filing->markFiled($this->fx->supplierId, $kh, '2020-01-01 10:00', 'epo', null, null);
        self::assertSame([], array_values($titles()));
    }
}
