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
        // app_setting is a seed table clean() keeps: the one switch tests turn on starts back at its seeded off (MO4).
        self::askModeChanges(false);
        // The supplier detail switches (0062, SD1) start back at their seeded off, as their history says (the Codex review of 770dde7):
        // the supplier tests switch them on (SupplierTestCase).
        self::supplierNeeds(false);
    }

    /** The four "A supplier needs ..." switches (suppliers.need_*, 0062): on, a supplier is usable only with those details. */
    protected static function supplierNeeds(bool $on): void
    {
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key IN ('suppliers.need_address', 'suppliers.need_contact', "
            . "'suppliers.need_payment_terms', 'suppliers.need_due_diligence')", [$on ? 'true' : 'false']);
    }

    /** "Ask about ERPNext mode changes" (relay.mode_review, 0059): on, a mode change that changes something waits in Approvals. */
    protected static function askModeChanges(bool $on): void
    {
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'relay.mode_review'", [$on ? 'true' : 'false']);
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
