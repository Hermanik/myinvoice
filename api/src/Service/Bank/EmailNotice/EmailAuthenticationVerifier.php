<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\EmailNotice;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Service\Bank\EmailNotice\Dkim\DomainAlignment;

/**
 * E-mail authenticity check. Who vouches for the sender:
 *
 *  1. A trusted receiving server, but ONLY when the account has a trusted authserv-id configured and
 *     one of the message's `Authentication-Results` headers carries exactly that id: we do not
 *     recompute anything, we take its verdict and require `dmarc=pass` or `dkim=pass` with a domain
 *     aligned to the sender.
 *  2. Otherwise (the default) our own DKIM verification of the raw message ({@see DkimVerifier}).
 *
 * Security note: anybody can put an A-R header into a message, and a mailbox provider that adds none
 * (Seznam) leaves ONLY sender-written ones. Without a configured authserv-id every A-R header is
 * therefore ignored.
 */
final class EmailAuthenticationVerifier
{
    public function __construct(private readonly DkimVerifier $dkim) {}

    /**
     * @param list<string> $authResults Authentication-Results header values, topmost first.
     * @param ?string $trustedAuthServId authserv-id of the receiving server whose A-R header may be trusted; null/empty = trust none.
     * @param string $rawHeader Raw header block of the message (empty = not captured).
     * @param string $rawBody Raw, undecoded body.
     */
    public function verify(
        array $authResults,
        ?string $expectedDomain,
        ?string $trustedAuthServId = null,
        string $rawHeader = '',
        string $rawBody = '',
    ): EmailAuthResult {
        $trustedAuthServId = trim((string) $trustedAuthServId);
        if ($trustedAuthServId !== '') {
            foreach ($authResults as $candidate) {
                $servId = trim(explode(';', $candidate, 2)[0]);
                if (stripos($servId, $trustedAuthServId) !== false) {
                    return $this->fromAuthenticationResults($candidate, $expectedDomain);
                }
            }
        }

        // No trusted verdict (the default): check the signature ourselves.
        if ($rawHeader === '') {
            return new EmailAuthResult(AuthVerdict::Fail, AuthMethod::DkimLocal, 'dkim_no_raw_message');
        }
        $result = $this->dkim->verify($rawHeader, $rawBody, $expectedDomain);
        return new EmailAuthResult(
            $result->pass ? AuthVerdict::Pass : ($result->retry ? AuthVerdict::Deferred : AuthVerdict::Fail),
            AuthMethod::DkimLocal,
            $result->detail,
        );
    }

    private function fromAuthenticationResults(string $line, ?string $expectedDomain): EmailAuthResult
    {
        $lc = strtolower($line);
        $result = static fn (bool $pass, string $detail): EmailAuthResult => new EmailAuthResult(
            $pass ? AuthVerdict::Pass : AuthVerdict::Fail,
            AuthMethod::AuthenticationResults,
            $detail,
        );

        // dmarc=pass already implies alignment with the From domain on the receiving server, so it is strong on its own.
        if (preg_match('/\bdmarc\s*=\s*pass\b/', $lc) === 1) {
            return $result(true, 'dmarc_pass');
        }

        // dkim=pass must carry a domain (header.d=) aligned with the expected sender.
        if (preg_match_all('/\bdkim\s*=\s*pass\b([^;]*)/', $lc, $matches) >= 1) {
            foreach ($matches[1] as $rest) {
                if ($expectedDomain === null || $expectedDomain === '') {
                    return $result(true, 'dkim_pass');
                }
                if (preg_match('/header\.d\s*=\s*([a-z0-9.\-]+)/', $rest, $d) === 1
                    && DomainAlignment::aligns($d[1], $expectedDomain)) {
                    return $result(true, 'dkim_pass_aligned');
                }
            }
            return $result(false, 'dkim_domain_mismatch');
        }

        return $result(false, 'auth_failed');
    }

    /**
     * Extracts the domain from a From address ("Name <info@rb.cz>" as well as "info@rb.cz").
     */
    public function domainFromSender(string $sender): ?string
    {
        if (preg_match('/[<\s]?([^<>@\s]+)@([a-z0-9.\-]+)/i', $sender, $m) === 1) {
            return strtolower($m[2]);
        }
        return null;
    }
}
