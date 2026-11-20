<?php

declare(strict_types=1);

/**
 * The start of the buying prices (profit step 1; docs/decisions.md PR12-PR14; CW\Profit\CostEngine): each CW item's price on the day CW's
 * own price starts (ERPNext's Bin valuation, else CW's average so far, else the supplier's last price, else none), then the replay of
 * every delivery, invoice and credit note since then, then the cost of every stored sale that waited for it. The start is written once; a
 * start stopped half way is run again the same way and goes on with the items not written yet, or (stopped after the start was written)
 * with the replay and the sales that waited.
 *
 *   php bin/seed_item_cost.php --bin=<erp_bin.tsv> [--start=YYYY-MM-DD] [--store=<code>] [--apply]
 *
 *   --bin    ERPNext's Bin as tab-separated text with a header row (item_code, warehouse, actual_qty, valuation_rate, ...)
 *   --start  the UK day the prices start (default: the setting profit.cost_start)
 *   --store  the store whose variant numbers ERPNext's item codes carry (default: the setting erp_opening.listing_store)
 *   --apply  write the start and replay since it; without it: a dry run that prints the counts and the items it could not price
 *
 * Exit codes: 0 done · 2 usage · 3 cannot run.
 */

use CW\Ops\Cli;
use CW\Profit\CostEngine;
use CW\Profit\PendingCosts;
use CW\Profit\ProfitSettings;
use CW\Reports\ReportRefresh;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main(CostEngine::JOB, ['bin:', 'start:', 'store:', 'apply'],
    'usage: php bin/seed_item_cost.php --bin=<erp_bin.tsv> [--start=YYYY-MM-DD] [--store=<code>] [--apply]',
    static function (Cli $cli, array $opts): int {
        $bin = is_string($opts['bin'] ?? null) ? $opts['bin'] : throw new InvalidArgumentException('give --bin=<erp_bin.tsv>');
        if (!is_file($bin) || !is_readable($bin)) {
            throw new InvalidArgumentException("cannot read {$bin}");
        }
        $settings = new ProfitSettings($cli->db);
        $day = is_string($opts['start'] ?? null) ? $opts['start'] : $settings->costStartDay();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1 || !checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            throw new InvalidArgumentException('--start must be a day, YYYY-MM-DD');
        }
        $store = is_string($opts['store'] ?? null) ? $opts['store']
            : (string) json_decode((string) ($cli->db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'erp_opening.listing_store'") ?? '"vapeandgo"'));
        $engine = new CostEngine($cli->db);
        $plan = $engine->plan((string) file_get_contents($bin), $store);
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
        $startAt = \CW\Clock::db(ReportRefresh::bounds($day)[0]);
        if ($engine->state()['mark_at'] !== null) {
            // Stopped after the start was written (it is written once): run again the same way, it goes on with the replay and the sales that
            // waited (the Codex re-check of e5d2e5a).
            $cli->log('the start was written before: going on with the replay since then');
        } else {
            $n = $engine->seed($plan['prices'], $startAt);
            $cli->log("started {$n} items at {$day} (UK); replaying since then");
        }
        do {
            $r = $engine->run();
            $cli->log('replay ' . json_encode($r, JSON_UNESCAPED_SLASHES));
        } while (($r['events'] ?? 0) >= CostEngine::MAX_EVENTS);
        do {
            $p = (new PendingCosts($cli->db))->run();
            $cli->log('sales costed that waited for the start: ' . $p['orders'] . ' orders');
        } while ($p['orders'] >= PendingCosts::MAX_ORDERS);
        return Cli::OK;
    }, true, true));
