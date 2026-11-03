<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Databox;

use MyInvoice\Service\Databox\IsdsTransportResponse;
use MyInvoice\Service\Pdf\Asn1;

/**
 * Handwritten ISDS responses (api/tests/Fixtures/Databox/*.xml, each validated against the official XSD in
 * DataboxFixturesSchemaTest) and a builder of synthetic signed messages (ZFO = CMS SignedData around the ISDS XML).
 */
final class DataboxFixtures
{
    public const OWNER_PFO = 'tpbxbx7';
    public const OWNER_FO = 'vqt3fkw';
    public const TAX_OFFICE_PRAHA = '7nyn2d9';

    public static function path(string $file): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Databox/' . $file;
    }

    public static function xml(string $name): string
    {
        return (string) file_get_contents(self::path($name . '.xml'));
    }

    public static function response(string $name, int $status = 200): IsdsTransportResponse
    {
        return new IsdsTransportResponse($status, self::xml($name));
    }

    /** SOAP response carrying a sealed document, as returned by SignedMessageDownload / SignedSentMessageDownload. */
    public static function signedResponse(string $operation, string $zfo): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"><SOAP-ENV:Body>'
            . "<q:{$operation}Response xmlns:q=\"http://isds.czechpoint.cz/v20\"><q:dmSignature>" . base64_encode($zfo) . '</q:dmSignature>'
            . '<q:dmStatus><q:dmStatusCode>0000</q:dmStatusCode><q:dmStatusMessage>Provedeno úspěšně.</q:dmStatusMessage></q:dmStatus>'
            . "</q:{$operation}Response></SOAP-ENV:Body></SOAP-ENV:Envelope>";
    }

    /**
     * A message as ISDS exports it (ZFO): PKCS#7 SignedData whose content is the ISDS XML. The signature itself is not
     * part of the model - the extractor does not verify it.
     *
     * @param list<array{name:string, mime:string, bytes:string}> $files
     */
    public static function zfo(string $dmId, array $files, string $annotation = 'Testovací zpráva'): string
    {
        $parts = '';
        foreach ($files as $i => $f) {
            $parts .= '<p:dmFile dmFileMetaType="' . ($i === 0 ? 'main' : 'enclosure') . '" dmFileDescr="' . htmlspecialchars($f['name'], ENT_XML1) . '"'
                . ' dmMimeType="' . htmlspecialchars($f['mime'], ENT_XML1) . '"><p:dmEncodedContent>' . base64_encode($f['bytes']) . '</p:dmEncodedContent></p:dmFile>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<q:MessageDownloadResponse xmlns:q="http://isds.czechpoint.cz/v20/message"><q:dmReturnedMessage>'
            . '<p:dmDm xmlns:p="http://isds.czechpoint.cz/v20"><p:dmID>' . $dmId . '</p:dmID>'
            . '<p:dbIDSender>7nyn2d9</p:dbIDSender><p:dmSender>Finanční úřad pro hlavní město Prahu</p:dmSender>'
            . '<p:dbIDRecipient>' . self::OWNER_PFO . '</p:dbIDRecipient><p:dmRecipient>Jan Testovací</p:dmRecipient>'
            . '<p:dmAnnotation>' . htmlspecialchars($annotation, ENT_XML1) . '</p:dmAnnotation>'
            . '<p:dmFiles>' . $parts . '</p:dmFiles></p:dmDm></q:dmReturnedMessage></q:MessageDownloadResponse>';
        return self::cms($xml);
    }

    /** UTF-16LE document with a byte-order mark: its "<!DOCTYPE" is invisible to a byte search on the raw string. */
    public static function utf16(string $xmlWithoutDeclaration): string
    {
        return "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>' . $xmlWithoutDeclaration, 'UTF-16LE', 'UTF-8');
    }

    /** The "billion laughs" expansion (10^9 times "lol"), hidden in UTF-16. Never expand it: it must be refused unparsed. */
    public static function utf16BillionLaughs(): string
    {
        $defs = '<!ENTITY l0 "lol">';
        for ($i = 1; $i <= 9; $i++) {
            $defs .= "<!ENTITY l{$i} \"" . str_repeat('&l' . ($i - 1) . ';', 10) . '">';
        }
        return self::utf16('<!DOCTYPE lolz [' . $defs . ']><lolz>&l9;</lolz>');
    }

    private static function cms(string $xml): string
    {
        $encap = Asn1::tlv(0x30, Asn1::tlv(0x06, Asn1::oid('1.2.840.113549.1.7.1')) . Asn1::tlv(0xA0, Asn1::tlv(0x04, $xml)));
        $signed = Asn1::tlv(0x30, Asn1::tlv(0x02, chr(1)) . Asn1::tlv(0x31, '') . $encap);
        return Asn1::tlv(0x30, Asn1::tlv(0x06, Asn1::oid('1.2.840.113549.1.7.2')) . Asn1::tlv(0xA0, $signed));
    }
}
