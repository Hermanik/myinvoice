<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tax;

use MyInvoice\Service\Tax\TaxFilingException;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The input rules of the filing lifecycle that need no database: filing time, receipt content, file name. */
final class TaxSubmissionFilingRulesTest extends TestCase
{
    private const NOW = '2027-02-24 16:40:00';

    #[DataProvider('filingTimes')]
    public function testFilingTimeIsNormalised(?string $input, string $expected): void
    {
        self::assertSame($expected, TaxSubmissionFilingService::parseFiledAt($input, new \DateTimeImmutable(self::NOW)));
    }

    /** @return array<string, array{?string, string}> */
    public static function filingTimes(): array
    {
        return [
            'empty means now'             => [null, '2027-02-24 16:40:00'],
            'blank means now'             => ['  ', '2027-02-24 16:40:00'],
            'datetime-local input'        => ['2027-02-24T09:15', '2027-02-24 09:15:00'],
            'with seconds'                => ['2027-02-24 09:15:30', '2027-02-24 09:15:30'],
            'date only is the start of day' => ['2027-02-20', '2027-02-20 00:00:00'],
            'a few minutes ahead (clock skew)' => ['2027-02-24T16:44', '2027-02-24 16:44:00'],
        ];
    }

    #[DataProvider('badFilingTimes')]
    public function testInvalidFilingTimeIsRefused(string $input): void
    {
        $this->expectException(TaxFilingException::class);
        TaxSubmissionFilingService::parseFiledAt($input, new \DateTimeImmutable(self::NOW));
    }

    /** @return array<string, array{string}> */
    public static function badFilingTimes(): array
    {
        return [
            'tomorrow'        => ['2027-02-25 10:00'],
            'garbage'         => ['yesterday'],
            'czech format'    => ['24.2.2027 10:00'],
            'impossible date' => ['2027-02-30 10:00'],
            'impossible hour' => ['2027-02-20 25:00'],
        ];
    }

    #[DataProvider('validReceipts')]
    public function testReceiptsThatLookRightAreAccepted(string $content, string $extension): void
    {
        TaxSubmissionFilingService::assertReceiptContent($content, $extension);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string, string}> */
    public static function validReceipts(): array
    {
        return [
            'PDF'                  => ["%PDF-1.7\n%âãÏÓ\n", 'pdf'],
            'PDF behind a BOM'     => ["\xef\xbb\xbf%PDF-1.4", 'pdf'],
            'DER PKCS#7 (.p7s)'    => ["\x30\x82\x0b\x1c" . str_repeat("\x00", 16), 'p7s'],
            'PEM PKCS#7 (.p7s)'    => ["-----BEGIN PKCS7-----\nMIIB\n-----END PKCS7-----", 'p7s'],
            'base64 CMS (.p7s)'    => ['MIAGCSqGSIb3DQEHAqCAMIACAQExDzANBglghkgBZQMEAgEFADCABgkqhkiG9w0B', 'p7s'],
            'ISDS message (.zfo)'  => ["\x30\x82\x01\x00" . str_repeat('a', 32), 'zfo'],
        ];
    }

    #[DataProvider('invalidReceipts')]
    public function testOtherContentIsRefused(string $content, string $extension, string $errorCode): void
    {
        try {
            TaxSubmissionFilingService::assertReceiptContent($content, $extension);
            self::fail('Expected a refusal');
        } catch (TaxFilingException $e) {
            self::assertSame($errorCode, $e->errorCode);
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidReceipts(): array
    {
        return [
            'executable extension'  => ['MZ', 'exe', 'invalid_extension'],
            'html page as .pdf'     => ['<!doctype html><script>', 'pdf', 'invalid_pdf'],
            'html page as .p7s'     => ['<html><body>', 'p7s', 'invalid_receipt'],
            'plain text as .zfo'    => ['hello', 'zfo', 'invalid_receipt'],
            'empty'                 => ['', 'pdf', 'empty_file'],
            'too large'             => ["%PDF-1.4\n" . str_repeat('a', TaxSubmissionFilingService::RECEIPT_MAX_BYTES), 'pdf', 'file_too_large'],
            'no extension'          => ["%PDF-1.4\n", '', 'invalid_extension'],
        ];
    }

    #[DataProvider('fileNames')]
    public function testReceiptNameIsSanitisedForDisplayAndDownload(string $input, string $expected): void
    {
        self::assertSame($expected, TaxSubmissionFilingService::safeFilename($input));
    }

    /** @return array<string, array{string, string}> */
    public static function fileNames(): array
    {
        return [
            'plain'             => ['potvrzeni.pdf', 'potvrzeni.pdf'],
            'diacritics kept'   => ['potvrzení podání.p7s', 'potvrzení podání.p7s'],
            'path traversal'    => ['../../etc/passwd.pdf', 'passwd.pdf'],
            'windows path'      => ['C:\\Users\\me\\a.pdf', 'a.pdf'],
            'quotes and header injection' => ["a\"\r\nX-Evil: 1.pdf", 'a_X-Evil_ 1.pdf'],
            'empty'             => ['', 'potvrzeni'],
            'only dots'         => ['...', 'potvrzeni'],
        ];
    }
}
