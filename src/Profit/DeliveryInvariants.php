<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * The nightly checks of the delivery bills (0077; docs/decisions.md DL20; called by CW\Invariants::check, so bin/invariants.php and every
 * stock test run them). Read-only; at most MAX_PER_CHECK findings per check. The day figures they feed are held to their lines by the
 * rebuild check (bin/rebuild_profit_days.php, PR104: ProfitDays::compute works them out from scratch).
 *
 *  DB1. a bill line is in exactly one state, with what that state points at and nothing else (matched: a parcel and its order; collection:
 *       an order; before_start, not_matched: nothing; ambiguous: nothing, 2 or more candidates).
 *  DB2. a matched line points at a parcel CW holds (kept even when a later snapshot removed it), a collection line at an order CW holds.
 *  DB3. per live bill, its lines add up to its net exactly, and there are as many as it says.
 *  DB4. no two live bills of the same carrier, account and number.
 *  DB5. every bill of one carrier account that is not undone names the same shop (an undone bill is no evidence of its shop, DL8).
 *  DB6. a superseded bill was replaced by exactly one bill of the same carrier, account and number, uploaded after it; a bill names as
 *       replaced only a superseded one of its own key.
 * The pooled bills (Royal Mail, 0078; docs/decisions.md DL67): DB3 is the per-parcel carriers' (a pooled bill has rows, not lines), and
 *  DB7. per live pooled bill, its rows add up to its net exactly, and there are as many as it says.
 *  DB8. per row of a live pooled bill, its live allocations add up to its net exactly (every penny somewhere, none twice).
 *  DB9. a superseded or undone bill has no live allocation (undo and replace end them right after, RoyalMailBills::sweep()).
 *  DB10. an allocation's label is a label of its bill's own carrier account (a parcel of another account never takes its money).
 *  DB11. a flat label holds one live price at most across the rows of its day (the day book puts every label on one row).
 *  DB12. no pooled bill is still "loading" 10 minutes after its upload (its write did not end; the next bill write or the nightly sweeps it).
 * Parcel2Go (per_charge, 0085): DB7 and DB8 hold for its bills too, and P2gInvariants' DB13-DB18.
 * Their cost (the Codex check r1, LOW 2): DB1, DB2 and DB3 read every line (DPD: about 10,000 a month, 120,000 a year: a second or two a
 * night); a carrier with a line per parcel at ten times that would want them by the bills uploaded since the last run.
 */
final class DeliveryInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT invoice_id, line_no, match_state FROM carrier_invoice_line WHERE NOT ("
            . "(match_state = 'matched' AND channel_id IS NOT NULL AND order_ref IS NOT NULL AND parcel_ref IS NOT NULL AND candidates = 0 AND matched_at IS NOT NULL) "
            . "OR (match_state = 'collection' AND channel_id IS NOT NULL AND order_ref IS NOT NULL AND parcel_ref IS NULL AND candidates = 0 AND matched_at IS NOT NULL) "
            . "OR (match_state = 'ambiguous' AND channel_id IS NULL AND order_ref IS NULL AND parcel_ref IS NULL AND candidates >= 2 AND matched_at IS NULL) "
            . "OR (match_state IN ('before_start', 'not_matched') AND channel_id IS NULL AND order_ref IS NULL AND parcel_ref IS NULL AND candidates = 0 "
            . 'AND matched_at IS NULL)) '
            . 'ORDER BY invoice_id, line_no LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB1 bill {$r['invoice_id']} line {$r['line_no']}: state {$r['match_state']} does not point at what that state points at";
        }
        foreach ($db->all("SELECT l.invoice_id, l.line_no, l.match_state FROM carrier_invoice_line l "
            . 'LEFT JOIN sale_parcel p ON p.channel_id = l.channel_id AND p.order_ref = l.order_ref AND p.parcel_ref = l.parcel_ref '
            . 'LEFT JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
            . "WHERE (l.match_state = 'matched' AND p.parcel_ref IS NULL) OR (l.match_state = 'collection' AND o.order_ref IS NULL) "
            . 'ORDER BY l.invoice_id, l.line_no LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB2 bill {$r['invoice_id']} line {$r['line_no']}: {$r['match_state']}, but its " . ($r['match_state'] === 'matched' ? 'parcel' : 'order')
                . ' is not in CW';
        }
        foreach ($db->all("SELECT i.id, i.invoice_no, i.net_pence, i.line_count, COALESCE(SUM(l.cost), 0) AS total, COUNT(l.line_no) AS n FROM carrier_invoice i "
            . "LEFT JOIN carrier_invoice_line l ON l.invoice_id = i.id WHERE i.status = 'live' AND i.carrier IN (SELECT code FROM carrier WHERE family = 'per_parcel') "
            . 'GROUP BY i.id, i.invoice_no, i.net_pence, i.line_count '
            . 'HAVING total <> i.net_pence OR n <> i.line_count ORDER BY i.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB3 bill {$r['id']} ({$r['invoice_no']}): its {$r['n']} lines add up to {$r['total']}p, it says {$r['line_count']} lines and {$r['net_pence']}p net";
        }
        foreach ($db->all("SELECT carrier, account, invoice_no, COUNT(*) AS n FROM carrier_invoice WHERE status = 'live' GROUP BY carrier, account, invoice_no "
            . 'HAVING n > 1 ORDER BY carrier, account, invoice_no LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB4 {$r['carrier']} account {$r['account']} bill {$r['invoice_no']}: {$r['n']} live bills";
        }
        foreach ($db->all("SELECT carrier, account, COUNT(DISTINCT channel_id) AS n FROM carrier_invoice WHERE status <> 'undone' GROUP BY carrier, account HAVING n > 1 "
            . 'ORDER BY carrier, account LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB5 {$r['carrier']} account {$r['account']}: its bills name {$r['n']} shops";
        }
        foreach ($db->all("SELECT o.id, o.status, COUNT(n.id) AS replaced_by, SUM(n.carrier <> o.carrier OR n.account <> o.account OR n.invoice_no <> o.invoice_no "
            . 'OR n.id <= o.id) AS other '
            // a pooled bill still loading, or one whose write never finished (undone as not stored), names its bill only as about to replace it (DL68)
            . "FROM carrier_invoice o LEFT JOIN carrier_invoice n ON n.supersedes_id = o.id AND n.status <> 'loading' AND NOT (n.status = 'undone' AND n.ended_actor = 'system:not_stored') "
            . 'GROUP BY o.id, o.status '
            . "HAVING (o.status = 'superseded' AND replaced_by <> 1) OR (o.status <> 'superseded' AND replaced_by <> 0) OR COALESCE(other, 0) > 0 "
            . 'ORDER BY o.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB6 bill {$r['id']} ({$r['status']}): named as replaced by {$r['replaced_by']} bills" . ((int) ($r['other'] ?? 0) > 0 ? ', some of another bill or uploaded before it' : '');
        }
        foreach ($db->all("SELECT i.id, i.invoice_no, i.net_pence, i.line_count, COALESCE(SUM(r.net_pence), 0) AS total, COUNT(r.row_no) AS n FROM carrier_invoice i "
            . "LEFT JOIN carrier_bill_row r ON r.invoice_id = i.id WHERE i.status = 'live' AND i.carrier IN (SELECT code FROM carrier WHERE family IN ('pooled', 'per_charge')) "
            . 'GROUP BY i.id, i.invoice_no, i.net_pence, i.line_count HAVING total <> i.net_pence OR n <> i.line_count ORDER BY i.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB7 bill {$r['id']} ({$r['invoice_no']}): its {$r['n']} rows add up to {$r['total']}p, it says {$r['line_count']} rows and {$r['net_pence']}p net";
        }
        foreach ($db->all("SELECT r.invoice_id, r.row_no, r.net_pence, COALESCE(SUM(a.pence), 0) AS total FROM carrier_bill_row r JOIN carrier_invoice i ON i.id = r.invoice_id "
            . "AND i.status = 'live' LEFT JOIN carrier_allocation a ON a.invoice_id = r.invoice_id AND a.row_no = r.row_no AND a.ended_at IS NULL "
            . 'GROUP BY r.invoice_id, r.row_no, r.net_pence HAVING total <> r.net_pence ORDER BY r.invoice_id, r.row_no LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB8 bill {$r['invoice_id']} row {$r['row_no']}: its live allocations add up to {$r['total']}p, the row's net is {$r['net_pence']}p";
        }
        foreach ($db->all("SELECT i.id, i.status, COUNT(*) AS n FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id WHERE a.ended_at IS NULL "
            . "AND i.status IN ('superseded', 'undone') GROUP BY i.id, i.status ORDER BY i.id LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB9 bill {$r['id']} ({$r['status']}): {$r['n']} allocations still live";
        }
        foreach ($db->all("SELECT id, invoice_no FROM carrier_invoice WHERE status = 'loading' AND uploaded_at < UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE ORDER BY id LIMIT "
            . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB12 bill {$r['id']} ({$r['invoice_no']}): still loading 10 minutes after its upload (a write that did not end: the next bill write or the nightly undoes it)";
        }
        foreach ($db->all('SELECT a.id FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id JOIN carrier_label l ON l.id = a.label_id '
            . 'JOIN carrier_account ca ON ca.id = l.account_id WHERE a.ended_at IS NULL AND (ca.carrier <> i.carrier OR ca.account_no <> i.account) '
            . 'ORDER BY a.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB10 allocation {$r['id']}: its label is of another carrier account than its bill";
        }
        foreach ($db->all("SELECT a.label_id, COUNT(*) AS n FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' "
            . "WHERE a.ended_at IS NULL AND a.kind = 'label' AND a.how = 'flat' GROUP BY a.label_id HAVING n > 1 ORDER BY a.label_id LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB11 label {$r['label_id']}: {$r['n']} live day prices";
        }
        // Parcel2Go (0085, docs/decisions.md PGL10); a schema before 0085 has none of these tables
        if ($db->value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'p2g_charge'") !== null) {
            array_push($v, ...P2gInvariants::check($db, self::MAX_PER_CHECK));
        }
        return $v;
    }
}
