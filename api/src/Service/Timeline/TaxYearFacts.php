<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Repository\SupplierVatStatusRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Tax\PausalSchedule;
use MyInvoice\Service\Tax\TaxCalendarService;
use MyInvoice\Service\Tax\TaxOptimizer;
use MyInvoice\Service\Vat\VatStatus;
use MyInvoice\Service\Vat\VatStatusHistory;

/**
 * What a tax year means for a natural person: is the supplier in the flat-tax regime (paušální daň) for it, did it
 * lose the regime, or was it a regular year from the start.
 *
 *   flat  the year has a flat-tax band, no VAT payer day and the income did not pass the limit: no income tax
 *         return, no insurance statements, no regular advances (the monthly flat payment covers them)
 *   lost  a band is set, but the regime does not hold for the year (§ 7a ZDP): the income paid in the year exceeded
 *         the limit of the band, or the supplier became a VAT payer in the course of the year. A regular return and
 *         the insurance statements are due; what was paid as flat tax is credited
 *   none  no band for the year (or a band left over on a supplier that is a VAT payer for the whole year)
 *
 * The income is the one the flat-tax limit of the dashboard watches: paid invoices by payment date. The estimated
 * liability of a year in the regular regime is {@see TaxYearEstimates}.
 */
final class TaxYearFacts
{
    public const FLAT = 'flat';
    public const LOST = 'lost';
    public const NONE = 'none';

    public const REASON_INCOME = 'income';
    public const REASON_VAT_PAYER = 'vat_payer';

    public function __construct(
        private readonly SupplierVatStatusRepository $flatTaxSettings,
        private readonly TaxProfileRepository $profiles,
        private readonly TaxConstantsRepository $constants,
        private readonly TaxOptimizer $optimizer,
    ) {}

    /**
     * @param list<int> $years
     * `band` is the flat-tax band set for the year (null when there is none), also for a year that lost the regime.
     *
     * @return array<int, array{regime:string, reason:?string, income:?float, limit:?float, band:?string}> by year
     */
    public function regimes(int $supplierId, string $supplierBand, VatStatusHistory $history, \DateTimeImmutable $now, array $years): array
    {
        $currentYear = (int) $now->format('Y');
        $profileBands = $this->flatTaxSettings->profileFlatTaxBands($supplierId);
        $out = [];
        foreach ($years as $year) {
            $declared = TaxCalendarService::declaredFlatTaxBand($year, $currentYear, $profileBands, $supplierBand);
            $row = ['regime' => self::NONE, 'reason' => null, 'income' => null, 'limit' => null, 'band' => null];
            if (!in_array($declared, PausalSchedule::BANDS, true)) {
                $out[$year] = $row;
                continue;
            }
            $row['band'] = $declared;
            $from = "$year-01-01";
            $to = "$year-12-31";
            if (!$history->hasPayerTimeBetween($from, $to)) {
                $row['regime'] = self::FLAT;
                if ($year <= $currentYear) {
                    $row = $this->checkIncomeLimit($supplierId, $year, $declared, $row);
                }
            } elseif ($history->hasStatusBetween($from, $to, [VatStatus::NON_PAYER, VatStatus::IDENTIFIED])) {
                // Became (or stopped being) a payer in the course of the year: the regime cannot hold for it.
                $row['regime'] = self::LOST;
                $row['reason'] = self::REASON_VAT_PAYER;
            } // else: a payer for the whole year — the band is a leftover setting, the year is a regular one
            $out[$year] = $row;
        }
        return $out;
    }

    /**
     * @param array{regime:string, reason:?string, income:?float, limit:?float, band:?string} $row
     * @return array{regime:string, reason:?string, income:?float, limit:?float, band:?string}
     */
    private function checkIncomeLimit(int $supplierId, int $year, string $band, array $row): array
    {
        $income = $this->profiles->annualIncome($supplierId, $year);
        $profile = $this->profiles->find($supplierId, $year);
        $constants = $this->constants->forYear($year);
        $pausal = $this->optimizer->compare(
            ['activity_rate' => (int) ($profile['activity_rate'] ?? 60), 'flat_tax_band' => $band, 'is_vat_payer' => false],
            $income,
            $constants,
        )['pausal'];
        $row['income'] = $income;
        if (!$pausal['applicable'] && ($pausal['reason'] ?? '') === 'over_2m') {
            $row['regime'] = self::LOST;
            $row['reason'] = self::REASON_INCOME;
            $row['limit'] = (float) $constants['vat_limit_low'];
        }
        return $row;
    }
}
