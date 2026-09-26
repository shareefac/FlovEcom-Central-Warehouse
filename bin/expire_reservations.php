<?php

declare(strict_types=1);

/**
 * Expires unpaid holds past their TTL (plan §3 "The expiry cron selects ids without locks, then
 * processes each through the same path", §4 step 3). Cron: every minute (docs/ops.md).
 *
 *   php bin/expire_reservations.php [--db=cw_staging] [--limit=500] [--max-seconds=50] [--admin]
 *
 * Each batch selects up to --limit due reservation ids WITHOUT locks, then expires each in its
 * own transaction through CW\Reservations::expireDue (lock the reservation, re-check it is still
 * held and due, release its held units, status expired). Batches repeat until none is due or
 * --max-seconds have passed (the next minute's run carries on). A reservation that fails is
 * logged and skipped, never blocks the others; the run then exits 1. One run at a time per
 * schema (named lock; an overlapping run logs "skipped" and exits 0).
 * Exit codes: 0 ok, 1 some reservations failed, 2 usage, 3 cannot run (see CW\Ops\Cli).
 */

use CW\Ops\Cli;
use CW\Reservations;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(
    'expire_reservations',
    ['limit:', 'max-seconds:'],
    'usage: php bin/expire_reservations.php [--limit=500] [--max-seconds=50]',
    static function (Cli $cli, array $opts): int {
        $limit = Cli::intOpt($opts, 'limit', 500, 1, 10_000);
        $deadline = microtime(true) + Cli::intOpt($opts, 'max-seconds', 50, 1, 3_600);
        $started = hrtime(true);
        $res = new Reservations($cli->db);
        $failed = [];
        $expired = 0;
        $batches = 0;
        do {
            $n = $res->expireDue($limit, static function (int $id, \Throwable $e) use ($cli, &$failed): void {
                if (!isset($failed[$id])) {
                    $cli->error("reservation {$id} could not be expired: " . Cli::describe($e));
                }
                $failed[$id] = true;
            });
            $expired += $n;
            $batches++;
        } while ($n > 0 && microtime(true) < $deadline);
        $cli->log(sprintf('expired=%d failed=%d batches=%d ms=%d%s', $expired, count($failed), $batches,
            intdiv(hrtime(true) - $started, 1_000_000), $n > 0 ? ' (time budget used up; the next run continues)' : ''));
        return $failed === [] ? Cli::OK : Cli::PROBLEM;
    },
));
