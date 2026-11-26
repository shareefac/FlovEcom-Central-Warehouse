<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Profit\GpFees;
use CW\Profit\GpInvariants;
use CW\Profit\GpInvoices;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Settings;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1174, the two checks of 513fa3d (runs/gpfees_final2_astra: Codex H1, H2, M4-M8; Fable FA2, FA4, FA7) with the lead's decisions, each shown
 * failing first: H1 a slow check under the guards never keeps a snapshot past its own 3 s; H2 a big day whose write batches are slow is
 * written batch by batch (each its own short guarded transaction with its day figures), so the quick stop reaches a checked zero over
 * repeated passes; M4 a card snapshot waits for a setting being changed before it takes its day's guard, never holding the other orders of
 * the day; M5 a month with a huge queue is still spread; M6 the deadline stops a month going back to estimates before its next day and caps
 * the queue's row waits; M7 a day the nightly could not go over is gone over by the next run; M8 a parked invoice is no 6-hour wait for the
 * nightly checks; FA4 the month still running is refused until it ends.
 */
final class GpFeesLastTest extends StockTestCase
{
    private const MERCHANT = '55829291';

    private static function sys(): Caller
    {
        return Caller::system('test');
    }

    private function set(string $key, string $value): void
    {
        (new Settings(self::$db))->set(self::sys(), $key, $value, 'set for the test');
    }

    private function on(string $from = '2026-11-01'): void
    {
        $this->set('profit.gp_from_vpg_online', $from);
        $this->set('profit.gp_merchant_vpg_online', self::MERCHANT);
        if ($from < '2026-11-01') {
            $this->set('profit.gp_shop_from_vapeandgo', $from);
        }
    }

    private function store(Caller $site, string $ref, string $paid, int $grand, string $gateway = 'globalpay'): void
    {
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, $ref, ['v' => 1, 'version' => 1000, 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid,
            'paid_at' => $paid, 'gateway' => $gateway, 'currency' => 'GBP', 'parent_ref' => null, 'lines' => [], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => $grand,
            'parcels' => []], 'api');
    }

    /** An invoice entered as the page does, on 5 Dec 2026 (November's has ended: the month still running is refused, FA4). */
    private static function invoices(): GpInvoices
    {
        return new GpInvoices(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-12-05 12:00:00', Clock::utc()));
    }

    private static function invoice(array $over = []): array
    {
        return $over + ['account' => 'vpg_online', 'month' => '2026-11', 'merchant_no' => self::MERCHANT, 'invoice_date' => '2026-11-30', 'sales' => '50.00',
            'net' => '1.00', 'vat' => '0', 'total' => '1.00', 'note' => ''];
    }

    private static function bigDay(Caller $site, string $day, int $n): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [$site->channelId, (string) (8000000 + $i), 'completed', "{$day} 10:00:00", $day, 'globalpay', 2000, 500 + ($i * 37) % 9500, 1, "{$day} 10:00:00",
                "{$day} 10:00:00"];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            self::$db->exec('INSERT INTO sale_order (channel_id, order_ref, status, paid_at, day, gateway, vat_bp, grand_inc, snapshot_version, first_at, updated_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')), array_merge(...$chunk));
        }
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function day(Caller $site, string $day): array
    {
        $r = self::$db->one('SELECT gp_fee, gp_fee_est, gp_other FROM profit_day WHERE channel_id = ? AND day = ?', [$site->channelId, $day]);
        return $r === null ? [0, 0, 0] : [(int) $r['gp_fee'], (int) $r['gp_fee_est'], (int) $r['gp_other']];
    }

    private static function same(Caller $site, string ...$days): void
    {
        foreach ($days as $d) {
            self::assertSame([], ProfitDays::differences(self::$db, (int) $site->channelId, $d), "a rebuild of {$d} gives the same figures");
        }
    }

    private static function hook(?\Closure $fn): void
    {
        if (property_exists(GpFees::class, 'hook')) {
            GpFees::$hook = $fn;
        }
    }

    /** @return array{0: resource, 1: array<int, resource>} a worker of this directory started with the test's schema */
    private static function worker(string $file, array $args = [], ?string $stdin = null): array
    {
        $pipes = [];
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', __DIR__ . '/' . $file, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__, 3), ['CW_SLOT' => (string) getenv('CW_SLOT'), 'CW_DB_NAME' => TestDb::name()] + getenv());
        self::assertIsResource($p);
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin . "\n");
        }
        fclose($pipes[0]);
        return [$p, $pipes];
    }

    /** @param array{0: resource, 1: array<int, resource>} $w @return array<string, mixed> its last line, decoded */
    private static function done(array $w): array
    {
        [$p, $pipes] = $w;
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = trim((string) stream_get_contents($pipes[2]));
        proc_close($p);
        $r = json_decode((string) substr($out, (int) strrpos("\n" . $out, "\n")), true);
        self::assertIsArray($r, $out . ' ' . $err);
        return $r;
    }

    protected function tearDown(): void
    {
        self::hook(null);
        parent::tearDown();
    }

    public function testH1ASlowCheckUnderTheGuardsNeverKeepsASnapshotPastItsWait(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        self::bigDay($vpg, '2026-11-11', 300);
        $this->on();
        $probe = null;
        self::hook(function (string $at) use ($vpg, &$probe): void {
            if ($at === 'revalidated' && $probe === null) {
                $probe = self::worker('gp_guard_probe.php', [(string) $vpg->channelId, '2026-11-11']);
                self::assertSame('trying', trim((string) fgets($probe[1][1])));
                usleep(2_200_000); // the check of the batch against the plan took 2.2 s: a snapshot of the day waits meanwhile
            }
        });
        $s = (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-11');
        self::assertNotNull($probe, 'the batch was checked under the guards');
        $r = self::done($probe);
        self::assertTrue($r['got'], 'the snapshot had its guard inside its own 3 s');
        self::assertLessThan(3000, $r['ms'], "it waited {$r['ms']} ms");
        self::assertTrue($s['busy'], 'past the hold right after the check: that batch rolled back, left for the next run');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM gp_fee_order'));
        self::hook(null);
        self::assertFalse((new GpFees(self::$db))->syncDay('vpg_online', '2026-11-11')['busy']);
        self::assertSame(300, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state = 'estimate'"));
        self::same($vpg, '2026-11-11');
    }

    public function testH2TheQuickStopFinishesABigDayWhoseBatchesAreSlow(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        self::bigDay($vpg, '2026-11-11', 1541);
        $this->on();
        self::assertFalse((new GpFees(self::$db))->syncDay('vpg_online', '2026-11-11')['busy']);
        self::assertSame(1541, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state = 'estimate'"));
        $n = 0;
        self::hook(function (string $at) use (&$n): void {
            if ($at === 'batch') {
                $n++;
                usleep($n % 4 === 0 ? 2_100_000 : 300_000); // every batch slow (a busy disk), every fourth past the hold
            }
        });
        $r = (new GpFees(self::$db))->stop(self::sys(), 'vpg_online', 'the owner said stop', 40.0);
        self::assertTrue($r['ok'], 'the stop reached a checked zero: ' . json_encode($r));
        self::assertGreaterThan(7, $n, 'over more than one pass: the batches done stayed done');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state <> 'off'"));
        self::assertSame([0, 0, 0], self::day($vpg, '2026-11-11'));
        self::same($vpg, '2026-11-11');
    }

    public function testM4ACardSnapshotWaitingForASettingHoldsNoGuardOfItsDay(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        // someone saves Electrofag's first day (its row held for a few seconds)
        $x = TestDb::connect();
        $x->exec('START TRANSACTION');
        $x->value("SELECT setting_key FROM app_setting WHERE setting_key = 'profit.gp_from_ef_online' FOR UPDATE");
        $failed = null;
        $wait = (int) self::$db->value('SELECT @@SESSION.innodb_lock_wait_timeout');
        try {
            // a Vape and Go card snapshot of 19 Nov waits for it
            $w = self::worker('gp_snapshot_worker.php', [], json_encode(['channel' => (int) $vpg->channelId, 'refs' => 1, 'days' => ['2026-11-19'], 'until_ms' => 300,
                'gap_ms' => 20], JSON_THROW_ON_ERROR));
            self::assertSame('running', trim((string) fgets($w[1][1])));
            usleep(800_000);
            // a PayPal order of the same shop-day is stored meanwhile, at once
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 3');
            $t = microtime(true);
            try {
                $this->store($vpg, '9100500', '2026-11-19T12:00:00Z', 2000, 'pp');
            } catch (\Throwable $e) {
                $failed = get_class($e) . ($e instanceof CwException ? ':' . $e->errorCode : '');
            }
            $took = microtime(true) - $t;
        } finally {
            $x->exec('ROLLBACK');
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = ' . $wait);
        }
        $res = self::done($w);
        self::assertNull($failed, 'the PayPal order was stored');
        self::assertLessThan(1.5, $took, sprintf('it waited %.1f s: never for the card snapshot that waits for the setting', $took));
        self::assertSame(0, $res['failed'], 'the card snapshot was stored once the setting was saved: ' . json_encode($res));
        self::assertGreaterThan(0, $res['stores']);
    }

    public function testM5AMonthWithAHugeQueueIsStillSpread(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        $id = self::invoices()->add(self::sys(), self::invoice())['id'];
        self::$db->exec('SET SESSION cte_max_recursion_depth = 100000');
        self::$db->exec("INSERT INTO gp_due (account, month, queued_at) WITH RECURSIVE s (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 66000) "
            . "SELECT 'vpg_online', '2026-11', UTC_TIMESTAMP(6) FROM s");
        self::$db->exec("UPDATE gp_invoice SET spread_state = 'due' WHERE id = ?", [$id]);
        self::assertSame('done', (new GpFees(self::$db))->settleMonth(self::sys(), 'vpg_online', '2026-11'), '66,000 queued changes covered by one spread');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM gp_due'));
    }

    public function testM6TheDeadlineStopsAMonthGoingBackToEstimatesBeforeItsNextDay(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        foreach (['19', '20', '21'] as $i => $d) {
            $this->store($vpg, '910000' . $i, "2026-11-{$d}T10:00:00Z", 5000);
        }
        $id = self::invoices()->add(self::sys(), self::invoice(['sales' => '150.00', 'net' => '3.00', 'total' => '3.00']))['id'];
        (new GpFees(self::$db))->run(self::sys());
        // deleted (its own settle did not run): its month goes back to estimates
        self::$db->exec("UPDATE gp_invoice SET deleted_at = UTC_TIMESTAMP(6), deleted_actor = 'test', deleted_reason = 'the wrong statement', spread_state = 'due', "
            . 'version = version + 1 WHERE id = ?', [$id]);
        self::hook(static function (string $at): void {
            if ($at === 'back_day') {
                usleep(700_000); // each day slow
            }
        });
        $d = (new GpFees(self::$db, null, 1.0))->run(self::sys())['vpg_online'];
        self::assertGreaterThan(0, $d['left'], 'stopped at the deadline before its next day');
        self::assertGreaterThan(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state = 'actual'"), 'a day left for the next run');
        self::hook(null);
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state = 'actual'"));
        self::same($vpg, '2026-11-19', '2026-11-20', '2026-11-21');
    }

    public function testM6TheQueuesRowWaitsAreCappedByTheDeadline(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        $id = self::invoices()->add(self::sys(), self::invoice())['id'];
        (new GpFees(self::$db))->run(self::sys());
        $this->store($vpg, '9100002', '2026-11-20T10:00:00Z', 5000); // queued
        $x = TestDb::connect();
        $x->exec('START TRANSACTION');
        $x->one('SELECT id FROM gp_invoice WHERE id = ? FOR UPDATE', [$id]); // an edit of the invoice holds its row
        try {
            $t = microtime(true);
            $d = (new GpFees(self::$db, null, 2.0))->run(self::sys())['vpg_online'];
            $took = microtime(true) - $t;
        } finally {
            $x->exec('ROLLBACK');
        }
        self::assertLessThan(4.0, $took, sprintf('%.1f s: every row wait capped by the time left', $took));
        self::assertGreaterThan(0, $d['left']);
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('done', (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]));
    }

    public function testM7ADayTheNightlyCouldNotGoOverIsGoneOverByTheNextRun(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        (new GpFees(self::$db))->run(self::sys());
        self::bigDay($vpg, '2026-11-07', 1); // a card order with no card fee (it reached CW while it was not counted)
        $x = TestDb::connect();
        $x->exec('START TRANSACTION');
        ProfitDays::guard($x, (int) $vpg->channelId, ['2026-11-07']);
        try {
            $n = (new GpFees(self::$db))->run(self::sys(), 'nightly')['vpg_online'];
        } finally {
            $x->exec('ROLLBACK');
        }
        self::assertGreaterThan(0, $n['busy']);
        self::assertSame('partial', (string) self::$db->value("SELECT state FROM gp_run WHERE account = 'vpg_online' ORDER BY id DESC LIMIT 1"));
        $j = (new GpFees(self::$db))->run(self::sys())['vpg_online'];
        self::assertSame(1, $j['days'], 'the 10-minute run goes over the day the nightly left');
        self::assertSame('estimate', (string) self::$db->value("SELECT state FROM gp_fee_order WHERE order_ref = '8000000'"));
        self::assertSame('ok', (string) self::$db->value("SELECT state FROM gp_run WHERE account = 'vpg_online' ORDER BY id DESC LIMIT 1"));
        self::assertSame(0, (new GpFees(self::$db))->run(self::sys())['vpg_online']['days'], 'then nothing left');
    }

    public function testM8AParkedInvoiceIsNoSixHourWaitForTheNightlyChecks(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on('2026-09-01');
        $this->store($vpg, '9100002', '2026-10-15T10:00:00Z', 5000);
        $id = self::invoices()->add(self::sys(), self::invoice(['month' => '2026-10', 'invoice_date' => '2026-10-31']))['id'];
        $this->set('profit.gp_until_vpg_online', '2026-09-30');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('due', (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]), 'parked');
        self::$db->exec('UPDATE gp_invoice SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 7 HOUR WHERE id = ?', [$id]);
        self::assertSame([], array_values(array_filter(GpInvariants::check(self::$db), static fn (string $l): bool => str_starts_with($l, 'GP4'))),
            'parked and cleared: the health alert says it (gp_invoice_outside), the nightly checks do not fail on it');
        self::assertContains('gp_invoice_outside', array_column(GpFees::health(self::$db, Clock::now()), 'kind'));
        $this->set('profit.gp_until_vpg_online', '');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('done', (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]));
    }

    public function testFA4TheMonthStillRunningIsRefusedUntilItEnds(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        $inNov = new GpInvoices(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-26 12:00:00', Clock::utc()));
        $code = static function (\Closure $fn): string {
            try {
                $fn();
            } catch (CwException $e) {
                return $e->errorCode;
            }
            return 'no refusal';
        };
        self::assertSame('gp_current_month', $code(static fn () => $inNov->add(self::sys(), self::invoice(['current_ok' => '1']))), 'no tick lets it in');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM gp_invoice'));
        self::assertIsInt(self::invoices()->add(self::sys(), self::invoice())['id'], 'once November has ended');
    }
    /**
     * The line-by-line check of e1fe6a9 (Fable L4): a batch whose rows all changed between the plan and the batch writes nothing, and the
     * summary row its guard made on a day with nothing on it is taken away again (as a rebuild has none): never a row of zeros left.
     */
    public function testL4ABatchThatWritesNothingLeavesNoRowOfZeros(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        self::bigDay($vpg, '2026-11-11', 1);
        $this->on();
        $moved = false;
        self::hook(function (string $at) use (&$moved): void {
            if ($at === 'sync_planned' && !$moved) {
                $moved = true; // the order moved to the 12th between the plan and its batch (its snapshot, elsewhere)
                self::$db->exec("UPDATE sale_order SET day = '2026-11-12', paid_at = '2026-11-12 10:00:00' WHERE order_ref = '8000000'");
            }
        });
        (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-11');
        self::assertTrue($moved);
        self::assertNull(self::$db->value("SELECT 1 FROM profit_day WHERE channel_id = ? AND day = '2026-11-11'", [$vpg->channelId]),
            'no summary row of zeros left on the 11th');
        self::same($vpg, '2026-11-11');
        self::hook(null);
        (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-12'); // the order's fee on its day (the nightly checks after the test)
    }

    /**
     * The line-by-line check of e1fe6a9 (Fable L5): a nightly killed before it finished (left "running", marked failed by the next run) never
     * loses the days it did not reach: the next 10-minute run goes over the nightly's days once.
     */
    public function testL5AKilledNightlysDaysAreGoneOverByTheNextRun(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        (new GpFees(self::$db))->run(self::sys());
        self::bigDay($vpg, '2026-11-07', 1); // a card order with no card fee (only a day pass gives it one)
        self::$db->exec("INSERT INTO gp_run (account, kind, actor, started_at) VALUES ('vpg_online', 'nightly', 'system:gp_fees', UTC_TIMESTAMP(6) - INTERVAL 2 HOUR)");
        $d = (new GpFees(self::$db))->run(self::sys())['vpg_online'];
        self::assertSame('failed', (string) self::$db->value("SELECT state FROM gp_run WHERE kind = 'nightly' ORDER BY id DESC LIMIT 1"), 'the killed nightly marked failed');
        self::assertSame('estimate', (string) self::$db->value("SELECT state FROM gp_fee_order WHERE order_ref = '8000000'"), 'the next run went over its days');
        self::assertGreaterThan(1, $d['days']);
        self::assertSame(0, (new GpFees(self::$db))->run(self::sys())['vpg_online']['days'], 'once: the run after it goes over none again');
    }
}
