<?php

declare(strict_types=1);

namespace CW\Items;

use CW\Db;
use CW\Profit\OrderSnapshots;

/**
 * CW-1153 (docs/decisions.md VB13): puts the shop's-own mark on the sale lines stored before a period was started in the past (the go-live
 * kit's one explicit exception: the 81 items were Vape Big's own from the count of 16 Nov), or after the periods changed: each order with lines
 * of the given items, paid on the days given, whose lines' marks (or own figures) are not what the periods say is worked out again from its
 * stored snapshot by the money service itself (OrderSnapshots::recompute, under the order's guards: its day summaries follow). It NEVER
 * changes a cost: a line whose cost is fixed keeps it (the service's own rule), and an order with a line still waiting for its cost is left
 * to the cost job, which marks it the same way when it fixes the cost (`waiting`). A re-run finds nothing more to do.
 */
final class ItemOwnerRefresh
{
    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * @param list<int> $skuIds
     * @return array{orders: int, refreshed: int, waiting: int, deferred: int, lines: int}
     */
    public function run(array $skuIds, string $from, string $to, ?string $channelCode, bool $apply): array
    {
        $skuIds = array_values(array_unique(array_map('intval', $skuIds)));
        if ($skuIds === []) {
            throw new \InvalidArgumentException('an empty list of items');
        }
        $channel = null;
        if ($channelCode !== null) {
            $channel = $this->db->value('SELECT id FROM channel WHERE code = ?', [$channelCode]) ?? throw new \InvalidArgumentException("no store {$channelCode}");
        }
        $in = implode(', ', array_fill(0, count($skuIds), '?'));
        $orders = $this->db->all("SELECT DISTINCT l.channel_id, l.order_ref FROM sale_line l WHERE l.sku_id IN ({$in}) AND l.day BETWEEN ? AND ?"
            . ($channel === null ? '' : ' AND l.channel_id = ?') . ' ORDER BY l.channel_id, l.order_ref', [...$skuIds, $from, $to, ...($channel === null ? [] : [(int) $channel])]);
        $periods = ItemOwners::periods($this->db, $skuIds);
        $service = new OrderSnapshots($this->db, $this->clock);
        $out = ['orders' => count($orders), 'refreshed' => 0, 'waiting' => 0, 'deferred' => 0, 'lines' => 0];
        foreach ($orders as $o) {
            $ch = (int) $o['channel_id'];
            $ref = (string) $o['order_ref'];
            $need = $this->due($ch, $ref, $periods);
            if ($need === null) {
                continue;
            }
            if ($need['waiting']) {
                $out['waiting']++;
                continue;
            }
            if ($apply) {
                if (!$service->recompute($ch, $ref, [])) {
                    $out['deferred']++;
                    continue;
                }
            }
            $out['refreshed']++;
            $out['lines'] += $need['lines'];
        }
        return $out;
    }

    /**
     * Whether the order's marks or own figures differ from what the periods say (null: nothing to do), how many lines move, and whether a line
     * still waits for its cost (left to the cost job).
     *
     * @param array<int, list<array{0: int, 1: string, 2: ?string}>> $periods
     * @return array{lines: int, waiting: bool}|null
     */
    private function due(int $ch, string $ref, array $periods): ?array
    {
        $order = $this->db->one('SELECT paid_at, own_units, own_sale_ex, own_cost, own_no_cost FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$ch, $ref]);
        if ($order === null) {
            return null;
        }
        $paid = $order['paid_at'] === null ? null : (string) $order['paid_at'];
        $moves = 0;
        $waiting = false;
        $sums = ['own_units' => 0, 'own_sale_ex' => 0, 'own_cost' => 0, 'own_no_cost' => 0];
        $all = [];
        foreach ($this->db->all('SELECT sku_id, item_owner, units, sale_ex, cost, cost_source, restocked, removed_at FROM sale_line WHERE channel_id = ? AND order_ref = ?',
            [$ch, $ref]) as $l) {
            $all[] = $l;
        }
        // The items of the order not in $periods (another item of a mixed order) are read too: the order is worked out whole.
        $more = ItemOwners::periods($this->db, array_values(array_filter(array_map(static fn (array $l): ?int => $l['sku_id'] === null ? null : (int) $l['sku_id'], $all),
            static fn (?int $s): bool => $s !== null && !isset($periods[$s]))));
        foreach ($all as $l) {
            $sku = $l['sku_id'] === null ? null : (int) $l['sku_id'];
            $owner = ItemOwners::ownerAt($periods + $more, $sku, $paid);
            $want = $owner === $ch ? $owner : null;
            $has = $l['item_owner'] === null ? null : (int) $l['item_owner'];
            if ($want !== $has) {
                $moves++;
            }
            if ($l['removed_at'] === null && $l['cost_source'] === null && $paid !== null && (int) $l['units'] > 0 && (int) $l['restocked'] === 0) {
                $waiting = true;
            }
            if ($has !== null && $l['removed_at'] === null) {
                $sums['own_units'] += (int) $l['units'];
                $sums['own_sale_ex'] += (int) $l['sale_ex'];
                $sums['own_cost'] += (int) $l['cost'];
                $sums['own_no_cost'] += OrderSnapshots::unitsNoCost($l);
            }
        }
        $stale = $sums !== ['own_units' => (int) $order['own_units'], 'own_sale_ex' => (int) $order['own_sale_ex'], 'own_cost' => (int) $order['own_cost'],
            'own_no_cost' => (int) $order['own_no_cost']];
        if ($moves === 0 && !$stale) {
            return null;
        }
        return ['lines' => $moves, 'waiting' => $waiting];
    }
}
