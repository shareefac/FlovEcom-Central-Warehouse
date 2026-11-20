<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Db;

/**
 * The sale lines whose cost waits for the cost job (docs/decisions.md PR16; the Codex review of 92efa82, a1/b1): a line stored on a paid
 * order before the job had read up to its paid time is left open (cost_source NULL with a day); once the job's mark is past the order's
 * paid time its cost is fixed, at the price of the paid time, through the order's own service (OrderSnapshots::recompute: its totals and
 * the day summaries follow). Run by bin/refresh_item_cost.php after each cost run, and by the start (bin/seed_item_cost.php).
 */
final class PendingCosts
{
    public const MAX_ORDERS = 20_000;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
    }

    /** @return array{orders: int} the orders whose lines were fixed */
    public function run(int $maxOrders = self::MAX_ORDERS): array
    {
        $mark = $this->db->value('SELECT mark_at FROM profit_job WHERE job = ?', [CostEngine::JOB]);
        if ($mark === null) {
            return ['orders' => 0];
        }
        $day = \CW\Reports\ReportRefresh::ukDay((string) $mark);
        // The open lines of a paid order (index (cost_source, day)), of orders paid up to the mark.
        $orders = $this->db->all('SELECT DISTINCT l.channel_id, l.order_ref FROM sale_line l JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
            . 'WHERE l.cost_source IS NULL AND l.day IS NOT NULL AND l.day <= ? AND l.removed_at IS NULL AND o.paid_at <= ? ORDER BY l.channel_id, l.order_ref LIMIT '
            . max(1, $maxOrders), [$day, (string) $mark]);
        $n = 0;
        $service = new OrderSnapshots($this->db, $this->clock); // it reads the job's mark once: the one this run read
        foreach ($orders as $o) {
            $n += $service->recompute((int) $o['channel_id'], (string) $o['order_ref']) ? 1 : 0;
        }
        return ['orders' => $n];
    }
}
