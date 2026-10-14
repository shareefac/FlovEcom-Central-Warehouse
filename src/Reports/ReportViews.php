<?php

declare(strict_types=1);

namespace CW\Reports;

use CW\Db;
use CW\Ui\StockViews;

/**
 * The read side of the Reports tabs (docs/decisions.md PE1, PE8-PE11). Reads only. What a store can sell, what is low and what the stock
 * is worth come from stock_balance as it is now (the same reads as Stock › Overview: one row per item and warehouse, never the
 * ledger); the average cost, the first day in stock and the last sale come from report_item, and the sales from report_sales_day,
 * both kept by the report job (ReportRefresh) every 15 minutes. Lists are paged by PAGE with a COUNT, as Stock › Overview; a CSV
 * takes every row of the same list (limit: null).
 *
 * Money: the average cost of an item is in_value / in_units rounded half up to 6 decimals (CostHints::average's rule); the value of
 * an item in a warehouse is its units there (above zero) times that cost, rounded half up to pennies, and every total is the sum of
 * those, so a product, a brand, a warehouse and the whole stock add up exactly. Stock in another account's warehouse is not ours: it
 * is shown apart and never in our value.
 */
final class ReportViews
{
    public const PAGE = 50;
    public const STORE_SHOW = ['all', 'in', 'out'];
    public const LOW_SHOW = ['low', 'out'];
    public const SLOW_SHOW = ['slow', 'dead'];
    public const VALUE_BY = ['product', 'brand', 'warehouse'];
    /** The average cost in SQL (NULL when no unit came in with a cost) and a unit's value at it, in pennies, rounded half up. */
    private const AVG = 'IF(r.in_units > 0, ROUND(r.in_value / r.in_units, 6), NULL)';
    private const VALUE = 'IF(r.in_units > 0, ROUND(b.on_hand * ROUND(r.in_value / r.in_units, 6), 2), 0)';

    public function __construct(private readonly Db $db)
    {
    }

    // ---- Stock by Store ------------------------------------------------------------------------------------------------------------

    /** @return list<array{id: int, code: string, name: string, warehouse_id: ?int, warehouse: ?string}> every store, with the warehouse it sells from */
    public function stores(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'],
            'warehouse_id' => $r['warehouse_id'] === null ? null : (int) $r['warehouse_id'], 'warehouse' => $r['wname'] === null ? null : (string) $r['wname']],
            $this->db->all('SELECT c.id, c.code, c.name, cw.warehouse_id, w.name AS wname FROM channel c '
                . 'LEFT JOIN channel_warehouse cw ON cw.channel_id = c.id AND cw.is_sellable = 1 LEFT JOIN warehouse w ON w.id = cw.warehouse_id ORDER BY c.id'));
    }

    /**
     * Per store: its matched products (each warehouse item once), how many have something to sell, how many not, and the units it can
     * sell (each item's available units in the store's warehouse, above zero).
     *
     * @param list<array{id: int, warehouse_id: ?int}> $stores
     * @return array<int, array{matched: int, in: int, out: int, units: int}>
     */
    public function storeFigures(array $stores): array
    {
        $out = [];
        foreach ($stores as $s) {
            $r = $this->db->one('SELECT COUNT(*) AS matched, COALESCE(SUM(COALESCE(b.on_hand - b.allocated - b.held, 0) > 0), 0) AS in_n, '
                . 'COALESCE(SUM(GREATEST(COALESCE(b.on_hand - b.allocated - b.held, 0), 0)), 0) AS units '
                . "FROM (SELECT DISTINCT sku_id FROM channel_listing WHERE channel_id = ? AND status = 'mapped') m "
                . 'LEFT JOIN stock_balance b ON b.warehouse_id = ? AND b.sku_id = m.sku_id', [$s['id'], $s['warehouse_id'] ?? 0]) ?? [];
            $matched = (int) ($r['matched'] ?? 0);
            $in = $s['warehouse_id'] === null ? 0 : (int) ($r['in_n'] ?? 0);
            $out[$s['id']] = ['matched' => $matched, 'in' => $in, 'out' => $matched - $in, 'units' => $s['warehouse_id'] === null ? 0 : (int) ($r['units'] ?? 0)];
        }
        return $out;
    }

    /**
     * One store's matched products with what it can sell: the available units in the store's warehouse (on hand less reserved), the
     * selling policy, and the biggest "1 sale = N units" of its listings. Out of stock first, then by name.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function storeItems(int $storeId, ?int $warehouseId, string $q, string $show, int $page, ?int $limit = self::PAGE): array
    {
        [$where, $params] = StockViews::skuMatch($q, 's');
        $inner = 'SELECT s.id AS sku_id, s.code, s.name, s.brand, s.sell_policy, COALESCE(b.on_hand - b.allocated - b.held, 0) AS available, '
            . 'MAX(cl.units_per_item) AS pack FROM channel_listing cl JOIN sku s ON s.id = cl.sku_id '
            . 'LEFT JOIN stock_balance b ON b.warehouse_id = ? AND b.sku_id = cl.sku_id '
            . "WHERE cl.channel_id = ? AND cl.status = 'mapped'" . ($where === [] ? '' : ' AND ' . implode(' AND ', $where))
            . ' GROUP BY s.id, s.code, s.name, s.brand, s.sell_policy, b.on_hand, b.allocated, b.held'
            . ($show === 'in' ? ' HAVING available > 0' : ($show === 'out' ? ' HAVING available <= 0' : ''));
        return $this->paged($inner, [$warehouseId ?? 0, $storeId, ...$params], 'available > 0, name, sku_id', $page, $limit);
    }

    // ---- Low Stock -----------------------------------------------------------------------------------------------------------------

    /**
     * The products the stores sell (matched on a store; not merged into another, not marked discontinued on their card) whose units
     * the stores can sell (the sellable warehouses' on hand less reserved) are at or below their low level: their own "least stock to
     * keep" (item_reorder.min_stock), else $default. `low`: above zero; `out`: zero or less. Fewest first. `low` and `out` count both
     * lists (with the search).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, low: int, out: int}
     */
    public function lowStock(string $show, string $q, int $default, int $page, ?int $limit = self::PAGE): array
    {
        [$where, $params] = StockViews::skuMatch($q, 's');
        $sellable = array_map('intval', $this->db->column('SELECT id FROM warehouse WHERE is_sellable = 1'));
        $inner = 'SELECT s.id AS sku_id, s.code, s.name, s.brand, COALESCE(SUM(b.on_hand - b.allocated - b.held), 0) AS available, o.min_stock, '
            . 'COALESCE(o.min_stock, ?) AS low_at FROM sku s '
            . 'LEFT JOIN stock_balance b ON b.sku_id = s.id AND b.warehouse_id IN (' . ($sellable === [] ? '0' : implode(', ', $sellable)) . ') '
            . 'LEFT JOIN item_reorder o ON o.sku_id = s.id LEFT JOIN item_card c ON c.sku_id = s.id '
            . "WHERE s.merged_into_sku_id IS NULL AND COALESCE(c.discontinued, 0) = 0 AND EXISTS (SELECT 1 FROM channel_listing cl WHERE cl.sku_id = s.id AND cl.status = 'mapped')"
            . ($where === [] ? '' : ' AND ' . implode(' AND ', $where))
            . ' GROUP BY s.id, s.code, s.name, s.brand, o.min_stock HAVING available <= low_at';
        $show = $show === 'out' ? 'out' : 'low';
        $r = $this->paged($inner, [$default, ...$params], 'available, name, sku_id', $page, $limit, $show === 'out' ? 'available <= 0' : 'available > 0',
            ['low' => 'available > 0', 'out' => 'available <= 0'], $show);
        return $r + ['low' => $r['counts']['low'] ?? 0, 'out' => $r['counts']['out'] ?? 0];
    }

    // ---- Stock Value ---------------------------------------------------------------------------------------------------------------

    /**
     * The figures over the tab: our stock's value at average cost, its units with a cost and without one, and the units in another
     * account's warehouses (left out of our value).
     *
     * @return array{value: string, costed: int, not_costed: int, theirs: int}
     */
    public function valueFigures(): array
    {
        $out = ['value' => '0.00', 'costed' => 0, 'not_costed' => 0, 'theirs' => 0];
        foreach ($this->db->all('SELECT w.stock_owner, COALESCE(SUM(' . self::VALUE . '), 0) AS value, COALESCE(SUM(IF(r.in_units > 0, b.on_hand, 0)), 0) AS costed, '
            . 'COALESCE(SUM(IF(r.in_units > 0, 0, b.on_hand)), 0) AS not_costed FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id '
            . 'LEFT JOIN report_item r ON r.sku_id = b.sku_id WHERE b.on_hand > 0 GROUP BY w.stock_owner') as $r) {
            if ($r['stock_owner'] === 'own') {
                $out['value'] = (string) $r['value'];
                $out['costed'] = (int) $r['costed'];
                $out['not_costed'] = (int) $r['not_costed'];
            } else {
                $out['theirs'] += (int) $r['costed'] + (int) $r['not_costed'];
            }
        }
        return $out;
    }

    /**
     * Our products in stock with their units (our warehouses, above zero), average cost and value: the biggest value first; with
     * $noCost only those with no unit that came in with a cost (their value is not known), the most units first.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function valueByProduct(string $q, bool $noCost, int $page, ?int $limit = self::PAGE): array
    {
        [$where, $params] = StockViews::skuMatch($q, 's');
        if ($noCost) {
            $where[] = 'COALESCE(r.in_units, 0) = 0';
        }
        $inner = 'SELECT s.id AS sku_id, s.code, s.name, s.brand, SUM(b.on_hand) AS units, ' . self::AVG . ' AS avg_cost, SUM(' . self::VALUE . ') AS value '
            . "FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id AND w.stock_owner = 'own' JOIN sku s ON s.id = b.sku_id "
            . 'LEFT JOIN report_item r ON r.sku_id = b.sku_id WHERE b.on_hand > 0' . ($where === [] ? '' : ' AND ' . implode(' AND ', $where))
            . ' GROUP BY s.id, s.code, s.name, s.brand, r.in_units, r.in_value';
        return $this->paged($inner, $params, $noCost ? 'units DESC, name, sku_id' : 'value DESC, name, sku_id', $page, $limit);
    }

    /** @return list<array{brand: ?string, products: int, units: int, value: string, not_costed: int}> our stock by brand, the biggest value first */
    public function valueByBrand(): array
    {
        return array_map(static fn (array $r): array => ['brand' => $r['brand'] === null || $r['brand'] === '' ? null : (string) $r['brand'],
            'products' => (int) $r['products'], 'units' => (int) $r['units'], 'value' => (string) $r['value'], 'not_costed' => (int) $r['not_costed']],
            $this->db->all('SELECT s.brand, COUNT(DISTINCT b.sku_id) AS products, SUM(b.on_hand) AS units, SUM(' . self::VALUE . ') AS value, '
                . "SUM(IF(r.in_units > 0, 0, b.on_hand)) AS not_costed FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id AND w.stock_owner = 'own' "
                . 'JOIN sku s ON s.id = b.sku_id LEFT JOIN report_item r ON r.sku_id = b.sku_id WHERE b.on_hand > 0 GROUP BY s.brand ORDER BY value DESC, s.brand'));
    }

    /** @return list<array{id: int, name: string, ours: bool, products: int, units: int, value: string, not_costed: int}> every warehouse with stock, ours first */
    public function valueByWarehouse(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'ours' => $r['stock_owner'] === 'own',
            'products' => (int) $r['products'], 'units' => (int) $r['units'], 'value' => (string) $r['value'], 'not_costed' => (int) $r['not_costed']],
            $this->db->all('SELECT w.id, w.name, w.stock_owner, COUNT(*) AS products, SUM(b.on_hand) AS units, SUM(' . self::VALUE . ') AS value, '
                . 'SUM(IF(r.in_units > 0, 0, b.on_hand)) AS not_costed FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id '
                . "LEFT JOIN report_item r ON r.sku_id = b.sku_id WHERE b.on_hand > 0 GROUP BY w.id, w.name, w.stock_owner, w.sort_order "
                . "ORDER BY w.stock_owner = 'own' DESC, w.sort_order, w.id"));
    }

    // ---- Slow Stock ----------------------------------------------------------------------------------------------------------------

    /**
     * Our products in stock (our warehouses, above zero) whose last sale (else, never sold, the day stock first came in) is at least
     * $slowDays ago and less than $deadDays (`slow`), or at least $deadDays ago (`dead`). A product the report job has not read yet is
     * left out (nothing is known of it). The longest without a sale first. `slow` and `dead` count both lists (with the search).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, slow: int, dead: int}
     */
    public function slow(string $show, string $q, string $today, int $slowDays, int $deadDays, int $page, ?int $limit = self::PAGE): array
    {
        [$where, $params] = StockViews::skuMatch($q, 's');
        $slowCut = (new \DateTimeImmutable($today))->modify("-{$slowDays} days")->format('Y-m-d');
        $deadCut = (new \DateTimeImmutable($today))->modify("-{$deadDays} days")->format('Y-m-d');
        $inner = 'SELECT s.id AS sku_id, s.code, s.name, s.brand, SUM(b.on_hand) AS units, r.last_sale_on, r.first_in_on, '
            . 'COALESCE(r.last_sale_on, r.first_in_on) AS since, SUM(' . self::VALUE . ') AS value '
            . "FROM stock_balance b JOIN warehouse w ON w.id = b.warehouse_id AND w.stock_owner = 'own' JOIN sku s ON s.id = b.sku_id "
            . 'JOIN report_item r ON r.sku_id = b.sku_id WHERE b.on_hand > 0 AND COALESCE(r.last_sale_on, r.first_in_on) <= ?'
            . ($where === [] ? '' : ' AND ' . implode(' AND ', $where))
            . ' GROUP BY s.id, s.code, s.name, s.brand, r.last_sale_on, r.first_in_on, r.in_units, r.in_value';
        $show = $show === 'dead' ? 'dead' : 'slow';
        // The cuts are dates this method made (Y-m-d), named as literals in the conditions on the inner's columns.
        $r = $this->paged($inner, [$slowCut, ...$params], 'since, name, sku_id', $page, $limit, $show === 'dead' ? "since <= '{$deadCut}'" : "since > '{$deadCut}'",
            ['slow' => "since > '{$deadCut}'", 'dead' => "since <= '{$deadCut}'"], $show);
        return $r + ['slow' => $r['counts']['slow'] ?? 0, 'dead' => $r['counts']['dead'] ?? 0];
    }

    // ---- Sales by Store ------------------------------------------------------------------------------------------------------------

    /**
     * Per store over the days $from..$to (UK days, both included): orders, units sold, sent, cancelled and returned; and the same per
     * day for one store ($storeId) or all of them added up (null).
     *
     * @return array{stores: array<int, array<string, int>>, days: list<array<string, mixed>>}
     */
    public function sales(string $from, string $to, ?int $storeId): array
    {
        $cols = 'SUM(orders) AS orders, SUM(units_sold) AS units_sold, SUM(units_sent) AS units_sent, SUM(units_cancelled) AS units_cancelled, '
            . 'SUM(units_returned) AS units_returned';
        $stores = [];
        foreach ($this->db->all("SELECT channel_id, {$cols} FROM report_sales_day WHERE sale_date BETWEEN ? AND ? GROUP BY channel_id", [$from, $to]) as $r) {
            $stores[(int) $r['channel_id']] = self::ints($r);
        }
        $days = [];
        foreach ($this->db->all("SELECT sale_date, {$cols} FROM report_sales_day WHERE sale_date BETWEEN ? AND ?"
            . ($storeId === null ? '' : ' AND channel_id = ?') . ' GROUP BY sale_date ORDER BY sale_date DESC', $storeId === null ? [$from, $to] : [$from, $to, $storeId]) as $r) {
            $days[] = ['day' => (string) $r['sale_date']] + self::ints($r);
        }
        return ['stores' => $stores, 'days' => $days];
    }

    /** @param array<string, mixed> $r @return array<string, int> */
    private static function ints(array $r): array
    {
        $out = [];
        foreach (['orders', 'units_sold', 'units_sent', 'units_cancelled', 'units_returned'] as $k) {
            $out[$k] = (int) ($r[$k] ?? 0);
        }
        return $out;
    }

    /**
     * A page of an inner GROUP BY query in ONE pass over it: the page's rows with the size of the list and $counts (name => a condition
     * on the inner's columns) worked out by window functions over the whole inner before the LIMIT. $filter narrows the list (a
     * condition on the inner's columns, no parameters); the total is the count named $totalKey, else the filtered list's own. $limit
     * null = every row (a CSV). A page past the end shows the last page (one more read only then).
     *
     * @param list<mixed> $params
     * @param array<string, string> $counts
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, counts: array<string, int>}
     */
    private function paged(string $inner, array $params, string $order, int $page, ?int $limit, ?string $filter = null, array $counts = [],
        ?string $totalKey = null): array
    {
        $where = $filter === null ? '' : ' WHERE ' . $filter;
        if ($limit === null) {
            $rows = $this->db->all('SELECT * FROM (' . $inner . ') x' . $where . ' ORDER BY ' . $order, $params);
            return ['rows' => $rows, 'total' => count($rows), 'page' => 1, 'pages' => 1, 'counts' => []];
        }
        $win = '';
        foreach ($counts as $k => $cond) {
            $win .= ", SUM({$cond}) OVER () AS _c_{$k}";
        }
        // The list's size: over the inner when the filter is one of the counts (it applies after the window), else over the filtered rows.
        $sql = $totalKey === null ? 'SELECT x.*, COUNT(*) OVER () AS _n' . $win . ' FROM (' . $inner . ') x' . $where
            : 'SELECT * FROM (SELECT x.*, COUNT(*) OVER () AS _n' . $win . ' FROM (' . $inner . ') x) y' . $where;
        $read = function (int $page) use ($sql, $order, $limit, $params): array {
            return $this->db->all($sql . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . (max(1, $page) - 1) * $limit, $params);
        };
        $rows = $limit === 0 ? [] : $read($page);
        if ($rows === []) {
            $sums = '';
            foreach ($counts as $k => $cond) {
                $sums .= ", COALESCE(SUM({$cond}), 0) AS _c_{$k}";
            }
            $first = $this->db->one('SELECT COUNT(*) AS _n' . $sums . ' FROM (' . $inner . ') x' . ($totalKey === null ? $where : ''), $params) ?? ['_n' => 0];
        } else {
            $first = $rows[0];
        }
        $got = [];
        foreach (array_keys($counts) as $k) {
            $got[$k] = (int) ($first['_c_' . $k] ?? 0);
        }
        $total = $totalKey === null ? (int) ($first['_n'] ?? 0) : $got[$totalKey];
        $pages = max(1, intdiv($total + max(1, $limit) - 1, max(1, $limit)));
        if ($rows === [] && $limit > 0 && $page > $pages && $total > 0) {
            $page = $pages;
            $rows = $read($page);
        }
        $rows = array_map(static fn (array $r): array => array_filter($r, static fn (string $k): bool => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY), $rows);
        return ['rows' => $rows, 'total' => $total, 'page' => min(max(1, $page), $pages), 'pages' => $pages, 'counts' => $got];
    }
}
