<?php

declare(strict_types=1);

/**
 * The owner's weekly list as regular costs (CW-1150, the brief's point 6; docs/decisions.md EX16): from the business owner, no invoices, every
 * week, for Vape and Go, from 1 Nov 2026 unless --from says another first day (confirmed with the owner before go-live). Weekly total
 * GBP 9,771.94. Every line includes 20% VAT (the owner, 24 Nov 2026: "all of them i gave you is 20% inc vat"; EX38): the shops' rate
 * (profit.vat_rate_pct) is kept on each line and only the cost without VAT counts, GBP 8,143.28 a week. Starlink (a monthly invoice: an
 * expense) and Cloudflare (yearly: the owner adds it) are not in it.
 *
 *   php bin/seed_regular_costs.php [--from=2026-11-01] [--apply]
 *
 * Always Vape and Go's (the owner: the list is Vape and Go's; no other shop can be named: the Codex check r2, H3). The work is
 * CW\Expenses\RegularCostSeed (plan, then apply in one transaction: docs/decisions.md EX47).
 *
 *   (none)    a dry run: every line said, what would be added and what is there already; nothing written; never waits for a lock
 *   --apply   adds the lines not there yet (as system:seed_regular_costs, each with its history); a line of the same name in the shop that is
 *             not deleted with the same figures (group, amount, VAT, days) is there already (a second run adds nothing); with other figures
 *             the whole run is refused; the whole plan is checked again under the locks before anything is written
 *
 * Exit codes: 0 done · 1 refused (no such shop, a group missing or off, other figures, changed meanwhile: nothing written) · 2 usage ·
 * 3 cannot run · 4 busy: another run holds the seed's lock, nothing written (run it again; never 0: the final review of 70ab3d2, astra HIGH 3).
 */

use CW\CwException;
use CW\Expenses\ExpenseMoney;
use CW\Expenses\RegularCostMath;
use CW\Expenses\RegularCostSeed;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(Cli::main('seed_regular_costs', ['from:', 'apply'], 'usage: php bin/seed_regular_costs.php [--from=YYYY-MM-DD] [--apply]',
    static function (Cli $cli, array $opts): int {
        $from = is_string($opts['from'] ?? null) ? $opts['from'] : '2026-11-01';
        if (!RegularCostMath::isDay($from)) {
            throw new InvalidArgumentException('--from must be a day: YYYY-MM-DD');
        }
        $apply = array_key_exists('apply', $opts);
        // --apply takes the seed's lock (one apply at a time); held by another run, nothing is written and it says so with its own exit code
        // (Cli::BUSY), never 0. A dry run writes nothing and takes no lock.
        if ($apply && !$cli->tryLock()) {
            $cli->error('busy: another run of the seed holds its lock: nothing written (run it again)');
            return Cli::BUSY;
        }
        $seed = new RegularCostSeed($cli->db);
        $plan = $seed->plan($from);
        if ($plan['shop'] !== null) {
            $cli->log(($apply ? 'APPLY' : 'DRY RUN (nothing written; --apply writes)') . ": {$plan['shop']['name']}, every week, from {$from}");
        }
        if ($plan['refusals'] !== []) {
            foreach ($plan['refusals'] as $why) {
                $cli->error("refused: {$why}");
            }
            $cli->error('refused: nothing written');
            return Cli::PROBLEM;
        }
        $add = count(array_filter($plan['lines'], static fn (array $p): bool => $p['what'] === 'add'));
        $there = count($plan['lines']) - $add;
        $total = 'every week in all ' . ExpenseMoney::gbp($plan['week']) . ' with VAT, ' . ExpenseMoney::gbp($plan['week_cost']) . ' without VAT';
        if (!$apply) {
            foreach ($plan['lines'] as $p) {
                $cli->log(($p['what'] === 'add' ? 'would add: ' : 'already there: ') . $p['line']);
            }
            $cli->log("{$add} to add, {$there} already there; {$total}");
            return Cli::OK;
        }
        try {
            $done = $seed->apply($plan);
        } catch (CwException $e) {
            $cli->error("refused: {$e->getMessage()}: nothing written");
            return Cli::PROBLEM;
        }
        foreach ($done as $l) {
            $cli->log($l);
        }
        $cli->log("{$add} added, {$there} already there; {$total}");
        return Cli::OK;
    }, false, true));
