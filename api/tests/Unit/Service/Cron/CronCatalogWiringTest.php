<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Service\Cron\CronCatalog;
use PHPUnit\Framework\TestCase;

/** Every scheduled job in the catalog ships its PHP entry point and both wrappers (Linux and Windows). */
final class CronCatalogWiringTest extends TestCase
{
    public function testEveryCatalogJobHasAScriptAndBothWrappers(): void
    {
        $root = dirname(__DIR__, 5);
        foreach (CronCatalog::scripts() as $script) {
            self::assertFileExists("{$root}/api/bin/{$script}.php", $script);
            self::assertFileExists("{$root}/cmd/{$script}.sh", $script);
            self::assertFileExists("{$root}/cmd/{$script}.cmd", $script);
            self::assertTrue(is_executable("{$root}/cmd/{$script}.sh"), "{$script}.sh must be executable");
        }
    }

    public function testRepoRateRefreshIsAMonthlyJob(): void
    {
        $job = null;
        foreach (CronCatalog::all() as $candidate) {
            if ($candidate['script'] === 'cron-refresh-repo-rates') {
                $job = $candidate;
            }
        }

        self::assertNotNull($job);
        self::assertSame('10 7 1 * *', $job['linux_cron']);
        self::assertGreaterThan(31 * 24, $job['max_age_hours'], 'a month plus slack, otherwise the UI flags a healthy job');
        self::assertFalse($job['critical']);
    }
}
