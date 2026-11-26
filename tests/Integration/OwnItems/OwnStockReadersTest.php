<?php

declare(strict_types=1);

namespace CW\Tests\Integration\OwnItems;

use CW\Caller;
use CW\Documents\Documents;
use CW\Items\ItemOwners;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Purchasing;
use CW\Reorder\ReorderList;
use CW\Reports\ReportViews;
use CW\Tests\Support\StockTestCase;

/**
 * CW-1153 B3 (docs/decisions.md VB14-VB16): a shop's own items stay in MAIN and sell as before, but they are never OUR stock value (any
 * warehouse), low stock or slow stock, nor in another shop's purchasing report, nor suggested by our reorder list. The stock value report
 * shows them apart, at their buying price (free = £0, a price; none yet = counted apart). Taken off the list, the item is ours again everywhere.
 */
final class OwnStockReadersTest extends StockTestCase
{
    private int $h;
    private int $e;
    private int $o;
    private \CW\Caller $vb;
    private \CW\Caller $vpg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vb = $this->site('vapebig');
        $this->vpg = $this->site('vapeandgo');
        $this->h = $this->item('legacy', 0, 'Hayati 4000 Blue Razz');
        $this->e = $this->item('legacy', 0, 'Elux Legend 4000 Grape');
        $this->o = $this->item('legacy', 0, 'Our liquid 10ml');
        $this->book('goods_in', $this->h, 100, 'MAIN', null, '3.625000');
        $this->book('goods_in', $this->e, 40);
        $this->book('goods_in', $this->o, 10, 'MAIN', null, '2.000000');
        $this->listing($this->vb, 'H', $this->h);
        $this->listing($this->vb, 'E', $this->e);
        $this->listing($this->vpg, 'O', $this->o);
        foreach ([[$this->h, 37500], [$this->e, 0]] as [$sku, $e4]) {
            self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'none', '2026-11-16', '2026-11-16')", [$sku, $e4]);
        }
        foreach ([$this->h, $this->e] as $sku) {
            (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]), 'Vape Big\'s own goods',
                '2026-11-16 00:00:00');
        }
        // the report job's sums (CW's average so far, ReportRefresh): as it would have read the three goods-in
        foreach ([[$this->h, 100, '362.50'], [$this->e, 0, '0'], [$this->o, 10, '20.00']] as [$sku, $units, $value]) {
            self::$db->exec('INSERT INTO report_item (sku_id, in_units, in_value, first_in_on) VALUES (?, ?, ?, ?) AS n ON DUPLICATE KEY UPDATE in_units = n.in_units, '
                . 'in_value = n.in_value', [$sku, $units, $value, '2026-09-26']);
        }
    }

    public function testOurStockValueLeavesThemOutAndShowsThemApartAtTheirPrice(): void
    {
        $r = new ReportViews(self::$db);
        $f = $r->valueFigures();
        self::assertSame(['20.00', 10, 1], [number_format((float) $f['value'], 2, '.', ''), $f['costed'], $f['items']], 'ours: only our liquid');
        self::assertSame([['shop' => 'VAPEBIG test site', 'items' => 2, 'units' => 140, 'value' => '375.00', 'unpriced' => 0]], $f['own'],
            '100 x 3.75 + 40 x 0 (free is a price)');
        self::assertSame([$this->o], array_column($r->valueByProduct('', false, 1)['rows'], 'sku_id'));
        self::assertSame(10, (int) array_sum(array_column($r->valueByWarehouse(), 'units')));
        self::assertSame(10, (int) array_sum(array_column($r->valueByBrand(), 'units')));
        // as of a past day: the periods of that moment (the mark started on the 16th): before it, ours
        $before = $r->at('2026-11-10 00:00:00.000000')->bounded(fn (): array => $r->at('2026-11-10 00:00:00.000000')->valueFigures());
        self::assertSame([], $before['own']);
        // taken off the list: ours again from now
        (new ItemOwners(self::$db))->remove(Caller::system('own_items_kit'), (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$this->e]), 'ours again');
        self::assertSame(2, (new ReportViews(self::$db))->valueFigures()['items']);
    }

    public function testLowStockAndSlowStockAreOursAlone(): void
    {
        $r = new ReportViews(self::$db);
        $low = $r->lowStock('low', '', 1000, 1);
        self::assertNotContains($this->h, array_column($low['rows'], 'sku_id'));
        self::assertContains($this->o, array_column($low['rows'], 'sku_id'), 'our item under 1,000 units is low (the default)');
        $slow = $r->slow('dead', '', '2026-11-26', 1, 2, 1);
        self::assertSame([$this->o], array_column($slow['rows'], 'sku_id'));
    }

    public function testAnotherShopsPurchasingReportNeverHoldsThem(): void
    {
        $p = new Purchasing(self::$db);
        $ids = static fn (array $r): array => array_column($r['items'], 'sku_id');
        self::assertSame([$this->o], array_values(array_intersect([$this->h, $this->e, $this->o], $ids($p->report(0, 500, (int) $this->vpg->channelId)))));
        self::assertSame([$this->h, $this->e, $this->o], array_values(array_intersect([$this->h, $this->e, $this->o], $ids($p->report(0, 500, (int) $this->vb->channelId)))));
        self::assertCount(3, array_intersect([$this->h, $this->e, $this->o], $ids($p->report())), 'staff and tools see every item');
    }

    public function testOurReorderListNeverSuggestsThem(): void
    {
        foreach ([$this->h, $this->o] as $sku) {
            self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 500, 'system:test') AS n ON DUPLICATE KEY UPDATE min_stock = n.min_stock", [$sku]);
        }
        $lines = array_column((new ReorderList(self::$db, new PurchaseOrders(self::$db, new Documents(self::$db, []))))
            ->lines(ReorderList::filters(['show' => 'all', 'stock' => 'cw']), [$this->h, $this->o]), null, 'sku_id');
        self::assertContains('own_item', $lines[$this->h]['flags']);
        self::assertStringContainsString('own item', (string) $lines[$this->h]['never']);
        self::assertSame(0, $lines[$this->h]['packs']);
        self::assertNull($lines[$this->o]['never']);
    }
}
