<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Tests\Support\OpWorkers;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\WorkerPool;

/**
 * I3 under real concurrency: the value seq follows COMMIT order (which differs from stock_ledger id
 * order across warehouses), a rolled-back holder of an item's clock leaves no gap, and parallel
 * multi-item movements in random line order never deadlock on the clocks (taken in sku_id order).
 * Interleavings are forced with tests/Support/op_worker.php processes and a second session of the
 * test process (OpWorkers::session) holding a clock row, observed through performance_schema.data_locks.
 */
final class ValueSequenceRaceTest extends StockTestCase
{
    use OpWorkers;

    private const BUMP = 'INSERT INTO stock_value_clock (sku_id, last_seq) VALUES (?, ?) AS new '
        . 'ON DUPLICATE KEY UPDATE last_seq = stock_value_clock.last_seq + new.last_seq';

    protected function tearDown(): void
    {
        $this->stopWorkers();
        parent::tearDown();
    }

    /** @return list<array{seq: int, stock_ledger_id: int, warehouse_id: int, idem_key: ?string}> committed rows, in seq order */
    private function seqs(int $sku): array
    {
        return array_map(static fn (array $r): array => ['seq' => (int) $r['seq'], 'stock_ledger_id' => (int) $r['stock_ledger_id'],
            'warehouse_id' => (int) $r['warehouse_id'], 'idem_key' => $r['idem_key']],
            self::$db->all('SELECT s.seq, s.stock_ledger_id, l.warehouse_id, l.idem_key FROM stock_value_seq s '
                . 'JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE s.sku_id = ? ORDER BY s.seq', [$sku]));
    }

    /** @param list<array<string, mixed>> $lines */
    private static function move(string $key, string $warehouse, array $lines): array
    {
        return ['op' => 'move', 'key' => $key, 'request' => ['type' => 'goods_in', 'doc_ref' => 'PINV-' . $key, 'warehouse' => $warehouse, 'lines' => $lines]];
    }

    /**
     *   S  : holds W's clock (an in-flight operation on W that has not committed)
     *   T1 : goods_in of X then W at MAIN: inserts its X ledger row, then waits for W's clock (clocks go in sku_id order, W < X)
     *   T2 : goods_in of X at VERIFY (another balance): takes X's clock and commits -> X seq 2, with a HIGHER ledger id than T1's X row
     *   S rolls back -> T1 commits -> X seq 3 is T1's row, the LOWER ledger id. A reader never saw a gap.
     */
    public function testTheSeqFollowsCommitOrderNotLedgerIdOrder(): void
    {
        $w = $this->item('strict', 10);
        $x = $this->item('strict', 10);
        self::assertLessThan($x, $w);
        $s = self::session();
        $s->exec(self::BUMP, [$w, 0]);
        $t1 = $this->spawn([self::move('race-t1', 'MAIN', [['sku_id' => $x, 'qty' => 1], ['sku_id' => $w, 'qty' => 1]])]);
        $this->waitForLock('stock_value_clock', 'WAITING');
        $r2 = $this->collect($this->spawn([self::move('race-t2', 'VERIFY', [['sku_id' => $x, 'qty' => 2]])]), 30);
        self::assertSame(200, $r2['results'][0]['status'], json_encode($r2));
        self::assertTrue($this->running($t1), 'T1 still waits for W\'s clock');

        $seen = $this->seqs($x);
        self::assertSame([1, 2], array_column($seen, 'seq'), 'T1\'s X row is not committed, and no gap is visible');
        self::assertSame('race-t2', $seen[1]['idem_key']);

        $s->pdo()->rollBack();
        $r1 = $this->collect($t1, 30);
        self::assertSame(200, $r1['results'][0]['status'], json_encode($r1));
        self::assertSame(0, $r1['deadlocks'] + $r2['deadlocks'], $this->latestDeadlock());

        $after = $this->seqs($x);
        self::assertSame([1, 2, 3], array_column($after, 'seq'));
        self::assertSame(['race-t2', 'race-t1'], [$after[1]['idem_key'], $after[2]['idem_key']]);
        self::assertLessThan($after[1]['stock_ledger_id'], $after[2]['stock_ledger_id'], 'seq 3 belongs to the lower ledger id: commit order, not id order');
        self::assertSame([1, 2], array_column($this->seqs($w), 'seq'));
        self::assertSame(3, (int) self::$db->value('SELECT last_seq FROM stock_value_clock WHERE sku_id = ?', [$x]));
    }

    /** S bumps X's clock (and writes a seq row) and parks; T waits; S rolls back; T gets n + 1: no gap. */
    public function testARolledBackClockHolderLeavesNoGap(): void
    {
        $x = $this->item('strict', 10);
        $s = self::session();
        $s->exec(self::BUMP, [$x, 1]);
        $s->exec('INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id) VALUES (?, 2, 999999999)', [$x]);
        $t = $this->spawn([self::move('after-rollback', 'MAIN', [['sku_id' => $x, 'qty' => 3]])]);
        $this->waitForLock('stock_value_clock', 'WAITING');
        self::assertSame([1], array_column($this->seqs($x), 'seq'), 'S\'s seq 2 is not visible');
        $s->pdo()->rollBack();
        $r = $this->collect($t, 30);
        self::assertSame(200, $r['results'][0]['status'], json_encode($r));
        $seqs = $this->seqs($x);
        self::assertSame([1, 2], array_column($seqs, 'seq'));
        self::assertSame('after-rollback', $seqs[1]['idem_key']);
        self::assertSame(2, (int) self::$db->value('SELECT last_seq FROM stock_value_clock WHERE sku_id = ?', [$x]));
        $this->assertBal(13, 0, 0, $x);
    }

    /**
     * 12 workers x 40 staff movements of 1-4 items each (6 items, lines in random order, each line at MAIN or VERIFY):
     * no deadlock (balances, then clocks in sku_id order, then the feed clock), every item numbered 1..N, and replaying
     * each item's on_hand rows in seq order ends at its total on_hand.
     */
    public function testParallelMultiItemMovementsNeverDeadlockAndEveryItemIsNumberedWithoutGaps(): void
    {
        mt_srand(20261002);
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->item('legacy', 500);
        }
        $jobs = [];
        for ($w = 0; $w < WorkerPool::MAX_WORKERS; $w++) {
            $ops = [];
            for ($k = 0; $k < 40; $k++) {
                $pick = $items;
                shuffle($pick);
                $type = ['goods_in', 'adjustment', 'write_off'][mt_rand(0, 2)];
                $lines = [];
                foreach (array_slice($pick, 0, mt_rand(1, 4)) as $n => $sku) {
                    $qty = $type === 'adjustment' ? (mt_rand(0, 1) === 0 ? -1 : 1) * mt_rand(1, 5) : mt_rand(1, 5);
                    $lines[] = ['sku_id' => $sku, 'qty' => $qty, 'line_index' => $n, 'warehouse' => mt_rand(0, 1) === 0 ? 'MAIN' : 'VERIFY']
                        + ($type === 'goods_in' ? ['unit_cost' => sprintf('%d.%02d', mt_rand(0, 9), mt_rand(50, 99))] : []);
                }
                $ops[] = ['op' => 'move', 'caller' => 'staff', 'key' => "vs-{$w}-{$k}",
                    'request' => ['type' => $type, 'doc_ref' => "VS-{$w}-{$k}", 'lines' => $lines]];
            }
            $jobs[] = $ops;
        }
        $out = WorkerPool::run($jobs);

        self::assertCount(WorkerPool::MAX_WORKERS * 40, $out['results']);
        $by = array_count_values(array_map(static fn (array $r): string => $r['status'] . ':' . ($r['result'] ?? $r['error'] ?? ''), $out['results']));
        self::assertSame(['200:recorded' => WorkerPool::MAX_WORKERS * 40], $by, json_encode(array_slice(array_filter($out['results'],
            static fn (array $r): bool => $r['status'] !== 200), 0, 3)));
        self::assertSame(0, $out['deadlocks'], 'balances, clocks and the feed clock are always taken in one order');
        foreach ($items as $sku) {
            $seqs = array_map('intval', self::$db->column('SELECT seq FROM stock_value_seq WHERE sku_id = ? ORDER BY seq', [$sku]));
            $rows = (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand'", [$sku]);
            self::assertSame(range(1, $rows), $seqs, "item {$sku}: 1..N");
            $replay = (int) self::$db->value('SELECT SUM(l.qty_delta) FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE s.sku_id = ?', [$sku]);
            self::assertSame((int) self::$db->value('SELECT SUM(on_hand) FROM stock_balance WHERE sku_id = ?', [$sku]), $replay, "item {$sku}: replay");
        }
    }
}
