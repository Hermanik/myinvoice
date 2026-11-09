<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox\EmailNotice;

use MyInvoice\Service\Bank\EmailNotice\AuthVerdict;
use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthenticationVerifier;
use MyInvoice\Service\Bank\EmailNotice\EmailAuthResult;
use MyInvoice\Service\Databox\EmailNotice\DatovkaNoticeAuthenticator;
use MyInvoice\Tests\Support\Databox\DatovkaNoticeFixtures as F;
use MyInvoice\Tests\Support\Dkim\ArrayDkimKeyResolver;
use MyInvoice\Tests\Support\Dkim\DkimTestSigner;
use MyInvoice\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * Authenticity of a notification e-mail, with keys generated here and a fake DNS: nothing real is signed or looked up.
 */
final class DatovkaNoticeAuthenticatorTest extends TestCase
{
    /** @var array{private:\OpenSSLAsymmetricKey,txt:string} */
    private static array $key;
    /** @var array{private:\OpenSSLAsymmetricKey,txt:string} */
    private static array $otherKey;

    public static function setUpBeforeClass(): void
    {
        self::$key = DkimTestSigner::rsaKey(2048);
        self::$otherKey = DkimTestSigner::rsaKey(2048);
    }

    /** @param array<string,list<string>|null>|null $zone */
    private function authenticator(?array $zone = null, ?ArrayDkimKeyResolver &$dns = null): DatovkaNoticeAuthenticator
    {
        $dns = new ArrayDkimKeyResolver($zone ?? [
            '20230317._domainkey.datovka.gov.cz' => [self::$key['txt']],
            '20230317._domainkey.mojedatovaschranka.cz' => [self::$key['txt']],
        ]);
        return new DatovkaNoticeAuthenticator(new EmailAuthenticationVerifier(new DkimVerifier($dns, new FixedClock('2026-11-09 10:00:00 UTC'))));
    }

    private function verify(string $raw, ?DatovkaNoticeAuthenticator $auth = null): EmailAuthResult
    {
        $message = F::message($raw);
        return ($auth ?? $this->authenticator())->verify($message->sender, $message->rawHeader, $message->raw);
    }

    public function testASignedNoticeFromTheCurrentDomainPasses(): void
    {
        $result = $this->verify(F::signed(F::raw(), self::$key));

        self::assertTrue($result->passed(), $result->detail);
        self::assertSame('dkim_local_pass:datovka.gov.cz', $result->detail);
    }

    public function testASignedNoticeFromTheOlderDomainPasses(): void
    {
        $raw = F::signed(F::raw(['from' => '"Datové schránky" <notifikace@mojedatovaschranka.cz>']), self::$key, 'mojedatovaschranka.cz');

        $result = $this->verify($raw);

        self::assertTrue($result->passed(), $result->detail);
        self::assertSame('dkim_local_pass:mojedatovaschranka.cz', $result->detail);
    }

    public function testAnUnsignedNoticeIsRejected(): void
    {
        $result = $this->verify(F::raw());

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_no_signature', $result->detail);
    }

    public function testATamperedBodyIsRejected(): void
    {
        // The genuine, signed headers with the body of a notice that says something else.
        [$signedHeader] = F::split(F::signed(F::raw(), self::$key));
        [, $otherBody] = F::split(F::raw(['html' => F::html(['annotation' => 'Výzva k zaplacení všeho'])]));
        $forged = $signedHeader . $otherBody;

        $result = $this->verify($forged);

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_body_hash_mismatch', $result->detail);
    }

    public function testATamperedSignedHeaderIsRejected(): void
    {
        $genuine = F::signed(F::raw(), self::$key);
        // The Subject was rewritten after the signing; the body and the signature are untouched.
        $forged = (string) preg_replace('/^Subject:.*$/m', "Subject: Jiná věc\r", $genuine);
        self::assertNotSame($genuine, $forged);

        $result = $this->verify($forged);

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_signature_invalid', $result->detail);
    }

    public function testASignatureOfAnotherKeyIsRejected(): void
    {
        $result = $this->verify(F::signed(F::raw(), self::$otherKey));

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_signature_invalid', $result->detail);
    }

    public function testASignatureByAnotherDomainIsRejectedWithoutALookup(): void
    {
        $raw = F::signed(F::raw(), self::$key, 'evil.example');

        $result = $this->verify($raw, $this->authenticator(['20230317._domainkey.evil.example' => [self::$key['txt']]], $dns));

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_domain_not_aligned', $result->detail);
        self::assertSame([], $dns->lookups, 'no DNS lookup for a domain that cannot align');
    }

    public function testASignatureOfTheOtherNotifierDomainDoesNotAlignWithTheFromDomain(): void
    {
        // Both domains are the notifier's, but a message from one must be signed by that one.
        $result = $this->verify(F::signed(F::raw(), self::$key, 'mojedatovaschranka.cz'));

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_domain_not_aligned', $result->detail);
    }

    public function testAParentDomainSignatureIsNotEnough(): void
    {
        // gov.cz aligns with datovka.gov.cz as a parent, which the generic verifier accepts; here it must be the notifier itself.
        $raw = F::signed(F::raw(), self::$key, 'gov.cz');

        $result = $this->verify($raw, $this->authenticator(['20230317._domainkey.gov.cz' => [self::$key['txt']]]));

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_domain_not_allowed', $result->detail);
    }

    public function testASenderThatIsNotTheNotifierIsRejectedBeforeAnyLookup(): void
    {
        foreach ([
            'another local part' => '"Datové schránky" <info@datovka.gov.cz>',
            'another domain' => 'Datove schranky <notifikace@datovka.example>',
            'a look-alike domain' => 'notifikace@evildatovka.gov.cz',
            'a subdomain' => 'notifikace@mail.datovka.gov.cz',
            'no address' => 'Datové schránky',
        ] as $case => $from) {
            $raw = F::signed(F::raw(['from' => $from]), self::$key);

            $result = $this->verify($raw, $this->authenticator(null, $dns));

            self::assertSame(AuthVerdict::Fail, $result->verdict, $case);
            self::assertSame('sender_not_allowed', $result->detail, $case);
            self::assertSame([], $dns->lookups, $case);
        }
    }

    public function testADisplayNameThatMentionsTheNotifierDoesNotLendItsAddress(): void
    {
        $raw = F::signed(F::raw(['from' => '"notifikace@datovka.gov.cz" <evil@example.cz>']), self::$key, 'example.cz');

        self::assertSame('sender_not_allowed', $this->verify($raw)->detail);
    }

    public function testATemporaryDnsFailureDefersInsteadOfRejecting(): void
    {
        $result = $this->verify(F::signed(F::raw(), self::$key), $this->authenticator(['20230317._domainkey.datovka.gov.cz' => null]));

        self::assertSame(AuthVerdict::Deferred, $result->verdict);
        self::assertTrue($result->isDeferred());
    }

    public function testAMissingKeyRecordIsARejectionNotADeferral(): void
    {
        $result = $this->verify(F::signed(F::raw(), self::$key), $this->authenticator([]));

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_key_not_found', $result->detail);
    }

    public function testWithoutTheRawHeaderNothingCanBeProved(): void
    {
        $message = F::message(F::signed(F::raw(), self::$key));

        $result = $this->authenticator()->verify($message->sender, '', $message->raw);

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_no_raw_message', $result->detail);
    }

    public function testAnAuthenticationResultsHeaderChangesNothing(): void
    {
        // What an attacker writes into a message for a mailbox that adds no verdict of its own.
        $forged = "Authentication-Results: mx.example.invalid; spf=pass dkim=pass dmarc=pass header.d=datovka.gov.cz\r\n" . F::raw();

        $result = $this->verify($forged);

        self::assertSame(AuthVerdict::Fail, $result->verdict);
        self::assertSame('dkim_no_signature', $result->detail);
    }
}
