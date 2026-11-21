<?php

declare(strict_types=1);

/**
 * The money work of the shops' order snapshots (profit step 1; the final review of 6d59eda, a-HIGH2; docs/decisions.md PR95): what
 * PUT /v1/orders/{ref}/snapshot stored (order_snapshot_queue) worked out into the order, its lines, its parcels, its first order's re-send
 * figures and the day summaries (CW\Profit\OrderSnapshots::applyQueued), in this process, never in the web server's workers: a store's
 * checkout never waits behind it. The oldest first, each order in a transaction of its own, at most --max orders and about --seconds a run.
 * Cron runs it every minute; one run at a time (the job's lock: a second run started meanwhile does nothing). --status reads without the
 * lock (it can always be read); an asked-for --retry-refused that finds the lock held does nothing and says so (exit 4).
 *
 *   php bin/apply_order_snapshots.php [--max=N] [--seconds=N] [--status | --retry-refused]
 *
 *   --max            the most orders a run works out (default 2000)
 *   --seconds        a run stops taking new orders after about this long (default 45); a lock it waits for is waited for at most a few
 *                    seconds (the order is left for the next run), so one order never holds the run up
 *   --status         reads only: how many snapshots wait, the oldest, and the refused ones
 *   --retry-refused  the refused snapshots are tried again on this run (after what refused them was put right)
 *
 * Exit codes: 0 done (also: nothing waiting, or another run holds the job's lock) · 1 a snapshot was refused (its queue row says why) · 2 usage
 * · 3 cannot run · 4 --retry-refused not run: another run holds the job's lock.
 */

use CW\Ops\Cli;
use CW\Profit\OrderSnapshots;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('apply_order_snapshots', ['max:', 'seconds:', 'status', 'retry-refused'],
    'usage: php bin/apply_order_snapshots.php [--max=N] [--seconds=N] [--status | --retry-refused]',
    static function (Cli $cli, array $opts): int {
        if (array_key_exists('status', $opts)) {
            $s = $cli->db->one('SELECT SUM(failed = 0) AS waiting, SUM(failed = 1) AS refused, MIN(IF(failed = 0, queued_at, NULL)) AS oldest FROM order_snapshot_queue');
            $cli->log(sprintf('waiting %d (oldest %s), refused %d', (int) ($s['waiting'] ?? 0), (string) ($s['oldest'] ?? '-'), (int) ($s['refused'] ?? 0)));
            foreach ($cli->db->all('SELECT q.channel_id, q.order_ref, q.error, q.queued_at FROM order_snapshot_queue q WHERE q.failed = 1 ORDER BY q.queued_at LIMIT 20') as $r) {
                $cli->log("refused: store {$r['channel_id']} order {$r['order_ref']} ({$r['queued_at']}): {$r['error']}");
            }
            return Cli::OK;
        }
        $max = Cli::intOpt($opts, 'max', OrderSnapshots::QUEUE_MAX_ORDERS, 1, 100_000);
        $seconds = Cli::intOpt($opts, 'seconds', OrderSnapshots::QUEUE_MAX_SECONDS, 1, 3600);
        // One run at a time (the job's lock, taken here so that --status never needs it: the Codex check of 2b718e7).
        if (!$cli->tryLock()) {
            if (array_key_exists('retry-refused', $opts)) {
                $cli->error('not run: another run of this job holds its lock; nothing tried again (run it again in a minute)');
                return Cli::BUSY;
            }
            $cli->log('skipped: another run of this job holds its lock');
            return Cli::OK;
        }
        // Short lock waits (an order's guards, a day's summary row a rebuild holds, a queue row the API is replacing): such an order is left
        // for the next run, never the run held up behind it (the Codex check of c48683e); set before the retry's update too (of 2b718e7).
        $cli->db->exec('SET SESSION innodb_lock_wait_timeout = ' . OrderSnapshots::QUEUE_LOCK_WAIT_SEC);
        if (array_key_exists('retry-refused', $opts)) {
            $cli->log('refused snapshots to try again: ' . $cli->db->exec('UPDATE order_snapshot_queue SET failed = 0 WHERE failed = 1'));
        }
        $r = (new OrderSnapshots($cli->db, null, null, OrderSnapshots::QUEUE_LOCK_WAIT_SEC))->applyQueued($max, $seconds);
        $cli->log(sprintf('applied %d, refused %d, busy %d (left for the next run), waiting %d', $r['applied'], $r['refused'], $r['busy'], $r['left']));
        return $r['refused'] > 0 ? Cli::PROBLEM : Cli::OK;
    }, false, true));
