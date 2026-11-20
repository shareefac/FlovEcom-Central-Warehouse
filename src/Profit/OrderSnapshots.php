<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Reports\ReportRefresh;

/**
 * Stores a shop's order snapshot and works out the order's money and buying cost (profit step 1; docs/decisions.md PR1-PR11). ONE
 * service for the API (PUT /v1/orders/{ref}/snapshot), the bulk files (bin/import_order_snapshots.php), the ERPNext history import and
 * the deliberate re-cost, so the live feed, a backfill and a correction can never disagree. One transaction per order; nothing here
 * touches stock, reservations or documents (it only reads reservation_unit and channel_listing, without locks).
 *
 *  store()      the snapshot's content is the same as the one stored (whatever its version): "same" (a newer version is remembered);
 *               an older version: "older_ignored"; else it replaces the stored one ("stored") and apply() works the order out again.
 *  apply()      the order (sale_order), its lines (sale_line) and parcels (sale_parcel), the re-send figures of the order it is a re-send
 *               of, and the day summaries (profit_day, profit_item_day: by the difference between what the order counted before and
 *               now, in key order).
 *  recompute()  apply() again with the stored snapshot (the returns sweep; the ERPNext import and the re-cost with their costs).
 *
 * The cost of a line is fixed ONCE, the first time it is stored on a paid order: the item's buying price at the time it was paid
 * (ItemCosts::at), with where it came from. Later snapshots keep it; only the ERPNext history import and bin/recost_sales.php change
 * it, each writing its own costed_by. A line's item is fixed with its cost: the unit's own item at the sale (reservation_unit), else
 * the listing's link when the line is first stored.
 *
 * Lock order: order_snapshot, the order's sale_order and sale_line rows, its sale_parcel rows, the sale_order row of the order it is a
 * re-send of, then profit_item_day and profit_day rows in key order.
 */
final class OrderSnapshots
{
    public const RESULTS = ['stored', 'same', 'older_ignored'];
    public const SOURCES = ['api', 'bulk'];

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly ProfitSettings $settings;
    private readonly ItemCosts $costs;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null, ?ProfitSettings $settings = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
        $this->settings = $settings ?? new ProfitSettings($db);
        $this->costs = new ItemCosts($db, $this->settings);
    }

    /**
     * @param array<string, mixed> $raw the decoded JSON object (a bulk line's `ref` is ignored here)
     * @return array{result: string, version: int}
     */
    public function store(int $channelId, string $ref, array $raw, string $source): array
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("unknown snapshot source {$source}");
        }
        $ref = SnapshotBody::orderRef($ref, 'ref');
        $now = ($this->clock)();
        $body = SnapshotBody::parse($raw, $now);
        if ($body['parent_ref'] === $ref) {
            throw new CwException('bad_snapshot', 'parent_ref must be another order', 400, ['field' => 'parent_ref']);
        }
        $hash = SnapshotBody::hash($body);
        $at = Clock::db($now);
        return $this->db->transaction(function (Db $db) use ($channelId, $ref, $body, $hash, $source, $at): array {
            $cur = $db->one('SELECT version, body_hash FROM order_snapshot WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]);
            if ($cur !== null) {
                $stored = (int) $cur['version'];
                if (hash_equals((string) $cur['body_hash'], $hash)) {
                    if ($body['version'] > $stored) {
                        $db->exec("UPDATE order_snapshot SET version = ?, body = JSON_SET(body, '$.version', ?) WHERE channel_id = ? AND order_ref = ?",
                            [$body['version'], $body['version'], $channelId, $ref]);
                        $db->exec('UPDATE sale_order SET snapshot_version = ? WHERE channel_id = ? AND order_ref = ?', [$body['version'], $channelId, $ref]);
                    }
                    return ['result' => 'same', 'version' => max($stored, $body['version'])];
                }
                if ($body['version'] < $stored) {
                    return ['result' => 'older_ignored', 'version' => $stored];
                }
            }
            $json = Idempotency::canonicalJson($body);
            if ($cur === null) {
                $db->exec('INSERT INTO order_snapshot (channel_id, order_ref, version, body_hash, body, source, first_at, stored_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$channelId, $ref, $body['version'], $hash, $json, $source, $at, $at]);
            } else {
                $db->exec('UPDATE order_snapshot SET version = ?, body_hash = ?, body = ?, source = ?, stored_at = ? WHERE channel_id = ? AND order_ref = ?',
                    [$body['version'], $hash, $json, $source, $at, $channelId, $ref]);
            }
            $this->apply($db, $channelId, $ref, $body, $at);
            return ['result' => 'stored', 'version' => $body['version']];
        });
    }

    /**
     * apply() again with the stored snapshot, in a transaction of its own (or the caller's). False when the order has none.
     *
     * @param array<string, array{e4: ?int, source: string, by: string}> $override line id => the cost to fix on it now
     */
    public function recompute(int $channelId, string $ref, array $override = []): bool
    {
        return $this->db->transaction(function (Db $db) use ($channelId, $ref, $override): bool {
            $json = $db->value('SELECT CAST(body AS CHAR) FROM order_snapshot WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]);
            if ($json === null) {
                return false;
            }
            $body = json_decode((string) $json, true, 64, JSON_THROW_ON_ERROR);
            $this->apply($db, $channelId, $ref, $body, Clock::db(($this->clock)()), $override);
            return true;
        });
    }

    /**
     * Works one order out from its (normalised) snapshot, inside the caller's transaction, with the order_snapshot row locked.
     *
     * @param array<string, mixed> $body SnapshotBody::parse()'s shape
     * @param array<string, array{e4: ?int, source: string, by: string, sku?: int, upi?: int}> $override line id => the cost to fix on it now
     *        (and, for a line that had no item, the item it is costed as)
     */
    public function apply(Db $db, int $channelId, string $ref, array $body, string $now, array $override = []): void
    {
        $old = $db->one('SELECT * FROM sale_order WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]);
        $oldLines = [];
        foreach ($db->all('SELECT * FROM sale_line WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]) as $r) {
            $oldLines[(string) $r['line_id']] = $r;
        }
        $ids = array_map(static fn (array $l): string => (string) $l['line_id'], $body['lines']);
        foreach (array_chunk($ids, 500) as $chunk) {
            $other = $db->value('SELECT line_id FROM sale_line WHERE channel_id = ? AND line_id IN (' . self::marks($chunk) . ') AND order_ref <> ? LIMIT 1',
                [$channelId, ...$chunk, $ref]);
            if ($other !== null) {
                $i = (int) array_search((string) $other, $ids, true);
                throw new CwException('bad_snapshot', "line {$other} belongs to another order", 400, ['field' => "lines[{$i}].line_id"]);
            }
        }
        $vatBp = $old !== null ? (int) $old['vat_bp'] : $this->settings->vatBp();
        $paid = $body['paid_at'];
        $day = $paid === null ? null : ReportRefresh::ukDay((string) $paid);

        // The CW item of each line: the unit's own at the sale, else the listing's link now (an item fixed with its cost stays).
        $units = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($db->all('SELECT unit_id, listing_id, sku_id, units_per_item, state FROM reservation_unit WHERE channel_id = ? AND unit_id IN (' . self::marks($chunk) . ')',
                [$channelId, ...$chunk]) as $r) {
                $units[(string) $r['unit_id']] = $r;
            }
        }
        $variants = array_values(array_unique(array_map(static fn (array $l): string => (string) $l['variant_id'], $body['lines'])));
        $listings = [];
        foreach (array_chunk($variants, 500) as $chunk) {
            foreach ($db->all('SELECT id, external_variant_id, sku_id, units_per_item, status FROM channel_listing WHERE channel_id = ? AND external_variant_id IN ('
                . self::marks($chunk) . ')', [$channelId, ...$chunk]) as $r) {
                $listings[(string) $r['external_variant_id']] = $r;
            }
        }
        $lines = [];
        $toCost = [];
        foreach ($body['lines'] as $l) {
            $id = (string) $l['line_id'];
            $prev = $oldLines[$id] ?? null;
            $fixed = $prev !== null && $prev['cost_source'] !== null;
            $u = $units[$id] ?? null;
            $li = $listings[(string) $l['variant_id']] ?? null;
            if (isset($override[$id]['sku'])) {
                // A deliberate re-cost of a line that had no item: the item its listing is linked to now (RecostSales).
                $sku = (int) $override[$id]['sku'];
                $upi = (int) ($override[$id]['upi'] ?? 1);
                $listing = $li === null ? ($prev['listing_id'] ?? null) : (int) $li['id'];
            } elseif ($fixed) {
                $sku = $prev['sku_id'] === null ? null : (int) $prev['sku_id'];
                $upi = (int) $prev['units_per_item'];
                $listing = $prev['listing_id'] === null ? null : (int) $prev['listing_id'];
            } elseif ($u !== null) {
                $sku = $u['sku_id'] === null ? null : (int) $u['sku_id'];
                $upi = (int) $u['units_per_item'];
                $listing = (int) $u['listing_id'];
            } else {
                $linked = $li !== null && in_array((string) $li['status'], ['mapped', 'quarantined'], true) && $li['sku_id'] !== null;
                $sku = $linked ? (int) $li['sku_id'] : null;
                $upi = $li === null ? 1 : (int) $li['units_per_item'];
                $listing = $li === null ? null : (int) $li['id'];
            }
            $restocked = $u !== null && (string) $u['state'] === 'returned';
            $lines[$id] = ['l' => $l, 'prev' => $prev, 'fixed' => $fixed, 'sku' => $sku, 'upi' => max(1, $upi), 'listing' => $listing, 'restocked' => $restocked];
            if ($paid !== null && !$fixed && !isset($override[$id]) && $sku !== null) {
                $toCost[] = $sku;
            }
        }
        $costs = $paid === null || $toCost === [] ? [] : $this->costs->at($toCost, (string) $paid);

        $newLines = [];
        foreach ($lines as $id => $x) {
            $l = $x['l'];
            $m = ProfitMath::line($l, $x['upi'], $x['restocked'], $vatBp);
            $prev = $x['prev'];
            $e4 = null;
            $source = null;
            $by = null;
            $costAt = null;
            if (isset($override[$id]) && $paid !== null) {
                $e4 = $override[$id]['e4'];
                $source = $override[$id]['source'];
                $by = $override[$id]['by'];
                $costAt = $now;
            } elseif ($x['fixed']) {
                $e4 = $prev['unit_cost_e4'] === null ? null : (int) $prev['unit_cost_e4'];
                $source = (string) $prev['cost_source'];
                $by = (string) $prev['costed_by'];
                $costAt = (string) $prev['cost_at'];
            } elseif ($paid !== null) {
                $c = $x['sku'] === null ? ['e4' => null, 'source' => 'none'] : ($costs[$x['sku']] ?? ['e4' => null, 'source' => 'none']);
                $e4 = $c['e4'];
                $source = $c['e4'] === null ? 'none' : $c['source'];
                $by = 'sale';
                $costAt = $now;
            }
            if ($source === 'none') {
                $e4 = null;
            }
            $newLines[$id] = [
                'channel_id' => $channelId, 'line_id' => $id, 'order_ref' => $ref, 'variant_id' => (string) $l['variant_id'], 'listing_id' => $x['listing'],
                'sku_id' => $x['sku'], 'units_per_item' => $x['upi'], 'qty' => (int) $l['qty'], 'units' => $m['units'], 'price_inc' => (int) $l['price_inc'],
                'discount_inc' => (int) $l['discount_inc'], 'refund_inc' => (int) $l['refund_inc'], 'cancelled' => $l['cancelled'] ? 1 : 0, 'is_free' => $l['free'] ? 1 : 0,
                'restocked' => $x['restocked'] ? 1 : 0, 'sale_inc' => $m['sale_inc'], 'sale_ex' => $m['sale_ex'], 'unit_cost_e4' => $e4,
                'cost' => ProfitMath::lineCost($m['counts'], $m['units'], $e4, $x['restocked']), 'cost_source' => $source, 'costed_by' => $by,
                'cost_at' => $costAt, 'day' => $day, 'removed_at' => null, 'updated_at' => $now,
            ];
        }
        // A line gone from this snapshot: kept, counts nothing.
        foreach ($oldLines as $id => $r) {
            if (isset($newLines[$id])) {
                continue;
            }
            $newLines[$id] = ['units' => 0, 'sale_inc' => 0, 'sale_ex' => 0, 'cost' => 0, 'day' => $day, 'updated_at' => $now,
                'removed_at' => $r['removed_at'] ?? $now] + $r;
        }

        $order = self::totals($body, $newLines, $vatBp) + [
            'channel_id' => $channelId, 'order_ref' => $ref, 'order_no' => (string) $body['order_no'], 'status' => (string) $body['status'],
            'placed_at' => $body['placed_at'], 'paid_at' => $paid, 'day' => $day, 'gateway' => (string) $body['gateway'], 'currency' => 'GBP',
            'parent_ref' => $body['parent_ref'], 'sub_of' => $body['sub_of'] ?? null, 'vat_bp' => $vatBp, 'snapshot_version' => (int) $body['version'],
            'first_at' => $old === null ? $now : (string) $old['first_at'], 'updated_at' => $now,
        ];
        // Its re-sends (the cost that belongs to it), as stored now.
        $rs = $db->one('SELECT COUNT(*) AS n, COALESCE(SUM(cost), 0) AS c FROM sale_order WHERE channel_id = ? AND parent_ref = ? AND paid_at IS NOT NULL '
            . 'AND order_ref <> ?', [$channelId, $ref, $ref]) ?? ['n' => 0, 'c' => 0];
        $order['resends'] = (int) $rs['n'];
        $order['resend_cost'] = (int) $rs['c'];
        $order['margin_bp'] = ProfitMath::marginBp($order['sale_ex'] - $order['cost'] - $order['resend_cost'], $order['sale_ex']);

        self::upsert($db, 'sale_order', [$order], ['channel_id', 'order_ref', 'first_at']);
        foreach (array_chunk(array_values($newLines), 200) as $chunk) {
            self::upsert($db, 'sale_line', array_map(static fn (array $r): array => self::lineRow($r), $chunk), ['channel_id', 'line_id']);
        }
        $this->parcels($db, $channelId, $ref, $body['parcels'], $now);
        // The re-send figures of the order(s) this one is (or was) a re-send of.
        $parents = array_values(array_unique(array_filter([$old['parent_ref'] ?? null, $body['parent_ref']], static fn (mixed $p): bool => $p !== null)));
        sort($parents, SORT_STRING);
        foreach ($parents as $p) {
            self::refreshParent($db, $channelId, (string) $p);
        }
        // The day summaries: what the order counts now less what it counted before.
        ProfitDays::change($db, $channelId, ProfitDays::orderShare($old, $oldLines), ProfitDays::orderShare($order, $newLines));
    }

    /**
     * The order's totals from its lines (the ones that count) and its delivery.
     *
     * @param array<string, mixed> $body
     * @param array<string, array<string, mixed>> $lines
     * @return array<string, int|string|null>
     */
    public static function totals(array $body, array $lines, int $vatBp): array
    {
        $t = ['line_count' => 0, 'units' => 0, 'goods_inc' => 0, 'discount_inc' => 0, 'line_refund_inc' => 0, 'sale_inc' => 0, 'sale_ex' => 0, 'cost' => 0,
            'units_no_cost' => 0];
        foreach ($lines as $r) {
            if ($r['removed_at'] !== null) {
                continue;
            }
            $t['line_count']++;
            $t['goods_inc'] += (int) $r['price_inc'];
            $t['discount_inc'] += (int) $r['discount_inc'];
            $t['line_refund_inc'] += (int) $r['refund_inc'];
            $t['units'] += (int) $r['units'];
            $t['sale_inc'] += (int) $r['sale_inc'];
            $t['sale_ex'] += (int) $r['sale_ex'];
            $t['cost'] += (int) $r['cost'];
            $t['units_no_cost'] += self::unitsNoCost($r);
        }
        $refund = (int) $body['order_refund_inc'];
        $refundEx = ProfitMath::exVat($refund, $vatBp);
        $t['sale_inc'] -= $refund;
        $t['sale_ex'] -= $refundEx;
        $d = $body['delivery'];
        return $t + [
            'order_refund_inc' => $refund,
            'order_refund_ex' => $refundEx,
            'delivery_method_id' => $d['method_id'],
            'delivery_method' => $d['method'],
            'delivery_charged_inc' => (int) $d['charged_inc'],
            'delivery_refund_inc' => (int) $d['refund_inc'],
            'delivery_ex' => ProfitMath::exVat((int) $d['charged_inc'] - (int) $d['refund_inc'], $vatBp),
            'grand_inc' => (int) $body['grand_inc'],
        ];
    }

    /** Units of a line that count and have no buying price (a unit back in stock has none by rule: not missing). */
    public static function unitsNoCost(array $r): int
    {
        return $r['removed_at'] === null && (int) $r['units'] > 0 && (int) $r['restocked'] === 0 && ($r['cost_source'] ?? null) === 'none' ? (int) $r['units'] : 0;
    }

    /** The re-sends of $parent (paid, stored) and their cost on its row, and its margin with them. */
    public static function refreshParent(Db $db, int $channelId, string $parent): void
    {
        $p = $db->one('SELECT sale_ex, cost FROM sale_order WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $parent]);
        if ($p === null) {
            return;
        }
        $rs = $db->one('SELECT COUNT(*) AS n, COALESCE(SUM(cost), 0) AS c FROM sale_order WHERE channel_id = ? AND parent_ref = ? AND paid_at IS NOT NULL '
            . 'AND order_ref <> ?', [$channelId, $parent, $parent]) ?? ['n' => 0, 'c' => 0];
        $db->exec('UPDATE sale_order SET resends = ?, resend_cost = ?, margin_bp = ? WHERE channel_id = ? AND order_ref = ?',
            [(int) $rs['n'], (int) $rs['c'], ProfitMath::marginBp((int) $p['sale_ex'] - (int) $p['cost'] - (int) $rs['c'], (int) $p['sale_ex']), $channelId, $parent]);
    }

    /** @param list<array<string, mixed>> $parcels */
    private function parcels(Db $db, int $channelId, string $ref, array $parcels, string $now): void
    {
        $have = [];
        foreach ($db->all('SELECT parcel_ref, removed_at FROM sale_parcel WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$channelId, $ref]) as $r) {
            $have[(string) $r['parcel_ref']] = $r['removed_at'];
        }
        $rows = [];
        foreach ($parcels as $p) {
            $rows[] = ['channel_id' => $channelId, 'order_ref' => $ref, 'parcel_ref' => (string) $p['parcel_ref'], 'tracking_no' => $p['tracking_no'],
                'carrier' => (string) $p['carrier'], 'service_code' => (string) $p['service_code'], 'service' => (string) $p['service'], 'status' => (string) $p['status'],
                'label_at' => $p['label_at'], 'shipped_at' => $p['shipped_at'], 'is_manual' => $p['manual'] ? 1 : 0, 'is_partial' => $p['partial'] ? 1 : 0,
                'first_at' => $now, 'updated_at' => $now, 'removed_at' => null];
            unset($have[(string) $p['parcel_ref']]);
        }
        if ($rows !== []) {
            self::upsert($db, 'sale_parcel', $rows, ['channel_id', 'order_ref', 'parcel_ref', 'first_at']);
        }
        foreach ($have as $pref => $removed) {
            if ($removed === null) {
                $db->exec('UPDATE sale_parcel SET removed_at = ?, updated_at = ? WHERE channel_id = ? AND order_ref = ? AND parcel_ref = ?',
                    [$now, $now, $channelId, $ref, (string) $pref]);
            }
        }
    }

    /** @param array<string, mixed> $r @return array<string, mixed> the sale_line columns */
    private static function lineRow(array $r): array
    {
        $cols = ['channel_id', 'line_id', 'order_ref', 'variant_id', 'listing_id', 'sku_id', 'units_per_item', 'qty', 'units', 'price_inc', 'discount_inc', 'refund_inc',
            'cancelled', 'is_free', 'restocked', 'sale_inc', 'sale_ex', 'unit_cost_e4', 'cost', 'cost_source', 'costed_by', 'cost_at', 'day', 'removed_at', 'updated_at'];
        $out = [];
        foreach ($cols as $c) {
            $out[$c] = $r[$c] ?? null;
        }
        return $out;
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE of whole rows (the same columns each); $keep are never changed on an existing row.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $keep
     */
    private static function upsert(Db $db, string $table, array $rows, array $keep): void
    {
        $cols = array_keys($rows[0]);
        $params = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $params[] = $r[$c];
            }
        }
        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = n.`{$c}`", array_values(array_diff($cols, $keep))));
        $db->exec('INSERT INTO ' . $table . ' (' . implode(', ', array_map(static fn (string $c): string => "`{$c}`", $cols)) . ') VALUES '
            . implode(', ', array_fill(0, count($rows), '(' . self::marks($cols) . ')')) . ' AS n ON DUPLICATE KEY UPDATE ' . $set, $params);
    }

    /** @param list<mixed> $a */
    private static function marks(array $a): string
    {
        return implode(', ', array_fill(0, count($a), '?'));
    }
}
