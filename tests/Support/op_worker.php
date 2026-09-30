<?php

declare(strict_types=1);

/**
 * Lock-order test worker (tests/Integration/Stock/LockOrderTest.php, LinkAdoptionTest.php): one
 * process = one DB connection. Reads a JSON job on stdin, runs its ops in order and prints one
 * JSON line with, per op, the status/result, the elapsed wall time and the deadlocks
 * Db::transaction() retried so far. Unlike tests/Support/worker.php it also runs opening orders,
 * listing pushes, staff movements, and can wait for a row lock to appear (orchestration).
 *
 * job = {now?: CW's clock (default StockTestCase::NOW), ops: [{op, channel_id?, channel_code?, key?, ...}]}
 * op  = reserve | commit | release | ship | opening | listings | move | decide (staff_id, request) | wait_lock
 */

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\Mapping\ListingIngestService;
use CW\Movements;
use CW\Reservations;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
$now = new DateTimeImmutable((string) ($job['now'] ?? StockTestCase::NOW), Clock::utc());
$clock = static fn (): DateTimeImmutable => $now;
$res = new Reservations($db, null, $clock);
$moves = new Movements($db, null, $clock);

/** Polls performance_schema until some session holds/waits for a row lock on $table. */
function waitLock(Db $db, string $table, string $status, float $timeout, int $intervalMs = 2): array
{
    $until = microtime(true) + $timeout;
    while (microtime(true) < $until) {
        $n = (int) $db->value(
            "SELECT COUNT(*) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA = DATABASE() AND OBJECT_NAME = ? "
            . "AND LOCK_TYPE = 'RECORD' AND LOCK_STATUS = ?",
            [$table, $status],
        );
        if ($n > 0) {
            return ['found' => $n];
        }
        usleep($intervalMs * 1_000); // each poll walks every lock of the server: do not hammer a big transaction
    }
    throw new \RuntimeException("no {$status} lock on {$table} within {$timeout}s");
}

$out = [];
foreach ($job['ops'] as $op) {
    $caller = isset($op['channel_id']) ? Caller::channel((int) $op['channel_id'], (string) $op['channel_code']) : Caller::staff(1);
    $t0 = hrtime(true);
    $row = ['op' => $op['op'], 'order_ref' => $op['order_ref'] ?? null];
    try {
        $r = match ($op['op']) {
            'reserve' => $res->reserve($caller, (string) $op['order_ref'], $op['lines'], (string) $op['key']),
            'commit' => $res->commit($caller, (string) $op['order_ref'], $op['lines'], (string) ($op['origin'] ?? 'reserved'), (string) $op['key']),
            'release' => $res->release($caller, (string) $op['order_ref'], $op['attempt'] ?? null, (string) $op['key']),
            'ship' => $res->ship($caller, (string) $op['order_ref'], $op['unit_ids'], (string) $op['dispatched_at'], (string) $op['key']),
            'opening' => $res->openingOrders($caller, $op['orders'], (bool) $op['final'], (string) $op['key'], $op['t0'] ?? null),
            'listings' => (new ListingIngestService($db))->push($caller, $op['listings']),
            'decide' => (new DecisionService($db, null, $res))->decide(Caller::staff((int) $op['staff_id']), $op['request']),
            'move' => $moves->record($caller, $op['request'], (string) $op['key']),
            'wait_lock' => waitLock($db, (string) $op['table'], (string) $op['status'], (float) ($op['timeout'] ?? 30.0), (int) ($op['interval_ms'] ?? 2)),
            default => throw new \InvalidArgumentException('unknown op'),
        };
        if (is_array($r) && isset($r['found'])) {
            $row += ['status' => 200, 'result' => 'lock_seen'];
        } elseif (is_array($r) && isset($r['decision_id'])) {
            $row += ['status' => 200, 'result' => $r['state'], 'body' => $r];
        } elseif (is_array($r)) {
            $row += ['status' => 200, 'result' => 'pushed', 'body' => ['received' => $r['received'], 'created' => $r['created']]];
        } else {
            $row += ['status' => $r->status, 'replayed' => $r->replayed, 'result' => $r->body['result'] ?? $r->body['error'] ?? null, 'body' => $r->body];
        }
    } catch (CwException $e) {
        $row += ['status' => $e->httpStatus, 'error' => $e->errorCode];
    } catch (\Throwable $e) {
        $row += ['status' => 500, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
    $row['elapsed_ms'] = intdiv(hrtime(true) - $t0, 1_000_000);
    $row['deadlocks'] = Db::deadlockRetries();
    $out[] = $row;
}
echo json_encode(['results' => $out, 'deadlocks' => Db::deadlockRetries()], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
