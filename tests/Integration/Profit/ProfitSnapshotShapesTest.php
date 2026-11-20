<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\CostEngine;
use CW\Profit\OrderSnapshots;
use CW\Tests\Support\StockTestCase;

/**
 * CONTRACT.md change 8 (docs/decisions.md PR83; the lead's real-data test of 51417a3: 4 of 65,363 real Vape and Go orders refused): the
 * shapes the shop really stores are taken whole and their money stays exact: spread-remainder lines priced -2p / -3p next to free lines, and
 * a coupon's remainder of -99p on a 49p line beside lines discounted 1p each. Made-up order and line numbers; the prices are the real shapes.
 */
final class ProfitSnapshotShapesTest extends StockTestCase
{
    private static function sl(string $id, int $price, int $discount = 0, bool $free = false): array
    {
        return ['line_id' => $id, 'variant_id' => '1', 'qty' => 1, 'price_inc' => $price, 'discount_inc' => $discount, 'cancelled' => false, 'refund_inc' => 0,
            'free' => $free];
    }

    /** @param list<array<string, mixed>> $lines */
    private static function body(array $lines): array
    {
        $paid = '2026-11-18T10:00:00Z';
        return ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP',
            'parent_ref' => null, 'lines' => $lines, 'delivery' => null, 'order_refund_inc' => 0,
            'grand_inc' => array_sum(array_map(static fn (array $l): int => $l['price_inc'] - $l['discount_inc'], $lines)), 'parcels' => []];
    }

    public function testTheShapesTheShopReallyStoresAreTakenWithTheirMoneyExact(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $this->listing($site, '1', $a);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES (?, '2030-01-01')", [CostEngine::JOB]);
        $s = new OrderSnapshots(self::$db);
        // Spread remainders: £12.99, two free lines, and two lines of -2p (orders like 1512210); one of -3p (like 1514739 / 1525078).
        self::assertSame('stored', $s->store((int) $site->channelId, '9101', self::body([self::sl('a1', 1299), self::sl('a2', 0, 0, true), self::sl('a3', 0, 0, true),
            self::sl('a4', -2), self::sl('a5', -2)]), 'api')['result']);
        self::assertSame('stored', $s->store((int) $site->channelId, '9102', self::body([self::sl('b1', 1299), self::sl('b2', 0, 0, true), self::sl('b3', -3)]), 'api')['result']);
        // A coupon's pennies spread over five 49p lines (1p each), the remainder (-99p) on one line (like 1533139).
        self::assertSame('stored', $s->store((int) $site->channelId, '9103', self::body([self::sl('c1', 49, 1), self::sl('c2', 49, 1), self::sl('c3', 49, 1),
            self::sl('c4', 49, 1), self::sl('c5', 49, -99)]), 'api')['result']);
        $o = static fn (string $ref): array => array_map('intval', (array) self::$db->one('SELECT goods_inc, discount_inc, sale_inc, sale_ex, units FROM sale_order WHERE order_ref = ?',
            [$ref]));
        // £12.99 - 2p - 2p = £12.95 inc VAT; ex VAT line by line: £10.83 (12.99 / 1.2 = 10.825), -2p (-1.67p) twice: £10.79.
        self::assertSame(['goods_inc' => 1295, 'discount_inc' => 0, 'sale_inc' => 1295, 'sale_ex' => 1079, 'units' => 5], $o('9101'));
        self::assertSame(['goods_inc' => 1296, 'discount_inc' => 0, 'sale_inc' => 1296, 'sale_ex' => 1080, 'units' => 3], $o('9102'), '£12.99 - 3p; ex VAT £10.83 - 3p (-2.5p)');
        // 4 x (49p - 1p) + (49p + 99p) = £3.40 inc VAT; ex VAT 4 x 40p (48 / 1.2) + £1.23 (148 / 1.2 = 123.3): £2.83.
        self::assertSame(['goods_inc' => 245, 'discount_inc' => -95, 'sale_inc' => 340, 'sale_ex' => 283, 'units' => 5], $o('9103'));
        self::assertSame([-2, -2], array_map('intval', self::$db->column("SELECT sale_ex FROM sale_line WHERE order_ref = '9101' AND price_inc < 0 ORDER BY line_id")));
        self::assertSame(123, (int) self::$db->value("SELECT sale_ex FROM sale_line WHERE line_id = 'c5'"));
        self::assertSame(1079 + 1080 + 283, (int) self::$db->value('SELECT SUM(sale_ex) FROM profit_day WHERE channel_id = ?', [(int) $site->channelId]),
            'the day adds up the same money');
    }
}
