<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\UserInviteService;
use MyInvoice\Service\Auth\UserMfaMode;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/admin/users/{id}/invite — the admin issues a one-time "set your own password" link
 * for a new or already existing user (re-invite).
 *
 *   {"send_email": true}   the app mails the link to the user; the link itself is NOT returned
 *   {"send_email": false}  the link is returned once so the admin can forward it by hand
 *
 * Issuing a link invalidates the user's earlier unused links.
 */
final class UserInviteAdminAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserInviteService $invites,
        private readonly Mailer $mailer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /**
     * @param array<string,string> $args
     */
    public function issue(Request $request, Response $response, array $args): Response
    {
        $admin = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($admin['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }

        $id = (int) ($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, email, name, role, locale, is_active, mfa_mode FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($user === false) {
            return Json::error($response, 'not_found', 'Uživatel nenalezen.', 404);
        }
        if ((int) $user['is_active'] !== 1) {
            return Json::error($response, 'user_inactive', 'Neaktivního uživatele nelze pozvat.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $sendEmail = !empty($body['send_email']);

        $invite = $this->invites->issue($id, (int) ($admin['id'] ?? 0) ?: null);
        $link = $this->invites->link($invite['token']);

        if ($sendEmail) {
            $locale = (string) ($user['locale'] ?? 'cs');
            try {
                $this->mailer->sendTemplate(
                    'user_invite',
                    $locale,
                    [(string) $user['email']],
                    [
                        'name'       => $user['name'],
                        'inviteLink' => $link,
                        'expiresIn'  => $locale === 'en'
                            ? UserInviteService::TTL_DAYS . ' days'
                            : UserInviteService::TTL_DAYS . ' dní',
                        'invitedBy'  => (string) ($admin['name'] ?? ''),
                        'emailCodes' => UserMfaMode::usesEmail($user),
                    ],
                );
            } catch (\Throwable $e) {
                // Nobody received the link, so do not leave a live token behind.
                $this->invites->revokeUnused($id);
                $this->log($request, 'user.invite_mail_failed', $id, ['error' => $e->getMessage()]);
                return Json::error(
                    $response,
                    'invite_mail_failed',
                    'Pozvánku se nepodařilo odeslat e-mailem. Zkontroluj nastavení SMTP nebo použij odkaz.',
                    502,
                );
            }
        }

        // Never log the token or the link.
        $this->log($request, 'user.invited', $id, [
            'via' => $sendEmail ? 'email' : 'link',
            'expires_at' => $invite['expires_at'],
        ]);

        $result = ['email_sent' => $sendEmail, 'expires_at' => $invite['expires_at']];
        if (!$sendEmail) {
            $result['invite_link'] = $link;
        }
        return Json::ok($response, $result);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function log(Request $request, string $action, int $entityId, array $payload): void
    {
        $admin = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log(
            $action,
            (int) ($admin['id'] ?? 0),
            'user',
            $entityId,
            $payload,
            $ip,
            $request->getHeaderLine('User-Agent'),
        );
    }
}
