<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice\Dkim;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimMessage;
use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Tests\Support\Dkim\ArrayDkimKeyResolver;
use MyInvoice\Tests\Support\Dkim\DkimTestSigner;
use MyInvoice\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Local DKIM verification against synthetic messages signed in the test with freshly generated
 * keys; DNS is faked, nothing touches the network.
 */
final class DkimVerifierTest extends TestCase
{
    private const NOW = '2026-11-04 10:00:00 UTC';
    private const TS = 1_793_786_400; // 2026-11-04 10:00:00 UTC

    /** @var array{private:\OpenSSLAsymmetricKey,txt:string} */
    private static array $rsa;
    /** @var array{private:\OpenSSLAsymmetricKey,txt:string} */
    private static array $evil;
    /** @var array{private:string,txt:string} */
    private static array $ed;

    public static function setUpBeforeClass(): void
    {
        self::$rsa = DkimTestSigner::rsaKey(2048);
        self::$evil = DkimTestSigner::rsaKey(2048);
        self::$ed = DkimTestSigner::ed25519Key();
    }

    // ------------------------------------------------------------------ helpers

    private function message(string $from = 'Air Bank<info@airbank.cz>', ?string $body = null): string
    {
        return DkimTestSigner::sampleMessage($from, $body);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function signed(array $options = [], ?string $message = null): string
    {
        return DkimTestSigner::sign($message ?? $this->message(), $options + ['key' => self::$rsa['private']]);
    }

    /**
     * @param array<string,list<string>|null> $zone
     */
    private function verifier(?array $zone = null, ?ArrayDkimKeyResolver &$resolver = null, int $maxSignatures = 5): DkimVerifier
    {
        $resolver = new ArrayDkimKeyResolver($zone ?? ['ab._domainkey.airbank.cz' => [self::$rsa['txt']]]);
        return new DkimVerifier($resolver, new FixedClock(self::NOW), $maxSignatures);
    }

    private function assertPass(string $message, ?string $expectedDomain = 'airbank.cz', ?array $zone = null, string $domain = 'airbank.cz'): void
    {
        $result = DkimTestSigner::verify($this->verifier($zone), $message, $expectedDomain);
        self::assertTrue($result->pass, 'expected pass, got ' . $result->detail);
        self::assertSame('dkim_local_pass:' . $domain, $result->detail);
    }

    private function assertFail(string $detail, string $message, ?array $zone = null, ?string $expectedDomain = 'airbank.cz'): void
    {
        $result = DkimTestSigner::verify($this->verifier($zone), $message, $expectedDomain);
        self::assertFalse($result->pass, 'must not pass');
        self::assertSame($detail, $result->detail);
    }

    // ------------------------------------------------------------------ valid signatures

    /**
     * @return array<string,array{string}>
     */
    public static function canonicalizations(): array
    {
        return [
            'relaxed/relaxed' => ['relaxed/relaxed'],
            'simple/simple' => ['simple/simple'],
            'relaxed/simple' => ['relaxed/simple'],
            'simple/relaxed' => ['simple/relaxed'],
            'relaxed (body defaults to simple)' => ['relaxed'],
        ];
    }

    #[DataProvider('canonicalizations')]
    public function testValidRsaSignaturePasses(string $canon): void
    {
        $this->assertPass($this->signed(['canon' => $canon]));
    }

    #[DataProvider('canonicalizations')]
    public function testValidEd25519SignaturePasses(string $canon): void
    {
        $zone = ['ab._domainkey.airbank.cz' => [self::$ed['txt']]];
        $message = DkimTestSigner::sign($this->message(), ['key' => self::$ed['private'], 'alg' => 'ed25519-sha256', 'canon' => $canon]);
        $this->assertPass($message, 'airbank.cz', $zone);
    }

    public function testFoldedSignatureHeaderVerifiesInBothHeaderModes(): void
    {
        foreach (['simple/simple', 'relaxed/relaxed'] as $canon) {
            $this->assertPass($this->signed(['canon' => $canon, 'fold' => true]));
        }
    }

    public function testFoldedAndTabbedSignedHeadersVerify(): void
    {
        $message = str_replace(
            "Subject: =?UTF-8?B?WnbDvcWhZW7DrSB6xa9zdGF0a3U=?=\r\n",
            "Subject: =?UTF-8?B?WnbDvcWhZW7DrSB6xa9zdGF0a3U=?=\r\n\t  folded   continuation\t\r\n",
            $this->message(),
        );
        foreach (['simple/simple', 'relaxed/relaxed'] as $canon) {
            $this->assertPass($this->signed(['canon' => $canon], $message));
        }
    }

    public function testBodyWhitespaceDifferencesAreIgnoredOnlyByRelaxed(): void
    {
        $original = $this->message(body: "line one  \r\nline  two\t\r\n\r\n\r\n");
        $relaxedSigned = $this->signed(['canon' => 'relaxed/relaxed'], $original);
        // The same message after a hop that trimmed trailing blanks and squeezed runs of spaces.
        $rewritten = str_replace("line one  \r\nline  two\t\r\n\r\n\r\n", "line one\r\nline two\r\n", $relaxedSigned);
        $this->assertPass($rewritten);

        $simpleSigned = $this->signed(['canon' => 'relaxed/simple'], $original);
        $rewritten = str_replace("line one  \r\nline  two\t\r\n\r\n\r\n", "line one\r\nline two\r\n", $simpleSigned);
        $this->assertFail('dkim_body_hash_mismatch', $rewritten);
    }

    public function testLfOnlyCopyOfTheMessageStillVerifies(): void
    {
        $signed = $this->signed(['canon' => 'relaxed/relaxed']);
        $this->assertPass(str_replace("\r\n", "\n", $signed));

        $signed = $this->signed(['canon' => 'simple/simple', 'fold' => true]);
        $this->assertPass(str_replace("\r\n", "\n", $signed));
    }

    public function testSubdomainSigningDomainAlignsWithOrganizationalFromDomain(): void
    {
        $zone = ['ab._domainkey.mail.airbank.cz' => [self::$rsa['txt']]];
        $this->assertPass($this->signed(['domain' => 'mail.airbank.cz']), 'airbank.cz', $zone, 'mail.airbank.cz');
    }

    public function testParentSigningDomainAlignsWithSubdomainFrom(): void
    {
        $message = $this->signed([], $this->message('Air Bank <info@mail.airbank.cz>'));
        $this->assertPass($message, 'mail.airbank.cz');
    }

    public function testMultipleSignaturesWhereOnlyOneIsValid(): void
    {
        // On top: a signature whose selector has no published key. Below it: the genuine one.
        $first = DkimTestSigner::sign($this->signed(), ['key' => self::$evil['private'], 'selector' => 'zz', 'domain' => 'airbank.cz']);
        $this->assertPass($first);
    }

    public function testFirstSignatureInvalidSecondValid(): void
    {
        // Outer signature is valid, the inner one was made with the wrong private key.
        $badInner = $this->signed(['key' => self::$evil['private']]);
        $this->assertPass(DkimTestSigner::sign($badInner, ['key' => self::$rsa['private'], 'selector' => 'ab']));
        // Reverse order: bad one on top, good one below.
        $good = $this->signed();
        $reverse = DkimTestSigner::sign($good, ['key' => self::$evil['private'], 'selector' => 'ab']);
        $this->assertPass($reverse);
    }

    public function testSignatureWithLengthEqualToBodyIsAccepted(): void
    {
        $body = $this->message();
        $canonLength = strlen(DkimTestSigner::canonBody(DkimTestSigner::split($body)[1], 'relaxed'));
        $this->assertPass($this->signed(['canon' => 'relaxed/relaxed', 'length' => $canonLength]));
    }

    public function testBodyThatIsNotRelaxedCanonicalAtBodyLevelStillHashesConsistently(): void
    {
        $this->assertPass($this->signed(['canon' => 'relaxed/relaxed'], $this->message(body: '')));
        $this->assertPass($this->signed(['canon' => 'simple/simple'], $this->message(body: '')));
    }

    // ------------------------------------------------------------------ tampering

    public function testTamperedBodyFailsBodyHash(): void
    {
        $signed = $this->signed();
        $tampered = str_replace('1,00 CZK', '99 999,00 CZK', $signed);
        self::assertNotSame($signed, $tampered);
        $this->assertFail('dkim_body_hash_mismatch', $tampered);
    }

    public function testTamperedBodyNeedsNoDnsLookup(): void
    {
        $verifier = $this->verifier(null, $resolver);
        DkimTestSigner::verify($verifier, str_replace('1,00 CZK', '9,00 CZK', $this->signed()), 'airbank.cz');
        self::assertSame([], $resolver->lookups);
    }

    public function testTamperedSignedHeaderFailsSignature(): void
    {
        $signed = $this->signed();
        $tampered = str_replace('To: owner@example.cz', 'To: someone@example.cz', $signed);
        self::assertNotSame($signed, $tampered);
        $this->assertFail('dkim_signature_invalid', $tampered);
    }

    public function testTamperedSignatureValueFails(): void
    {
        $signed = $this->signed();
        // flip a char in the middle of b=
        $pos = strpos($signed, '; b=') + 10;
        $tampered = substr($signed, 0, $pos) . ($signed[$pos] === 'A' ? 'B' : 'A') . substr($signed, $pos + 1);
        $this->assertFail('dkim_signature_invalid', $tampered);
    }

    public function testSignatureMadeWithAnotherKeyFails(): void
    {
        $this->assertFail('dkim_signature_invalid', $this->signed(['key' => self::$evil['private']]));
    }

    public function testBodyAppendedBelowASignedPrefixIsRefused(): void
    {
        // The signer limited the hash to a prefix with l=; whoever sits on the path may append text.
        $prefixLength = strlen(DkimTestSigner::canonBody("Dobrý den,\r\n", 'relaxed'));
        $signed = $this->signed(['canon' => 'relaxed/relaxed', 'length' => $prefixLength]);
        $this->assertFail('dkim_body_length_limit', $signed);
    }

    public function testAddedFromHeaderIsRefused(): void
    {
        $signed = $this->signed(['headers' => ['from', 'to', 'subject', 'date', 'message-id']]);
        $forged = "From: Evil <ceo@airbank.cz>\r\n" . $signed;
        $this->assertFail('dkim_from_ambiguous', $forged);
    }

    public function testOverSignedHeaderCannotBeAdded(): void
    {
        $options = ['headers' => ['from', 'to', 'subject', 'subject', 'date', 'message-id']];
        $signed = $this->signed($options);
        $this->assertPass($signed);
        // A second Subject on top: because Subject was listed twice, the extra instance is detected.
        $this->assertFail('dkim_signature_invalid', "Subject: Platba prijata\r\n" . $signed);
    }

    public function testAddingAnUnlistedHeaderDoesNotBreakTheSignature(): void
    {
        $signed = $this->signed();
        $this->assertPass("X-Spam-Status: No\r\n" . $signed);
    }

    public function testAddingAnotherInstanceOfASingleSignedHeaderIsIgnoredBottomUp(): void
    {
        $signed = $this->signed();
        // Not over-signed: the verifier takes the bottom-most (original) Subject, as the signer did.
        $this->assertPass("Subject: injected\r\n" . $signed);
    }

    // ------------------------------------------------------------------ alignment

    public function testSignatureOfAnUnrelatedDomainIsNotAligned(): void
    {
        $zone = ['ab._domainkey.airbank.cz' => [self::$rsa['txt']], 'ab._domainkey.evil.example' => [self::$evil['txt']]];
        $message = $this->signed(['domain' => 'evil.example', 'key' => self::$evil['private']]);
        $verifier = $this->verifier($zone, $resolver);
        $result = DkimTestSigner::verify($verifier, $message, 'airbank.cz');
        self::assertFalse($result->pass);
        self::assertSame('dkim_domain_not_aligned', $result->detail);
        self::assertSame([], $resolver->lookups, 'no DNS lookup for a domain that cannot align');
    }

    public function testLookalikeSuffixDomainIsNotAligned(): void
    {
        $zone = ['ab._domainkey.notairbank.cz' => [self::$evil['txt']]];
        $message = $this->signed(['domain' => 'notairbank.cz', 'key' => self::$evil['private']]);
        $this->assertFail('dkim_domain_not_aligned', $message, $zone);
    }

    public function testSiblingSubdomainsAreNotAligned(): void
    {
        $zone = ['ab._domainkey.a.airbank.cz' => [self::$rsa['txt']]];
        $message = $this->signed(['domain' => 'a.airbank.cz'], $this->message('Air Bank <info@b.airbank.cz>'));
        $this->assertFail('dkim_domain_not_aligned', $message, $zone, 'b.airbank.cz');
    }

    public function testDisplayNameMentioningTheBankDoesNotBorrowItsDomain(): void
    {
        $zone = ['ab._domainkey.airbank.cz' => [self::$rsa['txt']]];
        $message = $this->signed([], $this->message('"info@airbank.cz" <attacker@evil.example>'));
        $this->assertFail('dkim_domain_not_aligned', $message, $zone, 'evil.example');
    }

    public function testExpectedDomainMustMatchTheFromHeader(): void
    {
        $this->assertFail('dkim_from_domain_mismatch', $this->signed(), null, 'evil.example');
    }

    public function testNoExpectedDomainStillRequiresAlignmentWithTheFromHeader(): void
    {
        $zone = ['ab._domainkey.evil.example' => [self::$evil['txt']]];
        $message = $this->signed(['domain' => 'evil.example', 'key' => self::$evil['private']]);
        $this->assertFail('dkim_domain_not_aligned', $message, $zone, null);
    }

    // ------------------------------------------------------------------ keys

    public function testMissingKeyIsReported(): void
    {
        $this->assertFail('dkim_key_not_found', $this->signed(), []);
    }

    public function testRevokedKeyIsRefused(): void
    {
        $this->assertFail('dkim_key_revoked', $this->signed(), ['ab._domainkey.airbank.cz' => ['v=DKIM1; k=rsa; p=']]);
    }

    public function testTestingKeyIsNotTrusted(): void
    {
        $zone = ['ab._domainkey.airbank.cz' => [self::$rsa['txt'] . '; t=y']];
        $this->assertFail('dkim_key_testing', $this->signed(), $zone);
        // t=s (strict subdomain flag) alone does not make a key untrusted.
        $this->assertPass($this->signed(), 'airbank.cz', ['ab._domainkey.airbank.cz' => [self::$rsa['txt'] . '; t=s']]);
    }

    public function testDnsFailureIsTemporary(): void
    {
        $result = DkimTestSigner::verify($this->verifier(['ab._domainkey.airbank.cz' => null]), $this->signed(), 'airbank.cz');
        self::assertFalse($result->pass);
        self::assertTrue($result->retry);
        self::assertSame('dkim_dns_error', $result->detail);
    }

    public function testPermanentFailuresAreNotRetried(): void
    {
        $result = DkimTestSigner::verify($this->verifier([]), $this->signed(), 'airbank.cz');
        self::assertFalse($result->retry);
    }

    public function testUnrelatedTxtRecordsAreIgnored(): void
    {
        $zone = ['ab._domainkey.airbank.cz' => ['v=spf1 -all', self::$rsa['txt']]];
        $this->assertPass($this->signed(), 'airbank.cz', $zone);
        $this->assertFail('dkim_key_not_found', $this->signed(), ['ab._domainkey.airbank.cz' => ['v=spf1 -all']]);
    }

    public function testKeyRecordWithWhitespaceInsideP(): void
    {
        $txt = preg_replace('/p=(.{60})(.+)$/', 'p=$1 $2', self::$rsa['txt']);
        $txt = chunk_split(str_replace('p=', 'p= ', $txt), 70, ' ');
        $this->assertPass($this->signed(), 'airbank.cz', ['ab._domainkey.airbank.cz' => [$txt]]);
    }

    public function testKeyRestrictedToOtherServiceOrHashIsNotUsable(): void
    {
        $this->assertFail('dkim_key_not_found', $this->signed(), ['ab._domainkey.airbank.cz' => [self::$rsa['txt'] . '; s=other']]);
        $this->assertFail('dkim_key_not_found', $this->signed(), ['ab._domainkey.airbank.cz' => [self::$rsa['txt'] . '; h=sha1']]);
        $this->assertFail('dkim_key_not_found', $this->signed(), ['ab._domainkey.airbank.cz' => [str_replace('DKIM1', 'DKIM2', self::$rsa['txt'])]]);
        $this->assertPass($this->signed(), 'airbank.cz', ['ab._domainkey.airbank.cz' => [self::$rsa['txt'] . '; s=*; h=sha1:sha256']]);
    }

    public function testKeyTypeMustMatchSignatureAlgorithm(): void
    {
        $edTxt = self::$ed['txt'];
        $this->assertFail('dkim_key_invalid', $this->signed(), ['ab._domainkey.airbank.cz' => [$edTxt]]);
    }

    public function testWeakRsaKeyIsRefused(): void
    {
        $weak = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 512]);
        if ($weak === false) {
            self::markTestSkipped('This OpenSSL refuses to generate 512-bit keys.');
        }
        $b64 = DkimTestSigner::publicKeyB64($weak);
        $message = $this->signed(['key' => $weak]);
        $this->assertFail('dkim_key_too_weak', $message, ['ab._domainkey.airbank.cz' => ['v=DKIM1; k=rsa; p=' . $b64]]);
    }

    public function testAirBankStyleKeyRecordIsAccepted(): void
    {
        // Shape of the real record at ab._domainkey.airbank.cz: one string, `v=DKIM1; k=rsa; p=<216 base64 chars>`,
        // i.e. a 1024-bit RSA key (still valid under RFC 8301, which only forbids anything smaller).
        $key = DkimTestSigner::rsaKey(1024);
        self::assertSame(216, strlen(substr($key['txt'], strlen('v=DKIM1; k=rsa; p='))));
        $message = DkimTestSigner::sign($this->message(), ['key' => $key['private'], 'canon' => 'relaxed/relaxed']);
        $this->assertPass($message, 'airbank.cz', ['ab._domainkey.airbank.cz' => [$key['txt']]]);
    }

    public function testBarePkcs1PublicKeyIsAccepted(): void
    {
        $der = base64_decode(DkimTestSigner::publicKeyB64(self::$rsa['private']));
        // SubjectPublicKeyInfo: skip the algorithm identifier header (24 bytes for a 2048-bit key) to get RSAPublicKey.
        $pkcs1 = substr($der, 24);
        $this->assertPass($this->signed(), 'airbank.cz', ['ab._domainkey.airbank.cz' => ['v=DKIM1; k=rsa; p=' . base64_encode($pkcs1)]]);
    }

    public function testGarbageKeyDoesNotThrow(): void
    {
        $this->assertFail('dkim_key_not_found', $this->signed(), ['ab._domainkey.airbank.cz' => ['v=DKIM1; k=rsa; p=%%%not-base64%%%']]);
        $this->assertFail('dkim_key_invalid', $this->signed(), ['ab._domainkey.airbank.cz' => ['v=DKIM1; k=rsa; p=' . base64_encode('garbage bytes that are no key')]]);
    }

    public function testOneKeyLookupPerCheckedSignature(): void
    {
        $verifier = $this->verifier(null, $resolver);
        DkimTestSigner::verify($verifier, $this->signed(), 'airbank.cz');
        self::assertSame(['ab._domainkey.airbank.cz'], $resolver->lookups);
    }

    // ------------------------------------------------------------------ algorithm / time / tags

    public function testSha1SignatureIsRejectedEvenWhenItVerifies(): void
    {
        $this->assertFail('dkim_algorithm_insecure', $this->signed(['alg' => 'rsa-sha1']));
    }

    public function testUnknownAlgorithmIsRejected(): void
    {
        $this->assertFail('dkim_algorithm_unsupported', $this->signed(['tags' => ['a' => 'rsa-md5']]));
    }

    public function testExpiredSignatureIsRejected(): void
    {
        $this->assertFail('dkim_signature_expired', $this->signed(['timestamp' => self::TS - 7200, 'expires' => self::TS - 60]));
        $this->assertPass($this->signed(['timestamp' => self::TS - 7200, 'expires' => self::TS + 3600]));
    }

    public function testTimestampFarInTheFutureIsRejected(): void
    {
        $this->assertFail('dkim_timestamp_invalid', $this->signed(['timestamp' => self::TS + 10 * 86400]));
        $this->assertPass($this->signed(['timestamp' => self::TS + 600]));
    }

    public function testExpiryBeforeTimestampIsMalformed(): void
    {
        $this->assertFail('dkim_malformed_signature', $this->signed(['timestamp' => self::TS, 'expires' => self::TS - 1000]));
    }

    /**
     * @return array<string,array{array<string,string>}>
     */
    public static function malformedTags(): array
    {
        return [
            'no body hash' => [['bh' => '']],
            'no domain' => [['d' => '']],
            'no selector' => [['s' => '']],
            'no signed headers' => [['h' => '']],
            'wrong version' => [['v' => '2']],
            'duplicate-ish bad canonicalization' => [['c' => 'relaxed/relaxed/simple']],
            'unknown canonicalization' => [['c' => 'foo/bar']],
            'bad domain' => [['d' => 'not a domain']],
            'domain without dot' => [['d' => 'localhost']],
            'bad selector' => [['s' => 'a b']],
            'dns query injection in selector' => [['s' => '../x']],
            'short body hash' => [['bh' => 'AAAA']],
            'bad base64 body hash' => [['bh' => '!!!!']],
            'non numeric length' => [['l' => 'abc']],
            'non numeric timestamp' => [['t' => '12x']],
            'identity of another domain' => [['i' => '@evil.example']],
            'unsupported query method' => [['q' => 'http/foo']],
        ];
    }

    /**
     * @param array<string,string> $tags
     */
    #[DataProvider('malformedTags')]
    public function testMalformedSignatureTagsAreRejectedWithoutThrowing(array $tags): void
    {
        $result = DkimTestSigner::verify($this->verifier(), $this->signed(['tags' => $tags]), 'airbank.cz');
        self::assertFalse($result->pass);
        self::assertSame('dkim_malformed_signature', $result->detail);
    }

    public function testSignatureThatDoesNotCoverFromIsRejected(): void
    {
        $this->assertFail('dkim_from_not_signed', $this->signed(['headers' => ['to', 'subject', 'date']]));
    }

    public function testIdentityOfTheSigningDomainIsAccepted(): void
    {
        $this->assertPass($this->signed(['tags' => ['i' => '@mail.airbank.cz']]));
        $this->assertPass($this->signed(['tags' => ['i' => 'info@airbank.cz']]));
    }

    public function testDuplicateTagIsRejected(): void
    {
        $signed = $this->signed();
        $broken = preg_replace('/^DKIM-Signature: /', 'DKIM-Signature: d=evil.example; ', $signed);
        $this->assertFail('dkim_malformed_signature', $broken);
    }

    public function testUnknownTagsAreIgnored(): void
    {
        // Unknown tags are part of the signed header, so the signer must include them.
        $this->assertPass($this->signed(['tags' => ['z' => 'whatever', 'r' => 'y']]));
    }

    // ------------------------------------------------------------------ message structure

    public function testNoSignature(): void
    {
        $this->assertFail('dkim_no_signature', $this->message());
    }

    public function testMissingAndAmbiguousFromHeader(): void
    {
        $this->assertFail('dkim_from_missing', "Subject: x\r\n\r\nbody\r\n");
        $this->assertFail('dkim_from_ambiguous', "From: a@airbank.cz, b@airbank.cz\r\n\r\nbody\r\n");
        $this->assertFail('dkim_from_ambiguous', "From: Undisclosed: a@airbank.cz;\r\n\r\nbody\r\n");
        $this->assertFail('dkim_from_invalid', "From: not an address\r\n\r\nbody\r\n");
        $this->assertFail('dkim_from_invalid', "From: info@localhost\r\n\r\nbody\r\n");
        $this->assertFail('dkim_from_invalid', "From: \"unterminated <info@airbank.cz>\r\n\r\nbody\r\n");
    }

    public function testFromVariantsResolveToTheAddressDomain(): void
    {
        foreach ([
            'info@airbank.cz',
            'Air Bank <info@airbank.cz>',
            'Air Bank<info@airbank.cz>',
            '"Bank, Air" <info@AIRBANK.cz>',
            'info@airbank.cz (Air Bank)',
            '=?UTF-8?B?QWlyIEJhbms=?= <info@airbank.cz>',
            '"info@evil.example" <info@airbank.cz>',
        ] as $from) {
            $this->assertPass($this->signed([], $this->message($from)), 'airbank.cz');
        }
    }

    public function testEmptyAndGarbageInputNeverThrow(): void
    {
        $verifier = $this->verifier();
        foreach ([
            '', "\r\n", "\n\n\n", 'no headers at all', ':', "\0\0\0", str_repeat("\xFF", 1000),
            "DKIM-Signature: \r\n\r\n", "DKIM-Signature\r\nFrom: a@airbank.cz\r\n\r\n",
            "DKIM-Signature: v=1; a=rsa-sha256\r\nFrom: a@airbank.cz\r\n\r\n",
            "From: a@airbank.cz\r\nDKIM-Signature: ;;;===;;;\r\n\r\nx",
            " continuation first\r\nFrom: a@airbank.cz\r\n\r\n",
        ] as $input) {
            $result = DkimTestSigner::verify($verifier, $input, 'airbank.cz');
            self::assertFalse($result->pass);
            self::assertStringStartsWith('dkim_', $result->detail);
        }
    }

    public function testRandomBytesNeverThrowOrPass(): void
    {
        $verifier = $this->verifier();
        mt_srand(7);
        for ($i = 0; $i < 200; $i++) {
            $input = random_bytes(mt_rand(1, 400));
            self::assertFalse(DkimTestSigner::verify($verifier, $input, 'airbank.cz')->pass);
            // Structured garbage: valid-looking headers with random tag values.
            $junk = "From: a@airbank.cz\r\nDKIM-Signature: " . bin2hex(random_bytes(mt_rand(1, 40))) . '=' . base64_encode(random_bytes(30)) . "; v=1; a=rsa-sha256\r\n\r\n";
            self::assertFalse(DkimTestSigner::verify($verifier, $junk, 'airbank.cz')->pass);
        }
    }

    public function testOversizedHeaderBlockAndBodyAreRefused(): void
    {
        $verifier = $this->verifier();
        $header = "From: a@airbank.cz\r\n" . str_repeat('X-Padding: ' . str_repeat('a', 70) . "\r\n", 4000) . "\r\n";
        self::assertGreaterThan(DkimMessage::MAX_HEADER_BYTES, strlen($header));
        self::assertSame('dkim_header_too_large', $verifier->verify($header, 'body', 'airbank.cz')->detail);

        $body = str_repeat('b', DkimMessage::MAX_BODY_BYTES + 1);
        self::assertSame('dkim_message_too_large', $verifier->verify("From: a@airbank.cz\r\n\r\n", $body, 'airbank.cz')->detail);
        // Exactly at the cap is still looked at (and fails for want of a signature).
        self::assertSame('dkim_no_signature', $verifier->verify("From: a@airbank.cz\r\n\r\n", substr($body, 1), 'airbank.cz')->detail);
    }

    public function testHugeBodyOfBlanksCanonicalizesInLinearTime(): void
    {
        $verifier = $this->verifier();
        $body = str_repeat(' ', DkimMessage::MAX_BODY_BYTES - 10) . "x\r\n";
        $signed = DkimTestSigner::sign($this->message(body: $body), ['key' => self::$rsa['private'], 'canon' => 'relaxed/relaxed']);
        $start = microtime(true);
        self::assertTrue(DkimTestSigner::verify($verifier, $signed, 'airbank.cz')->pass);
        self::assertLessThan(5.0, microtime(true) - $start);
    }

    public function testOnlyTheConfiguredNumberOfAlignedSignaturesIsChecked(): void
    {
        $message = $this->signed();   // the valid one ends up at the bottom
        for ($i = 0; $i < 3; $i++) {
            $message = DkimTestSigner::sign($message, ['key' => self::$evil['private'], 'selector' => 'ab']);
        }
        $resolver = null;
        $verifier = $this->verifier(null, $resolver, 3);
        $result = DkimTestSigner::verify($verifier, $message, 'airbank.cz');
        self::assertFalse($result->pass);
        self::assertStringContainsString('dkim_too_many_signatures', $result->detail);
        self::assertCount(3, $resolver->lookups, 'three signatures are examined, the fourth hits the cap');

        $verifier = $this->verifier(null, $resolver, 4);
        self::assertTrue(DkimTestSigner::verify($verifier, $message, 'airbank.cz')->pass);
    }

    public function testManyUnalignedSignaturesDoNotExhaustTheBudget(): void
    {
        $message = $this->signed();
        for ($i = 0; $i < 20; $i++) {
            $message = DkimTestSigner::sign($message, ['key' => self::$evil['private'], 'domain' => "x$i.evil.example"]);
        }
        $verifier = $this->verifier(null, $resolver);
        self::assertTrue(DkimTestSigner::verify($verifier, $message, 'airbank.cz')->pass);
        self::assertSame(['ab._domainkey.airbank.cz'], $resolver->lookups);
    }

    public function testMultipleFailuresAreReportedTogether(): void
    {
        $zone = ['ab._domainkey.airbank.cz' => [self::$rsa['txt']]];
        $message = $this->signed(['key' => self::$evil['private']]);
        $message = DkimTestSigner::sign($message, ['key' => self::$evil['private'], 'domain' => 'evil.example']);
        $result = DkimTestSigner::verify($this->verifier($zone), $message, 'airbank.cz');
        self::assertSame('dkim_domain_not_aligned,dkim_signature_invalid', $result->detail);
    }
}
