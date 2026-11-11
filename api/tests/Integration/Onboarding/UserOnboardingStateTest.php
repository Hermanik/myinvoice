<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Onboarding;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Onboarding\UserOnboardingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The guided-tour state against the real schema (migration 9014): a new user starts pending, finishing records the
 * outcome per user, and a restart of the tour only overwrites it.
 */
#[Group('integration')]
final class UserOnboardingStateTest extends TestCase
{
    private Connection $db;
    private UserOnboardingService $service;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(UserOnboardingService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('users', 'tour_status')) {
            $this->markTestSkipped('Migrace 9014 (user onboarding) není aplikovaná.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->userIds as $id) {
            $this->db->pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    private function user(string $role): int
    {
        $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, 'Prohlídka Test', ?)"
        )->execute([uniqid('tour-', true) . '@example.invalid', str_repeat('x', 60), $role]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;

        return $id;
    }

    public function testNewUserStartsPendingAndFinishingIsRememberedPerUser(): void
    {
        $accountant = $this->user('accountant');
        $other = $this->user('admin');

        self::assertSame(['status' => 'pending', 'finished_at' => null], $this->service->tourState($accountant));

        $finished = $this->service->finishTour($accountant, 'completed');

        self::assertSame('completed', $finished['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $finished['finished_at']);
        self::assertSame($finished, $this->service->tourState($accountant), 'what was stored is what is read back');
        self::assertSame('pending', $this->service->tourState($other)['status'], 'another user is not affected');
    }

    public function testRestartedTourOverwritesTheEarlierOutcome(): void
    {
        $user = $this->user('readonly');

        $this->service->finishTour($user, 'skipped');
        self::assertSame('skipped', $this->service->tourState($user)['status']);

        $this->service->finishTour($user, 'completed');
        self::assertSame('completed', $this->service->tourState($user)['status']);
    }

    public function testInactiveUserIsNotUpdated(): void
    {
        $user = $this->user('readonly');
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$user]);

        $this->service->finishTour($user, 'completed');

        self::assertSame('pending', $this->service->tourState($user)['status']);
    }
}
