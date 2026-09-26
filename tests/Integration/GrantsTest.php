<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Grants;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * The app-login grant model, checked with a throwaway login on the test schema:
 * full DML everywhere except the append-only tables (SELECT/INSERT) and schema_migrations (SELECT).
 */
final class GrantsTest extends IntegrationTestCase
{
    private const DENIED = 1142;

    private static string $user;
    private static string $password;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$user = substr('cw_t_' . preg_replace('/[^a-z0-9_]/', '', substr(TestDb::name(), strlen('cw_test_'))), 0, 32);
        self::$password = bin2hex(random_bytes(16)) . 'Aa1-';
        $server = TestDb::server();
        $account = Grants::account(self::$user);
        $server->pdo()->exec("DROP USER IF EXISTS {$account}");
        $server->pdo()->exec("CREATE USER {$account} IDENTIFIED BY " . $server->pdo()->quote(self::$password) . ' REQUIRE SSL');
    }

    public static function tearDownAfterClass(): void
    {
        TestDb::server()->pdo()->exec('DROP USER IF EXISTS ' . Grants::account(self::$user));
    }

    public function testDesiredPrivileges(): void
    {
        self::assertSame(['Select', 'Insert'], Grants::desired('stock_ledger'));
        self::assertSame(['Select', 'Insert'], Grants::desired('audit_log'));
        self::assertSame(['Select'], Grants::desired('schema_migrations'));
        self::assertSame(['Select', 'Insert', 'Update', 'Delete'], Grants::desired('stock_balance'));
    }

    public function testApplyConvergesAndTheAppLoginIsLimited(): void
    {
        $name = TestDb::name();
        $changes = Grants::apply(self::$db, $name, self::$user);
        self::assertNotEmpty($changes);
        self::assertSame([], Grants::apply(self::$db, $name, self::$user), 'second apply changes nothing');

        $app = $this->appSession();
        $main = self::warehouseId('MAIN');
        $sku = self::makeSku();
        self::$db->exec('INSERT INTO stock_balance (warehouse_id, sku_id) VALUES (?, ?)', [$main, $sku]);

        // Allowed: ordinary DML, and INSERT into the append-only tables.
        $app->exec('UPDATE stock_balance SET on_hand = on_hand + 1 WHERE warehouse_id = ? AND sku_id = ?', [$main, $sku]);
        $ledgerId = $app->insert(
            "INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, actor) VALUES (?, ?, 'on_hand', 1, 1, 'adjustment', 'system:test')",
            [$main, $sku],
        );
        $app->insert("INSERT INTO audit_log (actor, action) VALUES ('system:test', 'test.grant')");
        self::assertSame(1, $app->value('SELECT COUNT(*) FROM stock_ledger WHERE id = ?', [$ledgerId]));
        self::assertSame(1, $app->value('SELECT COUNT(*) FROM schema_migrations WHERE version = ?', ['0001_core.sql']));

        // Refused: rewriting history or the migration bookkeeping.
        foreach ([
            'UPDATE stock_ledger SET qty_delta = 99',
            'DELETE FROM stock_ledger',
            "UPDATE audit_log SET action = 'x'",
            'DELETE FROM audit_log',
            "INSERT INTO schema_migrations (version, checksum, applied_at, duration_ms) VALUES ('x', 'x', UTC_TIMESTAMP(), 0)",
            'DELETE FROM schema_migrations',
            'DROP TABLE stock_balance',
            'ALTER TABLE sku ADD COLUMN x INT',
        ] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        self::assertSame(1, self::$db->value('SELECT qty_delta FROM stock_ledger WHERE id = ?', [$ledgerId]));
    }

    public function testApplyRemovesDriftAndDatabaseLevelGrants(): void
    {
        $name = TestDb::name();
        $account = Grants::account(self::$user);
        Grants::apply(self::$db, $name, self::$user);
        self::$db->pdo()->exec('GRANT UPDATE, DELETE ON ' . Db::ident($name) . '.`stock_ledger` TO ' . $account);
        self::$db->pdo()->exec('GRANT ALL PRIVILEGES ON ' . Db::ident($name) . '.* TO ' . $account);

        $changes = Grants::apply(self::$db, $name, self::$user);
        self::assertContains('revoked UPDATE, DELETE on stock_ledger', $changes);
        self::assertContains("revoked database-level grant on {$name}.*", $changes);

        $app = $this->appSession();
        self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec('DELETE FROM stock_ledger')));
        self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec('CREATE TABLE x (id INT PRIMARY KEY)')));
    }

    private function appSession(): Db
    {
        $s = TestDb::config()->dbAdmin()->withCredentials(self::$user, self::$password)->withDatabase(TestDb::name());
        return Db::connect($s);
    }
}
