<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Profit\CostEngine;
use CW\Profit\OrderSnapshots;
use CW\Profit\PendingCosts;
use CW\Profit\ProfitDays;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * The Codex review of 92efa82 (build/A/codex_r1_{a,b,c}.txt), the order side, each shown failing first: a sale paid after the cost job's
 * mark waits for it (b1/a1); a unit of another order's reservation (b6); an older version with the same content (b20); a tracking number
 * that is not one (b12); a margin past INT (a12); a snapshot and a rebuild wait for the same day (c1, a7, b10); a re-send and its first
 * order wait for each other (a8, b9) and two first snapshots of one order too (b8).
 */
final class ProfitReviewR1Test extends StockTestCase
{
    private ?Db $other = null;

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            try {
                $this->other->pdo()->exec('ROLLBACK');
                $this->other->value('SELECT RELEASE_ALL_LOCKS()');
            } catch (\Throwable) {
            }
            $this->other = null;
        }
        parent::tearDown();
    }

    private static function price(int $sku, ?int $e4, string $at = '2026-11-16 00:00:00', string $kind = 'start'): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec('INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, ?, ?, ?)',
            [$sku, $at, $at, $kind, $kind . ':' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
    }

    private static function mark(string $at): void
    {
        self::$db->exec('INSERT INTO profit_job (job, mark_at) VALUES (?, ?) AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at', [CostEngine::JOB, $at]);
    }

    private static function sl(string $id, string $variant, int $price, array $over = []): array
    {
        return $over + ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    private static function body(array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VPG1', 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z',
            'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'globalpay', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => null, 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []];
    }

    private static function row(Caller $site, string $id): array
    {
        return (array) self::$db->one('SELECT * FROM sale_line WHERE channel_id = ? AND line_id = ?', [$site->channelId, $id]);
    }

    public function testASaleWaitsForTheCostJobToReachItsPaidTime(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $this->listing($site, '1', $a);
        self::price($a, 10000);
        self::mark('2026-11-19 09:00:00');
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, '1', self::body([self::sl('L1', '1', 1200)]), 'api');
        $l = self::row($site, 'L1');
        self::assertSame([null, '2026-11-19', '0'], [$l['cost_source'], $l['day'], (string) $l['cost']], 'paid after the job\'s mark: not fixed yet');
        // The job reads a delivery at 09:30 that moves the price to £2.00, then passes 10:00: the sale takes the price of its time.
        self::price($a, 20000, '2026-11-19 09:30:00', 'start'); // a later price, as an absolute event
        self::mark('2026-11-19 10:05:00');
        self::assertSame(1, (new PendingCosts(self::$db))->run()['orders']);
        $l = self::row($site, 'L1');
        self::assertSame(['cw_cost', '20000', '200', 'sale'], [$l['cost_source'], (string) $l['unit_cost_e4'], (string) $l['cost'], $l['costed_by']]);
        self::assertSame('200', (string) self::$db->value('SELECT cost FROM profit_day WHERE day = ?', ['2026-11-19']));
        self::assertSame(0, (new PendingCosts(self::$db))->run()['orders'], 'done once');
        // Never started: every paid line waits.
        self::$db->exec('DELETE FROM profit_job');
        $s = new OrderSnapshots(self::$db); // the job's mark is read once per service (a request, a run)
        $s->store((int) $site->channelId, '2', self::body([self::sl('L2', '1', 1200)], ['paid_at' => '2026-11-17T10:00:00Z']), 'api');
        self::assertNull(self::row($site, 'L2')['cost_source']);
    }

    public function testTheSnapshotsChecksTheUnitsOrderTheVersionFirstAndTheTrackingNumber(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy', 5);
        $this->listing($site, '1', $a);
        self::price($a, 10000);
        self::mark('2026-11-20 00:00:00');
        $this->ok($this->commit($site, 'B', [self::line('1', 'u9')]));
        $s = new OrderSnapshots(self::$db);
        try {
            $s->store((int) $site->channelId, 'A', self::body([self::sl('u9', '1', 1200)]), 'api');
            self::fail('a unit of order B sent on order A');
        } catch (CwException $e) {
            self::assertSame(['bad_snapshot', 'lines[0].line_id'], [$e->errorCode, $e->detail['field'] ?? null]);
        }
        self::assertSame('stored', $s->store((int) $site->channelId, 'B', self::body([self::sl('u9', '1', 1200)], ['version' => 1005]), 'api')['result']);
        self::assertSame(['result' => 'older_ignored', 'version' => 1005], $s->store((int) $site->channelId, 'B', self::body([self::sl('u9', '1', 1200)]), 'api'),
            'an older version is older, whatever its content (CONTRACT)');
        $parcel = static fn (?string $t): array => ['parcel_ref' => 'odl:' . random_int(1, 999_999_999), 'tracking_no' => $t, 'carrier' => 'dpd', 'service_code' => '',
            'service' => '', 'status' => 'generated', 'label_at' => null, 'shipped_at' => null, 'manual' => false, 'partial' => false];
        $s->store((int) $site->channelId, 'C', self::body([self::sl('c1', '1', 100)], ['parcels' => [$parcel('15501234567890'), $parcel('customer@example.invalid'),
            $parcel('John Smith 12345'), $parcel('07123456789'), $parcel('ab 123 456 789 gb'), $parcel('1550-1234-56')]]), 'api');
        $tracks = array_column(json_decode((string) self::$db->value("SELECT CAST(body AS CHAR) FROM order_snapshot WHERE order_ref = 'C'"), true)['parcels'], 'tracking_no');
        self::assertSame(['15501234567890', null, null, null, 'AB123456789GB', '1550123456'], $tracks,
            'only a carrier\'s own shape is kept (CONTRACT change 5b), as its invoice prints it: no free text, no phone number reaches CW');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM sale_parcel WHERE tracking_no LIKE '%@%' OR tracking_no LIKE '%Smith%'"));
        // A margin far below -100%: 1p of sale on £3,000 of cost.
        $big = $this->item('legacy');
        $this->listing($site, '2', $big);
        self::price($big, 30_000_000);
        $s->store((int) $site->channelId, 'D', self::body([self::sl('d1', '2', 120, ['refund_inc' => 119])]), 'api');
        self::assertSame(-2999990000, (int) self::$db->value("SELECT margin_bp FROM sale_order WHERE order_ref = 'D'"));
    }

    public function testASnapshotAndARebuildOfTheSameDayWaitForEachOther(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $this->listing($site, '1', $a);
        self::price($a, 10000);
        self::mark('2026-11-20 00:00:00');
        $s = new OrderSnapshots(self::$db);
        $s->store((int) $site->channelId, '1', self::body([self::sl('L1', '1', 1200)]), 'api');
        // Another session holds the day (as a rebuild does from its start): a snapshot of that day waits for it.
        $this->other = TestDb::connect();
        $this->other->pdo()->exec('START TRANSACTION');
        ProfitDays::guard($this->other, (int) $site->channelId, ['2026-11-19']);
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $code = self::mysqlError(fn () => $s->store((int) $site->channelId, '2', self::body([self::sl('L2', '1', 600)]), 'api'));
            self::assertSame(1205, $code, 'the snapshot waits for the day');
        } finally {
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }
        $this->other->pdo()->exec('ROLLBACK');
        // The snapshot itself was stored (the API's part, PR95); its money work waited and is done now.
        self::assertSame('same', $s->store((int) $site->channelId, '2', self::body([self::sl('L2', '1', 600)]), 'api')['result']);
        self::assertSame('500', (string) self::$db->value("SELECT sale_ex FROM sale_order WHERE channel_id = ? AND order_ref = '2'", [$site->channelId]));
    }

    public function testAReSendAndItsFirstOrderWaitForEachOther(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $a = $this->item('legacy');
        $this->listing($site, '1', $a);
        self::price($a, 10000);
        self::mark('2026-11-20 00:00:00');
        // Another session is storing order 7001 (it holds the order's guard): a re-send of it waits, and so does a second first snapshot.
        $this->other = TestDb::connect();
        self::assertSame(1, (int) $this->other->value('SELECT GET_LOCK(?, 0)', [OrderSnapshots::lockName((int) $site->channelId, '7001')]));
        $s = new OrderSnapshots(self::$db, lockWaitSec: 1);
        foreach (['7002' => ['parent_ref' => '7001'], '7001' => []] as $ref => $over) {
            try {
                $s->store((int) $site->channelId, (string) $ref, self::body([self::sl('x' . $ref, '1', 100)], $over), 'api');
                self::fail("{$ref} did not wait");
            } catch (CwException $e) {
                self::assertSame(['busy', 503], [$e->errorCode, $e->httpStatus], (string) $ref);
            }
        }
        $this->other->value('SELECT RELEASE_ALL_LOCKS()');
        self::assertSame('stored', $s->store((int) $site->channelId, '7002', self::body([self::sl('x7002', '1', 0, ['free' => true])], ['parent_ref' => '7001']), 'api')['result']);
        self::assertSame(0, (int) self::$db->value('SELECT IS_USED_LOCK(?) IS NOT NULL', [OrderSnapshots::lockName((int) $site->channelId, '7002')]), 'released');
    }
}
