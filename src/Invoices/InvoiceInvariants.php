<?php

declare(strict_types=1);

namespace CW\Invoices;

use CW\Db;

/**
 * The nightly checks of supplier invoices, credit notes and returns (pack C; called from CW\Invariants::check, so bin/invariants.php and
 * every stock test run them). Read-only; at most MAX_PER_CHECK violations per check.
 *
 *  V1. every SINV / SCN document that is not a reversal has its supplier_invoice row of the right kind, every DN its supplier_return row;
 *      and every such row belongs to a document of that type.
 *  V2. every posted or reversed SINV, SCN and DN (not a reversal) has its write-once purchase_doc_posting anchor, whose content hashes to
 *      its content_hash and equals the content recomputed now (a fixed field changed after posting is found); no unposted one has one.
 *  V3. a record's invoice key is its number's key while the record is live (draft, waiting, posted), and NULL once cancelled or reversed.
 *  V4. every posted invoice has a stored match, and it is what the match is now with the tolerances it was worked out with (a change
 *      that forgot to work the match out again is found; `accepted` only for exactly the lines and figures accepted), and a posted
 *      invoice keeps a person's acceptance only while it is `accepted`; nothing else has a match (the newest MATCH_LIMIT records).
 *  V5. the value-only events of a reversed record and of its reversal net to zero, line by line.
 *  V6. a posted return's claim is the sum of its lines' amounts; an unposted one has none.
 *  V7. what grn_invoice_left stores for a posted delivery is what it has left to invoice now (the newest MATCH_LIMIT rows), and every
 *      posted delivery a posted or reversed invoice, credit note or return names has its row (PC18).
 */
final class InvoiceInvariants
{
    private const MAX_PER_CHECK = 50;
    /** V4 works out again the matches of the newest this many invoices and credit notes. */
    public const MATCH_LIMIT = 2000;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        return [...self::headers($db), ...self::anchors($db), ...self::keys($db), ...self::matches($db), ...self::events($db), ...self::claims($db),
            ...self::deliveries($db)];
    }

    /** @return list<string> V1 */
    private static function headers(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            "SELECT d.id, d.doc_type, si.kind, r.document_id AS dn FROM document d LEFT JOIN supplier_invoice si ON si.document_id = d.id "
            . "LEFT JOIN supplier_return r ON r.document_id = d.id WHERE d.doc_type IN ('SINV', 'SCN', 'DN') AND d.reverses_id IS NULL "
            . "AND NOT ((d.doc_type = 'SINV' AND si.kind <=> 'invoice') OR (d.doc_type = 'SCN' AND si.kind <=> 'credit_note') OR (d.doc_type = 'DN' AND r.document_id IS NOT NULL)) "
            . 'ORDER BY d.id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "V1 {$r['doc_type']} document {$r['id']} has no header of its kind";
        }
        foreach ($db->all(
            "SELECT si.document_id, si.kind, d.doc_type FROM supplier_invoice si JOIN document d ON d.id = si.document_id WHERE NOT ((si.kind = 'invoice' AND d.doc_type = 'SINV') "
            . "OR (si.kind = 'credit_note' AND d.doc_type = 'SCN')) OR d.reverses_id IS NOT NULL LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "V1 supplier_invoice {$r['document_id']} ({$r['kind']}) is on a {$r['doc_type']} document or a reversal";
        }
        foreach ($db->all("SELECT r.document_id, d.doc_type FROM supplier_return r JOIN document d ON d.id = r.document_id WHERE d.doc_type <> 'DN' OR d.reverses_id IS NOT NULL LIMIT "
            . self::MAX_PER_CHECK) as $r) {
            $v[] = "V1 supplier_return {$r['document_id']} is on a {$r['doc_type']} document or a reversal";
        }
        return $v;
    }

    /** @return list<string> V2 */
    private static function anchors(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            "SELECT d.id, d.doc_type, d.status, p.content_hash, p.content FROM document d LEFT JOIN purchase_doc_posting p ON p.document_id = d.id "
            . "WHERE d.doc_type IN ('SINV', 'SCN', 'DN') AND d.reverses_id IS NULL AND (d.status IN ('posted', 'reversed') OR p.document_id IS NOT NULL) ORDER BY d.id",
        ) as $r) {
            $posted = in_array($r['status'], ['posted', 'reversed'], true);
            if (!$posted) {
                $v[] = "V2 {$r['doc_type']} document {$r['id']} is {$r['status']} but has a posting anchor";
            } elseif ($r['content'] === null) {
                $v[] = "V2 {$r['doc_type']} document {$r['id']} is {$r['status']} without its posting anchor";
            } elseif (hash('sha256', (string) $r['content']) !== $r['content_hash']) {
                $v[] = "V2 {$r['doc_type']} document {$r['id']}: its posting anchor does not hash to its content_hash";
            } else {
                $now = $r['doc_type'] === 'DN' ? SupplierReturnHandler::content($db, (int) $r['id']) : SupplierInvoiceHandler::content($db, (int) $r['id']);
                if ($now !== (string) $r['content']) {
                    $v[] = "V2 {$r['doc_type']} document {$r['id']}: its module content changed after posting";
                }
            }
            if (count($v) >= self::MAX_PER_CHECK) {
                break;
            }
        }
        return $v;
    }

    /** @return list<string> V3 */
    private static function keys(Db $db): array
    {
        $v = [];
        foreach ($db->all('SELECT si.document_id, si.invoice_key, d.status, d.external_ref FROM supplier_invoice si JOIN document d ON d.id = si.document_id ORDER BY si.document_id') as $r) {
            $live = in_array($r['status'], ['draft', 'awaiting_approval', 'posted'], true);
            $want = $live ? SupplierInvoices::key($r['external_ref'] === null ? null : (string) $r['external_ref']) : null;
            if ($want !== ($r['invoice_key'] === null ? null : (string) $r['invoice_key'])) {
                $v[] = "V3 supplier_invoice {$r['document_id']} ({$r['status']}): invoice_key " . ($r['invoice_key'] ?? 'NULL') . ', expected ' . ($want ?? 'NULL');
                if (count($v) >= self::MAX_PER_CHECK) {
                    break;
                }
            }
        }
        return $v;
    }

    /** @return list<string> V4 */
    private static function matches(Db $db): array
    {
        $v = [];
        $match = new InvoiceMatch($db);
        // The newest MATCH_LIMIT records (a night's check stays bounded as invoices pile up; an older invoice's match is worked out
        // again whenever something it reads changes).
        foreach ($db->all('SELECT si.*, d.status FROM supplier_invoice si JOIN document d ON d.id = si.document_id ORDER BY si.document_id DESC LIMIT ' . self::MATCH_LIMIT) as $r) {
            $should = $r['kind'] === 'invoice' && $r['status'] === 'posted';
            if (!$should) {
                if ($r['match_state'] !== null) {
                    $v[] = "V4 supplier_invoice {$r['document_id']} ({$r['kind']}, {$r['status']}) has a stored match";
                }
            } elseif ($r['match_state'] === null || $r['match_rules'] === null) {
                $v[] = "V4 posted invoice {$r['document_id']} has no stored match";
            } else {
                $rules = json_decode((string) $r['match_rules'], true);
                if (!is_array($rules)) {
                    $v[] = "V4 posted invoice {$r['document_id']}: its match_rules are not JSON";
                    continue;
                }
                $m = $match->compute((int) $r['document_id'], ['price_pct' => (string) ($rules['price_pct'] ?? '0'), 'price_gbp' => (string) ($rules['price_gbp'] ?? '0'),
                    'qty_pct' => (string) ($rules['qty_pct'] ?? '0'), 'total_per_line' => (string) ($rules['total_per_line'] ?? '0'),
                    // PC19: a match stored before 0040 has no check_price: it was worked out with the price checked.
                    'check_price' => (string) ($rules['check_price'] ?? '1')]);
                $state = InvoiceMatch::stored($m, $r);
                if ($r['accepted_at'] !== null && $r['match_state'] !== 'accepted') {
                    $v[] = "V4 posted invoice {$r['document_id']} keeps an acceptance its match ({$r['match_state']}) no longer is";
                }
                if ($state !== $r['match_state'] || $m['lines_off'] !== (int) $r['match_lines_off'] || $m['difference_e2'] !== InvoiceMath::e2((string) $r['match_difference'])) {
                    $v[] = "V4 posted invoice {$r['document_id']}: stored match {$r['match_state']} ({$r['match_lines_off']} off, {$r['match_difference']}) but it is {$state} "
                        . "({$m['lines_off']} off, " . InvoiceMath::fromE2($m['difference_e2']) . ') now';
                }
            }
            if (count($v) >= self::MAX_PER_CHECK) {
                break;
            }
        }
        return $v;
    }

    /** @return list<string> V5 */
    private static function events(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT o.id, o.number, e.line_no, e.kind, e.sku_id, SUM(e.amount) AS net FROM document o JOIN document r ON r.reverses_id = o.id AND r.status = \'posted\' '
            . "JOIN invoice_value_event e ON e.document_id IN (o.id, r.id) WHERE o.doc_type IN ('SINV', 'SCN') AND o.status = 'reversed' "
            . 'GROUP BY o.id, o.number, e.line_no, e.kind, e.sku_id HAVING SUM(e.amount) <> 0 LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "V5 {$r['number']} line {$r['line_no']} ({$r['kind']}, item {$r['sku_id']}): its value events and its reversal's net to {$r['net']}, not 0";
        }
        return $v;
    }

    /** @return list<string> V6 */
    private static function claims(Db $db): array
    {
        $v = [];
        foreach ($db->all(
            'SELECT r.document_id, d.status, r.claim_total, (SELECT COALESCE(SUM(l.amount), 0) FROM document_line l WHERE l.document_id = r.document_id) AS lines_total '
            . 'FROM supplier_return r JOIN document d ON d.id = r.document_id '
            . "WHERE (d.status IN ('posted', 'reversed') AND NOT (r.claim_total <=> ROUND((SELECT COALESCE(SUM(l.amount), 0) FROM document_line l WHERE l.document_id = r.document_id), 2))) "
            . "OR (d.status NOT IN ('posted', 'reversed') AND r.claim_total IS NOT NULL) LIMIT " . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "V6 return {$r['document_id']} ({$r['status']}): claim " . ($r['claim_total'] ?? 'NULL') . ", its lines add up to {$r['lines_total']}";
        }
        return $v;
    }

    /** @return list<string> V7 */
    private static function deliveries(Db $db): array
    {
        $v = [];
        $rows = [];
        foreach ($db->all("SELECT gl.grn_document_id, gl.units_left, gl.value_left FROM grn_invoice_left gl JOIN document d ON d.id = gl.grn_document_id AND d.status = 'posted' "
            . 'ORDER BY gl.grn_document_id DESC LIMIT ' . self::MATCH_LIMIT) as $r) {
            $rows[(int) $r['grn_document_id']] = $r;
        }
        $left = InvoiceMatch::leftByReceipt((new InvoiceMatch($db))->receiptFacts(array_keys($rows)));
        foreach ($rows as $id => $r) {
            [$units, $e2] = $left[$id] ?? [0, 0];
            if ($units !== (int) $r['units_left'] || $e2 !== InvoiceMath::e2((string) $r['value_left'])) {
                $v[] = "V7 delivery {$id}: stores {$r['units_left']} units ({$r['value_left']}) left to invoice, but it has {$units} (" . InvoiceMath::fromE2($e2) . ') now';
                if (count($v) >= self::MAX_PER_CHECK) {
                    return $v;
                }
            }
        }
        foreach ($db->all(
            "SELECT x.grn_document_id FROM (SELECT sl.grn_document_id FROM sinv_line sl JOIN document i ON i.id = sl.document_id AND i.status IN ('posted', 'reversed') "
            . "WHERE sl.grn_document_id IS NOT NULL UNION SELECT n.grn_document_id FROM dn_line n JOIN document r ON r.id = n.document_id AND r.status IN ('posted', 'reversed') "
            . "WHERE n.grn_document_id IS NOT NULL) x JOIN document g ON g.id = x.grn_document_id AND g.status = 'posted' "
            . 'LEFT JOIN grn_invoice_left gl ON gl.grn_document_id = x.grn_document_id WHERE gl.grn_document_id IS NULL ORDER BY x.grn_document_id LIMIT ' . self::MAX_PER_CHECK,
        ) as $r) {
            $v[] = "V7 delivery {$r['grn_document_id']} is named by an invoice, credit note or return but has no stored figures left to invoice";
        }
        return $v;
    }
}
