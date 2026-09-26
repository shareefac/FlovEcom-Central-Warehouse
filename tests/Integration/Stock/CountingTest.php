<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Purchasing;
use CW\Tests\Support\StockTestCase;

/** Counting and the switch to protection (§8.2, §12; §14 "bucket arithmetic"). */
final class CountingTest extends StockTestCase
{
    public function testLegacyItemWithOpenPaidOrdersIsCountedProtectedAndShippedWithAllocatedBackToZero(): void
    {
        $site = $this->site();
        $sku = $this->item('legacy', 0);
        $this->listing($site, 'V1', $sku);
        // opening estimate (§8.1): never shown to a customer
        $this->ok($this->moves->record(self::staff(), ['type' => 'adjustment', 'note' => 'opening estimate',
            'lines' => [['sku_id' => $sku, 'qty' => 4]]], $this->key('open')));

        // linked-legacy sales move the buckets but are never refused (available goes to -1)
        $this->reserve($site, '101', [self::line('V1', 'a', 'b')]);
        $this->commit($site, '101', [self::line('V1', 'a', 'b')]);
        $this->commit($site, '102', [self::line('V1', 'c', 'd', 'e')]);
        $this->assertBal(4, 5, 0, $sku);
        self::assertSame('legacy', $this->view($site, 'V1')['state']);

        // the count: 5 paid units still on the shelf + 2 free
        $r = $this->book('count', $sku, 7, 'MAIN', '2026-09-26T11:59:00Z');
        self::assertSame(['counted', 3, 7], [$r->body['lines'][0]['result'], $r->body['lines'][0]['delta'], $r->body['lines'][0]['on_hand']]);
        $this->assertBal(7, 5, 0, $sku);

        // protected: every in-flight order is already inside `allocated`
        $this->ok($this->stock->setPolicy(self::staff(), $sku, 'strict', $this->key('policy')));
        self::assertSame(['in_stock', 2], [$this->view($site, 'V1')['state'], $this->view($site, 'V1')['available']]);
        self::assertSame(409, $this->reserve($site, '103', [self::line('V1', 'x1', 'x2', 'x3')])->status);

        $this->ship($site, '101', ['a', 'b'], '2026-09-26T12:10:00Z');
        $this->ship($site, '102', ['c', 'd', 'e'], '2026-09-26T12:20:00Z');
        $this->assertBal(2, 0, 0, $sku);
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE bucket = 'allocated' AND balance_after < 0"));
        self::assertSame(201, $this->reserve($site, '104', [self::line('V1', 'y1', 'y2')])->status);
        self::assertSame(0, $this->view($site, 'V1')['available']);
    }

    public function testALateShipDispatchedBeforeCountedAtOnlyReducesAllocated(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '201', [self::line('V1', 'a', 'b')]);

        // both units left at 11:00; the counter starts at 12:00 and finds 8; the ship report is late
        $this->book('count', $sku, 8, 'MAIN', '2026-09-26T12:00:00Z');
        $this->assertBal(8, 2, 0, $sku);
        $r = $this->ship($site, '201', ['a', 'b'], '2026-09-26T11:00:00Z');
        self::assertSame(['shipped_pre_count', 'shipped_pre_count'], array_column($r->body['units'], 'result'));
        $this->assertBal(8, 0, 0, $sku);
        self::assertSame(['pre_count', 'pre_count'], self::$db->column(
            "SELECT note FROM stock_ledger WHERE movement_type = 'ship' AND bucket = 'allocated' ORDER BY id"));
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE movement_type = 'ship' AND bucket = 'on_hand'"));
        self::assertSame(0, self::$db->value('SELECT COUNT(*) FROM count_review'), 'an hour before the count is not ambiguous');
    }

    public function testACountSubtractsShipsAlreadyAppliedThatWereDispatchedAfterCountedAt(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '301', [self::line('V1', 'a', 'b', 'c')]);

        // counter starts at 12:00 (counts 10: all three units are still on the shelf);
        // unit a is dispatched at 12:30 and reported before the count is submitted
        $this->ship($site, '301', ['a'], '2026-09-26T12:30:00Z');
        $this->assertBal(9, 2, 0, $sku);
        // unit b was dispatched at 12:20 and reset at 12:25 (net zero after counted_at)
        $this->ship($site, '301', ['b'], '2026-09-26T12:20:00Z');
        $this->res->unship($site, '301', ['b'], '2026-09-26T12:25:00Z', $this->key('unship'));
        $r = $this->book('count', $sku, 10, 'MAIN', '2026-09-26T12:00:00Z');
        self::assertSame(9, $r->body['lines'][0]['on_hand'], 'counted 10 minus the one unit that left after the count started');
        $this->assertBal(9, 2, 0, $sku);

        // units reported later with dispatch after counted_at reduce on_hand as usual
        $this->ship($site, '301', ['b', 'c'], '2026-09-26T12:40:00Z');
        $this->assertBal(7, 0, 0, $sku);
    }

    public function testADispatchResetBeforeCountedAtReportedAfterTheCountOnlyRestoresAllocated(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '350', [self::line('V1', 'a', 'b')]);

        // a leaves at 11:00 and b at 11:20 (reported at once); a's dispatch is reset at 11:30,
        // so a is back on the shelf when the counter starts at 12:00 and finds 9 (b is gone)
        $this->ship($site, '350', ['a'], '2026-09-26T11:00:00Z');
        $this->ship($site, '350', ['b'], '2026-09-26T11:20:00Z');
        $this->assertBal(8, 0, 0, $sku);
        $this->book('count', $sku, 9, 'MAIN', '2026-09-26T12:00:00Z');
        $this->assertBal(9, 0, 0, $sku);

        // the reset is reported only now: the count already has the unit, so only allocated moves
        $r = $this->res->unship($site, '350', ['a'], '2026-09-26T11:30:00Z', $this->key('unship'));
        self::assertSame('unshipped_pre_count', $r->body['units'][0]['result']);
        $this->assertBal(9, 1, 0, $sku);
        self::assertSame(8, $this->view($site, 'V1')['available']);

        // a reset after the count is a unit coming back to the shelf: on_hand rises too
        $r = $this->res->unship($site, '350', ['b'], '2026-09-26T12:30:00Z', $this->key('unship'));
        self::assertSame('unshipped', $r->body['units'][0]['result']);
        $this->assertBal(10, 2, 0, $sku);
        self::assertSame(0, self::$db->value('SELECT COUNT(*) FROM count_review'));

        // and a reset within ten minutes of the count is flagged for a person
        $this->ship($site, '350', ['a'], '2026-09-26T12:03:00Z');
        $this->res->unship($site, '350', ['a'], '2026-09-26T12:05:00Z', $this->key('unship'));
        self::assertSame(1, self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'unship_near_count'"));
    }

    public function testShipsWithinTenMinutesOfACountGoToCountReview(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '401', [self::line('V1', 'a', 'b', 'c')]);
        $this->book('count', $sku, 10, 'MAIN', '2026-09-26T12:00:00Z');

        $r = $this->ship($site, '401', ['a'], '2026-09-26T11:55:00Z'); // before: pre-count, but ambiguous
        self::assertSame('shipped_pre_count', $r->body['units'][0]['result']);
        $r = $this->ship($site, '401', ['b'], '2026-09-26T12:05:00Z'); // after: normal, but ambiguous
        self::assertSame('shipped', $r->body['units'][0]['result']);
        $this->ship($site, '401', ['c'], '2026-09-26T12:30:00Z');       // clearly after
        $this->assertBal(8, 0, 0, $sku);
        self::assertSame(2, self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'ship_near_count' AND status = 'open'"));
    }

    public function testACountNearAnAlreadyAppliedShipOpensAReview(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '402', [self::line('V1', 'a')]);
        $this->ship($site, '402', ['a'], '2026-09-26T12:03:00Z');
        $this->book('count', $sku, 9, 'MAIN', '2026-09-26T12:00:00Z');
        $this->assertBal(8, 0, 0, $sku);
        $review = self::$db->one("SELECT proposed_qty, detail FROM count_review WHERE source = 'count_near_ship'");
        self::assertNotNull($review);
        self::assertSame(9, $review['proposed_qty']);
        self::assertSame('a', json_decode((string) $review['detail'], true)['units'][0]['unit_id']);
    }

    public function testAnOlderCountThanTheLastOneIsIgnored(): void
    {
        $sku = $this->item('strict', 10);
        $this->book('count', $sku, 6, 'MAIN', '2026-09-26T12:00:00Z');
        $r = $this->book('count', $sku, 9, 'MAIN', '2026-09-26T11:00:00Z');
        self::assertSame('stale_count', $r->body['lines'][0]['result']);
        $this->assertBal(6, 0, 0, $sku);
        $r = $this->book('count', $sku, 6, 'MAIN', '2026-09-26T13:00:00Z');
        self::assertSame(['counted', 0], [$r->body['lines'][0]['result'], $r->body['lines'][0]['delta']]);
        self::assertSame(2, self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE movement_type = 'count'"), 'a count with no difference is still journalled; the stale one is not');
        self::assertSame('2026-09-26 13:00:00.000000', self::$db->value('SELECT counted_at FROM stock_balance WHERE sku_id = ?', [$sku]));
    }

    public function testNegativeOnHandOpensOneCountReviewWhileItIsOpen(): void
    {
        $site = $this->site();
        $sku = $this->item('legacy', 0);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '501', [self::line('V1', 'a', 'b')]);
        $this->ship($site, '501', ['a']);
        $this->ship($site, '501', ['b']);
        $this->assertBal(-2, 0, 0, $sku);
        self::assertSame(1, self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'negative_on_hand'"));

        self::$db->exec("UPDATE count_review SET status = 'resolved', resolution = 'counted', resolved_at = UTC_TIMESTAMP(6)");
        $this->book('erp_sale', $sku, 1);
        self::assertSame(2, self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'negative_on_hand'"));
        self::assertSame(1, self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'negative_on_hand' AND status = 'open'"));
    }

    /** R11, §3 GET /v1/purchasing "counted_at": the latest count at a sellable warehouse. */
    public function testPurchasingShowsWhenAnItemWasCounted(): void
    {
        $sku = $this->item('strict', 3);
        $never = $this->item('strict', 3);
        $this->book('count', $sku, 3, 'MAIN', '2026-09-26T11:00:00Z');
        $this->book('count', $never, 1, 'VERIFY', '2026-09-26T11:30:00Z'); // not a sellable location
        $rows = array_column((new Purchasing(self::$db))->report()['items'], 'counted_at', 'sku_id');
        self::assertSame(['2026-09-26T11:00:00.000000Z', null], [$rows[$sku], $rows[$never]]);
    }

    /**
     * R13: the count rule adjusts for ships only (§8.2); any other on_hand movement booked after
     * counted_at (goods-in, a return, an ERP sale, a cancel moved to VERIFY, ...) is overwritten
     * by the count, so it opens a count review listing those rows for a person to settle.
     */
    public function testACountOverwritingMovementsBookedAfterCountedAtOpensAReview(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->ship($site, '1', ['a'], '2026-09-26T11:00:00Z');
        $this->res->returnUnits($site, '1', ['a'], $this->key('return'));   // booked at 18:00
        $this->book('goods_in', $sku, 6);                                  // booked at 18:00
        $this->res->cancel($site, '1', ['b'], false, $this->key('cancel')); // MAIN -> VERIFY at 18:00
        $this->assertBal(15, 0, 0, $sku);

        $r = $this->book('count', $sku, 9, 'MAIN', '2026-09-26T17:00:00Z'); // started before all three
        self::assertSame(['counted', 9], [$r->body['lines'][0]['result'], $r->body['lines'][0]['on_hand']]);
        $review = self::$db->one("SELECT proposed_qty, detail FROM count_review WHERE source = 'count_after_movements'");
        self::assertNotNull($review);
        self::assertSame(9, $review['proposed_qty']);
        $detail = json_decode((string) $review['detail'], true);
        self::assertSame(['return', 'goods_in', 'transfer_out'], array_column($detail['rows'], 'movement_type'));
        self::assertSame(6, $detail['net']);
        // a count after them opens nothing
        $this->book('count', $sku, 9, 'MAIN', '2026-09-26T18:00:00Z');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'count_after_movements'"));
    }
}
