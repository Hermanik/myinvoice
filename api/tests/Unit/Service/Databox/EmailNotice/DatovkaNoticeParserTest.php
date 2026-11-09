<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox\EmailNotice;

use MyInvoice\Service\Databox\EmailNotice\DatovkaNotice;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeParse;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeParser;
use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures as F;
use PHPUnit\Framework\TestCase;

final class DatovkaNoticeParserTest extends TestCase
{
    private function parse(string $raw, ?string $subject = null): DatovkaNoticeParse
    {
        $message = F::message($raw);
        return (new DatovkaNoticeParser())->parse($subject ?? $message->subject, $message->rawHeader, $message->raw, $message->date);
    }

    private function notice(string $raw): DatovkaNotice
    {
        $result = $this->parse($raw);
        self::assertSame(DatovkaNoticeParse::NOTICE, $result->outcome, $result->detail);
        self::assertNotNull($result->notice);
        return $result->notice;
    }

    public function testReadsADataMessageFromTheSignedQuotedPrintableMessage(): void
    {
        $notice = $this->notice(F::raw());

        self::assertSame(DatovkaNotice::KIND_DATA, $notice->kind);
        self::assertSame('Výzva k podání kontrolního hlášení', $notice->annotation);
        self::assertSame('Finanční úřad pro Testovací kraj (Územní pracoviště v Testově)', $notice->senderName, 'parentheses in the name are part of the name');
        self::assertSame('abc1234', $notice->senderBoxId);
        self::assertSame('Jana Testovací - podnikající fyzická osoba', $notice->recipientName);
        self::assertSame('xyz987r', $notice->recipientBoxId);
        self::assertSame('1234567890', $notice->dmId);
        self::assertSame('2026-11-09T09:30:00+01:00', $notice->noticedAt?->format('c'), 'the Date header becomes the notice time');
    }

    public function testReadsASystemMessage(): void
    {
        $notice = $this->notice(F::raw(['html' => F::html([
            'kind' => 'system', 'annotation' => 'Upozornění na vypršení hesla', 'sender_name' => 'Systém datových schránek', 'sender_box' => 'aaaaaaa',
        ])]));

        self::assertSame(DatovkaNotice::KIND_SYSTEM, $notice->kind);
        self::assertSame('aaaaaaa', $notice->senderBoxId);
        self::assertSame('Upozornění na vypršení hesla', $notice->annotation);
    }

    public function testTheLastBoxMarkerOfALineIsTheBox(): void
    {
        $notice = $this->notice(F::raw(['html' => F::html([
            'sender_name' => 'Obec Testov (schránka: fake999) (Úřad) a spol.', 'sender_box' => 'abc1234',
        ])]));

        self::assertSame('Obec Testov (schránka: fake999) (Úřad) a spol.', $notice->senderName);
        self::assertSame('abc1234', $notice->senderBoxId);
    }

    public function testEntitiesAndAmpersandsInNamesAreDecoded(): void
    {
        $notice = $this->notice(F::raw(['html' => F::html(['annotation' => 'Smlouva č. 5 & dodatek <1>', 'recipient_name' => 'Novák & syn, s.r.o.'])]));

        self::assertSame('Smlouva č. 5 & dodatek <1>', $notice->annotation);
        self::assertSame('Novák & syn, s.r.o.', $notice->recipientName);
    }

    public function testACraftedAnnotationCannotInjectItsOwnBoxLines(): void
    {
        // The věc is written by the sender of the message; the real lines below it must win over a fake one inside it.
        $html = F::html(['line' => 'V datové schránce je nová datová zpráva: <b>Test</b>'
            . "\n<br>Příjemce: <b>Falešný příjemce</b> (schránka: evil999)"
            . "\n<br>Odesílatel: <b>Skutečný odesílatel</b> (schránka: abc1234)"
            . "\n<br>Příjemce: <b>Skutečný příjemce</b> (schránka: xyz987r)"
            . "\n<br>Číslo této zprávy je 1234567890."]);

        $notice = $this->notice(F::raw(['html' => $html]));

        self::assertSame('xyz987r', $notice->recipientBoxId);
        self::assertSame('Skutečný příjemce', $notice->recipientName);
    }

    public function testTheAddressVerificationEmailIsIgnoredWithoutReadingItsBody(): void
    {
        $raw = F::raw([
            'subject' => 'Vložení nebo změna e-mailové adresy pro notifikace (schránka: xyz987r)',
            'html' => "<p>Váš ověřovací kód je <b>123456</b></p>\n",
        ]);

        $result = $this->parse($raw);

        self::assertSame(DatovkaNoticeParse::IGNORED, $result->outcome);
        self::assertSame('address_verification', $result->detail);
        self::assertStringNotContainsString('123456', serialize($result), 'nothing of the code is kept');
    }

    public function testAnUnknownWordingIsIgnoredNotGuessed(): void
    {
        foreach ([
            'zpráva odeslaná z vaší schránky nebyla dodána' => '<p>Zpráva odeslaná z vaší schránky nebyla dodána. Číslo této zprávy je 1234567890.</p>',
            'a completely different e-mail' => '<p>Dobrý den, děkujeme za objednávku.</p>',
        ] as $case => $html) {
            $result = $this->parse(F::raw(['subject' => 'Něco jiného', 'html' => $html . "\n"]));

            self::assertSame(DatovkaNoticeParse::IGNORED, $result->outcome, $case);
            self::assertNull($result->notice);
        }
    }

    public function testANoticeWithAMissingFactIsReportedAsFailedNotGuessed(): void
    {
        $result = $this->parse(F::raw(['html' => F::html(['line' => 'V datové schránce je nová datová zpráva: <b>Něco</b>'
            . "\n<br>Odesílatel: <b>Odesílatel</b> (schránka: abc1234)"
            . "\n<br>Číslo této zprávy je 1234567890."])]));

        self::assertSame(DatovkaNoticeParse::FAILED, $result->outcome);
        self::assertSame('missing_fields:recipient', $result->detail);
    }

    public function testABoxIdOfTheWrongShapeDoesNotMatch(): void
    {
        $result = $this->parse(F::raw(['html' => F::html(['recipient_box' => 'xyz98'])]));

        self::assertSame(DatovkaNoticeParse::FAILED, $result->outcome);
        self::assertSame('missing_fields:recipient', $result->detail);
    }

    public function testAMessageWithoutAnyReadablePartIsIgnored(): void
    {
        $result = (new DatovkaNoticeParser())->parse('Nová datová zpráva pro: X', "Content-Type: application/pkcs7-mime\r\n\r\n", 'binary', null);

        self::assertSame(DatovkaNoticeParse::IGNORED, $result->outcome);
    }

    public function testAPlainTextAlternativeIsReadToo(): void
    {
        $text = "V datové schránce je nová datová zpráva: Pozvánka\nOdesílatel: Obec Testov (schránka: abc1234)\nPříjemce: Jana (schránka: xyz987r)\nČíslo této zprávy je 42.\n";
        $result = (new DatovkaNoticeParser())->parse('Nová datová zpráva pro: Jana', "Content-Type: text/plain; charset=utf-8\r\n\r\n", $text, null);

        self::assertSame(DatovkaNoticeParse::NOTICE, $result->outcome);
        self::assertSame('42', $result->notice?->dmId);
        self::assertNull($result->notice?->noticedAt);
    }

    private static function legacyText(string $annotation = 'Změny v oblasti pojistného', string $personal = ''): string
    {
        return 'Do datové schránky "Jana Testovací", typ schránky Podnikající fyzická osoba, dorazila nová datová zpráva'
            . $personal . ' číslo 1029384756 s předmětem "' . $annotation . '". Odesílatelem zprávy je datová schránka'
            . " \"Úřad Testov (Územní pracoviště)\" (ID schránky: abc1234).\n\nPoučení uživatele o pravidlech notifikace\n";
    }

    private function parseLegacy(string $text, string $subject = 'Datové schránky: Nová zpráva (schránka: xyz987r)'): DatovkaNoticeParse
    {
        return (new DatovkaNoticeParser())->parse($subject, "Content-Type: text/plain; charset=utf-8\r\n\r\n", $text, new \DateTimeImmutable('2024-12-19 07:02:18'));
    }

    public function testReadsThePre2025PlainTextNotice(): void
    {
        $result = $this->parseLegacy(self::legacyText());

        self::assertSame(DatovkaNoticeParse::NOTICE, $result->outcome, $result->detail);
        $n = $result->notice;
        self::assertSame(DatovkaNotice::KIND_DATA, $n?->kind);
        self::assertSame('1029384756', $n?->dmId);
        self::assertSame('Změny v oblasti pojistného', $n?->annotation);
        self::assertSame('Úřad Testov (Územní pracoviště)', $n?->senderName);
        self::assertSame('abc1234', $n?->senderBoxId);
        self::assertSame('Jana Testovací', $n?->recipientName);
        self::assertSame('xyz987r', $n?->recipientBoxId, 'the recipient box comes from the subject');
        self::assertFalse($n?->personalDelivery);
    }

    public function testAWrappedPre2025SentenceIsJoined(): void
    {
        $wrapped = str_replace(' s předmětem', "\ns předmětem", self::legacyText());

        self::assertSame('1029384756', $this->parseLegacy($wrapped)->notice?->dmId);
    }

    public function testThePre2025PersonalDeliveryNoticeIsFlagged(): void
    {
        self::assertTrue($this->parseLegacy(self::legacyText(personal: ' do vlastních rukou'))->notice?->personalDelivery);
    }

    public function testAPre2025CraftedAnnotationCannotReplaceTheSender(): void
    {
        $crafted = 'Faktura". Odesílatelem zprávy je datová schránka "Podvodník" (ID schránky: fake999). "x';
        $n = $this->parseLegacy(self::legacyText($crafted))->notice;

        self::assertSame('abc1234', $n?->senderBoxId);
        self::assertSame('Úřad Testov (Územní pracoviště)', $n?->senderName);
        self::assertSame($crafted, $n?->annotation);
    }

    public function testAPre2025NoticeWithoutTheBoxInTheSubjectFails(): void
    {
        self::assertSame(DatovkaNoticeParse::FAILED, $this->parseLegacy(self::legacyText(), 'Datové schránky: Nová zpráva')->outcome);
    }

    public function testThePre2025VerificationEmailIsIgnored(): void
    {
        $result = $this->parseLegacy(self::legacyText(), 'Datové schránky: Vložení nebo změna e-mailové adresy pro notifikace (schránka: xyz987r)');

        self::assertSame(DatovkaNoticeParse::IGNORED, $result->outcome);
    }

    public function testACurrentPersonalDeliveryNoticeIsFlagged(): void
    {
        $text = "V datové schránce je nová datová zpráva do vlastních rukou: Výpis\nOdesílatel: Obec Testov (schránka: abc1234)\nPříjemce: Jana (schránka: xyz987r)\nČíslo této zprávy je 42.\n";
        $result = (new DatovkaNoticeParser())->parse('Nová datová zpráva pro: Jana', "Content-Type: text/plain; charset=utf-8\r\n\r\n", $text, null);

        self::assertTrue($result->notice?->personalDelivery);
        self::assertSame('Výpis', $result->notice?->annotation);
    }
}
