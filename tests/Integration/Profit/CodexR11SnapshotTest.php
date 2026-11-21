<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\CwException;
use CW\Profit\ErpSalesImport;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitViews;
use CW\Profit\SnapshotImport;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The Codex check of the fix round (r11, build/A/codex_r11_astra.txt, gpt-6.2-astra), the orders and tools, each shown failing first on
 * 973c8ff: ERPNext's rows of one order in two cases are one order (F3 PARTLY); the seed checks the start it resumes and says when a waiting
 * sale failed (F6 PARTLY, new MEDIUM 2); a bulk order held by a lock is one refused line, the rest of the file goes on (F8 PARTLY); an order
 * twice in a day file is refused whole even when one copy is broken (F9, F19 PARTLY); ERPNext's reconciliation tells a missing order from one
 * invoiced on another day, on a day with no file too (F10 PARTLY); the import takes the series the export's invoices are of (F11 PARTLY); a
 * replacement of a replacement waits for its first order's guard (F14 PARTLY); an order no longer paid shows no profit in the list (F18).
 */
final class CodexR11SnapshotTest extends StockTestCase
{
    private const DAY = '2026-11-19';
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_r11_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
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
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => '2026-11-19T10:00:00Z',
            'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines, 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 0,
            'parcels' => []];
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

    /** @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5?: string}> $rows name, order, item, qty, net, rate */
    private static function si(string $day, array $rows): string
    {
        $out = "name\tposting_date\tcreation\tcustom_order_id\tis_return\treturn_against\tdocstatus\tnet_total\ttotal_taxes_and_charges\tgrand_total\t"
            . "discount_amount\tidx\titem_code\tqty\trate\tnet_rate\tnet_amount\tincoming_rate\twarehouse\n";
        foreach ($rows as $i => $r) {
            [$name, $order, $item, $qty, $net] = $r;
            $out .= implode("\t", [$name, $day, $day . ' 10:00:00.000000', $order, '0', 'NULL', '1', $net, '0.00', $net, '0.000000000', (string) ($i + 1), $item, $qty,
                $net, $net, $net, $r[5] ?? '5.000000000', 'Stores - VPG']) . "\n";
        }
        return $out;
    }

    private function storeOrder(int $ch, string $ref, string $paid, array $lines): void
    {
        (new OrderSnapshots(self::$db))->store($ch, $ref, self::body($lines, ['placed_at' => $paid, 'paid_at' => $paid]), 'bulk');
    }

    /** F3 PARTLY: two units of P7 invoiced as VPG42 at £2 and vpg42 at £4: one order, £3 a unit (two one-unit "mismatches" before). */
    public function testErpNextsRowsOfOneOrderInTwoCasesAreOneOrder(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 10000);
        $this->listing($site, '7', $a);
        $this->storeOrder((int) $site->channelId, 'proto1-42', '2026-11-10T10:00:00Z', [self::sl('9100001', '7', 1200), self::sl('9100002', '7', 1200)]);
        file_put_contents($this->dir . '/si_2026-11-10.tsv', self::si('2026-11-10', [['SI-1', 'PROTO1-42', 'P7', '1.000000000', '10.000000000', '2.000000000'],
            ['SI-2', 'proto1-42', 'P7', '1.000000000', '10.000000000', '4.000000000']]));
        (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, true, static fn (string $m) => null);
        self::assertSame(['30000', '30000'], array_map('strval', self::$db->column("SELECT unit_cost_e4 FROM sale_line WHERE order_ref = 'proto1-42' ORDER BY line_id")),
            'one order: (2 + 4) / 2 a unit');
    }

    /** F6 PARTLY: a start written for 16 Nov is never "verified" by a run asking for 17 Nov; new MEDIUM 2: a waiting sale that failed is said. */
    public function testTheSeedChecksTheStartItResumesAndSaysWhenASaleFailed(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $this->listing($site, '1', $this->item('legacy'));
        (new OrderSnapshots(self::$db))->store($ch, '9100015', self::body([self::sl('9100003', '1', 1200)]), 'api'); // its cost waits for the start
        self::assertNull(self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '9100003'"));
        self::$db->exec("UPDATE order_snapshot SET body = JSON_OBJECT('paid_at', NULL, 'lines', 5) WHERE channel_id = ? AND order_ref = '9100015'", [$ch]);
        $bin = $this->dir . '/erp_bin.tsv';
        file_put_contents($bin, "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n");
        $r = self::script('seed_item_cost', '--bin=' . $bin, '--start=2026-11-16', '--apply');
        self::assertSame(1, $r['code'], 'a waiting sale failed: a problem, named; ' . $r['out']);
        self::assertStringContainsString('9100015', $r['out']);
        $r = self::script('seed_item_cost', '--bin=' . $bin, '--start=2026-11-17', '--apply');
        self::assertSame(1, $r['code'], 'the start resumed is of another day: never "done"; ' . $r['out']);
        self::assertStringContainsString('2026-11-16', $r['out']);
    }

    /** F8 PARTLY: an order of a bulk file whose rows another writer holds is ONE refused line (busy); the next order of the file is stored. */
    public function testABulkOrderHeldByALockIsOneRefusedLine(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, '1', $a);
        $lines = [json_encode(['ref' => '6101'] + self::body([self::sl('9100004', '1', 1200)]), JSON_THROW_ON_ERROR),
            json_encode(['ref' => '6102'] + self::body([self::sl('9100005', '1', 1200)], ['paid_at' => '2026-11-20T10:00:00Z']), JSON_THROW_ON_ERROR)];
        $file = $this->dir . '/snapshots_vapeandgo_2026-11-19.jsonl';
        file_put_contents($file, implode("\n", $lines) . "\n");
        file_put_contents($this->dir . '/snapshots_vapeandgo_2026-11-19.sum', json_encode(['channel' => 'vapeandgo', 'day' => '2026-11-19', 'orders' => 2, 'lines' => 2,
            'goods_inc' => 2400, 'delivery_inc' => 0, 'file_sha256' => hash_file('sha256', $file)], JSON_THROW_ON_ERROR));
        $other = TestDb::connect();
        $other->exec('INSERT INTO profit_day (channel_id, day) VALUES (?, ?) AS n ON DUPLICATE KEY UPDATE channel_id = n.channel_id', [$ch, self::DAY]);
        $other->pdo()->beginTransaction();
        $other->one('SELECT * FROM profit_day WHERE channel_id = ? AND day = ? FOR UPDATE', [$ch, self::DAY]);
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $log = [];
        try {
            $r = (new SnapshotImport(self::$db))->file('vapeandgo', $file, false, function (string $m) use (&$log): void {
                $log[] = $m;
            });
        } finally {
            $other->pdo()->rollBack();
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        self::assertSame([1, 1], [$r['refused'], $r['results']['stored']], implode(' | ', $log));
        self::assertStringContainsString('busy', implode("\n", $log));
        self::assertNotNull(self::$db->value("SELECT 1 FROM sale_order WHERE order_ref = '6102'"), 'the next order of the file was stored');
    }

    /** F9 / F19 PARTLY: ref 42 twice, one copy broken (qty 2), one valid: the file is refused whole (the valid copy was written). */
    public function testAnOrderTwiceInADayFileIsRefusedWholeEvenWhenOneCopyIsBroken(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $lines = [json_encode(['ref' => '42'] + self::body([self::sl('9100006', '1', 1200, ['qty' => 2])]), JSON_THROW_ON_ERROR),
            json_encode(['ref' => '42'] + self::body([self::sl('9100006', '1', 1200)]), JSON_THROW_ON_ERROR)];
        $file = $this->dir . '/snapshots_vapeandgo_2026-11-19.jsonl';
        file_put_contents($file, implode("\n", $lines) . "\n");
        file_put_contents($this->dir . '/snapshots_vapeandgo_2026-11-19.sum', json_encode(['channel' => 'vapeandgo', 'day' => '2026-11-19', 'orders' => 1, 'lines' => 1,
            'goods_inc' => 1200, 'delivery_inc' => 0, 'file_sha256' => hash_file('sha256', $file)], JSON_THROW_ON_ERROR));
        $log = [];
        (new SnapshotImport(self::$db))->file('vapeandgo', $file, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertStringContainsString('order 42 is in the file twice', implode("\n", $log));
        self::assertNull(self::$db->value('SELECT 1 FROM sale_order WHERE channel_id = ?', [$site->channelId]), 'nothing written');
    }

    /**
     * F10 PARTLY: an order invoiced on its paid day and the next is no "other invoice day" on its paid day; an order paid on a day with no
     * file and invoiced on the next day is "on another invoice day", not missing; one in no file at all is missing.
     */
    public function testErpNextsReconciliationTellsAMissingOrderFromOneInvoicedOnAnotherDay(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        $this->listing($site, '101', $a);
        $this->storeOrder($ch, '1201', '2026-11-11T10:00:00Z', [self::sl('9100007', '101', 1200)]); // invoiced on the 11th and the 12th
        $this->storeOrder($ch, '1202', '2026-11-13T10:00:00Z', [self::sl('9100008', '101', 1200)]); // paid on the 13th (no file), invoiced on the 14th
        $this->storeOrder($ch, '1203', '2026-11-13T11:00:00Z', [self::sl('9100009', '101', 4800)]); // paid on the 13th, in no file: missing
        file_put_contents($this->dir . '/si_2026-11-11.tsv', self::si('2026-11-11', [['SI-1', '1201', 'P101', '1.000000000', '10.000000000']]));
        file_put_contents($this->dir . '/si_2026-11-12.tsv', self::si('2026-11-12', [['SI-2', '1201', 'P101', '0.000000000', '0.000000000']]));
        file_put_contents($this->dir . '/si_2026-11-14.tsv', self::si('2026-11-14', [['SI-3', '1202', 'P101', '1.000000000', '10.000000000']]));
        $log = [];
        (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        $all = implode("\n", $log);
        self::assertStringContainsString('2026-11-11 erp orders', $log[0]);
        self::assertStringContainsString('not in the export 0 (GBP 0.00), on another invoice day 0', $log[0], 'invoiced on its paid day too');
        self::assertStringContainsString('no day file: 2 orders, of them not in the export 1 (GBP 40.00), on another invoice day 1', $all);
    }

    /** F11 PARTLY: --apply takes the series the invoices of the export are of: a row of another series refuses its day; a dry run says the series it saw. */
    public function testTheErpNextImportTakesTheSeriesOfItsInvoices(): void
    {
        $this->site('vapeandgo', 'live');
        file_put_contents($this->dir . '/si_2026-11-10.tsv', self::si('2026-11-10', [['SINV-26-00001', '123', 'P401', '1.000000000', '10.000000000'],
            ['SINV-26-00002', '124', 'P401', '1.000000000', '10.000000000']]));
        $r = self::script('import_erp_sales_cost', '--dir=' . $this->dir, '--channel=vapeandgo', '--apply');
        self::assertSame(2, $r['code'], 'no series given: usage; ' . $r['out']);
        $r = self::script('import_erp_sales_cost', '--dir=' . $this->dir, '--channel=vapeandgo');
        self::assertSame(0, $r['code'], $r['out']);
        self::assertStringContainsString('SINV-##-##### (2 rows)', $r['out'], 'the dry run names the series it saw (no row)');
        $r = self::script('import_erp_sales_cost', '--dir=' . $this->dir, '--channel=vapeandgo', '--series=EF-SINV-', '--apply');
        self::assertSame(1, $r['code'], 'rows of another series: refused, nothing applied; ' . $r['out']);
        $r = self::script('import_erp_sales_cost', '--dir=' . $this->dir, '--channel=vapeandgo', '--series=SINV-', '--apply');
        self::assertSame(0, $r['code'], $r['out']);
    }

    /** F14 PARTLY: a replacement of a replacement holds its first order's guard too (a reparent elsewhere in the chain waits for it). */
    public function testAReplacementOfAReplacementWaitsForItsFirstOrdersGuard(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, '8400', self::body([self::sl('9100010', '1', 1200)]), 'api');
        $s->store($ch, '8401', self::body([self::sl('9100011', '1', 0, ['free' => true])], ['parent_ref' => '8400']), 'api');
        $s->store($ch, '8402', self::body([self::sl('9100012', '1', 0, ['free' => true])], ['parent_ref' => '8401']), 'api');
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($ch, '8400')]));
        try {
            (new OrderSnapshots(self::$db, lockWaitSec: 1))->store($ch, '8403', self::body([self::sl('9100013', '1', 0, ['free' => true])], ['parent_ref' => '8402']), 'api');
            self::fail('the first order of its chain is held: busy');
        } catch (CwException $e) {
            self::assertSame('busy', $e->errorCode);
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::lockName($ch, '8400')]);
        }
        self::assertSame(1, (new OrderSnapshots(self::$db))->applyQueued()['applied'], 'worked out once it is free');
        self::assertSame('3', (string) self::$db->value("SELECT resends FROM sale_order WHERE order_ref = '8400'"));
    }

    /** F18 PARTLY: an order paid with its cost fixed, then no longer paid: the list shows no profit and no margin for it. */
    public function testAnOrderNoLongerPaidShowsNoProfitInTheList(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, '1', $a);
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, '8500', self::body([self::sl('9100014', '1', 1200)]), 'api');
        $s->store($ch, '8500', self::body([self::sl('9100014', '1', 1200)], ['version' => 1001, 'paid_at' => null, 'status' => 'pending']), 'api');
        $row = (new ProfitViews(self::$db))->orders(self::DAY, self::DAY, $ch, '8500', false)['rows'][0];
        self::assertSame([null, null], [$row['profit'], $row['margin_bp']], 'not paid: no profit (it showed £7, 70%)');
    }
}
