<?php

declare(strict_types=1);

/**
 * ERPNext's cost at each sale for the orders the shop sent (profit step 1; docs/decisions.md PR18-PR21; CW\Profit\ErpSalesImport): the
 * day files si_<YYYY-MM-DD>.tsv of the read-only export; the lines of orders paid before CW's own price starts get ERPNext's incoming rate,
 * cost_source erp_at_sale. One reconciliation line per day. Returns are reported, not applied. Run again: the same costs again.
 *
 *   php bin/import_erp_sales_cost.php --dir=<folder> [--channel=<store code>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--apply]
 *
 *   --channel  default: the setting erp_opening.listing_store (the store ERPNext's invoices are of)
 *   --apply    write; without it a dry run: the reconciliation lines only
 *
 * Exit codes: 0 done · 1 a day file refused (nothing applied) · 2 usage · 3 cannot run.
 */

use CW\Ops\Cli;
use CW\Profit\ErpSalesImport;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('import_erp_sales_cost', ['dir:', 'channel:', 'from:', 'to:', 'apply'],
    'usage: php bin/import_erp_sales_cost.php --dir=<folder> [--channel=<store code>] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD] [--apply]',
    static function (Cli $cli, array $opts): int {
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
            $cli->log(sprintf('refused: %d day file(s); nothing applied', $r['refused']));
            return Cli::PROBLEM;
        }
        $cli->log(sprintf('done: %d days, %d orders, %d lines costed%s', $r['days'], $r['orders'], $r['lines'], array_key_exists('apply', $opts) ? '' : ' (dry run)'));
        return Cli::OK;
    }, true, true));
