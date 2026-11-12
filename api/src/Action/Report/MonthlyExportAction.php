<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\ExportBasis;
use MyInvoice\Service\Export\ExportPeriod;
use MyInvoice\Service\Export\ExportPeriodResolver;
use MyInvoice\Service\Export\MonthlyExportService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Bulk export — a background job (import_jobs, source='monthly_export') that packs the existing
 * exports for a month, quarter or year into named folders (issued/received invoices PDF+ISDOC,
 * bank statements PDF+GPC, VAT book, summary CSVs). It runs in the background like the Fakturoid
 * import, because rendering the PDFs of many invoices can exceed the web timeout.
 *
 *   GET    /api/reports/monthly-export/preview?period=monthly&year=&month= → counts per part
 *   GET    /api/reports/monthly-export/preview?period=quarterly&year=&quarter=1..4
 *   GET    /api/reports/monthly-export/preview?period=yearly&year=
 *         optional &basis=duzp|payment (how documents are assigned; default duzp, payment for yearly)
 *   POST   /api/reports/monthly-export/start                    → creates the job + spawns the worker
 *   GET    /api/reports/monthly-export/jobs/{id}                → job status (polling)
 *   GET    /api/reports/monthly-export/jobs/{id}/download       → downloads the finished ZIP
 *   POST   /api/reports/monthly-export/jobs/{id}/cancel         → cancels the job
 *   DELETE /api/reports/monthly-export/jobs/{id}                → deletes the job + file
 *
 * Access: admin / accountant / readonly (an export is a read).
 */
final class MonthlyExportAction
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly MonthlyExportService $export,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly ExportPeriodResolver $periodResolver,
    ) {}

    /** GET /preview — counts of the available items per part. */
    public function preview(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        [$period, $basis, $errResp] = $this->parsePeriod($request, $response);
        if ($errResp !== null) return $errResp;
        $sid = SupplierGuard::currentId($request);

        $preview = $this->export->previewCounts($sid, $period, $basis);
        return Json::ok($response, [
            'period' => $period->label,
            'period_type' => $period->type,
            'basis' => $basis->value,
            'counts' => $preview['counts'],
            'outside' => $preview['outside'],
        ]);
    }

    /** POST /start — creates the job and spawns the background worker. */
    public function start(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        [$period, $basis, $errResp] = $this->parsePeriod($request, $response);
        if ($errResp !== null) return $errResp;
        $sid = SupplierGuard::currentId($request);
        if ($sid === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        // Reap stuck jobs (dead worker), otherwise they would block a new start.
        $this->jobs->reapStale($sid, 'monthly_export');
        foreach ($this->jobs->listForTenant($sid, 'monthly_export', limit: 5) as $existing) {
            if (in_array($existing['status'], ['queued', 'running'], true)) {
                return Json::error($response, 'already_running',
                    "Měsíční export už běží (job #{$existing['id']}).", 409,
                    ['existing_job_id' => $existing['id']]);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $rawParts = $body['parts'] ?? [];
        $parts = MonthlyExportService::normalizeParts(
            is_array($rawParts) ? array_map('strval', $rawParts) : []
        );
        $params = [
            'period'  => $period->type,
            'year'    => $period->year,
            'month'   => $period->month,
            'quarter' => $period->quarter,
            'basis'   => $basis->value,
            'parts'   => $parts,
        ];

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);
        $jobId = $this->jobs->create($sid, 'monthly_export', $params, $userId);
        $this->spawnWorker($jobId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('reports.monthly_export_started', $userId, 'import_job', $jobId, $params,
            $ip, $request->getHeaderLine('User-Agent'), $sid);

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'params' => $params], 201);
    }

    /** GET /jobs — history of the latest exports (downloadable until cleaned up). */
    public function list(Request $request, Response $response): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        $sid = SupplierGuard::currentId($request);
        $out = array_map(static fn (array $j): array => [
            'id'            => $j['id'],
            'status'        => $j['status'],
            'params'        => $j['params'],
            'total_items'   => $j['total_items'] ?? null,
            'processed'     => $j['processed'],
            'created_count' => $j['created_count'],
            'failed_count'  => $j['failed_count'],
            'current_step'  => $j['current_step'],
            'last_error'    => $j['last_error'],
            'cancel_requested' => $j['cancel_requested'],
            'result_name'   => $j['result_name'] ?? null,
            'result_size'   => $j['result_size'] ?? null,
            'created_at'    => $j['created_at'],
            'finished_at'   => $j['finished_at'],
        ], $this->jobs->listForTenant($sid, 'monthly_export', 15));
        return Json::ok($response, $out);
    }

    /** GET /jobs/{id} — stav jobu (polling). */
    public function jobStatus(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        $job = $this->findOwnedJob($request, $args);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Export job nenalezen.', 404);
        }
        return Json::ok($response, $job);
    }

    /** GET /jobs/{id}/download — downloads the finished ZIP. */
    public function download(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        $job = $this->findOwnedJob($request, $args);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Export job nenalezen.', 404);
        }
        if (($job['status'] ?? '') !== 'completed' || empty($job['result_path'])) {
            return Json::error($response, 'not_ready', 'Export ještě není připravený ke stažení.', 409);
        }

        $abs = $this->export->resolveResultPath((string) $job['result_path']);
        $absReal = realpath($abs);
        $baseReal = realpath($this->export->storageBaseDir());
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        // Path-traversal guard (result_path is system-generated, the guard is belt and braces).
        if ($absReal === false || !is_file($absReal) || $baseReal === false
            || !str_starts_with(
                $isWindows ? strtolower($absReal) : $absReal,
                ($isWindows ? strtolower($baseReal) : $baseReal) . DIRECTORY_SEPARATOR
            )) {
            return Json::error($response, 'file_unavailable', 'Soubor exportu už není k dispozici.', 410);
        }

        $name = (string) ($job['result_name'] ?: 'monthly-export.zip');
        $safeName = preg_replace('/[\x00-\x1f"\\\\]/', '_', $name) ?? $name;
        $fp = fopen($absReal, 'rb');
        if ($fp === false) {
            return Json::error($response, 'file_unavailable', 'Soubor exportu nelze otevřít.', 410);
        }

        return $response
            ->withBody(new Stream($fp))
            ->withHeader('Content-Type', (string) ($job['result_mime'] ?: 'application/zip'))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safeName . '"')
            ->withHeader('Content-Length', (string) filesize($absReal))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** POST /jobs/{id}/cancel — cancels a running/queued job. */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        if ($this->jobs->find($id, $sid) === null) {
            return Json::error($response, 'not_found', 'Export job nenalezen.', 404);
        }
        $ok = $this->jobs->requestCancel($id, $sid);
        return Json::ok($response, ['ok' => $ok, 'cancel_requested' => true]);
    }

    /** DELETE /jobs/{id} — deletes the job and its file. */
    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($err = $this->guard($request, $response)) !== null) return $err;
        $id = (int) ($args['id'] ?? 0);
        $sid = SupplierGuard::currentId($request);
        $job = $this->jobs->find($id, $sid);
        if ($job === null) {
            return Json::error($response, 'not_found', 'Export job nenalezen.', 404);
        }
        // Delete the generated ZIP too (only within the storage base — guarded).
        if (!empty($job['result_path'])) {
            $absReal = realpath($this->export->resolveResultPath((string) $job['result_path']));
            $baseReal = realpath($this->export->storageBaseDir());
            $isWindows = DIRECTORY_SEPARATOR === '\\';
            if ($absReal !== false && $baseReal !== false && is_file($absReal)
                && str_starts_with(
                    $isWindows ? strtolower($absReal) : $absReal,
                    ($isWindows ? strtolower($baseReal) : $baseReal) . DIRECTORY_SEPARATOR
                )) {
                @unlink($absReal);
            }
        }
        $this->jobs->delete($id, $sid);
        return Json::ok($response, ['ok' => true, 'deleted' => true]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function guard(Request $request, Response $response): ?Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        return null;
    }

    /** @return array{0:?ExportPeriod,1:?ExportBasis,2:?Response} [period, basis, errorResponse] */
    private function parsePeriod(Request $request, Response $response): array
    {
        // Preview reads the query, start reads the body; merge them (the body wins).
        $merged = array_merge(
            (array) $request->getQueryParams(),
            (array) ($request->getParsedBody() ?? []),
        );
        try {
            $period = $this->periodResolver->resolve($merged);
            return [$period, $this->periodResolver->resolveBasis($merged, $period), null];
        } catch (\InvalidArgumentException $e) {
            return [null, null, Json::error($response, 'validation_failed', $e->getMessage(), 400)];
        }
    }

    /** @return array<string,mixed>|null */
    private function findOwnedJob(Request $request, array $args): ?array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) return null;
        return $this->jobs->find($id, SupplierGuard::currentId($request));
    }

    private function spawnWorker(int $jobId): void
    {
        $rootDir = \MyInvoice\Bootstrap::rootDir();
        \MyInvoice\Service\BackgroundProcess::spawnPhp(
            $rootDir . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            \MyInvoice\Infrastructure\Config\RuntimePaths::log('import-worker.log'),
            $rootDir,
        );
    }
}
