<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Profit\ErpSalesImport;
use CW\Profit\ItemCosts;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitSettings;
use CW\Profit\ProfitViews;
use CW\Profit\RecostSales;
use CW\Settings;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The final review of 6d59eda (build/A/codex_final_astra_{a,b,c}.txt), the orders' side, each shown failing first with the reviewers'
 * numbers: the API stores a snapshot without the money work, so a checkout never waits behind a day summary's lock (a-HIGH2); the cost of
 * an order's items is read in a couple of statements, not one or two per item; Profit by order's rows add up to the day summary (c-HIGH3)
 * and never print a profit that is not known (c-M10); a full order refund takes the sale off exactly (c-LOW1); a parcel gone keeps no text
 * (b-HIGH2); ERPNext's cost only where its units match and inside its history (c-M6, c-M7); the re-cost keeps to its days (c-M8); the VAT
 * setting "5.0000" is 5% (c-M5).
 */
final class FinalReviewSnapshotTest extends StockTestCase
{
    private const PAID = '2026-11-19T10:00:00Z';
    private const DAY = '2026-11-19';
    /** @var array<string, string> setting key => value_json to put back */
    private array $saved = [];
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_final_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $k]);
        }
        $this->saved = [];
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->saved[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    private static function price(int $sku, ?int $e4, string $at = '2026-11-16 00:00:00'): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, 'start', ?, ?)",
            [$sku, $at, $at, 'start:' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
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
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => self::PAID,
            'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '12', 'method' => 'Royal Mail Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0,
            'grand_inc' => 0, 'parcels' => []];
    }

    public function testTheApiStoresWithoutTheMoneyWork(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, 'VA', $a);
        $ch = (int) $site->channelId;
        // Another writer holds the day's summary row and the order's money guard (a rebuild, the money job on a big order).
        $other = TestDb::connect();
        $other->exec('INSERT INTO profit_day (channel_id, day) VALUES (?, ?) AS n ON DUPLICATE KEY UPDATE channel_id = n.channel_id', [$ch, self::DAY]);
        $other->pdo()->beginTransaction();
        $other->one('SELECT * FROM profit_day WHERE channel_id = ? AND day = ? FOR UPDATE', [$ch, self::DAY]);
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($ch, '1')]));
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $t = microtime(true);
            $r = (new OrderSnapshots(self::$db, null, null, 1))->accept($ch, '1', self::body([self::sl('L1', 'VA', 1200)]), 'api');
            self::assertSame('stored', $r['result']);
            self::assertLessThan(0.9, microtime(true) - $t, 'the API never waits for a day summary or the money work');
        } finally {
            $other->pdo()->rollBack();
            $other->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($ch, '1')]);
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM order_snapshot WHERE channel_id = ?', [$ch]), 'order_snapshot holds what is worked out');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sale_order WHERE channel_id = ?', [$ch]), 'nothing of the money written by the API');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM order_snapshot_queue WHERE channel_id = ? AND failed = 0', [$ch]));
        // The job works it out; its queue row goes.
        self::assertSame(['applied' => 1, 'refused' => 0, 'busy' => 0, 'left' => 0], (new OrderSnapshots(self::$db))->applyQueued());
        self::assertSame(['1000', '300'], [(string) self::$db->value('SELECT sale_ex FROM sale_order WHERE channel_id = ?', [$ch]),
            (string) self::$db->value('SELECT cost FROM sale_order WHERE channel_id = ?', [$ch])]);
        self::assertSame('300', (string) self::$db->value('SELECT cost FROM profit_day WHERE channel_id = ? AND day = ?', [$ch, self::DAY]));
        self::assertSame('1000', (string) self::$db->value('SELECT version FROM order_snapshot WHERE channel_id = ?', [$ch]), 'the snapshot worked out');
        // Two snapshots before the job runs: the newest is worked out, once.
        $s = new OrderSnapshots(self::$db);
        $s->accept($ch, '1', self::body([self::sl('L1', 'VA', 1200)], ['version' => 1001, 'grand_inc' => 5]), 'api');
        $s->accept($ch, '1', self::body([self::sl('L1', 'VA', 2400)], ['version' => 1002]), 'api');
        self::assertSame('2', (string) self::$db->value('SELECT seq FROM order_snapshot_queue WHERE channel_id = ?', [$ch]), 'one row, its seq moved on');
        self::assertSame(1, $s->applyQueued()['applied']);
        self::assertSame('2000', (string) self::$db->value('SELECT sale_ex FROM sale_order WHERE channel_id = ?', [$ch]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM order_snapshot_queue WHERE channel_id = ?', [$ch]));
        // While another writer holds the order's guards, the job leaves it for the next run.
        $s->accept($ch, '1', self::body([self::sl('L1', 'VA', 1200)], ['version' => 1003]), 'api');
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($ch, '1')]));
        try {
            self::assertSame(['applied' => 0, 'refused' => 0, 'busy' => 1, 'left' => 1], (new OrderSnapshots(self::$db, null, null, 1))->applyQueued());
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($ch, '1')]);
        }
        self::assertSame(1, $s->applyQueued()['applied']);
        self::assertSame('1000', (string) self::$db->value('SELECT sale_ex FROM sale_order WHERE channel_id = ?', [$ch]));
    }

    public function testARaceTheMoneyWorkRefusesIsMarkedAndNotTriedAgain(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $s = new OrderSnapshots(self::$db);
        // Two orders send the same line before the job ran (each passes the API's read-only check).
        $s->accept($ch, 'B', self::body([self::sl('X1', 'VA', 1200)]), 'api');
        $s->accept($ch, 'A', self::body([self::sl('X1', 'VA', 1200)]), 'api');
        self::assertSame(['applied' => 1, 'refused' => 1, 'busy' => 0, 'left' => 0], $s->applyQueued());
        self::assertStringContainsString('belongs to another order', (string) self::$db->value("SELECT error FROM order_snapshot_queue WHERE order_ref = 'A' AND failed = 1"));
        self::assertSame(['applied' => 0, 'refused' => 0, 'busy' => 0, 'left' => 0], $s->applyQueued(), 'never tried again on its own');
        $s->accept($ch, 'A', self::body([self::sl('X2', 'VA', 1200)], ['version' => 1001]), 'api');
        self::assertSame(1, $s->applyQueued()['applied'], 'a newer snapshot of the order is worked out');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM order_snapshot_queue'));
    }

    public function testTheCostOfAThousandItemsIsReadInAFewStatements(): void
    {
        $skus = [];
        $events = [];
        $params = [];
        for ($i = 0; $i < 1000; $i++) {
            $skus[] = self::makeSku('Many ' . $i);
        }
        self::$db->exec('INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) SELECT id, 10000, \'erp_bin\', \'2026-11-16\', \'2026-11-16\' FROM sku '
            . 'WHERE id IN (' . implode(', ', $skus) . ')');
        foreach ($skus as $sku) {
            $events[] = "(?, '2026-11-16 00:00:00', '2026-11-16 00:00:00', 'start', ?, 10000)";
            array_push($params, $sku, 'many:' . $sku);
        }
        self::$db->exec('INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES ' . implode(', ', $events), $params);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
        $costs = new ItemCosts(self::$db);
        $costs->reached('2026-11-19 10:00:00'); // the mark, read once
        $questions = static fn (): int => (int) (self::$db->one("SHOW SESSION STATUS LIKE 'Questions'")['Value'] ?? 0);
        $before = $questions();
        $got = $costs->at($skus, '2026-11-19 10:00:00');
        $n = $questions() - $before - 1;
        self::assertCount(1000, $got);
        self::assertSame(['e4' => 10000, 'source' => 'cw_cost'], $got[$skus[999]]);
        self::assertLessThanOrEqual(6, $n, "1,000 items in a few statements while the order's day is locked, never 2,000 ({$n})");
    }

    public function testTheRowsOfAPeriodAddUpToItsDaySummary(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, 'VA', $a);
        $s = new OrderSnapshots(self::$db);
        // A parent sells £10 ex VAT at a cost of £3; its free re-send the same day costs £3.
        $s->store((int) $site->channelId, 'P', self::body([self::sl('P1', 'VA', 1200)]), 'api');
        $s->store((int) $site->channelId, 'R', self::body([self::sl('R1', 'VA', 0, ['free' => true])], ['parent_ref' => 'P', 'order_no' => 'VPG2']), 'api');
        $views = new ProfitViews(self::$db);
        $day = $views->totals(self::DAY, self::DAY, null);
        self::assertSame([1000, 600, 400], [$day['goods'], $day['cost'], $day['profit']], 'the day: £6 cost, £4 profit');
        $rows = $views->orders(self::DAY, self::DAY, null, '', false)['rows'];
        $sum = ['sale' => 0, 'cost' => 0, 'profit' => 0, 'units' => 0, 'delivery' => 0];
        foreach ($rows as $r) {
            foreach (array_keys($sum) as $k) {
                $sum[$k] += (int) $r[$k];
            }
        }
        self::assertSame(['sale' => $day['goods'], 'cost' => $day['cost'], 'profit' => $day['profit'], 'units' => $day['units'], 'delivery' => $day['delivery']], $sum,
            'the rows add up to the day summary (never £9 cost and £1 profit)');
        $parent = array_values(array_filter($rows, static fn (array $r): bool => $r['ref'] === 'P'))[0];
        self::assertSame([300, 700, 300, 400, 4000], [$parent['cost'], $parent['profit'], $parent['resend_cost'], $parent['with_resends'], $parent['margin_bp']],
            'its own cost and profit; its re-sends\' cost and its profit with them on their own; its margin with them (PR10)');
    }

    public function testAProfitThatIsNotKnownIsNeverANumber(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, 'VA', $a);
        $this->listing($site, 'VB', $b); // no buying price
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, 'U', self::body([self::sl('U1', 'VB', 1200)]), 'api');
        // A parent refunded in full, its re-send of an item with no price.
        $s->store((int) $site->channelId, 'P', self::body([self::sl('P1', 'VA', 1200, ['refund_inc' => 1200])]), 'api');
        $s->store((int) $site->channelId, 'R', self::body([self::sl('R1', 'VB', 0, ['free' => true])], ['parent_ref' => 'P']), 'api');
        $rows = [];
        foreach ((new ProfitViews(self::$db))->orders(self::DAY, self::DAY, null, '', false)['rows'] as $r) {
            $rows[$r['ref']] = $r;
        }
        self::assertNull($rows['U']['profit'], 'a £10 sale with a unit of no price: "not known", never £10 profit');
        self::assertTrue($rows['U']['incomplete']);
        self::assertTrue($rows['P']['incomplete'], 'its re-send has a unit with no price: incomplete, whatever the parent sold');
        self::assertNull($rows['P']['with_resends']);
    }

    public function testAFullOrderRefundTakesTheSaleOffExactly(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $lines = [];
        for ($i = 1; $i <= 10; $i++) {
            $lines[] = self::sl('F' . $i, 'NONE', 99);
        }
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, 'F', self::body($lines, ['order_refund_inc' => 990]), 'api');
        $o = self::$db->one("SELECT sale_inc, sale_ex, order_refund_ex FROM sale_order WHERE order_ref = 'F'");
        self::assertSame(['0', '0', '830'], [(string) $o['sale_inc'], (string) $o['sale_ex'], (string) $o['order_refund_ex']],
            'ten 99p lines are 830p ex VAT; refunding all £9.90 at order level leaves nothing (never 5p)');
        $kept = self::$db->one('SELECT sale_ex, order_refund_ex FROM profit_day WHERE channel_id = ? AND day = ?', [$site->channelId, self::DAY]);
        self::assertSame(['830', '830'], [(string) $kept['sale_ex'], (string) $kept['order_refund_ex']]);
    }

    public function testAParcelGoneKeepsNoText(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $s = new OrderSnapshots(self::$db);
        $parcel = ['parcel_ref' => 'orl:9', 'tracking_no' => null, 'carrier' => 'royal_mail', 'service_code' => 'TSS', 'service' => 'Return label', 'status' => 'created',
            'label_at' => '2026-11-19T11:00:00Z', 'shipped_at' => null, 'manual' => false, 'partial' => false];
        $s->store((int) $site->channelId, 'G', self::body([self::sl('G1', 'NONE', 100)], ['parcels' => [$parcel]]), 'api');
        self::assertSame('Return label', (string) self::$db->value("SELECT service FROM sale_parcel WHERE order_ref = 'G'"));
        $s->store((int) $site->channelId, 'G', self::body([self::sl('G1', 'NONE', 100)], ['version' => 1001]), 'api');
        $p = self::$db->one("SELECT service, service_code, removed_at FROM sale_parcel WHERE order_ref = 'G'");
        self::assertNotNull($p['removed_at']);
        self::assertSame(['', 'TSS'], [(string) $p['service'], (string) $p['service_code']], 'a parcel gone keeps its codes, no text');
    }

    private static function tsv(array $rows): string
    {
        $head = ['name', 'posting_date', 'creation', 'custom_order_id', 'is_return', 'return_against', 'docstatus', 'net_total', 'total_taxes_and_charges', 'grand_total',
            'discount_amount', 'idx', 'item_code', 'qty', 'rate', 'net_rate', 'net_amount', 'incoming_rate', 'warehouse'];
        $out = implode("\t", $head) . "\n";
        foreach ($rows as $i => [$name, $order, $item, $qty, $rate, $status]) {
            $out .= implode("\t", [$name, '2025-12-15', '2025-12-15 10:00:00.000000', $order, '0', 'NULL', $status, '10.00', '2.00', '12.00', '0.000000000', $i + 1,
                $item, $qty, '1.00', '1.00', '8.33', $rate, 'Stores - VPG']) . "\n";
        }
        return $out;
    }

    public function testErpNextsCostOnlyWhereItsUnitsMatchAndInsideItsHistory(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 10000, '2025-01-01 00:00:00');
        $this->listing($site, '101', $a);
        $s = new OrderSnapshots(self::$db);
        // Two single-unit lines of one variant; ERPNext priced one unit at £3.
        $s->store((int) $site->channelId, '2001', self::body([self::sl('M1', '101', 1200), self::sl('M2', '101', 1200)], ['paid_at' => '2025-12-15T10:00:00Z']), 'bulk');
        // Paid on 14 Dec 2025, the day before ERPNext's history, with profit.before_history = none.
        $this->setting('profit.before_history', '"none"');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '2002', self::body([self::sl('M3', '101', 1200)], ['paid_at' => '2025-12-14T10:00:00Z']), 'bulk');
        file_put_contents($this->dir . '/si_2025-12-15.tsv', self::tsv([['SI-1', '2001', 'P101', '1.000000000', '3.000000000', '1'],
            ['SI-2', '2002', 'P101', '1.000000000', '3.000000000', '1']]));
        $log = [];
        (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, true, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        $src = self::$db->all("SELECT line_id, cost_source, cost FROM sale_line WHERE order_ref IN ('2001', '2002') ORDER BY line_id");
        self::assertSame([['M1', 'estimate', '100'], ['M2', 'estimate', '100'], ['M3', 'none', '0']], array_map(static fn (array $r): array => array_map('strval', array_values($r)), $src),
            'two CW units against one ERPNext unit: never £6 of ERPNext cost for £3 it priced; before its history: never priced from it');
        self::assertStringContainsString('units not matching 1', $log[0]);
        self::assertStringContainsString('paid before the history 1', $log[0]);
        // A broken status is a broken day, never a row left out without a word.
        file_put_contents($this->dir . '/si_2025-12-15.tsv', self::tsv([['SI-1', '2001', 'P101', '2.000000000', '3.000000000', '']]));
        $r = (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, true, static fn (string $m) => null);
        self::assertSame(1, $r['refused']);
    }

    public function testTheReCostKeepsToItsDays(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $this->listing($site, 'VA', $a);
        self::price($a, null);
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, '1', self::body([self::sl('L1', 'VA', 1200)]), 'api');
        self::price($a, 30000, '2026-11-18 00:00:00');
        // The re-cost of 19 Nov chose the order; a newer snapshot moved its payment to 20 Nov before the re-cost took its lock. What the
        // re-cost works out under the lock (its plan, read again there) must leave it alone: it is not paid on a day asked for any more.
        $s->store((int) $site->channelId, '1', self::body([self::sl('L1', 'VA', 1200)], ['version' => 1001, 'paid_at' => '2026-11-20T10:00:00Z']), 'api');
        $plan = new \ReflectionMethod(RecostSales::class, 'plan');
        $got = $plan->invoke(null, self::$db, new ItemCosts(self::$db), (int) $site->channelId, '1', ['none'], self::DAY, self::DAY);
        self::assertNull($got, 'paid on 20 Nov now: the re-cost of 19 Nov never fixes its £3 price of 18 Nov on it');
        self::assertSame(0, (new RecostSales(self::$db))->run(self::DAY, self::DAY, ['none'], null, true)['orders']);
        self::assertSame('none', (string) self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = 'L1'"));
    }

    public function testAVatOfFivePointZeroZeroIsSavedAndReadAsFivePercent(): void
    {
        $settings = new Settings(self::$db);
        $this->saved['profit.vat_rate_pct'] ??= (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'profit.vat_rate_pct'");
        $settings->set(Caller::system('test'), 'profit.vat_rate_pct', '5.0000', 'a test of the VAT setting');
        self::assertSame('"5"', (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'profit.vat_rate_pct'"), 'kept as 5');
        self::assertSame(500, (new ProfitSettings(self::$db))->vatBp());
        try {
            $settings->set(Caller::system('test'), 'profit.vat_rate_pct', '5.125', 'a test of the VAT setting');
            self::fail('three decimals that matter are refused');
        } catch (CwException $e) {
            self::assertSame('bad_value', $e->errorCode);
        }
        self::$db->exec("UPDATE app_setting SET value_json = CAST('\"5.0000\"' AS JSON) WHERE setting_key = 'profit.vat_rate_pct'");
        self::assertSame(500, (new ProfitSettings(self::$db))->vatBp(), 'a value kept as "5.0000" is read as 5%, never 20%');
        $site = $this->site('vapeandgo', 'live');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, 'V', self::body([self::sl('V1', 'NONE', 12600)]), 'api');
        self::assertSame('12000', (string) self::$db->value("SELECT sale_ex FROM sale_order WHERE order_ref = 'V'"), '£126 at 5% is £120 ex VAT (never £105)');
    }

    public function testTheCheckOfTheDaySummariesWritesNothing(): void
    {
        $site = $this->site('vapeandgo', 'live');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, 'C', self::body([self::sl('C1', 'NONE', 1200)]), 'api');
        self::assertSame([], ProfitDays::differences(self::$db, (int) $site->channelId, self::DAY));
        self::$db->exec('UPDATE profit_day SET cost = cost + 1000 WHERE channel_id = ? AND day = ?', [$site->channelId, self::DAY]);
        self::assertSame(['day: cost kept 1000, worked out 0 (-1000)'], ProfitDays::differences(self::$db, (int) $site->channelId, self::DAY));
        self::assertSame('1000', (string) self::$db->value('SELECT cost FROM profit_day WHERE channel_id = ? AND day = ?', [$site->channelId, self::DAY]), 'nothing written');
    }

    /** The Codex check of c48683e: the order's rows, order_snapshot and the batch tools agree on one version. */
    public function testABatchToolWaitsWhileANewerSnapshotWaitsForTheMoneyJob(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 80000, '2025-01-01 00:00:00');
        $this->listing($site, '101', $a);
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, '3001', self::body([self::sl('W1', '101', 1200)], ['paid_at' => '2025-12-15T10:00:00Z']), 'bulk');
        // A correction moves its payment to 17 Nov 2026 (after CW's own price started); the money job has not worked it out yet.
        $s->accept((int) $site->channelId, '3001', self::body([self::sl('W1', '101', 1200)], ['version' => 1001, 'paid_at' => '2026-11-17T10:00:00Z']), 'api');
        file_put_contents($this->dir . '/si_2025-12-15.tsv', self::tsv([['SI-1', '3001', 'P101', '1.000000000', '3.000000000', '1']]));
        $log = [];
        (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, true, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(['estimate', '800'], array_map('strval', array_values((array) self::$db->one("SELECT cost_source, cost FROM sale_line WHERE line_id = 'W1'"))),
            'never £3 of ERPNext cost written while a newer snapshot (paid after the start) waits');
        self::assertStringContainsString('waiting for the money job 1', $log[0]);
        $s->applyQueued();
        (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, true, static fn (string $m) => null);
        self::assertSame('estimate', (string) self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = 'W1'"), 'run again after the job: paid after the start now');
    }

    public function testALineWhoseIdChangesCaseIsTheSameLine(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, 'K', self::body([self::sl('L1', 'NONE', 1200)]), 'api');
        $s->store((int) $site->channelId, 'K', self::body([self::sl('l1', 'NONE', 1200)], ['version' => 1001]), 'api');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM sale_line WHERE order_ref = 'K'"));
        self::assertNull(self::$db->value("SELECT removed_at FROM sale_line WHERE order_ref = 'K'"), 'one row, still on the order (never marked removed)');
        self::assertSame(['1000', '1000'], [(string) self::$db->value("SELECT sale_ex FROM sale_order WHERE order_ref = 'K'"),
            (string) self::$db->value('SELECT sale_ex FROM profit_day WHERE channel_id = ? AND day = ?', [$site->channelId, self::DAY])]);
        self::assertSame([], ProfitDays::differences(self::$db, (int) $site->channelId, self::DAY));
    }

    /** The Codex check of c48683e (new HIGH 1): a refusal marks only the snapshot refused; a correction stored meanwhile is worked out next. */
    public function testARefusalNeverStrandsACorrectionStoredMeanwhile(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $s = new OrderSnapshots(self::$db);
        // A's first snapshot names X1; B stores X1 and is worked out first, so the money work refuses A's first snapshot.
        $s->accept($ch, 'A', self::body([self::sl('X1', 'VA', 1200)]), 'api');
        $s->store($ch, 'B', self::body([self::sl('X1', 'VA', 1200)]), 'api');
        // While the job works A's first snapshot out, the shop sends its correction (the API, on another connection).
        $other = TestDb::connect();
        $fired = false;
        $clock = static function () use (&$fired, $other, $ch): \DateTimeImmutable {
            if (!$fired) {
                $fired = true;
                self::assertSame('stored', (new OrderSnapshots($other))->accept($ch, 'A', self::body([self::sl('X2', 'VA', 1200)], ['version' => 1001]), 'api')['result']);
            }
            return Clock::now();
        };
        self::assertSame(['applied' => 0, 'refused' => 1, 'busy' => 0, 'left' => 1], (new OrderSnapshots(self::$db, $clock))->applyQueued(),
            'the first snapshot refused; the correction still waits');
        self::assertTrue($fired);
        self::assertSame(['0', '2'], array_map('strval', array_values((array) self::$db->one("SELECT failed, seq FROM order_snapshot_queue WHERE order_ref = 'A'"))));
        self::assertSame(1, $s->applyQueued()['applied'], 'the correction is worked out, never stranded');
        self::assertSame(['X2'], self::$db->column("SELECT line_id FROM sale_line WHERE order_ref = 'A' AND removed_at IS NULL"));
    }

    /** The Codex check of c48683e (LOW 1): a day's row of zeros kept after its only order moved to another day is a difference. */
    public function testTheCheckReportsADayRowOfZerosLeftByAnOrderThatMoved(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, 'Z', self::body([self::sl('Z1', 'NONE', 1200)]), 'api');
        $s->store($ch, 'Z', self::body([self::sl('Z1', 'NONE', 1200)], ['version' => 1001, 'paid_at' => '2026-11-20T10:00:00Z']), 'api');
        self::assertSame(['0', '0'], array_map('strval', array_values((array) self::$db->one('SELECT orders, sale_ex FROM profit_day WHERE channel_id = ? AND day = ?',
            [$ch, self::DAY]))), 'the old day keeps a row of zeros');
        self::assertSame(['day: a summary row of zeros kept with no paid order on the day (--apply removes it)'], ProfitDays::differences(self::$db, $ch, self::DAY));
        self::assertSame([], ProfitDays::differences(self::$db, $ch, '2026-11-20'));
    }

    /** The Codex check of c48683e (c-M6): ERPNext's cost is never stamped on a cancelled line (not one of the units it priced). */
    public function testErpNextsCostNeverStampsACancelledLine(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 10000, '2025-01-01 00:00:00');
        $this->listing($site, '101', $a);
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '2003', self::body([self::sl('C1', '101', 1200), self::sl('C2', '101', 1200, ['cancelled' => true])],
            ['paid_at' => '2025-12-15T10:00:00Z']), 'bulk');
        file_put_contents($this->dir . '/si_2025-12-15.tsv', self::tsv([['SI-1', '2003', 'P101', '1.000000000', '3.000000000', '1']]));
        (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, true, static fn (string $m) => null);
        $rows = self::$db->all("SELECT line_id, cost_source, cost FROM sale_line WHERE order_ref = '2003' ORDER BY line_id");
        self::assertSame(['C1', 'erp_at_sale', '300'], array_map('strval', array_values($rows[0])), 'the one unit ERPNext priced');
        self::assertNotSame('erp_at_sale', (string) $rows[1]['cost_source'], 'the cancelled line never takes ERPNext\'s cost');
    }
}
