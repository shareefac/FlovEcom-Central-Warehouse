<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Reorder;

use CW\Reorder\ReorderJobs;

/**
 * Pack G (docs/decisions.md PG4): the schedule bin/reorder_refresh.php runs every 5 minutes does only what is due. The fold when a UK day
 * is waiting, the demand once a UK day from reorder.demand_hour (again after a failure, not before reorder.refresh_minutes), the list
 * after the demand and every reorder.refresh_minutes; each step's last run is kept with its error.
 */
final class ReorderJobsTest extends ReorderTestCase
{
    private function jobs(string $utc): ReorderJobs
    {
        return new ReorderJobs(self::$db, $this->pos, null, static fn (): \DateTimeImmutable => new \DateTimeImmutable($utc, new \DateTimeZone('UTC')));
    }

    /** @return array{0: bool, 1: bool, 2: bool} which steps ran */
    private static function ran(array $r): array
    {
        return [is_array($r['fold']), is_array($r['demand']), is_array($r['lines'])];
    }

    public function testEachStepRunsWhenItIsDue(): void
    {
        $s = $this->listScenario();
        self::$db->exec('DELETE FROM reorder_job'); // the scenario's own refresh: start from no run at all
        // 03:30 in the UK (02:30 UTC, summer time): before the demand hour (4); the list has never run.
        self::assertSame([false, false, true], self::ran($this->jobs('2026-10-14 02:30:00')->scheduled()));
        // 05:10 in the UK: the demand (never run today), then the list.
        $r = $this->jobs('2026-10-14 04:10:00')->scheduled();
        self::assertSame([false, true, true], self::ran($r));
        self::assertSame(5, $r['demand']['items']);
        // Five minutes later: nothing.
        self::assertSame([false, false, false], self::ran($this->jobs('2026-10-14 04:15:00')->scheduled()));
        // Fifteen minutes after the last list: the list only.
        self::assertSame([false, false, true], self::ran($this->jobs('2026-10-14 04:25:00')->scheduled()));
        // The next UK day before 04:00: nothing new; from 04:00 the demand again.
        self::assertSame([false, false, true], self::ran($this->jobs('2026-10-15 02:00:00')->scheduled()));
        self::assertSame([false, true, true], self::ran($this->jobs('2026-10-15 03:05:00')->scheduled()));
        // A store connected two days ago: its days are folded first.
        self::$db->exec("INSERT INTO channel_opening (channel_id, t0_at, t0_last_order_id) VALUES (?, '2026-10-13 09:00:00', 1)", [$s['vpg']]);
        $r = $this->jobs('2026-10-15 03:15:00')->scheduled();
        self::assertSame([true, false, false], self::ran($r), 'the list ran ten minutes ago');
        self::assertSame(['2026-10-13', '2026-10-14'], array_column($r['fold']['days'], 'date'));
        $state = $this->jobs('2026-10-15 03:15:00')->state();
        self::assertSame(['demand', 'fold', 'lines'], array_keys(array_intersect_key($state, array_flip(['demand', 'fold', 'lines']))));
        self::assertSame([2, 1, 5], [(int) $state['demand']['runs'], (int) $state['fold']['runs'], (int) $state['lines']['runs']]);
        // --force: every step now.
        self::assertSame([true, true, true], self::ran($this->jobs('2026-10-15 03:31:00')->scheduled(true)));
    }

    /**
     * Review finding 3 (Codex, 14 Oct 2026): while CW's own record has days left to fold (more than reorder.fold_days_per_run, or another
     * run is folding), the demand waits; it is worked out the same day once the fold has caught up.
     */
    public function testTheDemandWaitsUntilTheFoldHasCaughtUp(): void
    {
        $s = $this->listScenario();
        self::$db->exec('DELETE FROM reorder_job');
        $this->setting('reorder.fold_days_per_run', '2');
        self::$db->exec("INSERT INTO channel_opening (channel_id, t0_at, t0_last_order_id) VALUES (?, '2026-10-09 09:00:00', 1)", [$s['vpg']]);
        // 05:10 in the UK, after the demand hour: five UK days (9-13 Oct) to fold, two a run.
        $r = $this->jobs('2026-10-14 04:10:00')->scheduled();
        self::assertSame([true, false], [is_array($r['fold']), is_array($r['demand'])], 'three days still to fold: no demand yet');
        self::assertSame(['2026-10-09', '2026-10-10'], array_column($r['fold']['days'], 'date'));
        $r = $this->jobs('2026-10-14 04:15:00')->scheduled();
        self::assertSame([true, false], [is_array($r['fold']), is_array($r['demand'])], 'one day still to fold');
        // Another run is folding: the demand waits for it too.
        $other = \CW\Tests\Support\TestDb::connect();
        $other->value('SELECT GET_LOCK(?, 0)', [\CW\Reorder\SalesFold::LOCK_PREFIX . \CW\Tests\Support\TestDb::name()]);
        try {
            $r = $this->jobs('2026-10-14 04:20:00')->scheduled();
            self::assertSame(['already running', 'waiting for the fold'], [$r['fold'], $r['demand']]);
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [\CW\Reorder\SalesFold::LOCK_PREFIX . \CW\Tests\Support\TestDb::name()]);
        }
        $r = $this->jobs('2026-10-14 04:25:00')->scheduled();
        self::assertSame(['2026-10-13'], array_column($r['fold']['days'], 'date'));
        self::assertTrue(is_array($r['demand']), 'the fold has caught up: the demand is worked out the same day');
        self::assertNull($this->jobs('2026-10-14 04:30:00')->scheduled()['demand'], 'and not again that day');
    }

    public function testAFailedDemandIsKeptAndTriedAgainLater(): void
    {
        $this->listScenario();
        self::$db->exec('DELETE FROM reorder_job');
        $this->setting('reorder.short_weight', '"7"'); // out of range: the build refuses before writing
        $e = self::refused(500, 'bad_setting', fn () => $this->jobs('2026-10-14 04:10:00')->scheduled());
        self::assertStringContainsString('reorder.short_weight', $e->getMessage());
        $row = (array) self::$db->one("SELECT started_at, finished_at, ok_at, error FROM reorder_job WHERE job = 'demand'");
        self::assertSame(['2026-10-14 04:10:00.000000', null], [$row['started_at'], $row['ok_at']]);
        self::assertStringStartsWith('bad_setting: ', (string) $row['error']);
        $this->setting('reorder.short_weight', '"0.50"');
        self::assertSame([false, false, true], self::ran($this->jobs('2026-10-14 04:20:00')->scheduled()), 'the demand not before reorder.refresh_minutes; the list had not run');
        $r = $this->jobs('2026-10-14 04:26:00')->scheduled();
        self::assertSame([false, true, true], self::ran($r));
        self::assertNull(self::$db->value("SELECT error FROM reorder_job WHERE job = 'demand'"));
    }
}
