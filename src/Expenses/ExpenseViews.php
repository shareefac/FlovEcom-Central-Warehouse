<?php

declare(strict_types=1);

namespace CW\Expenses;

use CW\Db;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;

/**
 * The read side of Profit > Expenses, Profit > Regular costs and the real profit (docs/decisions.md EX13-EX16). Reads only. Money in pence.
 * The expenses count by their invoice date and their amount WITHOUT VAT (the profit pages are all without VAT); a deleted one counts nowhere.
 * The regular costs count per day (RegularCostMath), each line rounded once per period. Summed on read: profit_day is not changed.
 */
final class ExpenseViews
{
    /** The most rows the list shows at once (a month of one business holds far fewer). */
    public const LIST_LIMIT = 1000;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id: int, code: string, name: string}> the shops in their order */
    public function shops(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name']],
            $this->db->all('SELECT id, code, name FROM channel ORDER BY id'));
    }

    /**
     * The expenses of the days $from..$to (by invoice date, not deleted), per shop: without VAT, VAT, total and how many.
     *
     * @return array<int, array{net: int, vat: int, total: int, count: int}>
     */
    public function expensesBy(string $from, string $to, ?int $shop): array
    {
        $out = [];
        foreach ($this->db->all('SELECT channel_id, SUM(net_pence) AS net, SUM(vat_pence) AS vat, SUM(total_pence) AS total, COUNT(*) AS n FROM expense '
            . 'WHERE invoice_date BETWEEN ? AND ? AND deleted_at IS NULL' . ($shop === null ? '' : ' AND channel_id = ?') . ' GROUP BY channel_id ORDER BY channel_id',
            [$from, $to, ...($shop === null ? [] : [$shop])]) as $r) {
            $out[(int) $r['channel_id']] = ['net' => (int) $r['net'], 'vat' => (int) $r['vat'], 'total' => (int) $r['total'], 'count' => (int) $r['n']];
        }
        return $out;
    }

    /**
     * Each regular cost line's share of the days $from..$to (lines not deleted that run on at least one of them), with its shop and group.
     *
     * @return list<array{id: int, name: string, shop_id: int, shop: string, group: string, every: string, amount: int, from: string, until: ?string, share: int}>
     */
    public function regularLines(string $from, string $to, ?int $shop): array
    {
        $out = [];
        foreach ($this->db->all('SELECT r.id, r.name, r.channel_id, c.name AS shop, t.name AS grp, r.every, r.amount_pence, r.from_day, r.until_day FROM regular_cost r '
            . 'JOIN channel c ON c.id = r.channel_id JOIN expense_type t ON t.id = r.type_id WHERE r.deleted_at IS NULL AND r.from_day <= ? '
            . 'AND (r.until_day IS NULL OR r.until_day >= ?)' . ($shop === null ? '' : ' AND r.channel_id = ?') . ' ORDER BY r.channel_id, t.sort_order, r.name, r.id',
            [$to, $from, ...($shop === null ? [] : [$shop])]) as $r) {
            $until = $r['until_day'] === null ? null : (string) $r['until_day'];
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'shop_id' => (int) $r['channel_id'], 'shop' => (string) $r['shop'], 'group' => (string) $r['grp'],
                'every' => (string) $r['every'], 'amount' => (int) $r['amount_pence'], 'from' => (string) $r['from_day'], 'until' => $until,
                'share' => RegularCostMath::share((string) $r['every'], (int) $r['amount_pence'], (string) $r['from_day'], $until, $from, $to)];
        }
        return $out;
    }

    /** @return array<int, int> the regular costs of the days $from..$to per shop (the sum of its lines' shares) */
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
    public function months(string $today): array
    {
        $r = $this->db->one('SELECT MIN(invoice_date) AS lo, MAX(invoice_date) AS hi FROM expense WHERE deleted_at IS NULL') ?? [];
        $now = substr($today, 0, 7);
        $lo = $r['lo'] === null ? $now : min($now, substr((string) $r['lo'], 0, 7));
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
        }
        $tiles = [];
        if ($from !== null) {
            $by = $this->expensesBy($from, (string) $to, null);
            foreach ($this->shops() as $s) {
                $tiles[] = ['shop_id' => $s['id'], 'shop' => $s['name'], 'net' => $by[$s['id']]['net'] ?? 0, 'total' => $by[$s['id']]['total'] ?? 0,
                    'count' => $by[$s['id']]['count'] ?? 0];
            }
        }
        return ['groups' => $groups, 'tiles' => $tiles, 'more' => $more];
    }

    /** @return array<string, mixed>|null one expense with its shop, supplier, type, file and history */
    public function expense(int $id): ?array
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
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'is_active' => (int) $r['is_active'] === 1,
            'version' => (int) $r['version'], 'used' => (int) $r['used']], $this->db->all('SELECT s.id, s.name, s.is_active, s.version, '
            . '(SELECT COUNT(*) FROM expense e WHERE e.supplier_id = s.id AND e.deleted_at IS NULL) AS used FROM expense_supplier s ORDER BY s.name, s.id'));
    }

    /**
     * Profit > Regular costs: every line not deleted (ended ones too, greyed), grouped by its group in the list's order, and what is in force
     * today per shop and how often (the drawing's "Every week in all").
     *
     * @return array{groups: list<array{group: string, rows: list<array<string, mixed>>}>, today: array<string, int>}
     */
    public function regularList(string $today, ?int $shop = null): array
    {
        $rows = $this->db->all('SELECT r.id, r.name, r.channel_id, c.name AS shop, r.type_id, t.name AS grp, t.sort_order, r.every, r.amount_pence, r.from_day, r.until_day, '
            . 'r.note, r.version FROM regular_cost r JOIN channel c ON c.id = r.channel_id JOIN expense_type t ON t.id = r.type_id WHERE r.deleted_at IS NULL'
            . ($shop === null ? '' : ' AND r.channel_id = ?') . ' ORDER BY t.sort_order, t.name, r.id', $shop === null ? [] : [$shop]);
        $groups = [];
        $inForce = ['week' => 0, 'month' => 0, 'year' => 0];
        foreach ($rows as $r) {
            $until = $r['until_day'] === null ? null : (string) $r['until_day'];
            $running = (string) $r['from_day'] <= $today && ($until === null || $until >= $today);
            $ended = $until !== null && $until < $today;
            if ($running) {
                $inForce[(string) $r['every']] += (int) $r['amount_pence'];
            }
            $g = (string) $r['grp'];
            $groups[$g] ??= ['group' => $g, 'rows' => []];
            $groups[$g]['rows'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'shop_id' => (int) $r['channel_id'], 'shop' => (string) $r['shop'],
                'every' => (string) $r['every'], 'amount' => (int) $r['amount_pence'], 'from' => (string) $r['from_day'], 'until' => $until, 'ended' => $ended,
                'running' => $running, 'note' => $r['note'] === null ? null : (string) $r['note']];
        }
        return ['groups' => array_values($groups), 'today' => $inForce];
    }

    /** @return array<string, mixed>|null one regular cost line with its shop, group, the line it continues, the line continuing it, its history */
    public function regular(int $id): ?array
    {
        $r = $this->db->one('SELECT r.*, c.name AS shop, t.name AS grp, t.is_active AS group_active, du.display_name AS deleted_name FROM regular_cost r '
            . 'JOIN channel c ON c.id = r.channel_id JOIN expense_type t ON t.id = r.type_id LEFT JOIN staff_user du ON du.id = r.deleted_by WHERE r.id = ?', [$id]);
        if ($r === null) {
            return null;
        }
        $next = $this->db->one('SELECT id, amount_pence, from_day FROM regular_cost WHERE replaces_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$id]);
        return $r + ['next' => $next, 'history' => ExpenseHistory::of($this->db, 'regular_cost', $id)];
    }

    /**
     * The real profit of the days $from..$to per shop (the brief's point 4; docs/decisions.md EX15): the profit days' figures as the other
     * Profit pages show them (goods sold less refunds, buying cost, delivery charged, the carriers' bills on the orders and not on one), then
     * payment fees (not built yet: 0), the expenses (without VAT, by invoice date) and the regular costs (per day); real profit = goods - cost +
     * delivery charged - delivery cost - fees - expenses - regular costs. All from ONE moment of the database.
     *
     * @return list<array{shop_id: int, shop: string, goods: int, cost: int, delivery: int, delivery_cost: int, fees: int, expenses: int, expenses_count: int, regular: int, real: int, incomplete: bool}>
     */
    public function realProfit(string $from, string $to, ?int $shop): array
    {
        $views = new ProfitViews($this->db);
        return ProfitDays::snapshot($this->db, function () use ($views, $from, $to, $shop): array {
            $expenses = $this->expensesBy($from, $to, $shop);
            $regular = $this->regularBy($from, $to, $shop);
            $out = [];
            foreach ($this->shops() as $s) {
                if ($shop !== null && $s['id'] !== $shop) {
                    continue;
                }
                $f = $views->totals($from, $to, $s['id']);
                $goods = (int) $f['goods'];
                $cost = (int) $f['cost'];
                $delivery = (int) $f['delivery'];
                $deliveryCost = (int) $f['delivery_cost'] + (int) $f['delivery_other'];
                $fees = 0;
                $exp = $expenses[$s['id']]['net'] ?? 0;
                $reg = $regular[$s['id']] ?? 0;
                $out[] = ['shop_id' => $s['id'], 'shop' => $s['name'], 'goods' => $goods, 'cost' => $cost, 'delivery' => $delivery, 'delivery_cost' => $deliveryCost,
                    'fees' => $fees, 'expenses' => $exp, 'expenses_count' => $expenses[$s['id']]['count'] ?? 0, 'regular' => $reg,
                    'real' => $goods - $cost + $delivery - $deliveryCost - $fees - $exp - $reg, 'incomplete' => (bool) $f['incomplete']];
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
