<?php

declare(strict_types=1);

/**
 * Pack E (docs/decisions.md PE13): the report queries against a FILLED schema, with EXPLAIN ANALYZE. A dev tool: it runs on the staging
 * box in a slot copy, as the admin login, against the slot's own test schema ONLY (cw_test_<CW_SLOT>; it refuses anything else), which
 * it drops, re-creates and migrates first (as a PHPUnit run does), then fills with a store-sized data set, runs the report job, reads
 * every report page's queries as the screens do, and prints each SELECT's EXPLAIN ANALYZE and time.
 *
 *   CW_RUN_SLOT=packe /root/cw_work/runs/packe_run.sh php tests/perf/report_explain.php [--skus=20000] [--ledger=1] [--days=200]
 *
 * --ledger multiplies the ledger and the orders (1 = about 335,000 ledger rows and 100,000 paid orders). Nothing outside the test schema
 * is read or written.
 */

use CW\Auth\RoleMap;
use CW\Db;
use CW\Reports\ReportRefresh;
use CW\Reports\ReportViews;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class ExplainStatement extends \PDOStatement
{
    /** @var list<array{sql: string, params: array<int|string, array{0: mixed, 1: int}>, ms: float, rows: int}> */
    public static array $log = [];
    /** @var array<int|string, array{0: mixed, 1: int}> */
    private array $bound = [];

    protected function __construct()
    {
    }

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->bound[$param] = [$value, $type];
        return parent::bindValue($param, $value, $type);
    }

    public function execute(?array $params = null): bool
    {
        $t = hrtime(true);
        try {
            return parent::execute($params);
        } finally {
            self::$log[] = ['sql' => $this->queryString, 'params' => $this->bound, 'ms' => round((hrtime(true) - $t) / 1e6, 1), 'rows' => $this->rowCount()];
        }
    }
}

$opts = getopt('', ['skus:', 'ledger:', 'days:']) ?: [];
$skus = max(1000, (int) ($opts['skus'] ?? 20000));
$mult = max(1, (int) ($opts['ledger'] ?? 1));
$days = max(30, (int) ($opts['days'] ?? 200));
$name = TestDb::name();
$slot = (string) getenv('CW_SLOT');
if ($name !== 'cw_test_' . $slot || preg_match('/^cw_test_[a-z0-9_]+$/D', $name) !== 1) {
    fwrite(STDERR, "refusing: the schema must be this slot's own test schema (cw_test_<CW_SLOT>), not {$name}\n");
    exit(2);
}
$t0 = hrtime(true);
$say = static function (string $m) use ($t0): void {
    printf("[%6.1fs] %s\n", (hrtime(true) - $t0) / 1e9, $m);
};
TestDb::reset();
$db = TestDb::connect();
$db->exec('SET SESSION MAX_EXECUTION_TIME = 0');
$say("schema {$name} migrated; seeding {$skus} items, ledger x{$mult}, {$days} days");

$digits = 'SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9';
$db->exec('CREATE TABLE seed_n (n INT NOT NULL PRIMARY KEY)');
$db->exec("INSERT INTO seed_n SELECT a.d + b.d * 10 + c.d * 100 + d.d * 1000 + e.d * 10000 + f.d * 100000 FROM ({$digits}) a, ({$digits}) b, ({$digits}) c, ({$digits}) d, ({$digits}) e, ({$digits}) f");
$n = static fn (int $max): string => "SELECT n FROM seed_n WHERE n < {$max}";

$db->exec("INSERT INTO channel (id, code, name, mode) VALUES (1, 'vapeandgo', 'Vape and Go', 'live'), (2, 'electrofag', 'Electrofag', 'live'), (3, 'vapebig', 'Vape Big', 'live')");
$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (1, 1, 1), (2, 1, 1), (3, 1, 1)');
$db->exec("INSERT INTO warehouse (id, code, name, is_sellable, stock_owner, owner_entity) VALUES (9, 'ROOM2', 'Room of VPG 2', 0, 'other', 'VPG 2 Ltd')");
$db->exec("INSERT INTO sku (id, code, name, brand) SELECT n + 1, CONCAT('CW-', LPAD(n + 1, 6, '0')), CONCAT('Item ', n + 1, ' 10ml'), CONCAT('Brand ', n % 300) FROM ({$n($skus)}) x");
$sold = (int) ($skus * 0.75);
$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, status) SELECT 1, CONCAT('v', n), IF(n < {$sold}, n + 1, NULL), IF(n < {$sold}, 'mapped', 'unmapped') FROM ({$n($skus)}) x");
$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, status) SELECT 2, CONCAT('e', n), n + 1, 'mapped' FROM ({$n(intdiv($skus, 3))}) x");
$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, status) SELECT 3, CONCAT('b', n), n + 1, 'mapped' FROM ({$n(intdiv($skus, 10))}) x");
$db->exec("INSERT INTO stock_balance (warehouse_id, sku_id, on_hand, allocated, held) SELECT 1, n + 1, (n * 7) % 50 - 3, n % 3, 0 FROM ({$n($sold)}) x");
$db->exec("INSERT INTO stock_balance (warehouse_id, sku_id, on_hand) SELECT 2, n + 1, n % 5 FROM ({$n(intdiv($skus, 10))}) x");
$db->exec("INSERT INTO stock_balance (warehouse_id, sku_id, on_hand) SELECT 9, n + 1, 10 FROM ({$n(intdiv($skus, 20))}) x");
$say('items, listings and balances');

for ($m = 0; $m < $mult; $m++) {
    $off = $m * 1_000_000;
    // Deliveries with a cost, paid orders' units, sends (both figures), cancels and returns, spread over the days.
    $db->exec("INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, unit_cost, cost_currency, cost_source, actor, created_at) "
        . "SELECT 1, n % {$sold} + 1, 'on_hand', 10, 10, 'goods_in', 1.25 + n % 9, 'GBP', 'manual', 'system:seed', UTC_TIMESTAMP(6) - INTERVAL (n % {$days}) DAY - INTERVAL (n % 80000) SECOND FROM ({$n(100000)}) x");
    $db->exec("INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, channel_id, order_ref, unit_id, actor, created_at) "
        . "SELECT 1, n % {$sold} + 1, 'allocated', 1, 1, 'commit', n % 3 + 1, CONCAT('o', {$off} + n DIV 2), CONCAT('u', {$off} + n), 'channel:seed', "
        . "UTC_TIMESTAMP(6) - INTERVAL (n % {$days}) DAY - INTERVAL (n % 80000) SECOND FROM ({$n(100000)}) x");
    foreach (['allocated', 'on_hand'] as $bucket) {
        $db->exec("INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, channel_id, order_ref, unit_id, actor, created_at) "
            . "SELECT 1, n % {$sold} + 1, '{$bucket}', -1, 0, 'ship', n % 3 + 1, CONCAT('o', {$off} + n DIV 2), CONCAT('u', {$off} + n), 'channel:seed', "
            . "UTC_TIMESTAMP(6) - INTERVAL (n % {$days}) DAY - INTERVAL (n % 70000) SECOND FROM ({$n(60000)}) x");
    }
    $db->exec("INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, channel_id, order_ref, unit_id, actor, created_at) "
        . "SELECT 1, n % {$sold} + 1, 'allocated', -1, 0, 'cancel', n % 3 + 1, CONCAT('o', {$off} + n), CONCAT('c', {$off} + n), 'channel:seed', "
        . "UTC_TIMESTAMP(6) - INTERVAL (n % {$days}) DAY FROM ({$n(10000)}) x");
    $db->exec("INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, channel_id, order_ref, unit_id, actor, created_at) "
        . "SELECT 1, n % {$sold} + 1, 'on_hand', 1, 1, 'return', n % 3 + 1, CONCAT('o', {$off} + n), CONCAT('r', {$off} + n), 'channel:seed', "
        . "UTC_TIMESTAMP(6) - INTERVAL (n % {$days}) DAY FROM ({$n(5000)}) x");
    $db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) SELECT n % 3 + 1, CONCAT('o', {$off} + n), 'committed', "
        . "UTC_TIMESTAMP(6) - INTERVAL (n % {$days}) DAY - INTERVAL (n % 80000) SECOND, UTC_TIMESTAMP(6) - INTERVAL (n % {$days}) DAY - INTERVAL (n % 80000) SECOND FROM ({$n(100000)}) x");
    $say("ledger and orders, pass " . ($m + 1));
}
$db->exec("INSERT INTO reservation_unit (channel_id, unit_id, reservation_id, listing_id, sku_id, units_per_item, warehouse_id, state, dispatched_at) "
    . "SELECT r.channel_id, CONCAT(r.order_ref, '-', k.n), r.id, r.id % {$sold} + 1, (r.id + k.n) % {$sold} + 1, 1 + (r.id % 7 = 0) * 2, 1, "
    . "ELT(1 + r.id % 10, 'shipped', 'shipped', 'shipped', 'shipped', 'shipped', 'shipped', 'allocated', 'allocated', 'cancelled', 'returned'), "
    . "IF(r.id % 10 < 6 OR r.id % 10 = 9, r.committed_at, NULL) FROM reservation r JOIN (SELECT 0 n UNION ALL SELECT 1) k");
$db->exec("INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id) SELECT sku_id, ROW_NUMBER() OVER (PARTITION BY sku_id ORDER BY id), id FROM stock_ledger WHERE bucket = 'on_hand'");
$db->exec('INSERT INTO stock_value_clock (sku_id, last_seq) SELECT s.id, COALESCE(MAX(v.seq), 0) FROM sku s LEFT JOIN stock_value_seq v ON v.sku_id = s.id GROUP BY s.id');
$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) SELECT n * 7 + 1, 12, 'system:seed' FROM ({$n(intdiv($sold, 7))}) x");
$db->exec("INSERT INTO item_card (sku_id, discontinued, updated_actor) SELECT n * 11 + 1, 1, 'system:seed' FROM ({$n(intdiv($sold, 11))}) x");
$batch = $db->insert("INSERT INTO sales_import_batch (channel_id, source, date_from, date_to, sales_file, sales_sha256, manifest, exported_at, status, actor) "
    . "VALUES (1, 'cps', '2026-01-01', '2026-09-30', 'seed.csv', REPEAT('a', 64), JSON_OBJECT(), UTC_TIMESTAMP(6), 'loaded', 'system:seed')");
$db->exec("INSERT INTO sales_history_day (channel_id, external_variant_id, sale_date, units_online, orders_online, batch_id) "
    . "SELECT 1, CONCAT('v', n % {$skus}), DATE('2026-09-30') - INTERVAL (n DIV {$skus}) * 3 DAY, 1, 1, {$batch} FROM ({$n(100000)}) x");
$db->exec('DROP TABLE seed_n');
foreach (['sku', 'channel_listing', 'stock_balance', 'stock_ledger', 'stock_value_seq', 'stock_value_clock', 'reservation', 'reservation_unit', 'item_reorder', 'item_card',
    'sales_history_day', 'role_permission'] as $t) {
    $db->all('ANALYZE TABLE ' . Db::ident($t));
}
$counts = [];
foreach (['sku', 'channel_listing', 'stock_balance', 'stock_ledger', 'stock_value_seq', 'reservation', 'reservation_unit', 'sales_history_day'] as $t) {
    $counts[$t] = (int) $db->value('SELECT COUNT(*) FROM ' . Db::ident($t));
}
$say('seeded: ' . json_encode($counts));

// The job: a first run from nothing (everything is read once, in bounded runs), then a run with nothing new (what every 15 minutes costs).
$job = new ReportRefresh($db);
$runs = 0;
do {
    $t = hrtime(true);
    $r = $job->run(true, 50_000, 31);
    $runs++;
    $say(sprintf('job run %d: %.1f s %s', $runs, (hrtime(true) - $t) / 1e9, json_encode($r)));
} while (($r['seq_rows'] > 0 || $r['days'] > 1) && $runs < 40);

// Every query of the pages and of a quiet job run, captured with its parameters, then EXPLAIN ANALYZE.
$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [ExplainStatement::class, []]);
$today = ReportRefresh::ukDay(\CW\Clock::db(\CW\Clock::now()));
$views = new ReportViews($db);
$pages = [
    'role map load' => static fn () => RoleMap::load($db),
    'stock by store: figures' => static fn () => $views->storeFigures($views->stores()),
    'stock by store: page 1' => static fn () => $views->storeItems(1, 1, '', 'all', 1),
    'stock by store: out, page 3' => static fn () => $views->storeItems(1, 1, '', 'out', 3),
    'stock by store: search' => static fn () => $views->storeItems(1, 1, 'item 12', 'all', 1),
    'sales by store: 30 days' => static fn () => $views->sales((new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d'), $today, null),
    'sales by store: a year, one store' => static fn () => $views->sales((new DateTimeImmutable($today))->modify('-365 days')->format('Y-m-d'), $today, 2),
    'low stock: low' => static fn () => $views->lowStock('low', '', 5, 1),
    'low stock: out, page 2' => static fn () => $views->lowStock('out', '', 5, 2),
    'stock value: figures' => static fn () => $views->valueFigures(),
    'stock value: by product' => static fn () => $views->valueByProduct('', false, 1),
    'stock value: no cost' => static fn () => $views->valueByProduct('', true, 1),
    'stock value: by brand' => static fn () => $views->valueByBrand(),
    'stock value: by warehouse' => static fn () => $views->valueByWarehouse(),
    'slow stock: slow' => static fn () => $views->slow('slow', '', $today, 60, 180, 1),
    'slow stock: dead' => static fn () => $views->slow('dead', '', $today, 60, 180, 1),
    'job: a quiet run' => static fn () => $job->run(true),
];
$admin = TestDb::connect();
$admin->exec('SET SESSION MAX_EXECUTION_TIME = 60000');
foreach ($pages as $label => $fn) {
    ExplainStatement::$log = [];
    $t = hrtime(true);
    $fn();
    $ms = (hrtime(true) - $t) / 1e6;
    echo "\n######## {$label}: " . round($ms, 1) . ' ms, ' . count(ExplainStatement::$log) . " statements\n";
    foreach (ExplainStatement::$log as $q) {
        echo "--- {$q['ms']} ms, {$q['rows']} rows: " . preg_replace('/\s+/', ' ', mb_substr($q['sql'], 0, 600)) . "\n";
        if (preg_match('/^\s*(SELECT|WITH)\b/i', $q['sql']) !== 1 || $q['ms'] < 0) {
            continue;
        }
        try {
            $st = $admin->pdo()->prepare('EXPLAIN ANALYZE ' . $q['sql']);
            foreach ($q['params'] as $k => [$v, $type]) {
                $st->bindValue($k, $v, $type);
            }
            $st->execute();
            foreach ($st->fetchAll(\PDO::FETCH_NUM) as $row) {
                echo $row[0], "\n";
            }
        } catch (\Throwable $e) {
            echo 'explain failed: ', $e->getMessage(), "\n";
        }
    }
}
$say('done (the schema stays filled until the next PHPUnit run of this slot re-creates it)');
