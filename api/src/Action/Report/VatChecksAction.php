<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatCrossCheckService;
use MyInvoice\Service\Tax\ChangesAfterFilingService;
use MyInvoice\Service\Tax\VatPaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * What the DPH / KH / SH pages show next to the preview, before the XML is downloaded:
 *
 *   GET /api/reports/vat-checks?form=dphdp3|dphkh1|dphshv&year=2027&month=1[&period=quarterly]
 *
 *   {
 *     cross_check:          "Kontrola souladu" of the three outputs for the period ({@see VatCrossCheckService}),
 *     filing:               the filed/confirmed submission of this form + period (null = nothing filed yet),
 *     changed_after_filing: documents created / changed / cancelled / deleted after it was filed (null without filing),
 *     payment:              the "Platba DPH" card of the filed DPH return (dphdp3 only),
 *   }
 */
final class VatChecksAction
{
    public function __construct(
        private readonly VatCrossCheckService $crossCheck,
        private readonly TaxSubmissionRepository $submissions,
        private readonly ChangesAfterFilingService $changes,
        private readonly VatPaymentService $payments,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant', 'readonly'], true)) {
            return Json::error($response, 'forbidden', 'Nemáš oprávnění.', 403);
        }
        $supplierId = SupplierGuard::currentId($request);
        $q = $request->getQueryParams();
        $form = (string) ($q['form'] ?? 'dphdp3');
        $year = (int) ($q['year'] ?? date('Y'));
        $month = (int) ($q['month'] ?? date('n'));
        if (!in_array($form, ChangesAfterFilingService::VAT_FORMS, true) || $month < 1 || $month > 12 || $year < 2020 || $year > 2050) {
            return Json::error($response, 'validation_failed', 'Neplatný formulář, rok nebo měsíc.', 400);
        }
        $stmt = $this->db->pdo()->prepare('SELECT vat_period FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $requested = (string) ($q['period'] ?? '');
        $period = DphPriznaniBuilder::normalizePeriod(
            in_array($requested, ['monthly', 'quarterly'], true) ? $requested : null,
            $stmt->fetchColumn() ?: null,
        );

        $quarter = $period === 'quarterly' ? (int) ceil($month / 3) : null;
        $filedRows = $this->submissions->filedForPeriod($supplierId, $form, $year, $quarter === null ? $month : null, $quarter);
        $effective = array_last($filedRows);

        return Json::ok($response, [
            'cross_check'          => $this->crossCheck->check($supplierId, $year, $month, $period),
            'filing'               => $effective === null ? null : $this->slim($effective),
            'changed_after_filing' => $effective === null ? null : $this->changes->forSubmission($effective),
            'payment'              => $effective !== null && $form === VatPaymentService::FORM_CODE
                ? $this->payments->card($effective, withQr: true, filed: $filedRows)
                : null,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function slim(array $row): array
    {
        return array_intersect_key($row, array_flip([
            'id', 'form_code', 'submission_form', 'filing_status', 'filed_at', 'filing_channel',
            'filing_reference', 'confirmed_at', 'has_receipt', 'receipt_name', 'generated_at',
        ]));
    }
}
