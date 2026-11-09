<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox;

/**
 * Parses ISDS SOAP responses. Lookup is by local element name, so the namespace variants the operator uses
 * (".../v20", ".../v20/message", prefixes) do not matter. XML is loaded with DTDs and network access disabled.
 */
final class IsdsResponseParser
{
    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * Returns the operation response element ("<Operation>Response") of a SOAP body.
     *
     * @throws IsdsException on a SOAP fault, malformed XML or when the expected element is missing
     */
    public static function operationElement(string $body, string $expectedLocalName): \DOMElement
    {
        $doc = self::load($body, true);
        $xp = new \DOMXPath($doc);

        $fault = $xp->query("//*[local-name()='Fault']")->item(0);
        if ($fault instanceof \DOMElement) {
            $text = trim((string) ($xp->query(".//*[local-name()='faultstring' or local-name()='Text']", $fault)->item(0)?->textContent ?? ''));
            throw new IsdsException(
                'server_error',
                'Systém datových schránek vrátil chybu' . ($text !== '' ? ': ' . mb_substr($text, 0, 200) : '.'),
            );
        }

        $el = $xp->query("//*[local-name()='{$expectedLocalName}']")->item(0);
        if (!$el instanceof \DOMElement) {
            throw IsdsException::invalidResponse("chybí {$expectedLocalName}");
        }
        return $el;
    }

    /**
     * Status of a response (dmStatus for message operations, dbStatus for box operations).
     * Codes starting with "00" are success / information (0002 = nothing found, 0003 = result truncated).
     *
     * @return array{code:string, message:string}
     */
    public static function status(\DOMElement $response): array
    {
        $node = self::child($response, 'dmStatus') ?? self::child($response, 'dbStatus');
        if ($node === null) {
            throw IsdsException::invalidResponse('chybí stav operace');
        }
        $code = self::text($node, 'dmStatusCode') ?? self::text($node, 'dbStatusCode') ?? '';
        $message = self::text($node, 'dmStatusMessage') ?? self::text($node, 'dbStatusMessage') ?? '';
        if ($code === '') {
            throw IsdsException::invalidResponse('chybí kód stavu');
        }
        return ['code' => $code, 'message' => $message];
    }

    /** Throws for every status that is not a success (00xx). */
    public static function assertSuccess(\DOMElement $response): array
    {
        $status = self::status($response);
        if (!str_starts_with($status['code'], '00')) {
            throw IsdsException::fromStatus($status['code'], $status['message']);
        }
        return $status;
    }

    /** @return array{db_id:?string, db_type:?string, ic:?string, name:?string, state:?int} */
    public static function ownerInfo(\DOMElement $response): array
    {
        $owner = self::child($response, 'dbOwnerInfo');
        if ($owner === null) {
            throw IsdsException::invalidResponse('chybí dbOwnerInfo');
        }
        return self::ownerFields($owner);
    }

    /** @return list<array{db_id:?string, db_type:?string, ic:?string, name:?string, state:?int}> */
    public static function foundBoxes(\DOMElement $response): array
    {
        $out = [];
        $results = self::child($response, 'dbResults');
        if ($results === null) {
            return $out;
        }
        foreach ($results->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === 'dbOwnerInfo') {
                $out[] = self::ownerFields($node);
            }
        }
        return $out;
    }

    /** @return array{user_type:?string, privileges:int, isds_id:?string, name:?string} */
    public static function userInfo(\DOMElement $response): array
    {
        $user = self::child($response, 'dbUserInfo');
        if ($user === null) {
            throw IsdsException::invalidResponse('chybí dbUserInfo');
        }
        $name = trim((self::text($user, 'pnGivenNames') ?? '') . ' ' . (self::text($user, 'pnLastName') ?? ''));
        return [
            'user_type' => self::text($user, 'userType'),
            'privileges' => (int) (self::text($user, 'userPrivils') ?? 0),
            'isds_id' => self::text($user, 'isdsID'),
            'name' => $name !== '' ? $name : null,
        ];
    }

    public static function passwordExpiry(\DOMElement $response): ?\DateTimeImmutable
    {
        return self::dateTime(self::text($response, 'pswExpDate'));
    }

    /**
     * Envelopes of GetListOfReceivedMessages / GetListOfSentMessages.
     *
     * @return list<array<string,mixed>>
     */
    public static function messageRecords(\DOMElement $response): array
    {
        $out = [];
        $records = self::child($response, 'dmRecords');
        if ($records === null) {
            return $out;
        }
        foreach ($records->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === 'dmRecord') {
                $out[] = self::envelopeFields($node) + [
                    'status' => self::intOrNull(self::text($node, 'dmMessageStatus')),
                    'attachment_size_kb' => self::intOrNull(self::text($node, 'dmAttachmentSize')),
                    'delivery_time' => self::dateTime(self::text($node, 'dmDeliveryTime')),
                    'acceptance_time' => self::dateTime(self::text($node, 'dmAcceptanceTime')),
                    'message_type' => $node->getAttribute('dmType') !== '' ? $node->getAttribute('dmType') : null,
                ];
            }
        }
        return $out;
    }

    /**
     * GetDeliveryInfo (the unsigned delivery receipt).
     *
     * @return array{xml:string, status:?int, delivery_time:?\DateTimeImmutable, acceptance_time:?\DateTimeImmutable, events:list<array{time:?\DateTimeImmutable, text:string}>}
     */
    public static function deliveryInfo(\DOMElement $response): array
    {
        $delivery = self::child($response, 'dmDelivery');
        if ($delivery === null) {
            throw IsdsException::invalidResponse('chybí dmDelivery');
        }
        return self::deliveryFields($delivery);
    }

    /**
     * The fields of a dmDelivery element, wherever it comes from: the GetDeliveryInfo response or the content of a signed
     * delivery receipt (ZFO).
     *
     * @return array{xml:string, status:?int, delivery_time:?\DateTimeImmutable, acceptance_time:?\DateTimeImmutable, events:list<array{time:?\DateTimeImmutable, text:string}>}
     */
    public static function deliveryFields(\DOMElement $delivery): array
    {
        $events = [];
        $list = self::child($delivery, 'dmEvents');
        if ($list !== null) {
            foreach ($list->childNodes as $event) {
                if ($event instanceof \DOMElement && $event->localName === 'dmEvent') {
                    $events[] = [
                        'time' => self::dateTime(self::text($event, 'dmEventTime')),
                        'text' => self::text($event, 'dmEventDescr') ?? '',
                    ];
                }
            }
        }
        // The hash and the qualified time stamp are bulky and useless without the message; drop them from the stored copy.
        $copy = $delivery->cloneNode(true);
        foreach (['dmQTimestamp'] as $drop) {
            foreach (iterator_to_array($copy->getElementsByTagNameNS('*', $drop)) as $n) {
                $n->parentNode?->removeChild($n);
            }
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $doc->appendChild($doc->importNode($copy, true));

        return [
            'xml' => (string) $doc->saveXML(),
            'status' => self::intOrNull(self::text($delivery, 'dmMessageStatus')),
            'delivery_time' => self::dateTime(self::text($delivery, 'dmDeliveryTime')),
            'acceptance_time' => self::dateTime(self::text($delivery, 'dmAcceptanceTime')),
            'events' => $events,
        ];
    }

    /** Decoded content of dmSignature (signed message / receipt = ZFO bytes). */
    public static function signature(\DOMElement $response): string
    {
        $b64 = self::text($response, 'dmSignature');
        if ($b64 === null) {
            throw IsdsException::invalidResponse('chybí dmSignature');
        }
        $bytes = base64_decode($b64, true); // strict decoding still skips the line breaks of wrapped base64
        if ($bytes === false || $bytes === '') {
            throw IsdsException::invalidResponse('dmSignature není platné base64');
        }
        return $bytes;
    }

    public static function createdMessageId(\DOMElement $response): string
    {
        $id = self::text($response, 'dmID');
        if ($id === null || preg_match('/^[0-9]{1,20}$/', $id) !== 1) {
            throw IsdsException::invalidResponse('chybí číslo zprávy');
        }
        return $id;
    }

    // ---- helpers ----

    /**
     * @param bool $soapResponse a SOAP response of ISDS: the only input that may carry the multi-megabyte base64 content
     *                           of a message, so the only one parsed without libxml's size limits (LIBXML_PARSEHUGE)
     * @throws IsdsException
     */
    public static function load(string $xml, bool $soapResponse = false): \DOMDocument
    {
        if (trim($xml) === '') {
            throw IsdsException::invalidResponse('prázdná odpověď');
        }
        // Without the size limits a hostile document could expand without bound while it is parsed, so such a
        // document must be plain UTF-8: in UTF-16/32 the byte check for a DOCTYPE below would see nothing.
        if ($soapResponse && !self::isPlainUtf8($xml)) {
            throw IsdsException::invalidResponse('neočekávané kódování');
        }
        // A DOCTYPE is never legitimate in an ISDS response; refusing it removes the whole XXE class.
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw IsdsException::invalidResponse('nepovolená DTD');
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT | ($soapResponse ? LIBXML_PARSEHUGE : 0));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw IsdsException::invalidResponse('neplatné XML');
        }
        // The byte check above cannot see a DOCTYPE in another encoding (UTF-16): whatever the encoding, the parsed
        // document must not have one.
        if ($doc->doctype !== null) {
            throw IsdsException::invalidResponse('nepovolená DTD');
        }
        return $doc;
    }

    /** No NUL byte / byte-order mark (UTF-16, UTF-32) and no encoding declaration other than UTF-8. */
    private static function isPlainUtf8(string $xml): bool
    {
        if (str_contains($xml, "\0") || str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\xFE\xFF")) {
            return false;
        }
        if (preg_match('/^\s*<\?xml[^>]*\sencoding\s*=\s*["\']([^"\']+)["\']/i', $xml, $m) === 1) {
            return strcasecmp($m[1], 'UTF-8') === 0;
        }
        return true;
    }

    /** Text of the first direct child with this local name; null when missing, empty or xsi:nil. */
    public static function text(\DOMElement $parent, string $local): ?string
    {
        $node = self::child($parent, $local);
        if ($node === null || $node->getAttributeNS(self::XSI, 'nil') === 'true' || $node->getAttributeNS(self::XSI, 'nil') === '1') {
            return null;
        }
        $value = trim($node->textContent);
        return $value === '' ? null : $value;
    }

    public static function child(\DOMElement $parent, string $local): ?\DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $local) {
                return $node;
            }
        }
        return null;
    }

    /** ISO 8601 with offset -> immutable date-time in the application time zone. */
    public static function dateTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function intOrNull(?string $value): ?int
    {
        return ($value !== null && preg_match('/^-?[0-9]+$/', $value) === 1) ? (int) $value : null;
    }

    /** @return array{db_id:?string, db_type:?string, ic:?string, name:?string, state:?int} */
    private static function ownerFields(\DOMElement $owner): array
    {
        $person = trim((self::text($owner, 'pnGivenNames') ?? self::text($owner, 'pnFirstName') ?? '') . ' ' . (self::text($owner, 'pnLastName') ?? ''));
        return [
            'db_id' => self::text($owner, 'dbID'),
            'db_type' => self::text($owner, 'dbType'),
            'ic' => self::text($owner, 'ic'),
            'name' => self::text($owner, 'firmName') ?? ($person !== '' ? $person : null),
            'state' => self::intOrNull(self::text($owner, 'dbState')),
        ];
    }

    /** Envelope part shared by dmRecord (lists) and dmDm (messages and receipts). @return array<string,mixed> */
    public static function envelopeFields(\DOMElement $el): array
    {
        return [
            'dm_id' => self::text($el, 'dmID'),
            'sender_box_id' => self::text($el, 'dbIDSender'),
            'sender_name' => self::text($el, 'dmSender'),
            'sender_type' => self::intOrNull(self::text($el, 'dmSenderType')),
            'recipient_box_id' => self::text($el, 'dbIDRecipient'),
            'recipient_name' => self::text($el, 'dmRecipient'),
            'annotation' => self::text($el, 'dmAnnotation'),
            'to_hands' => self::text($el, 'dmToHands'),
            'sender_ref_number' => self::text($el, 'dmSenderRefNumber'),
            'recipient_ref_number' => self::text($el, 'dmRecipientRefNumber'),
            'sender_ident' => self::text($el, 'dmSenderIdent'),
            'recipient_ident' => self::text($el, 'dmRecipientIdent'),
            'personal_delivery' => in_array(self::text($el, 'dmPersonalDelivery'), ['true', '1'], true),
            'allow_subst_delivery' => ($v = self::text($el, 'dmAllowSubstDelivery')) === null ? null : in_array($v, ['true', '1'], true),
        ];
    }
}
