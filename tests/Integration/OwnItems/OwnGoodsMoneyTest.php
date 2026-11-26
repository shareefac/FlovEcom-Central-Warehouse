<?php

declare(strict_types=1);

namespace CW\Tests\Integration\OwnItems;

use CW\Caller;
use CW\Expenses\ExpenseViews;
use CW\Items\ItemCodes;
use CW\Items\ItemOwnerInvariants;
use CW\Items\ItemOwnerRefresh;
use CW\Items\ItemOwners;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;
use CW\Profit\RecostSales;
use CW\Tests\Support\StockTestCase;

/**
 * CW-1153 B2 (docs/decisions.md VB9-VB13, the money table): a sale line of a shop's OWN item (marked at the order's PAID time, sold by that
 * shop) is the shop's own goods: its units, sale, cost, units with no price and its share of an order-level refund (by the lines' sale inc
 * VAT, mulDivRound, a one-owner order wholly) are kept beside the whole figures, which never change; every OUR figure (summary, periods,
 * by shop / product, by order, no price, waiting, order refunds, real profit) is the whole less the own part; the shop's page shows the own
 * goods on their own line. Delivery, PayPal and the order count stay the shop's. Free (price 0) is a price, never "no price". The owner is
 * fixed at payment: a mark added later never moves a past sale. The re-cost of the marked items leaves every other line of a mixed order alone,
 * and the refresh tool marks the lines stored before a backdated mark without changing any cost. A rebuild of a day gives what was kept.
 */
final class OwnGoodsMoneyTest extends StockTestCase
{
    private const DAY = '2026-11-19';
    private const PAID = '2026-11-19T10:00:00Z';
    private Caller $vb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vb = $this->site('vapebig', 'live');
        self::$db->exec("UPDATE app_setting SET value_json = JSON_QUOTE('2026-11-01') WHERE setting_key = 'profit.real_from_vapebig'");
    }

    protected function tearDown(): void
    {
        self::$db->exec("UPDATE app_setting SET value_json = JSON_QUOTE('') WHERE setting_key = 'profit.real_from_vapebig'");
        parent::tearDown();
    }

    /** An item's buying price from $at, the cost job's mark far ahead (costs are fixed at once) unless $mark. */
    private static function price(int $sku, ?int $e4, string $at = '2026-11-16 00:00:00', string $mark = '2030-01-01'): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, 'start', ?, ?)",
            [$sku, $at, $at, 'start:' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', ?) AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at", [$mark]);
    }

    private static function mark(int $sku, string $from = '2026-11-16 00:00:00'): void
    {
        (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]), 'Vape Big\'s own goods', $from);
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
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VB1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => self::PAID,
            'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '12', 'method' => 'Royal Mail Tracked 48', 'charged_inc' => 399, 'refund_inc' => 0], 'order_refund_inc' => 0,
            'grand_inc' => 1498, 'parcels' => []];
    }

    private function store(string $ref, array $lines, array $over = []): void
    {
        $r = (new OrderSnapshots(self::$db))->store((int) $this->vb->channelId, $ref, self::body($lines, $over), 'api');
        self::assertContains($r['result'], ['stored', 'same']);
    }

    /** @return array<string, int> */
    private static function ownOf(string $ref): array
    {
        return array_map('intval', (array) self::$db->one('SELECT own_units, own_sale_ex, own_refund_ex, own_cost, own_no_cost FROM sale_order WHERE order_ref = ?', [$ref]));
    }

    /** @return array<string, int> */
    private function day(): array
    {
        return array_map('intval', (array) self::$db->one('SELECT orders, units, sale_ex, order_refund_ex, cost, units_no_cost, delivery_ex, own_units, own_sale_ex, '
            . 'own_refund_ex, own_cost, own_no_cost FROM profit_day WHERE channel_id = ? AND day = ?', [$this->vb->channelId, self::DAY]));
    }

    /** @return array{0: int, 1: int, 2: int} three items: Hayati (£3.75, Vape Big's own), Elux (free, Vape Big's own), ours (£2.00) */
    private function items(): array
    {
        $h = $this->item('legacy', 0, 'Hayati 4000 Blue Razz');
        $e = $this->item('legacy', 0, 'Elux Legend 4000 Grape');
        $o = $this->item('legacy', 0, 'Our liquid 10ml');
        self::price($h, 37500);
        self::price($e, 0);
        self::price($o, 20000);
        $this->listing($this->vb, '40101', $h);
        $this->listing($this->vb, '40102', $e);
        $this->listing($this->vb, '40103', $o);
        self::mark($h);
        self::mark($e);
        return [$h, $e, $o];
    }

    public function testTheMoneyTableEveryPennyOnceAndARebuildGivesWhatWasKept(): void
    {
        [$h, $e, $o] = $this->items();
        // A: one Hayati at £5.99 inc (4.99 ex), cost 3.75: all Vape Big's own
        $this->store('9001', [self::sl('8101', '40101', 599)]);
        self::assertSame(['own_units' => 1, 'own_sale_ex' => 499, 'own_refund_ex' => 0, 'own_cost' => 375, 'own_no_cost' => 0], self::ownOf('9001'));
        $f = (new ProfitViews(self::$db))->totals(self::DAY, self::DAY, (int) $this->vb->channelId);
        self::assertSame([0, 0, 0, 0], [$f['units'], $f['goods'], $f['cost'], $f['profit']], 'ours: nothing');
        self::assertSame([1, 499, 375, 124], [$f['own']['units'], $f['own']['goods'], $f['own']['cost'], $f['own']['profit']], 'the shop\'s own line: +1.24');
        self::assertSame(1, $f['orders'], 'the order still counts');
        // ... refunded in full at order level: wholly the shop's own (ours stays 0)
        $this->store('9001', [self::sl('8101', '40101', 599)], ['version' => 1001, 'order_refund_inc' => 599]);
        self::assertSame(['own_units' => 1, 'own_sale_ex' => 499, 'own_refund_ex' => 499, 'own_cost' => 375, 'own_no_cost' => 0], self::ownOf('9001'));
        // B: mixed: Hayati 5.99, ours 12.00, Elux 3.00 (free stock: price 0, a price), £3.00 refunded at order level
        $this->store('9002', [self::sl('8201', '40101', 599), self::sl('8202', '40103', 1200), self::sl('8203', '40102', 300)], ['order_refund_inc' => 300]);
        // lines ex: 499 + 1000 + 250 = 1749; refund ex = 1749 x 300 / 2099 = 250; own share = 250 x (599 + 300) / 2099 = 107; ours 143
        self::assertSame(['own_units' => 2, 'own_sale_ex' => 749, 'own_refund_ex' => 107, 'own_cost' => 375, 'own_no_cost' => 0], self::ownOf('9002'));
        self::assertSame(250, (int) self::$db->value("SELECT order_refund_ex FROM sale_order WHERE order_ref = '9002'"));
        self::assertSame('cw_cost', (string) self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '8203'"), 'free is a price');
        $d = $this->day();
        self::assertSame(['orders' => 2, 'units' => 4, 'sale_ex' => 2248, 'order_refund_ex' => 749, 'cost' => 950, 'units_no_cost' => 0, 'delivery_ex' => 666,
            'own_units' => 3, 'own_sale_ex' => 1248, 'own_refund_ex' => 606, 'own_cost' => 750, 'own_no_cost' => 0], $d, 'whole figures + the own part beside');
        $f = (new ProfitViews(self::$db))->totals(self::DAY, self::DAY, (int) $this->vb->channelId);
        self::assertSame([1, 857, 200, 657, 666, 0], [$f['units'], $f['goods'], $f['cost'], $f['profit'], $f['delivery'], $f['no_cost']], 'ours = whole - own');
        self::assertSame([3, 642, 750, -108], [$f['own']['units'], $f['own']['goods'], $f['own']['cost'], $f['own']['profit']]);
        // every penny once: ours + own = whole
        self::assertSame($d['sale_ex'] - $d['order_refund_ex'], $f['goods'] + $f['own']['goods']);
        self::assertSame($d['cost'], $f['cost'] + $f['own']['cost']);
        // a rebuild from the orders and lines gives exactly what was kept (the own figures too)
        ProfitDays::rebuild(self::$db, (int) $this->vb->channelId, self::DAY);
        self::assertSame($d, $this->day());
        self::assertSame([], ProfitDays::differences(self::$db, (int) $this->vb->channelId, self::DAY));
        self::assertSame([], ItemOwnerInvariants::check(self::$db));
        unset($h, $e, $o);
    }

    public function testEveryOurFigureLeavesTheShopsOwnGoodsOutAndThePagesShowThemApart(): void
    {
        [$h, $e, $o] = $this->items();
        $n = $this->item('legacy', 0, 'Hayati 4000 no price yet');
        $this->listing($this->vb, '40104', $n);
        self::mark($n);
        $this->store('9001', [self::sl('8101', '40101', 599), self::sl('8102', '40104', 599)]); // own only, one with no price
        $this->store('9002', [self::sl('8201', '40101', 599), self::sl('8202', '40103', 1200)]);
        $v = new ProfitViews(self::$db);
        $f = $v->totals(self::DAY, self::DAY, null);
        self::assertSame([1, 1000, 200, 0], [$f['units'], $f['goods'], $f['cost'], $f['no_cost']], 'all shops: ours');
        self::assertSame([3, 1497, 750, 1], [$f['own']['units'], $f['own']['goods'], $f['own']['cost'], $f['own']['no_cost']]);
        $p = $v->periods(self::DAY, self::DAY, (int) $this->vb->channelId, 'day');
        self::assertSame([1, 1000], [$p[0]['f']['units'], $p[0]['f']['goods']]);
        $byProduct = array_column($v->breakdown(self::DAY, self::DAY, null, 'product', 'profit')['rows'], 'f', 'id');
        self::assertSame([$o], array_keys($byProduct), 'only our items in our list by product');
        $byShop = $v->breakdown(self::DAY, self::DAY, null, 'shop', 'profit')['rows'];
        self::assertSame([1000, 200], [$byShop[0]['f']['goods'], $byShop[0]['f']['cost']]);
        self::assertSame([], $v->noCost(self::DAY, self::DAY, null), 'the no-price list is ours');
        self::assertSame([$n], array_column($v->noCost(self::DAY, self::DAY, null, true), 'sku_id'), 'the shop\'s own list apart');
        $orders = array_column($v->orders(self::DAY, self::DAY, null, '', false)['rows'], null, 'ref');
        self::assertSame([0, 0, null], [$orders['9001']['units'], $orders['9001']['sale'], $orders['9001']['shop_own']['profit']], 'own only: nothing of ours, own profit not known');
        self::assertSame([1, 1000, 200], [$orders['9002']['units'], $orders['9002']['sale'], $orders['9002']['cost']]);
        self::assertSame([499, 375], [$orders['9002']['shop_own']['goods'], $orders['9002']['shop_own']['cost']]);
        self::assertSame(['9002'], array_column($v->orders(self::DAY, self::DAY, null, '', true)['rows'], 'ref'), 'an own-only order has no margin of ours');
        $real = (new ExpenseViews(self::$db))->realProfit(self::DAY, self::DAY, (int) $this->vb->channelId, false);
        self::assertSame([1000, 200], [$real[0]['goods'], $real[0]['cost']]);
        self::assertSame([1497, 750], [$real[0]['own']['goods'], $real[0]['own']['cost']]);
        unset($h, $e);
    }

    public function testTheOwnerIsFixedAtPaymentAndAWaitingCostIsSplitByOwner(): void
    {
        $o = $this->item('legacy', 0, 'Our coil');
        self::price($o, 20000);
        $this->listing($this->vb, '40103', $o);
        $this->store('9003', [self::sl('8301', '40103', 1200)]);
        // marked a week later (a person: from now): the sale paid on the 19th stays ours, after any later snapshot
        (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$o]), 'from now');
        $this->store('9003', [self::sl('8301', '40103', 1200)], ['version' => 1001, 'status' => 'shipped']);
        self::assertNull(self::$db->value("SELECT item_owner FROM sale_line WHERE line_id = '8301'"));
        self::assertSame(1, (new ProfitViews(self::$db))->totals(self::DAY, self::DAY, null)['units']);
        // a line whose cost the cost job has not reached yet: waiting, counted on the owner's side
        $h = $this->item('legacy', 0, 'Hayati 4000 Cola');
        $this->listing($this->vb, '40101', $h);
        self::mark($h);
        self::price($h, 37500, '2026-11-16 00:00:00', '2026-11-19 09:00:00');
        $this->store('9004', [self::sl('8401', '40101', 599), self::sl('8402', '40103', 1200)]);
        self::assertNull(self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '8401'"));
        self::assertSame((int) $this->vb->channelId, (int) self::$db->value("SELECT item_owner FROM sale_line WHERE line_id = '8401'"), 'the owner before the cost');
        $f = (new ProfitViews(self::$db))->totals(self::DAY, self::DAY, null);
        self::assertSame([1, 1], [$f['pending'], $f['own']['pending']]);
    }

    public function testTheReCostOfTheMarkedItemsLeavesEveryOtherLineAloneAndTheRefreshChangesNoCost(): void
    {
        $h = $this->item('legacy', 0, 'Hayati 4000 Mint');
        $o = $this->item('legacy', 0, 'Our pods');
        $this->listing($this->vb, '40101', $h);
        $this->listing($this->vb, '40103', $o);
        self::price($o, null); // neither has a price when sold
        self::price($h, null);
        $this->store('9005', [self::sl('8501', '40101', 599), self::sl('8502', '40103', 1200)]);
        $this->store('9006', [self::sl('8601', '40101', 599)]);
        $before = self::$db->all("SELECT line_id, cost_source, unit_cost_e4, cost, costed_by, cost_at, sale_ex FROM sale_line WHERE line_id IN ('8502') ORDER BY line_id");
        $sale = (int) self::$db->value('SELECT SUM(sale_ex) FROM sale_line');
        // the kit: the items marked from the count (before these sales), the owner's price, the re-cost of THOSE items only, the refresh
        self::mark($h);
        self::price($h, 37500, '2026-11-16 00:00:00');
        $r = (new RecostSales(self::$db))->run('2026-11-01', self::DAY, ['none', 'cw_cost'], 'vapebig', true, ItemCodes::fromText(self::$db,
            (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$h])));
        self::assertSame(2, $r['lines']);
        self::assertSame($before, self::$db->all("SELECT line_id, cost_source, unit_cost_e4, cost, costed_by, cost_at, sale_ex FROM sale_line WHERE line_id IN ('8502') ORDER BY line_id"),
            'the other line of the mixed order: untouched');
        self::assertSame($sale, (int) self::$db->value('SELECT SUM(sale_ex) FROM sale_line'), 'sales never move');
        self::assertSame(750, (int) self::$db->value('SELECT SUM(cost) FROM sale_line'), 'the cost moved by exactly the marked items\' re-cost');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM sale_line WHERE item_owner IS NOT NULL'), 'the re-cost marked its lines too');
        // an order of the marked item whose cost was already fixed (so the re-cost does not read it): the refresh marks it, no cost moves
        $this->store('9007', [self::sl('8701', '40101', 599)], ['paid_at' => '2026-11-15T10:00:00Z', 'placed_at' => '2026-11-15T09:59:00Z']);
        $this->store('9008', [self::sl('8801', '40101', 599)]);
        self::$db->exec("UPDATE item_owner SET from_at = '2026-11-14 00:00:00' WHERE sku_id = ?", [$h]); // as if marked earlier: T's line is now behind
        $costs = self::$db->all('SELECT line_id, cost_source, unit_cost_e4, cost, costed_by, cost_at FROM sale_line ORDER BY line_id');
        $dry = (new ItemOwnerRefresh(self::$db))->run([$h], '2026-11-01', self::DAY, null, false);
        self::assertSame([1, 0], [$dry['refreshed'], $dry['waiting']]);
        $done = (new ItemOwnerRefresh(self::$db))->run([$h], '2026-11-01', self::DAY, null, true);
        self::assertSame(1, $done['refreshed']);
        self::assertSame($costs, self::$db->all('SELECT line_id, cost_source, unit_cost_e4, cost, costed_by, cost_at FROM sale_line ORDER BY line_id'), 'no cost changed');
        self::assertSame((int) $this->vb->channelId, (int) self::$db->value("SELECT item_owner FROM sale_line WHERE line_id = '8701'"));
        self::assertSame(0, (new ItemOwnerRefresh(self::$db))->run([$h], '2026-11-01', self::DAY, null, true)['refreshed'], 'a run again: nothing more');
        self::assertSame([], ItemOwnerInvariants::check(self::$db));
        foreach (['2026-11-15', self::DAY] as $day) {
            self::assertSame([], ProfitDays::differences(self::$db, (int) $this->vb->channelId, $day));
        }
    }

    public function testTheItemsFileIsReadStrictly(): void
    {
        $a = $this->item('legacy', 0, 'Hayati 4000 A');
        $code = (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$a]);
        self::assertSame([$a], ItemCodes::fromText(self::$db, "\n{$code}\r\n\n"));
        foreach (["{$code}\nCW-999999", "{$code}\n{$code}", "{$code}\nhayati", ''] as $bad) {
            try {
                ItemCodes::fromText(self::$db, $bad);
                self::fail('refused whole: ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('hayati', $e->getMessage(), 'lines are named by number, never by what they hold');
            }
        }
    }
}
