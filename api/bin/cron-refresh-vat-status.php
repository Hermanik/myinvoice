<?php

declare(strict_types=1);

/**
 * MyInvoice.cz — cron-refresh-vat-status
 *
 * Recomputes the cached `supplier.is_vat_payer` / `supplier.is_identified` columns
 * ("VAT status today") from the dated history in `supplier_vat_status_history`.
 * A history row dated in the future (e.g. payer from 2027-01-01) flips the cache the
 * first time this job runs on or after that date — no manual action needed.
 *
 * Date-based code (invoices, reports, stats) reads the history directly and never
 * depends on this job; it only keeps readers of the cache columns current.
 *
 * Scheduling: once a day shortly after midnight.
 *   - Linux/cron:        5 0 * * *  cd /path && php api/bin/cron-refresh-vat-status.php
 *   - Windows/Scheduler: daily 00:05, action: php.exe api\bin\cron-refresh-vat-status.php
 *
 * Idempotent — running it again changes nothing.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Vat\VatStatusService;

$container = Bootstrap::buildApp()->getContainer();
$conn = $container->get(Connection::class);

$run = CronRun::start($conn->pdo(), 'cron-refresh-vat-status');

$report = $container->get(VatStatusService::class)->refreshAllCaches();

echo sprintf(
    "[%s] cron-refresh-vat-status — checked=%d changed=%d%s\n",
    date('Y-m-d H:i:s'),
    $report['checked'],
    $report['changed'],
    $report['changed'] > 0 ? ' (suppliers: ' . implode(', ', $report['changed_supplier_ids']) . ')' : '',
);

$run->finish('ok', $report);
