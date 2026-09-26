<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\OpResult;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\WorkerPool;

/** §14: "every call replayed 3x (also concurrently) -> one effect". */
final class IdempotencyTest extends StockTestCase
{
    /**
     * Runs $call three times with one key; asserts one effect (ledger, change feed and
     * queues untouched by the replays) and the same stored answer each time.
     *
     * @param callable(string): OpResult $call
     */
    private function thrice(string $key, callable $call): OpResult
    {
        $first = $call($key);
        $state = $this->fingerprint();
        for ($i = 0; $i < 2; $i++) {
            $again = $call($key);
            self::assertTrue($again->replayed, "{$key}: replay {$i} must come from the idempotency table");
            self::assertSame($first->status, $again->status, $key);
            self::assertEquals($first->body, $again->body, $key);
            self::assertSame($state, $this->fingerprint(), "{$key}: a replay changed something");
        }
        self::assertFalse($first->replayed);
        return $first;
    }

    /** @return array<string, int> */
    private function fingerprint(): array
    {
        $out = [];
        foreach (['stock_ledger', 'stock_change', 'reservation', 'reservation_unit', 'oversell_event', 'count_review', 'goods_in_suspense', 'idempotency'] as $t) {
            $out[$t] = (int) self::$db->value("SELECT COUNT(*) FROM {$t}");
        }
        $out['balances'] = crc32(json_encode(self::$db->all('SELECT * FROM stock_balance ORDER BY warehouse_id, sku_id')));
        $out['units'] = crc32(json_encode(self::$db->all('SELECT channel_id, unit_id, state FROM reservation_unit ORDER BY channel_id, unit_id')));
        return $out;
    }

    public function testEveryOperationReplayedThreeTimesHasOneEffect(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 3);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V2', $this->item('legacy', 0));
        $lines = [self::line('V1', 'a', 'b'), self::line('V2', 'c')];

        $this->thrice('reserve-1', fn (string $k) => $this->res->reserve($site, '1', $lines, $k));
        $this->thrice('release-1', fn (string $k) => $this->res->release($site, '1', 1, $k));
        $this->thrice('reserve-1b', fn (string $k) => $this->res->reserve($site, '1', $lines, $k));
        $this->thrice('reserve-short', fn (string $k) => $this->res->reserve($site, '9', [self::line('V1', 'z1', 'z2')], $k));
        $this->thrice('commit-1', fn (string $k) => $this->res->commit($site, '1', $lines, 'reserved', $k));
        $this->thrice('commit-2', fn (string $k) => $this->res->commit($site, '2', [self::line('V1', 'd', 'e')], 'unreserved', $k));
        $this->thrice('release-2', fn (string $k) => $this->res->release($site, '2', null, $k));
        $this->thrice('tombstone-3', fn (string $k) => $this->res->release($site, '3', 1, $k));
        $this->thrice('cancel-1', fn (string $k) => $this->res->cancel($site, '1', ['b'], false, $k));
        $this->thrice('ship-1', fn (string $k) => $this->res->ship($site, '1', ['a', 'c'], '2026-09-26T12:00:00Z', $k));
        $this->thrice('unship-1', fn (string $k) => $this->res->unship($site, '1', ['a'], '2026-09-26T12:05:00Z', $k));
        $this->thrice('reship-1', fn (string $k) => $this->res->ship($site, '1', ['a'], '2026-09-26T12:10:00Z', $k));
        $this->thrice('return-1', fn (string $k) => $this->res->returnUnits($site, '1', ['a'], $k));
        $this->thrice('goods-in', fn (string $k) => $this->moves->record($site, ['type' => 'goods_in', 'doc_ref' => 'PINV-1',
            'lines' => [['variant_id' => 'V1', 'qty' => 4], ['variant_id' => 'NOPE', 'qty' => 1]]], $k));
        $this->thrice('count', fn (string $k) => $this->moves->record(self::staff(), ['type' => 'count', 'counted_at' => '2026-09-26T12:30:00Z',
            'lines' => [['sku_id' => $sku, 'qty' => 6]]], $k));
        $this->thrice('policy', fn (string $k) => $this->stock->setPolicy(self::staff(), $sku, 'backorder', $k));
        $this->thrice('opening', fn (string $k) => $this->res->openingOrders($site, [['order_ref' => '50', 'lines' => [self::line('V1', 'o1')]]], true, $k,
            ['at' => '2026-09-26T17:00:00Z', 'last_order_id' => 49, 'last_stock_log_id' => null]));

        // on_hand: the count at 12:30 found 6 (no ship dispatched after it); allocated: d, e of
        // order 2 and the opening unit o1 (a shipped and came back, b went to VERIFY)
        $this->assertBal(6, 3, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
    }

    public function testTheSameKeyWithAnotherBodyIs422AndChangesNothing(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '1', [self::line('V1', 'a')], 'k');
        $r = $this->reserve($site, '1', [self::line('V1', 'a', 'b')], 'k');
        self::assertSame([422, 'idempotency_key_reused'], [$r->status, $r->body['error']]);
        $r = $this->commit($site, '1', [self::line('V1', 'a')], 'reserved', 'k');
        self::assertSame(422, $r->status, 'a key is one request, whatever the operation');
        $this->assertBal(5, 0, 1, $sku);
    }

    public function testConcurrentReplaysOfEveryOperationHaveOneEffect(): void
    {
        $site = $this->site();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $lines = [self::line('V1', 'a', 'b', 'c')];
        $base = ['channel_id' => $site->channelId, 'channel_code' => 'vpg', 'order_ref' => '77'];
        $phases = [
            'reserve' => ['op' => 'reserve', 'lines' => $lines],
            'release' => ['op' => 'release', 'attempt' => 1],
            'reserve-again' => ['op' => 'reserve', 'lines' => $lines],
            'commit' => ['op' => 'commit', 'lines' => $lines, 'origin' => 'reserved'],
            'ship' => ['op' => 'ship', 'unit_ids' => ['a', 'b'], 'dispatched_at' => '2026-09-26T12:00:00Z'],
            'unship' => ['op' => 'unship', 'unit_ids' => ['a'], 'at' => '2026-09-26T12:05:00Z'],
            'cancel' => ['op' => 'cancel', 'unit_ids' => ['c'], 'restockable' => false],
            'return' => ['op' => 'return', 'unit_ids' => ['b']],
            'goods-in' => ['op' => 'move', 'order_ref' => 'PINV-77', 'request' => ['type' => 'goods_in', 'doc_ref' => 'PINV-77',
                'lines' => [['variant_id' => 'V1', 'qty' => 4], ['variant_id' => 'NOPE', 'qty' => 2]]]],
        ];
        foreach ($phases as $name => $op) {
            $out = WorkerPool::run(array_fill(0, 6, [$op + ['key' => "{$name}-77"] + $base]), 1.0);
            $statuses = array_unique(array_column($out['results'], 'status'));
            $hashes = array_unique(array_column($out['results'], 'body_hash'));
            self::assertCount(1, $statuses, "{$name}: " . json_encode($out['results']));
            self::assertContains($statuses[0], [200, 201], "{$name}: " . json_encode($out['results']));
            self::assertCount(1, $hashes, "{$name}: every replay returns the stored answer");
            self::assertSame(1, count(array_filter($out['results'], static fn (array $r): bool => $r['replayed'] === false)), "{$name}: exactly one execution");
        }
        // reserve 3, release 3, reserve 3, commit 3, ship 2, unship 1, cancel 1 to VERIFY, return 1, goods-in 4
        $this->assertBal(13, 1, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
        self::assertSame(2, (int) $this->reservation($site, '77')['attempt']);
        $counts = [];
        foreach (self::$db->all('SELECT movement_type, COUNT(*) AS n FROM stock_ledger GROUP BY movement_type') as $r) {
            $counts[$r['movement_type']] = (int) $r['n'];
        }
        ksort($counts);
        self::assertSame(['cancel' => 1, 'commit' => 6, 'goods_in' => 2, 'release' => 3, 'reserve' => 6, 'return' => 1,
            'ship' => 4, 'transfer_in' => 1, 'transfer_out' => 1, 'unship' => 2], $counts, 'goods_in: the fixture + the relay');
        self::assertSame(1, self::$db->value('SELECT COUNT(*) FROM goods_in_suspense'));
        self::assertSame(1, self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'verify_recount'"));
    }

    public function testConcurrentFirstCallsForOneOrderUnderDifferentKeysHoldOnce(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $jobs = [];
        for ($i = 0; $i < 6; $i++) {
            $jobs[] = [['op' => 'reserve', 'channel_id' => $site->channelId, 'channel_code' => 'vpg', 'order_ref' => '88',
                'lines' => [self::line('V1', 'a', 'b')], 'key' => "retry-{$i}"]];
        }
        $out = WorkerPool::run($jobs);
        $results = array_count_values(array_map(static fn (array $r): string => $r['status'] . ':' . $r['result'], $out['results']));
        ksort($results);
        self::assertSame(['200:extended' => 5, '201:held' => 1], $results, json_encode($out['results']));
        $this->assertBal(10, 0, 2, $sku);
    }
}
