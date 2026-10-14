<?php

declare(strict_types=1);

/**
 * The reorder engine's schedule (CW\Reorder\ReorderJobs; docs/decisions.md PG4; docs/ops.md "The reorder engine"): installed in cron
 * every 5 minutes. Each run does what is due:
 *   1. fold     CW's own record of each store's sales, from the stock ledger, one UK day at a time (the days not folded yet; the last
 *               reorder.fold_refold_days days once more a day);
 *   2. demand   the sales worked out again once a UK day, from the hour reorder.demand_hour;
 *   3. lines    the reorder list worked out again in chunks, every reorder.refresh_minutes (and at once after the demand).
 * It only reads the stock ledger, the orders and the stock; it writes the reorder tables. Nothing books stock.
 *
 *   php bin/reorder_refresh.php [--force] [--db=<schema>] [--admin]
 *
 *   --force   every step now (the fold's days due, the demand and the list), whatever the times say.
 *
 * Exit codes: 0 done (also "nothing due" and "already running": another run does it) · 1 a step failed (its error is kept in
 * reorder_job and shown on the list) · 2 usage · 3 cannot run.
 */

use CW\CwException;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Ops\Cli;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Reorder\ReorderJobs;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

exit(Cli::main('reorder_refresh', ['force'], 'usage: php bin/reorder_refresh.php [--force]',
    static function (Cli $cli, array $opts): int {
        $jobs = new ReorderJobs($cli->db, new PurchaseOrders($cli->db, new Documents($cli->db, DocumentHandlers::all($cli->db))));
        try {
            $r = $jobs->scheduled(array_key_exists('force', $opts));
        } catch (CwException $e) {
            $cli->error("{$e->errorCode}: {$e->getMessage()}");
            return Cli::PROBLEM;
        }
        $say = static function (mixed $step, string $what): string {
            if ($step === null) {
                return "{$what}: not due";
            }
            if (is_string($step)) {
                return "{$what}: {$step}";
            }
            return match ($what) {
                'fold' => sprintf('fold: %d days (%s)%s', count($step['days']), implode(', ', array_map(static fn (array $d): string => "{$d['date']} {$d['units']} units",
                    $step['days'])), $step['more'] ? ', more to do' : ''),
                'demand' => sprintf('demand: %d items, %d listings, %d ms', $step['items'], $step['listings'], $step['ms']),
                default => sprintf('lines: %d items, %d changed, %d unlisted, %d chunks, %d ms', $step['items'], $step['changed'], $step['unlisted'], $step['chunks'],
                    $step['ms']),
            };
        };
        $cli->log(implode('; ', [$say($r['fold'], 'fold'), $say($r['demand'], 'demand'), $say($r['lines'], 'lines')]));
        return Cli::OK;
    }, true, true));
