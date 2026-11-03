<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use MyInvoice\Service\Report\ReportPeriod;

/**
 * Payment of the own VAT liability of a filed DPH return (DPHDP3).
 *
 * The liability is the row of the form itself: row 64 (vlastní daňová povinnost, Veta6 `dano_da`);
 * row 66 (nadměrný odpočet, `dano_no`) is a refund the tax office pays out — nothing to pay.
 * The tax is due on the filing deadline (25th of the month after the period, § 98/1 ZDPH, moved to
 * the next working day) to the VAT account of the supplier's office ({@see FinancialOfficeAccounts}),
 * variable symbol = DIČ root.
 *
 * Several submissions of one period (an "opravné" O after a "řádné" B) can be filed: the LAST filed
 * one is the effective return, the earlier ones are superseded. What was already paid towards the
 * superseded ones is deducted from what the effective one still asks for.
 */
final class VatPaymentService
{
    public const FORM_CODE = 'dphdp3';

    public function __construct(
        private readonly TaxSubmissionRepository $repo,
        private readonly Connection $db,
        private readonly QrPaymentGenerator $qr,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * The liability in whole CZK as filed on rows 64 / 66.
     *
     * @param array<string,mixed> $submission
     * @return array{kind:'payment'|'refund'|'none', amount:int}
     */
    public static function liability(array $submission): array
    {
        if (($submission['form_code'] ?? '') !== self::FORM_CODE) {
            return ['kind' => 'none', 'amount' => 0];
        }
        $summary = (array) ($submission['summary'] ?? []);
        if (array_key_exists('line_64_dano_da', $summary) || array_key_exists('line_66_dano_no', $summary)) {
            $due = (int) ($summary['line_64_dano_da'] ?? 0);
            $refund = (int) ($summary['line_66_dano_no'] ?? 0);
        } else {
            // Archived before the summary carried the rows: the signed tax of the return.
            $signed = (float) ($summary['tax_due'] ?? 0);
            $due = $signed > 0 ? (int) round($signed) : 0;
            $refund = $signed < 0 ? (int) round(-$signed) : 0;
        }
        if ($due > 0) {
            return ['kind' => 'payment', 'amount' => $due];
        }
        return $refund > 0 ? ['kind' => 'refund', 'amount' => $refund] : ['kind' => 'none', 'amount' => 0];
    }

    /**
     * @param array<string,mixed> $submission
     * @return array{year:int, month:int, quarter:?int, period:'monthly'|'quarterly', label:string}
     */
    public static function period(array $submission): array
    {
        $year = (int) $submission['period_year'];
        $quarter = isset($submission['period_quarter']) ? (int) $submission['period_quarter'] : null;
        if ($quarter !== null) {
            return ['year' => $year, 'month' => $quarter * 3, 'quarter' => $quarter, 'period' => 'quarterly',
                    'label' => sprintf('%d Q%d', $year, $quarter)];
        }
        $month = (int) $submission['period_month'];
        return ['year' => $year, 'month' => $month, 'quarter' => null, 'period' => 'monthly',
                'label' => sprintf('%d-%02d', $year, $month)];
    }

    /** @param array<string,mixed> $submission */
    public static function dueDate(array $submission): string
    {
        $p = self::period($submission);
        return ReportPeriod::filingDeadline($p['year'], $p['month'], $p['period']);
    }

    /**
     * The payment card of a DPH submission.
     *
     * @param array<string,mixed> $submission a row from the repository (summary decoded)
     * @param list<array<string,mixed>>|null $filed filed rows of the same period when the caller has them
     * @param array<string,mixed>|null $supplier the supplier row (company_name, dic, financial_office_code) when the caller has it
     * @return array<string,mixed>
     */
    public function card(array $submission, bool $withQr = false, ?array $filed = null, ?array $supplier = null): array
    {
        $liability = self::liability($submission);
        $period = self::period($submission);
        $supplierId = (int) $submission['supplier_id'];
        $card = [
            'submission_id' => (int) $submission['id'],
            'kind'          => $liability['kind'],
            'amount'        => $liability['amount'],
            'amount_due'    => 0,
            'period_label'  => $period['label'],
            'due_date'      => self::dueDate($submission),
            'state'         => 'none',
            'payable'       => false,
            'account'       => null,
            'office'        => null,
            'variable_symbol' => null,
            'qr'            => null,
            'paid'          => null,
            'warnings'      => [],
        ];
        if ($liability['kind'] === 'none') {
            return $card;
        }
        if ($liability['kind'] === 'refund') {
            $card['state'] = 'refund';
            return $card;
        }

        $filed ??= $this->repo->filedForPeriod($supplierId, self::FORM_CODE, $period['year'],
            $period['quarter'] === null ? $period['month'] : null, $period['quarter']);
        $effective = array_last($filed);
        $isFiled = in_array($submission['filing_status'], ['filed', 'confirmed'], true);
        $isEffective = $isFiled && $effective !== null && (int) $effective['id'] === (int) $submission['id'];

        // What was already paid towards the superseded returns of this period.
        $alreadyPaid = 0.0;
        foreach ($filed as $row) {
            if ((int) $row['id'] !== (int) $submission['id'] && $row['paid_at'] !== null) {
                $alreadyPaid += (float) $row['paid_amount'];
            }
        }
        $card['amount_due'] = (int) max(0, $liability['amount'] - (int) round($alreadyPaid));
        if ($submission['paid_at'] !== null) {
            $card['paid'] = [
                'at'      => (string) $submission['paid_at'],
                'amount'  => (float) $submission['paid_amount'],
                'source'  => (string) $submission['paid_source'],
                'bank_transaction_id' => $submission['paid_bank_transaction_id'],
            ];
        }
        if (!$isFiled) {
            $card['state'] = 'not_filed';
        } elseif (!$isEffective) {
            $card['state'] = 'superseded';
        } elseif ($submission['paid_at'] !== null || $card['amount_due'] === 0) {
            $card['state'] = 'paid';
        } else {
            $card['state'] = 'unpaid';
            $card['payable'] = true;
        }

        $supplier ??= $this->supplier($supplierId);
        $account = FinancialOfficeAccounts::vatAccount($supplier['financial_office_code'] ?? null);
        $vs = FinancialOfficeAccounts::variableSymbol($supplier['dic'] ?? null);
        if ($account === null) {
            $card['warnings'][] = [
                'code'    => 'office_unknown',
                'message' => ($supplier['financial_office_code'] ?? '') === ''
                    ? 'Chybí kód finančního úřadu — doplň ho v Nastavení → Daňové nastavení, jinak nelze určit účet pro platbu DPH.'
                    : 'Kód finančního úřadu „' . $supplier['financial_office_code'] . '" neznám — zkontroluj ho v Nastavení → Daňové nastavení.',
            ];
        } else {
            $card['account'] = $account;
            $card['office'] = ['code' => $account['office_code'], 'name' => $account['office_name']];
        }
        if ($vs === null) {
            $card['warnings'][] = [
                'code'    => 'dic_missing',
                'message' => 'Chybí české DIČ — variabilní symbol platby DPH je číselná část DIČ.',
            ];
        }
        $card['variable_symbol'] = $vs;

        if ($withQr && $account !== null && $vs !== null && $card['amount_due'] > 0 && $card['state'] !== 'superseded') {
            // No due date is passed (the generator then dates the payment today): a tax is paid when it is
            // CREDITED to the office's account, so a payment dated for the due date would arrive late.
            $card['qr'] = $this->qr->generate(
                'CZK',
                (float) $card['amount_due'],
                $vs,
                ['account_number' => $account['prefix'] . '-' . $account['number'], 'bank_code' => $account['bank_code'], 'iban' => $account['iban']],
                (string) ($supplier['company_name'] ?? ''),
                null,
                $period['quarter'] !== null ? sprintf('DPH Q%d %d', $period['quarter'], $period['year']) : sprintf('DPH %02d/%d', $period['month'], $period['year']),
            );
        }
        return $card;
    }

    /**
     * Cards of every effective, filed and still unpaid DPH liability of the supplier, oldest first.
     * Feeds the dashboard item and the bank payment recognition.
     *
     * @return list<array<string,mixed>>
     */
    public function unpaidLiabilities(int $supplierId): array
    {
        $supplier = $this->supplier($supplierId);
        $out = [];
        foreach ($this->filedByPeriod($supplierId) as $rows) {
            $effective = array_last($rows);
            if ($effective['paid_at'] !== null) {
                continue;
            }
            $card = $this->card($effective, false, $rows, $supplier);
            if ($card['payable']) {
                $out[] = $card;
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['due_date'], $a['submission_id']] <=> [$b['due_date'], $b['submission_id']]);
        return $out;
    }

    /**
     * Payment cards (no QR) for the DPH rows of an archive listing — one query for the filed returns and one
     * for the supplier instead of two per row.
     *
     * @param list<array<string,mixed>> $rows archive rows (summary decoded)
     * @return array<int, array<string,mixed>> submission id => card, only for rows with a liability or a refund
     */
    public function cardsFor(int $supplierId, array $rows): array
    {
        $dph = array_filter($rows, static fn (array $r): bool => self::liability($r)['kind'] !== 'none');
        if ($dph === []) {
            return [];
        }
        $filedByPeriod = $this->filedByPeriod($supplierId);
        $supplier = $this->supplier($supplierId);
        $cards = [];
        foreach ($dph as $row) {
            $slot = TaxSubmissionRepository::slotKey(self::FORM_CODE, $row['period_year'], $row['period_month'], $row['period_quarter']);
            $cards[(int) $row['id']] = $this->card($row, false, $filedByPeriod[$slot] ?? [], $supplier);
        }
        return $cards;
    }

    /**
     * Filed/confirmed DPH returns of the supplier grouped by period, oldest filing first within a period.
     *
     * @return array<string, list<array<string,mixed>>> slot key => rows
     */
    private function filedByPeriod(int $supplierId): array
    {
        $byPeriod = [];
        foreach ($this->repo->filedByTypes($supplierId, [self::FORM_CODE]) as $row) {
            $byPeriod[TaxSubmissionRepository::slotKey(self::FORM_CODE, $row['period_year'], $row['period_month'], $row['period_quarter'])][] = $row;
        }
        return $byPeriod;
    }

    /**
     * Marks the liability as paid by hand.
     *
     * @return array<string,mixed> the refreshed card
     * @throws TaxFilingException
     */
    public function markPaid(int $supplierId, int $submissionId, ?string $paidAt, ?float $amount, ?int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $paidAt = $paidAt !== null && $paidAt !== '' ? $paidAt : date('Y-m-d');
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $paidAt);
        if ($dt === false || $dt->format('Y-m-d') !== $paidAt) {
            throw new TaxFilingException('Datum úhrady musí být ve formátu RRRR-MM-DD.', 'validation_failed', 400);
        }
        [$row, $paid] = $this->repo->withLockedRow($submissionId, $supplierId, function (?array $row) use ($submissionId, $paidAt, $amount): array {
            $row ?? throw TaxFilingException::notFound();
            $card = $this->card($row);
            if ($card['state'] === 'paid') {
                throw new TaxFilingException('Platba DPH už je označená jako zaplacená.', 'already_paid', 409);
            }
            if (!$card['payable']) {
                throw new TaxFilingException(
                    match ($card['state']) {
                        'refund'     => 'U nadměrného odpočtu se nic neplatí — finanční úřad částku vrací.',
                        'superseded' => 'Toto přiznání nahradilo pozdější podané přiznání za stejné období.',
                        'not_filed'  => 'Nejdřív označ přiznání jako podané.',
                        default      => 'U tohoto podání není co platit.',
                    },
                    'not_payable',
                    409,
                );
            }
            $paid = $amount ?? (float) $card['amount_due'];
            if ($paid <= 0) {
                throw new TaxFilingException('Částka úhrady musí být kladná.', 'validation_failed', 400);
            }
            $this->repo->markPaid($submissionId, $paidAt, $paid, 'manual', null);
            return [$row, $paid];
        });
        $this->logger->log('tax_submission.payment_marked', $userId, 'tax_submission', $submissionId,
            ['paid_at' => $paidAt, 'amount' => $paid, 'source' => 'manual'], $ip, $userAgent, $supplierId);
        return $this->card($this->repo->findMeta($submissionId, $supplierId) ?? $row);
    }

    /**
     * Forgets the payment (manual or recognised). A bank transaction that was consumed by the
     * recognition goes back to the unmatched ones.
     *
     * @return array<string,mixed> the refreshed card
     * @throws TaxFilingException
     */
    public function clearPaid(int $supplierId, int $submissionId, ?int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $row = $this->repo->withLockedRow($submissionId, $supplierId, function (?array $row) use ($submissionId): array {
            $row ?? throw TaxFilingException::notFound();
            if ($row['paid_at'] === null) {
                throw new TaxFilingException('Platba DPH není označená jako zaplacená.', 'not_paid', 409);
            }
            $this->repo->clearPaid($submissionId);
            if ($row['paid_bank_transaction_id'] !== null) {
                $this->releaseBankTransaction((int) $row['paid_bank_transaction_id']);
            }
            return $row;
        });
        $this->logger->log('tax_submission.payment_cleared', $userId, 'tax_submission', $submissionId,
            ['was_source' => $row['paid_source'], 'bank_transaction_id' => $row['paid_bank_transaction_id']], $ip, $userAgent, $supplierId);
        return $this->card($this->repo->findMeta($submissionId, $supplierId) ?? $row);
    }

    /**
     * Called when a bank transaction that was recognised as a VAT payment is unmatched by the
     * user: the liability is open again. No-op for any other transaction.
     */
    public function releaseTransaction(int $bankTransactionId, ?int $userId = null): void
    {
        $row = $this->repo->findByPaidTransaction($bankTransactionId);
        if ($row === null) {
            return;
        }
        $this->repo->clearPaid((int) $row['id']);
        $this->logger->log('tax_submission.payment_cleared', $userId, 'tax_submission', (int) $row['id'],
            ['was_source' => 'bank', 'bank_transaction_id' => $bankTransactionId], null, null, (int) $row['supplier_id']);
    }

    /** Puts a transaction that the recognition marked as "ignored" back to the unmatched ones. */
    private function releaseBankTransaction(int $bankTransactionId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE bank_transactions
                SET match_status = 'unmatched', ignore_origin = NULL, ignore_note = NULL, matched_at = NULL
              WHERE id = ? AND match_status = 'ignored' AND ignore_origin = 'vat_payment'"
        )->execute([$bankTransactionId]);
    }

    /** @return array<string,mixed> */
    private function supplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, dic, financial_office_code FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
