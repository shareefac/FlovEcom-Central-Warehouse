<?php

declare(strict_types=1);

namespace CW\Expenses;

/**
 * A regular cost counted per day (the owner, 24 Nov 2026: "Yes, per day"; docs/decisions.md EX5, EX6). Pure arithmetic on whole pence and
 * exact fractions; never a float.
 *
 *   per day:   a weekly amount / 7; a monthly amount / the days of that calendar month (28-31); a yearly amount / the days of that calendar
 *              year (365 or 366). A line counts from its first day to its last day (until), both included; no last day: no end.
 *   rounding:  ONE rule, the cumulative one: what a line accrued from its first day to the end of day D is an exact fraction A(D); its share
 *              of the days a..b is round(A(b)) - round(A(a - 1)), each half up. So the shares of following periods always add up to the
 *              share of the whole (a month is the sum of its days, a year of its months), 7 days of a weekly line are its amount exactly
 *              (A grows by the whole amount in 7 days), a whole calendar month of a monthly line its amount, and so is a calendar year of a
 *              yearly line. A period's share is within a penny of its own exact value. A shop's figure is the sum of its lines' shares (each
 *              line rounded once per period), so the lines shown always add up to the shop's line.
 */
final class RegularCostMath
{
    public const EVERY = ['week', 'month', 'year'];

    /**
     * A line's share of the days $a..$b (both included, $a <= $b), in whole pence.
     *
     * @param string $every week | month | year
     * @param int $amount pence a week, a calendar month or a calendar year (> 0)
     */
    public static function share(string $every, int $amount, string $from, ?string $until, string $a, string $b): int
    {
        if ($b < $a) {
            throw new \InvalidArgumentException('a period ends on or after its first day');
        }
        $dayBefore = self::addDays($a, -1);
        return self::round(self::accrued($every, $amount, $from, $until, $b)) - self::round(self::accrued($every, $amount, $from, $until, $dayBefore));
    }

    /**
     * What a line accrued from its first day to the end of $day (0 before its first day; after its last day, what it accrued to its last day),
     * exactly: [whole pence, numerator, denominator] with 0 <= numerator < denominator.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function accrued(string $every, int $amount, string $from, ?string $until, string $day): array
    {
        if (!in_array($every, self::EVERY, true) || $amount < 0) {
            throw new \InvalidArgumentException('a regular cost is every week, month or year, of 0 or more pence');
        }
        $end = $until !== null && $until < $day ? $until : $day;
        if ($end < $from) {
            return [0, 0, 1];
        }
        if ($every === 'week') {
            $n = self::days($from, $end);
            return self::norm(0, $amount * $n, 7);
        }
        // Month or year: whole segments (calendar months or years) add the amount; a part of one adds amount x its days / the segment's days.
        $whole = 0;
        $num = 0;
        $den = 1;
        $cursor = $from;
        while ($cursor <= $end) {
            [$segStart, $segEnd] = self::segment($every, $cursor);
            $last = $segEnd < $end ? $segEnd : $end;
            $len = self::days($segStart, $segEnd);
            $k = self::days($cursor, $last);
            if ($k === $len) {
                $whole += $amount;
            } else {
                [$w, $n, $d] = self::norm(0, $amount * $k, $len);
                $whole += $w;
                [$w2, $num, $den] = self::norm(0, $num * $d + $n * $den, $den * $d);
                $whole += $w2;
            }
            if ($last >= $end) {
                break; // the last day is done: never a day after it (the year 9999's next day is no YYYY day: the Codex check r3, M11)
            }
            $cursor = self::addDays($last, 1);
        }
        return [$whole, $num, $den];
    }

    /** An exact amount rounded to the penny, half up (amounts are never below 0). @param array{0: int, 1: int, 2: int} $x */
    public static function round(array $x): int
    {
        return $x[0] + (2 * $x[1] >= $x[2] ? 1 : 0);
    }

    /** The days from $a to $b, both included (1 when they are the same day). */
    public static function days(string $a, string $b): int
    {
        return self::epochDay($b) - self::epochDay($a) + 1;
    }

    public static function addDays(string $day, int $n): string
    {
        return gmdate('Y-m-d', (self::epochDay($day) + $n) * 86400);
    }

    /** Whether $s is a real day written YYYY-MM-DD. */
    public static function isDay(string $s): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $s, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @return array{0: string, 1: string} the calendar month or year that holds $day */
    private static function segment(string $every, string $day): array
    {
        $y = (int) substr($day, 0, 4);
        if ($every === 'year') {
            return [sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)];
        }
        $m = (int) substr($day, 5, 2);
        return [sprintf('%04d-%02d-01', $y, $m), sprintf('%04d-%02d-%s', $y, $m, gmdate('t', gmmktime(0, 0, 0, $m, 1, $y)))];
    }

    /** whole + num / den with num reduced below den and the fraction in lowest terms. @return array{0: int, 1: int, 2: int} */
    private static function norm(int $whole, int $num, int $den): array
    {
        $whole += intdiv($num, $den);
        $num %= $den;
        if ($num === 0) {
            return [$whole, 0, 1];
        }
        $g = self::gcd($num, $den);
        return [$whole, intdiv($num, $g), intdiv($den, $g)];
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a;
    }

    /** Days since 1970-01-01 of a YYYY-MM-DD day (UTC: no clock change). */
    private static function epochDay(string $day): int
    {
        $t = gmmktime(0, 0, 0, (int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4));
        return intdiv($t, 86400);
    }
}
