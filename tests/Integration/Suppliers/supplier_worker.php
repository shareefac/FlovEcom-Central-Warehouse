<?php

declare(strict_types=1);

/**
 * Concurrency-test worker for suppliers (spawned by SupplierRaceTest, two at a time): one process = one DB connection
 * (admin login, this slot's test schema). Reads a JSON job on stdin, waits for its start time, runs one operation and
 * prints one JSON line with its result. `hold_ms` keeps the operation's transaction open (its locks held) that long
 * before the commit, so the other worker meets the locks for certain.
 *
 * job = {start: float unix time, op: 'approve' | 'set_preferred', staff_id, task_id?, supplier_item_id?, hold_ms?}
 */

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
$caller = Caller::staff((int) $job['staff_id']);
@time_sleep_until((float) $job['start']);
$hold = (int) ($job['hold_ms'] ?? 0);
$started = microtime(true);
try {
    $run = static function () use ($db, $job, $caller): array {
        return match ($job['op']) {
            'approve' => (new Suppliers($db))->approve($caller, (int) $job['task_id'], 'race'),
            'set_preferred' => (new SupplierItems($db))->setPreferred($caller, (int) $job['supplier_item_id']),
            default => throw new \InvalidArgumentException('unknown op'),
        };
    };
    if ($hold > 0) {
        $row = $db->transaction(static function () use ($run, $hold): array {
            $r = $run();
            usleep($hold * 1000);
            return $r;
        });
    } else {
        $row = $run();
    }
    $out = ['status' => 200, 'id' => (int) $row['id']];
} catch (CwException $e) {
    $out = ['status' => $e->httpStatus, 'error' => $e->errorCode];
} catch (\Throwable $e) {
    $out = ['status' => 500, 'error' => get_class($e) . ': ' . $e->getMessage()];
}
$out['ms'] = (int) round((microtime(true) - $started) * 1000);
$out['deadlocks'] = Db::deadlockRetries();
echo json_encode($out, JSON_THROW_ON_ERROR), "\n";
