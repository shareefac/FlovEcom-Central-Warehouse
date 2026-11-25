<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Caller;
use CW\Db;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;
use CW\Settings;

/**
 * The read side of Profit > Expenses, Profit > Regular costs and the real profit (docs/decisions.md EX13-EX16). Reads only. Money in pence.
 * The expenses count by their invoice date and their amount WITHOUT VAT (the profit pages are all without VAT); a deleted one counts nowhere.
 * The regular costs count per day (RegularCostMath) WITHOUT their VAT (the amount less the VAT it includes: EX38), each line rounded once per
 * period. Summed on read: profit_day is not changed.
 *
 * Given the person who reads ($viewer), every read of expense figures checks again, inside its own snapshot, that they see the expenses
 * (expenses.view: ExpenseAccess::mayView), so a job taken away while a page is drawn shows nothing (the final review of 70ab3d2, astra MEDIUM;
 * EX48). Without one: a server tool or a service's own read.
 */
final class ExpenseViews
{
    /** The most rows the list shows at once (a month of one business holds far fewer). */
    public const LIST_LIMIT = 1000;
    /** The three shops expenses and regular costs are for (the owner: EX1), by their codes; any other channel takes none (EX44). */
    public const SHOP_CODES = ['vapeandgo', 'electrofag', 'vapebig'];
    /** The setting of each shop's first real profit day (0079, EX39): this prefix + its code. */
    public const REAL_FROM = 'profit.real_from_';

    public function __construct(private readonly Db $db, private readonly ?Caller $viewer = null)
    {
    }

    /** @return list<array{id: int, code: string, name: string}> the three shops in their order (their ids) */
    public function shops(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name']],
            $this->db->all('SELECT id, code, name FROM channel WHERE code IN (' . implode(', ', array_fill(0, count(self::SHOP_CODES), '?')) . ') ORDER BY id',
                self::SHOP_CODES));
    }

    /** Whether channel $id is one of the three shops (the services take no other: the final review of 70ab3d2, astra MEDIUM; EX44). */
    public static function isBusinessShop(Db $db, int $id): bool
    {
        return in_array((string) $db->value('SELECT code FROM channel WHERE id = ?', [$id]), self::SHOP_CODES, true);
    }

    /** The first day shop $code's real profit counts (its setting; null: none set, so never started). */
    public function realFrom(string $code): ?string
    {
        $s = new Settings($this->db);
        $v = $s->has(self::REAL_FROM . $code) ? $s->get(self::REAL_FROM . $code) : null;
        return is_string($v) && RegularCostMath::isDay($v) ? $v : null;
    }

    /** 403 unless the viewer (when given) sees the expenses; call inside the read's snapshot. */
    private function guard(): void
    {
        if ($this->viewer !== null) {
            ExpenseAccess::mayView($this->db, $this->viewer);
        }
    }

    /**
     * The expenses of the days $from..$to (by invoice date, not deleted), per shop: without VAT, VAT, total and how many.
     *
     * @return array<int, array{net: int, vat: int, total: int, count: int, credits: int}>
     */
    public function expensesBy(string $from, string $to, ?int $shop): array
    {
        $this->guard();
        $out = [];
        foreach ($this->db->all('SELECT channel_id, SUM(net_pence) AS net, SUM(vat_pence) AS vat, SUM(total_pence) AS total, COUNT(*) AS n, '
            . 'SUM(net_pence < 0) AS credits FROM expense '
            . 'WHERE invoice_date BETWEEN ? AND ? AND deleted_at IS NULL' . ($shop === null ? '' : ' AND channel_id = ?') . ' GROUP BY channel_id ORDER BY channel_id',
            [$from, $to, ...($shop === null ? [] : [$shop])]) as $r) {
            // How many, and of them the credit notes (the tiles count them apart: the Fable review, L4).
            $out[(int) $r['channel_id']] = ['net' => (int) $r['net'], 'vat' => (int) $r['vat'], 'total' => (int) $r['total'], 'count' => (int) $r['n'],
                'credits' => (int) $r['credits']];
        }
        return $out;
    }

    /**
     * Each regular cost line's share of the days $from..$to (lines not deleted that run on at least one of them), with its shop and group: the
     * share of its cost without VAT (amount less the VAT it includes; the owner, 24 Nov 2026: EX38).
     *
     * @return list<array{id: int, name: string, shop_id: int, shop: string, group: string, every: string, amount: int, vat_rate: int, vat: int, cost: int, from: string, until: ?string, share: int}>
     */
    public function regularLines(string $from, string $to, ?int $shop): array
    {
        $this->guard();
        $out = [];
        foreach ($this->db->all('SELECT r.id, r.name, r.channel_id, c.name AS shop, t.name AS grp, r.every, r.amount_pence, r.vat_rate_bp, r.from_day, r.until_day '
            . 'FROM regular_cost r '
            . 'JOIN channel c ON c.id = r.channel_id JOIN expense_type t ON t.id = r.type_id WHERE r.deleted_at IS NULL AND r.from_day <= ? '
            . 'AND (r.until_day IS NULL OR r.until_day >= ?)' . ($shop === null ? '' : ' AND r.channel_id = ?') . ' ORDER BY r.channel_id, t.sort_order, r.name, r.id',
            [$to, $from, ...($shop === null ? [] : [$shop])]) as $r) {
            $until = $r['until_day'] === null ? null : (string) $r['until_day'];
            [$vat, $cost] = self::vatAndCost((int) $r['amount_pence'], (int) $r['vat_rate_bp']);
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'shop_id' => (int) $r['channel_id'], 'shop' => (string) $r['shop'], 'group' => (string) $r['grp'],
                'every' => (string) $r['every'], 'amount' => (int) $r['amount_pence'], 'vat_rate' => (int) $r['vat_rate_bp'], 'vat' => $vat, 'cost' => $cost,
                'from' => (string) $r['from_day'], 'until' => $until,
                'share' => RegularCostMath::share((string) $r['every'], $cost, (string) $r['from_day'], $until, $from, $to)];
        }
        return $out;
    }

    /**
     * The VAT an amount includes at its line's rate and the cost without it (the owner, 24 Nov 2026: EX38).
     *
     * @return array{0: int, 1: int} [VAT, cost without VAT] in pence
     */
    public static function vatAndCost(int $amount, int $rateBp): array
    {
        $vat = RegularCostMath::vat($amount, $rateBp);
        return [$vat, $amount - $vat];
    }

    /** @return array<int, int> the regular costs of the days $from..$to per shop without VAT (the sum of its lines' shares) */
    public function regularBy(string $from, string $to, ?int $shop): array
    {
        $out = [];
        foreach ($this->regularLines($from, $to, $shop) as $l) {
            $out[$l['shop_id']] = ($out[$l['shop_id']] ?? 0) + $l['share'];
        }
        return $out;
    }

    /**
     * The months the list offers, newest first: from the earliest expense's month (or this month) to the later of this month and the latest
     * expense's month.
     *
     * @return list<string> YYYY-MM
     */
    public function months(string $today, bool $withDeleted = false): array
    {
        // With "Show deleted ones too", the months of the deleted invoices as well (the Codex check r3, M6).
        $r = $this->db->one('SELECT MIN(invoice_date) AS lo, MAX(invoice_date) AS hi FROM expense' . ($withDeleted ? '' : ' WHERE deleted_at IS NULL')) ?? [];
        // The months of the regular costs too: a month with regular costs only is offered (the Codex check r1, M8).
        $first = $this->db->value('SELECT MIN(from_day) FROM regular_cost WHERE deleted_at IS NULL');
        $now = substr($today, 0, 7);
        $lo = $r['lo'] === null ? $now : min($now, substr((string) $r['lo'], 0, 7));
        $lo = $first === null ? $lo : min($lo, substr((string) $first, 0, 7));
        $hi = $r['hi'] === null ? $now : max($now, substr((string) $r['hi'], 0, 7));
        $out = [];
        for ($m = $hi; $m >= $lo && count($out) < 240; $m = self::prevMonth($m)) {
            $out[] = $m;
        }
        return $out;
    }

    /**
     * Profit > Expenses: the expenses of a month (null: every month) of one shop or all, a search on the supplier's name or the invoice
     * number, the deleted ones too when asked; grouped by shop in the shops' order, newest first, each group with its sums and (a month, no
     * search) the shop's regular costs of the month. Tiles: each shop's month (without VAT, total, how many), whatever the search.
     *
     * @return array{groups: list<array{shop_id: int, shop: string, rows: list<array<string, mixed>>, net: int, total: int, count: int, regular: int}>, tiles: list<array{shop_id: int, shop: string, net: int, total: int, count: int}>, more: bool}
     */
    public function month(?string $month, ?int $shop, string $q, bool $deleted): array
    {
        [$from, $to] = $month === null ? [null, null] : self::monthDays($month);
        return $this->days($from, $to, $shop, $q, $deleted);
    }

    /**
     * month() for any days $from..$to (both null: every day), the rows, the regular costs and the tiles from ONE moment of the database (the
     * Codex check r1, H4: a row moved meanwhile never shows under one shop while the tiles count it in another).
     *
     * @return array{groups: list<array<string, mixed>>, tiles: list<array<string, mixed>>, more: bool}
     */
    public function days(?string $from, ?string $to, ?int $shop, string $q, bool $deleted): array
    {
        return ProfitDays::snapshot($this->db, function () use ($from, $to, $shop, $q, $deleted): array {
            $this->guard();
            return $this->daysNow($from, $to, $shop, $q, $deleted);
        });
    }

    /** @return array{groups: list<array<string, mixed>>, tiles: list<array<string, mixed>>, more: bool} */
    private function daysNow(?string $from, ?string $to, ?int $shop, string $q, bool $deleted): array
    {
        $where = [];
        $params = [];
        if ($from !== null) {
            $where[] = 'e.invoice_date BETWEEN ? AND ?';
            array_push($params, $from, $to);
        }
        if ($shop !== null) {
            $where[] = 'e.channel_id = ?';
            $params[] = $shop;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $where[] = '(s.name LIKE ? OR e.invoice_no LIKE ?)';
            array_push($params, $like, $like);
        }
        if (!$deleted) {
            $where[] = 'e.deleted_at IS NULL';
        }
        $rows = $this->db->all('SELECT e.id, e.channel_id, c.name AS shop, e.invoice_date, s.name AS supplier, t.name AS type, t.sort_order AS type_order, e.invoice_no, '
            . 'e.net_pence, e.vat_pence, e.total_pence, e.file_id, e.deleted_at FROM expense e JOIN channel c ON c.id = e.channel_id '
            . 'JOIN expense_supplier s ON s.id = e.supplier_id JOIN expense_type t ON t.id = e.type_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY e.channel_id, e.invoice_date DESC, e.id DESC LIMIT ' . (self::LIST_LIMIT + 1), $params);
        $more = count($rows) > self::LIST_LIMIT;
        $rows = array_slice($rows, 0, self::LIST_LIMIT);
        $regular = $from === null || $q !== '' ? [] : $this->regularBy($from, (string) $to, $shop);
        $groups = [];
        foreach ($this->shops() as $s) {
            if ($shop !== null && $s['id'] !== $shop) {
                continue;
            }
            $mine = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['channel_id'] === $s['id']));
            $reg = $regular[$s['id']] ?? 0;
            if ($mine === [] && $reg === 0) {
                continue;
            }
            $live = array_filter($mine, static fn (array $r): bool => $r['deleted_at'] === null);
            $groups[] = ['shop_id' => $s['id'], 'shop' => $s['name'], 'rows' => array_map(static fn (array $r): array => ['id' => (int) $r['id'],
                'invoice_date' => (string) $r['invoice_date'], 'supplier' => (string) $r['supplier'], 'type' => (string) $r['type'], 'type_order' => (int) $r['type_order'],
                'invoice_no' => (string) $r['invoice_no'], 'shop' => (string) $r['shop'], 'net' => (int) $r['net_pence'], 'vat' => (int) $r['vat_pence'],
                'total' => (int) $r['total_pence'], 'has_file' => $r['file_id'] !== null, 'deleted' => $r['deleted_at'] !== null], $mine),
                'net' => array_sum(array_map(static fn (array $r): int => (int) $r['net_pence'], $live)),
                'total' => array_sum(array_map(static fn (array $r): int => (int) $r['total_pence'], $live)), 'count' => count($live), 'regular' => $reg];
            // (the rows' shops are the three: an expense of another channel is never taken, EX44)
        }
        $tiles = [];
        if ($from !== null) {
            $by = $this->expensesBy($from, (string) $to, null);
            foreach ($this->shops() as $s) {
                $tiles[] = ['shop_id' => $s['id'], 'shop' => $s['name'], 'net' => $by[$s['id']]['net'] ?? 0, 'total' => $by[$s['id']]['total'] ?? 0,
                    'count' => $by[$s['id']]['count'] ?? 0, 'credits' => $by[$s['id']]['credits'] ?? 0];
            }
        }
        return ['groups' => $groups, 'tiles' => $tiles, 'more' => $more];
    }

    /** @return array<string, mixed>|null one expense with its shop, supplier, type, file and history, from ONE moment (the Codex check r3, M10) */
    public function expense(int $id): ?array
    {
        return ProfitDays::snapshot($this->db, function () use ($id): ?array {
            $this->guard();
            return $this->expenseNow($id);
        });
    }

    /** @return array<string, mixed>|null */
    private function expenseNow(int $id): ?array
    {
        $r = $this->db->one('SELECT e.*, c.name AS shop, s.name AS supplier, s.is_active AS supplier_active, t.name AS type, f.mime, f.original_name, f.size_bytes, '
            . 'cu.display_name AS created_name, du.display_name AS deleted_name FROM expense e JOIN channel c ON c.id = e.channel_id '
            . 'JOIN expense_supplier s ON s.id = e.supplier_id JOIN expense_type t ON t.id = e.type_id LEFT JOIN stored_file f ON f.id = e.file_id '
            . 'LEFT JOIN staff_user cu ON cu.id = e.created_by LEFT JOIN staff_user du ON du.id = e.deleted_by WHERE e.id = ?', [$id]);
        return $r === null ? null : $r + ['history' => ExpenseHistory::of($this->db, 'expense', $id)];
    }

    /** @return list<array{id: int, name: string, is_active: bool, version: int, used: int}> the expenses' suppliers by name */
    public function suppliers(): array
    {
        // The viewer's jobs and the list from ONE moment (the Codex re-check of 8dbee81, C3; EX60).
        return ProfitDays::snapshot($this->db, function (): array {
            $this->guard();
            return $this->suppliersNow();
        });
    }

    /** @return list<array{id: int, name: string, is_active: bool, version: int, used: int}> */
    private function suppliersNow(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'is_active' => (int) $r['is_active'] === 1,
            'version' => (int) $r['version'], 'used' => (int) $r['used']], $this->db->all('SELECT s.id, s.name, s.is_active, s.version, '
            . '(SELECT COUNT(*) FROM expense e WHERE e.supplier_id = s.id AND e.deleted_at IS NULL) AS used FROM expense_supplier s ORDER BY s.name, s.id'));
    }

    /**
     * Profit > Regular costs: every line not deleted (ended ones too, greyed) with its amount as given, the VAT it includes and its cost without
     * VAT (EX38), grouped by its group in the list's order, and what is in force today by how often (the drawing's "Every week in all"): the
     * amounts (today) and the costs without VAT (today_cost).
     *
     * @return array{groups: list<array{group: string, rows: list<array<string, mixed>>}>, today: array<string, int>, today_cost: array<string, int>}
     */
    public function regularList(string $today, ?int $shop = null): array
    {
        // The viewer's jobs and the lines from ONE moment (C3; EX60).
        return ProfitDays::snapshot($this->db, function () use ($today, $shop): array {
            $this->guard();
            return $this->regularListNow($today, $shop);
        });
    }

    /** @return array{groups: list<array{group: string, rows: list<array<string, mixed>>}>, today: array<string, int>, today_cost: array<string, int>} */
    private function regularListNow(string $today, ?int $shop): array
    {
        $rows = $this->db->all('SELECT r.id, r.name, r.channel_id, c.name AS shop, r.type_id, t.name AS grp, t.sort_order, r.every, r.amount_pence, r.vat_included, '
            . 'r.vat_rate_bp, '
            . 'r.from_day, r.until_day, '
            . 'r.note, r.version FROM regular_cost r JOIN channel c ON c.id = r.channel_id JOIN expense_type t ON t.id = r.type_id WHERE r.deleted_at IS NULL'
            . ($shop === null ? '' : ' AND r.channel_id = ?') . ' ORDER BY t.sort_order, t.name, r.id', $shop === null ? [] : [$shop]);
        $groups = [];
        $inForce = ['week' => 0, 'month' => 0, 'year' => 0];
        $inForceCost = $inForce;
        foreach ($rows as $r) {
            $until = $r['until_day'] === null ? null : (string) $r['until_day'];
            $running = (string) $r['from_day'] <= $today && ($until === null || $until >= $today);
            $ended = $until !== null && $until < $today;
            [$vat, $cost] = self::vatAndCost((int) $r['amount_pence'], (int) $r['vat_rate_bp']);
            if ($running) {
                $inForce[(string) $r['every']] += (int) $r['amount_pence'];
                $inForceCost[(string) $r['every']] += $cost;
            }
            $g = (string) $r['grp'];
            $groups[$g] ??= ['group' => $g, 'rows' => []];
            $groups[$g]['rows'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'shop_id' => (int) $r['channel_id'], 'shop' => (string) $r['shop'],
                'every' => (string) $r['every'], 'amount' => (int) $r['amount_pence'], 'vat_included' => (int) $r['vat_included'] === 1, 'vat_rate' => (int) $r['vat_rate_bp'],
                'vat' => $vat, 'cost' => $cost, 'from' => (string) $r['from_day'], 'until' => $until, 'ended' => $ended,
                'running' => $running, 'note' => $r['note'] === null ? null : (string) $r['note']];
        }
        return ['groups' => array_values($groups), 'today' => $inForce, 'today_cost' => $inForceCost];
    }

    /** @return array<string, mixed>|null one regular cost line with its shop, group, the line it continues, the line continuing it, its history */
    public function regular(int $id): ?array
    {
        return ProfitDays::snapshot($this->db, function () use ($id): ?array {
            $this->guard();
            return $this->regularNow($id);
        });
    }

    /** @return array<string, mixed>|null */
    private function regularNow(int $id): ?array
    {
        $r = $this->db->one('SELECT r.*, c.name AS shop, t.name AS grp, t.is_active AS group_active, du.display_name AS deleted_name FROM regular_cost r '
            . 'JOIN channel c ON c.id = r.channel_id JOIN expense_type t ON t.id = r.type_id LEFT JOIN staff_user du ON du.id = r.deleted_by WHERE r.id = ?', [$id]);
        if ($r === null) {
            return null;
        }
        // The line that follows it by date in its whole chain of new amounts, any branch (a second new amount on the same line makes two, and a
        // backdated one makes the later one its sibling: the Codex check r3, M4; the final review of 70ab3d2, astra LOW).
        $next = $this->db->one('WITH RECURSIVE d (id) AS (SELECT ? UNION ALL SELECT r.id FROM regular_cost r JOIN d ON r.replaces_id = d.id) '
            . 'SELECT r.id, r.amount_pence, r.from_day FROM d JOIN regular_cost r ON r.id = d.id WHERE r.id <> ? AND r.deleted_at IS NULL AND r.from_day > ? '
            . 'ORDER BY r.from_day, r.id LIMIT 1', [RegularCosts::root($this->db, $id), $id, (string) $r['from_day']]);
        // The live line just before it by date in the same whole chain (the one whose last day decides whether a gap opens when this one is
        // deleted: £70 from 1 Nov, £98 from 15 Nov, then £84 backdated from 8 Nov: before the £98 line is the £84 one ending 14 Nov, never the
        // £70 one ending 7 Nov; the Codex re-check of 8dbee81, C5; EX59).
        $prev = $this->db->one('WITH RECURSIVE d (id) AS (SELECT ? UNION ALL SELECT r.id FROM regular_cost r JOIN d ON r.replaces_id = d.id) '
            . 'SELECT r.id, r.until_day FROM d JOIN regular_cost r ON r.id = d.id WHERE r.id <> ? AND r.deleted_at IS NULL AND r.from_day < ? '
            . 'ORDER BY r.from_day DESC, r.id DESC LIMIT 1', [RegularCosts::root($this->db, $id), $id, (string) $r['from_day']]);
        return $r + ['next' => $next, 'prev' => $prev, 'history' => ExpenseHistory::of($this->db, 'regular_cost', $id)];
    }

    /**
     * The real profit of the days $from..$to per shop (the brief's point 4; docs/decisions.md EX15): the profit days' figures as the other
     * Profit pages show them (goods sold less refunds, buying cost, delivery charged, the carriers' bills on the orders and not on one), then
     * payment fees (PayPal's for a shop switched on, from its first fee day: 0080, PF17; 0 for the others), the expenses (without VAT, by invoice date; a credit note takes off) and the regular costs (per day, without
     * their VAT); real profit = goods - cost + delivery charged - delivery cost - fees - expenses - regular costs. All from ONE moment of the
     * database.
     *
     * Each shop counts from its own first day (its setting profit.real_from_<code>; the owner, 24 Nov 2026: Vape and Go from 1 Nov, Electrofag
     * and Vape Big from 1 Dec; EX39): days before it are left out of every line (counted_from says from when, when the days start earlier); a
     * shop whose day is after $to (or not set) has not started: no figure (null), only its day (from). Not complete while ANY unit counted
     * has no buying price or waits for it (EX35). $withExpenses false (a person without expenses.view): the expense reads are not made at all
     * and their lines are null (EX22, EX48).
     *
     * @return list<array{shop_id: int, shop: string, code: string, started: bool, from: ?string, counted_from: ?string, goods: ?int, cost: ?int, delivery: ?int, delivery_cost: ?int, fees: ?int, fees_from: ?string, expenses: ?int, expenses_count: int, regular: ?int, real: ?int, incomplete: bool, no_cost: int}>
     */
    public function realProfit(string $from, string $to, ?int $shop, bool $withExpenses = true): array
    {
        $views = new ProfitViews($this->db);
        return ProfitDays::snapshot($this->db, function () use ($views, $from, $to, $shop, $withExpenses): array {
            if ($withExpenses) {
                $this->guard();
            }
            $out = [];
            foreach ($this->shops() as $s) {
                if ($shop !== null && $s['id'] !== $shop) {
                    continue;
                }
                $start = $this->realFrom($s['code']);
                $a = $start !== null && $start > $from ? $start : $from;
                $row = ['shop_id' => $s['id'], 'shop' => $s['name'], 'code' => $s['code'], 'started' => $start !== null && $a <= $to, 'from' => $start,
                    'counted_from' => $start !== null && $start > $from && $start <= $to ? $start : null, 'goods' => null, 'cost' => null, 'delivery' => null,
                    'delivery_cost' => null, 'fees' => null, 'fees_from' => null, 'expenses' => null, 'expenses_count' => 0, 'regular' => null, 'real' => null, 'incomplete' => false,
                    'no_cost' => 0];
                if (!$row['started']) {
                    $out[] = $row;
                    continue;
                }
                $f = $views->totals($a, $to, $s['id']);
                $deliveryCost = (int) $f['delivery_cost'] + (int) $f['delivery_other'];
                // PayPal's fees (0080, docs/decisions.md PF17) for a shop switched on: what the profit days hold of them from its first fee day
                // (on its orders and not on one; before that day nothing is counted at all), else 0 as before
                $feesFrom = \CW\Profit\PaypalFees::feesFrom($this->db, $s['code']);
                $fees = $feesFrom !== null && $feesFrom <= $to ? (int) $f['fee_cost'] + (int) $f['fee_other'] : 0;
                $row = ['goods' => (int) $f['goods'], 'cost' => (int) $f['cost'], 'delivery' => (int) $f['delivery'], 'delivery_cost' => $deliveryCost, 'fees' => $fees,
                    'fees_from' => $feesFrom !== null && $feesFrom <= $to ? $feesFrom : null,
                    // Not complete while ANY unit counted has no buying price or waits for it (the margin pages' 0.5% rule is for a margin; a real
                    // profit is an amount: the Codex check r3, H5).
                    'incomplete' => (int) $f['no_cost'] + (int) $f['pending'] > 0, 'no_cost' => (int) $f['no_cost'] + (int) $f['pending']] + $row;
                if ($withExpenses) {
                    $e = $this->expensesBy($a, $to, $s['id'])[$s['id']] ?? ['net' => 0, 'count' => 0];
                    $reg = $this->regularBy($a, $to, $s['id'])[$s['id']] ?? 0;
                    $row = ['expenses' => $e['net'], 'expenses_count' => $e['count'], 'regular' => $reg,
                        'real' => $row['goods'] - $row['cost'] + $row['delivery'] - $deliveryCost - $row['fees'] - $e['net'] - $reg] + $row;
                }
                $out[] = $row;
            }
            return $out;
        });
    }

    /** @return array{0: string, 1: string} the first and last day of a month YYYY-MM */
    public static function monthDays(string $month): array
    {
        $first = $month . '-01';
        return [$first, gmdate('Y-m-t', (int) gmmktime(0, 0, 0, (int) substr($month, 5, 2), 1, (int) substr($month, 0, 4)))];
    }

    /** Whether $s is a month YYYY-MM between 2000 and 2099. */
    public static function isMonth(string $s): bool
    {
        return preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $s) === 1;
    }

    private static function prevMonth(string $m): string
    {
        $y = (int) substr($m, 0, 4);
        $mo = (int) substr($m, 5, 2) - 1;
        return $mo === 0 ? sprintf('%04d-12', $y - 1) : sprintf('%04d-%02d', $y, $mo);
    }
}
