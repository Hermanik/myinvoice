<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\ActivityLogger;

/**
 * Filing lifecycle of an archived tax submission: generated -> filed (podáno) -> confirmed (potvrzeno).
 *
 * MyInvoice never files anything itself — the user files the downloaded XML on the EPO portal (or
 * through the data box) and reports it here: when, through which channel, under which reference
 * number (číslo podání), and finally uploads the receipt. Only ONE submission per
 * (supplier, type, period, form) can be filed/confirmed: the duplicate guard is checked here and
 * backed by a unique index on the generated `filed_slot` column (migration 9004).
 */
final class TaxSubmissionFilingService
{
    public const CHANNELS = ['epo', 'data_box', 'other'];

    public const RECEIPT_MAX_BYTES = 10 * 1024 * 1024;

    /** Receipt file types: EPO PKCS#7 confirmation (.p7s), PDF confirmation, ISDS message (.zfo). */
    private const RECEIPT_MIME = [
        'pdf' => 'application/pdf',
        'p7s' => 'application/pkcs7-signature',
        'zfo' => 'application/octet-stream',
    ];

    public function __construct(
        private readonly TaxSubmissionRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly VatPaymentRecognizer $payments,
    ) {}

    /**
     * Normalises the user-supplied filing time to `Y-m-d H:i:s`. Empty = now. Date-only means
     * the start of that day — unless `$requireTime`: when another return of the same period is
     * already filed, the order of the two decides which one is in force (the latest `filed_at`,
     * ties by id), so a bare date is not enough. Anything later than a few minutes from now is refused.
     *
     * @throws TaxFilingException
     */
    public static function parseFiledAt(?string $value, \DateTimeImmutable $now, bool $requireTime = false): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $now->format('Y-m-d H:i:s');
        }
        $parsed = null;
        $dateOnly = false;
        foreach (['!Y-m-d\TH:i:s', '!Y-m-d\TH:i', '!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $format) {
            $candidate = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($candidate !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $dateOnly = $format === '!Y-m-d';
                $parsed = $candidate;
                break;
            }
        }
        if ($parsed === null) {
            throw new TaxFilingException('Datum a čas podání musí být ve formátu RRRR-MM-DD HH:MM.', 'validation_failed', 400);
        }
        if ($dateOnly && $requireTime) {
            throw new TaxFilingException(
                'Za toto období už je podané jiné přiznání — zadej i čas podání (RRRR-MM-DD HH:MM), podle něj se pozná, které je platné.',
                'time_required',
                400,
            );
        }
        if ($parsed > $now->modify('+5 minutes')) {
            throw new TaxFilingException('Datum podání nemůže být v budoucnu.', 'validation_failed', 400);
        }
        return $parsed->format('Y-m-d H:i:s');
    }

    /**
     * generated -> filed.
     *
     * @return array<string,mixed> the updated row (metadata)
     * @throws TaxFilingException
     */
    public function markFiled(
        int $supplierId,
        int $id,
        ?string $filedAt,
        string $channel,
        ?string $reference,
        ?int $userId,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new TaxFilingException('Neplatný způsob podání (povoleno: ' . implode(', ', self::CHANNELS) . ').', 'validation_failed', 400);
        }
        $reference = $reference !== null ? trim($reference) : null;
        if ($reference !== null && mb_strlen($reference) > 100) {
            throw new TaxFilingException('Číslo podání může mít nejvýše 100 znaků.', 'validation_failed', 400);
        }
        $reference = $reference === '' ? null : $reference;

        [$row, $filedAt] = $this->repo->withLockedRow($id, $supplierId, function (?array $row) use ($id, $filedAt, $channel, $reference, $userId): array {
            $row ?? throw TaxFilingException::notFound();
            if ($row['filing_status'] !== 'generated') {
                throw new TaxFilingException('Toto podání už je označené jako podané.', 'already_filed', 409);
            }
            // Locks the filed DPH returns of the period (and re-reads them after the lock): two returns of one period cannot
            // be filed at the same time, so two supplementary returns (a DPH form) cannot both stand on the same base.
            $filedOfThePeriod = $this->repo->filedForPeriod($row['supplier_id'], $row['form_code'], $row['period_year'], $row['period_month'], $row['period_quarter'], forUpdate: $row['form_code'] === TaxSubmissionRepository::FORM_DPH);
            $filedAt = self::parseFiledAt($filedAt, new \DateTimeImmutable(), requireTime: $filedOfThePeriod !== []);
            if (FiledReturnChain::isSupplementary($row)) {
                $chain = new FiledReturnChain($filedOfThePeriod);
                $chain->assertCurrentBaseOf($row);
                $chain->assertFiledAfterBase($filedAt);
            }
            $sibling = $this->repo->findFiledSibling($row);
            if ($sibling !== null) {
                throw $this->duplicate($sibling);
            }
            try {
                $this->repo->markFiled($id, $filedAt, $channel, $reference, $userId);
            } catch (\PDOException $e) {
                // Lost the race against a concurrent filing of the same slot (unique index).
                if ((string) $e->getCode() === '23000') {
                    $winner = $this->repo->findFiledSibling($row);
                    throw $winner !== null ? $this->duplicate($winner)
                        : new TaxFilingException('Za toto období a formu už je podání označené jako podané.', 'duplicate_filing', 409);
                }
                throw $e;
            }
            return [$row, $filedAt];
        });

        $this->logger->log('tax_submission.filed', $userId, 'tax_submission', $id, [
            'form_code' => $row['form_code'], 'period' => VatPaymentService::period($row)['label'],
            'submission_form' => $row['submission_form'], 'filed_at' => $filedAt,
            'channel' => $channel, 'reference' => $reference,
        ], $ip, $userAgent, $supplierId);

        // The tax is often paid before the filing is recorded: pick that payment up from the bank now.
        // The filing itself is done and committed, so a failure here must not turn it into an error.
        if ($row['form_code'] === VatPaymentService::FORM_CODE) {
            try {
                $this->payments->reconsider($supplierId, $id);
            } catch (\Throwable $e) {
                $this->logger->log('tax_submission.payment_recognition_failed', $userId, 'tax_submission', $id, ['error' => $e->getMessage()], $ip, $userAgent, $supplierId);
            }
        }

        return $this->repo->findMeta($id, $supplierId) ?? $row;
    }

    /**
     * filed | confirmed -> generated (the filing was entered by mistake). The receipt goes with it.
     *
     * @return array<string,mixed>
     * @throws TaxFilingException
     */
    public function revertFiling(int $supplierId, int $id, ?int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $row = $this->repo->withLockedRow($id, $supplierId, function (?array $row) use ($id): array {
            $row ?? throw TaxFilingException::notFound();
            if ($row['filing_status'] === 'generated') {
                throw new TaxFilingException('Podání není označené jako podané.', 'not_filed', 409);
            }
            // A supplementary return reports the difference against the return filed before it: that one stays.
            if ($row['form_code'] === TaxSubmissionRepository::FORM_DPH && (new FiledReturnChain($this->repo->filedForPeriod($row['supplier_id'], $row['form_code'], $row['period_year'], $row['period_month'], $row['period_quarter'], forUpdate: true)))->hasSupplementAfter($row)) {
                throw new TaxFilingException('Nejdřív zruš označení podání pozdějších dodatečných přiznání za toto období — vycházejí z tohoto podání.', 'later_supplementary', 409);
            }
            $this->repo->clearFiling($id);
            return $row;
        });

        $this->logger->log('tax_submission.filing_reverted', $userId, 'tax_submission', $id, [
            'previous_status' => $row['filing_status'], 'filed_at' => $row['filed_at'],
            'receipt_sha256' => $row['receipt_sha256'],
        ], $ip, $userAgent, $supplierId);

        return $this->repo->findMeta($id, $supplierId) ?? $row;
    }

    /**
     * filed -> confirmed (or replaces the receipt of a confirmed one).
     *
     * @return array<string,mixed>
     * @throws TaxFilingException
     */
    public function attachReceipt(int $supplierId, int $id, string $content, string $originalName, ?int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        self::assertReceiptContent($content, $extension);
        $name = self::safeFilename($originalName);
        $mime = self::RECEIPT_MIME[$extension];

        $row = $this->repo->withLockedRow($id, $supplierId, function (?array $row) use ($id, $content, $name, $mime): array {
            $row ?? throw TaxFilingException::notFound();
            if ($row['filing_status'] === 'generated') {
                throw new TaxFilingException('Potvrzení lze nahrát až k podání označenému jako podané.', 'not_filed', 409);
            }
            $this->repo->setReceipt($id, $content, $name, $mime);
            return $row;
        });

        $this->logger->log('tax_submission.receipt_uploaded', $userId, 'tax_submission', $id, [
            'name' => $name, 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
            'replaced' => $row['filing_status'] === 'confirmed' ?: null,
        ], $ip, $userAgent, $supplierId);

        return $this->repo->findMeta($id, $supplierId) ?? $row;
    }

    /**
     * confirmed -> filed (the receipt is dropped).
     *
     * @return array<string,mixed>
     * @throws TaxFilingException
     */
    public function removeReceipt(int $supplierId, int $id, ?int $userId, ?string $ip = null, ?string $userAgent = null): array
    {
        $row = $this->repo->withLockedRow($id, $supplierId, function (?array $row) use ($id): array {
            $row ?? throw TaxFilingException::notFound();
            if ($row['filing_status'] !== 'confirmed') {
                throw new TaxFilingException('K podání není nahrané žádné potvrzení.', 'no_receipt', 409);
            }
            $this->repo->clearReceipt($id);
            return $row;
        });

        $this->logger->log('tax_submission.receipt_removed', $userId, 'tax_submission', $id, [
            'name' => $row['receipt_name'], 'sha256' => $row['receipt_sha256'],
        ], $ip, $userAgent, $supplierId);

        return $this->repo->findMeta($id, $supplierId) ?? $row;
    }

    /**
     * Receipt sanity check: allowed extension, size, and content that looks like what the
     * extension promises (the file is only ever served back as a download, never rendered).
     *
     * @throws TaxFilingException
     */
    public static function assertReceiptContent(string $content, string $extension): void
    {
        if (!isset(self::RECEIPT_MIME[$extension])) {
            throw new TaxFilingException('Povolené jsou jen soubory .p7s, .pdf a .zfo.', 'invalid_extension', 400);
        }
        if ($content === '') {
            throw new TaxFilingException('Soubor je prázdný.', 'empty_file', 400);
        }
        if (strlen($content) > self::RECEIPT_MAX_BYTES) {
            throw new TaxFilingException('Soubor je příliš velký (max 10 MiB).', 'file_too_large', 413);
        }
        $head = ltrim(substr($content, 0, 1024), "\x00\x09\x0a\x0d\x20\xef\xbb\xbf");
        if ($extension === 'pdf') {
            if (!str_starts_with($head, '%PDF-')) {
                throw new TaxFilingException('Soubor není platné PDF.', 'invalid_pdf', 400);
            }
            return;
        }
        // .p7s / .zfo: a DER encoded CMS (starts with an ASN.1 SEQUENCE, 0x30), or its PEM / base64 text form.
        $isDer = $content[0] === "\x30";
        $isText = str_starts_with($head, '-----BEGIN') || preg_match('/^MI[A-Za-z0-9+\/]{20}/', $head) === 1;
        if (!$isDer && !$isText) {
            throw new TaxFilingException('Soubor nevypadá jako podepsané potvrzení (.' . $extension . ').', 'invalid_receipt', 400);
        }
    }

    /** Display file name: no path, no control characters, bounded length. */
    public static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f"<>|:*?]+/u', '_', $name) ?? '';
        $name = trim($name, " .\t");
        if ($name === '') {
            return 'potvrzeni';
        }
        return mb_substr($name, 0, 200);
    }

    private function duplicate(array $sibling): TaxFilingException
    {
        return new TaxFilingException(
            sprintf('Za toto období a formu už je podání #%d označené jako podané (%s). Nejdřív u něj zruš označení podání.',
                $sibling['id'], substr((string) $sibling['filed_at'], 0, 16)),
            'duplicate_filing',
            409,
            ['conflict' => ['id' => $sibling['id'], 'filing_status' => $sibling['filing_status'], 'filed_at' => $sibling['filed_at']]],
        );
    }
}
