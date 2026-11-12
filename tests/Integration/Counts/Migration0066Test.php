<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Counts;

use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * 0066 (Doubtful stock; docs/decisions.md DS1-) on the migrated test schema: one new table and nothing else; at most one OPEN row per store and
 * item (a second is refused by the database, an answered one leaves room for a new open one); an answered row says who and when, a cleared one
 * what was counted and its record; the app login reads, adds and changes rows and never removes one (Grants::NO_DELETE, like the stores' queues).
 */
final class Migration0066Test extends IntegrationTestCase
{
    public function testTheFileAddsOneTableAndNothingElse(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 3) . '/migrations/0066_stock_doubt.sql'));
        self::assertCount(1, $statements);
        self::assertMatchesRegularExpression('/^CREATE\s+TABLE\s+stock_doubt\s*\(/i', $statements[0]);
        self::assertSame(['open', 'cleared', 'dismissed'], self::enum('status'));
        self::assertSame(['differs', 'below_zero', 'other'], self::enum('reason'));
        self::assertContains('stock_doubt', Grants::NO_DELETE);
        self::assertSame(['Select', 'Insert', 'Update'], Grants::desired('stock_doubt'));
    }

    /** @return list<string> */
    private static function enum(string $column): array
    {
        $type = (string) self::$db->value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_doubt' AND COLUMN_NAME = ?",
            [$column]);
        preg_match_all("/'([^']*)'/", $type, $m);
        return $m[1];
    }

    public function testOneOpenRowPerStoreAndItemAndAnAnsweredRowSaysWhoWhenAndWhat(): void
    {
        $store = self::makeChannel('vapeandgo');
        $other = self::makeChannel('electrofag');
        $sku = self::makeSku('Doubt pod');
        $person = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m66', 'M66', 'm66@test.invalid', 'x')");
        $add = static fn (int $channel, string $status = 'open', array $more = []): int => self::$db->insert('INSERT INTO stock_doubt (channel_id, sku_id, reason, cw_qty, batch, status'
            . implode('', array_map(static fn (string $c): string => ", {$c}", array_keys($more))) . ") VALUES (?, ?, 'differs', 3, 'list', ?"
            . str_repeat(', ?', count($more)) . ')', [$channel, $sku, $status, ...array_values($more)]);
        $add($store);
        self::assertSame(1062, self::mysqlError(fn () => $add($store)), 'a second open row of a store and item');
        $add($other);
        $add($store, 'dismissed', ['resolved_by' => $person, 'resolved_at' => '2026-11-12 06:00:00', 'resolved_note' => 'not needed']);
        $add($store, 'dismissed', ['resolved_by' => $person, 'resolved_at' => '2026-11-12 06:01:00', 'resolved_note' => 'not needed']);
        self::assertSame(4, (int) self::$db->value('SELECT COUNT(*) FROM stock_doubt'), 'answered rows leave room');
        // The rules of a row, on an item with no open row (so only a CHECK can refuse).
        $fresh = self::makeSku('Fresh pod');
        $row = static fn (string $status, array $more = []): int => self::$db->insert('INSERT INTO stock_doubt (channel_id, sku_id, reason, cw_qty, status'
            . implode('', array_map(static fn (string $c): string => ", {$c}", array_keys($more))) . ") VALUES (?, ?, 'differs', 3, ?"
            . str_repeat(', ?', count($more)) . ')', [$store, $fresh, $status, ...array_values($more)]);
        foreach ([
            fn () => $row('dismissed', ['batch' => 'l', 'resolved_at' => '2026-11-12 06:00:00']),
            fn () => $row('dismissed', ['batch' => 'l', 'resolved_by' => $person]),
            fn () => $row('open', ['batch' => 'l', 'resolved_by' => $person, 'resolved_at' => '2026-11-12 06:00:00']),
            fn () => $row('cleared', ['batch' => 'l', 'resolved_by' => $person, 'resolved_at' => '2026-11-12 06:00:00', 'counted_qty' => 4]),
            fn () => $row('dismissed', ['batch' => 'l', 'resolved_by' => $person, 'resolved_at' => '2026-11-12 06:00:00', 'counted_qty' => 4]),
            fn () => $row('dismissed', ['batch' => 'l', 'resolved_by' => $person, 'resolved_at' => '2026-11-12 06:00:00', 'booked_delta' => 4]),
            fn () => $row('open', ['batch' => 'l', 'sold_30d' => -1]),
            fn () => $row('open', ['batch' => '']),
        ] as $i => $bad) {
            self::assertSame(3819, self::mysqlError($bad), "case {$i}: refused by a CHECK");
        }
        // A cleared row says what it booked (Codex round 1, HIGH 1: never cleared with nothing booked).
        self::$db->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            self::assertSame(3819, self::mysqlError(fn () => $row('cleared', ['batch' => 'l', 'resolved_by' => $person, 'resolved_at' => '2026-11-12 06:00:00',
                'counted_qty' => 4, 'count_document_id' => 999999])), 'cleared with no booked difference');
        } finally {
            self::$db->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $row('open', ['batch' => 'l', 'sold_30d' => 0]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM stock_doubt WHERE sku_id = ? AND status = 'open'", [$fresh]));
    }

    public function testTheAppLoginNeverRemovesARow(): void
    {
        $config = TestDb::config();
        $user = $config->appDbUser();
        self::assertNotNull($user);
        Grants::apply(self::$db, TestDb::name(), $user);
        $app = Db::connect($config->dbApp()->withDatabase(TestDb::name()));
        $store = self::makeChannel('vapeandgo');
        $sku = self::makeSku('Grant pod');
        $id = $app->insert("INSERT INTO stock_doubt (channel_id, sku_id, reason, cw_qty, batch) VALUES (?, ?, 'below_zero', -2, 'list')", [$store, $sku]);
        $app->exec('UPDATE stock_doubt SET cw_qty = -3 WHERE id = ?', [$id]);
        self::assertSame(-3, (int) $app->value('SELECT cw_qty FROM stock_doubt WHERE id = ?', [$id]));
        try {
            $app->exec('DELETE FROM stock_doubt WHERE id = ?', [$id]);
            self::fail('the app login removed a row');
        } catch (\PDOException $e) {
            self::assertSame(1142, Db::driverCode($e), 'DELETE command denied');
        }
    }
}
