<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\Stock;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;

/**
 * Availability views and the change feed (§3 GET /v1/changes, snapshot; §14 "Feed").
 * A tiny site mirror applies values with the version guard, as the connector will.
 */
final class FeedTest extends StockTestCase
{
    /** @var array<string, array<string, mixed>> variant => last applied view */
    private array $mirror = [];

    /** @param list<array<string, mixed>> $views @return int how many were applied */
    private function apply(array $views): int
    {
        $n = 0;
        foreach ($views as $v) {
            $have = $this->mirror[$v['variant_id']]['version'] ?? -1;
            if ($v['version'] > $have) {
                $this->mirror[$v['variant_id']] = $v;
                $n++;
            }
        }
        return $n;
    }

    private function poll(Caller $site, int &$after): array
    {
        $c = $this->avail->changes((int) $site->channelId, $after);
        $after = $c['next_after'];
        if ($c['resync']) {
            $page = ['next_after_listing' => 0];
            while ($page['next_after_listing'] !== null) {
                $page = $this->avail->snapshot((int) $site->channelId, $page['next_after_listing'], 2);
                $this->apply($page['listings']);
            }
        }
        $this->apply($c['listings']);
        return $c;
    }

    public function testViewsGiveStatesAndListingUnits(): void
    {
        $site = $this->site();
        $this->listing($site, 'IN', $this->item('strict', 3));
        $this->listing($site, 'OUT', $this->item('strict', 0));
        $this->listing($site, 'BO', $this->item('backorder', 0));
        $this->listing($site, 'ST', $this->item('stopped', 9));
        $this->listing($site, 'LEG', $this->item('legacy', 4));
        $this->listing($site, 'Q', $this->item('strict', 9), 1, 'quarantined');
        $this->listing($site, 'TEN', $this->item('strict', 29), 10);
        $this->listing($site, 'UN', null);

        $got = [];
        foreach ($this->avail->forVariants((int) $site->channelId, ['IN', 'OUT', 'BO', 'ST', 'LEG', 'Q', 'TEN', 'UN', 'GHOST']) as $v) {
            $got[$v['variant_id']] = [$v['state'], $v['available']];
        }
        self::assertSame([
            'IN' => ['in_stock', 3], 'OUT' => ['out_of_stock', 0], 'BO' => ['backorder', 0], 'ST' => ['stopped', 9],
            'LEG' => ['legacy', 4], 'Q' => ['stopped', 9], 'TEN' => ['in_stock', 2], 'UN' => ['unlinked', null], 'GHOST' => ['unlinked', null],
        ], $got);
        self::assertSame('CW-', substr((string) $this->view($site, 'IN')['sku_code'], 0, 3));
    }

    public function testStockChangesReachTheSiteWithNewerVersions(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $after = 0;
        $this->poll($site, $after);
        self::assertSame(5, $this->mirror['V1']['available']);
        $v0 = $this->mirror['V1']['version'];

        $this->reserve($site, '1', [self::line('V1', 'a', 'b')]);
        $c = $this->poll($site, $after);
        self::assertSame(['V1'], array_column($c['listings'], 'variant_id'));
        self::assertSame(3, $this->mirror['V1']['available']);
        self::assertGreaterThan($v0, $this->mirror['V1']['version']);

        // held -> allocated and a dispatch do not change availability: no new feed row
        $seq = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');
        $this->commit($site, '1', [self::line('V1', 'a', 'b')]);
        $this->ship($site, '1', ['a', 'b']);
        self::assertSame($seq, (int) self::$db->value('SELECT MAX(seq) FROM stock_change'));
    }

    public function testRemapPolicyChangeAndWarehouseChangeAllReachTheSite(): void
    {
        $site = $this->site();
        $other = $this->site('alt');
        $a = $this->item('strict', 5);
        $b = $this->item('strict', 8);
        $listing = $this->listing($site, 'V1', $a);
        $this->listing($other, 'W1', $b);
        $after = 0;
        $this->poll($site, $after);
        self::assertSame(5, $this->mirror['V1']['available']);

        $this->relink($listing, $b);
        $this->poll($site, $after);
        self::assertSame([8, $this->mirror['V1']['sku_code']], [$this->mirror['V1']['available'], sprintf('CW-%06d', $b)]);

        $this->ok($this->stock->setPolicy(self::staff(), $b, 'stopped', $this->key('p')));
        $this->poll($site, $after);
        self::assertSame(['stopped', 'stopped'], [$this->mirror['V1']['state'], $this->mirror['V1']['policy']]);

        // a stock change of the old item no longer concerns the re-linked listing
        $this->book('goods_in', $a, 3);
        $c = $this->avail->changes((int) $site->channelId, $after, 5000, 0);
        self::assertSame([], $c['listings']);

        self::$db->exec("INSERT INTO warehouse (code, name, is_sellable) VALUES ('SECOND', 'Second building', 1)");
        $this->ok($this->stock->setPolicy(self::staff(), $b, 'strict', $this->key('p')));
        $this->poll($site, $after);
        $beforeWarehouse = $after;
        $this->ok($this->stock->assignSellableWarehouse(self::staff(), (int) $site->channelId, 'SECOND', $this->key('wh')));
        $c = $this->poll($site, $after);
        self::assertTrue($c['resync']);
        self::assertSame(['out_of_stock', 0], [$this->mirror['V1']['state'], $this->mirror['V1']['available']]);
        // the other site is not told to re-snapshot
        self::assertFalse($this->avail->changes((int) $other->channelId, $beforeWarehouse, 5000, 0)['resync']);
    }

    public function testQuarantineAndUnlinkReachTheSite(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $listing = $this->listing($site, 'V1', $sku);
        $after = 0;
        $this->poll($site, $after);
        self::assertSame('in_stock', $this->mirror['V1']['state']);

        $this->relink($listing, $sku, 'quarantined');
        $this->poll($site, $after);
        self::assertSame(['stopped', 'quarantined'], [$this->mirror['V1']['state'], $this->mirror['V1']['link']]);

        $this->relink($listing, null, 'unmapped');
        $this->poll($site, $after);
        self::assertSame(['unlinked', null, null], [$this->mirror['V1']['state'], $this->mirror['V1']['available'], $this->mirror['V1']['sku_code']]);
    }

    public function testTheVersionGuardNeverAppliesAnOlderValue(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);

        $this->commit($site, '1', [self::line('V1', 'a')]);            // out-of-order commits (no holds)
        $r1 = $this->avail->forVariants((int) $site->channelId, ['V1']);  // read: 9
        $this->commit($site, '3', [self::line('V1', 'c', 'd')]);
        $r2 = $this->avail->forVariants((int) $site->channelId, ['V1']);  // read: 7
        $this->commit($site, '2', [self::line('V1', 'b')]);
        $r3 = $this->avail->forVariants((int) $site->channelId, ['V1']);  // read: 6
        self::assertLessThan($r2[0]['version'], $r1[0]['version']);
        self::assertLessThan($r3[0]['version'], $r2[0]['version']);

        // delivered newest first, then the older responses: only the newest sticks
        self::assertSame(1, $this->apply($r3));
        self::assertSame(0, $this->apply($r1));
        self::assertSame(0, $this->apply($r2));
        self::assertSame(0, $this->apply($r3), 'a re-delivery of the same version is a no-op');
        self::assertSame(6, $this->mirror['V1']['available']);
    }

    public function testFeedSeqsAreAllocatedInCommitOrderSoAVersionNeverGoesBackwards(): void
    {
        $site = $this->site();
        $a = $this->item('strict', 5);
        $b = $this->item('strict', 5);
        $this->listing($site, 'A', $a);
        $this->listing($site, 'B', $b);
        $main = self::warehouseId('MAIN');

        // another connection changes item A and has not committed yet
        $other = TestDb::connect();
        $otherStock = new Stock($other);
        $other->pdo()->beginTransaction();
        $otherStock->lock([[$main, $a]]);
        $otherStock->apply($main, $a, 'on_hand', 1, ['type' => 'adjustment', 'actor' => 'system:test']);
        $otherStock->flush();
        $pending = (int) $other->value('SELECT MAX(seq) FROM stock_change');

        // a change of an unrelated item (no common balance lock) cannot take a feed seq meanwhile:
        // otherwise a higher seq could become visible first and the lower one would never beat it
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            self::assertSame(1205, self::mysqlError(fn () => $this->moves->record(self::staff(),
                ['type' => 'goods_in', 'doc_ref' => 'P-B', 'lines' => [['sku_id' => $b, 'qty' => 1]]], 'gi-b')));
        } finally {
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        $other->pdo()->commit();

        $this->ok($this->moves->record(self::staff(), ['type' => 'goods_in', 'doc_ref' => 'P-B', 'lines' => [['sku_id' => $b, 'qty' => 1]]], 'gi-b'));
        self::assertSame($pending, $this->view($site, 'A')['version']);
        self::assertGreaterThan($pending, $this->view($site, 'B')['version']);
        self::assertSame([6, 6], [$this->view($site, 'A')['available'], $this->view($site, 'B')['available']]);
    }

    public function testEachPollReReadsARecentOverlapWindow(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '1', [self::line('V1', 'a')]);
        $max = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');

        $c = $this->avail->changes((int) $site->channelId, $max, 5000, 60);
        self::assertSame(['V1'], array_column($c['listings'], 'variant_id'), 'rows of the last 60 s are read again');
        self::assertSame($max, $c['next_after']);
        $c = $this->avail->changes((int) $site->channelId, $max, 5000, 0);
        self::assertSame([], $c['listings']);
    }

    public function testChangesArePagedAndSnapshotsArePagedByListing(): void
    {
        $site = $this->site();
        $skus = [];
        for ($i = 1; $i <= 5; $i++) {
            $skus[$i] = $this->item('strict', $i);
            $this->listing($site, "V{$i}", $skus[$i]);
        }
        $c = $this->avail->changes((int) $site->channelId, 0, 3, 0);
        self::assertTrue($c['more']);
        $c2 = $this->avail->changes((int) $site->channelId, $c['next_after'], 5000, 0);
        self::assertFalse($c2['more']);
        $seen = array_merge(array_column($c['listings'], 'variant_id'), array_column($c2['listings'], 'variant_id'));
        self::assertSame(['V1', 'V2', 'V3', 'V4', 'V5'], array_values(array_unique($seen)));

        $pages = [];
        $after = 0;
        do {
            $p = $this->avail->snapshot((int) $site->channelId, $after, 2);
            $pages[] = array_column($p['listings'], 'variant_id');
            $after = $p['next_after_listing'];
        } while ($after !== null);
        self::assertSame([['V1', 'V2'], ['V3', 'V4'], ['V5']], $pages);
    }

    public function testAnotherChannelsListingsAreNotInTheFeed(): void
    {
        $site = $this->site();
        $other = $this->site('alt');
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->listing($other, 'W1', $sku);
        $this->listing($other, 'W2', $this->item('strict', 5));
        $c = $this->avail->changes((int) $site->channelId, 0, 5000, 0);
        self::assertSame(['V1'], array_column($c['listings'], 'variant_id'));
    }

    /**
     * R3: a channel-wide row (warehouse re-assignment, D38) makes the site re-snapshot once. The
     * overlap re-read (rows of the last 10 s with seq <= after) used to raise resync again on every
     * poll inside the window: ~5 full snapshots of the channel per change at the 2 s cadence.
     */
    public function testAChannelWideChangeTriggersOneResyncNotOnePerPollOfTheOverlapWindow(): void
    {
        $site = $this->site('vpg', 'live');
        $this->listing($site, 'V1', $this->item('strict', 5));
        self::$db->exec("INSERT INTO warehouse (code, name, is_sellable) VALUES ('MAIN2', 'Second sellable', 1)");
        $this->ok($this->stock->assignSellableWarehouse(self::staff(), (int) $site->channelId, 'MAIN2', $this->key('wh')));

        $c1 = $this->avail->changes((int) $site->channelId, 0); // default overlap (10 s)
        self::assertTrue($c1['resync']);
        $c2 = $this->avail->changes((int) $site->channelId, $c1['next_after']);
        $c3 = $this->avail->changes((int) $site->channelId, $c2['next_after']);
        self::assertSame($c1['next_after'], $c3['next_after'], 'nothing new was written');
        self::assertFalse($c2['resync'] || $c3['resync'], 'the site already re-snapshotted for this row');
        // the overlap still re-delivers the listings of recent item rows (second line of defence)
        $this->commit($site, '1', [self::line('V1', 'a')]);
        $c4 = $this->avail->changes((int) $site->channelId, $c3['next_after']);
        $c5 = $this->avail->changes((int) $site->channelId, $c4['next_after']);
        self::assertSame(['V1'], array_column($c5['listings'], 'variant_id'));
        self::assertFalse($c4['resync'] || $c5['resync']);
    }
}
