<?php

declare(strict_types=1);

/**
 * The nightly invariant check (plan §14; CW\Invariants, D44): cached buckets = unit states =
 * ledger sums, unit states agree with their reservations. docs/ops.md: nightly.
 *
 *   php bin/invariants.php [--db=cw_staging] [--admin]
 *
 * Runs on ONE consistent snapshot (CW\Ops\Snapshot: REPEATABLE READ, read-only, no locks), so it
 * is safe while the service runs and never reports a half-applied transaction as a mismatch.
 * Prints "ok" and exits 0, or prints every violation found (at most 50 per check) to stderr and
 * exits 1. Exit 3 when it cannot run (see CW\Ops\Cli).
 */

use CW\Db;
use CW\Invariants;
use CW\Ops\Cli;
use CW\Ops\Snapshot;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(
    'invariants',
    [],
    'usage: php bin/invariants.php',
    static function (Cli $cli, array $opts): int {
        $started = hrtime(true);
        [$violations, $balances, $units] = Snapshot::read($cli->db, static fn (Db $db): array => [
            Invariants::check($db),
            (int) $db->value('SELECT COUNT(*) FROM stock_balance'),
            (int) $db->value('SELECT COUNT(*) FROM reservation_unit'),
        ]);
        $stats = sprintf('balances=%d units=%d ms=%d peak_mb=%d', $balances, $units, intdiv(hrtime(true) - $started, 1_000_000),
            intdiv(memory_get_peak_usage(true), 1_048_576));
        if ($violations === []) {
            $cli->log("ok: invariants hold ({$stats})");
            return Cli::OK;
        }
        $cli->error('MISMATCH: ' . count($violations) . " invariant violation(s) ({$stats})");
        foreach ($violations as $v) {
            $cli->error("  {$v}");
        }
        $cli->log('MISMATCH: ' . count($violations) . ' violation(s); details on stderr');
        return Cli::PROBLEM;
    },
    false, // read-only: two overlapping runs do no harm
));
