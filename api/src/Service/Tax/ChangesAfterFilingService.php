<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\ReportPeriod;
use MyInvoice\Service\Vat\VatStatusService;

/**
 * "Doklady změněné po podání": issued / received documents whose DUZP lies in the period of a
 * filed (or confirmed) return and that were created, issued, edited, cancelled or deleted AFTER
 * the return was filed. Such a document can make the filed return wrong — a corrective ("opravné",
 * only before the deadline) or a supplementary ("dodatečné") filing may be needed.
 *
 * Sources: `created_at` / `cancelled_at` of the documents and the activity log
 * (edits, issuing, deleting). Drafts are not part of any return and are ignored. A deleted
 * document is only placeable into a period when the log carries its DUZP — the delete actions
 * write `tax_date` / `issue_date` into the log payload.
 */
final class ChangesAfterFilingService
{
    /** Activity log actions that change what a sales document contributes to a return. */
    private const SALE_EVENTS = [
        'invoice.created'          => 'created',
        'invoice.issued'           => 'issued',
        'invoice.updated'          => 'edited',
        'invoice.force_edit'       => 'edited',
        'invoice.rebuild_snapshots' => 'edited',
        'invoice.reissued_bulk'    => 'edited',
        'invoice.cancelled'        => 'cancelled',
    ];

    private const PURCHASE_EVENTS = [
        'purchase_invoice.created'               => 'created',
        'purchase_invoice.updated'               => 'edited',
        'purchase_invoice.force_edit'            => 'edited',
        'purchase_invoice.items_updated'         => 'edited',
        'purchase_invoice.document_kind_changed' => 'edited',
        'purchase_invoice.exchange_rate_set'     => 'edited',
    ];

    /** The returns built from the VAT ledger (DPH, KH, SH). */
    public const VAT_FORMS = ['dphdp3', 'dphkh1', 'dphshv'];

    public function __construct(
        private readonly Connection $db,
        private readonly VatStatusService $vatStatus,
    ) {}

    /**
     * Changes since the submission was filed.
     *
     * @param array<string,mixed> $submission a repository row (filed or confirmed)
     * @return array{filed_at:?string, period_start:string, period_end:string, items:list<array<string,mixed>>, count:int}
     */
    public function forSubmission(array $submission): array
    {
        if (!in_array($submission['form_code'] ?? '', self::VAT_FORMS, true)) {
            return ['filed_at' => null, 'period_start' => '', 'period_end' => '', 'items' => [], 'count' => 0];
        }
        $period = VatPaymentService::period($submission);
        [$start, $end] = ReportPeriod::range($period['year'], $period['month'], $period['period']);
        $filedAt = $submission['filed_at'] ?? null;
        if (!in_array($submission['filing_status'] ?? 'generated', ['filed', 'confirmed'], true) || $filedAt === null) {
            return ['filed_at' => null, 'period_start' => $start, 'period_end' => $end, 'items' => [], 'count' => 0];
        }
        $items = $this->changes((int) $submission['supplier_id'], (string) $filedAt, $start, $end);
        return ['filed_at' => (string) $filedAt, 'period_start' => $start, 'period_end' => $end, 'items' => $items, 'count' => count($items)];
    }

    /**
     * @return list<array<string,mixed>> documents with their changes, sorted by the first change
     */
    public function changes(int $supplierId, string $filedAt, string $start, string $end): array
    {
        $history = $this->vatStatus->history($supplierId);
        $items = array_merge(
            $this->liveDocuments($supplierId, $filedAt, $start, $end, sale: true),
            $this->liveDocuments($supplierId, $filedAt, $start, $end, sale: false),
            $this->deletedDocuments($supplierId, $filedAt, $start, $end),
        );
        // Only documents that belong to a time with VAT duties could ever be in a return.
        $items = array_values(array_filter(
            $items,
            static fn (array $d): bool => $d['tax_date'] === null || $history->hasVatDutiesAt((string) $d['tax_date']),
        ));
        usort($items, static fn (array $a, array $b): int => [$a['changes'][0]['at'], $a['source'], (int) $a['id']] <=> [$b['changes'][0]['at'], $b['source'], (int) $b['id']]);
        return $items;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function liveDocuments(int $supplierId, string $filedAt, string $start, string $end, bool $sale): array
    {
        $pdo = $this->db->pdo();
        $events = $sale ? self::SALE_EVENTS : self::PURCHASE_EVENTS;
        $entity = $sale ? 'invoice' : 'purchase_invoice';
        $actions = implode(',', array_map(static fn (string $a): string => "'{$a}'", array_keys($events)));

        if ($sale) {
            $sql = "SELECT d.id, d.varsymbol AS doc_number, d.status, d.invoice_type AS kind,
                           COALESCE(d.tax_date, d.issue_date) AS tax_date, d.total_with_vat,
                           d.created_at, d.cancelled_at,
                           c.company_name AS counterparty, cur.code AS currency
                      FROM invoices d
                 LEFT JOIN clients c ON c.id = d.client_id
                 LEFT JOIN currencies cur ON cur.id = d.currency_id
                     WHERE d.supplier_id = ? AND d.status <> 'draft'
                       AND d.invoice_type NOT IN ('proforma')
                       AND COALESCE(d.tax_date, d.issue_date) BETWEEN ? AND ?";
            $params = [$supplierId, $start, $end];
        } else {
            // A received document counts to the later of DUZP and issue date (§ 73 ZDPH); the
            // foreign reverse-charge ones to the DUZP — take both so none is missed.
            $sql = "SELECT d.id, COALESCE(d.vendor_invoice_number, d.varsymbol) AS doc_number, d.status, d.document_kind AS kind,
                           COALESCE(d.tax_date, d.issue_date) AS tax_date, d.total_with_vat,
                           d.created_at, d.cancelled_at,
                           c.company_name AS counterparty, cur.code AS currency
                      FROM purchase_invoices d
                 LEFT JOIN clients c ON c.id = d.vendor_id
                 LEFT JOIN currencies cur ON cur.id = d.currency_id
                     WHERE d.supplier_id = ? AND d.status <> 'draft'
                       AND d.document_kind <> 'advance'
                       AND (COALESCE(d.tax_date, d.issue_date) BETWEEN ? AND ?
                            OR GREATEST(COALESCE(d.tax_date, d.issue_date), d.issue_date) BETWEEN ? AND ?)";
            $params = [$supplierId, $start, $end, $start, $end];
        }
        $sql .= " AND (d.created_at > ? OR d.cancelled_at > ?
                   OR EXISTS (SELECT 1 FROM activity_log a
                            WHERE a.entity_type = '{$entity}' AND a.entity_id = d.id
                              AND a.created_at > ? AND a.action IN ({$actions})))";
        $stmt = $pdo->prepare($sql);
        // created_at, cancelled_at and the activity-log EXISTS — all compared with the filing time.
        $stmt->execute([...$params, $filedAt, $filedAt, $filedAt]);
        $docs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($docs === []) {
            return [];
        }

        $ids = array_map(static fn (array $d): int => (int) $d['id'], $docs);
        $logStmt = $pdo->prepare(
            "SELECT entity_id, action, user_id, created_at FROM activity_log
              WHERE entity_type = ? AND created_at > ? AND action IN ({$actions})
                AND entity_id IN (" . implode(',', $ids) . ')
           ORDER BY created_at, id'
        );
        $logStmt->execute([$entity, $filedAt]);
        $logged = [];
        foreach ($logStmt->fetchAll(\PDO::FETCH_ASSOC) as $e) {
            $logged[(int) $e['entity_id']][] = ['type' => $events[$e['action']], 'at' => (string) $e['created_at'], 'user_id' => $e['user_id'] !== null ? (int) $e['user_id'] : null];
        }

        $out = [];
        foreach ($docs as $d) {
            $changes = $logged[(int) $d['id']] ?? [];
            $logTypes = array_column($changes, 'type');
            if (!in_array('created', $logTypes, true) && (string) $d['created_at'] > $filedAt) {
                $changes[] = ['type' => 'created', 'at' => (string) $d['created_at'], 'user_id' => null];
            }
            if ($d['cancelled_at'] !== null && (string) $d['cancelled_at'] > $filedAt && !in_array('cancelled', $logTypes, true)) {
                $changes[] = ['type' => 'cancelled', 'at' => (string) $d['cancelled_at'], 'user_id' => null];
            }
            if ($changes === []) {
                continue;
            }
            usort($changes, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $out[] = [
                'source'         => $sale ? 'sale' : 'purchase',
                'id'             => (int) $d['id'],
                'doc_number'     => $d['doc_number'],
                'kind'           => $d['kind'],
                'status'         => $d['status'],
                'tax_date'       => $d['tax_date'],
                'counterparty'   => $d['counterparty'],
                'total_with_vat' => $d['total_with_vat'] !== null ? (float) $d['total_with_vat'] : null,
                'currency'       => $d['currency'],
                'deleted'        => false,
                'changes'        => $changes,
            ];
        }
        return $out;
    }

    /**
     * Documents deleted after filing; their DUZP comes from the audit payload.
     *
     * @return list<array<string,mixed>>
     */
    private function deletedDocuments(int $supplierId, string $filedAt, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT entity_type, entity_id, user_id, payload, created_at
               FROM activity_log
              WHERE supplier_id = ? AND created_at > ?
                AND action IN ('invoice.force_deleted', 'purchase_invoice.deleted')
           ORDER BY created_at, id"
        );
        $stmt->execute([$supplierId, $filedAt]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }
            $sale = $row['entity_type'] === 'invoice';
            // Drafts (and the proforma / advance documents) never were part of a return.
            if (($payload['status_before'] ?? null) === 'draft'
                || ($sale && ($payload['type'] ?? null) === 'proforma')
                || (!$sale && ($payload['document_kind'] ?? null) === 'advance')
            ) {
                continue;
            }
            $taxDate = (string) ($payload['tax_date'] ?? $payload['issue_date'] ?? '');
            if ($taxDate === '' || $taxDate < $start || $taxDate > $end) {
                continue;
            }
            $out[] = [
                'source'         => $sale ? 'sale' : 'purchase',
                'id'             => (int) $row['entity_id'],
                'doc_number'     => $payload['varsymbol'] ?? null,
                'kind'           => $payload['type'] ?? ($payload['document_kind'] ?? null),
                'status'         => 'deleted',
                'tax_date'       => $taxDate,
                'counterparty'   => null,
                'total_with_vat' => isset($payload['total']) ? (float) $payload['total'] : null,
                'currency'       => $payload['currency'] ?? null,
                'deleted'        => true,
                'changes'        => [['type' => 'deleted', 'at' => (string) $row['created_at'], 'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null]],
            ];
        }
        return $out;
    }
}
