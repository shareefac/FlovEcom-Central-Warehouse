<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Reorder\DemandMath;
use CW\Reorder\Explain;

/**
 * "Why this amount?" of a line of What to buy in plain sentences (plan F308): how much it sells (and which unusual days were left
 * out), how many days of stock to aim for, what you have, and so what to buy. Reorder\Explain keeps the formula: the screens show
 * it under "Show the maths", the CSV carries it. Both are made from the same facts (ReorderList::lines() + `demand_detail`).
 *
 * Pure: no database.
 */
final class ReorderWhy
{
    /**
     * @param array<string, mixed> $l a line of ReorderList::explain(): ReorderMath's results, the item's settings and `demand_detail`
     * @return list<string>
     */
    public static function sentences(array $l): array
    {
        $out = [];
        $detail = (array) ($l['demand_detail'] ?? []);
        $listings = array_values((array) ($detail['listings'] ?? []));
        $rate = (int) ($l['rate_e4'] ?? 0);
        $perDay = DemandMath::halfUpDiv((int) ($l['d_e6'] ?? 0), 100);
        if ($listings === [] && $rate === 0) {
            $out[] = Words::WHY['sells_none'];
        } else {
            $out[] = Words::say('WHY', 'sells', Explain::rate($rate)) . self::leftOut($listings[0] ?? []);
            $factor = (int) ($l['factor_e2'] ?? 100);
            if ($factor !== 100) {
                $pct = abs($factor - 100);
                $how = Words::say('WHY', $factor > 100 ? 'factor_more' : 'factor_less', (string) $pct);
                $whose = ($l['factor_source'] ?? '') === 'item' ? Words::WHY['factor_item'] : Words::WHY['factor_brand'];
                $out[] = Words::say('WHY', 'factor', $how, $whose, Explain::rate($perDay));
            }
        }
        $out[] = Words::say('WHY', 'cover', (int) $l['lead'], (int) $l['review'], (int) $l['safety'], (int) $l['cover_days']);
        $target = (int) $l['target'];
        if (!empty($l['min_applied'])) {
            $out[] = Words::say('WHY', 'target_min', (int) ($l['min_stock'] ?? $target), $target);
        } elseif (!empty($l['max_applied'])) {
            $out[] = Words::say('WHY', 'target_max', (int) ($l['max_stock'] ?? $target), $target);
        } elseif ($perDay > 0) {
            $out[] = Words::say('WHY', 'target', (int) $l['cover_days'], Explain::rate($perDay), $target);
        }
        $site = ($l['stock_source'] ?? 'cw') === 'site';
        $out[] = Words::say('WHY', $site ? 'have_site' : 'have', (int) $l['available'], (int) $l['on_order']);
        if ($site && !empty($l['site_unreliable'])) {
            $out[] = Words::WHY['site_unsure'];
        }
        $need = (int) $l['need'];
        $upp = max(1, (int) ($l['upp'] ?? 1));
        $never = $l['never'] ?? null;
        if ($never !== null) {
            $out[] = Words::say('WHY', 'never', self::never($l));
        } elseif ($need === 0) {
            $out[] = Words::WHY['buy_none'];
        } elseif ((int) $l['packs'] === 0) {
            $out[] = Words::say('WHY', 'rounded_zero', $upp);
        } else {
            $packs = $upp > 1 ? Words::say('WHY', 'packs', (int) $l['packs'], $upp, (int) $l['units']) : Words::say('WHY', 'single', (int) $l['units']);
            $notes = [];
            if (!empty($l['moq_applied'])) {
                $notes[] = Words::say('WHY', 'smallest', (int) $l['moq']);
            }
            if (!empty($l['mult_applied'])) {
                $notes[] = Words::say('WHY', 'steps', (int) $l['mult']);
            }
            if (($l['rounding'] ?? 'up') === 'nearest') {
                $notes[] = Words::WHY['nearest'];
            }
            $out[] = Words::say('WHY', 'buy', $need, $packs . ($notes === [] ? '' : ' (' . implode(', ', $notes) . ')'));
        }
        if (!empty($l['urgent'])) {
            $out[] = Words::WHY['urgent'];
        }
        if ((int) ($l['in_drafts'] ?? 0) > 0) {
            $out[] = Words::say('WHY', 'drafts', (int) $l['in_drafts']);
        }
        return $out;
    }

    /** "Days left" of a line (plan F318): "12.5", or "no sales" where the list printed ∞. */
    public static function daysLeft(?int $coverNowE1): string
    {
        return $coverNowE1 === null ? Words::REORDER['no_sales'] : \CW\Reorder\ReorderList::cover($coverNowE1);
    }

    /**
     * " Left out: Pre-duty stockpiling 14–22 Sep, 2 promotion days." — the unusual days of the main listing's longer period
     * ('' when none).
     *
     * @param array<string, mixed> $main
     */
    private static function leftOut(array $main): string
    {
        $parts = [];
        $named = 0;
        foreach ((array) ($main['anomalies'] ?? []) as $a) {
            $n = (int) ($a['long'] ?? 0);
            if ($n > 0) {
                $parts[] = Explain::shortLabel((string) $a['label']) . ' ' . Explain::range((string) $a['from'], (string) $a['to']);
                $named += $n;
            }
        }
        $excluded = (array) ($main['excluded'] ?? []);
        foreach (['promo', 'oos'] as $reason) {
            $n = (int) ($excluded[$reason] ?? 0);
            if ($n > 0) {
                $parts[] = number_format($n) . ' ' . Words::WHY[$reason];
            }
        }
        return $parts === [] ? '' : ' ' . Words::say('WHY', 'left_out', implode(', ', $parts));
    }

    /** Why a line is never suggested, in words. @param array<string, mixed> $l */
    private static function never(array $l): string
    {
        $flags = (array) ($l['flags'] ?? []);
        return match (true) {
            in_array('merged', $flags, true) => Words::say('WHY', 'never_merged', (string) preg_replace('/^merged into /', '', (string) $l['never'])),
            in_array('do_not_reorder', $flags, true) => Words::WHY['never_marked'],
            in_array('discontinued', $flags, true) => Words::WHY['never_discontinued'],
            default => Words::WHY['never_blocked'],
        };
    }
}
