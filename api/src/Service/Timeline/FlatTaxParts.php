<?php

declare(strict_types=1);

namespace MyInvoice\Service\Timeline;

use MyInvoice\Service\Tax\PausalSchedule;

/**
 * What a monthly flat-tax payment (paušální daň, § 7a ZDP) is made of: an income tax advance of 100 Kč, health
 * insurance and social insurance. When the flat-tax regime does not hold for a year, the payments already made are
 * credited to the income tax, the health insurance and the social insurance of the year by exactly these parts.
 *
 * The split is not stored anywhere (the tax constants only carry the total of a band), so it is derived from the
 * constants of the year:
 *
 *   tax     100 Kč (§ 7a odst. 1 ZDP)
 *   health  the larger of the minimum health advance (minimum assessment base / 12 × rate, rounded up) and the share
 *           of the rest that health insurance has in the insurance computed from a profit — rate × assessment
 *           percentage of health against the same product of social. Band 1 is built on the minimum bases, so its
 *           health part is the minimum advance; bands 2 and 3 are built on a profit and split in that proportion
 *   social  the rest
 *
 * For band 1 of 2026 (9 984 Kč): 100 + 3 306 + 6 578; from 1. 7. 2026 (9 162 Kč): 100 + 3 306 + 5 756. The proportion
 * for bands 2 and 3 is an approximation of the split the tax authority publishes — the timeline calls the result
 * an estimate.
 */
final class FlatTaxParts
{
    public const TAX = 100;

    /**
     * @param array<string,mixed> $constants constants of the year
     * @return array{income_tax:int, health:int, social:int}
     */
    public static function split(float $monthly, array $constants): array
    {
        $tax = (int) min((float) self::TAX, $monthly);
        $rest = $monthly - $tax;
        $healthWeight = (float) $constants['health_rate'] * (float) $constants['health_assessment_pct'];
        $socialWeight = (float) $constants['social_rate'] * (float) $constants['social_assessment_pct'];
        $minimum = InsuranceAdvanceAmounts::monthlyMinimum((float) $constants['health_min_base'], (float) $constants['health_rate']);
        $health = (int) min($rest, max($minimum, round($rest * $healthWeight / ($healthWeight + $socialWeight))));
        return ['income_tax' => $tax, 'health' => $health, 'social' => (int) round($rest) - $health];
    }

    /**
     * Parts of the payments of the given months of a year, from the monthly amounts of the band in force in each month
     * (the amount of a band can change in the course of a year, e.g. 1. 7. 2026).
     *
     * @param array<string,mixed> $constants constants of the year, with the normalized `pausal_monthly` schedule
     * @param list<int> $months 1-12
     * @return array{income_tax:int, health:int, social:int, total:int}
     */
    public static function credited(int $year, string $band, array $constants, array $months): array
    {
        $segments = PausalSchedule::normalize($constants['pausal_monthly'] ?? []);
        $sum = ['income_tax' => 0, 'health' => 0, 'social' => 0, 'total' => 0];
        foreach ($months as $month) {
            $monthly = (float) (PausalSchedule::monthlyAt($year, $month, $segments)[$band] ?? 0);
            foreach (self::split($monthly, $constants) as $part => $amount) {
                $sum[$part] += $amount;
            }
            $sum['total'] += (int) round($monthly);
        }
        return $sum;
    }
}
