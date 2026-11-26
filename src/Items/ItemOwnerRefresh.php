<?php

declare(strict_types=1);

namespace CW\Items;

use CW\CwException;
use CW\Db;
use CW\Profit\OrderSnapshots;

/**
 * CW-1153 (docs/decisions.md VB13): puts the shop's-own mark on the sale lines stored before a period was started in the past (the go-live
 * kit's one explicit exception: the 81 items were Vape Big's own from the count of 16 Nov), or after the periods changed: each order with lines
 * of the given items, paid on the days given, whose lines' owners or own figures (the order-level refund's own share included) are not what
 * the periods say gets ONLY its owners and own figures written, and its day summaries by the same difference (OrderSnapshots::reown, under the
 * order's guards). Nothing is replayed (the Codex review of bd9104d, M6 and H1): it NEVER changes a cost, a unit, a sale or a refund, also for
 * a line restocked since or still waiting for its cost (its owner is set now; the cost job fixes its cost later and keeps the owner, `waiting`
 * counts those orders). An order another money writer holds is left (`deferred`: run again). A re-run finds nothing more to do.
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
        $service = new OrderSnapshots($this->db, $this->clock);
        $out = ['orders' => count($orders), 'refreshed' => 0, 'waiting' => 0, 'deferred' => 0, 'lines' => 0];
        foreach ($orders as $o) {
            $ch = (int) $o['channel_id'];
            $ref = (string) $o['order_ref'];
            $need = OrderSnapshots::ownerPlan($this->db, $ch, $ref, false);
            if ($need === null || !$need['due']) {
                continue;
            }
            if ($apply) {
                try {
                    if (!$service->reown($ch, $ref)) {
                        continue; // put right meanwhile (a snapshot of it worked out since the read above)
                    }
                } catch (CwException $e) {
                    if ($e->errorCode !== 'busy') {
                        throw $e;
                    }
                    $out['deferred']++;
                    continue;
                } catch (\PDOException $e) {
                    if (!in_array(Db::driverCode($e), [1205, 1213], true)) {
                        throw $e;
                    }
                    $out['deferred']++;
                    continue;
                }
            }
            $out['refreshed']++;
            $out['lines'] += count($need['moves']);
            $out['waiting'] += $need['waiting'] ? 1 : 0;
        }
        return $out;
    }
}
