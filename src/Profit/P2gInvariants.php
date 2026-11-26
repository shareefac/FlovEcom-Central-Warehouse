<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * The nightly checks of Parcel2Go's money (0085; docs/decisions.md PGL10; called by DeliveryInvariants::check, so bin/invariants.php and every
 * stock test run them). Read only; at most $max findings per check. DB7 (a bill's rows add up to its net) and DB8 (a row's live allocations
 * add up to its net) hold for Parcel2Go's bills as DeliveryInvariants checks them; these are Parcel2Go's own:
 *  DB13. every row of a live Parcel2Go bill names exactly one label (p2g_charge) of the bill's own account.
 *  DB14. a label holds at most one live price row; a label priced names a live bill that prices it.
 *  DB15 is a LOOK, not a failing check (looks(); the small round, G5): a CW parcel with Parcel2Go money and another carrier account's live cost
 *     (a DPD line, another account's allocation) keeps both by decision (DPD and Royal Mail match unchanged): bin/invariants.php says it and
 *     stays exit 0 (the nightly; install_cron's smoke), and the health alert p2g_needs_look names the orders.
 *  DB16. Parcel2Go money on an order is on a parcel pinned to the label its row names.
 *  DB17. no order's live Parcel2Go money is below 0.
 *  DB18. no live Parcel2Go row names a PrePay top-up.
 */
final class P2gInvariants
{
    /** @return list<string> */
    public static function check(Db $db, int $max = 50): array
    {
        $v = [];
        foreach ($db->all("SELECT r.invoice_id, r.row_no, COUNT(c.row_no) AS n, SUM(ca.account_no <> i.account) AS other FROM carrier_bill_row r JOIN carrier_invoice i ON i.id = r.invoice_id "
            . "AND i.status = 'live' AND i.carrier = 'parcel2go' LEFT JOIN p2g_charge c ON c.invoice_id = r.invoice_id AND c.row_no = r.row_no LEFT JOIN carrier_account ca "
            . 'ON ca.id = c.account_id GROUP BY r.invoice_id, r.row_no HAVING n <> 1 OR COALESCE(other, 0) > 0 ORDER BY r.invoice_id, r.row_no LIMIT ' . $max) as $r) {
            $v[] = "DB13 Parcel2Go bill {$r['invoice_id']} row {$r['row_no']}: it names {$r['n']} labels" . ((int) ($r['other'] ?? 0) > 0 ? ', of another account' : '');
        }
        foreach ($db->all("SELECT c.account_id, c.order_line_id, COUNT(*) AS n FROM p2g_charge c JOIN carrier_invoice i ON i.id = c.invoice_id AND i.status = 'live' "
            . "WHERE c.kind = 'price' GROUP BY c.account_id, c.order_line_id HAVING n > 1 ORDER BY c.account_id, c.order_line_id LIMIT " . $max) as $r) {
            $v[] = "DB14 Parcel2Go label P2G{$r['order_line_id']}: {$r['n']} live prices";
        }
        foreach ($db->all("SELECT l.account_id, l.order_line_id FROM p2g_line l LEFT JOIN carrier_invoice i ON i.id = l.invoice_id AND i.status = 'live' LEFT JOIN p2g_charge c "
            . "ON c.invoice_id = l.invoice_id AND c.account_id = l.account_id AND c.order_line_id = l.order_line_id AND c.kind = 'price' WHERE l.price_state = 'priced' "
            . 'AND (i.id IS NULL OR c.row_no IS NULL) ORDER BY l.account_id, l.order_line_id LIMIT ' . $max) as $r) {
            $v[] = "DB14 Parcel2Go label P2G{$r['order_line_id']}: priced, but no live bill prices it";
        }
        foreach ($db->all("SELECT a.id FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' AND i.carrier = 'parcel2go' "
            . 'JOIN p2g_charge c ON c.invoice_id = a.invoice_id AND c.row_no = a.row_no WHERE a.ended_at IS NULL AND a.dest = \'order\' AND NOT EXISTS (SELECT 1 FROM p2g_parcel p '
            . 'WHERE p.account_id = c.account_id AND p.order_line_id = c.order_line_id AND p.cw_channel_id = a.channel_id AND p.cw_order_ref = a.order_ref '
            . 'AND p.cw_parcel_ref = a.parcel_ref) ORDER BY a.id LIMIT ' . $max) as $r) {
            $v[] = "DB16 allocation {$r['id']}: Parcel2Go money on an order parcel not pinned to its label";
        }
        foreach ($db->all("SELECT a.channel_id, a.order_ref, SUM(a.pence) AS p FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' "
            . "AND i.carrier = 'parcel2go' WHERE a.ended_at IS NULL AND a.dest = 'order' GROUP BY a.channel_id, a.order_ref HAVING p < 0 ORDER BY a.channel_id, a.order_ref LIMIT "
            . $max) as $r) {
            $v[] = "DB17 order {$r['order_ref']} (shop {$r['channel_id']}): its Parcel2Go money is {$r['p']}p, below 0";
        }
        foreach ($db->all("SELECT c.invoice_id, c.row_no FROM p2g_charge c JOIN carrier_invoice i ON i.id = c.invoice_id AND i.status = 'live' JOIN p2g_line l "
            . "ON l.account_id = c.account_id AND l.order_line_id = c.order_line_id WHERE l.kind = 'topup' ORDER BY c.invoice_id, c.row_no LIMIT " . $max) as $r) {
            $v[] = "DB18 Parcel2Go bill {$r['invoice_id']} row {$r['row_no']}: it names a PrePay top-up";
        }
        return $v;
    }

    /**
     * The lines to LOOK at (said by bin/invariants.php, never a failure): DB15, a CW parcel with Parcel2Go money and another carrier account's
     * cost (both kept; Parcel2Go::health p2g_needs_look names the orders).
     *
     * @return list<string>
     */
    public static function looks(Db $db, int $max = 50): array
    {
        if ($db->value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'p2g_charge'") === null) {
            return [];
        }
        $v = [];
        foreach ($db->all("SELECT DISTINCT a.channel_id, a.order_ref, a.parcel_ref FROM carrier_allocation a JOIN carrier_invoice i ON i.id = a.invoice_id AND i.status = 'live' "
            . "AND i.carrier = 'parcel2go' WHERE a.ended_at IS NULL AND a.dest = 'order' AND (EXISTS (SELECT 1 FROM carrier_allocation b JOIN carrier_invoice j ON j.id = b.invoice_id "
            . "AND j.status = 'live' WHERE b.ended_at IS NULL AND b.dest = 'order' AND b.channel_id = a.channel_id AND b.order_ref = a.order_ref AND b.parcel_ref = a.parcel_ref "
            . "AND NOT (j.carrier = i.carrier AND j.account = i.account)) OR EXISTS (SELECT 1 FROM carrier_invoice_line l JOIN carrier_invoice k ON k.id = l.invoice_id "
            . "AND k.status = 'live' WHERE l.match_state = 'matched' AND l.channel_id = a.channel_id AND l.order_ref = a.order_ref AND l.parcel_ref = a.parcel_ref)) "
            . 'ORDER BY a.channel_id, a.order_ref, a.parcel_ref LIMIT ' . $max) as $r) {
            $v[] = "DB15 parcel {$r['parcel_ref']} of order {$r['order_ref']} (shop {$r['channel_id']}): Parcel2Go money and another carrier account's cost";
        }
        return $v;
    }
}
