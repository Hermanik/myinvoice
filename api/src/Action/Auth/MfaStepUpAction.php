<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PasskeyCredentialRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\BruteForceGuard;
use MyInvoice\Service\Auth\EmailOtpService;
use MyInvoice\Service\Auth\MfaStepUpService;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Auth\StepUpOperationException;
use MyInvoice\Service\Auth\TotpService;
use MyInvoice\Service\Auth\UserMfaMode;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MfaStepUpAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly MfaStepUpService $stepUp,
        private readonly TotpService $totp,
        private readonly SecretEncryption $crypto,
        private readonly BruteForceGuard $bruteForce,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Auth\MfaRecoveryCodeService $recoveryCodes,
        private readonly EmailOtpService $emailOtp,
        private readonly PasskeyCredentialRepository $credentials,
    ) {}

    public function totp(Request $request, Response $response): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $sessionToken = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        if ($userId < 1 || $sessionToken === '') {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $operation = trim((string) ($body['operation'] ?? ''));
        try {
            $operation = $this->stepUp->assertAllowed($userId, $operation, 'totp');
        } catch (StepUpOperationException) {
            return Json::error($response, 'step_up_operation_invalid', 'Nepovolený účel ověření.', 400);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT totp_secret, totp_enabled FROM users WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$userId]);
        $freshUser = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($freshUser === false
            || (int) $freshUser['totp_enabled'] !== 1
            || empty($freshUser['totp_secret'])
        ) {
            return Json::error($response, 'totp_unavailable', 'TOTP není pro uživatele aktivní.', 409);
        }
        if ($this->bruteForce->isTotpLocked($userId)) {
            return Json::error(
                $response,
                'too_many_attempts',
                'Příliš mnoho TOTP pokusů. Zkus to později.',
                429,
            );
        }

        $code = trim((string) ($body['code'] ?? ''));
        try {
            $secret = $this->crypto->decrypt((string) $freshUser['totp_secret']);
        } catch (\RuntimeException) {
            return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
        }
        if (!$this->totp->verify($secret, $code)) {
            $this->bruteForce->recordTotpFailure($userId);
            return Json::error($response, 'invalid_code', 'Neplatný TOTP kód.', 401);
        }

        $this->bruteForce->recordTotpSuccess($userId);
        $proofToken = $this->stepUp->issue($userId, $sessionToken, $operation, 'totp');
        $this->logger->log(
            'auth.totp_step_up',
            $userId,
            'user',
            $userId,
            ['operation' => $operation],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, ['step_up_token' => $proofToken]);
    }

    /**
     * Step-up of a user in `email` mode, part 1: mail a fresh code. Such a user has no passkey / TOTP,
     * so the "re-prove it is really you" step for a protected operation is a code from their mailbox.
     * A normal (default-mode) user, and anybody who owns a passkey or TOTP, is refused - they use their
     * real factor.
     */
    public function emailRequest(Request $request, Response $response): Response
    {
        $prepared = $this->prepareEmailStepUp($request, $response);
        if ($prepared instanceof Response) {
            return $prepared;
        }
        ['user' => $user, 'operation' => $operation, 'ip' => $ip, 'body' => $body] = $prepared;

        $issued = $this->emailOtp->issue($user, $ip, !empty($body['resend']));
        $this->logger->log('auth.email_step_up_sent', (int) $user['id'], 'user', (int) $user['id'], [
            'operation' => $operation, 'sent' => $issued['sent'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent' => $issued['sent'],
            'cooldown_remaining' => $issued['cooldown_remaining'],
        ]);
    }

    /** Step-up of a user in `email` mode, part 2: verify the emailed code and issue the one-time proof. */
    public function email(Request $request, Response $response): Response
    {
        $prepared = $this->prepareEmailStepUp($request, $response);
        if ($prepared instanceof Response) {
            return $prepared;
        }
        ['user' => $user, 'operation' => $operation, 'ip' => $ip, 'body' => $body] = $prepared;
        $userId = (int) $user['id'];
        $sessionToken = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');

        if ($this->bruteForce->isEmailOtpLocked($userId)) {
            return Json::error($response, 'too_many_attempts', 'Příliš mnoho pokusů o ověření kódu. Zkus to později.', 429);
        }
        if (!$this->emailOtp->verify($userId, trim((string) ($body['code'] ?? '')))) {
            $this->bruteForce->recordEmailOtpFailure($userId);
            $this->logger->log('auth.email_step_up_failed', $userId, 'user', $userId, [
                'operation' => $operation,
            ], $ip, $request->getHeaderLine('User-Agent'));
            return Json::error($response, 'invalid_code', 'Neplatný nebo expirovaný kód.', 401);
        }

        $this->bruteForce->recordEmailOtpSuccess($userId);
        $proofToken = $this->stepUp->issue($userId, $sessionToken, $operation, MfaStepUpService::METHOD_EMAIL);
        $this->logger->log('auth.email_step_up', $userId, 'user', $userId, [
            'operation' => $operation,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['step_up_token' => $proofToken]);
    }

    /**
     * Shared guards of the two email step-up endpoints.
     *
     * @return Response|array{user:array<string,mixed>,operation:string,ip:string,body:array<string,mixed>}
     */
    private function prepareEmailStepUp(Request $request, Response $response): Response|array
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        $sessionUser = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($sessionUser['id'] ?? 0);
        if ($userId < 1 || (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '') === '') {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        try {
            $operation = $this->stepUp->assertAllowed(
                $userId,
                trim((string) ($body['operation'] ?? '')),
                MfaStepUpService::METHOD_EMAIL,
            );
        } catch (StepUpOperationException) {
            return Json::error($response, 'step_up_operation_invalid', 'Nepovolený účel ověření.', 400);
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, email, name, role, locale, mfa_mode, totp_enabled FROM users WHERE id = ? AND is_active = 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($user === false
            || !UserMfaMode::usesEmail($user)
            || (int) $user['totp_enabled'] === 1
            || $this->credentials->countActiveForUser($userId) > 0
        ) {
            return Json::error(
                $response,
                'email_step_up_unavailable',
                'Ověření kódem z e-mailu není pro tento účet dostupné.',
                409,
            );
        }

        return [
            'user' => $user,
            'operation' => $operation,
            'ip' => $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            'body' => $body,
        ];
    }

    /**
     * Step-up záložním jednorázovým kódem — poslední cesta, jak z účtu odstranit
     * ztracený faktor, když jiný silný už uživatel nemá.
     *
     * Kód se spotřebuje, i když se operace potom nedokončí. Je to záměr: jinak by
     * šlo jedním kódem zkoušet operace donekonečna a jednorázovost by byla jen
     * na papíře.
     */
    public function recovery(Request $request, Response $response): Response
    {
        if ($request->getAttribute(AuthMiddleware::ATTR_METHOD) !== 'session') {
            return Json::error(
                $response,
                'session_required',
                'Tento endpoint je dostupný pouze z přihlášené webové session.',
                403,
            );
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $sessionToken = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        if ($userId < 1 || $sessionToken === '') {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $operation = trim((string) ($body['operation'] ?? ''));
        try {
            $operation = $this->stepUp->assertAllowed($userId, $operation, 'recovery');
        } catch (StepUpOperationException $e) {
            return Json::error($response, 'step_up_operation_invalid', $e->getMessage(), 400);
        }

        // Týž zámek jako u TOTP: 50 bitů entropie se hrubou silou uhádnout nedá,
        // ale nechat na auth endpointu neomezené pokusy je zbytečná laskavost.
        if ($this->bruteForce->isTotpLocked($userId)) {
            return Json::error($response, 'too_many_attempts', 'Příliš mnoho pokusů. Zkus to později.', 429);
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $code = (string) ($body['code'] ?? '');
        if (!$this->recoveryCodes->consume($userId, $code, $ip)) {
            $this->bruteForce->recordTotpFailure($userId);
            $this->logger->log(
                'auth.recovery_code_failed',
                $userId,
                'user',
                $userId,
                ['operation' => $operation],
                $ip,
                $request->getHeaderLine('User-Agent'),
            );
            return Json::error($response, 'invalid_code', 'Neplatný nebo už použitý záložní kód.', 401);
        }

        $this->bruteForce->recordTotpSuccess($userId);
        $proofToken = $this->stepUp->issue($userId, $sessionToken, $operation, 'recovery');
        $remaining = $this->recoveryCodes->remaining($userId);
        $this->logger->log(
            'auth.recovery_code_used',
            $userId,
            'user',
            $userId,
            ['operation' => $operation, 'remaining' => $remaining],
            $ip,
            $request->getHeaderLine('User-Agent'),
        );

        return Json::ok($response, [
            'step_up_token' => $proofToken,
            'remaining'     => $remaining,
        ]);
    }
}
