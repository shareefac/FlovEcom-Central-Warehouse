<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * The nightly checks of PayPal's rows (0080; docs/decisions.md PF18; called by CW\Invariants::check, so bin/invariants.php and every stock
 * test run them). Read-only; at most MAX_PER_CHECK findings per check. The day figures they feed are held to their rows by the rebuild check
 * (bin/rebuild_profit_days.php: ProfitDays::compute works fee_cost and fee_other out from scratch with PaypalFees::dayFigures).
 *
 *  PP1. a decided row (not due) counts its whole cost in exactly one place, or nowhere: counts with a cost not 0 = one place holding exactly
 *       its cost (an order, or the shop's "not on an order" line); anything else = no place and 0 (every penny once, none twice).
 *  PP2. a row on an order is on an order of its own account's shop that carries its number (never another shop's, never another number's);
 *       a sale's fee counts on the order's paid day (no day of its own), every other kind on its own UK day; a row on the shop's line is in its
 *       own account's shop, on its own day.
 *  PP3. kind and cost are what the event code and the amounts say (PaypalCodes): a stored row never disagrees with the rules it was read by.
 *  PP4. no row is due for more than STALE hours after it was last read or changed (a run that stopped half-way is finished by the next one).
 *  PP5. no run is still "running" STALE hours after it started (one that died: its rows stay due for the next one).
 */
final class PaypalInvariants
{
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT id, count_state, cost_pence, alloc_dest, alloc_pence FROM paypal_txn WHERE due = 0 AND NOT ("
            . "(count_state = 'counts' AND cost_pence <> 0 AND alloc_dest IS NOT NULL AND alloc_pence = cost_pence) "
            . "OR (NOT (count_state = 'counts' AND cost_pence <> 0) AND alloc_dest IS NULL AND alloc_pence = 0)) ORDER BY id LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "PP1 PayPal row {$r['id']}: {$r['count_state']} costing {$r['cost_pence']}p, counted " . ($r['alloc_dest'] === null ? 'nowhere' : "on its {$r['alloc_dest']}")
                . " as {$r['alloc_pence']}p";
        }
        foreach ($db->all("SELECT t.id, t.kind, t.alloc_dest FROM paypal_txn t LEFT JOIN sale_order o ON o.channel_id = t.alloc_channel AND o.order_ref = t.alloc_order_ref "
            . "WHERE (t.alloc_dest = 'order' AND (t.alloc_channel <> t.channel_id OR o.order_ref IS NULL OR o.order_no <> t.order_no OR t.order_no IS NULL "
            . "OR (t.kind = 'sale') <> (t.alloc_day IS NULL) OR (t.alloc_day IS NOT NULL AND t.alloc_day <> t.day))) "
            . "OR (t.alloc_dest = 'shop' AND (t.alloc_channel <> t.channel_id OR t.alloc_day <> t.day)) ORDER BY t.id LIMIT " . self::MAX_PER_CHECK) as $r) {
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
        foreach ($db->all('SELECT id FROM paypal_txn WHERE due = 1 AND changed_at < UTC_TIMESTAMP(6) - INTERVAL ' . PaypalFees::STALE_HOURS . ' HOUR ORDER BY id LIMIT '
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
