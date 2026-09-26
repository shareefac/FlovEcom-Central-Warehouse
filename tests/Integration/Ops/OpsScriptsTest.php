<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Ops;

use CW\Caller;
use CW\Clock;
use CW\Reservations;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use DateTimeImmutable;

/**
 * The cron jobs in bin/ run end to end as separate processes against the test schema (with the
 * admin login: the app login has no rights on test schemas), plus the expiry error isolation
 * they rely on (Reservations::expireDue $onError).
 */
final class OpsScriptsTest extends StockTestCase
{
    /** @return array{code: int, out: string, err: string} */
    private static function script(string $name, string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $cmd = [PHP_BINARY, "{$root}/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /** A reservation made an hour ago on a 60 s TTL: due now. */
    private function dueHold(string $ref, string $variant, string $unit): void
    {
        $past = new Reservations(self::$db, $this->stock, static fn (): DateTimeImmutable => Clock::now()->modify('-1 hour'));
        $this->ok($past->reserve($this->vpg, $ref, [self::line($variant, $unit)], $this->key('reserve')));
    }

    private Caller $vpg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vpg = $this->site('vpg');
        self::$db->exec('UPDATE channel SET reserve_ttl_sec = 60 WHERE id = ?', [$this->vpg->channelId]);
    }

    public function testExpireReservationsExpiresDueHoldsOnly(): void
    {
        $sku = $this->item('strict', 5);
        $this->listing($this->vpg, 'V1', $sku);
        $this->dueHold('1', 'V1', 'a');
        $this->dueHold('2', 'V1', 'b');
        $this->ok($this->res->reserve($this->vpg, '3', [self::line('V1', 'c')], $this->key('reserve'))); // StockTestCase clock: 2026-09-26 12:00 -> also due
        $fresh = new Reservations(self::$db, $this->stock); // real clock: not due for 60 s
        $this->ok($fresh->reserve($this->vpg, '4', [self::line('V1', 'd')], $this->key('reserve')));
        $this->assertBal(5, 0, 4, $sku);

        $r = self::script('expire_reservations', '--limit=2');
        self::assertSame(0, $r['code'], $r['err'] . $r['out']);
        self::assertMatchesRegularExpression('/expire_reservations \[cw_test_\w+\] expired=3 failed=0 batches=3 /', $r['out']);
        self::assertSame(['expired', 'expired', 'expired', 'held'], self::$db->column("SELECT status FROM reservation ORDER BY order_ref"));
        $this->assertBal(5, 0, 1, $sku);

        $again = self::script('expire_reservations');
        self::assertSame(0, $again['code']);
        self::assertStringContainsString('expired=0 failed=0 batches=1', $again['out']);
    }

    public function testExpireDueIsolatesAFailingReservation(): void
    {
        $ok = $this->item('strict', 5);
        $bad = $this->item('strict', 5);
        $this->listing($this->vpg, 'OK', $ok);
        $this->listing($this->vpg, 'BAD', $bad);
        $this->dueHold('10', 'BAD', 'x'); // oldest: first in the cron's ORDER BY expires_at
        $this->dueHold('11', 'OK', 'y');
        $badId = (int) self::$db->value("SELECT id FROM reservation WHERE order_ref = '10'");
        // Corrupt the cached bucket so expiring order 10 fails (held would go negative).
        self::$db->exec('UPDATE stock_balance SET held = 0 WHERE sku_id = ?', [$bad]);
        $live = new Reservations(self::$db, $this->stock); // the real clock: both holds are due
        try {
            $caught = null;
            try {
                $live->expireDue(10);
            } catch (\LogicException $e) {
                $caught = $e;
            }
            self::assertNotNull($caught, 'without $onError the failure propagates (unchanged behaviour)');
            self::assertSame(['held', 'held'], self::$db->column('SELECT status FROM reservation ORDER BY order_ref'), 'and stops the batch');

            $failed = [];
            $n = $live->expireDue(10, static function (int $id, \Throwable $e) use (&$failed): void {
                $failed[$id] = $e::class;
            });
            self::assertSame(1, $n);
            self::assertSame([$badId => \LogicException::class], $failed);
            self::assertSame(['held', 'expired'], self::$db->column('SELECT status FROM reservation ORDER BY order_ref'));

            // The cron: logs the bad one, expires the rest, exits 1.
            $this->dueHold('12', 'OK', 'z');
            $r = self::script('expire_reservations');
            self::assertSame(1, $r['code'], $r['out'] . $r['err']);
            self::assertStringContainsString('expired=1 failed=1', $r['out']);
            self::assertStringContainsString("reservation {$badId} could not be expired", $r['err']);
            self::assertSame(['held', 'expired', 'expired'], self::$db->column('SELECT status FROM reservation ORDER BY order_ref'));
        } finally {
            self::$db->exec('UPDATE stock_balance SET held = 1 WHERE sku_id = ?', [$bad]);
        }
        $r = self::script('expire_reservations');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('expired=1 failed=0', $r['out']);
        $this->assertBal(5, 0, 0, $bad);
    }

    public function testInvariantsScriptReportsOkAndMismatches(): void
    {
        $sku = $this->item('strict', 5);
        $this->listing($this->vpg, 'V1', $sku);
        $this->ok($this->reserve($this->vpg, '1', [self::line('V1', 'a')]));

        $r = self::script('invariants');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertMatchesRegularExpression('/ok: invariants hold \(balances=\d+ units=1 /', $r['out']);

        self::$db->exec('UPDATE stock_balance SET on_hand = on_hand + 2, held = held + 1 WHERE sku_id = ?', [$sku]);
        try {
            $r = self::script('invariants');
            self::assertSame(1, $r['code']);
            self::assertStringContainsString('MISMATCH', $r['out']);
            self::assertStringContainsString("held=2 but its held units sum to 1", $r['err']);
            self::assertStringContainsString("on_hand=7 but its ledger sums to 5", $r['err']);
        } finally {
            self::$db->exec('UPDATE stock_balance SET on_hand = on_hand - 2, held = held - 1 WHERE sku_id = ?', [$sku]);
        }
    }

    public function testPruneChangesScript(): void
    {
        $sku = $this->item('strict', 1);
        $this->listing($this->vpg, 'V1', $sku);
        $this->book('goods_in', $sku, 1);
        $this->book('goods_in', $sku, 1);
        self::$db->exec('UPDATE stock_change SET created_at = ?', [Clock::db(Clock::now()->modify('-30 days'))]);
        $rows = (int) self::$db->value('SELECT COUNT(*) FROM stock_change');
        self::assertGreaterThanOrEqual(3, $rows);

        $dry = self::script('prune_changes', '--dry-run');
        self::assertSame(0, $dry['code'], $dry['err']);
        self::assertStringContainsString('DRY RUN', $dry['out']);
        self::assertStringContainsString('deleted=' . ($rows - 1), $dry['out']);
        self::assertSame($rows, self::$db->value('SELECT COUNT(*) FROM stock_change'));

        $r = self::script('prune_changes', '--days=14');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('deleted=' . ($rows - 1) . ' kept_newest_of_scope=1', $r['out']);
        self::assertSame(1, self::$db->value('SELECT COUNT(*) FROM stock_change'));

        $bad = self::script('prune_changes', '--days=0');
        self::assertSame(2, $bad['code']);
        self::assertStringContainsString('--days must be an integer from 1', $bad['err']);
    }

    public function testHealthAlertListsStaleChannels(): void
    {
        $alt = $this->site('alt', 'shadow');
        $this->site('vbig', 'off'); // off: never expected to report
        $insert = 'INSERT INTO channel_health (channel_id, received_at, site_mode, dead_letters) VALUES (?, ?, ?, ?)';
        self::$db->exec($insert, [$this->vpg->channelId, Clock::db(Clock::now()->modify('-10 minutes')), 'live', 0]);
        self::$db->exec($insert, [$this->vpg->channelId, Clock::db(Clock::now()->modify('-20 seconds')), 'shadow', 3]);
        // alt: none yet

        $r = self::script('health_alert');
        self::assertSame(1, $r['code'], $r['err']);
        self::assertStringContainsString('STALE alt (shadow): no heartbeat ever received', $r['out']);
        self::assertStringContainsString('DEAD_LETTERS vpg (live): 3 dead-lettered outbox rows', $r['out']);
        self::assertStringContainsString('MODE_MISMATCH vpg (live): site reports CW_MODE=shadow', $r['out']);
        self::assertStringNotContainsString('STALE vpg', $r['out'], 'the newest heartbeat counts');
        self::assertStringNotContainsString('vbig', $r['out']);

        $r = self::script('health_alert', '--stale-after=5');
        self::assertMatchesRegularExpression('/STALE vpg \(live\): last heartbeat \S+ \(\d+ s ago; threshold 5 s\)/', $r['out']);

        self::$db->exec($insert, [$this->vpg->channelId, Clock::db(Clock::now()), 'live', 0]);
        self::$db->exec($insert, [$alt->channelId, Clock::db(Clock::now()), 'shadow', 0]);
        $r = self::script('health_alert');
        self::assertSame(0, $r['code'], $r['out'] . $r['err']);
        self::assertStringContainsString('ok: every shadow/live channel reported within 180 s', $r['out']);
    }

    public function testJobsRefuseASchemaThatDoesNotMatchTheCodeAndRunOneAtATime(): void
    {
        self::$db->exec("INSERT INTO schema_migrations (version, checksum, applied_at, duration_ms) VALUES ('9999_from_the_future.sql', REPEAT('0', 64), UTC_TIMESTAMP(6), 0)");
        try {
            $r = self::script('expire_reservations');
            self::assertSame(3, $r['code']);
            self::assertStringContainsString('has migrations this code does not have (9999_from_the_future.sql)', $r['err']);
        } finally {
            self::$db->exec("DELETE FROM schema_migrations WHERE version = '9999_from_the_future.sql'");
        }

        $lock = 'cw_job:expire_reservations:' . TestDb::name();
        self::assertSame(1, self::$db->value('SELECT GET_LOCK(?, 0)', [$lock]));
        try {
            $r = self::script('expire_reservations');
            self::assertSame(0, $r['code'], $r['err']);
            self::assertStringContainsString('skipped: another run of this job holds its lock', $r['out']);
        } finally {
            self::$db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        $r = self::script('expire_reservations', '--limit=lots');
        self::assertSame(2, $r['code']);
        self::assertStringContainsString('--limit must be an integer', $r['err']);
    }
}
