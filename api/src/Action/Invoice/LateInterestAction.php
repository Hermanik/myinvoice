<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\LateInterest\LateInterestException;
use MyInvoice\Service\LateInterest\LateInterestInvoiceCreator;
use MyInvoice\Service\LateInterest\LateInterestService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Statutory late-payment interest (úrok z prodlení) of one invoice:
 *   GET  /api/invoices/{id}/late-interest           accrued interest + opt-in state (?as_of=YYYY-MM-DD)
 *   PUT  /api/invoices/{id}/late-interest           per-invoice override: {late_interest: true|false|null}
 *   POST /api/invoices/{id}/late-interest-invoice   creates the DRAFT interest invoice
 */
final class LateInterestAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly LateInterestService $interest,
        private readonly LateInterestInvoiceCreator $creator,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        $invoice = $this->repo->find((int) ($args['id'] ?? 0));
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $asOf = self::asOf($request->getQueryParams()['as_of'] ?? null);
        if ($asOf === null) {
            return Json::error($response, 'validation_failed', 'Neplatné datum as_of.', 422);
        }
        return Json::ok($response, $this->payload($invoice, $asOf));
    }

    public function setOverride(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        if (!array_key_exists('late_interest', $body) || ($body['late_interest'] !== null && !is_bool($body['late_interest']))) {
            return Json::error($response, 'validation_failed', 'late_interest musí být true, false nebo null.', 422);
        }
        $this->repo->setLateInterest($id, $body['late_interest']);

        $this->audit($request, 'invoice.late_interest_override', $id, ['late_interest' => $body['late_interest']]);
        return Json::ok($response, $this->payload($this->repo->find($id) ?? $invoice, date('Y-m-d')));
    }

    public function createInvoice(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $asOf = self::asOf($body['as_of'] ?? null);
        if ($asOf === null || $asOf > date('Y-m-d')) {
            return Json::error($response, 'validation_failed', 'Datum, ke kterému se úrok vyúčtovává, musí být platné a nesmí být v budoucnu.', 422);
        }
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);

        try {
            $created = $this->creator->create($invoice, $asOf, (bool) ($body['include_fixed_cost'] ?? true), (int) ($user['id'] ?? 0));
        } catch (LateInterestException $e) {
            return Json::error($response, 'invalid_state', $e->getMessage(), 409);
        }

        $this->audit($request, 'invoice.late_interest_invoice_created', $id, [
            'interest_invoice_id' => $created['invoice_id'],
            'period_from'         => $created['period_from'],
            'period_to'           => $created['period_to'],
            'interest'            => $created['interest'],
            'fixed_cost'          => $created['fixed_cost'],
        ]);

        return Json::ok($response, [
            'invoice'  => $this->repo->find($created['invoice_id']),
            'warnings' => $created['warnings'],
        ], 201);
    }

    /** @return array<string,mixed> */
    private function payload(array $invoice, string $asOf): array
    {
        return $this->interest->evaluate($invoice, $asOf) + [
            'invoice_id' => (int) $invoice['id'],
            'as_of'      => $asOf,
            'claims'     => $this->interest->claims((int) $invoice['id']),
        ];
    }

    private static function asOf(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return date('Y-m-d');
        }
        $raw = (string) $raw;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && date('Y-m-d', (int) strtotime($raw)) === $raw ? $raw : null;
    }

    private function audit(Request $request, string $action, int $invoiceId, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'invoice', $invoiceId, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
