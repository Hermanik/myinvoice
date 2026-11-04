<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\AuthMethod;
use MyInvoice\Service\Bank\EmailNotice\AuthVerdict;
use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use MyInvoice\Tests\Support\Dkim\ArrayDkimKeyResolver;
use MyInvoice\Tests\Support\Dkim\DkimTestSigner;
use MyInvoice\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * Policy under test: an Authentication-Results header is believed ONLY when the account pins the
 * authserv-id of its receiving server and the header carries exactly that id. In every other case
 * (the default) the sender has to prove itself with its own DKIM signature, and A-R headers, which
 * the sender can write itself, are ignored.
 */
final class EmailAuthenticationVerifierTest extends TestCase
{
    private const TRUSTED = 'mx.tvujmail.cz';
    private const FORGED_PASS = 'attacker.example; spf=pass dkim=pass dmarc=pass header.d=rb.cz header.d=airbank.cz';

    /** @var array{private:\OpenSSLAsymmetricKey,txt:string} */
    private static array $key;
    private ArrayDkimKeyResolver $dns;
    private EmailAuthenticationVerifier $verifier;

    public static function setUpBeforeClass(): void
    {
        self::$key = DkimTestSigner::rsaKey(2048);
    }

    protected function setUp(): void
    {
        $this->dns = new ArrayDkimKeyResolver(['ab._domainkey.airbank.cz' => [self::$key['txt']]]);
        $this->verifier = new EmailAuthenticationVerifier(new DkimVerifier($this->dns, new FixedClock('2026-11-04 10:00:00 UTC')));
    }

    /**
     * @return array{0:string,1:string} raw header block and raw body of a notice, unsigned or signed by the bank
     */
    private function notice(bool $signed): array
    {
        $message = DkimTestSigner::sampleMessage();
        if ($signed) {
            $message = DkimTestSigner::sign($message, ['key' => self::$key['private'], 'canon' => 'relaxed/relaxed']);
        }
        $cut = (int) strpos($message, "\r\n\r\n") + 4;
        return [substr($message, 0, $cut), substr($message, $cut)];
    }

    // ---------------------------------------------------------------- trusted Authentication-Results

    public function testDmarcPassOfTheTrustedServerIsAccepted(): void
    {
        $r = $this->verifier->verify([self::TRUSTED . '; spf=pass dkim=pass dmarc=pass header.d=rb.cz'], 'rb.cz', self::TRUSTED);
        self::assertTrue($r->passed());
        self::assertSame(AuthMethod::AuthenticationResults, $r->method);
        self::assertSame('dmarc_pass', $r->detail);
    }

    public function testDkimPassWithAlignedDomainIsAccepted(): void
    {
        $r = $this->verifier->verify([self::TRUSTED . '; dkim=pass header.d=rb.cz'], 'rb.cz', self::TRUSTED);
        self::assertTrue($r->passed());
        self::assertSame('dkim_pass_aligned', $r->detail);
    }

    public function testDkimPassWithSubdomainAligns(): void
    {
        self::assertTrue($this->verifier->verify([self::TRUSTED . '; dkim=pass header.d=mail.rb.cz'], 'rb.cz', self::TRUSTED)->passed());
    }

    public function testDkimPassWithWrongDomainIsRejected(): void
    {
        $r = $this->verifier->verify([self::TRUSTED . '; dkim=pass header.d=evil.com'], 'rb.cz', self::TRUSTED);
        self::assertSame(AuthVerdict::Fail, $r->verdict);
        self::assertSame('dkim_domain_mismatch', $r->detail);
    }

    public function testDkimPassForSingleLabelParentDomainDoesNotAlign(): void
    {
        self::assertFalse($this->verifier->verify([self::TRUSTED . '; dkim=pass header.d=cz'], 'rb.cz', self::TRUSTED)->passed());
    }

    public function testFailVerdictOfTheTrustedServerStandsEvenForALocallyValidSignature(): void
    {
        [$header, $body] = $this->notice(signed: true);
        $r = $this->verifier->verify([self::TRUSTED . '; spf=fail dkim=fail dmarc=fail'], 'airbank.cz', self::TRUSTED, $header, $body);
        self::assertFalse($r->passed());
        self::assertSame('auth_failed', $r->detail);
        self::assertSame(AuthMethod::AuthenticationResults, $r->method);
        self::assertSame([], $this->dns->lookups, 'no DNS when the server gave a verdict');
    }

    public function testForgedTopHeaderIsIgnoredWhenAuthServIdIsPinned(): void
    {
        // The sender's header on top pretends to pass; only the line with the pinned id counts.
        $headers = ['attacker-injected; dkim=pass header.d=rb.cz', self::TRUSTED . '; dkim=fail dmarc=fail'];
        self::assertFalse($this->verifier->verify($headers, 'rb.cz', self::TRUSTED)->passed());
    }

    public function testPinnedAuthServIdSelectsTheGenuineLine(): void
    {
        $headers = ['attacker-injected; dkim=fail', self::TRUSTED . '; dkim=pass header.d=rb.cz'];
        self::assertTrue($this->verifier->verify($headers, 'rb.cz', self::TRUSTED)->passed());
    }

    // ---------------------------------------------------------------- everything else: own DKIM check

    public function testForgedAuthenticationResultsAreIgnoredWithoutAPinnedAuthServId(): void
    {
        [$header, $body] = $this->notice(signed: false);
        foreach ([null, '', '   '] as $pinned) {
            $r = $this->verifier->verify([self::FORGED_PASS], 'airbank.cz', $pinned, $header, $body);
            self::assertFalse($r->passed(), 'a sender-written A-R header must not vouch for the sender');
            self::assertSame(AuthMethod::DkimLocal, $r->method);
            self::assertSame('dkim_no_signature', $r->detail);
        }
    }

    public function testForgedAuthenticationResultsDoNotRescueABrokenSignature(): void
    {
        [$header, $body] = $this->notice(signed: true);
        $r = $this->verifier->verify([self::FORGED_PASS], 'airbank.cz', null, $header, str_replace('1,00 CZK', '99 999,00 CZK', $body));
        self::assertFalse($r->passed());
        self::assertSame('dkim_body_hash_mismatch', $r->detail);
    }

    public function testGenuineSignedMessagePassesLocallyWhateverTheHeadersSay(): void
    {
        [$header, $body] = $this->notice(signed: true);
        foreach ([[], [self::FORGED_PASS], ['mx.seznam.cz; dkim=fail']] as $authResults) {
            $r = $this->verifier->verify($authResults, 'airbank.cz', null, $header, $body);
            self::assertTrue($r->passed());
            self::assertSame(AuthMethod::DkimLocal, $r->method);
            self::assertSame('dkim_local_pass:airbank.cz', $r->detail);
        }
    }

    public function testConfiguredAuthServIdThatMatchesNoHeaderFallsBackToLocalVerification(): void
    {
        [$header, $body] = $this->notice(signed: true);
        $foreign = ['attacker.example; dkim=pass header.d=airbank.cz'];

        $genuine = $this->verifier->verify($foreign, 'airbank.cz', 'mx.seznam.cz', $header, $body);
        self::assertTrue($genuine->passed());
        self::assertSame(AuthMethod::DkimLocal, $genuine->method);

        [$unsignedHeader, $unsignedBody] = $this->notice(signed: false);
        $forged = $this->verifier->verify($foreign, 'airbank.cz', 'mx.seznam.cz', $unsignedHeader, $unsignedBody);
        self::assertFalse($forged->passed());
        self::assertSame('dkim_no_signature', $forged->detail);
    }

    public function testWithoutTheRawMessageAndWithoutATrustedVerdictNothingIsAccepted(): void
    {
        foreach ([[], [self::FORGED_PASS]] as $authResults) {
            $r = $this->verifier->verify($authResults, 'airbank.cz');
            self::assertFalse($r->passed());
            self::assertSame('dkim_no_raw_message', $r->detail);
        }
    }

    public function testTemporaryDnsFailureIsDeferredNotRejected(): void
    {
        $verifier = new EmailAuthenticationVerifier(new DkimVerifier(
            new ArrayDkimKeyResolver(['ab._domainkey.airbank.cz' => null]),
            new FixedClock('2026-11-04 10:00:00 UTC'),
        ));
        [$header, $body] = $this->notice(signed: true);

        $r = $verifier->verify([], 'airbank.cz', null, $header, $body);
        self::assertSame(AuthVerdict::Deferred, $r->verdict);
        self::assertTrue($r->isDeferred());
        self::assertFalse($r->passed());
        self::assertSame('dkim_dns_error', $r->detail);
    }

    public function testFromDomainOfTheAppAndOfTheSignedHeaderMustAgree(): void
    {
        [$header, $body] = $this->notice(signed: true);
        $r = $this->verifier->verify([], 'evil.example', null, $header, $body);
        self::assertFalse($r->passed());
        self::assertSame('dkim_from_domain_mismatch', $r->detail);
    }

    public function testResultIsStoredAsMethodAndDetail(): void
    {
        [$header, $body] = $this->notice(signed: true);
        self::assertSame(
            ['auth_method' => 'dkim_local', 'auth_detail' => 'dkim_local_pass:airbank.cz'],
            $this->verifier->verify([], 'airbank.cz', null, $header, $body)->toPayload(),
        );
        self::assertSame(
            ['auth_method' => 'authentication_results', 'auth_detail' => 'dmarc_pass'],
            $this->verifier->verify([self::TRUSTED . '; dmarc=pass'], 'airbank.cz', self::TRUSTED)->toPayload(),
        );
    }

    public function testDomainFromSender(): void
    {
        self::assertSame('rb.cz', $this->verifier->domainFromSender('Raiffeisenbank <info@rb.cz>'));
        self::assertSame('rb.cz', $this->verifier->domainFromSender('info@rb.cz'));
        self::assertNull($this->verifier->domainFromSender('not-an-email'));
    }
}
