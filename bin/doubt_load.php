<?php

declare(strict_types=1);

/**
 * Loads a list of items whose stock figure is in doubt into Inventory > Doubtful stock (CW\Counts\StockDoubt; docs/decisions.md DS1-; the
 * owner, 12 Nov 2026). Staff then check each item on the shelf and clear it there ("Save count") or say it is not needed.
 *
 *   php bin/doubt_load.php --db=<schema> --store=<store code> --csv=<file> --batch=<list name> [--apply]
 *
 * The list is a CSV with the columns cw_code, shop_qty, cw_qty, reason, sold_30d, note (any others are ignored; reason is differs,
 * below_zero or other; shop_qty, sold_30d and note may be empty). Every row is checked first: one bad row, an item twice, an item CW does
 * not have or one merged into another refuses the whole list and nothing is written.
 *
 * DRY RUN by default: one line per row with what it would do (add: a new open row; refresh: the open row takes these figures; same: the
 * open row has them already; skip: the item was answered already in this same list), then the counts. Nothing is written. --apply writes
 * them all in ONE transaction, with one audit row (stock_doubt.load). An item already open is refreshed, never doubled; running the same
 * list again opens nothing that was answered; a list of another name opens it again.
 *
 * Exit codes: 0 done (or dry run) · 1 the list was refused (nothing written) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\Counts\StockDoubt;
use CW\CwException;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(StockDoubt::JOB, ['store:', 'csv:', 'batch:', 'apply'],
    'usage: php bin/doubt_load.php --store=<store code> --csv=<file> --batch=<list name> [--apply]',
    static function (Cli $cli, array $opts): int {
        $code = $opts['store'] ?? null;
        $file = $opts['csv'] ?? null;
        if (!is_string($code) || $code === '' || !is_string($file) || $file === '' || !is_string($opts['batch'] ?? null)) {
            throw new InvalidArgumentException('--store, --csv and --batch are required');
        }
        $batch = StockDoubt::batch((string) $opts['batch']);
        $channelId = $cli->db->value('SELECT id FROM channel WHERE code = ?', [$code]);
        if ($channelId === null) {
            throw new InvalidArgumentException("no store {$code}");
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new InvalidArgumentException("cannot read {$file}");
        }
        $apply = array_key_exists('apply', $opts);
        $svc = new StockDoubt($cli->db);
        try {
            $rows = StockDoubt::readFile($file);
            $sum = hash_file('sha256', $file);
            $plan = $apply ? $svc->load(Caller::system(StockDoubt::JOB), (int) $channelId, $rows, $batch, basename($file) . ' sha256:' . $sum)
                : $svc->plan((int) $channelId, $rows, $batch);
        } catch (CwException $e) {
            $cli->error('the list was refused, nothing written: ' . $e->getMessage());
            return Cli::PROBLEM;
        }
        $fig = static fn (?int $v): string => $v === null ? '-' : (string) $v;
        $dry = $apply ? '' : 'DRY RUN (nothing written) ';
        foreach ($plan['rows'] as $r) {
            $cli->log(sprintf('%srow %d %s %s %s shop=%s cw=%d sold=%s', $dry, $r['row'], $r['cw_code'], $r['action'], $r['reason'], $fig($r['shop_qty']), $r['cw_qty'],
                $fig($r['sold_30d'])));
        }
        $c = $plan['counts'];
        $cli->log(sprintf('%sstore=%s batch=%s rows=%d add=%d refresh=%d same=%d skip=%d open_now=%d', $dry, $code, $batch, count($plan['rows']), $c['add'], $c['refresh'],
            $c['same'], $c['skip'], $svc->openCount((int) $channelId)));
        return Cli::OK;
    }, true, true));
