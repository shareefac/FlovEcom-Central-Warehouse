<?php

declare(strict_types=1);

namespace CW\Reorder;

/**
 * The "Why" text of a reorder line (spec §7.4; docs/decisions.md I65). Pure: it only formats what DemandBuilder stored
 * (reorder_demand + its detail JSON) and ReorderMath computed. For example:
 *
 *   Demand 12.4/day = 0.5×13.1 (28 days: 19 valid; 9 excluded: Pre-duty stockpiling 14–22 Sep) + 0.5×11.7 (91 days: 76 valid;
 *   15 excluded: 9 Pre-duty stockpiling 14–22 Sep, 2 promotion, 2 out of stock, 2 before the first sale; 1 day capped at 40)
 *   [vapeandgo 11.9 + electrofag 0.5]; factor 1.00. Cover 2 lead + 7 review + 5 safety = 14 days → target 174. Available 60
 *   + on order 48 = 108 (in drafts 0). Need 66 → 3 × box of 24 = 72 (MOQ 2). Plain 30-day average 15.2/day.
 *
 * The window facts (valid and excluded days, the cap) are those of the item's main listing (the one that contributes most);
 * the per-channel split follows in brackets when the item sells on more than one listing.
 */
final class Explain
{
    public const REASON_TEXT = ['nodata' => 'before the history', 'before_first' => 'before the first sale', 'promo' => 'promotion', 'oos' => 'out of stock'];
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /**
     * @param array<string, mixed> $x see demand() and the keys of supply()
     */
    public static function text(array $x): string
    {
        return self::demand($x) . ' ' . self::supply($x);
    }

    /**
     * The demand sentence.
     *
     * @param array<string, mixed> $x rate_e4, rate_short_e4, rate_long_e4, weight_e6, short_window, long_window, min_long, factor_e2,
     *        factor_source (item|brand|default), listings (list of {channel, rate_e4, method, valid_short, valid_long, excluded,
     *        excluded_short, anomalies: list of {label, from, to, short, long}, cap, capped, r_short_e4, r_long_e4} with rates in
     *        central units, main listing first)
     */
    public static function demand(array $x): string
    {
        $listings = array_values((array) ($x['listings'] ?? []));
        $wS = (int) ($x['short_window'] ?? 28);
        $wL = (int) ($x['long_window'] ?? 91);
        $factor = self::factor((int) ($x['factor_e2'] ?? 100), (string) ($x['factor_source'] ?? 'default'), (int) ($x['rate_e4'] ?? 0));
        if ($listings === []) {
            return 'No sales history: demand 0/day' . $factor . '.';
        }
        $main = $listings[0];
        $methods = array_unique(array_map(static fn (array $l): string => (string) $l['method'], $listings));
        $s = 'Demand ' . self::rate((int) $x['rate_e4']) . '/day = ';
        if (count($methods) === 1) {
            $s .= self::methodText((string) $main['method'], $main, $x, (int) ($x['rate_short_e4'] ?? 0), (int) ($x['rate_long_e4'] ?? 0), $wS, $wL);
            if (count($listings) > 1) {
                $by = [];
                foreach ($listings as $l) {
                    $by[(string) $l['channel']] = ($by[(string) $l['channel']] ?? 0) + (int) $l['rate_e4'];
                }
                $s .= ' [' . implode(' + ', array_map(static fn (string $c, int $r): string => $c . ' ' . self::rate($r), array_keys($by), $by)) . ']';
            }
        } else {
            $parts = [];
            foreach ($listings as $l) {
                $parts[] = $l['channel'] . ' ' . self::rate((int) $l['rate_e4']) . ' ('
                    . self::methodText((string) $l['method'], $l, $x, (int) ($l['r_short_e4'] ?? 0), (int) ($l['r_long_e4'] ?? 0), $wS, $wL) . ')';
            }
            $s .= implode(' + ', $parts);
        }
        return $s . $factor . '.';
    }

    /**
     * The cover, position, need and comparison sentences.
     *
     * @param array<string, mixed> $x lead, review, safety, cover_days, target, target_raw, min_applied, max_applied, min_stock, max_stock,
     *        stock_source (cw|site), site_date, site_channel, available, on_order, in_drafts, position, need, packs, packs_raw, units, upp,
     *        purchase_unit, moq, moq_applied, mult, mult_applied, rounding, no_supplier, never (?string), urgent, rop, rate_raw_30_e4
     */
    public static function supply(array $x): string
    {
        $out = [];
        $cover = 'Cover ' . (int) $x['lead'] . ' lead + ' . (int) $x['review'] . ' review + ' . (int) $x['safety'] . ' safety = ' . (int) $x['cover_days']
            . ' days → target ' . self::n((int) $x['target']);
        if (!empty($x['min_applied'])) {
            $cover .= ' (the minimum stock; demand alone gives ' . self::n((int) $x['target_raw']) . ')';
        } elseif (!empty($x['max_applied'])) {
            $cover .= ' (capped at the maximum stock; demand alone gives ' . self::n((int) $x['target_raw']) . ')';
        }
        $out[] = $cover . '.';
        $stock = ($x['stock_source'] ?? 'cw') === 'site'
            ? 'Site stock ' . self::n((int) $x['available']) . ' (' . ($x['site_channel'] ?? 'site')
                . ($x['site_date'] !== null && $x['site_date'] !== '' ? ', ' . self::date((string) $x['site_date'], true) : ', no snapshot') . ')'
                . (!empty($x['site_unreliable']) ? ' [not reliable: the site sells In-Stock variants whatever their figure; below 0 counts as 0]' : '')
            : 'Available ' . self::n((int) $x['available']);
        $out[] = $stock . ' + on order ' . self::n((int) $x['on_order']) . ' = ' . self::n((int) $x['position']) . ' (in drafts ' . self::n((int) $x['in_drafts']) . ').';
        if (!empty($x['urgent'])) {
            $out[] = 'Below the reorder point ' . self::n((int) $x['rop']) . ' (' . (int) $x['lead'] . ' lead + ' . (int) $x['safety'] . ' safety days).';
        }
        $need = (int) $x['need'];
        $upp = (int) $x['upp'];
        if (($x['never'] ?? null) !== null) {
            $out[] = 'Need ' . self::n($need) . ', never suggested: ' . $x['never'] . '.';
        } elseif ($need === 0) {
            $out[] = 'Need 0: nothing to order.';
        } elseif ((int) $x['packs'] === 0) {
            $out[] = 'Need ' . self::n($need) . ' → 0 packs (rounded to the nearest pack of ' . self::n($upp) . ').';
        } else {
            $pack = $upp > 1 ? self::n((int) $x['packs']) . ' × ' . ($x['purchase_unit'] ?? 'pack') . ' of ' . self::n($upp) . ' = ' . self::n((int) $x['units'])
                : self::n((int) $x['units']) . ' units';
            $notes = [];
            if (!empty($x['moq_applied'])) {
                $notes[] = 'MOQ ' . self::n((int) $x['moq']);
            }
            if (!empty($x['mult_applied'])) {
                $notes[] = 'in multiples of ' . self::n((int) $x['mult']);
            }
            if (($x['rounding'] ?? 'up') === 'nearest') {
                $notes[] = 'nearest pack';
            }
            if (!empty($x['no_supplier'])) {
                $notes[] = 'no preferred supplier';
            }
            $out[] = 'Need ' . self::n($need) . ' → ' . $pack . ($notes === [] ? '' : ' (' . implode(', ', $notes) . ')') . '.';
        }
        $out[] = 'Plain 30-day average ' . self::rate((int) ($x['rate_raw_30_e4'] ?? 0)) . '/day.';
        return implode(' ', $out);
    }

    /** A rate (e4) as people read it: one decimal from 1 a day, two below ('12.4', '0.05'). */
    public static function rate(int $e4): string
    {
        $h = DemandMath::halfUpDiv($e4, 100);
        if (abs($h) < 100) {
            return ($h < 0 ? '-' : '') . '0.' . str_pad((string) abs($h), 2, '0', STR_PAD_LEFT);
        }
        $t = DemandMath::halfUpDiv($e4, 1_000);
        return ($t < 0 ? '-' : '') . intdiv(abs($t), 10) . '.' . (abs($t) % 10);
    }

    /** The short name of an anomaly in the text: its label before " (", or its first two words when that is longer than 40 characters. */
    public static function shortLabel(string $label): string
    {
        $s = trim((string) preg_replace('/\s*\(.*$/su', '', $label));
        if ($s === '') {
            $s = trim($label);
        }
        if (mb_strlen($s) > 40) {
            $s = implode(' ', array_slice(preg_split('/\s+/u', $s) ?: [], 0, 2));
        }
        return $s;
    }

    /** '14–22 Sep', '28 Sep–3 Oct', '1 Oct' ($withYear adds the year: '14–22 Sep 2026'). */
    public static function range(string $from, string $to, bool $withYear = false): string
    {
        [$fy, $fm, $fd] = array_map('intval', explode('-', $from));
        [$ty, $tm, $td] = array_map('intval', explode('-', $to));
        $year = $withYear ? ' ' . $ty : '';
        if ($from === $to) {
            return $fd . ' ' . self::MONTHS[$fm] . $year;
        }
        if ($fy === $ty && $fm === $tm) {
            return $fd . '–' . $td . ' ' . self::MONTHS[$tm] . $year;
        }
        return $fd . ' ' . self::MONTHS[$fm] . ($fy !== $ty ? ' ' . $fy : '') . '–' . $td . ' ' . self::MONTHS[$tm] . ($fy !== $ty || $withYear ? ' ' . $ty : '');
    }

    /** '1 Oct' or '1 Oct 2026'. */
    public static function date(string $ymd, bool $withYear = false): string
    {
        return self::range($ymd, $ymd, $withYear);
    }

    /**
     * @param array<string, mixed> $l a listing (or the main one)
     * @param array<string, mixed> $x
     */
    private static function methodText(string $method, array $l, array $x, int $rS, int $rL, int $wS, int $wL): string
    {
        $short = self::window($wS, (int) $l['valid_short'], (array) ($l['excluded_short'] ?? []), (array) ($l['anomalies'] ?? []), 'short', null, 0);
        $long = self::window($wL, (int) $l['valid_long'], (array) ($l['excluded'] ?? []), (array) ($l['anomalies'] ?? []), 'long',
            isset($l['cap']) ? (int) $l['cap'] : null, (int) ($l['capped'] ?? 0));
        $w = (int) ($x['weight_e6'] ?? 500_000);
        return match ($method) {
            DemandMath::METHOD_BLEND => self::weight($w) . '×' . self::rate($rS) . ' (' . $short . ') + ' . self::weight(1_000_000 - $w) . '×' . self::rate($rL) . ' (' . $long . ')',
            DemandMath::METHOD_SHORT => 'the short window ' . self::rate($rS) . ' (' . $short . '; fewer than ' . (int) ($x['min_long'] ?? 21) . ' valid days in ' . $wL . ')',
            DemandMath::METHOD_MIN7 => 'too few valid days, averaged over at least 7 (' . $long . ')',
            default => 'no valid day (' . $long . ')',
        };
    }

    /**
     * "28 days: 19 valid; 9 excluded: Pre-duty stockpiling 14–22 Sep" / "...; 13 excluded: 9 X, 2 promotion, 2 out of stock; 1 day capped at 40".
     *
     * @param array<string, int> $excluded reason => days
     * @param array<int|string, array<string, mixed>> $anomalies {label, from, to, short, long}
     */
    private static function window(int $days, int $valid, array $excluded, array $anomalies, string $which, ?int $cap, int $capped): string
    {
        $parts = [];
        $total = 0;
        foreach ($anomalies as $a) {
            $n = (int) ($a[$which] ?? 0);
            if ($n > 0) {
                $parts[] = [$n, self::shortLabel((string) $a['label']) . ' ' . self::range((string) $a['from'], (string) $a['to'])];
                $total += $n;
            }
        }
        $other = (int) ($excluded['anomaly'] ?? 0) - $total;
        if ($other > 0) {
            $parts[] = [$other, 'anomaly'];
            $total += $other;
        }
        foreach (['promo', 'oos', 'before_first', 'nodata'] as $r) {
            $n = (int) ($excluded[$r] ?? 0);
            if ($n > 0) {
                $parts[] = [$n, self::REASON_TEXT[$r]];
                $total += $n;
            }
        }
        $s = "{$days} days: {$valid} valid";
        if ($total > 0) {
            $s .= "; {$total} excluded: " . (count($parts) === 1 ? $parts[0][1] : implode(', ', array_map(static fn (array $p): string => $p[0] . ' ' . $p[1], $parts)));
        }
        if ($capped > 0 && $cap !== null) {
            $s .= '; ' . $capped . ($capped === 1 ? ' day' : ' days') . ' capped at ' . self::n($cap);
        }
        return $s;
    }

    private static function factor(int $e2, string $source, int $rateE4): string
    {
        $f = intdiv($e2, 100) . '.' . str_pad((string) ($e2 % 100), 2, '0', STR_PAD_LEFT);
        if ($e2 === 100) {
            return $source === 'default' ? '; factor 1.00' : "; {$source} factor 1.00";
        }
        return "; {$source} factor {$f} → " . self::rate(DemandMath::halfUpDiv($rateE4 * $e2, 100)) . '/day';
    }

    private static function weight(int $e6): string
    {
        $s = rtrim(rtrim(intdiv($e6, 1_000_000) . '.' . str_pad((string) ($e6 % 1_000_000), 6, '0', STR_PAD_LEFT), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    private static function n(int $v): string
    {
        return number_format($v);
    }
}
