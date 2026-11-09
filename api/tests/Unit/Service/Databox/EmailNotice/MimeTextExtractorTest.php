<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox\EmailNotice;

use MyInvoice\Service\Databox\EmailNotice\MimeTextExtractor;
use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures;
use PHPUnit\Framework\TestCase;

final class MimeTextExtractorTest extends TestCase
{
    /** @return array{html:?string,text:?string} */
    private function extract(string $raw): array
    {
        [$header, $body] = DatovkaNoticeFixtures::split($raw);
        return MimeTextExtractor::extract($header, $body);
    }

    public function testReadsTheHtmlPartOfAnSmimeSignedMessage(): void
    {
        $parts = $this->extract(DatovkaNoticeFixtures::raw(['html' => "<p>Příliš žluťoučký kůň: <b>úpěl</b> ďábelské ódy</p>\n"]));

        self::assertSame("<p>Příliš žluťoučký kůň: <b>úpěl</b> ďábelské ódy</p>\n", $parts['html'], 'quoted-printable decoded, the signature part skipped');
        self::assertNull($parts['text']);
    }

    public function testDecodesBase64AndLegacyCharsets(): void
    {
        $raw = "Content-Type: multipart/alternative; boundary=\"b\"\r\n\r\n"
            . "--b\r\nContent-Type: text/plain; charset=windows-1250\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode(iconv('UTF-8', 'WINDOWS-1250', 'Číslo zprávy')) . "\r\n"
            . "--b\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode('<p>Číslo</p>') . "\r\n--b--\r\n";

        $parts = $this->extract($raw);

        self::assertSame('Číslo zprávy', $parts['text']);
        self::assertSame('<p>Číslo</p>', $parts['html']);
    }

    public function testAMessageThatIsNotMultipartIsTheBodyItself(): void
    {
        $parts = $this->extract("Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n<p>Ahoj</p>\r\n");

        self::assertSame("<p>Ahoj</p>\r\n", $parts['html']);
    }

    public function testNothingReadableYieldsNothing(): void
    {
        self::assertSame(['html' => null, 'text' => null], MimeTextExtractor::extract("Content-Type: application/pdf\r\n\r\n", '%PDF-1.4'));
        self::assertSame(['html' => null, 'text' => null], MimeTextExtractor::extract("Content-Type: multipart/mixed\r\n\r\n", 'no boundary parameter'));
        self::assertSame(['html' => null, 'text' => null], MimeTextExtractor::extract('', ''));
    }

    /** A text/html part wrapped in `$levels` nested multiparts. */
    private static function nested(int $levels): string
    {
        $body = "--b{$levels}\r\nContent-Type: text/html\r\n\r\n<p>deep</p>\r\n--b{$levels}--\r\n";
        for ($i = $levels - 1; $i >= 0; $i--) {
            $body = "--b{$i}\r\nContent-Type: multipart/mixed; boundary=\"b" . ($i + 1) . "\"\r\n\r\n" . $body . "--b{$i}--\r\n";
        }
        return "Content-Type: multipart/mixed; boundary=\"b0\"\r\n\r\n" . $body;
    }

    public function testHostileNestingAndSizeAreBounded(): void
    {
        self::assertSame('<p>deep</p>', $this->extract(self::nested(3))['html'], 'the builder makes a readable message at a sane depth');
        self::assertNull($this->extract(self::nested(30))['html'], 'a message nested far beyond any real one is not followed');

        $huge = MimeTextExtractor::extract("Content-Type: text/html\r\n\r\n", str_repeat('x', 2_200_000));
        self::assertSame(['html' => null, 'text' => null], $huge);
    }
}
