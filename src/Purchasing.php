<?php

declare(strict_types=1);

namespace CW;

/**
 * GET /v1/purchasing (plan §3, §9 "purchasing view"): per item, CW's stock and demand across
 * every site, paged by sku id.
 *
 *   on_hand / allocated / held / available   summed over SELLABLE warehouses (central units)
 *   non_sellable_on_hand                     VERIFY, UNSTAMPED, ... (not available to sell)
 *   counted_at                               the latest count of the item at a sellable
 *                                            warehouse (stock_balance.counted_at, R11)
 *   units_90d                                {channel code: central units} of units in a
 *                                            committed order committed in the last 90 days and
 *                                            not cancelled (allocated, shipped or returned),
 *                                            opening orders included; linked units only
 */
final class Purchasing
{
    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT = 2000;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{items: list<array<string, mixed>>, next_after_sku: ?int} */
    public function report(int $afterSku = 0, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $rows = $this->db->all(
            'SELECT s.id, s.code, s.name, s.brand, s.sell_policy, MAX(IF(w.is_sellable = 1, b.counted_at, NULL)) AS counted_at, '
            . 'COALESCE(SUM(IF(w.is_sellable = 1, b.on_hand, 0)), 0) AS on_hand, '
            . 'COALESCE(SUM(IF(w.is_sellable = 1, b.allocated, 0)), 0) AS allocated, '
            . 'COALESCE(SUM(IF(w.is_sellable = 1, b.held, 0)), 0) AS held, '
            . 'COALESCE(SUM(IF(w.is_sellable = 0, b.on_hand, 0)), 0) AS other_on_hand '
            . 'FROM sku s LEFT JOIN stock_balance b ON b.sku_id = s.id LEFT JOIN warehouse w ON w.id = b.warehouse_id '
            . 'WHERE s.id > ? GROUP BY s.id ORDER BY s.id LIMIT ?',
            [$afterSku, $limit],
        );
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        /** @var array<int, array<string, int>> $units */
        $units = [];
        if ($ids !== []) {
            foreach ($this->db->all(
                'SELECT ru.sku_id, c.code, SUM(ru.units_per_item) AS units FROM reservation_unit ru '
                . 'JOIN reservation r ON r.id = ru.reservation_id JOIN channel c ON c.id = ru.channel_id '
                . "WHERE ru.sku_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND ru.state IN ('allocated', 'shipped', 'returned') "
                . "AND r.status = 'committed' AND r.committed_at >= UTC_TIMESTAMP(6) - INTERVAL 90 DAY "
                . 'GROUP BY ru.sku_id, c.code ORDER BY ru.sku_id, c.code',
                $ids,
            ) as $u) {
                $units[(int) $u['sku_id']][(string) $u['code']] = (int) $u['units'];
            }
        }
        $items = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $onHand = (int) $r['on_hand'];
            $allocated = (int) $r['allocated'];
            $held = (int) $r['held'];
            $items[] = [
                'sku_id' => $id,
                'sku_code' => $r['code'],
                'name' => $r['name'],
                'brand' => $r['brand'],
                'policy' => $r['sell_policy'],
                'counted_at' => Clock::iso($r['counted_at'] === null ? null : (string) $r['counted_at']),
                'on_hand' => $onHand,
                'allocated' => $allocated,
                'held' => $held,
                'available' => $onHand - $allocated - $held,
                'non_sellable_on_hand' => (int) $r['other_on_hand'],
                'units_90d' => $units[$id] ?? new \stdClass(),
            ];
        }
        return ['items' => $items, 'next_after_sku' => count($rows) === $limit ? end($ids) : null];
    }
}
