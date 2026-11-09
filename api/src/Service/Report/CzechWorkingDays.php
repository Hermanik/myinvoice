<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Czech working days for tax filing deadlines.
 *
 * § 33 odst. 4 of the Tax Procedure Code (280/2009 Sb.): when the last day of a deadline falls on a Saturday, a Sunday
 * or a public holiday, the deadline ends on the nearest following working day. Without the shift the application
 * wrongly reported "overdue" (e.g. KH 06/2026: 25. 7. 2026 is a Saturday, the real deadline is Monday 27. 7. 2026).
 *
 * Public holidays per act 245/2000 Sb.: the fixed ones plus Good Friday and Easter Monday (derived from the date of
 * Easter — the anonymous Gregorian algorithm, exact for the whole range of years the returns allow).
 */
final class CzechWorkingDays
{
    /** Fixed public holidays as 'm-d' (245/2000 Sb.). */
    private const FIXED_HOLIDAYS = [
        '01-01', // Restoration Day of the Independent Czech State / New Year
        '05-01', // Labour Day
        '05-08', // Victory Day
        '07-05', // Saints Cyril and Methodius
        '07-06', // Jan Hus Day
        '09-28', // Czech Statehood Day
        '10-28', // Independent Czechoslovak State Day
        '11-17', // Struggle for Freedom and Democracy Day
        '12-24', // Christmas Eve
        '12-25', // Christmas Day
        '12-26', // St Stephen's Day
    ];

    /** Moves the date to the nearest FOLLOWING working day (the date itself included). */
    public static function shiftToWorkingDay(\DateTimeImmutable $d): \DateTimeImmutable
    {
        while (!self::isWorkingDay($d)) {
            $d = $d->modify('+1 day');
        }
        return $d;
    }

    /** The date $days working days after $d (the day itself does not count); 0 days = $d unchanged. */
    public static function addWorkingDays(\DateTimeImmutable $d, int $days): \DateTimeImmutable
    {
        while ($days > 0) {
            $d = $d->modify('+1 day');
            if (self::isWorkingDay($d)) {
                $days--;
            }
        }
        return $d;
    }

    /** The $n-th (1-based) working day of the month containing $anyDayOfMonth. */
    public static function nthWorkingDayOfMonth(\DateTimeImmutable $anyDayOfMonth, int $n): \DateTimeImmutable
    {
        $first = $anyDayOfMonth->modify('first day of this month');
        // The 1st itself counts when it is a working day, hence start one day earlier.
        return self::addWorkingDays($first->modify('-1 day'), $n);
    }

    public static function isWorkingDay(\DateTimeImmutable $d): bool
    {
        // N = ISO day of the week (6 Saturday, 7 Sunday)
        if ((int) $d->format('N') >= 6) {
            return false;
        }
        return !self::isPublicHoliday($d);
    }

    public static function isPublicHoliday(\DateTimeImmutable $d): bool
    {
        if (in_array($d->format('m-d'), self::FIXED_HOLIDAYS, true)) {
            return true;
        }
        return in_array($d->format('Y-m-d'), self::easterHolidays((int) $d->format('Y')), true);
    }

    /**
     * Good Friday and Easter Monday of the year as Y-m-d (cached: the timeline asks about hundreds of days).
     *
     * @return list<string>
     */
    private static function easterHolidays(int $year): array
    {
        static $cache = [];
        if (!isset($cache[$year])) {
            $easter = self::easterSunday($year);
            $cache[$year] = [$easter->modify('-2 days')->format('Y-m-d'), $easter->modify('+1 day')->format('Y-m-d')];
        }
        return $cache[$year];
    }

    /**
     * Easter Sunday (Gregorian calendar) — the anonymous / Meeus algorithm.
     * Deliberately without ext-calendar (easter_date), so the helper runs everywhere.
     */
    public static function easterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
