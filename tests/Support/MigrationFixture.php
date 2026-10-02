<?php

declare(strict_types=1);

namespace CW\Tests\Support;

use CW\Db;
use CW\Schema\Migrator;

/**
 * A scratch schema migrated only up to a given migration, so a test can write the data a later
 * migration must carry (a backfill, a column moved, a table split) and then apply that migration
 * to it. The schema is `<test schema>_<suffix>` (always cw_test_*); the migrations are copies in a
 * temporary directory, so the real migrations/ directory is never touched.
 *
 *   [$db, $dir] = MigrationFixture::upTo('m6', '0005_listing_barcodes_index.sql');
 *   ... insert rows as they were before 0006 ...
 *   MigrationFixture::migrateRest($db, $dir, '0006_value_core.sql');
 *   ... assert ...
 *   MigrationFixture::drop('m6', $dir);   // tearDown
 */
final class MigrationFixture
{
    /** The scratch schema of $suffix: cw_test_<slot>_<suffix>. */
    public static function schema(string $suffix): string
    {
        if (preg_match('/^[a-z0-9]{1,8}$/D', $suffix) !== 1) {
            throw new \InvalidArgumentException('a migration fixture suffix is 1-8 lower-case letters or digits');
        }
        $name = TestDb::name() . '_' . $suffix;
        if (preg_match(TestDb::NAME_PATTERN, $name) !== 1) {
            throw new \RuntimeException("refusing to use {$name} as a scratch schema");
        }
        return $name;
    }

    /**
     * Drops and re-creates the scratch schema and applies the migrations up to and including $lastFile.
     *
     * @return array{0: Db, 1: string} an admin connection to the scratch schema, and its migrations directory
     */
    public static function upTo(string $suffix, string $lastFile): array
    {
        $all = self::files();
        if (!isset($all[$lastFile])) {
            throw new \InvalidArgumentException("no migration {$lastFile}");
        }
        $name = self::schema($suffix);
        $server = TestDb::server();
        $server->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident($name));
        $server->pdo()->exec('CREATE DATABASE ' . Db::ident($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $dir = sys_get_temp_dir() . '/cw_migfx_' . $suffix . '_' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException("cannot create {$dir}");
        }
        foreach ($all as $version => $path) {
            self::copy($path, "{$dir}/{$version}");
            if ($version === $lastFile) {
                break;
            }
        }
        $db = Db::connect(TestDb::config()->dbAdmin()->withDatabase($name));
        (new Migrator($db, $dir))->migrate();
        return [$db, $dir];
    }

    /**
     * Applies the migrations after the ones already in $dir (all of them, or up to and including $through).
     *
     * @return list<string> the versions applied
     */
    public static function migrateRest(Db $db, string $dir, ?string $through = null): array
    {
        $all = self::files();
        if ($through !== null && !isset($all[$through])) {
            throw new \InvalidArgumentException("no migration {$through}");
        }
        foreach ($all as $version => $path) {
            if (!is_file("{$dir}/{$version}")) {
                self::copy($path, "{$dir}/{$version}");
            }
            if ($version === $through) {
                break;
            }
        }
        return (new Migrator($db, $dir))->migrate();
    }

    /** Drops the scratch schema and removes its migrations directory. */
    public static function drop(string $suffix, string $dir): void
    {
        TestDb::server()->pdo()->exec('DROP DATABASE IF EXISTS ' . Db::ident(self::schema($suffix)));
        if (is_dir($dir) && str_starts_with(basename($dir), 'cw_migfx_')) {
            foreach (glob($dir . '/*.sql') ?: [] as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }

    /** @return array<string, string> version => path of every migration in the repo, in apply order */
    private static function files(): array
    {
        $files = [];
        foreach (scandir(Migrator::defaultDir()) ?: [] as $name) {
            if (preg_match(Migrator::FILE_PATTERN, $name) === 1) {
                $files[$name] = Migrator::defaultDir() . '/' . $name;
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function copy(string $from, string $to): void
    {
        if (!copy($from, $to)) {
            throw new \RuntimeException("cannot copy {$from}");
        }
    }
}
