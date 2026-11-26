<?php

declare(strict_types=1);

/**
 * Race worker for the card fees (spawned by GpFeesHealthTest while an invoice is spread again and again): the order snapshot job on this
 * slot's test schema, on its own connection. Reads a JSON job on stdin: {channel, refs, days: [YYYY-MM-DD, ...], until_ms, gap_ms}. Prints
 * "running", then until until_ms is up stores real snapshots (OrderSnapshots::store) of `refs` card orders in turn, each a newer version with
 * another amount and, in turn, another paid day of `days`; gap_ms between two. Last line: JSON {stores, failed, max_ms, errors} (failed: a
 * store that threw, a busy guard among them: a snapshot never fails or waits past its own wait because of a spread).
 */

use CW\Profit\OrderSnapshots;
use CW\Tests\Support\TestDb;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
date_default_timezone_set('UTC');

$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = TestDb::connect();
fwrite(STDOUT, "running\n");
fflush(STDOUT);
$until = microtime(true) + (int) $job['until_ms'] / 1000;
$ch = (int) $job['channel'];
$refs = max(1, (int) $job['refs']);
$days = array_values($job['days']);
$stores = 0;
$failed = 0;
$max = 0;
$errors = [];
for ($i = 0; microtime(true) < $until; $i++) {
    $ref = (string) (9300000 + $i % $refs);
    $paid = $days[intdiv($i, $refs) % count($days)] . 'T10:00:00Z';
    $body = ['v' => 1, 'version' => 1000 + intdiv($i, $refs), 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay',
        'currency' => 'GBP', 'parent_ref' => null, 'lines' => [], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 1000 + ($i * 37) % 5000, 'parcels' => []];
    $t = microtime(true);
    try {
        (new OrderSnapshots($db))->store($ch, $ref, $body, 'api');
        $stores++;
    } catch (\Throwable $e) {
        $failed++;
        $k = get_class($e) . ($e instanceof \CW\CwException ? ':' . $e->errorCode : '');
        $errors[$k] = ($errors[$k] ?? 0) + 1;
    }
    $max = max($max, (int) round((microtime(true) - $t) * 1000));
    usleep((int) ($job['gap_ms'] ?? 20) * 1000);
}
fwrite(STDOUT, json_encode(['stores' => $stores, 'failed' => $failed, 'max_ms' => $max, 'errors' => $errors], JSON_THROW_ON_ERROR) . "\n");
