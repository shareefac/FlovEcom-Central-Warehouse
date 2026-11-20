<?php

declare(strict_types=1);

/**
 * The cost job (profit step 1; docs/decisions.md PR15; CW\Profit\CostEngine, PendingCosts, ReturnsSweep): follows the deliveries, supplier
 * invoices and credit notes booked since its last run into each item's buying price (and starts the items made since), fixes the cost of
 * the sales that waited for it, then works out again the orders whose units came back to stock since then. Cron runs it every 10 minutes; it never runs inside a booking (a booking can never fail because of it). While
 * the setting profit.cost_refresh_on is off it does nothing (unless --force). Restartable: what is done is never done twice.
 *
 *   php bin/refresh_item_cost.php [--force] [--status] [--reconcile]
 *
 *   --reconcile  also every delivery, invoice and credit note since the start not read whole yet, and every unit back in stock whenever its
 *                return was booked (a full read: once a night). It WAITS up to 15 minutes for a run of every 10 minutes that holds the job's
 *                lock, so the night's full read is never skipped (the final review of 6d59eda, a-M4, PR98).
 *
 * Exit codes: 0 done (also: switched off, not started yet, or another run of every 10 minutes holds the job's lock) · 2 usage · 3 cannot
 * run · 4 --reconcile not run: the lock was still held after 15 minutes (run it again).
 */

use CW\Ops\Cli;
use CW\Profit\CostEngine;
use CW\Profit\PendingCosts;
use CW\Profit\ProfitSettings;
use CW\Profit\ReturnsSweep;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(CostEngine::JOB, ['force', 'status', 'reconcile'], 'usage: php bin/refresh_item_cost.php [--force] [--status] [--reconcile]',
    static function (Cli $cli, array $opts): int {
        $engine = new CostEngine($cli->db);
        if (array_key_exists('status', $opts)) {
            if (array_key_exists('force', $opts)) {
                throw new InvalidArgumentException('--status reads only: give it alone');
            }
            $cli->log('status ' . json_encode($engine->state(), JSON_UNESCAPED_SLASHES));
            return Cli::OK;
        }
        $reconcile = array_key_exists('reconcile', $opts);
        if (!$cli->tryLock($reconcile ? 900 : 0)) {
            if ($reconcile) {
                $cli->error('the night\'s full read was not run: another run held the job\'s lock for 15 minutes; run this again');
                return Cli::BUSY;
            }
            $cli->log('skipped: another run of this job holds its lock');
            return Cli::OK;
        }
        if (!(new ProfitSettings($cli->db))->refreshOn() && !array_key_exists('force', $opts)) {
            $cli->log('switched off (setting profit.cost_refresh_on): nothing done');
            return Cli::OK;
        }
        // Nightly (--reconcile): every document since the start not read whole yet, too (docs/decisions.md PR57).
        $r = $engine->run(CostEngine::MAX_EVENTS, CostEngine::UNREAD_PER_RUN, array_key_exists('reconcile', $opts));
        $cli->log(isset($r['not_started']) ? 'not started yet (run bin/seed_item_cost.php first): nothing done' : 'cost ' . json_encode($r, JSON_UNESCAPED_SLASHES));
        // The sales stored before the job reached their paid time get their cost now (docs/decisions.md PR16).
        $cli->log('pending ' . json_encode((new PendingCosts($cli->db))->run(), JSON_UNESCAPED_SLASHES));
        $cli->log('returns ' . json_encode((new ReturnsSweep($cli->db))->run(), JSON_UNESCAPED_SLASHES));
        if (array_key_exists('reconcile', $opts)) {
            // Nightly: every unit back in stock, whenever its return was booked (docs/decisions.md PR46).
            $cli->log('returns reconciled ' . json_encode((new ReturnsSweep($cli->db))->reconcile(), JSON_UNESCAPED_SLASHES));
        }
        return Cli::OK;
    }, false, true));
