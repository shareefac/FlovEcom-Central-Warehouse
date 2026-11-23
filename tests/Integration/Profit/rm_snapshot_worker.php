<?php

declare(strict_types=1);

/**
 * Timing-test worker for Royal Mail's bills (spawned by RoyalMailWeekTest while a week's bill is stored): stands in for the order snapshot
 * job on this slot's test schema. Reads a JSON job on stdin: {orders: [[channel, ref, day], ...], until_ms, gap_ms}. Prints "running", then
 * until until_ms is up, for each order in turn, as a snapshot does: its guard (GET_LOCK(name, OrderSnapshots::QUEUE_LOCK_WAIT_SEC)), its day
 * row (ProfitDays::guard) in a transaction, 20 ms of work, commit, the guard let go; gap_ms (default 100) between two. Last line: JSON {stores, waited_max_ms, missed} (missed: a
 * guard not got within the snapshot's own wait).
 */

use CW\Db;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
fwrite(STDOUT, "running\n");
fflush(STDOUT);
$until = microtime(true) + (int) $job['until_ms'] / 1000;
$stores = 0;
$max = 0;
$missed = 0;
while (microtime(true) < $until) {
    foreach ($job['orders'] as [$ch, $ref, $day]) {
        if (microtime(true) >= $until) {
            break;
        }
        $name = OrderSnapshots::lockName((int) $ch, (string) $ref);
        $t = microtime(true);
        $got = (int) $db->value('SELECT GET_LOCK(?, ?)', [$name, OrderSnapshots::QUEUE_LOCK_WAIT_SEC]);
        $max = max($max, (int) round((microtime(true) - $t) * 1000));
        if ($got !== 1) {
            $missed++;
            continue;
        }
        try {
            $db->transaction(static function (Db $db) use ($ch, $day): void {
                ProfitDays::guard($db, (int) $ch, [(string) $day]);
                usleep(20_000);
            });
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [$name]);
        }
        $stores++;
        usleep((int) ($job['gap_ms'] ?? 100) * 1000);
    }
}
fwrite(STDOUT, json_encode(['stores' => $stores, 'waited_max_ms' => $max, 'missed' => $missed], JSON_THROW_ON_ERROR) . "\n");
