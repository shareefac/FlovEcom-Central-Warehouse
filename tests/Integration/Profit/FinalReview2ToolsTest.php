<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\CostEngine;
use CW\Profit\ErpSalesImport;
use CW\Profit\OrderSnapshots;
use CW\Profit\SnapshotImport;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The second final review of 3c31d94 (build/A/codex_final2_astra_{a,b,c}.txt; final2/FIX_BRIEF.md), the tools and imports, each shown
 * failing first with the reviewers' numbers: a tool that finds its lock held ends with exit 4, never 0 (F6); a day file with an order twice
 * or an order of another day is refused before anything is written (F9); ERPNext's reconciliation shows the orders its export misses and
 * refuses a folder with no day file (F10); the ERPNext import is bound to the store its items are of (F11); a Bin file names its warehouses
 * and holds each item and warehouse once (F15); a day file of orders placed before the store's first reservation in CW is refused (F19);
 * one day that fails never stops the nightly rebuild (F3b).
 */
final class FinalReview2ToolsTest extends StockTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_final2tools_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @return list<string> */
    private static function command(string $name, string ...$args): array
    {
        return [PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . "/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args];
    }

    /** @return array{code: int, out: string} */
    private static function script(string $name, string ...$args): array
    {
        $p = proc_open(self::command($name, ...$args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    private static function jobLock(string $job): string
    {
        $name = 'cw_job:' . $job . ':' . TestDb::name();
        return strlen($name) > 64 ? 'cw_job:' . sha1($job . "\0" . TestDb::name()) : $name;
    }

    /** @return array<string, mixed> */
    private static function body(string $ref, string $line, int $price, array $over = []): array
    {
        return $over + ['ref' => $ref, 'v' => 1, 'version' => 1000, 'order_no' => '1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:00:00Z',
            'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'pp', 'currency' => 'GBP', 'parent_ref' => null,
            'lines' => [['line_id' => $line, 'variant_id' => '1', 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
                'free' => false]], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => $price, 'parcels' => []];
    }

    /** A day file and its matching .sum (the connector's export). @param list<array<string, mixed>> $bodies */
    private function dayFile(string $day, array $bodies, string $store = 'vapeandgo'): string
    {
        $file = "{$this->dir}/snapshots_{$store}_{$day}.jsonl";
        $lines = 0;
        $goods = 0;
        $text = '';
        foreach ($bodies as $b) {
            $text .= json_encode($b, JSON_THROW_ON_ERROR) . "\n";
            foreach ($b['lines'] as $l) {
                $lines++;
                $goods += $l['price_inc'] - $l['discount_inc'];
            }
        }
        file_put_contents($file, $text);
        file_put_contents("{$this->dir}/snapshots_{$store}_{$day}.sum", json_encode(['channel' => $store, 'day' => $day, 'orders' => count($bodies), 'lines' => $lines,
            'goods_inc' => $goods, 'delivery_inc' => 0, 'file_sha256' => hash_file('sha256', $file)], JSON_THROW_ON_ERROR));
        return $file;
    }

    /** F6 (a-M5, b-M1, c-M8): the start, the ERPNext import and the re-cost, each finding another run holding its lock: exit 4, nothing done. */
    public function testAToolThatFindsItsLockHeldEndsBusyNeverDone(): void
    {
        $bin = $this->dir . '/erp_bin.tsv';
        file_put_contents($bin, "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n");
        file_put_contents($this->dir . '/si_2026-04-12.tsv', "name\tposting_date\tcreation\tcustom_order_id\tis_return\treturn_against\tdocstatus\tnet_total\t"
            . "total_taxes_and_charges\tgrand_total\tdiscount_amount\tidx\titem_code\tqty\trate\tnet_rate\tnet_amount\tincoming_rate\twarehouse\n");
        $this->site('vapeandgo', 'live');
        $other = TestDb::connect();
        foreach ([[CostEngine::JOB, ['seed_item_cost', '--bin=' . $bin, '--apply']], ['import_erp_sales_cost', ['import_erp_sales_cost', '--dir=' . $this->dir, '--apply']],
            ['recost_sales', ['recost_sales', '--from=2026-11-19', '--to=2026-11-19', '--apply']]] as [$job, $cmd]) {
            self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [self::jobLock($job)]));
            try {
                $r = self::script(...$cmd);
                self::assertSame(4, $r['code'], "{$cmd[0]}: busy, nothing done (never exit 0); " . $r['out']);
                self::assertStringContainsString('nothing done', $r['out']);
            } finally {
                $other->value('SELECT RELEASE_LOCK(?)', [self::jobLock($job)]);
            }
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM profit_job'), 'the start was not written');
        $r = self::script('seed_item_cost', '--bin=' . $bin, '--apply');
        self::assertSame(0, $r['code'], $r['out']);
        self::assertMatchesRegularExpression('/started \d+ items/', $r['out'], 'the go-live step\'s line');
    }

    /** F9 (a-L3, b-M5): two copies of one order (and a .sum that says two) and an order placed on another day: refused, nothing written. */
    public function testADayFileWithAnOrderTwiceOrOfAnotherDayIsRefusedWhole(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $log = [];
        $f = $this->dayFile('2026-11-19', [self::body('5101', '9100005', 1200), self::body('5101', '9100005', 1200)]);
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $f, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertFalse($r['sum_ok'] && $r['totals_checked'], 'never "matches": ' . implode(' | ', $log));
        self::assertStringContainsString('order 5101 is in the file twice', implode("\n", $log));
        self::assertNull(self::$db->value('SELECT 1 FROM order_snapshot_queue WHERE channel_id = ?', [$site->channelId]), 'nothing written');
        self::assertNull(self::$db->value('SELECT 1 FROM sale_order WHERE channel_id = ?', [$site->channelId]));
        $log = [];
        $f = $this->dayFile('2026-11-19', [self::body('5102', '9100006', 1200), self::body('5103', '9100007', 1200, ['placed_at' => '2026-11-18T23:30:00Z'])]);
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $f, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertFalse($r['sum_ok'] && $r['totals_checked'], implode(' | ', $log));
        self::assertStringContainsString('placed on 2026-11-18', implode("\n", $log), 'the London day of its placing (23:30 UTC is still the 18th in winter)');
        self::assertNull(self::$db->value('SELECT 1 FROM sale_order WHERE channel_id = ?', [$site->channelId]), 'nothing written');
        // The same orders placed on the file's own day: stored.
        $f = $this->dayFile('2026-11-19', [self::body('5102', '9100006', 1200), self::body('5103', '9100007', 1200, ['placed_at' => '2026-11-19T00:30:00Z'])]);
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $f, false, static fn (string $m) => null);
        self::assertSame([true, true, 2], [$r['sum_ok'], $r['totals_checked'], $r['results']['stored']]);
    }

    /**
     * F19 (c-HIGH3 scope): a day file of orders placed before the store's first reservation in CW (orders CW never held: their returns are
     * booked through the historical path, which the profit does not match) is refused unless --allow-unreserved.
     */
    public function testADayFileOfOrdersBeforeTheStoresFirstReservationIsRefused(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 5);
        $this->listing($site, '1', $a);
        $this->ok($this->commit($site, '9100004', [self::line('1', '9100001')])); // the store's first reservation: now
        $f = $this->dayFile('2026-11-19', [self::body('5201', '9100008', 1200)]);
        $r = self::script('import_order_snapshots', '--channel=vapeandgo', '--file=' . $f);
        self::assertSame(1, $r['code'], 'refused: ' . $r['out']);
        self::assertStringContainsString('placed before the store\'s first reservation', $r['out']);
        self::assertNull(self::$db->value("SELECT 1 FROM sale_order WHERE order_ref = '5201'"), 'nothing written');
        $r = self::script('import_order_snapshots', '--channel=vapeandgo', '--file=' . $f, '--allow-unreserved');
        self::assertSame(0, $r['code'], $r['out']);
        self::assertNotNull(self::$db->value("SELECT 1 FROM sale_order WHERE order_ref = '5201'"));
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string, 4: string}> $rows name, order, item, qty, net (rate 5.00) */
    private static function si(string $day, array $rows): string
    {
        $out = "name\tposting_date\tcreation\tcustom_order_id\tis_return\treturn_against\tdocstatus\tnet_total\ttotal_taxes_and_charges\tgrand_total\t"
            . "discount_amount\tidx\titem_code\tqty\trate\tnet_rate\tnet_amount\tincoming_rate\twarehouse\n";
        foreach ($rows as $i => [$name, $order, $item, $qty, $net]) {
            $out .= implode("\t", [$name, $day, $day . ' 10:00:00.000000', $order, '0', 'NULL', '1', $net, '0.00', $net, '0.000000000', (string) ($i + 1), $item, $qty,
                $net, $net, $net, '5.000000000', 'Stores - VPG']) . "\n";
        }
        return $out;
    }

    /**
     * F10 (a-M4, b-M4, c-M3): CW holds two orders paid on a day (£60 and £40 without VAT), the export only the £60 one: the day's line says one
     * CW order and £40 are not in the export (it said one order and £60 on both sides). A folder with no day file in the days asked for is
     * refused (it finished with zero days).
     */
    public function testErpNextsReconciliationShowsTheOrdersItsExportMisses(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $this->listing($site, '101', $a);
        $s = new OrderSnapshots(self::$db);
        foreach (['1101' => 7200, '1102' => 4800] as $ref => $inc) {
            $s->store((int) $site->channelId, (string) $ref, ['v' => 1, 'version' => 1, 'order_no' => '', 'status' => 'completed', 'placed_at' => '2026-11-10T09:00:00Z',
                'paid_at' => '2026-11-10T10:00:00Z', 'gateway' => 'pp', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [['line_id' => '9100002' . $ref, 'variant_id' => '101',
                'qty' => 1, 'price_inc' => $inc, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false]], 'delivery' => null, 'order_refund_inc' => 0,
                'grand_inc' => $inc, 'parcels' => []], 'bulk');
        }
        file_put_contents($this->dir . '/si_2026-11-10.tsv', self::si('2026-11-10', [['SI-1', '1101', 'P101', '1.000000000', '60.000000000']]));
        $log = [];
        (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertStringContainsString('cw orders paid that day not in the export 1 (GBP 40.00)', implode("\n", $log));
        $log = [];
        $r = (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', '2026-10-01', '2026-10-02', false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(1, $r['refused'], 'no day file in the days asked for: refused, never "0 days done"');
        self::assertStringContainsString('no day file', implode("\n", $log));
        $r = self::script('import_erp_sales_cost', '--dir=' . $this->dir, '--from=2026-10-01', '--to=2026-10-02');
        self::assertSame(1, $r['code'], $r['out']);
    }

    /**
     * F11 (b-HIGH2): the export carries no shop of its own: its item codes are "P" + a variant of the store ERPNext's items are of
     * (erp_opening.listing_store), so it is that store's; given another store, it is refused before a file is read.
     */
    public function testTheErpNextImportIsBoundToTheStoreItsItemsAreOf(): void
    {
        $this->site('vapeandgo', 'live');
        $this->site('electrofag', 'live');
        file_put_contents($this->dir . '/si_2026-11-10.tsv', self::si('2026-11-10', [['SI-1', '123', 'P401', '1.000000000', '10.000000000']]));
        try {
            (new ErpSalesImport(self::$db))->run($this->dir, 'electrofag', null, null, true, static fn (string $m) => null);
            self::fail('another store\'s orders never take ERPNext\'s costs');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('vapeandgo', $e->getMessage());
        }
        $r = self::script('import_erp_sales_cost', '--dir=' . $this->dir, '--channel=electrofag', '--apply');
        self::assertSame(2, $r['code'], $r['out']);
    }

    /**
     * F15 (b-M6): a Bin file must name its warehouses, and each item and warehouse once: two copies of warehouse A's row (10 at £2) where B's
     * (10 at £6) was are refused, even with --rows=2 (the opening would be £2, never £4).
     */
    public function testABinFileNamesItsWarehousesEachOnce(): void
    {
        $engine = new CostEngine(self::$db);
        try {
            $engine->plan("item_code\tactual_qty\tvaluation_rate\nP500\t10\t2.00\n", 'vapeandgo');
            self::fail('a Bin file without its warehouses');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('warehouse', $e->getMessage());
        }
        $bin = "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\nP500\tStores - VPG\t10\t2.00\t20\nP500\tStores - VPG\t10\t2.00\t20\n";
        $plan = $engine->plan($bin, 'vapeandgo', null, [], 2);
        self::assertSame([3], array_column($plan['broken'], 'row'), 'the second copy is broken: ' . json_encode($plan['broken']));
        self::assertStringContainsString('twice', (string) $plan['broken'][0]['why']);
    }

    /** F3b: one store-day that cannot be rebuilt (its row held elsewhere) is named; the next day is rebuilt and the run says it found a problem. */
    public function testOneDayThatFailsNeverStopsTheNightlyRebuild(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $s = new OrderSnapshots(self::$db);
        foreach (['2026-11-18' => '9100041', '2026-11-19' => '9100042'] as $day => $ref) {
            $s->store($ch, $ref, ['v' => 1, 'version' => 1, 'order_no' => '1', 'status' => 'completed', 'placed_at' => $day . 'T09:00:00Z', 'paid_at' => $day . 'T10:00:00Z',
                'gateway' => 'pp', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [['line_id' => $ref . '1', 'variant_id' => '9100003', 'qty' => 1, 'price_inc' => 1200,
                'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false]], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 1200,
                'parcels' => []], 'bulk');
        }
        self::$db->exec("UPDATE profit_day SET cost = 900 WHERE channel_id = ? AND day = '2026-11-19'", [$ch]);
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one("SELECT * FROM profit_day WHERE channel_id = ? AND day = '2026-11-18' FOR UPDATE", [$ch]);
        try {
            $r = self::script('rebuild_profit_days', '--from=2026-11-18', '--to=2026-11-19', '--apply');
        } finally {
            $other->pdo()->rollBack();
        }
        self::assertSame(1, $r['code'], 'a problem, said: ' . $r['out']);
        self::assertStringContainsString('2026-11-18', $r['out'], 'the day that failed is named');
        self::assertSame('0', (string) self::$db->value("SELECT cost FROM profit_day WHERE channel_id = ? AND day = '2026-11-19'", [$ch]), 'the next day was rebuilt');
    }
}
