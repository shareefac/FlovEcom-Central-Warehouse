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
        // 0080 (docs/decisions.md PF7): PayPal's money on the orders and not on an order.
        . 'COALESCE(SUM(fee_cost), 0) AS fee_cost, COALESCE(SUM(fee_other), 0) AS fee_other';
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
        foreach ($this->db->all('SELECT day, SUM(units) AS u FROM sale_line WHERE cost_source IS NULL AND day BETWEEN ? AND ? AND removed_at IS NULL AND restocked = 0'
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
        // Units waiting for their cost (minutes at most), per row, joined in SQL: their rows are incomplete too, and a list by margin puts every
        // incomplete row after the rows whose margin is known BEFORE the list is cut at ROW_LIMIT (the Codex re-checks of 37a2cf0 and 6b8a7ee,
        // c6); a brand's waiting units are matched by the database's own comparison, the one its rows are grouped by (case does not matter).
        $pending = 'COALESCE(MAX(w.u), 0)';
        // Incomplete as figures() says it (PR85): more than 0.5% of the row's units without a known cost.
        $incomplete = '((SUM(p.units_no_cost) + ' . $pending . ') * ' . self::INCOMPLETE_PER . ' > SUM(p.units))';
        $order = match ($sort) {
            'sale' => "{$goods} DESC",
            'cost' => 'SUM(p.cost) DESC',
            'units' => 'SUM(p.units) DESC',
            'margin' => "{$incomplete}, {$margin} IS NULL, {$margin} DESC",
            'margin_low' => "{$incomplete}, {$margin} IS NULL, {$margin} ASC",
            default => "{$goods} - SUM(p.cost) DESC",
        };
        $where = 'p.day BETWEEN ? AND ?' . ($store === null ? '' : ' AND p.channel_id = ?');
        $params = [$from, $to, ...($store === null ? [] : [$store])];
        $lw = 'l.cost_source IS NULL AND l.removed_at IS NULL AND l.restocked = 0 AND l.day BETWEEN ? AND ?' . ($store === null ? '' : ' AND l.channel_id = ?');
        $waiting = static fn (string $key, string $from, string $on): string => "LEFT JOIN (SELECT {$key} AS k, SUM(l.units) AS u FROM {$from} WHERE {$lw} GROUP BY k) w ON {$on}";
        $limit = self::ROW_LIMIT + 1;
        $rows = match ($by) {
            'shop' => $this->db->all('SELECT p.channel_id AS id, c.name AS label, NULL AS code, ' . self::SUMS . ", {$pending} AS pending FROM profit_day p "
                . 'JOIN channel c ON c.id = p.channel_id ' . $waiting('l.channel_id', 'sale_line l', 'w.k = p.channel_id')
                . " WHERE {$where} GROUP BY p.channel_id, c.name ORDER BY {$order}, p.channel_id LIMIT {$limit}", [...$params, ...$params]),
            'brand' => $this->db->all('SELECT COALESCE(NULLIF(s.brand, \'\'), \'\') AS label, NULL AS id, NULL AS code, ' . self::ITEM_SUMS . ", {$pending} AS pending "
                . 'FROM profit_item_day p LEFT JOIN sku s ON s.id = p.sku_id '
                . $waiting("COALESCE(NULLIF(s.brand, ''), '')", 'sale_line l LEFT JOIN sku s ON s.id = l.sku_id', "w.k = COALESCE(NULLIF(s.brand, ''), '')")
                . " WHERE {$where} GROUP BY label ORDER BY {$order}, label LIMIT {$limit}", [...$params, ...$params]),
            'supplier' => $this->db->all('SELECT si.supplier_id AS id, COALESCE(sp.name, \'\') AS label, NULL AS code, ' . self::ITEM_SUMS . ", {$pending} AS pending "
                . 'FROM profit_item_day p LEFT JOIN supplier_item si ON si.preferred_sku_id = p.sku_id LEFT JOIN supplier sp ON sp.id = si.supplier_id '
                . $waiting('si.supplier_id', 'sale_line l LEFT JOIN supplier_item si ON si.preferred_sku_id = l.sku_id', 'w.k <=> si.supplier_id')
                . " WHERE {$where} GROUP BY si.supplier_id, sp.name ORDER BY {$order}, label LIMIT {$limit}", [...$params, ...$params]),
            default => $this->db->all('SELECT p.sku_id AS id, COALESCE(s.name, \'\') AS label, s.code, ' . self::ITEM_SUMS . ", {$pending} AS pending FROM profit_item_day p "
                . 'LEFT JOIN sku s ON s.id = p.sku_id ' . $waiting('COALESCE(l.sku_id, 0)', 'sale_line l', 'w.k = p.sku_id')
                . " WHERE {$where} GROUP BY p.sku_id, s.name, s.code ORDER BY {$order}, p.sku_id LIMIT {$limit}", [...$params, ...$params]),
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
            . '(SELECT COUNT(*) FROM sale_line l WHERE l.channel_id = o.channel_id AND l.order_ref = o.order_ref AND l.cost_source IS NULL AND l.removed_at IS NULL '
            . 'AND l.units > 0 AND l.restocked = 0) AS pending';
        // Its re-sends' units with no price or waiting for one are those of its whole chain (a replacement of a replacement too: the second
        // final review of 3c31d94, a-M9, c-M6, PR143), read for the rows that have re-sends (a few on a page).
        $chain = function (array $rows): array {
            foreach ($rows as $i => $r) {
                $f = (int) $r['resends'] > 0 ? OrderSnapshots::resendFigures($this->db, (int) $r['channel_id'], (string) $r['order_ref']) : ['nc' => 0, 'open' => 0];
                $rows[$i]['resend_no_cost'] = (int) $f['nc'];
                $rows[$i]['resend_pending'] = (int) $f['open'];
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
        // Counted on the first page only (the next pages carry no count: the Codex review of 57d98ad, c2); the lowest margin first counts the
        // orders it can show, those with a margin (the second final review of 3c31d94, b-L1, c-L2, PR147: it counted the replacements too).
        $total = $after === null ? (int) $this->db->value('SELECT COUNT(*) FROM sale_order o WHERE ' . implode(' AND ', $where) . ($lowest ? ' AND o.margin_bp IS NOT NULL' : ''),
            $params) : null;
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
        return ['rows' => array_map(self::orderRow(...), $chain($rows)), 'total' => $total, 'next' => $next];
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
        $sale = (int) $r['sale_ex'];
        $cost = (int) $r['cost'];
        $resendCost = (int) $r['resend_cost'];
        // An order not paid (any more) shows no profit and no margin, whatever its rows still hold (the Codex check of the fix round, F18 PARTLY,
        // PR152: a sale paid with its cost fixed and then unpaid showed £7, 70%).
        $unpaid = $r['paid_at'] === null;
        $ownUnknown = $unpaid || (int) $r['units_no_cost'] > 0 || (int) ($r['pending'] ?? 0) > 0;
        $resendUnknown = (int) ($r['resend_no_cost'] ?? 0) > 0 || (int) ($r['resend_pending'] ?? 0) > 0;
        return ['store_id' => (int) $r['channel_id'], 'store' => (string) $r['store'], 'ref' => (string) $r['order_ref'], 'no' => (string) $r['order_no'],
            'status' => (string) $r['status'], 'paid_at' => $r['paid_at'] === null ? null : (string) $r['paid_at'], 'gateway' => (string) $r['gateway'],
            'parent' => $r['parent_ref'] === null ? null : (string) $r['parent_ref'], 'units' => (int) $r['units'], 'sale' => $sale, 'cost' => $cost,
            'resends' => (int) $r['resends'], 'resend_cost' => $resendCost, 'profit' => $ownUnknown ? null : $sale - $cost,
            'with_resends' => (int) $r['resends'] === 0 ? null : ($ownUnknown || $resendUnknown ? null : $sale - $cost - $resendCost),
            'margin_bp' => $unpaid || $r['margin_bp'] === null ? null : (int) $r['margin_bp'], 'unpaid' => $unpaid,
            'delivery' => (int) $r['delivery_ex'], 'no_cost' => (int) $r['units_no_cost'], 'pending' => (int) ($r['pending'] ?? 0),
            'own_unknown' => $ownUnknown, 'resend_unknown' => $resendUnknown,
            // Incomplete: some unit it counts, its own or a paid re-send's, has no price or waits for it.
            'incomplete' => $ownUnknown || $resendUnknown];
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
            // its PayPal money (0080, docs/decisions.md PF16): a sale's fee, a refund's fee back, a chargeback's money and fees
            'paypal' => PaypalFees::orderLines($this->db, $store, (string) $o['order_ref']),
            'clone_goods_inc' => $cloneGoods,
            'lines' => self::grouped($lines),
            'parcels' => $delivery['parcels'],
            'delivery' => $delivery,
            'resends' => $resends,
            'own_incomplete' => $own,
            'resends_incomplete' => $theirs,
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
                (int) $l['cancelled'], (int) $l['is_free'], (int) $l['restocked'], (int) $l['refund_inc'] > 0 ? 1 : 0, $l['removed_at'] === null ? 0 : 1]);
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
        $delivery = (int) ($r['delivery_ex'] ?? 0);
        $deliveryCost = (int) ($r['delivery_cost'] ?? 0);
        $deliveryOther = (int) ($r['delivery_other'] ?? 0);
        return ['orders' => (int) ($r['orders'] ?? 0), 'resends' => (int) ($r['resends'] ?? 0), 'units' => (int) ($r['units'] ?? 0), 'goods' => $goods, 'cost' => $cost,
            'profit' => $goods - $cost, 'margin_bp' => ProfitMath::marginBp($goods - $cost, $goods), 'delivery' => $delivery,
            // Delivery on its own line, never in the goods margin (0077, docs/decisions.md DL23): what it cost on the orders, the carriers' charges on no
            // order, and delivery charged less both.
            'delivery_cost' => $deliveryCost, 'delivery_other' => $deliveryOther, 'delivery_result' => $delivery - $deliveryCost - $deliveryOther,
            // PayPal's money (0080, PF7): on the orders, on no order, and both
            'fee_cost' => (int) ($r['fee_cost'] ?? 0), 'fee_other' => (int) ($r['fee_other'] ?? 0), 'fees' => (int) ($r['fee_cost'] ?? 0) + (int) ($r['fee_other'] ?? 0),
            'no_cost' => (int) ($r['units_no_cost'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0),
            'incomplete' => self::incomplete((int) ($r['units_no_cost'] ?? 0) + (int) ($r['pending'] ?? 0), (int) ($r['units'] ?? 0))];
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
