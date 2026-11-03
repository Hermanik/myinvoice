<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Tax\RegistrationDeductionAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Service\Vat\RegistrationDeductionService;
use MyInvoice\Service\Vat\VatStatusException;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Agenda "Odpočet při registraci" (§ 79 / § 79a ZDPH): validation against the dated VAT status history, the amounts,
 * and the flow of the total into row 45 of the DPHDP3 (with the XML attributes EPO expects).
 *
 * Works around the registration on 1. 1. 2050 and the deregistration on 1. 1. 2052; every created row is removed in tearDown.
 */
#[Group('integration')]
final class RegistrationDeductionTest extends TestCase
{
    private ?FilingFixture $fx = null;
    private RegistrationDeductionService $service;
    private DphPriznaniBuilder $dph;

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        if (!$this->fx->db->hasTable('vat_registration_deductions')) {
            self::markTestSkipped('Migrace 9006 (vat_registration_deductions) není aplikovaná.');
        }
        $this->service = $this->fx->container->get(RegistrationDeductionService::class);
        $this->dph = $this->fx->container->get(DphPriznaniBuilder::class);
        $this->fx->configureSupplier(['dic' => 'CZ12345678', 'financial_office_code' => '451', 'taxpayer_type' => 'fo', 'vat_period' => 'monthly']);
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2050-01-01', 'payer']]);
    }

    protected function tearDown(): void
    {
        $this->fx?->restore();
    }

    private function sid(): int
    {
        return $this->fx->supplierId;
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function add(array $overrides = []): array
    {
        $item = $this->service->create($this->sid(), $overrides + [
            'kind' => 'registration', 'event_date' => '2050-01-01', 'description' => 'Zásoby materiálu', 'asset_type' => 'inventory',
            'acquired_on' => '2049-06-01', 'tax_base' => 10000, 'vat_amount' => 2100,
        ], $this->fx->userId);
        $this->fx->trackDeduction($item['id']);
        return $item;
    }

    /** @return array<string,mixed> */
    private function veta4(array $result): array
    {
        $xml = new \SimpleXMLElement($result['xml']);
        return $xml->DPHDP3->Veta4 === null ? [] : array_map('strval', iterator_to_array($xml->DPHDP3->Veta4->attributes()));
    }

    private function assertXsdPasses(string $xml): void
    {
        $validation = $this->fx->container->get(XmlSchemaValidator::class)->validate($xml, 'dphdp3');
        self::assertSame('passed', $validation['status'], implode('; ', $validation['errors']));
    }

    /** @param callable(): mixed $call */
    private function assertRefused(string $code, callable $call): void
    {
        try {
            $call();
            self::fail("Expected the error '{$code}'.");
        } catch (VatStatusException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    public function testOverviewDescribesTheRegistrationEvent(): void
    {
        $this->add();

        $overview = $this->service->overview($this->sid());

        self::assertCount(1, $overview['events']);
        $event = $overview['events'][0];
        self::assertSame('registration', $event['kind']);
        self::assertSame('2050-01-01', $event['date']);
        self::assertSame(['start' => '2049-01-01', 'end' => '2049-12-31'], $event['window']);
        self::assertSame(['year' => 2050, 'month' => 1, 'quarter' => null, 'start' => '2050-01-01', 'end' => '2050-01-31'], $event['return_period']);
        self::assertSame(2100.0, $event['total']);
        self::assertNull($event['filed_return']);
        self::assertSame([], $overview['orphaned']);
    }

    public function testRegistrationRowMustExistAndTheSupplyMustLieInTheTwelveMonthWindow(): void
    {
        $this->assertRefused('no_registration_event', fn () => $this->add(['event_date' => '2050-02-01']));
        $this->assertRefused('no_deregistration_event', fn () => $this->add(['kind' => 'deregistration', 'event_date' => '2050-01-01']));
        $this->assertRefused('outside_window', fn () => $this->add(['acquired_on' => '2048-12-31']));
        $this->assertRefused('outside_window', fn () => $this->add(['acquired_on' => '2050-01-01']));
        $this->assertRefused('validation_failed', fn () => $this->add(['description' => ' ']));
        $this->assertRefused('validation_failed', fn () => $this->add(['asset_type' => 'car']));
        $this->assertRefused('validation_failed', fn () => $this->add(['vat_amount' => 0]));
        $this->assertRefused('validation_failed', fn () => $this->add(['asset_type' => 'long_term_asset', 'adjustment_years' => 7]));
        $this->assertRefused('not_found', fn () => $this->add(['purchase_invoice_id' => 999999999]));

        // The edges of the window are inside.
        self::assertSame(2100.0, $this->add(['acquired_on' => '2049-01-01'])['deduction_amount']);
        self::assertSame(2100.0, $this->add(['acquired_on' => '2049-12-31'])['deduction_amount']);
    }

    public function testNoRegistrationRowMeansNoItems(): void
    {
        $this->fx->payer(); // payer since forever: no registration event
        $this->assertRefused('no_registration_event', fn () => $this->add());
    }

    public function testDeductionIsComputedByTheAssetType(): void
    {
        $inventory = $this->add();
        $longTerm = $this->add(['description' => 'Notebook', 'asset_type' => 'long_term_asset', 'acquired_on' => '2049-09-01', 'tax_base' => 100000, 'vat_amount' => 21000, 'adjustment_years' => 5]);
        $building = $this->add(['description' => 'Sklad', 'asset_type' => 'long_term_asset', 'acquired_on' => '2049-03-01', 'tax_base' => 1000000, 'vat_amount' => 210000, 'adjustment_years' => 10]);
        $notOwned = $this->add(['description' => 'Prodáno', 'in_business_assets' => false]);

        self::assertSame(2100.0, $inventory['deduction_amount']);
        self::assertSame(16800.0, $longTerm['deduction_amount'], '4 of 5 years are left');
        self::assertSame(189000.0, $building['deduction_amount'], '9 of 10 years are left');
        self::assertSame(0.0, $notOwned['deduction_amount'], 'no longer business property on the day of registration');
        self::assertSame(5, $longTerm['adjustment_years']);
        self::assertNull($inventory['adjustment_years']);
    }

    public function testTotalFlowsIntoRow45AndTheSumsOfTheFirstReturnAfterRegistration(): void
    {
        $this->add();
        $this->add(['description' => 'Notebook', 'asset_type' => 'long_term_asset', 'acquired_on' => '2049-09-01', 'tax_base' => 100000, 'vat_amount' => 21000]);
        $this->add(['description' => 'Prodáno', 'in_business_assets' => false, 'vat_amount' => 999]);

        $january = $this->dph->build($this->sid(), 2050, 1, 'monthly');

        $veta4 = $this->veta4($january);
        self::assertSame('18900', $veta4['odp_rez_nar'], 'row 45 "V plné výši": 2 100 + 16 800');
        self::assertSame('18900', $veta4['odp_sum_nar'], 'row 46 includes row 45');
        self::assertSame('100000', $veta4['nar_maj'], 'row 47: the base of the long-term asset claimed on registration');
        self::assertArrayNotHasKey('od_maj', $veta4);
        $veta6 = (new \SimpleXMLElement($january['xml']))->DPHDP3->Veta6;
        self::assertSame('18900', (string) $veta6['odp_zocelk'], 'row 63');
        self::assertSame('18900', (string) $veta6['dano_no'], 'excess deduction: nothing was sold');
        self::assertSame(18900.0, $january['summary']['lines']['45']['vat']);
        self::assertSame(-18900.0, $january['summary']['tax_due']);
        $this->assertXsdPasses($january['xml']);
    }

    public function testForecastAndTrendProjectionAgreeWithTheReturnOnTheDeduction(): void
    {
        $this->add();
        $this->add(['description' => 'Notebook', 'asset_type' => 'long_term_asset', 'acquired_on' => '2049-09-01', 'tax_base' => 100000, 'vat_amount' => 21000]);
        $mapper = $this->fx->container->get(\MyInvoice\Service\Report\VatClassificationMapper::class);

        $return = $this->dph->build($this->sid(), 2050, 1, 'monthly')['summary'];
        $forecast = $mapper->predictDph($this->sid(), 2050, 1, 'monthly');
        $projection = $mapper->dphSummaryTotals($mapper->aggregateForDphPriznani($this->sid(), 2050, 1));

        self::assertSame(18900.0, $return['total_vat_input']);
        self::assertSame($return['total_vat_input'], $forecast['vat_input'], 'the forecast of the first period includes row 45');
        self::assertSame($return['total_vat_input'], $projection['input'], 'so does the projection behind the trend');
        self::assertSame($return['tax_due'], $forecast['tax_due']);
    }

    public function testOnlyTheFirstPeriodAfterRegistrationCarriesTheDeduction(): void
    {
        $this->add();

        self::assertArrayNotHasKey('45', $this->dph->build($this->sid(), 2050, 2, 'monthly')['summary']['lines']);
        self::assertArrayNotHasKey('45', $this->dph->build($this->sid(), 2049, 12, 'monthly')['summary']['lines']);
    }

    public function testQuarterlyFilerClaimsItInTheQuarterOfTheRegistration(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2050-02-15', 'payer']]);
        $this->add(['event_date' => '2050-02-15', 'acquired_on' => '2049-03-01']);

        $quarter = $this->dph->build($this->sid(), 2050, 3, 'quarterly');

        self::assertSame('2100', $this->veta4($quarter)['odp_rez_nar']);
        self::assertArrayNotHasKey('45', $this->dph->build($this->sid(), 2050, 4, 'quarterly')['summary']['lines']);
        $this->assertXsdPasses($quarter['xml']);
    }

    public function testReportWarnsWhenARegistrationHasNoItemsAndStopsWhenItHas(): void
    {
        $warned = fn (array $r): bool => count(array_filter($r['warnings'], static fn (string $w): bool => str_contains($w, 'Odpočet při registraci'))) > 0;

        self::assertTrue($warned($this->dph->build($this->sid(), 2050, 1, 'monthly')));
        self::assertFalse($warned($this->dph->build($this->sid(), 2050, 2, 'monthly')), 'other periods are not concerned');

        $this->add();
        self::assertFalse($warned($this->dph->build($this->sid(), 2050, 1, 'monthly')));
    }

    public function testDeregistrationReductionIsNegativeInTheLastPeriodOfTheRegistration(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2050-01-01', 'payer'], ['2052-01-01', 'non_payer']]);
        $inventory = $this->add();
        $longTerm = $this->add(['description' => 'Notebook', 'asset_type' => 'long_term_asset', 'acquired_on' => '2049-09-01', 'tax_base' => 100000, 'vat_amount' => 21000]);
        $this->add(['description' => 'Prodáno', 'in_business_assets' => false]);

        $suggestions = $this->service->suggestReversals($this->sid(), '2052-01-01');

        self::assertCount(2, $suggestions, 'the item that was not in the business assets has no reduction');
        $byDescription = array_column($suggestions, null, 'description');
        self::assertSame(2100.0, $byDescription['Zásoby materiálu']['deduction_amount']);
        self::assertSame(12600.0, $byDescription['Notebook']['deduction_amount'], '3 of 5 years are left at 31. 12. 2051');
        self::assertSame($inventory['id'], $byDescription['Zásoby materiálu']['source_item_id']);

        foreach ($suggestions as $suggestion) {
            $created = $this->service->create($this->sid(), $suggestion, $this->fx->userId);
            $this->fx->trackDeduction($created['id']);
            self::assertSame('deregistration', $created['kind']);
        }
        self::assertSame([], $this->service->suggestReversals($this->sid(), '2052-01-01'), 'a created reduction is not offered again');
        $this->assertRefused('duplicate', fn () => $this->service->create($this->sid(), $suggestions[0], $this->fx->userId));
        $reductions = array_column($this->service->overview($this->sid())['events'][0]['items'], null, 'description');
        self::assertSame($longTerm['id'], $reductions['Notebook']['source_item_id'], 'the reduction points at the deduction it reverses');

        $last = $this->dph->build($this->sid(), 2051, 12, 'monthly');
        $veta4 = $this->veta4($last);
        self::assertSame('-14700', $veta4['odp_rez_nar'], 'negative in the last period of the registration');
        self::assertSame('-14700', $veta4['odp_sum_nar']);
        $veta6 = (new \SimpleXMLElement($last['xml']))->DPHDP3->Veta6;
        self::assertSame('-14700', (string) $veta6['odp_zocelk']);
        self::assertSame('14700', (string) $veta6['dano_da'], 'giving back the deduction is a tax to pay');
        self::assertArrayNotHasKey('nar_maj', $veta4, 'row 47 is only fed by the deduction on registration');
        $this->assertXsdPasses($last['xml']);

        // The registration deduction stays in January 2050, the reduction is not there.
        self::assertSame('18900', $this->veta4($this->dph->build($this->sid(), 2050, 1, 'monthly'))['odp_rez_nar'] ?? null, '2 100 + 16 800');
    }

    public function testManualReductionOfAnAssetBoughtDuringTheRegistration(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2050-01-01', 'payer'], ['2052-01-01', 'non_payer']]);

        $item = $this->service->create($this->sid(), [
            'kind' => 'deregistration', 'event_date' => '2052-01-01', 'description' => 'Monitor', 'asset_type' => 'other',
            'acquired_on' => '2051-10-15', 'tax_base' => 20000, 'vat_amount' => 4200,
        ], $this->fx->userId);
        $this->fx->trackDeduction($item['id']);

        self::assertSame(3150.0, $item['deduction_amount'], '9 whole months before the purchase: 4 200 / 12 x 9');
        $this->assertRefused('validation_failed', fn () => $this->service->create($this->sid(), [
            'kind' => 'deregistration', 'event_date' => '2052-01-01', 'description' => 'X', 'asset_type' => 'other',
            'acquired_on' => '2052-01-01', 'tax_base' => 1, 'vat_amount' => 1,
        ], $this->fx->userId));
    }

    public function testItemsOfAVanishedEventAreOrphanedAndStayOutOfTheReturn(): void
    {
        $this->add();
        $this->fx->vatHistory([['2000-01-01', 'non_payer'], ['2050-03-01', 'payer']]); // the registration moved

        $overview = $this->service->overview($this->sid());

        self::assertCount(1, $overview['orphaned']);
        self::assertSame(0, $this->service->forPeriod($this->sid(), '2050-01-01', '2050-01-31')['count']);
        self::assertArrayNotHasKey('45', $this->dph->build($this->sid(), 2050, 1, 'monthly')['summary']['lines']);
    }

    public function testItemCanBeEditedAndDeleted(): void
    {
        $item = $this->add();

        $edited = $this->service->update($this->sid(), $item['id'], [
            'description' => 'Zásoby (upraveno)', 'asset_type' => 'inventory', 'acquired_on' => '2049-07-01',
            'tax_base' => 20000, 'vat_amount' => 4200, 'note' => 'ručně',
        ]);
        self::assertSame(4200.0, $edited['deduction_amount']);
        self::assertSame('ručně', $edited['note']);
        self::assertSame('registration', $edited['kind']);

        $this->service->delete($this->sid(), $item['id']);
        $this->assertRefused('not_found', fn () => $this->service->delete($this->sid(), $item['id']));
        $this->assertRefused('not_found', fn () => $this->service->update($this->sid(), $item['id'], []));
    }

    public function testWritesAreAdminOnlyOverHttp(): void
    {
        $action = $this->fx->container->get(RegistrationDeductionAction::class);
        $body = ['kind' => 'registration', 'event_date' => '2050-01-01', 'description' => 'HTTP', 'asset_type' => 'inventory', 'acquired_on' => '2049-06-01', 'tax_base' => 1000, 'vat_amount' => 210];

        $denied = $action->create($this->request('POST', 'accountant', $body), new Response());
        self::assertSame(403, $denied->getStatusCode());

        $created = $action->create($this->request('POST', 'admin', $body), new Response());
        self::assertSame(201, $created->getStatusCode());
        $id = $this->json($created)['id'];
        $this->fx->trackDeduction($id);

        $refused = $action->create($this->request('POST', 'admin', ['acquired_on' => '2040-01-01'] + $body), new Response());
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('outside_window', $this->json($refused)['error']['code']);

        $list = $action->list($this->request('GET', 'readonly'), new Response());
        self::assertSame(200, $list->getStatusCode());
        self::assertSame($id, $this->json($list)['events'][0]['items'][0]['id']);

        self::assertSame(403, $action->delete($this->request('DELETE', 'readonly'), new Response(), ['id' => $id])->getStatusCode());
        self::assertSame(200, $action->delete($this->request('DELETE', 'admin'), new Response(), ['id' => $id])->getStatusCode());
    }

    private function request(string $method, string $role, ?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/tax/registration-deductions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->sid())
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fx->userId, 'role' => $role]);
        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
