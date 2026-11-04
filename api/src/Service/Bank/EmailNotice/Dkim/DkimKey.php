<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice\Dkim;

/**
 * A DKIM public key record (the TXT record at `<selector>._domainkey.<domain>`, RFC 6376 section 3.6.1).
 */
final class DkimKey
{
    private const MIN_RSA_BITS = 1024;
    private const MAX_RSA_BITS = 8192;
    private const ED25519_SPKI_PREFIX = '302a300506032b6570032100';

    /**
     * @param string $keyBytes Decoded `p=`; empty for a revoked key.
     */
    private function __construct(
        public readonly string $type,
        public readonly string $keyBytes,
        public readonly bool $testing,
    ) {}

    public function isRevoked(): bool
    {
        return $this->keyBytes === '';
    }

    /**
     * Parse one TXT record. Returns null when the record is not a usable DKIM key record for
     * e-mail signed with SHA-256 (not a key at all, wrong version, restricted to other services
     * or hash algorithms, unknown key type, undecodable `p=`).
     */
    public static function parse(string $txt): ?self
    {
        $tags = [];
        foreach (explode(';', $txt) as $segment) {
            if (trim($segment) === '') {
                continue;
            }
            $pair = explode('=', $segment, 2);
            if (count($pair) !== 2) {
                return null;
            }
            $name = trim($pair[0]);
            if ($name === '' || isset($tags[$name])) {
                return null;
            }
            $tags[$name] = trim($pair[1]);
        }
        // `p` is mandatory; its absence means this TXT record is something else entirely.
        if (!isset($tags['p'])) {
            return null;
        }
        if (isset($tags['v']) && $tags['v'] !== 'DKIM1') {
            return null;
        }
        $type = strtolower($tags['k'] ?? 'rsa');
        if ($type !== 'rsa' && $type !== 'ed25519') {
            return null;
        }
        if (isset($tags['h']) && !self::listContains($tags['h'], ['sha256', '*'])) {
            return null;
        }
        if (isset($tags['s']) && !self::listContains($tags['s'], ['email', '*'])) {
            return null;
        }

        $keyBytes = '';
        $b64 = (string) preg_replace('/\s+/', '', $tags['p']);
        if ($b64 !== '') {
            $decoded = base64_decode($b64, true);
            if ($decoded === false || $decoded === '') {
                return null;
            }
            $keyBytes = $decoded;
        }

        $testing = isset($tags['t']) && in_array('y', array_map('trim', explode(':', strtolower($tags['t']))), true);
        return new self($type, $keyBytes, $testing);
    }

    /**
     * Verify a signature over the canonicalized header data.
     *
     * @throws DkimException when the key itself is unusable (`dkim_key_invalid`, `dkim_key_too_weak`).
     * @return bool true only for a valid signature
     */
    public function verify(string $signedData, string $signature, string $algorithm): bool
    {
        if ($algorithm === DkimSignature::RSA_SHA256 && $this->type === 'rsa') {
            return $this->verifyRsa($signedData, $signature);
        }
        if ($algorithm === DkimSignature::ED25519_SHA256 && $this->type === 'ed25519') {
            return $this->verifyEd25519($signedData, $signature);
        }
        // Signature algorithm and published key type disagree.
        throw new DkimException('dkim_key_invalid');
    }

    private function verifyRsa(string $signedData, string $signature): bool
    {
        $body = chunk_split(base64_encode($this->keyBytes), 64, "\n");
        $key = self::quietly(static function () use ($body) {
            // Keys are normally SubjectPublicKeyInfo; some old signers publish a bare PKCS#1 RSAPublicKey.
            $key = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n{$body}-----END PUBLIC KEY-----\n");
            return $key !== false
                ? $key
                : openssl_pkey_get_public("-----BEGIN RSA PUBLIC KEY-----\n{$body}-----END RSA PUBLIC KEY-----\n");
        });
        if ($key === false || $key === null) {
            throw new DkimException('dkim_key_invalid');
        }
        $details = self::quietly(static fn () => openssl_pkey_get_details($key));
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new DkimException('dkim_key_invalid');
        }
        $bits = (int) ($details['bits'] ?? 0);
        if ($bits < self::MIN_RSA_BITS) {
            throw new DkimException('dkim_key_too_weak');
        }
        if ($bits > self::MAX_RSA_BITS) {
            throw new DkimException('dkim_key_invalid');
        }
        return self::quietly(static fn () => openssl_verify($signedData, $signature, $key, OPENSSL_ALGO_SHA256)) === 1;
    }

    private function verifyEd25519(string $signedData, string $signature): bool
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new DkimException('dkim_algorithm_unsupported');
        }
        $publicKey = $this->keyBytes;
        // RFC 8463 publishes the raw 32-byte key; tolerate the SubjectPublicKeyInfo wrapper some signers publish.
        if (strlen($publicKey) === 44 && str_starts_with(bin2hex($publicKey), self::ED25519_SPKI_PREFIX)) {
            $publicKey = substr($publicKey, 12);
        }
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new DkimException('dkim_key_invalid');
        }
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            // RFC 8463: the Ed25519 signature is over the SHA-256 digest of the canonicalized data.
            return sodium_crypto_sign_verify_detached($signature, hash('sha256', $signedData, true), $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * @param list<string> $accepted
     */
    private static function listContains(string $colonList, array $accepted): bool
    {
        foreach (explode(':', strtolower($colonList)) as $item) {
            if (in_array(trim($item), $accepted, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run an OpenSSL call without leaking warnings or leaving errors on the OpenSSL error queue.
     */
    private static function quietly(callable $fn): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $fn();
        } finally {
            restore_error_handler();
            while (openssl_error_string() !== false) {
                // drain
            }
        }
    }
}
