<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Service\Auth\TrustedDeviceService;
use MyInvoice\Service\Auth\UserInviteService;
use MyInvoice\Tests\Support\InviteFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Token lifecycle of the user invitation: issue, re-issue, expiry, single use.
 * Synthetic users only (see InviteFixtures); needs the database (cfg.php + migration 9013).
 */
#[Group('integration')]
final class UserInviteServiceTest extends TestCase
{
    use InviteFixtures;

    private UserInviteService $invites;

    protected function setUp(): void
    {
        $this->bootApp();
        $this->invites = $this->c->get(UserInviteService::class);
    }

    public function testIssuedTokenIsStoredOnlyAsSha256Hash(): void
    {
        $user = $this->makeUser();
        $invite = $this->invites->issue($user['id'], null);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $invite['token'], '32 random bytes as hex');
        $stored = $this->db->pdo()->prepare('SELECT token_hash FROM user_invites WHERE user_id = ?');
        $stored->execute([$user['id']]);
        self::assertSame([hash('sha256', $invite['token'])], $stored->fetchAll(\PDO::FETCH_COLUMN));
        self::assertStringNotContainsString($invite['token'], hash('sha256', $invite['token']));
    }

    public function testTokensAreUnique(): void
    {
        $user = $this->makeUser();
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $tokens[] = $this->invites->issue($user['id'], null)['token'];
        }
        self::assertCount(5, array_unique($tokens));
    }

    public function testInviteIsValidForSevenDays(): void
    {
        $user = $this->makeUser();
        $this->invites->issue($user['id'], null);

        $seconds = (int) $this->db->pdo()->query(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), expires_at) FROM user_invites ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        self::assertSame(7, UserInviteService::TTL_DAYS);
        self::assertEqualsWithDelta(7 * 86400, $seconds, 5);
    }

    public function testIssuingANewInviteInvalidatesThePreviousUnusedOnes(): void
    {
        $user = $this->makeUser();
        $first = $this->invites->issue($user['id'], null)['token'];
        $second = $this->invites->issue($user['id'], null)['token'];
        $third = $this->invites->issue($user['id'], null)['token'];

        self::assertNull($this->invites->peek($first));
        self::assertNull($this->invites->peek($second));
        self::assertSame($user['id'], (int) $this->invites->peek($third)['id']);
        self::assertNull($this->invites->accept($first, 'irrelevant-hash'), 'A superseded link cannot be spent.');
    }

    public function testInvitesOfOtherUsersAreUntouched(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser();
        $aliceToken = $this->invites->issue($alice['id'], null)['token'];
        $this->invites->issue($bob['id'], null);

        self::assertNotNull($this->invites->peek($aliceToken));
    }

    public function testExpiredInviteIsRejected(): void
    {
        $user = $this->makeUser();
        $token = $this->invites->issue($user['id'], null)['token'];
        $this->db->pdo()->prepare('UPDATE user_invites SET expires_at = NOW() - INTERVAL 1 SECOND WHERE user_id = ?')
            ->execute([$user['id']]);

        self::assertNull($this->invites->peek($token));
        self::assertNull($this->invites->accept($token, 'irrelevant-hash'));
    }

    public function testInviteWorksOnceAndSetsThePassword(): void
    {
        $user = $this->makeUser(password: null);
        $token = $this->invites->issue($user['id'], null)['token'];
        $hash = password_hash('does-not-matter', PASSWORD_BCRYPT);

        $accepted = $this->invites->accept($token, $hash);
        self::assertNotNull($accepted);
        self::assertSame($user['id'], (int) $accepted['id']);
        self::assertSame($hash, $this->passwordHash($user['id']));

        self::assertNull($this->invites->accept($token, password_hash('second-try', PASSWORD_BCRYPT)), 'Single use.');
        self::assertNull($this->invites->peek($token));
        self::assertSame($hash, $this->passwordHash($user['id']), 'The second attempt must not change the password.');
    }

    public function testAcceptingDropsTrustedDevicesAndPendingEmailCodes(): void
    {
        $user = $this->makeUser();
        $this->c->get(TrustedDeviceService::class)->issue($user['id'], self::TEST_IP, 'PHPUnit');
        $this->db->pdo()->prepare(
            'INSERT INTO login_otps (user_id, code_hash, expires_at, ip) VALUES (?, ?, NOW() + INTERVAL 5 MINUTE, ?)'
        )->execute([$user['id'], str_repeat('a', 64), '']);
        $token = $this->invites->issue($user['id'], null)['token'];

        $this->invites->accept($token, password_hash('x', PASSWORD_BCRYPT));

        foreach (['trusted_devices', 'login_otps'] as $table) {
            $count = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ?");
            $count->execute([$user['id']]);
            self::assertSame(0, (int) $count->fetchColumn(), $table);
        }
    }

    public function testInactiveUserCannotUseAnInvite(): void
    {
        $user = $this->makeUser();
        $token = $this->invites->issue($user['id'], null)['token'];
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$user['id']]);

        self::assertNull($this->invites->peek($token));
        self::assertNull($this->invites->accept($token, 'irrelevant-hash'));
    }

    public function testMalformedTokensNeverReachTheDatabase(): void
    {
        foreach (['', 'abc', str_repeat('z', 64), str_repeat('a', 63), str_repeat('A', 64), "x' OR '1'='1"] as $bad) {
            self::assertNull($this->invites->peek($bad), $bad);
            self::assertNull($this->invites->accept($bad, 'h'), $bad);
        }
    }

    public function testPendingExpiriesListsOnlyLiveInvites(): void
    {
        $live = $this->makeUser();
        $used = $this->makeUser();
        $this->invites->issue($live['id'], null);
        $usedToken = $this->invites->issue($used['id'], null)['token'];
        $this->invites->accept($usedToken, password_hash('x', PASSWORD_BCRYPT));

        $pending = $this->invites->pendingExpiries();
        self::assertArrayHasKey($live['id'], $pending);
        self::assertArrayNotHasKey($used['id'], $pending);
    }

    public function testLinkPointsAtTheInvitePage(): void
    {
        $link = $this->invites->link(str_repeat('a', 64));
        self::assertStringEndsWith('/invite?token=' . str_repeat('a', 64), $link);
    }

    private function passwordHash(int $userId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string) $stmt->fetchColumn();
    }
}
