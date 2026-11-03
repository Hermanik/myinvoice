<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Document;

use MyInvoice\Service\Document\DocumentException;
use MyInvoice\Service\Document\ZfoExtractor;
use MyInvoice\Tests\Support\Databox\DataboxFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Testuje ZfoExtractor proti synteticky sestavenému PKCS#7 SignedData DER
 * (nezávislé na privátních datech). Buduje minimální, ale validní strukturu:
 *   ContentInfo { signedData, [0] { SignedData { v1, SET{}, encapContentInfo {
 *     pkcs7-data, [0] { OCTET STRING = XML } } } } }
 */
final class ZfoExtractorTest extends TestCase
{
    // ───────── DER helpers ─────────

    private static function len(int $n): string
    {
        if ($n < 0x80) return chr($n);
        $bytes = '';
        while ($n > 0) { $bytes = chr($n & 0xFF) . $bytes; $n >>= 8; }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function tlv(int $tag, string $content): string
    {
        return chr($tag) . self::len(strlen($content)) . $content;
    }

    private static function buildZfo(string $xml): string
    {
        $oidData   = self::tlv(0x06, hex2bin('2a864886f70d010701')); // pkcs7-data
        $oidSigned = self::tlv(0x06, hex2bin('2a864886f70d010702')); // signedData
        $eContent  = self::tlv(0xA0, self::tlv(0x04, $xml));          // [0] { OCTET STRING }
        $encap     = self::tlv(0x30, $oidData . $eContent);          // encapContentInfo
        $version   = self::tlv(0x02, chr(1));
        $digestSet = self::tlv(0x31, '');                            // prázdná SET
        $signed    = self::tlv(0x30, $version . $digestSet . $encap);
        $explicit  = self::tlv(0xA0, $signed);                       // [0] { SignedData }
        return self::tlv(0x30, $oidSigned . $explicit);             // ContentInfo
    }

    private static function sampleXml(string $b64): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<q:MessageDownloadResponse xmlns:q="http://isds.czechpoint.cz/v20/SentMessage">'
            . '<q:dmReturnedMessage><p:dmDm xmlns:p="http://isds.czechpoint.cz/v20">'
            . '<p:dmID>123456</p:dmID>'
            . '<p:dbIDSender>snd1234</p:dbIDSender>'
            . '<p:dmSender>Odesílatel s.r.o.</p:dmSender>'
            . '<p:dmSenderAddress>Hlavní 1, Praha</p:dmSenderAddress>'
            . '<p:dbIDRecipient>rcp9876</p:dbIDRecipient>'
            . '<p:dmRecipient>Příjemce a.s.</p:dmRecipient>'
            . '<p:dmAnnotation>Předmět zprávy</p:dmAnnotation>'
            . '<p:dmAcceptanceTime>2026-01-02T03:04:05</p:dmAcceptanceTime>'
            . '<p:dmFiles>'
            . '<p:dmFile dmFileMetaType="main" dmFileDescr="dokument.pdf" dmMimeType="application/pdf">'
            . '<p:dmEncodedContent>' . $b64 . '</p:dmEncodedContent>'
            . '</p:dmFile></p:dmFiles>'
            . '</p:dmDm></q:dmReturnedMessage></q:MessageDownloadResponse>';
    }

    // ───────── tests ─────────

    public function testLooksLikeZfoTrueForSignedData(): void
    {
        $der = self::buildZfo(self::sampleXml(base64_encode('x')));
        self::assertTrue(ZfoExtractor::looksLikeZfo($der));
    }

    public function testLooksLikeZfoFalseForPlainData(): void
    {
        self::assertFalse(ZfoExtractor::looksLikeZfo('%PDF-1.4 not a zfo'));
        self::assertFalse(ZfoExtractor::looksLikeZfo(''));
    }

    public function testExtractMetadataAndAttachment(): void
    {
        $payload = 'PDF-BYTES-HERE';
        $der = self::buildZfo(self::sampleXml(base64_encode($payload)));

        $result = (new ZfoExtractor())->extract($der);
        $m = $result['metadata'];

        self::assertSame('123456', $m['dm_id']);
        self::assertSame('sent', $m['direction']);
        self::assertSame('snd1234', $m['sender_box_id']);
        self::assertSame('Odesílatel s.r.o.', $m['sender_name']);
        self::assertSame('rcp9876', $m['recipient_box_id']);
        self::assertSame('Příjemce a.s.', $m['recipient_name']);
        self::assertSame('Předmět zprávy', $m['annotation'] ?? '!');
        self::assertSame('2026-01-02 03:04:05', $m['acceptance_time']);
        self::assertNotSame('', (string) $m['envelope_xml']);

        self::assertCount(1, $result['attachments']);
        $att = $result['attachments'][0];
        self::assertSame('dokument.pdf', $att['name']);
        self::assertSame('main', $att['meta_type']);
        self::assertSame('application/pdf', $att['mime']);
        self::assertSame($payload, $att['bytes']);
    }

    public function testRejectsDoctypeXxe(): void
    {
        // eContent obsahuje DOCTYPE → musí být odmítnuto (anti-XXE).
        $xml = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<q:MessageDownloadResponse xmlns:q="http://isds.czechpoint.cz/v20/SentMessage">'
            . '<p:dmID xmlns:p="http://isds.czechpoint.cz/v20">1</p:dmID></q:MessageDownloadResponse>';
        $der = self::buildZfo($xml);

        $this->expectException(DocumentException::class);
        (new ZfoExtractor())->extract($der);
    }

    public function testRejectsADoctypeHiddenInUtf16(): void
    {
        // Between the NUL bytes of UTF-16 the byte search for "<!DOCTYPE" finds nothing: the parsed document decides.
        $der = self::buildZfo(DataboxFixtures::utf16('<!DOCTYPE foo [<!ENTITY e "x">]><foo>&e;</foo>'));
        self::assertSame(0, preg_match('/<!DOCTYPE/i', $der), 'precondition');

        $this->expectException(DocumentException::class);
        $this->expectExceptionMessage('DTD');
        (new ZfoExtractor())->extract($der);
    }

    public function testUtf16BillionLaughsIsRefusedWithoutExpandingIt(): void
    {
        $before = memory_get_peak_usage();
        try {
            (new ZfoExtractor())->extract(self::buildZfo(DataboxFixtures::utf16BillionLaughs()));
            self::fail('expected DocumentException');
        } catch (DocumentException) {
            self::addToAssertionCount(1);
        }
        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    public function testThrowsOnGarbageInput(): void
    {
        $this->expectException(DocumentException::class);
        (new ZfoExtractor())->extract(random_bytes(128));
    }
}
