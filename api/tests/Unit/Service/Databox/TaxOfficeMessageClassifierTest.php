<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use MyInvoice\Service\Databox\TaxOfficeMessageClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxOfficeMessageClassifierTest extends TestCase
{
    /** @return array<string, array{array<string,mixed>, bool}> */
    public static function senders(): array
    {
        return [
            'by the box ID' => [['sender_box_id' => '7nyn2d9', 'sender_name' => 'cokoliv'], true],
            'regional office by name' => [['sender_box_id' => 'zzxx22t', 'sender_name' => 'Finanční úřad pro Jihomoravský kraj'], true],
            'territorial workplace by name' => [['sender_box_id' => 'zzxx22t', 'sender_name' => 'Finanční úřad pro Prahu 4 - Územní pracoviště'], true],
            'specialised office' => [['sender_box_id' => null, 'sender_name' => 'Specializovaný finanční úřad'], true],
            'general directorate' => [['sender_box_id' => null, 'sender_name' => 'Generální finanční ředitelství'], true],
            'appellate directorate, odd casing' => [['sender_box_id' => null, 'sender_name' => 'ODVOLACÍ FINANČNÍ ŘEDITELSTVÍ'], true],
            'a company that merely has finance in its name' => [['sender_box_id' => 'q7m2zpk', 'sender_name' => 'Finanční poradenství Novák s.r.o.'], false],
            'a municipality' => [['sender_box_id' => 'kd4n6w3', 'sender_name' => 'Obec Testov'], false],
            'nothing known' => [[], false],
        ];
    }

    /** @param array<string,mixed> $message */
    #[DataProvider('senders')]
    public function testRecognisesTheTaxAdministration(array $message, bool $expected): void
    {
        self::assertSame($expected, TaxOfficeMessageClassifier::classify($message + ['annotation' => 'x'])['is_tax_office']);
    }

    /** @return array<string, array{string, ?string}> */
    public static function annotations(): array
    {
        return [
            'call about the control statement' => ['Výzva k odstranění pochybností - kontrolní hlášení 03/2026', TaxOfficeMessageClassifier::KIND_KH_CALL],
            'call to file the control statement' => ['VÝZVA k podání kontrolního hlášení', TaxOfficeMessageClassifier::KIND_KH_CALL],
            'any other call' => ['Výzva k odstranění vad podání', TaxOfficeMessageClassifier::KIND_CALL],
            'a decision' => ['Platební výměr', TaxOfficeMessageClassifier::KIND_OTHER],
            'the control statement without a call' => ['Potvrzení o přijetí kontrolního hlášení', TaxOfficeMessageClassifier::KIND_OTHER],
            'empty' => ['', TaxOfficeMessageClassifier::KIND_OTHER],
        ];
    }

    #[DataProvider('annotations')]
    public function testKindFromTheAnnotation(string $annotation, string $kind): void
    {
        $r = TaxOfficeMessageClassifier::classify(['sender_box_id' => '7nyn2d9', 'sender_name' => 'Finanční úřad', 'annotation' => $annotation]);
        self::assertSame($kind, $r['kind']);
    }

    public function testNonTaxMessagesHaveNoKind(): void
    {
        self::assertNull(TaxOfficeMessageClassifier::classify(['sender_box_id' => 'q7m2zpk', 'sender_name' => 'Dodavatel', 'annotation' => 'Výzva'])['kind']);
    }

    public function testControlStatementCallDeadlines(): void
    {
        // Put into the box Tue 27.10.2026 -> 17 days = Fri 13.11.2026 (working day); delivered by login Fri 30.10.2026 -> +5 days = Wed 4.11.2026.
        $hints = TaxOfficeMessageClassifier::deadlineHints([
            'tax_office_kind' => TaxOfficeMessageClassifier::KIND_KH_CALL,
            'delivery_time' => '2026-10-27 09:15:30',
            'acceptance_time' => '2026-10-30 08:00:00',
        ]);
        self::assertSame('kh_change_17d', $hints[0]['code']);
        self::assertSame('§ 101g odst. 3 ZDPH', $hints[0]['law']);
        self::assertSame('2026-11-13', $hints[0]['due']);
        self::assertSame('kh_file_5d', $hints[1]['code']);
        self::assertSame('§ 101g odst. 1 ZDPH', $hints[1]['law']);
        self::assertSame('2026-11-04', $hints[1]['due']);
    }

    public function testDeadlineThatEndsOnAHolidayMovesToTheNextWorkingDay(): void
    {
        // Put into the box Sat 31.10.2026 -> 17 days = Tue 17.11.2026 (state holiday) -> Wed 18.11.2026.
        $hints = TaxOfficeMessageClassifier::deadlineHints([
            'tax_office_kind' => TaxOfficeMessageClassifier::KIND_KH_CALL,
            'delivery_time' => '2026-10-31 10:00:00',
            'acceptance_time' => null,
        ]);
        self::assertSame('2026-11-18', $hints[0]['due']);
        self::assertNull($hints[1]['due'], 'the 5 day period starts with the notice (delivery), unknown while undelivered');
    }

    public function testOtherKindsHaveNoDeadlineHints(): void
    {
        self::assertSame([], TaxOfficeMessageClassifier::deadlineHints(['tax_office_kind' => TaxOfficeMessageClassifier::KIND_CALL, 'delivery_time' => '2026-10-27 09:00:00']));
        self::assertSame([], TaxOfficeMessageClassifier::deadlineHints([]));
    }
}
