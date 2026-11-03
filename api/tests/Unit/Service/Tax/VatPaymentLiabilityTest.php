<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tax;

use MyInvoice\Service\Report\ReportPeriod;
use MyInvoice\Service\Tax\VatPaymentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The pure parts of the VAT payment: the liability rows, the period and the due date. */
final class VatPaymentLiabilityTest extends TestCase
{
    public function testOwnTaxLiabilityComesFromRow64(): void
    {
        $liability = VatPaymentService::liability(['form_code' => 'dphdp3', 'summary' => ['line_64_dano_da' => 12345, 'line_66_dano_no' => 0, 'tax_due' => 12345.4]]);

        self::assertSame(['kind' => 'payment', 'amount' => 12345], $liability);
    }

    public function testExcessDeductionComesFromRow66AndIsARefundNotAPayment(): void
    {
        $liability = VatPaymentService::liability(['form_code' => 'dphdp3', 'summary' => ['line_64_dano_da' => 0, 'line_66_dano_no' => 777, 'tax_due' => -777.0]]);

        self::assertSame(['kind' => 'refund', 'amount' => 777], $liability);
    }

    public function testZeroLiabilityAndOtherForms(): void
    {
        self::assertSame('none', VatPaymentService::liability(['form_code' => 'dphdp3', 'summary' => ['line_64_dano_da' => 0, 'line_66_dano_no' => 0]])['kind']);
        self::assertSame('none', VatPaymentService::liability(['form_code' => 'dphkh1', 'summary' => ['line_64_dano_da' => 500]])['kind'], 'only the DPH return carries a liability');
        self::assertSame('none', VatPaymentService::liability(['form_code' => 'dphdp3'])['kind']);
    }

    public function testRowsArchivedBeforeTheSummaryCarriedThemFallBackToTheSignedTax(): void
    {
        self::assertSame(['kind' => 'payment', 'amount' => 900], VatPaymentService::liability(['form_code' => 'dphdp3', 'summary' => ['tax_due' => 899.6]]));
        self::assertSame(['kind' => 'refund', 'amount' => 120], VatPaymentService::liability(['form_code' => 'dphdp3', 'summary' => ['tax_due' => -120.0]]));
    }

    /** @return array<string, array{array<string,mixed>, string, string}> */
    public static function dueDates(): array
    {
        return [
            // Monthly: the 25th of the following month, moved to the next working day.
            'January 2027'                  => [['period_year' => 2027, 'period_month' => 1, 'period_quarter' => null], '2027-02-25', '2027-01'],
            'May 2026, 25th is Thursday'    => [['period_year' => 2026, 'period_month' => 5, 'period_quarter' => null], '2026-06-25', '2026-05'],
            'June 2026, 25. 7. is Saturday' => [['period_year' => 2026, 'period_month' => 6, 'period_quarter' => null], '2026-07-27', '2026-06'],
            'December rolls into January'   => [['period_year' => 2026, 'period_month' => 12, 'period_quarter' => null], '2027-01-25', '2026-12'],
            // Quarterly: the 25th of the month after the quarter
            'Q1 2027, 25. 4. is Sunday'     => [['period_year' => 2027, 'period_month' => null, 'period_quarter' => 1], '2027-04-26', '2027 Q1'],
            'Q4 2026'                       => [['period_year' => 2026, 'period_month' => null, 'period_quarter' => 4], '2027-01-25', '2026 Q4'],
            'Q3 2026'                       => [['period_year' => 2026, 'period_month' => null, 'period_quarter' => 3], '2026-10-26', '2026 Q3'],
        ];
    }

    /** @param array<string,mixed> $submission */
    #[DataProvider('dueDates')]
    public function testDueDateIsThe25thOfTheFollowingMonthMovedToAWorkingDay(array $submission, string $due, string $label): void
    {
        self::assertSame($due, VatPaymentService::dueDate($submission));
        self::assertSame($label, VatPaymentService::period($submission)['label']);
    }

    public function testFilingDeadlineAndIncomeTaxDeadlineHelpers(): void
    {
        self::assertSame('2027-02-25', ReportPeriod::filingDeadline(2027, 1, 'monthly'));
        self::assertSame('2027-04-26', ReportPeriod::filingDeadline(2027, 2, 'quarterly'), 'any month of the quarter gives the quarter deadline');
        self::assertSame('2027-04-01', ReportPeriod::incomeTaxDeadline(2026));
        self::assertSame('2026-04-01', ReportPeriod::incomeTaxDeadline(2025));
    }
}
