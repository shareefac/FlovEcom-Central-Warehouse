<?php

declare(strict_types=1);

/**
 * Loads one sales-history export (tools/sales_history/export.php, run on the Vape and Go box; the files copied next to their
 * manifest, e.g. under /srv/cw-import/ on staging) into CW, then rebuilds the demand (CW\Reorder\SalesHistoryImport and
 * DemandBuilder; docs/decisions.md I61-I62, docs/ops.md "Sales history").
 *
 *   php bin/import_sales_history.php --channel=<code> --manifest=<path> [--dry-run] [--allow-gap] [--no-build] [--db=<schema>] [--admin]
 *
 * The manifest's site must be --channel; every file's sha256 must be the manifest's. A file already loaded for the channel:
 * "already loaded as batch N", exit 0. A batch that would leave days without history before or after the loaded ones is
 * refused unless --allow-gap; an overlapping batch replaces its days. Prints the units a day per month and, for each active
 * anomaly window, its units a day against the Jul-Aug baseline. --dry-run checks and counts, writes nothing.
 *
 * Exit codes: 0 loaded (or already loaded, or a dry run) · 1 refused (sha256, gap, a bad file) or the demand build failed ·
 * 2 usage (also: the manifest is of another site) · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Ops\Cli;
use CW\Reorder\DemandBuilder;
use CW\Reorder\SalesHistoryImport;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

exit(Cli::main('import_sales_history', ['channel:', 'manifest:', 'dry-run', 'allow-gap', 'no-build'],
    'usage: php bin/import_sales_history.php --channel=<code> --manifest=<path> [--dry-run] [--allow-gap] [--no-build]',
    static function (Cli $cli, array $opts): int {
        $channel = $opts['channel'] ?? null;
        $manifest = $opts['manifest'] ?? null;
        if (!is_string($channel) || !is_string($manifest) || $channel === '' || $manifest === '') {
            throw new InvalidArgumentException('--channel=<code> and --manifest=<path> are required (once each)');
        }
        $dry = array_key_exists('dry-run', $opts);
        try {
            $r = (new SalesHistoryImport($cli->db))->import(Caller::system('import_sales_history'), $channel, $manifest, $dry, array_key_exists('allow-gap', $opts));
        } catch (CwException $e) {
            if (in_array($e->errorCode, ['site_mismatch', 'unknown_channel'], true)) {
                throw new InvalidArgumentException("{$e->errorCode}: {$e->getMessage()}");
            }
            $cli->error("{$e->errorCode}: {$e->getMessage()}");
            return Cli::PROBLEM;
        }
        if ($r['status'] === 'already') {
            $cli->log(basename($manifest) . " already loaded as batch {$r['batch_id']}");
            return Cli::OK;
        }
        $c = $r['counts'];
        $cli->log(sprintf('%s %s to %s%s: rows=%d units=%d unknown=%d rows / %d units, unlinked=%d rows / %d units, stock days=%d, latest=%d, snapshot days=%d, %d ms',
            $channel, $r['from'], $r['to'], $dry ? ' (dry run, nothing written)' : " batch {$r['batch_id']}", $c['rows_read'], $c['units_loaded'], $c['unknown_rows'],
            $c['unknown_units'], $c['unlinked_rows'], $c['unlinked_units'], $c['stock_rows'], $c['latest_rows'], $c['snapshot_days'], $r['ms']));
        if (($c['latest_kept_older'] ?? 0) === 1) {
            $cli->log("site stock: the stored snapshot of {$r['latest_kept']} is newer than this export's: kept");
        }
        $cli->log('month     days      units  units/day');
        foreach ($r['months'] as $m) {
            $cli->log(sprintf('%s  %4d %10s %10s', $m['month'], $m['days'], number_format($m['units']), $m['per_day']));
        }
        foreach ($r['anomalies'] as $a) {
            $cli->log(sprintf('anomaly %s to %s%s "%s": %s units/day in the window vs %s in Jul-Aug (%s)', $a['from'], $a['to'],
                $a['brand'] === null ? '' : " (brand {$a['brand']})", mb_substr($a['label'], 0, 60), $a['per_day'], $a['baseline_per_day'] ?? '-', $a['uplift'] ?? 'no baseline'));
        }
        if ($dry || array_key_exists('no-build', $opts)) {
            return Cli::OK;
        }
        try {
            $b = (new DemandBuilder($cli->db))->rebuild();
        } catch (CwException $e) {
            if ($e->errorCode === 'build_running') {
                $cli->log('demand: already running (another build will include this history)');
                return Cli::OK;
            }
            $cli->error("demand: {$e->errorCode}: {$e->getMessage()}");
            return Cli::PROBLEM;
        }
        $cli->log(sprintf('demand: %d items, %d listings, %d ms', $b['items'], $b['listings'], $b['ms']));
        return Cli::OK;
    }));
