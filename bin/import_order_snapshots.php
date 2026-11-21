<?php

declare(strict_types=1);

/**
 * The backfill of the stores' order snapshots (profit step 1; docs/decisions.md PR3; CONTRACT.md "Bulk"): one day file of the connector's
 * export (snapshots_<store>_<YYYY-MM-DD>.jsonl, one JSON object per line with its `ref`) through the SAME service as
 * PUT /v1/orders/{ref}/snapshot (CW\Profit\SnapshotImport, OrderSnapshots::store): the same checks, "same" and "older ignored", one
 * transaction per order. A refused line is printed with its field; the rest go on. The file's .sum line is checked when it is there.
 *
 *   php bin/import_order_snapshots.php --channel=<store code> --file=<path> [--dry-run] [--allow-unreserved]
 *
 *   --dry-run           read and check every line, write nothing
 *   --allow-unreserved  load orders placed before the store's first reservation in CW too (orders CW never held: a return of one is booked
 *                       through the historical path, which the profit does not match; the second final review of 3c31d94, c-HIGH3, PR148).
 *                       Without it such a file is refused whole, nothing written (the go-live backfill starts on the first whole day after it)
 *
 * The file holds each order once, each placed on the file's own London day (PR138): else it is refused whole, nothing written.
 *
 * Stores AND works out each order now (OrderSnapshots::store, in this process: never in the web server's workers).
 *
 * Exit codes: 0 every line stored (or same / older) · 1 a line was refused or the .sum does not match · 2 usage · 3 cannot run ·
 * 4 not run: another import holds the job's lock (nothing read: run it again; the final review of 6d59eda, a-M4, PR98).
 */

use CW\Ops\Cli;
use CW\Profit\SnapshotImport;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('import_order_snapshots', ['channel:', 'file:', 'dry-run', 'allow-unreserved'],
    'usage: php bin/import_order_snapshots.php --channel=<store code> --file=<path> [--dry-run] [--allow-unreserved]',
    static function (Cli $cli, array $opts): int {
        $channel = is_string($opts['channel'] ?? null) ? $opts['channel'] : throw new InvalidArgumentException('give --channel=<store code>');
        $file = is_string($opts['file'] ?? null) ? $opts['file'] : throw new InvalidArgumentException('give --file=<path>');
        $dry = array_key_exists('dry-run', $opts);
        // One import at a time (the job's lock); a second one started meanwhile reads nothing and says so with its own exit code, never "done".
        if (!$cli->tryLock()) {
            $cli->error('not run: another import of order snapshots holds the job\'s lock; nothing was read: run this again');
            return Cli::BUSY;
        }
        // The store's first reservation in CW, read now (vapeandgo 28 Oct 2026, electrofag and vapebig 16 Nov 2026 on live): the orders placed
        // before it are refused unless --allow-unreserved (PR148).
        $notBefore = null;
        if (!array_key_exists('allow-unreserved', $opts)) {
            $first = $cli->db->value('SELECT MIN(r.created_at) FROM reservation r JOIN channel c ON c.id = r.channel_id WHERE c.code = ?', [$channel]);
            $notBefore = $first === null ? '9999-12-31 00:00:00.000000' : (string) $first;
            $cli->log('the store\'s first reservation in CW: ' . ($first === null ? 'none yet (every order is before it)' : $notBefore . ' UTC'));
        }
        $r = (new SnapshotImport($cli->db))->file($channel, $file, $dry, $cli->log(...), $notBefore);
        $cli->log(sprintf('%s: %d lines, %d orders read%s, %d refused; stored %d, same %d, older ignored %d; .sum file %s; totals %s%s',
            basename($file), $r['lines'], $r['read']['orders'], $dry ? ' (dry run: nothing written)' : '', $r['refused'], $r['results']['stored'], $r['results']['same'],
            $r['results']['older_ignored'], $r['sum_ok'] ? 'matches' : 'DOES NOT MATCH', $r['totals_checked'] ? 'match' : 'NOT CHECKED',
            $r['file_refused'] > 0 ? "; FILE REFUSED: {$r['file_refused']} order(s) not of this file (named above)" : ''));
        return $r['refused'] > 0 || !$r['sum_ok'] || !$r['totals_checked'] ? Cli::PROBLEM : Cli::OK;
    }, false, true));
