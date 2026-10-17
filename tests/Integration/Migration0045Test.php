<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Reports\ReportRefresh;
use CW\Stock;
use CW\Tests\Support\MigrationFixture;
use PHPUnit\Framework\TestCase;

final class Migration0045Test extends TestCase
{
    private ?string $dir = null;

    protected function setUp(): void
    {
        if (getenv('CW_TEST_DB') === '0') {
            self::markTestSkipped('CW_TEST_DB=0: database tests disabled');
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m45', $this->dir);
        }
    }

    public function testPaymentBackfillPreservesSourcesAndRebuildsDerivedReports(): void
    {
        [$db, $this->dir] = MigrationFixture::upTo('m45', '0041_incoming_stock.sql');
        $channel = $db->insert("INSERT INTO channel (code, name) VALUES ('m45', 'Migration 45')");
        $sku = $db->insert("INSERT INTO sku (code, name) VALUES ('CW-000001', 'Migration item')");
        $main = (int) $db->value("SELECT id FROM warehouse WHERE code = 'MAIN'");
        $listing = $db->insert("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, status) VALUES (?, 'M', ?, 'mapped')", [$channel, $sku]);
        $res = $db->insert("INSERT INTO reservation (channel_id, order_ref, status, committed_at) VALUES (?, 'old', 'committed', '2026-09-26 12:00:00')", [$channel]);
        foreach ([['paid', 'cancelled', $sku], ['unpaid', 'cancelled', $sku], ['unknown', 'cancelled', null], ['active', 'allocated', $sku], ['released', 'released', $sku]] as [$unit, $state, $item]) {
            $db->exec('INSERT INTO reservation_unit (channel_id, unit_id, reservation_id, listing_id, sku_id, warehouse_id, state) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$channel, $unit, $res, $listing, $item, $main, $state]);
        }
        $db->transaction(static function (Db $db) use ($channel, $sku, $main): void {
            $stock = new Stock($db);
            $stock->lock([[$main, $sku]]);
            $m = ['actor' => 'system:test', 'effective_at' => '2026-03-01 12:00:00.000000'];
            $stock->apply($main, $sku, 'on_hand', 10, $m + ['type' => 'goods_in', 'unit_cost' => '2.000000', 'cost_source' => 'manual']);
            $sale = $m + ['channel_id' => $channel, 'order_ref' => 'old'];
            $stock->apply($main, $sku, 'allocated', 1, $sale + ['type' => 'commit', 'unit_id' => 'paid']);
            $stock->apply($main, $sku, 'allocated', -1, $sale + ['type' => 'cancel', 'unit_id' => 'paid']);
            $stock->apply($main, $sku, 'held', 1, $sale + ['type' => 'reserve', 'unit_id' => 'unpaid']);
            $stock->apply($main, $sku, 'held', -1, $sale + ['type' => 'cancel', 'unit_id' => 'unpaid']);
            $stock->flush();
        });
        $db->exec("INSERT INTO report_item (sku_id, seq_done, in_units, in_value, first_in_on, last_sale_on) VALUES (?, 1, 10, 20, '2026-03-01', '2026-09-26')", [$sku]);
        $db->exec("INSERT INTO report_refresh (id, history_batch_id, sales_through, ran_at) VALUES (1, 0, '2026-09-27 12:00:00', '2026-09-27 12:00:00')");
        $units = $db->all('SELECT unit_id, state, updated_at FROM reservation_unit ORDER BY unit_id');
        $ledger = $db->all('SELECT * FROM stock_ledger ORDER BY id');

        MigrationFixture::migrateRest($db, $this->dir, '0045_xhigh_fixes_c.sql');
        self::assertSame(['active' => 1, 'paid' => 1, 'released' => 0, 'unknown' => null, 'unpaid' => 0],
            array_column($db->all('SELECT unit_id, was_paid FROM reservation_unit ORDER BY unit_id'), 'was_paid', 'unit_id'));
        self::assertSame($units, $db->all('SELECT unit_id, state, updated_at FROM reservation_unit ORDER BY unit_id'));
        self::assertSame($ledger, $db->all('SELECT * FROM stock_ledger ORDER BY id'));
        self::assertSame([0, 0, '0.000000', null], array_values((array) $db->one(
            'SELECT seq_done, in_units, CAST(in_value AS CHAR), last_sale_on FROM report_item WHERE sku_id = ?', [$sku])));
        self::assertNull($db->value('SELECT sales_through FROM report_refresh WHERE id = 1'));
        self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM report_item'), 'summary rows are retained');

        $job = new ReportRefresh($db, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-09-27 12:00:00', new \DateTimeZone('UTC')));
        $job->run(true, null, 366);
        self::assertSame([1, 10, '20.000000', '2026-03-01', '2026-09-26', null], array_values((array) $db->one(
            'SELECT seq_done, in_units, CAST(in_value AS CHAR), first_in_on, last_sale_on, last_other_sale_on FROM report_item WHERE sku_id = ?', [$sku])));
        self::assertSame(3, (int) $db->value("SELECT units_sold FROM report_sales_day WHERE sale_date = '2026-09-26' AND channel_id = ?", [$channel]),
            'known unpaid cancellation excluded; historical unknown retains its previous reporting behaviour');
    }
}
