<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tax;

use MyInvoice\Service\Tax\FinancialOfficeAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The VAT account codebook is checked against the figures the Finanční správa publishes
 * (Příloha č. 4 "Bankovní účty FÚ nejčastěji placených daní" and Příloha č. 5 with the IBANs, state 1. 1. 2026).
 */
final class FinancialOfficeAccountsTest extends TestCase
{
    /** @return array<string, array{string, string, string, string}> c_ufo, display, IBAN, name */
    public static function officialAccounts(): array
    {
        return [
            'Praha'             => ['451', '705-77628031/0710', 'CZ74071000070500776280' . '31', 'Finanční úřad pro hlavní město Prahu'],
            'Středočeský'       => ['452', '705-77628111/0710', 'CZ48071000070500776281' . '11', 'Finanční úřad pro Středočeský kraj'],
            'Jihočeský'         => ['453', '705-77627231/0710', 'CZ43071000070500776272' . '31', 'Finanční úřad pro Jihočeský kraj'],
            'Plzeňský'          => ['454', '705-77627311/0710', 'CZ17071000070500776273' . '11', 'Finanční úřad pro Plzeňský kraj'],
            'Karlovarský'       => ['455', '705-77629341/0710', 'CZ12071000070500776293' . '41', 'Finanční úřad pro Karlovarský kraj'],
            'Ústecký'           => ['456', '705-77621411/0710', 'CZ43071000070500776214' . '11', 'Finanční úřad pro Ústecký kraj'],
            'Liberecký'         => ['457', '705-77628461/0710', 'CZ07071000070500776284' . '61', 'Finanční úřad pro Liberecký kraj'],
            'Královéhradecký'   => ['458', '705-77626511/0710', 'CZ83071000070500776265' . '11', 'Finanční úřad pro Královéhradecký kraj'],
            'Pardubický'        => ['459', '705-77622561/0710', 'CZ33071000070500776225' . '61', 'Finanční úřad pro Pardubický kraj'],
            'Vysočina'          => ['460', '705-67626681/0710', 'CZ67071000070500676266' . '81', 'Finanční úřad pro Kraj Vysočina'],
            'Jihomoravský'      => ['461', '705-77628621/0710', 'CZ52071000070500776286' . '21', 'Finanční úřad pro Jihomoravský kraj'],
            'Olomoucký'         => ['462', '705-47623811/0710', 'CZ84071000070500476238' . '11', 'Finanční úřad pro Olomoucký kraj'],
            'Moravskoslezský'   => ['463', '705-77621761/0710', 'CZ02071000070500776217' . '61', 'Finanční úřad pro Moravskoslezský kraj'],
            'Zlínský'           => ['464', '705-47620661/0710', 'CZ65071000070500476206' . '61', 'Finanční úřad pro Zlínský kraj'],
            'Specializovaný'    => ['13',  '705-77620021/0710', 'CZ34071000070500776200' . '21', 'Specializovaný finanční úřad'],
        ];
    }

    #[DataProvider('officialAccounts')]
    public function testEveryOfficeMatchesThePublishedAccountAndIban(string $code, string $display, string $iban, string $name): void
    {
        $account = FinancialOfficeAccounts::vatAccount($code);

        self::assertNotNull($account);
        self::assertSame($display, $account['display']);
        self::assertSame($iban, $account['iban'], 'the IBAN check digits must equal Příloha č. 5');
        self::assertSame($name, $account['office_name']);
        self::assertSame('705', $account['prefix']);
        self::assertSame('0710', $account['bank_code']);
    }

    public function testTheCodebookCoversExactlyTheFifteenOffices(): void
    {
        $expected = array_map(static fn (array $row): string => $row[0], array_values(self::officialAccounts()));
        $actual = FinancialOfficeAccounts::codes();
        sort($expected);
        sort($actual);

        self::assertCount(15, $actual);
        self::assertSame($expected, $actual);
    }

    public function testCodeNormalisationAndUnknownCodes(): void
    {
        self::assertSame('451', FinancialOfficeAccounts::office(' 451 ')['code']);
        self::assertSame('13', FinancialOfficeAccounts::office('013')['code'], 'leading zeros are ignored');
        self::assertNull(FinancialOfficeAccounts::vatAccount('2001'), 'a territorial workplace code (c_pracufo) is not an office');
        self::assertNull(FinancialOfficeAccounts::vatAccount('465'));
        self::assertNull(FinancialOfficeAccounts::vatAccount(''));
        self::assertNull(FinancialOfficeAccounts::vatAccount(null));
    }

    #[DataProvider('variableSymbols')]
    public function testVariableSymbolIsTheDicRoot(?string $dic, ?string $expected): void
    {
        self::assertSame($expected, FinancialOfficeAccounts::variableSymbol($dic));
    }

    /** @return array<string, array{?string, ?string}> */
    public static function variableSymbols(): array
    {
        return [
            'legal entity'             => ['CZ12345678', '12345678'],
            'natural person (RČ)'      => ['CZ6812240000', '6812240000'],
            'lower case + spaces'      => [' cz 1234 5678 ', '12345678'],
            'without the CZ prefix'    => ['12345678', '12345678'],
            'foreign VAT ID'           => ['SK2020123456', null],
            'missing'                  => [null, null],
            'garbage'                  => ['CZ12AB', null],
        ];
    }

    #[DataProvider('statementAccounts')]
    public function testStatementAccountsAreRecognised(string $raw, ?string $bank, bool $expected): void
    {
        $account = FinancialOfficeAccounts::vatAccount('451');
        self::assertSame($expected, FinancialOfficeAccounts::accountMatches($raw, $bank, $account));
    }

    /** @return array<string, array{string, ?string, bool}> */
    public static function statementAccounts(): array
    {
        return [
            'dash form'                    => ['705-77628031', '0710', true],
            'dash form with bank suffix'   => ['705-77628031/0710', null, true],
            'padded 16-digit GPC form'     => ['0007050077628031', '0710', true],
            'czech IBAN'                   => ['CZ7407100007050077628031', null, true],
            'bank unknown on the notice'   => ['705-77628031', null, true],
            'other bank'                   => ['705-77628031', '0800', false],
            'other office'                 => ['705-77628111', '0710', false],
            'other tax (DPFO 721)'         => ['721-77628031', '0710', false],
            'no prefix'                    => ['77628031', '0710', false],
            'empty'                        => ['', '0710', false],
        ];
    }

    public function testParseAccount(): void
    {
        self::assertSame(['prefix' => '705', 'number' => '77628031', 'bank_code' => '0710'], FinancialOfficeAccounts::parseAccount('0007050077628031', '0710'));
        self::assertSame(['prefix' => '', 'number' => '123456789', 'bank_code' => null], FinancialOfficeAccounts::parseAccount('123456789'));
        self::assertNull(FinancialOfficeAccounts::parseAccount('   '));
        self::assertNull(FinancialOfficeAccounts::parseAccount('000-000'));
    }
}
