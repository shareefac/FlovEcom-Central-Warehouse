<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ops;

use CW\Db;
use CW\Ops\ErpOpeningFiles;
use CW\Ops\SitePendingSales;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * bin/erp_pending_sales.php (pack I, PI20) on a MADE-UP copy of the three website tables it reads (data_sync, orders_master,
 * orders_items, shaped as the Vape and Go schema), in a scratch schema of the test server. Never a live database.
 */
final class SitePendingSalesTest extends IntegrationTestCase
{
    private string $schema;
    private Db $site;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = substr(TestDb::name() . '_site', 0, 64);
        $server = TestDb::server();
        $server->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident($this->schema));
        $server->pdo()->exec('CREATE DATABASE ' . Db::ident($this->schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $admin = Db::connect(TestDb::config()->dbAdmin()->withDatabase($this->schema));
        foreach ([
            "CREATE TABLE data_sync (ds_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, ds_type VARCHAR(100) NOT NULL, ds_type_id BIGINT NOT NULL,
               ds_method_type ENUM('INSERT','UPDATE') NOT NULL, ds_json_payload JSON NOT NULL, ds_status ENUM('PENDING','SUCCESS','FAILED') DEFAULT 'PENDING',
               ds_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, ds_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               retry_count INT DEFAULT 0,
               source_ord_id BIGINT UNSIGNED GENERATED ALWAYS AS (CAST(JSON_UNQUOTE(JSON_EXTRACT(ds_json_payload, '$.custom_source_orders[0].ord_id')) AS UNSIGNED)) STORED,
               KEY idx_source_ord_id (source_ord_id))",
            "CREATE TABLE orders_master (ord_id BIGINT NOT NULL PRIMARY KEY, ord_is_syncable BIT(1) DEFAULT b'0', ord_generate_prepare_to_sync INT DEFAULT 0,
               ord_genarated_date DATETIME NULL, ord_status VARCHAR(20) NULL)",
            'CREATE TABLE orders_items (ordi_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, ordi_ord_id BIGINT NOT NULL, ordi_prodt_id BIGINT NOT NULL)',
        ] as $ddl) {
            $admin->exec($ddl);
        }
        $row = static function (string $type, string $status, ?int $order, array $variants, string $updated = 'NOW()', int $retries = 0) use ($admin): void {
            $payload = ['doctype' => 'Sales Invoice', 'items' => array_map(static fn (string $v): array => ['item_code' => $v, 'qty' => 1], $variants)]
                + ($order === null ? [] : ['custom_source_orders' => [['ord_id' => $order]]]);
            $admin->exec("INSERT INTO data_sync (ds_type, ds_type_id, ds_method_type, ds_json_payload, ds_status, ds_updated_at, retry_count) "
                . "VALUES (?, 1, 'INSERT', ?, ?, {$updated}, ?)", [$type, json_encode($payload), $status, $retries]);
        };
        $row('sales_invoice', 'PENDING', 1, ['101', '101', '102']);
        $row('sales_invoice', 'FAILED', 2, ['103'], 'NOW()', 5);                         // stuck: the processor no longer retries it
        $row('sales_invoice', 'FAILED', 3, ['109']);
        $row('sales_invoice', 'SUCCESS', 3, ['109'], 'NOW() - INTERVAL 3 HOUR');      // the newer row of order 3 went through
        $row('sales_invoice', 'SUCCESS', 4, ['105'], 'NOW() - INTERVAL 2 MINUTE');    // in ERPNext's own queue still
        $row('sales_invoice', 'SUCCESS', 5, ['106'], 'NOW() - INTERVAL 1 HOUR');
        $row('product', 'PENDING', null, ['101']);                                      // not a sale
        $row('cancel_sales_order_with_refund', 'PENDING', 7, ['101']);                  // a return on its way: counted, not netted
        $row('sales_invoice', 'FAILED', 6, ['107']);
        $row('sales_invoice', 'PENDING', 6, ['107', '107']);                            // order 6 re-queued: its newest row counts
        $row('sales_invoice', 'PENDING', null, ['108']);
        $admin->exec("INSERT INTO orders_master (ord_id, ord_is_syncable, ord_generate_prepare_to_sync, ord_genarated_date) VALUES
            (20, b'1', 0, NOW()), (21, b'1', 1, NOW()), (22, b'0', 0, NOW()), (23, b'1', 0, NULL)");
        $admin->exec('INSERT INTO orders_items (ordi_ord_id, ordi_prodt_id) VALUES (20, 101), (20, 104), (21, 101), (22, 101), (23, 101)');
        $this->site = Db::connect(TestDb::config()->dbAdmin()->withDatabase($this->schema));
        $this->dir = sys_get_temp_dir() . '/cw_site_pending_' . getmypid() . '_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        TestDb::server()->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident($this->schema));
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    /** @return array<string, string> table => CHECKSUM TABLE */
    private function checksums(): array
    {
        $out = [];
        foreach (['data_sync', 'orders_master', 'orders_items'] as $t) {
            $out[$t] = (string) $this->site->one("CHECKSUM TABLE {$t}")['Checksum'];
        }
        return $out;
    }

    public function testItCountsTheSalesNotYetInErpnextReadingOnly(): void
    {
        $before = $this->checksums();
        $r = (new SitePendingSales($this->site, 5000, 10))->read();
        // 101: order 1 (2) + order 20 not queued yet (1); 103: stuck FAILED; 105: SUCCESS 2 minutes ago; 107: order 6's newest row only
        self::assertSame([101 => 3, 102 => 1, 103 => 1, 104 => 1, 105 => 1, 107 => 2, 108 => 1], $r['lines']);
        $rep = $r['report'];
        self::assertSame(['FAILED_stuck' => 1, 'PENDING' => 3, 'SUCCESS' => 1], $rep['queue_rows']);
        self::assertSame(['orders' => 1, 'units' => 2], $rep['not_queued']);
        self::assertSame(['cancel_sales_order_with_refund' => ['PENDING' => 1]], $rep['returns_on_their_way_not_netted']);
        self::assertSame([true, 5000, $this->schema], [$rep['read_only'], $rep['max_ms'], $rep['database']]);
        self::assertSame($before, $this->checksums());
        // The session it used is read only: a write on it is refused by the server.
        self::assertSame(1, (int) $this->site->value('SELECT @@session.transaction_read_only'));
        self::assertSame(1792, self::mysqlError(fn () => $this->site->exec("INSERT INTO orders_items (ordi_ord_id, ordi_prodt_id) VALUES (1, 1)")));
        // Every statement is a SELECT with its time cap.
        foreach ((new SitePendingSales($this->site, 1234))->statements() as $sql) {
            self::assertMatchesRegularExpression('/^SELECT \/\*\+ MAX_EXECUTION_TIME\(1234\) \*\/ /', $sql);
            self::assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|GRANT|LOCK)\b/i', $sql);
        }
        // With no grace, a SUCCESS row is taken as in ERPNext.
        $site2 = Db::connect(TestDb::config()->dbAdmin()->withDatabase($this->schema));
        self::assertArrayNotHasKey(105, (new SitePendingSales($site2, 5000, 0))->read()['lines']);
    }

    public function testTheScriptWritesTheFileTheOpeningImportReads(): void
    {
        $s = TestDb::config()->dbAdmin();
        $env = $this->dir . '/site.env';
        file_put_contents($env, "# made for the test\nDB_HOST={$s->host}\nDB_PORT={$s->port}\nDB_USER={$s->user}\nDB_PASSWORD='{$s->password()}'\n"
            . "DB_NAME={$this->schema}\nDB_SSL_MODE={$s->sslMode}\n" . ($s->sslCa === null ? '' : "DB_SSL_CA={$s->sslCa}\n"));
        chmod($env, 0644);
        $open = $this->script(['--site-env=' . $env]);
        self::assertSame(2, $open['code'], 'an env file others may read is refused');
        self::assertStringContainsString('chmod 600', $open['err']);
        chmod($env, 0600);
        $out = $this->dir . '/pending.csv';
        $r = $this->script(['--site-env=' . $env, '--out=' . $out, '--max-ms=5000']);
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringNotContainsString($s->password(), $r['err'] . $r['out'], 'never printed');
        self::assertStringContainsString('NOT NETTED', $r['err']);
        self::assertSame("variant_id,qty\n101,3\n102,1\n103,1\n104,1\n105,1\n107,2\n108,1\n", (string) file_get_contents($out));
        self::assertSame(0600, fileperms($out) & 0777);
        // ... and the opening import reads it as its pending-sales file.
        $p = ErpOpeningFiles::pendingSales($out);
        self::assertSame(['variant', 7, 10], [$p['by'], count($p['rows']), array_sum(array_column($p['rows'], 'qty'))]);
        self::assertSame(2, $this->script(['--site-env=' . $env, '--max-ms=5'])['code'], 'a cap below 100 ms is a usage error');
    }

    /** @param list<string> $args @return array{code: int, out: string, err: string} */
    private function script(array $args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/erp_pending_sales.php", ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
