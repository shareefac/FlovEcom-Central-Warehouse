<?php

declare(strict_types=1);

/**
 * Concurrency-test worker for pack B (spawned by ItemsRaceTest): one process = one DB connection (admin login, this slot's test schema).
 * Reads a JSON job on stdin, waits for its start time, runs one operation and prints one JSON line. `hold_ms` keeps the operation's
 * transaction open that long before the commit, so the other worker meets its locks for certain.
 *
 * job = {start, op: 'create' | 'bulk_assign' | 'assign' | 'lock_item', staff_id, card?, family_id?, list?, sku_id?, hold_ms?}
 * lock_item: locks one item's sku row (FOR UPDATE) for hold_ms: another transaction's insert that names that item (a foreign key)
 * waits for it there, which puts that transaction between its read and its writes for a known time.
 */

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Items\Families;
use CW\Items\HandMade;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
@time_sleep_until((float) $job['start']);
$hold = (int) ($job['hold_ms'] ?? 0);
$started = microtime(true);
try {
    $run = static function () use ($db, $job): array {
        $who = Caller::staff((int) $job['staff_id']);
        return match ($job['op']) {
            'create' => (new HandMade($db))->create($who, ['card' => (array) $job['card']]),
            'bulk_assign' => (new Families($db))->bulkAssign($who, (int) $job['family_id'], (string) $job['list']),
            'assign' => (new Families($db))->assign($who, (int) $job['sku_id'], (int) $job['family_id'], null),
            'lock_item' => ['locked' => (int) $db->value('SELECT id FROM sku WHERE id = ? FOR UPDATE', [(int) $job['sku_id']])],
            default => throw new \InvalidArgumentException('unknown op'),
        };
    };
    $r = $hold > 0 ? $db->transaction(static function () use ($run, $hold): array {
        $r = $run();
        usleep($hold * 1000);
        return $r;
    }) : $run();
    $out = ['status' => 200, 'result' => $r];
} catch (CwException $e) {
    $out = ['status' => $e->httpStatus, 'error' => $e->errorCode];
} catch (\Throwable $e) {
    $out = ['status' => 500, 'error' => get_class($e) . ': ' . $e->getMessage()];
}
$out['ms'] = (int) round((microtime(true) - $started) * 1000);
$out['deadlocks'] = Db::deadlockRetries();
echo json_encode($out, JSON_THROW_ON_ERROR), "\n";
