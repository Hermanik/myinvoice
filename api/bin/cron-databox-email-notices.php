<?php

declare(strict_types=1);

/**
 * MyInvoice.cz — cron-databox-email-notices
 *
 * Watches the data boxes through the notification e-mails of the data box system: reads the configured mailbox folder
 * over IMAP (without marking anything read), proves each notice genuine by its DKIM signature, records the announced
 * message in the box named as its recipient and, when an alert address is set, mails one alert per new message.
 *
 * Nothing here logs in to the data box system, so reading never delivers a message. See
 * MyInvoice\Service\Databox\EmailNotice\DataboxEmailNoticeScanner.
 *
 * Scheduling: every 30 minutes (same cadence as cron-bank-email-notices).
 *   - Linux/cron:        every 30 minutes (crontab line in cmd/README.md), command: php api/bin/cron-databox-email-notices.php
 *   - Windows/Scheduler: every 30 minutes, action: php.exe api\bin\cron-databox-email-notices.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DataboxEmailNoticeRepository;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Databox\EmailNotice\DataboxEmailNoticeScanner;

$container = Bootstrap::buildApp()->getContainer();
$conn = $container->get(Connection::class);
$run = CronRun::start($conn->pdo(), 'cron-databox-email-notices');

$scanner = $container->get(DataboxEmailNoticeScanner::class);

$started = microtime(true);
$counters = ['fetched', 'created', 'known_skipped', 'ignored', 'rejected', 'unknown_box', 'parse_failed', 'auth_deferred', 'alerts_sent', 'alert_errors', 'errors'];
$summary = array_fill_keys($counters, 0) + ['suppliers' => 0, 'details' => []];

// The cron serves every supplier that switched the watcher on, whichever supplier the configuration names as default.
foreach ($container->get(DataboxEmailNoticeRepository::class)->enabledSupplierIds() as $supplierId) {
    fwrite(STDOUT, '[' . date('H:i:s') . "] supplier {$supplierId} — data box e-mail notices scan\n");
    try {
        $result = $scanner->scanSupplier($supplierId);
    } catch (\Throwable $e) {
        $summary['errors']++;
        $summary['details'][] = ['supplier_id' => $supplierId, 'error' => $e->getMessage()];
        fwrite(STDERR, "[databox-email-notices] supplier {$supplierId}: " . $e->getMessage() . "\n");
        continue;
    }
    $summary['suppliers']++;
    foreach ($counters as $key) {
        $summary[$key] += $result[$key];
    }
    if ($result['status'] === 'error') {
        $summary['errors']++;
        $summary['details'][] = ['supplier_id' => $supplierId, 'error' => $result['message']];
    }
}

$summary['duration_ms'] = (int) ((microtime(true) - $started) * 1000);
echo '[' . date('Y-m-d H:i:s') . '] databox-email-notices: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

$failed = $summary['errors'] > 0 || $summary['alert_errors'] > 0;
$run->finish($failed ? 'error' : 'ok', $summary, $failed ? 'Some data box e-mail scans or alerts failed.' : null);
