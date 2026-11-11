<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Action\Admin\UserInviteAdminAction;
use MyInvoice\Action\Auth\InviteAction;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\TrustedDeviceService;
use MyInvoice\Tests\Support\InviteFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Admin creates a user without a password, hands out an invitation, the user accepts it and lands
 * signed in. Production runs the legacy `auth.require_totp = true`, so that is the default policy here.
 */
#[Group('integration')]
final class UserInviteFlowTest extends TestCase
{
    use InviteFixtures;

    /** @var array{id:int,email:string,name:string,role:string,locale:string,mfa_mode:string} */
    private array $admin;

    protected function setUp(): void
    {
        $this->bootApp(mfaRequired: true);
        self::assertTrue($this->c->get(MfaPolicyService::class)->isRequired(), 'test premise: MFA is required');
        $this->admin = $this->makeAdmin();
    }

    // ── Admin side ──────────────────────────────────────────────────────────────────────────

    public function testAdminCreatesAUserWithoutAPasswordAndGetsAnInviteLink(): void
    {
        $created = $this->createUserWithoutPassword('accountant', 'email');
        self::assertSame('email', $created['mfa_mode']);
        self::assertFalse($created['invite_pending'] ?? false);

        $invite = $this->inviteByLink($created['id']);
        self::assertStringContainsString('/invite?token=', $invite['invite_link']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->tokenFromLink($invite['invite_link']));
        self::assertFalse($invite['email_sent']);

        $list = $this->json($this->c->get(UserAdminAction::class)->list(
            $this->request('GET', '/api/admin/users', actor: $this->admin),
            $this->response(),
        ));
        $row = array_values(array_filter($list, static fn (array $u) => $u['id'] === $created['id']))[0];
        self::assertTrue($row['invite_pending']);
        self::assertNotNull($row['invite_expires_at']);
    }

    public function testPasswordlessUserCannotLogInWithAnyPasswordUntilTheInviteIsAccepted(): void
    {
        $created = $this->createUserWithoutPassword('accountant', 'email');
        $response = $this->c->get(\MyInvoice\Action\Auth\LoginAction::class)(
            $this->request('POST', '/api/auth/login', ['email' => $created['email'], 'password' => self::TEST_PASSWORD]),
            $this->response(),
        );
        self::assertSame('invalid_credentials', $this->errorCode($response));
    }

    public function testInviteByEmailUsesTheUserInviteTemplateAndDoesNotReturnTheLink(): void
    {
        $created = $this->createUserWithoutPassword('accountant', 'email');
        $response = $this->invite($created['id'], ['send_email' => true]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertTrue($body['email_sent']);
        self::assertArrayNotHasKey('invite_link', $body);
        self::assertStringNotContainsString('token=', (string) $response->getBody());

        self::assertCount(1, $this->sentMails);
        $mail = $this->sentMails[0];
        self::assertSame('user_invite', $mail['template']);
        self::assertSame([$created['email']], $mail['to']);
        self::assertSame('cs', $mail['locale']);
        self::assertStringContainsString('/invite?token=', $mail['vars']['inviteLink']);
        self::assertSame($this->admin['name'], $mail['vars']['invitedBy']);
        self::assertTrue($mail['vars']['emailCodes'], 'The mail tells an email-mode user no authenticator is needed.');
        // The mailed link is a live invite.
        self::assertNotNull($this->c->get(\MyInvoice\Service\Auth\UserInviteService::class)
            ->peek($this->tokenFromLink($mail['vars']['inviteLink'])));
    }

    public function testFailedMailDeliveryLeavesNoLiveToken(): void
    {
        $created = $this->createUserWithoutPassword('accountant', 'email');
        $this->mailFails = true;

        $response = $this->invite($created['id'], ['send_email' => true]);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('invite_mail_failed', $this->errorCode($response));
        self::assertArrayNotHasKey($created['id'], $this->c->get(\MyInvoice\Service\Auth\UserInviteService::class)->pendingExpiries());
        self::assertNotContains('user.invited', $this->loggedActions($created['id']));
    }

    public function testExistingUserCanBeReInvitedAndTheOldLinkDies(): void
    {
        $existing = $this->makeUser('accountant', 'email', password: null); // random unknown password, like production user 2
        $first = $this->inviteByLink($existing['id'])['invite_link'];
        $second = $this->inviteByLink($existing['id'])['invite_link'];

        self::assertNotSame($first, $second);
        self::assertSame(410, $this->show($this->tokenFromLink($first))->getStatusCode());
        self::assertSame(200, $this->show($this->tokenFromLink($second))->getStatusCode());
    }

    public function testOnlyAdminsCanInvite(): void
    {
        $target = $this->makeUser('readonly');
        $accountant = $this->makeUser('accountant');
        $response = $this->c->get(UserInviteAdminAction::class)->issue(
            $this->request('POST', "/api/admin/users/{$target['id']}/invite", ['send_email' => false], $accountant),
            $this->response(),
            ['id' => (string) $target['id']],
        );
        self::assertSame(403, $response->getStatusCode());
    }

    public function testInactiveAndUnknownUsersCannotBeInvited(): void
    {
        $inactive = $this->makeUser('accountant');
        $this->db->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$inactive['id']]);

        self::assertSame(409, $this->invite($inactive['id'], ['send_email' => false])->getStatusCode());
        self::assertSame(404, $this->invite(2147483000, ['send_email' => false])->getStatusCode());
    }

    // ── Invite page ─────────────────────────────────────────────────────────────────────────

    public function testInvitePageGreetsTheUserAndStatesThePasswordRules(): void
    {
        $created = $this->createUserWithoutPassword('accountant', 'email');
        $token = $this->tokenFromLink($this->inviteByLink($created['id'])['invite_link']);

        $response = $this->show($token);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame($created['name'], $body['name']);
        self::assertSame(PasswordHasher::MIN_LENGTH, $body['password_min_length']);
        self::assertSame(PasswordHasher::MAX_LENGTH, $body['password_max_length']);
        self::assertTrue($body['email_codes']);
        self::assertArrayNotHasKey('email', $body, 'The page must not leak the address.');
    }

    /** @return iterable<string,array{string}> */
    public static function deadTokens(): iterable
    {
        yield 'unknown' => [str_repeat('a', 64)];
        yield 'malformed' => ['not-a-token'];
        yield 'empty' => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deadTokens')]
    public function testDeadLinkAnswersGoneWithTheFriendlyMessage(string $token): void
    {
        $response = $this->show($token);
        self::assertSame(410, $response->getStatusCode());
        self::assertSame('invite_invalid', $this->errorCode($response));
        self::assertSame(
            'Odkaz už neplatí, požádejte správce o nový.',
            $this->json($response)['error']['message'],
        );
    }

    public function testExpiredAndUsedLinksLookIdenticalToUnknownOnes(): void
    {
        $user = $this->makeUser('accountant');
        $expired = $this->tokenFromLink($this->inviteByLink($user['id'])['invite_link']);
        $this->db->pdo()->prepare('UPDATE user_invites SET expires_at = NOW() - INTERVAL 1 SECOND WHERE user_id = ?')->execute([$user['id']]);
        $used = $this->tokenFromLink($this->inviteByLink($user['id'])['invite_link']);
        $this->accept($used, 'Brand-new-password-123');

        $unknown = $this->show(str_repeat('b', 64));
        foreach ([$expired, $used] as $token) {
            $response = $this->show($token);
            self::assertSame($unknown->getStatusCode(), $response->getStatusCode());
            self::assertSame((string) $unknown->getBody(), (string) $response->getBody());
        }
    }

    public function testDeadLinksAreThrottledPerIp(): void
    {
        $locked = false;
        for ($i = 0; $i < 40 && !$locked; $i++) {
            $locked = $this->show(bin2hex(random_bytes(32)))->getStatusCode() === 429;
        }
        self::assertTrue($locked, 'Repeated dead-link hits from one IP must end in 429.');
        // Even a valid link is refused while the bucket is locked, the same as login behaves.
        $user = $this->makeUser('accountant');
        $token = $this->tokenFromLink($this->inviteByLink($user['id'])['invite_link']);
        self::assertSame(429, $this->show($token)->getStatusCode());
        self::assertSame(429, $this->accept($token, 'Brand-new-password-123')->getStatusCode());
    }

    // ── Acceptance ──────────────────────────────────────────────────────────────────────────

    public function testShortOrMismatchedPasswordIsRejectedWithoutBurningTheLink(): void
    {
        $created = $this->createUserWithoutPassword('accountant', 'email');
        $token = $this->tokenFromLink($this->inviteByLink($created['id'])['invite_link']);

        $short = $this->accept($token, 'short');
        self::assertSame(400, $short->getStatusCode());
        self::assertSame('Heslo musí mít alespoň 12 znaků.', $this->json($short)['error']['message']);

        $mismatch = $this->accept($token, 'Brand-new-password-123', 'Different-password-123');
        self::assertSame(400, $mismatch->getStatusCode());

        self::assertSame(200, $this->show($token)->getStatusCode(), 'The link must survive validation errors.');
    }

    public function testAcceptingSetsThePasswordAndSignsAnEmailModeUserInWithoutAnExtraCode(): void
    {
        $created = $this->createUserWithoutPassword('accountant', 'email');
        $token = $this->tokenFromLink($this->inviteByLink($created['id'])['invite_link']);

        $response = $this->accept($token, 'Brand-new-password-123');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame($created['id'], $body['user']['id']);
        self::assertSame('email', $body['user']['mfa_mode']);
        self::assertFalse($body['user']['must_setup_mfa'], 'No forced TOTP/passkey enrolment for an email-mode user.');
        self::assertFalse($body['user']['must_setup_totp'], 'Not even under the legacy require_totp policy.');
        self::assertNotEmpty($body['csrf_token']);

        $sessionToken = $this->cookie($response, 'myinvoice_session');
        self::assertNotNull($sessionToken, 'A session cookie, same as a login issues.');
        self::assertSame(
            ['assurance_level' => 'strong', 'auth_method' => 'invite'],
            $this->sessionRow($sessionToken),
        );

        // The browser is trusted right away - the next login from it needs no emailed code.
        $trusted = $this->cookie($response, $this->c->get(TrustedDeviceService::class)->cookieName());
        self::assertNotNull($trusted);
        self::assertTrue($this->c->get(TrustedDeviceService::class)->verify($trusted, $created['id']));

        // The chosen password works, and the link is spent.
        self::assertTrue($this->c->get(PasswordHasher::class)->verify('Brand-new-password-123', $this->passwordHash($created['id'])));
        $again = $this->accept($token, 'Another-new-password-123');
        self::assertSame(410, $again->getStatusCode());
        self::assertNull($this->cookie($again, 'myinvoice_session'));
    }

    public function testAcceptingAReInviteReplacesTheOldPasswordAndSessions(): void
    {
        $existing = $this->makeUser('accountant', 'email');
        $oldSession = $this->c->get(\MyInvoice\Service\Auth\SessionManager::class)->create(
            $existing['id'],
            self::TEST_IP,
            'PHPUnit',
            \MyInvoice\Service\Auth\SessionAuthContext::basic('password'),
        )['token'];
        $token = $this->tokenFromLink($this->inviteByLink($existing['id'])['invite_link']);

        $this->accept($token, 'Brand-new-password-123');

        self::assertFalse($this->c->get(PasswordHasher::class)->verify(self::TEST_PASSWORD, $this->passwordHash($existing['id'])));
        self::assertNull($this->c->get(\MyInvoice\Service\Auth\SessionManager::class)->load($oldSession));
    }

    public function testDefaultModeUserFollowsThePolicyAfterTheInvite(): void
    {
        $created = $this->createUserWithoutPassword('accountant', 'default');
        $token = $this->tokenFromLink($this->inviteByLink($created['id'])['invite_link']);

        $response = $this->accept($token, 'Brand-new-password-123');

        $body = $this->json($response);
        self::assertTrue($body['user']['must_setup_mfa'], 'Required MFA + no mailbox factor → forced enrolment.');
        $session = $this->sessionRow((string) $this->cookie($response, 'myinvoice_session'));
        self::assertSame('setup', $session['assurance_level']);
    }

    public function testDefaultModeUserGetsABasicSessionWhenMfaIsNotRequired(): void
    {
        $this->bootApp(mfaRequired: false);
        $this->admin = $this->makeAdmin();
        $created = $this->createUserWithoutPassword('readonly', 'default');
        $token = $this->tokenFromLink($this->inviteByLink($created['id'])['invite_link']);

        $response = $this->accept($token, 'Brand-new-password-123');

        $session = $this->sessionRow((string) $this->cookie($response, 'myinvoice_session'));
        self::assertSame('basic', $session['assurance_level']);
        self::assertFalse($this->json($response)['user']['must_setup_mfa']);
    }

    public function testUserWhoAlreadyHasATotpFactorIsNotSignedInByTheLink(): void
    {
        $existing = $this->makeUser('accountant', 'email');
        $this->db->pdo()->prepare('UPDATE users SET totp_enabled = 1, totp_secret = ? WHERE id = ?')
            ->execute(['enc:v1:synthetic', $existing['id']]);
        $token = $this->tokenFromLink($this->inviteByLink($existing['id'])['invite_link']);

        $response = $this->accept($token, 'Brand-new-password-123');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['signed_in' => false], $this->json($response));
        self::assertNull($this->cookie($response, 'myinvoice_session'), 'A mailed link must not bypass an existing authenticator.');
        self::assertTrue($this->c->get(PasswordHasher::class)->verify('Brand-new-password-123', $this->passwordHash($existing['id'])));
    }

    public function testInviteEndpointsIgnoreASessionCookieTheBrowserStillCarries(): void
    {
        // Otherwise a stale legacy/setup session would be stopped by the MFA guard before the invite is handled.
        $reflection = new \ReflectionClassConstant(\MyInvoice\Middleware\AuthMiddleware::class, 'SESSION_IGNORED_PATHS');
        $ignored = $reflection->getValue();
        self::assertContains('/api/auth/invite', $ignored['GET']);
        self::assertContains('/api/auth/invite/accept', $ignored['POST']);
    }

    // ── Second-factor mode rules ────────────────────────────────────────────────────────────

    public function testAdminCannotBeCreatedInEmailMode(): void
    {
        $response = $this->c->get(UserAdminAction::class)->create(
            $this->request('POST', '/api/admin/users', [
                'email' => 'invite-test-' . bin2hex(random_bytes(4)) . '@example.invalid',
                'name' => 'Nope', 'role' => 'admin', 'mfa_mode' => 'email',
            ], $this->admin),
            $this->response(),
        );
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    public function testAdminCannotBeSwitchedToEmailMode(): void
    {
        $other = $this->makeAdmin();
        $response = $this->update($other['id'], ['mfa_mode' => 'email']);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('default', $this->mfaMode($other['id']));
    }

    public function testRoleAndModeInOneRequestCannotSmuggleEmailModeOntoAnAdmin(): void
    {
        $user = $this->makeUser('accountant', 'email');
        $response = $this->update($user['id'], ['role' => 'admin', 'mfa_mode' => 'email']);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame('accountant', $this->role($user['id']));
        self::assertSame('email', $this->mfaMode($user['id']));
    }

    public function testPromotingAnEmailModeUserToAdminFallsBackToTheDefaultMode(): void
    {
        $user = $this->makeUser('accountant', 'email');

        $response = $this->update($user['id'], ['role' => 'admin']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('admin', $this->role($user['id']));
        self::assertSame('default', $this->mfaMode($user['id']));
        self::assertContains('user.mfa_mode_changed', $this->loggedActions($user['id']));
    }

    public function testDatabaseItselfRefusesAnAdminInEmailMode(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role, mfa_mode) VALUES (?, 'x', 'x', 'admin', 'email')"
        )->execute(['invite-test-' . bin2hex(random_bytes(4)) . '@example.invalid']);
    }

    public function testChangingTheModeRevokesTheUsersSessions(): void
    {
        $user = $this->makeUser('accountant', 'email');
        $sessions = $this->c->get(\MyInvoice\Service\Auth\SessionManager::class);
        $token = $sessions->create(
            $user['id'], self::TEST_IP, 'PHPUnit',
            \MyInvoice\Service\Auth\SessionAuthContext::strong('email_otp', new \DateTimeImmutable()),
        )['token'];
        self::assertNotNull($sessions->load($token));

        $this->update($user['id'], ['mfa_mode' => 'default']);

        self::assertNull($sessions->load($token), 'A strong session earned through the mailbox must not outlive the mode.');
    }

    public function testInvalidModeIsRejected(): void
    {
        $user = $this->makeUser('accountant');
        self::assertSame(400, $this->update($user['id'], ['mfa_mode' => 'sms'])->getStatusCode());
    }

    // ── Audit trail ─────────────────────────────────────────────────────────────────────────

    public function testActivityLogRecordsInviteAcceptanceAndModeWithoutAnySecret(): void
    {
        $created = $this->createUserWithoutPassword('accountant', 'email');
        $link = $this->inviteByLink($created['id'])['invite_link'];
        $token = $this->tokenFromLink($link);
        $this->accept($token, 'Brand-new-password-123');
        $this->update($created['id'], ['mfa_mode' => 'default']);

        $actions = $this->loggedActions($created['id']);
        foreach (['user.created', 'user.mfa_mode_changed', 'user.invited', 'user.invite_accepted'] as $expected) {
            self::assertContains($expected, $actions);
        }
        self::assertSame(2, count(array_keys($actions, 'user.mfa_mode_changed', true)), 'on create and on the switch');

        $payloads = $this->db->pdo()->prepare('SELECT payload FROM activity_log WHERE user_id = ? OR (entity_type = ? AND entity_id = ?)');
        $payloads->execute([$this->admin['id'], 'user', $created['id']]);
        foreach ($payloads->fetchAll(\PDO::FETCH_COLUMN) as $payload) {
            self::assertStringNotContainsString($token, (string) $payload);
            self::assertStringNotContainsString('Brand-new-password-123', (string) $payload);
            self::assertStringNotContainsString('token=', (string) $payload);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function createUserWithoutPassword(string $role, string $mode): array
    {
        $response = $this->c->get(UserAdminAction::class)->create(
            $this->request('POST', '/api/admin/users', [
                'email' => 'invite-test-' . bin2hex(random_bytes(5)) . '@example.invalid',
                'name' => 'Test ' . ucfirst($role),
                'role' => $role,
                'mfa_mode' => $mode,
            ], $this->admin),
            $this->response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $this->userIds[] = (int) $body['id'];
        return $body;
    }

    /** @param array<string,mixed> $body */
    private function invite(int $userId, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->c->get(UserInviteAdminAction::class)->issue(
            $this->request('POST', "/api/admin/users/{$userId}/invite", $body, $this->admin),
            $this->response(),
            ['id' => (string) $userId],
        );
    }

    /** @return array<string,mixed> */
    private function inviteByLink(int $userId): array
    {
        $response = $this->invite($userId, ['send_email' => false]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->json($response);
    }

    private function show(string $token): \Psr\Http\Message\ResponseInterface
    {
        return $this->c->get(InviteAction::class)->show(
            $this->request('GET', '/api/auth/invite')->withQueryParams(['token' => $token]),
            $this->response(),
        );
    }

    private function accept(string $token, string $password, ?string $confirm = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->c->get(InviteAction::class)->accept(
            $this->request('POST', '/api/auth/invite/accept', [
                'token' => $token, 'password' => $password, 'password_confirm' => $confirm ?? $password,
            ]),
            $this->response(),
        );
    }

    /** @param array<string,mixed> $body */
    private function update(int $userId, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->c->get(UserAdminAction::class)->update(
            $this->request('PUT', "/api/admin/users/{$userId}", $body, $this->admin),
            $this->response(),
            ['id' => (string) $userId],
        );
    }

    private function passwordHash(int $userId): string
    {
        return $this->column('password_hash', $userId);
    }

    private function role(int $userId): string
    {
        return $this->column('role', $userId);
    }

    private function mfaMode(int $userId): string
    {
        return $this->column('mfa_mode', $userId);
    }

    private function column(string $column, int $userId): string
    {
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (string) $stmt->fetchColumn();
    }
}
