<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

/**
 * Čte ZFO datovou zprávu (stažená/odeslaná z datové schránky) = PKCS#7/CMS
 * podepsaná XML obálka v ISDS formátu.
 *
 * Postup (čistě v PHP, bez shellování na openssl CLI — stejné chování Win/Docker):
 *   1. DER PKCS#7 SignedData → reassembly eContent (pkcs7-data) z BER OCTET STRING
 *      (zvládá constructed/indefinite chunking).
 *   2. eContent = XML obálka ISDS → extrakce VEŠKERÝCH metadat + příloh (dmFile,
 *      base64 dmEncodedContent).
 *
 * Podpis NEOVĚŘUJEME — jen extrahujeme obsah. XML parsujeme s tvrdým zákazem
 * DOCTYPE/externích entit (anti-XXE / billion-laughs).
 */
final class ZfoExtractor
{
    /** OID 1.2.840.113549.1.7.1 (pkcs7-data) — DER content. */
    private const OID_PKCS7_DATA = '2a864886f70d010701';
    /** OID 1.2.840.113549.1.7.2 (signedData). */
    private const OID_SIGNED_DATA = '2a864886f70d010702';

    /** Anti-bomb: strop dekódovaného eContentu i jednotlivých příloh. */
    private const MAX_CONTENT_BYTES    = 200 * 1024 * 1024;
    private const MAX_ATTACHMENT_BYTES = 100 * 1024 * 1024;

    /** Heuristika: vypadá vstup jako PKCS#7 SignedData (DER)? */
    public static function looksLikeZfo(string $bytes): bool
    {
        if ($bytes === '' || ord($bytes[0]) !== 0x30) {
            return false;
        }
        // signedData OID se vyskytuje hned v hlavičce ContentInfo
        return str_contains(bin2hex(substr($bytes, 0, 64)), self::OID_SIGNED_DATA);
    }

    /**
     * @return array{metadata:array<string,mixed>,attachments:list<array{name:string,mime:string,meta_type:string,bytes:string}>,envelope_xml:string}
     * @throws DocumentException
     */
    public function extract(string $der): array
    {
        $xml = $this->extractEContent($der);
        if ($xml === null || trim($xml) === '') {
            throw new DocumentException('zfo_parse_failed', 'V ZFO se nepodařilo najít obsah datové zprávy.', 422);
        }
        return $this->parseEnvelope($xml);
    }

    // ───────────────────────── PKCS#7 / BER ─────────────────────────

    /** Najde eContent (pkcs7-data OCTET STRING) a vrátí jeho byty (XML), nebo null. */
    private function extractEContent(string $der): ?string
    {
        $len = strlen($der);
        if ($len > self::MAX_CONTENT_BYTES) {
            throw new DocumentException('zfo_too_large', 'ZFO je příliš velké.', 413);
        }
        $pos = 0;
        $nodes = $this->parseNodes($der, $pos, $len, 0);
        return $this->findEContent($nodes);
    }

    /**
     * Rekurzivně hledá encapContentInfo: SEQUENCE, jejíž první potomek je OID
     * pkcs7-data a druhý [0] EXPLICIT obsahuje OCTET STRING s obsahem.
     */
    private function findEContent(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if (($node['constructed'] ?? false) && ($node['children'] ?? null) !== null) {
                $children = $node['children'];
                // encapContentInfo pattern
                for ($i = 0; $i < count($children) - 1; $i++) {
                    $a = $children[$i];
                    $b = $children[$i + 1];
                    if (($a['tag'] ?? -1) === 0x06 && ($a['class'] ?? -1) === 0
                        && bin2hex((string) ($a['value'] ?? '')) === self::OID_PKCS7_DATA
                        && ($b['class'] ?? -1) === 2 && ($b['tag'] ?? -1) === 0 && ($b['constructed'] ?? false)) {
                        $bytes = $this->octetStringBytes($b);
                        if ($bytes !== '') return $bytes;
                    }
                }
                $deep = $this->findEContent($children);
                if ($deep !== null) return $deep;
            }
        }
        return null;
    }

    /** Reassembly OCTET STRING obsahu (zploští constructed/indefinite chunky). */
    private function octetStringBytes(array $node): string
    {
        if (!($node['constructed'] ?? false)) {
            return (string) ($node['value'] ?? '');
        }
        $out = '';
        foreach (($node['children'] ?? []) as $child) {
            $out .= $this->octetStringBytes($child);
            if (strlen($out) > self::MAX_CONTENT_BYTES) {
                throw new DocumentException('zfo_too_large', 'Obsah ZFO je příliš velký.', 413);
            }
        }
        return $out;
    }

    /**
     * Minimální BER/DER parser. Vrací stromovou strukturu uzlů. Zvládá
     * definite i indefinite length (0x80 + EOC 0x00 0x00).
     *
     * @return list<array{class:int,constructed:bool,tag:int,value:?string,children:?array}>
     */
    private function parseNodes(string $d, int &$pos, int $end, int $depth): array
    {
        if ($depth > 64) {
            throw new DocumentException('zfo_parse_failed', 'ZFO struktura je příliš zanořená.', 422);
        }
        $nodes = [];
        while ($pos < $end) {
            // End-of-contents pro indefinite length
            if ($pos + 1 < $end && $d[$pos] === "\x00" && $d[$pos + 1] === "\x00") {
                $pos += 2;
                break;
            }
            if ($pos >= $end) break;

            $idByte = ord($d[$pos++]);
            $class = ($idByte & 0xC0) >> 6;
            $constructed = ($idByte & 0x20) !== 0;
            $tag = $idByte & 0x1F;
            if ($tag === 0x1F) { // high-tag-number form
                $tag = 0;
                do {
                    if ($pos >= $end) break 2;
                    $b = ord($d[$pos++]);
                    $tag = ($tag << 7) | ($b & 0x7F);
                } while ($b & 0x80);
            }

            if ($pos >= $end) break;
            $lenByte = ord($d[$pos++]);
            $indefinite = false;
            $length = 0;
            if ($lenByte === 0x80) {
                $indefinite = true;
            } elseif ($lenByte & 0x80) {
                $n = $lenByte & 0x7F;
                if ($n > 4 || $pos + $n > $end) break;
                for ($i = 0; $i < $n; $i++) {
                    $length = ($length << 8) | ord($d[$pos++]);
                }
            } else {
                $length = $lenByte;
            }

            if ($constructed) {
                if ($indefinite) {
                    $children = $this->parseNodes($d, $pos, $end, $depth + 1);
                } else {
                    $childEnd = min($pos + $length, $end);
                    $childPos = $pos;
                    $children = $this->parseNodes($d, $childPos, $childEnd, $depth + 1);
                    $pos = $childEnd;
                }
                $nodes[] = ['class' => $class, 'constructed' => true, 'tag' => $tag, 'value' => null, 'children' => $children];
            } else {
                if ($indefinite) break; // primitive nesmí mít indefinite length
                $value = substr($d, $pos, $length);
                $pos += $length;
                $nodes[] = ['class' => $class, 'constructed' => false, 'tag' => $tag, 'value' => $value, 'children' => null];
            }
        }
        return $nodes;
    }

    // ───────────────────────── ISDS XML ─────────────────────────

    /**
     * @return array{metadata:array<string,mixed>,attachments:list<array{name:string,mime:string,meta_type:string,bytes:string}>,envelope_xml:string}
     */
    private function parseEnvelope(string $xml): array
    {
        // Anti-XXE: reject a DOCTYPE outright (no entities) and disable the external loader.
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new DocumentException('zfo_parse_failed', 'ZFO obsahuje nepovolenou DTD deklaraci.', 422);
        }

        $prevLoader = null;
        if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            libxml_disable_entity_loader(true); // no-op on PHP 8+, harmless
        }
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            throw new DocumentException('zfo_parse_failed', 'Obsah datové zprávy se nepodařilo přečíst.', 422);
        }
        // The byte check above misses a DOCTYPE in another encoding (UTF-16): whatever the encoding, the parsed
        // document must not have one.
        if ($dom->doctype !== null) {
            throw new DocumentException('zfo_parse_failed', 'ZFO obsahuje nepovolenou DTD deklaraci.', 422);
        }

        $xp = new \DOMXPath($dom);
        $get = function (string $local) use ($xp): ?string {
            $nl = $xp->query("//*[local-name()='{$local}']");
            if ($nl === false || $nl->length === 0) return null;
            $v = trim((string) $nl->item(0)->textContent);
            return $v === '' ? null : $v;
        };

        $direction = 'unknown';
        if (stripos($xml, 'SentMessage') !== false) $direction = 'sent';
        elseif (stripos($xml, 'ReceivedMessage') !== false) $direction = 'received';

        $metadata = [
            'dm_id'                => $get('dmID'),
            'direction'            => $direction,
            'sender_box_id'        => $get('dbIDSender'),
            'sender_name'          => $get('dmSender'),
            'sender_address'       => $get('dmSenderAddress'),
            'sender_type'          => $get('dmSenderType'),
            'recipient_box_id'     => $get('dbIDRecipient'),
            'recipient_name'       => $get('dmRecipient'),
            'recipient_address'    => $get('dmRecipientAddress'),
            'annotation'           => $get('dmAnnotation'),
            'sender_ref_number'    => $get('dmSenderRefNumber'),
            'sender_ident'         => $get('dmSenderIdent'),
            'recipient_ref_number' => $get('dmRecipientRefNumber'),
            'recipient_ident'      => $get('dmRecipientIdent'),
            'dm_type'              => $get('dmType'),
            'dm_status'            => $get('dmMessageStatus') ?? $get('dmStatus'),
            'delivery_time'        => $this->normalizeDate($get('dmDeliveryTime')),
            'acceptance_time'      => $this->normalizeDate($get('dmAcceptanceTime')),
            'envelope_xml'         => $xml,
        ];

        $attachments = [];
        $files = $xp->query("//*[local-name()='dmFile']");
        if ($files !== false) {
            foreach ($files as $idx => $file) {
                /** @var \DOMElement $file */
                $descr = $this->attr($file, 'dmFileDescr') ?: ('attachment-' . ($idx + 1));
                $mime  = $this->attr($file, 'dmMimeType') ?: 'application/octet-stream';
                $meta  = $this->attr($file, 'dmFileMetaType') ?: 'enclosure';
                $b64 = '';
                foreach ($file->childNodes as $c) {
                    if ($c instanceof \DOMElement && strtolower($c->localName ?? '') === 'dmencodedcontent') {
                        $b64 = trim($c->textContent);
                        break;
                    }
                }
                if ($b64 === '') continue;
                $bytes = base64_decode($b64, true);
                if ($bytes === false || $bytes === '') continue;
                if (strlen($bytes) > self::MAX_ATTACHMENT_BYTES) {
                    throw new DocumentException('zfo_too_large', 'Příloha v ZFO je příliš velká.', 413);
                }
                $attachments[] = [
                    'name'      => $descr,
                    'mime'      => $mime,
                    'meta_type' => $meta,
                    'bytes'     => $bytes,
                ];
            }
        }

        return ['metadata' => $metadata, 'attachments' => $attachments, 'envelope_xml' => $xml];
    }

    private function attr(\DOMElement $el, string $name): ?string
    {
        $v = $el->getAttribute($name);
        return $v !== '' ? $v : null;
    }

    private function normalizeDate(?string $v): ?string
    {
        if ($v === null || $v === '') return null;
        try {
            return (new \DateTimeImmutable($v))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
