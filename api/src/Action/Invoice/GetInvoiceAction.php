<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Currency\ExchangeRateApplier;
use MyInvoice\Service\Invoice\InvoiceVatAdvisor;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetInvoiceAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ExchangeRateApplier $rateApplier,
        private readonly InvoiceVatAdvisor $vatAdvisor,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $sid = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        $invoice = $this->repo->find($id);
        if ($invoice === null || (int) ($invoice['supplier_id'] ?? 0) !== $sid) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Backfill kurzu pro starší / chybějící exchange_rate (cache → ČNB → last known)
        if (
            (string) ($invoice['currency'] ?? 'CZK') !== 'CZK'
            && empty($invoice['exchange_rate'])
        ) {
            $this->rateApplier->ensureRate($id);
            $invoice = $this->repo->find($id);
        }

        // Hints before issuing (e.g. a payer's draft with every line on 0 %); empty for issued invoices.
        $invoice['warnings'] = $this->vatAdvisor->warnings($sid, $invoice);

        return Json::ok($response, $invoice);
    }
}
