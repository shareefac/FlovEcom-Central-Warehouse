<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Db;
use CW\Tests\Support\MigrationFixture;
use CW\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

/**
 * tools/sales_history/export.php (spec §7.1, docs/decisions.md I60) against a FAKE site schema cw_test_<slot>_site on the
 * staging cluster: the live tables' columns and index names (consolidate_product_sale, orders_master, orders_items,
 * order_return_items, product_stock_snapshot) with synthetic orders (Completed / Failed / office / a re-ship child /
 * cancelled / cancelled but returned) and cps / snapshot rows, plus filler rows outside the window so the optimizer reads by
 * index as on live. The tool runs as a subprocess with the CW_EXPORT_DB_* connection and CW_SALES_EXPORT_ROOT in a temporary
 * directory. Never against a real site.
 */
final class SalesExportToolTest extends TestCase
{
    private const FROM = '2026-09-01';
    private const TO = '2026-09-14';

    private static Db $site;
    private static string $schema;
    private string $root = '';

    public static function setUpBeforeClass(): void
    {
        if (getenv('CW_TEST_DB') === '0') {
            self::markTestSkipped('CW_TEST_DB=0: database tests disabled');
        }
        self::$schema = MigrationFixture::schema('site');
        $server = TestDb::server();
        $server->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident(self::$schema));
        $server->pdo()->exec('CREATE DATABASE ' . Db::ident(self::$schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        self::$site = Db::connect(TestDb::config()->dbAdmin()->withDatabase(self::$schema));
        foreach (self::ddl() as $sql) {
            self::$site->pdo()->exec($sql);
        }
        self::fixtures();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$schema)) {
            TestDb::server()->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident(self::$schema));
        }
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cw_salesexp_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /** @return list<string> the live DDL subset: the columns the queries read and the same index names */
    private static function ddl(): array
    {
        return [
            'CREATE TABLE consolidate_product_sale (cps_id int NOT NULL AUTO_INCREMENT, cps_date date NOT NULL, cps_prodt_id int NOT NULL, cps_prod_id int NOT NULL, '
            . 'cps_net_sale decimal(10,2) NOT NULL, cps_sale_qty int NOT NULL, cps_order_qty int NOT NULL, cps_gross_sale decimal(10,2) NOT NULL, '
            . 'cps_discount decimal(10,2) NOT NULL, PRIMARY KEY (cps_id), UNIQUE KEY cps_date (cps_date, cps_prodt_id), KEY cps_prodt_id (cps_prodt_id), '
            . 'KEY cps_prod_id (cps_prod_id), KEY cps_date_2 (cps_date, cps_prodt_id), KEY idx_cps_prod_date (cps_prod_id, cps_date), '
            . 'KEY idx_cps_prod_date_qty (cps_prod_id, cps_date, cps_sale_qty)) ENGINE=InnoDB',
            "CREATE TABLE orders_master (ord_id int NOT NULL AUTO_INCREMENT, ord_date datetime DEFAULT NULL, ord_cust_id int DEFAULT NULL, "
            . "ord_status enum('Draft','Pending','Processing','Completed','Failed','Cancelled','Create','Open') DEFAULT 'Pending', ord_type varchar(200) DEFAULT 'Online', "
            . 'ord_parent_Id int DEFAULT NULL, PRIMARY KEY (ord_id), KEY ord_parent_Id (ord_parent_Id), KEY ord_cust_id (ord_cust_id), KEY ord_status (ord_status), '
            . 'KEY ord_type (ord_type), KEY ord_date (ord_date), KEY idx_ord_status_date (ord_status, ord_date DESC), '
            . 'KEY idx_orders_report (ord_date, ord_status, ord_type, ord_cust_id, ord_id), KEY idx_ord_type_status_date (ord_type, ord_status, ord_date)) ENGINE=InnoDB',
            'CREATE TABLE orders_items (ordi_id int NOT NULL AUTO_INCREMENT, ordi_prodt_id int DEFAULT NULL, ordi_sale_price decimal(10,2) DEFAULT NULL, '
            . "ordi_ord_id int DEFAULT NULL, ordi_iscancelled int NOT NULL DEFAULT '0', ordi_discount_amount decimal(10,2) NOT NULL DEFAULT '0.00', PRIMARY KEY (ordi_id), "
            . 'KEY ordi_prodt_id (ordi_prodt_id), KEY idx_ordi_ord_cancelled_prodt (ordi_ord_id, ordi_iscancelled, ordi_prodt_id)) ENGINE=InnoDB',
            'CREATE TABLE order_return_items (orti_id INT UNSIGNED NOT NULL AUTO_INCREMENT, orti_ort_id INT UNSIGNED NOT NULL, orti_ordi_id INT UNSIGNED NOT NULL, '
            . "orti_prodt_id INT UNSIGNED DEFAULT NULL, orti_status VARCHAR(20) NOT NULL DEFAULT 'Expected', PRIMARY KEY (orti_id), "
            . 'UNIQUE KEY uq_orti_ort_ordi (orti_ort_id, orti_ordi_id), KEY idx_orti_ordi (orti_ordi_id), KEY idx_orti_status (orti_status)) ENGINE=InnoDB',
            "CREATE TABLE product_stock_snapshot (pss_date DATE NOT NULL, pss_prodt_id INT NOT NULL, pss_stock INT NOT NULL DEFAULT 0, "
            . "pss_stock_mode ENUM('In-Stock','Out-Of-Stock','From-Warehouse') NULL, pss_allow_backorders TINYINT(1) NULL, pss_sellable TINYINT(1) NOT NULL DEFAULT 0, "
            . 'PRIMARY KEY (pss_date, pss_prodt_id), KEY idx_prodt_date (pss_prodt_id, pss_date)) ENGINE=InnoDB',
        ];
    }

    private static function fixtures(): void
    {
        $db = self::$site;
        // consolidate_product_sale: 3 sold rows in the window; a zero-quantity row and a variant 0 (left out); rows just outside.
        $cps = [['2026-09-01', 101, '5.85', 3, 2, '6.00'], ['2026-09-02', 102, '1.95', 1, 1, '1.95'], ['2026-09-10', 101, '7.80', 4, 3, '8.00'],
            ['2026-09-11', 103, '0.00', 0, 0, '0.00'], ['2026-09-12', 0, '9.75', 5, 1, '9.75'], ['2026-08-31', 101, '1.95', 1, 1, '1.95'],
            ['2026-09-15', 101, '1.95', 1, 1, '1.95']];
        for ($d = 0; $d < 181; $d++) {
            for ($v = 1001; $v <= 1020; $v++) {
                $cps[] = [gmdate('Y-m-d', gmmktime(0, 0, 0, 1, 1 + $d, 2025)), $v, '1.00', 1, 1, '1.00'];
            }
        }
        foreach (array_chunk($cps, 500) as $chunk) {
            $p = [];
            foreach ($chunk as $r) {
                array_push($p, $r[0], $r[1], 1, $r[2], $r[3], $r[4], $r[5], '0.00');
            }
            $db->exec('INSERT INTO consolidate_product_sale (cps_date, cps_prodt_id, cps_prod_id, cps_net_sale, cps_sale_qty, cps_order_qty, cps_gross_sale, cps_discount) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)')), $p);
        }
        // Orders: [date, status, type, parent, items [variant, price, discount, cancelled, returned]].
        $orders = [
            ['2026-09-01 10:00:00', 'Completed', 'Online', null, [[101, '2.00', '0.05', 0, false], [101, '2.00', '0.05', 0, false], [102, '2.00', '0.00', 1, true],
                [103, '2.00', '0.00', 1, false]]],
            ['2026-09-01 11:00:00', 'Failed', 'Online', null, [[101, '2.00', '0.00', 0, false]]],
            ['2026-09-02 09:00:00', 'Completed', 'office', null, [[101, '0.00', '0.00', 0, false], [104, '0.00', '0.00', 0, false], [105, '0.00', '0.00', 1, false]]],
            ['2026-09-02 12:00:00', 'Completed', 'office', 1, [[101, '0.00', '0.00', 0, false]]],
            ['2026-09-03 09:00:00', 'Completed', 'office', 0, [[104, '0.00', '0.00', 0, false]]],
            ['2026-08-31 23:59:59', 'Completed', 'Online', null, [[101, '2.00', '0.00', 0, false]]],
            ['2026-09-15 00:00:00', 'Completed', 'Online', null, [[101, '2.00', '0.00', 0, false]]],
            ['2026-09-09 15:00:00', 'Completed', 'Online', null, [[104, '2.00', '0.00', 0, false], [104, '2.00', '0.00', 0, false], [104, '2.00', '0.00', 0, false]]],
        ];
        $ret = 0;
        foreach ($orders as [$date, $status, $type, $parent, $items]) {
            $o = $db->insert('INSERT INTO orders_master (ord_date, ord_cust_id, ord_status, ord_type, ord_parent_Id) VALUES (?, 7, ?, ?, ?)', [$date, $status, $type, $parent]);
            foreach ($items as [$v, $price, $disc, $cancelled, $returned]) {
                $i = $db->insert('INSERT INTO orders_items (ordi_prodt_id, ordi_sale_price, ordi_ord_id, ordi_iscancelled, ordi_discount_amount) VALUES (?, ?, ?, ?, ?)',
                    [$v, $price, $o, $cancelled, $disc]);
                if ($returned) {
                    $db->exec('INSERT INTO order_return_items (orti_ort_id, orti_ordi_id, orti_prodt_id) VALUES (?, ?, ?)', [++$ret, $i, $v]);
                }
            }
        }
        // Filler orders in 2025 (one line each) and returns of some of them.
        for ($n = 0; $n < 1500; $n += 500) {
            $p = [];
            for ($k = $n; $k < $n + 500; $k++) {
                array_push($p, gmdate('Y-m-d H:i:s', gmmktime(10, 0, 0, 1, 1 + intdiv($k, 10), 2025)), $k % 3 === 0 ? 'office' : 'Online');
            }
            $db->exec("INSERT INTO orders_master (ord_date, ord_cust_id, ord_status, ord_type) VALUES " . implode(', ', array_fill(0, 500, "(?, 9, 'Completed', ?)")), $p);
        }
        $db->exec('INSERT INTO orders_items (ordi_prodt_id, ordi_sale_price, ordi_ord_id, ordi_iscancelled) SELECT 2000 + (ord_id % 50), 1.00, ord_id, 0 FROM orders_master '
            . 'WHERE ord_cust_id = 9');
        $db->exec('INSERT INTO order_return_items (orti_ort_id, orti_ordi_id, orti_prodt_id) SELECT 1000 + ordi_id, ordi_id, ordi_prodt_id FROM orders_items '
            . 'WHERE ordi_prodt_id >= 2000 AND ordi_id % 5 = 0');
        // The snapshot: 10-14 Sep for 101-105 (101 unsellable on the 12th, 103 on the 13th, 105 always) and one day after the window;
        // filler days in 2025.
        $pss = [];
        foreach (['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-13', '2026-09-14', '2026-09-20'] as $d) {
            foreach ([101, 102, 103, 104, 105] as $v) {
                $unsellable = $v === 105 || ($v === 101 && $d === '2026-09-12') || ($v === 103 && $d === '2026-09-13');
                $pss[] = [$d, $v, $unsellable ? 0 : 10 + $v % 100, $unsellable ? 'Out-Of-Stock' : 'In-Stock', 0, $unsellable ? 0 : 1];
            }
        }
        for ($d = 0; $d < 50; $d++) {
            for ($v = 3001; $v <= 3040; $v++) {
                $pss[] = [gmdate('Y-m-d', gmmktime(0, 0, 0, 1, 1 + $d, 2025)), $v, 5, 'In-Stock', 0, $v % 4 === 0 ? 0 : 1];
            }
        }
        foreach (array_chunk($pss, 500) as $chunk) {
            $p = [];
            foreach ($chunk as $r) {
                array_push($p, ...$r);
            }
            $db->exec('INSERT INTO product_stock_snapshot (pss_date, pss_prodt_id, pss_stock, pss_stock_mode, pss_allow_backorders, pss_sellable) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)')), $p);
        }
        foreach (['consolidate_product_sale', 'orders_master', 'orders_items', 'order_return_items', 'product_stock_snapshot'] as $t) {
            $db->all('ANALYZE TABLE ' . Db::ident($t));
        }
    }

    /**
     * Runs the tool; returns its exit code, stdout and stderr.
     *
     * @param array<string, string> $env
     * @return array{code: int, out: string, err: string}
     */
    private function export(array $args, array $env = []): array
    {
        $admin = TestDb::config()->dbAdmin();
        $root = dirname(__DIR__, 3);
        $environment = getenv() + [];
        $environment = array_merge($environment, ['CW_EXPORT_DB_HOST' => $admin->host, 'CW_EXPORT_DB_PORT' => (string) $admin->port, 'CW_EXPORT_DB_USER' => $admin->user,
            'CW_EXPORT_DB_PASSWORD' => $admin->password(), 'CW_EXPORT_DB_NAME' => self::$schema, 'CW_EXPORT_DB_SSL' => '1', 'CW_SALES_EXPORT_ROOT' => $this->root], $env);
        $p = proc_open([PHP_BINARY, "{$root}/tools/sales_history/export.php", ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $r = ['code' => proc_close($p), 'out' => $out, 'err' => $err];
        foreach ([$admin->password(), $admin->host] as $secret) {
            self::assertStringNotContainsString($secret, $out . $err, 'the tool never prints the password or the host');
        }
        return $r;
    }

    /** @return list<list<string>> the rows of a gzip CSV, header first */
    private static function csv(string $path): array
    {
        $out = [];
        $fh = gzopen($path, 'rb');
        self::assertNotFalse($fh);
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $out[] = array_map('strval', $r);
        }
        gzclose($fh);
        return $out;
    }

    /** @return array<string, mixed> the manifest of the one export in the site's directory */
    private function manifest(string $site): array
    {
        $m = glob("{$this->root}/{$site}/*.manifest.json") ?: [];
        self::assertCount(1, $m);
        $raw = (string) file_get_contents($m[0]);
        $admin = TestDb::config()->dbAdmin();
        self::assertStringNotContainsString($admin->password(), $raw);
        self::assertStringNotContainsString($admin->host, $raw);
        self::assertSame('0640', substr(sprintf('%o', fileperms($m[0])), -4));
        return (array) json_decode($raw, true);
    }

    public function testTheConsolidatedSalesExport(): void
    {
        $r = $this->export(['--site=vapeandgo', '--source=cps', '--from=' . self::FROM, '--to=' . self::TO]);
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('exported vapeandgo (cps, database ' . self::$schema . ') 2026-09-01 to 2026-09-14: 6 sales rows, 1 stock-day rows, 5 latest rows', $r['out']);
        $m = $this->manifest('vapeandgo');
        self::assertSame(['vapeandgo', self::$schema, 'cps', self::FROM, self::TO, 2], [$m['site'], $m['database'], $m['source'], $m['from'], $m['to'], $m['slices']]);
        self::assertStringContainsString('consolidate_product_sale', (string) $m['rule']);
        self::assertSame(['2026-09' => ['units_online' => 8, 'units_office' => 3, 'rows' => 6]], $m['months']);
        $files = array_column($m['files'], null, 'kind');
        self::assertSame(['sales', 'stockdays', 'latest'], array_keys($files));
        foreach ($files as $f) {
            $path = "{$this->root}/vapeandgo/{$f['name']}";
            self::assertSame($f['sha256'], hash_file('sha256', $path));
            self::assertSame('0640', substr(sprintf('%o', fileperms($path)), -4));
            self::assertCount($f['rows'] + 1, self::csv($path));
        }
        self::assertSame([
            ['site', 'variant_id', 'sale_date', 'units_online', 'orders_online', 'net_online', 'gross_online', 'units_office', 'orders_office'],
            ['vapeandgo', '101', '2026-09-01', '3', '2', '5.85', '6.00', '0', '0'],
            ['vapeandgo', '101', '2026-09-02', '0', '0', '0.00', '0.00', '1', '1'],
            ['vapeandgo', '102', '2026-09-02', '1', '1', '1.95', '1.95', '0', '0'],
            ['vapeandgo', '104', '2026-09-02', '0', '0', '0.00', '0.00', '1', '1'],
            ['vapeandgo', '104', '2026-09-03', '0', '0', '0.00', '0.00', '1', '1'],
            ['vapeandgo', '101', '2026-09-10', '4', '3', '7.80', '8.00', '0', '0'],
        ], self::csv("{$this->root}/vapeandgo/{$files['sales']['name']}"), 'cps online merged with the office orders: not a re-ship child (parent 1), parent 0 is none, a cancelled office line left out');
        self::assertSame([['site', 'variant_id', 'stock_date', 'stock', 'stock_mode', 'allow_backorders'], ['vapeandgo', '101', '2026-09-12', '0', 'Out-Of-Stock', '0']],
            self::csv("{$this->root}/vapeandgo/{$files['stockdays']['name']}"), 'unsellable days of variants that sold only (103 and 105 never sold)');
        self::assertSame('vapeandgo_stocklatest_2026-09-14_', substr($files['latest']['name'], 0, 33), 'the last snapshot day on or before --to');
        self::assertSame(['vapeandgo', '105', '2026-09-14', '0', 'Out-Of-Stock', '0'], self::csv("{$this->root}/vapeandgo/{$files['latest']['name']}")[5]);
        self::assertSame([['date' => '2026-09-10', 'variants' => 5, 'unsellable' => 1], ['date' => '2026-09-11', 'variants' => 5, 'unsellable' => 1],
            ['date' => '2026-09-12', 'variants' => 5, 'unsellable' => 2], ['date' => '2026-09-13', 'variants' => 5, 'unsellable' => 2],
            ['date' => '2026-09-14', 'variants' => 5, 'unsellable' => 1]], $m['snapshot_days']);
        self::assertSame(['V1', 'O2', 'S3a', 'S1', 'S2', 'S3b'], array_keys($m['explain']), 'the first slice\'s plans');
        self::assertSame('range', $m['explain']['V1'][0]['type']);
        self::assertContains($m['explain']['V1'][0]['key'], ['cps_date', 'cps_date_2']);
        self::assertSame(hash('sha256', implode("\n", (array) $m['queries'])), $m['query_sha256']);
        self::assertIsInt($m['threads_running']['before']);
        self::assertIsInt($m['threads_running']['max']);
        self::assertIsInt($m['threads_running']['after']);
        self::assertSame([], glob("{$this->root}/vapeandgo/*.part") ?: [], 'no partial file left');
    }

    /**
     * Review finding (I76): an out-of-stock day counts only for a variant that did NOT sell that day, so a one-day export
     * (the nightly runbook) must keep the unsellable days of variants sold BEFORE its window. It reads the 365 days to --to
     * (ids only, same statements, same gate) and keeps 101's day; before the fix it kept no stock-day row at all.
     */
    public function testAOneDayExportKeepsTheStockDaysOfVariantsSoldBefore(): void
    {
        $r = $this->export(['--site=vapeandgo', '--source=cps', '--from=2026-09-12', '--to=2026-09-12']);
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        $m = $this->manifest('vapeandgo');
        $files = array_column($m['files'], null, 'kind');
        self::assertSame(0, $files['sales']['rows'], 'nothing sold on the 12th (the row of variant 0 is not a sale)');
        self::assertSame([['site', 'variant_id', 'stock_date', 'stock', 'stock_mode', 'allow_backorders'], ['vapeandgo', '101', '2026-09-12', '0', 'Out-Of-Stock', '0']],
            self::csv("{$this->root}/vapeandgo/{$files['stockdays']['name']}"), '101 sold on 1, 2 and 10 Sep: its unsellable 12th is kept (103 and 105 never sold)');
        self::assertSame(['from' => '2025-09-13', 'to' => '2026-09-11', 'slices' => 52, 'variants_in_window' => 0, 'variants' => 3], $m['lookback'],
            '101, 102 and 104 (office) sold in the lookback');
        self::assertSame([['date' => '2026-09-12', 'variants' => 5, 'unsellable' => 2]], $m['snapshot_days']);
    }

    public function testTheOrderLinesExport(): void
    {
        $r = $this->export(['--site=electrofag', '--source=orders', '--from=' . self::FROM, '--to=' . self::TO]);
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        $m = $this->manifest('electrofag');
        self::assertSame(['2026-09' => ['units_online' => 6, 'units_office' => 3, 'rows' => 6]], $m['months']);
        $files = array_column($m['files'], null, 'kind');
        self::assertSame([
            ['site', 'variant_id', 'sale_date', 'units_online', 'orders_online', 'net_online', 'gross_online', 'units_office', 'orders_office'],
            ['electrofag', '101', '2026-09-01', '2', '1', '3.90', '4.00', '0', '0'],
            ['electrofag', '102', '2026-09-01', '1', '1', '2.00', '2.00', '0', '0'],
            ['electrofag', '101', '2026-09-02', '0', '0', '0.00', '0.00', '1', '1'],
            ['electrofag', '104', '2026-09-02', '0', '0', '0.00', '0.00', '1', '1'],
            ['electrofag', '104', '2026-09-03', '0', '0', '0.00', '0.00', '1', '1'],
            ['electrofag', '104', '2026-09-09', '3', '1', '6.00', '6.00', '0', '0'],
        ], self::csv("{$this->root}/electrofag/{$files['sales']['name']}"),
            'Completed Online only (not Failed); a cancelled line counts only when it was returned (102), not otherwise (103); net = price - discount; the window is [from, to + 1)');
        self::assertSame(['O1', 'O2', 'S3a', 'S1', 'S2', 'S3b'], array_keys($m['explain']));
        $plan = array_column($m['explain']['O1'], null, 'table');
        self::assertSame(['idx_orders_report', 'idx_ordi_ord_cancelled_prodt', 'idx_orti_ordi'], [$plan['om']['key'], $plan['oi']['key'], $plan['r']['key']]);
        self::assertSame('DEPENDENT SUBQUERY', $plan['r']['select_type']);
    }

    public function testTheExplainGateRefusesBeforeAnyDataQuery(): void
    {
        self::$site->pdo()->exec('ALTER TABLE orders_master DROP INDEX idx_orders_report');
        try {
            $r = $this->export(['--site=electrofag', '--source=orders', '--from=' . self::FROM, '--to=' . self::TO]);
        } finally {
            self::$site->pdo()->exec('ALTER TABLE orders_master ADD KEY idx_orders_report (ord_date, ord_status, ord_type, ord_cust_id, ord_id)');
        }
        self::assertSame(3, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('refused: O1: EXPLAIN failed (MySQL 1176', $r['err']);
        self::assertStringContainsString('nothing further was run (data queries run before: 0); no files kept', $r['err']);
        self::assertSame([], array_values(array_filter(glob("{$this->root}/electrofag/*") ?: [], 'is_file')), 'nothing written');
        // A full scan is refused too: the snapshot without its primary key's date range.
        self::$site->pdo()->exec('ALTER TABLE product_stock_snapshot DROP PRIMARY KEY, ADD PRIMARY KEY (pss_prodt_id, pss_date)');
        try {
            $r = $this->export(['--site=vapeandgo', '--source=cps', '--from=' . self::FROM, '--to=' . self::TO]);
        } finally {
            self::$site->pdo()->exec('ALTER TABLE product_stock_snapshot DROP PRIMARY KEY, ADD PRIMARY KEY (pss_date, pss_prodt_id)');
        }
        self::assertSame(3, $r['code'], $r['out'] . $r['err']);
        self::assertMatchesRegularExpression('/refused: S3a: product_stock_snapshot (is read by a full scan|would use key)/', $r['err']);
        self::assertSame([], array_values(array_filter(glob("{$this->root}/vapeandgo/*") ?: [], 'is_file')), 'the sales file of pass 1 is removed too');
    }

    public function testTheOutputMustBeInsideTheRootAndUsageErrors(): void
    {
        $outside = sys_get_temp_dir() . '/cw_salesexp_outside_' . bin2hex(random_bytes(4));
        foreach (["--out={$outside}", "--out={$this->root}/../" . basename($outside), '--out=/root'] as $out) {
            $r = $this->export(['--site=vapeandgo', '--source=cps', '--from=' . self::FROM, '--to=' . self::TO, $out]);
            self::assertSame(2, $r['code'], $out . ': ' . $r['err']);
            self::assertStringContainsString('--out must be inside CW_SALES_EXPORT_ROOT', $r['err']);
        }
        self::assertDirectoryDoesNotExist($outside);
        self::assertSame(2, $this->export(['--site=vapeandgo', '--source=nope'])['code']);
        self::assertSame(2, $this->export(['--site=vapeandgo', '--source=cps', '--from=2026-09-20', '--to=2026-09-10'])['code']);
        self::assertSame(2, $this->export(['--site=vapeandgo', '--source=cps', '--to=2099-01-01'])['code']);
        self::assertSame(2, $this->export(['--site=Bad!', '--source=cps'])['code']);
        // A wrong password: a plain failure (1) that names neither the host nor the password.
        $r = $this->export(['--site=vapeandgo', '--source=cps', '--from=' . self::FROM, '--to=' . self::TO], ['CW_EXPORT_DB_PASSWORD' => 'not-the-password']);
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('cannot connect to the database (MySQL 1045)', $r['err']);
        // A site without a snapshot table: sales only.
        self::$site->pdo()->exec('RENAME TABLE product_stock_snapshot TO pss_hidden');
        try {
            $r = $this->export(['--site=vapeandgo', '--source=cps', '--from=' . self::FROM, '--to=' . self::TO]);
        } finally {
            self::$site->pdo()->exec('RENAME TABLE pss_hidden TO product_stock_snapshot');
        }
        self::assertSame(0, $r['code'], $r['err']);
        $m = $this->manifest('vapeandgo');
        self::assertSame(['sales'], array_column($m['files'], 'kind'));
        self::assertContains('the site has no product_stock_snapshot table: no stock files', $m['warnings']);
        // An empty snapshot table (Electrofag today: the table exists, its cron does not run): MAX finds no row, sales only.
        exec('rm -rf ' . escapeshellarg("{$this->root}/vapeandgo"));
        self::$site->pdo()->exec('RENAME TABLE product_stock_snapshot TO pss_hidden');
        self::$site->pdo()->exec('CREATE TABLE product_stock_snapshot LIKE pss_hidden');
        try {
            $r = $this->export(['--site=vapeandgo', '--source=cps', '--from=' . self::FROM, '--to=' . self::TO]);
        } finally {
            self::$site->pdo()->exec('DROP TABLE product_stock_snapshot');
            self::$site->pdo()->exec('RENAME TABLE pss_hidden TO product_stock_snapshot');
        }
        self::assertSame(0, $r['code'], $r['err']);
        $m = $this->manifest('vapeandgo');
        self::assertSame(['sales'], array_column($m['files'], 'kind'));
        self::assertContains("the site's product_stock_snapshot has no day on or before " . self::TO . ': no stock files', $m['warnings']);
        self::assertSame([], $m['snapshot_days']);
        self::assertArrayHasKey('S3a', $m['explain']);
    }
}
