<?php

declare(strict_types=1);

namespace MyInvoice\Action\Tax;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Vat\RegistrationDeductionService;
use MyInvoice\Service\Vat\VatStatusException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Agenda "Odpočet při registraci" (§ 79 ZDPH) and the reduction on deregistration (§ 79a ZDPH).
 *
 *   GET    /api/tax/registration-deductions                        events of the VAT history + items + totals
 *   GET    /api/tax/registration-deductions/reversal-suggestions   ?event_date=YYYY-MM-DD  draft reduction items
 *   POST   /api/tax/registration-deductions                        create an item (admin)
 *   PUT    /api/tax/registration-deductions/{id}                   edit an item (admin)
 *   DELETE /api/tax/registration-deductions/{id}                   delete an item (admin)
 *
 * Reads are open to every role (the `GET /api/tax` rule of RoleMiddleware); writes are admin-only.
 */
final class RegistrationDeductionAction
{
    public function __construct(
        private readonly RegistrationDeductionService $service,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        return Json::ok($response, $this->service->overview($supplierId));
    }

    public function suggestions(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        try {
            return Json::ok($response, [
                'suggestions' => $this->service->suggestReversals($supplierId, trim((string) ($request->getQueryParams()['event_date'] ?? ''))),
            ]);
        } catch (VatStatusException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->write($request, $response, 'tax.registration_deduction_created', fn (int $supplierId, array $body, ?int $userId): array
            => $this->service->create($supplierId, $body, $userId), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        return $this->write($request, $response, 'tax.registration_deduction_updated', fn (int $supplierId, array $body): array
            => $this->service->update($supplierId, $id, $body));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        return $this->write($request, $response, 'tax.registration_deduction_deleted', function (int $supplierId) use ($id): array {
            $this->service->delete($supplierId, $id);
            return ['id' => $id];
        });
    }

    /**
     * Admin-only write: runs `$operation`, maps domain errors, logs the change.
     *
     * @param callable(int, array<string,mixed>, ?int): array<string,mixed> $operation
     */
    private function write(Request $request, Response $response, string $logAction, callable $operation, int $status = 200): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        $userId = (int) ($user['id'] ?? 0) ?: null;
        try {
            $result = $operation($supplierId, (array) ($request->getParsedBody() ?? []), $userId);
        } catch (VatStatusException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        $this->logger->log(
            $logAction,
            (int) ($userId ?? 0),
            'supplier',
            $supplierId,
            array_intersect_key($result, array_flip(['id', 'kind', 'event_date', 'asset_type', 'deduction_amount'])),
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
        );
        return Json::ok($response, $result, $status);
    }
}
