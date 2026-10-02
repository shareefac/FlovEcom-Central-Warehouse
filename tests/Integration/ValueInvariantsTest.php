<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Caller;
use CW\Invariants;
use CW\Movements;
use CW\Tests\Support\IntegrationTestCase;

/**
 * Invariants 7-9 (the per-item value sequence, I3) report each kind of corruption. The corruption is
 * written with the admin connection; this class has no invariant post-condition (IntegrationTestCase).
 */
final class ValueInvariantsTest extends IntegrationTestCase
{
    private int $sku;
    private int $other;
    /** @var list<int> the item's on_hand ledger ids at MAIN, in seq order (seq 1, 2, 3) */
    private array $rows;

    protected function setUp(): void
    {
        parent::setUp();
        $moves = new Movements(self::$db);
        $this->sku = self::makeSku('Seq item');
        $this->other = self::makeSku('Other item');
        foreach ([[$this->sku, 5], [$this->sku, 3], [$this->other, 2], [$this->sku, -1]] as $i => [$sku, $qty]) {
            $r = $moves->record(Caller::staff(1), ['type' => 'adjustment', 'doc_ref' => 'INV-' . $i, 'lines' => [['sku_id' => $sku, 'qty' => $qty]]], 'inv-' . $i);
            self::assertSame(200, $r->status);
        }
        $this->rows = array_map('intval', self::$db->column(
            "SELECT id FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand' ORDER BY id", [$this->sku]));
        self::assertCount(3, $this->rows);
        self::assertSame([], Invariants::check(self::$db), 'clean before the corruption');
    }

    private function assertReports(string $needle): void
    {
        $v = Invariants::check(self::$db);
        $hit = array_filter($v, static fn (string $s): bool => str_contains($s, $needle));
        self::assertNotSame([], $hit, "expected a violation containing \"{$needle}\", got: " . json_encode($v));
    }

    public function testASeqRowDeleted(): void
    {
        self::$db->exec('DELETE FROM stock_value_seq WHERE stock_ledger_id = ?', [$this->rows[2]]);
        $this->assertReports("ledger row {$this->rows[2]} (adjustment, on_hand of ");
        $this->assertReports("item {$this->sku}: value clock at 3 but its highest value seq is 2");
    }

    public function testAGappedSeq(): void
    {
        self::$db->exec('UPDATE stock_value_seq SET seq = 5 WHERE sku_id = ? AND seq = 3', [$this->sku]);
        $this->assertReports("item {$this->sku}: value seqs 1..5 in 3 rows (expected 1..3, no gap)");
    }

    public function testAnExtraSeqPointingAtNoRow(): void
    {
        self::$db->exec('INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id) VALUES (?, 4, 999999999)', [$this->sku]);
        $this->assertReports("value seq {$this->sku}#4 points at ledger row 999999999, which does not exist");
        $this->assertReports("item {$this->sku}: value clock at 3 but its highest value seq is 4");
    }

    public function testAClockOffByOne(): void
    {
        self::$db->exec('UPDATE stock_value_clock SET last_seq = last_seq + 1 WHERE sku_id = ?', [$this->sku]);
        $this->assertReports("item {$this->sku}: value clock at 4 but its highest value seq is 3");
    }

    public function testSeqRowsWithoutAClock(): void
    {
        self::$db->exec('DELETE FROM stock_value_clock WHERE sku_id = ?', [$this->sku]);
        $this->assertReports("item {$this->sku} has 3 value seqs but no value clock");
    }

    public function testASeqInvertedWithinOneBalance(): void
    {
        // Swap the seqs of the first two rows of MAIN:sku (through a free number: (sku_id, seq) is the primary key).
        self::$db->exec('UPDATE stock_value_seq SET seq = 99 WHERE sku_id = ? AND seq = 1', [$this->sku]);
        self::$db->exec('UPDATE stock_value_seq SET seq = 1 WHERE sku_id = ? AND seq = 2', [$this->sku]);
        self::$db->exec('UPDATE stock_value_seq SET seq = 2 WHERE sku_id = ? AND seq = 99', [$this->sku]);
        $main = self::warehouseId('MAIN');
        $this->assertReports("balance {$main}:{$this->sku}: ledger row {$this->rows[1]} has value seq 1, not after the previous row's 2");
    }

    public function testASeqPointingAtAHeldRow(): void
    {
        $main = self::warehouseId('MAIN');
        $held = self::$db->insert(
            "INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, actor) VALUES (?, ?, 'held', 1, 1, 'reserve', 'system:test')",
            [$main, $this->sku],
        );
        self::$db->exec('UPDATE stock_value_seq SET stock_ledger_id = ? WHERE stock_ledger_id = ?', [$held, $this->rows[2]]);
        $this->assertReports("value seq {$this->sku}#3 points at ledger row {$held}, which is in bucket held of item {$this->sku}");
        $this->assertReports("ledger row {$this->rows[2]} (adjustment, on_hand of {$main}:{$this->sku}) has no value seq");
    }

    public function testASeqOfAnotherItemsRow(): void
    {
        $otherRow = (int) self::$db->value("SELECT id FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand'", [$this->other]);
        self::$db->exec('UPDATE stock_value_seq SET sku_id = ?, seq = 4 WHERE stock_ledger_id = ?', [$this->sku, $otherRow]);
        $this->assertReports("value seq {$this->sku}#4 points at ledger row {$otherRow}, which is in bucket on_hand of item {$this->other}");
    }
}
