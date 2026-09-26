<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\CwException;
use CW\Tests\Support\StockTestCase;

/** Paid units after the commit: ship, unship, cancel (restockable or not), return (§3, §14). */
final class UnitLifecycleTest extends StockTestCase
{
    public function testShipTakesUnitsOutOfOnHandAndAllocatedAndUnshipPutsThemBack(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $before = $this->view($site, 'V1')['available'];

        $r = $this->ship($site, '1', ['a', 'b'], '2026-09-26T13:00:00+01:00');
        self::assertSame(['shipped', 'shipped'], array_column($r->body['units'], 'result'));
        $this->assertBal(3, 0, 0, $sku);
        self::assertSame($before, $this->view($site, 'V1')['available'], 'dispatch does not change availability');
        self::assertSame('2026-09-26 12:00:00.000000', self::$db->value("SELECT dispatched_at FROM reservation_unit WHERE unit_id = 'a'"), 'London -> UTC');

        $r = $this->res->unship($site, '1', ['a'], null, $this->key('unship'));
        self::assertSame('unshipped', $r->body['units'][0]['result']);
        $this->assertBal(4, 1, 0, $sku);
        self::assertSame('allocated', $this->unitState($site, 'a'));
        $r = $this->res->unship($site, '1', ['a'], null, $this->key('unship'));
        self::assertSame('not_shipped', $r->body['units'][0]['result']);
        $this->assertBal(4, 1, 0, $sku);

        $r = $this->ship($site, '1', ['a', 'b']);
        self::assertSame(['shipped', 'already_shipped'], array_column($r->body['units'], 'result'));
        $this->assertBal(3, 0, 0, $sku);
    }

    public function testCancelRestockablePutsTheUnitsBackOnSale(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '2', [self::line('V1', 'a', 'b')]);
        self::assertSame(3, $this->view($site, 'V1')['available']);

        $r = $this->res->cancel($site, '2', ['a'], true, $this->key('cancel'));
        self::assertSame('cancelled', $r->body['units'][0]['result']);
        $this->assertBal(5, 1, 0, $sku);
        self::assertSame(4, $this->view($site, 'V1')['available']);
        $r = $this->res->cancel($site, '2', ['a', 'zz'], true, $this->key('cancel'));
        self::assertSame(['already_cancelled', 'unknown_unit'], array_column($r->body['units'], 'result'));
        $this->assertBal(5, 1, 0, $sku);
    }

    public function testCancelNotRestockableParksTheUnitsInVerifyWithARecountAndNoWriteOff(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '3', [self::line('V1', 'a', 'b')]);

        $r = $this->res->cancel($site, '3', ['a', 'b'], false, $this->key('cancel'));
        self::assertSame(['cancelled_to_verify', 'cancelled_to_verify'], array_column($r->body['units'], 'result'));
        $this->assertBal(3, 0, 0, $sku);
        $this->assertBal(2, 0, 0, $sku, 'VERIFY');
        self::assertSame(3, $this->view($site, 'V1')['available'], 'doubtful units are not put back on sale');
        self::assertSame(2, self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'verify_recount' AND warehouse_id = ? AND status = 'open'", [self::warehouseId('VERIFY')]));
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE movement_type = 'write_off'"));

        // later a person confirms one unit is really gone and the other is back on the shelf
        $this->book('write_off', $sku, 1, 'VERIFY');
        $this->ok($this->moves->record(self::staff(), ['type' => 'transfer_out', 'warehouse' => 'VERIFY', 'lines' => [['sku_id' => $sku, 'qty' => 1]]], $this->key('t')));
        $this->ok($this->moves->record(self::staff(), ['type' => 'transfer_in', 'warehouse' => 'MAIN', 'lines' => [['sku_id' => $sku, 'qty' => 1]]], $this->key('t')));
        $this->assertBal(0, 0, 0, $sku, 'VERIFY');
        $this->assertBal(4, 0, 0, $sku);
    }

    public function testAHeldUnitCanBeCancelledAndAShippedOneCannot(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '4', [self::line('V1', 'a')]);
        $r = $this->res->cancel($site, '4', ['a'], false, $this->key('cancel'));
        self::assertSame('cancelled', $r->body['units'][0]['result'], 'an unpaid unit never left: nothing to verify');
        $this->assertBal(5, 0, 0, $sku);

        $this->commit($site, '5', [self::line('V1', 'b')]);
        $this->ship($site, '5', ['b']);
        $r = $this->res->cancel($site, '5', ['b'], true, $this->key('cancel'));
        self::assertSame('shipped', $r->body['units'][0]['result']);
        $this->assertBal(4, 0, 0, $sku);
    }

    public function testAReturnAfterARefundIsCountedOnce(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '6', [self::line('V1', 'a', 'b')]);
        $this->ship($site, '6', ['a', 'b']);
        $this->assertBal(3, 0, 0, $sku);

        // refund with restock after dispatch (key return:<ordi_id>) ...
        $r1 = $this->res->returnUnits($site, '6', ['a'], 'return:a');
        self::assertSame('returned', $r1->body['units'][0]['result']);
        $this->assertBal(4, 0, 0, $sku);
        // ... the same event replayed by the other path with the same key ...
        $r2 = $this->res->returnUnits($site, '6', ['a'], 'return:a');
        self::assertTrue($r2->replayed);
        self::assertEquals($r1->body, $r2->body);
        // ... and the warehouse's return receipt under another key: still one unit back
        $r3 = $this->res->returnUnits($site, '6', ['a', 'b'], $this->key('receipt'));
        self::assertSame(['already_returned', 'returned'], array_column($r3->body['units'], 'result'));
        $this->assertBal(5, 0, 0, $sku);
        self::assertSame('returned', $this->unitState($site, 'a'));
    }

    public function testAReturnOfAnUndispatchedUnitChangesNothing(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '7', [self::line('V1', 'a')]);
        $this->res->cancel($site, '7', ['a'], true, $this->key('cancel'));
        $r = $this->res->returnUnits($site, '7', ['a'], 'return:a');
        self::assertSame('not_shipped', $r->body['units'][0]['result']);
        $this->assertBal(5, 0, 0, $sku);
    }

    public function testAShipBeforeItsCommitIsNotStoredSoTheSameKeyWorksAfterTheCommit(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '8', [self::line('V1', 'a')]);
        try {
            $this->ship($site, '8', ['a'], '2026-09-26T12:00:00Z', 'ship:a');
            self::fail('a ship of an unpaid unit must wait for its commit');
        } catch (CwException $e) {
            self::assertSame(['not_committed', 409], [$e->errorCode, $e->httpStatus]);
        }
        $this->commit($site, '8', [self::line('V1', 'a')]);
        $r = $this->ship($site, '8', ['a'], '2026-09-26T12:00:00Z', 'ship:a');
        self::assertSame(['shipped', false], [$r->body['units'][0]['result'], $r->replayed]);
        $this->assertBal(4, 0, 0, $sku);
    }

    public function testShipUnshipAndReturnWaitForTheCommitOfAReleasedOrder(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '9', [self::line('V1', 'a')]);
        $this->release($site, '9', 1);
        foreach (['ship', 'unship', 'return'] as $op) {
            try {
                match ($op) {
                    'ship' => $this->ship($site, '9', ['a'], '2026-09-26T12:00:00Z', 'k-' . $op),
                    'unship' => $this->res->unship($site, '9', ['a'], null, 'k-' . $op),
                    'return' => $this->res->returnUnits($site, '9', ['a'], 'k-' . $op),
                };
                self::fail("{$op} of an unpaid order must wait for its commit");
            } catch (CwException $e) {
                self::assertSame(['not_committed', 409], [$e->errorCode, $e->httpStatus], $op);
            }
        }
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key LIKE 'k-%'"));
        // the payment is captured late: the commit works without a hold and the ship goes through
        $this->commit($site, '9', [self::line('V1', 'a')]);
        self::assertSame('shipped', $this->ship($site, '9', ['a'], '2026-09-26T12:00:00Z', 'k-ship')->body['units'][0]['result']);
        $this->assertBal(4, 0, 0, $sku);
    }

    public function testUnitOperationsOnAnUnknownOrderAreNotStored(): void
    {
        $site = $this->site();
        $this->expectExceptionObject(new CwException('unknown_order', 'CW has no reservation for this order yet', 404));
        $this->ship($site, 'nope', ['a']);
    }
}
