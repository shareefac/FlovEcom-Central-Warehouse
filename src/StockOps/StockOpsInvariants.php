<?php

declare(strict_types=1);

namespace CW\StockOps;

use CW\Db;

/**
 * The nightly checks of the stock records of pack A1 (called by CW\Invariants::check, so bin/invariants.php, the hammer and every
 * stock test run them; docs/decisions.md SO14). Read only; at most MAX_PER_CHECK problems per check.
 *
 *  O1. every final release (REL, posted or reversed, not itself a reversal) has exactly one `release` entry on the balance owed, of
 *      its own warehouse, whose amount is the sum of its lines' qty x price to the penny; and no other entry names it.
 *  O2. every posted cancellation of a release has exactly one `release_reversal` entry, the negation of its original's, on the
 *      same warehouse.
 *  O3. every entry that names a record names a release (or its cancellation) that is final; a release entry only a release, a
 *      release_reversal only a cancellation.
 *  O4. every payment reversal mirrors a payment of the same warehouse (the amount negated), and a payment is reversed at most once.
 *  O5. a final transfer or release nets to zero per product on the stock ledger (stock moves, it is never made or lost), and books
 *      only transfers (a release: out of the other account's room, goods in at the agreed price).
 *  O6. a stock record's header extension is of its type's kind; every final stock in, stock out, transfer and release has one.
 */
final class StockOpsInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::releases($db), ...self::entries($db), ...self::ledger($db), ...self::headers($db)];
    }

    /** @return list<string> O1, O2 */
    private static function releases(Db $db): array
    {
        $v = [];
        $amounts = [];
        foreach ($db->all("SELECT l.document_id, l.qty, l.unit_cost FROM document_line l JOIN document d ON d.id = l.document_id WHERE d.doc_type = 'REL' "
            . "AND d.status IN ('posted', 'reversed') AND d.reverses_id IS NULL AND l.sku_id IS NOT NULL") as $l) {
            $id = (int) $l['document_id'];
            $amounts[$id] = bcadd($amounts[$id] ?? '0.00', $l['unit_cost'] === null ? '0.00' : CostHints::amount((int) $l['qty'], (string) $l['unit_cost']), 2);
        }
        foreach ($db->all("SELECT d.id, d.number, d.warehouse_id, COUNT(e.id) AS n, MIN(e.kind) AS kind, SUM(e.amount) AS amount, MIN(e.warehouse_id) AS e_wh "
            . "FROM document d LEFT JOIN other_account_entry e ON e.document_id = d.id WHERE d.doc_type = 'REL' AND d.status IN ('posted', 'reversed') "
            . 'AND d.reverses_id IS NULL GROUP BY d.id, d.number, d.warehouse_id') as $r) {
            if (count($v) >= self::MAX_PER_CHECK) {
                return $v;
            }
            $want = $amounts[(int) $r['id']] ?? '0.00';
            if ((int) $r['n'] !== 1 || $r['kind'] !== 'release' || (int) $r['e_wh'] !== (int) $r['warehouse_id'] || bccomp((string) $r['amount'], $want, 2) !== 0) {
                $v[] = "release {$r['number']}: " . (int) $r['n'] . ' entries on the balance owed (' . ($r['kind'] ?? 'none') . ', ' . ($r['amount'] ?? '0')
                    . "), expected one release entry of {$want} on its warehouse";
            }
        }
        foreach ($db->all("SELECT d.id, d.number, o.warehouse_id, oe.amount AS o_amount, COUNT(e.id) AS n, MIN(e.kind) AS kind, SUM(e.amount) AS amount, MIN(e.warehouse_id) AS e_wh "
            . 'FROM document d JOIN document o ON o.id = d.reverses_id '
            . "LEFT JOIN other_account_entry oe ON oe.document_id = o.id AND oe.kind = 'release' LEFT JOIN other_account_entry e ON e.document_id = d.id "
            . "WHERE d.doc_type = 'REL' AND d.status = 'posted' GROUP BY d.id, d.number, o.warehouse_id, oe.amount") as $r) {
            if (count($v) >= self::MAX_PER_CHECK) {
                return $v;
            }
            $want = bcsub('0', (string) ($r['o_amount'] ?? '0'), 2);
            if ((int) $r['n'] !== 1 || $r['kind'] !== 'release_reversal' || (int) $r['e_wh'] !== (int) $r['warehouse_id'] || bccomp((string) $r['amount'], $want, 2) !== 0) {
                $v[] = "cancellation {$r['number']} of a release: " . (int) $r['n'] . ' entries (' . ($r['kind'] ?? 'none') . ', ' . ($r['amount'] ?? '0')
                    . "), expected one release_reversal of {$want}";
            }
        }
        return $v;
    }

    /** @return list<string> O3, O4 */
    private static function entries(Db $db): array
    {
        $v = [];
        foreach ($db->all('SELECT e.id, e.kind, d.doc_type, d.status, d.reverses_id FROM other_account_entry e LEFT JOIN document d ON d.id = e.document_id '
            . "WHERE e.document_id IS NOT NULL AND (d.id IS NULL OR d.doc_type <> 'REL' OR d.status NOT IN ('posted', 'reversed') "
            . "OR (e.kind = 'release') <> (d.reverses_id IS NULL)) LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "balance entry {$r['id']} ({$r['kind']}) names " . ($r['doc_type'] === null ? 'no record' : "a {$r['status']} {$r['doc_type']} record")
                . ($r['reverses_id'] === null ? '' : ' (a cancellation)');
        }
        foreach ($db->all('SELECT r.id, r.amount, r.warehouse_id, p.id AS p_id, p.kind AS p_kind, p.amount AS p_amount, p.warehouse_id AS p_wh '
            . "FROM other_account_entry r LEFT JOIN other_account_entry p ON p.id = r.reverses_id WHERE r.kind = 'payment_reversal' "
            . "AND (p.id IS NULL OR p.kind <> 'payment' OR p.warehouse_id <> r.warehouse_id OR r.amount <> -p.amount) LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "payment reversal {$r['id']} ({$r['amount']}) does not mirror a payment of its warehouse (" . ($r['p_id'] === null ? 'none'
                : "{$r['p_kind']} {$r['p_id']} of {$r['p_amount']}") . ')';
        }
        return $v;
    }

    /** @return list<string> O5 */
    private static function ledger(Db $db): array
    {
        $v = [];
        foreach ($db->all('SELECT l.document_id, d.number, l.sku_id, SUM(l.qty_delta) AS net, '
            . "SUM(l.movement_type NOT IN ('transfer_out', 'transfer_in', 'goods_in')) AS other, SUM(d.doc_type = 'TRF' AND l.movement_type = 'goods_in') AS trf_goods "
            . "FROM stock_ledger l JOIN document d ON d.id = l.document_id WHERE d.doc_type IN ('TRF', 'REL') AND l.bucket = 'on_hand' "
            . 'GROUP BY l.document_id, d.number, l.sku_id HAVING net <> 0 OR other > 0 OR trf_goods > 0 LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "record {$r['number']}: item {$r['sku_id']} nets {$r['net']} on the ledger" . ((int) $r['other'] + (int) $r['trf_goods'] > 0 ? ' or books a movement that is not a transfer' : '')
                . ' (a transfer or a release moves stock, it never makes or loses any)';
        }
        return $v;
    }

    /** @return list<string> O6 */
    private static function headers(Db $db): array
    {
        $v = [];
        $kinds = [];
        foreach (StockOpHandler::KINDS as $kind => $type) {
            $kinds[] = "WHEN '{$type}' THEN '{$kind}'";
        }
        foreach ($db->all('SELECT o.document_id, o.kind, d.doc_type FROM stock_op o JOIN document d ON d.id = o.document_id '
            . 'WHERE o.kind <> (CASE d.doc_type ' . implode(' ', $kinds) . " ELSE '' END) LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "stock record {$r['document_id']}: its header is of the kind {$r['kind']} but it is a {$r['doc_type']} record";
        }
        foreach ($db->all("SELECT d.id, d.number, d.doc_type FROM document d LEFT JOIN stock_op o ON o.document_id = d.id WHERE d.doc_type IN ('SIN', 'SOUT', 'TRF', 'REL') "
            . "AND d.status IN ('posted', 'reversed') AND o.document_id IS NULL LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "{$r['doc_type']} record {$r['number']} has no stock header";
        }
        return $v;
    }
}
