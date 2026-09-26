<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\WorkerPool;

/**
 * Same-key races the hammer (tests/concurrency/hammer.php, scenario 3) found.
 *
 * A call CW cannot act on yet (ship before its commit, D36) rolls its key claim back and stores
 * nothing. Copies of that call that were queued on the claim then raced each other on the freed
 * key: each held a shared lock from its duplicate-key check and wanted to insert, so they
 * deadlocked (35 deadlocks retried, 2 surfaced as errors with 20 copies). Idempotency::run now
 * serialises calls with one key on a named lock (H1), so this never deadlocks.
 */
final class IdempotencyRaceTest extends StockTestCase
{
    /** @return array<string, mixed> */
    private static function shipOp(int $channelId, string $key): array
    {
        return ['op' => 'ship', 'channel_id' => $channelId, 'channel_code' => 'vpg', 'order_ref' => '7', 'unit_ids' => ['a'],
            'dispatched_at' => '2026-09-26T12:00:00Z', 'key' => $key];
    }

    public function testConcurrentCopiesOfACallThatIsNotStoredNeverDeadlock(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->ok($this->reserve($site, '7', [self::line('V1', 'a')])); // held, not paid yet

        for ($round = 0; $round < 2; $round++) {
            $out = WorkerPool::run(array_fill(0, WorkerPool::MAX_WORKERS, [self::shipOp((int) $site->channelId, 'ship-7')]));
            self::assertCount(WorkerPool::MAX_WORKERS, $out['results']);
            foreach ($out['results'] as $r) {
                self::assertSame(409, $r['status'], json_encode($r));
                self::assertSame('not_committed', $r['error'] ?? null, json_encode($r));
            }
            self::assertSame(0, $out['deadlocks'], 'copies of one key must queue, not deadlock on the freed key claim');
        }
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM idempotency WHERE idem_key = 'ship-7'"), 'a not_committed answer is never stored');
        $this->assertBal(5, 0, 1, $sku);

        // The same key works once the order is paid (the site's outbox retries it after the commit).
        $this->ok($this->commit($site, '7', [self::line('V1', 'a')]));
        $r = $this->ok($this->ship($site, '7', ['a'], '2026-09-26T12:00:00Z', 'ship-7'));
        self::assertFalse($r->replayed);
        self::assertSame('shipped', $r->body['units'][0]['result']);
        $this->assertBal(4, 0, 0, $sku);
    }

    public function testConcurrentCopiesOfAStoredCallStillHaveOneEffect(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->ok($this->reserve($site, '7', [self::line('V1', 'a')]));
        $this->ok($this->commit($site, '7', [self::line('V1', 'a')]));

        $out = WorkerPool::run(array_fill(0, WorkerPool::MAX_WORKERS, [self::shipOp((int) $site->channelId, 'ship-7b')]));
        $fresh = array_values(array_filter($out['results'], static fn (array $r): bool => $r['replayed'] === false));
        self::assertCount(1, $fresh, json_encode($out['results']));
        foreach ($out['results'] as $r) {
            self::assertSame(200, $r['status']);
            self::assertSame($fresh[0]['body_hash'], $r['body_hash'], 'every copy gets the one stored answer');
        }
        self::assertSame(0, $out['deadlocks']);
        self::assertSame(2, self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE idem_key = 'ship-7b'"));
        $this->assertBal(4, 0, 0, $sku);
    }
}
