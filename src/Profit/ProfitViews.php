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
    /** Summed figures are "incomplete" when more than 1 unit in this many has no known cost (0.5%; PR85). */
    public const INCOMPLETE_PER = 200;
    private const SUMS = 'COALESCE(SUM(orders), 0) AS orders, COALESCE(SUM(resends), 0) AS resends, COALESCE(SUM(units), 0) AS units, '
        . 'COALESCE(SUM(sale_ex), 0) AS sale_ex, COALESCE(SUM(order_refund_ex), 0) AS order_refund_ex, COALESCE(SUM(cost), 0) AS cost, '
        . 'COALESCE(SUM(units_no_cost), 0) AS units_no_cost, COALESCE(SUM(delivery_ex), 0) AS delivery_ex, '
        // 0077 (docs/decisions.md DL11): what the carriers' bills say delivery cost, on the orders and not on an order.
        . 'COALESCE(SUM(delivery_cost), 0) AS delivery_cost, COALESCE(SUM(delivery_other), 0) AS delivery_other, '
        // 0080 (docs/decisions.md PPF7): PayPal's money on the orders and not on an order.
        . 'COALESCE(SUM(fee_cost), 0) AS fee_cost, COALESCE(SUM(fee_other), 0) AS fee_other, '
        // 0084 (docs/decisions.md GPF11): Global Payments' card fees on the orders (and the part still estimated) and on no order.
        . 'COALESCE(SUM(gp_fee), 0) AS gp_fee, COALESCE(SUM(gp_fee_est), 0) AS gp_fee_est, COALESCE(SUM(gp_other), 0) AS gp_other, '
        // CW-1153 (0083, docs/decisions.md VB11): the selling shop's own goods inside those figures (figures() takes them off: ours = whole - own)
        . 'COALESCE(SUM(own_units), 0) AS own_units, COALESCE(SUM(own_sale_ex), 0) AS own_sale_ex, COALESCE(SUM(own_refund_ex), 0) AS own_refund_ex, '
        . 'COALESCE(SUM(own_cost), 0) AS own_cost, COALESCE(SUM(own_no_cost), 0) AS own_no_cost';
    private const ITEM_SUMS = 'COALESCE(SUM(p.units), 0) AS units, COALESCE(SUM(p.sale_ex), 0) AS sale_ex, COALESCE(SUM(p.cost), 0) AS cost, '
        . 'COALESCE(SUM(p.units_no_cost), 0) AS units_no_cost, COALESCE(SUM(p.own_units), 0) AS own_units, COALESCE(SUM(p.own_sale_ex), 0) AS own_sale_ex, '
        . 'COALESCE(SUM(p.own_cost), 0) AS own_cost, COALESCE(SUM(p.own_no_cost), 0) AS own_no_cost';

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
        // Every period's figures and waiting units from ONE moment of the database (a cost fixed between two reads never shows a complete
        // margin with its cost missing: the final review of 6d59eda, c-M9, PR103).
        return ProfitDays::snapshot($this->db, function () use ($periods, $store): array {
            $out = [];
            foreach ($periods as $k => [$from, $to]) {
                $out[] = ['key' => $k, 'from' => $from, 'to' => $to, 'f' => $this->totals($from, $to, $store)];
            }
            return $out;
        });
    }

    /** @return array<string, int|null> the figures of the days $from..$to (one store, or all), with the units still waiting for their cost */
    public function totals(string $from, string $to, ?int $store): array
    {
        return ProfitDays::snapshot($this->db, fn (): array => $this->totalsNow($from, $to, $store));
    }

    /** @return array<string, int|null> totals() inside the caller's snapshot */
    private function totalsNow(string $from, string $to, ?int $store): array
    {
        $r = $this->db->one('SELECT ' . self::SUMS . ' FROM profit_day WHERE day BETWEEN ? AND ?' . ($store === null ? '' : ' AND channel_id = ?'),
            [$from, $to, ...($store === null ? [] : [$store])]) ?? [];
        return self::figures($r + ['pending' => array_sum($this->pending($from, $to, $store)), 'own_pending' => array_sum($this->pending($from, $to, $store, true))]);
    }

    /**
     * The units of paid orders whose cost waits for the cost job (a few minutes at most), by day (index (cost_source, day)): until then their
     * cost is 0 in every figure, which the screens say (the Codex review of 92efa82, c6).
     *
     * @return array<string, int> day => units
     */
    public function pending(string $from, string $to, ?int $store, bool $own = false): array
    {
        $out = [];
        // CW-1153 (VB11): ours, or ($own) the selling shop's own goods' (the line's owner is fixed at payment, before its cost)
        foreach ($this->db->all('SELECT day, SUM(units) AS u FROM sale_line WHERE cost_source IS NULL AND day BETWEEN ? AND ? AND removed_at IS NULL AND restocked = 0'
            . ' AND item_owner IS ' . ($own ? 'NOT ' : '') . 'NULL'
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
        return ProfitDays::snapshot($this->db, fn (): array => $this->periodsNow($from, $to, $store, $by));
    }

    /** @return list<array{from: string, to: string, f: array<string, int|null>}> periods() inside the caller's snapshot */
    private function periodsNow(string $from, string $to, ?int $store, string $by): array
    {
        $groups = [];
        $pending = $this->pending($from, $to, $store);
        $ownPending = $this->pending($from, $to, $store, true);
        foreach ($this->db->all('SELECT day, ' . self::SUMS . ' FROM profit_day WHERE day BETWEEN ? AND ?' . ($store === null ? '' : ' AND channel_id = ?')
            . ' GROUP BY day ORDER BY day', [$from, $to, ...($store === null ? [] : [$store])]) as $r) {
            $r['pending'] = $pending[(string) $r['day']] ?? 0;
            $r['own_pending'] = $ownPending[(string) $r['day']] ?? 0;
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
        // CW-1153 (VB11): OUR figures: the selling shop's own goods taken off every one, in SQL, so the sort and the cut at ROW_LIMIT are on ours
        $goods = $by === 'shop' ? '(SUM(p.sale_ex) - SUM(p.own_sale_ex) - SUM(p.order_refund_ex) + SUM(p.own_refund_ex))' : '(SUM(p.sale_ex) - SUM(p.own_sale_ex))';
        $cost = '(SUM(p.cost) - SUM(p.own_cost))';
        $unitsOurs = '(SUM(p.units) - SUM(p.own_units))';
        $margin = "IF({$goods} > 0, ({$goods} - {$cost}) / {$goods}, NULL)";
        // Units waiting for their cost (minutes at most), per row, joined in SQL: their rows are incomplete too, and a list by margin puts every
        // incomplete row after the rows whose margin is known BEFORE the list is cut at ROW_LIMIT (the Codex re-checks of 37a2cf0 and 6b8a7ee,
        // c6); a brand's waiting units are matched by the database's own comparison, the one its rows are grouped by (case does not matter).
        $pending = 'COALESCE(MAX(w.u), 0)';
        // Incomplete as figures() says it (PR85): more than 0.5% of the row's units without a known cost.
        $incomplete = '((SUM(p.units_no_cost) - SUM(p.own_no_cost) + ' . $pending . ') * ' . self::INCOMPLETE_PER . " > {$unitsOurs})";
        $order = match ($sort) {
            'sale' => "{$goods} DESC",
            'cost' => "{$cost} DESC",
            'units' => "{$unitsOurs} DESC",
            'margin' => "{$incomplete}, {$margin} IS NULL, {$margin} DESC",
            'margin_low' => "{$incomplete}, {$margin} IS NULL, {$margin} ASC",
            default => "{$goods} - {$cost} DESC",
        };
        // A product, brand or supplier row with nothing of ours (only the shop's own goods) is not a row of our lists; a row that holds no own
        // goods is shown as before, whatever its figures (the final review, Fable L12).
        $having = $by === 'shop' ? '' : " HAVING {$unitsOurs} <> 0 OR {$goods} <> 0 OR {$cost} <> 0 OR (SUM(p.own_units) = 0 AND SUM(p.own_sale_ex) = 0 AND SUM(p.own_cost) = 0)";
        $where = 'p.day BETWEEN ? AND ?' . ($store === null ? '' : ' AND p.channel_id = ?');
        $params = [$from, $to, ...($store === null ? [] : [$store])];
        $lw = 'l.cost_source IS NULL AND l.removed_at IS NULL AND l.restocked = 0 AND l.item_owner IS NULL AND l.day BETWEEN ? AND ?' . ($store === null ? '' : ' AND l.channel_id = ?');
        $waiting = static fn (string $key, string $from, string $on): string => "LEFT JOIN (SELECT {$key} AS k, SUM(l.units) AS u FROM {$from} WHERE {$lw} GROUP BY k) w ON {$on}";
        $limit = self::ROW_LIMIT + 1;
        $rows = match ($by) {
            'shop' => $this->db->all('SELECT p.channel_id AS id, c.name AS label, NULL AS code, ' . self::SUMS . ", {$pending} AS pending FROM profit_day p "
                . 'JOIN channel c ON c.id = p.channel_id ' . $waiting('l.channel_id', 'sale_line l', 'w.k = p.channel_id')
                . " WHERE {$where} GROUP BY p.channel_id, c.name ORDER BY {$order}, p.channel_id LIMIT {$limit}", [...$params, ...$params]),
            'brand' => $this->db->all('SELECT COALESCE(NULLIF(s.brand, \'\'), \'\') AS label, NULL AS id, NULL AS code, ' . self::ITEM_SUMS . ", {$pending} AS pending "
                . 'FROM profit_item_day p LEFT JOIN sku s ON s.id = p.sku_id '
                . $waiting("COALESCE(NULLIF(s.brand, ''), '')", 'sale_line l LEFT JOIN sku s ON s.id = l.sku_id', "w.k = COALESCE(NULLIF(s.brand, ''), '')")
                . " WHERE {$where} GROUP BY label{$having} ORDER BY {$order}, label LIMIT {$limit}", [...$params, ...$params]),
            'supplier' => $this->db->all('SELECT si.supplier_id AS id, COALESCE(sp.name, \'\') AS label, NULL AS code, ' . self::ITEM_SUMS . ", {$pending} AS pending "
                . 'FROM profit_item_day p LEFT JOIN supplier_item si ON si.preferred_sku_id = p.sku_id LEFT JOIN supplier sp ON sp.id = si.supplier_id '
                . $waiting('si.supplier_id', 'sale_line l LEFT JOIN supplier_item si ON si.preferred_sku_id = l.sku_id', 'w.k <=> si.supplier_id')
                . " WHERE {$where} GROUP BY si.supplier_id, sp.name{$having} ORDER BY {$order}, label LIMIT {$limit}", [...$params, ...$params]),
            default => $this->db->all('SELECT p.sku_id AS id, COALESCE(s.name, \'\') AS label, s.code, ' . self::ITEM_SUMS . ", {$pending} AS pending FROM profit_item_day p "
                . 'LEFT JOIN sku s ON s.id = p.sku_id ' . $waiting('COALESCE(l.sku_id, 0)', 'sale_line l', 'w.k = p.sku_id')
                . " WHERE {$where} GROUP BY p.sku_id, s.name, s.code{$having} ORDER BY {$order}, p.sku_id LIMIT {$limit}", [...$params, ...$params]),
        };
        $more = count($rows) > self::ROW_LIMIT;
        $out = [];
        foreach (array_slice($rows, 0, self::ROW_LIMIT) as $r) {
            $out[] = ['id' => $r['id'] === null ? null : (int) $r['id'], 'label' => (string) $r['label'], 'code' => $r['code'] === null ? null : (string) $r['code'],
                'f' => self::figures($r)];
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
        // Its own units with no price or waiting for it, and its paid re-sends' (by ix_sale_order_parent), counted on each row: a profit that is
        // not known is never printed as a number (the final review of 6d59eda, c-M10, PR102).
        $cols = 'o.channel_id, o.order_ref, o.order_no, o.status, o.paid_at, o.gateway, o.parent_ref, o.units, o.sale_ex, o.cost, o.resend_cost, o.resends, '
            . 'o.delivery_ex, o.margin_bp, o.units_no_cost, c.name AS store, '
            // CW-1153 (VB11): the selling shop's own goods of the order (each row shows ours and them apart)
            . 'o.own_units, o.own_sale_ex, o.own_refund_ex, o.own_cost, o.own_no_cost, '
            . '(SELECT COUNT(*) FROM sale_line l WHERE l.channel_id = o.channel_id AND l.order_ref = o.order_ref AND l.cost_source IS NULL AND l.removed_at IS NULL '
            . 'AND l.units > 0 AND l.restocked = 0 AND l.item_owner IS NULL) AS pending, '
            . '(SELECT COUNT(*) FROM sale_line l WHERE l.channel_id = o.channel_id AND l.order_ref = o.order_ref AND l.cost_source IS NULL AND l.removed_at IS NULL '
            . 'AND l.units > 0 AND l.restocked = 0 AND l.item_owner IS NOT NULL) AS own_pending';
        // Its re-sends' units with no price or waiting for one are those of its whole chain (a replacement of a replacement too: the second
        // final review of 3c31d94, a-M9, c-M6, PR143), read for the rows that have re-sends (a few on a page).
        $chain = function (array $rows): array {
            foreach ($rows as $i => $r) {
                $rows[$i]['rs'] = (int) $r['resends'] > 0 ? OrderSnapshots::resendFigures($this->db, (int) $r['channel_id'], (string) $r['order_ref']) : self::NO_RESENDS;
            }
            return $rows;
        };
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
            return ['rows' => array_map(self::orderRow(...), $chain(array_values($rows))), 'total' => count($rows), 'next' => null];
        }
        $lo = \CW\Clock::db(ReportRefresh::bounds($from)[0]);
        $hi = \CW\Clock::db(ReportRefresh::bounds($to)[1]);
        $where = ['o.paid_at >= ?', 'o.paid_at < ?'];
        $params = [$lo, $hi];
        if ($store !== null) {
            $where[] = 'o.channel_id = ?';
            $params[] = $store;
        }
        if ($lowest) {
            return $this->lowestMargin($cols, $where, $params, $after, $chain);
        }
        // Counted on the first page only (the next pages carry no count: the Codex review of 57d98ad, c2).
        $total = $after === null ? (int) $this->db->value('SELECT COUNT(*) FROM sale_order o WHERE ' . implode(' AND ', $where), $params) : null;
        $hint = '';
        if ($after !== null) {
            $where[] = '(o.paid_at, o.channel_id, o.order_ref) < (?, ?, ?)';
            array_push($params, $after[0], $after[1], $after[2]);
        }
        $order = 'o.paid_at DESC, o.channel_id DESC, o.order_ref DESC';
        $rows = $this->db->all("SELECT {$cols} FROM sale_order o{$hint} JOIN channel c ON c.id = o.channel_id WHERE " . implode(' AND ', $where) . " ORDER BY {$order} LIMIT "
            . (self::PAGE + 1), $params);
        $next = null;
        if (count($rows) > self::PAGE) {
            $rows = array_slice($rows, 0, self::PAGE);
            $last = $rows[self::PAGE - 1];
            $next = [(string) $last['paid_at'], (int) $last['channel_id'], (string) $last['order_ref']];
        }
        return ['rows' => array_map(self::orderRow(...), $chain($rows)), 'total' => $total, 'next' => $next];
    }

    /**
     * "The lowest margin first" (CW-1153, the last Codex check of a46cc3d, M12): the orders listed, counted, ordered and paged by the margin each
     * row SHOWS (orderRow(): ours when the order holds any of the selling shop's own goods, else the stored one, which is the same), never by the
     * whole order's: an order with no margin of ours (nothing but the shop's own goods, a unit of ours with no price) is left out, an order whose
     * own line has no price is in by ours. An order with no re-sends: that margin worked out in SQL from its own row (ours = the whole less the
     * shop's own part, rounded as ProfitMath::marginBp), keyset-paged on the period's paid-time index (the period is at most LOWEST_MAX_DAYS);
     * an order with re-sends (a few): by orderRow() itself from its chain; the two merged in (margin, store, ref) order.
     *
     * @param list<string> $where
     * @param list<mixed> $params
     * @param array{0: string, 1: int, 2: string}|null $after
     * @return array{rows: list<array<string, mixed>>, total: ?int, next: ?array{0: string, 1: int, 2: string}}
     */
    private function lowestMargin(string $cols, array $where, array $params, ?array $after, \Closure $chain): array
    {
        $ownGoods = '(o.own_sale_ex - o.own_refund_ex)';
        $any = "(o.own_units <> 0 OR {$ownGoods} <> 0 OR o.own_cost <> 0 OR o.own_no_cost <> 0)";
        $goods = "(o.sale_ex - {$ownGoods})";
        $profit = "({$goods} - (o.cost - o.own_cost) - o.resend_cost)";
        $waiting = '(SELECT COUNT(*) FROM sale_line l WHERE l.channel_id = o.channel_id AND l.order_ref = o.order_ref AND l.cost_source IS NULL AND l.removed_at IS NULL '
            . 'AND l.units > 0 AND l.restocked = 0 AND l.item_owner IS NULL)';
        $shown = "CASE WHEN NOT {$any} THEN o.margin_bp WHEN o.units_no_cost - o.own_no_cost > 0 OR {$goods} <= 0 OR {$waiting} > 0 THEN NULL "
            . "ELSE SIGN({$profit}) * ((ABS({$profit}) * 20000 + {$goods}) DIV (2 * {$goods})) END";
        $hint = ' USE INDEX (ix_sale_order_paid, ix_sale_order_store_paid)';
        $plain = implode(' AND ', [...$where, 'o.resends = 0']);
        $total = $after === null ? (int) $this->db->value("SELECT COUNT(*) FROM (SELECT {$shown} AS shown_bp FROM sale_order o{$hint} WHERE {$plain}) t WHERE t.shown_bp IS NOT NULL",
            $params) : null;
        $having = 'shown_bp IS NOT NULL' . ($after === null ? '' : ' AND (shown_bp, channel_id, order_ref) > (?, ?, ?)');
        $keys = $this->db->all("SELECT o.channel_id, o.order_ref, {$shown} AS shown_bp FROM sale_order o{$hint} WHERE {$plain} HAVING {$having} "
            . 'ORDER BY shown_bp, o.channel_id, o.order_ref LIMIT ' . (self::PAGE + 1), [...$params, ...($after === null ? [] : [(int) $after[0], (int) $after[1], $after[2]])]);
        $plainRows = [];
        if ($keys !== []) {
            $pairs = implode(', ', array_fill(0, count($keys), '(?, ?)'));
            foreach ($this->db->all("SELECT {$cols} FROM sale_order o JOIN channel c ON c.id = o.channel_id WHERE (o.channel_id, o.order_ref) IN ({$pairs})",
                array_merge(...array_map(static fn (array $k): array => [(int) $k['channel_id'], (string) $k['order_ref']], $keys))) as $r) {
                $plainRows[$r['channel_id'] . ':' . strtolower((string) $r['order_ref'])] = $r;
            }
        }
        // the orders with re-sends: their shown margin is orderRow()'s, read from their chain
        $cmp = static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]] ?: strcasecmp($a[2], $b[2]);
        $resent = [];
        foreach (array_map(self::orderRow(...), $chain($this->db->all("SELECT {$cols} FROM sale_order o{$hint} JOIN channel c ON c.id = o.channel_id WHERE "
            . implode(' AND ', [...$where, 'o.resends > 0']), $params))) as $row) {
            if ($row['margin_bp'] === null || ($after !== null && $cmp([$row['margin_bp'], $row['store_id'], $row['ref']], [(int) $after[0], (int) $after[1], $after[2]]) <= 0)) {
                continue;
            }
            $resent[] = [$row['margin_bp'], $row['store_id'], $row['ref'], $row];
        }
        if ($total !== null) {
            $total += count($resent);
        }
        $all = [];
        foreach ($keys as $k) {
            $r = $plainRows[$k['channel_id'] . ':' . strtolower((string) $k['order_ref'])] ?? null;
            if ($r !== null) {
                $all[] = [(int) $k['shown_bp'], (int) $k['channel_id'], (string) $k['order_ref'], $r];
            }
        }
        $all = [...$all, ...$resent];
        usort($all, $cmp);
        $all = array_slice($all, 0, self::PAGE + 1);
        $next = null;
        if (count($all) > self::PAGE) {
            $all = array_slice($all, 0, self::PAGE);
            $last = $all[self::PAGE - 1];
            $next = [(string) $last[0], $last[1], $last[2]];
        }
        $plainOut = array_map(self::orderRow(...), $chain(array_values(array_filter(array_column($all, 3), static fn (array $r): bool => !isset($r['store_id'])))));
        $rows = [];
        $i = 0;
        foreach ($all as $x) {
            $rows[] = isset($x[3]['store_id']) ? $x[3] : $plainOut[$i++];
        }
        return ['rows' => $rows, 'total' => $total, 'next' => $next];
    }

    /**
     * One row of Profit by order. Its goods, cost and profit are the order's OWN (what the day summaries count: a re-send's cost is counted on
     * the re-send's own row and day, so the rows of a period add up to its day summary exactly; the final review of 6d59eda, c-HIGH3, PR102);
     * the cost of its paid re-sends and its profit with them are figures of their own (resend_cost, with_resends), and its margin is the
     * agreed one, with its re-sends (PR10). A profit is null ("not known") when a unit it counts has no price or waits for one (c-M10).
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function orderRow(array $r): array
    {
        // CW-1153 (VB11; the Codex review of bd9104d, M7 and M8): the row's goods, cost, units, units with no price, re-send cost and profits are
        // OURS (split(): the selling shop's own goods, and its own goods re-sent, taken off: they are on the row's own line); its margin is ours
        // too when it has any of them (else the stored one, the same).
        $sp = self::split($r, $r['rs'] ?? self::NO_RESENDS, (int) ($r['pending'] ?? 0), (int) ($r['own_pending'] ?? 0));
        $ours = $sp['ours'];
        $own = $sp['own'];
        $unpaid = $r['paid_at'] === null;
        $margin = $r['margin_bp'];
        if ($sp['any_own']) {
            // ours from our goods whenever ours is known (the final review of 2d13f32, M18): the shop's own line waiting for a price (which
            // empties the stored whole-order margin) never hides it
            $margin = $ours['unknown'] || $ours['resend_unknown'] ? null : ProfitMath::marginBp($ours['goods'] - $ours['cost'] - $ours['resend_cost'], $ours['goods']);
        }
        return ['store_id' => (int) $r['channel_id'], 'store' => (string) $r['store'], 'ref' => (string) $r['order_ref'], 'no' => (string) $r['order_no'],
            'status' => (string) $r['status'], 'paid_at' => $r['paid_at'] === null ? null : (string) $r['paid_at'], 'gateway' => (string) $r['gateway'],
            'parent' => $r['parent_ref'] === null ? null : (string) $r['parent_ref'], 'units' => $ours['units'], 'sale' => $ours['goods'], 'cost' => $ours['cost'],
            'resends' => (int) $r['resends'], 'resend_cost' => $ours['resend_cost'], 'profit' => $ours['profit'], 'with_resends' => $ours['with_resends'],
            // An order not paid (any more) shows no profit and no margin, whatever its rows still hold (the Codex check of the fix round, F18 PARTLY,
            // PR152: a sale paid with its cost fixed and then unpaid showed £7, 70%).
            'margin_bp' => $unpaid || $margin === null ? null : (int) $margin, 'unpaid' => $unpaid,
            'delivery' => (int) $r['delivery_ex'], 'no_cost' => $ours['no_cost'], 'pending' => $ours['pending'],
            'own_unknown' => $ours['unknown'], 'resend_unknown' => $ours['resend_unknown'],
            // Incomplete: some unit of ours it counts, its own or a paid re-send's, has no price or waits for it.
            'incomplete' => $ours['unknown'] || $ours['resend_unknown'],
            'shop_own' => $sp['any_own'] ? $own : null,
            'shop_own_resend_cost' => $own['resend_cost']];
    }

    /** No re-sends (resendFigures()' shape). */
    public const NO_RESENDS = ['n' => 0, 'c' => 0, 'nc' => 0, 'open' => 0, 'c_own' => 0, 'nc_own' => 0, 'open_own' => 0];

    /**
     * CW-1153 (the Codex review of bd9104d, M7 and M8): ONE order's money split into OURS and the selling shop's OWN goods: the one calculation
     * both order readers use (Profit by order's rows and one order's page). Each side: units, goods sold (its lines' sale ex VAT less its share
     * of the order-level refund), that refund share, buying cost, units with no price, units waiting for a cost, profit (null: not known: not
     * paid, or a unit of that side with no price or waiting for one), its paid re-sends' cost by whose goods were re-sent (a re-send of the
     * shop's own goods never costs us: resendFigures()' c_own) and its profit with them (null while a re-sent unit of that side is not known).
     * Every penny once: ours = the whole less the shop's own.
     *
     * @param array<string, mixed> $o a sale_order row (sale_ex, order_refund_ex, cost, units, units_no_cost, own_*, resends, resend_cost, paid_at)
     * @param array<string, int|string> $rs its paid re-sends (OrderSnapshots::resendFigures(), or NO_RESENDS)
     * @param int $pending its units of ours waiting for a cost (lines), $ownPending the shop's own
     * @return array{ours: array<string, mixed>, own: array<string, mixed>, any_own: bool}
     */
    public static function split(array $o, array $rs, int $pending, int $ownPending): array
    {
        $unpaid = $o['paid_at'] === null;
        $resends = (int) ($o['resends'] ?? 0);
        $wholeRefund = (int) ($o['order_refund_ex'] ?? 0);
        $ownRefund = (int) ($o['own_refund_ex'] ?? 0);
        $ownGoods = (int) ($o['own_sale_ex'] ?? 0) - $ownRefund;
        $ownResend = (int) ($rs['c_own'] ?? 0);
        $side = static function (int $units, int $goods, int $refund, int $cost, int $noCost, int $pend, int $resendCost, int $resendNc, int $resendOpen)
            use ($unpaid, $resends): array {
            $unknown = $unpaid || $noCost > 0 || $pend > 0;
            $resendUnknown = $resendNc > 0 || $resendOpen > 0;
            return ['units' => $units, 'goods' => $goods, 'refund' => $refund, 'cost' => $cost, 'no_cost' => $noCost, 'pending' => $pend,
                'profit' => $unknown ? null : $goods - $cost, 'resend_cost' => $resendCost,
                'with_resends' => $resends === 0 ? null : ($unknown || $resendUnknown ? null : $goods - $cost - $resendCost),
                'unknown' => $unknown, 'resend_unknown' => $resendUnknown];
        };
        $own = $side((int) ($o['own_units'] ?? 0), $ownGoods, $ownRefund, (int) ($o['own_cost'] ?? 0), (int) ($o['own_no_cost'] ?? 0), $ownPending, $ownResend,
            (int) ($rs['nc_own'] ?? 0), (int) ($rs['open_own'] ?? 0));
        $ours = $side((int) $o['units'] - $own['units'], (int) $o['sale_ex'] - $ownGoods, $wholeRefund - $ownRefund, (int) $o['cost'] - $own['cost'],
            (int) $o['units_no_cost'] - $own['no_cost'], $pending, (int) $o['resend_cost'] - $ownResend, (int) ($rs['nc'] ?? 0) - (int) ($rs['nc_own'] ?? 0),
            (int) ($rs['open'] ?? 0) - (int) ($rs['open_own'] ?? 0));
        $any = $own['units'] !== 0 || $own['goods'] !== 0 || $own['cost'] !== 0 || $own['no_cost'] !== 0 || $ownPending !== 0 || $ownResend !== 0
            || (int) ($rs['nc_own'] ?? 0) !== 0 || (int) ($rs['open_own'] ?? 0) !== 0;
        return ['ours' => $ours, 'own' => $own, 'any_own' => $any];
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
        // Its re-sends: every replacement of its chain when it is a first order (a replacement of a replacement too, PR143); a replacement's
        // own go on its first order.
        $chain = $o['parent_ref'] === null ? OrderSnapshots::descendants($this->db, $store, (string) $o['order_ref']) : [];
        $resends = $chain === [] ? [] : $this->db->all('SELECT o.order_ref, o.order_no, o.paid_at, o.cost, o.units, o.units_no_cost, (SELECT COUNT(*) FROM sale_line l '
            . 'WHERE l.channel_id = o.channel_id AND l.order_ref = o.order_ref AND l.cost_source IS NULL AND l.removed_at IS NULL AND l.units > 0 AND l.restocked = 0) AS pending '
            . 'FROM sale_order o WHERE o.channel_id = ? AND o.order_ref IN (' . implode(', ', array_fill(0, count($chain), '?')) . ') ORDER BY o.paid_at, o.order_ref',
            [$store, ...$chain]);
        // Whether its own units, and its paid re-sends' units, all have their buying price (the Codex re-check of e5d2e5a, S1): the profit is
        // "not known" exactly when they do not, whatever the order sold for.
        $own = $o['paid_at'] !== null && ((int) $o['units_no_cost'] > 0
            || array_filter($lines, static fn (array $l): bool => $l['cost_source'] === null && $l['removed_at'] === null && (int) $l['units'] > 0
                && (int) $l['restocked'] === 0) !== []);
        $theirs = array_filter($resends, static fn (array $r): bool => $r['paid_at'] !== null && ((int) $r['units_no_cost'] > 0 || (int) $r['pending'] > 0)) !== [];
        // CW-1153 (the Codex review of bd9104d, M7 and M8): ours and the selling shop's own goods apart, by the one calculation Profit by order's
        // rows use (split()): its lines waiting for a cost on each side, its chain's re-sends by whose goods were re-sent.
        $waiting = static fn (bool $own): int => count(array_filter($lines, static fn (array $l): bool => $l['cost_source'] === null && $l['removed_at'] === null
            && (int) $l['units'] > 0 && (int) $l['restocked'] === 0 && ($l['item_owner'] !== null) === $own));
        $split = self::split($o, $chain === [] ? self::NO_RESENDS : OrderSnapshots::resendFigures($this->db, $store, (string) $o['order_ref']), $waiting(false), $waiting(true));
        // A clone's price the shop has on it, as its page says it is not counted: its lines that count (a cancelled line, or one gone from the
        // order, is none of it: the second final review of 3c31d94, Fable L7, PR147).
        $cloneGoods = 0;
        foreach ($lines as $l) {
            if ((int) $l['cancelled'] === 0 && $l['removed_at'] === null) {
                $cloneGoods += (int) $l['price_inc'] - (int) $l['discount_inc'];
            }
        }
        $parcels = $this->db->all('SELECT * FROM sale_parcel WHERE channel_id = ? AND order_ref = ? ORDER BY removed_at IS NOT NULL, label_at, parcel_ref', [$store, $ref]);
        // Its delivery (0077, docs/decisions.md DL23): its parcels with their carrier bill lines ("not billed yet" without), the collections on it,
        // and its re-sends' bill lines when it is a first order (they belong to it, PR143).
        $delivery = DeliveryViews::order($this->db, $store, (string) $o['order_ref'], $parcels, $chain);
        return [
            'order' => $o,
            // its PayPal money (0080, docs/decisions.md PPF16): a sale's fee, a refund's fee back, a chargeback's money and fees
            'paypal' => PaypalFees::orderLines($this->db, $store, (string) $o['order_ref']),
            // its Global Payments card fee (0084, docs/decisions.md GPF11): estimated (base x rate) or its share of a month's invoice; null: none
            'gp' => self::gpLine($this->db, $store, (string) $o['order_ref']),
            'clone_goods_inc' => $cloneGoods,
            'lines' => self::grouped($lines),
            'parcels' => $delivery['parcels'],
            'delivery' => $delivery,
            'resends' => $resends,
            'own_incomplete' => $own,
            'resends_incomplete' => $theirs,
            'split' => $split,
        ];
    }

    /**
     * An order's lines as the page shows them (the lead's look at real data, PR84): one row per product (its CW item, else its website
     * number), cost of one unit, where the cost came from and state (cancelled, free, back in stock, refunded, no longer on the order), its
     * units, goods sold, cost and profit added up (so the order's totals stay what they are) and its margin from those sums.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public static function grouped(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $key = implode('|', [$l['sku_id'] === null ? 'v' . $l['variant_id'] : 's' . $l['sku_id'], $l['unit_cost_e4'] ?? '-', $l['cost_source'] ?? '-',
                (int) $l['cancelled'], (int) $l['is_free'], (int) $l['restocked'], (int) $l['refund_inc'] > 0 ? 1 : 0, $l['removed_at'] === null ? 0 : 1,
                $l['item_owner'] ?? '-']); // CW-1153 (VB11): the shop's own goods never grouped with ours
            if (!isset($out[$key])) {
                $out[$key] = $l + ['lines' => 0];
                $out[$key]['units'] = 0;
                $out[$key]['sale_ex'] = 0;
                $out[$key]['cost'] = 0;
            }
            $out[$key]['lines']++;
            $out[$key]['units'] += (int) $l['units'];
            $out[$key]['sale_ex'] += (int) $l['sale_ex'];
            $out[$key]['cost'] += (int) $l['cost'];
        }
        return array_values(array_map(static fn (array $g): array => ['profit' => $g['sale_ex'] - $g['cost'],
            'margin_bp' => ProfitMath::marginBp($g['sale_ex'] - $g['cost'], $g['sale_ex'])] + $g, $out));
    }

    /**
     * The items sold with no buying price on these days: their units and what those units sold for (indexed by day and cost source).
     *
     * @return list<array{sku_id: ?int, code: ?string, name: string, variant: ?string, units: int, sale: int, lines: int}>
     */
    public function noCost(string $from, string $to, ?int $store, bool $own = false): array
    {
        // A website product with no CW item is its store's own (two stores' variant 123 are not the same product: the Codex review of 92efa82,
        // c5); a CW item is one row whatever store sold it.
        $rows = $this->db->all("SELECT l.sku_id, IF(l.sku_id IS NULL, l.variant_id, NULL) AS variant, IF(l.sku_id IS NULL, l.channel_id, NULL) AS ch, s.code, s.name, "
            . "SUM(l.units) AS units, SUM(l.sale_ex) AS sale, COUNT(*) AS n FROM sale_line l LEFT JOIN sku s ON s.id = l.sku_id "
            . "WHERE l.cost_source = 'none' AND l.day BETWEEN ? AND ? AND l.units > 0 AND l.restocked = 0 AND l.removed_at IS NULL" . ($store === null ? '' : ' AND l.channel_id = ?')
            . ' AND l.item_owner IS ' . ($own ? 'NOT ' : '') . 'NULL' // CW-1153 (VB11): ours, or the selling shop's own goods' list
            . ' GROUP BY l.sku_id, variant, ch, s.code, s.name ORDER BY units DESC, l.sku_id, ch, variant LIMIT ' . self::ROW_LIMIT, [$from, $to, ...($store === null ? [] : [$store])]);
        $names = array_column($this->stores(), 'name', 'id');
        return array_map(static fn (array $r): array => ['sku_id' => $r['sku_id'] === null ? null : (int) $r['sku_id'], 'code' => $r['code'] === null ? null : (string) $r['code'],
            'name' => (string) ($r['name'] ?? ''), 'variant' => $r['variant'] === null ? null : (string) $r['variant'],
            'store' => $r['ch'] === null ? null : (string) ($names[(int) $r['ch']] ?? ''), 'units' => (int) $r['units'], 'sale' => (int) $r['sale'], 'lines' => (int) $r['n']], $rows);
    }

    /** The refunds the shops kept at order level in these days (ex VAT): on no product, so the lists by product, brand and supplier leave them out. */
    public function orderRefunds(string $from, string $to, ?int $store): int
    {
        return (int) $this->db->value('SELECT COALESCE(SUM(order_refund_ex) - SUM(own_refund_ex), 0) FROM profit_day WHERE day BETWEEN ? AND ?' . ($store === null ? '' : ' AND channel_id = ?'),
            [$from, $to, ...($store === null ? [] : [$store])]);
    }

    /**
     * An order's Global Payments card fee as its page shows it (0084, GPF11): what counts, whether estimated (at which rate, of which amount)
     * or its share of which month's invoice; null when it has none (not a card order of an account switched on, or off now).
     *
     * @return array{state: string, fee: int, base: int, rate: ?string, month: ?string}|null
     */
    public static function gpLine(Db $db, int $store, string $ref): ?array
    {
        $r = $db->one("SELECT g.state, g.fee_pence, g.base_pence, g.rate_e4, i.month FROM gp_fee_order g LEFT JOIN gp_invoice i ON i.id = g.invoice_id "
            . "WHERE g.channel_id = ? AND g.order_ref = ? AND g.state <> 'off'", [$store, $ref]);
        return $r === null ? null : ['state' => (string) $r['state'], 'fee' => (int) $r['fee_pence'], 'base' => (int) $r['base_pence'],
            'rate' => $r['rate_e4'] === null ? null : ProfitMath::ratePercent((int) $r['rate_e4']), 'month' => $r['month'] === null ? null : (string) $r['month']];
    }

    /**
     * Profit, margin and goods sold of summed figures.
     *
     * @param array<string, mixed> $r
     * @return array<string, int|null>
     */
    public static function figures(array $r): array
    {
        // CW-1153 (docs/decisions.md VB11): every figure here is OURS: the selling shop's own goods (own_*, inside the stored figures) are taken
        // off, and shown apart in `own` (goods, cost, profit, margin, units, no price, waiting). Orders, delivery and PayPal stay the shop's.
        $ownGoods = (int) ($r['own_sale_ex'] ?? 0) - (int) ($r['own_refund_ex'] ?? 0);
        $ownCost = (int) ($r['own_cost'] ?? 0);
        $ownUnits = (int) ($r['own_units'] ?? 0);
        $ownNoCost = (int) ($r['own_no_cost'] ?? 0);
        $ownPending = (int) ($r['own_pending'] ?? 0);
        $r['units'] = (int) ($r['units'] ?? 0) - $ownUnits;
        $r['units_no_cost'] = (int) ($r['units_no_cost'] ?? 0) - $ownNoCost;
        $goods = (int) ($r['sale_ex'] ?? 0) - (int) ($r['order_refund_ex'] ?? 0) - $ownGoods;
        $cost = (int) ($r['cost'] ?? 0) - $ownCost;
        $delivery = (int) ($r['delivery_ex'] ?? 0);
        $deliveryCost = (int) ($r['delivery_cost'] ?? 0);
        $deliveryOther = (int) ($r['delivery_other'] ?? 0);
        return ['orders' => (int) ($r['orders'] ?? 0), 'resends' => (int) ($r['resends'] ?? 0), 'units' => (int) ($r['units'] ?? 0), 'goods' => $goods, 'cost' => $cost,
            'profit' => $goods - $cost, 'margin_bp' => ProfitMath::marginBp($goods - $cost, $goods), 'delivery' => $delivery,
            // Delivery on its own line, never in the goods margin (0077, docs/decisions.md DL23): what it cost on the orders, the carriers' charges on no
            // order, and delivery charged less both.
            'delivery_cost' => $deliveryCost, 'delivery_other' => $deliveryOther, 'delivery_result' => $delivery - $deliveryCost - $deliveryOther,
            // PayPal's money (0080, PPF7): on the orders, on no order, and both
            'fee_cost' => (int) ($r['fee_cost'] ?? 0), 'fee_other' => (int) ($r['fee_other'] ?? 0), 'fees' => (int) ($r['fee_cost'] ?? 0) + (int) ($r['fee_other'] ?? 0),
            // Global Payments' card fees (0084, GPF11): on the orders, the part of them still estimated, on no order, and all of them; they stay
            // the shop's (never split onto its own goods: VB10)
            'gp_fee' => (int) ($r['gp_fee'] ?? 0), 'gp_fee_est' => (int) ($r['gp_fee_est'] ?? 0), 'gp_other' => (int) ($r['gp_other'] ?? 0),
            'gp' => (int) ($r['gp_fee'] ?? 0) + (int) ($r['gp_other'] ?? 0),
            'no_cost' => (int) ($r['units_no_cost'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0),
            'incomplete' => self::incomplete((int) ($r['units_no_cost'] ?? 0) + (int) ($r['pending'] ?? 0), (int) ($r['units'] ?? 0)),
            'own' => ['units' => $ownUnits, 'goods' => $ownGoods, 'cost' => $ownCost, 'profit' => $ownGoods - $ownCost,
                'margin_bp' => ProfitMath::marginBp($ownGoods - $ownCost, $ownGoods), 'no_cost' => $ownNoCost, 'pending' => $ownPending,
                'any' => $ownUnits !== 0 || $ownGoods !== 0 || $ownCost !== 0 || $ownNoCost !== 0 || $ownPending !== 0,
                // the shop's own profit is not complete while ANY of its units has no price or waits for one (the Codex review of bd9104d, M20:
                // its sales are never shown as its profit), not the 0.5% rule of ours
                'incomplete' => $ownNoCost + $ownPending > 0]];
    }

    /**
     * Whether summed figures are said "incomplete" (the lead's look at real data, PR85): the units with no buying price, or waiting for one,
     * are more than 0.5% of the units (one in 200). Below that the margin moves by less than the units' share of the goods, and the count of
     * those units is on the page anyway (the No price column). One order is incomplete with any unit (orderRow).
     */
    public static function incomplete(int $unknownUnits, int $units): bool
    {
        return $unknownUnits > 0 && $unknownUnits * self::INCOMPLETE_PER > $units;
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
