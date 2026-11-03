<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\NavFavoritesService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class NavFavoritesServiceTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function invalidLists(): iterable
    {
        yield 'not an array' => ['/invoices'];
        yield 'object instead of list' => [['a' => '/invoices']];
        yield 'non-string item' => [[42]];
        yield 'relative path' => [['invoices']];
        yield 'absolute url' => [['https://example.invalid/']];
        yield 'protocol-relative url' => [['//example.invalid/']];
        yield 'backslash host' => [['/\\example.invalid']];
        yield 'whitespace' => [['/invoices list']];
        yield 'control character' => [["/invoices\n"]];
        yield 'too long' => [['/' . str_repeat('a', NavFavoritesService::MAX_PATH_LENGTH)]];
        yield 'too many' => [array_map(
            static fn (int $i): string => '/item-' . $i,
            range(0, NavFavoritesService::MAX_ITEMS),
        )];
    }

    #[DataProvider('invalidLists')]
    public function testUpdateRejectsAnythingButInternalPathsBeforeDatabaseWrite(mixed $paths): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $this->expectException(\InvalidArgumentException::class);
        (new NavFavoritesService($db))->update(17, $paths);
    }

    public function testGetDecodesStoredListAndSkipsNonStrings(): void
    {
        $db = $this->connection(true, '["/invoices",7,null,"/stats"]', 17);

        self::assertSame(['/invoices', '/stats'], (new NavFavoritesService($db))->get(17));
    }

    public function testGetToleratesMissingOrMalformedValue(): void
    {
        self::assertSame([], (new NavFavoritesService($this->connection(true, null, 17)))->get(17));
        self::assertSame([], (new NavFavoritesService($this->connection(true, '{broken', 17)))->get(17));
        self::assertSame([], (new NavFavoritesService($this->connection(true, '"/invoices"', 17)))->get(17));
    }

    public function testGetReturnsEmptyListBeforeMigrationRuns(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('hasColumn')->with('users', 'nav_favorites')->willReturn(false);
        $db->expects(self::never())->method('pdo');

        self::assertSame([], (new NavFavoritesService($db))->get(17));
    }

    public function testUpdateStoresListInClickOrderWithoutDuplicates(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())
            ->method('execute')
            ->with(['["/stats","/invoices","/clients?role=vendors"]', 17]);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $items = (new NavFavoritesService($db))
            ->update(17, ['/stats', '/invoices', '/stats', '/clients?role=vendors']);

        self::assertSame(['/stats', '/invoices', '/clients?role=vendors'], $items);
    }

    public function testUpdateClearsColumnForEmptyList(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([null, 17]);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        self::assertSame([], (new NavFavoritesService($db))->update(17, []));
    }

    private function connection(bool $hasColumn, ?string $stored, int $userId): Connection
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([$userId]);
        $statement->method('fetchColumn')->willReturn($stored ?? null);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('hasColumn')->with('users', 'nav_favorites')->willReturn($hasColumn);
        $db->method('pdo')->willReturn($pdo);

        return $db;
    }
}
