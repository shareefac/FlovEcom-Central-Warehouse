<?php

declare(strict_types=1);

namespace CW\Reorder;

/**
 * One reorder line (IM9 basic, spec §7.4; docs/decisions.md I64): pure integer arithmetic, no database.
 *
 *   d   = rate(s) × φ                                   (central units a day; rate e4 × φ e2 = e6)
 *   T   = ⌈d·(L+R+S)⌉, then max(T, min_stock ?? 0), then min(T, max_stock) when set          (target)
 *   ROP = max(⌈d·(L+S)⌉, min_stock ?? 0), then min(ROP, T) when max_stock is set (I80)         (reorder point)
 *   P   = A + O       (available, which may be negative, + on order; units in drafts are shown, never counted)
 *   N   = max(0, T - P)                                                                      (need)
 *   k0  = N / upp;  k = ⌈k0⌉, or ⌊k0 + ½⌋ when pack_rounding = 'nearest';  N = 0 or k = 0 -> k = 0, otherwise
 *         k = max(k, moq) and k = ⌈k / mult⌉ · mult                                          (packs)
 *   suggested units = k·upp; value = k·last_pack_price;  urgent ⇔ P < ROP;  cover_now = P / d (∞ when d = 0).
 * An item never suggested (do_not_reorder, merged) gets k = 0 whatever its need.
 */
final class ReorderMath
{
    /**
     * @param array{rate_e4: int, factor_e2: int, lead: int, review: int, safety: int, min_stock: ?int, max_stock: ?int, available: int,
     *   on_order: int, in_drafts: int, upp: int, moq: int, mult: int, rounding: string, never: bool, pack_price_e4: ?int} $in
     * @return array{d_e6: int, cover_days: int, target: int, target_raw: int, min_applied: bool, max_applied: bool, rop: int, position: int,
     *   need: int, packs_raw: int, packs: int, moq_applied: bool, mult_applied: bool, units: int, value_e4: ?int, urgent: bool, cover_now_e1: ?int}
     */
    public static function line(array $in): array
    {
        foreach (['upp', 'moq', 'mult'] as $k) {
            if ($in[$k] < 1) {
                throw new \InvalidArgumentException("{$k} must be at least 1");
            }
        }
        if ($in['rate_e4'] < 0 || $in['factor_e2'] < 0 || $in['lead'] < 0 || $in['review'] < 0 || $in['safety'] < 0) {
            throw new \InvalidArgumentException('rates, factors and days are never negative');
        }
        $d = $in['rate_e4'] * $in['factor_e2'];
        $days = $in['lead'] + $in['review'] + $in['safety'];
        $raw = DemandMath::ceilDiv($d * $days, 1_000_000);
        $target = $raw;
        $minApplied = false;
        $maxApplied = false;
        if ($in['min_stock'] !== null && $in['min_stock'] > $target) {
            $target = $in['min_stock'];
            $minApplied = true;
        }
        if ($in['max_stock'] !== null && $target > $in['max_stock']) {
            $target = $in['max_stock'];
            $maxApplied = true;
        }
        $rop = max(DemandMath::ceilDiv($d * ($in['lead'] + $in['safety']), 1_000_000), $in['min_stock'] ?? 0);
        if ($in['max_stock'] !== null) {
            $rop = min($rop, $target); // a maximum below the cover: never "urgent" with nothing to order (review finding, I80)
        }
        $position = $in['available'] + $in['on_order'];
        $need = max(0, $target - $position);
        $upp = $in['upp'];
        $packsRaw = $in['rounding'] === 'nearest' ? intdiv(2 * $need + $upp, 2 * $upp) : DemandMath::ceilDiv($need, $upp);
        $packs = $packsRaw;
        $moqApplied = false;
        $multApplied = false;
        if ($in['never'] || $need === 0 || $packs === 0) {
            $packs = 0;
        } else {
            if ($packs < $in['moq']) {
                $packs = $in['moq'];
                $moqApplied = true;
            }
            $rounded = DemandMath::ceilDiv($packs, $in['mult']) * $in['mult'];
            $multApplied = $rounded !== $packs;
            $packs = $rounded;
        }
        return [
            'd_e6' => $d,
            'cover_days' => $days,
            'target' => $target,
            'target_raw' => $raw,
            'min_applied' => $minApplied,
            'max_applied' => $maxApplied,
            'rop' => $rop,
            'position' => $position,
            'need' => $need,
            'packs_raw' => $packsRaw,
            'packs' => $packs,
            'moq_applied' => $moqApplied,
            'mult_applied' => $multApplied,
            'units' => $packs * $upp,
            'value_e4' => $in['pack_price_e4'] === null ? null : $packs * $in['pack_price_e4'],
            'urgent' => $position < $rop,
            'cover_now_e1' => $d === 0 ? null : DemandMath::halfUpDiv($position * 10_000_000, $d),
        ];
    }

    /**
     * The list order (spec §7.4): urgent first, then cover now ascending (∞ last), then value descending, then the item.
     *
     * @param array{urgent: bool, cover_now_e1: ?int, value_e4: ?int, sku_id: int} $a
     * @param array{urgent: bool, cover_now_e1: ?int, value_e4: ?int, sku_id: int} $b
     */
    public static function compare(array $a, array $b): int
    {
        return [$b['urgent'], $a['cover_now_e1'] === null, $a['cover_now_e1'] ?? 0, -($a['value_e4'] ?? 0), $a['sku_id']]
            <=> [$a['urgent'], $b['cover_now_e1'] === null, $b['cover_now_e1'] ?? 0, -($b['value_e4'] ?? 0), $b['sku_id']];
    }
}
