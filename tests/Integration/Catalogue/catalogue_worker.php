<?php

declare(strict_types=1);

/**
 * Concurrency-test worker for item cards and barcodes (spawned by CatalogueRaceTest): one process = one DB connection (admin login,
 * this slot's test schema). Reads a JSON job on stdin, waits for its start time, runs one operation and prints one JSON line. `hold_ms`
 * keeps the operation's transaction open that long before the commit, so the other worker meets its locks for certain.
 *
 * job = {start, op: 'save' | 'confirm' | 'sync' | 'add_barcode', staff_id?, sku_id?, version?, fields?, barcode?, hold_ms?}
 */

use CW\Caller;
use CW\Catalogue\BarcodeSync;
use CW\Catalogue\ItemBarcodes;
use CW\Catalogue\ItemCards;
use CW\CwException;
use CW\Db;
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
        $who = isset($job['staff_id']) ? Caller::staff((int) $job['staff_id']) : Caller::system('sync_barcodes');
        return match ($job['op']) {
            'save' => (new ItemCards($db))->save($who, (int) $job['sku_id'], (int) $job['version'], (array) $job['fields']),
            'confirm' => (new ItemCards($db))->confirm($who, (int) $job['sku_id'], (int) $job['version'], true),
            'sync' => (new BarcodeSync($db))->run($who, true),
            'add_barcode' => (new ItemBarcodes($db))->add($who, (int) $job['sku_id'], (string) $job['barcode']),
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
