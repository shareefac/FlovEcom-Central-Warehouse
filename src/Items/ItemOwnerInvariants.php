<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Db;

/**
 * The nightly checks of a shop's own items (CW-1153; docs/decisions.md VB6), O1-O6:
 *  O1 an item that is a shop's own now is linked on no other shop's website;
 *  O2 no sale line of another shop sold an item at a time it was a shop's own (a breach of O1 that got through);
 *  O3 each paid sale line's mark (sale_line.item_owner) is what the periods say at its order's paid time (set where they say, empty elsewhere);
 *  O4 an item's periods never overlap;
 *  O5 each order's own figures (sale_order.own_*) are the sums of its own lines;
 *  O6 a bill marked as a shop's own (supplier_invoice.item_owner) has only that shop's own items on its goods lines, at its posting time.
 * Each check lists at most MAX rows; reads only.
 */
final class ItemOwnerInvariants
{
    public const MAX = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $v = [];
        $m = self::MAX;
        foreach ($db->all("SELECT p.sku_id, s.code, c.code AS shop FROM item_owner p JOIN sku s ON s.id = p.sku_id JOIN channel_listing l ON l.sku_id = p.sku_id "
            . "AND l.status IN ('mapped', 'quarantined') AND l.channel_id <> p.channel_id JOIN channel c ON c.id = l.channel_id WHERE p.to_at IS NULL LIMIT {$m}") as $r) {
            $v[] = "O1: {$r['code']} is a shop's own item but linked on {$r['shop']}";
        }
        foreach ($db->all('SELECT l.channel_id, l.line_id, l.order_ref FROM item_owner p JOIN sale_line l ON l.sku_id = p.sku_id AND l.channel_id <> p.channel_id '
            . 'JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
            . "WHERE l.removed_at IS NULL AND o.paid_at >= p.from_at AND (p.to_at IS NULL OR o.paid_at < p.to_at) LIMIT {$m}") as $r) {
            $v[] = "O2: line {$r['channel_id']}:{$r['line_id']} (order {$r['order_ref']}) sold another shop's own item";
        }
        foreach ($db->all('SELECT l.channel_id, l.line_id FROM sale_line l JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
            . 'WHERE l.item_owner IS NOT NULL AND NOT (o.paid_at IS NOT NULL AND l.item_owner = l.channel_id AND EXISTS (SELECT 1 FROM item_owner p WHERE p.sku_id = l.sku_id '
            . "AND p.channel_id = l.item_owner AND o.paid_at >= p.from_at AND (p.to_at IS NULL OR o.paid_at < p.to_at))) LIMIT {$m}") as $r) {
            $v[] = "O3: line {$r['channel_id']}:{$r['line_id']} is marked a shop's own but its item was not at the paid time";
        }
        foreach ($db->all('SELECT l.channel_id, l.line_id FROM item_owner p JOIN sale_line l ON l.sku_id = p.sku_id AND l.channel_id = p.channel_id '
            . 'JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
            . "WHERE l.item_owner IS NULL AND o.paid_at >= p.from_at AND (p.to_at IS NULL OR o.paid_at < p.to_at) LIMIT {$m}") as $r) {
            $v[] = "O3: line {$r['channel_id']}:{$r['line_id']} sold its shop's own item but is not marked";
        }
        foreach ($db->all('SELECT a.sku_id, a.id AS a, b.id AS b FROM item_owner a JOIN item_owner b ON b.sku_id = a.sku_id AND b.id > a.id '
            . "WHERE a.from_at < COALESCE(b.to_at, '9999-12-31') AND b.from_at < COALESCE(a.to_at, '9999-12-31') LIMIT {$m}") as $r) {
            $v[] = "O4: item {$r['sku_id']}: periods {$r['a']} and {$r['b']} overlap";
        }
        foreach ($db->all('SELECT o.channel_id, o.order_ref, o.own_units, o.own_sale_ex, o.own_cost, o.own_no_cost, '
            . 'COALESCE(SUM(IF(l.item_owner IS NOT NULL AND l.removed_at IS NULL, l.units, 0)), 0) AS u, '
            . 'COALESCE(SUM(IF(l.item_owner IS NOT NULL AND l.removed_at IS NULL, l.sale_ex, 0)), 0) AS s, '
            . 'COALESCE(SUM(IF(l.item_owner IS NOT NULL AND l.removed_at IS NULL, l.cost, 0)), 0) AS c, '
            . "COALESCE(SUM(IF(l.item_owner IS NOT NULL AND l.removed_at IS NULL AND l.units > 0 AND l.restocked = 0 AND l.cost_source = 'none', l.units, 0)), 0) AS nc "
            . 'FROM sale_order o LEFT JOIN sale_line l ON l.channel_id = o.channel_id AND l.order_ref = o.order_ref '
            . 'WHERE (o.own_units <> 0 OR o.own_sale_ex <> 0 OR o.own_cost <> 0 OR o.own_no_cost <> 0 OR o.own_refund_ex <> 0 '
            . '  OR EXISTS (SELECT 1 FROM sale_line x WHERE x.channel_id = o.channel_id AND x.order_ref = o.order_ref AND x.item_owner IS NOT NULL)) '
            . "GROUP BY o.channel_id, o.order_ref, o.own_units, o.own_sale_ex, o.own_cost, o.own_no_cost HAVING o.own_units <> u OR o.own_sale_ex <> s OR o.own_cost <> c "
            . "OR o.own_no_cost <> nc LIMIT {$m}") as $r) {
            $v[] = "O5: order {$r['channel_id']}:{$r['order_ref']} own figures {$r['own_units']}/{$r['own_sale_ex']}/{$r['own_cost']}/{$r['own_no_cost']} but its own lines "
                . "add up to {$r['u']}/{$r['s']}/{$r['c']}/{$r['nc']}";
        }
        foreach ($db->all('SELECT d.number, sl.line_no FROM supplier_invoice si JOIN document d ON d.id = si.document_id JOIN sinv_line sl ON sl.document_id = si.document_id '
            . 'JOIN document_line dl ON dl.document_id = sl.document_id AND dl.line_no = sl.line_no '
            . "WHERE si.item_owner IS NOT NULL AND sl.kind = 'goods' AND dl.sku_id IS NOT NULL AND NOT EXISTS "
            . ' (SELECT 1 FROM item_owner p WHERE p.sku_id = dl.sku_id AND p.channel_id = si.item_owner '
            . "AND COALESCE(d.posted_at, d.created_at) >= p.from_at AND (p.to_at IS NULL OR COALESCE(d.posted_at, d.created_at) < p.to_at)) LIMIT {$m}") as $r) {
            $v[] = "O6: {$r['number']} is marked a shop's own bill but its line {$r['line_no']} is not that shop's own item";
        }
        return $v;
    }
}
