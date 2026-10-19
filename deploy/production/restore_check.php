<?php

// The restore test of production's database (docs/ops.md "The production server", "The restore test"): compares cw_prod on a
// cluster restored from a backup of db-mysql-lon1-37047 with production. Read-only on both (SELECTs and CHECKSUM TABLE). Run as root
// on cw-prod-lon1-01, in /opt/cw-prod:
//
//   php deploy/production/restore_check.php <private host of the restored cluster>
//   php deploy/production/restore_check.php --self-test <production's public host>   (the check of this script: production against itself)
//
// Then bin/invariants.php against the copy: CW_DB_HOST=<host> php bin/invariants.php --db=cw_prod (it records its run in the copy).
// Exit 0 = the copy is whole (same tables, columns, users and grants; every table's rows as production's, or a table the running
// jobs wrote since the backup); 1 = a difference to look at; 2 = usage, refused, or cannot connect.

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use CW\Config;
use CW\Db;

const SCHEMA = 'cw_prod';
// Tables the running jobs (and sign-ins) write every few minutes: rows changed there after the backup are expected.
const BUSY = ['alert_state', 'audit_log', 'integrity_run', 'login_attempt', 'staff_session', 'report_refresh', 'reorder_job', 'idempotency',
    'feed_clock', 'channel_health'];

$selfTest = ($argv[1] ?? '') === '--self-test';
$copyHost = $argv[$selfTest ? 2 : 1] ?? '';
if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $copyHost)) {
    fwrite(STDERR, "usage: php deploy/production/restore_check.php [--self-test] <host of the restored cluster>\n");
    exit(2);
}
// Production as its files say it (db.env, app.env): no CW_DB_* override inherited from this shell (only the files' own paths).
$env = array_filter(getenv(), static fn (string $k): bool => !str_starts_with($k, 'CW_DB_') || $k === 'CW_DB_ENV', ARRAY_FILTER_USE_KEY);
$prodCfg = Config::load($env);
$copyCfg = Config::load(['CW_DB_HOST' => $copyHost] + $env);
if ($prodCfg->appDbName() !== SCHEMA) {
    fwrite(STDERR, 'REFUSING: this box\'s app.env names the schema ' . ($prodCfg->appDbName() ?? '(none)') . ', not ' . SCHEMA . ": run it on the production box\n");
    exit(2);
}
if (!$selfTest && $prodCfg->dbAdmin()->host === $copyHost) {
    fwrite(STDERR, "REFUSING: {$copyHost} is production's own host\n");
    exit(2);
}

try {
    $prod = Db::connect($prodCfg->dbAdmin());
    $copy = Db::connect($copyCfg->dbAdmin());
} catch (\Throwable $e) {
    fwrite(STDERR, 'cannot connect: ' . $e->getMessage() . "\n");
    exit(2);
}
$problems = 0;
$say = static function (string $line): void {
    echo $line, "\n";
};

$idP = $prod->one('SELECT @@hostname AS h, @@server_uuid AS u, VERSION() AS v');
$idC = $copy->one('SELECT @@hostname AS h, @@server_uuid AS u, VERSION() AS v');
$say("production: {$prodCfg->dbAdmin()->host} ({$idP['h']}, MySQL {$idP['v']})");
$say("copy:       {$copyHost} ({$idC['h']}, MySQL {$idC['v']})");
if ($selfTest) {
    $say('SELF-TEST: production against itself through a second host name; every table must come out identical');
} elseif ($idP['h'] === $idC['h'] && $idP['u'] === $idC['u']) {
    fwrite(STDERR, "REFUSING: both connections reach the same server\n");
    exit(2);
}

// 1. The schema: the same tables and the same columns.
$colsSql = 'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION';
$hashP = hash('sha256', json_encode($prod->all($colsSql, [SCHEMA])));
$hashC = hash('sha256', json_encode($copy->all($colsSql, [SCHEMA])));
$tablesSql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY 1";
$tablesP = $prod->column($tablesSql, [SCHEMA]);
$tablesC = $copy->column($tablesSql, [SCHEMA]);
$say(sprintf('tables: production %d, copy %d; columns %s', count($tablesP), count($tablesC), $hashP === $hashC ? 'the same' : 'DIFFER'));
if (!in_array('schema_migrations', $tablesP, true)) {
    $say('  production has no ' . SCHEMA . ' (no schema_migrations): nothing to compare');
    $problems++;
}
foreach (array_diff($tablesP, $tablesC) as $t) {
    $say("  MISSING in the copy: {$t}");
    $problems++;
}
foreach (array_diff($tablesC, $tablesP) as $t) {
    $say("  only in the copy: {$t}");
}
if ($hashP !== $hashC) {
    $problems++;
}

// 2. The rows: count and checksum each table on both.
$same = 0;
$grown = [];
foreach (array_intersect($tablesP, $tablesC) as $t) {
    $q = Db::ident(SCHEMA) . '.' . Db::ident($t);
    $nP = (int) $prod->value("SELECT COUNT(*) FROM {$q}");
    $nC = (int) $copy->value("SELECT COUNT(*) FROM {$q}");
    $sP = (string) ($prod->one("CHECKSUM TABLE {$q}")['Checksum'] ?? '');
    $sC = (string) ($copy->one("CHECKSUM TABLE {$q}")['Checksum'] ?? '');
    if ($nP === $nC && $sP === $sC) {
        $same++;
        continue;
    }
    $busy = in_array($t, BUSY, true) && $nC <= $nP;
    $grown[] = sprintf('  %s %s: production %d rows, copy %d rows%s', $busy ? 'written since the backup' : 'DIFFERS', $t, $nP, $nC,
        $nP === $nC ? ' (same count, other content)' : '');
    if (!$busy) {
        $problems++;
    }
}
$say("rows: {$same} tables identical");
foreach ($grown as $line) {
    $say($line);
}

// 3. The logins: the same users, cw_app's grants, and cw_app can sign in to the copy.
$usersSql = "SELECT CONCAT(User, '@', Host) FROM mysql.user WHERE User NOT LIKE 'mysql.%' ORDER BY 1";
try {
    $uP = $prod->column($usersSql);
    $uC = $copy->column($usersSql);
    $say('users: ' . ($uP === $uC ? 'the same (' . count($uP) . ')' : 'DIFFER: production ' . implode(', ', $uP) . '; copy ' . implode(', ', $uC)));
    if ($uP !== $uC) {
        $problems++;
    }
} catch (\Throwable $e) {
    $say('users: CANNOT read mysql.user (' . Db::driverCode($e) . ')');
    $problems++;
}
$app = $prodCfg->dbApp()->user;
$grantsSql = 'SHOW GRANTS FOR ' . $prod->pdo()->quote($app) . "@'%'";
$gP = array_map(static fn (array $r): string => (string) reset($r), $prod->all($grantsSql));
$gC = array_map(static fn (array $r): string => (string) reset($r), $copy->all($grantsSql));
$say("{$app} grants: " . ($gP === $gC ? 'the same (' . count($gP) . ' lines)' : 'DIFFER'));
if ($gP !== $gC) {
    $problems++;
}
try {
    $asApp = Db::connect($copyCfg->dbApp());
    $who = $asApp->one('SELECT CURRENT_USER() AS u, DATABASE() AS d');
    $say("{$app} signs in to the copy: {$who['u']} on {$who['d']}");
    if ($who['d'] !== SCHEMA) {
        $problems++;
    }
} catch (\Throwable $e) {
    $say("{$app} CANNOT sign in to the copy: " . $e->getMessage());
    $problems++;
}

// 4. The newest row of the busy log tables: how old the backup is.
foreach (['integrity_run' => 'finished_at'] as $t => $col) {
    try {
        $q = Db::ident(SCHEMA) . '.' . Db::ident($t);
        $say("newest {$t}: production " . ($prod->value("SELECT MAX({$col}) FROM {$q}") ?? '-') . ', copy ' . ($copy->value("SELECT MAX({$col}) FROM {$q}") ?? '-'));
    } catch (\Throwable) {
    }
}

$say($problems === 0 ? 'RESULT: the copy is whole' : "RESULT: {$problems} difference(s) to look at");
exit($problems === 0 ? 0 : 1);
