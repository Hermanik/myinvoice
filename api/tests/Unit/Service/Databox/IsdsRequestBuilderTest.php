<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use MyInvoice\Service\Databox\IsdsRequestBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SOAP requests are validated against the OFFICIAL schemas of the operating-rules annex 2
 * (dmBaseTypes.xsd 3.10 and dbTypes.xsd, copied to tests/Fixtures/Databox/xsd), so a wrong element name or order fails here.
 */
final class IsdsRequestBuilderTest extends TestCase
{
    private const SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const NS = 'http://isds.czechpoint.cz/v20';

    /** @param 'dm'|'db' $schema */
    private function assertValidRequest(string $soap, string $schema): \DOMElement
    {
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($soap), 'well-formed XML');
        $body = $doc->getElementsByTagNameNS(self::SOAP, 'Body')->item(0);
        $operation = null;
        foreach ($body->childNodes as $n) {
            if ($n instanceof \DOMElement) {
                $operation = $n;
                break;
            }
        }
        self::assertNotNull($operation);
        self::assertSame(self::NS, $operation->namespaceURI);

        $only = new \DOMDocument();
        $only->appendChild($only->importNode($operation, true));
        libxml_use_internal_errors(true);
        $valid = $only->schemaValidate(dirname(__DIR__, 3) . '/Fixtures/Databox/xsd/' . ($schema === 'dm' ? 'dmBaseTypes.xsd' : 'dbTypes.xsd'));
        $errors = array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        self::assertTrue($valid, $operation->localName . ' violates the official schema: ' . implode(' | ', $errors));
        return $operation;
    }

    public function testLoginInformationRequestsAreValid(): void
    {
        $this->assertValidRequest(IsdsRequestBuilder::getOwnerInfo(), 'db');
        $this->assertValidRequest(IsdsRequestBuilder::getUserInfo(), 'db');
        $this->assertValidRequest(IsdsRequestBuilder::getPasswordInfo(), 'db');
    }

    #[DataProvider('listOperations')]
    public function testListRequestIsValidAndUsesPragueOffset(string $operation): void
    {
        $summer = new \DateTimeImmutable('2026-07-01 00:00:00', new \DateTimeZone('Europe/Prague'));
        $winter = new \DateTimeImmutable('2026-12-01 12:30:00', new \DateTimeZone('UTC'));
        $xml = IsdsRequestBuilder::listOfMessages($operation, $summer, $winter, -1, 1, 1000);

        $element = $this->assertValidRequest($xml, 'dm');
        self::assertSame($operation, $element->localName);
        self::assertStringContainsString('<p:dmFromTime>2026-07-01T00:00:00+02:00</p:dmFromTime>', $xml);
        self::assertStringContainsString('<p:dmToTime>2026-12-01T13:30:00+01:00</p:dmToTime>', $xml, 'UTC input is converted to Prague time');
        self::assertStringContainsString('<p:dmStatusFilter>-1</p:dmStatusFilter>', $xml);
        self::assertStringContainsString('<p:dmLimit>1000</p:dmLimit>', $xml);
    }

    /** @return array<string, array{string}> */
    public static function listOperations(): array
    {
        return ['received' => ['GetListOfReceivedMessages'], 'sent' => ['GetListOfSentMessages']];
    }

    public function testListRejectsAnUnknownOperation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IsdsRequestBuilder::listOfMessages('MessageDownload', new \DateTimeImmutable(), new \DateTimeImmutable(), -1, 1, 10);
    }

    #[DataProvider('messageIdOperations')]
    public function testMessageIdRequestsAreValid(string $operation): void
    {
        $element = $this->assertValidRequest(IsdsRequestBuilder::byMessageId($operation, '4102001'), 'dm');
        self::assertSame($operation, $element->localName);
        self::assertSame('4102001', $element->getElementsByTagNameNS(self::NS, 'dmID')->item(0)->textContent);
    }

    /** @return array<string, array{string}> */
    public static function messageIdOperations(): array
    {
        return array_combine(
            ['GetDeliveryInfo', 'GetSignedDeliveryInfo', 'SignedMessageDownload', 'SignedSentMessageDownload', 'MessageEnvelopeDownload', 'SentMessageEnvelopeDownload'],
            array_map(static fn (string $o): array => [$o], ['GetDeliveryInfo', 'GetSignedDeliveryInfo', 'SignedMessageDownload', 'SignedSentMessageDownload', 'MessageEnvelopeDownload', 'SentMessageEnvelopeDownload']),
        );
    }

    public function testMessageIdMustBeNumeric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IsdsRequestBuilder::byMessageId('SignedMessageDownload', '1</p:dmID><x/>');
    }

    public function testCreateMessageIsValidAndEncodesAttachments(): void
    {
        $xml = IsdsRequestBuilder::createMessage([
            'recipient_box_id' => '7nyn2d9',
            'annotation' => 'Přiznání k DPH & "kontrolní" <hlášení>',
            'sender_ref_number' => 'ČJ 1/2026',
            'personal_delivery' => false,
        ], [
            ['name' => 'dphdp3-2026-09.xml', 'mime' => 'application/xml', 'meta_type' => '', 'bytes' => "<?xml version=\"1.0\"?><Pisemnost/>"],
            ['name' => 'doplnek "A".pdf', 'mime' => 'application/pdf', 'meta_type' => '', 'bytes' => '%PDF-1.4 fake'],
        ]);

        $element = $this->assertValidRequest($xml, 'dm');
        self::assertSame('CreateMessage', $element->localName);
        self::assertSame('7nyn2d9', $element->getElementsByTagNameNS(self::NS, 'dbIDRecipient')->item(0)->textContent);
        self::assertSame('Přiznání k DPH & "kontrolní" <hlášení>', $element->getElementsByTagNameNS(self::NS, 'dmAnnotation')->item(0)->textContent);

        $files = $element->getElementsByTagNameNS(self::NS, 'dmFile');
        self::assertCount(2, $files);
        self::assertSame('main', $files->item(0)->getAttribute('dmFileMetaType'), 'the first document is the main one');
        self::assertSame('enclosure', $files->item(1)->getAttribute('dmFileMetaType'));
        self::assertSame('application/xml', $files->item(0)->getAttribute('dmMimeType'));
        self::assertSame('doplnek "A".pdf', $files->item(1)->getAttribute('dmFileDescr'));
        self::assertSame(base64_encode("<?xml version=\"1.0\"?><Pisemnost/>"), $files->item(0)->getElementsByTagNameNS(self::NS, 'dmEncodedContent')->item(0)->textContent);
    }

    public function testCreateMessageNeedsAnAttachment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IsdsRequestBuilder::createMessage(['recipient_box_id' => '7nyn2d9', 'annotation' => 'x'], []);
    }

    public function testControlCharactersNeverReachTheXml(): void
    {
        $xml = IsdsRequestBuilder::createMessage(['recipient_box_id' => '7nyn2d9', 'annotation' => "a\x00b\x1Fc\x0Bd"], [
            ['name' => 'a.txt', 'mime' => 'text/plain', 'meta_type' => 'main', 'bytes' => 'x'],
        ]);
        $element = $this->assertValidRequest($xml, 'dm');
        self::assertSame('abcd', $element->getElementsByTagNameNS(self::NS, 'dmAnnotation')->item(0)->textContent);
    }

    public function testFindDataBoxRequestIsValid(): void
    {
        $byId = $this->assertValidRequest(IsdsRequestBuilder::findDataBox(['db_id' => '7nyn2d9']), 'db');
        self::assertSame('7nyn2d9', $byId->getElementsByTagNameNS(self::NS, 'dbID')->item(0)->textContent);

        $byName = $this->assertValidRequest(IsdsRequestBuilder::findDataBox(['name' => 'Finanční úřad pro Prahu & okolí', 'ic' => '72080043']), 'db');
        self::assertSame('Finanční úřad pro Prahu & okolí', $byName->getElementsByTagNameNS(self::NS, 'firmName')->item(0)->textContent);
    }
}
