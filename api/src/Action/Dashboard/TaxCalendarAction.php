<?php

declare(strict_types=1);

namespace MyInvoice\Action\Dashboard;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Tax\TaxCalendarService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Daňový kalendář (dashboard widget): the upcoming tax deadlines of the current supplier.
 *
 *   GET /api/dashboard/tax-calendar[?days=60]
 *
 * {@see TaxCalendarService} holds the rules (DPH / KH / SH by the supplier's settings and VAT status,
 * the income tax return, the flat-tax advance) and the filed/unfiled badge from the submission archive.
 */
final class TaxCalendarAction
{
    public function __construct(private readonly TaxCalendarService $calendar) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $days = max(7, min(180, (int) ($request->getQueryParams()['days'] ?? 60)));
        $now = new \DateTimeImmutable('today');
        return Json::ok($response, [
            'today' => $now->format('Y-m-d'),
            'items' => $this->calendar->items($supplierId, $now, $days),
        ]);
    }
}
