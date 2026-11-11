<?php

declare(strict_types=1);

namespace MyInvoice\Action\Onboarding;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Onboarding\UserOnboardingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Guided tour state of the signed-in user (self-service, every role):
 *
 *   GET /api/onboarding/tour   {status: pending|completed|skipped, finished_at}
 *   PUT /api/onboarding/tour   {status: completed|skipped} - records how a tour run ended
 */
final class TourStateAction
{
    public function __construct(private readonly UserOnboardingService $onboarding) {}

    public function get(Request $request, Response $response): Response
    {
        $userId = $this->userId($request);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }

        return Json::ok($response, $this->onboarding->tourState($userId));
    }

    public function finish(Request $request, Response $response): Response
    {
        $userId = $this->userId($request);
        if ($userId <= 0) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        $body = $request->getParsedBody();

        try {
            $state = $this->onboarding->finishTour($userId, is_array($body) ? ($body['status'] ?? null) : null);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }

        return Json::ok($response, $state);
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        return (int) ($user['id'] ?? 0);
    }
}
