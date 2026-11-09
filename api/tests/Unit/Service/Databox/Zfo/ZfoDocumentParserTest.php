<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox\Zfo;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\Zfo\ZfoDocument;
use MyInvoice\Service\Databox\Zfo\ZfoDocumentParser;
use MyInvoice\Tests\Support\Databox\ZfoFixtures as Z;
use PHPUnit\Framework\TestCase;

final class ZfoDocumentParserTest extends TestCase
{
    private string $sender;
    private string $recipient;

    protected function setUp(): void
    {
        date_default_timezone_set('Europe/Prague');
        $this->sender = Z::boxId('tstsnd');
        $this->recipient = Z::boxId('tstrcp');
    }

    private function parse(string $xml): ZfoDocument
    {
        return (new ZfoDocumentParser())->parse($xml);
    }

    public function testASignedMessageIsReadWithItsEnvelopeLifecycleAndAttachmentFingerprints(): void
    {
        $doc = $this->parse(Z::messageXml([
            'dm_id' => '8800001', 'sender' => $this->sender, 'recipient' => $this->recipient, 'personal' => true,
            'files' => [
                ['name' => 'a.txt', 'mime' => 'text/plain', 'bytes' => 'hello'],
                ['name' => 'dphdp3.xml', 'mime' => 'application/xml', 'bytes' => '<Pisemnost><DPHDP3/></Pisemnost>'],
            ],
        ]));

        self::assertSame(ZfoDocument::MESSAGE, $doc->kind);
        self::assertSame('8800001', $doc->dmId);
        self::assertSame($this->sender, $doc->senderBoxId());
        self::assertSame($this->recipient, $doc->recipientBoxId());
        self::assertSame('Synthetic subject', $doc->envelope['annotation']);
        self::assertTrue($doc->envelope['personal_delivery']);
        self::assertSame(6, $doc->status);
        self::assertSame('2026-10-20 10:00:00', $doc->deliveryTime?->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-20 10:00:05', $doc->acceptanceTime?->format('Y-m-d H:i:s'));
        self::assertSame(1, $doc->attachmentSizeKb);
        self::assertNull($doc->deliveryInfoXml);
        self::assertSame(
            [['size' => 5, 'sha256' => hash('sha256', 'hello'), 'epo' => false],
             ['size' => 32, 'sha256' => hash('sha256', '<Pisemnost><DPHDP3/></Pisemnost>'), 'epo' => true]],
            $doc->attachments,
        );
    }

    public function testTimesAreConvertedToTheApplicationTimeZone(): void
    {
        $doc = $this->parse(Z::messageXml(['sender' => $this->sender, 'recipient' => $this->recipient, 'delivery' => '2026-10-20T08:00:00.000Z', 'acceptance' => null]));

        self::assertSame('2026-10-20 10:00:00', $doc->deliveryTime?->format('Y-m-d H:i:s'), 'UTC 08:00 is 10:00 in Prague (summer time)');
        self::assertNull($doc->acceptanceTime);
    }

    public function testASignedReceiptIsReadWithTheStateTheEventsAndTheUnsignedCopy(): void
    {
        $doc = $this->parse(Z::receiptXml(['dm_id' => '8800002', 'sender' => $this->sender, 'recipient' => $this->recipient, 'status' => 5]));

        self::assertSame(ZfoDocument::RECEIPT, $doc->kind);
        self::assertSame('8800002', $doc->dmId);
        self::assertSame(5, $doc->status);
        self::assertSame($this->recipient, $doc->recipientBoxId());
        self::assertSame([], $doc->attachments);
        self::assertStringContainsString('<p:dmDelivery', (string) $doc->deliveryInfoXml);
        self::assertStringNotContainsString('dmQTimestamp', (string) $doc->deliveryInfoXml, 'the bulky time stamp is not kept in the readable copy');
        self::assertStringContainsString('doručena přihlášením', (string) $doc->deliveryInfoXml, 'the events stay in the readable copy');
    }

    public function testTheLifecycleOfAMessageMayBeNestedDifferently(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><r xmlns:p="http://isds.czechpoint.cz/v20"><p:dmDm><p:dmID>9</p:dmID><p:dbIDSender>' . $this->sender . '</p:dbIDSender>'
            . '<p:dbIDRecipient>' . $this->recipient . '</p:dbIDRecipient><p:dmFiles/></p:dmDm><p:wrap><p:dmMessageStatus>7</p:dmMessageStatus></p:wrap></r>';

        $doc = $this->parse($xml);

        self::assertSame(ZfoDocument::MESSAGE, $doc->kind);
        self::assertSame(7, $doc->status, 'found in a descendant of the message element');
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'not XML' => ['this is not xml', 'zfo_unreadable'],
            'another document' => ['<?xml version="1.0"?><a><b/></a>', 'zfo_unknown_content'],
            'a message number that is no number' => [
                '<?xml version="1.0"?><r xmlns:p="http://x"><p:dmReturnedMessage><p:dmDm><p:dmID>12abc</p:dmID></p:dmDm></p:dmReturnedMessage></r>', 'zfo_unknown_content',
            ],
            'a receipt without an envelope' => ['<?xml version="1.0"?><r xmlns:p="http://x"><p:dmDelivery><p:dmMessageStatus>6</p:dmMessageStatus></p:dmDelivery></r>', 'zfo_unknown_content'],
            'a DOCTYPE (entity expansion)' => ['<?xml version="1.0"?><!DOCTYPE r [<!ENTITY e "x">]><r>&e;</r>', 'zfo_unreadable'],
            'UTF-16 hiding a DOCTYPE' => [
                "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE r [<!ENTITY e "x">]><r>&e;</r>', 'UTF-16LE', 'UTF-8'), 'zfo_unreadable',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testUnusableContentIsRefused(string $xml, string $code): void
    {
        try {
            $this->parse($xml);
            self::fail('expected DataboxException');
        } catch (DataboxException $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }
}
