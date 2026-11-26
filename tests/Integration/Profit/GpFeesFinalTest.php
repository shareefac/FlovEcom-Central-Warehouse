<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\Db;
use CW\Expenses\ExpenseViews;
use CW\Profit\GpFees;
use CW\Profit\GpInvariants;
use CW\Profit\GpInvoices;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\SnapshotBody;
use CW\Settings;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1174, the two final reviews of 9b98e38..7332729 (runs/gpfees_final_astra: Codex H1-H7 and Fable F6-F10), each shown failing first:
 * H1 a snapshot that read "no invoice" and commits after the month's first invoice was spread queues its month in its own transaction (a
 * month with no invoice: the job drops it); H3 a day is read and planned outside its guards (a slow read never holds them) and its writes stop
 * at the hold limit; H5 an invoice whose month is outside the account's days counts nowhere (never posted outside its month) and waits; M6 the
 * job's deadline caps the spreads' lock wait and every day of a spread; M7 a settings change that could not finish resumes where it stopped
 * (its busy day too) and a partial run is not a good run; F6 a queued month says "estimated"; F7 a spread covers the changes queued before
 * it; F8 a month queued minutes ago is no 6-hour wait; F9 Vape Big's card fees from its own first day; F10 a row whose order lost its paid day
 * is switched off with the day's figure.
 */
final class GpFeesFinalTest extends StockTestCase
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
            $this->set('profit.gp_shop_from_vapeandgo', $from); // Vape and Go's own first day (seeded 1 Nov) as early as the account's
        }
    }

    /** @return array<string, mixed> */
    private static function body(string $paid, int $grand, int $version = 1000): array
    {
        return ['v' => 1, 'version' => $version, 'order_no' => '', 'status' => 'completed', 'placed_at' => $paid, 'paid_at' => $paid, 'gateway' => 'globalpay',
            'currency' => 'GBP', 'parent_ref' => null, 'lines' => [], 'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => $grand, 'parcels' => []];
    }

    private function store(Caller $site, string $ref, string $paid, int $grand, int $version = 1000): void
    {
        (new OrderSnapshots(self::$db))->store((int) $site->channelId, $ref, self::body($paid, $grand, $version), 'api');
    }

    /** An order snapshot's money work on another connection, inside a transaction the caller opened and has not committed yet. */
    private static function openSnapshot(Db $other, Caller $site, string $ref, string $paid, int $grand): void
    {
        $now = Clock::now();
        (new OrderSnapshots($other))->apply($other, (int) $site->channelId, $ref, SnapshotBody::parse(self::body($paid, $grand), $now), Clock::db($now));
    }

    private static function row(Caller $site, string $ref): ?array
    {
        return self::$db->one('SELECT * FROM gp_fee_order WHERE channel_id = ? AND order_ref = ?', [$site->channelId, $ref]);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function day(Caller $site, string $day): array
    {
        $r = self::$db->one('SELECT gp_fee, gp_fee_est, gp_other FROM profit_day WHERE channel_id = ? AND day = ?', [$site->channelId, $day]);
        return $r === null ? [0, 0, 0] : [(int) $r['gp_fee'], (int) $r['gp_fee_est'], (int) $r['gp_other']];
    }

    /** An invoice as the form sends it (the month still running is ticked: F5). */
    private static function invoice(array $over = []): array
    {
        return $over + ['account' => 'vpg_online', 'month' => '2026-11', 'merchant_no' => self::MERCHANT, 'invoice_date' => '2026-11-30', 'sales' => '50.00',
            'net' => '1.00', 'vat' => '0', 'total' => '1.00', 'note' => '', 'current_ok' => '1'];
    }

    private static function same(Caller $site, string ...$days): void
    {
        foreach ($days as $d) {
            self::assertSame([], ProfitDays::differences(self::$db, (int) $site->channelId, $d), "a rebuild of {$d} gives the same figures");
        }
    }

    private static function state(int $id): string
    {
        return (string) self::$db->value('SELECT spread_state FROM gp_invoice WHERE id = ?', [$id]);
    }

    /** A day of $n card orders of a shop stored plainly (as the largest real day: 1,541 on 11 Nov), with no card fee yet. */
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

    /** How long another connection waits for a store-day's guard (seconds; it gives up after 1 s). */
    private static function guardWait(Caller $site, string $day): float
    {
        $probe = TestDb::connect();
        $probe->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $probe->exec('START TRANSACTION');
        $t = microtime(true);
        try {
            ProfitDays::guard($probe, (int) $site->channelId, [$day]);
        } catch (\PDOException) {
            // held past its wait
        } finally {
            $probe->exec('ROLLBACK');
        }
        return microtime(true) - $t;
    }

    private static function hook(?\Closure $fn): void
    {
        if (property_exists(GpFees::class, 'hook')) {
            GpFees::$hook = $fn;
        } elseif ($fn !== null) {
            self::fail('GpFees has no test hook (the final round adds it: H3, M7)');
        }
    }

    protected function tearDown(): void
    {
        GpFees::$afterPlan = null;
        self::hook(null);
        parent::tearDown();
    }

    public function testH1ASnapshotThatReadNoInvoiceIsSpreadOnceItCommits(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $vb = $this->site('vapebig', 'live');
        $this->on('2026-10-01');
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        // a late Vape and Go order and a Vape Big order (before its own first day) of days with no card order yet: their snapshots are open,
        // each read "no invoice of November"
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        try {
            self::openSnapshot($other, $vpg, '9100002', '2026-11-21T10:00:00Z', 5000);
            self::openSnapshot($other, $vb, '9200001', '2026-11-22T10:00:00Z', 5000);
            // November's first invoice entered meanwhile: its spread cannot see them and is done
            $id = (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['sales' => '150.00', 'net' => '3.00', 'total' => '3.00', 'gap_ok' => '1']))['id'];
            self::assertSame('done', self::state($id));
            $other->pdo()->commit();
        } finally {
            if ($other->inTransaction()) {
                $other->pdo()->rollBack();
            }
        }
        self::assertGreaterThan(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_due WHERE account = 'vpg_online' AND month = '2026-11'"),
            'each snapshot queued its month in its own transaction, invoice or not');
        // an order of a month with no invoice queues it too: the job drops it
        $this->store($vpg, '9100009', '2026-10-15T10:00:00Z', 1000);
        self::assertGreaterThan(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_due WHERE month = '2026-10'"));
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('done', self::state($id));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM gp_fee_order WHERE state = 'estimate' AND day >= '2026-11-01'"), 'no estimate beside the invoice');
        self::assertSame(200, (int) self::$db->value("SELECT SUM(fee_pence) FROM gp_fee_order WHERE state = 'actual'"), '100p on each Vape and Go order');
        self::assertSame([0, 0, 100], self::day($vpg, '2026-11-30'), 'Vape Big\'s share on Vape and Go\'s "on no order" line');
        self::assertSame(300, (int) self::$db->value('SELECT spread_pence + other_pence FROM gp_invoice WHERE id = ?', [$id]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM gp_due'), 'November spread, October (no invoice) dropped');
        self::assertSame('estimate', (string) self::row($vpg, '9100009')['state']);
        self::same($vpg, '2026-11-19', '2026-11-21', '2026-11-30', '2026-10-15');
        self::same($vb, '2026-11-22');
    }

    public function testH3ASlowReadOfADayHoldsNoGuardAndIsNoReasonToGiveWay(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        self::bigDay($vpg, '2026-11-11', 1541);
        $this->on();
        $wait = null;
        self::hook(function (string $at) use ($vpg, &$wait): void {
            if ($at === 'sync_planned') {
                usleep(2_500_000); // the day's read took 2.5 s
                $wait = self::guardWait($vpg, '2026-11-11');
            }
        });
        $s = (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-11');
        self::assertNotNull($wait);
        self::assertLessThan(0.5, $wait, 'a snapshot of the day gets its guard at once while the day is read and planned');
        self::assertFalse($s['busy'], 'the read is outside the guard: the day is written');
        self::assertSame(1541, $s['written']);
        self::same($vpg, '2026-11-11');
    }

    public function testH3TheWritesOfADayStopAtTheHoldLimit(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        self::bigDay($vpg, '2026-11-11', 1541);
        $this->on();
        $n = 0;
        self::hook(function (string $at) use (&$n): void {
            if ($at === 'batch') {
                $n++;
                usleep($n === 3 ? 2_200_000 : 100_000); // the third batch past the hold (a busy disk)
            }
        });
        $s = (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-11');
        // each batch is a short transaction of its own (the check of 513fa3d, H2): the one past the hold rolled back alone, the two before it stay
        self::assertTrue($s['busy'], 'past the hold limit: that batch rolled back, the rest left for the next run');
        self::assertSame(2 * GpFees::BATCH, (int) self::$db->value('SELECT COUNT(*) FROM gp_fee_order'), 'the batches written before it stay written');
        self::assertSame((int) self::$db->value('SELECT SUM(fee_pence) FROM gp_fee_order'), self::day($vpg, '2026-11-11')[0], 'with their day figures');
        self::hook(null);
        self::assertFalse((new GpFees(self::$db))->syncDay('vpg_online', '2026-11-11')['busy'], 'the next run writes what is left');
        self::assertSame(1541, (int) self::$db->value('SELECT COUNT(*) FROM gp_fee_order'));
        self::same($vpg, '2026-11-11');
    }

    public function testH3ASpreadReadsEachDayOutsideItsGuard(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        self::bigDay($vpg, '2026-11-11', 1541);
        $this->on();
        (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-11');
        $takings = (int) self::$db->value('SELECT SUM(grand_inc) FROM sale_order WHERE channel_id = ?', [$vpg->channelId]);
        $wait = null;
        self::hook(function (string $at) use ($vpg, &$wait): void {
            if ($at === 'spread_day_planned' && $wait === null) {
                usleep(2_500_000);
                $wait = self::guardWait($vpg, '2026-11-11');
            }
        });
        $id = (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['sales' => sprintf('%d.%02d', intdiv($takings, 100), $takings % 100), 'net' => '512.34',
            'total' => '512.34']))['id'];
        self::assertNotNull($wait);
        self::assertLessThan(0.5, $wait, 'the day is read and checked against the plan outside its guard');
        self::assertSame('done', self::state($id));
        self::assertSame([51234, 0, 0], self::day($vpg, '2026-11-11'));
        self::same($vpg, '2026-11-11');
    }

    public function testH5ALastDayCorrectedBeforeAnInvoicesMonthParksTheInvoice(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on('2026-09-01');
        $this->store($vpg, '9100001', '2026-09-15T10:00:00Z', 5000);
        $this->store($vpg, '9100002', '2026-10-15T10:00:00Z', 5000);
        $id = (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['month' => '2026-10', 'invoice_date' => '2026-10-31']))['id'];
        self::assertSame('100', (string) self::row($vpg, '9100002')['fee_pence']);
        // the account's last day corrected to 30 Sep: October is outside its days now
        $this->set('profit.gp_until_vpg_online', '2026-09-30');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame([0, 0, 0], self::day($vpg, '2026-09-30'), 'October\'s invoice is never posted outside its month');
        self::assertSame(0, (int) self::$db->value('SELECT other_pence FROM gp_invoice WHERE id = ?', [$id]));
        self::assertSame('off', (string) self::row($vpg, '9100002')['state'], 'October\'s order counts nothing now');
        self::assertSame('due', self::state($id), 'the invoice waits (parked) until the days are put right or it is deleted');
        self::assertContains('gp_invoice_outside', array_column(GpFees::health(self::$db, Clock::now()), 'kind'), 'the health alert says so');
        self::same($vpg, '2026-09-15', '2026-09-30', '2026-10-15', '2026-10-31');
        // put right: spread again
        $this->set('profit.gp_until_vpg_online', '');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('done', self::state($id));
        self::assertSame(['actual', '100'], [(string) self::row($vpg, '9100002')['state'], (string) self::row($vpg, '9100002')['fee_pence']]);
        self::assertNotContains('gp_invoice_outside', array_column(GpFees::health(self::$db, Clock::now()), 'kind'));
    }

    public function testM6TheDeadlineCapsTheSpreadsLockWait(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        $id = (new GpInvoices(self::$db))->add(self::sys(), self::invoice())['id'];
        self::$db->exec("UPDATE gp_invoice SET spread_state = 'due' WHERE id = ?", [$id]);
        self::$db->exec("INSERT INTO gp_due (account, month, queued_at) VALUES ('vpg_online', '2026-11', UTC_TIMESTAMP(6))");
        $other = TestDb::connect();
        $lock = 'cw_gp_settle:' . TestDb::name();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [$lock]), 'another spread holds the spreads\' lock');
        try {
            $t = microtime(true);
            $d = (new GpFees(self::$db, null, 2.0))->run(self::sys())['vpg_online'];
            $took = microtime(true) - $t;
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        self::assertLessThan(4.0, $took, sprintf('every wait capped by the time left (%.1f s)', $took));
        self::assertGreaterThan(0, $d['left'], 'the month left for the next run');
        self::assertSame('due', self::state($id));
    }

    public function testM6ASpreadStopsBeforeADayOnceTheDeadlineIsPast(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        $this->store($vpg, '9100002', '2026-11-20T10:00:00Z', 5000);
        $id = (new GpInvoices(self::$db))->add(self::sys(), self::invoice(['sales' => '100.00']))['id'];
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('done', self::state($id));
        $v = (int) self::$db->value('SELECT MAX(spread_version) FROM gp_fee_order');
        self::$db->exec("UPDATE gp_invoice SET spread_state = 'due' WHERE id = ?", [$id]);
        GpFees::$afterPlan = static function (): void {
            usleep(1_200_000); // the plan of a big month took past the deadline
        };
        $d = (new GpFees(self::$db, null, 1.0))->run(self::sys())['vpg_online'];
        self::assertGreaterThan(0, $d['left']);
        self::assertSame('due', self::state($id), 'left due for the next run');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM gp_fee_order WHERE spread_version > ?', [$v]), 'no day written past the deadline');
        GpFees::$afterPlan = null;
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('done', self::state($id));
    }

    public function testM7ASettingsChangeThatCouldNotFinishResumesWhereItStopped(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-01T10:00:00Z', 5000);
        $this->store($vpg, '9100003', '2026-11-02T10:00:00Z', 5000);
        $this->store($vpg, '9100002', '2026-11-05T10:00:00Z', 5000);
        (new GpFees(self::$db))->run(self::sys());
        // a setting that changes which orders count (Vape and Go's own first day moved to 3 Nov): every day from the first is gone over again
        $this->set('profit.gp_shop_from_vapeandgo', '2026-11-03');
        $other = TestDb::connect();
        $other->exec('START TRANSACTION');
        ProfitDays::guard($other, (int) $vpg->channelId, ['2026-11-02']);
        try {
            $d1 = (new GpFees(self::$db, null, 1.0))->run(self::sys())['vpg_online'];
        } finally {
            $other->exec('ROLLBACK');
        }
        self::assertGreaterThan(0, $d1['left'], 'a held day and the deadline: days left');
        $r1 = self::$db->one("SELECT state, CAST(left_days AS CHAR) AS l FROM gp_run WHERE account = 'vpg_online' ORDER BY id DESC LIMIT 1") ?? [];
        $left = json_decode((string) ($r1['l'] ?? 'null'), true);
        self::assertSame('partial', (string) $r1['state'], 'not a good run');
        self::assertIsArray($left);
        self::assertSame('2026-11-02', $left[0] ?? null, 'it keeps the days still to go over, its busy day among them (the check of 513fa3d, H2: apart from the others)');
        self::assertNotContains('2026-11-01', $left, 'the day it went over is not gone over again');
        $d2 = (new GpFees(self::$db))->run(self::sys())['vpg_online'];
        self::assertSame(count($left), $d2['days'], 'the next run goes over exactly those days, not from the account\'s first day');
        $r2 = self::$db->one("SELECT state, left_days FROM gp_run WHERE account = 'vpg_online' ORDER BY id DESC LIMIT 1") ?? [];
        self::assertSame(['ok', null], [(string) $r2['state'], $r2['left_days']]);
        self::assertSame(0, (new GpFees(self::$db))->run(self::sys())['vpg_online']['days'], 'then nothing to go over again');
        self::assertSame(['off', 'off', 'estimate'], [(string) self::row($vpg, '9100001')['state'], (string) self::row($vpg, '9100003')['state'],
            (string) self::row($vpg, '9100002')['state']]);
    }

    public function testF6AQueuedMonthSaysEstimated(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        (new GpInvoices(self::$db))->add(self::sys(), self::invoice());
        $real = static fn (): array => (new ExpenseViews(self::$db))->realProfit('2026-11-01', '2026-11-30', (int) $vpg->channelId, false)[0];
        self::assertFalse($real()['gp_due']);
        self::$db->exec("INSERT INTO gp_due (account, month, queued_at) VALUES ('vpg_online', '2026-11', UTC_TIMESTAMP(6))");
        self::assertTrue($real()['gp_due'], 'the month is not its invoice until it is spread again: estimated');
        (new GpFees(self::$db))->run(self::sys());
    }

    public function testF7ASpreadCoversTheChangesQueuedBeforeIt(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        $id = (new GpInvoices(self::$db))->add(self::sys(), self::invoice())['id'];
        self::$db->exec("INSERT INTO gp_due (account, month, queued_at) VALUES ('vpg_online', '2026-11', UTC_TIMESTAMP(6))");
        self::$db->exec("UPDATE gp_invoice SET spread_state = 'due' WHERE id = ?", [$id]);
        self::assertSame('done', (new GpFees(self::$db))->settleMonth(self::sys(), 'vpg_online', '2026-11'), 'its plan holds that change: done at once');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM gp_due'), 'the queue it covered taken away');
    }

    public function testF8AMonthQueuedMinutesAgoIsNoSixHourWait(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        $id = (new GpInvoices(self::$db))->add(self::sys(), self::invoice())['id'];
        self::$db->exec('UPDATE gp_invoice SET updated_at = UTC_TIMESTAMP(6) - INTERVAL 7 HOUR, spread_at = UTC_TIMESTAMP(6) - INTERVAL 7 HOUR WHERE id = ?', [$id]);
        $this->store($vpg, '9100002', '2026-11-20T10:00:00Z', 5000); // a late order: its month queued now
        $other = TestDb::connect();
        $other->exec('START TRANSACTION');
        ProfitDays::guard($other, (int) $vpg->channelId, ['2026-11-20']);
        try {
            (new GpFees(self::$db))->run(self::sys()); // the spread gives way: still due
        } finally {
            $other->exec('ROLLBACK');
        }
        self::assertSame('due', self::state($id));
        self::assertSame([], array_values(array_filter(GpInvariants::check(self::$db), static fn (string $l): bool => str_starts_with($l, 'GP4'))),
            'due since the queue row of minutes ago, not since the invoice was saved');
        (new GpFees(self::$db))->run(self::sys());
        self::assertSame('done', self::state($id));
    }

    public function testF9VapeBigsCardFeesNoteItsOwnFirstDay(): void
    {
        $this->site('vapeandgo', 'live');
        $vb = $this->site('vapebig', 'live');
        $this->on();
        $row = (new ExpenseViews(self::$db))->realProfit('2026-12-01', '2026-12-31', (int) $vb->channelId, false)[0];
        self::assertSame('2026-12-01', $row['gp_from'], 'the later of the account\'s first day and Vape Big\'s own');
    }

    public function testF10ARowWhoseOrderLostItsPaidDayIsSwitchedOffWithTheDaysFigure(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $this->on();
        $this->store($vpg, '9100001', '2026-11-19T10:00:00Z', 5000);
        self::assertSame([51, 51, 0], self::day($vpg, '2026-11-19'));
        // its paid day cleared without the card fees (as the release before would, on an undo): the day still holds its fee
        self::$db->exec("UPDATE sale_order SET day = NULL, paid_at = NULL WHERE order_ref = '9100001'");
        (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-19');
        self::assertSame('off', (string) self::row($vpg, '9100001')['state']);
        self::assertSame([0, 0, 0], self::day($vpg, '2026-11-19'), 'its fee taken off the day it counted on');
        // paid again: the day's other figures as a rebuild has them, then its fee back
        self::$db->exec("UPDATE sale_order SET day = '2026-11-19', paid_at = '2026-11-19 10:00:00' WHERE order_ref = '9100001'");
        ProfitDays::rebuild(self::$db, (int) $vpg->channelId, '2026-11-19');
        (new GpFees(self::$db))->syncDay('vpg_online', '2026-11-19');
        self::assertSame([51, 51, 0], self::day($vpg, '2026-11-19'));
        self::same($vpg, '2026-11-19');
    }
}
