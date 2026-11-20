<?php

declare(strict_types=1);

/**
 * The Profit screens' day summaries worked out again from the stored orders and lines (profit step 1; docs/decisions.md PR22;
 * CW\Profit\ProfitDays::rebuild): a safety net after a batch, and nightly for the last days. Each store and day in a transaction of its own.
 *
 *   php bin/rebuild_profit_days.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--days=N] [--channel=<store code>]
 *
 *   --days  the last N UK days up to today (default 3) when --from / --to are not given
 *
 * Exit codes: 0 done · 2 usage · 3 cannot run.
 */

use CW\Ops\Cli;
use CW\Profit\ProfitDays;
use CW\Reports\ReportRefresh;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('rebuild_profit_days', ['from:', 'to:', 'days:', 'channel:'],
    'usage: php bin/rebuild_profit_days.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--days=N] [--channel=<store code>]',
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
        $days = 0;
        for ($d = $from; $d <= $to; $d = ReportRefresh::nextDay($d)) {
            foreach ($channels as $ch) {
                ProfitDays::rebuild($cli->db, (int) $ch, $d);
            }
            $days++;
        }
        $cli->log("rebuilt {$days} days for " . count($channels) . ' stores');
        return Cli::OK;
    }, true, true));
