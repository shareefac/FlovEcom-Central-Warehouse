<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * The ONE deliberate re-cost of sales already stored (bin/recost_sales.php; docs/decisions.md PR16): the lines of the orders paid on the
 * days asked for, whose cost came from the sources asked for (default: none), get the buying price at the time they were paid again
 * (ItemCosts::at, from today's history), with costed_by `recost`. A line with no item takes the item its listing is linked to now. Each
 * order through its own service (OrderSnapshots::recompute), so its totals and the day summaries follow. A cost is never changed silently:
 * only this command and the ERPNext history import change a fixed cost.
 */
final class RecostSales
{
    public const SOURCES = ['none', 'estimate', 'cw_cost', 'erp_at_sale'];
    /**
     * What `--source=all` means: every source but ERPNext's own cost at the sale (erp_at_sale is corrected by the ERPNext import run again,
     * never by CW's price; withdrawing it takes an explicit --source=erp_at_sale: the third final review, a-M / c-M5, PR154).
     */
    public const ALL = ['none', 'estimate', 'cw_cost'];

    /**
     * The sources a --source argument names: "all" is ALL; else the comma-separated list as given (each checked by run()).
     *
     * @return list<string>
     */
    public static function sources(string $arg): array
    {
        return $arg === 'all' ? self::ALL : array_values(array_unique(array_map('trim', explode(',', $arg))));
    }

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * @param list<string> $sources
     * @return array{orders: int, lines: int, moves: array<string, int>, deferred: int} deferred: orders left because a newer snapshot waits for
     *         the money job (run again after it)
     */
    public function run(string $from, string $to, array $sources, ?string $channelCode, bool $apply): array
    {
        foreach ($sources as $s) {
            if (!in_array($s, self::SOURCES, true)) {
                throw new \InvalidArgumentException("unknown cost source {$s}");
            }
        }
        $channel = null;
        if ($channelCode !== null) {
            $channel = $this->db->value('SELECT id FROM channel WHERE code = ?', [$channelCode]);
            if ($channel === null) {
                throw new \InvalidArgumentException("no store {$channelCode}");
            }
        }
        $in = implode(', ', array_fill(0, count($sources), '?'));
        $orders = $this->db->all("SELECT DISTINCT channel_id, order_ref FROM sale_line WHERE day BETWEEN ? AND ? AND cost_source IN ({$in}) AND removed_at IS NULL"
            . ($channel === null ? '' : ' AND channel_id = ?') . ' ORDER BY channel_id, order_ref', [$from, $to, ...$sources, ...($channel === null ? [] : [(int) $channel])]);
        $settings = new ProfitSettings($this->db);
        $costs = new ItemCosts($this->db, $settings);
        $service = new OrderSnapshots($this->db, $this->clock, $settings);
        $out = ['orders' => 0, 'lines' => 0, 'moves' => [], 'deferred' => 0];
        foreach ($orders as $o) {
            $ch = (int) $o['channel_id'];
            $ref = (string) $o['order_ref'];
            $plan = static fn (Db $db): ?array => self::plan($db, $costs, $ch, $ref, $sources, $from, $to);
            // What would move, read now (the dry run's figures); written under the order's locks from what is stored THEN (a line an import
            // fixed meanwhile is no longer of these sources and is left alone: the Codex review of 92efa82, a11/b11).
            $p = $plan($this->db);
            if ($p === null || $p['override'] === []) {
                continue;
            }
            if ($apply) {
                // What was really written, worked out again under the order's locks (the Codex check of c48683e: never the plan read before).
                $done = null;
                $wrote = $service->recompute($ch, $ref, static function (Db $db) use ($plan, &$done): ?array {
                    $again = $plan($db);
                    $done = $again === null || $again['override'] === [] ? null : $again;
                    return $done === null ? null : $again['override'];
                });
                // Counted only when recompute() says it committed: a change rolled back at its commit (a snapshot of the order stored meanwhile)
                // is left for later, `deferred`, whatever the function worked out (the second final review of 3c31d94, a-M6, b-M7, c-M4, PR136).
                if (!$wrote || $done === null) {
                    $out['deferred'] += $service->deferred ? 1 : 0;
                    continue;
                }
                $p = $done;
            }
            foreach ($p['moves'] as $k => $n) {
                $out['moves'][$k] = ($out['moves'][$k] ?? 0) + $n;
            }
            $out['orders']++;
            $out['lines'] += count($p['override']);
        }
        ksort($out['moves']);
        return $out;
    }

    /**
     * One order's lines of these sources and the cost each would get now (null: not paid, or none of these sources).
     *
     * @param list<string> $sources
     * @return array{override: array<string, array<string, mixed>>, moves: array<string, int>}|null
     */
    private static function plan(Db $db, ItemCosts $costs, int $ch, string $ref, array $sources, string $from, string $to): ?array
    {
        // Still paid on one of the days asked for, read again under the order's locks when it is written (a newer snapshot may have moved its
        // payment to another day meanwhile: the final review of 6d59eda, c-M8, PR110).
        $o = $db->one('SELECT paid_at, day FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$ch, $ref]);
        if ($o === null || $o['paid_at'] === null || (string) $o['day'] < $from || (string) $o['day'] > $to) {
            return null;
        }
        $paid = (string) $o['paid_at'];
        $in = implode(', ', array_fill(0, count($sources), '?'));
        $lines = $db->all("SELECT l.line_id, l.sku_id, l.cost_source, l.costed_by, l.unit_cost_e4, l.variant_id, c.sku_id AS now_sku, c.units_per_item AS now_upi, c.status FROM sale_line l "
            . 'LEFT JOIN channel_listing c ON c.channel_id = l.channel_id AND c.external_variant_id = l.variant_id '
            . "WHERE l.channel_id = ? AND l.order_ref = ? AND l.removed_at IS NULL AND l.cost_source IN ({$in})", [$ch, $ref, ...$sources]);
        if ($lines === []) {
            return null;
        }
        $linked = static fn (array $l): bool => $l['sku_id'] === null && $l['now_sku'] !== null && in_array($l['status'], ['mapped', 'quarantined'], true);
        $skus = [];
        foreach ($lines as $l) {
            if ($l['sku_id'] !== null || $linked($l)) {
                $skus[] = $l['sku_id'] !== null ? (int) $l['sku_id'] : (int) $l['now_sku'];
            }
        }
        $at = $costs->at($skus, (string) $paid);
        $override = [];
        $moves = [];
        foreach ($lines as $l) {
            $relinked = $linked($l);
            $sku = $l['sku_id'] !== null ? (int) $l['sku_id'] : ($relinked ? (int) $l['now_sku'] : null);
            $c = $sku === null ? ['e4' => null, 'source' => 'none'] : ($at[$sku] ?? ['e4' => null, 'source' => 'none']);
            if ($c['source'] === ItemCosts::PENDING) {
                continue; // the cost job has not reached its paid time: left as it is
            }
            $source = $c['e4'] === null ? 'none' : $c['source'];
            if (!$relinked && $l['costed_by'] === 'recost' && (string) $l['cost_source'] === $source
                && ($l['unit_cost_e4'] === null ? null : (int) $l['unit_cost_e4']) === $c['e4']) {
                continue; // re-costed to this very cost before: its time fixed stays (the final review of 6d59eda, a-LOW)
            }
            $override[(string) $l['line_id']] = ['e4' => $c['e4'], 'source' => $source, 'by' => 'recost']
                + ($relinked ? ['sku' => (int) $l['now_sku'], 'upi' => max(1, (int) $l['now_upi'])] : []);
            $k = $l['cost_source'] . ' > ' . $override[(string) $l['line_id']]['source'];
            $moves[$k] = ($moves[$k] ?? 0) + 1;
        }
        return ['override' => $override, 'moves' => $moves];
    }
}
