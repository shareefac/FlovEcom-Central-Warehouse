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
    /** The nightly check's last summary (profit_job; bin/refresh_item_cost.php --status reads it). */
    public const CHECK_JOB = 'returns_check';
    public const MAX_ORDERS = 5000;
    /** The nightly check reads a store's returned units this many at a time (its (channel_id, state) index). */
    public const PAGE = 2000;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => Clock::now();
    }

    /**
     * The nightly check (bin/refresh_item_cost.php --reconcile): every sale line whose unit is back in stock in CW and is not shown so, found
     * whatever the time of its return (a return whose booking committed after the sweep's window: the Codex review of 57d98ad, b2). One store
     * at a time, its returned units read in pages on its (channel_id, state) index, at most $maxOrders orders a store (the second final review
     * of 3c31d94, a-M3, PR149: one query over every store's units filtered on the state alone); an order whose work fails is counted and named,
     * and the next goes on (PR132).
     *
     * @return array{orders: int, failed: int, errors: list<array<string, mixed>>}
     */
    public function reconcile(int $maxOrders = self::MAX_ORDERS): array
    {
        $snapshots = new OrderSnapshots($this->db, $this->clock);
        $out = ['orders' => 0, 'failed' => 0, 'errors' => []];
        foreach (array_map('intval', $this->db->column('SELECT id FROM channel ORDER BY id')) as $ch) {
            $orders = [];
            $after = [0, ''];
            do {
                $page = $this->db->all("SELECT u.reservation_id, u.unit_id, s.order_ref, s.restocked, s.removed_at FROM reservation_unit u FORCE INDEX (ix_reservation_unit_store_state) "
                    . "LEFT JOIN sale_line s ON s.channel_id = u.channel_id AND s.line_id = u.unit_id WHERE u.channel_id = ? AND u.state = 'returned' "
                    . 'AND (u.reservation_id, u.unit_id) > (?, ?) ORDER BY u.reservation_id, u.unit_id LIMIT ' . self::PAGE, [$ch, $after[0], $after[1]]);
                foreach ($page as $r) {
                    if ($r['order_ref'] !== null && (int) $r['restocked'] === 0 && $r['removed_at'] === null) {
                        $orders[strtolower((string) $r['order_ref'])] ??= (string) $r['order_ref'];
                    }
                }
                $last = $page === [] ? null : $page[count($page) - 1];
                if ($last !== null) {
                    $after = [(int) $last['reservation_id'], (string) $last['unit_id']];
                }
            } while (count($page) === self::PAGE && count($orders) < $maxOrders);
            ksort($orders, SORT_STRING);
            foreach (array_slice($orders, 0, max(1, $maxOrders)) as $ref) {
                try {
                    $out['orders'] += $snapshots->recompute($ch, $ref) ? 1 : 0;
                } catch (\Throwable $e) {
                    PendingCosts::failed($out, $ch, $ref, $e);
                }
            }
        }
        PendingCosts::summary($this->db, self::CHECK_JOB, $out, $this->clock);
        return $out;
    }

    /** @return array{orders: int, failed: int, errors: list<array<string, mixed>>, mark_at: string} */
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
            $k = (int) $r['channel_id'] . "\0" . strtolower((string) $r['order_ref']); // one order, whatever the case of its ref (PR132)
            if (!isset($orders[$k]) && count($orders) >= $maxOrders) {
                $newMark = (string) $r['created_at'];
                break;
            }
            $orders[$k] = [(int) $r['channel_id'], (string) $r['order_ref']];
        }
        $snapshots = new OrderSnapshots($this->db, $this->clock);
        $out = ['orders' => 0, 'failed' => 0, 'errors' => []];
        foreach ($orders as [$ch, $ref]) {
            // One order whose work fails is counted and named; the next goes on (PR132). The mark still moves on: the nightly check reads every
            // returned unit again whatever its time.
            try {
                $out['orders'] += $snapshots->recompute($ch, $ref) ? 1 : 0;
            } catch (\Throwable $e) {
                PendingCosts::failed($out, $ch, $ref, $e);
            }
        }
        $this->db->exec('INSERT INTO profit_job (job, mark_at, ran_at, summary) VALUES (?, ?, ?, ?) AS x ON DUPLICATE KEY UPDATE mark_at = x.mark_at, '
            . 'ran_at = x.ran_at, summary = x.summary', [self::JOB, max((string) $mark, $newMark), Clock::db($now), json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        return $out + ['mark_at' => max((string) $mark, $newMark)];
    }
}
