<?php

declare(strict_types=1);

/**
 * The one deliberate re-cost of stored sales (profit step 1; docs/decisions.md PR16; CW\Profit\RecostSales): the lines of the orders paid
 * on these UK days whose cost came from these sources get the buying price at the time they were paid again (costed_by recost).
 *
 *   php bin/recost_sales.php --from=YYYY-MM-DD --to=YYYY-MM-DD [--source=none,estimate,...] [--channel=<store code>] [--items=<file>] [--apply]
 *
 *   --source  one or more of none, estimate, cw_cost, erp_at_sale, or all (= none, estimate, cw_cost: erp_at_sale only when named;
 *             default: none: the lines with no buying price)
 *   --items   (CW-1153, docs/decisions.md VB12) a file of CW codes, one a line: only the lines of those items are re-costed (every other line of
 *             a mixed order keeps its cost). The whole file is refused when one line is not a known item's code (named by its line number).
 *   --apply   write; without it a dry run that prints what would move
 *   --fail-deferred  (CW-1153, the Codex review of bd9104d, H5: a go-live kit) exit 1 when an order was left because a newer snapshot of it
 *             waits for the money job, or (the final review, H9) a line because the cost job has not reached its paid time (the kit applies the
 *             queue, runs the cost job and runs this again until none is left)
 *
 * Exit codes: 0 done · 1 (--fail-deferred) orders left · 2 usage · 3 cannot run · 4 not run: another re-cost holds the job's lock: nothing
 * done (PR135).
 */

use CW\Ops\Cli;
use CW\Profit\RecostSales;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('recost_sales', ['from:', 'to:', 'source:', 'channel:', 'items:', 'apply', 'fail-deferred'],
    'usage: php bin/recost_sales.php --from=YYYY-MM-DD --to=YYYY-MM-DD [--source=none,estimate,cw_cost,erp_at_sale|all] [--channel=<code>] [--items=<file>] [--apply] [--fail-deferred]',
    static function (Cli $cli, array $opts): int {
        // One run at a time (the job's lock): held by another run (the cost job of every ten minutes, a second copy of this tool), nothing is done
        // and it says so with its own exit code, never 0 (the second final review of 3c31d94, a-M5, b-M1, c-M8; docs/decisions.md PR135).
        if (!$cli->tryLock()) {
            $cli->error('busy: another run of this job holds its lock: nothing done (run it again)');
            return Cli::BUSY;
        }
        $day = static function (mixed $v, string $name): string {
            if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
                throw new InvalidArgumentException("give --{$name}=YYYY-MM-DD");
            }
            return $v;
        };
        $from = $day($opts['from'] ?? null, 'from');
        $to = $day($opts['to'] ?? null, 'to');
        if ($from > $to) {
            throw new InvalidArgumentException('--from must not be after --to');
        }
        $src = is_string($opts['source'] ?? null) ? $opts['source'] : 'none';
        // all = none, estimate, cw_cost: never erp_at_sale (ERPNext's cost is withdrawn only when asked by name, PR154).
        $sources = RecostSales::sources($src);
        $apply = array_key_exists('apply', $opts);
        $skuIds = is_string($opts['items'] ?? null) ? \CW\Items\ItemCodes::fromFile($cli->db, $opts['items']) : null;
        $r = (new RecostSales($cli->db))->run($from, $to, $sources, is_string($opts['channel'] ?? null) ? $opts['channel'] : null, $apply, $skuIds);
        foreach ($r['moves'] as $move => $n) {
            $cli->log("{$move}: {$n} lines");
        }
        $cli->log(sprintf('%d orders, %d lines %s', $r['orders'], $r['lines'], $apply ? 're-costed' : 'would be re-costed (dry run: add --apply)'));
        if ($r['deferred'] > 0) {
            $cli->log("{$r['deferred']} orders left: a newer snapshot of each waits for the money job (bin/apply_order_snapshots.php); run this again after it");
        }
        if ($r['pending'] > 0) {
            // CW-1153 (the final review, H9): never passed over silently
            $cli->log("{$r['pending']} lines left: the cost job has not reached their paid time (bin/refresh_item_cost.php); run this again after it");
        }
        if (($r['deferred'] > 0 || $r['pending'] > 0) && array_key_exists('fail-deferred', $opts)) {
            $cli->error("deferred={$r['deferred']} pending={$r['pending']}");
            return Cli::PROBLEM;
        }
        return Cli::OK;
    }, false, true));
