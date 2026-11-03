<?php

declare(strict_types=1);

namespace MyInvoice\Action\Settings;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Vat\VatStatusException;
use MyInvoice\Service\Vat\VatStatusService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Dated VAT status (UI: "Plátcovství DPH") of the current supplier.
 *
 *   GET    /api/settings/vat-status?date=YYYY-MM-DD   status on a date (default today)
 *   GET    /api/settings/vat-status/history           history rows + status today + next change
 *   POST   /api/settings/vat-status/history           add a row {valid_from, status, note?} (admin)
 *   DELETE /api/settings/vat-status/history/{id}      delete a row (admin; never the earliest)
 *
 * Reads are open to every role (like GET /api/settings/supplier); writes are admin-only,
 * enforced by RoleMiddleware (admin fallback) and again here.
 */
final class VatStatusAction
{
    public function __construct(
        private readonly VatStatusService $vatStatus,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    /** GET /api/settings/vat-status?date= */
    public function atDate(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        $date = trim((string) ($request->getQueryParams()['date'] ?? ''));
        if ($date === '') {
            $date = VatStatusService::today();
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return Json::error($response, 'validation_failed', 'Parametr date musí být platné datum ve formátu RRRR-MM-DD.', 400);
        }

        return Json::ok($response, array_merge(
            ['date' => $date, 'status' => $this->vatStatus->statusAt($supplierId, $date)],
            $this->vatStatus->flagsAt($supplierId, $date),
        ));
    }

    /** GET /api/settings/vat-status/history */
    public function history(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        return Json::ok($response, $this->vatStatus->overview($supplierId));
    }

    /** POST /api/settings/vat-status/history */
    public function add(Request $request, Response $response): Response
    {
        if (($denied = $this->requireAdmin($request, $response)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $note = array_key_exists('note', $body) && $body['note'] !== null ? (string) $body['note'] : null;

        try {
            $row = $this->vatStatus->add(
                $supplierId,
                (string) ($body['valid_from'] ?? ''),
                (string) ($body['status'] ?? ''),
                $note,
                $this->userId($request),
            );
        } catch (VatStatusException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $this->log($request, 'supplier.vat_status_added', $supplierId, [
            'valid_from' => $row['valid_from'] ?? null,
            'status'     => $row['status'] ?? null,
        ]);
        return Json::ok($response, $this->vatStatus->overview($supplierId) + ['row' => $row], 201);
    }

    /** DELETE /api/settings/vat-status/history/{id} */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($denied = $this->requireAdmin($request, $response)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        $rowId = (int) ($args['id'] ?? 0);

        try {
            $this->vatStatus->delete($supplierId, $rowId);
        } catch (VatStatusException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $this->log($request, 'supplier.vat_status_deleted', $supplierId, ['row_id' => $rowId]);
        return Json::ok($response, $this->vatStatus->overview($supplierId));
    }

    private function requireAdmin(Request $request, Response $response): ?Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ($user['role'] ?? '') === 'admin' ? null : Json::error($response, 'forbidden', 'Pouze admin.', 403);
    }

    private function userId(Request $request): ?int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $id = (int) ($user['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private function log(Request $request, string $action, int $supplierId, array $payload): void
    {
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($this->userId($request) ?? 0), 'supplier', $supplierId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
