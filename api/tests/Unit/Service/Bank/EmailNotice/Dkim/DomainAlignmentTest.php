<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice\Dkim;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DomainAlignment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DomainAlignmentTest extends TestCase
{
    /**
     * @return array<string,array{string,string,bool}>
     */
    public static function pairs(): array
    {
        return [
            'equal' => ['airbank.cz', 'airbank.cz', true],
            'case and trailing dot' => ['AirBank.cz.', 'airbank.cz', true],
            'signing subdomain' => ['mail.airbank.cz', 'airbank.cz', true],
            'from subdomain' => ['airbank.cz', 'info.airbank.cz', true],
            'unrelated' => ['evil.example', 'airbank.cz', false],
            'suffix lookalike' => ['notairbank.cz', 'airbank.cz', false],
            'prefix lookalike' => ['airbank.cz.evil.example', 'airbank.cz', false],
            'siblings' => ['a.airbank.cz', 'b.airbank.cz', false],
            'siblings under a public suffix' => ['a.co.uk', 'b.co.uk', false],
            'bare TLD is no parent' => ['mail.cz', 'cz', false],
            'empty' => ['', 'airbank.cz', false],
            'both empty' => ['', '', false],
        ];
    }

    #[DataProvider('pairs')]
    public function testAlignment(string $a, string $b, bool $expected): void
    {
        self::assertSame($expected, DomainAlignment::aligns($a, $b));
        self::assertSame($expected, DomainAlignment::aligns($b, $a), 'symmetric');
    }
}
