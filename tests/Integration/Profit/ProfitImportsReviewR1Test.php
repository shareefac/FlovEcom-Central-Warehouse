<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Profit\ErpSalesImport;
use CW\Profit\OrderSnapshots;
use CW\Profit\SnapshotImport;
use CW\Tests\Support\StockTestCase;

/**
 * The Codex review of 92efa82 (build/A/codex_r1_{a,b}.txt), the batch tools, each shown failing first: one order invoiced on two days
 * (a9, b17); rows at a rate of 0 beside priced ones (a10); a malformed day (b16); a folder name with glob characters (b19); a bulk file whose
 * store is not proven (b13); a .sum that does not match is found before anything is written, a missing or broken one refuses the file (b14);
 * a line longer than the cap is one refused line, never the start of another (b15).
 */
final class ProfitImportsReviewR1Test extends StockTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cw_profit_[r1]_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        if (str_contains($this->dir, 'cw_profit_[r1]_')) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    private static function price(int $sku, int $e4): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', '2026-11-16', '2026-11-16')", [$sku, $e4]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, '2026-11-16', '2026-11-16', 'start', ?, ?)",
            [$sku, 'start:' . $sku, $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
    }

    private static function sl(string $id, string $variant, int $price): array
    {
        return ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false];
    }

    private static function body(array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-04-10T09:59:00Z',
            'paid_at' => '2026-04-10T10:00:00Z', 'gateway' => 'pp', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines, 'delivery' => null,
            'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []];
    }

    private static function tsv(array $rows, string $day): string
    {
        $out = "name\tposting_date\tcreation\tcustom_order_id\tis_return\treturn_against\tdocstatus\tnet_total\ttotal_taxes_and_charges\tgrand_total\tdiscount_amount\tidx"
            . "\titem_code\tqty\trate\tnet_rate\tnet_amount\tincoming_rate\twarehouse\n";
        foreach ($rows as $i => [$name, $order, $item, $qty, $net, $rate]) {
            $out .= implode("\t", [$name, $day, $day . ' 10:00:00', $order, '0', 'NULL', '1', '1', '0', '1', '0', $i + 1, $item, $qty, '1', '1', $net, $rate, 'Stores - VPG']) . "\n";
        }
        return $out;
    }

    /** @return array<string, string> line id => unit_cost_e4 / cost_source */
    private static function costs(string $ref): array
    {
        return array_column(array_map(static fn (array $r): array => ['id' => (string) $r['line_id'], 'c' => $r['unit_cost_e4'] . '/' . $r['cost_source']],
            self::$db->all('SELECT line_id, unit_cost_e4, cost_source FROM sale_line WHERE order_ref = ? ORDER BY line_id', [$ref])), 'c', 'id');
    }

    public function testErpNextDaysAreReadWholeAndTogether(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        self::price($a, 10000);
        $this->listing($site, '101', $a);
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, '1', self::body([self::sl('9100001', '101', 1200), self::sl('9100002', '101', 1200)]), 'bulk');
        $s->store((int) $site->channelId, '2', self::body([self::sl('9100003', '101', 1200), self::sl('9100004', '101', 1200)]), 'bulk');
        $s->store((int) $site->channelId, '3', self::body([self::sl('9100005', '101', 1200)]), 'bulk');
        // Order 1: one unit invoiced on each of two days, at £1 and £3. Order 2: one unit at £2 and one at a rate of 0.
        file_put_contents($this->dir . '/si_2026-04-10.tsv', self::tsv([['SI-1', '1', 'P101', '1', '8.33', '1.0'], ['SI-2', '2', 'P101', '1', '8.33', '2.0'],
            ['SI-2', '2', 'P101', '1', '8.33', '0']], '2026-04-10'));
        file_put_contents($this->dir . '/si_2026-04-11.tsv.gz', gzencode(self::tsv([['SI-3', '1', 'P101', '1', '8.33', '3.0']], '2026-04-11')));
        // A broken day: a row cut short, a quantity that is no number.
        file_put_contents($this->dir . '/si_2026-04-12.tsv', self::tsv([['SI-4', '3', 'P101', '1', '8.33', '5.0']], '2026-04-12') . "SI-5\t2026-04-12\tcut short\n");
        $log = [];
        $r = (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, true, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(['days' => 0, 'orders' => 0, 'lines' => 0, 'refused' => 1, 'waiting' => 0], $r, 'a broken day stops the run before anything is written');
        self::assertSame(['9100001' => '10000/estimate', '9100002' => '10000/estimate'], self::costs('1'));
        self::assertStringContainsString('2026-04-12 refused: row 2', implode("\n", $log));
        unlink($this->dir . '/si_2026-04-12.tsv');
        $log = [];
        // Asked for the second day only: order 1's rows of the first day are read with it.
        $r = (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', '2026-04-11', '2026-04-11', true, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(['9100001' => '20000/erp_at_sale', '9100002' => '20000/erp_at_sale'], self::costs('1'), 'its two days together: (1 + 3) / 2 a unit');
        self::assertSame(['9100003' => '10000/estimate', '9100004' => '10000/estimate'], self::costs('2'), 'its last day is not asked for: left');
        self::assertSame(1, $r['days']);
        (new ErpSalesImport(self::$db))->run($this->dir, 'vapeandgo', null, null, true, function (string $m) use (&$log): void {
            $log[] = $m;
        });
        self::assertSame(['9100003' => '10000/estimate', '9100004' => '10000/estimate'], self::costs('2'), 'a rate of 0 beside a priced one: the variant is left as it was');
        self::assertStringContainsString('rate 0 1', implode("\n", $log));
        self::assertSame(['9100001' => '20000/erp_at_sale', '9100002' => '20000/erp_at_sale'], self::costs('1'), 'run again: the same');
    }

    public function testABulkFileProvesItsStoreAndItsSumBeforeAnythingIsWritten(): void
    {
        $this->site('vapeandgo', 'live');
        $this->site('vapebig', 'live');
        $line = static fn (string $ref, string $day = '2026-04-10'): string => json_encode(['ref' => $ref] + self::body([self::sl('9100006' . $ref, '9', 100)],
            ['placed_at' => $day . 'T09:59:00Z']), JSON_THROW_ON_ERROR);
        $import = new SnapshotImport(self::$db);
        $quiet = static fn (string $m) => null;
        // A file whose name names no store: refused.
        file_put_contents($this->dir . '/backfill.jsonl', $line('1') . "\n");
        $this->refused(fn () => $import->file('vapebig', $this->dir . '/backfill.jsonl', false, $quiet), 'snapshots_<store>_<day>.jsonl');
        // No .sum, or a .sum of another store, or one that is broken: refused, nothing written.
        $file = $this->dir . '/snapshots_vapebig_2026-04-10.jsonl';
        file_put_contents($file, $line('1') . "\n" . $line('2') . "\n");
        $this->refused(fn () => $import->file('vapebig', $file, false, $quiet), '.sum');
        $sum = ['channel' => 'vapebig', 'day' => '2026-04-10', 'orders' => 2, 'lines' => 2, 'goods_inc' => 200, 'delivery_inc' => 0, 'file_sha256' => hash_file('sha256', $file)];
        file_put_contents($this->dir . '/snapshots_vapebig_2026-04-10.sum', json_encode(['channel' => 'vapeandgo'] + $sum));
        $this->refused(fn () => $import->file('vapebig', $file, false, $quiet), 'vapeandgo');
        file_put_contents($this->dir . '/snapshots_vapebig_2026-04-10.sum', '{"orders": 2');
        $this->refused(fn () => $import->file('vapebig', $file, false, $quiet), '.sum');
        file_put_contents($this->dir . '/snapshots_vapebig_2026-04-10.sum', json_encode(['goods_inc' => 999] + $sum));
        $r = $import->file('vapebig', $file, false, $quiet);
        self::assertFalse($r['sum_ok']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM order_snapshot'), 'a .sum that does not match: nothing written');
        file_put_contents($this->dir . '/snapshots_vapebig_2026-04-10.sum', json_encode($sum));
        self::assertSame(['stored' => 2, 'same' => 0, 'older_ignored' => 0], $import->file('vapebig', $file, false, $quiet)['results']);
        // A line longer than the cap is one refused line: what follows it on that line is never read as an order.
        $long = $this->dir . '/snapshots_vapebig_2026-04-11.jsonl';
        file_put_contents($long, str_repeat('9100006', SnapshotImport::MAX_LINE_BYTES + 5) . $line('3', '2026-04-11') . "\n" . $line('4', '2026-04-11') . "\n");
        file_put_contents($this->dir . '/snapshots_vapebig_2026-04-11.sum', json_encode(['channel' => 'vapebig', 'day' => '2026-04-11', 'orders' => 1, 'lines' => 1,
            'goods_inc' => 100, 'delivery_inc' => 0, 'file_sha256' => hash_file('sha256', $long)]));
        $r = $import->file('vapebig', $long, false, $quiet);
        self::assertSame([1, 1], [$r['refused'], $r['results']['stored']]);
        self::assertNull(self::$db->value("SELECT 1 FROM order_snapshot WHERE order_ref = '3'"));
    }

    private function refused(callable $fn, string $says): void
    {
        try {
            $fn();
            self::fail('not refused: ' . $says);
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($says, $e->getMessage());
        }
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM order_snapshot'), 'nothing written');
    }
}
