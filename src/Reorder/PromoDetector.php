<?php

declare(strict_types=1);

namespace CW\Reorder;

/**
 * Promotion days of one brand on one channel, from revenue per unit (spec §7.3; docs/decisions.md I63). Pure: no database.
 *
 * Per channel c and brand b the daily U(t) = Σ units_online and N(t) = Σ net_online over the linked listings of items whose
 * sku.brand = b (an item without a brand is never on promotion). Days are processed in ascending order from
 * E_c - W_L - 28 + 1. The reference R(t) = the days t′ ∈ [t - 28, t - 1] with U(t′) > 0, not anomaly-excluded for (c, b) and
 * not already flagged. With |R| ≥ 7: p_ref = ΣN / ΣU over R and u_ref = ΣU / |R|, and
 *
 *   promo(c, b, t)  ⇔  U(t) ≥ promo_min_units  AND  N(t)/U(t) ≤ (1 - promo_price_drop)·p_ref  AND  U(t) ≥ promo_units_uplift·u_ref.
 *
 * Exact integer arithmetic: money in pence, the two decimal settings in millionths; the price test is cross-multiplied with
 * bcmath (the products pass 64 bits on a whole-brand day). A real case it is built for: Elux on vapeandgo from 23 Sep 2026,
 * £1.65 a unit against ~£1.95, at about 4× the units.
 */
final class PromoDetector
{
    public const REFERENCE_DAYS = 28;
    public const MIN_REFERENCE_DAYS = 7;

    private readonly int $dropE6;
    private readonly int $upliftE6;

    /**
     * @param int $minUnits promo_min_units (brand listing units that day)
     * @param string $priceDrop promo_price_drop, a decimal 0..1 ('0.10')
     * @param string $unitsUplift promo_units_uplift, a decimal ≥ 1 ('1.50')
     */
    public function __construct(public readonly int $minUnits = 20, string $priceDrop = '0.10', string $unitsUplift = '1.50')
    {
        $this->dropE6 = self::e6($priceDrop);
        $this->upliftE6 = self::e6($unitsUplift);
        if ($minUnits < 0 || $this->dropE6 > 1_000_000) {
            throw new \InvalidArgumentException('bad promotion parameters');
        }
    }

    /**
     * The flagged days.
     *
     * @param array<int, array{0: int, 1: int}> $daily day => [U(t) listing units, N(t) net in pence]; a missing day has U = 0
     * @param int $from the first day processed (E_c - W_L - 28 + 1)
     * @param int $to the last day processed (E_c)
     * @param array<int, true> $excluded the days an anomaly excludes for (c, b): never in a reference
     * @return array<int, true>
     */
    public function flag(array $daily, int $from, int $to, array $excluded = []): array
    {
        $flagged = [];
        for ($t = $from; $t <= $to; $t++) {
            $ut = $daily[$t][0] ?? 0;
            if ($ut <= 0 || $ut < $this->minUnits) {
                continue;
            }
            $n = 0;
            $sumU = 0;
            $sumN = 0;
            for ($r = $t - self::REFERENCE_DAYS; $r < $t; $r++) {
                $u = $daily[$r][0] ?? 0;
                if ($u <= 0 || isset($excluded[$r]) || isset($flagged[$r])) {
                    continue;
                }
                $n++;
                $sumU += $u;
                $sumN += $daily[$r][1];
            }
            if ($n < self::MIN_REFERENCE_DAYS) {
                continue;
            }
            // U(t) ≥ uplift · ΣU / |R|  ⇔  U(t) · |R| · 10^6 ≥ uplift_e6 · ΣU
            if ($ut * $n * 1_000_000 < $this->upliftE6 * $sumU) {
                continue;
            }
            // N(t)/U(t) ≤ (1 - drop) · ΣN/ΣU  ⇔  N(t) · ΣU · 10^6 ≤ (10^6 - drop_e6) · ΣN · U(t)
            $nt = $daily[$t][1];
            $left = bcmul(bcmul((string) $nt, (string) $sumU, 0), '1000000', 0);
            $right = bcmul(bcmul((string) (1_000_000 - $this->dropE6), (string) $sumN, 0), (string) $ut, 0);
            if (bccomp($left, $right, 0) <= 0) {
                $flagged[$t] = true;
            }
        }
        return $flagged;
    }

    /** A non-negative decimal string as millionths ('0.10' -> 100000). */
    public static function e6(string $v): int
    {
        if (preg_match('/^(\d{1,8})(?:\.(\d{1,6}))?$/D', trim($v), $m) !== 1) {
            throw new \InvalidArgumentException('not a non-negative decimal with at most 6 places');
        }
        return (int) $m[1] * 1_000_000 + (int) str_pad($m[2] ?? '', 6, '0');
    }
}
