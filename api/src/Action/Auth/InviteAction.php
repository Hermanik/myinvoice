<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\LoginSessionIssuer;
use MyInvoice\Service\Auth\MfaPolicyService;
use MyInvoice\Service\Auth\PasswordHasher;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Auth\UserInviteService;
use MyInvoice\Service\Auth\UserMfaMode;
use MyInvoice\Service\IpMatcher;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public invitation page backend (the token in the link is the credential).
 *
 *   GET  /api/auth/invite?token=…   name + password rules for the page; 410 when the link is dead
 *   POST /api/auth/invite/accept    sets the password and signs the user in (same session a login issues)
 *
 * An unknown, used, expired or deactivated-user token all answer the same 410, so the endpoint
 * reveals nothing about which tokens ever existed. Every dead-token hit counts against the
 * per-IP BruteForceGuard bucket (as the forgot/reset endpoints do); the router additionally
 * applies the per-IP rate limit.
 */
final class InviteAction
{
    /** Bucket identity for BruteForceGuard::check/recordFailure — there is no email on this page. */
    private const BF_SUBJECT = 'invite-link';

    public function __construct(
        private readonly UserInviteService $invites,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly LoginSessionIssuer $loginIssuer,
        private readonly BruteForceGuard $bf,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly PasskeyCredentialRepository $credentials,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ClockInterface $clock,
    ) {}

    public function show(Request $request, Response $response): Response
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        if ($this->isLocked($ip)) {
            return $this->tooManyAttempts($response);
        }

        $user = $this->invites->peek((string) ($request->getQueryParams()['token'] ?? ''));
        if ($user === null) {
            return $this->deadLink($request, $response, $ip);
        }

        return Json::ok($response, [
            'name' => (string) $user['name'],
            'email_codes' => UserMfaMode::usesEmail($user),
            'password_min_length' => PasswordHasher::MIN_LENGTH,
            'password_max_length' => PasswordHasher::MAX_LENGTH,
        ]);
    }

    public function accept(Request $request, Response $response): Response
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');
        if ($this->isLocked($ip)) {
            return $this->tooManyAttempts($response);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($this->invites->peek($token) === null) {
            return $this->deadLink($request, $response, $ip);
        }
        if ($password !== (string) ($body['password_confirm'] ?? '')) {
            return Json::error($response, 'validation_failed', 'Hesla se neshodují.', 400);
        }
        // Validate and hash BEFORE spending the token, so a too-short password does not burn the link.
        try {
            $hash = $this->hasher->hash($password);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $user = $this->invites->accept($token, $hash);
        if ($user === null) {
            return $this->deadLink($request, $response, $ip);
        }
        $userId = (int) $user['id'];

        // The old password is gone - so is every session that was opened with it.
        $this->sessions->destroyAllForUser($userId);

        $signIn = !$this->hasStrongFactor($user);
        $context = $this->sessionContext($user);
        $this->logger->log('user.invite_accepted', $userId, 'user', $userId, [
            'mfa_mode' => UserMfaMode::effective($user),
            'signed_in' => $signIn,
            'assurance' => $signIn ? $context->assuranceLevel : null,
        ], $ip, $userAgent);

        if (!$signIn) {
            // The user already owns a passkey / TOTP. A mailed link must not replace that factor, so the
            // password is set but the person still signs in the normal way.
            return Json::ok($response, ['signed_in' => false]);
        }

        // Possession of the link proves control of the mailbox, so this browser is trusted from now on
        // (no emailed code right after the invite; it only matters for email-code logins).
        return $this->loginIssuer->issue($response, $user, $ip, $userAgent, $context, true);
    }

    /**
     * Assurance of the session the invite earns. For an email-mode user the link IS the proof of
     * mailbox possession, i.e. their second factor, so the session is strong. Anyone else is treated
     * like a password-only login: setup session (forced enrolment) when MFA is required.
     *
     * @param array<string,mixed> $user
     */
    private function sessionContext(array $user): SessionAuthContext
    {
        if (UserMfaMode::usesEmail($user)) {
            return SessionAuthContext::strong('invite', $this->clock->now());
        }
        return $this->mfaPolicy->isRequired()
            ? SessionAuthContext::setup('invite')
            : SessionAuthContext::basic('invite');
    }

    /** @param array<string,mixed> $user */
    private function hasStrongFactor(array $user): bool
    {
        return (int) ($user['totp_enabled'] ?? 0) === 1
            || $this->credentials->countActiveForUser((int) $user['id']) > 0;
    }

    private function isLocked(string $ip): bool
    {
        return in_array(
            $this->bf->check(self::BF_SUBJECT, $ip),
            [BruteForceGuard::STATE_LOCKED_15M, BruteForceGuard::STATE_LOCKED_24H],
            true,
        );
    }

    private function deadLink(Request $request, Response $response, string $ip): Response
    {
        $this->bf->recordFailure(self::BF_SUBJECT, $ip);
        $this->logger->log('auth.invite_invalid', null, null, null, ['ip' => $ip], $ip, $request->getHeaderLine('User-Agent'));
        return Json::error($response, 'invite_invalid', 'Odkaz už neplatí, požádejte správce o nový.', 410);
    }

    private function tooManyAttempts(Response $response): Response
    {
        return Json::error($response, 'rate_limited', 'Příliš mnoho pokusů. Zkus to později.', 429);
    }
}
