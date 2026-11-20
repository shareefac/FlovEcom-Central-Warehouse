<?php

declare(strict_types=1);

namespace CW\Profit;

use CW\Clock;
use CW\Db;

/**
 * A unit that came back to stock in CW (a return booked on its reservation) has no cost, and no sale when it was refunded
 * (docs/decisions.md PR9). The order's snapshot may have been stored before the return reached CW, and the store need not send it again
 * (its content did not change), so this sweep (run with the cost job, bin/refresh_item_cost.php) finds the returns booked since its mark
 * (the ledger's `return` rows, read again OVERLAP_SECONDS back) and works their orders out again from the stored snapshot
 * (OrderSnapshots::recompute). An order already showing the unit back in stock is left alone.
 */
final class ReturnsSweep
{
    public const JOB = 'returns';
    public const MAX_ORDERS = 5000;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /** @return array{orders: int, mark_at: string} */
    public function run(int $maxOrders = self::MAX_ORDERS): array
    {
        $now = ($this->clock)();
        $cutoff = Clock::db($now->modify('-' . CostEngine::LAG_SECONDS . ' seconds'));
        $mark = $this->db->value('SELECT mark_at FROM profit_job WHERE job = ?', [self::JOB]);
        if ($mark === null) {
            // The first run starts from the first order CW stored a snapshot of.
            $mark = $this->db->value('SELECT MIN(first_at) FROM order_snapshot') ?? $cutoff;
        }
        $from = Clock::db(Clock::fromDb((string) $mark)->modify('-' . CostEngine::OVERLAP_SECONDS . ' seconds'));
        $orders = [];
        $newMark = $cutoff;
        $lo = $this->db->value('SELECT id FROM stock_ledger WHERE created_at >= ? ORDER BY created_at, id LIMIT 1', [$from]);
        if ($lo !== null) {
            foreach ($this->db->all("SELECT l.channel_id, l.order_ref, l.created_at FROM stock_ledger l "
                . "JOIN sale_line s ON s.channel_id = l.channel_id AND s.line_id = l.unit_id AND s.restocked = 0 "
                . "WHERE l.id >= ? AND l.created_at >= ? AND l.created_at <= ? AND l.movement_type = 'return' AND l.channel_id IS NOT NULL ORDER BY l.id",
                [(int) $lo, $from, $cutoff]) as $r) {
                $k = (int) $r['channel_id'] . "\0" . (string) $r['order_ref'];
                if (!isset($orders[$k]) && count($orders) >= $maxOrders) {
                    $newMark = (string) $r['created_at'];
                    break;
                }
                $orders[$k] = [(int) $r['channel_id'], (string) $r['order_ref']];
            }
        }
        $snapshots = new OrderSnapshots($this->db, $this->clock);
        $n = 0;
        foreach ($orders as [$ch, $ref]) {
            $n += $snapshots->recompute($ch, $ref) ? 1 : 0;
        }
        $this->db->exec('INSERT INTO profit_job (job, mark_at, ran_at, summary) VALUES (?, ?, ?, ?) AS x ON DUPLICATE KEY UPDATE mark_at = x.mark_at, '
            . 'ran_at = x.ran_at, summary = x.summary', [self::JOB, max((string) $mark, $newMark), Clock::db($now), json_encode(['orders' => $n], JSON_THROW_ON_ERROR)]);
        return ['orders' => $n, 'mark_at' => max((string) $mark, $newMark)];
    }
}
