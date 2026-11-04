<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support\Dkim;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimResult;
use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;

/**
 * A deliberately independent DKIM signer for tests (own canonicalization written from RFC 6376,
 * nothing shared with the production verifier) so that a bug in the verifier cannot cancel out.
 */
final class DkimTestSigner
{
    /**
     * @return array{private:\OpenSSLAsymmetricKey,txt:string}
     */
    public static function rsaKey(int $bits = 2048): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new failed: ' . (string) openssl_error_string());
        }
        return ['private' => $key, 'txt' => 'v=DKIM1; k=rsa; p=' . self::publicKeyB64($key)];
    }

    /**
     * Base64 of the DER SubjectPublicKeyInfo of an RSA key (what goes into `p=`).
     */
    public static function publicKeyB64(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        return (string) preg_replace('/-----[A-Z ]+-----|\s+/', '', (string) $details['key']);
    }

    /**
     * A bank notice shaped like the real Air Bank one (Seznam trace headers, 8bit UTF-8 body), unsigned.
     */
    public static function sampleMessage(
        string $from = 'Air Bank<info@airbank.cz>',
        ?string $body = null,
        string $messageId = '<abc123@airbank.cz>',
    ): string {
        $body ??= "Dobrý den,\r\n\r\nzůstatek na účtu 1000000005/3030 se zvýšil o částku 1,00 CZK.   \r\nČástka: 1,00 CZK\r\n";
        return "Return-Path: <info@airbank.cz>\r\n"
            . "Received: from mail.airbank.cz (mail.airbank.cz [192.0.2.10])\r\n\tby smtpd-profi-1 (szn-email-smtpd/2.0.93) with ESMTP id ABC\r\n"
            . "Date: Wed, 04 Nov 2026 10:00:00 +0100\r\n"
            . "From: {$from}\r\n"
            . "To: owner@example.cz\r\n"
            . "Subject: =?UTF-8?B?WnbDvcWhZW7DrSB6xa9zdGF0a3U=?=\r\n"
            . "Message-ID: {$messageId}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . $body;
    }

    /**
     * Runs the verifier on a complete message by cutting it at the first blank line the way an IMAP
     * server hands out RFC822.HEADER / RFC822.TEXT (line endings are left exactly as they are).
     */
    public static function verify(DkimVerifier $verifier, string $message, ?string $expectedFromDomain = null): DkimResult
    {
        if (preg_match('/\r?\n\r?\n/', $message, $m, PREG_OFFSET_CAPTURE) === 1) {
            $cut = $m[0][1] + strlen($m[0][0]);
            return $verifier->verify(substr($message, 0, $cut), substr($message, $cut), $expectedFromDomain);
        }
        return $verifier->verify($message, '', $expectedFromDomain);
    }

    /**
     * @return array{private:string,txt:string} private = libsodium secret key (64 bytes)
     */
    public static function ed25519Key(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'private' => sodium_crypto_sign_secretkey($pair),
            'txt' => 'v=DKIM1; k=ed25519; p=' . base64_encode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    /**
     * Returns the message with a DKIM-Signature header prepended.
     *
     * @param string $message Raw message, CRLF line endings.
     * @param array{
     *     key:\OpenSSLAsymmetricKey|string, domain?:string, selector?:string, alg?:string,
     *     canon?:string, headers?:list<string>, length?:?int, timestamp?:?int, expires?:?int,
     *     tags?:array<string,string>, fold?:bool
     * } $options `tags` overrides/adds raw tag values (null-like removal: pass '' to drop a tag).
     */
    public static function sign(string $message, array $options): string
    {
        $domain = $options['domain'] ?? 'airbank.cz';
        $selector = $options['selector'] ?? 'ab';
        $alg = $options['alg'] ?? 'rsa-sha256';
        $canon = $options['canon'] ?? 'relaxed/relaxed';
        [$hc, $bc] = array_pad(explode('/', $canon), 2, 'simple');
        $signedNames = $options['headers'] ?? ['from', 'to', 'subject', 'date', 'message-id'];

        [$rawHeaders, $body] = self::split($message);
        $fields = self::fields($rawHeaders);

        $canonBody = self::canonBody($body, $bc);
        if (isset($options['length'])) {
            $canonBody = substr($canonBody, 0, $options['length']);
        }
        $bh = base64_encode(hash($alg === 'rsa-sha1' ? 'sha1' : 'sha256', $canonBody, true));

        $tags = [
            'v' => '1',
            'a' => $alg,
            'c' => $canon,
            'd' => $domain,
            's' => $selector,
            'h' => implode(':', $signedNames),
            'bh' => $bh,
        ];
        if (isset($options['length'])) {
            $tags['l'] = (string) $options['length'];
        }
        if (isset($options['timestamp'])) {
            $tags['t'] = (string) $options['timestamp'];
        }
        if (isset($options['expires'])) {
            $tags['x'] = (string) $options['expires'];
        }
        foreach ($options['tags'] ?? [] as $name => $value) {
            if ($value === '') {
                unset($tags[$name]);
            } else {
                $tags[$name] = $value;
            }
        }

        $fold = !empty($options['fold']);
        $build = static function (string $b) use ($tags, $fold): string {
            $parts = [];
            foreach ($tags as $name => $value) {
                $parts[] = $name . '=' . $value;
            }
            if ($fold) {
                // Fold between tags and inside the long b= value, as real signers do.
                return 'DKIM-Signature: ' . implode(";\r\n\t", $parts) . ";\r\n\tb=" . rtrim(chunk_split($b, 64, "\r\n\t"), "\r\n\t");
            }
            return 'DKIM-Signature: ' . implode('; ', $parts) . '; b=' . $b;
        };

        // Header data to sign: listed headers picked bottom-up, then the signature header with empty b=.
        $pool = $fields;
        $data = '';
        foreach ($signedNames as $name) {
            for ($i = count($pool) - 1; $i >= 0; $i--) {
                if (strtolower($pool[$i]['name']) === strtolower($name)) {
                    $data .= self::canonHeader($pool[$i]['raw'], $hc) . "\r\n";
                    array_splice($pool, $i, 1);
                    break;
                }
            }
        }
        $data .= self::canonHeader($build(''), $hc);

        $signature = self::rawSign($data, $options['key'], $alg);
        $header = $build(base64_encode($signature));

        return $header . "\r\n" . $message;
    }

    public static function rawSign(string $data, \OpenSSLAsymmetricKey|string $key, string $alg): string
    {
        if ($alg === 'ed25519-sha256') {
            return sodium_crypto_sign_detached(hash('sha256', $data, true), (string) $key);
        }
        $signature = '';
        $algo = $alg === 'rsa-sha1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;
        if (!openssl_sign($data, $signature, $key, $algo)) {
            throw new \RuntimeException('openssl_sign failed');
        }
        return $signature;
    }

    /**
     * @return array{0:string,1:string} header block (no trailing blank line), body
     */
    public static function split(string $message): array
    {
        $pos = strpos($message, "\r\n\r\n");
        if ($pos === false) {
            throw new \InvalidArgumentException('test message needs a blank line');
        }
        return [substr($message, 0, $pos), substr($message, $pos + 4)];
    }

    /**
     * @return list<array{name:string,raw:string}>
     */
    public static function fields(string $headerBlock): array
    {
        $out = [];
        foreach (explode("\r\n", $headerBlock) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                $out[count($out) - 1]['raw'] .= "\r\n" . $line;
                continue;
            }
            $out[] = ['name' => substr($line, 0, (int) strpos($line, ':')), 'raw' => $line];
        }
        return $out;
    }

    public static function canonHeader(string $raw, string $mode): string
    {
        if ($mode === 'simple') {
            return $raw;
        }
        [$name, $value] = explode(':', $raw, 2);
        $value = preg_replace('/\r\n(?=[ \t])/', '', $value);
        $value = preg_replace('/[ \t]+/', ' ', $value);
        return strtolower(trim($name)) . ':' . trim($value, ' ');
    }

    public static function canonBody(string $body, string $mode): string
    {
        if ($mode === 'relaxed') {
            $lines = explode("\r\n", $body);
            $lines = array_map(static fn (string $l): string => preg_replace('/[ \t]+/', ' ', rtrim($l, " \t")), $lines);
            $body = implode("\r\n", $lines);
            $body = preg_replace('/(\r\n)+$/', '', $body);
            return $body === '' ? '' : $body . "\r\n";
        }
        $body = preg_replace('/(\r\n)*$/', '', $body);
        return $body . "\r\n";
    }
}
