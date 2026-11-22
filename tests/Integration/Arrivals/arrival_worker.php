<?php

declare(strict_types=1);

/**
 * Concurrency-test worker for pack J (spawned by ArrivalsRaceTest, two at a time): one process = one DB connection (this slot's test
 * schema). Reads a JSON job on stdin, waits for its start time, runs one operation and prints one JSON line with its result.
 * `hold_ms` keeps the operation's transaction open (its locks held) that long before the commit, so the other worker meets the
 * locks for certain.
 *
 * job = {start: float unix time, op: 'post_invoice' | 'post_receipt' | 'reserve', staff_id?, document_id?, version?, channel_id?,
 *        channel_code?, order_ref?, lines?, key?, hold_ms?, after_lock?}
 * `after_lock` (a table): after its start time the worker waits until another session holds a row lock on that table (the other
 * worker's posting has reached its stock), so the order of the two is certain however slow the machine is.
 *  post_invoice  SupplierInvoices::submit (the invoice made final: "count on arrival" books its units into Incoming)
 *  post_receipt  GoodsReceipts::post (a receipt made from an invoice: Incoming to on hand, the missing units out)
 *  reserve       Reservations::reserve as the site (a checkout hold: refused under the strict policy when short)
 */

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Invoices\SupplierInvoices;
use CW\OpResult;
use CW\Receiving\GoodsReceipts;
use CW\Reservations;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
$docs = new Documents($db, DocumentHandlers::all($db));
$now = new DateTimeImmutable(StockTestCase::NOW, Clock::utc());
$res = new Reservations($db, null, static fn (): DateTimeImmutable => $now);
@time_sleep_until((float) $job['start']);
if (isset($job['after_lock'])) {
    $until = microtime(true) + 30.0;
    while ((int) $db->value("SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA = DATABASE() AND OBJECT_NAME = ? AND LOCK_TYPE = 'RECORD' "
        . "AND LOCK_STATUS = 'GRANTED'", [(string) $job['after_lock']]) === 0) {
        if (microtime(true) > $until) {
            echo json_encode(['status' => 500, 'error' => "no lock on {$job['after_lock']} within 30 s", 'ms' => 0, 'deadlocks' => 0], JSON_THROW_ON_ERROR), "\n";
            exit(0);
        }
        usleep(2_000);
    }
}
$hold = (int) ($job['hold_ms'] ?? 0);
$started = microtime(true);
try {
    $run = static function () use ($db, $job, $docs, $res): array {
        return match ($job['op']) {
            'post_invoice' => ['id' => (new SupplierInvoices($db, $docs))->submit(Caller::staff((int) $job['staff_id']), (int) $job['document_id'], (int) $job['version'])->id],
            'post_receipt' => ['id' => (new GoodsReceipts($db, $docs))->post(Caller::staff((int) $job['staff_id']), (int) $job['document_id'], (int) $job['version'])->id],
            'reserve' => ['op' => $res->reserve(Caller::channel((int) $job['channel_id'], (string) $job['channel_code']), (string) $job['order_ref'], $job['lines'],
                (string) $job['key'])],
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
    if (isset($row['op']) && $row['op'] instanceof OpResult) {
        $out = ['status' => $row['op']->status, 'error' => $row['op']->body['error'] ?? null, 'reasons' => $row['op']->body['reasons'] ?? null];
    } else {
        $out = ['status' => 200, 'id' => (int) $row['id']];
    }
} catch (CwException $e) {
    $out = ['status' => $e->httpStatus, 'error' => $e->errorCode];
} catch (\Throwable $e) {
    $out = ['status' => 500, 'error' => get_class($e) . ': ' . $e->getMessage()];
}
$out['ms'] = (int) round((microtime(true) - $started) * 1000);
$out['deadlocks'] = Db::deadlockRetries();
echo json_encode($out, JSON_THROW_ON_ERROR), "\n";
