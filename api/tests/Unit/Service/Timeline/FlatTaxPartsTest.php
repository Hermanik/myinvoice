<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Timeline;

use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\Timeline\FlatTaxParts;
use PHPUnit\Framework\TestCase;

/**
 * The parts of a flat-tax payment (tax 100 Kč, health, social) derived from the constants of the year, and what
 * the payments of a year credit. Reference: 2026, band 1 9 984 Kč until June and 9 162 Kč from July.
 */
final class FlatTaxPartsTest extends TestCase
{
    /** @return array<string,mixed> */
    private function constants(): array
    {
        return TaxConstants::forYear(2026);
    }

    public function testBandOneIsTaxPlusMinimumHealthPlusTheRestAsSocial(): void
    {
        // Minimum health advance 2026: 293 802 / 12 × 13.5 % = 3 305.27 → 3 306.
        self::assertSame(['income_tax' => 100, 'health' => 3306, 'social' => 6578], FlatTaxParts::split(9984, $this->constants()));
        self::assertSame(['income_tax' => 100, 'health' => 3306, 'social' => 5756], FlatTaxParts::split(9162, $this->constants()), 'only the pension part fell on 1. 7. 2026');
    }

    public function testHigherBandsSplitTheRestInTheProportionOfTheInsuranceRates(): void
    {
        $c = $this->constants();
        // health 13.5 % × 50 % of the profit against social 29.2 % × 55 %: 0.0675 / (0.0675 + 0.1606) = 29.6 % of the rest.
        foreach ([16745 => 4926, 27139 => 8001] as $monthly => $health) {
            $parts = FlatTaxParts::split((float) $monthly, $c);
            self::assertSame(100, $parts['income_tax'], (string) $monthly);
            self::assertSame($health, $parts['health'], (string) $monthly);
            self::assertSame($monthly, array_sum($parts), 'the parts add up to the payment');
        }
    }

    public function testThePartsAlwaysAddUpToThePayment(): void
    {
        foreach ([50.0, 100.0, 3500.0, 9162.0, 12345.0] as $monthly) {
            $parts = FlatTaxParts::split($monthly, $this->constants());
            self::assertSame((int) round($monthly), array_sum($parts), (string) $monthly);
            self::assertGreaterThanOrEqual(0, min($parts), (string) $monthly);
        }
    }

    public function testAYearOfPaymentsCreditsTheChangeOfTheRateInTheMiddleOfIt(): void
    {
        $credited = FlatTaxParts::credited(2026, 'band1', $this->constants(), range(1, 12));

        // 6 × 9 984 + 6 × 9 162 = 114 876 = 12 × 100 + 12 × 3 306 + (6 × 6 578 + 6 × 5 756)
        self::assertSame(['income_tax' => 1200, 'health' => 39672, 'social' => 74004, 'total' => 114876], $credited);
    }

    public function testOnlyTheGivenMonthsAreCredited(): void
    {
        $credited = FlatTaxParts::credited(2026, 'band1', $this->constants(), [6, 7]);

        self::assertSame(['income_tax' => 200, 'health' => 6612, 'social' => 12334, 'total' => 19146], $credited, 'June 9 984 + July 9 162');
        self::assertSame(0, FlatTaxParts::credited(2026, 'band1', $this->constants(), [])['total']);
    }
}
