<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\DphSupplementaryDifference as Diff;
use PHPUnit\Framework\TestCase;

/** Arithmetic of the supplementary DPH return (form D): differences against the last known tax, § 141 DŘ. */
final class DphSupplementaryDifferenceTest extends TestCase
{
    /** @return array{base:float, vat:float, count:int, label:string} */
    private static function row(float $base, float $vat = 0.0, int $count = 1): array
    {
        return ['base' => $base, 'vat' => $vat, 'count' => $count, 'label' => 'row'];
    }

    public function testOnlyChangedRowsAreListedWithTheirDifferences(): void
    {
        $known = ['1' => self::row(8000, 1680), '40' => self::row(5000, 1050), '47' => self::row(20000)];
        $current = ['1' => self::row(10000, 2100, 2), '40' => self::row(5000, 1050), '47' => self::row(20000)];

        $diff = Diff::compute($current, $known);

        self::assertSame(['1'], array_map('strval', array_keys($diff['lines'])));
        self::assertSame(2000.0, $diff['lines']['1']['base']);
        self::assertSame(420.0, $diff['lines']['1']['vat']);
        self::assertSame(2, $diff['lines']['1']['count']);
        self::assertSame(['output' => 420, 'input' => 0, 'tax' => 420], ['output' => $diff['output'], 'input' => $diff['input'], 'tax' => $diff['tax']]);
    }

    public function testDifferencesAreComputedInWholeCrownsLikeTheForm(): void
    {
        // 2100.4 -> 2100 and 1680.6 -> 1681 on the two returns: the difference is 419, not 419.8.
        $diff = Diff::compute(['1' => self::row(10000.4, 2100.4)], ['1' => self::row(8000.0, 1680.6)]);

        self::assertSame(419.0, $diff['lines']['1']['vat']);
        self::assertSame(419, $diff['tax']);
    }

    public function testReductionOfTheTaxIsANegativeRow66(): void
    {
        $known = ['1' => self::row(10000, 2100), '40' => self::row(2000, 420)];
        $current = ['1' => self::row(10000, 2100), '40' => self::row(6000, 1260)];

        $diff = Diff::compute($current, $known);

        self::assertSame(840.0, $diff['lines']['40']['vat']);
        self::assertSame(840, $diff['input']);
        self::assertSame(-840, $diff['tax'], 'more deduction = lower tax = a negative difference');
    }

    public function testRowThatDisappearedIsReportedWithTheOppositeSign(): void
    {
        $diff = Diff::compute([], ['2' => self::row(1000, 120)]);

        self::assertSame(-1000.0, $diff['lines']['2']['base']);
        self::assertSame(-120.0, $diff['lines']['2']['vat']);
        self::assertSame(-120, $diff['tax']);
    }

    public function testBaseOnlyRowsChangeNoTax(): void
    {
        $diff = Diff::compute(['47' => self::row(50000, 0), '20' => self::row(300)], ['47' => self::row(20000, 0)]);

        self::assertSame(['20', '47'], array_map('strval', array_keys($diff['lines'])));
        self::assertSame(30000.0, $diff['lines']['47']['base']);
        self::assertSame(0, $diff['tax']);
    }

    public function testNothingChangedGivesNoRows(): void
    {
        $lines = ['1' => self::row(10000, 2100), '40' => self::row(3000, 630)];

        self::assertSame([], Diff::compute($lines, $lines)['lines']);
    }

    public function testRow45DeregistrationReductionEntersTheDeductionTotal(): void
    {
        $current = ['40' => self::row(1000, 210), '45' => self::row(0, -5000)];
        $known = ['40' => self::row(1000, 210)];

        $diff = Diff::compute($current, $known);

        self::assertSame(-5000, $diff['input']);
        self::assertSame(5000, $diff['tax'], 'a smaller deduction raises the tax');
    }

    public function testKnownLinesOfASupplementaryReturnAreItsCumulativeState(): void
    {
        $b = ['summary' => ['lines' => ['1' => self::row(100, 21)]]];
        $d = ['summary' => ['lines' => ['1' => self::row(50, 10)], 'full_lines' => ['1' => self::row(150, 31)]]];

        self::assertSame(100.0, Diff::knownLines($b)['1']['base']);
        self::assertSame(150.0, Diff::knownLines($d)['1']['base'], 'a D is compared by what it brought the tax to, not by its own differences');
        self::assertSame([], Diff::knownLines([]));
    }

    public function testReasonIsWrappedToTheLinesOfTheTextAttachment(): void
    {
        $lines = Diff::reasonLines("Při kontrole jsem zjistil, že faktura FP-2026-0042 od dodavatele nebyla zahrnuta do přiznání.\n\nOprava je doložená dobropisem.");

        self::assertSame([
            'Při kontrole jsem zjistil, že faktura FP-2026-0042 od dodavatele nebyla',
            'zahrnuta do přiznání.',
            'Oprava je doložená dobropisem.',
        ], $lines);
        foreach ($lines as $line) {
            self::assertLessThanOrEqual(Diff::REASON_LINE_LENGTH, mb_strlen($line));
        }
    }

    public function testAnOverlongWordIsCutAndBlankReasonGivesNoLines(): void
    {
        $lines = Diff::reasonLines(str_repeat('x', 150));

        self::assertSame([str_repeat('x', 72), str_repeat('x', 72), str_repeat('x', 6)], $lines);
        self::assertSame([], Diff::reasonLines("  \n "));
    }

    public function testDeadlineIsTheEndOfTheMonthAfterTheDiscoveryMovedToAWorkingDay(): void
    {
        self::assertSame('2026-11-30', Diff::deadline('2026-10-15'), '30. 11. 2026 is a Monday');
        self::assertSame('2027-02-01', Diff::deadline('2026-12-05'), '31. 1. 2027 is a Sunday');
        self::assertSame('2028-02-29', Diff::deadline('2028-01-31'), 'leap year');
    }
}
