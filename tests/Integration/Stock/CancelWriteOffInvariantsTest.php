<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Invariants;
use CW\Tests\Support\StockTestCase;

/**
 * Invariants 12-13 (CW-1180, docs/decisions.md NR6): the write-off of a not-restockable cancel and its take-back by an uncancel report
 * each kind of corruption. The corruption is written with the admin connection, so this class checks the invariants itself instead of
 * asserting them clean after every test.
 */
final class CancelWriteOffInvariantsTest extends StockTestCase
{
    private int $sku;
    private int $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $site = $this->site();
        $this->channel = (int) $site->channelId;
        $this->sku = $this->item('strict', 0);
        $this->book('goods_in', $this->sku, 10, 'MAIN', null, '2.04');
        $this->listing($site, 'V1', $this->sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->ok($this->res->cancel($site, '1', ['a', 'b'], false, 'cnl'));
        $this->ok($this->res->uncancel($site, '1', ['a'], 'unc'));
        self::assertSame([], Invariants::check(self::$db), 'clean before the corruption');
    }

    protected function assertPostConditions(): void
    {
    }

    private function assertReports(string $needle): void
    {
        $v = Invariants::check(self::$db);
        $hit = array_filter($v, static fn (string $s): bool => str_contains($s, $needle));
        self::assertNotSame([], $hit, "expected a violation containing \"{$needle}\", got: " . json_encode($v));
    }

    public function testAWriteOffAtAnotherWarehouseIsReported(): void
    {
        self::$db->exec('INSERT IGNORE INTO stock_balance (warehouse_id, sku_id) VALUES (?, ?)', [self::warehouseId('VERIFY'), $this->sku]);
        self::$db->exec("UPDATE stock_ledger SET warehouse_id = ? WHERE idem_key = 'cnl' AND unit_id = 'b' AND movement_type = 'write_off'", [self::warehouseId('VERIFY')]);
        self::$db->exec('UPDATE stock_balance SET on_hand = on_hand + 1 WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId('MAIN'), $this->sku]);
        self::$db->exec('UPDATE stock_balance SET on_hand = on_hand - 1 WHERE warehouse_id = ? AND sku_id = ?', [self::warehouseId('VERIFY'), $this->sku]);
        $this->assertReports("unit {$this->channel}:b: its cancel_not_restockable write-off under key cnl is not one on_hand row of its own item, warehouse and size "
            . 'with its own cancel (1 rows, net -1, 1 elsewhere');
    }

    public function testAWriteOffOfAnotherSizeIsReported(): void
    {
        self::$db->exec("UPDATE stock_ledger SET qty_delta = -2 WHERE idem_key = 'cnl' AND unit_id = 'b' AND movement_type = 'write_off'");
        $this->assertReports("unit {$this->channel}:b: its cancel_not_restockable write-off under key cnl is not one on_hand row of its own item, warehouse and size "
            . 'with its own cancel (1 rows, net -2');
    }

    public function testAWriteOffWithoutItsCancelIsReported(): void
    {
        self::$db->exec("UPDATE stock_ledger SET idem_key = 'other' WHERE idem_key = 'cnl' AND unit_id = 'b' AND movement_type = 'cancel'");
        $this->assertReports("unit {$this->channel}:b: its cancel_not_restockable write-off under key cnl is not one on_hand row of its own item, warehouse and size "
            . 'with its own cancel (1 rows, net -1, 0 elsewhere, 0 take-backs with a cost, its cancel 0');
    }

    public function testATakeBackWithACostIsReported(): void
    {
        self::$db->exec("UPDATE stock_ledger SET unit_cost = 2.04, cost_currency = 'GBP', cost_source = 'estimate' WHERE idem_key = 'unc' AND movement_type = 'write_off'");
        $this->assertReports("unit {$this->channel}:a: its uncancel_from_write_off write-off under key unc is not one on_hand row of its own item, warehouse and size "
            . 'with its own uncancel (1 rows, net 1, 0 elsewhere, 1 take-backs with a cost');
    }

    public function testMoreTakenBackThanWrittenOffIsReported(): void
    {
        self::$db->exec("UPDATE stock_ledger SET note = 'by hand' WHERE idem_key = 'cnl' AND unit_id = 'a' AND movement_type = 'write_off'");
        $this->assertReports("unit {$this->channel}:a: its write-offs of item {$this->sku} net 1 (more taken back than written off)");
    }
}
