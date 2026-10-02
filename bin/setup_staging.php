<?php

declare(strict_types=1);

/**
 * One-shot, idempotent setup of the CW staging database. Run ON THE STAGING SERVER as root
 * (it reads the doadmin login from /etc/cw/db.env):
 *
 *   php bin/setup_staging.php [--db=cw_staging] [--user=cw_app] [--mark-staging]
 *
 *  1. CREATE DATABASE IF NOT EXISTS cw_staging (utf8mb4_0900_ai_ci).
 *  2. App login cw_app: a random password is generated once and written to /etc/cw/app.env
 *     (key=value: db_user, db_password, db_name, plus db_host/db_port copied from db.env so the
 *     web service never reads db.env; other keys in the file are kept). A new file is 0600
 *     root; an existing file keeps its group and mode (0640 root:www-data once php-fpm serves
 *     the API, see deploy/staging/install_api.sh), never wider than 0640. With --mark-staging it
 *     also writes environment=staging, the marker staging-only tools check (D47). That is opt-in: a
 *     server that will carry real orders must never get it by re-running this script.
 *     Re-runs reuse that password; the account is created (REQUIRE SSL) or re-aligned to it.
 *  3. Migrations are applied as doadmin.
 *  4. cw_app table grants are converged: SELECT/INSERT/UPDATE/DELETE on every table except
 *     stock_ledger and audit_log (SELECT/INSERT only) and schema_migrations (SELECT only).
 *  5. Verification as cw_app: can read, cannot UPDATE/DELETE the append-only tables (probed on the
 *     first column of each table's primary key: stock_value_seq has no `id`).
 * Secrets are never printed.
 */

use CW\Config;
use CW\ConfigException;
use CW\Db;
use CW\DbSettings;
use CW\Schema\Grants;
use CW\Schema\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['db:', 'user:', 'mark-staging', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "usage: php bin/setup_staging.php [--db=cw_staging] [--user=cw_app] [--mark-staging]\n");
    exit(0);
}
$markStaging = array_key_exists('mark-staging', $opts);
$dbName = is_string($opts['db'] ?? null) ? $opts['db'] : 'cw_staging';
$appUser = is_string($opts['user'] ?? null) ? $opts['user'] : 'cw_app';
if (!DbSettings::isValidIdentifier($dbName) || !DbSettings::isValidIdentifier($appUser) || strlen($appUser) > 32) {
    fwrite(STDERR, "setup: invalid --db or --user\n");
    exit(2);
}

$say = static function (string $m): void {
    fwrite(STDOUT, "[setup] {$m}\n");
};

try {
    $config = Config::load();
    $adminSettings = $config->dbAdmin()->withDatabase(null);
    $server = Db::connect($adminSettings);
    $say('connected as admin over TLS');

    // 1. schema
    $server->pdo()->exec('CREATE DATABASE IF NOT EXISTS ' . Db::ident($dbName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    $say("database {$dbName} present");

    // 2. app login + app.env
    $appEnvPath = $config->appEnvPath;
    $existing = is_file($appEnvPath) ? Config::parseEnvFile($appEnvPath) : [];
    $password = null;
    if (($existing['db_user'] ?? null) === $appUser && ($existing['db_password'] ?? '') !== '') {
        $password = $existing['db_password'];
        $say("reusing the {$appUser} password from {$appEnvPath}");
    } else {
        $password = generatePassword(32);
        writeAppEnv($appEnvPath, ['db_user' => $appUser, 'db_password' => $password, 'db_name' => $dbName]);
        $say("new {$appUser} password written to {$appEnvPath} (0600)");
    }
    if (($existing['db_name'] ?? null) !== $dbName && isset($existing['db_password'])) {
        writeAppEnv($appEnvPath, ['db_name' => $dbName]);
    }
    // The web service reads only app.env (Config::loadApp), so it needs the host/port too.
    $hostPort = ['db_host' => $adminSettings->host, 'db_port' => (string) $adminSettings->port];
    $current = Config::parseEnvFile($appEnvPath);
    if (($current['db_host'] ?? null) !== $hostPort['db_host'] || ($current['db_port'] ?? null) !== $hostPort['db_port']) {
        writeAppEnv($appEnvPath, $hostPort);
        $say("db_host/db_port written to {$appEnvPath}");
    }
    // The staging marker (D47): staging-only tools (bin/purge_test_refs.php) refuse to run without it. Opt-in, so a
    // re-run on a server promoted to real use never puts it back (the go-live checklist removes it).
    if ($markStaging && ($current['environment'] ?? null) !== 'staging') {
        writeAppEnv($appEnvPath, ['environment' => 'staging']);
        $say("environment=staging written to {$appEnvPath}");
    } elseif (!$markStaging) {
        $say(($current['environment'] ?? null) === 'staging'
            ? "{$appEnvPath} says environment=staging (left as it is)"
            : "{$appEnvPath} has no staging marker (add --mark-staging on a staging server only)");
    }

    $account = Grants::account($appUser);
    $quoted = $server->pdo()->quote($password);
    $exists = $server->value('SELECT 1 FROM mysql.user WHERE User = ? AND Host = ?', [$appUser, '%']) !== null;
    if (!$exists) {
        $server->pdo()->exec("CREATE USER {$account} IDENTIFIED BY {$quoted} REQUIRE SSL");
        $say("created login {$appUser}");
    } elseif (!canLogin($adminSettings->withCredentials($appUser, $password))) {
        $server->pdo()->exec("ALTER USER {$account} IDENTIFIED BY {$quoted} REQUIRE SSL");
        $say("re-aligned the {$appUser} password with {$appEnvPath}");
    } else {
        $server->pdo()->exec("ALTER USER {$account} REQUIRE SSL");
        $say("login {$appUser} present");
    }

    // 3. migrations (admin)
    $db = Db::connect($adminSettings->withDatabase($dbName));
    $migrator = new Migrator($db, Migrator::defaultDir(), $say);
    $done = $migrator->migrate();
    $say($done === [] ? 'migrations: up to date' : 'migrations applied: ' . implode(', ', $done));

    // 4. grants
    $changes = Grants::apply($db, $dbName, $appUser);
    $say('grants: ' . ($changes === [] ? 'unchanged' : implode('; ', $changes)));

    // 5. verify as the app login
    $app = Db::connect($adminSettings->withCredentials($appUser, $password)->withDatabase($dbName));
    $warehouses = (int) $app->value('SELECT COUNT(*) FROM warehouse');
    $denied = [];
    foreach (Grants::APPEND_ONLY as $table) {
        $pk = $db->value(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' "
            . 'ORDER BY ORDINAL_POSITION LIMIT 1',
            [$dbName, $table],
        );
        if ($pk === null) {
            throw new RuntimeException("append-only table {$table} has no primary key to probe");
        }
        $col = Db::ident((string) $pk);
        foreach (['UPDATE ' . Db::ident($table) . " SET {$col} = {$col} WHERE 1 = 0", 'DELETE FROM ' . Db::ident($table) . ' WHERE 1 = 0'] as $sql) {
            try {
                $app->exec($sql);
                throw new RuntimeException("{$appUser} can run a write it must not have: " . strtok($sql, ' ') . " {$table}");
            } catch (PDOException $e) {
                if (Db::driverCode($e) !== 1142) {
                    throw $e;
                }
                $denied[] = strtok($sql, ' ') . " {$table}";
            }
        }
    }
    foreach (Grants::NO_DELETE as $table) { // UPDATE is allowed here: only DELETE is probed
        try {
            $app->exec('DELETE FROM ' . Db::ident($table) . ' WHERE 1 = 0');
            throw new RuntimeException("{$appUser} can run a write it must not have: DELETE {$table}");
        } catch (PDOException $e) {
            if (Db::driverCode($e) !== 1142) {
                throw $e;
            }
            $denied[] = "DELETE {$table}";
        }
    }
    $say("verified as {$appUser}: TLS on, {$warehouses} warehouses readable, denied: " . implode(', ', $denied));
    $say('done');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'setup: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

function generatePassword(int $length): string
{
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '-_.'];
    $all = implode('', $sets);
    $chars = [];
    foreach ($sets as $set) {
        $chars[] = $set[random_int(0, strlen($set) - 1)];
    }
    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }
    return implode('', $chars);
}

/**
 * @param array<string, string> $set keys to set; other keys already in the file are kept.
 * A new file is 0600 root. An existing file keeps its group and mode (capped at 0640), so the
 * php-fpm group read access set up by deploy/staging/install_api.sh survives a re-run.
 */
function writeAppEnv(string $path, array $set): void
{
    $prev = is_file($path) ? stat($path) : false;
    $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [
        '# Central Warehouse app settings (key=value, parsed by CW\\Config, never sourced).',
        '# Written by bin/setup_staging.php. 0600 root, or 0640 root:www-data once php-fpm serves the API.',
    ];
    $pending = $set;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.-]*)\s*=/', $line, $m)) {
            $key = strtolower($m[1]);
            if (array_key_exists($key, $pending)) {
                $lines[$i] = $key . '=' . $pending[$key];
                unset($pending[$key]);
            }
        }
    }
    foreach ($pending as $k => $v) {
        $lines[] = $k . '=' . $v;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new ConfigException("cannot create {$dir}");
    }
    $old = umask(0077);
    try {
        $tmp = tempnam($dir, '.app.env.');
        if ($tmp === false) {
            throw new ConfigException("cannot write in {$dir}");
        }
        chmod($tmp, 0600);
        if (file_put_contents($tmp, implode("\n", $lines) . "\n") === false) {
            @unlink($tmp);
            throw new ConfigException("cannot write {$path}");
        }
        if ($prev !== false) {
            chgrp($tmp, $prev['gid']);
            chmod($tmp, $prev['mode'] & 0640);
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new ConfigException("cannot write {$path}");
        }
    } finally {
        umask($old);
    }
}

function canLogin(DbSettings $s): bool
{
    try {
        Db::connect($s);
        return true;
    } catch (PDOException $e) {
        if (Db::driverCode($e) === 1045) {
            return false;
        }
        throw $e;
    }
}
