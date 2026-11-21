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
 * Each phase (the prices, the sales waiting for their cost, the returns, the nightly returns check) runs whatever the one before it did: an
 * order whose work fails is counted and named (stderr, and the phase's summary on its profit_job row) and the next order goes on; the run
 * then ends with exit 1 (the second final review of 3c31d94, a-HIGH4; docs/decisions.md PR132). --status prints the cost job's mark and the
 * last summary of each phase (waiting, failed and the orders that failed); a failed run is in the job's log only.
 *
 * Exit codes: 0 done (also: switched off, not started yet, or another run of every 10 minutes holds the job's lock) · 1 an order (or a phase)
 * failed: named above, the rest done · 2 usage · 3 cannot run · 4 --reconcile not run: the lock was still held after 15 minutes (run it again).
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
            foreach ([PendingCosts::JOB, ReturnsSweep::JOB, ReturnsSweep::CHECK_JOB] as $job) {
                $r = $cli->db->one('SELECT ran_at, CAST(summary AS CHAR) AS summary FROM profit_job WHERE job = ?', [$job]);
                $cli->log("status {$job}: " . ($r === null ? 'never run' : 'ran ' . (string) $r['ran_at'] . ' ' . (string) $r['summary']));
            }
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
        $problem = false;
        // Each phase on its own: one that fails is said and the next still runs (PR132).
        $phase = static function (string $name, Closure $fn) use ($cli, &$problem): void {
            try {
                $r = $fn();
            } catch (Throwable $e) {
                $problem = true;
                $cli->error("{$name}: " . Cli::describe($e) . ' (the next phase goes on)');
                return;
            }
            if (is_string($r)) {
                $cli->log($r);
                return;
            }
            $cli->log($name . ' ' . json_encode($r, JSON_UNESCAPED_SLASHES));
            foreach ($r['errors'] ?? [] as $x) {
                $cli->error("{$name}: store {$x['store']} order {$x['order']} failed: {$x['error']} (the next orders went on)");
            }
            if (($r['failed'] ?? 0) > 0) {
                $problem = true;
            }
        };
        // Nightly (--reconcile): every document since the start not read whole yet, too (docs/decisions.md PR57).
        $phase('cost', static function () use ($engine, $opts): array|string {
            $r = $engine->run(CostEngine::MAX_EVENTS, CostEngine::UNREAD_PER_RUN, array_key_exists('reconcile', $opts));
            return isset($r['not_started']) ? 'not started yet (run bin/seed_item_cost.php first): nothing done' : $r;
        });
        // The sales stored before the job reached their paid time get their cost now (docs/decisions.md PR16).
        $phase('pending', static fn (): array => (new PendingCosts($cli->db))->run());
        $phase('returns', static fn (): array => (new ReturnsSweep($cli->db))->run());
        if (array_key_exists('reconcile', $opts)) {
            // Nightly: every unit back in stock, whenever its return was booked (docs/decisions.md PR46).
            $phase('returns reconciled', static fn (): array => (new ReturnsSweep($cli->db))->reconcile());
        }
        return $problem ? Cli::PROBLEM : Cli::OK;
    }, false, true));
