<?php

declare(strict_types=1);

namespace MyInvoice\Action\Databox;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Databox\DataboxException;
use MyInvoice\Service\Databox\IsdsException;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Shared plumbing of the data box actions. All of them are session-only (the API-token allowlist does not contain
 * /api/databox, so a bearer token or the MCP gateway gets 403) and admin-only: the box holds legal correspondence.
 */
abstract class DataboxActionBase
{
    public function __construct(
        protected readonly ActivityLogger $activity,
        protected readonly IpMatcher $ipMatcher,
    ) {}

    /** @param callable(int $supplierId, int $userId): Response $handler */
    protected function run(Request $request, Response $response, callable $handler): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId <= 0) {
            return Json::error($response, 'not_found', 'Supplier nenalezen.', 404);
        }
        try {
            return $handler($supplierId, (int) ($user['id'] ?? 0));
        } catch (DataboxException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        } catch (IsdsException $e) {
            return $this->isdsError($response, $e);
        }
    }

    /**
     * ISDS failures are reported as 422 / 502 / 503, never 401 / 403: the SPA treats those as "your session is gone".
     */
    protected function isdsError(Response $response, IsdsException $e): Response
    {
        $status = match ($e->reason) {
            'rate_limited', 'maintenance' => 503,
            'network', 'server_error', 'invalid_response', 'http_error' => 502,
            default => 422,
        };
        $code = $e->isAuthFailure() ? 'databox_login_failed' : 'databox_isds_error';
        $extra = ['reason' => $e->reason];
        if ($e->isdsCode !== null) {
            $extra['isds_code'] = $e->isdsCode;
        }
        if ($e->blockedUntilTime !== null) {
            $extra['blocked_until'] = $e->blockedUntil(new \DateTimeImmutable())?->format('c');
        }
        return Json::error($response, $code, $e->getMessage(), $status, $extra);
    }

    /** @param array<string,mixed> $payload */
    protected function audit(Request $request, string $action, ?int $entityId, array $payload = []): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->activity->log(
            $action,
            (int) ($user['id'] ?? 0) ?: null,
            'databox',
            $entityId,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            SupplierGuard::currentId($request) ?: null,
        );
    }

    /** @return array<string,mixed> */
    protected function body(Request $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }
}
