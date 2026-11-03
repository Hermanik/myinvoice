<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\DphFiling;
use MyInvoice\Service\Report\KhFiling;
use MyInvoice\Service\Report\ReportFormParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parsing of the query parameters `form` (khdph_forma / dapdph_forma), `d_zjist`, `reason` and the call of the tax
 * office (`c_jed_vyzvy`, `vyzva_odp`) into the validated filing value objects.
 */
final class ReportFormParamsTest extends TestCase
{
    private const REFERENCE = '1234567/26/2100-23542-501001';

    public function testDefaultIsRegularForm(): void
    {
        $dph = ReportFormParams::dph([]);
        self::assertSame(['B', null, null], [$dph->form, $dph->dZjist, $dph->reason]);
        self::assertFalse($dph->isSupplementary());

        $kh = ReportFormParams::kh([]);
        self::assertSame(['B', null, null, null], [$kh->form, $kh->dZjist, $kh->callReference, $kh->callAnswer]);
        self::assertFalse($kh->isQuickAnswer());
        self::assertSame('B', $kh->archiveForm());
    }

    public function testInvalidFormRejected(): void
    {
        foreach ([fn () => ReportFormParams::kh(['form' => 'D']), fn () => ReportFormParams::dph(['form' => 'X']), fn () => ReportFormParams::dph(['form' => 'E', 'd_zjist' => '05.08.2026', 'reason' => 'x'])] as $parse) {
            try {
                $parse();
                self::fail('invalid form');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Neplatná forma', $e->getMessage());
            }
        }
    }

    public function testSupplementaryDphReturnRequiresDiscoveryDateAndReason(): void
    {
        foreach ([[], ['d_zjist' => '05.08.2026'], ['reason' => 'důvod'], ['d_zjist' => '05.08.2026', 'reason' => '  ']] as $incomplete) {
            try {
                ReportFormParams::dph(['form' => 'D'] + $incomplete);
                self::fail('incomplete supplementary return');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $dph = ReportFormParams::dph(['form' => 'd', 'd_zjist' => '05.08.2026', 'reason' => ' Zapomenutá faktura ']);
        self::assertSame(['D', '05.08.2026', 'Zapomenutá faktura'], [$dph->form, $dph->dZjist, $dph->reason]);
        self::assertTrue($dph->isSupplementary());
    }

    public function testFollowUpKhRequiresTheDateOrTheCallReference(): void
    {
        foreach (['N', 'E'] as $form) {
            try {
                ReportFormParams::kh(['form' => $form]);
                self::fail('follow-up KH without a reason');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertSame('05.08.2026', ReportFormParams::kh(['form' => 'N', 'd_zjist' => '05.08.2026'])->dZjist);
        $byReference = ReportFormParams::kh(['form' => 'N', 'c_jed_vyzvy' => self::REFERENCE]);
        self::assertNull($byReference->dZjist, 'the call reference replaces the date');
        self::assertSame(self::REFERENCE, $byReference->callReference);
    }

    public function testCorrectiveFormAllowsOptionalDzjistAndRegularFormDropsIt(): void
    {
        self::assertNull(ReportFormParams::dph(['form' => 'O'])->dZjist);
        self::assertSame('01.07.2026', ReportFormParams::dph(['form' => 'O', 'd_zjist' => '01.07.2026'])->dZjist);
        self::assertNull(ReportFormParams::kh(['form' => 'B', 'd_zjist' => '05.08.2026'])->dZjist, 'a date makes no sense on a regular filing');
        self::assertNull(ReportFormParams::dph(['form' => 'B', 'd_zjist' => '05.08.2026'])->dZjist);
    }

    /** @return array<string, array{string}> */
    public static function badDates(): array
    {
        return ['iso' => ['2026-08-05'], 'no zero padding' => ['5.8.2026'], 'not a day' => ['31.02.2026'], 'text' => ['nesmysl'], 'slashes' => ['05/08/2026']];
    }

    #[DataProvider('badDates')]
    public function testDzjistFormatValidation(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportFormParams::kh(['form' => 'N', 'd_zjist' => $bad]);
    }

    public function testEpoDateHelpers(): void
    {
        self::assertTrue(ReportFormParams::isValidEpoDate('29.02.2028'), 'leap year');
        self::assertFalse(ReportFormParams::isValidEpoDate('29.02.2026'));
        self::assertSame('2026-08-05', ReportFormParams::epoDateToIso('05.08.2026'));
        self::assertSame('whatever', ReportFormParams::epoDateToIso('whatever'));
        self::assertNull(ReportFormParams::cleanEpoDate('  '));
    }

    public function testCallOfTheTaxOffice(): void
    {
        $kh = ReportFormParams::kh(['c_jed_vyzvy' => ' ' . self::REFERENCE . ' ', 'vyzva_odp' => 'p']);
        self::assertSame([self::REFERENCE, 'P', true, 'V'], [$kh->callReference, $kh->callAnswer, $kh->isQuickAnswer(), $kh->archiveForm()]);
        self::assertSame('B', $kh->form, 'a quick answer is written as form B');

        // A follow-up filing carries the reference without a quick answer.
        self::assertFalse(ReportFormParams::kh(['form' => 'N', 'c_jed_vyzvy' => '1/26/2100-23542-501001'])->isQuickAnswer());
    }

    /** @return array<string, array{array<string,string>}> */
    public static function badCalls(): array
    {
        return [
            'malformed reference'          => [['c_jed_vyzvy' => 'abc']],
            'reference with short block'   => [['c_jed_vyzvy' => '1234567/26/2100-2354-501001']],
            'quick answer w/o reference'   => [['vyzva_odp' => 'P']],
            'unknown quick answer'         => [['c_jed_vyzvy' => self::REFERENCE, 'vyzva_odp' => 'X']],
            'quick answer on a follow-up'  => [['form' => 'N', 'c_jed_vyzvy' => self::REFERENCE, 'vyzva_odp' => 'P']],
        ];
    }

    /** @param array<string,string> $query */
    #[DataProvider('badCalls')]
    public function testInvalidCallCombinationsAreRefused(array $query): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportFormParams::kh($query);
    }

    public function testValueObjectsValidateOnTheirOwn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new KhFiling('B', null, null, 'P');
    }

    public function testDiscoveryDateInTheFutureIsRefusedForSupplementaryReturns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DphFiling('D', '01.01.2999', 'důvod');
    }
}
