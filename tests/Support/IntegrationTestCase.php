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
        // CW-1180 (0081, NR1): a cancelled paid unit the store marked "don't restock" starts back at the seeded rule, written off at once; the
        // tests of the way before (VERIFY, D37) set "verify" themselves (notRestockable()).
        self::notRestockable('write_off');
        // "Claude reads the upload" (0082, AI7) starts back at its seeded state after a test that set it: off, no key, the month's limit and
        // the prices as seeded (a schema before 0082 has none of these rows: nothing changes).
        self::$db->exec("UPDATE app_setting SET value_json = CASE setting_key WHEN 'ai.read_uploads' THEN CAST('false' AS JSON) "
            . "WHEN 'ai.anthropic_api_key' THEN CAST('\"\"' AS JSON) WHEN 'ai.model' THEN CAST('\"claude-opus-5-5\"' AS JSON) "
            . "WHEN 'ai.effort' THEN CAST('\"medium\"' AS JSON) WHEN 'ai.monthly_limit_gbp' THEN CAST('\"30\"' AS JSON) "
            . "WHEN 'ai.price_input_gbp_per_mtok' THEN CAST('\"3.20\"' AS JSON) ELSE CAST('\"16.00\"' AS JSON) END WHERE setting_key IN ('ai.read_uploads', "
            . "'ai.anthropic_api_key', 'ai.model', 'ai.effort', 'ai.monthly_limit_gbp', 'ai.price_input_gbp_per_mtok', 'ai.price_output_gbp_per_mtok')");
    }

    /** CW-1180 (0081, NR1): what a not-restockable cancel of a paid unit does, `write_off` (the seeded rule) or `verify` (D37, the way before). */
    protected static function notRestockable(string $mode): void
    {
        self::$db->exec("UPDATE app_setting SET value_json = JSON_QUOTE(?) WHERE setting_key = 'stock.cancel_not_restockable'", [$mode]);
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
