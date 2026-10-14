<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0037 (pack G, the reorder engine; docs/decisions.md PG1-PG16), applied to a scratch schema that already holds an item's reorder settings:
 * the six new tables with their rules, the low-stock column on item_reorder (its rows kept, the figure empty: each website keeps its
 * own), the nine settings with their baselines; nothing removed, and the column added by a table copy (the CHECK needs it: a small table).
 */
final class Migration0037Test extends IntegrationTestCase
{
    private const FILE = '0037_reorder_engine.sql';
    private const SETTINGS = ['reorder.own_sales_stores' => ['string', '""'], 'reorder.refresh_minutes' => ['int', '15'], 'reorder.refresh_chunk_items' => ['int', '500'],
        'reorder.refresh_inline_max_items' => ['int', '2000'], 'reorder.demand_hour' => ['int', '4'], 'reorder.fold_refold_days' => ['int', '3'],
        'reorder.fold_days_per_run' => ['int', '31'], 'reorder.low_stock_bulk_max' => ['int', '200'], 'reorder.draft_skip_no_price' => ['bool', 'true']];
    private const BAD_CHECK = 3819;
    /** @var array<string, string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $suffix => $dir) {
            MigrationFixture::drop($suffix, $dir);
        }
        $this->dirs = [];
    }

    private static function before(): string
    {
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files);
        $i = array_search(self::FILE, $files, true);
        self::assertIsInt($i);
        return $files[$i - 1];
    }

    public function testItAddsTheEngineTablesTheLowStockColumnAndTheSettings(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE));
        $alters = array_values(array_filter($statements, static fn (string $s): bool => preg_match('/^ALTER\s+TABLE\b/i', $s) === 1));
        self::assertCount(1, $alters);
        self::assertMatchesRegularExpression('/^ALTER\s+TABLE\s+item_reorder\s+ADD\s+COLUMN\s+low_stock_level\b.*ALGORITHM\s*=\s*COPY\s*,\s*LOCK\s*=\s*SHARED\s*$/is', $alters[0]);
        foreach ($statements as $s) {
            self::assertDoesNotMatchRegularExpression('/\bINSTANT\b/i', $s);
            self::assertDoesNotMatchRegularExpression('/^\s*(DROP|DELETE|TRUNCATE)\b/i', $s, 'nothing is removed');
        }

        /** @var Db $db */
        [$db, $dir] = MigrationFixture::upTo('m37', self::before());
        $this->dirs['m37'] = $dir;
        $sku = $db->insert("INSERT INTO sku (name) VALUES ('Before 0037')");
        $db->exec("INSERT INTO item_reorder (sku_id, safety_days, min_stock, updated_actor) VALUES (?, 4, 12, 'system:test')", [$sku]);
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $dir, self::FILE));
        self::assertSame([4, 12, null, 1], array_values(array_map(static fn (mixed $v): mixed => $v === null ? null : (int) $v,
            (array) $db->one('SELECT safety_days, min_stock, low_stock_level, version FROM item_reorder WHERE sku_id = ?', [$sku]))), 'kept; no figure: each website keeps its own');
        self::assertSame(self::BAD_CHECK, self::mysqlError(fn () => $db->exec('UPDATE item_reorder SET low_stock_level = 100001 WHERE sku_id = ?', [$sku])));
        $db->exec('UPDATE item_reorder SET low_stock_level = 100000 WHERE sku_id = ?', [$sku]);

        $tables = array_map('strval', $db->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'reorder\\_%' "
            . 'ORDER BY TABLE_NAME'));
        foreach (['reorder_fold_day', 'reorder_job', 'reorder_line', 'reorder_line_detail', 'reorder_sales_day', 'reorder_supplier'] as $t) {
            self::assertContains($t, $tables);
            self::assertContains($t, Grants::NO_DELETE, "{$t}: never deleted by the app login");
        }
        // The list's order is an index (the page reads its ids from it).
        $cols = array_map('strval', $db->column("SELECT CONCAT(COLUMN_NAME, IF(COLLATION = 'D', ' DESC', '')) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'reorder_line' AND INDEX_NAME = 'ix_reorder_line_need' ORDER BY SEQ_IN_INDEX"));
        self::assertSame(['listed', 'need', 'urgent DESC', 'no_demand', 'cover_now_e1', 'value_e4 DESC', 'sku_id'], $cols);
        self::assertSame(self::BAD_CHECK, self::mysqlError(fn () => $db->exec("INSERT INTO reorder_job (job) VALUES ('other')")));
        $sup = $db->insert("INSERT INTO supplier (code, name, created_actor, updated_actor) VALUES ('SUP-T', 'Test supplier', 'system:test', 'system:test')");
        self::assertSame(self::BAD_CHECK, self::mysqlError(fn () => $db->exec("INSERT INTO reorder_supplier (supplier_id, safety_days, updated_actor) VALUES (?, 91, 'system:test')",
            [$sup])));

        foreach (self::SETTINGS as $key => [$type, $json]) {
            self::assertSame([$type, $json, 1], array_values((array) $db->one('SELECT value_type, CAST(value_json AS CHAR) AS v, provisional FROM app_setting WHERE setting_key = ?',
                [$key])), $key);
            self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = ? AND version = 1 AND action = 'baseline' "
                . "AND actor = 'system:migrate'", [$key]), "{$key}: its version 1");
        }
    }
}
