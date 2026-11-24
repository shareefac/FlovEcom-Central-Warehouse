<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Profit\ProfitSettings;

/**
 * The owner's weekly list as regular costs of Vape and Go (CW-1150, the brief's point 6; docs/decisions.md EX16, EX26, EX30, EX38, EX47):
 * bin/seed_regular_costs.php plans it (plan(): nothing written; the dry run prints it) and applies it (apply(): all or nothing).
 *
 *   plan()   every line checked as the writer will check it (its group there and switched on, its days real days in CW's range, its last day
 *            not before the first: the final review of 70ab3d2, astra LOW, so a dry run never approves what --apply refuses); a line of the
 *            same name in the shop that is not deleted with the same figures (group, amount, VAT choice and rate, every week, first and last
 *            day) is "already there"; with other figures, or two of them, the whole run is refused. The shops' VAT rate is read ONCE.
 *   apply()  in ONE transaction, under the locks every add takes: the whole plan checked again (for each line, the live lines of its name in
 *            the shop: none for a line to add, exactly the one planned with the same group and figures for one already there; its group
 *            locked and still the planned one by id AND name, switched on; the shop still Vape and Go; the VAT rate read from its setting's row
 *            with a lock held to the commit, still the planned one), then every line added with THAT rate (astra MEDIUM x2; the Codex re-check
 *            of 8dbee81, C6; EX62): anything changed meanwhile refuses the whole run (409 seed_changed), nothing written.
 */
final class RegularCostSeed
{
    /** The list (the brief, 24 Nov 2026): name, pounds a week, its group (as the drawing MonthlyCosts groups it), its last day (none: no end). */
    public const LIST = [
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
    /** The shop the list is for (the owner: Vape and Go's; EX30). */
    public const SHOP = 'vapeandgo';
    public const NOTE = 'The owner\'s weekly list (no invoice), 24 Nov 2026';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array{shop: ?array{id: int, name: string}, from: string, rate: int, lines: list<array{what: string, name: string, amount: string, pence: int, group: int, until: ?string, line: string, id: ?int}>, refusals: list<string>, week: int, week_cost: int}
     */
    public function plan(string $from): array
    {
        $out = ['shop' => null, 'from' => $from, 'rate' => 0, 'lines' => [], 'refusals' => [], 'week' => 0, 'week_cost' => 0];
        $shop = $this->db->one('SELECT id, name FROM channel WHERE code = ?', [self::SHOP]);
        if ($shop === null) {
            $out['refusals'][] = 'no shop ' . self::SHOP . ' in CW';
            return $out;
        }
        $out['shop'] = ['id' => (int) $shop['id'], 'name' => (string) $shop['name']];
        if (!RegularCostMath::isDay($from) || $from < Expenses::FIRST_DAY || $from > '2099-12-31') {
            $out['refusals'][] = "the first day {$from} is not a day CW takes (" . Expenses::FIRST_DAY . ' to 2099-12-31)';
            return $out;
        }
        $groups = [];
        foreach ($this->db->all("SELECT id, name, is_active FROM expense_type WHERE kind = 'regular'") as $g) {
            $groups[(string) $g['name']] = ['id' => (int) $g['id'], 'active' => (int) $g['is_active'] === 1];
        }
        $rate = (new ProfitSettings($this->db))->vatBp();
        $out['rate'] = $rate;
        $pct = RegularCostMath::percent($rate);
        foreach (self::LIST as [$name, $amount, $group, $until]) {
            $pence = (int) ExpenseMoney::pence($amount);
            $cost = $pence - RegularCostMath::vat($pence, $rate);
            $out['week'] += $pence;
            $out['week_cost'] += $cost;
            $line = sprintf('%-20s %12s a week incl. %s VAT (%s without)  %-30s from %s%s', $name, ExpenseMoney::gbp($pence), $pct, ExpenseMoney::gbp($cost), $group,
                $from, $until === null ? '' : " until {$until}");
            if (!isset($groups[$group])) {
                $out['refusals'][] = "{$name}: the group {$group} is not in Settings > Expense types";
                continue;
            }
            if (!$groups[$group]['active']) {
                $out['refusals'][] = "{$name}: the group {$group} is switched off in Settings > Expense types";
                continue;
            }
            if ($until !== null && $until < $from) {
                $out['refusals'][] = "{$name} ends on {$until}, before the first day {$from}";
                continue;
            }
            $same = $this->live((int) $shop['id'], $name, false);
            $p = ['what' => 'add', 'name' => $name, 'amount' => $amount, 'pence' => $pence, 'group' => $groups[$group]['id'], 'group_name' => $group, 'until' => $until,
                'line' => $line, 'id' => null];
            if ($same === []) {
                $out['lines'][] = $p;
                continue;
            }
            if (count($same) > 1 || !self::sameFigures($same[0], $p, $from, $rate)) {
                $r = $same[0];
                $out['refusals'][] = "{$name} is there already with other figures (line {$r['id']}: " . ExpenseMoney::gbp((int) $r['amount_pence']) . " every {$r['every']} "
                    . ((int) $r['vat_included'] === 1 ? 'incl. ' . RegularCostMath::percent((int) $r['vat_rate_bp']) . ' VAT' : 'no VAT') . " from {$r['from_day']}"
                    . ($r['until_day'] === null ? '' : " until {$r['until_day']}") . (count($same) > 1 ? ', and ' . (count($same) - 1) . ' more of that name' : '')
                    . '): change or delete it on Profit > Regular costs first';
                continue;
            }
            $out['lines'][] = ['what' => 'there', 'id' => (int) $same[0]['id'], 'line' => $line . ' (line ' . (int) $same[0]['id'] . ')'] + $p;
        }
        return $out;
    }

    /**
     * Applies a plan with no refusals: ONE transaction, the whole plan checked again under the locks (409 seed_changed naming the line, nothing
     * written), every line added with the plan's rate. Returns a line of words per line.
     *
     * @param array{shop: ?array{id: int, name: string}, from: string, rate: int, lines: list<array<string, mixed>>, refusals: list<string>} $plan
     * @return list<string>
     */
    public function apply(array $plan): array
    {
        if ($plan['refusals'] !== [] || $plan['shop'] === null) {
            throw new \LogicException('a plan with refusals is never applied');
        }
        $rc = new RegularCosts($this->db);
        $caller = Caller::system('seed_regular_costs');
        return $this->db->transaction(function (Db $db) use ($plan, $rc, $caller): array {
            // The locks every add takes (the groups' rows, in their order), first: every add, change of name or end of a regular cost waits, and
            // a group renamed or switched off on Settings waits too; the rows as they are now, by id AND name (C6).
            $groups = [];
            foreach ($db->all("SELECT id, name, is_active FROM expense_type WHERE kind = 'regular' ORDER BY id FOR UPDATE") as $g) {
                $groups[(int) $g['id']] = ['name' => (string) $g['name'], 'active' => (int) $g['is_active'] === 1];
            }
            if ((string) $db->value('SELECT code FROM channel WHERE id = ? FOR SHARE', [$plan['shop']['id']]) !== self::SHOP) {
                throw new CwException('seed_changed', 'the shop changed while the seed ran', 409);
            }
            // The rate from its row, locked to the commit: a change of it waits for the seed (or the seed for it), never in between (C6).
            $rate = ProfitSettings::lockedVatBp($db);
            if ($rate !== $plan['rate']) {
                throw new CwException('seed_changed', 'the shops\' VAT rate changed while the seed ran (' . RegularCostMath::percent($plan['rate']) . ' planned, '
                    . RegularCostMath::percent($rate) . ' now)', 409);
            }
            foreach ($plan['lines'] as $p) {
                $live = $this->live($plan['shop']['id'], (string) $p['name'], true);
                $ok = $p['what'] === 'add' ? $live === []
                    : count($live) === 1 && (int) $live[0]['id'] === $p['id'] && self::sameFigures($live[0], $p, $plan['from'], $rate);
                $g = $groups[(int) $p['group']] ?? null;
                if ($g === null || !$g['active'] || $g['name'] !== (string) ($p['group_name'] ?? '')) {
                    throw new CwException('seed_changed', "{$p['name']}: its group {$p['group_name']} changed while the seed ran", 409, ['name' => $p['name']]);
                }
                if (!$ok) {
                    throw new CwException('seed_changed', "{$p['name']} changed while the seed ran", 409, ['name' => $p['name']]);
                }
            }
            $out = [];
            foreach ($plan['lines'] as $p) {
                if ($p['what'] === 'add') {
                    $id = $rc->add($caller, ['name' => $p['name'], 'shop' => $plan['shop']['id'], 'type' => $p['group'], 'vat' => 'inc', 'every' => 'week',
                        'amount' => $p['amount'], 'from' => $plan['from'], 'until' => $p['until'], 'note' => self::NOTE], '', $rate);
                    $out[] = "added (line {$id}): {$p['line']}";
                } else {
                    $out[] = "already there: {$p['line']}";
                }
            }
            return $out;
        });
    }

    /** @return list<array<string, mixed>> the lines of this name (any case) in the shop that are not deleted, locked when $lock */
    private function live(int $shop, string $name, bool $lock): array
    {
        return $this->db->all('SELECT id, type_id, every, amount_pence, vat_included, vat_rate_bp, from_day, until_day FROM regular_cost WHERE channel_id = ? AND name = ? '
            . 'AND deleted_at IS NULL ORDER BY id' . ($lock ? ' FOR UPDATE' : ''), [$shop, $name]);
    }

    /**
     * @param array<string, mixed> $r a live line
     * @param array<string, mixed> $p a planned line
     */
    private static function sameFigures(array $r, array $p, string $from, int $rate): bool
    {
        return (int) $r['type_id'] === (int) $p['group'] && (string) $r['every'] === 'week' && (int) $r['amount_pence'] === (int) $p['pence']
            && (int) $r['vat_included'] === 1 && (int) $r['vat_rate_bp'] === $rate && (string) $r['from_day'] === $from
            && ($r['until_day'] === null ? null : (string) $r['until_day']) === $p['until'];
    }
}
