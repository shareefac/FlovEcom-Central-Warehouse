<?php

declare(strict_types=1);

/**
 * The nightly invariant check (plan §14; CW\Invariants::nightly, D44): cached buckets = unit states =
 * ledger sums, unit states agree with their reservations, the modules' own checks, and since 0019 the configuration the screens
 * change (K1-K3). docs/ops.md: nightly.
 *
 *   php bin/invariants.php [--db=cw_staging] [--admin]
 *
 * Runs on ONE consistent snapshot (CW\Ops\Snapshot: REPEATABLE READ, read-only, no locks), so it
 * is safe while the service runs and never reports a half-applied transaction as a mismatch.
 * Prints "ok" and exits 0, or prints every violation found (at most 50 per check) to stderr and
 * exits 1. Exit 3 when it cannot run (see CW\Ops\Cli). Every run that got as far as the check is recorded in integrity_run
 * (CW\Ops\IntegrityRuns, 0019, G36): the Safety checks page and a Home card read it. A failure to record is logged and does not
 * change the exit code.
 */

use CW\Clock;
use CW\Db;
use CW\Invariants;
use CW\Ops\Cli;
use CW\Ops\IntegrityRuns;
use CW\Ops\Snapshot;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(
    'invariants',
    [],
    'usage: php bin/invariants.php',
    static function (Cli $cli, array $opts): int {
        $started = hrtime(true);
        $startedAt = Clock::db(Clock::now());
        [$violations, $balances, $units] = Snapshot::read($cli->db, static fn (Db $db): array => [
            Invariants::nightly($db),
            (int) $db->value('SELECT COUNT(*) FROM stock_balance'),
            (int) $db->value('SELECT COUNT(*) FROM reservation_unit'),
        ]);
        $ms = intdiv(hrtime(true) - $started, 1_000_000);
        $stats = sprintf('balances=%d units=%d ms=%d peak_mb=%d', $balances, $units, $ms, intdiv(memory_get_peak_usage(true), 1_048_576));
        try {
            (new IntegrityRuns($cli->db))->record($startedAt, $violations, ['balances' => $balances, 'units' => $units, 'ms' => $ms], 'system:invariants');
        } catch (\Throwable $e) {
            $cli->error('could not record the run for the Safety checks page: ' . $e->getMessage());
        }
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
