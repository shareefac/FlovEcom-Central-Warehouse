<?php

declare(strict_types=1);

namespace CW\Arrivals;

use CW\Db;

/**
 * The nightly checks of pack J (docs/decisions.md PJ13; called by CW\Invariants::check, so bin/invariants.php and every stock test run
 * them). Read-only; at most MAX_PER_CHECK findings per check.
 *
 *  J1. every balance's incoming is what the final invoices still on their way put there: the open, sellable invoice_arrival rows of that
 *      item at that warehouse (a booking that forgot its arrival row, or a receipt that forgot to close it, is found).
 *  J2. an open arrival row belongs to a final invoice, a received one to a final receipt (a reversal reopens or cancels them).
 *  J3. a final invoice booked as received names a receipt for every goods line (none waits in Arriving, none is left unreceived).
 *  J4. what a final receipt took out of Incoming (its `arrive` rows) is, item by item, what the sellable arrival rows it received had
 *      put there. (Any other booking of incoming makes a balance differ from the invoices on their way: J1, with check 2 of
 *      CW\Invariants holding every balance to its ledger. No scan of the whole ledger here: the receipts' rows are read by document.)
 *  J5. (CW-1155, docs/decisions.md RR15) every invoice_arrival_rest row is about a first receipt made from that invoice (goods_receipt_invoice);
 *      a `rest` row names a line of another receipt made from the same invoice, of the same supplier and of the invoice line's item; a
 *      `claim` row names a return to the supplier about that first receipt and that invoice.
 *  J6. (CW-1155) per invoice line, what the rows still counting say came later, was claimed or stopped waiting for (posted rest, live claims,
 *      waivers of its first receipt now) is never more than its first receipt did not bring (the newest REST_LIMIT invoices).
 */
final class ArrivalInvariants
{
    private const MAX_PER_CHECK = 50;
    /** J6 works out the lines of the newest this many invoices with rows. */
    public const REST_LIMIT = 2000;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT x.warehouse_id, x.sku_id, w.code, COALESCE(b.incoming, 0) AS incoming, COALESCE(a.units, 0) AS expected FROM ('
            . '  SELECT warehouse_id, sku_id FROM stock_balance WHERE incoming <> 0'
            . "  UNION SELECT warehouse_id, sku_id FROM invoice_arrival WHERE state = 'open' AND sellable = 1) x "
            . 'JOIN warehouse w ON w.id = x.warehouse_id LEFT JOIN stock_balance b ON b.warehouse_id = x.warehouse_id AND b.sku_id = x.sku_id '
            . "LEFT JOIN (SELECT warehouse_id, sku_id, SUM(units) AS units FROM invoice_arrival WHERE state = 'open' AND sellable = 1 GROUP BY warehouse_id, sku_id) a "
            . '  ON a.warehouse_id = x.warehouse_id AND a.sku_id = x.sku_id '
            . 'WHERE COALESCE(b.incoming, 0) <> COALESCE(a.units, 0) ORDER BY x.warehouse_id, x.sku_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "J1 item {$r['sku_id']} at {$r['code']}: incoming {$r['incoming']}, but the invoices on their way add up to {$r['expected']}";
        }
        foreach ($db->all(
            "SELECT a.sinv_document_id, a.line_no, a.state, d.status AS invoice_status, g.status AS receipt_status FROM invoice_arrival a JOIN document d ON d.id = a.sinv_document_id "
            . 'LEFT JOIN document g ON g.id = a.grn_document_id '
            . "WHERE (a.state = 'open' AND d.status <> 'posted') OR (a.state = 'received' AND (g.status IS NULL OR g.status <> 'posted')) "
            . 'ORDER BY a.sinv_document_id, a.line_no LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "J2 invoice {$r['sinv_document_id']} line {$r['line_no']}: {$r['state']} on its way, but the invoice is {$r['invoice_status']}"
                . ($r['state'] === 'received' ? ' and its receipt ' . ($r['receipt_status'] ?? 'missing') : '');
        }
        foreach ($db->all(
            "SELECT sl.document_id, sl.line_no FROM supplier_invoice si JOIN document d ON d.id = si.document_id AND d.status IN ('posted', 'reversed') "
            . "JOIN sinv_line sl ON sl.document_id = si.document_id AND sl.kind = 'goods' AND sl.grn_document_id IS NULL "
            . "LEFT JOIN invoice_arrival a ON a.sinv_document_id = sl.document_id AND a.line_no = sl.line_no WHERE si.receive_mode = 'received' AND a.line_no IS NULL "
            . 'ORDER BY sl.document_id, sl.line_no LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "J3 invoice {$r['document_id']} line {$r['line_no']}: booked as received, but no receipt names it";
        }
        foreach ($db->all(
            'SELECT x.document_id, x.sku_id, x.booked, COALESCE(a.units, 0) AS expected FROM (SELECT l.document_id, l.sku_id, -SUM(l.qty_delta) AS booked FROM stock_ledger l '
            . "JOIN document d ON d.id = l.document_id AND d.doc_type = 'GRN' AND d.status = 'posted' AND d.reverses_id IS NULL "
            . "WHERE l.bucket = 'incoming' AND l.movement_type = 'arrive' GROUP BY l.document_id, l.sku_id) x "
            . "LEFT JOIN (SELECT grn_document_id, sku_id, SUM(units) AS units FROM invoice_arrival WHERE state = 'received' AND sellable = 1 GROUP BY grn_document_id, sku_id) a "
            . '  ON a.grn_document_id = x.document_id AND a.sku_id = x.sku_id WHERE x.booked <> COALESCE(a.units, 0) '
            . "UNION ALL SELECT a.grn_document_id, a.sku_id, 0, SUM(a.units) FROM invoice_arrival a JOIN document d ON d.id = a.grn_document_id AND d.status = 'posted' "
            . "WHERE a.state = 'received' AND a.sellable = 1 AND NOT EXISTS (SELECT 1 FROM stock_ledger l WHERE l.document_id = a.grn_document_id AND l.sku_id = a.sku_id "
            . "AND l.bucket = 'incoming') GROUP BY a.grn_document_id, a.sku_id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "J4 receipt {$r['document_id']} item {$r['sku_id']}: took {$r['booked']} out of Incoming, but the invoice rows it received put {$r['expected']} there";
        }
        foreach ($db->all(
            'SELECT r.id, r.kind, r.sinv_document_id, r.line_no, r.document_id, r.document_line_no, r.first_grn_document_id, f.sinv_document_id AS first_inv, '
            . 'g.sinv_document_id AS doc_inv, gr.supplier_id AS doc_supplier, si.supplier_id AS inv_supplier, gl.sku_id AS doc_sku, il.sku_id AS inv_sku, '
            . 'sr.grn_document_id AS claim_grn, sr.invoice_document_id AS claim_inv FROM invoice_arrival_rest r '
            . 'JOIN supplier_invoice si ON si.document_id = r.sinv_document_id JOIN document_line il ON il.document_id = r.sinv_document_id AND il.line_no = r.line_no '
            . 'LEFT JOIN goods_receipt_invoice f ON f.grn_document_id = r.first_grn_document_id '
            . 'LEFT JOIN goods_receipt_invoice g ON g.grn_document_id = r.document_id LEFT JOIN goods_receipt gr ON gr.document_id = r.document_id '
            . 'LEFT JOIN document_line gl ON gl.document_id = r.document_id AND gl.line_no = r.document_line_no '
            . 'LEFT JOIN supplier_return sr ON sr.document_id = r.document_id '
            . 'LEFT JOIN invoice_arrival a ON a.sinv_document_id = r.sinv_document_id AND a.line_no = r.line_no '
            . 'WHERE NOT (f.sinv_document_id <=> r.sinv_document_id) '
            // the first receipt is never itself a receipt of the rest, and while it is the arrival row's its line is the row's (the Codex check
            // of a0d33a2, LOW)
            . "OR EXISTS (SELECT 1 FROM invoice_arrival_rest x WHERE x.kind = 'rest' AND x.document_id = r.first_grn_document_id) "
            . "OR (a.state = 'received' AND a.grn_document_id = r.first_grn_document_id AND NOT (a.grn_line_no <=> r.first_grn_line_no)) "
            . "OR (r.kind = 'rest' AND (NOT (g.sinv_document_id <=> r.sinv_document_id) OR r.document_id = r.first_grn_document_id OR NOT (gr.supplier_id <=> si.supplier_id) "
            . '  OR NOT (gl.sku_id <=> il.sku_id))) '
            . "OR (r.kind = 'claim' AND (NOT (sr.grn_document_id <=> r.first_grn_document_id) OR NOT (sr.invoice_document_id <=> r.sinv_document_id))) "
            . 'ORDER BY r.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "J5 invoice {$r['sinv_document_id']} line {$r['line_no']}: its {$r['kind']} row {$r['id']} (document " . ($r['document_id'] ?? 'none') . ' line '
                . ($r['document_line_no'] ?? '-') . ") is not about a receipt of that invoice, supplier and item";
        }
        $found = 0;
        foreach (array_map('intval', $db->column('SELECT DISTINCT sinv_document_id FROM invoice_arrival_rest ORDER BY sinv_document_id DESC LIMIT ' . self::REST_LIMIT)) as $inv) {
            foreach (ArrivalRest::lines($db, $inv) as $l) {
                $settled = $l['rest'] + $l['claimed'] + $l['waived'];
                if ($settled > $l['short'] && $found < self::MAX_PER_CHECK) {
                    $found++;
                    $v[] = "J6 invoice {$inv} line {$l['line_no']}: {$l['rest']} came later, {$l['claimed']} claimed and {$l['waived']} stopped waiting for, "
                        . "but its first receipt did not bring only {$l['short']}";
                }
            }
        }
        return $v;
    }
}
