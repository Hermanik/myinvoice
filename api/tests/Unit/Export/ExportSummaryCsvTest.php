<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Export;

use MyInvoice\Service\Export\ExportSummaryCsv;
use MyInvoice\Service\Export\ExportSummaryRow;
use PHPUnit\Framework\TestCase;

final class ExportSummaryCsvTest extends TestCase
{
    private function row(
        string $number = 'F-1',
        float $base = 1000.0,
        float $vat = 210.0,
        string $currency = 'CZK',
        ?float $rate = null,
        bool $counted = true,
        string $partner = 'Test s.r.o.',
        ?string $paidAt = '2026-02-10',
        string $state = 'Uhrazeno',
    ): ExportSummaryRow {
        return new ExportSummaryRow(
            number: $number, type: 'Faktura', partner: $partner, ic: '12345678', dic: 'CZ12345678',
            issueDate: '2026-01-05', taxDate: '2026-01-05', dueDate: '2026-01-19', paidAt: $paidAt,
            base: $base, vat: $vat, total: $base + $vat, currency: $currency, rate: $rate,
            state: $state, counted: $counted,
        );
    }

    /** @return list<list<string>> */
    private function parse(string $csv): array
    {
        $csv = substr($csv, 3); // BOM
        return array_map(
            static fn (string $line): array => str_getcsv($line, ';', '"', ''),
            explode("\r\n", rtrim($csv, "\r\n")),
        );
    }

    public function testUsesBomSemicolonCrlfAndCzechHeaders(): void
    {
        $csv = ExportSummaryCsv::render([$this->row()]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringEndsWith("\r\n", $csv);
        $lines = explode("\r\n", rtrim($csv, "\r\n"));
        self::assertSame(
            'Číslo dokladu;Typ;Partner;IČO;DIČ;Datum vystavení;DUZP;Splatnost;Datum úhrady;'
            . 'Základ bez DPH;DPH;Celkem s DPH;Měna;Celkem v CZK;Stav',
            substr($lines[0], 3),
        );
        self::assertCount(3, $lines, 'header + 1 document + totals');
    }

    public function testAmountsUseDecimalCommaAndTwoPlaces(): void
    {
        $rows = $this->parse(ExportSummaryCsv::render([$this->row(base: 12345.5, vat: 2592.56)]));

        self::assertSame('12345,50', $rows[1][9]);
        self::assertSame('2592,56', $rows[1][10]);
        self::assertSame('14938,06', $rows[1][11]);
        self::assertSame('CZK', $rows[1][12]);
        self::assertSame('', $rows[1][13], 'CZK documents leave the CZK column empty');
        self::assertSame('2026-02-10', $rows[1][8]);
    }

    public function testTotalsRowSumsCountedDocuments(): void
    {
        $rows = $this->parse(ExportSummaryCsv::render([
            $this->row('F-1', 1000.0, 210.0),
            $this->row('F-2', 500.10, 105.02),
            $this->row('D-1', -200.0, -42.0), // credit note
            $this->row('PRO-1', 9000.0, 1890.0, counted: false), // proforma listed, not summed
        ]));

        $totals = end($rows);
        self::assertSame('Celkem (v CZK)', $totals[0]);
        self::assertSame('1300,10', $totals[9]);
        self::assertSame('273,02', $totals[10]);
        self::assertSame('1573,12', $totals[11]);
        self::assertSame('CZK', $totals[12]);
        self::assertCount(1 + 4 + 1, $rows);
    }

    public function testForeignDocumentsAreConvertedByTheirRate(): void
    {
        $rows = $this->parse(ExportSummaryCsv::render([
            $this->row('F-CZK', 1000.0, 210.0),
            $this->row('F-EUR', 100.0, 0.0, 'EUR', 25.5),
        ]));

        self::assertSame('100,00', $rows[2][9], 'document columns stay in the document currency');
        self::assertSame('EUR', $rows[2][12]);
        self::assertSame('2550,00', $rows[2][13], 'CZK total of a foreign document');
        $totals = end($rows);
        self::assertSame('3550,00', $totals[9]);
        self::assertSame('210,00', $totals[10]);
        self::assertSame('3760,00', $totals[11]);
    }

    public function testForeignDocumentWithoutRateIsListedButLeftOutOfTotals(): void
    {
        $rows = [$this->row('F-CZK', 1000.0, 0.0), $this->row('F-USD', 50.0, 0.0, 'USD', null)];
        $parsed = $this->parse(ExportSummaryCsv::render($rows));

        self::assertSame('', $parsed[2][13]);
        self::assertSame('1000,00', end($parsed)[9]);
        self::assertSame(1, ExportSummaryCsv::unconvertibleCount($rows));
    }

    public function testOutsideSectionHasOwnHeaderAndTotals(): void
    {
        $csv = ExportSummaryCsv::render(
            [$this->row('F-1', 1000.0, 0.0)],
            [$this->row('F-2', 300.0, 0.0, paidAt: null, state: 'Neuhrazeno'), $this->row('F-3', 200.0, 0.0, paidAt: '2027-01-04', state: 'Uhrazeno po období')],
            'Mimo období',
        );
        $rows = $this->parse($csv);

        // header, 1 doc, totals, blank, title, header, 2 docs, totals
        self::assertCount(9, $rows);
        self::assertSame(['Mimo období'], $rows[4]);
        self::assertSame('Číslo dokladu', $rows[5][0]);
        self::assertSame('Neuhrazeno', $rows[6][14]);
        self::assertSame('', $rows[6][8], 'unpaid document has no payment date');
        self::assertSame('500,00', $rows[8][9]);
    }

    public function testNoOutsideSectionForTheDuzpBasis(): void
    {
        $rows = $this->parse(ExportSummaryCsv::render([$this->row()], null));

        self::assertCount(3, $rows);
    }

    public function testCellsWithSeparatorOrQuoteAreQuotedAndFormulasNeutralised(): void
    {
        $csv = ExportSummaryCsv::render([
            $this->row('F-1', partner: 'Novák; "Vše" s.r.o.'),
            $this->row('F-2', partner: '=HYPERLINK("http://x")'),
        ]);
        $rows = $this->parse($csv);

        self::assertSame('Novák; "Vše" s.r.o.', $rows[1][2]);
        self::assertSame("'=HYPERLINK(\"http://x\")", $rows[2][2], 'a leading = would be evaluated by Excel');
        self::assertStringContainsString('"Novák; ""Vše"" s.r.o."', $csv);
    }

    public function testEmptyDocumentListStillHasHeaderAndZeroTotals(): void
    {
        $rows = $this->parse(ExportSummaryCsv::render([]));

        self::assertCount(2, $rows);
        self::assertSame('0,00', $rows[1][11]);
    }

    public function testManyRowsDoNotDriftByACent(): void
    {
        $docs = [];
        for ($i = 0; $i < 300; $i++) {
            $docs[] = $this->row('F-' . $i, 0.1, 0.0);
        }
        $rows = $this->parse(ExportSummaryCsv::render($docs));
        $totals = end($rows);

        self::assertSame('30,00', $totals[9]);
    }
}
