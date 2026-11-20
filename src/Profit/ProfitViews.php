<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;
use CW\Reports\ReportRefresh;

/**
 * The read side of the Profit screens (docs/decisions.md PR24-PR28). Reads only. Every list comes from the day summaries (profit_day,
 * profit_item_day: one row per store and day, per store, day and item) or from an indexed read of the orders and lines (sale_order by day,
 * by margin or by number; sale_line by day and cost source), never a scan of every line. Money is in pence; profit = goods sold ex VAT less
 * the buying cost; margin = profit / goods sold. Goods sold are the lines' sale ex VAT less the refunds a shop keeps at order level (those
 * are not on any product, so the lists by product, brand and supplier do not take them off).
 */
final class ProfitViews
{
    public const PAGE = 50;
    /** The longest period "the lowest margin first" sorts (its orders are sorted, so it is bounded). */
    public const LOWEST_MAX_DAYS = 93;
    public const BY = ['shop', 'product', 'brand', 'supplier'];
    public const SORTS = ['profit', 'sale', 'cost', 'margin', 'margin_low', 'units'];
    public const GROUPS = ['day', 'week', 'month'];
    /** The most rows a list by product, brand or supplier shows (top first). */
    public const ROW_LIMIT = 300;
    /** The summary's periods, in order. */
    public const SUMMARY = ['today', 'yesterday', 'last_7', 'last_30', 'this_month', 'last_month'];
    private const SUMS = 'COALESCE(SUM(orders), 0) AS orders, COALESCE(SUM(resends), 0) AS resends, COALESCE(SUM(units), 0) AS units, '
        . 'COALESCE(SUM(sale_ex), 0) AS sale_ex, COALESCE(SUM(order_refund_ex), 0) AS order_refund_ex, COALESCE(SUM(cost), 0) AS cost, '
        . 'COALESCE(SUM(units_no_cost), 0) AS units_no_cost, COALESCE(SUM(delivery_ex), 0) AS delivery_ex';
    private const ITEM_SUMS = 'COALESCE(SUM(p.units), 0) AS units, COALESCE(SUM(p.sale_ex), 0) AS sale_ex, COALESCE(SUM(p.cost), 0) AS cost, '
        . 'COALESCE(SUM(p.units_no_cost), 0) AS units_no_cost';

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array{id: int, code: string, name: string}> */
    public function stores(): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name']],
            $this->db->all('SELECT id, code, name FROM channel ORDER BY id'));
    }

    /**
     * The summary's six periods ending today (UK).
     *
     * @return list<array{key: string, from: string, to: string, f: array<string, int|null>}>
     */
    public function summary(string $today, ?int $store): array
    {
        $t = new \DateTimeImmutable($today);
        $periods = [
            'today' => [$today, $today],
            'yesterday' => [$t->modify('-1 day')->format('Y-m-d'), $t->modify('-1 day')->format('Y-m-d')],
            'last_7' => [$t->modify('-6 days')->format('Y-m-d'), $today],
            'last_30' => [$t->modify('-29 days')->format('Y-m-d'), $today],
            'this_month' => [$t->format('Y-m-01'), $today],
            'last_month' => [$t->modify('first day of last month')->format('Y-m-d'), $t->modify('last day of last month')->format('Y-m-d')],
        ];
        $out = [];
        foreach ($periods as $k => [$from, $to]) {
            $out[] = ['key' => $k, 'from' => $from, 'to' => $to, 'f' => $this->totals($from, $to, $store)];
        }
        return $out;
    }

    /** @return array<string, int|null> the figures of the days $from..$to (one store, or all), with the units still waiting for their cost */
    public function totals(string $from, string $to, ?int $store): array
    {
        $r = $this->db->one('SELECT ' . self::SUMS . ' FROM profit_day WHERE day BETWEEN ? AND ?' . ($store === null ? '' : ' AND channel_id = ?'),
            [$from, $to, ...($store === null ? [] : [$store])]) ?? [];
        return self::figures($r + ['pending' => array_sum($this->pending($from, $to, $store))]);
    }

    /**
     * The units of paid orders whose cost waits for the cost job (a few minutes at most), by day (index (cost_source, day)): until then their
     * cost is 0 in every figure, which the screens say (the Codex review of 92efa82, c6).
     *
     * @return array<string, int> day => units
     */
    public function pending(string $from, string $to, ?int $store): array
    {
        $out = [];
        foreach ($this->db->all('SELECT day, SUM(units) AS u FROM sale_line WHERE cost_source IS NULL AND day BETWEEN ? AND ? AND removed_at IS NULL'
            . ($store === null ? '' : ' AND channel_id = ?') . ' GROUP BY day', [$from, $to, ...($store === null ? [] : [$store])]) as $r) {
            if ((int) $r['u'] > 0) {
                $out[(string) $r['day']] = (int) $r['u'];
            }
        }
        return $out;
    }

    /**
     * Day by day, week by week (from Monday) or month by month, newest first.
     *
     * @return list<array{from: string, to: string, f: array<string, int|null>}>
     */
    public function periods(string $from, string $to, ?int $store, string $by): array
    {
        $groups = [];
        $pending = $this->pending($from, $to, $store);
        foreach ($this->db->all('SELECT day, ' . self::SUMS . ' FROM profit_day WHERE day BETWEEN ? AND ?' . ($store === null ? '' : ' AND channel_id = ?')
            . ' GROUP BY day ORDER BY day', [$from, $to, ...($store === null ? [] : [$store])]) as $r) {
            $r['pending'] = $pending[(string) $r['day']] ?? 0;
            $d = new \DateTimeImmutable((string) $r['day']);
            [$gFrom, $gTo] = match ($by) {
                'week' => [$d->modify('monday this week')->format('Y-m-d'), $d->modify('sunday this week')->format('Y-m-d')],
                'month' => [$d->format('Y-m-01'), $d->format('Y-m-t')],
                default => [$d->format('Y-m-d'), $d->format('Y-m-d')],
            };
            $groups[$gFrom] ??= ['from' => max($gFrom, $from), 'to' => min($gTo, $to), 'raw' => []];
            foreach ($r as $k => $v) {
                if ($k !== 'day') {
                    $groups[$gFrom]['raw'][$k] = ($groups[$gFrom]['raw'][$k] ?? 0) + (int) $v;
                }
            }
        }
        krsort($groups);
        return array_values(array_map(static fn (array $g): array => ['from' => $g['from'], 'to' => $g['to'], 'f' => self::figures($g['raw'])], $groups));
    }

    /**
     * By shop, product, brand or supplier, the biggest first by $sort (at most ROW_LIMIT rows).
     *
     * @return array{rows: list<array<string, mixed>>, more: bool}
     */
    public function breakdown(string $from, string $to, ?int $store, string $by, string $sort): array
    {
        // Goods sold as the rows show them (figures()): by shop, the lines less the refunds kept at order level (the Codex review of 92efa82,
        // c4); by product, brand or supplier, the lines (those refunds are on no product: the page says how much they are, c7).
        $goods = $by === 'shop' ? '(SUM(p.sale_ex) - SUM(p.order_refund_ex))' : 'SUM(p.sale_ex)';
        $margin = "IF({$goods} > 0, ({$goods} - SUM(p.cost)) / {$goods}, NULL)";
        $order = match ($sort) {
            'sale' => "{$goods} DESC",
            'cost' => 'SUM(p.cost) DESC',
            'units' => 'SUM(p.units) DESC',
            // A row with units that have no price is not ranked by its margin (it is too high): after the others (c6, round 2).
            'margin' => "SUM(p.units_no_cost) > 0, {$margin} IS NULL, {$margin} DESC",
            'margin_low' => "SUM(p.units_no_cost) > 0, {$margin} IS NULL, {$margin} ASC",
            default => "{$goods} - SUM(p.cost) DESC",
        };
        $where = 'p.day BETWEEN ? AND ?' . ($store === null ? '' : ' AND p.channel_id = ?');
        $params = [$from, $to, ...($store === null ? [] : [$store])];
        $limit = self::ROW_LIMIT + 1;
        $rows = match ($by) {
            'shop' => $this->db->all('SELECT p.channel_id AS id, c.name AS label, NULL AS code, ' . self::SUMS . " FROM profit_day p JOIN channel c ON c.id = p.channel_id "
                . "WHERE {$where} GROUP BY p.channel_id, c.name ORDER BY {$order}, p.channel_id LIMIT {$limit}", $params),
            'brand' => $this->db->all('SELECT COALESCE(NULLIF(s.brand, \'\'), \'\') AS label, NULL AS id, NULL AS code, ' . self::ITEM_SUMS . ' FROM profit_item_day p '
                . "LEFT JOIN sku s ON s.id = p.sku_id WHERE {$where} GROUP BY label ORDER BY {$order}, label LIMIT {$limit}", $params),
            'supplier' => $this->db->all('SELECT si.supplier_id AS id, COALESCE(sp.name, \'\') AS label, NULL AS code, ' . self::ITEM_SUMS . ' FROM profit_item_day p '
                . 'LEFT JOIN supplier_item si ON si.preferred_sku_id = p.sku_id LEFT JOIN supplier sp ON sp.id = si.supplier_id '
                . "WHERE {$where} GROUP BY si.supplier_id, sp.name ORDER BY {$order}, label LIMIT {$limit}", $params),
            default => $this->db->all('SELECT p.sku_id AS id, COALESCE(s.name, \'\') AS label, s.code, ' . self::ITEM_SUMS . ' FROM profit_item_day p '
                . "LEFT JOIN sku s ON s.id = p.sku_id WHERE {$where} GROUP BY p.sku_id, s.name, s.code ORDER BY {$order}, p.sku_id LIMIT {$limit}", $params),
        };
        $more = count($rows) > self::ROW_LIMIT;
        $out = [];
        foreach (array_slice($rows, 0, self::ROW_LIMIT) as $r) {
            $id = $r['id'] === null ? null : (int) $r['id'];
            $out[] = ['id' => $id, 'label' => (string) $r['label'], 'code' => $r['code'] === null ? null : (string) $r['code'], 'f' => self::figures($r)];
        }
        return ['rows' => $out, 'more' => $more];
    }

    /**
     * The paid orders of the days (one store, or all), newest first or the lowest margin first, PAGE at a time after a cursor (keyset pages
     * on ix_sale_order_paid / ix_sale_order_store_paid and ix_sale_order_margin: a page reads its own rows, never every row before it; the
     * Codex review of 92efa82, c2); or the orders with this number (any day). The lowest margin first leaves out the orders with no sale (no
     * margin).
     *
     * @param array{0: string, 1: int, 2: string}|null $after the last row of the page before: its paid time (or margin), store, order ref
     * @return array{rows: list<array<string, mixed>>, total: ?int, next: ?array{0: string, 1: int, 2: string}}
     */
    public function orders(string $from, string $to, ?int $store, string $q, bool $lowest, ?array $after = null): array
    {
        $cols = 'o.channel_id, o.order_ref, o.order_no, o.status, o.paid_at, o.gateway, o.parent_ref, o.units, o.sale_ex, o.cost, o.resend_cost, o.resends, '
            . 'o.delivery_ex, o.margin_bp, o.units_no_cost, c.name AS store, '
            . '(SELECT COUNT(*) FROM sale_line l WHERE l.channel_id = o.channel_id AND l.order_ref = o.order_ref AND l.cost_source IS NULL AND l.removed_at IS NULL) AS pending';
        if ($q !== '') {
            $rows = [];
            foreach ($this->stores() as $s) {
                if ($store !== null && $s['id'] !== $store) {
                    continue;
                }
                foreach ($this->db->all("SELECT {$cols} FROM sale_order o JOIN channel c ON c.id = o.channel_id WHERE o.channel_id = ? AND o.order_ref = ?", [$s['id'], $q]) as $r) {
                    $rows[$r['channel_id'] . ':' . $r['order_ref']] = $r;
                }
            }
            foreach ($this->db->all("SELECT {$cols} FROM sale_order o JOIN channel c ON c.id = o.channel_id WHERE o.order_no = ?" . ($store === null ? '' : ' AND o.channel_id = ?')
                . ' LIMIT 50', [$q, ...($store === null ? [] : [$store])]) as $r) {
                $rows[$r['channel_id'] . ':' . $r['order_ref']] = $r;
            }
            return ['rows' => array_map(self::orderRow(...), array_values($rows)), 'total' => count($rows), 'next' => null];
        }
        $lo = \CW\Clock::db(ReportRefresh::bounds($from)[0]);
        $hi = \CW\Clock::db(ReportRefresh::bounds($to)[1]);
        $where = ['o.paid_at >= ?', 'o.paid_at < ?'];
        $params = [$lo, $hi];
        if ($store !== null) {
            $where[] = 'o.channel_id = ?';
            $params[] = $store;
        }
        // Counted on the first page only (the next pages carry no count: the Codex review of 57d98ad, c2).
        $total = $after === null ? (int) $this->db->value('SELECT COUNT(*) FROM sale_order o WHERE ' . implode(' AND ', $where), $params) : null;
        $hint = '';
        if ($lowest) {
            // The period's orders sorted (its paid-time index bounds them; the period is at most LOWEST_MAX_DAYS), never the whole history in
            // margin order.
            $hint = ' USE INDEX (ix_sale_order_paid, ix_sale_order_store_paid)';
            $where[] = 'o.margin_bp IS NOT NULL';
            if ($after !== null) {
                $where[] = '(o.margin_bp, o.channel_id, o.order_ref) > (?, ?, ?)';
                array_push($params, (int) $after[0], $after[1], $after[2]);
            }
            $order = 'o.margin_bp, o.channel_id, o.order_ref';
        } else {
            if ($after !== null) {
                $where[] = '(o.paid_at, o.channel_id, o.order_ref) < (?, ?, ?)';
                array_push($params, $after[0], $after[1], $after[2]);
            }
            $order = 'o.paid_at DESC, o.channel_id DESC, o.order_ref DESC';
        }
        $rows = $this->db->all("SELECT {$cols} FROM sale_order o{$hint} JOIN channel c ON c.id = o.channel_id WHERE " . implode(' AND ', $where) . " ORDER BY {$order} LIMIT "
            . (self::PAGE + 1), $params);
        $next = null;
        if (count($rows) > self::PAGE) {
            $rows = array_slice($rows, 0, self::PAGE);
            $last = $rows[self::PAGE - 1];
            $next = [$lowest ? (string) $last['margin_bp'] : (string) $last['paid_at'], (int) $last['channel_id'], (string) $last['order_ref']];
        }
        return ['rows' => array_map(self::orderRow(...), $rows), 'total' => $total, 'next' => $next];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function orderRow(array $r): array
    {
        $sale = (int) $r['sale_ex'];
        $cost = (int) $r['cost'] + (int) $r['resend_cost'];
        return ['store_id' => (int) $r['channel_id'], 'store' => (string) $r['store'], 'ref' => (string) $r['order_ref'], 'no' => (string) $r['order_no'],
            'status' => (string) $r['status'], 'paid_at' => $r['paid_at'] === null ? null : (string) $r['paid_at'], 'gateway' => (string) $r['gateway'],
            'parent' => $r['parent_ref'] === null ? null : (string) $r['parent_ref'], 'units' => (int) $r['units'], 'sale' => $sale, 'cost' => $cost,
            'resends' => (int) $r['resends'], 'profit' => $sale - $cost, 'margin_bp' => $r['margin_bp'] === null ? null : (int) $r['margin_bp'],
            'delivery' => (int) $r['delivery_ex'], 'no_cost' => (int) $r['units_no_cost'], 'pending' => (int) ($r['pending'] ?? 0),
            // No margin on an order that sold something: some unit (its own or a re-send's) has no price or waits for it.
            'incomplete' => (int) $r['units_no_cost'] > 0 || (int) ($r['pending'] ?? 0) > 0 || ($r['margin_bp'] === null && $sale > 0)];
    }

    /**
     * One order: its money, every line, its parcels, its re-sends and the order it is a re-send (or a sub order) of.
     *
     * @return array<string, mixed>|null
     */
    public function order(int $store, string $ref): ?array
    {
        $o = $this->db->one('SELECT o.*, c.name AS store FROM sale_order o JOIN channel c ON c.id = o.channel_id WHERE o.channel_id = ? AND o.order_ref = ?', [$store, $ref]);
        if ($o === null) {
            return null;
        }
        $lines = array_map(static function (array $l): array {
            $sale = (int) $l['sale_ex'];
            $cost = (int) $l['cost'];
            return $l + ['profit' => $sale - $cost, 'margin_bp' => ProfitMath::marginBp($sale - $cost, $sale)];
        }, $this->db->all('SELECT l.*, s.code AS sku_code, s.name AS sku_name FROM sale_line l LEFT JOIN sku s ON s.id = l.sku_id '
            . 'WHERE l.channel_id = ? AND l.order_ref = ? ORDER BY l.removed_at IS NOT NULL, l.line_id', [$store, $ref]));
        return [
            'order' => $o,
            'lines' => $lines,
            'parcels' => $this->db->all('SELECT * FROM sale_parcel WHERE channel_id = ? AND order_ref = ? ORDER BY removed_at IS NOT NULL, label_at, parcel_ref', [$store, $ref]),
            'resends' => $this->db->all('SELECT order_ref, order_no, paid_at, cost, units FROM sale_order WHERE channel_id = ? AND parent_ref = ? ORDER BY paid_at, order_ref',
                [$store, $ref]),
        ];
    }

    /**
     * The items sold with no buying price on these days: their units and what those units sold for (indexed by day and cost source).
     *
     * @return list<array{sku_id: ?int, code: ?string, name: string, variant: ?string, units: int, sale: int, lines: int}>
     */
    public function noCost(string $from, string $to, ?int $store): array
    {
        // A website product with no CW item is its store's own (two stores' variant 123 are not the same product: the Codex review of 92efa82,
        // c5); a CW item is one row whatever store sold it.
        $rows = $this->db->all("SELECT l.sku_id, IF(l.sku_id IS NULL, l.variant_id, NULL) AS variant, IF(l.sku_id IS NULL, l.channel_id, NULL) AS ch, s.code, s.name, "
            . "SUM(l.units) AS units, SUM(l.sale_ex) AS sale, COUNT(*) AS n FROM sale_line l LEFT JOIN sku s ON s.id = l.sku_id "
            . "WHERE l.cost_source = 'none' AND l.day BETWEEN ? AND ? AND l.units > 0 AND l.restocked = 0 AND l.removed_at IS NULL" . ($store === null ? '' : ' AND l.channel_id = ?')
            . ' GROUP BY l.sku_id, variant, ch, s.code, s.name ORDER BY units DESC, l.sku_id, ch, variant LIMIT ' . self::ROW_LIMIT, [$from, $to, ...($store === null ? [] : [$store])]);
        $names = array_column($this->stores(), 'name', 'id');
        return array_map(static fn (array $r): array => ['sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'], 'code' => $r['code'] === null ? null : (string) $r['code'],
            'name' => (string) ($r['name'] ?? ''), 'variant' => $r['variant'] === null ? null : (string) $r['variant'],
            'store' => $r['ch'] === null ? null : (string) ($names[(int) $r['ch']] ?? ''), 'units' => (int) $r['units'], 'sale' => (int) $r['sale'], 'lines' => (int) $r['n']], $rows);
    }

    /** The refunds the shops kept at order level in these days (ex VAT): on no product, so the lists by product, brand and supplier leave them out. */
    public function orderRefunds(string $from, string $to, ?int $store): int
    {
        return (int) $this->db->value('SELECT COALESCE(SUM(order_refund_ex), 0) FROM profit_day WHERE day BETWEEN ? AND ?' . ($store === null ? '' : ' AND channel_id = ?'),
            [$from, $to, ...($store === null ? [] : [$store])]);
    }

    /**
     * Profit, margin and goods sold of summed figures.
     *
     * @param array<string, mixed> $r
     * @return array<string, int|null>
     */
    public static function figures(array $r): array
    {
        $goods = (int) ($r['sale_ex'] ?? 0) - (int) ($r['order_refund_ex'] ?? 0);
        $cost = (int) ($r['cost'] ?? 0);
        return ['orders' => (int) ($r['orders'] ?? 0), 'resends' => (int) ($r['resends'] ?? 0), 'units' => (int) ($r['units'] ?? 0), 'goods' => $goods, 'cost' => $cost,
            'profit' => $goods - $cost, 'margin_bp' => ProfitMath::marginBp($goods - $cost, $goods), 'delivery' => (int) ($r['delivery_ex'] ?? 0),
            'no_cost' => (int) ($r['units_no_cost'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0),
            'incomplete' => (int) ($r['units_no_cost'] ?? 0) > 0 || (int) ($r['pending'] ?? 0) > 0];
    }

    /** Pence as a decimal amount of pounds ("-12.05"), for the money helper. */
    public static function gbp(int $pence): string
    {
        return ($pence < 0 ? '-' : '') . intdiv(abs($pence), 100) . '.' . str_pad((string) (abs($pence) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A unit price (GBP x 10,000) as "£1.0500". */
    public static function price(int $e4): string
    {
        return ($e4 < 0 ? '-' : '') . '£' . intdiv(abs($e4), 10000) . '.' . str_pad((string) (abs($e4) % 10000), 4, '0', STR_PAD_LEFT);
    }

    /** A margin in basis points as a percent with one decimal ("33.9%"), '-' when there is none. */
    public static function pct(?int $bp): string
    {
        return $bp === null ? '-' : number_format($bp / 100, 1) . '%';
    }

    /** Today's UK day. */
    public static function today(): string
    {
        return ReportRefresh::ukDay(\CW\Clock::db(\CW\Clock::now()));
    }
}
