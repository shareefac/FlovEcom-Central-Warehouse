<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\ErpSalesImport;
use CW\Profit\OrderSnapshots;
use CW\Profit\SnapshotImport;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The third final review of bc7e248 (build/A/codex_final3_astra_{a,b,c}.txt; final2/FIX_BRIEF_A4.md H4 and ALSO FIX; docs/decisions.md
 * PR154), the orders and the tools, each shown failing first on bc7e248: a parcel gone keeps no service code (H4); a malformed second copy of
 * an order refuses its day file whole (a-M, b-M1); the import's copy of a day file has no name on disk while it runs (a-M, b-M5); the dry run
 * refuses an order that names itself as its first order (b-L1); a re-send whose every line was cancelled counts on its first order as on the
 * day: none (c-L1); the backfill names its replacements whose first order is not in CW (c-M7); an ERPNext day there twice (.tsv and .tsv.gz)
 * is refused (b-M4); --source=all never re-costs ERPNext's own costs (a-M, c-M5); the seed refuses another store than ERPNext's (a-M, b-M2).
 */
final class FinalReview3SnapshotTest extends StockTestCase
{
    private const DAY = '2026-11-19';
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_final3_' . bin2hex(random_bytes(5));
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
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => '1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => '2026-11-19T10:00:00Z',
            'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines, 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 0,
            'parcels' => []];
    }

    /** A day file of these raw lines and its matching .sum (orders, lines and goods as given). */
    private function dayFile(array $raw, int $orders, int $lines, int $goods, string $store = 'vapeandgo', string $day = self::DAY): string
    {
        $file = "{$this->dir}/snapshots_{$store}_{$day}.jsonl";
        file_put_contents($file, implode("\n", $raw) . "\n");
        file_put_contents("{$this->dir}/snapshots_{$store}_{$day}.sum", json_encode(['channel' => $store, 'day' => $day, 'orders' => $orders, 'lines' => $lines,
            'goods_inc' => $goods, 'delivery_inc' => 0, 'file_sha256' => hash_file('sha256', $file)], JSON_THROW_ON_ERROR));
        return $file;
    }

    /** @return array{code: int, out: string} */
    private static function script(string $name, string ...$args): array
    {
        $cmd = [PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . "/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    /** H4: a parcel that a later snapshot drops keeps no service code (its ref, carrier and times stay). */
    public function testAParcelGoneKeepsNoServiceCode(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $s = new OrderSnapshots(self::$db);
        $parcel = ['parcel_ref' => 'odl:9500001', 'tracking_no' => null, 'carrier' => 'royal_mail', 'service_code' => 'TPL48HV', 'service' => 'Royal Mail Tracked 48',
            'status' => 'generated', 'label_at' => '2026-11-19T11:00:00Z', 'shipped_at' => null, 'manual' => false, 'partial' => false];
        $s->store((int) $site->channelId, '9500002', self::body([self::sl('9500003', '1', 100)], ['parcels' => [$parcel]]), 'bulk');
        self::assertSame('TPL48HV', (string) self::$db->value("SELECT service_code FROM sale_parcel WHERE order_ref = '9500002'"));
        $s->store((int) $site->channelId, '9500002', self::body([self::sl('9500003', '1', 100)], ['version' => 1001]), 'bulk');
        $p = (array) self::$db->one("SELECT service_code, service, carrier, removed_at FROM sale_parcel WHERE order_ref = '9500002'");
        self::assertNotNull($p['removed_at']);
        self::assertSame(['', '', 'royal_mail'], [(string) $p['service_code'], (string) $p['service'], (string) $p['carrier']], 'no code, no text; the carrier stays');
    }

    /**
     * a-M / b-M1: a valid order and a second copy of it whose lines are {} (a shape the check refuses first) in a file whose .sum matches: the
     * file is refused whole, nothing written (the valid copy was written and the run ended 1 after it).
     */
    public function testAMalformedSecondCopyOfAnOrderRefusesItsDayFileWhole(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $good = ['ref' => '5301'] + self::body([self::sl('9500011', '1', 1200)], ['grand_inc' => 1200]);
        $bad = json_encode(['ref' => '5301'] + self::body([self::sl('9500012', '1', 1200)]), JSON_THROW_ON_ERROR);
        $bad = str_replace('"lines":[', '"lines":{"0":', $bad);
        $bad = (string) preg_replace('/\],"delivery"/', '},"delivery"', $bad, 1);
        self::assertStringContainsString('"lines":{', $bad);
        $f = $this->dayFile([json_encode($good, JSON_THROW_ON_ERROR), $bad], 2, 2, 2400);
        $log = [];
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $f, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(1, $r['file_refused'], implode("\n", $log));
        self::assertStringContainsString('order 5301 is in the file twice', implode("\n", $log));
        self::assertNull(self::$db->value("SELECT 1 FROM sale_order WHERE channel_id = ? AND order_ref = '5301'", [$site->channelId]), 'nothing written');
        self::assertNull(self::$db->value("SELECT 1 FROM order_snapshot WHERE channel_id = ? AND order_ref = '5301'", [$site->channelId]));
    }

    /** a-M / b-M5: while the import reads a day file, its copy has no name on disk (a run killed half way leaves none); after it, none either. */
    public function testTheImportsCopyOfADayFileHasNoNameOnDisk(): void
    {
        $this->site('vapeandgo', 'live');
        $f = $this->dayFile([json_encode(['ref' => '5311'] + self::body([self::sl('9500021', '1', 1200)]), JSON_THROW_ON_ERROR)], 1, 1, 1200);
        $marker = 'cw_snap_';
        $before = glob(sys_get_temp_dir() . '/' . $marker . '*') ?: [];
        $seen = [];
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $f, true, function (string $m) use (&$seen, $before, $marker): void {
            foreach (array_diff(glob(sys_get_temp_dir() . '/' . $marker . '*') ?: [], $before) as $p) {
                $seen[] = $p;
            }
        });
        self::assertTrue($r['sum_ok']);
        // The dry run says the file's lines through $log (pass 1 and its refusals pass): the copy is open then.
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $this->dayFile([json_encode(['ref' => '5312'] + self::body([self::sl('9500022', '1', 1200)], ['currency' => 'USD']),
            JSON_THROW_ON_ERROR)], 1, 1, 1200), true, function (string $m) use (&$seen, $before, $marker): void {
                foreach (array_diff(glob(sys_get_temp_dir() . '/' . $marker . '*') ?: [], $before) as $p) {
                    $seen[] = $p;
                }
            });
        self::assertSame(1, $r['refused'], 'the refused line was said while the copy was open');
        self::assertSame([], $seen, 'no copy of the day file with a name while it ran');
        self::assertSame([], array_values(array_diff(glob(sys_get_temp_dir() . '/' . $marker . '*') ?: [], $before)), 'none after it');
    }

    /** b-L1: an order whose parent_ref is itself is refused by the dry run, as by the write. */
    public function testTheDryRunRefusesAnOrderThatNamesItselfAsItsFirstOrder(): void
    {
        $this->site('vapeandgo', 'live');
        $f = $this->dayFile([json_encode(['ref' => '5321'] + self::body([self::sl('9500031', '1', 1200)], ['parent_ref' => '5321']), JSON_THROW_ON_ERROR)], 1, 1, 1200);
        $log = [];
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $f, true, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(1, $r['refused'], implode("\n", $log));
        self::assertStringContainsString('parent_ref must be another order', implode("\n", $log));
    }

    /**
     * c-L1: a paid re-send whose only line was cancelled counts no unit: the day counts no re-send, and its first order none either (it said
     * one re-send there); a later replacement of it is still followed to the first order.
     */
    public function testAFullyCancelledResendCountsAsTheDayCountsIt(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, '9500041', $a);
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, '5331', self::body([self::sl('9500042', '9500041', 1200)]), 'bulk');
        $s->store($ch, '5332', self::body([self::sl('9500043', '9500041', 1200, ['cancelled' => true])], ['parent_ref' => '5331']), 'bulk');
        self::assertSame('0', (string) self::$db->value('SELECT resends FROM profit_day WHERE channel_id = ? AND day = ?', [$ch, self::DAY]), 'the day: none');
        self::assertSame('0', (string) self::$db->value("SELECT resends FROM sale_order WHERE channel_id = ? AND order_ref = '5331'", [$ch]), 'its first order: none either');
        $s->store($ch, '5333', self::body([self::sl('9500044', '9500041', 1200)], ['parent_ref' => '5332']), 'bulk');
        self::assertSame(['1', '300'], array_map('strval', array_values((array) self::$db->one("SELECT resends, resend_cost FROM sale_order WHERE channel_id = ? AND order_ref = '5331'",
            [$ch]))), 'its replacement still followed: one re-send, £3');
        self::assertSame('1', (string) self::$db->value('SELECT resends FROM profit_day WHERE channel_id = ? AND day = ?', [$ch, self::DAY]));
    }

    /** c-M7: the backfill names, at its end, each replacement of the file whose first order is not in CW (count and refs). */
    public function testTheBackfillNamesReplacementsWhoseFirstOrderIsNotInCw(): void
    {
        $site = $this->site('vapeandgo', 'live');
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '5340', self::body([self::sl('9500050', '1', 1200)]), 'bulk');
        $f = $this->dayFile([json_encode(['ref' => '5341'] + self::body([self::sl('9500051', '1', 1200)], ['parent_ref' => '5339']), JSON_THROW_ON_ERROR),
            json_encode(['ref' => '5342'] + self::body([self::sl('9500052', '1', 1200)], ['parent_ref' => '5340']), JSON_THROW_ON_ERROR)], 2, 2, 2400);
        $log = [];
        $r = (new SnapshotImport(self::$db))->file('vapeandgo', $f, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(2, $r['results']['stored'], implode("\n", $log));
        self::assertSame(1, $r['orphans'] ?? null);
        self::assertStringContainsString("1 replacement(s) of this file whose first order is not in CW (load the first order's day file): 5341 (first order 5339)",
            implode("\n", $log));
    }

    /** b-M4: a day there both as si_<day>.tsv and si_<day>.tsv.gz (an old file and a corrected one) is refused, nothing read or applied. */
    public function testAnErpNextDayThereTwiceIsRefused(): void
    {
        $this->site('vapeandgo', 'live');
        $head = "name\tposting_date\tcreation\tcustom_order_id\tis_return\treturn_against\tdocstatus\tnet_total\ttotal_taxes_and_charges\tgrand_total\t"
            . "discount_amount\tidx\titem_code\tqty\trate\tnet_rate\tnet_amount\tincoming_rate\twarehouse\n";
        file_put_contents($this->dir . '/si_2026-11-10.tsv', $head);
        file_put_contents($this->dir . '/si_2026-11-10.tsv.gz', (string) gzencode($head));
        $log = [];
        $r = (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, false, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(1, $r['refused'], implode("\n", $log));
        self::assertStringContainsString('2026-11-10', implode("\n", $log));
        self::assertStringContainsString('twice', implode("\n", $log));
        $x = self::script('import_erp_sales_cost', '--dir=' . $this->dir);
        self::assertSame(1, $x['code'], $x['out']);
    }

    /**
     * a-M / c-M5: --source=all re-costs none, estimate and cw_cost, never ERPNext's own cost at the sale (erp_at_sale): a £3 ERPNext cost stays
     * (it became CW's £5: profit £7 to £5); withdrawing it takes --source=erp_at_sale by name.
     */
    public function testAllNeverReCostsErpNextsOwnCost(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 50000);
        $this->listing($site, '9500061', $a);
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, '5351', self::body([self::sl('9500062', '9500061', 1200)]), 'bulk');
        self::$db->exec("UPDATE sale_line SET unit_cost_e4 = 30000, cost = 300, cost_source = 'erp_at_sale', costed_by = 'erp_import' WHERE line_id = '9500062'");
        $r = self::script('recost_sales', '--from=' . self::DAY, '--to=' . self::DAY, '--source=all', '--apply');
        self::assertSame(0, $r['code'], $r['out']);
        self::assertSame(['30000', 'erp_at_sale'], array_map('strval', array_values((array) self::$db->one("SELECT unit_cost_e4, cost_source FROM sale_line WHERE line_id = '9500062'"))),
            'ERPNext\'s £3 stays: ' . $r['out']);
        $r = self::script('recost_sales', '--from=' . self::DAY, '--to=' . self::DAY, '--source=erp_at_sale', '--apply');
        self::assertSame(0, $r['code'], $r['out']);
        self::assertSame('50000', (string) self::$db->value("SELECT unit_cost_e4 FROM sale_line WHERE line_id = '9500062'"), 'by name: withdrawn');
    }

    /** a-M / b-M2: the seed reads ERPNext's Bin through the store ERPNext's items are of; another --store is refused (exit 2), nothing written. */
    public function testTheSeedRefusesAnotherStoreThanErpNexts(): void
    {
        $this->site('vapeandgo', 'live');
        $this->site('electrofag', 'live');
        $bin = $this->dir . '/erp_bin.tsv';
        file_put_contents($bin, "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n");
        $r = self::script('seed_item_cost', '--bin=' . $bin, '--store=electrofag', '--apply');
        self::assertSame(2, $r['code'], $r['out']);
        self::assertStringContainsString('vapeandgo', $r['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM profit_job'), 'nothing written');
        $r = self::script('seed_item_cost', '--bin=' . $bin, '--store=vapeandgo');
        self::assertSame(0, $r['code'], 'its own store: the dry run ' . $r['out']);
    }
}
