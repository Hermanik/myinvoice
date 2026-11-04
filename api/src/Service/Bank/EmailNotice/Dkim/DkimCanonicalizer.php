<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

/**
 * RFC 6376 section 3.4 canonicalization (`simple` and `relaxed`) of header fields and body.
 * Inputs are CRLF-delimited, see {@see DkimMessage::parse()}.
 */
final class DkimCanonicalizer
{
    public const SIMPLE = 'simple';
    public const RELAXED = 'relaxed';

    /**
     * Canonical form of a single header field WITHOUT the trailing CRLF.
     *
     * @param string $rawHeader The field exactly as received ("Name: value", folding CRLFs included).
     */
    public static function header(string $rawHeader, string $mode): string
    {
        if ($mode !== self::RELAXED) {
            return $rawHeader;
        }
        $colon = strpos($rawHeader, ':');
        if ($colon === false) {
            return $rawHeader;
        }
        $name = strtolower(rtrim(substr($rawHeader, 0, $colon), " \t"));
        $value = substr($rawHeader, $colon + 1);
        // Unfold, collapse every WSP run to one SP, drop WSP around the colon and at the end.
        $value = str_replace("\r\n", '', $value);
        $value = (string) preg_replace('/[ \t]+/', ' ', $value);
        return $name . ':' . trim($value, ' ');
    }

    /**
     * Canonical body, ready for hashing.
     */
    public static function body(string $body, string $mode): string
    {
        if ($mode === self::RELAXED) {
            // Drop WSP at the end of every line, then collapse the remaining WSP runs. The
            // lookbehind makes a match start only at the beginning of a run (linear time even
            // for a body that is one huge run of blanks).
            $body = (string) preg_replace('/(?<![ \t])[ \t]++(?=\r\n|\z)/', '', $body);
            $body = (string) preg_replace('/[ \t]+/', ' ', $body);
        }

        // Drop trailing empty lines (linear scan; a regex over a huge run of CRLFs is not).
        $end = strlen($body);
        while ($end >= 2 && $body[$end - 2] === "\r" && $body[$end - 1] === "\n") {
            $end -= 2;
        }
        if ($end === 0) {
            // simple: an empty body is a single CRLF; relaxed: it stays empty.
            return $mode === self::RELAXED ? '' : "\r\n";
        }
        return substr($body, 0, $end) . "\r\n";
    }
}
