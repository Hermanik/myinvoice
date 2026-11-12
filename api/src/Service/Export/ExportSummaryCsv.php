<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

/**
 * Summary CSV of the documents of an export, written so that Czech Excel opens it directly:
 * UTF-8 with BOM, `;` separator, decimal comma, CRLF, Czech headers.
 *
 * The totals row adds up the CZK equivalents (foreign documents by their stored rate), so a
 * period with several currencies still ends in one meaningful number. Documents flagged as not
 * counted are listed but left out of the totals; foreign documents without a rate cannot be
 * converted and are left out too ({@see self::unconvertibleCount()}).
 */
final class ExportSummaryCsv
{
    private const BOM = "\xEF\xBB\xBF";
    private const SEP = ';';
    private const EOL = "\r\n";

    private const HEADERS = [
        'Číslo dokladu', 'Typ', 'Partner', 'IČO', 'DIČ', 'Datum vystavení', 'DUZP', 'Splatnost',
        'Datum úhrady', 'Základ bez DPH', 'DPH', 'Celkem s DPH', 'Měna', 'Celkem v CZK', 'Stav',
    ];

    /**
     * @param list<ExportSummaryRow> $rows
     * @param list<ExportSummaryRow>|null $outsideRows documents of the period not paid in it
     *        (payment basis only); written as a second section below the totals
     */
    public static function render(array $rows, ?array $outsideRows = null, string $outsideTitle = ''): string
    {
        $out = self::BOM;
        $out .= self::line(self::HEADERS);
        foreach ($rows as $row) {
            $out .= self::rowLine($row);
        }
        $out .= self::totalsLine($rows);

        if ($outsideRows !== null) {
            $out .= self::EOL;
            $out .= self::line([$outsideTitle]);
            $out .= self::line(self::HEADERS);
            foreach ($outsideRows as $row) {
                $out .= self::rowLine($row);
            }
            $out .= self::totalsLine($outsideRows);
        }

        return $out;
    }

    /** Counted foreign documents without a stored rate (they are missing from the totals). */
    public static function unconvertibleCount(array $rows): int
    {
        return count(array_filter($rows, static fn (ExportSummaryRow $r): bool => $r->counted && !$r->hasCzkEquivalent()));
    }

    /** Decimal comma, two places, no thousands separator (Excel groups on its own). */
    public static function number(float $value): string
    {
        return number_format($value, 2, ',', '');
    }

    private static function rowLine(ExportSummaryRow $r): string
    {
        return self::line([
            self::text($r->number), self::text($r->type), self::text($r->partner), self::text($r->ic), self::text($r->dic),
            (string) $r->issueDate, (string) $r->taxDate, (string) $r->dueDate, (string) $r->paidAt,
            self::number($r->base), self::number($r->vat), self::number($r->total), $r->currency,
            $r->isForeign() && $r->hasCzkEquivalent() ? self::number($r->toCzk($r->total)) : '',
            self::text($r->state),
        ]);
    }

    /** @param list<ExportSummaryRow> $rows */
    private static function totalsLine(array $rows): string
    {
        // Integer cents: summing many floats would drift by a cent.
        $base = $vat = $total = 0;
        foreach ($rows as $r) {
            if (!$r->counted || !$r->hasCzkEquivalent()) {
                continue;
            }
            $base += (int) round($r->toCzk($r->base) * 100);
            $vat += (int) round($r->toCzk($r->vat) * 100);
            $total += (int) round($r->toCzk($r->total) * 100);
        }

        return self::line([
            'Celkem (v CZK)', '', '', '', '', '', '', '', '',
            self::number($base / 100), self::number($vat / 100), self::number($total / 100), 'CZK', '', '',
        ]);
    }

    /** @param list<string> $cells */
    private static function line(array $cells): string
    {
        return implode(self::SEP, array_map(self::quote(...), $cells)) . self::EOL;
    }

    /**
     * A text cell that starts with a formula character would be evaluated by Excel
     * (CSV injection through a partner name), so it is forced to plain text.
     */
    private static function text(string $value): string
    {
        return $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false ? "'" . $value : $value;
    }

    private static function quote(string $cell): string
    {
        if (strpbrk($cell, "\";\r\n") === false) {
            return $cell;
        }

        return '"' . str_replace('"', '""', $cell) . '"';
    }
}
