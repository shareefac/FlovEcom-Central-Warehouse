<?php

declare(strict_types=1);

/**
 * The nightly clean-up (go-live plan part 6 "Housekeeping on CW"; docs/decisions.md PF11; CW\Ops\Housekeeping): the stores' stored
 * answers (idempotency), their heartbeats (channel_health; each store's newest one is kept) and the audit log (audit_log), each older
 * than its retention setting (housekeeping.*, Settings › System › Settings), in chunks, until done or the time setting runs out.
 *
 *   php bin/housekeeping.php [--db=cw_staging] [--dry-run] [--admin]
 *
 * The app login (the cron's) cannot delete audit rows (append-only): that part reports "kept". Only --admin (the admin login, a
 * person's decision) removes audit rows older than housekeeping.audit_keep_years (7 at the least). --dry-run counts and removes nothing.
 * One line per table. Exit codes: 0 ok (also "not finished: goes on next night"), 2 usage, 3 cannot run (see CW\Ops\Cli).
 */

use CW\Ops\Cli;
use CW\Ops\Housekeeping;
use CW\Settings;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(
    'housekeeping',
    ['dry-run'],
    'usage: php bin/housekeeping.php [--dry-run]',
    static function (Cli $cli, array $opts): int {
        $dry = array_key_exists('dry-run', $opts);
        $r = (new Housekeeping($cli->db))->run(new Settings($cli->db), $dry);
        foreach ($r as $table => $x) {
            $cli->log(sprintf('%s%s: %s=%d older_than=%s%s%s', $dry ? 'DRY RUN ' : '', $table, $dry ? 'would_remove' : 'removed', $x['removed'], $x['cutoff'],
                isset($x['kept_reason']) ? ' kept: ' . $x['kept_reason'] : '', $x['done'] ? '' : ' (not finished: the rest goes the next night)'));
        }
        return Cli::OK;
    },
    true,
    true,
));
