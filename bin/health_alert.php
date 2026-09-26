<?php

declare(strict_types=1);

/**
 * STUB alert from channel_health (plan §6.3 "CW also alerts centrally"): prints channels in
 * shadow/live whose site worker stopped reporting, reports dead letters, or runs another mode.
 * Printing only; sending to the alert webhook comes with the alerting work (docs/ops.md).
 *
 *   php bin/health_alert.php [--db=cw_staging] [--stale-after=180] [--admin]
 *
 * One line per problem ("STALE vpg (live): last heartbeat ... (412 s ago; threshold 180 s)").
 * Exit codes: 0 nothing to report, 1 problems printed, 2 usage, 3 cannot run (see CW\Ops\Cli).
 */

use CW\Ops\ChannelHealth;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(
    'health_alert',
    ['stale-after:'],
    'usage: php bin/health_alert.php [--stale-after=180]',
    static function (Cli $cli, array $opts): int {
        $staleAfter = Cli::intOpt($opts, 'stale-after', ChannelHealth::STALE_AFTER_SEC, 1, 86_400);
        $problems = (new ChannelHealth($cli->db))->problems(null, $staleAfter);
        foreach ($problems as $p) {
            $cli->log(sprintf('%s %s (%s): %s', strtoupper($p['problem']), $p['channel'], $p['mode'], $p['detail']));
        }
        if ($problems === []) {
            $cli->log('ok: every shadow/live channel reported within ' . $staleAfter . ' s');
            return Cli::OK;
        }
        return Cli::PROBLEM;
    },
    false,
));
