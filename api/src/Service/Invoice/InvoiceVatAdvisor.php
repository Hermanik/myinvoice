<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * Non-blocking VAT hints for a draft invoice. A 0 % rate is legal for a VAT payer (exempt supplies),
 * so it is never an error — but a document whose DUZP lies in payer time and whose lines are ALL
 * on 0 % is usually a template or draft that was prepared while the supplier was still a
 * non-payer (the exempt rate is what a non-payer gets), and would go out without VAT.
 *
 * Callers show the hints before issuing (editor / detail banner, `warnings` in the API response)
 * and the recurring generator keeps such an invoice as a draft instead of auto-issuing it.
 */
final class InvoiceVatAdvisor
{
    public const PAYER_ZERO_RATED = 'payer_zero_rated';

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly VatStatusService $vatStatus,
    ) {}

    /**
     * @param array<string,mixed> $invoice a stored invoice (InvoiceRepository::find): status, invoice_type,
     *        client_id, tax_date / issue_date, reverse_charge and items[].vat_rate_snapshot / oss_applicable
     * @return list<array{code: string, message: string}>
     */
    public function warnings(int $supplierId, array $invoice): array
    {
        if ((string) ($invoice['status'] ?? 'draft') !== 'draft'
            || !in_array((string) ($invoice['invoice_type'] ?? 'invoice'), ['invoice', 'proforma'], true)
            || !empty($invoice['reverse_charge'])
        ) {
            return [];
        }
        // Cheapest check first: every line must be a 0 % domestic line (the stored rate snapshot).
        $items = (array) ($invoice['items'] ?? []);
        foreach ($items as $item) {
            if (!empty($item['oss_applicable']) || !isset($item['vat_rate_snapshot']) || (float) $item['vat_rate_snapshot'] > 0.0) {
                return [];
            }
        }
        if ($items === []
            || !$this->vatStatus->isPayerAt($supplierId, VatStatusService::documentDateOf($invoice))
            || !$this->invoices->clientIsDomestic((int) ($invoice['client_id'] ?? 0))
        ) {
            return [];
        }

        return [[
            'code'    => self::PAYER_ZERO_RATED,
            'message' => 'Doklad má DUZP v době, kdy jste plátcem DPH, ale všechny položky jsou na 0 % sazbě. '
                . 'Pokud jde o běžné zdanitelné plnění, vyberte před vystavením u položek skutečnou sazbu DPH '
                . '(0 % je u plátce v pořádku jen u osvobozených plnění).',
        ]];
    }
}
