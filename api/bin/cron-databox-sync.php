<?php

declare(strict_types=1);

/**
 * MyInvoice.cz — cron-databox-sync
 *
 * Synchronises the message ENVELOPES (received + sent lists) of every active data box and raises the dashboard
 * items for new messages, especially from the tax office. No message content is downloaded.
 *
 * Delivery safety: listing received messages with a user who may READ them legally delivers the messages and starts
 * deadlines. The job therefore only runs with a login that cannot read (ISDS right "Zobrazovat seznamy, historii a
 * doručenky" only), unless the owner explicitly allowed delivering synchronisation for that box. See
 * MyInvoice\Service\Databox\DataboxSyncService.
 *
 * Scheduling: hourly.
 *   - Linux/cron:        17 * * * *  cd /path && php api/bin/cron-databox-sync.php
 *   - Windows/Scheduler: hourly, action: php.exe api\bin\cron-databox-sync.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DataboxOutboxRepository;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Databox\DataboxSyncService;

$container = Bootstrap::buildApp()->getContainer();
$conn = $container->get(Connection::class);

$run = CronRun::start($conn->pdo(), 'cron-databox-sync');

// Staged outgoing payloads (up to 20 MB each) that nobody confirmed must not linger in the database.
$container->get(DataboxOutboxRepository::class)->purgeExpired(new DateTimeImmutable());

$reports = $container->get(DataboxSyncService::class)->syncAll();

$summary = ['boxes' => count($reports), 'ok' => 0, 'refused' => 0, 'paused' => 0, 'errors' => 0, 'received_new' => 0, 'received_new_tax_office' => 0, 'sent_new' => 0];
foreach ($reports as $r) {
    match ($r['status']) {
        'ok' => $summary['ok']++,
        'refused', 'paused' => $summary[$r['status']]++,
        'error' => $summary['errors']++,
        default => null,
    };
    $summary['received_new'] += $r['received_new'];
    $summary['received_new_tax_office'] += $r['received_new_tax_office'];
    $summary['sent_new'] += $r['sent_new'];
    echo sprintf(
        "[%s] databox %d (%s): %s%s — new received=%d (tax office %d), new sent=%d, updated=%d, receipts=%d\n",
        date('Y-m-d H:i:s'), $r['box_id'], $r['label'], $r['status'], $r['message'] !== null ? ' (' . $r['message'] . ')' : '',
        $r['received_new'], $r['received_new_tax_office'], $r['sent_new'], $r['updated'], $r['receipts'],
    );
}

$run->finish($summary['errors'] > 0 ? 'error' : 'ok', $summary, $summary['errors'] > 0 ? 'Some data boxes failed to synchronise.' : null);
