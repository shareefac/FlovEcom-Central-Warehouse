<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\CwException;
use CW\Tests\Support\StockTestCase;

/** Starting a site at T0 (§8.1): opening orders and the opening on_hand estimate. */
final class OpeningOrdersTest extends StockTestCase
{
    /** §8.1 watermarks as a site sends them (R12). */
    private const T0 = ['at' => '2026-09-26T17:00:00Z', 'last_order_id' => 900, 'last_stock_log_id' => 5501];

    public function testOpeningOrdersBecomeAllocatedOnceAndTheirLaterDispatchBalances(): void
    {
        $site = $this->site('vpg', 'shadow');
        $sku = $this->item('legacy', 0);
        $ten = $this->item('legacy', 0);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'PACK', $ten, 10);
        $this->listing($site, 'UN', null);

        // an order paid through the normal path just before the opening batch arrives
        $this->commit($site, '900', [self::line('V1', 'n1')]);

        $first = $this->res->openingOrders($site, [
            ['order_ref' => 901, 'lines' => [self::line('V1', 'a1', 'a2'), self::line('PACK', 'a3')]],
            ['order_ref' => '900', 'lines' => [self::line('V1', 'n1')]],
        ], false, 'opening-1');
        self::assertSame(['accepted', 2, 1, 1], [$first->body['result'], $first->body['orders'], $first->body['committed'], $first->body['already_committed']]);
        $second = $this->res->openingOrders($site, [
            ['order_ref' => '902', 'lines' => [self::line('V1', 'b1'), self::line('UN', 'b2')]],
        ], true, 'opening-2', self::T0);
        self::assertSame(['accepted', true], [$second->body['result'], $second->body['final']]);
        self::assertSame(['at' => '2026-09-26T17:00:00.000000Z', 'last_order_id' => 900, 'last_stock_log_id' => 5501], $second->body['t0']);

        self::assertSame('opening', $this->reservation($site, '901')['origin']);
        self::assertSame('reserved', $this->reservation($site, '900')['origin'], 'the normal commit is kept');
        self::assertNotNull(self::$db->value('SELECT opening_orders_at FROM channel_opening WHERE channel_id = ?', [$site->channelId]));
        $this->assertBal(0, 4, 0, $sku);
        $this->assertBal(0, 10, 0, $ten);
        self::assertNull(self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = 'b2'"), 'unlinked opening units are recorded only');
        self::assertSame(0, self::$db->value('SELECT COUNT(*) FROM oversell_event'), 'legacy items are never flagged');

        // once final, the call is refused (stored, like any domain answer)
        $again = $this->res->openingOrders($site, [['order_ref' => '903', 'lines' => [self::line('V1', 'c1')]]], true, 'opening-3');
        self::assertSame([409, 'opening_orders_done'], [$again->status, $again->body['error']]);
        self::assertNull($this->reservation($site, '903'));

        // opening on_hand estimate (§8.1): the site's own stock (5 + 2 packs) + its open paid units
        $this->ok($this->moves->record(self::staff(), ['type' => 'adjustment', 'note' => 'opening estimate', 'lines' => [
            ['sku_id' => $sku, 'qty' => 5 + 4], ['sku_id' => $ten, 'qty' => 20 + 10]]], $this->key('estimate')));
        self::assertSame(5, $this->view($site, 'V1')['available']);
        self::assertSame(2, $this->view($site, 'PACK')['available']);

        // the opening units ship later: allocated returns to 0 and on_hand ends at the site's own figure
        $this->ship($site, '901', ['a1', 'a2', 'a3']);
        $this->ship($site, '902', ['b1', 'b2']);
        $this->ship($site, '900', ['n1']);
        $this->assertBal(5, 0, 0, $sku);
        $this->assertBal(20, 0, 0, $ten);
    }

    public function testAStrictItemShortAtT0IsFlaggedAsAnOpeningShortfall(): void
    {
        $site = $this->site('vpg', 'shadow');
        $sku = $this->item('strict', 1);
        $this->listing($site, 'V1', $sku);
        $r = $this->res->openingOrders($site, [['order_ref' => '1', 'lines' => [self::line('V1', 'a', 'b', 'c')]]], true, 'op', self::T0);
        self::assertSame(1, $r->body['oversell_events']);
        self::assertSame(['opening_short', 2], array_values(self::$db->one('SELECT kind, shortfall FROM oversell_event')));
        $this->assertBal(1, 3, 0, $sku);
    }

    public function testAUnitInTwoOpeningOrdersIsRejectedBeforeAnythingIsStored(): void
    {
        $site = $this->site('vpg', 'shadow');
        $this->listing($site, 'V1', $this->item('legacy', 0));
        try {
            $this->res->openingOrders($site, [
                ['order_ref' => '1', 'lines' => [self::line('V1', 'a')]],
                ['order_ref' => '2', 'lines' => [self::line('V1', 'a')]],
            ], true, 'dup', self::T0);
            self::fail('a unit id can belong to one order only');
        } catch (CwException $e) {
            self::assertSame('duplicate_unit', $e->errorCode);
        }
        self::assertSame(0, self::$db->value('SELECT COUNT(*) FROM idempotency'));
        self::assertNull(self::$db->value('SELECT opening_orders_at FROM channel_opening'));
    }

    /**
     * §8.1 "Record watermarks" (R12): T0 is stored once, by any batch; other values later are a
     * stored 409; the final batch cannot close the opening while no T0 is recorded.
     */
    public function testT0WatermarksAreRecordedOnceAndTheFinalBatchNeedsThem(): void
    {
        $site = $this->site('vpg', 'shadow');
        $this->listing($site, 'V1', $this->item('legacy', 0));
        try {
            $this->res->openingOrders($site, [['order_ref' => '1', 'lines' => [self::line('V1', 'a')]]], true, 'final-no-t0');
            self::fail('the final batch needs T0');
        } catch (CwException $e) {
            self::assertSame([409, 't0_required'], [$e->httpStatus, $e->errorCode]);
        }
        self::assertNull($this->reservation($site, '1'), 'nothing booked');

        // a non-final batch may carry T0 (or not)
        $this->ok($this->res->openingOrders($site, [['order_ref' => '1', 'lines' => [self::line('V1', 'a')]]], false, 'b1'));
        $r = $this->ok($this->res->openingOrders($site, [['order_ref' => '2', 'lines' => [self::line('V1', 'b')]]], false, 'b2', self::T0));
        self::assertSame(900, $r->body['t0']['last_order_id']);
        $row = self::$db->one('SELECT t0_at, t0_last_order_id, t0_last_stock_log_id, opening_orders_at FROM channel_opening WHERE channel_id = ?', [$site->channelId]);
        self::assertSame(['2026-09-26 17:00:00.000000', 900, 5501, null], array_values($row));

        // other values later: a stored 409, nothing booked
        $bad = $this->res->openingOrders($site, [['order_ref' => '3', 'lines' => [self::line('V1', 'c')]]], false, 'b3',
            ['last_order_id' => 901] + self::T0);
        self::assertSame([409, 't0_mismatch', 900], [$bad->status, $bad->body['error'], $bad->body['t0']['last_order_id']]);
        self::assertNull($this->reservation($site, '3'));
        // bad shapes and implausible times are refused before anything is stored
        foreach ([['at' => '2026-09-26T17:00:00Z'], ['last_order_id' => -1] + self::T0, ['at' => '2027-01-01T00:00:00Z'] + self::T0,
            ['last_stock_log_id' => '5'] + self::T0] as $i => $t0) {
            try {
                $this->res->openingOrders($site, [['order_ref' => '9', 'lines' => [self::line('V1', 'z')]]], false, "bad-t0-{$i}", $t0);
                self::fail("t0 {$i} should be refused");
            } catch (CwException $e) {
                self::assertSame(400, $e->httpStatus, json_encode($e->body()));
            }
        }
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key LIKE 'bad-t0-%'"));
        // T0 recorded earlier: the final batch may omit it, or repeat it
        $fin = $this->ok($this->res->openingOrders($site, [['order_ref' => '3', 'lines' => [self::line('V1', 'c')]]], true, 'b4', self::T0));
        self::assertTrue($fin->body['final']);
        $this->assertBal(0, 3, 0, (int) self::$db->value("SELECT sku_id FROM channel_listing WHERE external_variant_id = 'V1'"));
    }
}
