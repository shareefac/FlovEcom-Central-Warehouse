<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;
use CW\Tests\Support\StockTestCase;

/**
 * The owner's rule of 21 Nov 2026 ("Yes, that's right", README open question 5; docs/decisions.md PR129): every order with parent_ref (the
 * shop's clone) is a re-send, a replacement, whatever price staff left on it. Nothing on it counts as money in: its goods sale and its
 * delivery charge are 0 in every figure (its own row and lines, its margin, the day and item-day summaries, Profit by order); its goods cost
 * stays as built: on its first order (resends, resend_cost, that order's margin) and in the day's cost on the clone's own day; never counted
 * as an order. The price the shop put on it is kept as the shop sent it (goods_inc, delivery_charged_inc), never as money.
 */
final class CloneNeverMoneyInTest extends StockTestCase
{
    private const PAID = '2026-11-19T10:00:00Z';
    private const DAY = '2026-11-19';

    private static function price(int $sku, int $e4): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', '2026-11-16 00:00:00', "
            . "'2026-11-16 00:00:00')", [$sku, $e4]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16 00:00:00', '2026-11-16 00:00:00', 'start', ?, ?)",
            [$sku, 'start:' . $sku, $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
    }

    /** @return array<string, mixed> */
    private static function sl(string $id, int $price, array $over = []): array
    {
        return $over + ['line_id' => $id, 'variant_id' => '9100003', 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    /** @return array<string, mixed> */
    private static function body(array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => self::PAID,
            'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '12', 'method' => 'Royal Mail Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0,
            'grand_inc' => 0, 'parcels' => []];
    }

    /** The clone: staff left the £12 price on its line and a £3.60 delivery charge (both inc VAT). */
    private static function clone(array $over = [], ?array $line = null): array
    {
        return self::body([$line ?? self::sl('9100001', 1200)], $over + ['order_no' => 'VPG2', 'parent_ref' => '9100004',
            'delivery' => ['method_id' => '12', 'method' => 'Royal Mail Tracked 48', 'charged_inc' => 360, 'refund_inc' => 0]]);
    }

    /** @return list<string> */
    private static function row(string $sql, array $params): array
    {
        return array_map(static fn (mixed $v): string => $v === null ? 'null' : (string) $v, array_values((array) self::$db->one($sql, $params)));
    }

    /** @return array{0: int, 1: int} the store and the item (£3.00 a unit) */
    private function setUpShop(): array
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, '9100003', $a);
        // The first order: one unit at £12 inc VAT (£10 ex VAT), its cost £3.
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '9100004', self::body([self::sl('9100002', 1200)]), 'api');
        return [(int) $site->channelId, $a];
    }

    private function assertNoMoneyOnTheClone(int $ch, int $a, string $why): void
    {
        self::assertSame(['0', '0', '0', '0', '300', 'null'], self::row('SELECT sale_inc, sale_ex, order_refund_ex, delivery_ex, cost, margin_bp FROM sale_order '
            . "WHERE channel_id = ? AND order_ref = '9100005'", [$ch]), "the clone's own row: no money in, its cost £3 ({$why})");
        self::assertSame(['0', '0', '300'], self::row("SELECT SUM(sale_inc), SUM(sale_ex), SUM(cost) FROM sale_line WHERE channel_id = ? AND order_ref = '9100005' AND removed_at IS NULL",
            [$ch]), "its lines ({$why})");
        self::assertSame(['1', '300', '4000'], self::row("SELECT resends, resend_cost, margin_bp FROM sale_order WHERE channel_id = ? AND order_ref = '9100004'", [$ch]),
            "its cost on the first order: (£10 - £3 - £3) / £10 = 40% ({$why})");
        self::assertSame(['1', '1', '2', '1000', '0', '0', '600'], self::row('SELECT orders, resends, units, sale_ex, order_refund_ex, delivery_ex, cost FROM profit_day '
            . 'WHERE channel_id = ? AND day = ?', [$ch, self::DAY]), "the day: one order, one re-send, £10 of goods, no delivery charged, £6 of cost ({$why})");
        self::assertSame(['2', '1000', '600'], self::row('SELECT units, sale_ex, cost FROM profit_item_day WHERE channel_id = ? AND day = ? AND sku_id = ?', [$ch, self::DAY, $a]),
            "the item's day: two units out, £10 sold, £6 cost ({$why})");
        self::assertSame([], ProfitDays::differences(self::$db, $ch, self::DAY), "the day is what its orders add up to ({$why})");
    }

    public function testAPriceStaffLeftOnACloneIsNeverMoneyIn(): void
    {
        [$ch, $a] = $this->setUpShop();
        (new OrderSnapshots(self::$db))->store($ch, '9100005', self::clone(), 'api');
        $this->assertNoMoneyOnTheClone($ch, $a, 'a £12 price and £3.60 delivery left on it');
        self::assertSame(['1200', '360'], self::row("SELECT goods_inc, delivery_charged_inc FROM sale_order WHERE channel_id = ? AND order_ref = '9100005'", [$ch]),
            'the price the shop put on it is kept as the shop sent it, never as money');
        // Profit by order: the clone's row sells nothing; the first order's row carries the clone's cost.
        $rows = [];
        foreach ((new ProfitViews(self::$db))->orders(self::DAY, self::DAY, $ch, '', false)['rows'] as $r) {
            $rows[$r['ref']] = [$r['sale'], $r['cost'], $r['delivery'], $r['profit'], $r['with_resends']];
        }
        ksort($rows);
        self::assertSame(['9100004' => [1000, 300, 0, 700, 400], '9100005' => [0, 300, 0, -300, null]], $rows);
        $page = (new ProfitViews(self::$db))->order($ch, '9100005');
        self::assertSame(['0', '0'], [(string) $page['order']['sale_ex'], (string) $page['order']['delivery_ex']]);
        self::assertSame([0], array_column($page['lines'], 'sale_ex'));
    }

    public function testACloneStaysNoMoneyWhenItsPriceIsEditedOrRefunded(): void
    {
        [$ch, $a] = $this->setUpShop();
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, '9100005', self::clone(), 'api');
        // The price edited up, then a refund of the line and of the order on it: still nothing in, never below 0.
        $s->store($ch, '9100005', self::clone(['version' => 1001], self::sl('9100001', 2400)), 'api');
        $this->assertNoMoneyOnTheClone($ch, $a, 'the price edited to £24');
        $s->store($ch, '9100005', self::clone(['version' => 1002, 'order_refund_inc' => 360], self::sl('9100001', 1200, ['refund_inc' => 1200])), 'api');
        $this->assertNoMoneyOnTheClone($ch, $a, 'refunded: never a negative sale');
        // No longer a clone (the shop took the link away): its own sale; a clone again: no money again.
        $s->store($ch, '9100005', self::clone(['version' => 1003, 'parent_ref' => null]), 'api');
        self::assertSame(['1000', '300', '7000'], self::row("SELECT sale_ex, delivery_ex, margin_bp FROM sale_order WHERE channel_id = ? AND order_ref = '9100005'", [$ch]),
            'its own sale: (£10 - £3) / £10');
        self::assertSame(['0', '0', '7000'], self::row("SELECT resends, resend_cost, margin_bp FROM sale_order WHERE channel_id = ? AND order_ref = '9100004'", [$ch]));
        self::assertSame(['2', '0', '2000', '300'], self::row('SELECT orders, resends, sale_ex, delivery_ex FROM profit_day WHERE channel_id = ? AND day = ?', [$ch, self::DAY]));
        self::assertSame([], ProfitDays::differences(self::$db, $ch, self::DAY));
        $s->store($ch, '9100005', self::clone(['version' => 1004]), 'api');
        $this->assertNoMoneyOnTheClone($ch, $a, 'a clone again');
    }
}
