<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tax\ChangesAfterFilingService;
use MyInvoice\Service\Tax\TaxFilingException;
use MyInvoice\Service\Tax\TaxSubmissionFilingService;
use MyInvoice\Service\Tax\VatPaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Filing lifecycle, receipt, VAT payment and "changed after filing" of an archived submission.
 *
 *   POST   /api/reports/submissions/{id}/file                → mark as filed (podáno)  {filed_at?, channel, reference?}
 *   POST   /api/reports/submissions/{id}/unfile              → back to generated (entered by mistake)
 *   POST   /api/reports/submissions/{id}/receipt             → upload the confirmation (multipart `file`) -> confirmed
 *   GET    /api/reports/submissions/{id}/receipt             → download the confirmation
 *   DELETE /api/reports/submissions/{id}/receipt             → drop the confirmation (confirmed -> filed)
 *   GET    /api/reports/submissions/{id}/payment             → "Platba DPH" card incl. QR (DPH returns)
 *   POST   /api/reports/submissions/{id}/payment/paid        → mark the liability as paid {paid_at?, amount?}
 *   DELETE /api/reports/submissions/{id}/payment/paid        → forget the payment
 *   GET    /api/reports/submissions/{id}/changes             → documents changed after filing
 *
 * Reads: admin / accountant / readonly. Writes: admin only (like deleting an archive row).
 */
final class TaxSubmissionFilingAction
{
    public function __construct(
        private readonly TaxSubmissionRepository $repo,
        private readonly TaxSubmissionFilingService $filing,
        private readonly VatPaymentService $payments,
        private readonly ChangesAfterFilingService $changes,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function markFiled(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId, ?int $userId, string $ip, string $ua) use ($request, $args): array {
            $body = (array) $request->getParsedBody();
            $row = $this->filing->markFiled(
                $supplierId,
                (int) ($args['id'] ?? 0),
                isset($body['filed_at']) ? (string) $body['filed_at'] : null,
                (string) ($body['channel'] ?? ''),
                isset($body['reference']) ? (string) $body['reference'] : null,
                $userId, $ip, $ua,
            );
            return $this->present($row);
        });
    }

    public function unfile(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn (int $supplierId, ?int $userId, string $ip, string $ua): array =>
            $this->present($this->filing->revertFiling($supplierId, (int) ($args['id'] ?? 0), $userId, $ip, $ua)));
    }

    public function uploadReceipt(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId, ?int $userId, string $ip, string $ua) use ($request, $args): array {
            $file = $request->getUploadedFiles()['file'] ?? null;
            if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
                throw new TaxFilingException('Soubor chybí.', 'no_file', 400);
            }
            $declared = $file->getSize() ?? $file->getStream()->getSize();
            if ($declared !== null && $declared > TaxSubmissionFilingService::RECEIPT_MAX_BYTES) {
                throw new TaxFilingException('Soubor je příliš velký (max 10 MiB).', 'file_too_large', 413);
            }
            $row = $this->filing->attachReceipt(
                $supplierId, (int) ($args['id'] ?? 0),
                (string) $file->getStream()->getContents(), (string) $file->getClientFilename(),
                $userId, $ip, $ua,
            );
            return $this->present($row);
        });
    }

    public function removeReceipt(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn (int $supplierId, ?int $userId, string $ip, string $ua): array =>
            $this->present($this->filing->removeReceipt($supplierId, (int) ($args['id'] ?? 0), $userId, $ip, $ua)));
    }

    public function downloadReceipt(Request $request, Response $response, array $args): Response
    {
        if (!$this->canRead($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $receipt = $this->repo->receipt((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        if ($receipt === null) {
            return Json::error($response, 'not_found', 'Potvrzení nenalezeno.', 404);
        }
        $response->getBody()->write($receipt['content']);
        return $response
            ->withHeader('Content-Type', $receipt['mime'] !== '' ? $receipt['mime'] : 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . addcslashes($receipt['name'], '"\\') . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function payment(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, $args, fn (array $row): array => $this->payments->card($row, withQr: true));
    }

    public function markPaid(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, function (int $supplierId, ?int $userId, string $ip, string $ua) use ($request, $args): array {
            $body = (array) $request->getParsedBody();
            return $this->payments->markPaid(
                $supplierId, (int) ($args['id'] ?? 0),
                isset($body['paid_at']) ? (string) $body['paid_at'] : null,
                isset($body['amount']) && is_numeric($body['amount']) ? (float) $body['amount'] : null,
                $userId, $ip, $ua,
            );
        });
    }

    public function clearPaid(Request $request, Response $response, array $args): Response
    {
        return $this->write($request, $response, fn (int $supplierId, ?int $userId, string $ip, string $ua): array =>
            $this->payments->clearPaid($supplierId, (int) ($args['id'] ?? 0), $userId, $ip, $ua));
    }

    public function changes(Request $request, Response $response, array $args): Response
    {
        return $this->read($request, $response, $args, fn (array $row): array => $this->changes->forSubmission($row));
    }

    /**
     * Read wrapper: any of admin / accountant / readonly may see the submission of the current supplier.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $view
     */
    private function read(Request $request, Response $response, array $args, callable $view): Response
    {
        if (!$this->canRead($request)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $row = $this->repo->findMeta((int) ($args['id'] ?? 0), SupplierGuard::currentId($request));
        return $row === null ? Json::error($response, 'not_found', 'Záznam nenalezen.', 404) : Json::ok($response, $view($row));
    }

    /**
     * Admin-only write wrapper: resolves the actor, maps TaxFilingException onto an API error.
     *
     * @param callable(int, ?int, string, string): array<string,mixed> $operation
     */
    private function write(Request $request, Response $response, callable $operation): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Pouze admin.', 403);
        }
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        try {
            $result = $operation(SupplierGuard::currentId($request), (int) ($user['id'] ?? 0) ?: null, $ip, $request->getHeaderLine('User-Agent'));
        } catch (TaxFilingException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->extra);
        }
        return Json::ok($response, $result);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        if ($row['form_code'] === VatPaymentService::FORM_CODE) {
            $row['payment'] = $this->payments->card($row);
        }
        return $row;
    }

    private function canRead(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true);
    }
}
