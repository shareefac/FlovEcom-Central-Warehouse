<?php

declare(strict_types=1);

/**
 * ERPNext's cost at each sale for the orders the shop sent (profit step 1; docs/decisions.md PR18-PR21; CW\Profit\ErpSalesImport): the
 * day files si_<YYYY-MM-DD>.tsv of the read-only export; the lines of orders paid before CW's own price starts get ERPNext's incoming rate,
 * cost_source erp_at_sale. One reconciliation line per day. Returns are reported, not applied. Run again: the same costs again.
 *
 *   php bin/import_erp_sales_cost.php --dir=<folder> [--channel=<store code>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--apply]
 *
 *   --channel  default: the setting erp_opening.listing_store (the store ERPNext's invoices are of); any other store is refused (PR140: the
 *              export names no shop of its own; its item codes are that store's variants)
 *   --apply    write; without it a dry run: the reconciliation lines only
 *
 * Exit codes: 0 done · 1 a day file refused, or no day file in the days asked for (nothing applied) · 2 usage (also: a --channel that is not the
 * store ERPNext's items are of, PR140) · 3 cannot run · 4 not run: another import holds the job's lock: nothing done (PR135).
 */

use CW\Ops\Cli;
use CW\Profit\ErpSalesImport;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('import_erp_sales_cost', ['dir:', 'channel:', 'from:', 'to:', 'apply'],
    'usage: php bin/import_erp_sales_cost.php --dir=<folder> [--channel=<store code>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--apply]',
    static function (Cli $cli, array $opts): int {
        // One run at a time (the job's lock): held by another run (the cost job of every ten minutes, a second copy of this tool), nothing is done
        // and it says so with its own exit code, never 0 (the second final review of 3c31d94, a-M5, b-M1, c-M8; docs/decisions.md PR135).
        if (!$cli->tryLock()) {
            $cli->error('busy: another run of this job holds its lock: nothing done (run it again)');
            return Cli::BUSY;
        }
        $dir = is_string($opts['dir'] ?? null) ? $opts['dir'] : throw new InvalidArgumentException('give --dir=<folder>');
        if (!is_dir($dir)) {
            throw new InvalidArgumentException("no folder {$dir}");
        }
        $day = static function (mixed $v, string $name): ?string {
            if ($v === null) {
                return null;
            }
            if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
                throw new InvalidArgumentException("--{$name} must be a day, YYYY-MM-DD");
            }
            return $v;
        };
        $channel = is_string($opts['channel'] ?? null) ? $opts['channel']
            : (string) json_decode((string) ($cli->db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'erp_opening.listing_store'") ?? '"vapeandgo"'));
        $r = (new ErpSalesImport($cli->db))->run($dir, $channel, $day($opts['from'] ?? null, 'from'), $day($opts['to'] ?? null, 'to'), array_key_exists('apply', $opts),
            $cli->log(...));
        if ($r['refused'] > 0) {
            // A day file that cannot be read whole stops the import (nothing applied): a problem, so a go-live step that checks the exit code
            // stops here (the Codex re-check of e5d2e5a).
            $cli->log(sprintf('refused: %d day file(s) (or no day file in the days asked for; named above); nothing applied', $r['refused']));
            return Cli::PROBLEM;
        }
        $cli->log(sprintf('done: %d days, %d orders, %d lines costed%s', $r['days'], $r['orders'], $r['lines'], array_key_exists('apply', $opts) ? '' : ' (dry run)'));
        if ($r['waiting'] > 0) {
            // Left, not costed (the Codex check of 2b718e7): a newer snapshot of each waits for the money job.
            $cli->log("{$r['waiting']} orders left: a newer snapshot of each waits for the money job (bin/apply_order_snapshots.php); run this again after it");
        }
        return Cli::OK;
    }, false, true));
