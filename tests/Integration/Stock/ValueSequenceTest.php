<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\CwException;
use CW\Stock;
use CW\Tests\Support\StockTestCase;

/**
 * I3: every on_hand ledger row gets exactly one stock_value_seq row (1, 2, 3, ... per item, in commit
 * order, no gaps), written by Stock::flush() inside the operation's transaction, and nothing else does.
 * The invariant check after every test (7-9) covers the whole ledger as well.
 */
final class ValueSequenceTest extends StockTestCase
{
    /** @return list<array{seq: int, ledger_id: int, warehouse_id: int, movement_type: string, qty_delta: int}> in seq order */
    private function seqs(int $sku): array
    {
        return self::$db->all(
            'SELECT s.seq, s.stock_ledger_id AS ledger_id, l.warehouse_id, l.movement_type, l.qty_delta FROM stock_value_seq s '
            . 'JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE s.sku_id = ? ORDER BY s.seq',
            [$sku],
        );
    }

    private function clock(int $sku): ?int
    {
        $v = self::$db->value('SELECT last_seq FROM stock_value_clock WHERE sku_id = ?', [$sku]);
        return $v === null ? null : (int) $v;
    }

    private function onHandRows(int $sku): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand'", [$sku]);
    }

    /** Runs $op and asserts it added exactly $rows on_hand rows, each with the next seq. */
    private function step(int $sku, string $label, int $rows, callable $op): void
    {
        $before = count($this->seqs($sku));
        $op();
        $seqs = $this->seqs($sku);
        self::assertSame($before + $rows, count($seqs), "{$label}: seq rows added");
        self::assertSame($before + $rows, $this->onHandRows($sku), "{$label}: on_hand rows = seq rows");
        if ($seqs !== []) {
            self::assertSame(range(1, count($seqs)), array_column($seqs, 'seq'), "{$label}: seqs 1..N");
            self::assertSame(count($seqs), $this->clock($sku), "{$label}: clock");
        }
    }

    public function testEveryPathThatMovesOnHandGetsOneSeqPerRowAndNoOtherPathDoes(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in', 'supplier_return', 'erp_sale');
        $sku = $this->item('strict', 0);
        $this->listing($site, 'V1', $sku);
        $loose = $this->listing($site, 'V9', null);
        $verify = self::warehouseId('VERIFY');
        $main = self::warehouseId('MAIN');

        $this->step($sku, 'goods_in', 1, fn () => $this->book('goods_in', $sku, 30));
        $this->step($sku, 'supplier_return', 1, fn () => $this->book('supplier_return', $sku, 2));
        $this->step($sku, 'adjustment', 1, fn () => $this->book('adjustment', $sku, 2));
        $this->step($sku, 'write_off', 1, fn () => $this->book('write_off', $sku, 1));
        $this->step($sku, 'erp_sale (site relay)', 1, fn () => $this->ok($this->moves->record($site, ['type' => 'erp_sale', 'doc_ref' => 'ACC-SINV-1',
            'lines' => [['variant_id' => 'V1', 'qty' => 1]]], $this->key())));
        $this->step($sku, 'transfer_out', 1, fn () => $this->book('transfer_out', $sku, 3));
        $this->step($sku, 'transfer_in', 1, fn () => $this->book('transfer_in', $sku, 3, 'VERIFY'));
        $this->step($sku, 'count with a difference', 1, fn () => $this->book('count', $sku, 20, 'MAIN', '2026-09-26T13:00:00Z'));
        $this->step($sku, 'count without a difference', 1, fn () => $this->book('count', $sku, 3, 'VERIFY', '2026-09-26T13:00:00Z'));
        self::assertSame(0, (int) self::$db->value("SELECT qty_delta FROM stock_ledger WHERE sku_id = ? AND warehouse_id = ? AND movement_type = 'count'", [$sku, $verify]));

        $this->step($sku, 'reserve', 0, fn () => $this->ok($this->reserve($site, 'O1', [self::line('V1', 'u1', 'u2')])));
        $this->step($sku, 'commit', 0, fn () => $this->ok($this->commit($site, 'O1', [self::line('V1', 'u1', 'u2')])));
        $this->step($sku, 'reserve + release', 0, function () use ($site): void {
            $this->ok($this->reserve($site, 'O2', [self::line('V1', 'u3')]));
            $this->ok($this->release($site, 'O2', 1));
        });
        $this->step($sku, 'expire', 0, function () use ($site): void {
            $this->ok($this->reserve($site, 'O3', [self::line('V1', 'u4')]));
            $now = $this->now;
            $this->now = $now->modify('+1 day');
            try {
                self::assertSame(1, $this->res->expireDue(50));
            } finally {
                $this->now = $now;
            }
        });
        $this->step($sku, 'ship', 1, fn () => $this->ok($this->ship($site, 'O1', ['u1'], '2026-09-26T14:00:00Z')));
        $this->step($sku, 'unship', 1, fn () => $this->ok($this->res->unship($site, 'O1', ['u1'], '2026-09-26T14:30:00Z', $this->key())));
        $this->step($sku, 'ship again', 1, fn () => $this->ok($this->ship($site, 'O1', ['u1'], '2026-09-26T15:00:00Z')));
        $this->step($sku, 'return', 1, fn () => $this->ok($this->res->returnUnits($site, 'O1', ['u1'], $this->key())));
        $this->step($sku, 'cancel to VERIFY', 2, fn () => $this->ok($this->res->cancel($site, 'O1', ['u2'], false, $this->key())));
        $seqs = $this->seqs($sku);
        $last = array_slice($seqs, -2);
        self::assertSame([['transfer_out', $main, -1], ['transfer_in', $verify, 1]],
            array_map(static fn (array $s): array => [$s['movement_type'], $s['warehouse_id'], $s['qty_delta']], $last), 'MAIN first, then VERIFY');
        self::assertSame($last[0]['seq'] + 1, $last[1]['seq']);

        // A ship dispatched before the location's count moves allocated only (pre_count): no seq.
        $this->ok($this->commit($site, 'O4', [self::line('V1', 'u5')]));
        $this->step($sku, 'count before the late ship', 1, fn () => $this->book('count', $sku, 17, 'MAIN', '2026-09-26T16:30:00Z'));
        $this->step($sku, 'pre_count ship', 0, function () use ($site): void {
            self::assertSame('shipped_pre_count', $this->ship($site, 'O4', ['u5'], '2026-09-26T16:00:00Z')->body['units'][0]['result']);
        });
        // Units sold while the listing was unlinked are adopted into allocated/held: no seq.
        $this->ok($this->commit($site, 'O5', [self::line('V9', 'u6')]));
        $this->step($sku, 'adopt', 0, fn () => $this->relink($loose, $sku));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ? AND movement_type = 'adopt'", [$sku]));

        // Contiguous per item across MAIN and VERIFY, in ledger id order (one session books them one after another).
        $seqs = $this->seqs($sku);
        self::assertSame(range(1, 16), array_column($seqs, 'seq'));
        $ids = array_column($seqs, 'ledger_id');
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);
        self::assertSame([$main, $verify], array_values(array_unique(array_map('intval', array_column($seqs, 'warehouse_id')))));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE l.bucket <> 'on_hand'"));
    }

    public function testTwoLinesOfOneItemGetConsecutiveSeqsInLedgerIdOrderAndAReplayAddsNone(): void
    {
        $sku = $this->item('strict', 5);
        $req = ['type' => 'goods_in', 'doc_ref' => 'PINV-2L', 'lines' => [
            ['sku_id' => $sku, 'qty' => 4, 'line_index' => 7], ['sku_id' => $sku, 'qty' => 6, 'line_index' => 2], ['sku_id' => $sku, 'qty' => 1, 'warehouse' => 'VERIFY', 'line_index' => 9]]];
        $this->ok($this->moves->record(self::staff(), $req, 'two-lines'));
        $seqs = $this->seqs($sku);
        self::assertSame([1, 2, 3, 4], array_column($seqs, 'seq'));
        self::assertSame([5, 4, 6, 1], array_map('intval', array_column($seqs, 'qty_delta')), 'request line order, which is ledger id order');
        self::assertTrue($seqs[1]['ledger_id'] < $seqs[2]['ledger_id'] && $seqs[2]['ledger_id'] < $seqs[3]['ledger_id']);

        $again = $this->moves->record(self::staff(), $req, 'two-lines');
        self::assertTrue($again->replayed);
        self::assertCount(4, $this->seqs($sku));
        self::assertSame(4, $this->clock($sku));
    }

    /**
     * A movement that fails after its first line was applied (R16: the second line's delta is beyond INT) rolls back
     * whole: its seq rows and its clock increment go with it, so the next booking of the item gets n + 1 (no gap).
     */
    public function testARolledBackOperationLeavesNoGap(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $a = $this->item('strict', 3);
        $b = $this->item('strict', 0);
        $this->listing($site, 'A1', $a);
        $this->listing($site, 'B1000', $b, 1000);
        self::assertSame(1, $this->clock($a));
        try {
            $this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'PINV-R16', 'lines' => [
                ['variant_id' => 'A1', 'qty' => 2], ['variant_id' => 'B1000', 'qty' => 10_000_000]]], 'r16');
            self::fail('a delta beyond INT was booked');
        } catch (CwException $e) {
            self::assertSame('out_of_range', $e->errorCode);
        }
        self::assertSame(1, $this->clock($a), 'the clock increment rolled back');
        self::assertCount(1, $this->seqs($a));
        $this->book('goods_in', $a, 2);
        self::assertSame([1, 2], array_column($this->seqs($a), 'seq'));
        self::assertSame(2, $this->clock($a));
        $this->assertBal(5, 0, 0, $a);
    }

    /**
     * A transaction that fails AFTER flush() numbered its rows (the clock was bumped and the seq rows written, review
     * finding: the R16 case above fails before flush) rolls them back with everything else: the clock is where it was,
     * no seq row is left, and the next booking of the item gets n + 1. The same through bookForDocument, whose caller
     * owns the transaction (a document posting that fails after its stock was booked).
     */
    public function testARollbackAfterFlushRestoresTheClockAndTheSeqRows(): void
    {
        $sku = $this->item('strict', 3);
        $main = self::warehouseId('MAIN');
        self::assertSame(1, $this->clock($sku));
        try {
            self::$db->transaction(function () use ($sku, $main): void {
                $this->stock->lock([[$main, $sku]]);
                $this->stock->apply($main, $sku, 'on_hand', 2, ['type' => 'adjustment', 'actor' => 'system:test', 'note' => 'after flush']);
                $this->stock->flush();
                self::assertSame(2, $this->clock($sku), 'inside the transaction the clock moved');
                self::assertSame([1, 2], array_column($this->seqs($sku), 'seq'));
                throw new \RuntimeException('fails after flush');
            });
            self::fail('the transaction committed');
        } catch (\RuntimeException $e) {
            self::assertSame('fails after flush', $e->getMessage());
        }
        self::assertSame(1, $this->clock($sku), 'the clock increment rolled back');
        self::assertSame([1], array_column($this->seqs($sku), 'seq'));
        $this->assertBal(3, 0, 0, $sku);

        try {
            self::$db->transaction(function () use ($sku): void {
                $this->moves->bookForDocument(self::staff(), ['document_id' => 9101, 'doc_ref' => 'ADJ-000001'], 'doc:9101:post',
                    [['type' => 'adjustment', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => 4, 'unit_cost' => '1.5']]]]);
                self::assertSame(2, $this->clock($sku));
                throw new \RuntimeException('the posting fails after its stock');
            });
            self::fail('the transaction committed');
        } catch (\RuntimeException $e) {
            self::assertSame('the posting fails after its stock', $e->getMessage());
        }
        self::assertSame(1, $this->clock($sku));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = 9101'));
        $this->book('goods_in', $sku, 1);
        self::assertSame([1, 2], array_column($this->seqs($sku), 'seq'), 'the next booking gets n + 1: no gap');
        self::assertSame(2, $this->clock($sku));
        $this->assertBal(4, 0, 0, $sku);
    }

    /**
     * I29 (review finding): once a transaction has taken value clocks or the feed clock, no balance is locked in it again,
     * through ANY Stock on the same connection (the per-instance guard of I7 could not see a second Movements). A new
     * transaction (a deadlock retry included) starts clean.
     */
    public function testNoBalanceIsLockedAfterTheClocksThroughAnyStockOfTheConnection(): void
    {
        [$a, $b] = [$this->item('strict', 2), $this->item('strict', 2)];
        $main = self::warehouseId('MAIN');
        $verify = self::warehouseId('VERIFY');
        $other = new Stock(self::$db);
        $m = ['type' => 'adjustment', 'actor' => 'system:test', 'note' => 'two stocks'];
        foreach ([
            'value clocks only (VERIFY: no feed row)' => [$verify, 'on_hand'],
            'feed clock only (a hold at MAIN: no value seq)' => [$main, 'held'],
        ] as $label => [$wh, $bucket]) {
            try {
                self::$db->transaction(function () use ($other, $a, $b, $wh, $bucket, $m, $main): void {
                    $this->stock->lock([[$wh, $a]]);
                    $this->stock->apply($wh, $a, $bucket, 1, $m);
                    $this->stock->flush();
                    try {
                        $other->lock([[$main, $b]]);
                        self::fail('a balance was locked after the clocks');
                    } catch (\LogicException $e) {
                        self::assertStringContainsString('no balance is locked after them', $e->getMessage());
                    }
                    throw new \RuntimeException('roll back');
                });
            } catch (\RuntimeException $e) {
                self::assertSame('roll back', $e->getMessage(), $label);
            }
        }
        // A transaction whose flush took no clock (nothing on_hand, nothing sellable changed) may lock again.
        self::$db->transaction(function () use ($other, $a, $b, $main, $verify): void {
            $this->stock->lock([[$verify, $a]]);
            $this->stock->apply($verify, $a, 'held', 1, ['type' => 'reserve', 'actor' => 'system:test']);
            $this->stock->apply($verify, $a, 'held', -1, ['type' => 'release', 'actor' => 'system:test']);
            $this->stock->flush();
            $other->lock([[$main, $b]]);
            $other->apply($main, $b, 'on_hand', 1, ['type' => 'adjustment', 'actor' => 'system:test']);
            $other->flush();
        });
        // The next transaction starts clean for both.
        self::$db->transaction(function () use ($other, $a, $main): void {
            $other->lock([[$main, $a]]);
            $other->apply($main, $a, 'on_hand', 1, ['type' => 'adjustment', 'actor' => 'system:test']);
            $other->flush();
        });
        $this->assertBal(3, 0, 0, $a);
        $this->assertBal(3, 0, 0, $b);
    }

    public function testMoreItemsThanOneChunkAllGetTheirFirstSeq(): void
    {
        $n = Stock::VALUE_CHUNK + 1;
        $names = array_map(static fn (int $i): string => 'Chunk item ' . $i, range(1, $n));
        self::$db->exec('INSERT INTO sku (name) VALUES ' . implode(',', array_fill(0, $n, '(?)')), $names);
        self::$db->exec("UPDATE sku SET code = CONCAT('CW-', LPAD(id, 6, '0')) WHERE code IS NULL");
        $ids = array_map('intval', self::$db->column("SELECT id FROM sku WHERE name LIKE 'Chunk item %' ORDER BY id"));
        self::assertCount($n, $ids);
        $lines = [];
        foreach (array_reverse($ids) as $i => $id) { // any line order: the clocks are taken in sku_id order
            $lines[] = ['sku_id' => $id, 'qty' => 2, 'line_index' => $i];
        }
        $this->ok($this->moves->record(self::staff(), ['type' => 'goods_in', 'doc_ref' => 'PINV-CHUNK', 'lines' => $lines], 'chunk'));
        $r = self::$db->one(
            'SELECT COUNT(*) AS n, MIN(seq) AS lo, MAX(seq) AS hi FROM stock_value_seq WHERE sku_id IN (' . implode(',', $ids) . ')',
        );
        self::assertSame([$n, 1, 1], [(int) $r['n'], (int) $r['lo'], (int) $r['hi']]);
        self::assertSame($n, (int) self::$db->value('SELECT COUNT(*) FROM stock_value_clock WHERE last_seq = 1 AND sku_id IN (' . implode(',', $ids) . ')'));
    }

    public function testLockTwiceWithUnnumberedRowsIsRefusedAndARollbackClearsThem(): void
    {
        $sku = $this->item('strict', 4);
        $main = self::warehouseId('MAIN');
        $m = ['type' => 'adjustment', 'actor' => 'system:test', 'note' => 'lock guard'];
        try {
            self::$db->transaction(function () use ($sku, $main, $m): void {
                $this->stock->lock([[$main, $sku]]);
                $this->stock->apply($main, $sku, 'on_hand', 1, $m);
                try {
                    $this->stock->lock([[$main, $sku]]);
                    self::fail('a second lock() with on_hand rows still waiting for their seq was allowed');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('call flush() before lock() again', $e->getMessage());
                }
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('roll back', $e->getMessage());
        }
        // The rolled-back rows left stale entries in this Stock: the next transaction's lock() discards them.
        self::$db->transaction(function () use ($sku, $main, $m): void {
            $this->stock->lock([[$main, $sku]]);
            $this->stock->apply($main, $sku, 'on_hand', 1, $m);
            $this->stock->flush();
        });
        $this->assertBal(5, 0, 0, $sku);
        self::assertSame([1, 2], array_column($this->seqs($sku), 'seq'));

        // flush() alone (no lock()) after a rolled-back operation numbers nothing that is gone.
        try {
            self::$db->transaction(function () use ($sku, $main, $m): void {
                $this->stock->lock([[$main, $sku]]);
                $this->stock->apply($main, $sku, 'on_hand', 1, $m);
                throw new \RuntimeException('roll back again');
            });
        } catch (\RuntimeException) {
        }
        self::$db->transaction(function (): void {
            $this->stock->flush();
        });
        self::assertSame([1, 2], array_column($this->seqs($sku), 'seq'));
        // Allocated/held rows queue nothing, so lock() twice stays allowed for them (reserve's release-then-hold).
        self::$db->transaction(function () use ($sku, $main): void {
            $this->stock->lock([[$main, $sku]]);
            $this->stock->apply($main, $sku, 'held', 1, ['type' => 'reserve', 'actor' => 'system:test']);
            $this->stock->apply($main, $sku, 'held', -1, ['type' => 'release', 'actor' => 'system:test']);
            $this->stock->lock([[$main, $sku]]);
            $this->stock->flush();
        });
    }

    public function testSeqRowsAreWrittenBeforeTheFeedRowsOfTheirOperation(): void
    {
        $sku = $this->item('strict', 0);
        $this->book('goods_in', $sku, 3);
        $this->book('goods_in', $sku, 2, 'VERIFY'); // not sellable: no feed row, still a seq
        $r = self::$db->one(
            'SELECT (SELECT MAX(s.created_at) FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id '
            . "  WHERE l.sku_id = ? AND l.warehouse_id = ?) AS seq_at, (SELECT MIN(created_at) FROM stock_change WHERE sku_id = ? AND reason = 'stock') AS feed_at",
            [$sku, self::warehouseId('MAIN'), $sku],
        );
        self::assertNotNull($r['seq_at']);
        self::assertNotNull($r['feed_at']);
        self::assertLessThanOrEqual($r['feed_at'], $r['seq_at']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM stock_change WHERE sku_id = ? AND reason = 'stock'", [$sku]));
        self::assertCount(2, $this->seqs($sku));
    }

    public function testACostOnTheWrongRowIsAProgrammingError(): void
    {
        $sku = $this->item('strict', 1);
        $main = self::warehouseId('MAIN');
        foreach ([
            ['held', ['type' => 'reserve', 'unit_cost' => '1.000000', 'cost_source' => 'manual']],
            ['on_hand', ['type' => 'transfer_in', 'unit_cost' => '1.000000', 'cost_source' => 'manual']],
            ['on_hand', ['type' => 'goods_in', 'unit_cost' => '1.5', 'cost_source' => 'manual']],
            ['on_hand', ['type' => 'goods_in', 'unit_cost' => '1.500000']],
            ['on_hand', ['type' => 'goods_in', 'unit_cost' => '1.500000', 'cost_source' => 'guess']],
        ] as [$bucket, $m]) {
            try {
                self::$db->transaction(function () use ($sku, $main, $bucket, $m): void {
                    $this->stock->lock([[$main, $sku]]);
                    $this->stock->apply($main, $sku, $bucket, 1, $m + ['actor' => 'system:test']);
                });
                self::fail('accepted ' . json_encode($m));
            } catch (\LogicException $e) {
                self::assertStringContainsString('unit cost', $e->getMessage());
            }
        }
        $this->assertBal(1, 0, 0, $sku);
    }
}
