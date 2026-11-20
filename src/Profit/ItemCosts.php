<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * The buying price of CW items at a moment (docs/decisions.md PR12-PR14, PR21): read from item_cost_event, the history the cost job
 * writes (CostEngine). Reads only.
 *
 *  - before ERPNext's history (profit.erp_history_from): profit.before_history says: `estimate` = the item's price today, marked as an
 *    estimate; `none` = no price;
 *  - else the price of the item's last event up to the moment in its timeline in force (always in time order: CostEngine::rebuild):
 *    `cw_cost` from the day CW's own price starts (profit.cost_start), `estimate` before it (ERPNext's history import puts the cost at each
 *    sale in its place: erp_at_sale);
 *  - a moment before the item's first event: that first price, as an `estimate`;
 *  - no event at all, or no price: `none`;
 *  - `pending` (nothing fixed yet) while the cost job has not read up to the moment (its mark, profit_job): a delivery booked just
 *    before a sale may not be in the history yet (the Codex review of 92efa82, a1/b1); CW\Profit\PendingCosts fixes those lines once the
 *    job is past their paid time. Before the start of the buying prices was ever written every moment is pending.
 */
final class ItemCosts
{
    public const SOURCES = ['erp_at_sale', 'cw_cost', 'estimate', 'none'];
    /** Not a source: the cost job has not reached the moment yet (the line waits). */
    public const PENDING = 'pending';

    private readonly ProfitSettings $settings;

    public function __construct(private readonly Db $db, ?ProfitSettings $settings = null)
    {
        $this->settings = $settings ?? new ProfitSettings($db);
    }

    /**
     * @param list<int> $skuIds
     * @return array<int, array{e4: ?int, source: string}> every item asked for
     */
    public function at(array $skuIds, string $atUtc): array
    {
        $out = [];
        $skuIds = array_values(array_unique(array_filter($skuIds, static fn (int $s): bool => $s > 0)));
        if ($skuIds === []) {
            return [];
        }
        if (!$this->reached($atUtc)) {
            return array_fill_keys($skuIds, ['e4' => null, 'source' => self::PENDING]);
        }
        if ($atUtc < $this->settings->historyFromAt()) {
            if ($this->settings->beforeHistory() === 'none') {
                return array_fill_keys($skuIds, ['e4' => null, 'source' => 'none']);
            }
            foreach ($this->now($skuIds) as $sku => $e4) {
                $out[$sku] = ['e4' => $e4, 'source' => $e4 === null ? 'none' : 'estimate'];
            }
            return $out;
        }
        $own = $atUtc >= $this->settings->costStartAt();
        // The item's last event up to the moment in its timeline in force: a timeline is always in time order (an event the cost job meets
        // late makes it write the item's whole history again: CostEngine::rebuild), so its price is the price at the moment. One indexed read
        // per item (ix_item_cost_event_item, after the item's row), however long the history (the Codex re-checks of 37a2cf0 and 6b8a7ee, R6),
        // and ONE statement for up to 500 items (a lateral read per item: an order of 1,000 items is two statements, not 2,000; the final
        // review of 6d59eda, a-HIGH2, PR95).
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $asked = implode(' UNION ALL ', array_fill(0, count($chunk), 'SELECT ? AS sku_id'));
            // Each item's last event up to the moment: a dependent read per item, backwards on its own index from the moment (one row).
            $rows = $this->db->all('SELECT a.sku_id, COALESCE(c.timeline, 0) AS tl, (SELECT e.id FROM item_cost_event e FORCE INDEX (ix_item_cost_event_item) '
                . 'WHERE e.sku_id = a.sku_id AND e.timeline = COALESCE(c.timeline, 0) AND e.source_at <= ? ORDER BY e.source_at DESC, e.id DESC LIMIT 1) AS last_id '
                . 'FROM (' . $asked . ') a LEFT JOIN item_cost c ON c.sku_id = a.sku_id', [$atUtc, ...$chunk]);
            $ids = array_values(array_filter(array_map(static fn (array $r): int => (int) ($r['last_id'] ?? 0), $rows)));
            $price = [];
            foreach ($ids === [] ? [] : $this->db->all('SELECT id, new_cost_e4 FROM item_cost_event WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids) as $e) {
                $price[(int) $e['id']] = $e['new_cost_e4'] === null ? null : (int) $e['new_cost_e4'];
            }
            // A moment before an item's first event: that first price (its start), as an estimate (rare: read only for those items).
            $first = [];
            $early = array_values(array_filter($rows, static fn (array $r): bool => $r['last_id'] === null));
            if ($early !== []) {
                $pairs = implode(' UNION ALL ', array_fill(0, count($early), 'SELECT ? AS sku_id, ? AS tl'));
                $params = [];
                foreach ($early as $r) {
                    array_push($params, (int) $r['sku_id'], (int) $r['tl']);
                }
                foreach ($this->db->all('SELECT b.sku_id, (SELECT e.new_cost_e4 FROM item_cost_event e FORCE INDEX (ix_item_cost_event_item) WHERE e.sku_id = b.sku_id '
                    . "AND e.timeline = b.tl AND e.kind = 'start' ORDER BY e.source_at, e.id LIMIT 1) AS e4 FROM ({$pairs}) b", $params) as $r) {
                    $first[(int) $r['sku_id']] = $r['e4'] === null ? null : (int) $r['e4'];
                }
            }
            foreach ($rows as $r) {
                $sku = (int) $r['sku_id'];
                $before = $r['last_id'] === null;
                $e4 = $before ? ($first[$sku] ?? null) : ($price[(int) $r['last_id']] ?? null);
                $out[$sku] = ['e4' => $e4, 'source' => $e4 === null ? 'none' : ($own && !$before ? 'cw_cost' : 'estimate')];
            }
        }
        foreach ($skuIds as $sku) {
            $out[$sku] ??= ['e4' => null, 'source' => 'none'];
        }
        return $out;
    }

    /** Whether the cost job has read every delivery and invoice up to $atUtc (its mark). */
    public function reached(string $atUtc): bool
    {
        $this->mark ??= [(string) ($this->db->value('SELECT mark_at FROM profit_job WHERE job = ?', [CostEngine::JOB]) ?? '')];
        return $this->mark[0] !== '' && $atUtc <= $this->mark[0];
    }

    /** @var array{0: string}|null the cost job's mark, read once per instance ('' when never started) */
    private ?array $mark = null;

    /**
     * @param list<int> $skuIds
     * @return array<int, ?int> every item asked for => its price now (null: none)
     */
    public function now(array $skuIds): array
    {
        $out = array_fill_keys($skuIds, null);
        foreach (array_chunk($skuIds, 500) as $chunk) {
            foreach ($this->db->all('SELECT sku_id, unit_cost_e4 FROM item_cost WHERE sku_id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                $out[(int) $r['sku_id']] = $r['unit_cost_e4'] === null ? null : (int) $r['unit_cost_e4'];
            }
        }
        return $out;
    }
}
