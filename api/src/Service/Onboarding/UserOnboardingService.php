<?php

declare(strict_types=1);

namespace MyInvoice\Service\Onboarding;

use MyInvoice\Infrastructure\Database\Connection;
use Psr\Clock\ClockInterface;

/**
 * Per-user state of the guided tour ("Prohlídka aplikace"). The web client starts the tour on login while the
 * state is `pending` and records how it ended (`completed` / `skipped`). A restart from the menu is purely a client
 * action: the stored outcome is simply overwritten when that run ends.
 */
final class UserOnboardingService
{
    public const PENDING = 'pending';
    public const OUTCOMES = ['completed', 'skipped'];

    public function __construct(
        private readonly Connection $db,
        private readonly ClockInterface $clock,
    ) {}

    /** @return array{status: 'pending'|'completed'|'skipped', finished_at: ?string} */
    public function tourState(int $userId): array
    {
        // Before the migration runs there is nowhere to remember the outcome, so the tour must not nag on every login.
        if (!$this->db->hasColumn('users', 'tour_status')) {
            return ['status' => 'completed', 'finished_at' => null];
        }
        $stmt = $this->db->pdo()->prepare('SELECT tour_status, tour_finished_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return self::shape($row === false ? null : $row['tour_status'], $row === false ? null : $row['tour_finished_at']);
    }

    /**
     * @return array{status: 'pending'|'completed'|'skipped', finished_at: ?string}
     * @throws \InvalidArgumentException for an outcome other than completed / skipped
     */
    public function finishTour(int $userId, mixed $outcome): array
    {
        if (!is_string($outcome) || !in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('status musí být „completed" nebo „skipped".');
        }
        $finishedAt = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $this->db->pdo()
            ->prepare('UPDATE users SET tour_status = ?, tour_finished_at = ? WHERE id = ? AND is_active = 1')
            ->execute([$outcome, $finishedAt, $userId]);

        return self::shape($outcome, $finishedAt);
    }

    /** @return array{status: 'pending'|'completed'|'skipped', finished_at: ?string} */
    private static function shape(mixed $status, mixed $finishedAt): array
    {
        if (!is_string($status) || !in_array($status, self::OUTCOMES, true)) {
            return ['status' => self::PENDING, 'finished_at' => null];
        }
        $at = is_string($finishedAt) && $finishedAt !== ''
            ? (new \DateTimeImmutable($finishedAt, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z')
            : null;

        return ['status' => $status, 'finished_at' => $at];
    }
}
