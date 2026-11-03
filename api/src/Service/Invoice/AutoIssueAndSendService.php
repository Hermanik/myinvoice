<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * After the client approves the work report (or the status is switched to 'approved' by hand):
 *  1. A draft invoice is issued (varsymbol + snapshots + status='issued')
 *  2. The PDF is rendered
 *  3. The standard `invoice_send` e-mail goes to the recipients from resolveRecipients()
 *  4. The status moves to 'sent'
 *  5. `invoice.issued` (only if it was a draft) + `invoice.sent` are logged
 *
 * Returns a summary for the audit/payload of the calling action.
 *
 * A payer-time domestic draft with every line on 0 % (typically prepared while the supplier was
 * still a non-payer) is HELD: it stays a draft and nothing is sent, exactly like the recurring
 * generator does ({@see InvoiceVatAdvisor}). The caller gets `held` + the advisor's `warnings`.
 *
 * @phpstan-type Result array{issued: bool, sent_to: list<string>, varsymbol: string|null, held?: bool, warnings?: list<array{code:string,message:string}>}
 */
final class AutoIssueAndSendService
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly InvoicePdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly InvoiceEmailVarsBuilder $varsBuilder,
        private readonly ActivityLogger $logger,
        private readonly StatsRecomputer $stats,
        private readonly PdfArchiveService $pdfArchive,
        private readonly RecipientResolver $recipients,
        private readonly InvoiceVatAdvisor $vatAdvisor,
    ) {}

    /**
     * @param int      $invoiceId
     * @param ?int     $userId   user, který akci spustil (může být null pro public approval)
     * @param string   $ip       IP pro audit log
     * @param string   $ua       User-Agent pro audit log
     * @return array{issued: bool, sent_to: list<string>, varsymbol: string|null, held?: bool, warnings?: list<array{code:string,message:string}>}
     */
    public function run(int $invoiceId, ?int $userId, string $ip, string $ua): array
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Invoice #$invoiceId not found");
        }

        $issued = false;
        // 1. Vystavit pokud draft
        if ($invoice['status'] === 'draft') {
            $warnings = $this->vatAdvisor->warnings((int) $invoice['supplier_id'], $invoice);
            if ($warnings !== []) {
                // Same hold as the recurring generator: keep the draft, issue and send nothing.
                $this->logger->log('invoice.auto_issue_held', $userId, 'invoice', $invoiceId, [
                    'reason'      => $warnings[0]['code'],
                    'auto_reason' => 'work_report_approved',
                ], $ip, $ua);
                return [
                    'issued'    => false,
                    'sent_to'   => [],
                    'varsymbol' => $invoice['varsymbol'] ?? null,
                    'held'      => true,
                    'warnings'  => $warnings,
                ];
            }
            if (
                InvoiceAmountPolicy::requiresPositiveDraftAmountToPay(
                    (string) ($invoice['invoice_type'] ?? 'invoice'),
                    $invoice['parent_invoice_id'] ?? null,
                )
                && !InvoiceAmountPolicy::hasPositiveAmountToPay($invoice)
            ) {
                throw new \DomainException(InvoiceAmountPolicy::NON_POSITIVE_DRAFT_MESSAGE);
            }
            // VS + snapshoty — pokud už nebyly alokované předem (request-approval flow),
            // alokuj teď.
            $invoice = $this->allocateVarsymbolAndSnapshots($invoiceId);
            $this->db->pdo()->prepare(
                'UPDATE invoices SET status = "issued" WHERE id = ? AND status = "draft"'
            )->execute([$invoiceId]);
            $issued = true;
            $this->logger->log('invoice.issued', $userId, 'invoice', $invoiceId, [
                'varsymbol'   => $invoice['varsymbol'],
                'auto_reason' => 'work_report_approved',
            ], $ip, $ua);
            $this->stats->recomputeForInvoiceId($invoiceId);
            // Re-invalidate i po flipu na 'issued' — kdyby si někdo mezi alokací VS
            // a tímto blokem vyrenderoval Faktura-VS.pdf jako draft (bez "issued"
            // metadat), nahradíme ho čerstvým renderem.
            $this->renderer->invalidate($invoiceId, 'invalidate_issue');
            $invoice = $this->repo->find($invoiceId);
        }

        // 2. PDF
        $pdfPath = $this->renderer->render($invoiceId, false, $userId);

        // 3. Příjemci — jednotný resolver (#86), účel `documents`, včetně kopie
        //    dodavateli (supplier.self_copy / cfg smtp.cc_supplier_on_send).
        $r = $this->recipients->resolve(RecipientResolver::TYPE_DOCUMENTS, $invoice);
        $to = $r['to'];
        $bcc = $r['bcc'];
        $cc = $r['cc'];

        if (empty($to)) {
            // Faktura je vystavená, ale nemá komu poslat — vrátit info, caller rozhodne co dál
            return ['issued' => $issued, 'sent_to' => [], 'varsymbol' => $invoice['varsymbol'] ?? null];
        }

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->varsBuilder->build($invoice, false, $locale);

        try {
            $this->mailer->sendTemplate(
                'invoice_send',
                $locale,
                $to,
                $vars,
                null,
                $cc,
                $bcc,
                [['path' => $pdfPath, 'name' => basename($pdfPath), 'contentType' => 'application/pdf']],
                $userId,
            );
        } catch (\Throwable $e) {
            // Auto-send po schválení výkazu — selhání zalogujeme do přehledu e-mailů
            // a propustíme dál (caller v approval flow si ho ošetří).
            $this->logger->log('invoice.send_failed', $userId, 'invoice', $invoiceId, [
                'to' => $to, 'cc' => $cc,
                'auto_reason' => 'work_report_approved',
                'error' => mb_substr($e->getMessage(), 0, 500),
            ], $ip, $ua);
            throw $e;
        }

        $newStatus = $invoice['status'] === 'issued' ? 'sent' : $invoice['status'];
        $this->db->pdo()->prepare('UPDATE invoices SET status = ?, sent_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $invoiceId]);

        // Archivuj kopii PDF jako 'sent' verzi — viz SendEmailAction
        $sentToAll = array_values(array_unique(array_merge($to, $cc, $bcc)));
        $archiveId = $this->pdfArchive->archiveCopy($invoiceId, $pdfPath, 'sent', wasSent: true, sentTo: $sentToAll);

        $this->logger->log('invoice.sent', $userId, 'invoice', $invoiceId, [
            'to' => $to, 'cc' => $cc, 'bcc' => $bcc,
            'resolved_recipients' => $r['resolved'],
            'pdf_path' => basename($pdfPath),
            'pdf_archive_id' => $archiveId,
            'auto_reason' => 'work_report_approved',
        ], $ip, $ua);

        return ['issued' => $issued, 'sent_to' => $to, 'varsymbol' => $invoice['varsymbol'] ?? null];
    }

    /**
     * Alokuje varsymbol + zafixuje supplier/client/bank snapshoty na faktuře, pokud
     * ještě neexistují. Status zůstává 'draft'.
     *
     * Volá se ze dvou míst:
     *  - RequestApprovalAction: před odesláním žádosti o schválení, aby Vykaz-XYZ.pdf
     *    obsahoval reálný varsymbol (ne "draft-NN") a aby snapshoty odpovídaly stavu
     *    v okamžiku, kdy klient schvaluje (kdyby se mezitím editoval supplier/klient).
     *  - run() (níže): pokud schválení proběhlo bez prior request-approval (např.
     *    admin manuálně klikl "Schváleno"), alokuj teď.
     *
     * Idempotentní: pokud má faktura už VS, vrátí ji beze změny.
     *
     * @return array  čerstvá faktura (post-alokace)
     */
    public function allocateVarsymbolAndSnapshots(int $invoiceId): array
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Invoice #$invoiceId not found");
        }
        if (!empty($invoice['varsymbol'])) {
            return $invoice;
        }
        if ($invoice['status'] !== 'draft') {
            // Non-draft bez VS by neměl existovat; nic nealokujeme, vrať jak je.
            return $invoice;
        }

        $issueDate  = new \DateTimeImmutable($invoice['issue_date']);
        $supplierId = (int) $invoice['supplier_id'];
        $varsymbol  = $this->varsymbol->next($supplierId, $invoice['invoice_type'], $issueDate, (int) $invoice['client_id']);
        $snapshots  = $this->snapshots->build(
            (int) $invoice['client_id'],
            (int) $invoice['currency_id'],
            $supplierId,
            isset($invoice['branding_profile_id']) ? (int) $invoice['branding_profile_id'] : null,
            VatStatusService::documentDateOf($invoice),
        );

        $stmt = $this->db->pdo()->prepare(
            'UPDATE invoices SET
                varsymbol         = ?,
                client_snapshot   = ?,
                supplier_snapshot = ?,
                bank_snapshot     = ?
             WHERE id = ? AND status = "draft" AND varsymbol IS NULL'
        );
        $stmt->execute([
            $varsymbol,
            json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
            json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
            $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
            $invoiceId,
        ]);
        // Cache invalidace: dosavadní pdf_path mířil na "Faktura-draft-NN.pdf",
        // nový cachePath bude "Faktura-VS.pdf". Stará kopie je jen draft preview
        // (žádný odeslaný doklad) — smaž ji bez archive entry, ať historie
        // neobsahuje šum.
        $this->renderer->invalidate($invoiceId, 'invalidate_allocate', archive: false);

        return $this->repo->find($invoiceId);
    }
}
