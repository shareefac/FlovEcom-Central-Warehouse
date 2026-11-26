<?php

declare(strict_types=1);

/**
 * Global Payments' card fees in CW's profit (CW-1174; docs/decisions.md GPF1-GPF14; CW\Profit\GpFees). Nothing is read from Global Payments:
 * every card order's fee is estimated at its account's rate when its snapshot is stored, and an invoice entered on Profit > Card fees replaces
 * its month's estimates. This job does what waits:
 *
 *   php bin/gp_fees.php                         every 10 minutes: the months queued spread again, an account's days gone over again when its
 *                                               settings changed, estimates at an old rate worked out again, invoices due spread
 *   php bin/gp_fees.php --nightly               the same, and the last 62 days of each account gone over again
 *   php bin/gp_fees.php --backfill --account=vpg_online --from=2026-11-01 --to=2026-11-28   these UK days gone over (the go-live kit)
 *   php bin/gp_fees.php --status [--account=vpg_online]   read only: the settings, the newest runs, the months and their invoices
 *
 * Exit codes: 0 done (also: skipped, another run holds the job's lock) · 1 a problem (the run is recorded as failed) · 2 usage · 4 busy (a
 * backfill asked for by hand while another run holds the lock: nothing done, run it again).
 */

use CW\Caller;
use CW\Ops\Cli;
use CW\Profit\GpFees;
use CW\Profit\ProfitViews;

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = 'usage: php bin/gp_fees.php [--nightly | --backfill --account=<vpg_online|ef_online> --from=YYYY-MM-DD --to=YYYY-MM-DD | --status [--account=<name>]]';
exit(Cli::main('gp_fees', ['nightly', 'backfill', 'status', 'account:', 'from:', 'to:'], $usage,
    static function (Cli $cli, array $opts) use ($usage): int {
        $account = isset($opts['account']) ? (string) $opts['account'] : null;
        if ($account !== null && !in_array($account, GpFees::ACCOUNTS, true)) {
            throw new InvalidArgumentException("--account must be one of " . implode(', ', GpFees::ACCOUNTS) . "\n{$usage}");
        }
        $modes = (int) isset($opts['nightly']) + (int) isset($opts['backfill']) + (int) isset($opts['status']);
        if ($modes > 1 || ((isset($opts['from']) || isset($opts['to'])) && !isset($opts['backfill']))) {
            throw new InvalidArgumentException($usage);
        }
        $day = static fn (mixed $v): ?string => is_string($v) && preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $v) === 1 ? $v : null;
        if (isset($opts['backfill']) && ($account === null || $day($opts['from'] ?? null) === null || $day($opts['to'] ?? null) === null || $opts['from'] > $opts['to'])) {
            throw new InvalidArgumentException("a backfill needs --account, --from and --to (from on or before to)\n{$usage}");
        }
        if (isset($opts['status'])) {
            $out = [];
            foreach (GpFees::readConfig($cli->db) as $a => $c) {
                if ($account !== null && $a !== $account) {
                    continue;
                }
                $out[] = sprintf('%s: %s, from %s until %s, shops %s, rate %s%%, merchant number %s', $a, $c['on'] ? 'switched on' : 'not switched on', $c['from'] ?? '-',
                    $c['until'] ?? '-', implode(',', $c['codes']), $c['rate'] === null ? '-' : \CW\Profit\ProfitMath::ratePercent($c['rate']), $c['merchant'] === '' ? 'not set' : 'set');
                foreach ($cli->db->all('SELECT id, kind, state, from_day, to_day, orders_read, rows_written, error, started_at, finished_at FROM gp_run WHERE account = ? '
                    . 'ORDER BY id DESC LIMIT 5', [$a]) as $r) {
                    $out[] = sprintf('  run %d %s %s%s: %d orders read, %d rows written%s; %s - %s', $r['id'], $r['kind'], $r['state'],
                        $r['from_day'] === null ? '' : " {$r['from_day']}..{$r['to_day']}", $r['orders_read'], $r['rows_written'],
                        $r['error'] === null ? '' : " ({$r['error']})", $r['started_at'], $r['finished_at'] ?? 'running');
                }
                foreach ($cli->db->all("SELECT LEFT(day, 7) AS m, state, COUNT(*) AS n, SUM(base_pence) AS b, SUM(fee_pence) AS f FROM gp_fee_order WHERE account = ? "
                    . 'GROUP BY m, state ORDER BY m, state', [$a]) as $r) {
                    $out[] = sprintf('  %s %-8s %7d orders, takings %s, fees %s', $r['m'], $r['state'], $r['n'], ProfitViews::gbp((int) $r['b']), ProfitViews::gbp((int) $r['f']));
                }
                foreach ($cli->db->all('SELECT id, month, net_pence, sales_pence, spread_state, spread_pence, other_pence, deleted_at FROM gp_invoice WHERE account = ? ORDER BY month, id',
                    [$a]) as $r) {
                    $out[] = sprintf('  invoice %d %s%s: net %s on sales %s, spread %s (%s on orders, %s on no order)', $r['id'], $r['month'], $r['deleted_at'] === null ? '' : ' (deleted)',
                        ProfitViews::gbp((int) $r['net_pence']), ProfitViews::gbp((int) $r['sales_pence']), $r['spread_state'], ProfitViews::gbp((int) $r['spread_pence']),
                        ProfitViews::gbp((int) $r['other_pence']));
                }
                $out[] = sprintf('  months queued: %d', (int) $cli->db->value('SELECT COUNT(*) FROM gp_due WHERE account = ?', [$a]));
            }
            fwrite(STDOUT, implode("\n", $out) . "\n");
            return Cli::OK;
        }
        // the nightly waits for a 10-minute run that holds the lock (at most 5 minutes) rather than being skipped
        if (!$cli->tryLock(isset($opts['nightly']) ? 300 : 0)) {
            $cli->log('another run of this job holds its lock: nothing done' . (isset($opts['backfill']) ? '; run it again' : ''));
            return isset($opts['backfill']) ? Cli::BUSY : Cli::OK;
        }
        $kind = isset($opts['backfill']) ? 'backfill' : (isset($opts['nightly']) ? 'nightly' : 'job');
        $did = (new GpFees($cli->db))->run(Caller::system('gp_fees'), $kind, $opts['from'] ?? null, $opts['to'] ?? null, $account);
        foreach ($did as $a => $d) {
            $cli->log(sprintf('%s (%s): %d months queued taken, %d days gone over (%d orders, %d rows written), %d months settled, %d left for the next run', $a, $kind,
                $d['dues'], $d['days'], $d['orders'], $d['written'], $d['spread'], $d['left']));
        }
        return Cli::OK;
    }));
