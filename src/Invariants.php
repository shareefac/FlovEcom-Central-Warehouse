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
 *  7. every on_hand ledger row has exactly one stock_value_seq row (I3), and every seq row points at
 *     an existing on_hand row of the same item.
 *  8. per item the value seqs are exactly 1..N, and its stock_value_clock row says N (no clock row,
 *     no seq rows: an item without on_hand rows may have a clock at 0 or none).
 *  9. within one balance (warehouse, item) the value seq grows with the ledger id: the balance lock
 *     serialises whole transactions on a balance. Across warehouses seq order is commit order and may
 *     differ from id order (I3); that is intended and not checked.
 * 10. the VERIFY moves of one unit in one operation (cancel with restockable = false, noted
 *     cancel_not_restockable; uncancel back from VERIFY, noted uncancel_from_verify, D46) are one
 *     transfer_out and one transfer_in of one item that net to zero, with VERIFY on the right side.
 * 11. per unit and item, those VERIFY rows never net below zero (an uncancel takes back from VERIFY
 *     only what a cancel parked there), and a unit whose newest such row is a move back has no open
 *     verify_recount under its key (the uncancel dismissed it and retired the key).
 * D1-D7. the document base (0008): gapless numbers, reversal pairs, ledger rows naming posted documents by their
 *     number, review tasks on the right documents and never decided by their own people, line items, posted_hash
 *     (CW\Documents\DocumentInvariants, I17-I21).
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

        array_push($v, ...self::verifyMoves($db));
        array_push($v, ...self::valueSequence($db));
        array_push($v, ...Documents\DocumentInvariants::check($db));
        return $v;
    }

    /**
     * Invariants 10-11: the per-unit moves into and out of VERIFY of cancel (D37) and uncancel (D46).
     *
     * @return list<string>
     */
    private static function verifyMoves(Db $db): array
    {
        $v = [];
        $notes = "('cancel_not_restockable', 'uncancel_from_verify')";
        // 10. each operation's move of a unit is a balanced pair, VERIFY on the right side
        foreach ($db->all(
            'SELECT l.channel_id, l.unit_id, l.idem_key, l.note, SUM(l.qty_delta) AS net, '
            . "SUM(l.movement_type = 'transfer_out') AS outs, SUM(l.movement_type = 'transfer_in') AS ins, COUNT(DISTINCT l.sku_id) AS items, "
            . 'SUM(l.warehouse_id = v.id) AS at_verify, '
            . "SUM(l.warehouse_id = v.id AND l.movement_type = IF(l.note = 'cancel_not_restockable', 'transfer_in', 'transfer_out')) AS right_side "
            . "FROM stock_ledger l JOIN warehouse v ON v.code = 'VERIFY' "
            . "WHERE l.note IN {$notes} AND l.movement_type IN ('transfer_out', 'transfer_in') AND l.unit_id IS NOT NULL "
            . 'GROUP BY l.channel_id, l.unit_id, l.idem_key, l.note '
            . 'HAVING net <> 0 OR outs <> 1 OR ins <> 1 OR items <> 1 OR at_verify <> 1 OR right_side <> 1 '
            . 'ORDER BY l.channel_id, l.unit_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = sprintf('unit %s:%s: its %s move under key %s is not one balanced pair through VERIFY (net %d, %d out, %d in, %d items, %d at VERIFY)',
                $r['channel_id'], $r['unit_id'], $r['note'], $r['idem_key'] ?? 'NULL', $r['net'], $r['outs'], $r['ins'], $r['items'], $r['at_verify']);
        }
        // 11. never more taken back from VERIFY than parked there; a move back leaves no open recount
        foreach ($db->all(
            'SELECT l.channel_id, l.unit_id, l.sku_id, SUM(l.qty_delta) AS net FROM stock_ledger l '
            . "JOIN warehouse v ON v.id = l.warehouse_id AND v.code = 'VERIFY' "
            . "WHERE l.note IN {$notes} AND l.unit_id IS NOT NULL "
            . 'GROUP BY l.channel_id, l.unit_id, l.sku_id HAVING net < 0 ORDER BY l.channel_id, l.unit_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "unit {$r['channel_id']}:{$r['unit_id']}: its VERIFY moves of item {$r['sku_id']} net {$r['net']} (more taken back than parked)";
        }
        foreach ($db->all(
            'SELECT l.channel_id, l.unit_id, r.id AS review_id FROM stock_ledger l '
            . '  JOIN (SELECT MAX(l2.id) AS id FROM stock_ledger l2 '
            . "        JOIN warehouse v ON v.id = l2.warehouse_id AND v.code = 'VERIFY' "
            . "        WHERE l2.note IN {$notes} AND l2.unit_id IS NOT NULL GROUP BY l2.channel_id, l2.unit_id) m ON m.id = l.id "
            . "  JOIN count_review r ON r.dedupe_key = CONCAT('verify:', l.channel_id, ':', l.unit_id) AND r.status = 'open' "
            . "WHERE l.note = 'uncancel_from_verify' ORDER BY l.channel_id, l.unit_id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "unit {$r['channel_id']}:{$r['unit_id']} was moved back from VERIFY but its recount {$r['review_id']} is still open under its key";
        }
        return $v;
    }

    /**
     * Invariants 7-9: the per-item value sequence (I3) that IM8 values each item's on_hand changes by.
     *
     * @return list<string>
     */
    private static function valueSequence(Db $db): array
    {
        $v = [];
        // 7. one seq row per on_hand row (uq_stock_value_seq_ledger makes "at most one"), pointing back at it
        foreach ($db->all(
            'SELECT l.id, l.warehouse_id, l.sku_id, l.movement_type FROM stock_ledger l '
            . 'LEFT JOIN stock_value_seq s ON s.stock_ledger_id = l.id '
            . "WHERE l.bucket = 'on_hand' AND s.stock_ledger_id IS NULL ORDER BY l.id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "ledger row {$r['id']} ({$r['movement_type']}, on_hand of {$r['warehouse_id']}:{$r['sku_id']}) has no value seq";
        }
        foreach ($db->all(
            'SELECT s.sku_id, s.seq, s.stock_ledger_id, l.id AS l_id, l.bucket, l.sku_id AS l_sku FROM stock_value_seq s '
            . 'LEFT JOIN stock_ledger l ON l.id = s.stock_ledger_id '
            . "WHERE l.id IS NULL OR l.bucket <> 'on_hand' OR l.sku_id <> s.sku_id ORDER BY s.sku_id, s.seq LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "value seq {$r['sku_id']}#{$r['seq']} points at ledger row {$r['stock_ledger_id']}, "
                . ($r['l_id'] === null ? 'which does not exist' : "which is in bucket {$r['bucket']} of item {$r['l_sku']}");
        }

        // 8. seqs 1..N per item, and the clock agrees
        foreach ($db->all(
            'SELECT sku_id, MIN(seq) AS lo, MAX(seq) AS hi, COUNT(*) AS n FROM stock_value_seq GROUP BY sku_id '
            . 'HAVING MIN(seq) <> 1 OR MAX(seq) <> COUNT(*) ORDER BY sku_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "item {$r['sku_id']}: value seqs {$r['lo']}..{$r['hi']} in {$r['n']} rows (expected 1..{$r['n']}, no gap)";
        }
        foreach ($db->all(
            'SELECT c.sku_id, c.last_seq, COALESCE(m.hi, 0) AS hi FROM stock_value_clock c '
            . 'LEFT JOIN (SELECT sku_id, MAX(seq) AS hi FROM stock_value_seq GROUP BY sku_id) m ON m.sku_id = c.sku_id '
            . 'WHERE c.last_seq <> COALESCE(m.hi, 0) ORDER BY c.sku_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "item {$r['sku_id']}: value clock at {$r['last_seq']} but its highest value seq is {$r['hi']}";
        }
        foreach ($db->all(
            'SELECT s.sku_id, COUNT(*) AS n FROM stock_value_seq s LEFT JOIN stock_value_clock c ON c.sku_id = s.sku_id '
            . 'WHERE c.sku_id IS NULL GROUP BY s.sku_id ORDER BY s.sku_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "item {$r['sku_id']} has {$r['n']} value seqs but no value clock";
        }

        // 9. within one balance, seq order = ledger id order
        foreach ($db->all(
            'SELECT warehouse_id, sku_id, id, seq, prev FROM ('
            . '  SELECT l.warehouse_id, l.sku_id, l.id, s.seq, LAG(s.seq) OVER (PARTITION BY l.warehouse_id, l.sku_id ORDER BY l.id) AS prev '
            . "  FROM stock_ledger l JOIN stock_value_seq s ON s.stock_ledger_id = l.id WHERE l.bucket = 'on_hand') t "
            . 'WHERE seq <= prev ORDER BY warehouse_id, sku_id, id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "balance {$r['warehouse_id']}:{$r['sku_id']}: ledger row {$r['id']} has value seq {$r['seq']}, not after the previous row's {$r['prev']}";
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
