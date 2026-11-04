<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

use Psr\Clock\ClockInterface;

/**
 * Local DKIM verification (RFC 6376) of a raw RFC 5322 message, for mailboxes whose provider
 * adds no trustworthy `Authentication-Results` header (e.g. Seznam).
 *
 * A message passes when at least one DKIM signature is valid AND its `d=` domain aligns with the
 * domain of the (single) From address. Policy decisions, all fail-closed:
 *  - only `rsa-sha256` and `ed25519-sha256`; `rsa-sha1` is rejected as insecure (RFC 8301),
 *  - RSA keys below 1024 bits are rejected,
 *  - a signature with `l=` is refused unless it covers the WHOLE body: `l=` lets anybody append
 *    content below the signed part (RFC 6376 section 8.2),
 *  - a key record flagged `t=y` (testing) is never trusted, a key with an empty `p=` is revoked,
 *  - signatures whose `d=` does not align with From are not even looked up in DNS,
 *  - at most {@see $maxSignatures} aligned signatures are checked, header block and body are size-capped
 *    ({@see DkimMessage::MAX_HEADER_BYTES}, {@see DkimMessage::MAX_BODY_BYTES}),
 *  - no exception ever escapes: hostile input yields a failure detail.
 *
 * Signatures are only bound to the From header the verifier itself parses, so a display name that
 * mentions another domain (`"info@airbank.cz" <x@evil.example>`) cannot lend its domain to a message.
 */
final class DkimVerifier
{
    private const DEFAULT_MAX_SIGNATURES = 5;
    /** A signature time stamp this far ahead of our clock is treated as bogus. */
    private const MAX_FUTURE_SKEW_SECONDS = 86_400;
    /** Cap on DKIM-Signature fields examined at all (aligned or not). */
    private const MAX_SIGNATURE_FIELDS = 50;

    public function __construct(
        private readonly DkimKeyResolverInterface $resolver,
        private readonly ClockInterface $clock,
        private readonly int $maxSignatures = self::DEFAULT_MAX_SIGNATURES,
    ) {}

    /**
     * @param string $rawHeader Header block exactly as received (the blank separator line may be included).
     * @param string $rawBody Undecoded body exactly as received.
     * @param ?string $expectedFromDomain Domain of the sender as the rest of the app sees it; it
     *        must equal the domain of the signed From header (cross-check of two parsers).
     */
    public function verify(string $rawHeader, string $rawBody, ?string $expectedFromDomain = null): DkimResult
    {
        try {
            return $this->doVerify(DkimMessage::fromParts($rawHeader, $rawBody), $expectedFromDomain);
        } catch (DkimException $e) {
            return new DkimResult(false, $e->detail, $e->temporary);
        } catch (\Throwable) {
            return new DkimResult(false, 'dkim_internal_error');
        }
    }

    private function doVerify(DkimMessage $message, ?string $expectedFromDomain): DkimResult
    {
        $fromDomain = $message->fromDomain();

        $expected = $expectedFromDomain !== null ? strtolower(rtrim(trim($expectedFromDomain), '.')) : '';
        if ($expected !== '' && $expected !== $fromDomain) {
            throw new DkimException('dkim_from_domain_mismatch');
        }

        $fields = array_slice($message->indicesNamed('dkim-signature'), 0, self::MAX_SIGNATURE_FIELDS);
        if ($fields === []) {
            throw new DkimException('dkim_no_signature');
        }

        /** @var array<string,array{hash:string,length:int}> $bodyDigests per canonicalization mode */
        $bodyDigests = [];
        /** @var list<string> $failures */
        $failures = [];
        $retry = false;
        $checked = 0;

        foreach ($fields as $index) {
            try {
                $signature = DkimSignature::parse($message->headerValue($index));
                if (!DomainAlignment::aligns($signature->domain, $fromDomain)) {
                    // Never trust (or resolve keys for) a signature of an unrelated domain.
                    throw new DkimException('dkim_domain_not_aligned');
                }
                if ($checked >= $this->maxSignatures) {
                    throw new DkimException('dkim_too_many_signatures');
                }
                $checked++;
                $this->verifySignature($message, $index, $signature, $bodyDigests);
                return new DkimResult(true, 'dkim_local_pass:' . $signature->domain);
            } catch (DkimException $e) {
                $failures[] = $e->detail;
                $retry = $retry || $e->temporary;
            }
        }

        return new DkimResult(false, implode(',', array_values(array_unique($failures))), $retry);
    }

    /**
     * @param array<string,array{hash:string,length:int}> $bodyDigests
     * @throws DkimException when the signature does not verify
     */
    private function verifySignature(DkimMessage $message, int $fieldIndex, DkimSignature $signature, array &$bodyDigests): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($signature->expiration !== null && $signature->expiration < $now) {
            throw new DkimException('dkim_signature_expired');
        }
        if ($signature->timestamp !== null && $signature->timestamp > $now + self::MAX_FUTURE_SKEW_SECONDS) {
            throw new DkimException('dkim_timestamp_invalid');
        }

        // Body first: it is local and cheap, and a tampered body needs no DNS lookup. Only the
        // digest is kept per canonicalization mode, not the canonical body itself.
        $bodyDigests[$signature->bodyCanon] ??= self::digest(DkimCanonicalizer::body($message->body, $signature->bodyCanon));
        $digest = $bodyDigests[$signature->bodyCanon];
        if ($signature->bodyLength !== null && $signature->bodyLength !== $digest['length']) {
            // l= covers only part of the body (or more than there is): the unsigned remainder is attacker-controlled.
            throw new DkimException('dkim_body_length_limit');
        }
        if (!hash_equals($signature->bodyHash, $digest['hash'])) {
            throw new DkimException('dkim_body_hash_mismatch');
        }

        $records = $this->resolver->txtRecords($signature->selector, $signature->domain);
        if ($records === null) {
            throw new DkimException('dkim_dns_error', temporary: true);
        }
        $keys = [];
        foreach ($records as $record) {
            $key = DkimKey::parse($record);
            if ($key !== null) {
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            throw new DkimException('dkim_key_not_found');
        }

        $signedData = $this->signedData($message, $fieldIndex, $signature);

        $failure = 'dkim_key_not_found';
        foreach ($keys as $key) {
            if ($key->isRevoked()) {
                $failure = 'dkim_key_revoked';
                continue;
            }
            try {
                $valid = $key->verify($signedData, $signature->signature, $signature->algorithm);
            } catch (DkimException $e) {
                $failure = $e->detail;
                continue;
            }
            if (!$valid) {
                $failure = 'dkim_signature_invalid';
                continue;
            }
            if ($key->testing) {
                // t=y: the domain is only testing DKIM and asks verifiers not to treat failures
                // (and by extension passes) as meaningful.
                $failure = 'dkim_key_testing';
                continue;
            }
            return;
        }
        throw new DkimException($failure);
    }

    /**
     * @return array{hash:string,length:int} SHA-256 (raw) and byte length of a canonical body
     */
    private static function digest(string $canonicalBody): array
    {
        return ['hash' => hash('sha256', $canonicalBody, true), 'length' => strlen($canonicalBody)];
    }

    /**
     * The data covered by the signature: the canonicalized signed headers (RFC 6376 section 5.4,
     * picked bottom-up so an extra `h=` entry "over-signs" a header nobody may add later) followed
     * by the signature field itself with an empty `b=` and no trailing CRLF.
     */
    private function signedData(DkimMessage $message, int $fieldIndex, DkimSignature $signature): string
    {
        // Instances per header name, document order; a name listed again takes the next one up.
        $pools = [];
        foreach ($message->headers as $i => $header) {
            if ($i !== $fieldIndex) {
                $pools[$header['name']][] = $i;
            }
        }

        $data = '';
        foreach ($signature->signedHeaders as $name) {
            if (empty($pools[$name])) {
                continue; // listed but absent: contributes nothing, and keeps it absent for good
            }
            $i = array_pop($pools[$name]);
            $data .= DkimCanonicalizer::header($message->headers[$i]['raw'], $signature->headerCanon) . "\r\n";
        }

        return $data . DkimCanonicalizer::header(
            DkimSignature::withoutSignatureValue($message->headers[$fieldIndex]['raw']),
            $signature->headerCanon,
        );
    }
}
