<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Onboarding;

use MyInvoice\Action\Onboarding\TourStateAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Onboarding\UserOnboardingService;
use MyInvoice\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[AllowMockObjectsWithoutExpectations]
final class TourStateActionTest extends TestCase
{
    public function testReturnsTheStateOfTheSignedInUser(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([17]);
        $statement->method('fetch')->willReturn(['tour_status' => 'completed', 'tour_finished_at' => '2026-11-11 08:00:00']);
        $response = $this->call('GET', null, $this->database($statement));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'completed', 'finished_at' => '2026-11-11T08:00:00Z'], $this->json($response));
    }

    public function testRecordsHowTheTourEnded(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['skipped', '2026-11-11 08:00:00', 17]);
        $response = $this->call('PUT', ['status' => 'skipped'], $this->database($statement));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'skipped', 'finished_at' => '2026-11-11T08:00:00Z'], $this->json($response));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidBodies(): iterable
    {
        yield 'missing status' => [[]];
        yield 'unknown status' => [['status' => 'pending']];
        yield 'no body at all' => [null];
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsAnInvalidBodyWithoutWriting(mixed $body): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $response = $this->call('PUT', $body, $db);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->json($response)['error']['code'] ?? null);
    }

    public function testBothEndpointsRequireASignedInUser(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        self::assertSame(401, $this->call('GET', null, $db, null)->getStatusCode());
        self::assertSame(401, $this->call('PUT', ['status' => 'completed'], $db, null)->getStatusCode());
    }

    private function database(\PDOStatement $statement): Connection
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $db = $this->createMock(Connection::class);
        $db->method('hasColumn')->willReturn(true);
        $db->method('pdo')->willReturn($pdo);

        return $db;
    }

    private function call(string $method, mixed $body, Connection $db, ?int $userId = 17): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/onboarding/tour')->withParsedBody($body);
        if ($userId !== null) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'accountant']);
        }
        $action = new TourStateAction(new UserOnboardingService($db, new FixedClock('2026-11-11T08:00:00+00:00')));

        return $method === 'GET'
            ? $action->get($request, (new ResponseFactory())->createResponse())
            : $action->finish($request, (new ResponseFactory())->createResponse());
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
