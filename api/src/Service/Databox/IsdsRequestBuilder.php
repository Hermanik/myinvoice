<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * Builds the SOAP 1.1 request bodies of the ISDS web services (namespace http://isds.czechpoint.cz/v20,
 * document/literal, empty SOAPAction). Element names and order follow the official schemas
 * dmBaseTypes.xsd 3.10 and dbTypes.xsd from the operating-rules annex 2
 * (https://datovka.gov.cz/info/files/2256_Provozni_rad_ISDS_26_06_2026.zip).
 */
final class IsdsRequestBuilder
{
    public const NS = 'http://isds.czechpoint.cz/v20';

    /** Wraps an operation element into the SOAP envelope. */
    public static function envelope(string $operationXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:p="' . self::NS . '" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<soapenv:Header/><soapenv:Body>' . $operationXml . '</soapenv:Body></soapenv:Envelope>';
    }

    // ---- login information (service DsManage) ----

    public static function getOwnerInfo(): string
    {
        return self::envelope('<p:GetOwnerInfoFromLogin2><p:dbDummy></p:dbDummy></p:GetOwnerInfoFromLogin2>');
    }

    public static function getUserInfo(): string
    {
        return self::envelope('<p:GetUserInfoFromLogin2><p:dbDummy></p:dbDummy></p:GetUserInfoFromLogin2>');
    }

    public static function getPasswordInfo(): string
    {
        return self::envelope('<p:GetPasswordInfo><p:dbDummy></p:dbDummy></p:GetPasswordInfo>');
    }

    // ---- lists (service dx) ----

    /**
     * @param int $statusFilter -1 = every state; otherwise a bit mask where state n is bit 2^n (WS spec 2.9.1)
     */
    public static function listOfMessages(string $operation, \DateTimeInterface $from, \DateTimeInterface $to, int $statusFilter, int $offset, int $limit): string
    {
        if (!in_array($operation, ['GetListOfReceivedMessages', 'GetListOfSentMessages'], true)) {
            throw new \InvalidArgumentException("Not a list operation: {$operation}");
        }
        // Received uses dmRecipientOrgUnitNum, sent uses dmSenderOrgUnitNum (both unused, kept nil).
        $orgUnit = $operation === 'GetListOfReceivedMessages' ? 'dmRecipientOrgUnitNum' : 'dmSenderOrgUnitNum';
        return self::envelope(
            "<p:{$operation}>"
            . '<p:dmFromTime>' . self::time($from) . '</p:dmFromTime>'
            . '<p:dmToTime>' . self::time($to) . '</p:dmToTime>'
            . "<p:{$orgUnit} xsi:nil=\"true\"/>"
            . '<p:dmStatusFilter>' . $statusFilter . '</p:dmStatusFilter>'
            . '<p:dmOffset>' . max(1, $offset) . '</p:dmOffset>'
            . '<p:dmLimit>' . max(1, $limit) . '</p:dmLimit>'
            . "</p:{$operation}>"
        );
    }

    /** Operations that take just a message ID: GetDeliveryInfo, GetSignedDeliveryInfo, SignedMessageDownload ... */
    public static function byMessageId(string $operation, string $dmId): string
    {
        if (!in_array($operation, [
            'GetDeliveryInfo', 'GetSignedDeliveryInfo', 'SignedMessageDownload', 'SignedSentMessageDownload',
            'MessageEnvelopeDownload', 'SentMessageEnvelopeDownload',
        ], true)) {
            throw new \InvalidArgumentException("Not a message-id operation: {$operation}");
        }
        if (preg_match('/^[0-9]{1,20}$/', $dmId) !== 1) {
            throw new \InvalidArgumentException('Invalid message number.');
        }
        return self::envelope("<p:{$operation}><p:dmID>{$dmId}</p:dmID></p:{$operation}>");
    }

    // ---- sending (service dz) ----

    /**
     * @param array{recipient_box_id:string, annotation:string, to_hands?:?string, sender_ref_number?:?string,
     *              recipient_ref_number?:?string, sender_ident?:?string, recipient_ident?:?string,
     *              personal_delivery?:bool} $envelope
     * @param list<array{name:string, mime:string, meta_type:string, bytes:string}> $files
     */
    public static function createMessage(array $envelope, array $files): string
    {
        if ($files === []) {
            throw new \InvalidArgumentException('A message needs at least one attachment.');
        }
        $xml = '<p:CreateMessage><p:dmEnvelope>'
            . '<p:dmSenderOrgUnit xsi:nil="true"/><p:dmSenderOrgUnitNum xsi:nil="true"/>'
            . '<p:dbIDRecipient>' . self::esc($envelope['recipient_box_id']) . '</p:dbIDRecipient>'
            . '<p:dmRecipientOrgUnit xsi:nil="true"/><p:dmRecipientOrgUnitNum xsi:nil="true"/>'
            . self::optional('dmToHands', $envelope['to_hands'] ?? null)
            . '<p:dmAnnotation>' . self::esc($envelope['annotation']) . '</p:dmAnnotation>'
            . self::optional('dmRecipientRefNumber', $envelope['recipient_ref_number'] ?? null)
            . self::optional('dmSenderRefNumber', $envelope['sender_ref_number'] ?? null)
            . self::optional('dmRecipientIdent', $envelope['recipient_ident'] ?? null)
            . self::optional('dmSenderIdent', $envelope['sender_ident'] ?? null)
            . '<p:dmLegalTitleLaw xsi:nil="true"/><p:dmLegalTitleYear xsi:nil="true"/><p:dmLegalTitleSect xsi:nil="true"/>'
            . '<p:dmLegalTitlePar xsi:nil="true"/><p:dmLegalTitlePoint xsi:nil="true"/>'
            . '<p:dmPersonalDelivery>' . (($envelope['personal_delivery'] ?? false) ? 'true' : 'false') . '</p:dmPersonalDelivery>'
            . '<p:dmAllowSubstDelivery xsi:nil="true"/>'
            . '</p:dmEnvelope><p:dmFiles>';
        foreach ($files as $i => $file) {
            // The first document is the "main" one, the others are enclosures (WS spec 2.1.2).
            $metaType = $file['meta_type'] !== '' ? $file['meta_type'] : ($i === 0 ? 'main' : 'enclosure');
            $xml .= '<p:dmFile dmMimeType="' . self::esc($file['mime']) . '" dmFileMetaType="' . self::esc($metaType)
                . '" dmFileDescr="' . self::esc($file['name']) . '"><p:dmEncodedContent>'
                . base64_encode($file['bytes']) . '</p:dmEncodedContent></p:dmFile>';
        }
        return self::envelope($xml . '</p:dmFiles></p:CreateMessage>');
    }

    // ---- search (service df) ----

    /**
     * FindDataBox2. A non-OVM caller finds OVM boxes (tax offices, ...) and exactly one box with enabled postal
     * messages; the box ID alone is the most precise criterion (WS_vyhledavani_datovych_schranek.pdf 2.1).
     *
     * @param array{db_id?:?string, ic?:?string, name?:?string} $criteria
     */
    public static function findDataBox(array $criteria): string
    {
        $nil = static fn (string $n): string => "<p:{$n} xsi:nil=\"true\"/>";
        $val = static fn (string $n, ?string $v): string => ($v === null || $v === '') ? $nil($n) : "<p:{$n}>" . self::esc($v) . "</p:{$n}>";
        // Element order = tDbOwnerInfoExt21 in dbTypes.xsd.
        return self::envelope(
            '<p:FindDataBox2><p:dbOwnerInfo>'
            . $val('dbID', $criteria['db_id'] ?? null)
            . $nil('dbType')
            . $val('ic', $criteria['ic'] ?? null)
            . $nil('pnGivenNames') . $nil('pnLastName')
            . $val('firmName', $criteria['name'] ?? null)
            . $nil('biDate') . $nil('biCity') . $nil('biCounty') . $nil('biState')
            . $nil('adCode') . $nil('adCity') . $nil('adDistrict') . $nil('adStreet')
            . $nil('adNumberInStreet') . $nil('adNumberInMunicipality') . $nil('adZipCode') . $nil('adState')
            . $nil('nationality') . $nil('dbIdOVM') . $nil('dbState') . $nil('dbOpenAddressing') . $nil('dbUpperID')
            . '</p:dbOwnerInfo></p:FindDataBox2>'
        );
    }

    // ---- helpers ----

    /** Time in the Prague zone with an explicit offset, so the interval is unambiguous (WS spec 1.3.2). */
    public static function time(\DateTimeInterface $t): string
    {
        return \DateTimeImmutable::createFromInterface($t)->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d\TH:i:sP');
    }

    public static function esc(string $value): string
    {
        // XML 1.0 forbids most control characters; ISDS drops them anyway (WS spec 1.3.5).
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function optional(string $name, ?string $value): string
    {
        return ($value === null || $value === '') ? "<p:{$name} xsi:nil=\"true\"/>" : "<p:{$name}>" . self::esc($value) . "</p:{$name}>";
    }
}
