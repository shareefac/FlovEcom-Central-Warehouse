<?php

declare(strict_types=1);

/**
 * A snapshot's wait for one store-day's guard, on its own connection (spawned by GpFeesLastTest while the card fees job holds that guard): as
 * the money job's snapshot waits (OrderSnapshots::QUEUE_LOCK_WAIT_SEC, 3 s). Args: <channel id> <day>. Prints "trying", then one JSON line
 * {got: whether it had the guard within its 3 s, ms: how long it waited}.
 */

use CW\Profit\ProfitDays;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$db = TestDb::connect();
$db->exec('SET SESSION innodb_lock_wait_timeout = 3');
$db->exec('START TRANSACTION');
fwrite(STDOUT, "trying\n");
fflush(STDOUT);
$t = microtime(true);
$got = true;
try {
    ProfitDays::guard($db, (int) ($argv[1] ?? 0), [(string) ($argv[2] ?? '')]);
} catch (\PDOException) {
    $got = false;
}
$ms = (int) round((microtime(true) - $t) * 1000);
$db->exec('ROLLBACK');
fwrite(STDOUT, json_encode(['got' => $got, 'ms' => $ms], JSON_THROW_ON_ERROR) . "\n");
