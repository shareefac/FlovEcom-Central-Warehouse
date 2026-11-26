<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * The nightly checks of Global Payments' card fees (0084; docs/decisions.md GPF14; called by CW\Invariants::check, so bin/invariants.php and
 * every stock test run them). Read-only; at most MAX_PER_CHECK findings per check.
 *
 *  GP1. a counted row (estimate or actual) is of a paid card order, on the order's paid day, with the order's amount as its base (the row and
 *       the order are written in one transaction: the snapshot, the job).
 *  GP2. the profit days hold exactly the rows: each store's day's gp_fee and gp_fee_est are its paid orders' counted rows (by the order's day),
 *       its gp_other the invoices' charges on no order dated that day (every penny once; the rebuild check says the same day by day).
 *  GP3. an invoice spread (done, live, its month not queued again) is on its orders and on no order to the penny: its actual rows of its last
 *       spread plus its charge on no order are its net charge.
 *  GP4. nothing waits long: no month queued, no invoice due, no run running for STALE_HOURS; no estimate at another rate than its account's
 *       for STALE_HOURS (the job works them out again every 10 minutes).
 *  GP5. no paid card order of an account switched on, in its counted days of the last RECHECK_DAYS days, is without a counted row for
 *       STALE_HOURS after it was stored (the snapshot counts it; the nightly goes over the days again).
 */
final class GpInvariants
{
    public const STALE_HOURS = 6;
    private const MAX_PER_CHECK = 50;

    /** @return list<string> */
    public static function check(Db $db): array
    {
        $v = [];
        foreach ($db->all("SELECT g.channel_id, g.order_ref, g.day, g.base_pence, o.day AS o_day, o.grand_inc, o.gateway FROM gp_fee_order g JOIN sale_order o "
            . "ON o.channel_id = g.channel_id AND o.order_ref = g.order_ref WHERE g.state <> 'off' AND (o.day IS NULL OR o.day <> g.day OR o.grand_inc <> g.base_pence "
            . "OR o.gateway <> 'globalpay') ORDER BY g.channel_id, g.order_ref LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "GP1 card fee of order {$r['order_ref']} (shop {$r['channel_id']}): counted on {$r['day']} with base {$r['base_pence']}p, the order is "
                . ($r['o_day'] === null ? 'not paid' : "paid on {$r['o_day']}") . " for {$r['grand_inc']}p by '{$r['gateway']}'";
        }
        foreach ($db->all("SELECT p.channel_id, p.day, p.gp_fee, p.gp_fee_est, COALESCE(x.f, 0) AS f, COALESCE(x.e, 0) AS e FROM profit_day p LEFT JOIN ("
            . "SELECT o.channel_id, o.day, SUM(g.fee_pence) AS f, SUM(IF(g.state = 'estimate', g.fee_pence, 0)) AS e FROM gp_fee_order g JOIN sale_order o "
            . "ON o.channel_id = g.channel_id AND o.order_ref = g.order_ref WHERE g.state <> 'off' AND o.day IS NOT NULL GROUP BY o.channel_id, o.day) x "
            . 'ON x.channel_id = p.channel_id AND x.day = p.day WHERE p.gp_fee <> COALESCE(x.f, 0) OR p.gp_fee_est <> COALESCE(x.e, 0) '
            . 'ORDER BY p.day, p.channel_id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "GP2 shop {$r['channel_id']} on {$r['day']}: the day holds card fees {$r['gp_fee']}p ({$r['gp_fee_est']}p estimated), its orders {$r['f']}p ({$r['e']}p)";
        }
        foreach ($db->all('SELECT p.channel_id, p.day, p.gp_other, COALESCE(x.o, 0) AS o FROM profit_day p LEFT JOIN (SELECT other_channel, other_day, SUM(other_pence) AS o '
            . 'FROM gp_invoice WHERE other_pence <> 0 GROUP BY other_channel, other_day) x ON x.other_channel = p.channel_id AND x.other_day = p.day '
            . 'WHERE p.gp_other <> COALESCE(x.o, 0) ORDER BY p.day, p.channel_id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "GP2 shop {$r['channel_id']} on {$r['day']}: the day holds {$r['gp_other']}p of card fees on no order, the invoices {$r['o']}p";
        }
        foreach ($db->all("SELECT i.id, i.month, i.net_pence, i.other_pence, COALESCE(SUM(g.fee_pence), 0) AS f FROM gp_invoice i LEFT JOIN gp_fee_order g "
            . "ON g.invoice_id = i.id AND g.state = 'actual' AND g.spread_version = i.spread_version WHERE i.deleted_at IS NULL AND i.spread_state = 'done' "
            . 'AND NOT EXISTS (SELECT 1 FROM gp_due q WHERE q.account = i.account AND q.month = i.month) GROUP BY i.id, i.month, i.net_pence, i.other_pence '
            . 'HAVING f + i.other_pence <> i.net_pence ORDER BY i.id LIMIT ' . self::MAX_PER_CHECK) as $r) {
            $v[] = "GP3 card fee invoice {$r['id']} ({$r['month']}): net {$r['net_pence']}p, on its orders {$r['f']}p and on no order {$r['other_pence']}p";
        }
        $stale = 'UTC_TIMESTAMP(6) - INTERVAL ' . self::STALE_HOURS . ' HOUR';
        foreach ($db->all("SELECT account, month, MIN(queued_at) AS q FROM gp_due WHERE queued_at < {$stale} GROUP BY account, month ORDER BY account, month LIMIT "
            . self::MAX_PER_CHECK) as $r) {
            $v[] = "GP4 {$r['account']} {$r['month']}: queued to be spread again since {$r['q']} (bin/gp_fees.php takes it every 10 minutes)";
        }
        foreach ($db->all("SELECT id, account, month FROM gp_invoice WHERE deleted_at IS NULL AND spread_state = 'due' AND updated_at < {$stale} ORDER BY id LIMIT "
            . self::MAX_PER_CHECK) as $r) {
            $v[] = "GP4 card fee invoice {$r['id']} ({$r['account']} {$r['month']}): still waiting to be spread " . self::STALE_HOURS . ' hours after it was saved';
        }
        foreach ($db->all("SELECT id, account FROM gp_run WHERE state = 'running' AND started_at < {$stale} ORDER BY id LIMIT " . self::MAX_PER_CHECK) as $r) {
            $v[] = "GP4 card fee run {$r['id']} ({$r['account']}): still running " . self::STALE_HOURS . ' hours after it started';
        }
        $cfg = GpFees::readConfig($db);
        foreach ($cfg as $acct => $c) {
            if ($c['rate'] !== null) {
                $n = (int) $db->value("SELECT COUNT(*) FROM gp_fee_order WHERE account = ? AND state = 'estimate' AND rate_e4 <> ? AND updated_at < {$stale}", [$acct, $c['rate']]);
                if ($n > 0) {
                    $v[] = "GP4 {$acct}: {$n} card fees still estimated at another rate than its setting " . self::STALE_HOURS . ' hours on';
                }
            }
            if (!$c['on'] || $c['shops'] === []) {
                continue;
            }
            $today = (new GpFees($db))->today();
            $from = max((string) $c['from'], GpFees::addDays($today, -GpFees::RECHECK_DAYS));
            $to = $c['until'] !== null && $c['until'] < $today ? (string) $c['until'] : $today;
            if ($from > $to) {
                continue;
            }
            foreach ($db->all("SELECT o.channel_id, o.order_ref, o.day FROM sale_order o LEFT JOIN gp_fee_order g ON g.channel_id = o.channel_id AND g.order_ref = o.order_ref "
                . 'WHERE o.day BETWEEN ? AND ? AND o.channel_id IN (' . implode(', ', array_fill(0, count($c['shops']), '?')) . ") AND o.gateway = 'globalpay' "
                . "AND o.grand_inc > 0 AND (g.order_ref IS NULL OR g.state = 'off') AND o.updated_at < {$stale} ORDER BY o.day, o.channel_id, o.order_ref LIMIT "
                . self::MAX_PER_CHECK, [$from, $to, ...$c['shops']]) as $r) {
                $v[] = "GP5 card order {$r['order_ref']} (shop {$r['channel_id']}, paid {$r['day']}): no card fee counted for it ({$acct})";
            }
        }
        return $v;
    }
}
