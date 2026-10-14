<?php

declare(strict_types=1);

/**
 * The report job (CW\Reports\ReportRefresh, docs/decisions.md PE10-PE12): keeps the Reports tabs' own tables up to date from what CW
 * records (each item's value sequence, the paid orders and the ledger's sends, cancels and returns, the imported sales history). It
 * writes ONLY those tables (report_item, report_sales_day, report_refresh), which nothing else reads and which can be rebuilt at any
 * time. Cron runs it every 15 minutes; while the setting reports.refresh_on is off it does nothing.
 *
 *   php bin/refresh_reports.php [--force] [--rebuild] [--status] [--db=<schema>] [--admin]
 *
 *   --force    run although reports.refresh_on is off (a hand run)
 *   --rebuild  empty the three tables first, then run: the reports are rebuilt from the start over the next runs (each run reads at
 *              most reports.refresh_max_rows stock changes and reports.refresh_max_days days of sales)
 *   --status   print how far the job has read, and write nothing
 *
 * Exit codes: 0 done (also: switched off, or another run holds the job's lock) · 2 usage · 3 cannot run.
 */

use CW\Ops\Cli;
use CW\Reports\ReportRefresh;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(ReportRefresh::JOB, ['force', 'rebuild', 'status'],
    'usage: php bin/refresh_reports.php [--force] [--rebuild] [--status]',
    static function (Cli $cli, array $opts): int {
        $job = new ReportRefresh($cli->db);
        $json = static fn (mixed $v): string => (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (array_key_exists('status', $opts)) {
            if (array_key_exists('force', $opts) || array_key_exists('rebuild', $opts)) {
                throw new InvalidArgumentException('--status reads only: give it alone');
            }
            $cli->log('status ' . $json($job->state()));
            return Cli::OK;
        }
        if (array_key_exists('rebuild', $opts)) {
            $cli->db->exec('DELETE FROM report_item');
            $cli->db->exec('DELETE FROM report_sales_day');
            $cli->db->exec('DELETE FROM report_refresh');
            $cli->log('rebuild: the report tables were emptied');
        }
        $r = $job->run(array_key_exists('force', $opts));
        $cli->log($r['off'] ? 'switched off (setting reports.refresh_on): nothing done' : 'done ' . $json($r));
        return Cli::OK;
    }, true, true));
