<?php

declare(strict_types=1);

namespace CW\Tests\Integration\ApiKernel;

use CW\Tests\Support\ApiKernelTestCase;

/**
 * A15 (F5): GET /v1/changes carries head_seq, so a site sees on every poll that CW's feed went back
 * (a restore). A16 (F6): POST /v1/heartbeat needs an Idempotency-Key like every POST; the same key
 * with another body is 422.
 */
final class FeedHeadAndHeartbeatTest extends ApiKernelTestCase
{
    public function testChangesCarryTheFeedHead(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'live');
        [$other] = $this->apiSite('alt', 'live');
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);

        $first = self::data($this->call('GET', '/v1/changes?after=0', $key));
        self::assertSame(['head_seq', 'listings', 'more', 'next_after', 'resync'], array_keys($first));
        self::assertSame(['V1'], array_column($first['listings'], 'variant_id'));
        $head = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        self::assertGreaterThan(0, $head);
        self::assertSame($head, $first['head_seq']);
        self::assertSame($head, $first['next_after']);

        // Feed rows of another site's item move the head too (it is the head of the whole feed).
        $otherSku = $this->item('strict', 0);
        $this->listing($other, 'W1', $otherSku);
        $this->book('goods_in', $otherSku, 3);
        $c = self::data($this->call('GET', '/v1/changes?after=' . $first['next_after'], $key));
        self::assertNotContains('W1', array_column($c['listings'], 'variant_id'));
        self::assertGreaterThan($head, $c['head_seq']);
        self::assertSame((int) self::$db->value('SELECT MAX(seq) FROM stock_change'), $c['head_seq']);
        self::assertGreaterThanOrEqual($c['next_after'], $c['head_seq']);

        // A short page: head_seq is the head of the whole feed, ahead of next_after.
        $this->book('goods_in', $sku, 1);
        $this->book('goods_in', $otherSku, 1);
        $page = self::data($this->call('GET', '/v1/changes?after=' . $c['next_after'] . '&limit=1', $key));
        self::assertTrue($page['more']);
        self::assertGreaterThan($page['next_after'], $page['head_seq']);

        // A restore: CW's feed ends below the seq the site already applied. The answer moves nothing
        // forward, and head_seq < the site's seq tells the site to re-snapshot.
        $applied = $page['head_seq'];
        self::$db->exec('DELETE FROM stock_change WHERE seq > ?', [$head]);
        $after = self::data($this->call('GET', '/v1/changes?after=' . $applied, $key));
        self::assertSame($head, $after['head_seq']);
        self::assertLessThan($applied, $after['head_seq']);
        self::assertSame($applied, $after['next_after']);
        self::assertFalse($after['more']);

        // In-process too, and on an empty feed.
        self::assertSame($head, $this->avail->changes((int) $site->channelId, 0)['head_seq']);
        self::$db->exec('DELETE FROM stock_change');
        self::assertSame(0, $this->avail->changes((int) $site->channelId, $applied)['head_seq']);
        self::assertSame(0, $this->avail->headSeq());
    }

    public function testHeartbeatNeedsAnIdempotencyKeyAndRefusesAnotherBodyUnderIt(): void
    {
        [$site, $key] = $this->apiSite('vpg', 'shadow');
        $rows = static fn (): int => (int) self::$db->value('SELECT COUNT(*) FROM channel_health WHERE channel_id = ?', [$site->channelId]);
        $body = ['site_mode' => 'shadow', 'outbox_depth' => 2, 'last_seq' => 10, 'connector_version' => '1.0.0'];

        // No key, or a malformed one: 400, nothing recorded, nothing stored.
        self::envelope($this->call('POST', '/v1/heartbeat', $key, $body, ''), 400, 'idempotency_key_required');
        self::envelope($this->call('POST', '/v1/heartbeat', $key, $body, 'two words'), 400, 'bad_idempotency_key');
        self::assertSame(0, $rows());

        $first = $this->call('POST', '/v1/heartbeat', $key, $body, 'hb-2026-10-02T10:00:00Z');
        $d = self::data($first);
        self::assertSame(['feed_seq', 'mode', 'received_at', 'result'], array_keys($d));
        self::assertSame(['recorded', 'shadow'], [$d['result'], $d['mode']]);
        self::assertSame(1, $rows());

        // A retry with the same key and body is a replay (no second row), whatever order the fields come in.
        $retry = $this->call('POST', '/v1/heartbeat', $key, array_reverse($body, true), 'hb-2026-10-02T10:00:00Z');
        self::assertSame('true', self::header($retry, 'Idempotent-Replayed'));
        self::assertSame($first->json(), $retry->json());
        self::assertSame(1, $rows());

        // The same key with another body (the next minute's counters) is 422 and records nothing.
        $reused = $this->call('POST', '/v1/heartbeat', $key, ['outbox_depth' => 3] + $body, 'hb-2026-10-02T10:00:00Z');
        self::envelope($reused, 422, 'idempotency_key_reused');
        self::assertSame(1, $rows());

        // A fresh key per heartbeat records each one.
        self::data($this->call('POST', '/v1/heartbeat', $key, ['outbox_depth' => 3] + $body, 'hb-2026-10-02T10:01:00Z'));
        self::assertSame(2, $rows());
    }
}
