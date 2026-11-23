<?php

declare(strict_types=1);

/**
 * Concurrency-test worker for the delivery bills (spawned by DeliveryBillsTest while a bill holds its order guards): this slot's test schema.
 * Reads a JSON job on stdin.
 *  guard (default): one connection standing in for the snapshot worker: {name, wait, hold_ms}. Prints "waiting" (with whether the guard
 *        `name` is held elsewhere, by the bill), then asks for it as the snapshot worker does (GET_LOCK(name, wait):
 *        OrderSnapshots::QUEUE_LOCK_WAIT_SEC), keeps it hold_ms when it got it (a snapshot working out: milliseconds) and lets it go. Last
 *        line: JSON {seen, got, waited_ms}.
 *  rows: {mode: 'rows', invoice_id, line_nos, gap_ms}: each bill line held FOR UPDATE on a connection of its own; prints "locked", then lets
 *        the first go gap_ms later, the next gap_ms after that, and so on (so a writer that waits for each in turn waits a little under a
 *        second each time). Last line: JSON {released}.
 */

use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($job['mode'] ?? 'guard') === 'rows') {
    $conns = [];
    foreach ($job['line_nos'] as $no) {
        $c = TestDb::connect();
        $c->pdo()->beginTransaction();
        $c->one('SELECT line_no FROM carrier_invoice_line WHERE invoice_id = ? AND line_no = ? FOR UPDATE', [(int) $job['invoice_id'], (int) $no]);
        $conns[] = $c;
    }
    fwrite(STDOUT, "locked\n");
    fflush(STDOUT);
    $t = microtime(true);
    foreach ($conns as $i => $c) {
        @time_sleep_until($t + ($i + 1) * (int) $job['gap_ms'] / 1000);
        $c->pdo()->rollBack();
    }
    fwrite(STDOUT, json_encode(['released' => count($conns)], JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}
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
