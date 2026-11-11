<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Auth;

use MyInvoice\Action\Admin\UserAdminAction;
use MyInvoice\Action\Admin\UserInviteAdminAction;
use MyInvoice\Action\Auth\InviteAction;
use MyInvoice\Action\Auth\LoginAction;
use MyInvoice\Action\Auth\MfaStepUpAction;
use MyInvoice\Action\Auth\Tokens\CreateTokenAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\TrustedDeviceService;
use MyInvoice\Tests\Support\InviteFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The whole point of `mfa_mode = email`: a user without an authenticator app signs in with the
 * password plus a code from their mailbox, even though the installation REQUIRES a strong factor
 * (the legacy `auth.require_totp = true` production runs with) and the global email OTP is off.
 */
#[Group('integration')]
final class EmailModeLoginTest extends TestCase
{
    use InviteFixtures;

    private const NEW_PASSWORD = 'Brand-new-password-123';

    /** @var array{id:int,email:string,name:string,role:string,locale:string,mfa_mode:string} */
    private array $admin;

    protected function setUp(): void
    {
        $this->bootApp(mfaRequired: true);
        self::assertFalse(
            (bool) $this->c->get(Config::class)->get('auth.email_otp.enabled', false),
            'test premise: the global email OTP stays off',
        );
        $this->admin = $this->makeAdmin();
    }

    /** The "done when" scenario of the feature, start to finish. */
    public function testInvitedAccountantSignsInLaterFromAnUntrustedDeviceWithOnlyTheEmailedCode(): void
    {
        // Admin: create without a password, hand out the link.
        $created = $this->json($this->c->get(UserAdminAction::class)->create(
            $this->request('POST', '/api/admin/users', [
                'email' => 'invite-test-' . bin2hex(random_bytes(4)) . '@example.invalid',
                'name' => 'Test Accountant', 'role' => 'accountant', 'mfa_mode' => 'email',
            ], $this->admin),
            $this->response(),
        ));
        $this->userIds[] = (int) $created['id'];
        $link = $this->json($this->c->get(UserInviteAdminAction::class)->issue(
            $this->request('POST', "/api/admin/users/{$created['id']}/invite", ['send_email' => false], $this->admin),
            $this->response(),
            ['id' => (string) $created['id']],
        ))['invite_link'];

        // Accountant: opens the link on browser A, sets the password, is in.
        $accepted = $this->c->get(InviteAction::class)->accept(
            $this->request('POST', '/api/auth/invite/accept', [
                'token' => $this->tokenFromLink($link), 'password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD,
            ]),
            $this->response(),
        );
        self::assertSame(200, $accepted->getStatusCode());
        self::assertNotNull($this->cookie($accepted, 'myinvoice_session'));

        // Later, browser B (no trusted-device cookie): password alone is not enough, the code is.
        $first = $this->login($created['email'], self::NEW_PASSWORD);
        self::assertSame(401, $first->getStatusCode());
        self::assertSame('email_otp_required', $this->errorCode($first));
        self::assertTrue($this->json($first)['error']['otp_sent']);
        self::assertSame('login_otp', $this->sentMails[0]['template']);
        self::assertSame([$created['email']], $this->sentMails[0]['to']);

        $second = $this->login($created['email'], self::NEW_PASSWORD, ['email_otp' => $this->lastCode()]);
        self::assertSame(200, $second->getStatusCode(), (string) $second->getBody());
        $body = $this->json($second);
        self::assertSame($created['id'], $body['user']['id']);
        self::assertTrue($body['require_mfa'], 'The installation requires MFA…');
        self::assertFalse($body['user']['must_setup_mfa'], '…and the emailed code satisfies it: no forced TOTP enrolment.');
        self::assertFalse($body['user']['must_setup_totp']);

        $session = $this->sessionRow((string) $this->cookie($second, 'myinvoice_session'));
        self::assertSame(['assurance_level' => 'strong', 'auth_method' => 'email_otp'], $session);
        self::assertNull($this->cookie($second, $this->trustedCookieName()), 'The device is trusted only when asked for.');
    }

    public function testRememberingTheDeviceSkipsTheCodeNextTime(): void
    {
        $user = $this->makeUser('accountant', 'email');
        $this->login($user['email'], self::TEST_PASSWORD);

        $signedIn = $this->login($user['email'], self::TEST_PASSWORD, ['email_otp' => $this->lastCode(), 'remember_device' => true]);
        self::assertSame(200, $signedIn->getStatusCode());
        $trusted = $this->cookie($signedIn, $this->trustedCookieName());
        self::assertNotNull($trusted, '"Důvěřovat tomuto zařízení" sets the existing trusted-device cookie.');
        self::assertTrue($this->c->get(TrustedDeviceService::class)->verify($trusted, $user['id']));
        $expires = $this->db->pdo()->prepare('SELECT TIMESTAMPDIFF(DAY, NOW(), expires_at) FROM trusted_devices WHERE user_id = ?');
        $expires->execute([$user['id']]);
        self::assertContains((int) $expires->fetchColumn(), [29, 30], '30 days');

        $mailsBefore = count($this->sentMails);
        $again = $this->login($user['email'], self::TEST_PASSWORD, cookies: [$this->trustedCookieName() => $trusted]);
        self::assertSame(200, $again->getStatusCode());
        self::assertCount($mailsBefore, $this->sentMails, 'No new code is mailed from a trusted device.');
        self::assertSame(
            ['assurance_level' => 'strong', 'auth_method' => 'trusted_device'],
            $this->sessionRow((string) $this->cookie($again, 'myinvoice_session')),
        );
    }

    public function testTrustedDeviceOfAnotherUserDoesNotCount(): void
    {
        $alice = $this->makeUser('accountant', 'email');
        $bob = $this->makeUser('accountant', 'email');
        $aliceDevice = $this->c->get(TrustedDeviceService::class)->issue($alice['id'], self::TEST_IP, 'PHPUnit');

        $response = $this->login($bob['email'], self::TEST_PASSWORD, cookies: [$this->trustedCookieName() => $aliceDevice]);

        self::assertSame('email_otp_required', $this->errorCode($response));
    }

    public function testWrongPasswordNeverMailsACode(): void
    {
        $user = $this->makeUser('accountant', 'email');
        $response = $this->login($user['email'], 'Not-the-password-0000');
        self::assertSame('invalid_credentials', $this->errorCode($response));
        self::assertSame([], $this->sentMails);
    }

    public function testWrongCodeIsRefused(): void
    {
        $user = $this->makeUser('accountant', 'email');
        $this->login($user['email'], self::TEST_PASSWORD);
        $wrong = $this->lastCode() === '000000' ? '111111' : '000000';

        $response = $this->login($user['email'], self::TEST_PASSWORD, ['email_otp' => $wrong]);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('invalid_email_otp', $this->errorCode($response));
        self::assertNull($this->cookie($response, 'myinvoice_session'));
    }

    public function testEmailedCodeIsSingleUse(): void
    {
        $user = $this->makeUser('accountant', 'email');
        $this->login($user['email'], self::TEST_PASSWORD);
        $code = $this->lastCode();

        self::assertSame(200, $this->login($user['email'], self::TEST_PASSWORD, ['email_otp' => $code])->getStatusCode());
        self::assertSame(401, $this->login($user['email'], self::TEST_PASSWORD, ['email_otp' => $code])->getStatusCode());
    }

    public function testDefaultModeUserIsNotOfferedTheEmailedCodeAndStaysInMfaSetup(): void
    {
        $user = $this->makeUser('accountant', 'default');

        $response = $this->login($user['email'], self::TEST_PASSWORD);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->sentMails);
        self::assertTrue($this->json($response)['user']['must_setup_mfa']);
        self::assertSame('setup', $this->sessionRow((string) $this->cookie($response, 'myinvoice_session'))['assurance_level']);
    }

    public function testReadonlyUserCanUseEmailModeToo(): void
    {
        $user = $this->makeUser('readonly', 'email');
        $this->login($user['email'], self::TEST_PASSWORD);
        $response = $this->login($user['email'], self::TEST_PASSWORD, ['email_otp' => $this->lastCode()]);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['user']['must_setup_mfa']);
    }

    // ── Step-up ─────────────────────────────────────────────────────────────────────────────

    public function testApiTokenCreationNeedsAFreshEmailedCodeFromAnEmailModeUser(): void
    {
        $user = $this->makeUser('accountant', 'email');
        $sessionToken = $this->signIn($user);

        // 1) The session alone is not enough - same strictness a TOTP user faces.
        $denied = $this->createToken($user, $sessionToken, ['name' => 'integration', 'scope' => 'read']);
        self::assertSame(401, $denied->getStatusCode());
        self::assertSame('step_up_required', $this->errorCode($denied));
        self::assertSame(['email'], $this->json($denied)['error']['methods']);

        // 2) A fresh code is mailed on request.
        $sent = $this->stepUp($user, $sessionToken, 'emailRequest', ['operation' => 'api_token.create']);
        self::assertSame(200, $sent->getStatusCode());
        self::assertTrue($this->json($sent)['sent']);
        $code = $this->lastCode();

        // 3) A wrong code is refused, the right one yields a one-time proof.
        $wrong = $this->stepUp($user, $sessionToken, 'email', ['operation' => 'api_token.create', 'code' => $code === '000000' ? '111111' : '000000']);
        self::assertSame(401, $wrong->getStatusCode());
        $ok = $this->stepUp($user, $sessionToken, 'email', ['operation' => 'api_token.create', 'code' => $code]);
        self::assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $proof = $this->json($ok)['step_up_token'];

        // 4) The proof creates the token exactly once.
        $created = $this->createToken($user, $sessionToken, ['name' => 'integration', 'scope' => 'read', 'step_up_token' => $proof]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $reuse = $this->createToken($user, $sessionToken, ['name' => 'integration 2', 'scope' => 'read', 'step_up_token' => $proof]);
        self::assertSame(403, $reuse->getStatusCode());
        self::assertSame('step_up_proof_invalid', $this->errorCode($reuse));
    }

    public function testEmailStepUpIsLimitedToApiTokenCreation(): void
    {
        $user = $this->makeUser('accountant', 'email');
        $sessionToken = $this->signIn($user);

        foreach (['passkey.revoke:1', 'passkey.register', 'mfa.recovery_codes', 'something.else'] as $operation) {
            $response = $this->stepUp($user, $sessionToken, 'emailRequest', ['operation' => $operation]);
            self::assertSame(400, $response->getStatusCode(), $operation);
            self::assertSame('step_up_operation_invalid', $this->errorCode($response), $operation);
        }
    }

    public function testDefaultModeUserCannotUseEmailStepUp(): void
    {
        $user = $this->makeUser('accountant', 'default');
        $session = $this->c->get(\MyInvoice\Service\Auth\SessionManager::class)->create(
            $user['id'], self::TEST_IP, 'PHPUnit', \MyInvoice\Service\Auth\SessionAuthContext::basic('password'),
        )['token'];

        $response = $this->stepUp($user, $session, 'emailRequest', ['operation' => 'api_token.create']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('email_step_up_unavailable', $this->errorCode($response));
        self::assertSame([], $this->sentMails);
    }

    public function testEmailModeUserWhoOwnsATotpFactorMustUseIt(): void
    {
        $user = $this->makeUser('accountant', 'email');
        $this->db->pdo()->prepare('UPDATE users SET totp_enabled = 1, totp_secret = ? WHERE id = ?')
            ->execute(['enc:v1:synthetic', $user['id']]);
        $session = $this->c->get(\MyInvoice\Service\Auth\SessionManager::class)->create(
            $user['id'], self::TEST_IP, 'PHPUnit',
            \MyInvoice\Service\Auth\SessionAuthContext::strong('totp', new \DateTimeImmutable()),
        )['token'];

        $response = $this->stepUp($user, $session, 'emailRequest', ['operation' => 'api_token.create']);

        self::assertSame(409, $response->getStatusCode(), 'A mailbox code never replaces an existing real factor.');
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $extra
     * @param array<string,string> $cookies
     */
    private function login(string $email, string $password, array $extra = [], array $cookies = []): ResponseInterface
    {
        return $this->c->get(LoginAction::class)(
            $this->request('POST', '/api/auth/login', ['email' => $email, 'password' => $password] + $extra)
                ->withCookieParams($cookies),
            $this->response(),
        );
    }

    /** Signs the (email-mode) user in with a mailed code and returns the session token. */
    private function signIn(array $user): string
    {
        $this->login($user['email'], self::TEST_PASSWORD);
        $response = $this->login($user['email'], self::TEST_PASSWORD, ['email_otp' => $this->lastCode()]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return (string) $this->cookie($response, 'myinvoice_session');
    }

    /** @param array<string,mixed> $body */
    private function createToken(array $user, string $sessionToken, array $body): ResponseInterface
    {
        return $this->c->get(CreateTokenAction::class)(
            $this->authed('POST', '/api/auth/tokens', $user, $sessionToken, $body),
            $this->response(),
        );
    }

    /** @param array<string,mixed> $body */
    private function stepUp(array $user, string $sessionToken, string $method, array $body): ResponseInterface
    {
        return $this->c->get(MfaStepUpAction::class)->{$method}(
            $this->authed('POST', '/api/auth/mfa/step-up/email', $user, $sessionToken, $body),
            $this->response(),
        );
    }

    /** @param array<string,mixed> $body */
    private function authed(string $method, string $path, array $user, string $sessionToken, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->request($method, $path, $body, $user)->withAttribute(AuthMiddleware::ATTR_TOKEN, $sessionToken);
    }

    /** The newest code the stubbed mailer received. */
    private function lastCode(): string
    {
        for ($i = count($this->sentMails) - 1; $i >= 0; $i--) {
            if ($this->sentMails[$i]['template'] === 'login_otp') {
                return (string) $this->sentMails[$i]['vars']['code'];
            }
        }
        self::fail('No login code was mailed.');
    }

    private function trustedCookieName(): string
    {
        return $this->c->get(TrustedDeviceService::class)->cookieName();
    }
}
