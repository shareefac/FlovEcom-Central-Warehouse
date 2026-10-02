<?php

declare(strict_types=1);

/**
 * Concurrency-test worker (spawned by WorkerPool): one process = one DB connection = one
 * simulated site worker. Reads a JSON job on stdin, waits for the common start time, runs
 * its operations in order and prints one JSON line: per-op status/result and how many
 * deadlocks Db::transaction() retried. Never touches the schema (no bootstrap).
 *
 * job = {start: float unix time, now?: CW's clock (default StockTestCase::NOW), ops: [{op, channel_id, channel_code, order_ref, lines?, origin?,
 *        attempt?, unit_ids?, dispatched_at?, at?, restockable?, request?, key, caller?}]}
 * op = reserve | commit | release | ship | unship | cancel | uncancel | return | move (Movements::record)
 * caller = 'staff' runs the op as Caller::staff(1) (staff movements: counts, adjustments, transfers, costs);
 *          otherwise the op runs as the channel channel_id/channel_code.
 */

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Movements;
use CW\Reservations;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
// CW's clock: the stock tests' fixed one (StockTestCase::NOW) unless the job names another.
$now = new DateTimeImmutable((string) ($job['now'] ?? StockTestCase::NOW), Clock::utc());
$clock = static fn (): DateTimeImmutable => $now;
$res = new Reservations($db, null, $clock);
$moves = new Movements($db, null, $clock);
if (isset($job['start'])) {
    @time_sleep_until((float) $job['start']);
}
$out = [];
foreach ($job['ops'] as $op) {
    $caller = ($op['caller'] ?? null) === 'staff' ? Caller::staff(1) : Caller::channel((int) $op['channel_id'], (string) $op['channel_code']);
    try {
        $r = match ($op['op']) {
            'reserve' => $res->reserve($caller, (string) $op['order_ref'], $op['lines'], (string) $op['key']),
            'commit' => $res->commit($caller, (string) $op['order_ref'], $op['lines'], (string) ($op['origin'] ?? 'reserved'), (string) $op['key']),
            'release' => $res->release($caller, (string) $op['order_ref'], $op['attempt'] ?? null, (string) $op['key']),
            'ship' => $res->ship($caller, (string) $op['order_ref'], $op['unit_ids'], (string) $op['dispatched_at'], (string) $op['key']),
            'unship' => $res->unship($caller, (string) $op['order_ref'], $op['unit_ids'], $op['at'] ?? null, (string) $op['key']),
            'cancel' => $res->cancel($caller, (string) $op['order_ref'], $op['unit_ids'], (bool) $op['restockable'], (string) $op['key']),
            'uncancel' => $res->uncancel($caller, (string) $op['order_ref'], $op['unit_ids'], (string) $op['key']),
            'return' => $res->returnUnits($caller, (string) $op['order_ref'], $op['unit_ids'], (string) $op['key']),
            'move' => $moves->record($caller, $op['request'], (string) $op['key']),
            default => throw new \InvalidArgumentException('unknown op'),
        };
        $out[] = ['order_ref' => $op['order_ref'] ?? null, 'status' => $r->status, 'replayed' => $r->replayed,
            'result' => $r->body['result'] ?? $r->body['error'] ?? null, 'body_hash' => hash('sha256', json_encode(\CW\Idempotency::canonical($r->body)))];
    } catch (CwException $e) {
        $out[] = ['order_ref' => $op['order_ref'] ?? null, 'status' => $e->httpStatus, 'error' => $e->errorCode];
    } catch (\Throwable $e) {
        $out[] = ['order_ref' => $op['order_ref'] ?? null, 'status' => 500, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
}
echo json_encode(['results' => $out, 'deadlocks' => Db::deadlockRetries()], JSON_THROW_ON_ERROR), "\n";
