<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Databox;

use MyInvoice\Service\Bank\EmailNotice\BankEmailNoticeMessage;
use MyInvoice\Service\Bank\EmailNotice\MimeHeaderDecoder;
use MyInvoice\Tests\Support\Dkim\DkimTestSigner;

/**
 * Builds raw "new data message" notification e-mails of the data box system out of SYNTHETIC data: the same MIME
 * structure the real ones have (S/MIME multipart/signed around multipart/mixed, one quoted-printable text/html part, no
 * text/plain), but invented names, box IDs and message numbers.
 */
final class DatovkaNoticeFixtures
{
    public const SENDER_BOX = 'abc1234';
    public const RECIPIENT_BOX = 'xyz987r';
    public const DM_ID = '1234567890';

    /**
     * @param array{kind?:string, annotation?:string, sender_name?:string, sender_box?:string, recipient_name?:string,
     *              recipient_box?:string, dm_id?:string, line?:?string} $o `line` replaces the whole first paragraph
     */
    public static function html(array $o = []): string
    {
        $o += [
            'kind' => 'data', 'annotation' => 'Výzva k podání kontrolního hlášení',
            'sender_name' => 'Finanční úřad pro Testovací kraj (Územní pracoviště v Testově)', 'sender_box' => self::SENDER_BOX,
            'recipient_name' => 'Jana Testovací - podnikající fyzická osoba', 'recipient_box' => self::RECIPIENT_BOX,
            'dm_id' => self::DM_ID, 'line' => null,
        ];
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $what = $o['kind'] === 'system' ? 'systémová' : 'datová';
        $paragraph = $o['line'] ?? ('V datové schránce je nová ' . $what . ' zpráva: <b>' . $esc($o['annotation']) . '</b>'
            . "\n<br>Odesílatel: <b>" . $esc($o['sender_name']) . '</b> (schránka: ' . $o['sender_box'] . ')'
            . "\n<br>Příjemce: <b>" . $esc($o['recipient_name']) . '</b> (schránka: ' . $o['recipient_box'] . ')'
            . "\n<br>Číslo této zprávy je " . $o['dm_id'] . '.'
            . "\n<br>Notifikace jsou vám zasílány dle vašeho nastavení a mají pouze informativní charakter.");
        return "<html><body>\n<p>" . $paragraph . "</p>\n"
            . "<p>Přejeme vám krásný den,<br>Digitální a informační agentura</p>\n"
            . "<p style=\"font-size:11px\">Tento e-mail byl vygenerován automaticky, neodpovídejte na něj.</p>\n</body></html>\n";
    }

    /**
     * An unsigned raw message (CRLF). `subject` is the plain text subject, encoded here as MIME Q.
     *
     * @param array{from?:string, to?:string, date?:string, subject?:string, message_id?:string, html?:?string} $o
     */
    public static function raw(array $o = []): string
    {
        $o += [
            'from' => '"Datové schránky" <notifikace@datovka.gov.cz>', 'to' => 'owner@example.cz',
            'date' => 'Mon, 09 Nov 2026 09:30:00 +0100', 'subject' => 'Nová datová zpráva pro: Jana Testovací - podnikající fyzická osoba',
            'message_id' => '<' . bin2hex(random_bytes(6)) . '@example.invalid>', 'html' => null,
        ];
        $html = $o['html'] ?? self::html();
        $qp = str_replace("\n", "\r\n", str_replace("\r\n", "\n", quoted_printable_encode($html)));
        $outer = '----=_Part_sig_1';
        $inner = '----=_Part_mix_2';
        return "Return-Path: <notifikace@datovka.gov.cz>\r\n"
            . "Received: from mail.example.invalid (mail.example.invalid [192.0.2.25])\r\n\tby mx.example.invalid with ESMTP id XYZ\r\n"
            . "Date: {$o['date']}\r\n"
            . "From: {$o['from']}\r\n"
            . "To: {$o['to']}\r\n"
            . 'Subject: ' . self::encodeSubject($o['subject']) . "\r\n"
            . "Message-ID: {$o['message_id']}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/signed; protocol=\"application/pkcs7-signature\"; micalg=sha-256;\r\n\tboundary=\"{$outer}\"\r\n"
            . "\r\n"
            . "This is a multi-part message in MIME format.\r\n"
            . "--{$outer}\r\n"
            . "Content-Type: multipart/mixed; boundary=\"{$inner}\"\r\n"
            . "\r\n"
            . "--{$inner}\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n"
            . "\r\n"
            . $qp . "\r\n"
            . "--{$inner}--\r\n"
            . "\r\n"
            . "--{$outer}\r\n"
            . "Content-Type: application/pkcs7-signature; name=\"smime.p7s\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"smime.p7s\"\r\n"
            . "\r\n"
            . chunk_split(base64_encode('synthetic-signature-bytes'), 64, "\r\n")
            . "--{$outer}--\r\n";
    }

    /**
     * Signed the way the real notifier signs: rsa-sha256, simple/simple, h=Date:From:To:Subject.
     *
     * @param array{private:\OpenSSLAsymmetricKey,txt:string} $key
     */
    public static function signed(string $raw, array $key, string $domain = 'datovka.gov.cz', string $selector = '20230317'): string
    {
        return DkimTestSigner::sign($raw, [
            'key' => $key['private'], 'domain' => $domain, 'selector' => $selector,
            'canon' => 'simple/simple', 'headers' => ['date', 'from', 'to', 'subject'],
        ]);
    }

    /** @return array{0:string, 1:string} the header block (with its blank line) and the body, as an IMAP client hands them out */
    public static function split(string $raw): array
    {
        $cut = (int) strpos($raw, "\r\n\r\n") + 4;
        return [substr($raw, 0, $cut), substr($raw, $cut)];
    }

    /** The message as the mailbox client builds it from a raw message (Webklex gives no body for the S/MIME structure). */
    public static function message(string $raw, int $uid = 1): BankEmailNoticeMessage
    {
        [$header, $body] = self::split($raw);
        $field = static function (string $name) use ($header): string {
            return preg_match('/^' . $name . ':[ \t]*(.*(?:\r\n[ \t]+.*)*)/mi', $header, $m) === 1 ? trim((string) preg_replace('/\r\n[ \t]+/', ' ', $m[1])) : '';
        };
        return new BankEmailNoticeMessage(
            uid: $uid,
            messageId: $field('Message-ID') ?: null,
            date: $field('Date') !== '' ? new \DateTimeImmutable($field('Date')) : null,
            sender: MimeHeaderDecoder::decode($field('From')),
            subject: MimeHeaderDecoder::decode($field('Subject')),
            text: '',
            raw: $body,
            rawHeader: $header,
        );
    }

    /** RFC 2047 "Q" encoding, as the real notifier writes it. */
    private static function encodeSubject(string $subject): string
    {
        $encoded = '';
        foreach (str_split($subject) as $byte) {
            $encoded .= ctype_alnum($byte) ? $byte : ($byte === ' ' ? '_' : sprintf('=%02X', ord($byte)));
        }
        return '=?UTF-8?Q?' . $encoded . '?=';
    }
}
