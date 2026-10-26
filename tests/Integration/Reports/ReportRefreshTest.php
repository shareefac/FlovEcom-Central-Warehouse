<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reports;

use CW\Clock;
use CW\Reports\ReportRefresh;
use CW\Reports\ReportViews;
use CW\StockOps\CostHints;
use CW\Tests\Support\StockTestCase;
use DateTimeImmutable;

/**
 * Pack E (docs/decisions.md PE10-PE12): the report job keeps the reports' own tables exact from what CW records, read incrementally
 * (each item's value sequence; the UK days of paid orders and of the ledger's sends, cancels and returns; the imported sales
 * history), never by scanning the ledger. Each test also proves that emptying the tables and running again gives the same figures.
 */
final class ReportRefreshTest extends StockTestCase
{
    private function job(?DateTimeImmutable $at = null): ReportRefresh
    {
        return new ReportRefresh(self::$db, static fn (): DateTimeImmutable => $at ?? Clock::now());
    }

    /** @return array<int, array<string, mixed>> */
    private static function items(): array
    {
        $out = [];
        foreach (self::$db->all('SELECT sku_id, seq_done, in_units, CAST(in_value AS CHAR) AS in_value, first_in_on, last_sale_on FROM report_item ORDER BY sku_id') as $r) {
            $out[(int) $r['sku_id']] = $r;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function days(): array
    {
        return self::$db->all('SELECT sale_date, channel_id, orders, units_sold, units_sent, units_cancelled, units_returned FROM report_sales_day ORDER BY sale_date, channel_id');
    }

    private static function avg(array $r): ?string
    {
        return (int) $r['in_units'] === 0 ? null : CostHints::six(bcdiv((string) $r['in_value'], (string) $r['in_units'], 7));
    }

    /** The first day stock of an item went up, as its own ledger rows say. */
    private static function firstIn(int $sku): string
    {
        return ReportRefresh::ukDay((string) self::$db->value("SELECT MIN(COALESCE(effective_at, created_at)) FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand' AND qty_delta > 0", [$sku]));
    }

    private static function rebuild(): void
    {
        self::$db->exec('DELETE FROM report_item');
        self::$db->exec('DELETE FROM report_sales_day');
        self::$db->exec('DELETE FROM report_refresh');
    }

    /** The average cost is CostHints::average's, read a few rows at a time and again after more stock came in; a rebuild gives the same. */
    public function testTheAverageCostIsTheValueEnginesReadIncrementally(): void
    {
        $a = $this->item('strict');
        $b = $this->item('strict');
        $this->book('goods_in', $a, 10, 'MAIN', null, '2.500000');
        $this->book('goods_in', $a, 5, 'MAIN', null, '4.000000');
        $this->book('adjustment', $a, 3);       // found stock with no price: not in the average
        $this->book('write_off', $a, 2);         // out: never in the average
        $this->book('goods_in', $b, 7);          // no price at all: the value is not known
        $this->book('goods_in', $b, 2, 'MAIN', null, '1.250000');

        $r = $this->job()->run(true, 2);         // a budget of 2 value rows: the rest waits for the next run
        self::assertSame(2, $r['seq_rows']);
        $part = self::items();
        self::assertSame(2, (int) $part[$a]['seq_done']);
        self::assertArrayNotHasKey($b, $part, 'not reached yet');
        self::assertSame('3.000000', self::avg($part[$a]), 'the two priced rows of A: (25 + 20) / 15');

        $this->job()->run(true);
        $all = self::items();
        $engine = (new CostHints(self::$db))->average([$a, $b]);
        self::assertSame($engine[$a], self::avg($all[$a]));
        self::assertSame($engine[$b], self::avg($all[$b]), 'B: only its priced row');
        self::assertSame(['15', '45.000000'], [(string) $all[$a]['in_units'], $all[$a]['in_value']]);
        self::assertSame(self::firstIn($a), $all[$a]['first_in_on']);
        self::assertNull($all[$a]['last_sale_on']);

        // More stock at another price, and a sale booked through ERPNext: the next run moves the average and the last sale.
        $this->book('goods_in', $a, 5, 'MAIN', null, '1.000000');
        $this->book('erp_sale', $a, 4);
        $this->job()->run(true);
        $all = self::items();
        self::assertSame((new CostHints(self::$db))->average([$a])[$a], self::avg($all[$a]));
        self::assertSame('2.500000', self::avg($all[$a]), '(45 + 5) / 20');
        $sale = self::$db->value("SELECT COALESCE(effective_at, created_at) FROM stock_ledger WHERE sku_id = ? AND movement_type = 'erp_sale'", [$a]);
        self::assertSame(ReportRefresh::ukDay((string) $sale), $all[$a]['last_sale_on']);
        self::assertSame((int) self::$db->value('SELECT last_seq FROM stock_value_clock WHERE sku_id = ?', [$a]), (int) $all[$a]['seq_done']);

        // Nothing new: nothing read. Emptied and run again: the same figures.
        self::assertSame(0, $this->job()->run(true)['seq_rows']);
        $before = self::items();
        self::rebuild();
        $this->job()->run(true);
        self::assertEquals($before, self::items());
    }

    /**
     * Paid orders, units and the sends, cancels and returns, per UK day and store: an order paid at 23:30 UK time is that day's, one at
     * 00:30 the next day's (British Summer Time); a pack of 3 counts 3 units; an order never paid counts nothing; a cancelled unit of a
     * paid order still counts as sold that day, and its item gets no last sale from it.
     */
    public function testSalesPerUkDayAndStore(): void
    {
        $vpg = $this->site('vpg');
        $efg = $this->site('efg');
        $one = $this->item('strict', 50);
        $pack = $this->item('strict', 50);
        $other = $this->item('strict', 50);
        $this->listing($vpg, 'V1', $one);
        $this->listing($vpg, 'V3', $pack, 3);
        $this->listing($vpg, 'VO', $other);
        $this->listing($efg, 'E1', $one);

        $this->now = new DateTimeImmutable('2026-09-25 22:30:00', Clock::utc()); // 23:30 in the UK, 25 Sep
        $this->ok($this->commit($vpg, 'o1', [self::line('V1', 'u1', 'u2'), self::line('V3', 'u3')]));
        $this->now = new DateTimeImmutable('2026-09-25 23:30:00', Clock::utc()); // 00:30 in the UK, 26 Sep
        $this->ok($this->commit($vpg, 'o2', [self::line('VO', 'u4')]));
        $this->now = new DateTimeImmutable('2026-09-26 10:00:00', Clock::utc());
        $this->ok($this->commit($efg, 'o3', [self::line('E1', 'e1', 'e2')]));
        $this->ok($this->reserve($vpg, 'o4', [self::line('V1', 'u9')]));
        $this->ok($this->release($vpg, 'o4'));
        $this->now = new DateTimeImmutable(self::NOW, Clock::utc());
        $this->ok($this->ship($vpg, 'o1', ['u1', 'u2'], '2026-09-26T17:00:00Z'));
        $this->ok($this->res->cancel($vpg, 'o2', ['u4'], true, $this->key('cancel')));
        $this->ok($this->res->returnUnits($vpg, 'o1', ['u1'], $this->key('return')));

        // The orders are on the test clock's days (September 2026) and the sends on today's: one run reaches today whatever the
        // date (the setting's 31 days stopped short of it from 26 Oct 2026 on).
        $r = $this->job()->run(true, null, 3660);
        $today = ReportRefresh::ukDay(Clock::db(Clock::now()));
        self::assertGreaterThanOrEqual(2, $r['days']);
        $rows = [];
        foreach (self::days() as $d) {
            $rows[$d['sale_date'] . ' ' . ((int) $d['channel_id'] === $vpg->channelId ? 'vpg' : 'efg')] = [(int) $d['orders'], (int) $d['units_sold'],
                (int) $d['units_sent'], (int) $d['units_cancelled'], (int) $d['units_returned']];
        }
        self::assertSame([1, 5, 0, 0, 0], $rows['2026-09-25 vpg'], 'o1: 2 single units and a pack of 3');
        self::assertSame([1, 1, 0, 0, 0], $rows['2026-09-26 vpg'], 'o2 at 00:30 UK time; o4 was never paid');
        self::assertSame([1, 2, 0, 0, 0], $rows['2026-09-26 efg']);
        // The sends, the cancel and the return: on the day CW recorded them (today).
        self::assertSame([0, 0, 2, 1, 1], $rows["{$today} vpg"], 'sent 2, cancelled 1, returned 1');
        self::assertCount(4, $rows);

        $items = self::items();
        self::assertSame('2026-09-26', $items[$one]['last_sale_on'], 'sold on both stores; the later day');
        self::assertSame('2026-09-25', $items[$pack]['last_sale_on']);
        self::assertNull($items[$other]['last_sale_on'] ?? null, 'its only unit was cancelled');

        // The page's reads add up the days; a second run and a rebuild give the same.
        $sales = (new ReportViews(self::$db))->sales('2026-09-25', $today, null);
        self::assertSame(['orders' => 2, 'units_sold' => 6, 'units_sent' => 2, 'units_cancelled' => 1, 'units_returned' => 1], $sales['stores'][$vpg->channelId]);
        self::assertSame(['orders' => 1, 'units_sold' => 2, 'units_sent' => 0, 'units_cancelled' => 0, 'units_returned' => 0], $sales['stores'][$efg->channelId]);
        $one26 = (new ReportViews(self::$db))->sales('2026-09-26', '2026-09-26', $vpg->channelId);
        self::assertSame([['day' => '2026-09-26', 'orders' => 1, 'units_sold' => 1, 'units_sent' => 0, 'units_cancelled' => 0, 'units_returned' => 0]], $one26['days']);
        $before = self::days();
        $this->job()->run(true, null, 3660);
        self::assertEquals($before, self::days());
        self::rebuild();
        $this->job()->run(true, null, 3660);
        self::assertEquals($before, self::days());
    }

    /** A first run catches up a day per run when told to; an order paid with a time before the last run is still counted. */
    public function testAFirstRunCatchesUpAndALateCommitIsCounted(): void
    {
        $vpg = $this->site('vpg');
        $one = $this->item('strict', 100);
        $this->listing($vpg, 'V1', $one);
        foreach (['2026-09-20 09:00:00', '2026-09-21 09:00:00', '2026-09-22 09:00:00'] as $i => $t) {
            $this->now = new DateTimeImmutable($t, Clock::utc());
            $this->ok($this->commit($vpg, "d{$i}", [self::line('V1', "d{$i}u")]));
        }
        $at = new DateTimeImmutable('2026-09-24 12:00:00', Clock::utc());
        $r = $this->job($at)->run(true, null, 1);
        self::assertSame(1, $r['days']);
        self::assertSame(['2026-09-20'], array_column(self::days(), 'sale_date'));
        $this->job($at)->run(true, null, 1);
        $this->job($at)->run(true, null, 1);
        self::assertSame(['2026-09-20', '2026-09-21', '2026-09-22'], array_column(self::days(), 'sale_date'));
        $this->job($at)->run(true, null, 5);
        self::assertSame('2026-09-24 12:00:00.000000', $this->job($at)->state()['sales_through'], 'up to the run once today is counted');

        // An order whose transaction began before that run and committed after it: its time is before the mark, the next run counts it.
        $this->now = new DateTimeImmutable('2026-09-24 11:40:00', Clock::utc());
        $this->ok($this->commit($vpg, 'late', [self::line('V1', 'lu')]));
        $this->job(new DateTimeImmutable('2026-09-24 12:15:00', Clock::utc()))->run(true);
        self::assertSame([['2026-09-24', 1]], array_map(static fn (array $d): array => [$d['sale_date'], (int) $d['orders']],
            array_values(array_filter(self::days(), static fn (array $d): bool => $d['sale_date'] === '2026-09-24'))));
    }

    /** The imported sales history gives the last sale day, batch by batch in order; a batch still loading holds the later ones back. */
    public function testTheImportedSalesHistoryGivesTheLastSaleDay(): void
    {
        $vpg = $this->site('vpg');
        $x = $this->item('strict');
        $y = $this->item('strict');
        $this->listing($vpg, 'HX', $x);
        $this->listing($vpg, 'HY', $y);
        $this->listing($vpg, 'HZ', null); // not matched: its days teach nothing
        $batch = function (string $status, array $days) use ($vpg): int {
            $id = self::$db->insert("INSERT INTO sales_import_batch (channel_id, source, date_from, date_to, sales_file, sales_sha256, manifest, exported_at, status, actor) "
                . "VALUES (?, 'cps', '2026-01-01', '2026-09-30', 'f.csv', ?, JSON_OBJECT(), NOW(6), ?, 'system:test')", [$vpg->channelId, hash('sha256', random_bytes(8)), $status]);
            foreach ($days as [$variant, $day]) {
                self::$db->exec('INSERT INTO sales_history_day (channel_id, external_variant_id, sale_date, units_online, batch_id) VALUES (?, ?, ?, 1, ?)',
                    [$vpg->channelId, $variant, $day, $id]);
            }
            return $id;
        };
        $b1 = $batch('loaded', [['HX', '2026-08-01'], ['HX', '2026-08-15'], ['HZ', '2026-09-01']]);
        $b2 = $batch('loading', [['HY', '2026-09-10']]);
        $b3 = $batch('loaded', [['HX', '2026-07-01'], ['HY', '2026-09-20']]);
        $this->job()->run(true);
        self::assertSame($b1, $this->job()->state()['history_batch_id']);
        $items = self::items();
        self::assertSame('2026-08-15', $items[$x]['last_sale_on']);
        self::assertArrayNotHasKey($y, $items, 'batch 3 waits behind the batch still loading');
        // Review finding 3 (PE18): a failed batch holds the later ones back too, because the import retries it under the same id.
        self::$db->exec("UPDATE sales_import_batch SET status = 'failed' WHERE id = ?", [$b2]);
        $this->job()->run(true);
        self::assertSame($b1, $this->job()->state()['history_batch_id'], 'a failed batch waits for its retry');
        self::assertStringContainsString("history waits for batch {$b2} (failed)", (string) $this->job()->state()['note'], 'the run says what it waits for');
        self::assertArrayNotHasKey($y, self::items());
        self::$db->exec("UPDATE sales_import_batch SET status = 'loaded' WHERE id = ?", [$b2]); // the retry under the same id succeeds
        $this->job()->run(true);
        self::assertSame($b3, $this->job()->state()['history_batch_id']);
        $items = self::items();
        self::assertSame('2026-08-15', $items[$x]['last_sale_on'], 'an older day never moves it back');
        self::assertSame('2026-09-20', $items[$y]['last_sale_on'], 'the retried batch and the one after it are read');
    }

    /** Switched off by its setting the job does nothing; a hand run (force) still works. */
    public function testSwitchedOffItDoesNothingButAHandRunWorks(): void
    {
        $saved = self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'reports.refresh_on'");
        self::assertSame('true', trim((string) $saved), 'on by default (PE12)');
        $a = $this->item('strict');
        $this->book('goods_in', $a, 3, 'MAIN', null, '1.000000');
        self::$db->exec("UPDATE app_setting SET value_json = CAST('false' AS JSON) WHERE setting_key = 'reports.refresh_on'");
        try {
            self::assertTrue($this->job()->run()['off']);
            self::assertSame([], self::items());
            self::assertFalse($this->job()->run(true)['off']);
            self::assertArrayHasKey($a, self::items());
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'reports.refresh_on'", [(string) $saved]);
        }
    }

    /** UK days: the clocks go back on 25 Oct 2026 (a 25-hour day) and forward on 28 Mar 2027 (23 hours). */
    public function testUkDays(): void
    {
        self::assertSame('2026-09-26', ReportRefresh::ukDay('2026-09-25 23:00:00.000000'));
        self::assertSame('2026-09-25', ReportRefresh::ukDay('2026-09-25 22:59:59.999999'));
        self::assertSame('2026-12-25', ReportRefresh::ukDay('2026-12-25 23:59:59'));
        [$from, $to] = ReportRefresh::bounds('2026-10-25');
        self::assertSame(['2026-10-24 23:00:00.000000', '2026-10-26 00:00:00.000000'], [Clock::db($from), Clock::db($to)]);
        [$from, $to] = ReportRefresh::bounds('2027-03-28');
        self::assertSame(['2027-03-28 00:00:00.000000', '2027-03-28 23:00:00.000000'], [Clock::db($from), Clock::db($to)]);
        self::assertSame('2026-10-26', ReportRefresh::nextDay('2026-10-25'));
        self::assertSame('2027-01-01', ReportRefresh::nextDay('2026-12-31'));
    }

    public function testUnpaidCancellationsDoNotCountAsSales(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 30);
        $this->listing($site, 'M', $sku, 3);
        $this->listing($site, 'U', null, 2);
        $this->ok($this->reserve($site, 'part-paid', [self::line('M', 'ma', 'mb', 'mc'), self::line('U', 'ua', 'ub', 'uc')]));
        $this->ok($this->res->cancel($site, 'part-paid', ['ma', 'ua'], true, $this->key('cancel-held')));
        $this->ok($this->commit($site, 'part-paid', [self::line('M', 'mb', 'mc'), self::line('U', 'ub', 'uc')]));
        $this->ok($this->res->cancel($site, 'part-paid', ['mc', 'uc'], true, $this->key('cancel-paid')));
        self::assertSame(['ma' => 0, 'mb' => 1, 'mc' => 1, 'ua' => 0, 'ub' => 1, 'uc' => 1], array_column(self::$db->all(
            'SELECT unit_id, was_paid FROM reservation_unit ORDER BY unit_id'), 'was_paid', 'unit_id'));
        $this->job()->recount('2026-09-26');
        self::assertSame([1, 10], array_values((array) self::$db->one(
            "SELECT orders, units_sold FROM report_sales_day WHERE sale_date = '2026-09-26' AND channel_id = ?", [$site->channelId])),
            'only payment members count, including mapped and unlinked units cancelled after payment');
    }

    public function testCancellingAnOldOrderRecomputesLastSaleWithoutLosingOtherSources(): void
    {
        $real = Clock::now();
        $site = $this->site();
        $skus = [];
        foreach (['A', 'B', 'C', 'D'] as $v) {
            $skus[$v] = $this->item('strict');
            $this->listing($site, $v, $skus[$v]);
        }
        $this->now = $real->modify('-220 days');
        foreach ($skus as $sku) {
            $this->book('goods_in', $sku, 100, 'MAIN', null, '2.00');
        }
        $this->now = $real->modify('-45 days');
        $this->ok($this->commit($site, 'earlier', [self::line('B', 'older-b')]));
        $olderStore = ReportRefresh::ukDay(Clock::db($this->now));
        $this->now = $real->modify('-30 days');
        $this->book('erp_sale', $skus['C'], 1);
        $erpDay = ReportRefresh::ukDay(Clock::db($this->now));
        $historyDay = ReportRefresh::ukDay(Clock::db($real->modify('-40 days')));
        $batch = self::$db->insert("INSERT INTO sales_import_batch (channel_id, source, date_from, date_to, sales_file, sales_sha256, manifest, exported_at, status, actor) "
            . "VALUES (?, 'cps', ?, ?, 'xc.csv', ?, JSON_OBJECT(), NOW(6), 'loaded', 'system:test')",
            [$site->channelId, $historyDay, $historyDay, hash('sha256', 'xc-history')]);
        self::$db->exec("INSERT INTO sales_history_day (channel_id, external_variant_id, sale_date, units_online, batch_id) VALUES (?, 'D', ?, 1, ?)",
            [$site->channelId, $historyDay, $batch]);

        $this->now = $real->modify('-2 days');
        $latest = ReportRefresh::ukDay(Clock::db($this->now));
        $this->ok($this->commit($site, 'latest', [self::line('A', 'a'), self::line('B', 'b'), self::line('C', 'c'), self::line('D', 'd')]));
        $this->job($real)->run(true, null, 366);
        foreach ($skus as $sku) {
            self::assertSame($latest, self::items()[$sku]['last_sale_on']);
        }

        // The payment day has left the report job's overlap window. The cancellation day must invalidate these items.
        $this->now = $real;
        $this->ok($this->res->cancel($site, 'latest', ['a', 'b', 'c', 'd'], true, $this->key('cancel')));
        $at = $real->modify('+1 minute');
        $this->job($at)->run(true);
        $after = self::items();
        self::assertSame([null, $olderStore, $erpDay, $historyDay],
            array_map(static fn (int $sku): ?string => $after[$sku]['last_sale_on'], array_values($skus)));
        $dead = (new ReportViews(self::$db))->slow('dead', '', ReportRefresh::ukDay(Clock::db($at)), 60, 180, 1);
        self::assertContains($skus['A'], array_map('intval', array_column($dead['rows'], 'sku_id')), 'its only sale was cancelled: old stock is dead again');

        self::rebuild();
        $this->job($at)->run(true, null, 366);
        self::assertEquals($after, self::items(), 'incremental refresh and rebuild agree');
        $this->ok($this->res->uncancel($site, 'latest', ['a'], $this->key('uncancel')));
        $this->job($at)->run(true);
        self::assertSame($latest, self::items()[$skus['A']]['last_sale_on'], 'taking the cancellation back restores the sale day');
    }
}
