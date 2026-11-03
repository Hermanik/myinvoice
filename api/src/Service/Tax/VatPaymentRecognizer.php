<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;

/**
 * Recognises the payment of a filed DPH liability on an outgoing bank transaction.
 *
 * Hooked into {@see \MyInvoice\Service\Bank\StatementMatcher::match()}, which every source goes
 * through (GPC / PDF statement import, e-mail notice, iDoklad, manual re-match) — so one place
 * covers them all. A transaction is a VAT payment when ALL of these hold:
 *   - it is an outgoing CZK payment of a supplier that has an open (filed, unpaid) DPH liability,
 *   - the counterparty account is the VAT account of the supplier's office (705-matrika/0710),
 *   - the variable symbol is the DIČ root of the supplier,
 *   - the amount equals the amount still due (whole CZK; a part payment is left to the user).
 * Among several liabilities of the same amount the oldest period wins ("pay the oldest first").
 *
 * The transaction is then consumed like an ignored one (`ignore_origin = vat_payment`, with a
 * note) so it stops showing up as unmatched, and the liability is marked paid with a link to it.
 * Unmatching the transaction in the bank UI reopens the liability ({@see VatPaymentService::releaseTransaction()}).
 *
 * The same payment can arrive twice (e-mail notice first, the official statement later); the
 * second transaction is recognised as the twin of the first and consumed as well.
 *
 * The usual order is "pay first, record the filing later", and a return that is not marked as filed
 * has no liability to match — {@see reconsider()} catches up when the filing is recorded.
 */
final class VatPaymentRecognizer
{
    /** A notice and its statement line may carry different posting dates. */
    private const TWIN_WINDOW_DAYS = 5;
    private const AMOUNT_TOLERANCE = 0.005;
    /** How far around the due date of a return a payment made before the filing was recorded is looked for. */
    private const RECONSIDER_WINDOW_DAYS = 60;

    public function __construct(
        private readonly VatPaymentService $payments,
        private readonly TaxSubmissionRepository $repo,
        private readonly Connection $db,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param array<string,mixed> $tx a bank_transactions row joined with its statement (as loaded by StatementMatcher::match())
     * @return array{status:'vat_payment', submission_id:int, twin:bool}|null null = not a VAT payment
     */
    public function recognize(int $supplierId, array $tx): ?array
    {
        $amount = (float) ($tx['amount'] ?? 0);
        if ($amount >= 0 || ($tx['match_status'] ?? 'unmatched') === 'ignored') {
            return null;
        }
        $currency = strtoupper((string) (($tx['currency'] ?? '') !== '' ? $tx['currency'] : ($tx['statement_currency'] ?? '')));
        if ($currency !== '' && $currency !== 'CZK') {
            return null;
        }
        $counterparty = (string) ($tx['counterparty_account'] ?? '');
        $vs = VariableSymbolNormalizer::forMatching((string) ($tx['variable_symbol'] ?? ''));
        if ($vs === '') {
            return null;
        }
        // Cheap guard first: the account must be a VAT account of ANY office before any query runs.
        if (!$this->looksLikeVatAccount($counterparty, $tx['counterparty_bank'] ?? null)) {
            return null;
        }
        $supplier = $this->supplier($supplierId);
        $account = FinancialOfficeAccounts::vatAccount($supplier['financial_office_code'] ?? null);
        $supplierVs = FinancialOfficeAccounts::variableSymbol($supplier['dic'] ?? null);
        if ($account === null || $supplierVs === null
            || !FinancialOfficeAccounts::accountMatches($counterparty, $tx['counterparty_bank'] ?? null, $account)
            || VariableSymbolNormalizer::forMatching($supplierVs) !== $vs
        ) {
            return null;
        }

        $paidAmount = abs($amount);
        foreach ($this->payments->unpaidLiabilities($supplierId) as $card) {
            if (abs($card['amount_due'] - $paidAmount) > self::AMOUNT_TOLERANCE) {
                continue;
            }
            $this->consume($supplierId, (int) $card['submission_id'], $tx, $paidAmount, $card['period_label'], null);
            return ['status' => 'vat_payment', 'submission_id' => (int) $card['submission_id'], 'twin' => false];
        }

        $twin = $this->findPaidTwin($supplierId, $tx, $paidAmount);
        if ($twin !== null) {
            $this->consume($supplierId, (int) $twin['id'], $tx, $paidAmount, VatPaymentService::period($twin)['label'], $twin);
            return ['status' => 'vat_payment', 'submission_id' => (int) $twin['id'], 'twin' => true];
        }
        return null;
    }

    /**
     * Looks for the payment of a return that has just been marked as filed: re-runs {@see recognize()} — the
     * very same exact-match rules — over the supplier's unmatched outgoing transactions of the amount still
     * due, posted within ±60 days of the due date of the period. Idempotent: a recognised transaction is
     * consumed, so a repeated call finds nothing more.
     *
     * @return int number of bank transactions recognised
     */
    public function reconsider(int $supplierId, int $submissionId): int
    {
        $submission = $this->repo->findMeta($submissionId, $supplierId);
        $card = $submission !== null ? $this->payments->card($submission) : null;
        if ($card === null || !$card['payable']) {
            return 0;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.match_status = 'unmatched' AND bt.amount < 0
                AND ABS(ABS(bt.amount) - ?) <= ?
                AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)
           ORDER BY bt.posted_at, bt.id"
        );
        $stmt->execute([
            number_format((float) $card['amount_due'], 2, '.', ''), self::AMOUNT_TOLERANCE,
            $card['due_date'], self::RECONSIDER_WINDOW_DAYS, $card['due_date'], self::RECONSIDER_WINDOW_DAYS,
        ]);
        $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($candidates === []) {
            return 0;
        }
        $accounts = $this->bankAccounts($supplierId);
        $recognised = 0;
        foreach ($candidates as $tx) {
            if (!$this->belongsToSupplier($accounts, $tx)) {
                continue;
            }
            if ($this->recognize($supplierId, $tx) !== null) {
                $recognised++;
            }
        }
        return $recognised;
    }

    /**
     * @param list<array<string,mixed>> $accounts the supplier's bank accounts
     * @param array<string,mixed> $tx a transaction joined with its statement
     */
    private function belongsToSupplier(array $accounts, array $tx): bool
    {
        $statementAccount = (string) ($tx['recipient_account'] ?? '');
        if ($statementAccount === '') {
            return false;
        }
        $bank = isset($tx['recipient_bank']) ? (string) $tx['recipient_bank'] : null;
        foreach ($accounts as $account) {
            if (AccountNumberNormalizer::statementBelongsTo($account, $statementAccount, $bank)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string,mixed>> */
    private function bankAccounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT account_number, iban, bank_code FROM currencies
              WHERE supplier_id = ? AND (account_number IS NOT NULL OR iban IS NOT NULL)'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function looksLikeVatAccount(string $account, mixed $bank): bool
    {
        $parsed = FinancialOfficeAccounts::parseAccount($account, is_string($bank) ? $bank : null);
        return $parsed !== null
            && $parsed['prefix'] === FinancialOfficeAccounts::VAT_PREFIX
            && ($parsed['bank_code'] === null || $parsed['bank_code'] === FinancialOfficeAccounts::BANK_CODE);
    }

    /**
     * A liability already paid by another bank transaction that is the same payment as this one
     * (same amount, same posting window, a different source).
     *
     * @param array<string,mixed> $tx
     * @return array<string,mixed>|null
     */
    private function findPaidTwin(int $supplierId, array $tx, float $paidAmount): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id AS submission_id, bt.id AS tx_id, bt.source AS tx_source
               FROM tax_submissions t
               JOIN bank_transactions bt ON bt.id = t.paid_bank_transaction_id
              WHERE t.supplier_id = ? AND t.form_code = ? AND t.paid_source = 'bank'
                AND ABS(ABS(bt.amount) - ?) <= ?
                AND bt.id <> ?
                AND bt.posted_at BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)"
        );
        $stmt->execute([
            $supplierId, VatPaymentService::FORM_CODE, number_format($paidAmount, 2, '.', ''), self::AMOUNT_TOLERANCE,
            $tx['id'], $tx['posted_at'], self::TWIN_WINDOW_DAYS, $tx['posted_at'], self::TWIN_WINDOW_DAYS,
        ]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        // Exactly one candidate, otherwise leave it to the user.
        if (count($rows) !== 1 || (string) $rows[0]['tx_source'] === (string) ($tx['source'] ?? 'statement')) {
            return null;
        }
        return $this->repo->findMeta((int) $rows[0]['submission_id'], $supplierId);
    }

    /**
     * @param array<string,mixed> $tx
     * @param array<string,mixed>|null $twinOf the already paid submission when $tx is the second record of the same payment
     */
    private function consume(int $supplierId, int $submissionId, array $tx, float $paidAmount, string $periodLabel, ?array $twinOf): void
    {
        $twin = $twinOf !== null;
        $txId = (int) $tx['id'];
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            if (!$twin) {
                $this->repo->markPaid($submissionId, (string) $tx['posted_at'], $paidAmount, 'bank', $txId);
            } elseif (($tx['source'] ?? '') === 'statement') {
                // The official statement line is the authoritative record of the payment.
                $this->repo->markPaid($submissionId, (string) $twinOf['paid_at'], (float) $twinOf['paid_amount'], 'bank', $txId);
            }
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET match_status = 'ignored', ignore_origin = 'vat_payment', ignore_note = ?, matched_at = NOW()
                  WHERE id = ?"
            )->execute([
                sprintf('Platba DPH za %s (podání #%d)%s', $periodLabel, $submissionId, $twin ? ' — duplicitní záznam téže platby' : ''),
                $txId,
            ]);
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        try {
            $this->logger->log('tax_submission.payment_recognized', null, 'tax_submission', $submissionId, [
                'bank_transaction_id' => $txId,
                'amount'              => $paidAmount,
                'variable_symbol'     => $tx['variable_symbol'] ?? null,
                'twin'                => $twin ?: null,
            ], null, null, $supplierId);
        } catch (\Throwable) {
            // Audit logging must never break the bank matching.
        }
    }

    /** @return array<string,mixed> */
    private function supplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT dic, financial_office_code FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
