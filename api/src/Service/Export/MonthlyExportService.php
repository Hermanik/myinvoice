<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Import\CancelledException;
use MyInvoice\Service\Pdf\DphBookPdfRenderer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PurchaseInvoicePdfRenderer;
use MyInvoice\Service\Report\DphBookBuilder;
use ZipArchive;

/**
 * Bulk export — packs the existing exports for the chosen period (a month, a whole quarter or a
 * whole year) into one ZIP with named folders. It runs as a background job (import_jobs,
 * source='monthly_export') because rendering the PDFs of many invoices can exceed the web timeout.
 *
 * Assignment of documents to the period ({@see ExportBasis}):
 *   - DUZP (default for a month and a quarter; same as VatLedgerService → VAT book and VAT return):
 *       issued by DUZP (output VAT), received by the later of (DUZP, issue date) — input VAT
 *       cannot be deducted before the payer holds the tax document (§ 73 ZDPH),
 *   - payment date (default for a year; a sole trader's income tax follows the day of payment):
 *       issued and received documents paid in full within the period,
 *   - bank statements: always by statement date, VAT book: always by DUZP, for payer months.
 *
 * Struktura ZIPu:
 *   Vystavene-faktury/PDF|ISDOC/…   Prijate-faktury/PDF|ISDOC/…
 *   Vypisy-z-uctu/PDF|GPC/…         Kniha-DPH/kniha-dph-YYYY-MM.pdf   README.txt
 *   Prehled-vydanych-faktur.csv     Prehled-prijatych-faktur.csv
 *   (The VAT book is per month, only for months in which the supplier was a VAT payer.)
 *
 * Memory: content generated on the fly (ISDOC, reconstructed PDFs, statements from the DB, CSV) is
 * written to temporary files and added to the ZIP by reference, so memory does not grow with the
 * number of documents; the temporary directory is removed once the ZIP is closed.
 */
final class MonthlyExportService
{
    /** All supported parts (and the default when the user selects nothing). */
    public const ALL_PARTS = [
        'sales_pdf', 'sales_isdoc', 'purchase_pdf', 'purchase_isdoc',
        'bank_pdf', 'bank_gpc', 'dph_book', 'summary_csv',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly ImportJobRepository $jobs,
        private readonly ActivityLogger $logger,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly InvoicePdfRenderer $invoicePdf,
        private readonly IsdocExporter $isdoc,
        private readonly PurchaseInvoicePdfRenderer $purchasePdf,
        private readonly PurchaseInvoiceExportService $purchaseExport,
        private readonly DphBookBuilder $dphBookBuilder,
        private readonly DphBookPdfRenderer $dphBookRenderer,
        private readonly ExportPeriodResolver $periodResolver,
        private readonly ExportDocumentSelector $selector,
    ) {}

    /** Absolute base of the ZIP storage (under data_dir, otherwise the repo root). */
    public function storageBaseDir(): string
    {
        $base = ($this->config->dataDir() ?? \MyInvoice\Bootstrap::rootDir())
            . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'monthly-exports';
        return $base;
    }

    /** Absolute path of a file from the relative result_path (sup-N/file.zip). */
    public function resolveResultPath(string $relative): string
    {
        return $this->storageBaseDir() . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * Counts of the available items per part (for the UI checkboxes / preview) and the number of
     * documents that fall into the period by DUZP but are not paid in it (payment basis only).
     *
     * @return array{counts: array<string,int>, outside: array{sales:int, purchase:int}}
     */
    public function previewCounts(int $supplierId, ExportPeriod $period, ExportBasis $basis = ExportBasis::Duzp): array
    {
        $duzpSales    = $this->findSalesInvoiceIds($supplierId, $period);
        $duzpPurchase = $this->findPurchaseInvoices($supplierId, $period);
        $salesIds     = $basis === ExportBasis::Duzp ? $duzpSales : $this->selector->paidSalesIds($supplierId, $period);
        $purchaseIds  = $basis === ExportBasis::Duzp
            ? array_map(static fn (array $r): int => (int) $r['id'], $duzpPurchase)
            : $this->selector->paidPurchaseIds($supplierId, $period);
        [$outsideSales, $outsidePurchase] = $this->outsideIds($supplierId, $period, $basis, $salesIds, $purchaseIds);
        [$bankPdf, $bankGpc] = $this->countStatementFiles($supplierId, $period);

        // The VAT book is always by DUZP, whatever the document basis is.
        $vatMonths = $this->selector->vatBookMonths($supplierId, $period);
        $vatBook = ($duzpSales !== [] || $duzpPurchase !== []) ? count($vatMonths) : 0;

        return [
            'counts' => [
                'sales_pdf'      => count($salesIds),
                'sales_isdoc'    => count($salesIds),
                'purchase_pdf'   => count($purchaseIds),
                'purchase_isdoc' => count($purchaseIds),
                'bank_pdf'       => $bankPdf,
                'bank_gpc'       => $bankGpc,
                'dph_book'       => $vatBook,
                'summary_csv'    => count($salesIds) + count($purchaseIds) + count($outsideSales) + count($outsidePurchase),
            ],
            'outside' => ['sales' => count($outsideSales), 'purchase' => count($outsidePurchase)],
        ];
    }

    /**
     * Documents with a VAT date in the period that were not paid in it (payment basis only,
     * the DUZP basis has nothing "outside").
     *
     * @param list<int> $salesIds
     * @param list<int> $purchaseIds
     * @return array{0:list<int>,1:list<int>} [sales ids, purchase ids]
     */
    private function outsideIds(int $supplierId, ExportPeriod $period, ExportBasis $basis, array $salesIds, array $purchaseIds): array
    {
        if ($basis !== ExportBasis::Payment) {
            return [[], []];
        }
        return [
            $this->selector->outsideSalesIds($supplierId, $period, $salesIds),
            $this->selector->outsidePurchaseIds($supplierId, $period, $purchaseIds),
        ];
    }

    /**
     * @param list<string> $requested
     * @return list<string>
     */
    public static function normalizeParts(array $requested): array
    {
        $valid = array_values(array_intersect($requested, self::ALL_PARTS));
        return $valid !== [] ? $valid : self::ALL_PARTS;
    }

    /**
     * Worker entrypoint — builds the ZIP and stores it as the job result.
     * Has its own try/catch → markFailed; cancellation via the cancel_requested flag.
     */
    public function run(int $jobId): void
    {
        $job = $this->findJob($jobId);
        if ($job === null) return;
        // Atomic queued → running (no other worker could have beaten us to it).
        if (!$this->jobs->markRunning($jobId)) return;

        $supplierId = (int) $job['supplier_id'];
        $userId = (int) ($job['created_by'] ?? 0) ?: null;
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $parts = self::normalizeParts(array_map('strval', (array) ($params['parts'] ?? [])));

        // Period from params (period/year/month/quarter). A failure fails the job right away.
        try {
            $period = $this->periodResolver->resolve($params);
            $basis = $this->periodResolver->resolveBasis($params, $period);
        } catch (\InvalidArgumentException $e) {
            $this->jobs->markFailed($jobId, 'Neplatné období: ' . $e->getMessage());
            $this->logFinished($jobId, $userId, $supplierId, '?', 'failed', ['error' => $e->getMessage()]);
            return;
        }
        $month = $period->label;  // jen pro logy / názvy souborů (YYYY-MM, YYYY-Qn nebo YYYY)
        $tmpDir = null;

        try {
            $companyName = $this->supplierCompanyName($supplierId);

            // Prepare the target path (sup-N/<jobId>-<company>-hromadny-export-<period>.zip).
            $relDir = 'sup-' . $supplierId;
            $absDir = $this->storageBaseDir() . DIRECTORY_SEPARATOR . $relDir;
            if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
                throw new \RuntimeException('Nelze vytvořit úložiště exportů.');
            }
            // Temporary files of generated content (see the class header); removed in finally.
            $tmpDir = $absDir . DIRECTORY_SEPARATOR . 'tmp-' . $jobId;
            if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
                throw new \RuntimeException('Nelze vytvořit dočasný adresář exportu.');
            }
            // Company name (without diacritics) in the ZIP name — so exports of several suppliers can be told apart.
            $companySlug = $this->asciiSlug($companyName);
            // A basis other than the period's default shows in the name (a year by DUZP vs. by payment date).
            $basisSuffix = $basis === ExportBasis::defaultFor($period) ? ''
                : ($basis === ExportBasis::Payment ? '-podle-uhrady' : '-podle-duzp');
            $fileName = $companySlug !== ''
                ? sprintf('%s-hromadny-export-%s%s.zip', $companySlug, $month, $basisSuffix)
                : sprintf('myinvoice-hromadny-export-%s%s.zip', $month, $basisSuffix);
            $relPath  = $relDir . '/' . $jobId . '-' . $fileName;
            $absPath  = $this->storageBaseDir() . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

            $zip = new ZipArchive();
            if ($zip->open($absPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Nelze vytvořit ZIP.');
            }
            // Generated content → temporary file → added to the ZIP by reference (ZipArchive would
            // otherwise keep every string in memory until close()).
            $put = function (string $entry, string $content) use ($zip, $tmpDir): void {
                $tmp = tempnam($tmpDir, 'z');
                if ($tmp === false || file_put_contents($tmp, $content) === false) {
                    throw new \RuntimeException('Nelze zapsat dočasný soubor exportu.');
                }
                $zip->addFile($tmp, $entry);
            };

            // Precompute the sources + the total for the progress bar.
            $wantSummary = in_array('summary_csv', $parts, true);
            $salesParts = count(array_intersect(['sales_pdf', 'sales_isdoc'], $parts));
            $purchParts = count(array_intersect(['purchase_pdf', 'purchase_isdoc'], $parts));
            $salesIds   = ($salesParts > 0 || $wantSummary) ? $this->salesIdsFor($supplierId, $period, $basis) : [];
            $purchRows  = ($purchParts > 0 || $wantSummary) ? $this->purchaseRowsFor($supplierId, $period, $basis) : [];
            $statements = array_intersect(['bank_pdf', 'bank_gpc'], $parts) ? $this->findStatements($supplierId, $period) : [];

            $bankFiles  = 0;
            foreach ($statements as $s) {
                if (in_array('bank_pdf', $parts, true) && $s['has_pdf']) $bankFiles++;
                if (in_array('bank_gpc', $parts, true) && $s['has_gpc']) $bankFiles++;
            }
            $dphMonths = in_array('dph_book', $parts, true) ? $this->selector->vatBookMonths($supplierId, $period) : [];
            $total = count($salesIds) * $salesParts + count($purchRows) * $purchParts + $bankFiles
                + count($dphMonths) + ($wantSummary ? 2 : 0);
            $this->jobs->updateProgress($jobId, ['total_items' => $total, 'current_step' => 'Příprava…']);

            $added = 0;
            $processed = 0;
            $summary = [];
            $warnings = [];
            $outsideStats = null;

            $bump = function (string $step) use (&$processed, $jobId): void {
                $processed++;
                if ($processed % 5 === 0) {
                    $this->jobs->updateProgress($jobId, ['processed' => $processed, 'current_step' => $step]);
                }
            };

            // 1) Issued invoices
            if ($salesIds !== [] && $salesParts > 0) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $this->jobs->appendLog($jobId, 'Vystavené faktury: ' . count($salesIds) . ' ks');
                foreach ($salesIds as $id) {
                    $inv = $this->invoiceRepo->find($id);
                    if ($inv === null) continue;
                    $typeLabel = match ($inv['invoice_type'] ?? 'invoice') {
                        'proforma' => 'Proforma', 'credit_note' => 'Dobropis',
                        'cancellation' => 'Storno', 'tax_document' => 'DanovyDoklad',
                        default => 'Faktura',
                    };
                    $base = $typeLabel . '-' . $this->sanitize((string) ($inv['varsymbol'] ?? ('draft-' . $id)));
                    if (in_array('sales_pdf', $parts, true)) {
                        try {
                            $path = $this->invoicePdf->render($id, false, $userId);
                            if (is_file($path)) {
                                $zip->addFile($path, "Vystavene-faktury/PDF/{$base}.pdf");
                                $added++; $summary['sales_pdf'] = ($summary['sales_pdf'] ?? 0) + 1;
                            }
                        } catch (\Throwable $e) { $warnings[] = "VF {$base} PDF: " . $e->getMessage(); }
                        $bump('Vystavené faktury — PDF');
                    }
                    if (in_array('sales_isdoc', $parts, true)) {
                        try {
                            $put("Vystavene-faktury/ISDOC/{$base}.isdoc", $this->isdoc->buildXml($inv));
                            $added++; $summary['sales_isdoc'] = ($summary['sales_isdoc'] ?? 0) + 1;
                        } catch (\Throwable $e) { $warnings[] = "VF {$base} ISDOC: " . $e->getMessage(); }
                        $bump('Vystavené faktury — ISDOC');
                    }
                }
            }

            // 2) Received invoices (original > reconstruction)
            if ($purchRows !== [] && $purchParts > 0) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $this->jobs->appendLog($jobId, 'Přijaté faktury: ' . count($purchRows) . ' ks');
                $archiveRoot = $this->resolveArchiveRoot();
                $archiveRootReal = realpath($archiveRoot);
                $isWindows = DIRECTORY_SEPARATOR === '\\';
                foreach ($purchRows as $r) {
                    $id = (int) $r['id'];
                    $vs = (string) ($r['varsymbol'] ?? $r['vendor_invoice_number'] ?? ('id-' . $id));
                    $vendor = (string) ($r['vendor_company_name'] ?? 'vendor');
                    $entryBase = substr($this->sanitize($vs . '-' . $vendor) ?: 'invoice', 0, 100);
                    if (in_array('purchase_pdf', $parts, true)) {
                        $originalAbs = null;
                        if (!empty($r['pdf_path'])) {
                            $absReal = realpath($archiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $r['pdf_path']));
                            if ($absReal !== false && is_file($absReal) && $archiveRootReal !== false
                                && str_starts_with(
                                    $isWindows ? strtolower($absReal) : $absReal,
                                    ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR
                                )) {
                                $originalAbs = $absReal;
                            }
                        }
                        if ($originalAbs !== null) {
                            $zip->addFile($originalAbs, "Prijate-faktury/PDF/Prijata-{$entryBase}.pdf");
                            $added++; $summary['purchase_pdf'] = ($summary['purchase_pdf'] ?? 0) + 1;
                        } else {
                            try {
                                $put("Prijate-faktury/PDF/Prijata-{$entryBase}-rekonstrukce.pdf", $this->purchasePdf->render($id, $supplierId));
                                $added++; $summary['purchase_pdf'] = ($summary['purchase_pdf'] ?? 0) + 1;
                            } catch (\Throwable $e) { $warnings[] = "PF {$vs} PDF: " . $e->getMessage(); }
                        }
                        $bump('Přijaté faktury — PDF');
                    }
                    if (in_array('purchase_isdoc', $parts, true)) {
                        try {
                            $put("Prijate-faktury/ISDOC/Prijata-{$entryBase}.isdoc", $this->purchaseExport->toIsdocXml($id, $supplierId));
                            $added++; $summary['purchase_isdoc'] = ($summary['purchase_isdoc'] ?? 0) + 1;
                        } catch (\Throwable $e) { $warnings[] = "PF {$vs} ISDOC: " . $e->getMessage(); }
                        $bump('Přijaté faktury — ISDOC');
                    }
                }
            }

            // 3) Bank statements (content is read one statement at a time, not all at once)
            if ($statements !== []) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $this->jobs->appendLog($jobId, 'Výpisy z účtu: ' . count($statements) . ' ks');
                foreach ($statements as $s) {
                    $id = (int) $s['id'];
                    if (in_array('bank_pdf', $parts, true) && $s['has_pdf']) {
                        $name = $this->statementFilename((string) ($s['pdf_name'] ?? ''), (string) ($s['account_number'] ?? ''), $id, 'pdf');
                        $put("Vypisy-z-uctu/PDF/{$name}", $this->statementBlob($id, 'pdf_content'));
                        $added++; $summary['bank_pdf'] = ($summary['bank_pdf'] ?? 0) + 1;
                        $bump('Výpisy z účtu — PDF');
                    }
                    if (in_array('bank_gpc', $parts, true) && $s['has_gpc']) {
                        $name = $this->statementFilename((string) ($s['file_name'] ?? ''), (string) ($s['account_number'] ?? ''), $id, 'gpc');
                        $put("Vypisy-z-uctu/GPC/{$name}", $this->statementBlob($id, 'file_content'));
                        $added++; $summary['bank_gpc'] = ($summary['bank_gpc'] ?? 0) + 1;
                        $bump('Výpisy z účtu — GPC');
                    }
                }
            }

            // 4) VAT book — monthly journal, always by DUZP, only for payer months.
            if ($dphMonths !== []) {
                foreach ($dphMonths as [$bookYear, $bookMon]) {
                    $this->ensureNotCancelled($jobId, $zip, $absPath);
                    try {
                        $data = $this->dphBookBuilder->build($supplierId, $bookYear, $bookMon);
                        if (!empty($data['sections'])) {
                            $put(sprintf('Kniha-DPH/kniha-dph-%04d-%02d.pdf', $bookYear, $bookMon), $this->dphBookRenderer->render($data));
                            $added++; $summary['dph_book'] = ($summary['dph_book'] ?? 0) + 1;
                        }
                    } catch (\Throwable $e) { $warnings[] = sprintf('Kniha DPH %04d-%02d: %s', $bookYear, $bookMon, $e->getMessage()); }
                    $bump('Kniha DPH');
                }
            }

            // 5) Summary overviews (CSV for Excel): issued and received invoices
            if ($wantSummary) {
                $this->ensureNotCancelled($jobId, $zip, $absPath);
                $outsideStats = $this->addSummaryCsvs(
                    $put, $supplierId, $period, $basis, $salesIds,
                    array_map(static fn (array $r): int => (int) $r['id'], $purchRows),
                    $summary, $warnings, $added,
                );
                $processed += 2;
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'current_step' => 'Souhrnné přehledy']);
            }

            if ($added === 0) {
                $zip->close();
                @unlink($absPath);
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'failed_count' => count($warnings)]);
                $this->jobs->markFailed($jobId, "Za období {$month} nejsou pro zvolené části žádná data k exportu.");
                $this->logFinished($jobId, $userId, $supplierId, $month, 'failed', ['reason' => 'no_data']);
                return;
            }

            $put('README.txt', $this->buildReadme($companyName, $month, $basis, $summary, $warnings, $outsideStats));
            if (!$zip->close()) {
                throw new \RuntimeException('Nelze dokončit ZIP.');
            }

            $size = (int) (@filesize($absPath) ?: 0);
            $this->jobs->setResult($jobId, $relPath, $fileName, $size, 'application/zip');
            $this->jobs->updateProgress($jobId, [
                'processed' => $processed,
                'created_count' => $added,
                'failed_count' => count($warnings),
                'current_step' => 'Hotovo',
            ]);
            foreach (array_slice($warnings, 0, 50) as $w) {
                $this->jobs->appendLog($jobId, 'Upozornění: ' . $w);
            }
            $this->jobs->appendLog($jobId, "Export dokončen — {$added} souborů (" . $this->humanSize($size) . ').');
            $this->jobs->markCompleted($jobId);
            $this->logFinished($jobId, $userId, $supplierId, $month, 'completed', [
                'basis' => $basis->value, 'files' => $added, 'size_bytes' => $size, 'warnings' => count($warnings),
            ]);
        } catch (CancelledException) {
            // cleanup is done by ensureNotCancelled (closed + deleted the zip), only the status is set here
            $this->jobs->markCancelled($jobId);
            $this->logFinished($jobId, $userId, $supplierId, $month, 'cancelled', []);
        } catch (\Throwable $e) {
            if (isset($zip)) $this->discardZip($zip);
            if (isset($absPath) && is_file($absPath)) @unlink($absPath);
            $this->jobs->markFailed($jobId, $e->getMessage());
            $this->logFinished($jobId, $userId, $supplierId, $month, 'failed', ['error' => $e->getMessage()]);
        } finally {
            if ($tmpDir !== null) $this->removeDir($tmpDir);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Throws CancelledException (after the zip cleanup) when the user asked for cancellation. */
    private function ensureNotCancelled(int $jobId, ZipArchive $zip, string $absPath): void
    {
        if ($this->jobs->isCancelRequested($jobId)) {
            $zip->close();
            @unlink($absPath);
            throw new CancelledException();
        }
    }

    /** Drop pending changes so closing the archive writes nothing (it may already be closed). */
    private function discardZip(ZipArchive $zip): void
    {
        try {
            $zip->unchangeAll();
            $zip->close();
        } catch (\Throwable) {
            // already closed
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /** Content of one bank statement column, read on demand (statements can be megabytes). */
    private function statementBlob(int $statementId, string $column): string
    {
        if (!in_array($column, ['pdf_content', 'file_content'], true)) {
            throw new \InvalidArgumentException('Neplatný sloupec výpisu.');
        }
        $stmt = $this->db->pdo()->prepare("SELECT {$column} FROM bank_statements WHERE id = ?");
        $stmt->execute([$statementId]);
        return (string) $stmt->fetchColumn();
    }

    /** @return list<int> */
    private function salesIdsFor(int $sid, ExportPeriod $period, ExportBasis $basis): array
    {
        return $basis === ExportBasis::Payment
            ? $this->selector->paidSalesIds($sid, $period)
            : $this->findSalesInvoiceIds($sid, $period);
    }

    /** @return list<array<string,mixed>> */
    private function purchaseRowsFor(int $sid, ExportPeriod $period, ExportBasis $basis): array
    {
        return $basis === ExportBasis::Payment
            ? $this->selector->paidPurchaseRows($sid, $period)
            : $this->findPurchaseInvoices($sid, $period);
    }

    /**
     * Writes Prehled-vydanych-faktur.csv and Prehled-prijatych-faktur.csv. With the payment basis
     * each file gets a second section: documents of the period that were not paid in it.
     *
     * @param callable(string,string):void $put
     * @param list<int> $salesIds
     * @param list<int> $purchaseIds
     * @param array<string,int> $summary
     * @param list<string> $warnings
     * @return array{sales:array{total:int,unpaid:int},purchase:array{total:int,unpaid:int}}|null counts of the
     *         documents outside the payment period (null for the DUZP basis)
     */
    private function addSummaryCsvs(
        callable $put, int $sid, ExportPeriod $period, ExportBasis $basis,
        array $salesIds, array $purchaseIds, array &$summary, array &$warnings, int &$added,
    ): ?array {
        $payment = $basis === ExportBasis::Payment;
        $outsideSales = $payment ? $this->selector->outsideSalesIds($sid, $period, $salesIds) : [];
        $outsidePurchase = $payment ? $this->selector->outsidePurchaseIds($sid, $period, $purchaseIds) : [];

        $files = [
            'Prehled-vydanych-faktur.csv' => [
                $this->selector->salesSummaryRows($sid, $salesIds, $period),
                $payment ? $this->selector->salesSummaryRows($sid, $outsideSales, $period, true) : null,
                'vydané', 'sales',
            ],
            'Prehled-prijatych-faktur.csv' => [
                $this->selector->purchaseSummaryRows($sid, $purchaseIds, $period),
                $payment ? $this->selector->purchaseSummaryRows($sid, $outsidePurchase, $period, true) : null,
                'přijaté', 'purchase',
            ],
        ];
        $stats = [];
        foreach ($files as $entry => [$rows, $outside, $label, $statKey]) {
            $missingRate = ExportSummaryCsv::unconvertibleCount($rows);
            if ($missingRate > 0) {
                $warnings[] = "Přehled ({$label} faktury): {$missingRate} dokladů v cizí měně bez kurzu není v součtech.";
            }
            $stats[$statKey] = [
                'total'  => count($outside ?? []),
                'unpaid' => count(array_filter($outside ?? [], static fn (ExportSummaryRow $r): bool => $r->paidAt === null)),
            ];
            if ($rows === [] && ($outside ?? []) === []) continue;
            $put($entry, ExportSummaryCsv::render(
                $rows, $outside, 'Doklady s DUZP v období, které nejsou v období uhrazené (neuhrazené nebo uhrazené mimo období)',
            ));
            $added++; $summary['summary_csv'] = ($summary['summary_csv'] ?? 0) + 1;
        }

        return $payment ? $stats : null;
    }

    /**
     * Log the job completion to activity_log (pairs with 'reports.monthly_export_started').
     * Runs in the CLI worker → ip/ua null.
     *
     * @param array<string,mixed> $extra
     */
    private function logFinished(int $jobId, ?int $userId, int $supplierId, string $month, string $status, array $extra): void
    {
        $this->logger->log('reports.monthly_export_finished', $userId, 'import_job', $jobId,
            array_merge(['month' => $month, 'status' => $status], $extra), null, null, $supplierId);
    }

    /** Loads the job row cross-tenant (worker). */
    private function findJob(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id, created_by, params, status FROM import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) return null;
        if ($row['params'] !== null) {
            $decoded = json_decode((string) $row['params'], true);
            $row['params'] = is_array($decoded) ? $decoded : [];
        }
        return $row;
    }

    /** @return int[] */
    private function findSalesInvoiceIds(int $sid, ExportPeriod $period): array
    {
        // Issued by DUZP (output VAT arises at the DUZP). Same as VatLedgerService.
        $sql = "SELECT id FROM invoices
                 WHERE supplier_id = ?
                   AND COALESCE(tax_date, issue_date) >= ?
                   AND COALESCE(tax_date, issue_date) <  ?
                   AND status IN ('issued','sent','reminded','paid')
              ORDER BY COALESCE(tax_date, issue_date), id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$sid, $period->dateFrom, $period->dateToExclusive]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array<string,mixed>> */
    private function findPurchaseInvoices(int $sid, ExportPeriod $period): array
    {
        // Received by the later of (DUZP, issue date) — § 73(1)(a) ZDPH; foreign
        // reverse charge by DUZP (§ 25, § 73(1)(b) — issue #117). Same as VatLedgerService.
        $dateExpr = ExportDocumentSelector::purchaseVatDateExpr();
        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.pdf_path,
                       c.company_name AS vendor_company_name
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
             LEFT JOIN countries co ON co.id = c.country_id
                 WHERE pi.supplier_id = ?
                   AND $dateExpr >= ?
                   AND $dateExpr <  ?
                   AND pi.status IN ('received', 'booked', 'paid')
                 ORDER BY $dateExpr, pi.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$sid, $period->dateFrom, $period->dateToExclusive]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> statements of the period; the content (blob) is read on write, see statementBlob() */
    private function findStatements(int $sid, ExportPeriod $period): array
    {
        $sql = "SELECT bs.id, bs.account_number, bs.file_name, bs.pdf_name,
                       (bs.pdf_content IS NOT NULL AND LENGTH(bs.pdf_content) > 0) AS has_pdf,
                       (bs.file_content IS NOT NULL AND LENGTH(bs.file_content) > 0) AS has_gpc
                  FROM bank_statements bs
                 WHERE bs.statement_date >= ?
                   AND bs.statement_date <  ?
                   AND EXISTS (
                       SELECT 1 FROM currencies cur
                        WHERE cur.supplier_id = ?
                          AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                            = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                          AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                   )
                 ORDER BY bs.statement_date, bs.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$period->dateFrom, $period->dateToExclusive, $sid]);
        return array_map(static function (array $r): array {
            $r['has_pdf'] = (bool) $r['has_pdf'];
            $r['has_gpc'] = (bool) $r['has_gpc'];
            return $r;
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return array{0:int,1:int} [bankPdfCount, bankGpcCount] without loading the blobs */
    private function countStatementFiles(int $sid, ExportPeriod $period): array
    {
        $sql = "SELECT SUM(bs.pdf_content IS NOT NULL) AS pdf_count, SUM(bs.file_content IS NOT NULL) AS gpc_count
                  FROM bank_statements bs
                 WHERE bs.statement_date >= ?
                   AND bs.statement_date <  ?
                   AND EXISTS (
                       SELECT 1 FROM currencies cur
                        WHERE cur.supplier_id = ?
                          AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                            = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bs.account_number, ''),  '[^0-9]', ''))
                          AND (bs.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = bs.bank_code)
                   )";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$period->dateFrom, $period->dateToExclusive, $sid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [(int) ($row['pdf_count'] ?? 0), (int) ($row['gpc_count'] ?? 0)];
    }

    private function resolveArchiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') return $dir;
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') return dirname($uploads) . '/purchase-invoices';
        return \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices');
    }

    private function sanitize(string $s): string
    {
        // Transliterate diacritics to ASCII (č→c, ě→e, …) instead of replacing them with an underscore.
        return ExportFilename::sanitize($s);
    }

    /** Supplier company name (for the README and the ZIP name). Empty string = not found. */
    private function supplierCompanyName(int $sid): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        return trim((string) ($stmt->fetchColumn() ?: ''));
    }

    /**
     * Company name → ASCII slug for the file name: strips Czech/Slovak diacritics
     * (č→c, ž→z, …), replaces other non-alphanumeric characters with a hyphen, limits to 60 chars.
     */
    private function asciiSlug(string $s): string
    {
        $s = ExportFilename::transliterate($s);
        $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '';
        return mb_substr(trim($s, '-'), 0, 60);
    }

    private function statementFilename(string $name, string $account, int $id, string $ext): string
    {
        $name = $name !== '' ? $name : ('vypis-' . $id . '.' . $ext);
        $account = ltrim(trim($account), '0');
        if ($account !== '') {
            $acctDigits = preg_replace('/\D/', '', $account) ?? '';
            $nameDigits = preg_replace('/\D/', '', $name) ?? '';
            $alreadyHas = str_contains($name, $account) || ($acctDigits !== '' && str_contains($nameDigits, $acctDigits));
            $acctSafe = preg_replace('/[^A-Za-z0-9_-]/', '', $account) ?? '';
            if (!$alreadyHas && $acctSafe !== '') $name = $acctSafe . '-' . $name;
        }
        return $this->sanitize($name);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024) . ' kB';
        return $bytes . ' B';
    }

    /**
     * @param array<string,int> $summary
     * @param list<string> $warnings
     * @param array{sales:array{total:int,unpaid:int},purchase:array{total:int,unpaid:int}}|null $outside
     *        documents of the period not paid in it (payment basis only)
     */
    private function buildReadme(string $companyName, string $month, ExportBasis $basis, array $summary, array $warnings, ?array $outside): string
    {
        $labels = [
            'sales_pdf' => 'Vystavené faktury (PDF)', 'sales_isdoc' => 'Vystavené faktury (ISDOC)',
            'purchase_pdf' => 'Přijaté faktury (PDF)', 'purchase_isdoc' => 'Přijaté faktury (ISDOC)',
            'bank_pdf' => 'Výpisy z účtu (PDF)', 'bank_gpc' => 'Výpisy z účtu (GPC)',
            'dph_book' => 'Kniha DPH (PDF)', 'summary_csv' => 'Souhrnné přehledy (CSV)',
        ];
        $lines = [
            'Hromadný export MyInvoice.cz', '============================',
        ];
        if (trim($companyName) !== '') {
            $lines[] = 'Firma: ' . $companyName;
        }
        $lines = array_merge($lines, ['Období: ' . $month, 'Vygenerováno: ' . date('Y-m-d H:i:s'), '']);

        if ($basis === ExportBasis::Payment) {
            $lines = array_merge($lines, [
                'Zařazení dokladů do období: podle DATA ÚHRADY (podklady pro daň z příjmů).',
                '  Příjmy a výdaje OSVČ se pro daň z příjmů počítají ke dni zaplacení, ne podle DUZP —',
                '  prosincová faktura zaplacená v lednu patří do příjmů následujícího roku.',
                '  - Vystavené faktury: doklady zaplacené v plné výši, jejichž datum úhrady spadá do období.',
                '  - Přijaté faktury: totéž podle data úhrady.',
                '  - Proformy se do příjmů nepočítají (záloha se eviduje daňovým dokladem k přijaté platbě).',
                '  - Neuhrazené doklady a doklady uhrazené mimo období v exportu nejsou; jsou vypsané',
                '    ve druhé části souborů Prehled-*.csv (doklady s DUZP v období), ať je vidět, co chybí.',
                '  - Výpisy z účtu: podle data výpisu.',
                '  - Kniha DPH: vždy podle DUZP (nezávisle na úhradě), po měsících, ve kterých byl',
                '    dodavatel plátcem DPH; za měsíce mimo plátcovství se nevytváří.',
            ]);
        } else {
            $lines = array_merge($lines, [
                'Zařazení dokladů do období: podle DUZP (shodné s Knihou DPH a přiznáním DPH):',
                '  - Vystavené faktury: podle data zdanitelného plnění (DUZP).',
                '  - Přijaté faktury: podle pozdějšího z dat DUZP / vystavení',
                '    (nárok na odpočet nelze uplatnit dříve než podle daňového dokladu).',
                '  - Výpisy z účtu: podle data výpisu.',
                '  - Kniha DPH: po měsících, ve kterých byl dodavatel plátcem DPH.',
            ]);
        }
        $lines = array_merge($lines, [
            '',
            'Soubory Prehled-vydanych-faktur.csv a Prehled-prijatych-faktur.csv: jeden řádek na doklad,',
            'UTF-8, oddělovač ";", desetinná čárka (otevřou se přímo v Excelu). Poslední řádek sekce je',
            'součet v CZK (cizí měna kurzem dokladu); proformy a zálohy vyúčtované fakturou se vypisují,',
            'ale do součtu se nepočítají.',
            '', 'Obsah:',
        ]);
        foreach ($labels as $key => $label) {
            if (isset($summary[$key])) $lines[] = sprintf('  - %s: %d', $label, $summary[$key]);
        }
        if ($outside !== null) {
            $lines[] = '';
            $lines[] = 'Doklady s DUZP v období, které nejsou uhrazené v období (jen v přehledech CSV):';
            $lines[] = sprintf('  - Vystavené: %d (z toho zcela neuhrazené %d)', $outside['sales']['total'], $outside['sales']['unpaid']);
            $lines[] = sprintf('  - Přijaté: %d (z toho zcela neuhrazené %d)', $outside['purchase']['total'], $outside['purchase']['unpaid']);
        }
        if (!empty($warnings)) {
            $lines[] = '';
            $lines[] = 'Upozornění (přeskočené položky):';
            foreach (array_slice($warnings, 0, 50) as $w) $lines[] = '  - ' . $w;
        }
        return implode("\r\n", $lines) . "\r\n";
    }
}
