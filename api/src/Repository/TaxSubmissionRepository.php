<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Archive of generated EPO XML returns (DPHDP3, DPHKH1, DPHSHV, DPFDP5, DPPDP9, OSS).
 *
 * MyInvoice does NOT file anything itself: the user downloads the XML and files it on the EPO
 * portal. The table archives what was generated when, and (see migration 9004) records the
 * user-reported filing state (generated -> filed -> confirmed), the receipt and, for DPH returns,
 * the payment of the tax liability.
 */
final class TaxSubmissionRepository
{
    public const FORM_DPH = 'dphdp3';
    public const FORM_KH = 'dphkh1';
    /** DPH supplementary return (dodatečné přiznání, § 141 DŘ): states the difference against the last known tax. */
    public const DPH_SUPPLEMENTARY = 'D';

    /**
     * Forms that may be filed repeatedly for the same period, in sequence (form code => forms): the supplementary
     * DPH return D (each reports the difference against the last known tax, § 141 DŘ) and the subsequent KH N / E
     * and the quick answer to a call V. Their `filed_slot` of migration 9006 is NULL, so the unique index does not
     * apply to them — keep the list in the migration in step.
     */
    public const REPEATABLE_FORMS = [self::FORM_DPH => [self::DPH_SUPPLEMENTARY], self::FORM_KH => ['N', 'E', 'V']];

    public function __construct(private readonly Connection $db) {}

    /**
     * Archives a generated XML. Returns the new row id.
     *
     * @param array<string,mixed> $summary
     * @param list<string> $validationErrors
     */
    public function archive(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        string $xml,
        array $summary,
        string $validationStatus,
        array $validationErrors,
        ?int $generatedBy,
        string $submissionForm = 'B',
    ): int {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter, submission_form,
                 xml_content, xml_size_bytes, xml_sha256,
                 validation_status, validation_errors, summary_json, generated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $supplierId, $formCode, $year, $month, $quarter, $submissionForm,
            $xml, strlen($xml), hash('sha256', $xml),
            $validationStatus,
            !empty($validationErrors) ? json_encode($validationErrors, JSON_UNESCAPED_UNICODE) : null,
            json_encode($summary, JSON_UNESCAPED_UNICODE),
            $generatedBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Columns of the archive row without the XML body and the receipt blob (list + detail).
     * The generated `filed_slot` guard column of migration 9004 is deliberately not selected.
     */
    private const META_COLUMNS = 'id, supplier_id, form_code, period_year, period_month, period_quarter,
                    submission_form, xml_size_bytes, xml_sha256, validation_status, validation_errors,
                    summary_json, generated_by, generated_at, notes,
                    filing_status, filed_at, filing_channel, filing_reference, filed_by, confirmed_at,
                    receipt_name, receipt_mime, receipt_size_bytes, receipt_sha256,
                    paid_at, paid_amount, paid_source, paid_bank_transaction_id';

    /**
     * List per tenant for the list view: metadata only, without `xml_content` and the receipt.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, int $limit = 100): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::META_COLUMNS . '
               FROM tax_submissions
              WHERE supplier_id = ?
           ORDER BY generated_at DESC, id DESC
              LIMIT ?'
        );
        $stmt->bindValue(1, $supplierId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn ($r) => $this->normalize($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Row incl. `xml_content`, without the receipt blob (see {@see receipt()}). */
    public function find(int $id, int $supplierId): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::META_COLUMNS . ', xml_content FROM tax_submissions WHERE id = ? AND supplier_id = ?',
            [$id, $supplierId],
        );
    }

    /** Metadata row only (no XML body, no receipt). With `$forUpdate` it locks the row — call inside a transaction. */
    public function findMeta(int $id, int $supplierId, bool $forUpdate = false): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::META_COLUMNS . ' FROM tax_submissions WHERE id = ? AND supplier_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id, $supplierId],
        );
    }

    /**
     * Runs `$operation` in a transaction with the row locked (FOR UPDATE). The operation gets the
     * metadata row, or null when it does not exist, and decides what to do about it; the transaction
     * commits when it returns and rolls back when it throws.
     *
     * @template T
     * @param callable(?array<string,mixed>): T $operation
     * @return T
     */
    public function withLockedRow(int $id, int $supplierId, callable $operation): mixed
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $result = $operation($this->findMeta($id, $supplierId, forUpdate: true));
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Another submission of the same (supplier, type, period, form) that is already filed/confirmed.
     * Null for the forms that may be filed repeatedly ({@see REPEATABLE_FORMS}).
     *
     * @param array<string,mixed> $submission a row from {@see findMeta()}
     */
    public function findFiledSibling(array $submission): ?array
    {
        if (in_array($submission['submission_form'], self::REPEATABLE_FORMS[$submission['form_code']] ?? [], true)) {
            return null;
        }
        return $this->fetchOne(
            'SELECT ' . self::META_COLUMNS . '
               FROM tax_submissions
              WHERE supplier_id = ? AND form_code = ? AND period_year = ?
                AND COALESCE(period_month, 0) = ? AND COALESCE(period_quarter, 0) = ?
                AND submission_form = ? AND filing_status IN (\'filed\', \'confirmed\') AND id <> ?
              LIMIT 1',
            [
                $submission['supplier_id'], $submission['form_code'], $submission['period_year'],
                (int) ($submission['period_month'] ?? 0), (int) ($submission['period_quarter'] ?? 0),
                $submission['submission_form'], $submission['id'],
            ],
        );
    }

    /**
     * Filed/confirmed submissions of one type + period (all forms), in the order of filing: by `filed_at`,
     * ties by id. Which of them are in force is decided by {@see \MyInvoice\Service\Tax\FiledReturnChain}
     * (a regular / corrective return replaces the earlier ones, a supplementary one only adds to them); that is
     * why a second return of a period must be filed with a full date and time.
     *
     * @param bool $forUpdate lock the rows (call inside a transaction): serialises the concurrent filings of one period
     *
     * @return list<array<string,mixed>>
     */
    public function filedForPeriod(int $supplierId, string $formCode, int $year, ?int $month, ?int $quarter, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::META_COLUMNS . '
               FROM tax_submissions
              WHERE supplier_id = ? AND form_code = ? AND period_year = ?
                AND COALESCE(period_month, 0) = ? AND COALESCE(period_quarter, 0) = ?
                AND filing_status IN (\'filed\', \'confirmed\')
           ORDER BY filed_at ASC, id ASC' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $formCode, $year, (int) ($month ?? 0), (int) ($quarter ?? 0)]);
        return array_map(fn ($r) => $this->normalize($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Every filed/confirmed submission of the given types for a supplier (calendar badges, payment feed),
     * per period in the order of filing — `filed_at`, ties by id; see {@see \MyInvoice\Service\Tax\FiledReturnChain}.
     *
     * @param list<string> $formCodes
     * @param bool $withSummary false skips the (large) summary JSON when the caller only needs the filing facts
     * @return list<array<string,mixed>>
     */
    public function filedByTypes(int $supplierId, array $formCodes, bool $withSummary = true): array
    {
        if ($formCodes === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($formCodes), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . ($withSummary ? self::META_COLUMNS : str_replace('summary_json, ', '', self::META_COLUMNS)) . "
               FROM tax_submissions
              WHERE supplier_id = ? AND filing_status IN ('filed', 'confirmed') AND form_code IN ({$in})
           ORDER BY period_year, COALESCE(period_month, 0), COALESCE(period_quarter, 0), filed_at, id"
        );
        $stmt->execute(array_merge([$supplierId], array_values($formCodes)));
        return array_map(fn ($r) => $this->normalize($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Marks a submission as filed. Throws PDOException(23000) when the (type, period, form) slot is taken. */
    public function markFiled(int $id, string $filedAt, string $channel, ?string $reference, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tax_submissions
                SET filing_status = 'filed', filed_at = ?, filing_channel = ?, filing_reference = ?, filed_by = ?
              WHERE id = ?"
        )->execute([$filedAt, $channel, $reference, $userId, $id]);
    }

    /** Corrects the time of a filing that is already recorded (state and channel stay). */
    public function setFiledAt(int $id, string $filedAt): void
    {
        $this->db->pdo()->prepare('UPDATE tax_submissions SET filed_at = ? WHERE id = ?')->execute([$filedAt, $id]);
    }

    /** Back to `generated`: forgets the filing data and the receipt (the payment record is a separate fact and stays). */
    public function clearFiling(int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tax_submissions
                SET filing_status = 'generated', filed_at = NULL, filing_channel = NULL, filing_reference = NULL,
                    filed_by = NULL, confirmed_at = NULL,
                    receipt_content = NULL, receipt_name = NULL, receipt_mime = NULL,
                    receipt_size_bytes = NULL, receipt_sha256 = NULL
              WHERE id = ?"
        )->execute([$id]);
    }

    /** Stores the receipt and moves the submission to `confirmed`. */
    public function setReceipt(int $id, string $content, string $name, string $mime): void
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submissions
                SET filing_status = 'confirmed', confirmed_at = COALESCE(confirmed_at, NOW()),
                    receipt_content = ?, receipt_name = ?, receipt_mime = ?,
                    receipt_size_bytes = ?, receipt_sha256 = ?
              WHERE id = ?"
        );
        $stmt->bindValue(1, $content, \PDO::PARAM_LOB);
        $stmt->bindValue(2, $name);
        $stmt->bindValue(3, $mime);
        $stmt->bindValue(4, strlen($content), \PDO::PARAM_INT);
        $stmt->bindValue(5, hash('sha256', $content));
        $stmt->bindValue(6, $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Drops the receipt and falls back from `confirmed` to `filed`. */
    public function clearReceipt(int $id): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tax_submissions
                SET filing_status = 'filed', confirmed_at = NULL,
                    receipt_content = NULL, receipt_name = NULL, receipt_mime = NULL,
                    receipt_size_bytes = NULL, receipt_sha256 = NULL
              WHERE id = ?"
        )->execute([$id]);
    }

    /** @return array{content:string, name:string, mime:string}|null */
    public function receipt(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT receipt_content, receipt_name, receipt_mime
               FROM tax_submissions WHERE id = ? AND supplier_id = ? AND receipt_content IS NOT NULL'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'content' => (string) $row['receipt_content'],
            'name'    => (string) $row['receipt_name'],
            'mime'    => (string) $row['receipt_mime'],
        ];
    }

    public function markPaid(int $id, string $paidAt, float $amount, string $source, ?int $bankTransactionId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tax_submissions
                SET paid_at = ?, paid_amount = ?, paid_source = ?, paid_bank_transaction_id = ?
              WHERE id = ?'
        )->execute([$paidAt, number_format($amount, 2, '.', ''), $source, $bankTransactionId, $id]);
    }

    public function clearPaid(int $id): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tax_submissions
                SET paid_at = NULL, paid_amount = NULL, paid_source = NULL, paid_bank_transaction_id = NULL
              WHERE id = ?'
        )->execute([$id]);
    }

    /** Submission whose payment was recognised from this bank transaction. */
    public function findByPaidTransaction(int $bankTransactionId): ?array
    {
        return $this->fetchOne(
            'SELECT ' . self::META_COLUMNS . ' FROM tax_submissions WHERE paid_bank_transaction_id = ? LIMIT 1',
            [$bankTransactionId],
        );
    }

    /**
     * Stable key of a return's "slot" — type + period (+ filing form when given). The same identity the
     * generated `filed_slot` column of migration 9004 enforces as unique among filed/confirmed rows.
     */
    public static function slotKey(string $formCode, int $year, ?int $month, ?int $quarter, ?string $form = null): string
    {
        return implode('|', array_filter([$formCode, $year, $month ?? 0, $quarter ?? 0, $form], static fn ($p) => $p !== null));
    }

    /**
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalize($row) : null;
    }

    /** Deletes an archive row — never one that is filed/confirmed (it is the evidence of the filing). */
    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare("DELETE FROM tax_submissions WHERE id = ? AND supplier_id = ? AND filing_status = 'generated'");
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['period_year'] = (int) $row['period_year'];
        $row['period_month'] = $row['period_month'] !== null ? (int) $row['period_month'] : null;
        $row['period_quarter'] = $row['period_quarter'] !== null ? (int) $row['period_quarter'] : null;
        $row['xml_size_bytes'] = (int) $row['xml_size_bytes'];
        $row['submission_form'] = (string) ($row['submission_form'] ?? 'B');
        $row['filing_status'] = (string) ($row['filing_status'] ?? 'generated');
        $row['filed_by'] = isset($row['filed_by']) ? (int) $row['filed_by'] : null;
        $row['has_receipt'] = isset($row['receipt_name']);
        $row['receipt_size_bytes'] = isset($row['receipt_size_bytes']) ? (int) $row['receipt_size_bytes'] : null;
        $row['paid_amount'] = isset($row['paid_amount']) ? (float) $row['paid_amount'] : null;
        $row['paid_bank_transaction_id'] = isset($row['paid_bank_transaction_id']) ? (int) $row['paid_bank_transaction_id'] : null;
        if (isset($row['summary_json']) && $row['summary_json'] !== null) {
            $row['summary'] = json_decode((string) $row['summary_json'], true) ?: null;
            unset($row['summary_json']);
        }
        if (isset($row['validation_errors']) && $row['validation_errors'] !== null) {
            $row['validation_errors'] = json_decode((string) $row['validation_errors'], true) ?: [];
        } else {
            $row['validation_errors'] = [];
        }
        return $row;
    }
}
