<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Caller;
use CW\Reorder\SalesHistoryImport;
use CW\Tests\Support\TestDb;

/**
 * The sales-history import (spec §7.2, docs/decisions.md I61) through bin/import_sales_history.php and the service: checks
 * (sha256, the same file, the site), the mapping report, the coverage rules (an overlap replaces, a gap is refused unless
 * --allow-gap), the site's latest stock replaced, the dry run, the uplift report, a failed load.
 */
final class SalesHistoryImportTest extends ReorderTestCase
{
    private int $vpg;
    private int $elux;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = $this->channelOf('vapeandgo');
        $this->channelOf('electrofag');
        $this->elux = $this->brandItem('Elux Legend Blue Razz', 'Elux');
        $this->listingOf($this->vpg, '101', $this->elux);
        $this->listingOf($this->vpg, '102', null); // unlinked
        // 104 has no listing at all: unknown
    }

    /** @return array{code: int, out: string, err: string} */
    private static function cli(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/import_sales_history.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /** The first export: 1-14 Sep. */
    private function first(string $dir, string $ts = '20261002T050000Z'): string
    {
        return self::exportFiles($dir, 'vapeandgo', '2026-09-01', '2026-09-14', [
            ['101', '2026-09-01', 3, 2, '5.85', '6.00', 0, 0],
            ['102', '2026-09-02', 1, 1, '1.95', '1.95', 0, 0],
            ['104', '2026-09-02', 0, 0, '0.00', '0.00', 1, 1],
            ['104', '2026-09-03', 0, 0, '0.00', '0.00', 1, 1],
            ['101', '2026-09-10', 4, 3, '7.80', '8.00', 0, 0],
            ['101', '2026-09-12', 2, 2, '3.90', '3.90', 0, 0],
        ], [['101', '2026-09-13', 0, 'Out-Of-Stock', 0]], [['101', '2026-09-14', 0, 'Out-Of-Stock', 1], ['102', '2026-09-14', 12, 'In-Stock', 1],
            ['103', '2026-09-14', 4, 'In-Stock', 1], ['104', '2026-09-14', 7, 'From-Warehouse', 1], ['105', '2026-09-14', 0, '', 0]],
            array_map(static fn (string $d): array => ['date' => $d, 'variants' => 5, 'unsellable' => 1], ['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14']),
            $ts);
    }

    public function testALoadWithItsMappingReportAndTheDemandBuild(): void
    {
        $dir = $this->tempDir();
        $r = self::cli('--channel=vapeandgo', '--manifest=' . $this->first($dir));
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('vapeandgo 2026-09-01 to 2026-09-14 batch ', $r['out']);
        self::assertStringContainsString('rows=6 units=12 unknown=2 rows / 2 units, unlinked=1 rows / 1 units, stock days=1, latest=5, snapshot days=5', $r['out']);
        self::assertMatchesRegularExpression('/2026-09\s+14\s+12\s+0\.9/', $r['out'], 'the month table: 14 days, 12 units, 0.9 a day');
        self::assertStringContainsString('demand: 1 items, 1 listings', $r['out']);
        $b = self::$db->one('SELECT * FROM sales_import_batch');
        self::assertNotNull($b);
        self::assertSame(['loaded', 'cps', '2026-09-01', '2026-09-14', 6, 6, 12, 2, 2, 1, 1, 1, 'system:import_sales_history', null],
            [$b['status'], $b['source'], $b['date_from'], $b['date_to'], (int) $b['rows_read'], (int) $b['rows_loaded'], (int) $b['units_loaded'], (int) $b['unknown_rows'],
                (int) $b['unknown_units'], (int) $b['unlinked_rows'], (int) $b['unlinked_units'], (int) $b['stock_rows'], $b['actor'], $b['error']]);
        self::assertSame('vapeandgo_sales_2026-09-01_2026-09-14_20261002T050000Z.csv.gz', $b['sales_file']);
        self::assertNotNull($b['stock_sha256']);
        self::assertNotNull($b['latest_sha256']);
        self::assertSame('2026-10-02 05:00:00.000000', $b['exported_at']);
        self::assertSame([['101', '2026-09-01', 3, 2, '5.85', '6.00', 0, 0], ['102', '2026-09-02', 1, 1, '1.95', '1.95', 0, 0], ['104', '2026-09-02', 0, 0, '0.00', '0.00', 1, 1],
            ['104', '2026-09-03', 0, 0, '0.00', '0.00', 1, 1], ['101', '2026-09-10', 4, 3, '7.80', '8.00', 0, 0], ['101', '2026-09-12', 2, 2, '3.90', '3.90', 0, 0]],
            array_map('array_values', self::$db->all('SELECT external_variant_id, sale_date, units_online, orders_online, net_online, gross_online, units_office, orders_office '
                . 'FROM sales_history_day ORDER BY sale_date, external_variant_id')), 'every row is kept whatever its mapping');
        self::assertSame(5, (int) self::$db->value('SELECT COUNT(*) FROM channel_snapshot_day WHERE channel_id = ?', [$this->vpg]));
        self::assertSame([['101', '2026-09-13', 0, 'Out-Of-Stock', 0]], array_map('array_values',
            self::$db->all('SELECT external_variant_id, stock_date, stock, stock_mode, allow_backorders FROM listing_stock_day')));
        self::assertSame([['101', 0, 1], ['102', 12, 1], ['103', 4, 1], ['104', 7, 1], ['105', 0, 0]], array_map('array_values',
            self::$db->all('SELECT external_variant_id, stock, sellable FROM listing_stock_latest ORDER BY external_variant_id')));
        self::assertNull(self::$db->value("SELECT stock_mode FROM listing_stock_latest WHERE external_variant_id = '105'"));
        // The demand of the linked item: 9 units over 1-14 Sep, 13 valid days (13 Sep was out of stock): 0.6923 a day; plain 30-day average 0.3.
        self::assertSame(['0.6923', '0.3000', 1], array_values(array_map(static fn (mixed $v): mixed => is_int($v) ? $v : (string) $v,
            (array) self::$db->one('SELECT rate, rate_raw_30, excluded_oos FROM reorder_demand WHERE sku_id = ?', [$this->elux]))));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'sales.import' AND entity_id = ?", [(string) $b['id']]));
        // The same file again: nothing done.
        $again = self::cli('--channel=vapeandgo', '--manifest=' . glob($dir . '/*.manifest.json')[0]);
        self::assertSame(0, $again['code']);
        self::assertStringContainsString("already loaded as batch {$b['id']}", $again['out']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sales_import_batch'));
    }

    public function testShaMismatchSiteMismatchAndUsage(): void
    {
        $dir = $this->tempDir();
        $manifest = $this->first($dir);
        $file = glob($dir . '/*_stockdays_*.csv.gz')[0];
        $gz = gzencode("site,variant_id,stock_date,stock,stock_mode,allow_backorders\nvapeandgo,101,2026-09-12,0,Out-Of-Stock,0\n");
        file_put_contents($file, $gz);
        $r = self::cli('--channel=vapeandgo', '--manifest=' . $manifest);
        self::assertSame(1, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('sha_mismatch', $r['err']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sales_import_batch'), 'checked before anything is written');
        $r = self::cli('--channel=electrofag', '--manifest=' . $this->first($this->tempDir()));
        self::assertSame(2, $r['code']);
        self::assertStringContainsString('site_mismatch: the export is of site vapeandgo, not electrofag', $r['err']);
        self::assertSame(2, self::cli('--manifest=' . $manifest)['code']);
        self::assertSame(2, self::cli('--channel=nosuch', '--manifest=' . $manifest)['code']);
        $r = self::cli('--channel=vapeandgo', '--manifest=' . $dir . '/missing.manifest.json');
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('bad_manifest', $r['err']);
    }

    public function testAnOverlapReplacesItsDaysAndAGapIsRefused(): void
    {
        self::assertSame(0, self::cli('--channel=vapeandgo', '--no-build', '--manifest=' . $this->first($this->tempDir()))['code']);
        // 8-21 Sep: the overlap 8-14 is replaced (the 10 Sep row changes, the 12 Sep row goes), 1-7 Sep stays.
        $m2 = self::exportFiles($this->tempDir(), 'vapeandgo', '2026-09-08', '2026-09-21', [
            ['101', '2026-09-10', 6, 4, '11.70', '12.00', 0, 0],
            ['101', '2026-09-20', 1, 1, '1.95', '1.95', 0, 0],
        ], [], [['101', '2026-09-21', 9, 'In-Stock', 1]], [], '20261002T060000Z');
        $r = self::cli('--channel=vapeandgo', '--no-build', '--manifest=' . $m2);
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertSame([['101', '2026-09-01', 3], ['102', '2026-09-02', 1], ['104', '2026-09-02', 0], ['104', '2026-09-03', 0], ['101', '2026-09-10', 6], ['101', '2026-09-20', 1]],
            array_map('array_values', self::$db->all('SELECT external_variant_id, sale_date, units_online FROM sales_history_day ORDER BY sale_date, external_variant_id')));
        self::assertSame([], self::$db->all('SELECT * FROM listing_stock_day'), 'the stock days of the overlap were replaced (by none)');
        self::assertSame([], self::$db->all("SELECT * FROM channel_snapshot_day WHERE snapshot_date >= '2026-09-08'"));
        self::assertSame([['101', '2026-09-21', 9]], array_map('array_values', self::$db->all('SELECT external_variant_id, snapshot_date, stock FROM listing_stock_latest')),
            "the site's latest stock is replaced");
        self::assertSame(['2026-09-01', '2026-09-21'], array_values((array) self::$db->one("SELECT MIN(date_from), MAX(date_to) FROM sales_import_batch WHERE status = 'loaded'")));
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM sales_import_batch'), 'the first batch row stays (history of what was loaded)');
        // 25-30 Sep would leave 22-24 Sep without history: refused, unless --allow-gap.
        $m3 = self::exportFiles($this->tempDir(), 'vapeandgo', '2026-09-25', '2026-09-30', [['101', '2026-09-25', 2, 1, '3.90', '3.90', 0, 0]], null, null, [],
            '20261002T070000Z');
        $r = self::cli('--channel=vapeandgo', '--no-build', '--manifest=' . $m3);
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('history_gap', $r['err']);
        self::assertSame(0, self::cli('--channel=vapeandgo', '--no-build', '--allow-gap', '--manifest=' . $m3)['code']);
        // Before the coverage: adjacent is fine, a gap is not.
        $m4 = self::exportFiles($this->tempDir(), 'vapeandgo', '2026-08-01', '2026-08-20', [['101', '2026-08-02', 2, 1, '3.90', '3.90', 0, 0]], null, null, [],
            '20261002T080000Z');
        self::assertStringContainsString('history_gap', self::cli('--channel=vapeandgo', '--no-build', '--manifest=' . $m4)['err']);
        $m5 = self::exportFiles($this->tempDir(), 'vapeandgo', '2026-08-01', '2026-08-31', [['101', '2026-08-02', 2, 1, '3.90', '3.90', 0, 0]], null, null, [],
            '20261002T090000Z');
        self::assertSame(0, self::cli('--channel=vapeandgo', '--no-build', '--manifest=' . $m5)['code']);
        self::assertSame(['2026-08-01', '2026-09-30'], array_values((array) self::$db->one("SELECT MIN(date_from), MAX(date_to) FROM sales_import_batch WHERE status = 'loaded'")));
    }

    /**
     * Review findings (I77): a quarantined listing keeps its item but its sales never reach the demand, so they are reported
     * as unlinked; and an export of an earlier week does not put an older site-stock snapshot back.
     */
    public function testQuarantinedSalesAreUnlinkedAndAnOlderSnapshotIsKept(): void
    {
        $this->listingOf($this->vpg, '106', $this->elux, 1, 'quarantined');
        $m = self::exportFiles($this->tempDir(), 'vapeandgo', '2026-09-01', '2026-09-14', [
            ['101', '2026-09-01', 3, 2, '5.85', '6.00', 0, 0],
            ['106', '2026-09-02', 5, 1, '9.75', '9.75', 0, 0],
        ], null, [['101', '2026-09-14', 4, 'In-Stock', 1]], [], '20261002T050000Z');
        $r = self::cli('--channel=vapeandgo', '--no-build', '--manifest=' . $m);
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('unknown=0 rows / 0 units, unlinked=1 rows / 5 units', $r['out']);
        self::assertSame([1, 5], array_map('intval', array_values((array) self::$db->one('SELECT unlinked_rows, unlinked_units FROM sales_import_batch'))));
        // 1-7 Sep again, with the snapshot of the 7th: the sales of the week are replaced, the newer site stock of the 14th stays.
        $older = self::exportFiles($this->tempDir(), 'vapeandgo', '2026-09-01', '2026-09-07', [['101', '2026-09-01', 2, 1, '3.90', '3.90', 0, 0]], null,
            [['101', '2026-09-07', 1, 'In-Stock', 1], ['102', '2026-09-07', 3, 'In-Stock', 1]], [], '20261002T060000Z');
        $r = self::cli('--channel=vapeandgo', '--no-build', '--manifest=' . $older);
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('the stored snapshot of 2026-09-14 is newer than this export\'s: kept', $r['out']);
        self::assertSame([['101', '2026-09-14', 4]], array_map('array_values', self::$db->all('SELECT external_variant_id, snapshot_date, stock FROM listing_stock_latest')));
        self::assertSame(2, (int) self::$db->value("SELECT units_online FROM sales_history_day WHERE external_variant_id = '101' AND sale_date = '2026-09-01'"));
    }

    public function testADryRunWritesNothing(): void
    {
        $r = (new SalesHistoryImport(self::$db))->import(Caller::system('test'), 'vapeandgo', $this->first($this->tempDir()), true);
        self::assertSame(['dry_run', null], [$r['status'], $r['batch_id']]);
        self::assertSame(['rows_read' => 6, 'rows_loaded' => 6, 'units_loaded' => 12, 'unknown_rows' => 2, 'unknown_units' => 2, 'unlinked_rows' => 1, 'unlinked_units' => 1,
            'stock_rows' => 1, 'latest_rows' => 5, 'latest_kept_older' => 0, 'snapshot_days' => 5], $r['counts']);
        foreach (['sales_import_batch', 'sales_history_day', 'listing_stock_day', 'listing_stock_latest', 'channel_snapshot_day'] as $t) {
            self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM {$t}"), $t);
        }
        $cli = self::cli('--channel=vapeandgo', '--dry-run', '--manifest=' . $this->first($this->tempDir()));
        self::assertSame(0, $cli['code']);
        self::assertStringContainsString('(dry run, nothing written)', $cli['out']);
        self::assertStringNotContainsString('demand:', $cli['out']);
    }

    public function testTheStockpilingUpliftReport(): void
    {
        $this->stockpiling();
        $rows = [];
        foreach (self::daily('101', '2026-07-01', '2026-09-30', 10) as $r) {
            $rows[$r[1]] = [$r[0], $r[1], $r[2], 1, $r[3], $r[3], 0, 0];
        }
        foreach (range(0, 8) as $i) {
            $d = sprintf('2026-09-%02d', 14 + $i);
            $u = $i % 3 === 2 ? 15 : 14;
            $rows[$d] = ['101', $d, $u, 1, self::money($u * 195), self::money($u * 195), 0, 0];
        }
        $m = self::exportFiles($this->tempDir(), 'vapeandgo', '2026-07-01', '2026-09-30', array_values($rows));
        $r = (new SalesHistoryImport(self::$db))->import(Caller::system('test'), 'vapeandgo', $m, true);
        self::assertSame([['2026-07', 31, 310, '10.0'], ['2026-08', 31, 310, '10.0'], ['2026-09', 30, 339, '11.3']],
            array_map(static fn (array $x): array => [$x['month'], $x['days'], $x['units'], $x['per_day']], $r['months']));
        self::assertSame([['2026-09-14', '2026-09-22', '14.3', '10.0', '+43.3 %']],
            array_map(static fn (array $a): array => [$a['from'], $a['to'], $a['per_day'], $a['baseline_per_day'], $a['uplift']], $r['anomalies']));
        $cli = self::cli('--channel=vapeandgo', '--no-build', '--manifest=' . $m);
        self::assertSame(0, $cli['code'], $cli['err']);
        self::assertStringContainsString('anomaly 2026-09-14 to 2026-09-22 "Pre-duty stockpiling (+43% units/day)": 14.3 units/day in the window vs 10.0 in Jul-Aug (+43.3 %)',
            $cli['out']);
    }

    public function testAFailedLoadIsMarkedAndItsEarlierSlicesStay(): void
    {
        // Row 3 lies in the second slice and goes back in time: the first slice is loaded, then the file is refused.
        $m = self::exportFiles($this->tempDir(), 'vapeandgo', '2026-09-01', '2026-09-14', [
            ['101', '2026-09-01', 3, 2, '5.85', '6.00', 0, 0],
            ['101', '2026-09-09', 1, 1, '1.95', '1.95', 0, 0],
            ['101', '2026-09-08', 1, 1, '1.95', '1.95', 0, 0],
        ]);
        $r = self::cli('--channel=vapeandgo', '--manifest=' . $m);
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('bad_file', $r['err']);
        $b = self::$db->one('SELECT status, error, finished_at FROM sales_import_batch');
        self::assertSame('failed', $b['status'] ?? null);
        self::assertStringContainsString('sale_date goes back', (string) $b['error']);
        self::assertSame([['101', '2026-09-01']], array_map('array_values', self::$db->all('SELECT external_variant_id, sale_date FROM sales_history_day')),
            'the first slice (1-7 Sep) stays; the failed batch is not coverage');
        // Running it again fails again (the file is bad); a corrected export loads and replaces.
        self::assertSame(1, self::cli('--channel=vapeandgo', '--manifest=' . $m)['code']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sales_import_batch'), 'the same batch row is reused');
        self::assertSame(0, self::cli('--channel=vapeandgo', '--no-build', '--manifest=' . $this->first($this->tempDir()))['code']);
        self::assertSame(6, (int) self::$db->value('SELECT COUNT(*) FROM sales_history_day'));
    }
}
