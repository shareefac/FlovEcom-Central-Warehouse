<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Db;

/**
 * The read side of Stock › Overview and Stock › Movements (first version, owner's request of 8 Oct 2026): the figures of
 * stock_balance and the rows of stock_ledger, as the item page shows them for one product, for every product at once. Reads only.
 * The overview is one row per product (its figure in each warehouse), paged by PAGE with a COUNT; the movements page by the ledger's
 * id (`before`), newest first, never with a COUNT or an OFFSET over the whole ledger. A product search finds at most SKU_LIMIT
 * products by name, brand or CW number (the same words-in-any-order rule as the reorder list).
 */
final class StockViews
{
    public const PAGE = 50;
    public const MOVES_PAGE = 50;
    public const SKU_LIMIT = 200;
    public const SHOW = ['stock', 'all', 'negative'];
    public const FIGURES = ['on_hand', 'all'];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id: int, code: string, name: string, sellable: bool, active: bool}> every warehouse, in the warehouses page's order */
    public function warehouses(): array
    {
        return array_map(static fn (array $w): array => ['id' => (int) $w['id'], 'code' => (string) $w['code'], 'name' => (string) $w['name'],
            'sellable' => (int) $w['is_sellable'] === 1, 'active' => (int) $w['is_active'] === 1],
            $this->db->all('SELECT id, code, name, is_sellable, is_active FROM warehouse ORDER BY sort_order, id'));
    }

    /**
     * One page of products (design v4's "Stock by product"): one row per product with its on-hand figure in each warehouse, the
     * reserved units (orders and checkouts) and what the websites can sell (the sellable warehouses' on hand less reserved), the ones
     * with nothing to sell first, then by name; and how many products of the whole list have something to sell and how many not.
     *
     * @param array{warehouse: ?int, q: string, show: string, page: int} $f
     * @param list<array{id: int}> $warehouses
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, in: int, out: int}
     */
    public function products(array $f, array $warehouses): array
    {
        $where = [];
        $params = [];
        [$skuWhere, $skuParams] = self::skuMatch($f['q'], 's');
        array_push($where, ...$skuWhere);
        array_push($params, ...$skuParams);
        $having = [];
        $nonZero = '(sb.on_hand <> 0 OR sb.allocated <> 0 OR sb.held <> 0)';
        if ($f['warehouse'] !== null) {
            $having[] = 'SUM(sb.warehouse_id = ' . (int) $f['warehouse'] . ($f['show'] === 'all' ? '' : ' AND ' . $nonZero) . ') > 0';
        }
        if ($f['show'] === 'stock') {
            $having[] = 'SUM(' . $nonZero . ') > 0';
        } elseif ($f['show'] === 'negative') {
            $having[] = 'SUM(sb.on_hand < 0) > 0';
        }
        $cols = '';
        foreach ($warehouses as $w) {
            $cols .= ', SUM(CASE WHEN sb.warehouse_id = ' . (int) $w['id'] . ' THEN sb.on_hand ELSE 0 END) AS w_' . (int) $w['id'];
        }
        $inner = 'SELECT s.id AS sku_id, s.code, s.name, s.brand, SUM(sb.allocated + sb.held) AS reserved, '
            . 'SUM(CASE WHEN w.is_sellable = 1 THEN sb.on_hand - sb.allocated - sb.held ELSE 0 END) AS available' . $cols
            . ' FROM stock_balance sb JOIN sku s ON s.id = sb.sku_id JOIN warehouse w ON w.id = sb.warehouse_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' GROUP BY s.id, s.code, s.name, s.brand'
            . ($having === [] ? '' : ' HAVING ' . implode(' AND ', $having));
        $c = $this->db->one('SELECT COUNT(*) AS n, COALESCE(SUM(x.available > 0), 0) AS in_n FROM (' . $inner . ') x', $params) ?? [];
        $total = (int) ($c['n'] ?? 0);
        $pages = max(1, intdiv($total + self::PAGE - 1, self::PAGE));
        $page = min(max(1, $f['page']), $pages);
        $rows = $this->db->all($inner . ' ORDER BY available > 0, s.name, s.id LIMIT ' . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE), $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'in' => (int) ($c['in_n'] ?? 0), 'out' => $total - (int) ($c['in_n'] ?? 0)];
    }

    /**
     * The four figures over the Stock tabs (design v4's tiles): warehouse products, products with something in a sellable warehouse,
     * units in every warehouse, products with nothing to sell.
     *
     * @return array{products: int, in_stock: int, units: int}
     */
    public function figures(): array
    {
        $r = $this->db->one('SELECT (SELECT COUNT(*) FROM sku WHERE merged_into_sku_id IS NULL) AS products, '
            . '(SELECT COUNT(DISTINCT sb.sku_id) FROM stock_balance sb JOIN warehouse w ON w.id = sb.warehouse_id WHERE w.is_sellable = 1 AND sb.on_hand > 0) AS in_stock, '
            . '(SELECT COALESCE(SUM(on_hand), 0) FROM stock_balance) AS units') ?? [];
        return ['products' => (int) ($r['products'] ?? 0), 'in_stock' => (int) ($r['in_stock'] ?? 0), 'units' => (int) ($r['units'] ?? 0)];
    }

    /**
     * The ledger, newest first: at most MOVES_PAGE rows older than `before`, and the id to ask for the next (older) page.
     *
     * @param array{warehouse: ?int, q: string, type: ?string, figure: string, before: ?int} $f
     * @return array{rows: list<array<string, mixed>>, next: ?int}
     */
    public function movements(array $f): array
    {
        $where = [];
        $params = [];
        if ($f['before'] !== null) {
            $where[] = 'l.id < ?';
            $params[] = $f['before'];
        }
        if ($f['warehouse'] !== null) {
            $where[] = 'l.warehouse_id = ?';
            $params[] = $f['warehouse'];
        }
        if ($f['figure'] === 'on_hand') {
            $where[] = "l.bucket = 'on_hand'";
        }
        if ($f['type'] !== null) {
            $where[] = 'l.movement_type = ?';
            $params[] = $f['type'];
        }
        if ($f['q'] !== '') {
            [$skuWhere, $skuParams] = self::skuMatch($f['q'], 'sku');
            $ids = array_map('intval', $this->db->column('SELECT id FROM sku WHERE ' . implode(' AND ', $skuWhere) . ' ORDER BY id LIMIT ' . self::SKU_LIMIT, $skuParams));
            if ($ids === []) {
                return ['rows' => [], 'next' => null];
            }
            $where[] = 'l.sku_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        $rows = $this->db->all(
            'SELECT l.id, l.warehouse_id, w.name AS warehouse, l.sku_id, s.code, s.name, l.bucket, l.qty_delta, l.balance_after, l.movement_type, '
            . 'l.order_ref, l.doc_ref, l.actor, l.note, COALESCE(l.effective_at, l.created_at) AS at '
            . 'FROM stock_ledger l JOIN sku s ON s.id = l.sku_id JOIN warehouse w ON w.id = l.warehouse_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY l.id DESC LIMIT ' . (self::MOVES_PAGE + 1),
            $params,
        );
        $next = null;
        if (count($rows) > self::MOVES_PAGE) {
            $rows = array_slice($rows, 0, self::MOVES_PAGE);
            $next = (int) $rows[self::MOVES_PAGE - 1]['id'];
        }
        return ['rows' => $rows, 'next' => $next];
    }

    /**
     * Who made each change, in words (the item page's rule, plan F253): staff:<id> => the person's name, system:… => "set up by CW",
     * channel:<code> => the website's name.
     *
     * @param list<string> $actors
     * @return array<string, string> actor => words
     */
    public function actors(array $actors): array
    {
        $staff = [];
        foreach ($actors as $a) {
            if (preg_match('/^staff:(\d{1,10})$/D', $a, $m) === 1) {
                $staff[(int) $m[1]] = true;
            }
        }
        $people = [];
        if ($staff !== []) {
            $ids = array_keys($staff);
            foreach ($this->db->all('SELECT id, display_name FROM staff_user WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $p) {
                $people[(int) $p['id']] = (string) $p['display_name'];
            }
        }
        $sites = array_column($this->db->all('SELECT code, name FROM channel'), 'name', 'code');
        $out = [];
        foreach (array_unique($actors) as $a) {
            $out[$a] = match (true) {
                preg_match('/^staff:(\d{1,10})$/D', $a, $m) === 1 => $people[(int) $m[1]] ?? $a,
                str_starts_with($a, 'system:') => Words::ITEM['by_cw'],
                str_starts_with($a, 'channel:') => (string) ($sites[substr($a, 8)] ?? Words::ITEM['by_site']),
                default => $a,
            };
        }
        return $out;
    }

    /**
     * The condition that finds products by $q: a CW number (CW-000123, 123) by its id, else every word in the name, brand or code.
     *
     * @return array{0: list<string>, 1: list<string|int>}
     */
    private static function skuMatch(string $q, string $alias): array
    {
        if ($q === '') {
            return [[], []];
        }
        if (preg_match('/^(?:cw-?)?0*([0-9]{1,10})$/iD', $q, $m) === 1) {
            return [["{$alias}.id = ?"], [(int) $m[1]]];
        }
        $where = [];
        $params = [];
        foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6) as $w) {
            $where[] = "CONCAT_WS(' ', {$alias}.name, {$alias}.brand, {$alias}.code) LIKE ?";
            $params[] = '%' . addcslashes($w, '\\%_') . '%';
        }
        return [$where, $params];
    }
}
