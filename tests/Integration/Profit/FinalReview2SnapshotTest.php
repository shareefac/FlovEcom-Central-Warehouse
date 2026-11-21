<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Profit\OrderSnapshots;
use CW\Profit\PendingCosts;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;
use CW\Profit\RecostSales;
use CW\Profit\ReturnsSweep;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The second final review of 3c31d94 (build/A/codex_final2_astra_{a,b,c}.txt; final2/FIX_BRIEF.md), the orders' side, each shown failing
 * first with the reviewers' numbers: an order ref in another case never stops the money work or drops a product from its day (F3a, a-HIGH4,
 * b-M3, c-M7, a-M8); one order whose work fails never stops the others (F3b); the API waits about a second for another snapshot of the order
 * and a couple for its queue row (F5, a-M1, b-M10); the re-cost counts what it wrote (F7); a bulk replay of a refused order is refused, and a
 * busy one is tried again (F8); a replacement of a replacement puts its cost on the first order (F14); the nightly returns check gives each
 * store its turn (F20); the lowest-margin list counts what it shows (F18).
 */
final class FinalReview2SnapshotTest extends StockTestCase
{
    private const PAID = '2026-11-19T10:00:00Z';
    private const DAY = '2026-11-19';

    private static function price(int $sku, ?int $e4, string $at = '2026-11-16 00:00:00'): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, 'start', ?, ?)",
            [$sku, $at, $at, 'start:' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
    }

    /** @return array<string, mixed> */
    private static function sl(string $id, string $variant, int $price, array $over = []): array
    {
        return $over + ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    /** @return array<string, mixed> */
    private static function body(array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => self::PAID,
            'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '12', 'method' => 'Royal Mail Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0,
            'grand_inc' => 0, 'parcels' => []];
    }

    /** @return array<string, mixed> */
    private static function lineRow(int $ch, string $id): array
    {
        return (array) self::$db->one('SELECT * FROM sale_line WHERE channel_id = ? AND line_id = ?', [$ch, $id]);
    }

    /** @return array<string, mixed> */
    private static function order(int $ch, string $ref): array
    {
        return (array) self::$db->one('SELECT * FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$ch, $ref]);
    }

    /** An order whose money work throws, whatever the reason (its stored snapshot made unreadable for apply()). */
    private static function breakOrder(int $ch, string $ref): void
    {
        self::$db->exec("UPDATE order_snapshot SET body = JSON_OBJECT('paid_at', NULL, 'lines', 5) WHERE channel_id = ? AND order_ref = ?", [$ch, $ref]);
    }

    /** F3a (a-HIGH4, b-M3): stored with its cost waiting before its reservation came as vpg42: the ownership check compares without case. */
    public function testAReservationWhoseRefIsInAnotherCaseNeverStopsTheOrder(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy', 10);
        $this->listing($site, '1', $a);
        // Paid and stored while the cost job has not reached its time (its cost waits), before its reservation reached CW.
        (new OrderSnapshots(self::$db))->store($ch, 'VPG42', self::body([self::sl('u42', '1', 1200)]), 'api');
        self::assertNull(self::lineRow($ch, 'u42')['cost_source'], 'its cost waits');
        $this->ok($this->commit($site, 'vpg42', [self::line('1', 'u42')]));
        self::price($a, 30000);
        $r = (new PendingCosts(self::$db))->run();
        self::assertSame([1, 0], [$r['orders'], $r['failed']], 'the same order: its cost fixed, never refused as a unit of another order');
        self::assertSame(['cw_cost', '300'], [(string) self::lineRow($ch, 'u42')['cost_source'], (string) self::lineRow($ch, 'u42')['cost']]);
    }

    /**
     * F3a (a-M8, c-M7): ORD1 with a £12 line, then ord1 adding a £6 line of another item: the new line is stored under the order's own ref,
     * so the day and its products add up (the rebuild grouped it apart and dropped it: the day said £15, its products £10).
     */
    public function testALineSentUnderTheOrdersRefInAnotherCaseCountsInItsDayAndProducts(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        self::price($a, 30000);
        self::price($b, 20000);
        $this->listing($site, '1', $a);
        $this->listing($site, '2', $b);
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, 'ORD1', self::body([self::sl('L1', '1', 1200)]), 'api');
        $s->store($ch, 'ord1', self::body([self::sl('L1', '1', 1200), self::sl('L2', '2', 600)], ['version' => 1001]), 'api');
        self::assertSame(['ORD1', 'ORD1'], array_map('strval', self::$db->column('SELECT CAST(order_ref AS BINARY) FROM sale_line WHERE channel_id = ? ORDER BY line_id',
            [$ch])), 'every line under the order\'s own ref');
        self::assertSame([], ProfitDays::differences(self::$db, $ch, self::DAY), 'the day and its products add up');
        ProfitDays::rebuild(self::$db, $ch, self::DAY);
        self::assertSame(['1500', '2'], [(string) self::$db->value('SELECT SUM(sale_ex) FROM profit_item_day WHERE channel_id = ? AND day = ?', [$ch, self::DAY]),
            (string) self::$db->value('SELECT COUNT(*) FROM profit_item_day WHERE channel_id = ? AND day = ?', [$ch, self::DAY])], 'both products, £15');
    }

    /** F3b: one order whose work fails is counted and named; the next orders' costs are fixed, and the job's row says it. */
    public function testOneOrderThatFailsNeverStopsTheCostsThatWait(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        $this->listing($site, '1', $a);
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, 'A1', self::body([self::sl('LA', '1', 1200)]), 'api');
        $s->store($ch, 'B1', self::body([self::sl('LB', '1', 1200)]), 'api');
        self::breakOrder($ch, 'A1');
        self::price($a, 30000);
        $r = (new PendingCosts(self::$db))->run();
        self::assertSame([1, 1], [$r['orders'], $r['failed']]);
        self::assertStringContainsString('A1', json_encode($r['errors'], JSON_THROW_ON_ERROR), 'the order that failed is named');
        self::assertSame('cw_cost', (string) self::lineRow($ch, 'LB')['cost_source'], 'the next order got its cost');
        $job = json_decode((string) self::$db->value("SELECT CAST(summary AS CHAR) FROM profit_job WHERE job = 'pending_costs'"), true);
        self::assertSame(1, $job['failed'] ?? null, 'the cost job\'s --status reads it');
    }

    /** @return array{0: Caller, 1: int} a store with two shipped orders 9001 (u1) and 9002 (u2), each with its unit back in stock since its snapshot */
    private function twoReturned(): array
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy', 10);
        self::price($a, 10000);
        $this->listing($site, '1', $a);
        $s = new OrderSnapshots(self::$db);
        foreach (['9001' => 'u1', '9002' => 'u2'] as $ref => $u) {
            $this->ok($this->commit($site, (string) $ref, [self::line('1', $u)]));
            $this->ok($this->ship($site, (string) $ref, [$u]));
            $s->store($ch, (string) $ref, self::body([self::sl($u, '1', 1200, ['refund_inc' => 1200])]), 'api');
            $this->ok($this->res->returnUnits($site, (string) $ref, [$u], $this->key('ret')));
        }
        return [$site, $ch];
    }

    /** F3b: the returns sweep goes on past an order whose work fails, and says so. */
    public function testOneOrderThatFailsNeverStopsTheReturnsSweep(): void
    {
        [, $ch] = $this->twoReturned();
        self::breakOrder($ch, '9001');
        $r = (new ReturnsSweep(self::$db, static fn (): \DateTimeImmutable => Clock::now()->modify('+1 hour')))->run();
        self::assertSame([1, 1], [$r['orders'], $r['failed']]);
        self::assertSame('1', (string) self::lineRow($ch, 'u2')['restocked'], 'the next order is worked out again');
    }

    /** F3b: the nightly returns check too. */
    public function testOneOrderThatFailsNeverStopsTheNightlyReturnsCheck(): void
    {
        [, $ch] = $this->twoReturned();
        self::breakOrder($ch, '9001');
        $r = (new ReturnsSweep(self::$db))->reconcile();
        self::assertSame([1, 1], [$r['orders'], $r['failed']]);
        self::assertSame('1', (string) self::lineRow($ch, 'u2')['restocked']);
    }

    /** F20 (a-M3): one store at a time: with at most 2 orders a store, the second store's returned unit is found too (it waited behind the first). */
    public function testTheNightlyReturnsCheckGivesEachStoreItsTurn(): void
    {
        $a = $this->item('legacy', 20);
        self::price($a, 10000);
        $s = new OrderSnapshots(self::$db);
        $n = 0;
        $last = null;
        foreach ([[$this->site('vapebig', 'live'), 3], [$this->site('electrofag', 'live'), 1]] as [$site, $orders]) {
            $this->listing($site, '1', $a);
            for ($i = 0; $i < $orders; $i++) {
                $n++;
                $ref = (string) (9100 + $n);
                $u = 'r' . $n;
                $this->ok($this->commit($site, $ref, [self::line('1', $u)]));
                $this->ok($this->ship($site, $ref, [$u]));
                $s->store((int) $site->channelId, $ref, self::body([self::sl($u, '1', 1200, ['refund_inc' => 1200])]), 'api');
                $this->ok($this->res->returnUnits($site, $ref, [$u], $this->key('ret')));
                $last = [(int) $site->channelId, $u];
            }
        }
        $r = (new ReturnsSweep(self::$db))->reconcile(2);
        self::assertSame(3, $r['orders'], 'two of the first store and the one of the second');
        self::assertSame('1', (string) self::lineRow($last[0], $last[1])['restocked'], 'the second store had its turn');
    }

    /** F5 (a-M1, b-M10): another snapshot of the same order being stored: the API waits about a second, then 503 busy (it waited five). */
    public function testTheApiWaitsAboutASecondForAnotherSnapshotOfTheOrder(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::intakeLockName($ch, '5001')]));
        try {
            $t = microtime(true);
            try {
                (new OrderSnapshots(self::$db))->accept($ch, '5001', self::body([self::sl('L51', '1', 1200)]), 'api');
                self::fail('busy');
            } catch (CwException $e) {
                self::assertSame(['busy', 503], [$e->errorCode, $e->httpStatus]);
            }
            self::assertLessThan(2.5, microtime(true) - $t, 'about a second, never five');
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::intakeLockName($ch, '5001')]);
        }
    }

    /** F5: the order's queue row held by another writer's commit: the API waits a couple of seconds, then 503 busy; its session's wait is put back. */
    public function testTheApiWaitsAtMostACoupleOfSecondsForTheOrdersQueueRow(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $s = new OrderSnapshots(self::$db);
        $s->accept($ch, '5002', self::body([self::sl('L52', '1', 1200)]), 'api');
        $was = (int) self::$db->value('SELECT @@SESSION.innodb_lock_wait_timeout');
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one('SELECT seq FROM order_snapshot_queue WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [$ch, '5002']);
        try {
            $t = microtime(true);
            try {
                $s->accept($ch, '5002', self::body([self::sl('L52', '1', 2400)], ['version' => 1001]), 'api');
                self::fail('busy');
            } catch (CwException $e) {
                self::assertSame(['busy', 503], [$e->errorCode, $e->httpStatus]);
            }
            self::assertLessThan(4.0, microtime(true) - $t, 'a couple of seconds at most');
        } finally {
            $other->pdo()->rollBack();
        }
        self::assertSame($was, (int) self::$db->value('SELECT @@SESSION.innodb_lock_wait_timeout'), 'the request\'s short wait never stays on the connection');
    }

    /** F7 (a-M6, b-M7, c-M4): a re-cost rolled back at its commit (the API storing a snapshot of the order) is counted as left, never as written. */
    public function testTheReCostCountsOnlyWhatItWrote(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        self::price($a, null);
        $this->listing($site, '1', $a);
        (new OrderSnapshots(self::$db))->store($ch, '6001', self::body([self::sl('L61', '1', 1200)]), 'api');
        self::assertSame('none', (string) self::lineRow($ch, 'L61')['cost_source']);
        self::price($a, 30000, '2026-11-17 00:00:00'); // a price found since, of before the sale
        $other = TestDb::connect();
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::intakeLockName($ch, '6001')]));
        try {
            $r = (new RecostSales(self::$db))->run(self::DAY, self::DAY, ['none'], null, true);
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [OrderSnapshots::intakeLockName($ch, '6001')]);
        }
        self::assertSame([0, 0, 1], [$r['orders'], $r['lines'], $r['deferred']], 'left for later, never counted as re-costed');
        self::assertSame('none', (string) self::lineRow($ch, 'L61')['cost_source'], 'nothing was written');
        $r = (new RecostSales(self::$db))->run(self::DAY, self::DAY, ['none'], null, true);
        self::assertSame([1, 1, 0], [$r['orders'], $r['lines'], $r['deferred']]);
        self::assertSame('cw_cost', (string) self::lineRow($ch, 'L61')['cost_source']);
    }

    /** F8 (a-M7): the same snapshot sent again by a bulk file while the money job refused it is refused, never "same". */
    public function testABulkReplayOfASnapshotTheMoneyJobRefusedIsRefused(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $s = new OrderSnapshots(self::$db);
        $s->accept($ch, '7001', self::body([self::sl('L71', '1', 1200)]), 'bulk');
        self::$db->exec("UPDATE order_snapshot_queue SET failed = 1, error = 'bad_snapshot: line L71 belongs to another order' WHERE channel_id = ? AND order_ref = ?",
            [$ch, '7001']);
        try {
            $s->store($ch, '7001', self::body([self::sl('L71', '1', 1200)]), 'bulk');
            self::fail('a refused order is never "same"');
        } catch (CwException $e) {
            self::assertSame('bad_snapshot', $e->errorCode);
            self::assertStringContainsString('refused by the money job', $e->getMessage());
        }
        self::assertNull(self::$db->value('SELECT 1 FROM sale_order WHERE channel_id = ? AND order_ref = ?', [$ch, '7001']));
    }

    /** F8 (Fable L3): the minute job holding the order's guard a moment longer than a wait: the bulk file waits and tries again, never "busy". */
    public function testABulkFileTriesAnOrderAgainWhileTheMinuteJobHoldsIt(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, '1', $a);
        $name = OrderSnapshots::lockName($ch, '7002');
        $code = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . '; $db = \CW\Tests\Support\TestDb::connect(); '
            . 'if ((int) $db->value("SELECT GET_LOCK(?, 0)", [' . var_export($name, true) . ']) !== 1) { exit(1); } echo "held\n"; usleep(2500000);';
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($p);
        try {
            self::assertSame("held\n", fgets($pipes[1]), 'the minute job holds the order');
            $r = (new OrderSnapshots(self::$db, lockWaitSec: 1))->store($ch, '7002', self::body([self::sl('L72', '1', 1200)]), 'bulk');
            self::assertSame('stored', $r['result']);
            self::assertSame('300', (string) self::order($ch, '7002')['cost'], 'worked out once the job let it go');
        } finally {
            fclose($pipes[1]);
            proc_close($p);
        }
    }

    /**
     * F14 (a-M9, c-M6, Fable L6; the owner: the cost goes on the first order): a £12 sale with £3 cost, its replacement (£3) and a replacement
     * of that replacement (£3): the first order carries both (£6), its profit with them £1 and 10% (it said £4: the second one's cost sat on
     * the first replacement). Each replacement's cost stays on its own day.
     */
    public function testAReplacementOfAReplacementPutsItsCostOnTheFirstOrder(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, '1', $a);
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, '8100', self::body([self::sl('L81', '1', 1200)]), 'api');
        $s->store($ch, '8101', self::body([self::sl('L82', '1', 0, ['free' => true])], ['parent_ref' => '8100']), 'api');
        $s->store($ch, '8102', self::body([self::sl('L83', '1', 0, ['free' => true])], ['parent_ref' => '8101']), 'api');
        $first = self::order($ch, '8100');
        self::assertSame(['2', '600', '1000'], [(string) $first['resends'], (string) $first['resend_cost'], (string) $first['margin_bp']], '£10 - £3 - £6 = £1, 10%');
        self::assertSame(['0', '0'], [(string) self::order($ch, '8101')['resends'], (string) self::order($ch, '8101')['resend_cost']],
            'the first replacement carries none: they belong to the first order');
        self::assertSame('900', (string) self::$db->value('SELECT cost FROM profit_day WHERE channel_id = ? AND day = ?', [$ch, self::DAY]), 'each cost on its own day');
        self::assertSame([], ProfitDays::differences(self::$db, $ch, self::DAY));
        $row = (new ProfitViews(self::$db))->orders(self::DAY, self::DAY, $ch, '8100', false)['rows'][0];
        self::assertSame([600, 100], [$row['resend_cost'], $row['with_resends']]);
    }

    /** F14: whichever arrives first, the first order follows its chain; a replacement with no price at the end makes its profit not known. */
    public function testTheFirstOrderFollowsItsChainWhicheverArrivesFirst(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        self::price($a, 30000);
        self::price($b, null);
        $this->listing($site, '1', $a);
        $this->listing($site, '2', $b);
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, '8200', self::body([self::sl('L91', '1', 1200)]), 'api');
        $s->store($ch, '8202', self::body([self::sl('L93', '2', 0, ['free' => true])], ['parent_ref' => '8201']), 'api'); // before the one it names
        $s->store($ch, '8201', self::body([self::sl('L92', '1', 0, ['free' => true])], ['parent_ref' => '8200']), 'api');
        $first = self::order($ch, '8200');
        self::assertSame(['2', '300'], [(string) $first['resends'], (string) $first['resend_cost']]);
        self::assertNull($first['margin_bp'], 'a replacement of its chain has no buying price: not known');
        $row = (new ProfitViews(self::$db))->orders(self::DAY, self::DAY, $ch, '8200', false)['rows'][0];
        self::assertSame([null, true], [$row['with_resends'], $row['incomplete']], 'the list says so too');
        // A cycle (bad data) never loops.
        $s->store($ch, '8200', self::body([self::sl('L91', '1', 1200)], ['version' => 1001, 'parent_ref' => '8202']), 'api');
        self::assertNotNull(self::order($ch, '8200')['parent_ref']);
    }

    /** F18 (b-L1, c-L2): the lowest margin first counts the orders it can show (it counted a replacement with no margin). */
    public function testTheLowestMarginListCountsWhatItShows(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $ch = (int) $site->channelId;
        $a = $this->item('legacy');
        self::price($a, 30000);
        $this->listing($site, '1', $a);
        $s = new OrderSnapshots(self::$db);
        $s->store($ch, '8300', self::body([self::sl('L31', '1', 1200)]), 'api');
        $s->store($ch, '8301', self::body([self::sl('L32', '1', 0, ['free' => true])], ['parent_ref' => '8300']), 'api');
        $r = (new ProfitViews(self::$db))->orders(self::DAY, self::DAY, $ch, '', true);
        self::assertSame([1, 1], [$r['total'], count($r['rows'])], 'one order with a margin: one counted');
    }
}
