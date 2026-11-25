<?php

declare(strict_types=1);

/**
 * Applies pending migrations/*.sql to one schema, as the admin login (db.env).
 *
 *   php bin/migrate.php --db=cw_staging            apply pending migrations
 *   php bin/migrate.php --db=cw_test_x --create    create the schema first if missing
 *   php bin/migrate.php --db=cw_staging --status   list applied / pending, change nothing
 *   php bin/migrate.php                            target = CW_DB_NAME or app.env db_name
 *
 * When the target is the app schema named in app.env (db_name) and app.env names the app login,
 * the app login's table grants are converged afterwards (Schema\Grants) unless --no-grants, inside the
 * Migrator's run (its bounded metadata-lock wait and its lock: PR155).
 * Exit codes: 0 ok, 1 failure, 2 usage.
 */

use CW\Config;
use CW\Db;
use CW\DbSettings;
use CW\Schema\Grants;
use CW\Schema\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['db:', 'create', 'status', 'no-grants', 'dir:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "usage: php bin/migrate.php [--db=name] [--create] [--status] [--no-grants] [--dir=migrations]\n");
    exit(0);
}

try {
    $config = Config::load();
    $target = is_string($opts['db'] ?? null) ? $opts['db'] : $config->appDbName();
    if ($target === null || !DbSettings::isValidIdentifier($target)) {
        fwrite(STDERR, "migrate: give --db=<schema> (or set CW_DB_NAME / app.env db_name)\n");
        exit(2);
    }
    $dir = is_string($opts['dir'] ?? null) ? $opts['dir'] : Migrator::defaultDir();
    $admin = $config->dbAdmin();

    if (isset($opts['create'])) {
        $server = Db::connect($admin->withDatabase(null));
        $server->pdo()->exec('CREATE DATABASE IF NOT EXISTS ' . Db::ident($target)
            . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    }

    $db = Db::connect($admin->withDatabase($target));
    $migrator = new Migrator($db, $dir, static function (string $m) use ($target): void {
        fwrite(STDOUT, "[{$target}] {$m}\n");
    });

    if (isset($opts['status'])) {
        $applied = $migrator->applied();
        foreach (array_keys($migrator->files()) as $version) {
            $state = isset($applied[$version]) ? 'applied ' . $applied[$version]['applied_at'] : 'PENDING';
            fwrite(STDOUT, sprintf("%-40s %s\n", $version, $state));
        }
        $migrator->pending(); // verifies checksums / missing files
        exit(0);
    }

    // The grants only for the schema named in app.env (never a CW_DB_NAME override such as a test schema). They run inside the Migrator's
    // run, after the files: the same bounded metadata-lock wait (Migrator::LOCK_WAIT_SEC) and the same migration lock, the session's own
    // wait put back only at the end (the final re-check of the fix round, p-M, PR155).
    $appUser = $config->appFile('db_user');
    $grants = null;
    $changes = null;
    if (!isset($opts['no-grants']) && $appUser !== null && $target === $config->appFile('db_name')) {
        $grants = static function () use ($db, $target, $appUser, &$changes): void {
            $changes = Grants::apply($db, $target, $appUser);
        };
    }
    $done = $migrator->migrate($grants);
    fwrite(STDOUT, "[{$target}] " . ($done === [] ? 'up to date' : count($done) . ' migration(s) applied') . "\n");
    if ($changes !== null) {
        fwrite(STDOUT, "[{$target}] grants for {$appUser}: " . ($changes === [] ? 'unchanged' : implode('; ', $changes)) . "\n");
    }
    // CW-1182 (AI19): after an undo of "Claude reads the upload" the key's row is a number (the release before takes no key into it); this
    // release types it back, here, where the installer has the writers paused
    if (\CW\Ai\AiSettings::restoreKeyRow($db)) {
        fwrite(STDOUT, "[{$target}] the Anthropic API key's setting typed back to text (after an undo)\n");
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'migrate: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
