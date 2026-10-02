<?php

declare(strict_types=1);

namespace CW\Reorder;

use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Settings;

/**
 * Rebuilds reorder_demand from the imported sales history (spec §7.3; docs/decisions.md I62, I63): one row per item that
 * has a linked (status mapped) listing on a covered channel that sold in the 365 days read, or a minimum stock.
 *
 *   1. GET_LOCK('cw_reorder_build:<schema>', 0): one build at a time; busy -> 409 build_running.
 *   2. Coverage per channel: [S_c, E_c] = [min date_from, max date_to] over its loaded sales_import_batch rows.
 *   3. Promotions per channel: one aggregate over sales_history_day ⋈ channel_listing ⋈ sku per (sale_date, brand), then
 *      PromoDetector per brand.
 *   4. Items in chunks of 1,000 sku ids; per channel the history of their variants (sale_date range, the primary key) over
 *      366 days to E_c, the unsellable days of the long window, then DemandMath per listing and the item's sums.
 *   5. ONE transaction: DELETE FROM reorder_demand, then 500-row INSERTs.
 *
 * Reads only, except reorder_demand (spec §6.9: it takes no other lock). Nothing books stock.
 */
final class DemandBuilder
{
    public const CHUNK = 1000;
    public const INSERT_CHUNK = 500;
    public const LOCK_PREFIX = 'cw_reorder_build:';
    /** The history read per listing: 366 days to E_c (12 calendar months and the 365 days of units_365 / first()). */
    public const READ_DAYS = 366;

    private readonly Settings $settings;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?Settings $settings = null, ?\Closure $clock = null)
    {
        $this->settings = $settings ?? new Settings($db);
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * Recalculates every item's demand.
     *
     * @return array{items: int, listings: int, channels: array<string, array{from: string, to: string}>, promo_days: array<string, int>, ms: int, computed_at: string}
     */
    public function rebuild(): array
    {
        $t0 = hrtime(true);
        $lock = self::LOCK_PREFIX . (string) $this->db->value('SELECT DATABASE()');
        if ((int) $this->db->value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            throw new CwException('build_running', 'the demand is being recalculated right now: try again in a minute', 409);
        }
        try {
            $p = ReorderSettings::params($this->settings);
            $math = new DemandMath($p['short_window'], $p['long_window'], $p['weight_e6'], $p['min_short'], $p['min_long'], $p['cap_multiple'], $p['cap_floor']);
            $channels = $this->channels();
            $anomalies = $this->anomalies();
            $promo = $this->promotions($channels, $anomalies, $p);
            $skus = $this->itemIds(array_keys($channels));
            $globalEnd = $channels === [] ? null : max(array_column($channels, 'end'));
            $rows = [];
            $listings = 0;
            foreach (array_chunk($skus, self::CHUNK) as $chunk) {
                foreach ($this->compute($math, $p, $channels, $anomalies, $promo, $chunk, $globalEnd, false) as $sku => $item) {
                    $listings += count($item['listings']);
                    if ($item['listings'] === [] && $item['min_stock'] === null) {
                        continue;
                    }
                    $rows[$sku] = $item;
                }
            }
            $computedAt = Clock::db(($this->clock)());
            $this->write($rows, $computedAt);
            $promoDays = [];
            foreach ($channels as $c) {
                $promoDays[$c['code']] = array_sum(array_map('count', $promo[$c['id']] ?? []));
            }
            return [
                'items' => count($rows),
                'listings' => $listings,
                'channels' => array_map(static fn (array $c): array => ['from' => DemandMath::date($c['start']), 'to' => DemandMath::date($c['end'])],
                    array_column($channels, null, 'code')),
                'promo_days' => $promoDays,
                'ms' => intdiv(hrtime(true) - $t0, 1_000_000),
                'computed_at' => $computedAt,
            ];
        } finally {
            $this->db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * The demand of one item computed now, day by day (the item's reorder page): what rebuild() would store, plus every day of
     * the long window per listing with its units, its exclusion and whether it was capped. Null when the item has no linked
     * listing on a covered channel.
     *
     * @return array<string, mixed>|null
     */
    public function item(int $skuId): ?array
    {
        $p = ReorderSettings::params($this->settings);
        $math = new DemandMath($p['short_window'], $p['long_window'], $p['weight_e6'], $p['min_short'], $p['min_long'], $p['cap_multiple'], $p['cap_floor']);
        $channels = $this->channels();
        if ($channels === []) {
            return null;
        }
        $anomalies = $this->anomalies();
        $brand = $this->db->value('SELECT brand FROM sku WHERE id = ?', [$skuId]);
        $promo = $this->promotions($channels, $anomalies, $p, $brand === null ? null : (string) $brand);
        $out = $this->compute($math, $p, $channels, $anomalies, $promo, [$skuId], max(array_column($channels, 'end')), true);
        return $out[$skuId] ?? null;
    }

    /**
     * Coverage per channel with loaded history: id => {id, code, start, end} (day numbers).
     *
     * @return array<int, array{id: int, code: string, start: int, end: int}>
     */
    public function channels(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT b.channel_id, c.code, MIN(b.date_from) AS s, MAX(b.date_to) AS e FROM sales_import_batch b JOIN channel c ON c.id = b.channel_id "
            . "WHERE b.status = 'loaded' GROUP BY b.channel_id, c.code ORDER BY c.code") as $r) {
            $out[(int) $r['channel_id']] = ['id' => (int) $r['channel_id'], 'code' => (string) $r['code'], 'start' => DemandMath::day((string) $r['s']),
                'end' => DemandMath::day((string) $r['e'])];
        }
        return $out;
    }

    /**
     * The active anomaly windows, oldest first.
     *
     * @return list<array{id: int, from: int, to: int, channel_id: ?int, brand: ?string, label: string, date_from: string, date_to: string}>
     */
    public function anomalies(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT id, date_from, date_to, channel_id, brand, label FROM demand_anomaly WHERE is_active = 1 ORDER BY id') as $r) {
            $out[] = ['id' => (int) $r['id'], 'from' => DemandMath::day((string) $r['date_from']), 'to' => DemandMath::day((string) $r['date_to']),
                'channel_id' => $r['channel_id'] === null ? null : (int) $r['channel_id'], 'brand' => $r['brand'] === null ? null : self::brandKey((string) $r['brand']),
                'label' => (string) $r['label'], 'date_from' => (string) $r['date_from'], 'date_to' => (string) $r['date_to']];
        }
        return $out;
    }

    /**
     * The days an active anomaly excludes for (channel, brand) within [$from, $to]: day => the first window's id.
     *
     * @param list<array<string, mixed>> $anomalies
     * @return array<int, int>
     */
    public static function anomalyDays(array $anomalies, int $channelId, ?string $brandKey, int $from, int $to): array
    {
        $out = [];
        foreach ($anomalies as $a) {
            if (($a['channel_id'] !== null && $a['channel_id'] !== $channelId) || ($a['brand'] !== null && $a['brand'] !== $brandKey)) {
                continue;
            }
            for ($t = max($from, $a['from']); $t <= min($to, $a['to']); $t++) {
                $out[$t] ??= $a['id'];
            }
        }
        return $out;
    }

    /** How brands are compared (the collation of sku.brand ignores case and accents; trimmed, lower case here). */
    public static function brandKey(string $brand): string
    {
        return mb_strtolower(trim($brand));
    }

    /**
     * Promotion days per channel and brand: channel id => brand key => day => true.
     *
     * @param array<int, array{id: int, code: string, start: int, end: int}> $channels
     * @param list<array<string, mixed>> $anomalies
     * @param array<string, mixed> $p
     * @return array<int, array<string, array<int, true>>>
     */
    public function promotions(array $channels, array $anomalies, array $p, ?string $onlyBrand = null): array
    {
        $detector = new PromoDetector($p['promo_min_units'], $p['promo_drop'], $p['promo_uplift']);
        $out = [];
        foreach ($channels as $c) {
            $from = $c['end'] - $p['long_window'] - PromoDetector::REFERENCE_DAYS + 1;
            $readFrom = $from - PromoDetector::REFERENCE_DAYS;
            $sql = 'SELECT h.sale_date, s.brand, SUM(h.units_online) AS u, SUM(h.net_online) AS n FROM sales_history_day h '
                . 'JOIN channel_listing l ON l.channel_id = h.channel_id AND l.external_variant_id = h.external_variant_id JOIN sku s ON s.id = l.sku_id '
                . "WHERE h.channel_id = ? AND h.sale_date BETWEEN ? AND ? AND l.status = 'mapped' AND s.merged_into_sku_id IS NULL AND s.brand IS NOT NULL "
                . ($onlyBrand === null ? '' : 'AND s.brand = ? ') . 'GROUP BY h.sale_date, s.brand';
            $params = [$c['id'], DemandMath::date($readFrom), DemandMath::date($c['end'])];
            if ($onlyBrand !== null) {
                $params[] = $onlyBrand;
            }
            $daily = [];
            foreach ($this->db->all($sql, $params) as $r) {
                $k = self::brandKey((string) $r['brand']);
                if ($k === '') {
                    continue;
                }
                $day = DemandMath::day((string) $r['sale_date']);
                $daily[$k][$day][0] = ($daily[$k][$day][0] ?? 0) + (int) $r['u'];
                $daily[$k][$day][1] = ($daily[$k][$day][1] ?? 0) + self::pence((string) $r['n']);
            }
            foreach ($daily as $k => $days) {
                $excluded = array_map(static fn (): bool => true, self::anomalyDays($anomalies, $c['id'], $k, $readFrom, $c['end']));
                $flags = $detector->flag($days, $from, $c['end'], $excluded);
                if ($flags !== []) {
                    $out[$c['id']][$k] = $flags;
                }
            }
        }
        return $out;
    }

    /**
     * The items to compute: those with a mapped listing on a covered channel, and those with a minimum stock (not merged).
     *
     * @param list<int> $channelIds
     * @return list<int>
     */
    private function itemIds(array $channelIds): array
    {
        $ids = [];
        if ($channelIds !== []) {
            foreach ($this->db->column("SELECT DISTINCT l.sku_id FROM channel_listing l JOIN sku s ON s.id = l.sku_id WHERE l.status = 'mapped' "
                . 'AND s.merged_into_sku_id IS NULL AND l.channel_id IN (' . implode(', ', array_fill(0, count($channelIds), '?')) . ')', $channelIds) as $id) {
                $ids[(int) $id] = true;
            }
        }
        foreach ($this->db->column('SELECT r.sku_id FROM item_reorder r JOIN sku s ON s.id = r.sku_id WHERE r.min_stock IS NOT NULL AND s.merged_into_sku_id IS NULL') as $id) {
            $ids[(int) $id] = true;
        }
        $ids = array_keys($ids);
        sort($ids);
        return $ids;
    }

    /**
     * The demand of a chunk of items: sku id => {rate_e4, rate_short_e4, rate_long_e4, rate_raw30_e4, main fields, units_365,
     * first, last, monthly, listings (detail), min_stock}.
     *
     * @param array<string, mixed> $p
     * @param array<int, array{id: int, code: string, start: int, end: int}> $channels
     * @param list<array<string, mixed>> $anomalies
     * @param array<int, array<string, array<int, true>>> $promo
     * @param list<int> $skus
     * @return array<int, array<string, mixed>>
     */
    private function compute(DemandMath $math, array $p, array $channels, array $anomalies, array $promo, array $skus, ?int $globalEnd, bool $withDays): array
    {
        $in = implode(', ', array_fill(0, count($skus), '?'));
        $items = [];
        foreach ($this->db->all("SELECT s.id, s.brand, r.min_stock FROM sku s LEFT JOIN item_reorder r ON r.sku_id = s.id WHERE s.id IN ({$in})", $skus) as $r) {
            $items[(int) $r['id']] = ['brand' => $r['brand'] === null ? null : self::brandKey((string) $r['brand']),
                'min_stock' => $r['min_stock'] === null ? null : (int) $r['min_stock'], 'listings' => []];
        }
        $byChannel = [];
        if ($channels !== []) {
            $cin = implode(', ', array_fill(0, count($channels), '?'));
            foreach ($this->db->all("SELECT id, channel_id, external_variant_id, units_per_item, sku_id FROM channel_listing WHERE status = 'mapped' "
                . "AND sku_id IN ({$in}) AND channel_id IN ({$cin}) ORDER BY id", [...$skus, ...array_keys($channels)]) as $l) {
                $byChannel[(int) $l['channel_id']][(string) $l['external_variant_id']] = ['id' => (int) $l['id'], 'sku' => (int) $l['sku_id'],
                    'u' => (int) $l['units_per_item'], 'variant' => (string) $l['external_variant_id']];
            }
        }
        $results = [];
        foreach ($byChannel as $cid => $variants) {
            $c = $channels[$cid];
            $longFrom = $c['end'] - $p['long_window'] + 1;
            $readFrom = max($c['start'], $c['end'] - self::READ_DAYS + 1);
            $q = [];
            $unsellable = [];
            foreach (array_chunk(array_keys($variants), self::CHUNK) as $vchunk) {
                $vin = implode(', ', array_fill(0, count($vchunk), '?'));
                foreach ($this->db->all("SELECT external_variant_id, sale_date, units_online + units_office AS q FROM sales_history_day WHERE channel_id = ? "
                    . "AND external_variant_id IN ({$vin}) AND sale_date BETWEEN ? AND ?", [$cid, ...$vchunk, DemandMath::date($readFrom), DemandMath::date($c['end'])]) as $h) {
                    $q[(string) $h['external_variant_id']][DemandMath::day((string) $h['sale_date'])] = (int) $h['q'];
                }
                foreach ($this->db->all("SELECT external_variant_id, stock_date FROM listing_stock_day WHERE channel_id = ? AND external_variant_id IN ({$vin}) "
                    . 'AND stock_date BETWEEN ? AND ?', [$cid, ...$vchunk, DemandMath::date($longFrom), DemandMath::date($c['end'])]) as $s) {
                    $unsellable[(string) $s['external_variant_id']][DemandMath::day((string) $s['stock_date'])] = true;
                }
            }
            $snapshot = [];
            foreach ($this->db->column('SELECT snapshot_date FROM channel_snapshot_day WHERE channel_id = ? AND snapshot_date BETWEEN ? AND ?',
                [$cid, DemandMath::date($longFrom), DemandMath::date($c['end'])]) as $d) {
                $snapshot[DemandMath::day((string) $d)] = true;
            }
            $anomalyCache = [];
            foreach ($variants as $v => $l) {
                $brand = $items[$l['sku']]['brand'] ?? null;
                $akey = $brand ?? "\0";
                $anomalyCache[$akey] ??= self::anomalyDays($anomalies, $cid, $brand, $longFrom, $c['end']);
                $r = $math->listing($c['end'], $c['start'], $q[$v] ?? [], $anomalyCache[$akey], $brand === null ? [] : ($promo[$cid][$brand] ?? []), $snapshot,
                    $unsellable[$v] ?? [], $withDays);
                if ($r === null) {
                    continue;
                }
                $r['listing_id'] = $l['id'];
                $r['channel'] = $c['code'];
                $r['channel_id'] = $cid;
                $r['variant'] = $l['variant'];
                $r['u'] = $l['u'];
                $r['window_end'] = $c['end'];
                $r['history'] = $q[$v] ?? [];
                $results[$l['sku']][] = $r;
            }
        }
        $anomalyInfo = array_column($anomalies, null, 'id');
        $out = [];
        foreach ($items as $sku => $item) {
            $ls = $results[$sku] ?? [];
            usort($ls, static fn (array $a, array $b): int => [$b['u'] * $b['rate_e4'], $b['u'] * $b['units_365'], $a['listing_id']]
                <=> [$a['u'] * $a['rate_e4'], $a['u'] * $a['units_365'], $b['listing_id']]);
            $rate = 0;
            $rateS = null;
            $rateL = null;
            $raw = [];
            $units = 0;
            $first = null;
            $last = null;
            $months = self::months($globalEnd);
            $detail = [];
            foreach ($ls as $l) {
                $u = $l['u'];
                $rate += $u * $l['rate_e4'];
                if ($l['r_short_e4'] !== null) {
                    $rateS = ($rateS ?? 0) + $u * $l['r_short_e4'];
                }
                if ($l['r_long_e4'] !== null) {
                    $rateL = ($rateL ?? 0) + $u * $l['r_long_e4'];
                }
                $raw[] = $u * $l['sum_raw30'];
                $units += $u * $l['units_365'];
                $first = $first === null ? $l['first'] : min($first, $l['first']);
                $last = $last === null ? $l['last'] : max($last, $l['last']);
                foreach ($l['history'] as $day => $qt) {
                    $m = substr(DemandMath::date($day), 0, 7);
                    if (isset($months[$m])) {
                        $months[$m] += $u * $qt;
                    }
                }
                $windows = [];
                foreach ($l['anomalies'] as $id => [$short, $long]) {
                    $a = $anomalyInfo[$id] ?? null;
                    $windows[] = ['id' => (int) $id, 'label' => $a['label'] ?? '', 'from' => $a['date_from'] ?? '', 'to' => $a['date_to'] ?? '', 'short' => $short, 'long' => $long];
                }
                $d = ['channel' => $l['channel'], 'variant' => $l['variant'], 'listing_id' => $l['listing_id'], 'u' => $u, 'window_end' => DemandMath::date($l['window_end']),
                    'valid_short' => $l['valid_short'], 'valid_long' => $l['valid_long'], 'excluded' => $l['excluded'], 'excluded_short' => $l['excluded_short'],
                    'anomalies' => $windows, 'cap' => $l['cap'], 'capped' => $l['capped'],
                    'r_short' => $l['r_short_e4'] === null ? null : DemandMath::e4($l['r_short_e4']),
                    'r_long' => $l['r_long_e4'] === null ? null : DemandMath::e4($l['r_long_e4']), 'rate' => DemandMath::e4($l['rate_e4']), 'method' => $l['method'],
                    'first_sale' => DemandMath::date($l['first']), 'last_sale' => DemandMath::date($l['last']), 'units_365' => $l['units_365']];
                if ($withDays) {
                    $d['days'] = [];
                    foreach ($l['days'] as $day => [$qt, $reason, $capped]) {
                        $d['days'][] = ['date' => DemandMath::date($day), 'q' => $qt, 'reason' => $reason, 'capped' => $capped,
                            'anomaly' => $reason === 'anomaly' ? ($anomalyInfo[self::anomalyDays($anomalies, $l['channel_id'], $item['brand'], $day, $day)[$day] ?? 0]['label'] ?? null) : null];
                    }
                }
                $detail[] = $d;
            }
            $main = $ls[0] ?? null;
            $out[$sku] = [
                'rate_e4' => $rate,
                'rate_short_e4' => $rateS,
                'rate_long_e4' => $rateL,
                'rate_raw30_e4' => DemandMath::rawRateE4($raw),
                'valid_short' => $main['valid_short'] ?? 0,
                'valid_long' => $main['valid_long'] ?? 0,
                'excluded_anomaly' => $main['excluded']['anomaly'] ?? 0,
                'excluded_promo' => $main['excluded']['promo'] ?? 0,
                'excluded_oos' => $main['excluded']['oos'] ?? 0,
                'excluded_other' => ($main['excluded']['nodata'] ?? 0) + ($main['excluded']['before_first'] ?? 0),
                'capped' => $main['capped'] ?? 0,
                'units_365' => $units,
                'first' => $first === null ? null : DemandMath::date($first),
                'last' => $last === null ? null : DemandMath::date($last),
                'monthly' => $months,
                'listings' => $detail,
                'min_stock' => $item['min_stock'],
            ];
        }
        return $out;
    }

    /**
     * The 12 calendar months ending with the month of $end: 'YYYY-MM' => 0.
     *
     * @return array<string, int>
     */
    public static function months(?int $end): array
    {
        if ($end === null) {
            return [];
        }
        [$y, $m] = array_map('intval', explode('-', substr(DemandMath::date($end), 0, 7)));
        $out = [];
        for ($i = 11; $i >= 0; $i--) {
            $mm = $m - $i;
            $yy = $y;
            while ($mm < 1) {
                $mm += 12;
                $yy--;
            }
            $out[sprintf('%04d-%02d', $yy, $mm)] = 0;
        }
        return $out;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function write(array $rows, string $computedAt): void
    {
        $this->db->transaction(function (Db $db) use ($rows, $computedAt): void {
            $db->exec('DELETE FROM reorder_demand');
            foreach (array_chunk($rows, self::INSERT_CHUNK, true) as $chunk) {
                $params = [];
                foreach ($chunk as $sku => $r) {
                    array_push($params, $sku, $computedAt, DemandMath::e4($r['rate_e4']), $r['rate_short_e4'] === null ? null : DemandMath::e4($r['rate_short_e4']),
                        $r['rate_long_e4'] === null ? null : DemandMath::e4($r['rate_long_e4']), DemandMath::e4($r['rate_raw30_e4']), $r['valid_short'], $r['valid_long'],
                        $r['excluded_anomaly'], $r['excluded_promo'], $r['excluded_oos'], $r['excluded_other'], $r['capped'], $r['units_365'], $r['first'], $r['last'],
                        json_encode((object) $r['monthly'], JSON_THROW_ON_ERROR), json_encode(['listings' => $r['listings']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                }
                $db->exec('INSERT INTO reorder_demand (sku_id, computed_at, rate, rate_short, rate_long, rate_raw_30, valid_days_short, valid_days_long, excluded_anomaly, '
                    . 'excluded_promo, excluded_oos, excluded_other, capped_days, units_365, first_sale_date, last_sale_date, monthly, detail) VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
            }
        });
    }

    /** A DECIMAL money string as pence ('12.34' -> 1234; more places are cut, the sums of DECIMAL(14,2) have none). */
    public static function pence(string $v): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2})\d*)?$/D', trim($v), $m) !== 1) {
            return 0;
        }
        $n = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        return $m[1] === '-' ? -$n : $n;
    }
}
