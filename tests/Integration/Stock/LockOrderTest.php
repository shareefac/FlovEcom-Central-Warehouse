<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Tests\Support\OpWorkers;
use CW\Tests\Support\StockTestCase;

/**
 * The lock order and the feed clock under forced interleavings (D39, R1, R2). Workers
 * (tests/Support/op_worker.php, one connection each) are started one at a time and the test
 * waits on performance_schema.data_locks until each one is parked where it should be.
 *
 * Found by the review of 26 Sep (slot review1): the final opening batch X-locked the channel row
 * AFTER the feed clock, while every site transaction holds an S lock on its channel row from its
 * idempotency claim (FK check) — a deadlock with the site's own stock writes, a lost non-final
 * batch, and every other site's stock write queued behind the held feed clock.
 */
final class LockOrderTest extends StockTestCase
{
    use OpWorkers;

    private const T0 = ['at' => '2026-09-26T17:00:00Z', 'last_order_id' => 1, 'last_stock_log_id' => null];

    protected function tearDown(): void
    {
        $this->stopWorkers();
        parent::tearDown();
    }

    /**
     *   D  : holds the MAIN balance of s2 (what any in-flight reserve of s2 does)
     *   W2 : reserve on s2 (site vpg): claim -> FK S on channel(vpg); parks on D
     *   W1 : opening_orders final (site vpg, s1): must complete while W2 still holds channel S
     *   D  : commit -> W2 completes. No deadlock (it used to be 1213, W2 rolled back).
     */
    public function testOpeningOrdersFinalDoesNotDeadlockWithTheSitesOwnStockWrites(): void
    {
        $site = $this->site('vpg', 'shadow');
        $s1 = $this->item('strict', 10);
        $s2 = $this->item('strict', 10);
        $this->listing($site, 'V1', $s1);
        $this->listing($site, 'V2', $s2);

        $d = $this->holdBalance($s2);
        $w2 = $this->spawn([self::op($site, 'reserve', ['order_ref' => 'R2', 'lines' => [self::line('V2', 'r2a')], 'key' => 'rv-R2'])]);
        $this->waitForLock('stock_balance', 'WAITING');
        $w1 = $this->spawn([self::op($site, 'opening', ['orders' => [['order_ref' => 'O1', 'lines' => [self::line('V1', 'o1a')]]],
            'final' => true, 't0' => self::T0, 'key' => 'opening-final'])]);
        $r1 = $this->collect($w1, 20);
        self::assertTrue($this->running($w2), 'the reserve is still parked on the balance D holds');
        $d->pdo()->commit();
        $r2 = $this->collect($w2);

        self::assertSame(201, $r2['results'][0]['status'], json_encode($r2));
        self::assertSame(200, $r1['results'][0]['status'], json_encode($r1));
        self::assertSame(0, $r1['deadlocks'] + $r2['deadlocks'], "W1 " . json_encode($r1) . "\nW2 " . json_encode($r2) . "\n" . $this->latestDeadlock());
        self::assertNotNull(self::$db->value('SELECT opening_orders_at FROM channel_opening WHERE channel_id = ?', [$site->channelId]));
        $this->assertBal(10, 1, 0, $s1);
        $this->assertBal(10, 0, 1, $s2);
    }

    /**
     * Opening calls of one site run one at a time on its channel_opening row. A non-final batch
     * in flight when the final one arrives is not lost (it used to be the deadlock victim, and its
     * retry stored 409 opening_orders_done for ever). The connector sends final=true only after
     * every earlier batch answered 200; this checks CW copes even when it does not.
     */
    public function testANonFinalOpeningBatchIsNotLostToTheFinalOne(): void
    {
        $site = $this->site('vpg', 'shadow');
        $s1 = $this->item('strict', 100);
        $s2 = $this->item('strict', 10);
        $this->listing($site, 'V1', $s1);
        $this->listing($site, 'V2', $s2);
        $finalOrders = [];
        for ($i = 0; $i < 40; $i++) {
            $finalOrders[] = ['order_ref' => sprintf('F%02d', $i), 'lines' => [self::line('V1', sprintf('f%02d', $i))]];
        }

        $d = $this->holdBalance($s2);
        $wb = $this->spawn([self::op($site, 'opening', ['orders' => [['order_ref' => 'B1', 'lines' => [self::line('V2', 'b1a')]]],
            'final' => false, 'key' => 'opening-batch-1'])]);
        $this->waitForLock('stock_balance', 'WAITING');
        $wf = $this->spawn([self::op($site, 'opening', ['orders' => $finalOrders, 'final' => true, 't0' => self::T0, 'key' => 'opening-batch-2-final'])]);
        $this->waitForLock('channel_opening', 'WAITING'); // the final batch queues behind the first
        $d->pdo()->commit();

        $rb = $this->collect($wb);
        $rf = $this->collect($wf);
        self::assertSame([200, 200], [$rb['results'][0]['status'], $rf['results'][0]['status']], json_encode([$rb, $rf]));
        self::assertSame(0, $rb['deadlocks'] + $rf['deadlocks'], $this->latestDeadlock());
        self::assertSame('committed', $this->reservation($site, 'B1')['status'] ?? null);
        $this->assertBal(100, 40, 0, $s1);
        $this->assertBal(10, 1, 0, $s2);
    }

    /**
     * While a session of site vpg holds S on vpg's channel row (here: a channel_listing insert,
     * as PUT /v1/listings does for new variants), vpg's final opening batch completes, and a
     * reserve of another site is not queued behind a feed clock held by it.
     */
    public function testAnotherSitesReserveIsNotStalledWhileTheOpeningSitesChannelRowIsShared(): void
    {
        $site = $this->site('vpg', 'shadow');
        $other = $this->site('vbg', 'live');
        $s1 = $this->item('strict', 10);
        $s3 = $this->item('strict', 10);
        $this->listing($site, 'V1', $s1);
        $this->listing($other, 'W3', $s3);

        // Baseline: the other site's reserve with nothing in the way.
        $base = $this->collect($this->spawn([
            self::op($other, 'reserve', ['order_ref' => 'WARM', 'lines' => [self::line('W3', 'warm-a')], 'key' => 'rv-warm']),
            self::op($other, 'release', ['order_ref' => 'WARM', 'attempt' => 1, 'key' => 'rl-warm']),
            self::op($other, 'reserve', ['order_ref' => 'BASE', 'lines' => [self::line('W3', 'base-a')], 'key' => 'rv-base']),
        ]));
        $baseMs = $base['results'][2]['elapsed_ms'];

        $push = self::session();
        $push->exec("INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, 'NEW-1')", [$site->channelId]);
        $this->waitForLock('channel', 'GRANTED'); // S on channel(vpg), held until $push ends
        $ro = $this->collect($this->spawn([self::op($site, 'opening', ['orders' => [['order_ref' => 'O1', 'lines' => [self::line('V1', 'o1a')]]],
            'final' => true, 't0' => self::T0, 'key' => 'opening-final'])]), 20);
        $rr = $this->collect($this->spawn([self::op($other, 'reserve', ['order_ref' => 'R3', 'lines' => [self::line('W3', 'r3a')], 'key' => 'rv-R3'])]), 20);
        $push->pdo()->rollBack();

        self::assertSame(200, $ro['results'][0]['status'], json_encode($ro));
        self::assertSame(201, $rr['results'][0]['status'], json_encode($rr));
        $stalled = $rr['results'][0]['elapsed_ms'];
        fwrite(STDERR, sprintf("\n[lock-order] other-site reserve while vpg's channel row is shared: %d ms (baseline %d ms); opening final %d ms\n",
            $stalled, $baseMs, $ro['results'][0]['elapsed_ms']));
        self::assertLessThan($baseMs + 250, $stalled, "site vbg's reserve took {$stalled} ms (baseline {$baseMs} ms)");
    }

    /**
     * R2: flush() writes the feed rows in multi-row INSERTs, so the global feed clock is held for
     * a couple of round trips, not one per item. A 2,000-line goods-in (Movements::MAX_LINES) on
     * 2,000 items: its feed rows are written within ~50-90 ms (it was ~1.6 s at 0.66 ms per round
     * trip, and every other site's stock write waited that long).
     */
    public function testALargeMovementDoesNotHoldTheFeedClockForOneRoundTripPerItem(): void
    {
        $n = 2000;
        $names = array_map(static fn (int $i): string => 'Bulk item ' . $i, range(1, $n));
        self::$db->exec('INSERT INTO sku (name) VALUES ' . implode(',', array_fill(0, $n, '(?)')), $names);
        self::$db->exec("UPDATE sku SET code = CONCAT('CW-', LPAD(id, 6, '0')) WHERE code IS NULL");
        $ids = array_map('intval', self::$db->column("SELECT id FROM sku WHERE name LIKE 'Bulk item %' ORDER BY id"));
        self::assertCount($n, $ids);
        $other = $this->site('vbg', 'live');
        $s3 = $this->item('strict', 10);
        $this->listing($other, 'W3', $s3);

        $lines = [];
        foreach ($ids as $i => $id) {
            $lines[] = ['sku_id' => $id, 'qty' => 5, 'line_index' => $i];
        }
        $wm = $this->spawn([['op' => 'move', 'key' => 'gi-big', 'request' => ['type' => 'goods_in', 'doc_ref' => 'PINV-BIG', 'warehouse' => 'MAIN', 'lines' => $lines]]]);
        $wr = $this->spawn([
            self::op($other, 'reserve', ['order_ref' => 'WARM', 'lines' => [self::line('W3', 'warm-a')], 'key' => 'rv-warm']),
            ['op' => 'wait_lock', 'table' => 'feed_clock', 'status' => 'GRANTED', 'timeout' => 60, 'interval_ms' => 20],
            self::op($other, 'reserve', ['order_ref' => 'R3', 'lines' => [self::line('W3', 'r3a')], 'key' => 'rv-R3']),
        ]);
        $rr = $this->collect($wr, 120);
        $rm = $this->collect($wm, 120);
        self::assertSame(200, $rm['results'][0]['status'], json_encode($rm['results'][0]['error'] ?? $rm['results'][0]['status']));
        self::assertSame(201, $rr['results'][2]['status'], json_encode($rr));
        $span = self::$db->one(
            "SELECT COUNT(*) AS n, TIMESTAMPDIFF(MICROSECOND, MIN(created_at), MAX(created_at)) DIV 1000 AS ms FROM stock_change "
            . "WHERE reason = 'stock' AND sku_id IN (SELECT sku_id FROM stock_ledger WHERE idem_key = 'gi-big')",
        );
        fwrite(STDERR, sprintf("\n[lock-order] goods-in of %d lines: %d feed rows written within %d ms; other-site reserve %d ms (warm %d ms)\n",
            $n, $span['n'], $span['ms'], $rr['results'][2]['elapsed_ms'], $rr['results'][0]['elapsed_ms']));
        self::assertSame($n, (int) $span['n']);
        // Measured 46-90 ms on staging (the review's target: < 100 ms). The bound leaves headroom for
        // a busy shared cluster and still catches one INSERT per item (1.5-1.8 s) by a factor of 6.
        self::assertLessThan(250, (int) $span['ms'], "flush() held the global feed clock across {$span['n']} feed rows for {$span['ms']} ms");
    }

    /** A second connection holding the X lock of one MAIN balance (what any in-flight reserve of it does). */
    private function holdBalance(int $sku): \CW\Db
    {
        $d = self::session();
        self::assertNotNull($d->one('SELECT on_hand FROM stock_balance WHERE warehouse_id = ? AND sku_id = ? FOR UPDATE', [self::warehouseId('MAIN'), $sku]));
        return $d;
    }
}
