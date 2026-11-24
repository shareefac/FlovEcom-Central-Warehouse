<?php

declare(strict_types=1);

/**
 * The owner's weekly list as regular costs (CW-1150, the brief's point 6; docs/decisions.md EX16): from the business owner, no invoices, every
 * week, for Vape and Go, from 1 Nov 2026 unless --from says another first day (confirmed with the owner before go-live). Weekly total
 * GBP 9,771.94. Starlink (a monthly invoice: an expense) and Cloudflare (yearly: the owner adds it) are not in it.
 *
 *   php bin/seed_regular_costs.php [--from=2026-11-01] [--apply]
 *
 * Always Vape and Go's (the owner: the list is Vape and Go's; no other shop can be named: the Codex check r2, H3).
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

exit(Cli::main('seed_regular_costs', ['from:', 'apply'], 'usage: php bin/seed_regular_costs.php [--from=YYYY-MM-DD] [--apply]',
    static function (Cli $cli, array $opts): int {
        $from = is_string($opts['from'] ?? null) ? $opts['from'] : '2026-11-01';
        if (!RegularCostMath::isDay($from)) {
            throw new InvalidArgumentException('--from must be a day: YYYY-MM-DD');
        }
        $code = 'vapeandgo';
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
        // Every line checked first; then all of them written in ONE transaction, or none (the Codex check r1, H3): a run refused half way never
        // leaves some lines in, and a line of the same name in the shop with other figures refuses the whole run (it is never skipped as
        // "already there" when it is not the same).
        $plan = [];
        $refusals = [];
        $week = 0;
        foreach (SEED_LIST as [$name, $amount, $group, $until]) {
            $pence = (int) ExpenseMoney::pence($amount);
            $week += $pence;
            $line = sprintf('%-20s %12s a week  %-30s from %s%s', $name, ExpenseMoney::gbp($pence), $group, $from, $until === null ? '' : " until {$until}");
            if (!isset($groups[$group])) {
                $refusals[] = "{$name}: the group {$group} is not in Settings > Expense types";
                continue;
            }
            if ($until !== null && $until < $from) {
                $refusals[] = "{$name} ends on {$until}, before the first day {$from}";
                continue;
            }
            $same = $cli->db->all('SELECT id, every, amount_pence, from_day, until_day FROM regular_cost WHERE channel_id = ? AND name = ? AND deleted_at IS NULL',
                [$shop['id'], $name]);
            if ($same === []) {
                $plan[] = ['add', $name, $amount, $groups[$group], $until, $line];
                continue;
            }
            $match = array_filter($same, static fn (array $r): bool => (string) $r['every'] === 'week' && (int) $r['amount_pence'] === $pence
                && (string) $r['from_day'] === $from && ($r['until_day'] === null ? null : (string) $r['until_day']) === $until);
            if ($match === [] || count($same) > 1) {
                $r = $same[0];
                $refusals[] = "{$name} is there already with other figures (line {$r['id']}: " . ExpenseMoney::gbp((int) $r['amount_pence']) . " every {$r['every']} "
                    . "from {$r['from_day']}" . ($r['until_day'] === null ? '' : " until {$r['until_day']}") . '): change or delete it on Profit > Regular costs first';
                continue;
            }
            $plan[] = ['there', $name, $amount, $groups[$group], $until, $line . ' (line ' . (int) array_values($match)[0]['id'] . ')'];
        }
        if ($refusals !== []) {
            foreach ($refusals as $why) {
                $cli->error("refused: {$why}");
            }
            $cli->error('refused: nothing written');
            return Cli::PROBLEM;
        }
        $add = count(array_filter($plan, static fn (array $p): bool => $p[0] === 'add'));
        $there = count($plan) - $add;
        if (!$apply) {
            foreach ($plan as [$what, , , , , $line]) {
                $cli->log(($what === 'add' ? 'would add: ' : 'already there: ') . $line);
            }
            $cli->log("{$add} to add, {$there} already there; every week in all " . ExpenseMoney::gbp($week));
            return Cli::OK;
        }
        $rc = new RegularCosts($cli->db);
        $caller = Caller::system('seed_regular_costs');
        try {
            $done = $cli->db->transaction(static function () use ($plan, $rc, $caller, $shop, $from): array {
                $out = [];
                foreach ($plan as [$what, $name, $amount, $type, $until, $line]) {
                    if ($what === 'add') {
                        $id = $rc->add($caller, ['name' => $name, 'shop' => (int) $shop['id'], 'type' => $type, 'every' => 'week', 'amount' => $amount, 'from' => $from,
                            'until' => $until, 'note' => 'The owner\'s weekly list (no invoice), 24 Nov 2026']);
                        $out[] = "added (line {$id}): {$line}";
                    } else {
                        $out[] = "already there: {$line}";
                    }
                }
                return $out;
            });
        } catch (CwException $e) {
            $cli->error("refused: {$e->getMessage()}: nothing written");
            return Cli::PROBLEM;
        }
        foreach ($done as $l) {
            $cli->log($l);
        }
        $cli->log("{$add} added, {$there} already there; every week in all " . ExpenseMoney::gbp($week));
        return Cli::OK;
    }, true, true));
