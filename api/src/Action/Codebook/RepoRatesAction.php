<?php

declare(strict_types=1);

namespace MyInvoice\Action\Codebook;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\LateInterest\CnbRepoRateFetcher;
use MyInvoice\Repository\RepoRateRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Codebook of the CNB 2T repo rate used for statutory late-payment interest (GLOBAL, append-only):
 *   GET    /api/codebooks/repo-rates            list (readable by every role)
 *   POST   /api/codebooks/repo-rates            append a rate dated after the latest row (admin)
 *   DELETE /api/codebooks/repo-rates/{date}     delete a hand-typed row (admin; seeded / CNB rows are immutable)
 *   POST   /api/codebooks/repo-rates/fetch      append the new rows from the official CNB file (admin)
 */
final class RepoRatesAction
{
    public function __construct(
        private readonly RepoRateRepository $repo,
        private readonly CnbRepoRateFetcher $fetcher,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->snapshot());
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit číselník repo sazeb.', 403);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $date = (string) ($body['valid_from'] ?? '');
        if (!isset($body['rate_percent']) || !is_numeric($body['rate_percent'])) {
            return Json::error($response, 'validation_failed', 'Sazba musí být číslo.', 422);
        }
        try {
            $this->repo->append($date, (float) $body['rate_percent'], 'manual');
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        // A hand-typed rate means the admin has checked the CNB: the codebook is current as of today.
        $this->repo->markVerified(date('Y-m-d'));
        $this->audit($request, 'repo_rate.added', $date, ['rate_percent' => (float) $body['rate_percent']]);
        return Json::ok($response, $this->snapshot(), 201);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit číselník repo sazeb.', 403);
        }
        $date = (string) ($args['date'] ?? '');
        if (!$this->repo->deleteManual($date)) {
            return Json::error($response, 'not_found', 'Ručně zadaný záznam s tímto datem neexistuje (záznamy z ČNB se nemažou).', 404);
        }
        $this->audit($request, 'repo_rate.deleted', $date, []);
        return Json::ok($response, $this->snapshot());
    }

    public function fetch(Request $request, Response $response): Response
    {
        if (!$this->isAdmin($request)) {
            return Json::error($response, 'forbidden', 'Pouze admin smí měnit číselník repo sazeb.', 403);
        }
        try {
            $result = $this->fetcher->sync(date('Y-m-d'));
        } catch (\RuntimeException $e) {
            return Json::error($response, 'fetch_failed', $e->getMessage(), 502);
        }
        $this->audit($request, 'repo_rate.fetched', date('Y-m-d'), [
            'added'     => count($result['added']),
            'conflicts' => count($result['conflicts']),
        ]);
        return Json::ok($response, $this->snapshot() + ['fetch' => $result]);
    }

    /** @return array{rates: list<array<string,mixed>>, verified_on: ?string, is_current: bool} */
    private function snapshot(): array
    {
        return [
            'rates'       => array_reverse($this->repo->all()),
            'verified_on' => $this->repo->verifiedOn(),
            // Whether a delay beginning today could rely on the codebook (same rule as reminders and billing).
            'is_current'  => $this->repo->isCurrentFor(date('Y-m-d')),
        ];
    }

    private function isAdmin(Request $request): bool
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return ($user['role'] ?? '') === 'admin';
    }

    private function audit(Request $request, string $action, string $entityKey, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, $user['id'] ?? null, 'repo_rate', null, ['valid_from' => $entityKey] + $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
