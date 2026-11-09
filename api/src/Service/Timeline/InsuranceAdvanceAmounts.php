<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Tax\TaxConstants;

/**
 * Amounts of the monthly insurance advances of a self-employed person (OSVČ).
 *
 * The statutory minimum advance of a year comes from its tax constants: minimum assessment base / 12 × rate, rounded up
 * to a whole crown. The real advance follows the last statement (přehled), which the application does not know; after
 * the statement of the previous year the advance is the insurance of that year / 12 ({@see forYearTotal()}).
 */
final class InsuranceAdvanceAmounts
{
    public function __construct(
        private readonly TaxConstantsRepository $constants,
        private readonly TaxProfileRepository $profiles,
    ) {}

    /**
     * Statutory minimum monthly advances of the year, or null amounts when the constants do not carry them.
     *
     * @return array{social: ?float, health: ?float, secondary: bool, constants_year: int}
     */
    public function minimum(int $supplierId, int $year): array
    {
        $c = $this->constants->forYear($year);
        $secondary = (bool) ($this->profiles->find($supplierId, $year)['is_secondary'] ?? false);
        $socialBase = $c[$secondary ? 'social_min_base_secondary' : 'social_min_base_main'] ?? null;
        $healthBase = $c['health_min_base'] ?? null;
        $known = in_array($year, TaxConstants::availableYears(), true) || $this->constants->override($year) !== null;
        return [
            'social' => $socialBase !== null && isset($c['social_rate']) ? self::monthlyMinimum((float) $socialBase, (float) $c['social_rate']) : null,
            'health' => $healthBase !== null && isset($c['health_rate']) ? self::monthlyMinimum((float) $healthBase, (float) $c['health_rate']) : null,
            'secondary' => $secondary,
            'constants_year' => $known ? $year : $this->latestKnownYear($year),
        ];
    }

    /** Minimum monthly advance: the minimum annual assessment base / 12 × the rate, rounded up to a whole crown. */
    public static function monthlyMinimum(float $annualBase, float $rate): float
    {
        return (float) ceil($annualBase / 12 * $rate);
    }

    /** Monthly advance after a statement: the insurance of the statement's year / 12, rounded up to a whole crown. */
    public static function forYearTotal(float $total): float
    {
        return (float) ceil($total / 12);
    }

    private function latestKnownYear(int $year): int
    {
        $below = array_filter(TaxConstants::availableYears(), static fn (int $y): bool => $y < $year);
        return $below !== [] ? max($below) : min(TaxConstants::availableYears());
    }
}
