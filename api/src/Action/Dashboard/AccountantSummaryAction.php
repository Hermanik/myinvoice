<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\Dashboard\AccountantDashboardService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/dashboard/accountant - data of the accountant view of the dashboard (see
 * {@see AccountantDashboardService}). Readable by every role like the other dashboards; the obligations timeline
 * is left out for a role that may not read it (readonly), and the data box card exists for the accountant only.
 */
final class AccountantSummaryAction
{
    public function __construct(private readonly AccountantDashboardService $dashboard) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }

        return Json::ok($response, $this->dashboard->build(
            SupplierGuard::currentId($request),
            new \DateTimeImmutable('today'),
            withObligations: in_array($role, ['admin', 'accountant'], true),
            withDatabox: $role === 'accountant',
        ));
    }
}
