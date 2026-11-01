<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Queues;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Queues\Corrections;
use CW\Queues\NightlyConfirm;
use CW\Relay\RelayIntake;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The nightly Confirm all (the owner, 1 Nov 2026: "Make it automatic", then "Yes, build it" on NightlyConfirm.dc.html; docs/decisions.md
 * NC1-NC12), on real relayed corrections: off, or outside the night's window (UK time), a tick does nothing; inside it, it confirms the
 * ready ones as Confirm all ready does (the same rules, gate, deadline and run rows), at most "At most per night", leaves the rest, and runs
 * once a night (a second tick, two at once: one run). The job's system caller passes nowhere else: not one by one, not Confirm all, not
 * closing, not another system job or a store. The job itself (bin/confirm_corrections_nightly.php) as cron runs it: twice at once, one run.
 * The stock invariants hold after every test (StockTestCase).
 */
final class NightlyConfirmTest extends StockTestCase
{
    private Caller $site;
    /** @var list<array<string, mixed>> the nightly settings as they were */
    private array $was = [];
    private string $setUpAt = '';
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->site();
        $this->grant($this->site, 'erp_reconciliation');
        $this->was = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, updated_actor, updated_at FROM app_setting WHERE setting_key IN (?, ?, ?, ?)',
            [NightlyConfirm::SWITCH, NightlyConfirm::AT, NightlyConfirm::MAX, Corrections::BIG_SETTING]);
        $this->setUpAt = Clock::db(Clock::now()->modify('-3 hours'));
    }

    protected function tearDown(): void
    {
        foreach ($this->was as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), updated_actor = ?, updated_at = ? WHERE setting_key = ?',
                [$r['v'], $r['updated_actor'], $r['updated_at'], $r['setting_key']]);
        }
        parent::tearDown();
    }

    // ---- fixtures ------------------------------------------------------------------------------------------------------------------

    /** The switch on since 02:59 UK on 1 Nov 2026, turned on by $who; the time it runs and the most per night. */
    private function on(string $at = '04:30', int $max = 500, ?Caller $who = null): void
    {
        $who ??= $this->person('reviewer');
        self::$db->exec("UPDATE app_setting SET value_json = CAST('true' AS JSON), updated_actor = ?, updated_at = '2026-11-01 02:59:00.000000' WHERE setting_key = ?",
            [$who->actor, NightlyConfirm::SWITCH]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [json_encode($at), NightlyConfirm::AT]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [(string) $max, NightlyConfirm::MAX]);
    }

    /** An item with $onHand, sold as $variants, its stock booked three hours ago; and its correction to $set, made two hours ago. */
    private function corrected(int $onHand, int $set, int $variants = 1): array
    {
        $v = 'N' . (++$this->seq);
        $sku = $this->item('strict', $onHand);
        for ($i = 0; $i < $variants; $i++) {
            $this->listing($this->site, $v . ($i === 0 ? '' : '-' . $i), $sku);
        }
        self::$db->exec('UPDATE stock_ledger SET created_at = ? WHERE sku_id = ?', [$this->setUpAt, $sku]);
        $doc = 'MAT-RECO-' . $v;
        $this->ok((new RelayIntake(self::$db))->reconciliations($this->site, ['doc_ref' => $doc,
            'posted_at' => Clock::now()->modify('-2 hours')->format('Y-m-d\TH:i:s.u\Z'), 'lines' => [['variant_id' => $v, 'qty' => $set]]], $this->key('reco')));
        return ['sku' => $sku, 'id' => (int) self::$db->value('SELECT id FROM count_review WHERE ref = ?', [$doc])];
    }

    private function person(string $role): Caller
    {
        $n = bin2hex(random_bytes(3));
        $uid = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES (?, ?, ?, 'x')",
            ["nc-{$role}-{$n}", "NC {$role}", "nc-{$role}-{$n}@test.invalid"]);
        self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$uid, $role]);
        return Caller::staff($uid);
    }

    /** A tick at $utc (the window's clock; the bookings keep the real one). @return array<string, mixed> */
    private static function tick(string $utc): array
    {
        return (new NightlyConfirm(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable($utc, new \DateTimeZone('UTC'))))->tick();
    }

    private static function runs(): int
    {
        return (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_type = 'count_review' AND entity_id LIKE 'nightly:%'", [Corrections::RUN_ACTION]);
    }

    private static function state(int $id): string
    {
        return (string) self::$db->value('SELECT CAST(status AS CHAR) FROM count_review WHERE id = ?', [$id]);
    }

    /** @return array{code: int, out: string, err: string} */
    private static function job(): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/confirm_corrections_nightly.php", '--db=' . TestDb::name(), '--admin'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    // ---- the tests -------------------------------------------------------------------------------------------------------------------

    public function testOffOrOutsideTheWindowNothingHappens(): void
    {
        $a = $this->corrected(10, 13);
        $before = $this->ledgerCount();
        self::assertSame('off', self::tick('2026-11-02 04:35:00')['result'], 'off by default (0055)');
        self::assertSame(['relay.nightly_confirm' => 'false', 'relay.nightly_confirm_at' => '"04:30"', 'relay.nightly_confirm_max' => '500'],
            array_column(self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v FROM app_setting WHERE setting_key IN (?, ?, ?) ORDER BY setting_key',
                [NightlyConfirm::SWITCH, NightlyConfirm::AT, NightlyConfirm::MAX]), 'v', 'setting_key'));
        $this->on('04:30');
        // Outside: before the time, an hour after it, the day's other half; and UK time across the clock change (25 Oct: 03:30 UTC is 03:30 UK).
        foreach (['2026-11-02 04:29:59', '2026-11-02 05:30:00', '2026-11-02 16:30:00', '2026-10-25 03:30:00'] as $t) {
            self::assertSame('outside', self::tick($t)['result'], $t);
        }
        self::assertSame([0, $before, 'open'], [self::runs(), $this->ledgerCount(), self::state($a['id'])]);
    }

    public function testInsideTheWindowItConfirmsTheReadyOnesLeavesTheRestAndSavesItsRun(): void
    {
        $who = $this->person('reviewer');
        $a = $this->corrected(10, 13);
        $b = $this->corrected(10, 7);
        $big = $this->corrected(0, 400);
        $two = $this->corrected(5, 6, 2);
        $this->on('04:30', 500, $who);
        $r = self::tick('2026-11-02 04:31:00');
        self::assertSame(['ran', '2026-11-02'], [$r['result'], $r['night']]);
        $run = (new Corrections(self::$db))->run((int) $r['run']);
        self::assertSame(['finished', [$a['id'], $b['id']], 2, 3, 3], [$run['state'], $run['confirmed'], $run['booked'], $run['units_added'], $run['units_taken']]);
        self::assertSame([[$big['id'], 'big'], [$two['id'], 'variants']], array_map(static fn (array $l): array => [$l['id'], $l['why']['code']], $run['left']));
        self::assertSame([13, 7, 0, 5], [$this->bal($a['sku'])['on_hand'], $this->bal($b['sku'])['on_hand'], $this->bal($big['sku'])['on_hand'], $this->bal($two['sku'])['on_hand']]);
        // The run: the job's system caller, the night's mark, the note that says whose switch it is.
        $row = self::$db->one('SELECT actor, staff_user_id, entity_type, entity_id, CAST(detail AS CHAR) AS d FROM audit_log WHERE id = ?', [$r['run']]);
        self::assertSame(['system:confirm_nightly', null, 'count_review', 'nightly:2026-11-02'], [$row['actor'], $row['staff_user_id'], $row['entity_type'], $row['entity_id']]);
        $note = 'Nightly Confirm all (setting on since 1 Nov 2026, 02:59, by NC reviewer)';
        self::assertSame($note, json_decode((string) $row['d'], true)['note']);
        self::assertSame($run['actor'], 'system:confirm_nightly');
        // Each confirmation as one by one would book it, by the job: the row closed by CW (nobody), the note on it, the press's mark.
        foreach ([$a, $b] as $c) {
            $q = self::$db->one('SELECT CAST(status AS CHAR) AS s, resolution, resolved_by, note, CAST(detail AS CHAR) AS d FROM count_review WHERE id = ?', [$c['id']]);
            self::assertSame(['resolved', 'booked', null, $note, (int) $r['run']], [$q['s'], $q['resolution'], $q['resolved_by'], $q['note'], json_decode((string) $q['d'], true)['booked']['run']]);
            self::assertSame(['adjustment', 'system:confirm_nightly'], array_values((array) self::$db->one("SELECT movement_type, actor FROM stock_ledger WHERE sku_id = ? "
                . "AND movement_type = 'adjustment'", [$c['sku']])));
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'count_review.confirm' AND entity_id = ? AND actor = 'system:confirm_nightly'",
                [(string) $c['id']]));
        }
        self::assertSame(['open', 'open'], [self::state($big['id']), self::state($two['id'])]);
        // What it booked is a person's to look at and undo like any confirmation: a person may not confirm it again.
        $this->expectRefusal(fn () => (new Corrections(self::$db))->confirm($this->person('stock_controller'), $a['id'], 'again by hand'), 409, 'queue_settled');
    }

    public function testOnceANightASecondTickDoesNothingTheNextNightRuns(): void
    {
        $a = $this->corrected(1, 3);
        $this->on('04:30');
        self::assertSame('ran', self::tick('2026-11-02 04:30:00')['result']);
        $b = $this->corrected(1, 4);
        $before = $this->ledgerCount();
        foreach (['2026-11-02 04:35:00', '2026-11-02 05:29:59'] as $t) {
            $r = self::tick($t);
            self::assertSame(['done', '2026-11-02'], [$r['result'], $r['night']], $t);
        }
        self::assertSame([1, $before, 'open'], [self::runs(), $this->ledgerCount(), self::state($b['id'])], 'one run a night: the new one waits');
        self::assertSame('ran', self::tick('2026-11-03 04:30:00')['result'], 'the next night');
        self::assertSame([2, 'resolved', 'resolved'], [self::runs(), self::state($a['id']), self::state($b['id'])]);
    }

    public function testTwoStartsAtOnceMakeOneRun(): void
    {
        $a = $this->corrected(1, 3);
        $this->on('04:30');
        // Another start holds the night's lock: this one does nothing, and says so.
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [NightlyConfirm::lockName(self::$db)]));
        self::assertSame('busy', self::tick('2026-11-02 04:31:00')['result']);
        self::assertSame([0, 'open'], [self::runs(), self::state($a['id'])]);
        $other->value('SELECT RELEASE_LOCK(?)', [NightlyConfirm::lockName(self::$db)]);
        self::assertSame('ran', self::tick('2026-11-02 04:32:00')['result']);
        self::assertSame(1, self::runs());
        unset($other);
    }

    /** The job as cron runs it, twice at the same moment: one run; and its log lines. */
    public function testTheJobTwiceAtOnceIsOneRun(): void
    {
        $a = $this->corrected(1, 3);
        $off = self::job();
        self::assertSame(0, $off['code'], $off['err']);
        self::assertMatchesRegularExpression('/confirm_nightly \[' . preg_quote(TestDb::name(), '/') . '\] off: relay\.nightly_confirm is off$/m', $off['out']);
        $uk = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/London')))->modify('-1 minute');
        $this->on($uk->format('H:i'));
        $root = dirname(__DIR__, 3);
        $procs = [];
        for ($i = 0; $i < 2; $i++) {
            $p = proc_open([PHP_BINARY, "{$root}/bin/confirm_corrections_nightly.php", '--db=' . TestDb::name(), '--admin'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            self::assertIsResource($p);
            $procs[] = [$p, $pipes];
        }
        $outs = [];
        foreach ($procs as [$p, $pipes]) {
            $outs[] = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($p), implode("\n", $outs));
        }
        self::assertSame(1, self::runs(), implode("\n", $outs));
        self::assertSame(1, preg_match_all('/\bran \d{4}-\d{2}-\d{2}: run \d+, 1 confirmed \(1 booked\), 0 left/', implode("\n", $outs)), implode("\n", $outs));
        self::assertSame('resolved', self::state($a['id']));
        $again = self::job();
        self::assertMatchesRegularExpression('/\bdone already tonight \(\d{4}-\d{2}-\d{2}\): run \d+$/m', $again['out'], $again['out'] . $again['err']);
    }

    public function testTheCapIsAtMostPerNight(): void
    {
        $a = $this->corrected(1, 3);
        $b = $this->corrected(1, 3);
        $c = $this->corrected(1, 3);
        $this->on('04:30', 2);
        $r = self::tick('2026-11-02 04:40:00');
        $run = (new Corrections(self::$db))->run((int) $r['run']);
        self::assertSame([[$a['id'], $b['id']], [['id' => $c['id'], 'why' => ['code' => 'not_reached']]]], [$run['confirmed'], $run['left']]);
        self::assertSame('open', self::state($c['id']), 'waits for the next night or for Confirm all ready');
    }

    /**
     * NC13 (the Codex review of 774f39e): a ready correction behind a full page of ones that need a look is found; the search reads the
     * queue page by page (the oldest page of 500 all "big" used to hide it every night).
     */
    public function testAReadyOneBehindAFullPageOfDoubtfulOnesIsFound(): void
    {
        $big = $this->corrected(0, 400);
        $cols = 'warehouse_id, sku_id, source, proposed_qty, ref, detail, status, created_at';
        for ($i = 1; $i < 500; $i++) {
            self::$db->exec("INSERT INTO count_review ({$cols}) SELECT {$cols} FROM count_review WHERE id = ?", [$big['id']]);
        }
        $ready = $this->corrected(10, 13);
        $this->on('04:30');
        $r = self::tick('2026-11-02 04:40:00');
        self::assertSame(['ran', 1], [$r['result'], $r['confirmed']], json_encode($r));
        self::assertSame('resolved', self::state($ready['id']));
        self::assertSame('open', self::state($big['id']), 'the doubtful ones wait for a person');
        // Small pages: the same, page after page; the cap stops the search.
        $more = [$this->corrected(1, 2), $this->corrected(1, 3), $this->corrected(1, 4)];
        $this->on('04:30', 2);
        $r = (new NightlyConfirm(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-03 04:40:00', new \DateTimeZone('UTC')), null, 7))->tick();
        $run = (new Corrections(self::$db))->run((int) $r['run']);
        self::assertSame([$more[0]['id'], $more[1]['id']], $run['confirmed']);
        self::assertSame('open', self::state($more[2]['id']), 'at most per night');
    }

    /**
     * NC14 (the Codex review of 774f39e): the run's one deadline counts the search too. A search that took 236 of the 240 seconds leaves
     * the press 4: nothing more is started ("not reached"), the run is saved with its end, and the next night runs as usual.
     */
    public function testTheSearchCountsInTheNightsDeadline(): void
    {
        $a = $this->corrected(10, 13);
        $this->on('04:30');
        // The run's clock: 0 s through the search; 236 s from the first read after it (the note's), so the press is left 4 seconds.
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\StatementHook::class, [static function (string $sql) use (&$now): void {
            if (str_contains($sql, 'SELECT updated_actor, updated_at FROM app_setting')) {
                $now = 236.0;
            }
        }]]);
        try {
            $r = (new NightlyConfirm(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-02 04:40:00', new \DateTimeZone('UTC')), $clock))->tick();
        } finally {
            self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        $run = (new Corrections(self::$db))->run((int) $r['run']);
        self::assertSame(['finished', [], [['id' => $a['id'], 'why' => ['code' => 'not_reached']]]], [$run['state'], $run['confirmed'], $run['left']]);
        self::assertSame('open', self::state($a['id']));
        self::assertSame('ran', self::tick('2026-11-03 04:40:00')['result']);
        self::assertSame('resolved', self::state($a['id']), 'the next night confirms it');
    }

    /**
     * NC14, CW-111 (the Codex review of 0317222): no read of the search starts after its time, however slow the reads before it (each read
     * here "takes" 25 s on the run's clock: the third finds the search's 60 s gone); the search ends with what it found, the press runs.
     */
    public function testNoReadOfTheSearchStartsAfterItsTime(): void
    {
        $this->corrected(10, 13);
        $this->on('04:30');
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $reads = 0;
        self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\CW\Tests\Support\StatementHook::class, [static function (string $sql) use (&$now, &$reads): void {
            if ($now < 60.0 && str_starts_with(ltrim($sql), 'SELECT') && !str_contains($sql, '@@SESSION') && !str_contains($sql, 'GET_LOCK')) {
                $reads++;
                $now += 25.0;
            }
        }]]);
        try {
            $r = (new NightlyConfirm(self::$db, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-02 04:40:00', new \DateTimeZone('UTC')), $clock))->tick();
        } finally {
            self::$db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [\PDOStatement::class]);
        }
        self::assertSame('ran', $r['result']);
        self::assertLessThanOrEqual(3, $reads, 'the search stopped at its time: ' . $reads . ' reads');
        self::assertSame(0, $r['confirmed'], 'the page did not fit: nothing found tonight');
    }

    /**
     * NC14 (the Codex re-check of 3de1a33): one page of the search runs a few fixed statements (Corrections::readStatements()), whatever the
     * rows on it, each checked against the time left (Db::readsUntil); a page is one chunk of the reads (no more statements for a full page).
     */
    public function testOnePageOfTheSearchRunsAFewFixedStatements(): void
    {
        self::assertLessThanOrEqual(200, NightlyConfirm::PAGE, 'one chunk of readiness()\'s reads');
        $selects = static fn (): int => (int) self::$db->one("SHOW SESSION STATUS LIKE 'Com_select'")['Value'];
        $counts = [];
        foreach ([1, 3] as $n) {
            for ($i = 0; $i < $n; $i++) {
                $this->corrected(10, 13 + $i);
            }
            $before = $selects();
            $rows = (new Corrections(self::$db))->readiness(NightlyConfirm::PAGE);
            $counts[] = $selects() - $before;
            self::assertNotSame([], $rows);
        }
        self::assertLessThanOrEqual(Corrections::readStatements(NightlyConfirm::PAGE), max($counts), json_encode($counts));
        self::assertSame($counts[0], $counts[1], 'the same few statements for more rows: ' . json_encode($counts));
    }

    /**
     * NC16 (the Codex review of 774f39e): a night's run with no end row yet while its job still holds the night's lock is "running", not
     * "stopped"; without the lock, or started longer ago than the job's longest run, it stopped.
     */
    public function testARunStillGoingIsRunningNotStopped(): void
    {
        $a = $this->corrected(1, 3);
        $job = Caller::system(Corrections::NIGHTLY_JOB);
        \CW\Audit::write(self::$db, $job, Corrections::RUN_ACTION, Corrections::TABLE, 'nightly:2026-11-02', null, ['state' => 'running', 'note' => 'n', 'asked' => 1, 'ids' => [$a['id']]]);
        $id = (int) self::$db->value('SELECT LAST_INSERT_ID()');
        $queue = new Corrections(self::$db);
        self::assertSame('stopped', $queue->run($id)['state'], 'no lock held: it stopped');
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [NightlyConfirm::lockName(self::$db)]));
        try {
            self::assertSame('running', $queue->run($id)['state']);
            self::$db->exec('UPDATE audit_log SET created_at = ? WHERE id = ?', [Clock::db(Clock::now()->modify('-6 minutes')), $id]);
            self::assertSame('stopped', $queue->run($id)['state'], 'older than the longest run: another night\'s job holds the lock');
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [NightlyConfirm::lockName(self::$db)]);
        }
    }

    /** NC4: the job's caller passes only through the nightly press, with the switch on; nowhere else, and no other caller through it. */
    public function testTheJobsCallerPassesOnlyThroughTheNightlyPress(): void
    {
        $a = $this->corrected(1, 3);
        $nightly = Caller::system(Corrections::NIGHTLY_JOB);
        $queue = new Corrections(self::$db);
        foreach ([$nightly, Caller::system('housekeeping'), $this->site] as $who) {
            $this->expectRefusal(fn () => $queue->confirm($who, $a['id'], 'not one by one'), 403, 'staff_required');
            $this->expectRefusal(fn () => $queue->confirmAll($who, [$a['id']], 'not Confirm all'), 403, 'staff_required');
            $this->expectRefusal(fn () => $queue->dismiss($who, $a['id'], 'not closing'), 403, 'staff_required');
        }
        // The switch off: the nightly press itself refuses, before anything is written.
        $this->expectRefusal(fn () => $queue->confirmNightly('2026-11-02', [$a['id']], 'Nightly Confirm all (a test)', 500), 409, 'nightly_off');
        self::assertSame([0, 'open'], [(int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [Corrections::RUN_ACTION]), self::state($a['id'])]);
        // The other queues keep refusing it.
        $this->expectRefusal(fn () => (new \CW\Queues\Suspense(self::$db))->dismiss($nightly, 1, 'not a line to place'), 403, 'staff_required');
        // On: the nightly press books as the job; afterwards the same caller is refused one by one again.
        $this->on('04:30');
        $done = $queue->confirmNightly('2026-11-02', [$a['id']], 'Nightly Confirm all (a test)', 500);
        self::assertSame([$a['id']], $done['confirmed']);
        $b = $this->corrected(1, 4);
        $this->expectRefusal(fn () => $queue->confirm($nightly, $b['id'], 'not one by one, after the press either'), 403, 'staff_required');
        self::assertSame('open', self::state($b['id']));
    }

    private function expectRefusal(\Closure $fn, int $status, string $code): void
    {
        try {
            $fn();
        } catch (CwException $e) {
            self::assertSame([$status, $code], [$e->httpStatus, $e->errorCode], $e->getMessage());
            return;
        }
        self::fail("expected {$status} {$code}");
    }
}
