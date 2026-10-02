<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Tests\Support\Documents\FixtureDocuments;
use CW\Tests\Support\StockTestCase;

/**
 * I2, I7: a document posting books all its stock movements inside its own transaction with
 * Movements::bookForDocument (one lock(), one flush()), and a reversal books their exact negation with
 * Movements::reverseDocument. The documents are fixture rows (tests/Support/Documents/FixtureDocuments: posted, numbered,
 * a reversal pair whole) with fixed ids (9001, ...): stock_ledger.document_id has no FK (D15), but DocumentInvariants
 * (0008) checks that every ledger row names a posted document by its number.
 */
final class DocumentBookingTest extends StockTestCase
{
    private const DOC = ['document_id' => 9001, 'doc_ref' => 'ADJ-000001'];
    private const COUNTED_AT = '2026-09-26T17:30:00Z';

    /** @return mixed $fn's result, run inside one Db::transaction */
    private function inTx(callable $fn): mixed
    {
        return self::$db->transaction(static fn (Db $db): mixed => $fn($db));
    }

    private function feedTicks(): int
    {
        return (int) self::$db->value('SELECT COALESCE(MAX(ticks), 0) FROM feed_clock');
    }

    /** Record locks this test's schema holds right now on $table (the test process is the only session). */
    private function recordLocks(string $table): int
    {
        return (int) self::$db->value(
            "SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA = DATABASE() AND OBJECT_NAME = ? AND LOCK_TYPE = 'RECORD'",
            [$table],
        );
    }

    public function testOutsideATransactionIsAProgrammingError(): void
    {
        $sku = $this->item('strict', 1);
        try {
            $this->moves->bookForDocument(self::staff(), self::DOC, 'doc:9001:post',
                [['type' => 'goods_in', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => 1]]]]);
            self::fail('booked outside a transaction');
        } catch (\LogicException $e) {
            self::assertStringContainsString('inside the transaction', $e->getMessage());
        }
        try {
            $this->moves->reverseDocument(self::staff(), 9001, ['document_id' => 9002, 'doc_ref' => 'ADJ-000002'], 'doc:9002:reverse');
            self::fail('reversed outside a transaction');
        } catch (\LogicException $e) {
            self::assertStringContainsString('inside the transaction', $e->getMessage());
        }
    }

    public function testOnePostingBooksEveryMovementWithItsDocumentAndOneFeedTransactionAndItsReversalUndoesIt(): void
    {
        [$a, $b, $c, $d, $e] = [$this->item('strict', 10), $this->item('strict', 0), $this->item('strict', 20), $this->item('strict', 0), $this->item('strict', 0)];
        $this->book('goods_in', $d, 3, 'VERIFY');
        $this->book('goods_in', $e, 2, 'VERIFY');
        $before = [];
        foreach ([$a, $b, $c, $d, $e] as $s) {
            $before[$s] = [$this->bal($s), $this->bal($s, 'VERIFY')];
        }
        $ticks = $this->feedTicks();
        $feedRows = (int) self::$db->value('SELECT COUNT(*) FROM stock_change');
        self::assertSame(self::DOC['doc_ref'], FixtureDocuments::posted(self::$db, 9001, 'ADJ', 1));

        $out = $this->inTx(fn (): array => $this->moves->bookForDocument(self::staff(), self::DOC, 'doc:9001:post', [
            ['type' => 'goods_in', 'warehouse' => 'MAIN', 'note' => 'delivery', 'lines' => [
                ['document_line' => 1, 'sku_id' => $a, 'qty' => 5, 'unit_cost' => '1.25'],
                ['document_line' => 2, 'sku_code' => sprintf('CW-%06d', $b), 'qty' => 8, 'unit_cost' => 2]]],
            ['type' => 'transfer_out', 'warehouse' => 'MAIN', 'lines' => [['document_line' => 3, 'sku_id' => $c, 'qty' => 4]]],
            ['type' => 'transfer_in', 'warehouse' => 'VERIFY', 'lines' => [['document_line' => 3, 'sku_id' => $c, 'qty' => 4]]],
            ['type' => 'count', 'warehouse' => 'VERIFY', 'counted_at' => self::COUNTED_AT, 'lines' => [
                ['document_line' => 5, 'sku_id' => $d, 'qty' => 4], ['document_line' => 6, 'sku_id' => $d, 'qty' => 3],
                ['document_line' => 7, 'sku_id' => $e, 'qty' => 2, 'unit_cost' => '0.5']]],
        ]));

        self::assertSame(['goods_in', 'transfer_out', 'transfer_in', 'count'], array_column($out, 'type'));
        self::assertSame([['line_index' => 1, 'result' => 'booked', 'sku_code' => sprintf('CW-%06d', $a), 'delta' => 5, 'on_hand' => 15],
            ['line_index' => 2, 'result' => 'booked', 'sku_code' => sprintf('CW-%06d', $b), 'delta' => 8, 'on_hand' => 8]], $out[0]['lines']);
        self::assertSame([5, 6, 7], array_column($out[3]['lines'], 'line_index'));
        self::assertSame(['counted', 'counted', 'counted'], array_column($out[3]['lines'], 'result'));
        $this->assertBal(15, 0, 0, $a);
        $this->assertBal(8, 0, 0, $b);
        $this->assertBal(16, 0, 0, $c);
        $this->assertBal(4, 0, 0, $c, 'VERIFY');
        $this->assertBal(7, 0, 0, $d, 'VERIFY');
        $this->assertBal(2, 0, 0, $e, 'VERIFY');

        $rows = self::$db->all(
            'SELECT sku_id, warehouse_id, movement_type, qty_delta, doc_ref, idem_key, actor, unit_cost, cost_currency, cost_source, document_id, document_line, note '
            . 'FROM stock_ledger WHERE document_id = ? ORDER BY id', [9001]);
        self::assertCount(6, $rows);
        self::assertSame(['ADJ-000001'], array_values(array_unique(array_column($rows, 'doc_ref'))));
        self::assertSame(['doc:9001:post'], array_values(array_unique(array_column($rows, 'idem_key'))));
        self::assertSame(['staff:1'], array_values(array_unique(array_column($rows, 'actor'))));
        $short = array_map(static fn (array $r): array => [$r['movement_type'], (int) $r['qty_delta'], $r['unit_cost'], $r['cost_source'], $r['document_line']], $rows);
        self::assertSame([
            ['goods_in', 5, '1.250000', 'document', 1],
            ['goods_in', 8, '2.000000', 'document', 2],
            ['transfer_out', -4, null, null, 3],
            ['transfer_in', 4, null, null, 3],
            ['count', 4, null, null, null],          // lines 5 + 6 summed: no single document line
            ['count', 0, '0.500000', 'document', 7], // no difference: journalled, with its cost
        ], $short);
        self::assertSame('counted=7 ships_after=0 lines 5,6', $rows[4]['note']);
        self::assertSame(['GBP', 'GBP', null, null, null, 'GBP'], array_column($rows, 'cost_currency'));

        // One flush: one feed transaction, one feed row per item whose sellable availability changed (a, b, c at MAIN).
        self::assertSame($ticks + 1, $this->feedTicks());
        self::assertSame($feedRows + 3, (int) self::$db->value('SELECT COUNT(*) FROM stock_change'));
        self::assertSame(6, (int) self::$db->value('SELECT COUNT(*) FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE l.document_id = 9001'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key = 'doc:9001:post'"), 'the posting has no idempotency row');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE idem_key = 'doc:9001:post'"), 'the posting audits itself');
        $countedAt = self::$db->column('SELECT counted_at FROM stock_balance WHERE warehouse_id = ? AND sku_id IN (?, ?) ORDER BY sku_id', [self::warehouseId('VERIFY'), $d, $e]);
        self::assertSame(['2026-09-26 17:30:00.000000', '2026-09-26 17:30:00.000000'], $countedAt);

        // A second booking of the same document is a handler bug.
        try {
            $this->inTx(fn (): array => $this->moves->bookForDocument(self::staff(), self::DOC, 'doc:9001:post',
                [['type' => 'goods_in', 'lines' => [['document_line' => 1, 'sku_id' => $a, 'qty' => 1]]]]));
            self::fail('a document booked its stock twice');
        } catch (\LogicException $ex) {
            self::assertStringContainsString('already booked stock', $ex->getMessage());
        }

        // The reversal: the exact negation, costs and lines mirrored, counted_at untouched.
        self::assertSame('ADJ-000002', FixtureDocuments::posted(self::$db, 9002, 'ADJ', 2, 9001));
        $ticks = $this->feedTicks();
        $n = $this->inTx(fn (): int => $this->moves->reverseDocument(self::staff(), 9001, ['document_id' => 9002, 'doc_ref' => 'ADJ-000002'], 'doc:9002:reverse'));
        self::assertSame(6, $n);
        foreach ($before as $s => [$main, $verify]) {
            self::assertSame($main, $this->bal($s), "MAIN:{$s} restored");
            self::assertSame($verify, $this->bal($s, 'VERIFY'), "VERIFY:{$s} restored");
        }
        $rev = self::$db->all(
            'SELECT movement_type, qty_delta, unit_cost, cost_source, document_line, doc_ref, idem_key, note, effective_at '
            . 'FROM stock_ledger WHERE document_id = ? ORDER BY id', [9002]);
        self::assertSame([
            ['goods_in', -5, '1.250000', 'document', 1], ['goods_in', -8, '2.000000', 'document', 2],
            ['transfer_out', 4, null, null, 3], ['transfer_in', -4, null, null, 3],
            ['count', -4, null, null, null], ['count', 0, '0.500000', 'document', 7],
        ], array_map(static fn (array $r): array => [$r['movement_type'], (int) $r['qty_delta'], $r['unit_cost'], $r['cost_source'], $r['document_line']], $rev));
        self::assertSame(['ADJ-000002'], array_values(array_unique(array_column($rev, 'doc_ref'))));
        self::assertSame(['doc:9002:reverse'], array_values(array_unique(array_column($rev, 'idem_key'))));
        self::assertSame(['reversal of ADJ-000001'], array_values(array_unique(array_column($rev, 'note'))));
        self::assertSame([self::NOW], array_values(array_unique(array_column($rev, 'effective_at'))));
        self::assertSame($ticks + 1, $this->feedTicks(), 'one flush');
        self::assertSame($countedAt, self::$db->column('SELECT counted_at FROM stock_balance WHERE warehouse_id = ? AND sku_id IN (?, ?) ORDER BY sku_id',
            [self::warehouseId('VERIFY'), $d, $e]), 'a reversed count keeps the count time');

        try {
            $this->inTx(fn (): int => $this->moves->reverseDocument(self::staff(), 9001, ['document_id' => 9002, 'doc_ref' => 'ADJ-000002'], 'doc:9002:reverse'));
            self::fail('reversed twice');
        } catch (\LogicException $ex) {
            self::assertStringContainsString('booked once', $ex->getMessage());
        }
    }

    public function testADocumentWithoutStockRowsReversesToNothingWithoutLocks(): void
    {
        $this->item('strict', 3);
        $ticks = $this->feedTicks();
        $this->inTx(function (): void {
            self::assertSame(0, $this->moves->reverseDocument(self::staff(), 9999, ['document_id' => 10000, 'doc_ref' => 'ADJ-000010'], 'doc:10000:reverse'));
            foreach (['stock_balance', 'stock_value_clock', 'feed_clock'] as $t) {
                self::assertSame(0, $this->recordLocks($t), "no lock on {$t}");
            }
        });
        self::assertSame($ticks, $this->feedTicks());
    }

    public function testAnUnresolvedLineRefusesThePostingAndBooksNothing(): void
    {
        $a = $this->item('strict', 5);
        $ledger = $this->ledgerCount();
        $this->inTx(function () use ($a, $ledger): void {
            try {
                $this->moves->bookForDocument(self::staff(), self::DOC, 'doc:9001:post', [
                    ['type' => 'goods_in', 'lines' => [['document_line' => 1, 'sku_id' => $a, 'qty' => 5], ['document_line' => 2, 'sku_id' => 999_999_999, 'qty' => 1]]],
                    ['type' => 'adjustment', 'lines' => [['document_line' => 3, 'sku_code' => 'CW-NOPE01', 'qty' => -1]]],
                ]);
                self::fail('an unknown item was booked');
            } catch (CwException $e) {
                self::assertSame(['unresolved_line', 422], [$e->errorCode, $e->httpStatus]);
                self::assertSame(['lines' => [
                    ['movement' => 0, 'type' => 'goods_in', 'document_line' => 2, 'reason' => 'unknown_sku'],
                    ['movement' => 1, 'type' => 'adjustment', 'document_line' => 3, 'reason' => 'unknown_sku'],
                ]], $e->detail);
            }
            self::assertSame($ledger, $this->ledgerCount(), 'nothing booked');
            self::assertSame(0, $this->recordLocks('stock_balance'), 'nothing locked');
        });
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM goods_in_suspense'), 'a document never parks lines in suspense');
        $this->assertBal(5, 0, 0, $a);
    }

    public function testWhoAndWhatMayBeBooked(): void
    {
        $a = $this->item('strict', 5);
        FixtureDocuments::posted(self::$db, 9001, 'ADJ', 1);
        $site = $this->site();
        $book = fn (Caller $caller, array $movements, array $doc = self::DOC): array => $this->inTx(
            fn (): array => $this->moves->bookForDocument($caller, $doc, 'doc:9001:post', $movements));
        $line = ['document_line' => 1, 'sku_id' => $a, 'qty' => 1];
        foreach ([
            'channel caller' => [fn () => $book($site, [['type' => 'goods_in', 'lines' => [$line]]]), 'staff_only', 403],
            'erp_sale' => [fn () => $book(self::staff(), [['type' => 'erp_sale', 'lines' => [$line]]]), 'bad_type', 400],
            'no document_line' => [fn () => $book(self::staff(), [['type' => 'goods_in', 'lines' => [['sku_id' => $a, 'qty' => 1]]]]), 'bad_lines', 400],
            'document_line 0' => [fn () => $book(self::staff(), [['type' => 'goods_in', 'lines' => [['document_line' => 0] + $line]]]), 'bad_lines', 400],
            'duplicate document_line' => [fn () => $book(self::staff(), [['type' => 'goods_in', 'lines' => [$line, $line]]]), 'bad_lines', 400],
            'variant line' => [fn () => $book(self::staff(), [['type' => 'goods_in', 'lines' => [['document_line' => 1, 'variant_id' => 'V1', 'qty' => 1]]]]), 'bad_lines', 400],
            'no movements' => [fn () => $book(self::staff(), []), 'bad_movements', 400],
            '21 movements' => [fn () => $book(self::staff(), array_fill(0, 21, ['type' => 'goods_in', 'lines' => [$line]])), 'bad_movements', 400],
            'cost on a transfer' => [fn () => $book(self::staff(), [['type' => 'transfer_in', 'lines' => [$line + ['unit_cost' => '1']]]]), 'cost_not_allowed', 400],
            'cost on a trade_sale' => [fn () => $book(self::staff(), [['type' => 'trade_sale', 'lines' => [$line + ['unit_cost' => '1']]]]), 'cost_not_allowed', 400],
            'count too old' => [fn () => $book(self::staff(), [['type' => 'count', 'counted_at' => '2026-09-20T10:00:00Z', 'lines' => [$line]]]), 'bad_time', 400],
        ] as $label => [$call, $code, $status]) {
            try {
                $call();
                self::fail("{$label} was accepted");
            } catch (CwException $e) {
                self::assertSame([$code, $status], [$e->errorCode, $e->httpStatus], $label . ': ' . $e->getMessage());
            }
        }
        try {
            $book(self::staff(), [['type' => 'goods_in', 'lines' => [$line]]], ['document_id' => 0, 'doc_ref' => 'X']);
            self::fail('document id 0 was accepted');
        } catch (\InvalidArgumentException) {
        }
        $this->assertBal(5, 0, 0, $a);

        // A backdated count (staff typing in a paper sheet) and a trade issue are fine.
        $out = $book(self::staff(), [
            ['type' => 'count', 'warehouse' => 'VERIFY', 'counted_at' => '2026-09-20T10:00:00Z', 'backdated' => true, 'lines' => [['document_line' => 1, 'sku_id' => $a, 'qty' => 2]]],
            ['type' => 'trade_sale', 'lines' => [['document_line' => 2, 'sku_id' => $a, 'qty' => 3]]],
        ]);
        self::assertSame(['count', 'trade_sale'], array_column($out, 'type'));
        $this->assertBal(2, 0, 0, $a);
        $this->assertBal(2, 0, 0, $a, 'VERIFY');
        self::assertSame(-3, (int) self::$db->value("SELECT qty_delta FROM stock_ledger WHERE movement_type = 'trade_sale'"));
    }

    public function testAStrictItemPushedBelowZeroIsFlaggedOncePerOperationKey(): void
    {
        $s = $this->item('strict', 10);
        $main = self::warehouseId('MAIN');
        $writeOff = fn (int $docId, int $qty): array => $this->inTx(fn (): array => $this->moves->bookForDocument(self::staff(),
            ['document_id' => $docId, 'doc_ref' => FixtureDocuments::posted(self::$db, $docId, 'WO', $docId - 9100)], 'doc:9101:post',
            [['type' => 'write_off', 'lines' => [['document_line' => 1, 'sku_id' => $s, 'qty' => $qty]]]]));
        $writeOff(9101, 15);
        $ev = self::$db->all('SELECT kind, shortfall, available_after, dedupe_key FROM oversell_event WHERE sku_id = ?', [$s]);
        self::assertSame([['kind' => 'movement_short', 'shortfall' => 5, 'available_after' => -5,
            'dedupe_key' => "movement_short:staff:1:doc:9101:post:{$main}:{$s}"]], $ev);
        // The same operation key again (a retried posting) adds no second event for that balance.
        $writeOff(9102, 1);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM oversell_event WHERE sku_id = ?', [$s]));
        $this->assertBal(-6, 0, 0, $s);
    }
}
