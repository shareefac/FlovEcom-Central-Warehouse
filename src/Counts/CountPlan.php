<?php

declare(strict_types=1);

namespace CW\Counts;

use CW\Clock;
use CW\Db;
use CW\Ui\StockViews;
use DateTimeImmutable;

/**
 * The count plan and "Next to count" (pack A2a; docs/decisions.md CT10, CT11, CT13). Reads only.
 *
 * The plan is one number, the setting counts.plan_days: every item should be counted at least once in that many days. It covers the
 * items CW keeps a stock figure for at a warehouse (a stock_balance row there; an item merged into another is left out). When an
 * item was last counted there is stock_balance.counted_at, which only the count engine writes (a posted count; the count gate's
 * sku.counted_at is another thing and is not read here).
 *
 *   inside   counted within the plan's days
 *   overdue  never counted, or counted longer ago
 *
 * "Next to count", for one warehouse: the items never counted first (the best sellers first: reorder_demand.rate, the units a day
 * the reorder list works with; then the ones with no sales figure, by their CW number), then the counted ones, the longest ago
 * first. Every read keeps to an index: the never-counted and the oldest counts are ranges of stock_balance (warehouse_id, counted_at,
 * sku_id) (0029), the best sellers come off reorder_demand's index on the rate. It is a work list, not a report: it shows at most
 * MAX_SHOWN rows and has no later pages; what is counted and posted leaves it, and the next ones move up.
 */
final class CountPlan
{
    public const SHOW = [50, 100, 200];
    public const MAX_SHOWN = 200;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * The plan's figures for one warehouse: the items in the plan, how many were counted within $days, how many are overdue, and how
     * many of those were never counted.
     *
     * @return array{items: int, inside: int, overdue: int, never: int}
     */
    public function tiles(int $warehouseId, int $days, DateTimeImmutable $now): array
    {
        $r = $this->db->one(
            'SELECT COUNT(*) AS items, COALESCE(SUM(b.counted_at >= ?), 0) AS inside, COALESCE(SUM(b.counted_at IS NULL), 0) AS never '
            . 'FROM stock_balance b FORCE INDEX (ix_stock_balance_counted) JOIN sku s ON s.id = b.sku_id WHERE b.warehouse_id = ? AND s.merged_into_sku_id IS NULL',
            [Clock::db($now->modify('-' . max(0, $days) . ' days')), $warehouseId],
        ) ?? [];
        $items = (int) ($r['items'] ?? 0);
        $inside = (int) ($r['inside'] ?? 0);
        return ['items' => $items, 'inside' => $inside, 'overdue' => $items - $inside, 'never' => (int) ($r['never'] ?? 0)];
    }

    /**
     * The first $limit items to count at a warehouse (at most MAX_SHOWN): sku_id, code, name, brand, on_hand, counted_at (null:
     * never), rate (units sold a day as the reorder list has them; null: no sales figure).
     *
     * @return list<array{sku_id: int, code: string, name: string, brand: ?string, on_hand: int, counted_at: ?string, rate: ?string}>
     */
    public function next(int $warehouseId, int $limit): array
    {
        $limit = max(1, min(self::MAX_SHOWN, $limit));
        $cols = 's.id AS sku_id, s.code, s.name, s.brand, b.on_hand, b.counted_at, d.rate';
        // 1. Never counted, with a sales figure: the best sellers first (reorder_demand's index on the rate, read backwards).
        $rows = $this->db->all(
            "SELECT {$cols} FROM reorder_demand d FORCE INDEX (ix_reorder_demand_rate) JOIN stock_balance b ON b.warehouse_id = ? AND b.sku_id = d.sku_id "
            . 'JOIN sku s ON s.id = d.sku_id WHERE b.counted_at IS NULL AND s.merged_into_sku_id IS NULL ORDER BY d.rate DESC, d.sku_id DESC LIMIT ' . $limit,
            [$warehouseId],
        );
        // 2. Never counted, without one: by their number.
        if (count($rows) < $limit) {
            array_push($rows, ...$this->db->all(
                "SELECT {$cols} FROM stock_balance b FORCE INDEX (ix_stock_balance_counted) JOIN sku s ON s.id = b.sku_id LEFT JOIN reorder_demand d ON d.sku_id = b.sku_id "
                . 'WHERE b.warehouse_id = ? AND b.counted_at IS NULL AND d.sku_id IS NULL AND s.merged_into_sku_id IS NULL ORDER BY b.sku_id LIMIT ' . ($limit - count($rows)),
                [$warehouseId],
            ));
        }
        // 3. Counted: the longest ago first.
        if (count($rows) < $limit) {
            array_push($rows, ...$this->db->all(
                "SELECT {$cols} FROM stock_balance b FORCE INDEX (ix_stock_balance_counted) JOIN sku s ON s.id = b.sku_id LEFT JOIN reorder_demand d ON d.sku_id = b.sku_id "
                . 'WHERE b.warehouse_id = ? AND b.counted_at IS NOT NULL AND s.merged_into_sku_id IS NULL ORDER BY b.counted_at, b.sku_id LIMIT ' . ($limit - count($rows)),
                [$warehouseId],
            ));
        }
        return array_map(self::row(...), $rows);
    }

    /** @return list<int> the first $n items of next(): "take the next N" */
    public function nextIds(int $warehouseId, int $n): array
    {
        return array_column($this->next($warehouseId, $n), 'sku_id');
    }

    /**
     * The items a search finds (a CW number, or every word in the name, brand or code: the rule of Stock > Movements), whatever
     * their turn in the plan and whether or not CW keeps a figure for them at the warehouse (on_hand 0, never counted), by their
     * number; never a merged one. At most $limit (MAX_SHOWN).
     *
     * @return list<array{sku_id: int, code: string, name: string, brand: ?string, on_hand: int, counted_at: ?string, rate: ?string}>
     */
    public function search(int $warehouseId, string $q, int $limit): array
    {
        [$where, $params] = StockViews::skuMatch(trim($q), 's');
        if ($where === []) {
            return [];
        }
        return array_map(self::row(...), $this->db->all(
            'SELECT s.id AS sku_id, s.code, s.name, s.brand, b.on_hand, b.counted_at, d.rate FROM sku s LEFT JOIN stock_balance b ON b.warehouse_id = ? AND b.sku_id = s.id '
            . 'LEFT JOIN reorder_demand d ON d.sku_id = s.id WHERE ' . implode(' AND ', $where) . ' AND s.merged_into_sku_id IS NULL ORDER BY s.id LIMIT '
            . max(1, min(self::MAX_SHOWN, $limit)),
            [$warehouseId, ...$params],
        ));
    }

    /**
     * @param array<string, mixed> $r
     * @return array{sku_id: int, code: string, name: string, brand: ?string, on_hand: int, counted_at: ?string, rate: ?string}
     */
    private static function row(array $r): array
    {
        return ['sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'brand' => $r['brand'] === null ? null : (string) $r['brand'],
            'on_hand' => (int) ($r['on_hand'] ?? 0), 'counted_at' => $r['counted_at'] === null ? null : (string) $r['counted_at'],
            'rate' => $r['rate'] === null ? null : (string) $r['rate']];
    }
}
