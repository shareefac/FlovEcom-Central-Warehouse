<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\ApiTestCase;

/** Every POST carries an Idempotency-Key: replay = the stored answer; same key + other body = 422. */
final class ApiIdempotencyTest extends ApiTestCase
{
    public function testPostWithoutAValidKeyIs400AndStoresNothing(): void
    {
        [$site, $key] = $this->apiSite();
        $this->listing($site, 'V1', $this->item('strict', 5));
        $body = ['order_ref' => '1', 'lines' => [self::line('V1', 'a')]];
        $before = $this->fingerprint();
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $body, ''), 400, 'idempotency_key_required');
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $body, str_repeat('k', 192)), 400, 'bad_idempotency_key');
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $body, "k\u{e9}y"), 400, 'bad_idempotency_key');
        self::assertEnvelope($this->call('POST', '/v1/heartbeat', $key, ['outbox_depth' => 0], ''), 400, 'idempotency_key_required');
        self::assertSame($before, $this->fingerprint());
        // 191 printable characters is the longest key.
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $body, str_repeat('k', 191)), 201);
    }

    public function testReplayReturnsTheStoredAnswerByteForByte(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V2', $sku);
        $body = ['order_ref' => '1001', 'lines' => [self::line('V1', 'a', 'b'), self::line('V2', 'c')]];
        $first = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $body, 'R-1'), 201);
        self::assertFalse($first->replayed());
        $state = $this->fingerprint();

        $again = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $body, 'R-1'), 201);
        self::assertTrue($again->replayed());
        self::assertSame($first->raw, $again->raw, 'a replay is the stored answer, byte for byte');
        self::assertNotSame($first->header('x-request-id'), $again->header('x-request-id'));

        // Same request with the lines and unit ids in another order: still the same request (D28).
        $shuffled = ['lines' => [self::line('V2', 'c'), self::line('V1', 'b', 'a')], 'order_ref' => '1001'];
        $third = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, $shuffled, 'R-1'), 201);
        self::assertTrue($third->replayed());
        self::assertSame($first->raw, $third->raw);
        self::assertSame($state, $this->fingerprint(), 'replays had an effect');
        $this->assertBal(5, 0, 3, $sku);
    }

    public function testSameKeyWithAnotherBodyIs422AndKeysArePerChannel(): void
    {
        [$site, $key] = $this->apiSite();
        [$other, $otherKey] = $this->apiSite('vapebig');
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->listing($other, 'V1', $sku);
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => '1', 'lines' => [self::line('V1', 'a')]], 'K'), 201);
        $state = $this->fingerprint();

        $r = self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => '1', 'lines' => [self::line('V1', 'a', 'b')]], 'K'), 422, 'idempotency_key_reused');
        self::assertFalse($r->replayed());
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => '2', 'lines' => [self::line('V1', 'a')]], 'K'), 422, 'idempotency_key_reused');
        // The same key on another endpoint is another request too.
        self::assertEnvelope($this->call('POST', '/v1/reservations/1/commit', $key, ['lines' => [self::line('V1', 'a')]], 'K'), 422, 'idempotency_key_reused');
        self::assertEnvelope($this->call('POST', '/v1/heartbeat', $key, ['outbox_depth' => 1], 'K'), 422, 'idempotency_key_reused');
        self::assertSame($state, $this->fingerprint(), 'a reused key changed something');

        // Another site's identical key string is its own request.
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations', $otherKey, ['order_ref' => '1', 'lines' => [self::line('V1', 'x')]], 'K'), 201);
        self::assertFalse($r->replayed());
        $this->assertBal(5, 0, 2, $sku);
    }

    public function testConcurrentDuplicatesHaveOneEffect(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $reserve = ['order_ref' => '9', 'lines' => [self::line('V1', 'a', 'b', 'c')]];
        $calls = array_fill(0, 8, ['POST', '/v1/reservations', $key, $reserve, 'dup-reserve']);
        $answers = $this->parallel($calls);
        $data = null;
        foreach ($answers as $r) {
            self::assertEnvelope($r, 201);
            $data ??= $r->data();
            self::assertEquals($data, $r->data(), 'every duplicate gets the same answer');
        }
        self::assertSame(7, count(array_filter($answers, static fn ($r): bool => $r->replayed())), 'one effect, seven replays');
        $this->assertBal(10, 0, 3, $sku);

        $commit = ['lines' => [self::line('V1', 'a', 'b', 'c')]];
        $answers = $this->parallel(array_fill(0, 8, ['POST', '/v1/reservations/9/commit', $key, $commit, 'dup-commit']));
        foreach ($answers as $r) {
            self::assertEnvelope($r, 200);
            self::assertSame('committed', $r->data()['result']);
        }
        $this->assertBal(10, 3, 0, $sku);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM idempotency WHERE channel_id = ?', [$site->channelId]));
        self::assertSame(9, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE movement_type IN ('reserve', 'commit')"),
            '3 reserve rows (held+) and 6 commit rows (held-, allocated+): each unit moved once per step');
    }

    public function testRefusalsThatAreNotStoredCanBeRetriedWithTheSameKey(): void
    {
        [$site, $key] = $this->apiSite();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $ship = ['unit_ids' => ['a'], 'dispatched_at' => self::ago(3600)];
        // Unknown order and not-yet-paid order: nothing stored (D27, D36).
        self::assertEnvelope($this->call('POST', '/v1/reservations/5/ship', $key, $ship, 'S-1'), 404, 'unknown_order');
        self::assertEnvelope($this->call('POST', '/v1/reservations', $key, ['order_ref' => '5', 'lines' => [self::line('V1', 'a')]]), 201);
        self::assertEnvelope($this->call('POST', '/v1/reservations/5/ship', $key, $ship, 'S-1'), 409, 'not_committed');
        self::assertEnvelope($this->call('POST', '/v1/reservations/5/commit', $key, ['lines' => [self::line('V1', 'a')]]), 200);
        $r = self::assertEnvelope($this->call('POST', '/v1/reservations/5/ship', $key, $ship, 'S-1'), 200);
        self::assertFalse($r->replayed());
        self::assertSame('shipped', $r->data()['units'][0]['result']);
        $this->assertBal(4, 0, 0, $sku);
    }

    public function testEveryPostEndpointReplayedThreeTimesHasOneEffect(): void
    {
        [$site, $key] = $this->apiSite();
        $this->grant($site, 'goods_in');
        $sku = $this->item('strict', 20);
        $this->listing($site, 'V1', $sku);
        $calls = [
            ['/v1/reservations', ['order_ref' => '1', 'lines' => [self::line('V1', 'a', 'b', 'c', 'd')]]],
            ['/v1/reservations/2/release', ['attempt' => 1]],
            ['/v1/reservations/1/commit', ['lines' => [self::line('V1', 'a', 'b', 'c', 'd')], 'origin' => 'reserved']],
            ['/v1/reservations/1/ship', ['unit_ids' => ['a', 'b'], 'dispatched_at' => self::ago(7200)]],
            ['/v1/reservations/1/unship', ['unit_ids' => ['b'], 'at' => self::ago(3600)]],
            ['/v1/reservations/1/return', ['unit_ids' => ['a']]],
            ['/v1/reservations/1/cancel', ['unit_ids' => ['c'], 'restockable' => false]],
            ['/v1/opening_orders', ['orders' => [['order_ref' => '50', 'lines' => [self::line('V1', 'o1')]]], 'final' => true,
                't0' => ['at' => self::ago(600), 'last_order_id' => 49, 'last_stock_log_id' => 7]]],
            ['/v1/movements', ['type' => 'goods_in', 'doc_ref' => 'PINV-9', 'lines' => [['variant_id' => 'V1', 'qty' => 3]]]],
            ['/v1/heartbeat', ['outbox_depth' => 2, 'site_mode' => 'live']],
        ];
        foreach ($calls as $i => [$path, $body]) {
            $idem = 'three-' . $i;
            $first = $this->call('POST', $path, $key, $body, $idem);
            self::assertTrue($first->status < 300, $path . ': ' . $first->describe());
            $state = $this->fingerprint();
            for ($n = 0; $n < 2; $n++) {
                $again = $this->call('POST', $path, $key, $body, $idem);
                self::assertTrue($again->replayed(), $path);
                self::assertSame($first->status, $again->status, $path);
                self::assertSame($first->raw, $again->raw, $path);
                self::assertSame($state, $this->fingerprint(), "{$path}: a replay had an effect");
            }
        }
        // 20 + 3 goods-in; a shipped (-1), b unshipped, a returned (+1), c to VERIFY (-1); o1 allocated.
        $this->assertBal(22, 3, 0, $sku);
        $this->assertBal(1, 0, 0, $sku, 'VERIFY');
    }
}
