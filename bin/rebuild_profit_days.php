<?php

declare(strict_types=1);

/**
 * The Profit screens' day summaries worked out again from the stored orders and lines (profit step 1; docs/decisions.md PR22;
 * CW\Profit\ProfitDays::rebuild): a safety net after a batch, and nightly for the last days. Each store and day in a transaction of its own.
 *
 *   php bin/rebuild_profit_days.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--days=N] [--channel=<store code>] [--check | --apply]
 *
 *   --days   the last N UK days up to today (default 3) when --from / --to are not given
 *   --check  (also without --apply) compare only: every figure of a day or an item whose kept summary differs from what its orders add up
 *            to, printed with both values; nothing written (the go-live check; the final review of 6d59eda, c-LOW4 / a-M8, PR104)
 *   --apply  write: each day's summaries replaced by what its orders add up to (cron, nightly)
 *
 * The check takes no lock (it reads each store-day in one consistent snapshot), so it always compares; --apply takes the job's lock and, when
 * another run holds it, does nothing and says so (exit 4; the Codex check of c48683e).
 *
 * Exit codes: 0 done (the check: no day differs) · 1 the check found a day that differs · 2 usage · 3 cannot run · 4 --apply not run: another
 * run holds the job's lock.
 */

use CW\Ops\Cli;
use CW\Profit\ProfitDays;
use CW\Reports\ReportRefresh;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('rebuild_profit_days', ['from:', 'to:', 'days:', 'channel:', 'check', 'apply'],
    'usage: php bin/rebuild_profit_days.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--days=N] [--channel=<store code>] [--check | --apply]',
    static function (Cli $cli, array $opts): int {
        $today = ReportRefresh::ukDay(\CW\Clock::db(\CW\Clock::now()));
        $n = Cli::intOpt($opts, 'days', 3, 1, 3660);
        $from = $opts['from'] ?? (new DateTimeImmutable($today))->modify('-' . ($n - 1) . ' days')->format('Y-m-d');
        $to = $opts['to'] ?? $today;
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
                throw new InvalidArgumentException("--{$k} must be a day, YYYY-MM-DD");
            }
        }
        if ($from > $to) {
            throw new InvalidArgumentException('--from must not be after --to');
        }
        $channels = is_string($opts['channel'] ?? null)
            ? $cli->db->column('SELECT id FROM channel WHERE code = ?', [$opts['channel']])
            : $cli->db->column('SELECT id FROM channel ORDER BY id');
        if ($channels === []) {
            throw new InvalidArgumentException('no such store');
        }
        $apply = array_key_exists('apply', $opts);
        if ($apply && array_key_exists('check', $opts)) {
            throw new InvalidArgumentException('--check compares only and --apply writes: give one of them');
        }
        if ($apply && !$cli->tryLock()) {
            $cli->error('not run: another run of this job holds its lock; nothing written');
            return Cli::BUSY;
        }
        $days = 0;
        $differ = 0;
        for ($d = $from; $d <= $to; $d = ReportRefresh::nextDay($d)) {
            foreach ($channels as $ch) {
                if ($apply) {
                    ProfitDays::rebuild($cli->db, (int) $ch, $d);
                    continue;
                }
                $diff = ProfitDays::differences($cli->db, (int) $ch, $d);
                if ($diff !== []) {
                    $differ++;
                    foreach (array_slice($diff, 0, 50) as $line) {
                        $cli->log("{$d} store {$ch} {$line}");
                    }
                    if (count($diff) > 50) {
                        $cli->log("{$d} store {$ch}: " . (count($diff) - 50) . ' more differences');
                    }
                }
            }
            $days++;
        }
        if ($apply) {
            $cli->log("rebuilt {$days} days for " . count($channels) . ' stores');
            return Cli::OK;
        }
        $cli->log("checked {$days} days for " . count($channels) . " stores: {$differ} store-days differ (nothing written" . ($differ > 0 ? '; --apply writes what the orders add up to)' : ')'));
        return $differ > 0 ? Cli::PROBLEM : Cli::OK;
    }, false, true));
