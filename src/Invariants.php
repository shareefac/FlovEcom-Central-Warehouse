<?php

declare(strict_types=1);

namespace CW;

/**
 * The nightly invariant check (plan §14): the cached buckets must equal what the journals say.
 *
 *  1. held / allocated of every balance = SUM(units_per_item) of its reservation_unit rows in
 *     state held / allocated (linked units only; the sale-time snapshot's warehouse+sku).
 *  2. on_hand / allocated / held of every balance = SUM(qty_delta) of its stock_ledger rows
 *     (bucket by bucket: every change is journalled, movements included).
 *  3. the newest ledger row of each (balance, bucket) has balance_after = the bucket.
 *  4. per unit, the net held / allocated ledger deltas match the unit's state (held -> u held,
 *     allocated -> u allocated, anything else -> 0), under the unit's snapshot warehouse+sku.
 *  5. unit states agree with their reservation (held units only under held reservations,
 *     allocated/shipped/returned units only under committed ones) and channel.
 *  6. every linked unit has a balance row.
 *
 * Returns human-readable violations; an empty list means consistent. Read-only.
 */
final class Invariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $v = [];

        foreach (['held', 'allocated'] as $bucket) {
            $rows = $db->all(
                "SELECT b.warehouse_id, b.sku_id, b.`{$bucket}` AS bal, COALESCE(u.s, 0) AS units FROM stock_balance b "
                . 'LEFT JOIN (SELECT warehouse_id, sku_id, SUM(units_per_item) AS s FROM reservation_unit '
                . '           WHERE state = ? AND sku_id IS NOT NULL GROUP BY warehouse_id, sku_id) u '
                . '  ON u.warehouse_id = b.warehouse_id AND u.sku_id = b.sku_id '
                . "WHERE b.`{$bucket}` <> COALESCE(u.s, 0) ORDER BY b.warehouse_id, b.sku_id LIMIT " . self::MAX_PER_CHECK,
                [$bucket],
            );
            foreach ($rows as $r) {
                $v[] = "balance {$r['warehouse_id']}:{$r['sku_id']} {$bucket}={$r['bal']} but its {$bucket} units sum to {$r['units']}";
            }
        }
        foreach ($db->all(
            'SELECT u.channel_id, u.unit_id, u.warehouse_id, u.sku_id FROM reservation_unit u '
            . 'LEFT JOIN stock_balance b ON b.warehouse_id = u.warehouse_id AND b.sku_id = u.sku_id '
            . 'WHERE u.sku_id IS NOT NULL AND b.sku_id IS NULL LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "unit {$r['channel_id']}:{$r['unit_id']} points at balance {$r['warehouse_id']}:{$r['sku_id']}, which does not exist";
        }

        foreach ($db->all(
            'SELECT b.warehouse_id, b.sku_id, b.on_hand, b.allocated, b.held, '
            . 'COALESCE(l.oh, 0) AS l_on_hand, COALESCE(l.al, 0) AS l_allocated, COALESCE(l.he, 0) AS l_held FROM stock_balance b '
            . "LEFT JOIN (SELECT warehouse_id, sku_id, SUM(IF(bucket = 'on_hand', qty_delta, 0)) AS oh, "
            . "                  SUM(IF(bucket = 'allocated', qty_delta, 0)) AS al, SUM(IF(bucket = 'held', qty_delta, 0)) AS he "
            . '           FROM stock_ledger GROUP BY warehouse_id, sku_id) l ON l.warehouse_id = b.warehouse_id AND l.sku_id = b.sku_id '
            . 'WHERE b.on_hand <> COALESCE(l.oh, 0) OR b.allocated <> COALESCE(l.al, 0) OR b.held <> COALESCE(l.he, 0) '
            . 'ORDER BY b.warehouse_id, b.sku_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            foreach (['on_hand', 'allocated', 'held'] as $b) {
                if ((int) $r[$b] !== (int) $r['l_' . $b]) {
                    $v[] = "balance {$r['warehouse_id']}:{$r['sku_id']} {$b}={$r[$b]} but its ledger sums to {$r['l_' . $b]}";
                }
            }
        }

        foreach ($db->all(
            'SELECT l.warehouse_id, l.sku_id, l.bucket, l.balance_after, '
            . "CASE l.bucket WHEN 'on_hand' THEN b.on_hand WHEN 'allocated' THEN b.allocated ELSE b.held END AS cur "
            . 'FROM stock_ledger l JOIN (SELECT MAX(id) AS id FROM stock_ledger GROUP BY warehouse_id, sku_id, bucket) m ON m.id = l.id '
            . 'JOIN stock_balance b ON b.warehouse_id = l.warehouse_id AND b.sku_id = l.sku_id '
            . "HAVING l.balance_after <> cur LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "balance {$r['warehouse_id']}:{$r['sku_id']} {$r['bucket']}={$r['cur']} but its last ledger row says {$r['balance_after']}";
        }

        // 4. per-unit ledger nets
        $groups = [];
        foreach ($db->all(
            "SELECT channel_id, unit_id, warehouse_id, sku_id, SUM(IF(bucket = 'held', qty_delta, 0)) AS held_net, "
            . "SUM(IF(bucket = 'allocated', qty_delta, 0)) AS alloc_net FROM stock_ledger "
            . "WHERE unit_id IS NOT NULL AND channel_id IS NOT NULL AND bucket IN ('held', 'allocated') "
            . 'GROUP BY channel_id, unit_id, warehouse_id, sku_id',
        ) as $g) {
            $groups[$g['channel_id'] . "\0" . $g['unit_id']][] = $g;
        }
        $units = [];
        foreach ($db->all('SELECT channel_id, unit_id, warehouse_id, sku_id, units_per_item, state FROM reservation_unit WHERE sku_id IS NOT NULL') as $u) {
            $units[$u['channel_id'] . "\0" . $u['unit_id']] = $u;
        }
        $n = 0;
        foreach ($units + array_fill_keys(array_keys($groups), null) as $key => $u) {
            if ($n >= self::MAX_PER_CHECK) {
                break;
            }
            $u ??= $units[$key] ?? null;
            $gs = $groups[$key] ?? [];
            $seenOwn = false;
            foreach ($gs as $g) {
                $own = $u !== null && (int) $g['warehouse_id'] === (int) $u['warehouse_id'] && (int) $g['sku_id'] === (int) $u['sku_id'];
                $seenOwn = $seenOwn || $own;
                [$eh, $ea] = $own ? self::expected((string) $u['state'], (int) $u['units_per_item']) : [0, 0];
                if ((int) $g['held_net'] !== $eh || (int) $g['alloc_net'] !== $ea) {
                    $v[] = sprintf('unit %s: ledger nets held=%d allocated=%d at %d:%d, expected %d/%d (state %s)',
                        str_replace("\0", ':', (string) $key), $g['held_net'], $g['alloc_net'], $g['warehouse_id'], $g['sku_id'],
                        $eh, $ea, $u['state'] ?? 'no unit');
                    $n++;
                }
            }
            if ($u !== null && !$seenOwn && in_array($u['state'], ['held', 'allocated'], true)) {
                $v[] = sprintf('unit %s is %s but has no ledger rows', str_replace("\0", ':', (string) $key), $u['state']);
                $n++;
            }
        }

        foreach ($db->all(
            'SELECT u.channel_id, u.unit_id, u.state, r.status, r.channel_id AS r_channel FROM reservation_unit u '
            . 'JOIN reservation r ON r.id = u.reservation_id '
            . "WHERE (u.state = 'held' AND r.status <> 'held') "
            . "   OR (u.state IN ('allocated', 'shipped', 'returned') AND r.status <> 'committed') "
            . '   OR u.channel_id <> r.channel_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "unit {$r['channel_id']}:{$r['unit_id']} is {$r['state']} under a {$r['status']} reservation of channel {$r['r_channel']}";
        }
        foreach ($db->all(
            "SELECT id, channel_id, order_ref FROM reservation WHERE status = 'held' AND expires_at IS NULL LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "reservation {$r['channel_id']}:{$r['order_ref']} is held without expires_at";
        }
        return $v;
    }

    /** @return array{0: int, 1: int} expected [held, allocated] of one unit's own ledger rows */
    private static function expected(string $state, int $u): array
    {
        return match ($state) {
            'held' => [$u, 0],
            'allocated' => [0, $u],
            default => [0, 0],
        };
    }
}
