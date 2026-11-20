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
 *  - else the item's history up to the moment folded in its sources' order (ProfitMath::fold, the cost job's own rule): `cw_cost` from the
 *    day CW's own price starts (profit.cost_start), `estimate` before it (ERPNext's history import puts the cost at each sale in its place:
 *    erp_at_sale);
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
        foreach ($skuIds as $sku) {
            // The item's history up to the moment, folded in its sources' order with the job's own rule (ProfitMath::fold): the price at a
            // moment is the same whether the events came in order or one came late and was folded in since (the Codex review of 57d98ad, a6).
            // The item's last event up to the moment (a `replay` included: it holds the folded price of all before it) has the price the fold
            // gives, unless an event written after it has an earlier source (it came late: that event's price is out of date). One indexed
            // read and one short check; only a moment a late event changed folds the history (the Codex re-check of 37a2cf0, R6). The check
            // reads the late events only (the cost job marks each one, `late`; ix_item_cost_event_late): bounded by how many came late, never
            // by how long the history after the moment is (the Codex re-check of 6b8a7ee, R6).
            $last = $this->db->one('SELECT id, at, source_at, new_cost_e4 FROM item_cost_event WHERE sku_id = ? AND source_at <= ? ORDER BY source_at DESC, id DESC LIMIT 1',
                [$sku, $atUtc]);
            $before = $last === null;
            $stale = !$before && $this->db->value('SELECT 1 FROM item_cost_event WHERE sku_id = ? AND late = 1 AND source_at < ? AND id > ? LIMIT 1',
                [$sku, $last['source_at'], (int) $last['id']]) !== null;
            if ($stale) {
                $e4 = ProfitMath::fold($this->db->all('SELECT id, kind, units, on_hand, price_e4, booked_e4, new_cost_e4, deferred, undoes_event_id FROM item_cost_event '
                    . "WHERE sku_id = ? AND source_at <= ? AND kind <> 'replay' ORDER BY source_at, id", [$sku, $atUtc]));
            } else {
                $e4 = $before || $last['new_cost_e4'] === null ? null : (int) $last['new_cost_e4'];
            }
            if ($before) {
                $first = $this->db->one("SELECT new_cost_e4 FROM item_cost_event WHERE sku_id = ? AND kind = 'start' ORDER BY source_at, id LIMIT 1", [$sku]);
                $e4 = $first === null || $first['new_cost_e4'] === null ? null : (int) $first['new_cost_e4'];
            }
            $out[$sku] = ['e4' => $e4, 'source' => $e4 === null ? 'none' : ($own && !$before ? 'cw_cost' : 'estimate')];
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
