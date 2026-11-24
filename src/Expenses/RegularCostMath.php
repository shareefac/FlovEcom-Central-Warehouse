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
        // Month or year: the first segment (calendar month or year) from the first day, the last one to $end, and the whole ones between
        // them worked out directly (never walked one by one: a one-day report years after a line's first day costs the same as one in its first
        // month; the final review of 70ab3d2, astra LOW). A whole segment adds the amount; a part of one adds amount x its days / the
        // segment's days; summed exactly, the same fraction as day by day.
        [$s1, $e1] = self::segment($every, $from);
        if ($end <= $e1) {
            return self::part($amount, self::days($from, $end), self::days($s1, $e1));
        }
        [$s2, $e2] = self::segment($every, $end);
        $first = self::part($amount, self::days($from, $e1), self::days($s1, $e1));
        $last = self::part($amount, self::days($s2, $end), self::days($s2, $e2));
        $between = self::index($every, $s2) - self::index($every, $s1) - 1;
        return self::norm($first[0] + $last[0] + $between * $amount, $first[1] * $last[2] + $last[1] * $first[2], $first[2] * $last[2]);
    }

    /** $k days of a segment of $len days of $amount, exactly. @return array{0: int, 1: int, 2: int} */
    private static function part(int $amount, int $k, int $len): array
    {
        return $k === $len ? [$amount, 0, 1] : self::norm(0, $amount * $k, $len);
    }

    /** The calendar month (counted from year 0) or year that holds $day. */
    private static function index(string $every, string $day): int
    {
        $y = (int) substr($day, 0, 4);
        return $every === 'year' ? $y : $y * 12 + (int) substr($day, 5, 2) - 1;
    }

    /** An exact amount rounded to the penny, half up (amounts are never below 0). @param array{0: int, 1: int, 2: int} $x */
    public static function round(array $x): int
    {
        return $x[0] + (2 * $x[1] >= $x[2] ? 1 : 0);
    }

    /**
     * The VAT an amount includes at $rateBp (basis points: 2000 = 20%; 0: no VAT), in whole pence: amount x rate / (100% + rate), half up (at
     * 20%: the amount / 6). The cost without VAT is the amount less it (the owner, 24 Nov 2026: "Unit 1 207.69 -> VAT 34.62, cost 173.07";
     * docs/decisions.md EX38). Integers only.
     */
    public static function vat(int $amount, int $rateBp): int
    {
        if ($amount < 0 || $rateBp < 0 || $rateBp > 10000) {
            throw new \InvalidArgumentException('an amount of 0 or more pence and a rate of 0 to 100%');
        }
        $den = 10000 + $rateBp;
        return intdiv(2 * $amount * $rateBp + $den, 2 * $den);
    }

    /** A rate in basis points as the pages say it: 2000 -> "20%", 1750 -> "17.5%". */
    public static function percent(int $rateBp): string
    {
        return rtrim(rtrim(sprintf('%d.%02d', intdiv($rateBp, 100), $rateBp % 100), '0'), '.') . '%';
    }

    /** The days from $a to $b, both included (1 when they are the same day). */
    public static function days(string $a, string $b): int
    {
        return self::epochDay($b) - self::epochDay($a) + 1;
    }

    public static function addDays(string $day, int $n): string
    {
        return self::civil(self::epochDay($day) + $n);
    }

    /**
     * Whether $s is a real day written YYYY-MM-DD in the years 2000 to 2099 (the days a person or a link may ask for: the fix round 2 of
     * 8dbee81, Codex C4: "0027-11-01" was read as 2027 by the clock functions, skipped the cap and made a share minus; docs/decisions.md EX57).
     */
    public static function isDay(string $s): bool
    {
        if (preg_match('/^(20\d{2})-(\d{2})-(\d{2})$/D', $s, $m) !== 1) {
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
        return [sprintf('%04d-%02d-01', $y, $m), sprintf('%04d-%02d-%02d', $y, $m, self::monthLength($y, $m))];
    }

    /** The days of month $m of year $y (the Gregorian calendar, any year as written). */
    private static function monthLength(int $y, int $m): int
    {
        return $m === 2 ? (($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0 ? 29 : 28) : (in_array($m, [4, 6, 9, 11], true) ? 30 : 31);
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

    /**
     * Days since 1970-01-01 of a YYYY-MM-DD day, the year exactly as written (the Gregorian calendar worked out in whole numbers; the clock
     * functions read a year below 100 as 19xx or 20xx: Codex C4, EX57).
     */
    private static function epochDay(string $day): int
    {
        [$y, $m, $d] = [(int) substr($day, 0, 4), (int) substr($day, 5, 2), (int) substr($day, 8, 2)];
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    /** The YYYY-MM-DD day $z days after 1970-01-01 (epochDay's inverse). */
    private static function civil(int $z): string
    {
        $z += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp < 10 ? $mp + 3 : $mp - 9;
        return sprintf('%04d-%02d-%02d', $yoe + $era * 400 + ($m <= 2 ? 1 : 0), $m, $d);
    }
}
