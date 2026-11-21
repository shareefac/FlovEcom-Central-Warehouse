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
    /** Its last run's summary (profit_job; bin/refresh_item_cost.php --status reads it). */
    public const JOB = 'pending_costs';
    /** The orders that failed named in a run's summary (the first ones). */
    public const ERRORS_KEPT = 20;

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(private readonly Db $db, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * One order whose work fails (any error: a broken snapshot, a lock held too long) is counted and named, and the run goes on with the next
     * (the second final review of 3c31d94, a-HIGH4, b-M3, c-M7, a-M8; PR132): one order never holds the others' costs.
     *
     * @return array{orders: int, failed: int, errors: list<array{store: int, order: string, error: string}>} the orders whose lines were fixed,
     *         the orders that failed
     */
    public function run(int $maxOrders = self::MAX_ORDERS): array
    {
        $mark = $this->db->value('SELECT mark_at FROM profit_job WHERE job = ?', [CostEngine::JOB]);
        if ($mark === null) {
            return ['orders' => 0, 'failed' => 0, 'errors' => []];
        }
        $day = \CW\Reports\ReportRefresh::ukDay((string) $mark);
        // The open lines of a paid order (index (cost_source, day)), of orders paid up to the mark.
        $orders = $this->db->all('SELECT DISTINCT l.channel_id, l.order_ref FROM sale_line l JOIN sale_order o ON o.channel_id = l.channel_id AND o.order_ref = l.order_ref '
            . 'WHERE l.cost_source IS NULL AND l.day IS NOT NULL AND l.day <= ? AND l.removed_at IS NULL AND o.paid_at <= ? ORDER BY l.channel_id, l.order_ref LIMIT '
            . max(1, $maxOrders), [$day, (string) $mark]);
        $out = ['orders' => 0, 'failed' => 0, 'errors' => []];
        $service = new OrderSnapshots($this->db, $this->clock); // it reads the job's mark once: the one this run read
        foreach ($orders as $o) {
            try {
                $out['orders'] += $service->recompute((int) $o['channel_id'], (string) $o['order_ref']) ? 1 : 0;
            } catch (\Throwable $e) {
                self::failed($out, (int) $o['channel_id'], (string) $o['order_ref'], $e);
            }
        }
        self::summary($this->db, self::JOB, $out, $this->clock);
        return $out;
    }

    /**
     * One order that failed, counted and (the first ERRORS_KEPT) named with what went wrong.
     *
     * @param array{failed: int, errors: list<array<string, mixed>>} $out
     */
    public static function failed(array &$out, int $channelId, string $ref, \Throwable $e): void
    {
        $out['failed']++;
        if (count($out['errors']) < self::ERRORS_KEPT) {
            $out['errors'][] = ['store' => $channelId, 'order' => $ref, 'error' => mb_substr(($e instanceof \CW\CwException ? $e->errorCode : get_class($e)) . ': '
                . $e->getMessage(), 0, 200)];
        }
    }

    /**
     * A run's summary kept on its profit_job row (what --status reads; a run that failed on an order is also in the job's log).
     *
     * @param array<string, mixed> $summary
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public static function summary(Db $db, string $job, array $summary, ?\Closure $clock = null): void
    {
        $db->exec('INSERT INTO profit_job (job, ran_at, summary) VALUES (?, ?, ?) AS x ON DUPLICATE KEY UPDATE ran_at = x.ran_at, summary = x.summary',
            [$job, \CW\Clock::db($clock === null ? \CW\Clock::now() : $clock()), json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
    }
}
