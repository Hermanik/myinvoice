<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Tax;

use MyInvoice\Service\Tax\FiledReturnChain;
use MyInvoice\Service\Tax\TaxFilingException;
use PHPUnit\Framework\TestCase;

/** Which of the filed returns of a period are in force: a regular / corrective return replaces, a supplementary one adds. */
final class FiledReturnChainTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function row(int $id, string $form, string $formCode = 'dphdp3', array $summary = []): array
    {
        return ['id' => $id, 'form_code' => $formCode, 'submission_form' => $form, 'summary' => $summary];
    }

    /** @param list<array<string,mixed>> $rows */
    private static function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }

    public function testReturnsInForceStartAtTheLastRegularOrCorrectiveReturn(): void
    {
        self::assertSame([1], self::ids((new FiledReturnChain([self::row(1, 'B')]))->inForce()));
        self::assertSame([1, 2, 3], self::ids((new FiledReturnChain([self::row(1, 'B'), self::row(2, 'D'), self::row(3, 'D')]))->inForce()), 'supplementary returns add up');
        self::assertSame([3, 4], self::ids((new FiledReturnChain([self::row(1, 'B'), self::row(2, 'D'), self::row(3, 'O'), self::row(4, 'D')]))->inForce()), 'a corrective return replaces everything before it');
        self::assertSame([1, 2], self::ids((new FiledReturnChain([self::row(1, 'D'), self::row(2, 'D')]))->inForce()), 'regular return filed elsewhere');
        self::assertSame([], (new FiledReturnChain([]))->inForce());
    }

    public function testBaseSupplementsAndLast(): void
    {
        $chain = new FiledReturnChain([self::row(1, 'B'), self::row(2, 'D'), self::row(3, 'O'), self::row(4, 'D'), self::row(5, 'D')]);

        self::assertSame(3, $chain->base()['id']);
        self::assertSame([4, 5], self::ids($chain->supplements()));
        self::assertSame(5, $chain->last()['id']);
        self::assertTrue($chain->isInForce(4));
        self::assertFalse($chain->isInForce(2), 'superseded by the corrective return');
        self::assertNull((new FiledReturnChain([]))->base());
        self::assertNull((new FiledReturnChain([]))->last());
    }

    public function testOtherTypesNeverHaveSupplementaryReturns(): void
    {
        // A KH 'D' does not exist; and a KH 'N' replaces the earlier KH like any non-supplementary return.
        $chain = new FiledReturnChain([self::row(1, 'B', 'dphkh1'), self::row(2, 'N', 'dphkh1'), self::row(3, 'V', 'dphkh1')]);

        self::assertSame([3], self::ids($chain->inForce()));
        self::assertSame([], $chain->supplements());
        self::assertFalse(FiledReturnChain::isSupplementary(self::row(1, 'D', 'dphkh1')));
        self::assertTrue(FiledReturnChain::isSupplementary(self::row(1, 'D')));
    }

    public function testSupplementAfter(): void
    {
        $chain = new FiledReturnChain([self::row(1, 'B'), self::row(2, 'D'), self::row(3, 'O')]);

        self::assertTrue($chain->hasSupplementAfter(self::row(1, 'B')));
        self::assertFalse($chain->hasSupplementAfter(self::row(2, 'D')), 'the O after it is not a supplement');
        self::assertFalse($chain->hasSupplementAfter(self::row(3, 'O')));
        self::assertFalse($chain->hasSupplementAfter(self::row(99, 'B')), 'unknown row');
    }

    public function testSupplementMustStandOnTheLastFiledReturn(): void
    {
        $supplement = fn (int $basedOn): array => self::row(9, 'D', 'dphdp3', ['supplementary' => ['based_on' => ['submission_id' => $basedOn]]]);

        (new FiledReturnChain([self::row(1, 'B'), self::row(2, 'D')]))->assertCurrentBaseOf($supplement(2));
        $this->addToAssertionCount(1);

        foreach ([[[self::row(1, 'B'), self::row(2, 'D')], 1], [[], 1], [[self::row(1, 'B')], 0]] as [$filed, $basedOn]) {
            try {
                (new FiledReturnChain($filed))->assertCurrentBaseOf($supplement($basedOn));
                self::fail('stale supplementary return');
            } catch (TaxFilingException $e) {
                self::assertSame('stale_supplementary', $e->errorCode);
                self::assertSame(409, $e->httpStatus);
            }
        }
    }
}
