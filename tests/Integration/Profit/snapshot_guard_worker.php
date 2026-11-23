<?php

declare(strict_types=1);

/**
 * Concurrency-test worker for the delivery bills (spawned by DeliveryBillsTest while a bill holds its order guards): one process = one DB
 * connection (this slot's test schema), standing in for the snapshot worker. Reads a JSON job on stdin: {name, wait, hold_ms}. Prints
 * "waiting" once the guard `name` is seen held elsewhere (the bill), then asks for it as the snapshot worker does (GET_LOCK(name, wait):
 * OrderSnapshots::QUEUE_LOCK_WAIT_SEC), keeps it hold_ms when it got it (a snapshot working out: milliseconds) and lets it go. Last line:
 * JSON {seen, got, waited_ms}.
 */

use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
$name = (string) $job['name'];
$seen = $db->value('SELECT IS_USED_LOCK(?)', [$name]) !== null;
fwrite(STDOUT, "waiting\n");
fflush(STDOUT);
$t = microtime(true);
$got = (int) $db->value('SELECT GET_LOCK(?, ?)', [$name, (int) $job['wait']]);
$waited = (int) round((microtime(true) - $t) * 1000);
if ($got === 1) {
    usleep(1000 * (int) $job['hold_ms']);
    $db->value('SELECT RELEASE_LOCK(?)', [$name]);
}
fwrite(STDOUT, json_encode(['seen' => $seen, 'got' => $got, 'waited_ms' => $waited], JSON_THROW_ON_ERROR) . "\n");
