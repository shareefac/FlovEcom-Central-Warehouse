<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Db;
use PHPUnit\Framework\TestCase;

/** Base for tests that need the migrated test schema; every test starts from empty tables. */
abstract class IntegrationTestCase extends TestCase
{
    protected static Db $db;

    public static function setUpBeforeClass(): void
    {
        if (getenv('CW_TEST_DB') === '0') {
            self::markTestSkipped('CW_TEST_DB=0: database tests disabled');
        }
        self::$db = TestDb::db();
    }

    protected function setUp(): void
    {
        TestDb::clean(self::$db);
    }

    /** Runs $fn and returns the MySQL error code it raised (fails the test if none). */
    protected static function mysqlError(callable $fn): int
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            $code = Db::driverCode($e);
            self::assertNotNull($code, 'PDOException without a MySQL error code: ' . $e->getMessage());
            return $code;
        }
        self::fail('expected a MySQL error, none was raised');
    }

    /** Minimal fixtures. */
    protected static function makeChannel(string $code = 'vpg', string $mode = 'off'): int
    {
        return self::$db->insert(
            'INSERT INTO channel (code, name, mode) VALUES (?, ?, ?)',
            [$code, strtoupper($code) . ' test site', $mode],
        );
    }

    protected static function makeSku(string $name = 'Test item 10mg'): int
    {
        return self::$db->transaction(static function (Db $db) use ($name): int {
            $id = $db->insert('INSERT INTO sku (name) VALUES (?)', [$name]);
            $db->exec('UPDATE sku SET code = ? WHERE id = ?', [sprintf('CW-%06d', $id), $id]);
            return $id;
        });
    }

    protected static function warehouseId(string $code): int
    {
        return (int) self::$db->value('SELECT id FROM warehouse WHERE code = ?', [$code]);
    }
}
