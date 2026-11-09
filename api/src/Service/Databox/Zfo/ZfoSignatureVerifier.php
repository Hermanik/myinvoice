<?php

declare(strict_types=1);

namespace MyInvoice\Service\Databox\Zfo;

use MyInvoice\Service\Databox\DataboxException;

/**
 * Checks the integrity of a signed ZFO (DER CMS SignedData) with OpenSSL: the signature must verify against the content that is
 * embedded in the file, and that verified content is what the caller gets back - never a second reading of the same bytes.
 *
 * Only the integrity of the file is proven (nothing was changed after the data box system sealed it). The certificate chain
 * of the signer is deliberately NOT validated: that needs the CA certificates of the operator, and the file comes from the
 * owner himself, who downloaded it from his own box. A file signed by anybody's key would pass, which is why the signer is
 * reported to the user.
 */
final class ZfoSignatureVerifier
{
    /**
     * @return array{content:string, signer:?string} the verified content (the ISDS XML) and the subject of the signing certificate
     * @throws DataboxException
     */
    public function verify(string $der): array
    {
        $files = [];
        try {
            foreach (['in', 'content', 'signers'] as $name) {
                $path = tempnam(sys_get_temp_dir(), 'zfo');
                if ($path === false) {
                    throw new DataboxException('zfo_verification_unavailable', 'Soubor se nepodařilo ověřit (chybí dočasné úložiště).', 500);
                }
                $files[$name] = $path;
            }
            file_put_contents($files['in'], $der);
            $this->drainErrors();
            // NOVERIFY: the signer certificate is not chain-checked (see the class comment); the signature and the content digest are.
            $ok = @openssl_cms_verify(
                $files['in'],
                OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY,
                $files['signers'],
                [],
                null,
                $files['content'],
                null,
                null,
                OPENSSL_ENCODING_DER,
            );
            $this->drainErrors();
            $content = (string) file_get_contents($files['content']);
            if ($ok !== true || $content === '') {
                throw new DataboxException('zfo_signature_invalid', 'Podpis souboru ZFO se nepodařilo ověřit - soubor je poškozený nebo byl po stažení upraven.', 422);
            }
            return ['content' => $content, 'signer' => $this->signerName((string) file_get_contents($files['signers']))];
        } finally {
            $this->cleanup($files);
        }
    }

    /** "Common name (organisation)" of the first signing certificate, or null when it cannot be read. */
    private function signerName(string $pem): ?string
    {
        $parsed = $pem !== '' ? @openssl_x509_parse($pem) : false;
        $subject = is_array($parsed) ? ($parsed['subject'] ?? []) : [];
        $cn = self::first($subject['CN'] ?? null);
        $org = self::first($subject['O'] ?? null);
        $name = $cn !== null && $org !== null && $cn !== $org ? "{$cn} ({$org})" : ($cn ?? $org);
        return $name === null ? null : mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name), 0, 190);
    }

    private static function first(mixed $value): ?string
    {
        $value = is_array($value) ? ($value[0] ?? null) : $value;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** OpenSSL keeps its errors in a queue; a stale one must not be mistaken for a later failure. */
    private function drainErrors(): void
    {
        while (openssl_error_string() !== false) {
            // discard
        }
    }

    /** @param array<string,string> $files */
    private function cleanup(array $files): void
    {
        foreach ($files as $path) {
            @unlink($path);
        }
    }
}
