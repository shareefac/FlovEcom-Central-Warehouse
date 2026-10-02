<?php

declare(strict_types=1);

/**
 * Concurrency-test worker for the document base (spawned by tests/Support/Documents/DocWorkerPool, at most 12): one
 * process = one DB connection (admin login, this slot's test schema). Reads a JSON job on stdin, waits for the common
 * start time, runs its operations in order and prints one JSON line: per-op results and how many deadlocks
 * Db::transaction() retried. Never touches the schema.
 *
 * job = {start: float unix time, ops: [...]}
 * op  = {op: 'series', prefix, count, rollback_every?}   numbers taken in `count` transactions of their own; every
 *                                                         rollback_every-th is rolled back after taking its number
 *     | {op: 'post', staff_id, lines, header?}           createDraft + setLines + post of a fixture ADJ document
 *     | {op: 'reverse', staff_id, document_id, reason_code, note?}
 *     | {op: 'move', staff_id, request, key}             Movements::record as that staff user
 */

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Documents\Documents;
use CW\Documents\NumberSeries;
use CW\Movements;
use CW\Tests\Support\Documents\FixtureAdjustmentHandler;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
$docs = new Documents($db, ['ADJ' => new FixtureAdjustmentHandler($db)]);
$series = new NumberSeries($db);
$moves = new Movements($db);
if (isset($job['start'])) {
    @time_sleep_until((float) $job['start']);
}
$out = [];
foreach ($job['ops'] as $op) {
    try {
        switch ($op['op']) {
            case 'series':
                $numbers = [];
                $rolledBack = 0;
                for ($i = 1; $i <= (int) $op['count']; $i++) {
                    $rollback = isset($op['rollback_every']) && $i % (int) $op['rollback_every'] === 0;
                    try {
                        $no = $db->transaction(static function () use ($series, $op, $rollback): string {
                            $no = $series->next((string) $op['prefix']);
                            if ($rollback) {
                                throw new \DomainException('roll back ' . $no);
                            }
                            return $no;
                        });
                        $numbers[] = $no;
                    } catch (\DomainException) {
                        $rolledBack++;
                    }
                }
                $out[] = ['op' => 'series', 'status' => 200, 'numbers' => $numbers, 'rolled_back' => $rolledBack];
                break;
            case 'post':
                $caller = Caller::staff((int) $op['staff_id']);
                $d = $docs->createDraft($caller, 'ADJ', $op['header'] ?? []);
                $d = $docs->setLines($caller, $d->id, $d->version, $op['lines']);
                $d = $docs->post($caller, $d->id, $d->version);
                $out[] = ['op' => 'post', 'status' => 200, 'id' => $d->id, 'number' => $d->number, 'state' => $d->status];
                break;
            case 'reverse':
                $r = $docs->reverse(Caller::staff((int) $op['staff_id']), (int) $op['document_id'], (string) $op['reason_code'], $op['note'] ?? null);
                $out[] = ['op' => 'reverse', 'status' => 200, 'id' => $r->id, 'number' => $r->number];
                break;
            case 'move':
                $r = $moves->record(Caller::staff((int) $op['staff_id']), $op['request'], (string) $op['key']);
                $out[] = ['op' => 'move', 'status' => $r->status, 'result' => $r->body['result'] ?? $r->body['error'] ?? null];
                break;
            default:
                throw new \InvalidArgumentException('unknown op');
        }
    } catch (CwException $e) {
        $out[] = ['op' => $op['op'], 'status' => $e->httpStatus, 'error' => $e->errorCode, 'message' => $e->getMessage()];
    } catch (\Throwable $e) {
        $out[] = ['op' => $op['op'], 'status' => 500, 'error' => get_class($e) . ': ' . $e->getMessage()];
    }
}
echo json_encode(['results' => $out, 'deadlocks' => Db::deadlockRetries()], JSON_THROW_ON_ERROR), "\n";
