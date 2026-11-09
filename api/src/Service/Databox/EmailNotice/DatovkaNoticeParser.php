<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\EmailNotice;

/**
 * Reads the "new data message" notification e-mail that the data box system (ISDS) sends to the address a box holder
 * set in the portal (Nastavení - Notifikace) with the extended content ("Rozšířený obsah").
 *
 * The e-mail is HTML only; its first paragraph reads, line by line:
 *
 *   V datové schránce je nová datová zpráva: <b>věc</b>
 *   Odesílatel: <b>jméno</b> (schránka: abc1234)
 *   Příjemce: <b>jméno</b> (schránka: xyz9876)
 *   Číslo této zprávy je 1234567890.
 *
 * "nová systémová zpráva" announces a message issued by the system itself. The parser only recognises this shape: any
 * other e-mail - address verification codes, notices of a kind it has not seen - is reported as ignored and its content
 * is never looked at or kept; a notice with a missing fact is reported as failed instead of being guessed.
 */
final class DatovkaNoticeParser
{
    /** Subject of the one-time-code e-mail sent when a notification address is set. Its body is never read. */
    private const VERIFICATION_SUBJECT = '/^\s*Vložení nebo změna e-mailové adresy/iu';
    private const FIRST_LINE = '/nová (datová|systémová) zpráva[^:]*:\s*(.*)$/iu';
    private const BOX_LINE = '/^(Odesílatel|Příjemce):\s*(.*?)\s*\(schránka:\s*([a-z0-9]{7})\)\s*$/iu';
    private const NUMBER_LINE = '/^Číslo této zprávy je\s+(\d{1,20})\b/iu';
    /** The facts sit in the first paragraph; the footer after it is not examined. */
    private const MAX_LINES = 40;

    /**
     * @param string $subject decoded Subject header
     * @param string $rawHeader header block as received
     * @param string $rawBody undecoded body as received
     * @param ?\DateTimeImmutable $date Date header, becomes the notice time
     */
    public function parse(string $subject, string $rawHeader, string $rawBody, ?\DateTimeImmutable $date): DatovkaNoticeParse
    {
        if (preg_match(self::VERIFICATION_SUBJECT, $subject) === 1) {
            return DatovkaNoticeParse::ignored('address_verification');
        }

        $parts = MimeTextExtractor::extract($rawHeader, $rawBody);
        $lines = $parts['html'] !== null ? self::htmlLines($parts['html']) : self::textLines((string) $parts['text']);

        $first = null;
        foreach ($lines as $line) {
            if (preg_match(self::FIRST_LINE, $line, $m) === 1) {
                $first = $m;
                break;
            }
        }
        if ($first === null) {
            return DatovkaNoticeParse::ignored('not_a_message_notice');
        }

        // The labelled lines below the first one are read from the bottom up: the věc above them is written by the sender
        // of the message, so a crafted one must not be able to put a line of its own in front of the real ones.
        $sender = $recipient = $dmId = null;
        foreach (array_reverse($lines) as $line) {
            if (preg_match(self::BOX_LINE, $line, $m) === 1) {
                if (mb_strtolower($m[1]) === 'odesílatel') {
                    $sender ??= [$m[2], strtolower($m[3])];
                } else {
                    $recipient ??= [$m[2], strtolower($m[3])];
                }
            } elseif ($dmId === null && preg_match(self::NUMBER_LINE, $line, $m) === 1) {
                $dmId = $m[1];
            }
        }
        $missing = array_keys(array_filter(['sender' => $sender, 'recipient' => $recipient, 'dm_id' => $dmId], static fn ($v) => $v === null));
        if ($missing !== []) {
            return DatovkaNoticeParse::failed('missing_fields:' . implode(',', $missing));
        }

        return DatovkaNoticeParse::notice(new DatovkaNotice(
            kind: mb_strtolower($first[1]) === 'datová' ? DatovkaNotice::KIND_DATA : DatovkaNotice::KIND_SYSTEM,
            annotation: self::nullIfEmpty($first[2]),
            senderName: self::nullIfEmpty($sender[0]),
            senderBoxId: $sender[1],
            recipientName: self::nullIfEmpty($recipient[0]),
            recipientBoxId: $recipient[1],
            dmId: $dmId,
            noticedAt: $date,
        ));
    }

    /** @return list<string> */
    private static function htmlLines(string $html): array
    {
        $html = (string) preg_replace('~<(script|style)\b.*?</\1\s*>~is', ' ', $html);
        // Source line breaks are whitespace in HTML; only <br> and the end of a paragraph start a new line.
        $html = (string) preg_replace('/\s+/u', ' ', $html);
        $html = (string) preg_replace(['~<br\s*/?>~i', '~</p\s*>~i'], "\n", $html);
        return self::textLines(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return list<string> */
    private static function textLines(string $text): array
    {
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
            if (count($lines) >= self::MAX_LINES) {
                break;
            }
        }
        return $lines;
    }

    private static function nullIfEmpty(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
