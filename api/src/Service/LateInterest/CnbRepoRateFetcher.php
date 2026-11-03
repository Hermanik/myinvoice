<?php

declare(strict_types=1);

namespace MyInvoice\Service\LateInterest;

use MyInvoice\Repository\RepoRateRepository;
use GuzzleHttp\Client;

/**
 * Pulls the official CNB history of the 2T repo rate into the (append-only) codebook.
 *
 * Source: the "download as txt" file the CNB links from
 * https://www.cnb.cz/cs/casto-kladene-dotazy/Jak-se-vyvijela-dvoutydenni-repo-sazba-CNB/
 * Format (UTF-8 with BOM, one change per line, decimal comma):
 *
 *   PLATNA_OD|CNB_REPO_SAZBA_V_%
 *   20250509|3,50
 *   20260619|3,75
 *
 * Only rows dated AFTER the latest stored row are appended; a stored row is never touched. A row
 * the file reports differently from what is stored is returned as a conflict for the admin to look
 * at — nothing is overwritten.
 */
final class CnbRepoRateFetcher
{
    public const URL = 'https://www.cnb.cz/cs/casto-kladene-dotazy/.galleries/vyvoj_repo_historie.txt';

    /** A genuine file has ~120 lines; fewer means an error page or a truncated download. */
    private const MIN_ROWS = 30;

    /** @var (callable(): string)|null */
    private $download;

    /** @param (callable(): string)|null $download returns the file body; null = HTTP GET of self::URL */
    public function __construct(
        private readonly RepoRateRepository $rates,
        ?callable $download = null,
    ) {
        $this->download = $download;
    }

    /**
     * @return array{
     *   added: list<array{valid_from: string, rate_percent: float}>,
     *   conflicts: list<array{valid_from: string, stored: float, cnb: float}>
     * }
     * @throws \RuntimeException download failed or the body is not the expected file
     */
    public function sync(string $today): array
    {
        $rows = self::parse($this->body());
        if (count($rows) < self::MIN_ROWS) {
            throw new \RuntimeException('Soubor ČNB s historií repo sazby má neočekávaný obsah.');
        }

        $stored = array_column($this->rates->all(), 'rate_percent', 'valid_from');
        $latest = $this->rates->latestValidFrom();
        $added = [];
        $conflicts = [];
        foreach ($rows as $row) {
            if ($latest === null || $row['valid_from'] > $latest) {
                $this->rates->append($row['valid_from'], $row['rate_percent'], 'cnb');
                $added[] = $row;
            } elseif (isset($stored[$row['valid_from']]) && abs($stored[$row['valid_from']] - $row['rate_percent']) > 0.001) {
                $conflicts[] = ['valid_from' => $row['valid_from'], 'stored' => $stored[$row['valid_from']], 'cnb' => $row['rate_percent']];
            }
        }

        $this->rates->markVerified($today);
        return ['added' => $added, 'conflicts' => $conflicts];
    }

    /**
     * @return list<array{valid_from: string, rate_percent: float}> ascending by date; malformed lines
     *         (the header, blank lines) are skipped
     */
    public static function parse(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $rows = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (!preg_match('/^(\d{4})(\d{2})(\d{2})\|(\d{1,2}(?:[.,]\d{1,2})?)$/', trim($line), $m)) {
                continue;
            }
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                continue;
            }
            $rows[] = [
                'valid_from'   => "{$m[1]}-{$m[2]}-{$m[3]}",
                'rate_percent' => (float) str_replace(',', '.', $m[4]),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['valid_from'] <=> $b['valid_from']);
        return $rows;
    }

    private function body(): string
    {
        if ($this->download !== null) {
            return ($this->download)();
        }
        try {
            $client = new Client(['timeout' => 10, 'connect_timeout' => 5]);
            $resp = $client->get(self::URL, ['http_errors' => false, 'headers' => ['Accept' => 'text/plain']]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Soubor ČNB se nepodařilo stáhnout: ' . $e->getMessage(), 0, $e);
        }
        if ($resp->getStatusCode() !== 200) {
            throw new \RuntimeException('Soubor ČNB se nepodařilo stáhnout (HTTP ' . $resp->getStatusCode() . ').');
        }
        return (string) $resp->getBody();
    }
}
