<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Action\Report\VatChecksAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Report\VatCrossCheckService;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Tests\Support\FilingFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * "Kontrola souladu" over the real DPH / KH / SH builders and the pre-export endpoint of the pages.
 * Isolated period: June 2050 (the endpoint accepts years up to 2050), own client + documents, everything removed in tearDown.
 */
#[Group('integration')]
final class VatCrossCheckTest extends TestCase
{
    private ?FilingFixture $fx = null;
    private VatCrossCheckService $check;
    private int $customer = 0;
    private int $vendor = 0;
    /** @var list<int> */
    private array $classificationIds = [];

    protected function setUp(): void
    {
        $this->fx = FilingFixture::boot($this);
        $this->check = $this->fx->container->get(VatCrossCheckService::class);
        $this->fx->payer();
        $this->fx->configureSupplier(['dic' => 'CZ12345678', 'financial_office_code' => '451', 'taxpayer_type' => 'fo', 'vat_period' => 'monthly']);
        $this->customer = $this->fx->client('Odběratel s DIČ', null, 'CZ11111118');
        $this->vendor = $this->fx->client('Dodavatel s DIČ', null, 'CZ22222220');
    }

    protected function tearDown(): void
    {
        if ($this->fx !== null) {
            foreach ($this->classificationIds as $id) {
                $this->fx->pdo->prepare('DELETE FROM vat_classifications WHERE id = ? AND supplier_id = ?')->execute([$id, $this->fx->supplierId]);
            }
        }
        $this->fx?->restore();
    }

    /** @return array<string, array<string,mixed>> */
    private function checks(array $result): array
    {
        return array_column($result['checks'], null, 'id');
    }

    private function consistentDocuments(?string $createdAt = null): void
    {
        $this->fx->sale('2050060001', $this->customer, '2050-06-10', 20_000, 4_200, 21, '1', 'issued', $createdAt); // A.4
        $this->fx->sale('2050060002', $this->customer, '2050-06-11', 5_000, 1_050, 21, '1', 'issued', $createdAt);  // A.5
        $this->fx->purchase('P-2050-001', $this->vendor, '2050-06-12', 10_000, 2_100, 21, '40', 'received', $createdAt);
        $this->fx->purchase('P-2050-002', $this->vendor, '2050-06-13', 2_000, 420, 21, '40', 'received', $createdAt);
    }

    public function testConsistentDocumentsGiveEqualTotalsInAllThreeOutputs(): void
    {
        $this->consistentDocuments();

        $result = $this->check->check($this->fx->supplierId, 2050, 6, 'monthly');

        self::assertTrue($result['applicable']);
        self::assertSame('ok', $result['status']);
        $checks = $this->checks($result);
        self::assertSame(['output_21', 'input_21'], array_keys($checks));
        self::assertSame(25_000.0, $checks['output_21']['dph']['base']);
        self::assertSame(25_000.0, $checks['output_21']['other']['base']);
        self::assertSame(5_250.0, $checks['output_21']['other']['vat']);
        self::assertSame(12_000.0, $checks['input_21']['other']['base']);
        self::assertSame([], $result['documents']);
    }

    public function testAClassificationThatDisagreesBetweenReturnAndKhIsFoundWithItsDocument(): void
    {
        $this->consistentDocuments();
        // A custom code: the return takes it to row 1, but its KH section says A.1 (reverse charge).
        $this->fx->pdo->prepare(
            'INSERT INTO vat_classifications (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, display_order, archived)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 999, 0)'
        )->execute([$this->fx->supplierId, 'XA', 'Test XA', 'sale', '1', 'A.1', 21.0]);
        $this->classificationIds[] = (int) $this->fx->pdo->lastInsertId();
        $this->fx->sale('2050060003', $this->customer, '2050-06-14', 15_000, 3_150, 21, 'XA');

        $result = $this->check->check($this->fx->supplierId, 2050, 6, 'monthly');
        $checks = $this->checks($result);

        self::assertSame('error', $result['status']);
        self::assertSame(40_000.0, $checks['output_21']['dph']['base'], 'the return has 20 000 + 5 000 + 15 000');
        self::assertSame(25_000.0, $checks['output_21']['other']['base'], 'the KH A.4 + A.5 miss the 15 000');
        self::assertSame(15_000.0, $checks['output_21']['diff']['base']);
        self::assertSame(3_150.0, $checks['output_21']['diff']['vat']);
        self::assertSame('error', $checks['output_21']['status']);
        self::assertSame(0.0, $checks['rc_supplier']['dph']['base']);
        self::assertSame(15_000.0, $checks['rc_supplier']['other']['base'], 'it landed in A.1 instead');

        self::assertCount(1, $result['documents']);
        $doc = $result['documents'][0];
        self::assertSame('2050060003', $doc['doc_number']);
        self::assertSame('missing_in_kh', $doc['problem']);
        self::assertSame('1', $doc['dph_line']);
        self::assertSame('A.1', $doc['kh_section']);
        self::assertSame(15_000.0, $doc['base_czk']);
        self::assertContains('output_21', $doc['checks']);
    }

    public function testEuSupplyWithoutVatIdIsInTheReturnButNotInTheSouhrnneHlaseni(): void
    {
        $euWithId = $this->fx->client('EU s DIČ', $this->fx->skId, 'SK2020123456');
        $euWithoutId = $this->fx->client('EU bez DIČ', $this->fx->skId, null);
        $this->fx->sale('2050060010', $euWithId, '2050-06-10', 30_000, 0, 0, '20');
        $this->fx->sale('2050060011', $euWithoutId, '2050-06-11', 12_000, 0, 0, '20');

        $result = $this->check->check($this->fx->supplierId, 2050, 6, 'monthly');
        $checks = $this->checks($result);

        self::assertSame(42_000.0, $checks['eu_goods']['dph']['base']);
        self::assertSame(30_000.0, $checks['eu_goods']['other']['base']);
        self::assertSame('SH', $checks['eu_goods']['other']['document']);
        self::assertSame('error', $checks['eu_goods']['status']);
        self::assertSame(['2050060011'], array_column($result['documents'], 'doc_number'));
        self::assertSame('missing_in_sh', $result['documents'][0]['problem']);
    }

    public function testIdentifiedPersonHasNothingToCompare(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'identified']]);

        $result = $this->check->check($this->fx->supplierId, 2050, 6, 'monthly');

        self::assertFalse($result['applicable']);
        self::assertSame('identified_person', $result['reason']);
    }

    public function testPreExportEndpointBundlesCrossCheckFilingChangesAndPayment(): void
    {
        $this->consistentDocuments('2019-12-31 10:00:00'); // booked before the return is filed (1. 1. 2020)
        $action = $this->fx->container->get(VatChecksAction::class);
        $get = function (array $query, string $role = 'readonly') use ($action): array {
            $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/reports/vat-checks')
                ->withQueryParams($query)
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->fx->supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fx->userId, 'role' => $role]);
            $response = $action($request, new Response());
            return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)];
        };

        $none = $get(['form' => 'dphdp3', 'year' => 2050, 'month' => 6]);
        self::assertSame(200, $none['status']);
        self::assertSame('ok', $none['body']['cross_check']['status']);
        self::assertNull($none['body']['filing']);
        self::assertNull($none['body']['changed_after_filing']);
        self::assertNull($none['body']['payment']);

        // File the return, then a document appears in its period.
        $id = $this->fx->dphSubmission(2050, 6, null, 31_000);
        $this->fx->container->get(TaxSubmissionFilingService::class)->markFiled($this->fx->supplierId, $id, '2020-01-01 10:00', 'epo', null, null);
        $this->fx->sale('2050060099', $this->customer, '2050-06-20', 1_000, 210, 21, '1');

        $filed = $get(['form' => 'dphdp3', 'year' => 2050, 'month' => 6]);
        self::assertSame($id, $filed['body']['filing']['id']);
        self::assertSame('filed', $filed['body']['filing']['filing_status']);
        self::assertSame(1, $filed['body']['changed_after_filing']['count']);
        self::assertSame('2050060099', $filed['body']['changed_after_filing']['items'][0]['doc_number']);
        self::assertSame(31_000, $filed['body']['payment']['amount']);
        self::assertStringStartsWith('data:image/png;base64,', $filed['body']['payment']['qr'] ?? '');

        // The KH of the same period has no filing; the payment card exists for the DPH return only.
        $kh = $get(['form' => 'dphkh1', 'year' => 2050, 'month' => 6]);
        self::assertNull($kh['body']['filing']);
        self::assertNull($kh['body']['payment']);

        self::assertSame(400, $get(['form' => 'oss', 'year' => 2050, 'month' => 6])['status']);
    }

    public function testNoCrossCheckForAPeriodWithoutVatDuties(): void
    {
        $this->fx->vatHistory([['2000-01-01', 'non_payer']]);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/reports/vat-checks')
            ->withQueryParams(['form' => 'dphdp3', 'year' => 2050, 'month' => 6])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->fx->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->fx->userId, 'role' => 'admin']);

        $response = $this->fx->container->get(VatChecksAction::class)($request, new Response());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($body['cross_check']['applicable']);
        self::assertSame('not_vat_payer_in_period', $body['cross_check']['reason']);
    }
}
