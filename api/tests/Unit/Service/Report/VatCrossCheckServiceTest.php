<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\VatCrossCheckService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The comparison rules of the "Kontrola souladu" panel — pure functions, no database. */
final class VatCrossCheckServiceTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function line(float $base, float $vat, int $count = 1): array
    {
        return ['base' => $base, 'vat' => $vat, 'count' => $count, 'label' => ''];
    }

    /** @return array<string,array<string,float>> */
    private static function khTotals(array $override = []): array
    {
        return array_replace_recursive([
            'a4_a5' => ['base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0],
            'b2_b3' => ['base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0],
            'a1'    => ['base' => 0.0],
            'b1'    => ['base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0],
            'a2'    => ['base' => 0.0, 'vat' => 0.0],
        ], $override);
    }

    /** @param list<array<string,mixed>> $checks @return array<string, array<string,mixed>> */
    private static function byId(array $checks): array
    {
        return array_column($checks, null, 'id');
    }

    public function testMatchingTotalsAreAllOk(): void
    {
        $checks = self::byId(VatCrossCheckService::buildChecks(
            ['1' => self::line(25_000, 5_250, 2), '40' => self::line(10_000, 2_100)],
            self::khTotals(['a4_a5' => ['base21' => 25_000, 'vat21' => 5_250], 'b2_b3' => ['base21' => 10_000, 'vat21' => 2_100]]),
            [],
        ));

        self::assertSame(['output_21', 'input_21'], array_keys($checks), 'pairs with nothing on either side are not listed');
        self::assertSame('ok', $checks['output_21']['status']);
        self::assertSame(['base' => 0.0, 'vat' => 0.0], $checks['output_21']['diff']);
        self::assertSame(['lines' => ['1'], 'base' => 25_000.0, 'vat' => 5_250.0], $checks['output_21']['dph']);
        self::assertSame('A.4 + A.5', $checks['output_21']['other']['section']);
    }

    public function testAMismatchShowsTheAmountsOnBothSides(): void
    {
        $checks = self::byId(VatCrossCheckService::buildChecks(
            ['40' => self::line(53_000, 11_130, 6)],
            self::khTotals(['b2_b3' => ['base21' => 42_000, 'vat21' => 8_820]]),
            [],
        ));

        $input = $checks['input_21'];
        self::assertSame('error', $input['status'], '11 000 Kč is above the 1 000 Kč tolerance');
        self::assertSame(['base' => 11_000.0, 'vat' => 2_310.0], $input['diff']);
        self::assertSame(53_000.0, $input['dph']['base']);
        self::assertSame(42_000.0, $input['other']['base']);
        self::assertSame(8_820.0, $input['other']['vat']);
    }

    #[DataProvider('severities')]
    public function testSeverityGrowsWithTheDifference(float $difference, int $documents, string $expected): void
    {
        self::assertSame($expected, VatCrossCheckService::severity($difference, $documents));
    }

    /** @return array<string, array{float, int, string}> */
    public static function severities(): array
    {
        return [
            'exact'                                  => [0.0, 1, 'ok'],
            'one koruna of rounding'                 => [1.0, 1, 'ok'],
            'two korunas, one document'              => [2.0, 1, 'warning'],
            'rounding of 20 documents allows 10'     => [10.0, 20, 'ok'],
            'just over the rounding of 20 documents' => [11.0, 20, 'warning'],
            'exactly the tolerance'                  => [1000.0, 5, 'warning'],
            'above the tolerance'                    => [1000.01, 5, 'error'],
        ];
    }

    public function testVatDifferenceAloneRaisesTheSeverity(): void
    {
        $checks = self::byId(VatCrossCheckService::buildChecks(
            ['1' => self::line(10_000, 2_100)],
            self::khTotals(['a4_a5' => ['base21' => 10_000, 'vat21' => 1_500]]),
            [],
        ));

        self::assertSame('warning', $checks['output_21']['status']);
        self::assertSame(['base' => 0.0, 'vat' => 600.0], $checks['output_21']['diff']);
    }

    public function testReverseChargeAndSelfAssessedPairsHaveTheirOwnChecks(): void
    {
        $checks = self::byId(VatCrossCheckService::buildChecks(
            [
                '25' => self::line(15_000, 0), '10' => self::line(9_000, 1_890), '11' => self::line(1_000, 120),
                '3' => self::line(8_000, 1_680), '5' => self::line(2_000, 420), '12' => self::line(500, 105),
            ],
            self::khTotals([
                'a1' => ['base' => 15_000], 'b1' => ['base21' => 9_000, 'vat21' => 1_890, 'base12' => 1_000, 'vat12' => 120],
                'a2' => ['base' => 10_500, 'vat' => 2_205],
            ]),
            [],
        ));

        self::assertSame(['rc_supplier', 'rc_recipient_21', 'rc_recipient_12', 'a2'], array_keys($checks));
        self::assertSame(['3', '4', '5', '6', '12', '13'], $checks['a2']['dph']['lines']);
        self::assertSame(10_500.0, $checks['a2']['dph']['base']);
        foreach ($checks as $check) {
            self::assertSame('ok', $check['status'], $check['id']);
        }
        self::assertNull($checks['rc_supplier']['dph']['vat'], 'the supplier side of a reverse charge carries no tax');
    }

    public function testEuSuppliesAreComparedWithTheSouhrnneHlaseni(): void
    {
        $checks = self::byId(VatCrossCheckService::buildChecks(
            ['20' => self::line(80_000, 0, 2), '21' => self::line(30_000, 0), '31' => self::line(5_000, 0)],
            self::khTotals(),
            [
                ['sh_type' => '0', 'amount' => 50_000.0], ['sh_type' => '0', 'amount' => 30_000.0],
                ['sh_type' => '3', 'amount' => 20_000.0], ['sh_type' => '2', 'amount' => 5_000.0],
            ],
        ));

        self::assertSame('ok', $checks['eu_goods']['status']);
        self::assertSame('error', $checks['eu_services']['status']);
        self::assertSame(10_000.0, $checks['eu_services']['diff']['base']);
        self::assertSame('SH', $checks['eu_services']['other']['document']);
        self::assertNull($checks['eu_services']['dph']['vat']);
        self::assertSame('ok', $checks['eu_triangular']['status']);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private static function row(array $overrides): array
    {
        return $overrides + [
            'source' => 'sale', 'invoice_id' => 1, 'doc_number' => 'FV1', 'vendor_invoice_number' => null,
            'tax_date' => '2027-01-15', 'counterparty_name' => 'Odběratel', 'counterparty_dic' => 'CZ11111118',
            'country_iso2' => 'CZ', 'country_is_eu' => 0, 'code' => '1', 'dphdp3_line' => '1', 'kh_section' => 'A.4',
            'is_reverse_charge' => false, 'vat_rate' => 21.0, 'base_czk' => 10_000.0, 'vat_czk' => 2_100.0,
        ];
    }

    public function testDocumentsRoutedDifferentlyAreNamedAsTheCause(): void
    {
        $docs = VatCrossCheckService::causingDocuments([
            self::row(['invoice_id' => 1, 'doc_number' => 'OK']),                                                         // consistent
            self::row(['invoice_id' => 2, 'doc_number' => 'WRONG-SECTION', 'kh_section' => 'A.1']),                       // row 1 but KH A.1
            self::row(['invoice_id' => 3, 'doc_number' => 'NOT-IN-KH', 'is_reverse_charge' => true, 'kh_section' => null]), // row 1 but out of KH
            self::row(['invoice_id' => 4, 'doc_number' => 'ZERO', 'base_czk' => 0.0, 'kh_section' => 'A.1']),            // nothing to report
            self::row(['source' => 'purchase', 'invoice_id' => 5, 'doc_number' => 'IN', 'dphdp3_line' => '40', 'kh_section' => 'B.2', 'vendor_invoice_number' => 'P-5']),
            self::row(['source' => 'purchase', 'invoice_id' => 6, 'doc_number' => 'B1-BUT-LINE-40', 'dphdp3_line' => '40', 'kh_section' => 'B.1']),
        ]);

        self::assertSame(['WRONG-SECTION', 'NOT-IN-KH', 'B1-BUT-LINE-40'], array_column($docs, 'doc_number'));
        self::assertSame(['missing_in_kh', 'missing_in_kh', 'missing_in_kh'], array_column($docs, 'problem'));
        self::assertSame(['output_21', 'rc_supplier'], $docs[0]['checks'], 'missing where the return has it, a surplus where it landed');
        self::assertSame('A.1', $docs[0]['kh_section']);
        self::assertNull($docs[1]['kh_section']);
        self::assertSame(['input_21', 'rc_recipient_21', 'rc_recipient_12'], $docs[2]['checks']);
    }

    public function testASurplusInTheKhIsReported(): void
    {
        $docs = VatCrossCheckService::causingDocuments([
            self::row(['invoice_id' => 7, 'doc_number' => 'KH-ONLY', 'dphdp3_line' => '50', 'kh_section' => 'A.4']),
        ]);

        self::assertSame('extra_in_kh', $docs[0]['problem']);
        self::assertSame(['output_21', 'output_12'], $docs[0]['checks']);
    }

    public function testEuSupplyThatCannotGoIntoTheSouhrnneHlaseniIsReported(): void
    {
        $eu = ['dphdp3_line' => '20', 'code' => '20', 'kh_section' => null, 'country_is_eu' => 1, 'country_iso2' => 'SK', 'vat_rate' => 0.0, 'is_reverse_charge' => false];
        $docs = VatCrossCheckService::causingDocuments([
            self::row(array_replace($eu, ['invoice_id' => 1, 'doc_number' => 'OK', 'counterparty_dic' => 'SK2020123456'])),
            self::row(array_replace($eu, ['invoice_id' => 2, 'doc_number' => 'NO-VAT-ID', 'counterparty_dic' => ''])),
            self::row(array_replace($eu, ['invoice_id' => 3, 'doc_number' => 'NOT-EU', 'country_is_eu' => 0, 'country_iso2' => 'US', 'counterparty_dic' => 'US123'])),
        ]);

        self::assertSame(['NO-VAT-ID', 'NOT-EU'], array_column($docs, 'doc_number'));
        self::assertSame(['missing_in_sh', 'missing_in_sh'], array_column($docs, 'problem'));
        self::assertSame(['eu_goods'], $docs[0]['checks']);
    }

    public function testKhRoutingOfLedgerRows(): void
    {
        $route = static fn (array $row): ?string => KontrolniHlaseniBuilder::routeRow($row);

        self::assertSame('A.1', $route(['source' => 'sale', 'kh_section' => 'A.1', 'dphdp3_line' => '25']));
        self::assertSame('A.2', $route(['source' => 'purchase', 'kh_section' => 'A.2', 'dphdp3_line' => '3', 'is_reverse_charge' => true]));
        self::assertSame('B.1', $route(['source' => 'purchase', 'kh_section' => 'B.1', 'dphdp3_line' => '10', 'is_reverse_charge' => true]));
        self::assertSame('DOM', $route(['source' => 'sale', 'kh_section' => 'A.4', 'dphdp3_line' => '1']));
        self::assertSame('DOM', $route(['source' => 'sale', 'kh_section' => null, 'dphdp3_line' => '1']));
        self::assertSame('DOM', $route(['source' => 'purchase', 'kh_section' => 'B.2', 'dphdp3_line' => '40']));
        self::assertNull($route(['source' => 'purchase', 'kh_section' => null, 'dphdp3_line' => null]), 'a purchase without the right to deduct');
        self::assertNull($route(['source' => 'sale', 'kh_section' => null, 'dphdp3_line' => '22', 'is_reverse_charge' => true]), 'export / EU supply');
    }
}
