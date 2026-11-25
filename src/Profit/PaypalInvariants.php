<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * The nightly checks of PayPal's rows (0080; docs/decisions.md PPF18; called by CW\Invariants::check, so bin/invariants.php and every stock
 * test run them). Read-only; at most MAX_PER_CHECK findings per check. The day figures they feed are held to their rows by the rebuild check
 * (bin/rebuild_profit_days.php: ProfitDays::compute works fee_cost and fee_other out from scratch with PaypalFees::dayFigures).
 *
 *  PP1. a decided row (not due) counts its whole cost in exactly one place, or nowhere: counts with a cost not 0 = one place holding exactly
 *       it (an order, or the shop's "not on an order" line); a chargeback whose MONEY is held for a look (its order's refunds not on PayPal, its
 *       number of two orders: PaypalFees::HELD) = one place holding exactly its FEE, its money nowhere (H4, H5, round 4: both parts reconciled
 *       on their own); anything else = no place and 0 (every penny once, none twice); no refund offset on any row (PPF43: none is ever made;
 *       M4, round 4).
 *  PP2. a decided row counts only in its own shop (the shop of its invoice's form; with no shop's form, the account's main shop: never another
 *       shop's line, PPF21); on an order: one of that shop's orders that carries its number (never another number's), a sale's fee on the
 *       order's paid day (no day of its own), every other kind on its own UK day; on the shop's line: on its own day.
 *  PP3. kind and cost are what the event code and the amounts say (PaypalCodes): a stored row never disagrees with the rules it was read by.
 *  PP4. no row is due for more than STALE hours since it became due (due_since: a run that stopped half-way is finished by the next one).
 *  PP5. no run is still "running" STALE hours after it started (one that died: its rows stay due for the next one).
 */
final class PaypalInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $v = [];
        // decided rows only (a row due keeps its old place against its new facts until decided: PP4 times it; final review c4)
        // the part of a row that counts: its cost when it counts; its fee alone when it is a chargeback whose money is held; else nothing
        $part = "(CASE WHEN count_state = 'counts' THEN cost_pence WHEN count_state = 'look' AND kind = 'chargeback' AND look_reason IN ('"
            . implode("', '", PaypalFees::HELD) . "') THEN -fee_pence ELSE 0 END)";
        foreach ($db->all("SELECT id, count_state, cost_pence, alloc_dest, alloc_pence FROM paypal_txn WHERE due = 0 AND (NOT ("
            . "({$part} <> 0 AND alloc_dest IS NOT NULL AND alloc_pence = {$part}) OR ({$part} = 0 AND alloc_dest IS NULL AND alloc_pence = 0)) "
            . "OR refund_offset_pence <> 0) ORDER BY id LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "PP1 PayPal row {$r['id']}: {$r['count_state']} costing {$r['cost_pence']}p, counted " . ($r['alloc_dest'] === null ? 'nowhere' : "on its {$r['alloc_dest']}")
                . " as {$r['alloc_pence']}p";
        }
        foreach ($db->all("SELECT t.id, t.kind, t.alloc_dest FROM paypal_txn t LEFT JOIN sale_order o ON o.channel_id = t.alloc_channel AND o.order_ref = t.alloc_order_ref "
            . "WHERE t.due = 0 AND ((t.alloc_dest IS NOT NULL AND (t.shop_channel IS NULL OR t.alloc_channel <> t.shop_channel OR (t.form_shop IS NULL AND t.shop_channel <> t.channel_id))) "
            . "OR (t.alloc_dest = 'order' AND (o.order_ref IS NULL OR o.order_no <> t.order_no OR t.order_no IS NULL OR t.alloc_order_ref <> t.match_order_ref "
            . "OR (t.kind = 'sale') <> (t.alloc_day IS NULL) OR (t.alloc_day IS NOT NULL AND t.alloc_day <> t.day))) "
            . "OR (t.alloc_dest = 'shop' AND t.alloc_day <> t.day)) ORDER BY t.id LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "PP2 PayPal row {$r['id']} ({$r['kind']}): counted on {$r['alloc_dest']} it does not belong to, or on another day than its own";
        }
        // 5,000 rows a read (about 100,000 a year: the memory stays small at any size, checklist 26)
        $n = 0;
        for ($after = 0; $n < self::MAX_PER_CHECK; ) {
            $rows = $db->all('SELECT id, event_code, amount_pence, fee_pence, kind, cost_pence FROM paypal_txn WHERE id > ? ORDER BY id LIMIT 5000', [$after]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $after = (int) $r['id'];
                $kind = PaypalCodes::kind((string) $r['event_code'], (int) $r['amount_pence']);
                $cost = PaypalCodes::cost($kind, (int) $r['amount_pence'], (int) $r['fee_pence']);
                if ($kind !== $r['kind'] || $cost !== (int) $r['cost_pence']) {
                    $v[] = "PP3 PayPal row {$r['id']} ({$r['event_code']}): stored as {$r['kind']} costing {$r['cost_pence']}p, its rules say {$kind} costing {$cost}p";
                    if (++$n >= self::MAX_PER_CHECK) {
                        break;
                    }
                }
            }
        }
        foreach ($db->all('SELECT id FROM paypal_txn WHERE due = 1 AND due_since < UTC_TIMESTAMP(6) - INTERVAL ' . PaypalFees::STALE_HOURS . ' HOUR ORDER BY id LIMIT '
            . self::MAX_PER_CHECK) as $r) {
            $v[] = "PP4 PayPal row {$r['id']}: still due " . PaypalFees::STALE_HOURS . ' hours after it was read (the next run decides it)';
        }
        foreach ($db->all("SELECT id, account FROM paypal_run WHERE state = 'running' AND started_at < UTC_TIMESTAMP(6) - INTERVAL " . PaypalFees::STALE_HOURS . ' HOUR '
            . 'ORDER BY id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "PP5 PayPal run {$r['id']} ({$r['account']}): still running " . PaypalFees::STALE_HOURS . ' hours after it started';
        }
        return $v;
    }
}
