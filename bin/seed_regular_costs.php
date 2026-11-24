<?php

declare(strict_types=1);

/**
 * The owner's weekly list as regular costs (CW-1150, the brief's point 6; docs/decisions.md EX16): from the business owner, no invoices, every
 * week, for Vape and Go, from 1 Nov 2026 unless --from says another first day (confirmed with the owner before go-live). Weekly total
 * GBP 9,771.94. Starlink (a monthly invoice: an expense) and Cloudflare (yearly: the owner adds it) are not in it.
 *
 *   php bin/seed_regular_costs.php [--from=2026-11-01] [--shop=vapeandgo] [--apply]
 *
 *   (none)    a dry run: every line said, what would be added and what is there already; nothing written
 *   --apply   adds the lines not there yet (as system:seed_regular_costs, each with its history); a line of the same name in the shop that is
 *             not deleted is there already, whatever its amount (a second run adds nothing)
 *
 * Exit codes: 0 done · 1 refused (no such shop, a group missing) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Expenses\ExpenseMoney;
use CW\Expenses\RegularCostMath;
use CW\Expenses\RegularCosts;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

/** The list (the brief, 24 Nov 2026): name, pounds a week, its group (as the drawing MonthlyCosts groups it), its last day (none: no end). */
const SEED_LIST = [
    ['Unit 1 rent', '207.69', 'Rent', null],
    ['Unit 2 rent', '230.77', 'Rent', null],
    ['Unit 3 rent', '923.08', 'Rent', '2027-03-31'],
    ['Hub Box', '109.00', 'Running the business', null],
    ['Phone and internet', '26.40', 'Running the business', null],
    ['Verisure', '36.00', 'Running the business', null],
    ['Car and van lease', '152.00', 'Running the business', null],
    ['Adobe', '20.00', 'Running the business', null],
    ['Accounting', '138.00', 'Running the business', null],
    ['Sage', '42.00', 'Running the business', null],
    ['Cardboard', '13.00', 'Running the business', null],
    ['Bin', '177.00', 'Running the business', null],
    ['Netcore', '969.00', 'Marketing, website and people', null],
    ['Novos', '2,769.00', 'Marketing, website and people', null],
    ['Reboot', '2,076.00', 'Marketing, website and people', '2027-02-28'],
    ['Dean', '1,883.00', 'Marketing, website and people', null],
];

exit(Cli::main('seed_regular_costs', ['from:', 'shop:', 'apply'], 'usage: php bin/seed_regular_costs.php [--from=YYYY-MM-DD] [--shop=<store code>] [--apply]',
    static function (Cli $cli, array $opts): int {
        $from = is_string($opts['from'] ?? null) ? $opts['from'] : '2026-11-01';
        if (!RegularCostMath::isDay($from)) {
            throw new InvalidArgumentException('--from must be a day: YYYY-MM-DD');
        }
        $code = is_string($opts['shop'] ?? null) ? $opts['shop'] : 'vapeandgo';
        $apply = array_key_exists('apply', $opts);
        $shop = $cli->db->one('SELECT id, name FROM channel WHERE code = ?', [$code]);
        if ($shop === null) {
            $cli->error("refused: no shop {$code} in CW");
            return Cli::PROBLEM;
        }
        $groups = [];
        foreach ($cli->db->all("SELECT id, name FROM expense_type WHERE kind = 'regular'") as $g) {
            $groups[(string) $g['name']] = (int) $g['id'];
        }
        $cli->log(($apply ? 'APPLY' : 'DRY RUN (nothing written; --apply writes)') . ": {$shop['name']}, every week, from {$from}");
        $add = 0;
        $there = 0;
        $week = 0;
        $rc = new RegularCosts($cli->db);
        $caller = Caller::system('seed_regular_costs');
        foreach (SEED_LIST as [$name, $amount, $group, $until]) {
            $week += (int) ExpenseMoney::pence($amount);
            if (!isset($groups[$group])) {
                $cli->error("refused: the group {$group} is not in Settings > Expense types");
                return Cli::PROBLEM;
            }
            $exists = $cli->db->value('SELECT id FROM regular_cost WHERE channel_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1', [$shop['id'], $name]);
            $line = sprintf('%-20s %12s a week  %-30s from %s%s', $name, ExpenseMoney::gbp((int) ExpenseMoney::pence($amount)), $group, $from,
                $until === null ? '' : " until {$until}");
            if ($exists !== null) {
                $there++;
                $cli->log("already there (line {$exists}): {$line}");
                continue;
            }
            $add++;
            if (!$apply) {
                $cli->log("would add: {$line}");
                continue;
            }
            try {
                $id = $rc->add($caller, ['name' => $name, 'shop' => (int) $shop['id'], 'type' => $groups[$group], 'every' => 'week', 'amount' => $amount, 'from' => $from,
                    'until' => $until, 'note' => 'The owner\'s weekly list (no invoice), 24 Nov 2026']);
            } catch (CwException $e) {
                $cli->error("refused: {$name}: {$e->getMessage()}");
                return Cli::PROBLEM;
            }
            $cli->log("added (line {$id}): {$line}");
        }
        $cli->log($apply ? "{$add} added, {$there} already there; every week in all " . ExpenseMoney::gbp($week)
            : "{$add} to add, {$there} already there; every week in all " . ExpenseMoney::gbp($week));
        return Cli::OK;
    }, true, true));
