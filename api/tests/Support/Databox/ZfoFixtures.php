<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Databox;

use MyInvoice\Service\Databox\DataboxId;
use MyInvoice\Tests\Support\OpensslConfigTrait;

/**
 * Synthetic signed ZFO files for the import tests: the ISDS XML of a data message / delivery receipt (invented names, box
 * IDs and message numbers) sealed as a DER CMS SignedData with a throwaway key and a self-signed certificate that exist only
 * for the test run. Nothing real is in here.
 */
final class ZfoFixtures
{
    use OpensslConfigTrait;

    /** @var array<string, array{key:\OpenSSLAsymmetricKey, cert:\OpenSSLCertificate}> one throwaway signer per name */
    private static array $signers = [];

    /** What a message and a receipt have in common unless a test says otherwise. */
    private const DEFAULTS = [
        'dm_id' => '8800001', 'sender_name' => 'Synthetic Sender s.r.o.', 'recipient_name' => 'Synthetic Recipient', 'annotation' => 'Synthetic subject',
        'status' => 6, 'delivery' => '2026-10-20T10:00:00.000+02:00', 'acceptance' => '2026-10-20T10:00:05.000+02:00', 'personal' => false,
    ];

    /** A box ID that passes {@see DataboxId::isValid()}: the six given characters plus the check character. */
    public static function boxId(string $six): string
    {
        foreach (str_split('abcdefghijkmnpqrstuvwxyz23456789') as $check) {
            if (DataboxId::isValid($six . $check)) {
                return $six . $check;
            }
        }
        throw new \InvalidArgumentException("No check character for {$six}.");
    }

    /**
     * @param array{key:\OpenSSLAsymmetricKey, cert:\OpenSSLCertificate}|null $signer null = the shared throwaway signer
     * @param bool $detached sign without embedding the content (a different kind of CMS, never a ZFO)
     */
    public static function seal(string $xml, ?array $signer = null, bool $detached = false): string
    {
        $signer ??= self::signer();
        $in = tempnam(sys_get_temp_dir(), 'zfin');
        $out = tempnam(sys_get_temp_dir(), 'zfout');
        try {
            file_put_contents($in, $xml);
            $flags = OPENSSL_CMS_BINARY | ($detached ? OPENSSL_CMS_DETACHED : 0);
            if (!openssl_cms_sign($in, $out, $signer['cert'], $signer['key'], [], $flags, OPENSSL_ENCODING_DER)) {
                throw new \RuntimeException('openssl_cms_sign failed: ' . self::opensslErrors());
            }
            return (string) file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /** @return array{key:\OpenSSLAsymmetricKey, cert:\OpenSSLCertificate} */
    public static function signer(string $commonName = 'Synthetic ISDS Sealer', string $org = 'Synthetic Operator'): array
    {
        return self::$signers["{$commonName}|{$org}"] ??= self::createSigner($commonName, $org);
    }

    /** @return array{key:\OpenSSLAsymmetricKey, cert:\OpenSSLCertificate} */
    private static function createSigner(string $commonName, string $org): array
    {
        $config = self::opensslConfigArgs();
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048] + $config);
        $csr = $key !== false ? openssl_csr_new(['commonName' => $commonName, 'organizationName' => $org], $key, ['digest_alg' => 'sha256'] + $config) : false;
        $cert = $csr !== false ? openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256'] + $config) : false;
        if ($key === false || $cert === false) {
            throw new \RuntimeException('Cannot create the test signer: ' . self::opensslErrors());
        }
        return ['key' => $key, 'cert' => $cert];
    }

    /**
     * XML of a signed data message (what "Stáhnout zprávu" saves).
     *
     * @param array{dm_id?:string, sender?:string, sender_name?:string, recipient?:string, recipient_name?:string, annotation?:string,
     *              status?:?int, delivery?:?string, acceptance?:?string, files?:list<array{name:string, mime?:string, bytes:string}>,
     *              personal?:bool} $o
     */
    public static function messageXml(array $o): string
    {
        $o += self::DEFAULTS + ['files' => [['name' => 'note.txt', 'mime' => 'text/plain', 'bytes' => 'hello']]];
        $files = '';
        foreach ($o['files'] as $i => $f) {
            $files .= '<p:dmFile dmFileMetaType="' . ($i === 0 ? 'main' : 'enclosure') . '" dmFileDescr="' . htmlspecialchars($f['name'], ENT_XML1) . '"'
                . ' dmMimeType="' . htmlspecialchars($f['mime'] ?? 'application/octet-stream', ENT_XML1) . '"><p:dmEncodedContent>' . base64_encode($f['bytes']) . '</p:dmEncodedContent></p:dmFile>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<q:MessageDownloadResponse xmlns:q="http://isds.czechpoint.cz/v20/message" xmlns:p="http://isds.czechpoint.cz/v20"><q:dmReturnedMessage>'
            . self::dm($o) . '<p:dmFiles>' . $files . '</p:dmFiles></p:dmDm>'
            . '<p:dmHash algorithm="SHA-256">AAAA</p:dmHash><p:dmQTimestamp>MIIB</p:dmQTimestamp>'
            . self::lifecycle($o) . '<p:dmAttachmentSize>1</p:dmAttachmentSize>'
            . '</q:dmReturnedMessage></q:MessageDownloadResponse>';
    }

    /**
     * XML of a signed delivery receipt (what "Stáhnout doručenku" saves).
     *
     * @param array<string,mixed> $o see {@see messageXml()}; `events` = list<array{time:string, text:string}>
     */
    public static function receiptXml(array $o): string
    {
        $o += self::DEFAULTS + [
            'events' => [
                ['time' => '2026-10-20T10:00:00.000+02:00', 'text' => 'EV5: Datová zpráva byla dodána do datové schránky adresáta.'],
                ['time' => '2026-10-20T10:00:05.000+02:00', 'text' => 'EV11: Zpráva byla doručena přihlášením.'],
            ],
        ];
        $events = '';
        foreach ($o['events'] as $e) {
            $events .= '<p:dmEvent><p:dmEventTime>' . $e['time'] . '</p:dmEventTime><p:dmEventDescr>' . htmlspecialchars($e['text'], ENT_XML1) . '</p:dmEventDescr></p:dmEvent>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<q:GetDeliveryInfoResponse xmlns:q="http://isds.czechpoint.cz/v20/message" xmlns:p="http://isds.czechpoint.cz/v20"><q:dmDelivery>'
            . self::dm($o) . '</p:dmDm>'
            . '<p:dmHash algorithm="SHA-256">AAAA</p:dmHash><p:dmQTimestamp>MIIB</p:dmQTimestamp>'
            . self::lifecycle($o) . '<p:dmEvents>' . $events . '</p:dmEvents>'
            . '</q:dmDelivery></q:GetDeliveryInfoResponse>';
    }

    /** The opening of dmDm up to (not including) the files / the closing tag. @param array<string,mixed> $o */
    private static function dm(array $o): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_XML1);
        return '<p:dmDm><p:dmID>' . $o['dm_id'] . '</p:dmID>'
            . '<p:dbIDSender>' . $o['sender'] . '</p:dbIDSender><p:dmSender>' . $e($o['sender_name']) . '</p:dmSender><p:dmSenderType>20</p:dmSenderType>'
            . '<p:dbIDRecipient>' . $o['recipient'] . '</p:dbIDRecipient><p:dmRecipient>' . $e($o['recipient_name']) . '</p:dmRecipient>'
            . '<p:dmToHands xsi:nil="true" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"/>'
            . '<p:dmAnnotation>' . $e($o['annotation']) . '</p:dmAnnotation>'
            . '<p:dmPersonalDelivery>' . ($o['personal'] ? 'true' : 'false') . '</p:dmPersonalDelivery><p:dmAllowSubstDelivery>true</p:dmAllowSubstDelivery>';
    }

    /** @param array<string,mixed> $o */
    private static function lifecycle(array $o): string
    {
        return ($o['delivery'] !== null ? '<p:dmDeliveryTime>' . $o['delivery'] . '</p:dmDeliveryTime>' : '')
            . ($o['acceptance'] !== null ? '<p:dmAcceptanceTime>' . $o['acceptance'] . '</p:dmAcceptanceTime>' : '')
            . ($o['status'] !== null ? '<p:dmMessageStatus>' . $o['status'] . '</p:dmMessageStatus>' : '');
    }
}
