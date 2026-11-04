<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

/**
 * A raw RFC 5322 message split into its header fields and (undecoded) body.
 */
final class DkimMessage
{
    /** Bank notices are tiny; anything larger is refused rather than hashed. */
    public const MAX_HEADER_BYTES = 262_144;
    public const MAX_BODY_BYTES = 2_097_152;

    /**
     * @param list<array{name:string,raw:string}> $headers In message order (top first). `name` is
     *        lower-cased, `raw` is the field exactly as received including folding CRLFs.
     */
    private function __construct(public readonly array $headers, public readonly string $body) {}

    /**
     * @param string $rawHeader The header block as received; the blank separator line may or may not be included.
     * @param string $rawBody The body as received, undecoded.
     * @throws DkimException when header block or body exceed the size caps.
     */
    public static function fromParts(string $rawHeader, string $rawBody): self
    {
        // Size caps first, before any copy is made of the (possibly hostile) input.
        if (strlen($rawHeader) > self::MAX_HEADER_BYTES) {
            throw new DkimException('dkim_header_too_large');
        }
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            throw new DkimException('dkim_message_too_large');
        }

        $headerBlock = rtrim(self::withCrlf($rawHeader), "\r\n");
        $headers = [];
        foreach (explode("\r\n", $headerBlock) as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === ' ' || $line[0] === "\t") {
                $last = array_key_last($headers);
                if ($last !== null) {
                    $headers[$last]['raw'] .= "\r\n" . $line;
                }
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                continue;
            }
            $name = substr($line, 0, $colon);
            if (preg_match('/^[\x21-\x39\x3B-\x7E]+[ \t]*$/', $name) !== 1) {
                continue;
            }
            $headers[] = ['name' => strtolower(rtrim($name, " \t")), 'raw' => $line];
        }

        return new self($headers, self::withCrlf($rawBody));
    }

    /**
     * Messages travel as CRLF, but tolerate LF-only copies (mbox, local storage). A lone CR is
     * not a line break and is left alone; anything that was altered fails the body hash anyway.
     * The common case (already CRLF) costs one scan and no copy.
     */
    private static function withCrlf(string $text): string
    {
        return preg_match('/(?<!\r)\n/', $text) === 1 ? (string) preg_replace('/(?<!\r)\n/', "\r\n", $text) : $text;
    }

    /**
     * Indices (message order) of the header fields with the given lower-case name.
     *
     * @return list<int>
     */
    public function indicesNamed(string $lowerName): array
    {
        $out = [];
        foreach ($this->headers as $i => $header) {
            if ($header['name'] === $lowerName) {
                $out[] = $i;
            }
        }
        return $out;
    }

    public function headerValue(int $index): string
    {
        $raw = $this->headers[$index]['raw'];
        return substr($raw, (int) strpos($raw, ':') + 1);
    }

    /**
     * Domain of the single mailbox in the single From header (lower-case, no trailing dot).
     *
     * Several From headers or several mailboxes are refused: DKIM `h=from` only covers the
     * bottom-most From, so a second one added on top could show a different sender.
     */
    public function fromDomain(): string
    {
        $indices = $this->indicesNamed('from');
        if ($indices === []) {
            throw new DkimException('dkim_from_missing');
        }
        if (count($indices) > 1) {
            throw new DkimException('dkim_from_ambiguous');
        }
        return self::domainOfMailbox($this->headerValue($indices[0]));
    }

    private static function domainOfMailbox(string $value): string
    {
        $value = str_replace("\r\n", '', $value);

        // Strip comments and quoted strings (a display name may contain anything, e.g. an
        // address of another domain), keeping only the structural characters.
        $clean = '';
        $quoted = false;
        $depth = 0;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if ($quoted) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '"') {
                    $quoted = false;
                }
                continue;
            }
            if ($depth > 0) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')') {
                    $depth--;
                }
                continue;
            }
            if ($c === '"') {
                $quoted = true;
                continue;
            }
            if ($c === '(') {
                $depth++;
                continue;
            }
            $clean .= $c;
        }
        if ($quoted || $depth > 0) {
            throw new DkimException('dkim_from_invalid');
        }

        // Top-level comma (several mailboxes) or colon / semicolon (group syntax, obsolete routes).
        if (strpbrk($clean, ',:;') !== false) {
            throw new DkimException('dkim_from_ambiguous');
        }

        $open = substr_count($clean, '<');
        $close = substr_count($clean, '>');
        if ($open > 1 || $close > 1 || $open !== $close) {
            throw new DkimException('dkim_from_ambiguous');
        }
        if ($open === 1) {
            $start = (int) strpos($clean, '<');
            $end = (int) strpos($clean, '>');
            if ($end < $start) {
                throw new DkimException('dkim_from_invalid');
            }
            $addr = substr($clean, $start + 1, $end - $start - 1);
        } else {
            $addr = $clean;
        }

        $addr = (string) preg_replace('/\s+/', '', $addr);
        $at = strrpos($addr, '@');
        if ($at === false || $at === 0) {
            throw new DkimException('dkim_from_invalid');
        }
        $domain = strtolower(rtrim(substr($addr, $at + 1), '.'));
        if (!DomainAlignment::isValidName($domain)) {
            throw new DkimException('dkim_from_invalid');
        }
        return $domain;
    }
}
