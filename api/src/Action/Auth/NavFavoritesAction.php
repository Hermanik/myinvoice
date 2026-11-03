<?php

declare(strict_types=1);

namespace MyInvoice\Action\Auth;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Auth\NavFavoritesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/auth/nav-favorites — replaces the signed-in user's starred sidebar
 * items. The current list is part of GET /api/auth/me (user.nav_favorites).
 */
final class NavFavoritesAction
{
    public function __construct(private readonly NavFavoritesService $favorites) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        $body = $request->getParsedBody();

        try {
            $items = $this->favorites->update($userId, is_array($body) ? ($body['items'] ?? null) : null);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        return Json::ok($response, ['items' => $items]);
    }
}
