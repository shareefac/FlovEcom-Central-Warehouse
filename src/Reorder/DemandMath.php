<?php

declare(strict_types=1);

namespace CW\Reorder;

/**
 * The demand of ONE listing (IM9 basic, spec §7.3; docs/decisions.md I62): pure integer arithmetic, no database.
 *
 * For a listing ℓ of channel c (coverage [S_c, E_c]) with daily listing units q(t) (online + office; 0 on a day inside the
 * coverage without a row):
 *   first(ℓ) = the earliest t in the history read (the 365 days to E_c) with q(t) > 0; none -> the listing contributes nothing.
 *   D_L = [E_c - W_L + 1, E_c], D_S = [E_c - W_S + 1, E_c]. A day of D_L takes the FIRST matching exclusion:
 *     1 nodata        t < S_c
 *     2 before_first  t < first(ℓ)
 *     3 anomaly       an active demand_anomaly covers it (channel NULL or c, brand NULL or the item's)
 *     4 promo         PromoDetector flagged (c, brand, t)
 *     5 oos           the channel's snapshot covered t, the listing was unsellable that day, and q(t) = 0
 *   V_L = the other days of D_L, V_S = V_L ∩ D_S.
 *   Spike cap: μ = Σ_{V_L} q / |V_L|; cap = max(f, ⌈k·μ⌉); q′ = min(q, cap); capped = #{t ∈ V_L : q(t) > cap}.
 *   r_S = Σ_{V_S} q′ / |V_S|, r_L = Σ_{V_L} q′ / |V_L| (listing units a day, 4 decimals half-up);
 *   rate = 0 if |V_L| = 0; w·r_S + (1-w)·r_L if |V_S| ≥ m_S and |V_L| ≥ m_L; r_S if |V_S| ≥ m_S; else Σ_{V_L} q′ / max(|V_L|, 7).
 *
 * Rates are held as e4 integers (1/10,000 unit a day); the weight w as e6 (millionths). Days are integer day numbers (days
 * since 1970-01-01, UTC dates).
 */
final class DemandMath
{
    public const REASONS = ['nodata', 'before_first', 'anomaly', 'promo', 'oos'];
    public const METHOD_BLEND = 'blend';
    public const METHOD_SHORT = 'short';
    public const METHOD_MIN7 = 'min7';
    public const METHOD_NONE = 'none';
    /** The history read for first(), units_365 and the plain 30-day average. */
    public const HISTORY_DAYS = 365;
    public const RAW_DAYS = 30;

    /** @var array<string, int> */
    private static array $dayCache = [];

    /**
     * @param int $shortWindow W_S (days)
     * @param int $longWindow W_L (days)
     * @param int $weightE6 w in millionths (0..1,000,000)
     * @param int $minShort m_S
     * @param int $minLong m_L
     * @param int $capMultiple k
     * @param int $capFloor f
     */
    public function __construct(
        public readonly int $shortWindow = 28,
        public readonly int $longWindow = 91,
        public readonly int $weightE6 = 500_000,
        public readonly int $minShort = 7,
        public readonly int $minLong = 21,
        public readonly int $capMultiple = 4,
        public readonly int $capFloor = 5,
    ) {
        if ($shortWindow < 1 || $longWindow < $shortWindow || $longWindow > 366 || $weightE6 < 0 || $weightE6 > 1_000_000 || $minShort < 1 || $minLong < 1
            || $capMultiple < 1 || $capFloor < 0) {
            throw new \InvalidArgumentException('bad demand parameters');
        }
    }

    /**
     * The demand of one listing.
     *
     * @param int $end E_c (day number)
     * @param int $start S_c (day number)
     * @param array<int, int> $q day => listing units (> 0) over the history read; a missing day inside the coverage is 0
     * @param array<int, int> $anomaly day => the id of the first active anomaly that covers it for this channel and brand
     * @param array<int, true> $promo days PromoDetector flagged for this channel and brand
     * @param array<int, true> $snapshot days the channel's stock snapshot covered
     * @param array<int, true> $unsellable days the listing was unsellable on the snapshot
     * @param bool $withDays also return `days`: every day of D_L => [q, reason or null, capped]
     * @return array<string, mixed>|null null when the listing has no sale in [max(S_c, E_c - 364), E_c]: it contributes nothing.
     *         Keys: first, last (days), valid_short, valid_long, excluded / excluded_short (reason => days), anomalies (id =>
     *         [short, long] days), cap (?int), capped, r_short_e4 (?int), r_long_e4 (?int), rate_e4, method, sum_raw30, units_365
     */
    public function listing(int $end, int $start, array $q, array $anomaly = [], array $promo = [], array $snapshot = [], array $unsellable = [],
        bool $withDays = false): ?array
    {
        $histFrom = max($start, $end - self::HISTORY_DAYS + 1);
        $first = null;
        $last = null;
        $units365 = 0;
        $sumRaw = 0;
        foreach ($q as $day => $units) {
            if ($units <= 0 || $day > $end || $day < $start) {
                continue;
            }
            if ($day >= $histFrom) {
                $first = $first === null ? $day : min($first, $day);
                $last = $last === null ? $day : max($last, $day);
                $units365 += $units;
            }
            if ($day > $end - self::RAW_DAYS) {
                $sumRaw += $units;
            }
        }
        if ($first === null) {
            return null;
        }
        $longFrom = $end - $this->longWindow + 1;
        $shortFrom = $end - $this->shortWindow + 1;
        $excluded = array_fill_keys(self::REASONS, 0);
        $excludedShort = array_fill_keys(self::REASONS, 0);
        $anomalies = [];
        $valid = [];
        $days = [];
        for ($t = $longFrom; $t <= $end; $t++) {
            $qt = $q[$t] ?? 0;
            $reason = match (true) {
                $t < $start => 'nodata',
                $t < $first => 'before_first',
                isset($anomaly[$t]) => 'anomaly',
                isset($promo[$t]) => 'promo',
                isset($snapshot[$t]) && isset($unsellable[$t]) && $qt === 0 => 'oos',
                default => null,
            };
            if ($withDays) {
                $days[$t] = [$qt, $reason, false];
            }
            if ($reason === null) {
                $valid[$t] = $qt;
                continue;
            }
            $excluded[$reason]++;
            if ($t >= $shortFrom) {
                $excludedShort[$reason]++;
            }
            if ($reason === 'anomaly') {
                $id = $anomaly[$t];
                $anomalies[$id] ??= [0, 0];
                $anomalies[$id][1]++;
                if ($t >= $shortFrom) {
                    $anomalies[$id][0]++;
                }
            }
        }
        $nL = count($valid);
        $out = ['first' => $first, 'last' => $last, 'valid_long' => $nL, 'valid_short' => 0, 'excluded' => $excluded, 'excluded_short' => $excludedShort,
            'anomalies' => $anomalies, 'cap' => null, 'capped' => 0, 'r_short_e4' => null, 'r_long_e4' => null, 'rate_e4' => 0, 'method' => self::METHOD_NONE,
            'sum_raw30' => $sumRaw, 'units_365' => $units365] + ($withDays ? ['days' => $days] : []);
        if ($nL === 0) {
            return $out;
        }
        $sum = array_sum($valid);
        $cap = max($this->capFloor, self::ceilDiv($this->capMultiple * $sum, $nL));
        $sumL = 0;
        $sumS = 0;
        $nS = 0;
        $capped = 0;
        foreach ($valid as $t => $qt) {
            if ($qt > $cap) {
                $capped++;
                $qt = $cap;
                if ($withDays) {
                    $days[$t][2] = true;
                }
            }
            $sumL += $qt;
            if ($t >= $shortFrom) {
                $sumS += $qt;
                $nS++;
            }
        }
        $rL = self::halfUpDiv($sumL * 10_000, $nL);
        $rS = $nS > 0 ? self::halfUpDiv($sumS * 10_000, $nS) : null;
        if ($nS >= $this->minShort && $nL >= $this->minLong) {
            $rate = self::halfUpDiv($this->weightE6 * (int) $rS + (1_000_000 - $this->weightE6) * $rL, 1_000_000);
            $method = self::METHOD_BLEND;
        } elseif ($nS >= $this->minShort) {
            $rate = (int) $rS;
            $method = self::METHOD_SHORT;
        } else {
            $rate = self::halfUpDiv($sumL * 10_000, max($nL, 7));
            $method = self::METHOD_MIN7;
        }
        return ['valid_short' => $nS, 'cap' => $cap, 'capped' => $capped, 'r_short_e4' => $rS, 'r_long_e4' => $rL, 'rate_e4' => $rate, 'method' => $method]
            + ($withDays ? ['days' => $days] : []) + $out;
    }

    /**
     * The plain average of the last 30 days as ERPNext's "Fetch Item" would read it, in central units a day (e4): Σ_ℓ u ·
     * Σ_{t ∈ [E_c - 29, E_c]} q(t) / 30, no exclusions. $sums are the listings' u × (30-day sums).
     *
     * @param list<int> $sums
     */
    public static function rawRateE4(array $sums): int
    {
        return self::halfUpDiv(array_sum($sums) * 10_000, self::RAW_DAYS);
    }

    /** ⌊(2a + b) / 2b⌋: a / b rounded half up (a ≥ 0, b > 0). */
    public static function halfUpDiv(int $a, int $b): int
    {
        if ($b <= 0) {
            throw new \InvalidArgumentException('division by a non-positive number');
        }
        if ($a < 0) {
            return -self::halfUpDiv(-$a, $b);
        }
        return intdiv(2 * $a + $b, 2 * $b);
    }

    /** ⌈a / b⌉ for a ≥ 0, b > 0. */
    public static function ceilDiv(int $a, int $b): int
    {
        if ($b <= 0) {
            throw new \InvalidArgumentException('division by a non-positive number');
        }
        if ($a <= 0) {
            return -intdiv(-$a, $b);
        }
        return intdiv($a + $b - 1, $b);
    }

    /** An e4 integer as a decimal string with 4 places ('12.3400', '-0.0500'). */
    public static function e4(int $v): string
    {
        $sign = $v < 0 ? '-' : '';
        $v = abs($v);
        return $sign . intdiv($v, 10_000) . '.' . str_pad((string) ($v % 10_000), 4, '0', STR_PAD_LEFT);
    }

    /** A DECIMAL string with up to 4 places as an e4 integer ('12.34' -> 123400). */
    public static function toE4(string $v): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,4})\d*)?$/D', trim($v), $m) !== 1) {
            throw new \InvalidArgumentException('not a decimal');
        }
        $n = (int) $m[2] * 10_000 + (int) str_pad($m[3] ?? '', 4, '0');
        return $m[1] === '-' ? -$n : $n;
    }

    /** The day number of a 'Y-m-d' date (days since 1970-01-01). */
    public static function day(string $ymd): int
    {
        if (isset(self::$dayCache[$ymd])) {
            return self::$dayCache[$ymd];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('not a date');
        }
        $d = intdiv(gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]), 86_400);
        if (count(self::$dayCache) > 20_000) {
            self::$dayCache = [];
        }
        return self::$dayCache[$ymd] = $d;
    }

    /** The 'Y-m-d' date of a day number. */
    public static function date(int $day): string
    {
        return gmdate('Y-m-d', $day * 86_400);
    }
}
