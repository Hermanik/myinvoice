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
 * lose the regime, or was it a regular year from the start — and, for a closed year, how much income tax the
 * optimizer computes.
 *
 *   flat  the year has a flat-tax band, no VAT payer day and the income did not pass the limit: no income tax
 *         return, no insurance statements, no regular advances (the monthly flat payment covers them)
 *   lost  a band is set, but the regime does not hold for the year (§ 7a ZDP): the income paid in the year exceeded
 *         the limit of the band, or the supplier became a VAT payer in the course of the year. A regular return and
 *         the insurance statements are due; what was paid as flat tax is credited
 *   none  no band for the year (or a band left over on a supplier that is a VAT payer for the whole year)
 *
 * The income is the one the flat-tax limit of the dashboard watches: paid invoices by payment date.
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
     * @return array<int, array{regime:string, reason:?string, income:?float, limit:?float}> by year
     */
    public function regimes(int $supplierId, string $supplierBand, VatStatusHistory $history, \DateTimeImmutable $now, array $years): array
    {
        $currentYear = (int) $now->format('Y');
        $profileBands = $this->flatTaxSettings->profileFlatTaxBands($supplierId);
        $out = [];
        foreach ($years as $year) {
            $declared = TaxCalendarService::declaredFlatTaxBand($year, $currentYear, $profileBands, $supplierBand);
            $row = ['regime' => self::NONE, 'reason' => null, 'income' => null, 'limit' => null];
            if (!in_array($declared, PausalSchedule::BANDS, true)) {
                $out[$year] = $row;
                continue;
            }
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
     * Income tax of a CLOSED year as the optimizer computes it for the regular regime, or null when it cannot be told:
     * the year is not over, there is no tax profile of the year (the optimizer would only guess the activity and the
     * deductions), or the income is zero.
     *
     * @return array{tax: float, income: float}|null a negative tax is a tax bonus
     */
    public function incomeTaxLiability(int $supplierId, int $year, VatStatusHistory $history, \DateTimeImmutable $now): ?array
    {
        if ($year >= (int) $now->format('Y')) {
            return null;
        }
        $profile = $this->profiles->find($supplierId, $year);
        if ($profile === null) {
            return null;
        }
        $income = $this->profiles->annualIncome($supplierId, $year);
        if ($income <= 0) {
            return null;
        }
        $compare = $this->optimizer->compare(
            $profile + ['is_vat_payer' => $history->hasPayerTimeBetween("$year-01-01", "$year-12-31")],
            $income,
            $this->constants->forYear($year),
        );
        return ['tax' => (float) $compare['regular']['income_tax'], 'income' => $income];
    }

    /**
     * @param array{regime:string, reason:?string, income:?float, limit:?float} $row
     * @return array{regime:string, reason:?string, income:?float, limit:?float}
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
