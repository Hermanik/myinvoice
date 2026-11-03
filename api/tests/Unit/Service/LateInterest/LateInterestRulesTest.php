<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\LateInterest;

use MyInvoice\Service\Invoice\InvoiceVatAdvisor;
use MyInvoice\Service\LateInterest\CnbRepoRateFetcher;
use MyInvoice\Service\LateInterest\LateInterestService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Opt-in precedence, the business-customer test for the fixed cost and the CNB file parser (no database). */
final class LateInterestRulesTest extends TestCase
{
    /** @return array<string, array{?bool, ?bool, bool, bool, string}> */
    public static function precedence(): array
    {
        return [
            'nothing set: off by default'                       => [null, null, false, false, 'supplier'],
            'supplier opt-in'                                   => [null, null, true, true, 'supplier'],
            'client on beats supplier off'                      => [null, true, false, true, 'client'],
            'client off beats supplier on'                      => [null, false, true, false, 'client'],
            'invoice on beats client off'                       => [true, false, false, true, 'invoice'],
            'invoice off beats client on and supplier on'       => [false, true, true, false, 'invoice'],
            'invoice inherits (null), client decides'           => [null, false, true, false, 'client'],
        ];
    }

    #[DataProvider('precedence')]
    public function testOptInPrecedenceIsInvoiceThenClientThenSupplier(?bool $invoice, ?bool $client, bool $supplier, bool $enabled, string $source): void
    {
        self::assertSame(
            ['enabled' => $enabled, 'source' => $source],
            LateInterestService::resolveEnabled($invoice, $client, $supplier),
        );
    }

    #[DataProvider('clients')]
    public function testBusinessClientDetectionForTheFixedCost(array $client, bool $business): void
    {
        self::assertSame($business, LateInterestService::isBusinessClient($client));
    }

    /** @return array<string, array{array<string,mixed>, bool}> */
    public static function clients(): array
    {
        return [
            'company ID'                       => [['ic' => '12345678'], true],
            'VAT ID only (foreign business)'   => [['ic' => null, 'dic' => 'DE123456789'], true],
            'national tax number only'         => [['tax_number' => '123/456/78901'], true],
            'private person: no identifiers'   => [['ic' => '', 'dic' => null, 'tax_number' => '  '], false],
            'empty record'                     => [[], false],
        ];
    }

    public function testParsesTheOfficialCnbFile(): void
    {
        $body = "\xEF\xBB\xBFPLATNA_OD|CNB_REPO_SAZBA_V_%\n20250509|3,50\r\n20260619|3,75\n\nnot a row\n20250230|1,00\n";

        self::assertSame(
            [
                ['valid_from' => '2025-05-09', 'rate_percent' => 3.5],
                ['valid_from' => '2026-06-19', 'rate_percent' => 3.75],
            ],
            CnbRepoRateFetcher::parse($body),
            'BOM, header, blank lines, junk and the impossible date 30.2. are skipped; decimal comma is read',
        );
    }

    public function testParsedRowsAreSortedAscending(): void
    {
        $rows = CnbRepoRateFetcher::parse("20260619|3,75\n20250509|3,50\n");

        self::assertSame(['2025-05-09', '2026-06-19'], array_column($rows, 'valid_from'));
    }

    public function testOutsideVatScopeClassificationCodeMatchesTheMigration(): void
    {
        self::assertSame('MPD', InvoiceVatAdvisor::OUTSIDE_VAT_SCOPE);
        self::assertStringContainsString("'MPD'", (string) file_get_contents(dirname(__DIR__, 5) . '/db/migrations/9005_late_interest.sql'));
    }
}
