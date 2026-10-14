<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Db;

/**
 * The nightly checks of pack B (0031; docs/decisions.md PB14), called from CW\Invariants::check (so bin/invariants.php, the Safety checks
 * page and every stock test run them). Read-only; at most MAX_PER_CHECK violations per check.
 *
 *  B1. every repack record (RPK) that is posted or reversed has its header, and the header's items are its definition's: a record
 *      whose pack item was redefined under it is found.
 *  B2. a posted or reversed repack's on-hand ledger rows net, per item, to its lines (its lines are what it booked: the pack and the
 *      single move by exactly packs and packs x N units), and it booked nothing but `adjustment` rows.
 *  B3. every item made by hand (sku.origin `manual`) has its item_by_hand row, and every such row names a `manual` item.
 */
final class ItemsInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::headers($db), ...self::ledger($db), ...self::byHand($db)];
    }

    /** @return list<string> B1 */
    private static function headers(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT d.id, d.number, r.document_id, r.pack_sku_id, r.single_sku_id, r.units_per_pack, p.pack_sku_id AS p_pack, p.single_sku_id AS p_single, "
            . 'p.units AS p_units FROM document d LEFT JOIN repack r ON r.document_id = d.id LEFT JOIN item_pack p ON p.id = r.item_pack_id '
            . "WHERE d.doc_type = 'RPK' AND d.status IN ('posted', 'reversed') AND (r.document_id IS NULL OR r.pack_sku_id <> p.pack_sku_id "
            . 'OR r.single_sku_id <> p.single_sku_id OR r.units_per_pack <> p.units) LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = $r['document_id'] === null ? "repack {$r['number']} has no repack header"
                : "repack {$r['number']}: its header names other items or units than its pack definition";
        }
        return $v;
    }

    /** @return list<string> B2 */
    private static function ledger(Db $db): array
    {
        $v = [];
        foreach ($db->all('SELECT x.document_id, d.number, x.sku_id, x.booked, x.non_adj, COALESCE(l.qty, 0) AS line_qty FROM ('
            // From the repack records (ix_document_type_status) to their rows (ix_stock_ledger_document): never a pass over the ledger.
            . "SELECT /*+ JOIN_ORDER(dd, g) */ g.document_id, g.sku_id, SUM(g.qty_delta) AS booked, SUM(g.movement_type <> 'adjustment') AS non_adj "
            . "FROM document dd JOIN stock_ledger g ON g.document_id = dd.id WHERE dd.doc_type = 'RPK' AND g.bucket = 'on_hand' GROUP BY g.document_id, g.sku_id) x "
            . 'JOIN document d ON d.id = x.document_id '
            . 'LEFT JOIN (SELECT /*+ JOIN_ORDER(dt, dl) */ dl.document_id, dl.sku_id, SUM(dl.qty) AS qty FROM document dt JOIN document_line dl ON dl.document_id = dt.id '
            . "WHERE dt.doc_type = 'RPK' GROUP BY dl.document_id, dl.sku_id) l ON l.document_id = x.document_id AND l.sku_id = x.sku_id "
            . 'WHERE x.booked <> COALESCE(l.qty, 0) OR x.non_adj > 0 LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "repack {$r['number']}: item {$r['sku_id']} booked {$r['booked']} on hand against {$r['line_qty']} on its lines"
                . ((int) $r['non_adj'] > 0 ? ' (or a movement that is not an adjustment)' : '');
        }
        return $v;
    }

    /** @return list<string> B3 */
    private static function byHand(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT s.id, s.code FROM sku s LEFT JOIN item_by_hand h ON h.sku_id = s.id WHERE s.origin = 'manual' AND h.sku_id IS NULL LIMIT "
            . self::MAX_PER_CHECK) as $r) {
            $v[] = "item {$r['code']} was made by hand but has no record of who made it";
        }
        foreach ($db->all("SELECT h.sku_id, s.code, s.origin FROM item_by_hand h JOIN sku s ON s.id = h.sku_id WHERE NOT (s.origin <=> 'manual') LIMIT "
            . self::MAX_PER_CHECK) as $r) {
            $v[] = "item {$r['code']} is listed as made by hand but its origin is " . ($r['origin'] ?? 'not set');
        }
        return $v;
    }
}
