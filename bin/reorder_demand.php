<?php

declare(strict_types=1);

/**
 * Rebuilds reorder_demand from the imported sales history (CW\Reorder\DemandBuilder; docs/decisions.md I62, docs/ops.md
 * "Sales history"). For a future nightly cron, which is NOT installed: today it runs after each import
 * (bin/import_sales_history.php) and from the reorder list's "Recalculate".
 *
 *   php bin/reorder_demand.php [--db=<schema>] [--admin]
 *
 * Exit codes: 0 done (also "already running": another build holds the lock) · 1 the build failed · 2 usage · 3 cannot run.
 */

use CW\CwException;
use CW\Ops\Cli;
use CW\Reorder\DemandBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

exit(Cli::main('reorder_demand', [], 'usage: php bin/reorder_demand.php',
    static function (Cli $cli, array $opts): int {
        try {
            $b = (new DemandBuilder($cli->db))->rebuild();
        } catch (CwException $e) {
            if ($e->errorCode === 'build_running') {
                $cli->log('already running');
                return Cli::OK;
            }
            $cli->error("{$e->errorCode}: {$e->getMessage()}");
            return Cli::PROBLEM;
        }
        $channels = [];
        foreach ($b['channels'] as $code => $c) {
            $channels[] = "{$code} {$c['from']}..{$c['to']} (promotion days " . ($b['promo_days'][$code] ?? 0) . ')';
        }
        $cli->log(sprintf('demand: %d items, %d listings, %d ms; %s', $b['items'], $b['listings'], $b['ms'], $channels === [] ? 'no sales history loaded' : implode(', ', $channels)));
        return Cli::OK;
    }));
