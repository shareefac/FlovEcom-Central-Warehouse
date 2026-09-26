<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\ApiTestCase;

/** POST /v1/reservations/*, /v1/opening_orders over HTTP: happy paths and the main refusals. */
final class ApiReservationsTest extends ApiTestCase
{
    public function testASaleEndToEnd(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V10', $sku, 10);
        $this->listing($site, 'LOOSE', null);

        $r = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => 5001, 'lines' => [
            self::line('V1', 'a', 'b'), ['variant_id' => 'LOOSE', 'qty' => 1, 'unit_ids' => ['c']],
        ]]), 201);
        $d = $r->data();
        self::assertSame(['5001', 'held', 'held', 1], [$d['order_ref'], $d['result'], $d['status'], $d['attempt']]);
        self::assertMatchesRegularExpression('/Z$/', $d['expires_at']);
        $lines = array_column($d['lines'], null, 'variant_id');
        self::assertSame('held', $lines['V1']['result']);
        self::assertSame(8, $lines['V1']['available']);
        self::assertSame('unlinked', $lines['LOOSE']['result']);
        $this->assertBal(10, 0, 2, $sku);

        $r = self::assertEnvelope($this->call('POST', '/v1/reservations/5001/commit', $key, ['lines' => [
            self::line('V1', 'a', 'b'), ['variant_id' => 'LOOSE', 'qty' => 1, 'unit_ids' => ['c']],
        ]]), 200);
        self::assertSame(['committed', 'reserved', []], [$r->data()['result'], $r->data()['origin'], $r->data()['oversell']]);
        $this->assertBal(10, 2, 0, $sku);

        $shipAt = self::ago(7200, '+01:00');
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations/5001/ship', $key,
            ['unit_ids' => ['a', 'b', 'zz'], 'dispatched_at' => $shipAt]), 200);
        self::assertSame(['a' => 'shipped', 'b' => 'shipped', 'zz' => 'unknown_unit'],
            array_column($r->data()['units'], 'result', 'unit_id'));
        self::assertSame(self::dbTime($shipAt), self::$db->value("SELECT dispatched_at FROM reservation_unit WHERE unit_id = 'a'"),
            'dispatch times are stored in UTC');
        $this->assertBal(8, 0, 0, $sku);

        // unship takes `at` (the reset time), required; dispatched_at is the ship's field (R10).
        self::assertEnvelope($this->call('POST', '/v1/reservations/5001/unship', $key, ['unit_ids' => ['b'], 'dispatched_at' => self::ago(3600)]), 400, 'bad_request');
        self::assertEnvelope($this->call('POST', '/v1/reservations/5001/unship', $key,
            ['unit_ids' => ['b'], 'at' => self::ago(3600), 'dispatched_at' => self::ago(3600)]), 400, 'bad_request');
        self::assertEnvelope($this->call('POST', '/v1/reservations/5001/unship', $key, ['unit_ids' => ['b']]), 400, 'at_required');
        // a reset cannot precede the dispatch it reverses, nor lie in the future (R5)
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations/5001/unship', $key, ['unit_ids' => ['b'], 'at' => self::ago(7260)]), 400, 'bad_time');
        self::assertSame('not_after_dispatch', $r->data()['reason']);
        self::assertEnvelope($this->call('POST', '/v1/reservations/5001/unship', $key, ['unit_ids' => ['b'], 'at' => self::ago(-3600)]), 400, 'bad_time');
        $this->assertBal(8, 0, 0, $sku);
        self::assertEnvelope($this->call('POST', '/v1/reservations/5001/unship', $key, ['unit_ids' => ['b'], 'at' => self::ago(3600)]), 200);
        $this->assertBal(9, 1, 0, $sku);

        $r = self::assertEnvelope($this->call('POST', '/v1/reservations/5001/return', $key, ['unit_ids' => ['a', 'b']]), 200);
        self::assertSame(['a' => 'returned', 'b' => 'not_shipped'], array_column($r->data()['units'], 'result', 'unit_id'));
        $this->assertBal(10, 1, 0, $sku);

        // The site sees the new figure on the feed (u = 10 listing: floor(9 / 10) = 0).
        $views = array_column($this->call('GET', '/v1/availability?variant_ids=V1,V10', $key)->data()['listings'], null, 'variant_id');
        self::assertSame([9, 0], [$views['V1']['available'], $views['V10']['available']]);
    }

    public function testShortStrictItemIsRefusedWithPerLineAvailability(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 3);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V2', $sku, 2);
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => 'A1', 'lines' => [
            self::line('V1', 'a'), self::line('V2', 'b', 'c'),
        ]]), 409, 'refused');
        self::assertSame(['short'], $r->data()['reasons']);
        $lines = array_column($r->data()['lines'], null, 'variant_id');
        self::assertSame(['short', 3], [$lines['V1']['result'], $lines['V1']['available']]);
        self::assertSame(['short', 1], [$lines['V2']['result'], $lines['V2']['available']]);
        $this->assertBal(3, 0, 0, $sku);
        self::assertNull($this->reservation($site, 'A1'), 'a refused new order leaves no reservation');

        // Stopped items are refused on a live site whatever the stock.
        $this->ok($this->stock->setPolicy(self::staff(), $sku, 'stopped', $this->key()));
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => 'A2', 'lines' => [self::line('V1', 'x')]]), 409, 'refused');
        self::assertSame(['stopped'], $r->data()['reasons']);
    }

    public function testReservationStateMachineAnswers(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $order = ['order_ref' => '7', 'lines' => [self::line('V1', 'a')]];
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $order), 201);
        self::assertSame('extended', $this->call('POST', '/v1/reservations', $key, $order)->data()['result']);
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => '7', 'lines' => [self::line('V1', 'a', 'b')]]), 422, 'lines_changed');
        self::assertSame('held', $r->data()['status']);

        // release: bad attempt, then released, then already released; a new attempt, a stale release.
        self::assertEnvelope($this->call('POST', '/v1/reservations/7/release', $key, ['attempt' => 0]), 400, 'bad_request');
        self::assertEnvelope($this->call('POST', '/v1/reservations/7/release', $key, ['attempt' => '1']), 400, 'bad_request');
        self::assertSame('released', $this->call('POST', '/v1/reservations/7/release', $key, ['attempt' => 1])->data()['result']);
        self::assertSame('already_released', $this->call('POST', '/v1/reservations/7/release', $key, raw: '')->data()['result']);
        self::assertSame(2, $this->call('POST', '/v1/reservations', $key, $order)->data()['attempt']);
        self::assertSame('stale_attempt', $this->call('POST', '/v1/reservations/7/release', $key, ['attempt' => 1])->data()['result']);
        // no attempt means attempt 1 (R9): a late re-send of the first release cannot drop attempt 2
        self::assertSame('stale_attempt', $this->call('POST', '/v1/reservations/7/release', $key, (object) [])->data()['result']);
        self::assertEnvelope($this->call('POST', '/v1/reservations/7/release', $key, ['attempt' => 5_000_000_000]), 400, 'bad_request');
        $this->assertBal(5, 0, 1, $sku);

        // Unknown ref: a tombstone, and a late reserve holds nothing.
        self::assertSame('tombstoned', $this->call('POST', '/v1/reservations/8/release', $key, (object) [])->data()['result']);
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => '8', 'lines' => [self::line('V1', 'z')]]), 409, 'released');

        // Paid: a release is refused with use_cancel, a second commit is already_committed.
        self::assertEnvelope($this->call('POST', '/v1/reservations/7/commit', $key, ['lines' => $order['lines']]), 200);
        self::assertEnvelope($this->call('POST', '/v1/reservations/7/release', $key, (object) []), 409, 'use_cancel');
        self::assertSame('already_committed', $this->call('POST', '/v1/reservations/7/commit', $key, ['lines' => $order['lines']])->data()['result']);
        $this->assertBal(5, 1, 0, $sku);
    }

    public function testCommitWithoutHoldOriginAndOversell(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 1);
        $this->listing($site, 'V1', $sku);
        self::assertEnvelope($this->call('POST', '/v1/reservations/9/commit', $key, ['lines' => [self::line('V1', 'a')], 'origin' => 'paid']), 400, 'bad_origin');
        self::assertEnvelope($this->call('POST', '/v1/reservations/9/commit', $key, ['origin' => 'unreserved']), 400, 'bad_lines');
        // An outage order: committed without a hold, never refused; the shortfall is flagged.
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations/9/commit', $key, ['lines' => [self::line('V1', 'a', 'b')], 'origin' => 'unreserved']), 200);
        self::assertSame('outage_order', $r->data()['oversell'][0]['kind']);
        self::assertSame(1, $r->data()['oversell'][0]['shortfall']);
        $this->assertBal(1, 2, 0, $sku);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM oversell_event'));
    }

    public function testCancelRestockableOrNot(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '11', [self::line('V1', 'a', 'b', 'c')]);
        self::assertEnvelope($this->call('POST', '/v1/reservations/11/cancel', $key, ['unit_ids' => ['a']]), 400, 'bad_request');
        self::assertEnvelope($this->call('POST', '/v1/reservations/11/cancel', $key, ['unit_ids' => ['a'], 'restockable' => 'yes']), 400, 'bad_request');
        self::assertEnvelope($this->call('POST', '/v1/reservations/11/cancel', $key, ['restockable' => true]), 400, 'bad_unit_ids');
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations/11/cancel', $key, ['unit_ids' => ['a'], 'restockable' => true]), 200);
        self::assertSame('cancelled', $r->data()['units'][0]['result']);
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations/11/cancel', $key, ['unit_ids' => ['b', 'a'], 'restockable' => false]), 200);
        self::assertSame(['a' => 'already_cancelled', 'b' => 'cancelled_to_verify'], array_column($r->data()['units'], 'result', 'unit_id'));
        $this->assertBal(4, 1, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'verify_recount'"));
    }

    public function testOrderRefsInThePathArePercentDecoded(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('legacy', 2);
        $this->listing($site, 'V1', $sku);
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => 'web:77+1', 'lines' => [self::line('V1', 'a')]]), 201);
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations/web%3A77%2B1/commit', $key, ['lines' => [self::line('V1', 'a')]]), 200);
        self::assertSame('web:77+1', $r->data()['order_ref']);
        self::assertSame('committed', $this->reservation($site, 'web:77+1')['status']);
        self::assertEnvelope($this->call('POST', '/v1/reservations/' . str_repeat('1', 65) . '/commit', $key, ['lines' => [self::line('V1', 'b')]]), 400, 'bad_order_ref');
    }

    public function testVariantIdsFollowTheDatabaseCollation(): void
    {
        // channel_listing.external_variant_id is utf8mb4_0900_ai_ci: 'abc-1' and 'ABC-1' are one
        // listing to MySQL. A sale or a profile push naming it in another case must use that
        // listing, not fail (it used to end in a 500 from Reservations::listings).
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 5);
        $listing = $this->listing($site, 'abc-1', $sku);
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => '1', 'lines' => [
            self::line('ABC-1', 'u1'), self::line('abc-1', 'u2'),
        ]]), 201);
        self::assertSame(['held', 'held'], array_column($r->data()['lines'], 'result'));
        $this->assertBal(5, 0, 2, $sku);
        self::assertSame([$listing, $listing], array_map('intval', self::$db->column('SELECT listing_id FROM reservation_unit ORDER BY unit_id')));
        self::assertEnvelope($this->call('POST', '/v1/reservations/1/commit', $key, ['lines' => [self::line('Abc-1', 'u1', 'u2')]]), 200);
        $this->assertBal(5, 2, 0, $sku);

        $r = self::assertEnvelope($this->call('PUT', '/v1/listings', $key, ['listings' => [['variant_id' => 'ABC-1', 'product_title' => 'T']]]), 200);
        self::assertSame($listing, $r->data()['listings'][0]['listing_id']);
        self::assertEnvelope($this->call('PUT', '/v1/listings', $key, ['listings' => [['variant_id' => 'x-9'], ['variant_id' => 'X-9']]]), 400, 'bad_listings');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM channel_listing'));
    }

    public function testOpeningOrders(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'shadow');
        $sku = $this->item('legacy', 0);
        $this->listing($site, 'V1', $sku);
        $orders = [['order_ref' => 100, 'lines' => [self::line('V1', 'o1', 'o2')]], ['order_ref' => '101', 'lines' => [self::line('V1', 'o3')]]];
        self::assertEnvelope($this->call('POST', '/v1/opening_orders', $key, ['orders' => $orders]), 400, 'bad_request');
        self::assertEnvelope($this->call('POST', '/v1/opening_orders', $key, ['orders' => [], 'final' => true]), 400, 'bad_orders');
        // the final batch needs the T0 watermarks (R12); a bad t0 is refused
        self::assertEnvelope($this->call('POST', '/v1/opening_orders', $key, ['orders' => $orders, 'final' => true]), 409, 't0_required');
        self::assertEnvelope($this->call('POST', '/v1/opening_orders', $key, ['orders' => $orders, 'final' => true, 't0' => [1, 2]]), 400, 'bad_t0');
        $t0 = ['at' => self::ago(600), 'last_order_id' => 99, 'last_stock_log_id' => null];
        $r = self::assertEnvelope($this->call('POST', '/v1/opening_orders', $key, ['orders' => $orders, 'final' => true, 't0' => $t0]), 200);
        self::assertSame(['accepted', 2, 2, true], [$r->data()['result'], $r->data()['orders'], $r->data()['committed'], $r->data()['final']]);
        self::assertSame([99, null], [$r->data()['t0']['last_order_id'], $r->data()['t0']['last_stock_log_id']]);
        $this->assertBal(0, 3, 0, $sku);
        self::assertSame('opening', $this->reservation($site, '100')['origin']);
        self::assertEnvelope($this->call('POST', '/v1/opening_orders', $key, ['orders' => $orders, 'final' => true]), 409, 'opening_orders_done');
    }
}
