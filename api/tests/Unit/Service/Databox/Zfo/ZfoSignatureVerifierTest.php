<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox\Zfo;

use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\Zfo\ZfoSignatureVerifier;
use MyInvoice\Tests\Support\Databox\ZfoFixtures as Z;
use PHPUnit\Framework\TestCase;

final class ZfoSignatureVerifierTest extends TestCase
{
    private const XML = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><a>line one\r\nline two - příliš žluťoučký kůň</a>";

    public function testAnIntactFileReturnsItsVerifiedContentByteForByteAndTheSigner(): void
    {
        $result = (new ZfoSignatureVerifier())->verify(Z::seal(self::XML));

        self::assertSame(self::XML, $result['content'], 'binary mode: the line breaks are not rewritten');
        self::assertSame('Synthetic ISDS Sealer (Synthetic Operator)', $result['signer']);
    }

    public function testASignerWhoseNameEqualsHisOrganisationIsNamedOnce(): void
    {
        $signer = Z::signer('Same Name', 'Same Name');

        $result = (new ZfoSignatureVerifier())->verify(Z::seal(self::XML, $signer));

        self::assertSame('Same Name', $result['signer']);
    }

    public function testAnyChangeToTheSignedContentIsDetected(): void
    {
        $zfo = Z::seal(self::XML);
        $at = strpos($zfo, 'line two');
        self::assertNotFalse($at);
        $zfo[$at] = 'L';

        $this->expectException(DataboxException::class);
        $this->expectExceptionMessage('Podpis souboru ZFO se nepodařilo ověřit');
        (new ZfoSignatureVerifier())->verify($zfo);
    }

    public function testAChangedSignatureValueIsDetected(): void
    {
        $zfo = Z::seal(self::XML);
        // The RSA signature is the tail of the SignerInfo: flipping a bit in it must not verify.
        $zfo[strlen($zfo) - 20] = chr(ord($zfo[strlen($zfo) - 20]) ^ 0x01);

        $this->expectException(DataboxException::class);
        (new ZfoSignatureVerifier())->verify($zfo);
    }

    public function testGarbageAndASignatureWithoutContentAreRefused(): void
    {
        $verifier = new ZfoSignatureVerifier();
        foreach (['garbage' => 'not a cms at all', 'detached' => Z::seal(self::XML, null, true), 'empty' => ''] as $label => $bytes) {
            try {
                $verifier->verify($bytes);
                self::fail("{$label} must be refused");
            } catch (DataboxException $e) {
                self::assertSame('zfo_signature_invalid', $e->errorCode, $label);
                self::assertSame(422, $e->httpStatus, $label);
            }
        }
    }

    public function testNoTemporaryFileIsLeftBehind(): void
    {
        $before = glob(sys_get_temp_dir() . '/zfo*') ?: [];

        (new ZfoSignatureVerifier())->verify(Z::seal(self::XML));
        try {
            (new ZfoSignatureVerifier())->verify('garbage');
        } catch (DataboxException) {
        }

        self::assertSame($before, glob(sys_get_temp_dir() . '/zfo*') ?: []);
    }
}
