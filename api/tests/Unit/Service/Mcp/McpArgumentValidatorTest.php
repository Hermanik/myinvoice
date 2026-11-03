<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mcp;

use MyInvoice\Service\Mcp\McpArgumentValidator;
use PHPUnit\Framework\TestCase;

final class McpArgumentValidatorTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'amount' => ['type' => 'number', 'maximum' => 100],
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 5],
            'when' => ['type' => 'string', 'format' => 'date'],
            'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
            'flag' => ['type' => 'boolean'],
            'either' => ['type' => ['string', 'array'], 'items' => ['type' => 'string']],
            'lines' => [
                'type' => 'array', 'minItems' => 1, 'maxItems' => 2,
                'items' => ['type' => 'object', 'properties' => ['q' => ['type' => 'number']], 'required' => ['q'], 'additionalProperties' => false],
            ],
        ],
        'required' => ['id'],
        'additionalProperties' => false,
    ];

    public function testValidArgumentsPass(): void
    {
        self::assertSame([], McpArgumentValidator::validate(self::SCHEMA, [
            'id' => 3, 'amount' => 99.5, 'name' => 'abc', 'when' => '2026-02-28', 'kind' => 'a', 'flag' => false,
            'either' => ['x', 'y'], 'lines' => [['q' => 1.5]],
        ]));
    }

    public function testIntegralFloatCountsAsInteger(): void
    {
        self::assertSame([], McpArgumentValidator::validate(self::SCHEMA, ['id' => 5.0]));
        self::assertNotSame([], McpArgumentValidator::validate(self::SCHEMA, ['id' => 5.5]));
    }

    /** @return iterable<string, array{0: array<string,mixed>, 1: string}> */
    public static function invalidCases(): iterable
    {
        yield 'missing required' => [[], 'Chybí povinný argument `id`'];
        yield 'null required' => [['id' => null], 'Chybí povinný argument `id`'];
        yield 'wrong type' => [['id' => '3'], 'očekávaný typ integer'];
        yield 'bool is not integer' => [['id' => true], 'očekávaný typ integer'];
        yield 'minimum' => [['id' => 0], 'minimální hodnota'];
        yield 'maximum' => [['id' => 1, 'amount' => 101], 'maximální hodnota'];
        yield 'min length' => [['id' => 1, 'name' => 'a'], 'minimální délka'];
        yield 'max length' => [['id' => 1, 'name' => 'abcdef'], 'maximální délka'];
        yield 'bad date shape' => [['id' => 1, 'when' => '3.2.2026'], 'YYYY-MM-DD'];
        yield 'impossible date' => [['id' => 1, 'when' => '2026-02-30'], 'YYYY-MM-DD'];
        yield 'enum' => [['id' => 1, 'kind' => 'z'], 'povolené hodnoty'];
        yield 'string as boolean' => [['id' => 1, 'flag' => 'true'], 'očekávaný typ boolean'];
        yield 'unknown property' => [['id' => 1, 'extra' => 1], 'Neznámý argument `extra`'];
        yield 'union item type' => [['id' => 1, 'either' => [1]], '`either[0]`'];
        yield 'too few items' => [['id' => 1, 'lines' => []], 'minimální počet'];
        yield 'too many items' => [['id' => 1, 'lines' => [['q' => 1], ['q' => 2], ['q' => 3]]], 'maximální počet'];
        yield 'nested required' => [['id' => 1, 'lines' => [[]]], 'lines[0].q'];
        yield 'nested unknown' => [['id' => 1, 'lines' => [['q' => 1, 'z' => 1]]], 'lines[0].z'];
    }

    /** @param array<string,mixed> $arguments */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCases')]
    public function testInvalidArgumentsAreReported(array $arguments, string $expected): void
    {
        $problems = McpArgumentValidator::validate(self::SCHEMA, $arguments);

        self::assertNotSame([], $problems);
        self::assertStringContainsString($expected, implode(' ', $problems));
    }

    public function testEmptyArgumentsAreAnEmptyObject(): void
    {
        $schema = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];

        self::assertSame([], McpArgumentValidator::validate($schema, []));
        self::assertNotSame([], McpArgumentValidator::validate($schema, ['x' => 1]));
    }
}
