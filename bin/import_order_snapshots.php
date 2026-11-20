<?php

declare(strict_types=1);

/**
 * The backfill of the stores' order snapshots (profit step 1; docs/decisions.md PR3; CONTRACT.md "Bulk"): one day file of the connector's
 * export (snapshots_<store>_<YYYY-MM-DD>.jsonl, one JSON object per line with its `ref`) through the SAME service as
 * PUT /v1/orders/{ref}/snapshot (CW\Profit\SnapshotImport, OrderSnapshots::store): the same checks, "same" and "older ignored", one
 * transaction per order. A refused line is printed with its field; the rest go on. The file's .sum line is checked when it is there.
 *
 *   php bin/import_order_snapshots.php --channel=<store code> --file=<path> [--dry-run]
 *
 *   --dry-run  read and check every line, write nothing
 *
 * Exit codes: 0 every line stored (or same / older) · 1 a line was refused or the .sum does not match · 2 usage · 3 cannot run.
 */

use CW\Ops\Cli;
use CW\Profit\SnapshotImport;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('import_order_snapshots', ['channel:', 'file:', 'dry-run'],
    'usage: php bin/import_order_snapshots.php --channel=<store code> --file=<path> [--dry-run]',
    static function (Cli $cli, array $opts): int {
        $channel = is_string($opts['channel'] ?? null) ? $opts['channel'] : throw new InvalidArgumentException('give --channel=<store code>');
        $file = is_string($opts['file'] ?? null) ? $opts['file'] : throw new InvalidArgumentException('give --file=<path>');
        $dry = array_key_exists('dry-run', $opts);
        $r = (new SnapshotImport($cli->db))->file($channel, $file, $dry, $cli->log(...));
        $cli->log(sprintf('%s: %d lines, %d orders read%s, %d refused; stored %d, same %d, older ignored %d; .sum %s',
            basename($file), $r['lines'], $r['read']['orders'], $dry ? ' (dry run: nothing written)' : '', $r['refused'], $r['results']['stored'], $r['results']['same'],
            $r['results']['older_ignored'], $r['sum_ok'] === null ? 'not there' : ($r['sum_ok'] ? 'matches' : 'DOES NOT MATCH')));
        return $r['refused'] > 0 || $r['sum_ok'] === false ? Cli::PROBLEM : Cli::OK;
    }, true, true));
