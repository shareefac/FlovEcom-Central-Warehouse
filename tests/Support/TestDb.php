<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Config;
use CW\Db;
use CW\Schema\Migrator;

/**
 * The per-slot test schema (cw_test_<slot>): dropped, re-created and migrated once per
 * PHPUnit run by tests/bootstrap.php. Tests connect with the admin login from db.env.
 * Only schemas named cw_test_* are ever dropped.
 */
final class TestDb
{
    public const NAME_PATTERN = '/^cw_test_[a-z0-9_]{1,40}$/';
    /** Rows that 0001_core.sql seeds; clean() keeps them. */
    public const SEED_WAREHOUSES = ['MAIN', 'VERIFY', 'UNSTAMPED'];
    /**
     * Reference lists the migrations seed (0008: reason codes, document types; 0009: settings, VAT codes): clean() keeps
     * them, so a test that changes one (a review rule, a limit, a setting) restores it itself.
     */
    public const SEED_TABLES = ['reason_code', 'document_type', 'app_setting', 'vat_code'];

    private static ?Db $db = null;

    public static function name(): string
    {
        $name = getenv('CW_DB_NAME');
        if (!is_string($name) || $name === '') {
            $slot = getenv('CW_SLOT');
            if (!is_string($slot) || $slot === '') {
                throw new \RuntimeException('set CW_SLOT (test schema cw_test_<slot>) or CW_DB_NAME=cw_test_...');
            }
            $name = 'cw_test_' . strtolower($slot);
        }
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \RuntimeException("refusing to use {$name} as a test schema: it must match cw_test_[a-z0-9_]+");
        }
        return $name;
    }

    public static function config(): Config
    {
        return Config::load();
    }

    /** Admin connection to the server with no default schema. */
    public static function server(): Db
    {
        return Db::connect(self::config()->dbAdmin()->withDatabase(null));
    }

    /** Drops, re-creates and migrates the test schema. */
    public static function reset(): void
    {
        $name = self::name();
        $server = self::server();
        $server->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident($name));
        $server->pdo()->exec('CREATE DATABASE ' . Db::ident($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        self::$db = null;
        (new Migrator(self::db(), Migrator::defaultDir()))->migrate();
    }

    /** Shared admin connection to the test schema. */
    public static function db(): Db
    {
        return self::$db ??= self::connect();
    }

    /** A fresh, separate admin connection to the test schema (for concurrency tests). */
    public static function connect(): Db
    {
        return Db::connect(self::config()->dbAdmin()->withDatabase(self::name()));
    }

    /**
     * Empties every table except schema_migrations, the seeded warehouses and the seeded reference lists
     * (SEED_TABLES); the number series (one seeded row per prefix) are set back to 0 (pad 6) instead of deleted.
     */
    public static function clean(Db $db): void
    {
        $tables = $db->column(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'",
        );
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $t) {
                $t = (string) $t;
                if ($t === 'schema_migrations' || in_array($t, self::SEED_TABLES, true)) {
                    continue;
                }
                if ($t === 'number_series') {
                    $db->exec('UPDATE number_series SET last_no = 0, pad = 6');
                    continue;
                }
                if ($t === 'warehouse') {
                    $in = implode(',', array_fill(0, count(self::SEED_WAREHOUSES), '?'));
                    $db->exec("DELETE FROM warehouse WHERE code NOT IN ({$in})", self::SEED_WAREHOUSES);
                    continue;
                }
                $db->pdo()->exec('DELETE FROM ' . Db::ident($t));
            }
        } finally {
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
