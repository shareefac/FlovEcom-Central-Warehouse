<?php

declare(strict_types=1);

/**
 * The start of the buying prices (profit step 1; docs/decisions.md PR12-PR14, PR99; CW\Profit\CostEngine): each CW item's price on the day
 * CW's own price starts (ERPNext's Bin valuation, else CW's average of what came in BEFORE that day, else the supplier's last price known
 * before it, else none: never a figure that holds a delivery the replay adds again), then the replay of
 * every delivery, invoice and credit note since then, then the cost of every stored sale that waited for it. The start is written once; a
 * start stopped half way is run again the same way and goes on with the items not written yet, or (stopped after the start was written)
 * with the replay and the sales that waited.
 *
 *   php bin/seed_item_cost.php --bin=<erp_bin.tsv> [--start=YYYY-MM-DD] [--store=<code>] [--allow-skips=<code,code,row:N>] [--apply]
 *
 *   --bin          ERPNext's Bin as tab-separated text with a header row (item_code, warehouse, actual_qty, valuation_rate, ...)
 *   --start        the UK day the prices start (default: the setting profit.cost_start)
 *   --store        the store whose variant numbers ERPNext's item codes carry (default: the setting erp_opening.listing_store)
 *   --allow-skips  the broken Bin rows that may be left out, named by item code (or row:<n> for a row with none)
 *   --rows         the number of rows the export counted (ERPNext's own count of the Bin rows it wrote): a file with another number of rows
 *                  is cut short or grown, and refused
 *   --apply        write the start and replay since it; without it: a dry run that prints the counts and the items it could not price
 *
 * The Bin file is checked whole first: a row without the header's columns, or whose actual_qty or valuation_rate is blank or not a number,
 * is broken; while one is broken (and not named by --allow-skips) nothing is written, in the dry run too, and it ends with exit 1 naming
 * every one (the final review of 6d59eda, b-HIGH3).
 *
 * Exit codes: 0 done (with --apply: the start's mark checked after it was written) · 1 the Bin file has broken rows, or the start's mark is not
 * there after it was written · 2 usage · 3 cannot run · 4 not run: another run of the cost job (or of this tool) holds its lock: nothing done
 * (run it again; PR135).
 */

use CW\Ops\Cli;
use CW\Profit\CostEngine;
use CW\Profit\PendingCosts;
use CW\Profit\ProfitSettings;
use CW\Reports\ReportRefresh;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(CostEngine::JOB, ['bin:', 'start:', 'store:', 'allow-skips:', 'rows:', 'apply'],
    'usage: php bin/seed_item_cost.php --bin=<erp_bin.tsv> [--start=YYYY-MM-DD] [--store=<code>] [--allow-skips=<code,code,row:N>] [--rows=N] [--apply]',
    static function (Cli $cli, array $opts): int {
        // One run at a time (the job's lock): held by another run (the cost job of every ten minutes, a second copy of this tool), nothing is done
        // and it says so with its own exit code, never 0 (the second final review of 3c31d94, a-M5, b-M1, c-M8; docs/decisions.md PR135).
        if (!$cli->tryLock()) {
            $cli->error('busy: another run of this job holds its lock: nothing done (run it again)');
            return Cli::BUSY;
        }
        $bin = is_string($opts['bin'] ?? null) ? $opts['bin'] : throw new InvalidArgumentException('give --bin=<erp_bin.tsv>');
        if (!is_file($bin) || !is_readable($bin)) {
            throw new InvalidArgumentException("cannot read {$bin}");
        }
        $settings = new ProfitSettings($cli->db);
        $day = is_string($opts['start'] ?? null) ? $opts['start'] : $settings->costStartDay();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            throw new InvalidArgumentException('--start must be a day, YYYY-MM-DD');
        }
        // ERPNext's Bin item codes are "P" + a variant of the store ERPNext's items are of (erp_opening.listing_store): the seed reads them through
        // that store only; another --store is refused before the file is read (as the ERPNext sales import: the third final review, a-M / b-M2,
        // PR154: another store's variant of the same number is another item).
        $own = (string) json_decode((string) ($cli->db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'erp_opening.listing_store'") ?? '"vapeandgo"'));
        $store = is_string($opts['store'] ?? null) ? $opts['store'] : $own;
        if ($store !== $own) {
            throw new InvalidArgumentException("ERPNext's Bin is of the store {$own} (its item codes are that store's variants): --store={$store} is refused");
        }
        $engine = new CostEngine($cli->db);
        $startAt = \CW\Clock::db(ReportRefresh::bounds($day)[0]);
        $allow = is_string($opts['allow-skips'] ?? null) ? array_values(array_filter(array_map('trim', explode(',', $opts['allow-skips'])), static fn (string $c): bool => $c !== '')) : [];
        // Every price as of the start itself (PR99): what came in after it is the replay's, never in a starting price.
        $rows = array_key_exists('rows', $opts) ? Cli::intOpt($opts, 'rows', 0, 1, 10_000_000) : null;
        $plan = $engine->plan((string) file_get_contents($bin), $store, $startAt, $allow, $rows);
        foreach ($plan['skipped'] as $b) {
            $cli->log("Bin row {$b['row']} ({$b['item_code']}) left out as --allow-skips says: it {$b['why']}");
        }
        if ($plan['broken'] !== []) {
            foreach (array_slice($plan['broken'], 0, 100) as $b) {
                $cli->error($b['row'] === 0 ? "the Bin file {$b['why']}"
                    : "Bin row {$b['row']} (" . ($b['item_code'] === '' ? 'no item code' : $b['item_code']) . ") is broken: it {$b['why']}");
            }
            $cli->error(count($plan['broken']) . ' broken Bin row(s): nothing written. Export the Bin again, or name the rows that may be left out with '
                . '--allow-skips=<item code,...> (row:<n> for a row with no item code)');
            return Cli::PROBLEM;
        }
        $cli->log(sprintf('items %d: ERPNext %d, CW average %d, supplier price %d, none %d (Bin rows %d, variants not matched to a CW item %d)',
            count($plan['prices']), $plan['counts']['erp_bin'], $plan['counts']['cw_average'], $plan['counts']['supplier_price'], $plan['counts']['none'],
            $plan['bin_rows'], $plan['bin_unmatched']));
        $none = array_keys(array_filter($plan['prices'], static fn (array $p): bool => $p['e4'] === null));
        if ($none !== []) {
            $codes = $cli->db->column('SELECT code FROM sku WHERE id IN (' . implode(', ', array_map('intval', array_slice($none, 0, 200))) . ') ORDER BY id');
            $cli->log('no price (first ' . count($codes) . '): ' . implode(' ', array_map('strval', $codes)));
        }
        if (!array_key_exists('apply', $opts)) {
            $cli->log('dry run: nothing written (add --apply)');
            return Cli::OK;
        }
        if ($engine->state()['mark_at'] !== null) {
            // Stopped after the start was written (it is written once): run again the same way, it goes on with the replay and the sales that
            // waited (the Codex re-check of e5d2e5a). Only for the start asked for: one written for another day is said, nothing done (the
            // Codex check of the fix round, F6 PARTLY, PR152).
            $floor = (string) ($cli->db->value('SELECT floor_at FROM profit_job WHERE job = ?', [CostEngine::JOB]) ?? '');
            if ($floor !== $startAt) {
                $cli->error("the start was written for {$floor} UTC, not {$day} (UK, {$startAt} UTC): nothing done (give --start of the start written)");
                return Cli::PROBLEM;
            }
            $cli->log("the start written for {$day} (UK) was checked: going on with the replay since then");
        } else {
            $n = $engine->seed($plan['prices'], $startAt);
            // Checked as written: the job's mark and floor at the start (PR135; the go-live step reads "started N items").
            $job = $cli->db->one('SELECT mark_at, floor_at FROM profit_job WHERE job = ?', [CostEngine::JOB]);
            if ($job === null || $job['mark_at'] === null || (string) $job['floor_at'] !== $startAt) {
                $cli->error("the start was not found written (the cost job's mark and floor at {$startAt}): run this again");
                return Cli::PROBLEM;
            }
            $cli->log("started {$n} items at {$day} (UK); replaying since then");
        }
        do {
            $r = $engine->run();
            $cli->log('replay ' . json_encode($r, JSON_UNESCAPED_SLASHES));
        } while (($r['events'] ?? 0) >= CostEngine::MAX_EVENTS);
        // The sales that waited; one that fails is named and the run ends with a problem (the Codex check of the fix round, MEDIUM 2, PR152);
        // a page is full when its orders done and failed fill it (a failed one never hides the next ones).
        $failed = 0;
        do {
            $p = (new PendingCosts($cli->db))->run();
            $cli->log('sales costed that waited for the start: ' . $p['orders'] . ' orders' . ($p['failed'] > 0 ? ", {$p['failed']} failed" : ''));
            foreach ($p['errors'] as $x) {
                $cli->error("store {$x['store']} order {$x['order']} failed: {$x['error']}");
            }
            $failed += $p['failed'];
        } while ($p['orders'] >= PendingCosts::MAX_ORDERS && $p['failed'] === 0);
        return $failed > 0 ? Cli::PROBLEM : Cli::OK;
    }, false, true));
