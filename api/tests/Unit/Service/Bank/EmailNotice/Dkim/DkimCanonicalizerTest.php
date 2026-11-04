<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice\Dkim;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DkimCanonicalizer;
use PHPUnit\Framework\TestCase;

/**
 * Examples straight from RFC 6376 section 3.4.5.
 */
final class DkimCanonicalizerTest extends TestCase
{
    private const EXAMPLE_BODY = " C \r\nD \t E\r\n\r\n\r\n";

    public function testRelaxedBodyExample(): void
    {
        self::assertSame(" C\r\nD E\r\n", DkimCanonicalizer::body(self::EXAMPLE_BODY, 'relaxed'));
    }

    public function testSimpleBodyExample(): void
    {
        self::assertSame(" C \r\nD \t E\r\n", DkimCanonicalizer::body(self::EXAMPLE_BODY, 'simple'));
    }

    public function testRelaxedHeaderExamples(): void
    {
        self::assertSame('a:X', DkimCanonicalizer::header('A: X', 'relaxed'));
        self::assertSame("b:Y Z", DkimCanonicalizer::header("B  : Y\t\r\n\tZ  ", 'relaxed'));
    }

    public function testSimpleHeaderIsUntouched(): void
    {
        self::assertSame("B  : Y\t\r\n\tZ  ", DkimCanonicalizer::header("B  : Y\t\r\n\tZ  ", 'simple'));
    }

    public function testEmptyBodyCanonicalForms(): void
    {
        self::assertSame("\r\n", DkimCanonicalizer::body('', 'simple'));
        self::assertSame('', DkimCanonicalizer::body('', 'relaxed'));
        self::assertSame('', DkimCanonicalizer::body("\r\n\r\n  \r\n", 'relaxed'));
    }

    public function testBodyWithoutFinalCrlfGetsOne(): void
    {
        self::assertSame("abc\r\n", DkimCanonicalizer::body('abc', 'simple'));
        self::assertSame("abc\r\n", DkimCanonicalizer::body('abc  ', 'relaxed'));
    }

    public function testRelaxedDropsBlanksAtTheEndOfEveryLineAndOfTheBody(): void
    {
        self::assertSame("a\r\n\r\nb\r\n", DkimCanonicalizer::body("a \t\r\n \t \r\nb \t", 'relaxed'));
        self::assertSame("a b c\r\n", DkimCanonicalizer::body("a  b\t\tc", 'relaxed'));
    }

    public function testHugeRunOfBlanksIsHandledLinearly(): void
    {
        $body = str_repeat(' ', 2_000_000) . "x\r\n" . str_repeat("\t", 100_000);
        $start = microtime(true);
        self::assertSame(" x\r\n", DkimCanonicalizer::body($body, 'relaxed'));
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    public function testHugeRunOfBlankLinesIsHandledLinearly(): void
    {
        $body = "x\r\n" . str_repeat("\r\n", 500_000);
        $start = microtime(true);
        self::assertSame("x\r\n", DkimCanonicalizer::body($body, 'simple'));
        self::assertLessThan(2.0, microtime(true) - $start);
    }
}
