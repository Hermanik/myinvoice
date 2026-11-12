<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Export;

use MyInvoice\Service\Export\ExportBasis;
use MyInvoice\Service\Export\ExportPeriodResolver;
use PHPUnit\Framework\TestCase;

final class ExportPeriodResolverTest extends TestCase
{
    private ExportPeriodResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ExportPeriodResolver();
    }

    public function testLegacyMonthParameterResolvesMonthlyPeriod(): void
    {
        $period = $this->resolver->resolve(['month' => '2026-06']);

        self::assertSame('monthly', $period->type);
        self::assertSame(2026, $period->year);
        self::assertSame(6, $period->month);
        self::assertNull($period->quarter);
        self::assertSame('2026-06-01', $period->dateFrom);
        self::assertSame('2026-07-01', $period->dateToExclusive);
        self::assertSame('2026-06', $period->label);
    }

    public function testExplicitMonthlyPeriodResolvesNumericMonth(): void
    {
        $period = $this->resolver->resolve([
            'period' => 'monthly',
            'year' => '2026',
            'month' => '4',
        ]);

        self::assertSame('monthly', $period->type);
        self::assertSame(2026, $period->year);
        self::assertSame(4, $period->month);
        self::assertSame('2026-04-01', $period->dateFrom);
        self::assertSame('2026-05-01', $period->dateToExclusive);
        self::assertSame('2026-04', $period->label);
    }

    public function testQuarterlyPeriodResolvesWholeQuarter(): void
    {
        $period = $this->resolver->resolve([
            'period' => 'quarterly',
            'year' => '2026',
            'quarter' => '2',
        ]);

        self::assertSame('quarterly', $period->type);
        self::assertSame(2026, $period->year);
        self::assertNull($period->month);
        self::assertSame(2, $period->quarter);
        self::assertSame('2026-04-01', $period->dateFrom);
        self::assertSame('2026-07-01', $period->dateToExclusive);
        self::assertSame('2026-Q2', $period->label);
    }

    public function testInvalidQuarterFailsValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Parametr quarter musí být 1 až 4.');

        $this->resolver->resolve([
            'period' => 'quarterly',
            'year' => '2026',
            'quarter' => '5',
        ]);
    }

    public function testYearlyPeriodResolvesWholeYear(): void
    {
        $period = $this->resolver->resolve(['period' => 'yearly', 'year' => '2026']);

        self::assertSame('yearly', $period->type);
        self::assertTrue($period->isYearly());
        self::assertSame(2026, $period->year);
        self::assertNull($period->month);
        self::assertNull($period->quarter);
        self::assertSame('2026-01-01', $period->dateFrom);
        self::assertSame('2027-01-01', $period->dateToExclusive);
        self::assertSame('2026-12-31', $period->dateToInclusive());
        self::assertSame('2026', $period->label);
    }

    public function testYearlyPeriodRejectsMissingOrAbsurdYear(): void
    {
        foreach ([[], ['year' => '0'], ['year' => '1800'], ['year' => '2200']] as $query) {
            try {
                $this->resolver->resolve(['period' => 'yearly'] + $query);
                self::fail('expected a validation error for ' . json_encode($query));
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Neplatný rok/měsíc.', $e->getMessage());
            }
        }
    }

    public function testUnknownPeriodTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->resolve(['period' => 'weekly', 'year' => '2026']);
    }

    public function testBasisDefaultsToPaymentDateForYearAndDuzpOtherwise(): void
    {
        $year = $this->resolver->resolve(['period' => 'yearly', 'year' => '2026']);
        $month = $this->resolver->resolve(['period' => 'monthly', 'year' => '2026', 'month' => '3']);
        $quarter = $this->resolver->resolve(['period' => 'quarterly', 'year' => '2026', 'quarter' => '1']);

        self::assertSame(ExportBasis::Payment, $this->resolver->resolveBasis([], $year));
        self::assertSame(ExportBasis::Duzp, $this->resolver->resolveBasis([], $month));
        self::assertSame(ExportBasis::Duzp, $this->resolver->resolveBasis(['basis' => ''], $quarter));
    }

    public function testExplicitBasisOverridesTheDefault(): void
    {
        $year = $this->resolver->resolve(['period' => 'yearly', 'year' => '2026']);
        $month = $this->resolver->resolve(['period' => 'monthly', 'year' => '2026', 'month' => '3']);

        self::assertSame(ExportBasis::Duzp, $this->resolver->resolveBasis(['basis' => 'duzp'], $year));
        self::assertSame(ExportBasis::Payment, $this->resolver->resolveBasis(['basis' => 'payment'], $month));
    }

    public function testUnknownBasisIsRejected(): void
    {
        $month = $this->resolver->resolve(['period' => 'monthly', 'year' => '2026', 'month' => '3']);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->resolveBasis(['basis' => 'cash'], $month);
    }
}
