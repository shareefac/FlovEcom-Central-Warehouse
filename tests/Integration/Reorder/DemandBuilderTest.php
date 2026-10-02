<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Reorder\DemandBuilder;
use CW\Reorder\DemandMath;
use CW\Reorder\PromoDetector;
use CW\Tests\Support\MigrationFixture;
use CW\Tests\Support\TestDb;

/**
 * DemandBuilder (spec §7.3, docs/decisions.md I62-I63) on two synthetic sites of 120 days: a 10-pack listing (u = 10), the
 * stockpiling window, an Elux-like promotion, out-of-stock days, a first sale inside the window; the stored rows equal
 * DemandMath on the same inputs; merged items are skipped; a rebuild changes nothing; a second build at once is refused.
 */
final class DemandBuilderTest extends ReorderTestCase
{
    private const VPG_END = '2026-10-01';
    private const EF_END = '2026-09-30';
    private const START = '2026-06-04';

    /** @return array{vpg: int, ef: int, elux: int, mary: int, merged: int, minOnly: int, unsold: int} */
    private function scenario(): array
    {
        $vpg = $this->channelOf('vapeandgo');
        $ef = $this->channelOf('electrofag');
        $elux = $this->brandItem('Elux Legend 3500 Blue Razz', 'Elux');
        $mary = $this->brandItem('Lost Mary BM600 Cola', 'Lost Mary');
        $merged = $this->brandItem('Elux duplicate', 'Elux');
        $minOnly = $this->brandItem('Spare coils', null);
        $unsold = $this->brandItem('Never sold', 'Elux');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$elux, $merged]);
        self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 12, 'system:test')", [$minOnly]);
        $this->listingOf($vpg, '501', $elux);
        $this->listingOf($ef, '9501', $elux, 10);
        $this->listingOf($vpg, '502', $mary);
        $this->listingOf($vpg, '503', $merged);
        $this->listingOf($vpg, '504', $unsold);
        $this->listingOf($vpg, '505', null);
        $this->stockpiling();

        // Vape and Go: Elux 30 a day at £1.95; 45 a day in the stockpiling; 120 a day at £1.65 on 23-24 Sep (the promotion);
        // nothing on 27-28 Sep, unsellable on the snapshot; Lost Mary from 20 Sep at 8 a day; an unlinked variant; the merged item.
        $rows = [];
        foreach (self::daily('501', self::START, self::VPG_END, 30) as $r) {
            $rows[$r[1]] = $r;
        }
        foreach (self::daily('501', '2026-09-14', '2026-09-22', 45) as $r) {
            $rows[$r[1]] = $r;
        }
        foreach (self::daily('501', '2026-09-23', '2026-09-24', 120, 165) as $r) {
            $rows[$r[1]] = $r;
        }
        unset($rows['2026-09-27'], $rows['2026-09-28']);
        $vpgRows = array_values($rows);
        array_push($vpgRows, ...self::daily('502', '2026-09-20', self::VPG_END, 8, 549), ...self::daily('503', self::START, self::VPG_END, 5),
            ...self::daily('505', '2026-09-01', self::VPG_END, 3));
        $snapshot = array_map(static fn (int $d): string => DemandMath::date($d), range(DemandMath::day('2026-09-24'), DemandMath::day(self::VPG_END)));
        $this->history($vpg, self::START, self::VPG_END, $vpgRows, $snapshot, [['501', '2026-09-27'], ['501', '2026-09-28'], ['501', '2026-09-29'], ['502', '2026-09-29']]);
        // Electrofag: the Elux 10-pack, 1 a day, with an office sale on every Monday.
        $ef1 = [];
        foreach (self::daily('9501', self::START, self::EF_END, 1, 1650) as $r) {
            $ef1[] = (int) gmdate('N', DemandMath::day($r[1]) * 86_400) === 1 ? [$r[0], $r[1], $r[2], $r[3], 1] : $r;
        }
        $this->history($ef, self::START, self::EF_END, $ef1);
        return ['vpg' => $vpg, 'ef' => $ef, 'elux' => $elux, 'mary' => $mary, 'merged' => $merged, 'minOnly' => $minOnly, 'unsold' => $unsold];
    }

    public function testTheStoredDemandIsDemandMathOnTheSameInputs(): void
    {
        $s = $this->scenario();
        $r = $this->builder()->rebuild();
        self::assertSame(3, $r['items'], 'Elux, Lost Mary, the minimum-stock item; not the merged one, not the one that never sold');
        self::assertSame(['electrofag' => ['from' => self::START, 'to' => self::EF_END], 'vapeandgo' => ['from' => self::START, 'to' => self::VPG_END]], $r['channels']);
        self::assertSame(['electrofag' => 0, 'vapeandgo' => 2], $r['promo_days']);
        self::assertSame([$s['elux'], $s['mary'], $s['minOnly']], array_map('intval', self::$db->column('SELECT sku_id FROM reorder_demand ORDER BY sku_id')));

        // The expectation from the pure maths: Elux on Vape and Go, with the promotion flags of its brand totals.
        $math = new DemandMath();
        $end = DemandMath::day(self::VPG_END);
        $q = [];
        $daily = [];
        foreach (self::$db->all("SELECT sale_date, units_online + units_office AS q, units_online, net_online FROM sales_history_day WHERE external_variant_id = '501'") as $h) {
            $q[DemandMath::day((string) $h['sale_date'])] = (int) $h['q'];
            $daily[DemandMath::day((string) $h['sale_date'])] = [(int) $h['units_online'], DemandBuilder::pence((string) $h['net_online'])];
        }
        $anomaly = array_fill_keys(range(DemandMath::day('2026-09-14'), DemandMath::day('2026-09-22')), (int) self::$db->value('SELECT id FROM demand_anomaly'));
        $promo = (new PromoDetector())->flag($daily, $end - 91 - 28 + 1, $end, array_map(static fn (): bool => true, $anomaly));
        self::assertSame([DemandMath::day('2026-09-23') => true, DemandMath::day('2026-09-24') => true], $promo);
        $unsellable = [DemandMath::day('2026-09-27') => true, DemandMath::day('2026-09-28') => true, DemandMath::day('2026-09-29') => true];
        $snapshot = array_fill_keys(range(DemandMath::day('2026-09-24'), $end), true);
        $v = $math->listing($end, DemandMath::day(self::START), $q, array_intersect_key($anomaly, array_flip(range($end - 90, $end))), $promo, $snapshot, $unsellable);
        self::assertNotNull($v);
        // Electrofag: 1 a day, 2 on Mondays (an office sale).
        $efq = [];
        foreach (self::$db->all("SELECT sale_date, units_online + units_office AS q FROM sales_history_day WHERE external_variant_id = '9501'") as $h) {
            $efq[DemandMath::day((string) $h['sale_date'])] = (int) $h['q'];
        }
        $ef = $math->listing(DemandMath::day(self::EF_END), DemandMath::day(self::START), $efq, array_fill_keys(range(DemandMath::day('2026-09-14'),
            DemandMath::day('2026-09-22')), 1));
        self::assertNotNull($ef);
        $d = $this->demandOf($s['elux']);
        self::assertSame(DemandMath::e4($v['rate_e4'] + 10 * $ef['rate_e4']), $d['rate']);
        self::assertSame(DemandMath::e4((int) $v['r_short_e4'] + 10 * (int) $ef['r_short_e4']), $d['rate_short']);
        self::assertSame(DemandMath::e4((int) $v['r_long_e4'] + 10 * (int) $ef['r_long_e4']), $d['rate_long']);
        self::assertSame(DemandMath::e4(DemandMath::rawRateE4([$v['sum_raw30'], 10 * $ef['sum_raw30']])), $d['rate_raw_30']);
        self::assertSame([$v['valid_short'], $v['valid_long'], 9, 2, 2, 0, $v['capped']], [(int) $d['valid_days_short'], (int) $d['valid_days_long'],
            (int) $d['excluded_anomaly'], (int) $d['excluded_promo'], (int) $d['excluded_oos'], (int) $d['excluded_other'], (int) $d['capped_days']],
            'the main listing (Vape and Go): 9 stockpiling days, 2 promotion days, 2 out of stock (29 Sep sold: not out of stock)');
        self::assertLessThan(DemandMath::toE4((string) $d['rate_raw_30']), DemandMath::toE4((string) $d['rate']), 'the stockpiling and the promotion inflate the plain average');
        self::assertSame($v['units_365'] + 10 * $ef['units_365'], (int) $d['units_365']);
        self::assertSame([self::START, self::VPG_END], [$d['first_sale_date'], $d['last_sale_date']]);
        // Detail: per listing, main first; u = 10 on Electrofag.
        $l = $d['detail']['listings'];
        self::assertSame([['vapeandgo', '501', 1], ['electrofag', '9501', 10]], array_map(static fn (array $x): array => [$x['channel'], $x['variant'], $x['u']], $l));
        self::assertEquals(['nodata' => 0, 'before_first' => 0, 'anomaly' => 9, 'promo' => 2, 'oos' => 2], $l[0]['excluded'], 'JSON objects come back in MySQL\'s key order');
        self::assertEquals([['label' => 'Pre-duty stockpiling (+43% units/day)', 'from' => '2026-09-14', 'to' => '2026-09-22', 'short' => 9, 'long' => 9]],
            array_map(static fn (array $a): array => array_diff_key($a, ['id' => 1]), $l[0]['anomalies']));
        self::assertSame([DemandMath::e4($v['rate_e4']), DemandMath::e4($ef['rate_e4']), self::EF_END], [$l[0]['rate'], $l[1]['rate'], $l[1]['window_end']]);
        // 12 calendar months to the newest history end (Oct 2026), in central units.
        self::assertSame(['2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'],
            array_keys($d['monthly']));
        self::assertSame(30 + 0, $d['monthly']['2026-10'], 'Elux on 1 Oct: 30 on Vape and Go, nothing on Electrofag (its history ends 30 Sep)');
        self::assertSame(0, $d['monthly']['2026-05']);

        // Lost Mary: first sale 20 Sep, inside the window: 8 a day, not 8 × 12 / 91.
        $m = $this->demandOf($s['mary']);
        self::assertSame('8.0000', $m['rate']);
        self::assertSame(79, (int) $m['excluded_other'], 'before the first sale');
        self::assertSame('2026-09-20', $m['first_sale_date']);
        // The minimum-stock item without history: a row of zeros.
        $z = $this->demandOf($s['minOnly']);
        self::assertSame(['0.0000', '0.0000', null, null, []], [$z['rate'], $z['rate_raw_30'], $z['rate_short'], $z['first_sale_date'], $z['detail']['listings']]);
    }

    public function testRebuildIsIdempotentAndOneAtATime(): void
    {
        $this->scenario();
        $this->builder()->rebuild();
        $before = self::$db->all('SELECT sku_id, rate, rate_short, rate_long, rate_raw_30, CAST(detail AS CHAR) AS d, CAST(monthly AS CHAR) AS m FROM reorder_demand ORDER BY sku_id');
        $this->builder()->rebuild();
        self::assertSame($before, self::$db->all('SELECT sku_id, rate, rate_short, rate_long, rate_raw_30, CAST(detail AS CHAR) AS d, CAST(monthly AS CHAR) AS m '
            . 'FROM reorder_demand ORDER BY sku_id'));
        // Another session holds the build lock: 409 build_running, nothing changed; free again afterwards.
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [DemandBuilder::LOCK_PREFIX . TestDb::name()]));
        try {
            self::refused(409, 'build_running', fn () => $this->builder()->rebuild());
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [DemandBuilder::LOCK_PREFIX . TestDb::name()]);
        }
        self::assertSame(3, $this->builder()->rebuild()['items']);
        self::assertSame(1, (int) self::$db->value('SELECT IS_FREE_LOCK(?)', [DemandBuilder::LOCK_PREFIX . TestDb::name()]));
    }

    public function testSettingsAnomaliesAndLinksAreReadAtBuildTime(): void
    {
        $s = $this->scenario();
        $this->builder()->rebuild();
        $before = $this->demandOf($s['elux']);
        // Ending the window brings its days back; a brand window for another brand changes nothing for Elux.
        self::$db->exec("UPDATE demand_anomaly SET is_active = 0, ended_at = NOW(6)");
        $this->stockpiling('Lost Mary relaunch', null, 'Lost Mary', '2026-09-25', '2026-09-26');
        $this->builder()->rebuild();
        $after = $this->demandOf($s['elux']);
        self::assertSame(0, (int) $after['excluded_anomaly']);
        self::assertGreaterThan(DemandMath::toE4((string) $before['rate']), DemandMath::toE4((string) $after['rate']));
        self::assertSame(2, (int) $this->demandOf($s['mary'])['excluded_anomaly']);
        // A window on one site only.
        self::$db->exec("UPDATE demand_anomaly SET is_active = 0, ended_at = NOW(6)");
        $this->stockpiling('Electrofag outage', $s['ef']);
        $this->builder()->rebuild();
        $d = $this->demandOf($s['elux']);
        self::assertSame([0, 9], [(int) $d['detail']['listings'][0]['excluded']['anomaly'], (int) $d['detail']['listings'][1]['excluded']['anomaly']]);
        // A listing linked later makes its history count (the unlinked variant 505 now belongs to Lost Mary, × 2).
        self::$db->exec("UPDATE channel_listing SET sku_id = ?, status = 'mapped', units_per_item = 2 WHERE external_variant_id = '505'", [$s['mary']]);
        $this->builder()->rebuild();
        self::assertSame(['vapeandgo', 'vapeandgo'], array_column($this->demandOf($s['mary'])['detail']['listings'], 'channel'));
        self::assertSame('14.0000', $this->demandOf($s['mary'])['rate'], '8 + 2 × 3');
        // The windows are settings: a 14/56-day blend at 0.7.
        $this->setting('reorder.short_window_days', '14');
        $this->setting('reorder.long_window_days', '56');
        $this->setting('reorder.short_weight', '"0.70"');
        $this->builder()->rebuild();
        $l = $this->demandOf($s['elux'])['detail']['listings'][0];
        self::assertSame(56, $l['valid_long'] + array_sum($l['excluded']));
        self::assertSame(14, $l['valid_short'] + array_sum(array_intersect_key($l['excluded_short'], array_flip(['nodata', 'before_first', 'anomaly', 'promo', 'oos']))));
        // A bad setting stops the build (configuration error), nothing is written.
        $this->setting('reorder.min_valid_days_short', '0');
        self::refused(500, 'bad_setting', fn () => $this->builder()->rebuild());
    }

    public function testTheItemPageView(): void
    {
        $s = $this->scenario();
        $item = $this->builder()->item($s['elux']);
        self::assertNotNull($item);
        $days = array_column($item['listings'][0]['days'], null, 'date');
        self::assertCount(91, $days);
        self::assertSame(['q' => 45, 'reason' => 'anomaly', 'capped' => false, 'anomaly' => 'Pre-duty stockpiling (+43% units/day)'],
            array_diff_key($days['2026-09-15'], ['date' => 1]));
        self::assertSame('promo', $days['2026-09-23']['reason']);
        self::assertSame('oos', $days['2026-09-27']['reason']);
        self::assertNull($days['2026-09-29']['reason'], 'sold 30 on an unsellable day: counted');
        self::assertSame([], $this->builder()->item($s['unsold'])['listings'] ?? null, 'never sold: no listing contributes');
    }

    /**
     * OPT-IN (CW_REORDER_PERF=1): the build at the spec's scale on a scratch schema (cw_test_<slot>_perf): 15,000 linked items
     * of 100 brands, ~2,000 selling a day for 365 days (~180k rows in the 91-day window, ~730k in the year), eight snapshot
     * days with 2,000 unsellable variants each. Target (spec §7.3): at most 60 s on staging. Prints the time on stderr.
     */
    public function testTheBuildAtScale(): void
    {
        if (getenv('CW_REORDER_PERF') !== '1') {
            self::markTestSkipped('opt-in: CW_REORDER_PERF=1');
        }
        [$db, $dir] = MigrationFixture::upTo('perf', '0011_reorder.sql');
        try {
            $db->exec("INSERT INTO channel (code, name) VALUES ('vapeandgo', 'Vape and Go')");
            $db->exec('CREATE TABLE nums (n INT NOT NULL, PRIMARY KEY (n))');
            for ($i = 0; $i < 15_000; $i += 1000) {
                $db->exec('INSERT INTO nums (n) VALUES ' . implode(', ', array_map(static fn (int $n): string => "({$n})", range($i, $i + 999))));
            }
            $db->exec("INSERT INTO sku (id, code, name, brand) SELECT n + 1, CONCAT('CW-', LPAD(n + 1, 6, '0')), CONCAT('Item ', n), CONCAT('Brand ', n % 100) FROM nums");
            $db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) SELECT 1, CAST(n + 1 AS CHAR), n + 1, 1 + (n % 7 = 0) * 9, "
                . "'mapped' FROM nums");
            $db->exec("INSERT INTO sales_import_batch (channel_id, source, date_from, date_to, sales_file, sales_sha256, manifest, exported_at, status, actor) VALUES "
                . "(1, 'cps', '2025-10-02', '2026-10-01', 'perf.csv.gz', REPEAT('a', 64), '{}', NOW(6), 'loaded', 'system:perf')");
            $t = hrtime(true);
            $db->exec("INSERT INTO sales_history_day (channel_id, external_variant_id, sale_date, units_online, net_online, gross_online, batch_id) "
                . "SELECT 1, CAST(v.n + 1 AS CHAR), DATE_ADD('2025-10-02', INTERVAL d.n DAY), 1 + (v.n + d.n) % 9, (1 + (v.n + d.n) % 9) * 1.95, (1 + (v.n + d.n) % 9) * 1.95, 1 "
                . 'FROM nums v JOIN nums d ON d.n < 365 WHERE (v.n * 7 + d.n * 13) % 15 < 2');
            $db->exec("INSERT INTO channel_snapshot_day (channel_id, snapshot_date, variants, unsellable, batch_id) SELECT 1, DATE_ADD('2026-09-24', INTERVAL n DAY), 15000, 2000, 1 "
                . 'FROM nums WHERE n < 8');
            $db->exec("INSERT INTO listing_stock_day (channel_id, external_variant_id, stock_date, stock, batch_id) SELECT 1, CAST(v.n + 1 AS CHAR), DATE_ADD('2026-09-24', "
                . 'INTERVAL d.n DAY), 0, 1 FROM nums v JOIN nums d ON d.n < 8 WHERE v.n % 7 = d.n');
            $rows = (int) $db->value("SELECT COUNT(*) FROM sales_history_day WHERE sale_date > '2026-07-02'");
            $all = (int) $db->value('SELECT COUNT(*) FROM sales_history_day');
            $load = (hrtime(true) - $t) / 1e9;
            $t = hrtime(true);
            $b = (new DemandBuilder($db))->rebuild();
            $s = (hrtime(true) - $t) / 1e9;
            fwrite(STDERR, sprintf("[perf] %d rows in the 91-day window (%d in the year, loaded in %.1f s): build %d items, %d listings in %.1f s; promotion days %s\n",
                $rows, $all, $load, $b['items'], $b['listings'], $s, json_encode($b['promo_days'], JSON_THROW_ON_ERROR)));
            self::assertSame(15_000, $b['items']);
            self::assertLessThan(60.0, $s, 'spec §7.3: at most 60 s for ~15k items and ~180k rows in 91 days');
            // The list itself at that scale (review finding, I84): every line is computed on each page view, Why for the
            // page of 200 only.
            $list = new \CW\Reorder\ReorderList($db, new \CW\PurchaseOrders\PurchaseOrders($db, new \CW\Documents\Documents($db,
                \CW\Documents\DocumentHandlers::all($db))));
            foreach (['cw', 'site'] as $stock) {
                $t = hrtime(true);
                $all = $list->lines(\CW\Reorder\ReorderList::filters(['show' => 'all', 'stock' => $stock]));
                $page = $list->explain(array_slice($all, 0, \CW\Reorder\ReorderList::PAGE));
                $ms = (hrtime(true) - $t) / 1e6;
                fwrite(STDERR, sprintf("[perf] reorder list, stock=%s: %d lines and the Why of %d in %.0f ms\n", $stock, count($all), count($page), $ms));
                self::assertCount(15_000, $all);
                self::assertLessThan(20_000.0, $ms, 'a page view stays well inside the UI pool limits');
            }
        } finally {
            MigrationFixture::drop('perf', $dir);
        }
    }
}
