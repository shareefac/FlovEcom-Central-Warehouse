<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * The day summaries of the Profit screens (profit_day per store and UK day; profit_item_day per store, day and item; docs/decisions.md
 * PR22). What an order counts for is orderShare(), the ONE definition both ways use:
 *
 *  change()   the snapshot path: adds what an order counts now and takes away what it counted before, in the same transaction, row by
 *             row in key order (an increment: two orders of the same day never overwrite each other);
 *  rebuild()  the batch tools: a store's day worked out again from its sale_order and sale_line rows (the same orderShare()), its rows
 *             replaced; what change() kept must equal it (tests: ProfitSnapshotTest).
 *  guard()    THE lock of a store's day (its profit_day row, made when missing): every snapshot takes it for its day (and the day it
 *             counted on before) before it reads or writes anything of the order, and a rebuild takes it before it reads the day's orders,
 *             so a rebuild never replaces the day with figures that miss a snapshot committed meanwhile (the Codex review of 92efa82, c1).
 *
 * An order counts on the UK day it was paid (sale_order.day); an unpaid one counts nothing. `orders` counts the paid orders with at
 * least one unit that counts and no parent (`resends` the ones with one); sale_ex is the lines' (the order-level refunds apart).
 *
 * The delivery bills (0077; docs/decisions.md DL11-DL14): delivery_cost is what the live carrier bill lines on an order cost (its parcels'
 * lines and the collections on it), counted on the order's day like its goods (a re-send's on the re-send's own day); delivery_other is
 * what the live lines on no order (not matched, ambiguous) cost, on their own date in the shop the account bills. A snapshot passes the
 * order's delivery cost to orderShare() (DeliveryBills::orderCost), so an order paid or moved to another day takes it along; the bills'
 * own writes (CW\Profit\DeliveryBills) change the same two figures under the same day locks; compute() works both out from scratch.
 */
final class ProfitDays
{
    public const DAY_FIELDS = ['orders', 'resends', 'units', 'sale_ex', 'order_refund_ex', 'cost', 'units_no_cost', 'delivery_ex', 'delivery_cost', 'delivery_other'];
    public const ITEM_FIELDS = ['units', 'sale_ex', 'cost', 'units_no_cost'];
    /** bin/rebuild_profit_days.php --apply waits at most this long for a store-day's row held elsewhere, then names it and goes on (PR132). */
    public const REBUILD_LOCK_WAIT_SEC = 10;

    /**
     * @param array<string, mixed>|null $order a sale_order row (or the row about to be written)
     * @param array<string, array<string, mixed>> $lines its sale_line rows
     * @param int $deliveryCost what the live carrier bill lines on the order cost (DeliveryBills::orderCost), pence ex VAT
     * @return array{days: array<string, array<string, int>>, items: array<string, array<string, int>>} "day" and "day|sku" => figures
     */
    public static function orderShare(?array $order, array $lines, int $deliveryCost = 0): array
    {
        if ($order === null || $order['day'] === null) {
            return ['days' => [], 'items' => []];
        }
        $day = (string) $order['day'];
        $units = (int) $order['units'];
        $resend = $order['parent_ref'] !== null;
        $refundEx = (int) $order['order_refund_ex'];
        $days = [$day => ['orders' => !$resend && $units > 0 ? 1 : 0, 'resends' => $resend && $units > 0 ? 1 : 0, 'units' => $units,
            'sale_ex' => (int) $order['sale_ex'] + $refundEx, 'order_refund_ex' => $refundEx, 'cost' => (int) $order['cost'],
            'units_no_cost' => (int) $order['units_no_cost'], 'delivery_ex' => (int) $order['delivery_ex'], 'delivery_cost' => $deliveryCost, 'delivery_other' => 0]];
        $items = [];
        foreach ($lines as $r) {
            if ($r['removed_at'] !== null) {
                continue;
            }
            $f = ['units' => (int) $r['units'], 'sale_ex' => (int) $r['sale_ex'], 'cost' => (int) $r['cost'], 'units_no_cost' => OrderSnapshots::unitsNoCost($r)];
            if ($f === ['units' => 0, 'sale_ex' => 0, 'cost' => 0, 'units_no_cost' => 0]) {
                continue;
            }
            $k = $day . '|' . (int) ($r['sku_id'] ?? 0);
            $items[$k] ??= array_fill_keys(self::ITEM_FIELDS, 0);
            foreach ($f as $field => $v) {
                $items[$k][$field] += $v;
            }
        }
        return ['days' => $days, 'items' => $items];
    }

    /**
     * Adds $new and takes away $old (orderShare()s of one order), in key order.
     *
     * @param array{days: array<string, array<string, int>>, items: array<string, array<string, int>>} $old
     * @param array{days: array<string, array<string, int>>, items: array<string, array<string, int>>} $new
     */
    public static function change(Db $db, int $channelId, array $old, array $new): void
    {
        $items = self::diff($old['items'], $new['items'], self::ITEM_FIELDS);
        $days = self::diff($old['days'], $new['days'], self::DAY_FIELDS);
        if ($items !== []) {
            $params = [];
            foreach ($items as $k => $f) {
                [$day, $sku] = explode('|', $k);
                array_push($params, $channelId, $day, (int) $sku, ...array_values($f));
            }
            $db->exec('INSERT INTO profit_item_day (channel_id, day, sku_id, ' . implode(', ', self::ITEM_FIELDS) . ') VALUES '
                . implode(', ', array_fill(0, count($items), '(?, ?, ?, ' . implode(', ', array_fill(0, count(self::ITEM_FIELDS), '?')) . ')'))
                . ' AS n ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => "{$c} = profit_item_day.{$c} + n.{$c}", self::ITEM_FIELDS)),
                $params);
        }
        foreach ($days as $day => $f) {
            $db->exec('INSERT INTO profit_day (channel_id, day, ' . implode(', ', self::DAY_FIELDS) . ') VALUES (?, ?, '
                . implode(', ', array_fill(0, count(self::DAY_FIELDS), '?')) . ') AS n ON DUPLICATE KEY UPDATE '
                . implode(', ', array_map(static fn (string $c): string => "{$c} = profit_day.{$c} + n.{$c}", self::DAY_FIELDS)),
                [$channelId, $day, ...array_values($f)]);
        }
    }

    /**
     * A store's day worked out again from sale_order and sale_line, its two summaries' rows replaced (one transaction).
     *
     * @return array<string, int> the day's figures
     */
    /**
     * Locks the summary rows of a store's days (made when missing), in day order, inside the caller's transaction.
     *
     * @param list<string> $days
     */
    public static function guard(Db $db, int $channelId, array $days): void
    {
        $days = array_values(array_unique($days));
        sort($days, SORT_STRING);
        foreach ($days as $d) {
            $db->exec('INSERT INTO profit_day (channel_id, day) VALUES (?, ?) AS n ON DUPLICATE KEY UPDATE channel_id = n.channel_id', [$channelId, $d]);
        }
    }

    public static function rebuild(Db $db, int $channelId, string $day): array
    {
        return $db->transaction(static function (Db $db) use ($channelId, $day): array {
            self::guard($db, $channelId, [$day]);
            ['day' => $f, 'items' => $items, 'any' => $any] = self::compute($db, $channelId, $day);
            $db->exec('DELETE FROM profit_item_day WHERE day = ? AND channel_id = ?', [$day, $channelId]);
            $db->exec('DELETE FROM profit_day WHERE day = ? AND channel_id = ?', [$day, $channelId]);
            foreach (array_chunk($items, 500, true) as $chunk) {
                $params = [];
                foreach ($chunk as $sku => $g) {
                    array_push($params, $channelId, $day, (int) $sku, ...array_values($g));
                }
                $db->exec('INSERT INTO profit_item_day (channel_id, day, sku_id, ' . implode(', ', self::ITEM_FIELDS) . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ' . implode(', ', array_fill(0, count(self::ITEM_FIELDS), '?')) . ')')), $params);
            }
            if ($any) {
                $db->exec('INSERT INTO profit_day (channel_id, day, ' . implode(', ', self::DAY_FIELDS) . ') VALUES (?, ?, '
                    . implode(', ', array_fill(0, count(self::DAY_FIELDS), '?')) . ')', [$channelId, $day, ...array_values($f)]);
            }
            return $f;
        });
    }

    /**
     * A store's day worked out from sale_order and sale_line (orderShare(), as rebuild()), nothing written.
     *
     * @return array{day: array<string, int>, items: array<int, array<string, int>>, any: bool} items by item id, in item order
     */
    public static function compute(Db $db, int $channelId, string $day): array
    {
        $orders = [];
        foreach ($db->all('SELECT * FROM sale_order WHERE day = ? AND channel_id = ?', [$day, $channelId]) as $o) {
            $orders[(string) $o['order_ref']] = ['o' => $o, 'lines' => []];
        }
        // Each line under the ref of the order it joins (the key compares without case: a line stored as ord1 of ORD1 is ORD1's, never an
        // order of its own that the day loses: the second final review of 3c31d94, a-M8, c-M7, PR132).
        foreach ($db->all('SELECT l.*, o.order_ref AS of_order FROM sale_line l JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
            . 'WHERE o.day = ? AND o.channel_id = ?', [$day, $channelId]) as $l) {
            $orders[(string) $l['of_order']]['lines'][strtolower((string) $l['line_id'])] = $l;
        }
        // The delivery bills' lines of the day (0077, DL11): on each paid order, and on no order dated that day.
        $delivery = DeliveryBills::dayFigures($db, $channelId, $day);
        $days = [];
        $items = [];
        foreach ($orders as $ref => $x) {
            if (!isset($x['o'])) {
                continue;
            }
            $s = self::orderShare($x['o'], $x['lines'], $delivery['orders'][strtolower((string) $ref)] ?? 0);
            $days = self::add($days, $s['days'], self::DAY_FIELDS);
            $items = self::add($items, $s['items'], self::ITEM_FIELDS);
        }
        if ($delivery['other_lines'] > 0) {
            $days[$day] ??= array_fill_keys(self::DAY_FIELDS, 0);
            $days[$day]['delivery_other'] += $delivery['other'];
        }
        $bySku = [];
        foreach ($items as $k => $f) {
            $bySku[(int) explode('|', (string) $k)[1]] = $f;
        }
        ksort($bySku);
        return ['day' => $days[$day] ?? array_fill_keys(self::DAY_FIELDS, 0), 'items' => $bySku, 'any' => isset($days[$day])];
    }

    /**
     * What a rebuild of a store's day would change, nothing written (bin/rebuild_profit_days.php without --apply: the go-live check, the
     * final review of 6d59eda, c-LOW4 / a-M8, PR104): each figure of the day and of each item whose kept value differs from the worked-out
     * one, read in one consistent snapshot. [] when the day's summaries are exactly what its orders add up to.
     *
     * @return list<string> "day: cost kept 9000, worked out 10000 (+1000)", "item 12: units kept 3, worked out 2 (-1)"
     */
    public static function differences(Db $db, int $channelId, string $day): array
    {
        $read = static function (Db $db) use ($channelId, $day): array {
            $c = self::compute($db, $channelId, $day);
            $keptDay = $db->one('SELECT ' . implode(', ', self::DAY_FIELDS) . ' FROM profit_day WHERE day = ? AND channel_id = ?', [$day, $channelId]);
            $keptItems = [];
            foreach ($db->all('SELECT sku_id, ' . implode(', ', self::ITEM_FIELDS) . ' FROM profit_item_day WHERE day = ? AND channel_id = ? ORDER BY sku_id', [$day, $channelId]) as $r) {
                $keptItems[(int) $r['sku_id']] = array_map('intval', array_intersect_key($r, array_flip(self::ITEM_FIELDS)));
            }
            return [$c, $keptDay === null ? array_fill_keys(self::DAY_FIELDS, 0) : array_map('intval', $keptDay), $keptItems, $keptDay !== null];
        };
        [$c, $keptDay, $keptItems, $keptRow] = self::snapshot($db, $read);
        $out = [];
        // A day's row kept with nothing to count (its last order moved to another day): a line of zeros on the pages that --apply takes away
        // (the Codex check of c48683e).
        if ($keptRow && !$c['any'] && array_filter($keptDay) === []) {
            $out[] = 'day: a summary row of zeros kept with no paid order on the day (--apply removes it)';
        }
        foreach (self::DAY_FIELDS as $f) {
            if ($keptDay[$f] !== $c['day'][$f]) {
                $out[] = sprintf('day: %s kept %d, worked out %d (%+d)', $f, $keptDay[$f], $c['day'][$f], $c['day'][$f] - $keptDay[$f]);
            }
        }
        $skus = array_unique([...array_keys($keptItems), ...array_keys($c['items'])]);
        sort($skus);
        foreach ($skus as $sku) {
            // An item's row kept with nothing of it sold on the day (its only sale moved to another day): a line of zeros on the lists by
            // product that --apply takes away (the Codex check of 2b718e7).
            if (isset($keptItems[$sku]) && !isset($c['items'][$sku]) && array_filter($keptItems[$sku]) === []) {
                $out[] = "item {$sku}: a row of zeros kept with no sale of it on the day (--apply removes it)";
                continue;
            }
            foreach (self::ITEM_FIELDS as $f) {
                $k = $keptItems[$sku][$f] ?? 0;
                $w = $c['items'][$sku][$f] ?? 0;
                if ($k !== $w) {
                    $out[] = sprintf('item %d: %s kept %d, worked out %d (%+d)', $sku, $f, $k, $w, $w - $k);
                }
            }
        }
        return $out;
    }

    /**
     * $fn run on one consistent snapshot of the database (CW\Ops\Snapshot: REPEATABLE READ, WITH CONSISTENT SNAPSHOT, READ ONLY): every read
     * it makes sees the same moment (the Profit screens' figures and their waiting units: the final review of 6d59eda, c-M9, PR103). Inside
     * an open transaction, or a snapshot of its own, it runs in that one.
     *
     * @template T
     * @param \Closure(Db): T $fn
     * @return T
     */
    public static function snapshot(Db $db, \Closure $fn): mixed
    {
        $id = spl_object_id($db);
        if (isset(self::$reading[$id]) || $db->inTransaction()) {
            return $fn($db);
        }
        self::$reading[$id] = true;
        try {
            // REPEATABLE READ for this one transaction (CW's sessions read committed: CW\Ops\Snapshot), read only, no lock taken.
            return \CW\Ops\Snapshot::read($db, $fn);
        } finally {
            unset(self::$reading[$id]);
        }
    }

    /** @var array<int, true> the connections inside snapshot() (a snapshot asked for inside one joins it) */
    private static array $reading = [];

    /**
     * new - old per key and field, the keys in order, the rows with no difference left out.
     *
     * @param array<string, array<string, int>> $old
     * @param array<string, array<string, int>> $new
     * @param list<string> $fields
     * @return array<string, array<string, int>>
     */
    private static function diff(array $old, array $new, array $fields): array
    {
        $out = [];
        $keys = array_unique([...array_map('strval', array_keys($old)), ...array_map('strval', array_keys($new))]);
        sort($keys, SORT_STRING);
        foreach ($keys as $k) {
            $row = [];
            $any = false;
            foreach ($fields as $f) {
                $row[$f] = ($new[$k][$f] ?? 0) - ($old[$k][$f] ?? 0);
                $any = $any || $row[$f] !== 0;
            }
            if ($any) {
                $out[$k] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string, array<string, int>> $sum
     * @param array<string, array<string, int>> $add
     * @param list<string> $fields
     * @return array<string, array<string, int>>
     */
    private static function add(array $sum, array $add, array $fields): array
    {
        foreach ($add as $k => $f) {
            $sum[$k] ??= array_fill_keys($fields, 0);
            foreach ($fields as $field) {
                $sum[$k][$field] += $f[$field];
            }
        }
        return $sum;
    }
}
