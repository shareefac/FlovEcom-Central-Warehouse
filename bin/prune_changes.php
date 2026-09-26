<?php

declare(strict_types=1);

/**
 * Keeps the last --days (default 14) of the change feed, stock_change (docs/ops.md: nightly).
 *
 *   php bin/prune_changes.php [--db=cw_staging] [--days=14] [--dry-run] [--max-seconds=900] [--admin]
 *
 * Deletes rows older than the cutoff EXCEPT the newest row of each item / listing / channel /
 * global scope, so no listing's version (MAX seq, D38) ever changes (CW\Ops\ChangePruner, H2).
 * Short autocommit deletes in windows of 5,000 rows; safe while the service runs. A site that
 * was away longer than --days catches up through its 15-minute snapshot (plan §3), not the feed.
 * --dry-run counts what would go. One run at a time per schema.
 * Exit codes: 0 ok, 2 usage, 3 cannot run (see CW\Ops\Cli).
 */

use CW\Ops\ChangePruner;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(
    'prune_changes',
    ['days:', 'dry-run', 'max-seconds:'],
    'usage: php bin/prune_changes.php [--days=14] [--dry-run] [--max-seconds=900]',
    static function (Cli $cli, array $opts): int {
        $days = Cli::intOpt($opts, 'days', ChangePruner::KEEP_DAYS, 1, 3_650);
        $dryRun = array_key_exists('dry-run', $opts);
        $deadline = microtime(true) + Cli::intOpt($opts, 'max-seconds', 900, 1, 86_400);
        $started = hrtime(true);
        $cutoff = ChangePruner::cutoff($days);
        $r = (new ChangePruner($cli->db))->prune($cutoff, $dryRun, $deadline);
        $cli->log(sprintf('%scutoff=%s (%d days) deleted=%d kept_newest_of_scope=%d windows=%d up_to_seq=%d ms=%d%s',
            $dryRun ? 'DRY RUN ' : '', $cutoff->format('Y-m-d\TH:i:s\Z'), $days, $r['deleted'], $r['kept_newest'], $r['windows'],
            $r['up_to_seq'], intdiv(hrtime(true) - $started, 1_000_000), $r['complete'] ? '' : ' (time budget used up; the next run continues)'));
        return Cli::OK;
    },
));
