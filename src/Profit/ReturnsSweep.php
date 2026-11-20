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

    /**
     * The nightly check (bin/refresh_item_cost.php --reconcile): every sale line whose unit is back in stock in CW and is not shown so, found
     * whatever the time of its return (a return whose booking committed after the sweep's window: the Codex review of 57d98ad, b2). Reads
     * every returned unit (a full read, once a night; a few hundred units a month come back).
     *
     * @return array{orders: int}
     */
    public function reconcile(int $maxOrders = self::MAX_ORDERS): array
    {
        $orders = $this->db->all("SELECT DISTINCT s.channel_id, s.order_ref FROM reservation_unit u JOIN sale_line s ON s.channel_id = u.channel_id AND s.line_id = u.unit_id "
            . "WHERE u.state = 'returned' AND s.restocked = 0 AND s.removed_at IS NULL ORDER BY s.channel_id, s.order_ref LIMIT " . max(1, $maxOrders));
        $snapshots = new OrderSnapshots($this->db, $this->clock);
        $n = 0;
        foreach ($orders as $o) {
            $n += $snapshots->recompute((int) $o['channel_id'], (string) $o['order_ref']) ? 1 : 0;
        }
        return ['orders' => $n];
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
        // By the booking time alone (its own index), never from the id of the window's first row: ids are given at insert, so a return committed
        // with an earlier id and a later time is still read (the final review of 6d59eda, c-M3, PR107).
        foreach ($this->db->all("SELECT l.channel_id, l.order_ref, l.created_at FROM stock_ledger l FORCE INDEX (ix_stock_ledger_created) "
            . "JOIN sale_line s ON s.channel_id = l.channel_id AND s.line_id = l.unit_id AND s.restocked = 0 "
            . "WHERE l.created_at >= ? AND l.created_at <= ? AND l.movement_type = 'return' AND l.channel_id IS NOT NULL ORDER BY l.created_at, l.id",
            [$from, $cutoff]) as $r) {
            $k = (int) $r['channel_id'] . "\0" . (string) $r['order_ref'];
            if (!isset($orders[$k]) && count($orders) >= $maxOrders) {
                $newMark = (string) $r['created_at'];
                break;
            }
            $orders[$k] = [(int) $r['channel_id'], (string) $r['order_ref']];
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
