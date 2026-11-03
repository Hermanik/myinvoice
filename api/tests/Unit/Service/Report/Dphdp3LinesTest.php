<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\Dphdp3Lines;
use PHPUnit\Framework\TestCase;

/** The rows of the DPHDP3 the app generates and the arithmetic of rows 62 / 63 / 66. */
final class Dphdp3LinesTest extends TestCase
{
    public function testRowsThatCarryATaxAndTheirDirection(): void
    {
        self::assertTrue(Dphdp3Lines::carriesTax('1'));
        self::assertTrue(Dphdp3Lines::carriesTax('45'), 'the deduction on registration');
        self::assertFalse(Dphdp3Lines::carriesTax('47'), 'assets: a base-only row');
        self::assertFalse(Dphdp3Lines::carriesTax('26'));
        self::assertFalse(Dphdp3Lines::carriesTax('99'), 'a row the builder cannot render');

        self::assertTrue(Dphdp3Lines::isOutput('13'));
        self::assertFalse(Dphdp3Lines::isOutput('40'));
    }

    public function testTotalsRoundEveryRowAndKeepBaseOnlyAndUnknownRowsOut(): void
    {
        $totals = Dphdp3Lines::totals([
            '1' => ['vat' => 100.5], '3' => ['vat' => 50.4], '40' => ['vat' => 70.5],
            '47' => ['base' => 9999.0, 'vat' => 999.0], '99' => ['vat' => 5000.0],
        ]);

        // Rows 1 and 3 are output tax (101 + 50), row 40 is deduction (71); row 47 carries no tax, row 99 is not rendered.
        self::assertSame(['output' => 151, 'input' => 71, 'tax' => 80], $totals);
    }

    public function testNegativeRow45ReducesTheDeduction(): void
    {
        self::assertSame(['output' => 0, 'input' => 400, 'tax' => -400], Dphdp3Lines::totals(['40' => ['vat' => 1000.0], '45' => ['vat' => -600.0]]));
    }
}
