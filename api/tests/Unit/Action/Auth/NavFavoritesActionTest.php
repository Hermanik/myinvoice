<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Auth;

use MyInvoice\Action\Auth\NavFavoritesAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\NavFavoritesService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class NavFavoritesActionTest extends TestCase
{
    public function testStoresNormalizedListForSignedInUser(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['["/stats","/invoices"]', 17]);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('pdo')->willReturn($pdo);

        $response = $this->call($db, ['items' => ['/stats', '/invoices', '/stats']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['items' => ['/stats', '/invoices']], $this->json($response));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidBodies(): iterable
    {
        yield 'missing items' => [[]];
        yield 'items not a list' => [['items' => '/invoices']];
        yield 'external url' => [['items' => ['https://example.invalid/']]];
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidBodyWithoutWriting(mixed $body): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $response = $this->call($db, $body);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $this->json($response)['error']['code'] ?? null);
    }

    public function testRequiresSignedInUser(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $response = $this->call($db, ['items' => ['/invoices']], null);

        self::assertSame(401, $response->getStatusCode());
    }

    private function call(Connection $db, mixed $body, ?int $userId = 17): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/auth/nav-favorites')
            ->withParsedBody($body);
        if ($userId !== null) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'readonly']);
        }

        return (new NavFavoritesAction(new NavFavoritesService($db)))(
            $request,
            (new ResponseFactory())->createResponse(),
        );
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
