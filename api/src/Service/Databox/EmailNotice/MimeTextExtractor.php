<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\EmailNotice;

use MyInvoice\Service\Bank\EmailNotice\EmailCharsetNormalizer;

/**
 * Pulls the readable text parts out of a raw RFC 5322 message by walking its MIME tree.
 *
 * Needed because the notification e-mails of the data box system are `multipart/signed` (S/MIME) around a
 * `multipart/mixed` with a lone quoted-printable `text/html` part: IMAP libraries report no body for that
 * structure, so the application reads the undecoded message itself. Hostile input is bounded (size, depth, number of
 * parts) and never throws.
 */
final class MimeTextExtractor
{
    private const MAX_BYTES = 2_097_152;
    private const MAX_DEPTH = 8;
    private const MAX_PARTS = 64;

    /** @var array{html:?string, text:?string} */
    private array $found = ['html' => null, 'text' => null];
    private int $parts = 0;

    private function __construct() {}

    /**
     * @param string $rawHeader header block of the message exactly as received (carries the top-level Content-Type)
     * @param string $rawBody undecoded body exactly as received
     * @return array{html:?string, text:?string} the first text/html and the first text/plain part, decoded to UTF-8
     */
    public static function extract(string $rawHeader, string $rawBody): array
    {
        $self = new self();
        if (strlen($rawBody) <= self::MAX_BYTES) {
            try {
                $self->walk($rawHeader, $rawBody, 0);
            } catch (\Throwable) {
                // a malformed message simply yields what was found so far
            }
        }
        return $self->found;
    }

    private function walk(string $headerBlock, string $body, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || ++$this->parts > self::MAX_PARTS) {
            return;
        }
        $headers = self::headers($headerBlock);
        [$type, $params] = self::structured($headers['content-type'] ?? 'text/plain');

        if (str_starts_with($type, 'multipart/')) {
            $boundary = $params['boundary'] ?? '';
            if ($boundary === '') {
                return;
            }
            foreach (self::splitMultipart($body, $boundary) as $part) {
                [$partHeader, $partBody] = self::splitEntity($part);
                $this->walk($partHeader, $partBody, $depth + 1);
            }
            return;
        }

        $slot = match ($type) {
            'text/html' => 'html',
            'text/plain' => 'text',
            default => null,
        };
        if ($slot === null || $this->found[$slot] !== null) {
            return;
        }
        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        $decoded = match ($encoding) {
            'quoted-printable' => quoted_printable_decode($body),
            'base64' => (string) base64_decode($body),
            default => $body,
        };
        if (trim($decoded) !== '') {
            $this->found[$slot] = self::toUtf8($decoded, $params['charset'] ?? 'utf-8');
        }
    }

    /**
     * Header fields by lower-case name, folded values unfolded; when a name repeats, the first one wins.
     *
     * @return array<string,string>
     */
    private static function headers(string $block): array
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $block) ?? $block;
        $out = [];
        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $out[$name] ??= trim(substr($line, $colon + 1));
        }
        return $out;
    }

    /** @return array{0:string, 1:array<string,string>} lower-case media type and its parameters */
    private static function structured(string $value): array
    {
        $type = strtolower(trim(explode(';', $value, 2)[0]));
        $params = [];
        if (preg_match_all('/;\s*([A-Za-z0-9_.-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]*))/', $value, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $p) {
                $params[strtolower($p[1])] ??= ($p[2] ?? '') !== '' ? stripcslashes($p[2]) : ($p[3] ?? '');
            }
        }
        return [$type === '' ? 'text/plain' : $type, $params];
    }

    /** @return array{0:string, 1:string} header block and body of one entity */
    private static function splitEntity(string $entity): array
    {
        if (str_starts_with($entity, "\n")) {
            return ['', substr($entity, 1)]; // no header fields at all
        }
        $cut = strpos($entity, "\n\n");
        return $cut === false ? [$entity, ''] : [substr($entity, 0, $cut), substr($entity, $cut + 2)];
    }

    /**
     * The body parts between the boundary delimiter lines (LF-normalised). Preamble and epilogue are dropped.
     *
     * @return list<string>
     */
    private static function splitMultipart(string $body, string $boundary): array
    {
        $open = '--' . $boundary;
        $close = $open . '--';
        $parts = [];
        $current = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $body)) as $line) {
            $marker = rtrim($line, " \t");
            if ($marker === $close) {
                break;
            }
            if ($marker === $open) {
                if ($current !== null) {
                    $parts[] = implode("\n", $current);
                }
                $current = [];
                continue;
            }
            if ($current !== null) {
                $current[] = $line;
            }
        }
        if ($current !== null) {
            $parts[] = implode("\n", $current);
        }
        return $parts;
    }

    private static function toUtf8(string $text, string $charset): string
    {
        $charset = strtolower(trim($charset));
        if ($charset !== '' && !in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted)) {
                return $converted;
            }
        }
        return EmailCharsetNormalizer::toUtf8($text);
    }
}
