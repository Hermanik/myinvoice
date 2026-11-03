<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Vat;

use MyInvoice\Service\Vat\VatStatusHistory;
use PHPUnit\Framework\TestCase;

/** Registration / deregistration dates derived from the dated VAT status history. */
final class VatStatusHistoryTransitionsTest extends TestCase
{
    public function testRegistrationIsARowThatSwitchesToPayerFromAnotherStatus(): void
    {
        $history = new VatStatusHistory([
            ['valid_from' => '2000-01-01', 'status' => 'non_payer'],
            ['valid_from' => '2027-01-01', 'status' => 'payer'],
            ['valid_from' => '2027-06-01', 'status' => 'payer'],      // redundant row: not a registration
            ['valid_from' => '2029-01-01', 'status' => 'non_payer'],
            ['valid_from' => '2031-04-01', 'status' => 'identified'],
            ['valid_from' => '2032-01-01', 'status' => 'payer'],      // identified -> payer is a registration too
        ]);

        self::assertSame(['2027-01-01', '2032-01-01'], $history->registrationDates());
        self::assertSame(['2029-01-01'], $history->deregistrationDates());
    }

    public function testPayerToIdentifiedPersonIsADeregistration(): void
    {
        $history = new VatStatusHistory([
            ['valid_from' => '2000-01-01', 'status' => 'payer'],
            ['valid_from' => '2028-01-01', 'status' => 'identified'],
        ]);

        self::assertSame([], $history->registrationDates(), 'the floor row is not a registration');
        self::assertSame(['2028-01-01'], $history->deregistrationDates());
    }

    public function testHistoryWithoutAChangeHasNoEvents(): void
    {
        $history = VatStatusHistory::fromFlags(true, false);

        self::assertSame([], $history->registrationDates());
        self::assertSame([], $history->deregistrationDates());
    }
}
