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
 *
 * An order counts on the UK day it was paid (sale_order.day); an unpaid one counts nothing. `orders` counts the paid orders with at
 * least one unit that counts and no parent (`resends` the ones with one); sale_ex is the lines' (the order-level refunds apart).
 */
final class ProfitDays
{
    public const DAY_FIELDS = ['orders', 'resends', 'units', 'sale_ex', 'order_refund_ex', 'cost', 'units_no_cost', 'delivery_ex'];
    public const ITEM_FIELDS = ['units', 'sale_ex', 'cost', 'units_no_cost'];

    /**
     * @param array<string, mixed>|null $order a sale_order row (or the row about to be written)
     * @param array<string, array<string, mixed>> $lines its sale_line rows
     * @return array{days: array<string, array<string, int>>, items: array<string, array<string, int>>} "day" and "day|sku" => figures
     */
    public static function orderShare(?array $order, array $lines): array
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
            'units_no_cost' => (int) $order['units_no_cost'], 'delivery_ex' => (int) $order['delivery_ex']]];
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
    public static function rebuild(Db $db, int $channelId, string $day): array
    {
        return $db->transaction(static function (Db $db) use ($channelId, $day): array {
            $orders = [];
            foreach ($db->all('SELECT * FROM sale_order WHERE day = ? AND channel_id = ? FOR UPDATE', [$day, $channelId]) as $o) {
                $orders[(string) $o['order_ref']] = ['o' => $o, 'lines' => []];
            }
            foreach ($db->all('SELECT l.* FROM sale_line l JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
                . 'WHERE o.day = ? AND o.channel_id = ?', [$day, $channelId]) as $l) {
                $orders[(string) $l['order_ref']]['lines'][(string) $l['line_id']] = $l;
            }
            $days = [];
            $items = [];
            foreach ($orders as $x) {
                $s = self::orderShare($x['o'], $x['lines']);
                $days = self::add($days, $s['days'], self::DAY_FIELDS);
                $items = self::add($items, $s['items'], self::ITEM_FIELDS);
            }
            $db->exec('DELETE FROM profit_item_day WHERE day = ? AND channel_id = ?', [$day, $channelId]);
            $db->exec('DELETE FROM profit_day WHERE day = ? AND channel_id = ?', [$day, $channelId]);
            ksort($items, SORT_STRING);
            foreach (array_chunk($items, 500, true) as $chunk) {
                $params = [];
                foreach ($chunk as $k => $f) {
                    array_push($params, $channelId, $day, (int) explode('|', $k)[1], ...array_values($f));
                }
                $db->exec('INSERT INTO profit_item_day (channel_id, day, sku_id, ' . implode(', ', self::ITEM_FIELDS) . ') VALUES '
                    . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ' . implode(', ', array_fill(0, count(self::ITEM_FIELDS), '?')) . ')')), $params);
            }
            $f = $days[$day] ?? array_fill_keys(self::DAY_FIELDS, 0);
            if (isset($days[$day])) {
                $db->exec('INSERT INTO profit_day (channel_id, day, ' . implode(', ', self::DAY_FIELDS) . ') VALUES (?, ?, '
                    . implode(', ', array_fill(0, count(self::DAY_FIELDS), '?')) . ')', [$channelId, $day, ...array_values($f)]);
            }
            return $f;
        });
    }

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
