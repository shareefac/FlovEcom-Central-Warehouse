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
 *  - else the item's last event at or before the moment: `cw_cost` from the day CW's own price starts (profit.cost_start), `estimate`
 *    before it (ERPNext's history import puts the cost at each sale in its place: erp_at_sale);
 *  - a moment before the item's first event: that first price, as an `estimate`;
 *  - no event at all, or no price: `none`.
 */
final class ItemCosts
{
    public const SOURCES = ['erp_at_sale', 'cw_cost', 'estimate', 'none'];

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
        foreach ($skuIds as $sku) {
            $r = $this->db->one('SELECT new_cost_e4 FROM item_cost_event WHERE sku_id = ? AND at <= ? ORDER BY at DESC, id DESC LIMIT 1', [$sku, $atUtc]);
            $before = false;
            if ($r === null) {
                $r = $this->db->one('SELECT new_cost_e4 FROM item_cost_event WHERE sku_id = ? ORDER BY at, id LIMIT 1', [$sku]);
                $before = true;
            }
            $e4 = $r === null || $r['new_cost_e4'] === null ? null : (int) $r['new_cost_e4'];
            $out[$sku] = ['e4' => $e4, 'source' => $e4 === null ? 'none' : ($own && !$before ? 'cw_cost' : 'estimate')];
        }
        return $out;
    }

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
