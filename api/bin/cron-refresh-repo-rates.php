<?php

declare(strict_types=1);

/**
 * MyInvoice.cz — cron-refresh-repo-rates
 *
 * Monthly: appends the CNB two-week repo rates that appeared since the last run to the (append-only)
 * `cnb_repo_rates` codebook used for statutory late-payment interest, and advances the "codebook is
 * current" stamp. Existing rows are never rewritten; a row the CNB file reports differently is only
 * logged. Without this job reminders would stop showing interest (and billing would be blocked) after the
 * first day of a half-year, until an admin fetches the rates by hand (Systém → Číselníky → Repo sazba ČNB).
 *
 * A failure only lands in the log and in `cron_runs` (Systém → Plánované úlohy); the dashboard shows an
 * admin-only item while interest is in use and the codebook is not current or the last run failed.
 *
 * Scheduling: on the 1st of every month.
 *   - Linux/cron:        10 7 1 * *  cd /path && php api/bin/cron-refresh-repo-rates.php
 *   - Windows/Scheduler: monthly, day 1, 07:10, action: php.exe api\bin\cron-refresh-repo-rates.php
 *
 * Idempotent — running it again appends nothing.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

// STDERR is missing when the job is spawned from the admin UI ("run now") under php-cgi/FastCGI.
if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\LateInterest\CnbRepoRateFetcher;

$container = Bootstrap::buildApp()->getContainer();
$run = CronRun::start($container->get(Connection::class)->pdo(), 'cron-refresh-repo-rates');

try {
    $result = $container->get(CnbRepoRateFetcher::class)->sync(date('Y-m-d'));
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("[%s] cron-refresh-repo-rates FAILED: %s\n", date('Y-m-d H:i:s'), $e->getMessage()));
    $run->finish('error', null, $e->getMessage(), 1);
    exit(1);
}

$added = count($result['added']);
$conflicts = count($result['conflicts']);
echo sprintf("[%s] cron-refresh-repo-rates — added=%d conflicts=%d\n", date('Y-m-d H:i:s'), $added, $conflicts);
foreach ($result['added'] as $row) {
    echo sprintf("  + %s  %.2f %%\n", $row['valid_from'], $row['rate_percent']);
}
foreach ($result['conflicts'] as $row) {
    fwrite(STDERR, sprintf(
        "  ! %s: stored %.2f %%, CNB file says %.2f %% (history is never rewritten)\n",
        $row['valid_from'], $row['stored'], $row['cnb'],
    ));
}

$container->get(ActivityLogger::class)->log('cron.refresh_repo_rates', null, null, null, $result);
$run->finish('ok', ['added' => $added, 'conflicts' => $conflicts]);
