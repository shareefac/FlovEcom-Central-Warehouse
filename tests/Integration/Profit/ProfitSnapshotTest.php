<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\ReturnsSweep;
use CW\Tests\Support\StockTestCase;

/**
 * A store's order snapshot worked out into money and cost (profit step 1; docs/decisions.md PR1-PR11, PR22): the cost fixed at the sale and
 * never changed by a later snapshot; VAT, discount, cancelled, free, refund, delivery; same / older / newer; an unpaid order counts nothing
 * until it is paid; a re-send's cost on its first order; a line or parcel gone from a newer snapshot kept and counting nothing; a unit back
 * in stock (the returns sweep); before CW's price and before ERPNext's history; the day summaries kept by the snapshot equal a rebuild.
 */
final class ProfitSnapshotTest extends StockTestCase
{
    private const PAID = '2026-11-19T10:00:00Z';
    private const DAY = '2026-11-19';
    /** @var array<string, string> setting key => value_json to put back */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $k]);
        }
        $this->saved = [];
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->saved[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    /** An item's buying price from $at (an event as the cost job writes it). */
    private static function price(int $sku, ?int $e4, string $at = '2026-11-16 00:00:00', string $kind = 'start'): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec('INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, ?, ?, ?)',
            [$sku, $at, $at, $kind, $kind . ':' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
        // The cost job has read everything (its mark far ahead): costs are fixed at once.
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
    }

    /** @return array<string, mixed> */
    private static function sl(string $id, string $variant, int $price, array $over = []): array
    {
        return $over + ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    /** @return array<string, mixed> */
    private static function body(array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => self::PAID,
            'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '12', 'method' => 'Royal Mail Tracked 48', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0,
            'grand_inc' => 1498, 'parcels' => []];
    }

    private function snapshots(): OrderSnapshots
    {
        return new OrderSnapshots(self::$db);
    }

    /** @return array<string, mixed> */
    private static function order(Caller $site, string $ref): array
    {
        return (array) self::$db->one('SELECT * FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$site->channelId, $ref]);
    }

    /** @return array<string, mixed> */
    private static function lineRow(Caller $site, string $id): array
    {
        return (array) self::$db->one('SELECT * FROM sale_line WHERE channel_id = ? AND line_id = ?', [$site->channelId, $id]);
    }

    /** @return array{day: array<string, int>, items: array<int, array<string, int>>} the summaries of a store's day as kept */
    private static function summaries(Caller $site, string $day): array
    {
        $d = self::$db->one('SELECT orders, resends, units, sale_ex, order_refund_ex, cost, units_no_cost, delivery_ex FROM profit_day WHERE channel_id = ? AND day = ?',
            [$site->channelId, $day]);
        $items = [];
        foreach (self::$db->all('SELECT sku_id, units, sale_ex, cost, units_no_cost FROM profit_item_day WHERE channel_id = ? AND day = ? AND '
            . '(units <> 0 OR sale_ex <> 0 OR cost <> 0 OR units_no_cost <> 0) ORDER BY sku_id', [$site->channelId, $day]) as $r) {
            $items[(int) $r['sku_id']] = array_map('intval', array_slice($r, 1));
        }
        return ['day' => $d === null ? [] : array_map('intval', $d), 'items' => $items];
    }

    /** The summaries kept by the snapshots equal a rebuild from the stored orders and lines. */
    private function assertSummariesRebuild(Caller $site, string $day): void
    {
        $kept = self::summaries($site, $day);
        ProfitDays::rebuild(self::$db, (int) $site->channelId, $day);
        self::assertSame($kept, self::summaries($site, $day), 'the summaries kept by each snapshot equal a rebuild from the orders');
    }

    public function testAPaidOrderIsWorkedOutWithItsCostFixedAtTheSale(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        self::price($a, 10500);
        $this->listing($site, '40123', $a);
        $this->listing($site, '40124', $b);
        $this->listing($site, '40125', null);
        $lines = [
            self::sl('8001', '40123', 1299, ['discount_inc' => 200]),
            self::sl('8002', '40124', 500),
            self::sl('8003', '40125', 300, ['cancelled' => true]),
            self::sl('8004', '40123', 0, ['free' => true]),
        ];
        $s = $this->snapshots();
        $first = self::body($lines, ['parcels' => [
            ['parcel_ref' => 'odl:1', 'tracking_no' => '15501234567890', 'carrier' => 'royal_mail', 'service_code' => 'TPN', 'service' => 'Tracked 48',
                'status' => 'generated', 'label_at' => '2026-11-19T12:00:00Z', 'shipped_at' => null, 'manual' => false, 'partial' => false]]]);
        self::assertSame(['result' => 'stored', 'version' => 1000], $s->store((int) $site->channelId, '5001', $first, 'api'));
        $o = self::order($site, '5001');
        // goods: 1099/1.2 = 915.83 -> 916, 500/1.2 = 416.67 -> 417; delivery 399/1.2 = 332.5 -> 333
        self::assertSame(['1333', '210', '3', '1', '333', self::DAY, '2000'], [(string) $o['sale_ex'], (string) $o['cost'], (string) $o['units'],
            (string) $o['units_no_cost'], (string) $o['delivery_ex'], (string) $o['day'], (string) $o['vat_bp']]);
        self::assertNull($o['margin_bp'], 'one unit has no buying price: no margin is ranked until it has one (PR39, c6)');
        $l1 = self::lineRow($site, '8001');
        self::assertSame(['cw_cost', '10500', '105', 'sale'], [$l1['cost_source'], (string) $l1['unit_cost_e4'], (string) $l1['cost'], $l1['costed_by']]);
        self::assertSame('none', self::lineRow($site, '8002')['cost_source'], 'an item with no price: none (the No buying price list)');
        self::assertSame(['0', '0', '0'], [(string) self::lineRow($site, '8003')['units'], (string) self::lineRow($site, '8003')['sale_ex'],
            (string) self::lineRow($site, '8003')['cost']], 'a cancelled line counts nothing');
        self::assertSame(['1', '0', '105'], [(string) self::lineRow($site, '8004')['units'], (string) self::lineRow($site, '8004')['sale_ex'],
            (string) self::lineRow($site, '8004')['cost']], 'a free line: its cost, sale 0');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sale_parcel WHERE channel_id = ? AND order_ref = ?', [$site->channelId, '5001']));
        self::assertSame(['orders' => 1, 'resends' => 0, 'units' => 3, 'sale_ex' => 1333, 'order_refund_ex' => 0, 'cost' => 210, 'units_no_cost' => 1, 'delivery_ex' => 333],
            self::summaries($site, self::DAY)['day']);
        self::assertSame([$a => ['units' => 2, 'sale_ex' => 916, 'cost' => 210, 'units_no_cost' => 0], $b => ['units' => 1, 'sale_ex' => 417, 'cost' => 0, 'units_no_cost' => 1]],
            self::summaries($site, self::DAY)['items']);
        self::assertStringNotContainsString('secret', (string) self::$db->value('SELECT CAST(body AS CHAR) FROM order_snapshot'), 'only the contract\'s fields are kept');

        // The same content again: same, whatever its version (a newer version is remembered); an older version with other content: ignored.
        self::assertSame(['result' => 'same', 'version' => 1000], $s->store((int) $site->channelId, '5001', ['secret' => 'x'] + $first, 'api'));
        self::assertSame(['result' => 'same', 'version' => 1005], $s->store((int) $site->channelId, '5001', ['version' => 1005] + $first, 'api'));
        self::assertSame(['result' => 'older_ignored', 'version' => 1005], $s->store((int) $site->channelId, '5001', ['version' => 1001, 'grand_inc' => 1] + $first,
            'api'));
        self::assertSame(1005, (int) self::$db->value('SELECT version FROM order_snapshot WHERE order_ref = ?', ['5001']));

        // The item's price moves later: the line keeps the cost fixed at its sale.
        self::price($a, 99900, '2026-11-19 11:00:00', 'start'); // a later price, as an absolute event
        $lines[0]['refund_inc'] = 599;
        self::assertSame('stored', $s->store((int) $site->channelId, '5001', self::body($lines, ['version' => 1010]), 'api')['result']);
        $l1 = self::lineRow($site, '8001');
        self::assertSame(['10500', '105', '417'], [(string) $l1['unit_cost_e4'], (string) $l1['cost'], (string) $l1['sale_ex']], 'a refund of 5.99: 500 inc left');
        self::assertSame(834, (int) self::order($site, '5001')['sale_ex']);
        $this->assertSummariesRebuild($site, self::DAY);

        // A cost is fixed with the item: a listing linked elsewhere later changes nothing on a fixed line.
        $c = $this->item('legacy');
        self::$db->exec("UPDATE channel_listing SET sku_id = ?, status = 'mapped' WHERE channel_id = ? AND external_variant_id = '40125'", [$c, $site->channelId]);
        $s->store((int) $site->channelId, '5001', self::body($lines, ['version' => 1011, 'order_refund_inc' => 120]), 'api');
        self::assertNull(self::lineRow($site, '8003')['sku_id']);
        $o = self::order($site, '5001');
        self::assertSame(['120', '100', '734'], [(string) $o['order_refund_inc'], (string) $o['order_refund_ex'], (string) $o['sale_ex']], 'an order-level refund');
        $this->assertSummariesRebuild($site, self::DAY);
    }

    public function testAnUnpaidOrderCountsNothingUntilItIsPaid(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 20000);
        $this->listing($site, '1', $a);
        $s = $this->snapshots();
        $s->store((int) $site->channelId, '6001', self::body([self::sl('9001', '1', 1200)], ['paid_at' => null]), 'api');
        $l = self::lineRow($site, '9001');
        self::assertSame([null, null, '1000'], [$l['cost_source'], $l['day'], (string) $l['sale_ex']]);
        self::assertSame([], self::summaries($site, self::DAY)['day']);
        $s->store((int) $site->channelId, '6001', self::body([self::sl('9001', '1', 1200)], ['version' => 1001]), 'api');
        $l = self::lineRow($site, '9001');
        self::assertSame(['cw_cost', '200', self::DAY], [$l['cost_source'], (string) $l['cost'], $l['day']]);
        self::assertSame(1, self::summaries($site, self::DAY)['day']['orders']);
        $this->assertSummariesRebuild($site, self::DAY);
    }

    public function testAReSendsCostBelongsToTheFirstOrderWhicheverComesFirst(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, '1', $a);
        $s = $this->snapshots();
        // The re-send arrives first (a backfill out of order), then its first order.
        $s->store((int) $site->channelId, '7002', self::body([self::sl('9102', '1', 0, ['free' => true])], ['parent_ref' => '7001', 'gateway' => 'office',
            'delivery' => null, 'grand_inc' => 0]), 'bulk');
        $s->store((int) $site->channelId, '7001', self::body([self::sl('9101', '1', 1200)]), 'bulk');
        $p = self::order($site, '7001');
        self::assertSame(['1', '300', '300'], [(string) $p['resends'], (string) $p['resend_cost'], (string) $p['cost']]);
        self::assertSame(4000, (int) $p['margin_bp'], '(1000 - 300 - 300) / 1000');
        // A second snapshot of the re-send moves the first order's figures with it.
        $s->store((int) $site->channelId, '7002', self::body([self::sl('9102', '1', 0, ['free' => true]), self::sl('9103', '1', 0, ['free' => true])],
            ['parent_ref' => '7001', 'version' => 1001, 'delivery' => null, 'grand_inc' => 0]), 'api');
        self::assertSame('600', (string) self::order($site, '7001')['resend_cost']);
        $d = self::summaries($site, self::DAY)['day'];
        self::assertSame([1, 1, 3, 900], [$d['orders'], $d['resends'], $d['units'], $d['cost']], 'the re-send is not an order; its cost is counted');
        self::assertSame(['result' => 'stored'], array_intersect_key($s->store((int) $site->channelId, '7003', self::body([self::sl('9104', '1', 100)],
            ['sub_of' => '7001']), 'api'), ['result' => 1]));
        self::assertSame(['7001', null], [self::order($site, '7003')['sub_of'], self::order($site, '7003')['parent_ref']], 'an office sub order is its own sale');
        $this->assertSummariesRebuild($site, self::DAY);
    }

    public function testALineOrParcelGoneFromANewerSnapshotIsKeptAndCountsNothing(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 10000);
        $this->listing($site, '1', $a);
        $s = $this->snapshots();
        $parcel = ['parcel_ref' => 'odl:7', 'tracking_no' => null, 'carrier' => 'dpd', 'service_code' => '', 'service' => 'DPD', 'status' => 'generated',
            'label_at' => null, 'shipped_at' => null, 'manual' => true, 'partial' => false];
        $s->store((int) $site->channelId, '8001', self::body([self::sl('9201', '1', 1200), self::sl('9202', '1', 600)], ['parcels' => [$parcel]]), 'api');
        $s->store((int) $site->channelId, '8001', self::body([self::sl('9201', '1', 1200)], ['version' => 1001]), 'api');
        $gone = self::lineRow($site, '9202');
        self::assertNotNull($gone['removed_at']);
        self::assertSame(['0', '0'], [(string) $gone['units'], (string) $gone['cost']]);
        self::assertNotNull(self::$db->value('SELECT removed_at FROM sale_parcel WHERE parcel_ref = ?', ['odl:7']), 'the parcel is kept, marked removed');
        self::assertSame(['1000', '100', '1'], [(string) self::order($site, '8001')['sale_ex'], (string) self::order($site, '8001')['cost'],
            (string) self::order($site, '8001')['line_count']]);
        $s->store((int) $site->channelId, '8001', self::body([self::sl('9201', '1', 1200)], ['version' => 1002, 'parcels' => [$parcel]]), 'api');
        self::assertNull(self::$db->value('SELECT removed_at FROM sale_parcel WHERE parcel_ref = ?', ['odl:7']), 'back again');
        $this->assertSummariesRebuild($site, self::DAY);

        // A line of another order is refused whole (400, its field), nothing written.
        try {
            $s->store((int) $site->channelId, '8002', self::body([self::sl('9300', '1', 100), self::sl('9201', '1', 1200)]), 'api');
            self::fail('a line of another order');
        } catch (CwException $e) {
            self::assertSame(['bad_snapshot', 400, 'lines[1].line_id'], [$e->errorCode, $e->httpStatus, $e->detail['field'] ?? null]);
        }
        self::assertNull(self::$db->value('SELECT 1 FROM order_snapshot WHERE order_ref = ?', ['8002']));
    }

    public function testAUnitBackInStockHasNoCostAndNoSaleWhenRefunded(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 10);
        self::price($a, 10000);
        $this->listing($site, '1', $a);
        $this->ok($this->commit($site, '9001', [self::line('1', 'u1', 'u2')]));
        $this->ok($this->ship($site, '9001', ['u1', 'u2']));
        $s = $this->snapshots();
        $s->store((int) $site->channelId, '9001', self::body([self::sl('u1', '1', 1200), self::sl('u2', '1', 1200, ['refund_inc' => 1200])]), 'api');
        self::assertSame('200', (string) self::order($site, '9001')['cost']);
        // The return reaches CW after the snapshot; the store sends nothing new. The sweep finds it.
        $this->ok($this->res->returnUnits($site, '9001', ['u2'], $this->key('ret')));
        $later = static fn (): \DateTimeImmutable => Clock::now()->modify('+1 hour');
        self::assertSame(1, (new ReturnsSweep(self::$db, $later))->run()['orders']);
        $u2 = self::lineRow($site, 'u2');
        self::assertSame(['1', '0', '0'], [(string) $u2['restocked'], (string) $u2['cost'], (string) $u2['sale_ex']]);
        self::assertSame(['100', '1000'], [(string) self::order($site, '9001')['cost'], (string) self::order($site, '9001')['sale_ex']]);
        self::assertSame(0, (new ReturnsSweep(self::$db, $later))->run()['orders'], 'done once');
        $this->assertSummariesRebuild($site, self::DAY);
    }

    public function testBeforeCwsPriceAndBeforeErpNextsHistory(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 10000);
        self::price($a, 15000, '2026-11-18 00:00:00', 'start'); // a later price, as an absolute event
        $this->listing($site, '1', $a);
        $s = $this->snapshots();
        $s->store((int) $site->channelId, '1', self::body([self::sl('L1', '1', 1200)], ['paid_at' => '2026-11-17T10:00:00Z']), 'api');
        $s->store((int) $site->channelId, '2', self::body([self::sl('L2', '1', 1200)], ['paid_at' => '2026-11-01T10:00:00Z']), 'api');
        $s->store((int) $site->channelId, '3', self::body([self::sl('L3', '1', 1200)], ['paid_at' => '2025-06-01T10:00:00Z']), 'api');
        self::assertSame(['cw_cost', '10000'], [self::lineRow($site, 'L1')['cost_source'], (string) self::lineRow($site, 'L1')['unit_cost_e4']], 'the price on the day');
        self::assertSame(['estimate', '10000'], [self::lineRow($site, 'L2')['cost_source'], (string) self::lineRow($site, 'L2')['unit_cost_e4']],
            'before CW\'s price: its start, as an estimate (ERPNext\'s history replaces it)');
        self::assertSame(['estimate', '15000'], [self::lineRow($site, 'L3')['cost_source'], (string) self::lineRow($site, 'L3')['unit_cost_e4']],
            'before ERPNext\'s history: today\'s price, as an estimate');
        $this->setting('profit.before_history', '"none"');
        $s = $this->snapshots(); // settings are read once per instance (a request, a job)
        $s->store((int) $site->channelId, '4', self::body([self::sl('L4', '1', 1200)], ['paid_at' => '2025-06-01T10:00:00Z']), 'api');
        self::assertSame('none', self::lineRow($site, 'L4')['cost_source'], 'the owner\'s switch: none');
    }
}
