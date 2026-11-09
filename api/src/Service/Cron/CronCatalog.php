<?php

declare(strict_types=1);

namespace MyInvoice\Service\Cron;

/**
 * Catalogue of scheduled jobs - the single source of truth for:
 *   - api/bin/cron-*.php (job name)
 *   - cmd/cron-*.{cmd,sh} (wrappers)
 *   - UI System -> Scheduled jobs (recommended frequency, max age)
 *
 * If the last successful run (`last_ok_at`) is older than `max_age_hours`, the UI
 * shows a warning ("cron is not running" or "keeps failing"). The values leave a
 * safe margin (weekend, hosting outage, holiday).
 */
final class CronCatalog
{
    /**
     * @return array<int,array{
     *   script:string,
     *   recommended:string,
     *   linux_cron:string,
     *   windows_schtasks:string,
     *   max_age_hours:int,
     *   weekdays_only:bool,
     *   critical:bool,
     *   requires_config?:string
     * }>
     *
     * `requires_config` (optional) = the cfg key of a directory without which the job
     * has nothing to do (scan disabled). The UI hides the job until it is set (CronJobsAction).
     */
    public static function all(): array
    {
        return [
            [
                // Flips the cached supplier VAT flags when a dated status row comes into force.
                'script' => 'cron-refresh-vat-status',
                'recommended' => 'daily_0005',
                'linux_cron' => '5 0 * * *',
                'windows_schtasks' => '/sc daily /st 00:05',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-cleanup',
                'recommended' => 'daily_0300',
                'linux_cron' => '0 3 * * *',
                'windows_schtasks' => '/sc daily /st 03:00',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-backup',
                'recommended' => 'daily_0200',
                'linux_cron' => '0 2 * * *',
                'windows_schtasks' => '/sc daily /st 02:00',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => true,
            ],
            [
                'script' => 'cron-backup-pdf',
                'recommended' => 'daily_0230',
                'linux_cron' => '30 2 * * *',
                'windows_schtasks' => '/sc daily /st 02:30',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-backup-documents',
                'recommended' => 'daily_0235',
                'linux_cron' => '35 2 * * *',
                'windows_schtasks' => '/sc daily /st 02:35',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-bank-scan',
                'recommended' => 'every_30_min',
                'linux_cron' => '*/30 * * * *',
                'windows_schtasks' => '/sc minute /mo 30',
                'max_age_hours' => 4,
                'weekdays_only' => false,
                'critical' => false,
                'requires_config' => 'bank_import.scan_root',
            ],
            [
                'script' => 'cron-bank-email-notices',
                'recommended' => 'every_30_min',
                'linux_cron' => '*/30 * * * *',
                'windows_schtasks' => '/sc minute /mo 30',
                'max_age_hours' => 4,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-scan-purchase-inbox',
                'recommended' => 'every_10_min',
                'linux_cron' => '*/10 * * * *',
                'windows_schtasks' => '/sc minute /mo 10',
                'max_age_hours' => 2,
                'weekdays_only' => false,
                'critical' => false,
                'requires_config' => 'purchase_invoice.inbox_dir',
            ],
            [
                'script' => 'cron-send-reminders',
                'recommended' => 'weekdays_0900',
                'linux_cron' => '0 9 * * 1-5',
                'windows_schtasks' => '/sc weekly /d MON,TUE,WED,THU,FRI /st 09:00',
                'max_age_hours' => 96,
                'weekdays_only' => true,
                'critical' => false,
            ],
            [
                'script' => 'cron-send-approval-reminders',
                'recommended' => 'weekdays_0915',
                'linux_cron' => '15 9 * * 1-5',
                'windows_schtasks' => '/sc weekly /d MON,TUE,WED,THU,FRI /st 09:15',
                'max_age_hours' => 96,
                'weekdays_only' => true,
                'critical' => false,
            ],
            [
                'script' => 'cron-generate-recurring-invoices',
                'recommended' => 'daily_0630',
                'linux_cron' => '30 6 * * *',
                'windows_schtasks' => '/sc daily /st 06:30',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => true,
            ],
            [
                // Envelope-only sync of the connected data boxes; never delivers messages (see DataboxSyncService).
                'script' => 'cron-databox-sync',
                'recommended' => 'hourly_17',
                'linux_cron' => '17 * * * *',
                'windows_schtasks' => '/sc hourly /mo 1',
                'max_age_hours' => 4,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                // Reads the ISDS notification e-mails of boxes watched without a login; never delivers messages.
                'script' => 'cron-databox-email-notices',
                'recommended' => 'every_30_min',
                'linux_cron' => '*/30 * * * *',
                'windows_schtasks' => '/sc minute /mo 30',
                'max_age_hours' => 4,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                // Appends new CNB repo rates (append-only) so statutory late-payment interest keeps working
                // after the start of a half-year; max age = one month + slack.
                'script' => 'cron-refresh-repo-rates',
                'recommended' => 'monthly_0710',
                'linux_cron' => '10 7 1 * *',
                'windows_schtasks' => '/sc monthly /d 1 /st 07:10',
                'max_age_hours' => 800,
                'weekdays_only' => false,
                'critical' => false,
            ],
            [
                'script' => 'cron-version-check',
                'recommended' => 'daily_0600',
                'linux_cron' => '0 6 * * *',
                'windows_schtasks' => '/sc daily /st 06:00',
                'max_age_hours' => 36,
                'weekdays_only' => false,
                'critical' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function scripts(): array
    {
        return array_map(static fn ($e) => (string) $e['script'], self::all());
    }
}
