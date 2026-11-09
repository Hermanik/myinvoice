<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\Zfo;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\IsdsException;
use MyInvoice\Service\Databox\IsdsResponseParser;

/**
 * Reads the XML inside a verified ZFO and says which of the two documents it is. The element names are those of the ISDS
 * schema (the same ones the web service answers use, so the parsing is shared with {@see IsdsResponseParser}):
 *
 *  - a signed delivery receipt has a `dmDelivery` element (the envelope `dmDm`, the times, the state and `dmEvents`),
 *  - a signed message has a `dmReturnedMessage` element (the envelope `dmDm` with its `dmFiles`, plus the same lifecycle data).
 */
final class ZfoDocumentParser
{
    /**
     * @throws DataboxException when the content is neither of the two
     */
    public function parse(string $xml): ZfoDocument
    {
        try {
            $doc = IsdsResponseParser::load($xml, true);
        } catch (IsdsException) {
            throw new DataboxException('zfo_unreadable', 'Obsah ZFO se nepodařilo přečíst - není to datová zpráva ani doručenka.', 422);
        }
        $xp = new \DOMXPath($doc);

        $delivery = $this->element($xp, "//*[local-name()='dmDelivery']");
        if ($delivery !== null) {
            return $this->receipt($delivery);
        }
        $returned = $this->element($xp, "//*[local-name()='dmReturnedMessage']");
        $dm = $this->element($xp, "//*[local-name()='dmDm']");
        if ($dm === null || ($returned === null && $this->element($xp, "//*[local-name()='dmFiles']") === null)) {
            throw new DataboxException('zfo_unknown_content', 'Soubor ZFO neobsahuje datovou zprávu ani doručenku.', 422);
        }
        return $this->message($xp, $returned ?? ($dm->parentNode instanceof \DOMElement ? $dm->parentNode : $dm), $dm);
    }

    private function receipt(\DOMElement $delivery): ZfoDocument
    {
        $dm = IsdsResponseParser::child($delivery, 'dmDm') ?? throw new DataboxException('zfo_unknown_content', 'Doručenka neobsahuje obálku zprávy.', 422);
        $fields = IsdsResponseParser::deliveryFields($delivery);
        [$envelope, $dmId] = $this->envelope($dm);
        return new ZfoDocument(ZfoDocument::RECEIPT, $dmId, $envelope, $fields['status'], $fields['delivery_time'], $fields['acceptance_time'], null, [], $fields['xml']);
    }

    /** @param \DOMElement $container the element the envelope sits in (dmReturnedMessage): its lifecycle data are next to dmDm */
    private function message(\DOMXPath $xp, \DOMElement $container, \DOMElement $dm): ZfoDocument
    {
        [$envelope, $dmId] = $this->envelope($dm);
        $attachments = $this->attachments($xp);
        // The schema puts these next to dmDm; a file that nests them differently is still read (a descendant query also finds a direct child).
        $pick = static fn (string $local): ?string => trim((string) $xp->query(".//*[local-name()='{$local}']", $container)?->item(0)?->textContent) ?: null;
        $size = IsdsResponseParser::intOrNull($pick('dmAttachmentSize'))
            ?? ($attachments === [] ? null : (int) ceil(array_sum(array_column($attachments, 'size')) / 1024));

        return new ZfoDocument(
            ZfoDocument::MESSAGE,
            $dmId,
            $envelope,
            IsdsResponseParser::intOrNull($pick('dmMessageStatus')),
            IsdsResponseParser::dateTime($pick('dmDeliveryTime')),
            IsdsResponseParser::dateTime($pick('dmAcceptanceTime')),
            $size,
            $attachments,
            null,
        );
    }

    /** @return array{0:array<string,mixed>, 1:string} the envelope and the message number */
    private function envelope(\DOMElement $dm): array
    {
        $envelope = IsdsResponseParser::envelopeFields($dm);
        $dmId = (string) ($envelope['dm_id'] ?? '');
        if (preg_match('/^[0-9]{1,20}$/', $dmId) !== 1) {
            throw new DataboxException('zfo_unknown_content', 'V souboru ZFO chybí platné číslo zprávy.', 422);
        }
        $type = $dm->getAttribute('dmType');
        $envelope['message_type'] = $type !== '' ? $type : null;
        return [$envelope, $dmId];
    }

    /** @return list<array{size:int, sha256:string, epo:bool}> */
    private function attachments(\DOMXPath $xp): array
    {
        $out = [];
        foreach ($xp->query("//*[local-name()='dmFile']") ?: [] as $file) {
            /** @var \DOMElement $file */
            $content = IsdsResponseParser::text($file, 'dmEncodedContent');
            $bytes = $content !== null ? base64_decode($content, true) : false;
            if ($bytes === false || $bytes === '') {
                continue; // an attachment kept as inline XML (dmXMLContent) or damaged: nothing to fingerprint
            }
            $out[] = [
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'epo' => str_contains(substr($bytes, 0, 2048), '<Pisemnost'),
            ];
        }
        return $out;
    }

    private function element(\DOMXPath $xp, string $query): ?\DOMElement
    {
        $node = $xp->query($query)?->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }
}
