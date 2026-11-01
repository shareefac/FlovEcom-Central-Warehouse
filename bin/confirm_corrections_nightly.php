<?php

declare(strict_types=1);

/**
 * The nightly Confirm all (CW\Queues\NightlyConfirm; docs/decisions.md NC1-NC12; docs/ops.md): every five minutes from cron, and it does
 * something only while the setting relay.nightly_confirm is on, inside the night's window (relay.nightly_confirm_at, UK time, for an hour),
 * and once a night: then it confirms the ready ERPNext corrections exactly as a person's "Confirm all ready" does (the same rules, the same
 * gate under each one's locks, its deadline), at most relay.nightly_confirm_max, as its own system caller (system:confirm_nightly); the
 * doubtful ones stay in Approvals for a person. Its run is one more Confirm all run: Settings > Rules and numbers links last night's.
 *
 *   php bin/confirm_corrections_nightly.php [--db=<schema>] [--admin]
 *
 * One line per run: off, outside the window, busy (another start of tonight's run holds its lock), done already tonight (with the run), or
 * what tonight's run did (the run, confirmed, booked, left, its done page). No options of its own: the time and the switch are settings.
 * The command line is read whole or not at all (Ops\Cli::unreadable): anything else ends it with exit 2 before anything is read.
 *
 * Exit codes: 0 done (also: off, outside, busy, done already) · 2 usage · 3 cannot run (a run that failed: its run row says what it did).
 */

use CW\Ops\Cli;
use CW\Queues\NightlyConfirm;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(NightlyConfirm::JOB, [], 'usage: php bin/confirm_corrections_nightly.php',
    static function (Cli $cli, array $opts): int {
        $cli->log(NightlyConfirm::line((new NightlyConfirm($cli->db))->tick()));
        return Cli::OK;
    }, true, true));
