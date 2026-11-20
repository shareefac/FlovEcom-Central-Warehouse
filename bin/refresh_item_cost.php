<?php

declare(strict_types=1);

/**
 * The cost job (profit step 1; docs/decisions.md PR15; CW\Profit\CostEngine, CW\Profit\ReturnsSweep): follows the deliveries, supplier
 * invoices and credit notes booked since its last run into each item's buying price, then works out again the orders whose units came
 * back to stock since then. Cron runs it every 10 minutes; it never runs inside a booking (a booking can never fail because of it). While
 * the setting profit.cost_refresh_on is off it does nothing (unless --force). Restartable: what is done is never done twice.
 *
 *   php bin/refresh_item_cost.php [--force] [--status]
 *
 * Exit codes: 0 done (also: switched off, not started yet, or another run holds the job's lock) · 2 usage · 3 cannot run.
 */

use CW\Ops\Cli;
use CW\Profit\CostEngine;
use CW\Profit\ProfitSettings;
use CW\Profit\ReturnsSweep;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(CostEngine::JOB, ['force', 'status'], 'usage: php bin/refresh_item_cost.php [--force] [--status]',
    static function (Cli $cli, array $opts): int {
        $engine = new CostEngine($cli->db);
        if (array_key_exists('status', $opts)) {
            if (array_key_exists('force', $opts)) {
                throw new InvalidArgumentException('--status reads only: give it alone');
            }
            $cli->log('status ' . json_encode($engine->state(), JSON_UNESCAPED_SLASHES));
            return Cli::OK;
        }
        if (!(new ProfitSettings($cli->db))->refreshOn() && !array_key_exists('force', $opts)) {
            $cli->log('switched off (setting profit.cost_refresh_on): nothing done');
            return Cli::OK;
        }
        $r = $engine->run();
        $cli->log(isset($r['not_started']) ? 'not started yet (run bin/seed_item_cost.php first): nothing done' : 'cost ' . json_encode($r, JSON_UNESCAPED_SLASHES));
        $cli->log('returns ' . json_encode((new ReturnsSweep($cli->db))->run(), JSON_UNESCAPED_SLASHES));
        return Cli::OK;
    }, true, true));
