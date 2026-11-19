<?php

declare(strict_types=1);

/**
 * Concurrency-test worker for purchase orders (spawned by PurchaseOrderRaceTest, two at a time): one process = one DB
 * connection (admin login, this slot's test schema). Reads a JSON job on stdin, waits for its start time, runs one
 * operation and prints one JSON line with its result. `hold_ms` keeps the operation's transaction open (its locks held)
 * that long before the commit, so the other worker meets the locks for certain.
 *
 * job = {start: float unix time, op: 'approve' | 'create_once' | 'deactivate' | 'live_sets' | 'live_set' | 'live_add', staff_id, document_id?,
 *        version?, supplier_id?, form_key?, hold_ms?, uid?, rev?, packs?, values?, si?, key?}
 * create_once runs the PO creation exactly as Ui\FormOnce does (Idempotency::run under "ui:<staff id>:<form_key>").
 */

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Idempotency;
use CW\OpResult;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
$caller = Caller::staff((int) $job['staff_id']);
$pos = new PurchaseOrders($db, new Documents($db, DocumentHandlers::all($db)));
@time_sleep_until((float) $job['start']);
$hold = (int) ($job['hold_ms'] ?? 0);
$started = microtime(true);
try {
    $run = static function () use ($db, $job, $caller, $pos): array {
        return match ($job['op']) {
            'approve' => ['id' => $pos->approve($caller, (int) $job['document_id'], (int) $job['version'])->id],
            'deactivate' => (new Suppliers($db))->deactivate($caller, (int) $job['supplier_id'], (int) $job['version'], 'race'),
            default => throw new \InvalidArgumentException('unknown op'),
        };
    };
    if (str_starts_with((string) $job['op'], 'live_')) {
        // Each box saved by itself (docs/decisions.md LS1-LS14): live_sets saves a line's packs to each value in turn, each with the line's version read
        // just before (as a page that saw it); live_set and live_add are one save (the line's version as given; held open hold_ms when asked).
        $doc = (int) $job['document_id'];
        $one = static function () use ($pos, $caller, $job, $doc): array {
            $r = $job['op'] === 'live_add'
                ? $pos->addLineLive($caller, $doc, ['supplier_item_id' => (int) $job['si']], (int) $job['packs'], null, (string) $job['key'])
                : $pos->setLine($caller, $doc, (int) $job['uid'], (int) $job['rev'], ['packs' => (string) $job['packs']]);
            return ['result' => $r['status'], 'uid' => $r['uid'] ?? null];
        };
        if ($job['op'] === 'live_sets') {
            $results = [];
            foreach ($job['values'] as $v) {
                $rev = (int) $db->value('SELECT rev FROM document_line WHERE document_id = ? AND uid = ?', [$doc, (int) $job['uid']]);
                try {
                    $results[] = $pos->setLine($caller, $doc, (int) $job['uid'], $rev, ['packs' => (string) $v])['status'];
                } catch (CwException $e) {
                    $results[] = $e->errorCode;
                }
            }
            $out = ['status' => 200, 'results' => $results];
        } else {
            $row = $hold > 0 ? $db->transaction(static function () use ($one, $hold): array {
                $r = $one();
                usleep($hold * 1000);
                return $r;
            }) : $one();
            $out = ['status' => 200] + $row;
        }
    } elseif ($job['op'] === 'create_once') {
        $r = (new Idempotency($db))->run($caller, "ui:{$caller->staffUserId}:{$job['form_key']}", 'ui.po.create', '/ui/purchasing/orders',
            ['supplier_id' => (int) $job['supplier_id']], null, null, static function (Db $db) use ($pos, $caller, $job, $hold): OpResult {
                $d = $pos->createDraft($caller, (int) $job['supplier_id'], []);
                usleep($hold * 1000);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id, 'redirect' => '/ui/purchasing/orders/' . $d->id]);
            });
        $out = ['status' => 200, 'id' => (int) $r->body['document_id'], 'replayed' => $r->replayed];
    } else {
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
    }
} catch (CwException $e) {
    $out = ['status' => $e->httpStatus, 'error' => $e->errorCode];
} catch (\Throwable $e) {
    $out = ['status' => 500, 'error' => get_class($e) . ': ' . $e->getMessage()];
}
$out['ms'] = (int) round((microtime(true) - $started) * 1000);
$out['deadlocks'] = Db::deadlockRetries();
echo json_encode($out, JSON_THROW_ON_ERROR), "\n";
