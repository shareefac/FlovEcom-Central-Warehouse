<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitViews;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * The lead's look at the screens on real data (51417a3 on a copy of live CW, 50 real days), each shown failing first: an order's lines are
 * one row per product, cost of one unit, where the cost came from and state, with its units counted, the totals the same (PR84); "incomplete"
 * is said only when the units without a known cost are more than 0.5% of the units, while their count stays on the page (PR85).
 */
final class ProfitScreensRealDataTest extends KernelUiTestCase
{
    /** @return list<list<string>> */
    private static function rows(UiResponse $r, string $class): array
    {
        $xp = new \DOMXPath($r->dom());
        $out = [];
        foreach ($xp->query("//table[contains(@class, \"{$class}\")]/tbody/tr") ?: [] as $tr) {
            $row = [];
            foreach ($xp->query('th|td', $tr) ?: [] as $c) {
                $row[] = trim((string) preg_replace('/\s+/u', ' ', (string) $c->textContent));
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function sl(string $id, string $variant, int $price, array $over = []): array
    {
        return $over + ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    private function priced(int $sku, int $e4): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', '2026-11-16', '2026-11-16')", [$sku, $e4]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, ?)",
            [$sku, 'start:' . $sku, $e4]);
    }

    public function testAnOrdersLinesAreOneRowPerProductCostAndState(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 0, 'Twenty');
        $b = $this->item('legacy', 0, 'Single');
        $this->priced($a, 5000);
        $this->priced($b, 5000);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01')");
        $this->listing($site, '1', $a);
        $this->listing($site, '2', $b);
        // Twenty units of one product at £1.20 inc VAT, one of them refunded; one more cancelled; one unit of another product.
        $lines = [];
        for ($i = 1; $i <= 20; $i++) {
            $lines[] = self::sl('9100001' . $i, '1', 120, $i === 20 ? ['refund_inc' => 120] : []);
        }
        $lines[] = self::sl('9100002', '1', 120, ['cancelled' => true]);
        $lines[] = self::sl('9100003', '2', 600);
        $paid = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-1 hour')->format('Y-m-d\TH:i:s\Z');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '1', ['v' => 1, 'version' => 1, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => $paid,
            'paid_at' => $paid, 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines, 'delivery' => null, 'order_refund_inc' => 0,
            'grand_inc' => 3000, 'parcels' => []], 'api');
        $fin = $this->signIn($this->uiUser('finance'));
        $rows = self::rows($fin->get('/ui/profit/orders/view', ['store' => (string) $site->channelId, 'ref' => '1']), 'profit-order-lines');
        $cells = array_map(static fn (array $r): array => [$r[0], $r[1], $r[2], $r[5], $r[6]], $rows);
        self::assertCount(4, $rows, 'one row each: 19 sold, 1 refunded, 1 cancelled of the first product; 1 of the second');
        self::assertContains(['Twenty CW-' . sprintf('%06d', $a), '19', '£19.00', '£9.50', '£9.50'], $cells, '19 x £1.00 ex VAT, 19 x 50p');
        self::assertContains(['Single CW-' . sprintf('%06d', $b), '1', '£5.00', '£0.50', '£4.50'], $cells);
        // The totals as the order holds them (the refunded unit did not come back: it still counts and costs): 21 units, £24.00 goods, £10.50 cost.
        $o = array_map('intval', (array) self::$db->one("SELECT units, sale_ex, cost FROM sale_order WHERE order_ref = '1'"));
        self::assertSame(['units' => 21, 'sale_ex' => 2400, 'cost' => 1050], $o);
        $pence = static fn (string $m): int => (int) round(100 * (float) str_replace(['£', ','], '', $m));
        self::assertSame([$o['units'], $o['sale_ex'], $o['cost']], [array_sum(array_map(static fn (array $r): int => (int) $r[1], $rows)),
            array_sum(array_map(static fn (array $r): int => $pence($r[2]), $rows)), array_sum(array_map(static fn (array $r): int => $pence($r[5]), $rows))],
            'the rows add up to the order: units, goods, cost');
        self::assertContains(['Twenty CW-' . sprintf('%06d', $a) . ' Refunded', '1', '£0.00', '£0.50', '-£0.50'], $cells, 'the refunded one on its own row');
    }

    public function testIncompleteIsSaidOnlyAboveHalfAPercentOfTheUnits(): void
    {
        // The rule (PR85): the units with no buying price or waiting for one are more than 0.5% of the units.
        self::assertFalse(ProfitViews::figures(['units' => 1000, 'sale_ex' => 100000, 'cost' => 60000, 'units_no_cost' => 5])['incomplete'], '5 of 1,000 (0.5%): not said');
        self::assertTrue(ProfitViews::figures(['units' => 1000, 'sale_ex' => 100000, 'cost' => 60000, 'units_no_cost' => 6])['incomplete'], '6 of 1,000: said');
        self::assertTrue(ProfitViews::figures(['units' => 1000, 'sale_ex' => 100000, 'cost' => 60000, 'units_no_cost' => 3, 'pending' => 3])['incomplete'],
            'waiting units count with them');
        self::assertSame(5, ProfitViews::figures(['units' => 1000, 'sale_ex' => 100000, 'cost' => 60000, 'units_no_cost' => 5])['no_cost'], 'the count is always there');
        self::assertTrue(ProfitViews::figures(['units' => 1, 'sale_ex' => 100, 'units_no_cost' => 1])['incomplete']);

        // On the page: a day of 400 units with 1 without a price (0.25%) shows its count and no "incomplete"; with 3 (0.75%), "incomplete".
        $site = $this->site('vapeandgo', 'live');
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->format('Y-m-d');
        self::$db->exec('INSERT INTO profit_day (channel_id, day, orders, units, sale_ex, cost, units_no_cost) VALUES (?, ?, 100, 400, 400000, 240000, 1)',
            [(int) $site->channelId, $day]);
        $fin = $this->signIn($this->uiUser('finance'));
        $today = self::rows($fin->get('/ui/profit'), 'profit-summary')[0];
        self::assertStringNotContainsString(Words::PROFIT['incomplete'], implode(' ', $today), '1 of 400: the margin without "incomplete"');
        self::assertContains('1', $today, 'the count of units with no price stays');
        self::$db->exec('UPDATE profit_day SET units_no_cost = 3 WHERE channel_id = ?', [(int) $site->channelId]);
        $today = self::rows($fin->get('/ui/profit'), 'profit-summary')[0];
        self::assertStringContainsString(Words::PROFIT['incomplete'], implode(' ', $today), '3 of 400: "incomplete"');
    }

    public function testAListByMarginRanksARowWithATinyShareWithoutAPriceByItsMargin(): void
    {
        $ch = self::makeChannel('vapeandgo', 'live');
        $day = '2026-11-18';
        // Product 900001: 1 of 1,000 units without a price, margin 60%; product 900002: complete, 50%.
        self::$db->exec('INSERT INTO profit_item_day (channel_id, day, sku_id, units, sale_ex, cost, units_no_cost) VALUES (?, ?, 900001, 1000, 100000, 40000, 1), '
            . '(?, ?, 900002, 10, 1000, 500, 0)', [$ch, $day, $ch, $day]);
        $r = (new ProfitViews(self::$db))->breakdown($day, $day, null, 'product', 'margin');
        self::assertSame([900001, 900002], array_column($r['rows'], 'id'), 'the 60% first: one unit in a thousand does not push it after the 50%');
        self::assertFalse($r['rows'][0]['f']['incomplete']);
        self::assertSame(1, $r['rows'][0]['f']['no_cost']);
    }
}
