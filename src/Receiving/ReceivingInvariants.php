<?php

declare(strict_types=1);

namespace CW\Receiving;

use CW\Db;

/**
 * The nightly checks of goods receipts (0017; called at the end of CW\Invariants::check, so bin/invariants.php, the hammer and
 * every stock test run them; docs/decisions.md I142). Read-only; at most MAX_PER_CHECK violations per check.
 *
 *  G1. every non-reversal GRN document has a goods_receipt row and no other document has one; a live receipt (draft, waiting,
 *      posted) holds its invoice number as its key (ReceiptMath::invoiceKey of document.external_ref), a cancelled or reversed
 *      one none (the number is free again).
 *  G2. every line of a non-reversal GRN document has its grn_line, and every grn_line is on such a document.
 *  G3. every posted or reversed receipt has its write-once grn_posting anchor (and no other document has one); the anchor's content
 *      hashes to its content_hash; and the receipt's goods_receipt + grn_line content, recomputed (GoodsReceiptHandler::content, in
 *      batches of HASH_BATCH), equals it: a bench finding or a booked split changed after posting is found.
 *  G4. the stock of a posted (or reversed) receipt is what its lines say: per line, the goods_in rows of the document at MAIN,
 *      VERIFY and UNSTAMPED sum to its accepted, verify and quarantine units; every such row is a goods_in at the line's unit
 *      cost (cost source document), and none is elsewhere or without its line.
 *  G5. incidents: each names a line of a posted or reversed receipt and that line's item; an open one only on a posted receipt;
 *      its units and disposition are the line's (short, over, damaged, wrong item; unstamped when quarantined or refused; an
 *      unstamped delivery's damaged and over units where its unstamped ones went, I167); and every such exception of a posted or
 *      reversed line has its incident.
 *  G6. selling modes: every item_selling_mode row equals its newest log row (mode, previous mode, version), its log versions are
 *      1..n, and both name an existing item.
 *  G7. purchase-order receipts: a PO line that some receipt line receives has received_units = the units applied to it by the
 *      posted receipts (po_units), so a receipt's units reach its order exactly once (reversed receipts took theirs back).
 *  G8. a review task of a receipt (or of its reversal) was not decided by someone who did its goods-in bench check or set its
 *      supplier invoice without keying it (I172).
 */
final class ReceivingInvariants
{
    private const MAX_PER_CHECK = 50;
    public const HASH_BATCH = 500;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::headers($db), ...self::pairs($db), ...self::anchors($db), ...self::ledger($db), ...self::incidents($db), ...self::modes($db),
            ...self::receipts($db), ...self::reviews($db)];
    }

    /** @return list<string> G1 */
    private static function headers(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT d.id, d.doc_type, d.status, d.reverses_id, d.number, g.document_id AS g_id FROM document d LEFT JOIN goods_receipt g ON g.document_id = d.id '
            . "WHERE (d.doc_type = 'GRN' AND d.reverses_id IS NULL AND g.document_id IS NULL) OR (g.document_id IS NOT NULL AND (d.doc_type <> 'GRN' OR d.reverses_id IS NOT NULL)) "
            . 'ORDER BY d.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $label = 'document ' . $r['id'] . ' (' . ($r['number'] ?? $r['status']) . ')';
            $v[] = $r['g_id'] === null ? "GRN {$label} has no goods_receipt row" : "{$label} is a {$r['doc_type']}" . ($r['reverses_id'] !== null ? ' reversal' : '')
                . ' but has a goods_receipt row';
        }
        $after = 0;
        while (count($v) < self::MAX_PER_CHECK) {
            $rows = $db->all('SELECT d.id, d.number, d.status, d.external_ref, g.invoice_key FROM goods_receipt g JOIN document d ON d.id = g.document_id WHERE d.id > ? '
                . 'ORDER BY d.id LIMIT ' . self::HASH_BATCH, [$after]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $live = in_array($r['status'], ['draft', 'awaiting_approval', 'posted'], true);
                $want = $live ? ReceiptMath::invoiceKey($r['external_ref'] === null ? null : (string) $r['external_ref']) : '';
                $want = $want === '' ? null : $want;
                if ($want !== ($r['invoice_key'] === null ? null : (string) $r['invoice_key'])) {
                    $v[] = "receipt {$r['id']} (" . ($r['number'] ?? $r['status']) . '): its invoice key is ' . ($r['invoice_key'] ?? 'NULL') . ', not '
                        . ($want ?? 'NULL') . ($live ? ' (its supplier invoice number)' : ' (a cancelled or reversed receipt frees its number)');
                    if (count($v) >= self::MAX_PER_CHECK) {
                        break;
                    }
                }
            }
            $after = (int) end($rows)['id'];
        }
        return $v;
    }

    /** @return list<string> G2 */
    private static function pairs(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT dl.document_id, dl.line_no FROM document_line dl JOIN document d ON d.id = dl.document_id LEFT JOIN grn_line g ON g.document_id = dl.document_id '
            . "AND g.line_no = dl.line_no WHERE d.doc_type = 'GRN' AND d.reverses_id IS NULL AND g.document_id IS NULL ORDER BY dl.document_id, dl.line_no LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "receipt {$r['document_id']} line {$r['line_no']} has no grn_line";
        }
        foreach ($db->all(
            'SELECT g.document_id, g.line_no, d.doc_type FROM grn_line g JOIN document d ON d.id = g.document_id '
            . "WHERE d.doc_type <> 'GRN' OR d.reverses_id IS NOT NULL ORDER BY g.document_id, g.line_no LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "document {$r['document_id']} ({$r['doc_type']}) line {$r['line_no']} has a grn_line";
        }
        return $v;
    }

    /** @return list<string> G3 */
    private static function anchors(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT d.id, d.number, d.status, d.doc_type, d.reverses_id, p.document_id AS p_id FROM document d LEFT JOIN grn_posting p ON p.document_id = d.id '
            . "WHERE (d.doc_type = 'GRN' AND d.reverses_id IS NULL AND d.status IN ('posted', 'reversed') AND p.document_id IS NULL) "
            . "OR (p.document_id IS NOT NULL AND NOT (d.doc_type = 'GRN' AND d.reverses_id IS NULL AND d.status IN ('posted', 'reversed'))) "
            . 'ORDER BY d.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = $r['p_id'] === null ? "receipt {$r['id']} ({$r['number']}) is {$r['status']} but has no grn_posting anchor"
                : "document {$r['id']} (" . ($r['number'] ?? $r['status']) . ", {$r['doc_type']}) has a grn_posting anchor but is not a posted receipt";
        }
        foreach ($db->all('SELECT document_id FROM grn_posting WHERE SHA2(content, 256) <> content_hash ORDER BY document_id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "grn_posting of document {$r['document_id']}: its content does not hash to its content_hash";
        }
        $after = 0;
        while (count($v) < self::MAX_PER_CHECK) {
            $rows = $db->all('SELECT p.document_id, p.content, d.number FROM grn_posting p JOIN document d ON d.id = p.document_id WHERE p.document_id > ? '
                . 'ORDER BY p.document_id LIMIT ' . self::HASH_BATCH, [$after]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                if ($db->value('SELECT 1 FROM goods_receipt WHERE document_id = ?', [(int) $r['document_id']]) === null) {
                    continue; // G1 reports it
                }
                if (!hash_equals((string) $r['content'], GoodsReceiptHandler::content($db, (int) $r['document_id']))) {
                    $v[] = "receipt {$r['number']} (document {$r['document_id']}): its header, lines or bench findings changed after posting";
                    if (count($v) >= self::MAX_PER_CHECK) {
                        break;
                    }
                }
            }
            $after = (int) end($rows)['document_id'];
        }
        return $v;
    }

    /** @return list<string> G4 */
    private static function ledger(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT * FROM (SELECT g.document_id, g.line_no, d.number, w.code, '
            . "CASE w.code WHEN 'MAIN' THEN g.accepted_units WHEN 'VERIFY' THEN g.verify_units ELSE g.quarantine_units END AS expected, "
            . 'COALESCE((SELECT SUM(l.qty_delta) FROM stock_ledger l WHERE l.document_id = g.document_id AND l.document_line = g.line_no AND l.warehouse_id = w.id '
            . "AND l.bucket = 'on_hand'), 0) AS booked FROM grn_line g JOIN document d ON d.id = g.document_id AND d.doc_type = 'GRN' AND d.reverses_id IS NULL "
            . "AND d.status IN ('posted', 'reversed') JOIN warehouse w ON w.code IN ('MAIN', 'VERIFY', 'UNSTAMPED')) x "
            . 'WHERE NOT (x.expected <=> x.booked) ORDER BY x.document_id, x.line_no, x.code LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "receipt {$r['number']} line {$r['line_no']}: {$r['booked']} units booked at {$r['code']}, but the line says " . ($r['expected'] ?? 'NULL');
        }
        foreach ($db->all(
            'SELECT l.id, l.document_id, l.document_line, d.number, w.code, l.movement_type, l.unit_cost, l.cost_source, dl.unit_cost AS line_cost FROM stock_ledger l '
            . "JOIN document d ON d.id = l.document_id AND d.doc_type = 'GRN' AND d.reverses_id IS NULL JOIN warehouse w ON w.id = l.warehouse_id "
            . 'LEFT JOIN document_line dl ON dl.document_id = l.document_id AND dl.line_no = l.document_line '
            . "WHERE l.bucket <> 'on_hand' OR l.movement_type <> 'goods_in' OR dl.document_id IS NULL OR NOT (l.unit_cost <=> dl.unit_cost) "
            . "OR NOT (l.cost_source <=> 'document') OR w.code NOT IN ('MAIN', 'VERIFY', 'UNSTAMPED') ORDER BY l.id LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "receipt {$r['number']}: ledger row {$r['id']} ({$r['movement_type']} at {$r['code']}, line " . ($r['document_line'] ?? 'none') . ', cost '
                . ($r['unit_cost'] ?? 'none') . ' ' . ($r['cost_source'] ?? '') . ') is not a goods_in of a line at its unit cost (' . ($r['line_cost'] ?? 'no line') . ')';
        }
        return $v;
    }

    /** @return list<string> G5 */
    private static function incidents(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT i.id, i.kind, i.status, i.document_id, i.line_no, i.sku_id, i.units, i.disposition, w.code AS wh, d.status AS d_status, d.doc_type, d.reverses_id, '
            . 'dl.sku_id AS line_sku, g.short_units, g.over_units, g.damaged_units, g.wrong_item_units, g.unstamped_units, g.unstamped_action, g.stamp_required, '
            . 'g.stamp_on_pack, s.id AS s_id '
            . 'FROM incident i LEFT JOIN document d ON d.id = i.document_id LEFT JOIN document_line dl ON dl.document_id = i.document_id AND dl.line_no = i.line_no '
            . 'LEFT JOIN grn_line g ON g.document_id = i.document_id AND g.line_no = i.line_no LEFT JOIN warehouse w ON w.id = i.warehouse_id LEFT JOIN sku s ON s.id = i.sku_id '
            . "WHERE i.source = 'goods_receipt' ORDER BY i.id",
        ) as $r) {
            $label = "incident {$r['id']} ({$r['kind']})";
            // An unstamped delivery's damaged and over units follow its unstamped ones (ReceiptMath::extras, I167).
            $extras = ReceiptMath::extras((int) ($r['stamp_required'] ?? 0) === 1, $r['stamp_on_pack'] === null ? null : (int) $r['stamp_on_pack'],
                (int) $r['unstamped_units'] > 0 ? $r['unstamped_action'] : null);
            $extra = match ($extras) {
                'quarantine' => ['quarantine', 'UNSTAMPED'],
                'refuse' => ['refused', null],
                default => ['verify', 'VERIFY'],
            };
            $want = match ($r['kind']) {
                'short' => [(int) $r['short_units'], 'not_received', null],
                'over' => [(int) $r['over_units'], ...$extra],
                'damaged' => [(int) $r['damaged_units'], ...$extra],
                'wrong_item' => [(int) $r['wrong_item_units'], 'verify', 'VERIFY'],
                'unstamped' => [(int) $r['unstamped_units'], $r['unstamped_action'] === 'quarantine' ? 'quarantine' : 'refused', $r['unstamped_action'] === 'quarantine' ? 'UNSTAMPED' : null],
                default => [null, null, null],
            };
            $problem = match (true) {
                $r['doc_type'] !== 'GRN' || $r['reverses_id'] !== null || $r['line_sku'] === null => 'names no receipt line',
                !in_array($r['d_status'], ['posted', 'reversed'], true) => "is on a {$r['d_status']} receipt",
                $r['status'] === 'open' && $r['d_status'] !== 'posted' => 'is open on a reversed receipt',
                (int) $r['line_sku'] !== (int) $r['sku_id'] || $r['s_id'] === null => 'names another item than its line',
                $r['kind'] === 'unstamped' && !in_array($r['unstamped_action'], ['quarantine', 'refuse'], true) => 'is for unstamped units the line accepted',
                [(int) $r['units'], (string) $r['disposition'], $r['wh']] !== $want => "says {$r['units']} units {$r['disposition']}" . ($r['wh'] !== null ? " at {$r['wh']}" : '')
                    . ", the line says {$want[0]} {$want[1]}" . ($want[2] !== null ? " at {$want[2]}" : ''),
                default => null,
            };
            if ($problem !== null) {
                $v[] = "{$label} {$problem} (receipt {$r['document_id']} line {$r['line_no']})";
                if (count($v) >= self::MAX_PER_CHECK) {
                    return $v;
                }
            }
        }
        foreach ($db->all(
            "SELECT g.document_id, g.line_no, k.kind FROM grn_line g JOIN document d ON d.id = g.document_id AND d.doc_type = 'GRN' AND d.reverses_id IS NULL "
            . "AND d.status IN ('posted', 'reversed') JOIN (SELECT 'short' AS kind UNION ALL SELECT 'over' UNION ALL SELECT 'damaged' UNION ALL SELECT 'wrong_item' "
            . "UNION ALL SELECT 'unstamped') k ON (k.kind = 'short' AND g.short_units > 0) OR (k.kind = 'over' AND g.over_units > 0) OR (k.kind = 'damaged' AND g.damaged_units > 0) "
            . "OR (k.kind = 'wrong_item' AND g.wrong_item_units > 0) OR (k.kind = 'unstamped' AND g.unstamped_units > 0 AND g.unstamped_action IN ('quarantine', 'refuse')) "
            . 'LEFT JOIN incident i ON i.document_id = g.document_id AND i.line_no = g.line_no AND i.kind = k.kind WHERE i.id IS NULL '
            . 'ORDER BY g.document_id, g.line_no LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "receipt {$r['document_id']} line {$r['line_no']}: its {$r['kind']} units have no incident";
        }
        return $v;
    }

    /** @return list<string> G6 */
    private static function modes(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT m.sku_id, m.mode, m.previous_mode, m.version, l.mode_after, l.previous_mode AS l_previous, l.version AS l_version, s.id AS s_id FROM item_selling_mode m '
            . 'LEFT JOIN item_selling_mode_log l ON l.sku_id = m.sku_id AND l.version = (SELECT MAX(x.version) FROM item_selling_mode_log x WHERE x.sku_id = m.sku_id) '
            . 'LEFT JOIN sku s ON s.id = m.sku_id WHERE s.id IS NULL OR l.id IS NULL OR NOT (m.mode <=> l.mode_after) OR NOT (m.previous_mode <=> l.previous_mode) '
            . 'OR m.version <> l.version ORDER BY m.sku_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = $r['s_id'] === null ? "item_selling_mode of item {$r['sku_id']}: there is no such item"
                : "item_selling_mode of item {$r['sku_id']} ({$r['mode']}, previous " . ($r['previous_mode'] ?? 'none') . ", version {$r['version']}) is not its newest log row ("
                . ($r['mode_after'] ?? 'none') . ', previous ' . ($r['l_previous'] ?? 'none') . ', version ' . ($r['l_version'] ?? 'none') . ')';
        }
        foreach ($db->all(
            'SELECT l.sku_id, COUNT(*) AS n, MIN(l.version) AS lo, MAX(l.version) AS hi, MAX(m.sku_id IS NULL) AS orphan, MAX(s.id IS NULL) AS no_sku '
            . 'FROM item_selling_mode_log l LEFT JOIN item_selling_mode m ON m.sku_id = l.sku_id LEFT JOIN sku s ON s.id = l.sku_id GROUP BY l.sku_id '
            . 'HAVING n <> hi OR lo <> 1 OR orphan = 1 OR no_sku = 1 ORDER BY l.sku_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "item_selling_mode_log of item {$r['sku_id']}: versions {$r['lo']}..{$r['hi']} in {$r['n']} rows" . ((int) $r['orphan'] === 1 ? ', without its item_selling_mode row' : '')
                . ((int) $r['no_sku'] === 1 ? ', of an item that does not exist' : '');
        }
        return $v;
    }

    /** @return list<string> G7 */
    private static function receipts(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT * FROM (SELECT pl.document_id, pl.line_no, pl.received_units, p.number, '
            . "COALESCE((SELECT SUM(g.po_units) FROM grn_line g JOIN goods_receipt r ON r.document_id = g.document_id JOIN document d ON d.id = g.document_id "
            . "WHERE r.po_document_id = pl.document_id AND g.po_line_no = pl.line_no AND d.status = 'posted'), 0) AS applied FROM po_line pl JOIN document p ON p.id = pl.document_id "
            . 'WHERE EXISTS (SELECT 1 FROM grn_line g JOIN goods_receipt r ON r.document_id = g.document_id WHERE r.po_document_id = pl.document_id AND g.po_line_no = pl.line_no)) x '
            . 'WHERE x.received_units <> x.applied ORDER BY x.document_id, x.line_no LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "{$r['number']} line {$r['line_no']}: {$r['received_units']} units received, but its posted receipts applied {$r['applied']}";
        }
        return $v;
    }

    /** @return list<string> G8 */
    private static function reviews(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            "SELECT t.id, t.decided_by, d.id AS d_id, d.number FROM review_task t JOIN document d ON d.id = t.subject_id WHERE t.subject_type = 'document' "
            . "AND d.doc_type = 'GRN' AND t.decided_by IS NOT NULL AND EXISTS (SELECT 1 FROM audit_log a WHERE a.entity_type = 'document' "
            . "AND a.entity_id = CAST(COALESCE(d.reverses_id, d.id) AS CHAR) AND a.action IN ('grn.bench', 'grn.invoice') AND a.staff_user_id = t.decided_by) "
            . 'ORDER BY t.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "review task {$r['id']} of receipt {$r['d_id']} (" . ($r['number'] ?? 'unnumbered') . ") was decided by staff {$r['decided_by']}, who did its bench "
                . 'check or set its supplier invoice';
        }
        return $v;
    }
}
