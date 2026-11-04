<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

/**
 * A parsed `DKIM-Signature` header field (RFC 6376 section 3.5).
 */
final class DkimSignature
{
    public const RSA_SHA256 = 'rsa-sha256';
    public const ED25519_SHA256 = 'ed25519-sha256';

    private const SELECTOR_PATTERN = '/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)*$/';

    /**
     * @param list<string> $signedHeaders Lower-case `h=` names in order, repeats allowed (over-signing).
     * @param string $signature Binary `b=`.
     * @param string $bodyHash Binary `bh=` (SHA-256).
     */
    private function __construct(
        public readonly string $algorithm,
        public readonly string $headerCanon,
        public readonly string $bodyCanon,
        public readonly string $domain,
        public readonly string $selector,
        public readonly array $signedHeaders,
        public readonly string $signature,
        public readonly string $bodyHash,
        public readonly ?int $bodyLength,
        public readonly ?int $timestamp,
        public readonly ?int $expiration,
    ) {}

    /**
     * @param string $headerValue The field value after the colon (folding CRLFs allowed).
     * @throws DkimException on a malformed or unsupported signature.
     */
    public static function parse(string $headerValue): self
    {
        $tags = [];
        foreach (explode(';', $headerValue) as $segment) {
            if (trim($segment, " \t\r\n") === '') {
                continue;
            }
            if (preg_match('/^[ \t\r\n]*([A-Za-z][A-Za-z0-9_]*)[ \t\r\n]*=(.*)$/s', $segment, $m) !== 1) {
                throw new DkimException('dkim_malformed_signature');
            }
            if (isset($tags[$m[1]])) {
                // RFC 6376 3.2: a tag must not occur more than once.
                throw new DkimException('dkim_malformed_signature');
            }
            $tags[$m[1]] = $m[2];
        }

        foreach (['v', 'a', 'b', 'bh', 'd', 'h', 's'] as $required) {
            if (!isset($tags[$required])) {
                throw new DkimException('dkim_malformed_signature');
            }
        }
        $plain = static fn (string $tag): string => trim($tags[$tag], " \t\r\n");
        $noWsp = static fn (string $tag): string => (string) preg_replace('/[ \t\r\n]+/', '', $tags[$tag]);

        if ($plain('v') !== '1') {
            throw new DkimException('dkim_malformed_signature');
        }

        $algorithm = match (strtolower($plain('a'))) {
            self::RSA_SHA256 => self::RSA_SHA256,
            self::ED25519_SHA256 => self::ED25519_SHA256,
            // SHA-1 signatures are forgeable in practice (RFC 8301 forbids them): never trust.
            'rsa-sha1' => throw new DkimException('dkim_algorithm_insecure'),
            default => throw new DkimException('dkim_algorithm_unsupported'),
        };

        [$headerCanon, $bodyCanon] = self::canonicalization(isset($tags['c']) ? $plain('c') : '');

        $domain = strtolower($plain('d'));
        $selector = strtolower($plain('s'));
        if (!DomainAlignment::isValidName($domain)
            || strlen($selector) > 253 || preg_match(self::SELECTOR_PATTERN, $selector) !== 1) {
            throw new DkimException('dkim_malformed_signature');
        }

        if (isset($tags['q']) && !in_array('dns/txt', array_map('strtolower', explode(':', $noWsp('q'))), true)) {
            throw new DkimException('dkim_malformed_signature');
        }

        // The agent/user identifier, when present, must belong to the signing domain.
        if (isset($tags['i'])) {
            $identity = strtolower($plain('i'));
            $at = strrpos($identity, '@');
            $identityDomain = $at === false ? $identity : substr($identity, $at + 1);
            if ($identityDomain !== $domain && !str_ends_with($identityDomain, '.' . $domain)) {
                throw new DkimException('dkim_malformed_signature');
            }
        }

        $signedHeaders = [];
        foreach (explode(':', strtolower($noWsp('h'))) as $name) {
            if (preg_match('/^[\x21-\x39\x3B-\x7E]+$/', $name) !== 1) {
                throw new DkimException('dkim_malformed_signature');
            }
            $signedHeaders[] = $name;
        }
        if (!in_array('from', $signedHeaders, true)) {
            throw new DkimException('dkim_from_not_signed');
        }

        $signature = base64_decode($noWsp('b'), true);
        $bodyHash = base64_decode($noWsp('bh'), true);
        if ($signature === false || $signature === '' || $bodyHash === false || strlen($bodyHash) !== 32) {
            throw new DkimException('dkim_malformed_signature');
        }

        $number = static function (string $tag) use ($tags, $plain): ?int {
            if (!isset($tags[$tag])) {
                return null;
            }
            $value = $plain($tag);
            if (preg_match('/^[0-9]{1,15}$/', $value) !== 1) {
                throw new DkimException('dkim_malformed_signature');
            }
            return (int) $value;
        };
        $bodyLength = $number('l');
        $timestamp = $number('t');
        $expiration = $number('x');
        if ($expiration !== null && $timestamp !== null && $expiration < $timestamp) {
            throw new DkimException('dkim_malformed_signature');
        }

        return new self(
            $algorithm, $headerCanon, $bodyCanon, $domain, $selector, $signedHeaders,
            $signature, $bodyHash, $bodyLength, $timestamp, $expiration,
        );
    }

    /**
     * The raw header field with the VALUE of its `b=` tag removed (the tag name and "=" stay),
     * which is the form that gets hashed (RFC 6376 section 3.7). Only an exact `b` tag is
     * touched, never `bh`.
     */
    public static function withoutSignatureValue(string $rawHeader): string
    {
        $colon = (int) strpos($rawHeader, ':');
        $parts = explode(';', substr($rawHeader, $colon + 1));
        foreach ($parts as $i => $part) {
            if (preg_match('/^[ \t\r\n]*b[ \t\r\n]*=/', $part, $m) === 1) {
                $parts[$i] = $m[0];
            }
        }
        return substr($rawHeader, 0, $colon + 1) . implode(';', $parts);
    }

    /**
     * @return array{0:string,1:string} [header, body]; `c=relaxed` alone means relaxed/simple.
     */
    private static function canonicalization(string $value): array
    {
        if ($value === '') {
            return [DkimCanonicalizer::SIMPLE, DkimCanonicalizer::SIMPLE];
        }
        $parts = explode('/', strtolower($value));
        if (count($parts) > 2) {
            throw new DkimException('dkim_malformed_signature');
        }
        $header = $parts[0];
        $body = $parts[1] ?? DkimCanonicalizer::SIMPLE;
        foreach ([$header, $body] as $mode) {
            if ($mode !== DkimCanonicalizer::SIMPLE && $mode !== DkimCanonicalizer::RELAXED) {
                throw new DkimException('dkim_malformed_signature');
            }
        }
        return [$header, $body];
    }
}
