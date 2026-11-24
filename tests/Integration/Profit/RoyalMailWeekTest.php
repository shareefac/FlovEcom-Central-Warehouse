<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\CwException;
use CW\Invariants;
use CW\Profit\DeliveryBills;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\RoyalMailBills;
use CW\Tests\Support\RmBill;
use CW\Tests\Support\RmFixtures;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use CW\Tests\Support\TimedStatement;

/**
 * A real Royal Mail week's size (shipping plan v3 section 5.10; docs/decisions.md DL68): 7,000 labels on 7,000 orders, a bill of about 160 rows
 * (7 days of Tracked 48 / 24 in several pre-advice rows, Special Delivery by manifest and band, wrong weight, the tail of the week before, late
 * single items). Stored, replaced and undone each in ONE guarded write within the delivery writes' guard budget (DL41: the guards held at most
 * HOLD_SEC, the whole write within GUARD_BUDGET_SEC or busy) while a snapshot job keeps storing the bill's orders (each snapshot gets its guard
 * within its own 3 s); with one order's guard held elsewhere the bill gives up within the budget holding nothing. Synthetic numbers only.
 * The timings are written to the file CW_TIMING_LOG names (default: the temp folder's rm_week_timing.txt).
 */
final class RoyalMailWeekTest extends StockTestCase
{
    private const START = '2026-10-28 10:28:00.000000';
    private const DAYS = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'];
    private const T48 = 700;
    private const T24 = 290;
    private const SD = 10;

    public function testAWeeksBillIsStoredReplacedAndUndoneWithinTheGuardBudgetWhileSnapshotsRun(): void
    {
        $vpg = (int) $this->site('vapeandgo', 'live')->channelId;
        self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, committed_at, created_at) VALUES (?, '9000000', 'committed', ?, ?)",
            [$vpg, self::START, self::START]);
        $acct = RmFixtures::account(self::$db, $vpg)['account'];
        $log = [];
        // 7,000 orders and their parcels as the money job leaves them (written straight in: 7,000 snapshots would take minutes)
        $orders = [];
        $labels = [];
        $i = 0;
        foreach (self::DAYS as $d => $day) {
            foreach ([['JV', 'TPL48HV', self::T48], ['OX', 'TPM24HV', self::T24], ['AS', 'SD1', self::SD]] as [$prefix, $svc, $n]) {
                for ($k = 0; $k < $n; $k++, $i++) {
                    $tracking = sprintf('%s%09dGB', $prefix, 100000000 + $i);
                    $orders[] = [$vpg, (string) (600000 + $i), (string) (1400000 + $i), $day, $tracking];
                    $sd = $svc === 'SD1' ? ['manifest_no' => 3000 + $d * 2 + ($k % 2), 'sales_order' => (string) (5150000000 + $d * 2 + ($k % 2)),
                        'weight_g' => $k < 4 ? 80 : 300] : ['manifest_no' => 3000 + $d * 2];
                    $labels[] = RmFixtures::label($tracking, $svc, $day, (string) (1400000 + $i), $sd);
                }
            }
        }
        foreach (array_chunk($orders, 500) as $chunk) {
            self::$db->exec('INSERT INTO sale_order (channel_id, order_ref, order_no, paid_at, day, vat_bp, snapshot_version, first_at, updated_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, 2000, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))')),
                array_merge(...array_map(static fn (array $o): array => [$o[0], $o[1], $o[2], $o[3] . ' 09:00:00', $o[3]], $chunk)));
            self::$db->exec('INSERT INTO sale_parcel (channel_id, order_ref, parcel_ref, tracking_no, carrier, label_at, first_at, updated_at) VALUES '
                . implode(', ', array_fill(0, count($chunk), "(?, ?, ?, ?, 'royal_mail', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")),
                array_merge(...array_map(static fn (array $o): array => [$o[0], $o[1], 'odl:' . $o[1], $o[4], $o[3] . ' 10:00:00'], $chunk)));
        }
        foreach (self::DAYS as $day) {
            self::$db->exec('INSERT INTO profit_day (channel_id, day, orders) VALUES (?, ?, 0)', [$vpg, $day]);
        }
        $t = microtime(true);
        $r = RmFixtures::labels(new RoyalMailBills(self::$db), Caller::system('rm_week'), $acct, $labels, 'report');
        $log['labels_import_s'] = round(microtime(true) - $t, 2);
        self::assertSame(7000, $r['added']);
        // the bill: ~160 rows
        $lines = [];
        foreach (self::DAYS as $d => $day) {
            foreach ([431, 150, 80, 30, 9] as $q) {
                $lines[] = RmBill::line($day, 'TPL', $q, (int) round($q * 254.32), (string) (900 + $d));
            }
            foreach ([200, 60, 20, 9] as $q) {
                $lines[] = RmBill::line($day, 'TPM', $q, (int) round($q * 337.88), (string) (900 + $d));
            }
            foreach ([0, 1] as $m) {
                $so = (string) (5150000000 + $d * 2 + $m);
                $lines[] = RmBill::line($day, 'SD1', 2, 2007, 'CLICK AND DROP M' . (3000 + $d * 2 + $m), $so);
                $lines[] = RmBill::line($day, 'SD1', 3, 3185, 'CLICK AND DROP M' . (3000 + $d * 2 + $m), $so);
                $lines[] = RmBill::line($day, 'RX1', 1, 275, "ADJ{$so}RCN Surcharge");
                $lines[] = RmBill::line($day, 'RXA', 1, 125, "ADJ{$so}RCN Surcharge");
            }
            $lines[] = RmBill::line($day, 'RW4', 4, 420, 'Rectification Week 45');
            $lines[] = RmBill::line($day, 'TSS', 1, 270, '');
            $prev = (new \DateTimeImmutable($day))->modify('-7 days')->format('Y-m-d');
            $lines[] = RmBill::line($prev, 'TPL', 3, 763, '777');
            $lines[] = RmBill::line($prev, 'TPM', 1, 338, '777');
            $lines[] = RmBill::line('2026-09-17', 'TPM', 1, 316, '7269');
        }
        $log['bill_rows'] = count($lines);
        self::assertGreaterThanOrEqual(150, count($lines));
        $page = RmBill::page($lines, ['invoice' => '9070000100', 'date' => 'Nov 16, 2026']);
        $timed = static function (): RoyalMailBills {
            $conn = TestDb::connect();
            $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [TimedStatement::class, []]);
            return new RoyalMailBills($conn);
        };
        TimedStatement::$watch = ['SELECT GET_LOCK(?, 0) AS g0', 'SELECT RELEASE_LOCK(?) AS r0'];

        // one order's guard held elsewhere: busy within the budget, nothing left held
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($vpg, '603500')]));
        $t = microtime(true);
        try {
            $timed()->upload(Caller::system('rm_week'), $acct, $page);
            self::fail('busy');
        } catch (CwException $e) {
            self::assertSame('busy', $e->errorCode);
        }
        $log['busy_s'] = round(microtime(true) - $t, 2);
        self::assertLessThan(DeliveryBills::GUARD_BUDGET_SEC + 30, microtime(true) - $t);
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName($vpg, '600000')]), 'nothing left held');
        $other->value('SELECT RELEASE_ALL_LOCKS()');
        self::assertSame([['undone', RoyalMailBills::NOT_STORED]], array_map(static fn (array $r): array => array_values(array_map('strval', $r)),
            self::$db->all('SELECT status, ended_actor FROM carrier_invoice')), 'the bill that could not be stored is kept as not stored: it never counted');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM carrier_allocation WHERE ended_at IS NULL'));
        self::assertSame([], Invariants::check(self::$db));

        // free, with the snapshot job storing 200 of the bill's orders meanwhile: stored, replaced and undone, each one write within the budget
        $snap = array_map(static fn (array $o): array => [$o[0], $o[1], $o[3]], array_slice($orders, 0, 7000, false));
        $snap = array_values(array_filter($snap, static fn (array $o, int $k): bool => $k % 35 === 0, ARRAY_FILTER_USE_BOTH));
        foreach (['store' => null, 'replace' => 'replace', 'undo' => 'undo'] as $what => $_) {
            $pipes = [];
            $worker = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', __DIR__ . '/rm_snapshot_worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes, dirname(__DIR__, 3), ['CW_SLOT' => (string) getenv('CW_SLOT'), 'CW_DB_NAME' => TestDb::name()] + getenv());
            self::assertIsResource($worker);
            fwrite($pipes[0], json_encode(['orders' => $snap, 'until_ms' => 20_000, 'gap_ms' => 100], JSON_THROW_ON_ERROR) . "\n");
            fclose($pipes[0]);
            self::assertSame('running', trim((string) fgets($pipes[1])));
            TimedStatement::$marks = [];
            $t = microtime(true);
            $id = (int) self::$db->value("SELECT id FROM carrier_invoice WHERE status = 'live'");
            try {
                match ($what) {
                    'store' => $timed()->upload(Caller::system('rm_week'), $acct, $page),
                    'replace' => $timed()->replace(Caller::system('rm_week'), $id, RmBill::page([...array_slice($lines, 0, -1), RmBill::line('2026-09-17', 'TPM', 1, 317, '7269')],
                        ['invoice' => '9070000100', 'date' => 'Nov 16, 2026', 'gross' => null])),
                    default => $timed()->undo(Caller::system('rm_week'), $id),
                };
            } finally {
                $log["{$what}_s"] = round(microtime(true) - $t, 2);
                $log["{$what}_holds"] = [];
                $start = null;
                foreach (TimedStatement::$marks as [$p, $at]) {
                    if ($p === 'SELECT GET_LOCK(?, 0) AS g0' && $start === null) {
                        $start = $at;
                    } elseif ($p === 'SELECT RELEASE_LOCK(?) AS r0' && $start !== null) {
                        $log["{$what}_holds"][] = round($at - $start, 3);
                        $start = null;
                    }
                }
                if (getenv('CW_TIMING_DETAIL') === '1') {
                    // every statement of the last hold: its time to the next one, summed by its first words
                    $in = false;
                    $by = [];
                    $marks = TimedStatement::$marks;
                    foreach ($marks as $k => [$p, $at]) {
                        if ($p === 'SELECT GET_LOCK(?, 0) AS g0') {
                            $in = true;
                            $by = [];
                        }
                        if ($in && isset($marks[$k + 1])) {
                            $by[$p] = round(($by[$p] ?? 0) + $marks[$k + 1][1] - $at, 3);
                        }
                        if ($p === 'SELECT RELEASE_LOCK(?) AS r0') {
                            $in = false;
                        }
                    }
                    arsort($by);
                    $log["{$what}_detail"] = array_slice($by, 0, 12, true);
                }
                file_put_contents((string) (getenv('CW_TIMING_LOG') ?: sys_get_temp_dir() . '/rm_week_timing.txt'), json_encode($log, JSON_PRETTY_PRINT) . "\n");
            }
            $log["{$what}_s"] = round(microtime(true) - $t, 2);
            $log["{$what}_guards_held_s"] = round(TimedStatement::longest('SELECT GET_LOCK(?, 0) AS g0', 'SELECT RELEASE_LOCK(?) AS r0'), 2);
            $log["{$what}_guard_statements"] = count(array_filter(TimedStatement::$marks, static fn (array $m): bool => $m[0] === 'SELECT GET_LOCK(?, 0) AS g0'));
            $out = '';
            while (($l = fgets($pipes[1])) !== false) {
                $out = $l;
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($worker);
            $w = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            $log["{$what}_snapshots"] = $w['stores'];
            $log["{$what}_snapshot_wait_max_ms"] = $w['waited_max_ms'];
            self::assertSame(0, $w['missed'], 'every snapshot got its guard within its own wait');
            self::assertLessThan(OrderSnapshots::QUEUE_LOCK_WAIT_SEC * 1000, $w['waited_max_ms']);
            self::assertLessThanOrEqual(DeliveryBills::HOLD_SEC + 0.5, $log["{$what}_guards_held_s"], "{$what}: the guards held within HOLD_SEC");
            self::assertSame([], Invariants::check(self::$db));
        }
        $log['allocations'] = (int) self::$db->value('SELECT COUNT(*) FROM carrier_allocation');
        foreach (self::DAYS as $day) {
            self::assertSame([], array_values(array_filter(ProfitDays::differences(self::$db, $vpg, $day), static fn (string $l): bool => !str_contains($l, 'row of zeros'))));
        }
        self::assertSame([], RoyalMailBills::drift(self::$db, $acct));
        file_put_contents((string) (getenv('CW_TIMING_LOG') ?: sys_get_temp_dir() . '/rm_week_timing.txt'), json_encode($log, JSON_PRETTY_PRINT) . "\n");
    }
}
