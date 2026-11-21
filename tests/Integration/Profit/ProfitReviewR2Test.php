<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\Profit\CostEngine;
use CW\Profit\OrderSnapshots;
use CW\Profit\PendingCosts;
use CW\Profit\ReturnsSweep;
use CW\Tests\Support\StockTestCase;

/**
 * The Codex re-check of 57d98ad (build/A/codex_r2.txt), the order side, each shown failing first: a line waiting for its cost keeps the
 * item it was sold as (N7); an order with a unit without a price has no margin to rank, and its first order neither (c6); every unit back in
 * stock is found at night whenever its return was booked (b2).
 */
final class ProfitReviewR2Test extends StockTestCase
{
    private static function price(int $sku, int $e4): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', '2026-11-16', '2026-11-16')", [$sku, $e4]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, ?)",
            [$sku, 'start:' . $sku, $e4]);
    }

    private static function mark(string $at): void
    {
        self::$db->exec('INSERT INTO profit_job (job, mark_at) VALUES (?, ?) AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at', [CostEngine::JOB, $at]);
    }

    private static function body(array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z',
            'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => array_map(static fn (array $l): array => $l + ['qty' => 1, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false], $lines),
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []];
    }

    private static function row(Caller $site, string $id): array
    {
        return (array) self::$db->one('SELECT * FROM sale_line WHERE channel_id = ? AND line_id = ?', [$site->channelId, $id]);
    }

    public function testALineWaitingForItsCostKeepsTheItemItWasSoldAs(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        self::price($a, 10000);
        self::price($b, 30000);
        $listing = $this->listing($site, '1', $a);
        self::mark('2026-11-19 09:00:00');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '1', self::body([['line_id' => '9100002', 'variant_id' => '1', 'price_inc' => 1200]]), 'api');
        self::assertSame([null, (string) $a], [self::row($site, '9100002')['cost_source'], (string) self::row($site, '9100002')['sku_id']]);
        // Before the cost job reaches the paid time, the listing is matched to another item of 2 units.
        self::$db->exec('UPDATE channel_listing SET sku_id = ?, units_per_item = 2 WHERE id = ?', [$b, $listing]);
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '1', self::body([['line_id' => '9100002', 'variant_id' => '1', 'price_inc' => 1200]], ['version' => 1001,
            'grand_inc' => 1]), 'api');
        self::mark('2026-11-19 11:00:00');
        (new PendingCosts(self::$db))->run();
        $l = self::row($site, '9100002');
        self::assertSame([(string) $a, '1', '1', '100'], [(string) $l['sku_id'], (string) $l['units_per_item'], (string) $l['units'], (string) $l['cost']],
            'the item and units it was sold as, at their price');
    }

    public function testAnIncompleteOrderAndItsFirstOrderHaveNoMarginToRank(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        self::price($a, 10000);
        $this->listing($site, '1', $a);
        $this->listing($site, '2', $b);
        self::mark('2030-01-01');
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, '9100005', self::body([['line_id' => '9100003', 'variant_id' => '1', 'price_inc' => 1200]]), 'api');
        self::assertSame(9000, (int) self::$db->value("SELECT margin_bp FROM sale_order WHERE order_ref = '9100005'"));
        // Its re-send sends an item with no price: the first order's margin is not known any more.
        $s->store((int) $site->channelId, '9100008', self::body([['line_id' => '9100004', 'variant_id' => '2', 'price_inc' => 0, 'free' => true]], ['parent_ref' => '9100005']), 'api');
        self::assertNull(self::$db->value("SELECT margin_bp FROM sale_order WHERE order_ref = '9100005'"));
        $s->store((int) $site->channelId, '9100009', self::body([['line_id' => '9100006', 'variant_id' => '2', 'price_inc' => 1200]]), 'api');
        self::assertNull(self::$db->value("SELECT margin_bp FROM sale_order WHERE order_ref = '9100009'"), 'no price: no margin to rank');
    }

    public function testEveryUnitBackInStockIsFoundAtNight(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 10);
        self::price($a, 10000);
        self::mark('2030-01-01');
        $this->listing($site, '1', $a);
        $this->ok($this->commit($site, '9100007', [self::line('1', '9100001')]));
        $this->ok($this->ship($site, '9100007', ['9100001']));
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '9100007', self::body([['line_id' => '9100001', 'variant_id' => '1', 'price_inc' => 1200, 'refund_inc' => 1200]]),
            'api');
        $this->ok($this->res->returnUnits($site, '9100007', ['9100001'], $this->key('ret')));
        // The sweep's window has moved past the return (as if its booking committed late).
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('returns', ?)", [Clock::db(Clock::now()->modify('+3 hours'))]);
        self::assertSame(0, (new ReturnsSweep(self::$db, static fn (): \DateTimeImmutable => Clock::now()->modify('+4 hours')))->run()['orders']);
        self::assertSame(1, (new ReturnsSweep(self::$db))->reconcile()['orders']);
        self::assertSame(['1', '0'], [(string) self::row($site, '9100001')['restocked'], (string) self::row($site, '9100001')['cost']]);
        self::assertSame(0, (new ReturnsSweep(self::$db))->reconcile()['orders'], 'once');
    }
}
