<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\EmailNotice\Dkim;

use MyInvoice\Service\Bank\EmailNotice\Dkim\DnsDkimKeyResolver;
use PHPUnit\Framework\TestCase;

/**
 * The network lookup itself is not exercised (tests never touch DNS): a fake lookup stands in for
 * `dns_get_record()`. Covers how answers become key records and the per-object memo.
 */
final class DnsDkimKeyResolverTest extends TestCase
{
    public function testMultiStringRecordIsJoinedWithoutSeparator(): void
    {
        $first = 'v=DKIM1; k=rsa; p=' . str_repeat('A', 200);
        $second = str_repeat('B', 100) . 'QAB';
        $records = DnsDkimKeyResolver::recordsFromAnswers([[
            'host' => 'ab._domainkey.airbank.cz', 'class' => 'IN', 'ttl' => 300, 'type' => 'TXT',
            'txt' => $first . $second, 'entries' => [$first, $second],
        ]]);
        self::assertSame([$first . $second], $records);
    }

    public function testSingleStringRecord(): void
    {
        $txt = 'v=DKIM1; k=rsa; p=MIGf';
        self::assertSame([$txt], DnsDkimKeyResolver::recordsFromAnswers([['type' => 'TXT', 'txt' => $txt, 'entries' => [$txt]]]));
        self::assertSame([$txt], DnsDkimKeyResolver::recordsFromAnswers([['type' => 'TXT', 'txt' => $txt]]));
    }

    public function testNonTxtAnswersAndEmptyRecordsAreSkipped(): void
    {
        $records = DnsDkimKeyResolver::recordsFromAnswers([
            ['type' => 'CNAME', 'target' => 'other.example'],
            ['type' => 'TXT', 'txt' => '', 'entries' => ['']],
            ['type' => 'TXT', 'txt' => 'v=DKIM1; p=abc', 'entries' => ['v=DKIM1; p=abc']],
        ]);
        self::assertSame(['v=DKIM1; p=abc'], $records);
    }

    public function testNumberAndSizeOfRecordsAreBounded(): void
    {
        $answers = [];
        for ($i = 0; $i < 10; $i++) {
            $answers[] = ['type' => 'TXT', 'txt' => "v=DKIM1; p=$i", 'entries' => ["v=DKIM1; p=$i"]];
        }
        self::assertCount(4, DnsDkimKeyResolver::recordsFromAnswers($answers));
        $huge = str_repeat('x', 5000);
        self::assertSame([], DnsDkimKeyResolver::recordsFromAnswers([['type' => 'TXT', 'txt' => $huge, 'entries' => [$huge]]]));
    }

    /**
     * @param list<array<int,array<string,mixed>>|false|\Throwable> $answers one per real lookup
     * @return array{0:DnsDkimKeyResolver,1:\ArrayObject<int,string>} resolver and the names it looked up
     */
    private function resolver(array $answers): array
    {
        $names = new \ArrayObject();
        $resolver = new DnsDkimKeyResolver(static function (string $name) use (&$answers, $names) {
            $names[] = $name;
            $answer = array_shift($answers);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }
            return $answer;
        });
        return [$resolver, $names];
    }

    public function testLooksUpTheDomainKeyNameAndMemoizesTheAnswer(): void
    {
        [$resolver, $names] = $this->resolver([[['type' => 'TXT', 'txt' => 'v=DKIM1; p=abc', 'entries' => ['v=DKIM1; p=abc']]]]);

        self::assertSame(['v=DKIM1; p=abc'], $resolver->txtRecords('ab', 'airbank.cz'));
        self::assertSame(['v=DKIM1; p=abc'], $resolver->txtRecords('ab', 'airbank.cz'));
        self::assertSame(['ab._domainkey.airbank.cz.'], $names->getArrayCopy(), 'fifty notices of one bank cost one lookup');
    }

    public function testFailureIsTemporaryAndAlsoMemoized(): void
    {
        [$resolver, $names] = $this->resolver([false]);

        self::assertNull($resolver->txtRecords('ab', 'airbank.cz'));
        self::assertNull($resolver->txtRecords('ab', 'airbank.cz'), 'a scan does not wait for the same time-out fifty times');
        self::assertCount(1, $names);
    }

    public function testMissingNameIsAnEmptyListNotAFailure(): void
    {
        [$resolver] = $this->resolver([[]]);
        self::assertSame([], $resolver->txtRecords('nope', 'airbank.cz'));
    }

    public function testExceptionFromTheResolverIsAFailureNotACrash(): void
    {
        [$resolver] = $this->resolver([new \ValueError('boom')]);
        self::assertNull($resolver->txtRecords('ab', 'airbank.cz'));
    }

    public function testDifferentSelectorsAreLookedUpSeparately(): void
    {
        [$resolver, $names] = $this->resolver([[], []]);
        $resolver->txtRecords('a', 'airbank.cz');
        $resolver->txtRecords('b', 'airbank.cz');
        self::assertCount(2, $names);
    }
}
