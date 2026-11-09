<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Service\Tax\TaxCalendarService;

/**
 * Shape of one computed item of the obligations timeline.
 *
 * The backend sends codes, not prose: `type` + `params` + `notes` are rendered by the web client from its i18n
 * catalogue (`timeline.item.<type>.title|what`, `timeline.note.<code>`), so the page is available in every UI
 * language. `legal` is a language-neutral statutory reference. Status fields (status, done_source, mark) are added
 * later by {@see ObligationTimelineService}.
 */
final class TimelineItem
{
    /** @var array<string, string> app route by link label code (the label is the key of `timeline.link.*` in the web catalogue) */
    private const LINKS = [
        'dph'          => TaxCalendarService::LINKS['dph'],
        'kh'           => TaxCalendarService::LINKS['kh'],
        'sh'           => TaxCalendarService::LINKS['sh'],
        'income_tax'   => TaxCalendarService::LINKS['income_tax'],
        'optimizer'    => TaxCalendarService::LINKS['pausal'],
        'submissions'  => '/reports/submissions',
        'vat_status'   => '/admin/settings',
        'new_invoice'  => '/invoices/new',
        'invoices'     => '/invoices',
    ];

    /**
     * A note in its sent shape: the code and its parameters (an empty set is an object, so it stays `{}` in the JSON).
     *
     * @param string|array{code:string, params?:array<string,mixed>} $note
     * @return array{code:string, params:array<string,mixed>|\stdClass}
     */
    public static function note(string|array $note): array
    {
        return is_string($note) ? ['code' => $note, 'params' => new \stdClass()] : ['code' => $note['code'], 'params' => $note['params'] ?? new \stdClass()];
    }

    /**
     * @param string $type     item type, the i18n key of its title ({@see ObligationTimelineService} documents them)
     * @param array{
     *   start?: ?string, params?: array<string,mixed>, notes?: list<string|array{code:string, params?:array<string,mixed>}>,
     *   legal?: ?string, amount?: ?float, amount_estimated?: bool, links?: list<string>,
     *   obligation?: 'legal'|'recommended', severity?: 'normal'|'warning', info?: bool, auto_done?: ?bool,
     * } $o
     *   `auto_done`: null = the application has no evidence for this item (only a manual mark can finish it),
     *   true / false = the application knows whether it is done.
     *   `info`: informational only, never "overdue".
     * @return array<string,mixed>
     */
    public static function make(string $key, string $type, string $category, string $dueDate, array $o = []): array
    {
        return [
            'key'              => $key,
            'type'             => $type,
            'category'         => $category,
            'due_date'         => $dueDate,
            'start_date'       => $o['start'] ?? null,
            'params'           => ($o['params'] ?? []) === [] ? new \stdClass() : $o['params'],
            'notes'            => array_map(self::note(...), $o['notes'] ?? []),
            'legal'            => $o['legal'] ?? null,
            'amount'           => $o['amount'] ?? null,
            'amount_estimated' => $o['amount_estimated'] ?? false,
            'links'            => array_map(static fn (string $l): array => ['to' => self::LINKS[$l], 'label' => $l], $o['links'] ?? []),
            'obligation'       => $o['obligation'] ?? 'legal',
            'severity'         => $o['severity'] ?? 'normal',
            'info'             => $o['info'] ?? false,
            'auto_done'        => $o['auto_done'] ?? null,
        ];
    }
}
