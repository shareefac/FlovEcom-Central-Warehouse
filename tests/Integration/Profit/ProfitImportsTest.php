<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Profit\ErpSalesImport;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\RecostSales;
use CW\Profit\SnapshotImport;
use CW\Tests\Support\StockTestCase;

/**
 * The batch tools of profit step 1 (docs/decisions.md PR3, PR16, PR18-PR21): the bulk snapshot files give exactly what the API gives (the
 * same service), a refused line is reported and the rest go on, the .sum is checked; ERPNext's cost at each sale (plain and gzip day files,
 * one reconciliation line a day, returns reported, orders after CW's own price left alone, run again: the same); the deliberate re-cost.
 */
final class ProfitImportsTest extends StockTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_profit_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        if (str_starts_with(basename($this->dir), 'cw_profit_')) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    private static function price(int $sku, ?int $e4, string $at = '2026-11-16 00:00:00'): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, 'start', ?, ?)",
            [$sku, $at, $at, 'test:' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
        // The cost job has read everything (its mark far ahead): costs are fixed at once.
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
    }

    private static function sl(string $id, string $variant, int $price, array $over = []): array
    {
        return $over + ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    private static function body(array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z',
            'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'pp', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '3', 'method' => 'DPD', 'charged_inc' => 599, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 1799,
            'parcels' => [['parcel_ref' => 'odl:1', 'tracking_no' => '1550', 'carrier' => 'dpd', 'service_code' => '', 'service' => 'DPD', 'status' => 'generated',
                'label_at' => null, 'shipped_at' => null, 'manual' => false, 'partial' => false]]];
    }

    /** @return list<array<string, mixed>> a store's orders and lines without the store, the times and the source */
    private static function stored(Caller $site): array
    {
        $out = [];
        foreach (self::$db->all('SELECT * FROM sale_order WHERE channel_id = ? ORDER BY order_ref', [$site->channelId]) as $o) {
            unset($o['channel_id'], $o['first_at'], $o['updated_at']);
            $o['lines'] = array_map(static function (array $l): array {
                unset($l['channel_id'], $l['updated_at'], $l['cost_at'], $l['listing_id'], $l['sku_id']);
                return $l;
            }, self::$db->all('SELECT * FROM sale_line WHERE channel_id = ? AND order_ref = ? ORDER BY line_id', [$site->channelId, $o['order_ref']]));
            $out[] = $o;
        }
        return $out;
    }

    public function testTheBulkFileGivesWhatTheApiGives(): void
    {
        $api = $this->site('vapeandgo', 'live');
        $bulk = $this->site('vapebig', 'off');
        $a = $this->item('legacy');
        self::price($a, 12000);
        $this->listing($api, '1', $a);
        $this->listing($bulk, '1', $a);
        $orders = [
            '501' => self::body([self::sl('1', '1', 1200, ['discount_inc' => -2]), self::sl('2', '1', 1200, ['cancelled' => true])]),
            '502' => self::body([self::sl('3', '1', 0, ['free' => true])], ['parent_ref' => '501', 'gateway' => 'office', 'delivery' => null, 'grand_inc' => 0]),
            '503' => self::body([self::sl('4', '1', 1200, ['refund_inc' => 1200])], ['version' => 7]),
        ];
        $s = new OrderSnapshots(self::$db);
        $lines = [];
        $goods = 0;
        $lineCount = 0;
        $delivery = 0;
        foreach ($orders as $ref => $b) {
            $s->store((int) $api->channelId, (string) $ref, $b, 'api');
            $lines[] = json_encode(['ref' => (string) $ref] + $b, JSON_THROW_ON_ERROR);
            foreach ($b['lines'] as $l) {
                $goods += $l['price_inc'] - $l['discount_inc'];
                $lineCount++;
            }
            $delivery += $b['delivery']['charged_inc'] ?? 0;
        }
        $lines[] = json_encode(['ref' => '504'] + self::body([self::sl('5', '1', -101)]), JSON_THROW_ON_ERROR); // refused: a price below -£1.00 (CONTRACT change 8)
        $lines[] = json_encode(['ref' => '503'] + self::body([self::sl('4', '1', 1200)], ['version' => 6]), JSON_THROW_ON_ERROR); // older: ignored
        $file = $this->dir . '/snapshots_vapebig_2026-11-19.jsonl';
        file_put_contents($file, implode("\n", $lines) . "\n");
        // Its .sum (the connector's; its totals are not compared while a line is refused: its store, its day and the file's sha256 are).
        file_put_contents($this->dir . '/snapshots_vapebig_2026-11-19.sum', json_encode(['channel' => 'vapebig', 'day' => '2026-11-19', 'orders' => 5, 'lines' => 5,
            'goods_inc' => 0, 'delivery_inc' => 0, 'file_sha256' => hash_file('sha256', $file)]));
        $log = [];
        $r = (new SnapshotImport(self::$db))->file('vapebig', $file, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(['stored' => 3, 'same' => 0, 'older_ignored' => 1], $r['results']);
        self::assertSame(1, $r['refused']);
        self::assertStringContainsString('line 4: refused bad_snapshot (lines[0].price_inc)', implode("\n", $log));
        self::assertSame(self::stored($api), self::stored($bulk), 'the backfill and the live feed can never disagree');
        foreach (['2026-11-19'] as $day) {
            self::assertSame(self::$db->all('SELECT orders, resends, units, sale_ex, order_refund_ex, cost, units_no_cost, delivery_ex FROM profit_day WHERE channel_id = ?',
                [$api->channelId]), self::$db->all('SELECT orders, resends, units, sale_ex, order_refund_ex, cost, units_no_cost, delivery_ex FROM profit_day WHERE channel_id = ?',
                [$bulk->channelId]), $day);
        }
        // The second run: the same again; a wrong store's file is refused; the .sum is checked.
        self::assertSame(['stored' => 0, 'same' => 3, 'older_ignored' => 1], (new SnapshotImport(self::$db))->file('vapebig', $file, false, static fn (string $m) => null)['results']);
        try {
            (new SnapshotImport(self::$db))->file('vapeandgo', $file, true, static fn (string $m) => null);
            self::fail('another store\'s file');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('vapebig', $e->getMessage());
        }
        array_splice($lines, 3, 1); // without the refused line
        file_put_contents($file, implode("\n", $lines) . "\n");
        $sum = ['channel' => 'vapebig', 'day' => '2026-11-19', 'orders' => 4, 'lines' => $lineCount + 1, 'goods_inc' => $goods + 1200, 'delivery_inc' => $delivery + 599,
            'file_sha256' => hash_file('sha256', $file)];
        file_put_contents($this->dir . '/snapshots_vapebig_2026-11-19.sum', json_encode($sum) . "\n");
        self::assertTrue((new SnapshotImport(self::$db))->file('vapebig', $file, true, static fn (string $m) => null)['sum_ok']);
        file_put_contents($this->dir . '/snapshots_vapebig_2026-11-19.sum', json_encode(['goods_inc' => 1] + $sum) . "\n");
        self::assertFalse((new SnapshotImport(self::$db))->file('vapebig', $file, true, static fn (string $m) => null)['sum_ok']);
    }

    private static function tsv(array $rows): string
    {
        $head = ['name', 'posting_date', 'creation', 'custom_order_id', 'is_return', 'return_against', 'docstatus', 'net_total', 'total_taxes_and_charges', 'grand_total',
            'discount_amount', 'idx', 'item_code', 'qty', 'rate', 'net_rate', 'net_amount', 'incoming_rate', 'warehouse'];
        $out = implode("\t", $head) . "\n";
        foreach ($rows as $i => [$name, $order, $return, $item, $qty, $net, $rate]) {
            $out .= implode("\t", [$name, '2026-04-10', '2026-04-10 10:00:00.000000', $order, $return, 'NULL', '1', '10.00', '2.00', '12.00', '0.000000000', $i + 1,
                $item, $qty, '1.00', '1.00', $net, $rate, 'Stores - VPG']) . "\n";
        }
        return $out;
    }

    public function testErpNextsCostAtEachSaleFromPlainAndCompressedDayFiles(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        self::price($a, 10000);
        self::price($b, 10000);
        $this->listing($site, '101', $a);
        $this->listing($site, '102', $b, 2);
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, '1001', self::body([self::sl('L1', '101', 1200), self::sl('L2', '101', 1200), self::sl('L3', '102', 2400)],
            ['paid_at' => '2026-04-10T10:00:00Z']), 'bulk');
        $s->store((int) $site->channelId, '1002', self::body([self::sl('L4', '101', 1200)], ['paid_at' => '2026-11-17T10:00:00Z']), 'bulk');
        self::assertSame('estimate', (string) self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = 'L1'"));
        $day = '2026-04-10';
        $before = self::$db->one('SELECT cost FROM profit_day WHERE day = ?', [$day]);
        self::assertSame('400', (string) $before['cost'], '1 + 1 + 2 CW units at £1.00');
        // si_2026-04-10.tsv.gz: order 1001 (P101 twice at 0.90, P102 at 3.00 for a pack of 2), its delivery, a return, an invoice with no order,
        // an order CW does not hold; si_2026-11-17.tsv: order 1002 (paid after CW's own price starts).
        file_put_contents($this->dir . '/si_2026-04-10.tsv.gz', gzencode(self::tsv([
            ['SI-1', '1001', '0', 'P101', '2.000000000', '16.660000000', '0.900000000'],
            ['SI-1', '1001', '0', 'P102', '1.000000000', '20.000000000', '3.000000000'],
            ['SI-1', '1001', '0', 'Shipping Charge', '1.000000000', '4.990000000', '0'],
            ['SI-2', '1001', '1', 'P101', '-1.000000000', '-8.330000000', '0.900000000'],
            ['SI-3', 'NULL', '0', 'P101', '1.000000000', '8.330000000', '0.900000000'],
            ['SI-4', '999999', '0', 'P101', '1.000000000', '8.330000000', '0.900000000'],
        ])));
        file_put_contents($this->dir . '/si_2026-11-17.tsv', self::tsv([['SI-5', '1002', '0', 'P101', '1.000000000', '10.000000000', '0.500000000']]));
        file_put_contents($this->dir . '/si_2026-11-18.tsv.part', 'half written');
        $log = [];
        $import = new ErpSalesImport(self::$db);
        $import->run($this->dir, 'vapeandgo', null, null, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame('estimate', (string) self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = 'L1'"), 'a dry run writes nothing');
        self::assertCount(2, $log, 'one line a day; a file still being written is not read');
        self::assertStringContainsString('2026-04-10 erp orders 2 goods GBP 44.99 cost GBP 5.70 | cw orders 1 goods GBP 40.00 | lines costed 3', $log[0]);
        self::assertStringContainsString('orders not in cw 1', $log[0]);
        self::assertStringContainsString('returns 1 (-GBP 8.33), no order id 1', $log[0]);
        self::assertStringContainsString('2026-11-17 erp orders 1', $log[1]);
        self::assertStringContainsString('paid on or after the cost start 1', $log[1]);
        $r = $import->run($this->dir, 'vapeandgo', null, null, true, static fn (string $m) => null);
        self::assertSame(['days' => 2, 'orders' => 1, 'lines' => 3, 'refused' => 0], $r);
        $rows = self::$db->all("SELECT line_id, unit_cost_e4, cost, cost_source, costed_by FROM sale_line WHERE order_ref IN ('1001', '1002') ORDER BY line_id");
        self::assertSame([['L1', '9000', '90', 'erp_at_sale', 'erp_import'], ['L2', '9000', '90', 'erp_at_sale', 'erp_import'],
            ['L3', '15000', '300', 'erp_at_sale', 'erp_import'], ['L4', '10000', '100', 'cw_cost', 'sale']],
            array_map(static fn (array $l): array => array_map('strval', array_values($l)), $rows), 'per CW unit: £3.00 for a pack of 2 is £1.50 each');
        self::assertSame('480', (string) self::$db->value('SELECT cost FROM profit_day WHERE day = ?', [$day]), 'the summary follows');
        $kept = self::$db->all('SELECT * FROM profit_item_day WHERE day = ? ORDER BY sku_id', [$day]);
        ProfitDays::rebuild(self::$db, (int) $site->channelId, $day);
        self::assertSame($kept, self::$db->all('SELECT * FROM profit_item_day WHERE day = ? ORDER BY sku_id', [$day]));
        self::assertSame(['days' => 1, 'orders' => 1, 'lines' => 3, 'refused' => 0], $import->run($this->dir, 'vapeandgo', $day, $day, true, static fn (string $m) => null),
            'run again: the same costs again');
        self::assertSame('480', (string) self::$db->value('SELECT cost FROM profit_day WHERE day = ?', [$day]));
    }

    public function testTheDeliberateReCost(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        self::price($a, 10000);
        $this->listing($site, '1', $a);
        $this->listing($site, '2', $b);
        $this->listing($site, '3', null);
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, '1', self::body([self::sl('L1', '1', 1200), self::sl('L2', '2', 1200), self::sl('L3', '3', 1200)]), 'api');
        self::assertSame(2, (int) self::$db->value("SELECT units_no_cost FROM sale_order WHERE order_ref = '1'"));
        // The two prices are found later: b gets one, and the listing of variant 3 is linked to a.
        self::price($b, 20000);
        self::$db->exec("UPDATE channel_listing SET sku_id = ?, status = 'mapped' WHERE channel_id = ? AND external_variant_id = '3'", [$a, $site->channelId]);
        $dry = (new RecostSales(self::$db))->run('2026-11-19', '2026-11-19', ['none'], null, false);
        self::assertSame(['orders' => 1, 'lines' => 2, 'moves' => ['none > cw_cost' => 2]], $dry);
        self::assertSame(2, (int) self::$db->value("SELECT units_no_cost FROM sale_order WHERE order_ref = '1'"), 'a dry run writes nothing');
        (new RecostSales(self::$db))->run('2026-11-19', '2026-11-19', ['none'], 'vapeandgo', true);
        $rows = self::$db->all("SELECT line_id, sku_id, unit_cost_e4, cost_source, costed_by FROM sale_line WHERE order_ref = '1' ORDER BY line_id");
        self::assertSame([['L1', (string) $a, '10000', 'cw_cost', 'sale'], ['L2', (string) $b, '20000', 'cw_cost', 'recost'], ['L3', (string) $a, '10000', 'cw_cost', 'recost']],
            array_map(static fn (array $l): array => array_map('strval', array_values($l)), $rows));
        self::assertSame(['0', '400'], [(string) self::$db->value("SELECT units_no_cost FROM sale_order WHERE order_ref = '1'"),
            (string) self::$db->value("SELECT cost FROM sale_order WHERE order_ref = '1'")]);
        self::assertSame(['orders' => 0, 'lines' => 0, 'moves' => []], (new RecostSales(self::$db))->run('2026-11-19', '2026-11-19', ['none'], null, true), 'nothing left');
        $this->expectException(\InvalidArgumentException::class);
        (new RecostSales(self::$db))->run('2026-11-19', '2026-11-19', ['guess'], null, false);
    }
}
