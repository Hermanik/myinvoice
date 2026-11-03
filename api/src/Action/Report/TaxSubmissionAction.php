<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Tax\VatPaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * History of the archived EPO XML returns (DPHDP3, DPHKH1, DPHSHV, DPFDP5, DPPDP9, OSS).
 *
 *   GET    /api/reports/submissions          → list (with the filing state and, for DPH returns, the payment state)
 *   GET    /api/reports/submissions/{id}     → detail (incl. the XML body)
 *   GET    /api/reports/submissions/{id}/xml → XML download
 *   DELETE /api/reports/submissions/{id}     → delete an archive row (admin; not while it is filed/confirmed)
 *
 * The filing lifecycle lives in {@see TaxSubmissionFilingAction}.
 */
final class TaxSubmissionAction
{
    public function __construct(
        private readonly TaxSubmissionRepository $repo,
        private readonly VatPaymentService $payments,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $rows = $this->repo->list($supplierId);
        $cards = $this->payments->cardsFor($supplierId, $rows);
        foreach ($rows as &$row) {
            if ($row['form_code'] === VatPaymentService::FORM_CODE) {
                $row['payment'] = $cards[$row['id']] ?? null;
            }
        }
        unset($row);
        return Json::ok($response, $rows);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $row = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($row === null) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        return Json::ok($response, $row);
    }

    public function downloadXml(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $row = $this->repo->find((int) ($args['id'] ?? 0), $supplierId);
        if ($row === null) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);

        $monthPart = $row['period_month'] !== null
            ? sprintf('-%02d', $row['period_month'])
            : ($row['period_quarter'] !== null ? sprintf('-Q%d', $row['period_quarter']) : '');
        $filename = sprintf('%s-%d%s-archive.xml', $row['form_code'], $row['period_year'], $monthPart);

        $response->getBody()->write((string) $row['xml_content']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->findMeta($id, $supplierId);
        if ($existing === null) return Json::error($response, 'not_found', 'Záznam nenalezen.', 404);
        // The archive row is the evidence of what was filed — the filing has to be reverted first. The
        // repository's DELETE refuses a filed row as well, so a filing racing this check cannot slip through.
        if ($existing['filing_status'] !== 'generated' || !$this->repo->delete($id, $supplierId)) {
            return Json::error($response, 'filed_submission', 'Podané nebo potvrzené podání nelze smazat. Nejdřív zruš označení podání.', 409);
        }
        return Json::ok($response, ['deleted' => true]);
    }
}
