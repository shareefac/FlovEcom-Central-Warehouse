<?php

declare(strict_types=1);

namespace CW\PurchaseOrders;

use CW\Db;

/**
 * The nightly checks of purchase orders (0010; called at the end of CW\Invariants::check, so bin/invariants.php, the hammer
 * and every stock test run them). Read-only; at most MAX_PER_CHECK violations per check (spec §6.4).
 *
 *  P1. every non-reversal PO document has a purchase_order row and no reversal PO document has one (nor any other type);
 *      the state agrees with the document: draft / awaiting_approval / cancelled <=> state NULL; posted <=> approved, sent,
 *      part_received, received or closed; reversed <=> cancelled.
 *  P2. every posted or reversed non-reversal PO has its po_posting anchor (and no other document has one); the anchor's
 *      content hashes to its content_hash; and the current purchase_order + po_line content, recomputed
 *      (PurchaseOrderHandler::content, in batches of HASH_BATCH), equals it: a line or a header changed after approval is
 *      found (received_units and the state columns are not part of it).
 *  P3. item lines: qty = packs × units_per_pack, unit_cost = half-up(pack_price / units_per_pack, 6), amount =
 *      half-up(packs × pack_price, 2), an item; charge lines: no qty, amount = pack_price. In SQL with INTEGER operands
 *      (CAST ... AS UNSIGNED, then DIV): a DECIMAL division rounds its quotient at div_precision_increment (4) decimals, so
 *      q = n + 0.99996 would truncate to n + 1 (upp >= 10,000), and an 8-decimal intermediate rounds 0.000000499 up.
 *  P4. every line of a non-reversal PO document has its po_line, and every po_line has its document line on such a
 *      document.
 *  P5. the state agrees with the receipts: none received -> approved, sent or cancelled (unposted: state NULL); some ->
 *      part_received or closed; every item line received -> received or closed; sent => sent_at.
 *  P6. purchase_order totals = the recomputed totals (net = Σ amounts; VAT = Σ half-up(amount × rate / 100, 2)).
 */
final class PurchaseInvariants
{
    private const MAX_PER_CHECK = 50;
    public const HASH_BATCH = 500;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::headers($db), ...self::anchors($db), ...self::formulas($db), ...self::pairs($db), ...self::receipts($db), ...self::totals($db)];
    }

    /** @return list<string> P1 */
    private static function headers(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT d.id, d.doc_type, d.status, d.reverses_id, d.number, po.document_id AS po_id, po.state FROM document d '
            . 'LEFT JOIN purchase_order po ON po.document_id = d.id '
            . "WHERE (d.doc_type = 'PO' AND d.reverses_id IS NULL AND po.document_id IS NULL) "
            . "   OR (po.document_id IS NOT NULL AND (d.doc_type <> 'PO' OR d.reverses_id IS NOT NULL)) "
            . "   OR (po.document_id IS NOT NULL AND NOT ("
            . "        (d.status IN ('draft', 'awaiting_approval', 'cancelled') AND po.state IS NULL) "
            . "     OR (d.status = 'posted' AND COALESCE(po.state, '') IN ('approved', 'sent', 'part_received', 'received', 'closed')) "
            . "     OR (d.status = 'reversed' AND po.state <=> 'cancelled'))) "
            . 'ORDER BY d.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $label = 'document ' . $r['id'] . ' (' . ($r['number'] ?? $r['status']) . ')';
            $v[] = match (true) {
                $r['po_id'] === null => "PO {$label} has no purchase_order row",
                $r['doc_type'] !== 'PO' => "{$label} is a {$r['doc_type']} but has a purchase_order row",
                $r['reverses_id'] !== null => "PO cancellation {$label} has a purchase_order row (a reversal has none)",
                default => "PO {$label} is {$r['status']} but its purchase_order state is " . ($r['state'] ?? 'NULL'),
            };
        }
        return $v;
    }

    /** @return list<string> P2 */
    private static function anchors(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT d.id, d.number, d.status, d.doc_type, d.reverses_id, p.document_id AS p_id FROM document d LEFT JOIN po_posting p ON p.document_id = d.id '
            . "WHERE (d.doc_type = 'PO' AND d.reverses_id IS NULL AND d.status IN ('posted', 'reversed') AND p.document_id IS NULL) "
            . "   OR (p.document_id IS NOT NULL AND (d.doc_type <> 'PO' OR d.reverses_id IS NOT NULL OR d.status NOT IN ('posted', 'reversed'))) "
            . 'ORDER BY d.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = $r['p_id'] === null
                ? "PO document {$r['id']} ({$r['number']}) is {$r['status']} but has no po_posting anchor"
                : "document {$r['id']} (" . ($r['number'] ?? $r['status']) . ") has a po_posting anchor but is not an approved PO";
        }
        foreach ($db->all('SELECT document_id FROM po_posting WHERE SHA2(content, 256) <> content_hash ORDER BY document_id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "po_posting of document {$r['document_id']}: its content does not hash to its content_hash";
        }
        $after = 0;
        while (count($v) < self::MAX_PER_CHECK) {
            $pos = $db->all(
                'SELECT po.*, p.content_hash, d.number FROM po_posting p JOIN purchase_order po ON po.document_id = p.document_id JOIN document d ON d.id = p.document_id '
                . 'WHERE p.document_id > ? ORDER BY p.document_id LIMIT ' . self::HASH_BATCH,
                [$after],
            );
            if ($pos === []) {
                break;
            }
            $ids = array_map(static fn (array $p): int => (int) $p['document_id'], $pos);
            $lines = [];
            foreach ($db->all('SELECT * FROM po_line WHERE document_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY document_id, line_no', $ids) as $l) {
                $lines[(int) $l['document_id']][] = $l;
            }
            foreach ($pos as $p) {
                $id = (int) $p['document_id'];
                if (!hash_equals((string) $p['content_hash'], hash('sha256', PurchaseOrderHandler::content($p, $lines[$id] ?? [])))) {
                    $v[] = "PO {$p['number']} (document {$id}): its header or lines changed after approval (they no longer match its po_posting anchor)";
                    if (count($v) >= self::MAX_PER_CHECK) {
                        break;
                    }
                }
            }
            $after = (int) end($ids);
        }
        return $v;
    }

    /** @return list<string> P3 */
    private static function formulas(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT dl.document_id, dl.line_no, pl.kind, dl.sku_id, dl.qty, dl.unit_cost, dl.amount, pl.packs, pl.units_per_pack, pl.pack_price FROM po_line pl '
            . 'JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
            . "WHERE (pl.kind = 'item' AND (dl.sku_id IS NULL OR dl.qty IS NULL OR dl.qty <> pl.packs * pl.units_per_pack "
            . '   OR dl.unit_cost IS NULL OR dl.unit_cost * 1000000 <> (CAST(pl.pack_price * 10000 AS UNSIGNED) * 200 + pl.units_per_pack) DIV (2 * pl.units_per_pack) '
            . '   OR dl.amount IS NULL OR dl.amount * 100 <> (pl.packs * CAST(pl.pack_price * 10000 AS UNSIGNED) + 50) DIV 100)) '
            . "OR (pl.kind = 'charge' AND (dl.sku_id IS NOT NULL OR dl.qty IS NOT NULL OR dl.amount IS NULL OR dl.amount <> pl.pack_price)) "
            . 'ORDER BY dl.document_id, dl.line_no LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "PO document {$r['document_id']} line {$r['line_no']} ({$r['kind']}): qty " . ($r['qty'] ?? 'NULL') . ', unit cost ' . ($r['unit_cost'] ?? 'NULL')
                . ', amount ' . ($r['amount'] ?? 'NULL') . " do not follow {$r['packs']} packs × {$r['units_per_pack']} at {$r['pack_price']}";
        }
        return $v;
    }

    /** @return list<string> P4 */
    private static function pairs(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT dl.document_id, dl.line_no FROM document_line dl JOIN document d ON d.id = dl.document_id '
            . 'LEFT JOIN po_line pl ON pl.document_id = dl.document_id AND pl.line_no = dl.line_no '
            . "WHERE d.doc_type = 'PO' AND d.reverses_id IS NULL AND pl.document_id IS NULL ORDER BY dl.document_id, dl.line_no LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "PO document {$r['document_id']} line {$r['line_no']} has no po_line";
        }
        foreach ($db->all(
            'SELECT pl.document_id, pl.line_no, d.doc_type, d.reverses_id, dl.line_no AS dl_no FROM po_line pl '
            . 'LEFT JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no LEFT JOIN document d ON d.id = pl.document_id '
            . "WHERE dl.line_no IS NULL OR d.doc_type <> 'PO' OR d.reverses_id IS NOT NULL ORDER BY pl.document_id, pl.line_no LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "po_line {$r['document_id']}:{$r['line_no']} " . ($r['dl_no'] === null ? 'has no document line' : 'is on a document that is not an original PO');
        }
        return $v;
    }

    /** @return list<string> P5 */
    private static function receipts(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT po.document_id, po.state, po.sent_at, d.number, d.status, '
            . "COALESCE(SUM(IF(pl.kind = 'item', pl.received_units, 0)), 0) AS received, "
            . "COALESCE(SUM(IF(pl.kind = 'item' AND pl.received_units < dl.qty, 1, 0)), 0) AS short, "
            . "COALESCE(SUM(pl.kind = 'item'), 0) AS items "
            . 'FROM purchase_order po JOIN document d ON d.id = po.document_id LEFT JOIN po_line pl ON pl.document_id = po.document_id '
            . 'LEFT JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no '
            . 'GROUP BY po.document_id, po.state, po.sent_at, d.number, d.status '
            . "HAVING (received = 0 AND NOT (po.state IS NULL OR po.state IN ('approved', 'sent', 'cancelled'))) "
            . "    OR (received > 0 AND short > 0 AND NOT (COALESCE(po.state, '') IN ('part_received', 'closed'))) "
            . "    OR (received > 0 AND short = 0 AND NOT (COALESCE(po.state, '') IN ('received', 'closed'))) "
            . "    OR (po.state <=> 'sent' AND po.sent_at IS NULL) "
            . 'ORDER BY po.document_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = 'PO ' . ($r['number'] ?? 'document ' . $r['document_id']) . ' is ' . ($r['state'] ?? 'unposted') . " with {$r['received']} units received"
                . ((int) $r['short'] === 0 && (int) $r['received'] > 0 ? ' (every line complete)' : '') . ($r['state'] === 'sent' && $r['sent_at'] === null ? ' and no sent_at' : '');
        }
        return $v;
    }

    /** @return list<string> P6 */
    private static function totals(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT po.document_id, d.number, po.net_total, po.vat_total, po.gross_total, COALESCE(t.net, 0) AS net, COALESCE(t.vat, 0) AS vat FROM purchase_order po '
            . 'JOIN document d ON d.id = po.document_id LEFT JOIN ('
            . '  SELECT pl.document_id, SUM(dl.amount) AS net, SUM((CAST(ROUND(dl.amount * 100) AS SIGNED) * CAST(ROUND(pl.vat_rate * 100) AS SIGNED) + 5000) DIV 10000) / 100 AS vat '
            . '  FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id AND dl.line_no = pl.line_no GROUP BY pl.document_id) t '
            . '  ON t.document_id = po.document_id '
            . 'WHERE po.net_total <> COALESCE(t.net, 0) OR po.vat_total <> COALESCE(t.vat, 0) OR po.gross_total <> po.net_total + po.vat_total '
            . 'ORDER BY po.document_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = 'PO ' . ($r['number'] ?? 'document ' . $r['document_id']) . ": totals net {$r['net_total']} / VAT {$r['vat_total']} / gross {$r['gross_total']} "
                . "but its lines say net {$r['net']} / VAT {$r['vat']}";
        }
        return $v;
    }
}
