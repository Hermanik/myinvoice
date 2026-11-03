<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Databox;

use MyInvoice\Service\Databox\DataboxId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataboxIdTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function ids(): array
    {
        return [
            'worked example of the specification (aydaad + k)' => ['aydaadk', true],
            'the same with a wrong check character' => ['aydaadj', false],
            'fictitious PFO box' => ['tpbxbx7', true],
            'fictitious FO box' => ['vqt3fkw', true],
            'upper case is accepted' => ['TPBXBX7', true],
            'too short' => ['tpbxbx', false],
            'too long' => ['tpbxbx77', false],
            'letter o is not in the alphabet' => ['tpbxbxo', false],
            'digit 1 is not in the alphabet' => ['tpb1bx7', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('ids')]
    public function testCheckDigit(string $id, bool $valid): void
    {
        self::assertSame($valid, DataboxId::isValid($id));
    }

    public function testNormalize(): void
    {
        self::assertSame('tpbxbx7', DataboxId::normalize("  TPBXBX7 \n"));
    }
}
