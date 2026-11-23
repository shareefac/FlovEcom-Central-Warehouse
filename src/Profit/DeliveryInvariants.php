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
 *  DB5. every bill of one carrier account names the same shop.
 *  DB6. a superseded bill was replaced by exactly one bill of the same carrier, account and number, uploaded after it; a bill names as
 *       replaced only a superseded one of its own key.
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
            . "LEFT JOIN carrier_invoice_line l ON l.invoice_id = i.id WHERE i.status = 'live' GROUP BY i.id, i.invoice_no, i.net_pence, i.line_count "
            . 'HAVING total <> i.net_pence OR n <> i.line_count ORDER BY i.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB3 bill {$r['id']} ({$r['invoice_no']}): its {$r['n']} lines add up to {$r['total']}p, it says {$r['line_count']} lines and {$r['net_pence']}p net";
        }
        foreach ($db->all("SELECT carrier, account, invoice_no, COUNT(*) AS n FROM carrier_invoice WHERE status = 'live' GROUP BY carrier, account, invoice_no "
            . 'HAVING n > 1 ORDER BY carrier, account, invoice_no LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB4 {$r['carrier']} account {$r['account']} bill {$r['invoice_no']}: {$r['n']} live bills";
        }
        foreach ($db->all('SELECT carrier, account, COUNT(DISTINCT channel_id) AS n FROM carrier_invoice GROUP BY carrier, account HAVING n > 1 '
            . 'ORDER BY carrier, account LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB5 {$r['carrier']} account {$r['account']}: its bills name {$r['n']} shops";
        }
        foreach ($db->all("SELECT o.id, o.status, COUNT(n.id) AS replaced_by, SUM(n.carrier <> o.carrier OR n.account <> o.account OR n.invoice_no <> o.invoice_no "
            . 'OR n.id < o.id) AS other '
            . 'FROM carrier_invoice o LEFT JOIN carrier_invoice n ON n.supersedes_id = o.id GROUP BY o.id, o.status '
            . "HAVING (o.status = 'superseded' AND replaced_by <> 1) OR (o.status <> 'superseded' AND replaced_by <> 0) OR COALESCE(other, 0) > 0 "
            . 'ORDER BY o.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "DB6 bill {$r['id']} ({$r['status']}): named as replaced by {$r['replaced_by']} bills" . ((int) ($r['other'] ?? 0) > 0 ? ', some of another bill or uploaded before it' : '');
        }
        return $v;
    }
}
