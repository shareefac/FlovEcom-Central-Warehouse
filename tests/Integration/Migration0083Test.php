<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Db;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0083 (CW-1153, a shop's own items; docs/decisions.md VB1-VB3), applied to a scratch schema at 0082: one new empty table, six columns
 * added INSTANTLY with defaults (every row of sale_line, sale_order, profit_day, profit_item_day and supplier_invoice keeps its values: the
 * whole-shop figures never change), one setting with its baseline. No row is changed or deleted; every statement again changes nothing.
 */
final class Migration0083Test extends IntegrationTestCase
{
    private const FILE = '0083_own_items.sql';
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m83', $this->dir);
        }
    }

    public function testItOnlyAddsAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\b/i', $sql, 'nothing is deleted, changed or dropped');
        self::assertSame(5, substr_count($sql, "ALGORITHM = INSTANT'"), 'every ALTER is instant (a copy fails instead of running)');
        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m83', $files[$at - 1]);
        $ch = $db->insert("INSERT INTO channel (code, name, mode) VALUES ('vapebig', 'Vape Big', 'live')");
        $db->exec("INSERT INTO sale_order (channel_id, order_ref, vat_bp, snapshot_version, units, sale_ex, cost, first_at, updated_at) VALUES (?, 'O1', 2000, 1, 3, 1497, 1125, NOW(6), NOW(6))", [$ch]);
        $db->exec("INSERT INTO profit_day (channel_id, day, units, sale_ex, cost) VALUES (?, '2026-11-20', 3, 1497, 1125)", [$ch]);
        $before = $db->one('SELECT units, sale_ex, cost FROM profit_day WHERE channel_id = ?', [$ch]);
        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        self::assertSame($before, $db->one('SELECT units, sale_ex, cost FROM profit_day WHERE channel_id = ?', [$ch]));
        self::assertSame(['0', '0', '0', '0', '0'], array_values(array_map('strval', (array) $db->one(
            'SELECT own_units, own_sale_ex, own_refund_ex, own_cost, own_no_cost FROM sale_order WHERE channel_id = ?', [$ch]))));
        foreach (['sale_line' => 'item_owner', 'sale_order' => 'own_refund_ex', 'profit_day' => 'own_no_cost', 'profit_item_day' => 'own_cost',
            'supplier_invoice' => 'item_owner'] as $t => $c) {
            self::assertSame(1, (int) $db->value('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$t, $c]), "{$t}.{$c}");
        }
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM item_owner'));
        self::assertSame('vapebig', (new Settings($db))->get('stock.own_items_shop'));
        self::assertSame([], ConfigInvariants::check($db), 'the new setting has its history');
        // a second open period of one item is refused by the database itself
        $sku = $db->insert("INSERT INTO sku (name) VALUES ('Hayati 4000 Grape')");
        $db->exec("INSERT INTO item_owner (sku_id, channel_id, from_at, add_reason, added_actor, added_at) VALUES (?, ?, '2026-11-16 09:00:00', 'kit', 'system:kit', NOW(6))", [$sku, $ch]);
        self::assertSame(1062, self::mysqlError(fn () => $db->exec("INSERT INTO item_owner (sku_id, channel_id, from_at, add_reason, added_actor, added_at) "
            . "VALUES (?, ?, '2026-11-17 09:00:00', 'kit', 'system:kit', NOW(6))", [$sku, $ch])));
        $cols = $db->column("SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY 1");
        foreach (SqlSplitter::split($sql) as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame($cols, $db->column("SELECT CONCAT(table_name, '.', column_name) FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY 1"));
        self::assertSame(1, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key = 'stock.own_items_shop'"));
    }
}
