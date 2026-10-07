<?php

declare(strict_types=1);

/**
 * Concurrency-test worker for goods receipts (spawned by GoodsReceiptRaceTest, two at a time): one process = one DB connection
 * (admin login, this slot's test schema). Reads a JSON job on stdin, waits for its start time, runs one operation and prints one
 * JSON line with its result. `hold_ms` keeps the operation's transaction open (its locks held) that long before the commit, so
 * the other worker meets the locks for certain.
 *
 * job = {start: float unix time, op: 'post' | 'create' | 'create_once' | 'close_po', staff_id, document_id?, version?, supplier_id?,
 *        invoice?, po_id?, form_key?, hold_ms?}
 * create_once runs the receipt creation exactly as Ui\FormOnce does (Idempotency::run under "ui:<staff id>:<form_key>").
 */

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Idempotency;
use CW\OpResult;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Receiving\GoodsReceipts;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
$caller = Caller::staff((int) $job['staff_id']);
$docs = new Documents($db, DocumentHandlers::all($db));
$grns = new GoodsReceipts($db, $docs);
@time_sleep_until((float) $job['start']);
$hold = (int) ($job['hold_ms'] ?? 0);
$started = microtime(true);
try {
    $run = static function () use ($db, $job, $caller, $docs, $grns): array {
        return match ($job['op']) {
            'post' => ['id' => $grns->post($caller, (int) $job['document_id'], (int) $job['version'])->id],
            'create' => ['id' => $grns->createDraft($caller, (int) $job['supplier_id'], ['invoice_number' => (string) $job['invoice']])->id],
            'close_po' => ['id' => (new PurchaseOrders($db, $docs))->close($caller, (int) $job['po_id'], (int) $job['version'], 'race: the rest is not coming')->id],
            default => throw new \InvalidArgumentException('unknown op'),
        };
    };
    if ($job['op'] === 'create_once') {
        $r = (new Idempotency($db))->run($caller, "ui:{$caller->staffUserId}:{$job['form_key']}", 'ui.grn.create', '/ui/receiving',
            ['supplier_id' => (int) $job['supplier_id'], 'po_id' => null, 'invoice_number' => (string) $job['invoice'], 'copy' => false], null, null,
            static function (Db $db) use ($grns, $caller, $job, $hold): OpResult {
                $d = $grns->createDraft($caller, (int) $job['supplier_id'], ['invoice_number' => (string) $job['invoice']]);
                usleep($hold * 1000);
                return OpResult::of(303, ['result' => 'created', 'document_id' => $d->id, 'redirect' => '/ui/receiving/' . $d->id]);
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
