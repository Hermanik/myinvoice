<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice\Dkim;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimVerifier;
use MyInvoice\Tests\Support\Dkim\ArrayDkimKeyResolver;
use MyInvoice\Tests\Support\Dkim\DkimTestSigner;
use MyInvoice\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Messages signed by dkimpy (an independent, widely used Python implementation), so a mistake in
 * our canonicalization cannot be mirrored by our own test signer.
 */
final class DkimInteropFixtureTest extends TestCase
{
    /**
     * @return array<string,array{string,string,string,string}>
     */
    public static function cases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../../../../../Fixtures/dkim/dkimpy-interop.json'), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($data['cases'] as $case) {
            $out[$case['name']] = [(string) base64_decode($case['message_b64'], true), $case['txt'], $case['selector'], $case['domain']];
        }
        return $out;
    }

    #[DataProvider('cases')]
    public function testDkimpySignedMessageVerifies(string $message, string $txt, string $selector, string $domain): void
    {
        $resolver = new ArrayDkimKeyResolver([$selector . '._domainkey.' . $domain => [$txt]]);
        $verifier = new DkimVerifier($resolver, new FixedClock('2026-11-05 12:00:00 UTC'));

        $result = DkimTestSigner::verify($verifier, $message, $domain);
        self::assertTrue($result->pass, $result->detail);
        self::assertSame('dkim_local_pass:' . $domain, $result->detail);

        // The first body byte replaced: must break the body hash.
        $tampered = preg_replace('/(?<=\r\n\r\n)./s', 'X', $message, 1);
        self::assertNotSame($message, $tampered);
        self::assertSame('dkim_body_hash_mismatch', DkimTestSigner::verify($verifier, $tampered, $domain)->detail);
    }
}
