<?php

declare(strict_types=1);

namespace CW\Counts;

/**
 * Which count differences a person must look at (pack A2a; docs/decisions.md CT6). Pure: no database. The two limits are settings
 * (counts.review_over_units, counts.review_over_percent), in UNITS until costs exist (the module plan, IM2).
 *
 *   units    a difference of MORE than the limit, up or down, is over it; a limit of 0 means every difference
 *   percent  a difference of MORE than that percent of what the system held is over it; 0 means the percent is not used. Of an
 *            item the system held nothing of (or less than nothing) there is no percentage: any difference is over the limit
 *
 * A count that found exactly what the system held is never over anything.
 */
final class CountTolerance
{
    public const UNITS = 'units';
    public const PERCENT = 'percent';

    /**
     * @param int $held what the system held just before the count was booked
     * @param int $difference what the count changed it by (+ found, - missing)
     * @return list<string> the limits the difference is over (UNITS, PERCENT); [] = inside both
     */
    public static function over(int $held, int $difference, int $unitsLimit, int $percentLimit): array
    {
        if ($unitsLimit < 0 || $percentLimit < 0) {
            throw new \InvalidArgumentException('a limit is 0 or more');
        }
        $d = abs($difference);
        if ($d === 0) {
            return [];
        }
        $over = [];
        if ($d > $unitsLimit) {
            $over[] = self::UNITS;
        }
        if ($percentLimit > 0 && ($held <= 0 || $d * 100 > $percentLimit * $held)) {
            $over[] = self::PERCENT;
        }
        return $over;
    }

    /**
     * Whether a difference needs a SECOND person (docs/decisions.md CT8): the rule of the kind of record "Stock count" on the
     * Approval Rules page. `none`: never; `all`: always; `over_limit`: a difference of more than its limit, in units.
     */
    public static function needsSecondPerson(string $reviewRule, ?int $reviewLimitUnits, int $difference): bool
    {
        return match ($reviewRule) {
            'all' => true,
            'over_limit' => abs($difference) > (int) $reviewLimitUnits,
            default => false,
        };
    }
}
