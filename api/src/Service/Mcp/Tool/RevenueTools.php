<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp\Tool;

use MyInvoice\Service\Mcp\McpFormat;
use MyInvoice\Service\Mcp\McpTool;
use MyInvoice\Service\Mcp\McpToolContext;
use MyInvoice\Service\Mcp\McpToolProvider;

/** Revenue reporting over the same invoice listing the web app uses. */
final class RevenueTools implements McpToolProvider
{
    /** Same turnover definition as the monthly totals of the invoice list. */
    private const REVENUE_STATUSES = 'issued,sent,reminded,paid';
    private const REVENUE_TYPES = 'invoice,credit_note,tax_document';

    public function tools(): array
    {
        return [$this->revenueSummary()];
    }

    private function revenueSummary(): McpTool
    {
        return new McpTool(
            name: 'revenue_summary',
            title: 'Revenue summary for a year',
            description: 'Revenue (tržby) of one calendar year by month, with totals without VAT, VAT and with VAT, '
                . 'optionally broken down by client. Counts issued, sent, reminded and paid invoices, credit notes '
                . '(negative) and tax documents by DUZP (tax date, falling back to issue date); drafts, proformas and '
                . 'cancelled documents are excluded. Totals are kept per currency (no conversion).',
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'year' => ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100, 'description' => 'Calendar year, e.g. 2026.'],
                    'client_id' => ['type' => 'integer', 'description' => 'Only this client.'],
                    'currency' => ['type' => 'string', 'description' => 'Only this currency, e.g. CZK.'],
                    'by_client' => ['type' => 'boolean', 'description' => 'true = add a per-client breakdown, largest first (default false).'],
                    'top_clients' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Number of clients in the breakdown (default 15).'],
                ],
                'required' => ['year'],
                'additionalProperties' => false,
            ],
            write: false,
            handler: function (array $a, McpToolContext $c): array {
                $year = (int) $a['year'];
                $byClient = !empty($a['by_client']);
                $filter = McpFormat::compact([
                    'year'      => $year,
                    'status'    => self::REVENUE_STATUSES,
                    'type'      => self::REVENUE_TYPES,
                    'client_id' => $a['client_id'] ?? null,
                    'currency'  => $a['currency'] ?? null,
                ]);

                $listing = $c->walkInvoiceListing('/api/invoices', ['filter' => $filter]);

                $months = [];
                for ($m = 1; $m <= 12; $m++) {
                    $months[sprintf('%04d-%02d', $year, $m)] = [];
                }
                $yearTotals = [];
                $clients = [];
                foreach ($listing['rows'] as $row) {
                    $currency = (string) $row['currency'];
                    self::add($months[(string) $row['month_bucket']], $currency, $row);
                    self::add($yearTotals, $currency, $row);
                    if ($byClient) {
                        $clientId = (int) $row['client_id'];
                        $clients[$clientId]['name'] = $row['client_company_name'] ?? null;
                        $clients[$clientId]['totals'] ??= [];
                        self::add($clients[$clientId]['totals'], $currency, $row);
                    }
                }

                $result = [
                    'year'       => $year,
                    'basis'      => 'issued/sent/reminded/paid invoices, credit notes and tax documents by DUZP; '
                        . 'excludes drafts, proformas and cancelled documents',
                    'months'     => array_map(
                        static fn (string $month, array $totals): array => ['month' => $month, 'totals' => self::finish($totals)],
                        array_keys($months),
                        $months,
                    ),
                    'year_total' => self::finish($yearTotals),
                    'invoices_counted' => count($listing['rows']),
                    'truncated'  => $listing['truncated'],
                ];

                if ($byClient) {
                    // Ranking key only; currencies are not converted, so this is an approximation.
                    $revenue = array_map(static fn (array $client): float => array_sum(array_column($client['totals'], 'without_vat')), $clients);
                    arsort($revenue);
                    $result['clients'] = array_map(
                        static fn (int $id): array => ['client' => ['id' => $id, 'name' => $clients[$id]['name']], 'totals' => self::finish($clients[$id]['totals'])],
                        array_slice(array_keys($revenue), 0, (int) ($a['top_clients'] ?? 15)),
                    );
                    $result['clients_total'] = count($clients);
                }

                return $result;
            },
        );
    }

    /**
     * @param array<string,array<string,mixed>> $bucket per-currency accumulator
     * @param array<string,mixed>               $row
     */
    private static function add(array &$bucket, string $currency, array $row): void
    {
        $bucket[$currency] ??= ['currency' => $currency, 'invoices' => 0, 'without_vat' => 0.0, 'vat' => 0.0, 'with_vat' => 0.0];
        $bucket[$currency]['invoices']++;
        $bucket[$currency]['without_vat'] += (float) $row['total_without_vat'];
        $bucket[$currency]['vat'] += (float) $row['total_vat'];
        $bucket[$currency]['with_vat'] += (float) $row['total_with_vat'];
    }

    /**
     * @param array<string,array<string,mixed>> $bucket
     * @return list<array<string,mixed>>
     */
    private static function finish(array $bucket): array
    {
        ksort($bucket);
        return array_values(array_map(static fn (array $t): array => [
            'currency'    => $t['currency'],
            'invoices'    => $t['invoices'],
            'without_vat' => round($t['without_vat'], 2),
            'vat'         => round($t['vat'], 2),
            'with_vat'    => round($t['with_vat'], 2),
        ], $bucket));
    }
}
