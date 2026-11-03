<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Kontrolní hlášení DPHKH1 endpoints:
 *   GET /api/reports/dphkh1/preview?year=2026&month=5[&period=quarterly] → JSON summary
 *   GET /api/reports/dphkh1?year=2026&month=5[&period=quarterly]         → XML download
 *
 * PO = vždy měsíční (§ 101e odst. 1); FO = může být kvartální (§ 101e odst. 2).
 */
final class KontrolniHlaseniAction
{
    public function __construct(
        private readonly KontrolniHlaseniBuilder $builder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Report\TaxSubmissionArchiver $archiver,
        private readonly \MyInvoice\Service\Currency\MissingExchangeRateFiller $rateFiller,
        private readonly \MyInvoice\Service\Vat\VatStatusService $vatStatus,
        private readonly \MyInvoice\Service\Report\EpoIdentityValidator $epoValidator,
    ) {}

    /**
     * EPO identifikace: chybějící XSD-povinná pole (kód FÚ, DIČ, typ poplatníka)
     * → 422 při STAŽENÍ XML. Bez nich vznikne validně vypadající XML, které EPO
     * odmítne — uživatel to dřív zjistil až na Moje daně v den lhůty. Náhled se
     * neblokuje (chybějící pole vrací v `missing[]` vedle summary).
     */
    private function epoIdentityError(Response $response, array $missing): Response
    {
        return Json::error($response, 'epo_identity_incomplete',
            'Nelze vygenerovat XML — chybí povinné údaje pro EPO podání. Doplň je v Nastavení → Daňové nastavení.',
            422, ['missing' => $missing, 'settings_url' => '/admin/settings#epo']);
    }

    public function preview(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month, $period] = $this->parsePeriod($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        // Náhled se NEBLOKUJE ani při chybějící identifikaci — čísla výkazu si
        // uživatel musí umět zobrazit (i kdyby je jen opisoval do formuláře na
        // EPO webu). Chybějící povinná pole jdou do `missing[]`, na jejich
        // základě UI zakáže stažení XML.
        $epo = $this->epoValidator->forSupplier($supplierId, \MyInvoice\Service\Report\EpoIdentityValidator::DOC_DPHKH1);
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period);
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        return Json::ok($response, [
            'summary'  => $result['summary'],
            'missing'  => $epo['missing'],
            // Doporučená pole (ÚzP, e-mail, opr_*, telefon…) jen varují.
            'warnings' => array_merge($result['warnings'], $epo['warnings']),
        ]);
    }

    public function download(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        [$year, $month, $period] = $this->parsePeriod($request);
        if ($year === null) {
            return Json::error($response, 'validation_failed', 'Neplatný rok/měsíc.', 400);
        }
        $epo = $this->epoValidator->forSupplier($supplierId, \MyInvoice\Service\Report\EpoIdentityValidator::DOC_DPHKH1);
        if ($epo['missing'] !== []) {
            return $this->epoIdentityError($response, $epo['missing']);
        }
        // Forma podání (B/O/N/E) + datum zjištění důvodů pro následné KH (§ 101f).
        $fp = \MyInvoice\Service\Report\ReportFormParams::fromQuery(
            $request->getQueryParams(),
            KontrolniHlaseniBuilder::FORMS,
            KontrolniHlaseniBuilder::FORMS_REQUIRING_DZJIST,
        );
        if ($fp['error'] !== null) {
            return Json::error($response, 'validation_failed', $fp['error'], 400);
        }
        try {
            $this->vatStatus->assertVatDutiesInReportPeriod($supplierId, $year, $month, $period);
        } catch (\MyInvoice\Service\Vat\VatStatusException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        try {
            $result = $this->builder->build($supplierId, $year, $month, $period, $fp['form'], $fp['d_zjist']);
            // #238: doplň chybějící kurzy z ČNB a přebuildi; tvrdá chyba jen když ČNB nemá.
            if (!empty($result['missing_rates'])) {
                $this->rateFiller->fill($supplierId, $result['missing_rates']);
                $result = $this->builder->build($supplierId, $year, $month, $period, $fp['form'], $fp['d_zjist']);
                if (!empty($result['missing_rates'])) {
                    $labels = \MyInvoice\Service\Report\VatLedgerService::missingExchangeRateLabels($result['missing_rates']);
                    return Json::error($response, 'exchange_rate_missing',
                        'Nelze vytvořit XML: ČNB nemá kurz pro doklady ' . implode(', ', $labels)
                        . '. Doplňte kurz ručně u faktury a zkuste znovu.', 422);
                }
            }
        } catch (\Throwable $e) {
            return Json::error($response, 'build_failed', $e->getMessage(), 500);
        }
        $userId = (int) ($user['id'] ?? 0);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $isQuarterly = $period === 'quarterly';
        $quarter = $isQuarterly ? (int) ceil($month / 3) : null;
        $archived = $this->archiver->archive(
            $supplierId, 'dphkh1', $year, $isQuarterly ? null : $month, $quarter,
            $result['xml'], $result['summary'], $userId ?: null,
        );
        $periodLabel = $isQuarterly ? sprintf('%04d-Q%d', $year, $quarter) : sprintf('%04d-%02d', $year, $month);
        $this->logger->log('report.dphkh1_downloaded', $userId, null, null, [
            'period' => $periodLabel,
            'submission_id' => $archived['submission_id'],
            'validation_status' => $archived['validation_status'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        $filename = $isQuarterly
            ? sprintf('dphkh1-%04d-Q%d.xml', $year, $quarter)
            : sprintf('dphkh1-%04d-%02d.xml', $year, $month);
        $response->getBody()->write($result['xml']);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array{0:int|null, 1:int|null, 2:string}
     */
    private function parsePeriod(Request $request): array
    {
        $q = $request->getQueryParams();
        $year = (int) ($q['year'] ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        $period = $q['period'] ?? 'monthly';
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            return [null, null, 'monthly'];
        }
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return [null, null, 'monthly'];
        }
        return [$year, $month, $period];
    }
}
