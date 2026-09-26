<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Db;
use CW\Tests\Support\OpWorkers;
use CW\Tests\Support\StockTestCase;

/**
 * R4: units a listing sold while it was unlinked (sku NULL, §2.3 holding ledger) are adopted into
 * the buckets when the listing is linked (Reservations::adoptUnlinkedUnits, inside the link
 * transaction; StockTestCase::relink does what the DecisionService will do). Found by the review
 * of 26 Sep (slot review2): such units stayed outside the buckets for ever, so their dispatch
 * never lowered on_hand and a strict item could be oversold without an oversell_event.
 */
final class LinkAdoptionTest extends StockTestCase
{
    use OpWorkers;

    protected function tearDown(): void
    {
        $this->stopWorkers();
        parent::tearDown();
    }

    public function testUnitsSoldWhileUnlinkedEnterTheBucketsWhenTheListingIsLinked(): void
    {
        $vpg = $this->site('vpg', 'live');
        $alt = $this->site('alt', 'live');
        $sku = $this->item('legacy', 5);
        $this->listing($vpg, 'V1', $sku);
        $e1 = $this->listing($alt, 'E1', null); // sister-site listing, not linked yet

        $this->ok($this->commit($alt, 'A-1', [self::line('E1', 'e1')])); // paid, recorded only
        $this->assertBal(5, 0, 0, $sku);
        $this->relink($e1, $sku);                                       // review wave links it
        $this->assertBal(5, 1, 0, $sku);
        self::assertSame($sku, self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = 'e1'"));
        self::assertSame(['adopt'], self::$db->column("SELECT movement_type FROM stock_ledger WHERE unit_id = 'e1'"));
        // the counter finds 5 on the shelf (e1 is still waiting to be packed)
        $this->book('count', $sku, 5, 'MAIN', '2026-09-26T12:00:00Z');
        $this->ok($this->stock->setPolicy(self::staff(), $sku, 'strict', $this->key('policy')));
        self::assertSame(4, $this->view($vpg, 'V1')['available'], 'e1 is promised');
        $r = $this->ship($alt, 'A-1', ['e1'], '2026-09-26T12:30:00Z');
        self::assertSame('shipped', $r->body['units'][0]['result']);
        $this->assertBal(4, 0, 0, $sku);
        self::assertSame(4, $this->view($vpg, 'V1')['available']);
    }

    public function testHeldUnitsAreAdoptedAsHeldWithTheLinksUnitsAndLaterStepsUseTheNewSnapshot(): void
    {
        $alt = $this->site('alt', 'live');
        $sku = $this->item('strict', 20);
        $pack = $this->listing($alt, 'P10', null);
        $this->ok($this->reserve($alt, 'A-2', [self::line('P10', 'p1', 'p2')])); // unlinked: recorded, no hold
        $this->assertBal(20, 0, 0, $sku);

        $this->relink($pack, $sku, 'mapped', 10); // "10 x" listing: 10 central units per listing unit
        $this->assertBal(20, 0, 20, $sku);
        self::assertSame([10, 10], array_map('intval', self::$db->column("SELECT units_per_item FROM reservation_unit ORDER BY unit_id")));
        $this->ok($this->commit($alt, 'A-2', [self::line('P10', 'p1')])); // p2 not paid for: released
        $this->assertBal(20, 10, 0, $sku);
        $this->ship($alt, 'A-2', ['p1']);
        $this->assertBal(10, 0, 0, $sku);
    }

    public function testShippedAndClosedUnitsAreLeftAloneAndAdoptingTwiceChangesNothing(): void
    {
        $alt = $this->site('alt', 'live');
        $sku = $this->item('legacy', 5);
        $e = $this->listing($alt, 'E', null);
        $this->commit($alt, 'S', [self::line('E', 's1', 's2', 's3')]);
        $this->ship($alt, 'S', ['s1']);
        $this->res->cancel($alt, 'S', ['s2'], true, $this->key('cancel'));
        $this->commit($alt, 'O', [self::line('E', 'o1')]);

        $this->relink($e, $sku);
        $this->assertBal(5, 2, 0, $sku); // s3 and o1; s1 left the building before, s2 was cancelled
        self::assertSame(['o1', 's3'], self::$db->column('SELECT unit_id FROM reservation_unit WHERE sku_id IS NOT NULL ORDER BY unit_id'));
        $ledger = $this->ledgerCount();
        $again = self::$db->transaction(fn (Db $db): array => $this->res->adoptUnlinkedUnits(self::staff(), $e));
        self::assertSame(0, $again['adopted']);
        self::assertSame($ledger, $this->ledgerCount());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'listing.adopt_units'"));
        // an unlink or a relink of already-linked units adopts nothing
        $this->relink($e, $this->item('legacy', 0));
        $this->assertBal(5, 2, 0, $sku);
    }

    public function testAdoptionThatPushesAProtectedItemBelowZeroIsFlagged(): void
    {
        $alt = $this->site('alt', 'live');
        $sku = $this->item('strict', 1);
        $e = $this->listing($alt, 'E', null);
        $this->commit($alt, 'X', [self::line('E', 'x1', 'x2', 'x3')]);
        $this->relink($e, $sku);
        $this->assertBal(1, 3, 0, $sku);
        $ev = self::$db->one('SELECT kind, shortfall, available_after, order_ref FROM oversell_event');
        self::assertSame(['adopt_short', 2, -2, 'X'], array_values($ev));
    }

    public function testAdoptionNeedsTheLinkTransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->res->adoptUnlinkedUnits(self::staff(), 1);
    }

    /**
     * A sale of the listing racing the link: reserve/commit read the listing FOR SHARE, so a sale
     * that starts while the link transaction holds the listing row waits and snapshots the new
     * link; nothing is left unlinked behind the adoption.
     */
    public function testASaleRacingTheLinkIsSnapshottedWithTheNewLink(): void
    {
        $alt = $this->site('alt', 'live');
        $sku = $this->item('legacy', 5);
        $e = $this->listing($alt, 'E', null);
        $this->commit($alt, 'A', [self::line('E', 'a1')]);

        $link = self::session(); // the DecisionService's link transaction, in flight
        $link->exec("UPDATE channel_listing SET sku_id = ?, status = 'mapped' WHERE id = ?", [$sku, $e]);
        $w = $this->spawn([self::op($alt, 'commit', ['order_ref' => 'B', 'lines' => [self::line('E', 'b1')], 'key' => 'commit-B'])]);
        $this->waitForLock('channel_listing', 'WAITING');
        (new \CW\Reservations($link, new \CW\Stock($link)))->adoptUnlinkedUnits(self::staff(), $e);
        $link->pdo()->commit();
        $r = $this->collect($w);
        self::assertSame(200, $r['results'][0]['status'], json_encode($r));
        self::assertSame([$sku, $sku], array_map('intval', self::$db->column("SELECT sku_id FROM reservation_unit WHERE unit_id IN ('a1', 'b1') ORDER BY unit_id")));
        $this->assertBal(5, 2, 0, $sku);
    }
}
