<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\CwException;
use CW\Reservations;
use CW\Tests\Support\StockTestCase;

/** The reservation state machine (plan §3, §4, §14 "release/expire/re-reserve/commit-without-hold"). */
final class ReservationTest extends StockTestCase
{
    public function testReserveHoldsAndCommitMovesHeldToAllocated(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);

        $r = $this->reserve($site, '1001', [self::line('V1', '11', '12')]);
        self::assertSame(201, $r->status);
        self::assertSame('held', $r->body['result']);
        self::assertSame(1, $r->body['attempt']);
        self::assertSame('2026-09-26T18:40:00.000000Z', $r->body['expires_at'], 'default TTL 40 min');
        self::assertSame(['held', 3], [$r->body['lines'][0]['result'], $r->body['lines'][0]['available']]);
        $this->assertBal(5, 0, 2, $sku);

        $c = $this->commit($site, '1001', [self::line('V1', '12', '11')]);
        self::assertSame(200, $c->status);
        self::assertSame(['committed', []], [$c->body['result'], $c->body['oversell']]);
        $this->assertBal(5, 2, 0, $sku);
        self::assertSame('allocated', $this->unitState($site, '11'));
        self::assertSame('committed', $this->reservation($site, '1001')['status']);
    }

    public function testShortStrictItemIs409WithPerLineAvailableAndHoldsNothing(): void
    {
        $site = $this->site();
        $strict = $this->item('strict', 3);
        $legacy = $this->item('legacy', 0);
        $this->listing($site, 'V1', $strict);
        $this->listing($site, 'V2', $legacy);

        $r = $this->reserve($site, '1002', [self::line('V1', '1', '2', '3', '4'), self::line('V2', '5')]);
        self::assertSame(409, $r->status);
        self::assertSame(['refused', ['short']], [$r->body['error'], $r->body['reasons']]);
        $byVariant = array_column($r->body['lines'], null, 'variant_id');
        self::assertSame(['short', 3], [$byVariant['V1']['result'], $byVariant['V1']['available']]);
        self::assertSame('ok', $byVariant['V2']['result']);
        // all-or-nothing: the legacy line is not held either, and no reservation exists
        $this->assertBal(3, 0, 0, $strict);
        $this->assertBal(0, 0, 0, $legacy);
        self::assertNull($this->reservation($site, '1002'));
    }

    public function testShadowSiteIsNeverRefusedButItsHoldsCount(): void
    {
        $site = $this->site('vpg', 'shadow');
        $sku = $this->item('strict', 1);
        $this->listing($site, 'V1', $sku);

        $r = $this->reserve($site, '1003', [self::line('V1', '1', '2', '3')]);
        self::assertSame(201, $r->status);
        self::assertSame(-2, $r->body['lines'][0]['available']);
        $this->assertBal(1, 0, 3, $sku);
    }

    public function testDuplicateListingsOfOneItemAndATenPackAreSummedInCentralUnits(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 25);
        $this->listing($site, 'A', $sku);
        $this->listing($site, 'B', $sku);
        $this->listing($site, 'TENPACK', $sku, 10);

        // 2 + 3 + 2 x 10 = 25 central units: exactly what is there
        $r = $this->reserve($site, '2001', [self::line('A', 'a1', 'a2'), self::line('B', 'b1', 'b2', 'b3'), self::line('TENPACK', 't1', 't2')]);
        self::assertSame(201, $r->status);
        $this->assertBal(25, 0, 25, $sku);
        self::assertSame(10, self::$db->value("SELECT units_per_item FROM reservation_unit WHERE unit_id = 't1'"));
        self::assertSame(0, $this->view($site, 'TENPACK')['available']);

        $r = $this->reserve($site, '2002', [self::line('A', 'x1')]);
        self::assertSame(409, $r->status);
        self::assertSame(0, $r->body['lines'][0]['available']);

        $this->release($site, '2001');
        // one more pack than fits: 3 x 10 = 30 > 25, and the site is told 2 packs are left
        $r = $this->reserve($site, '2003', [self::line('TENPACK', 'y1', 'y2', 'y3')]);
        self::assertSame(409, $r->status);
        self::assertSame(2, $r->body['lines'][0]['available']);
        // the same need spread over two listings of the item in one basket is summed too
        $r = $this->reserve($site, '2004', [self::line('A', 'z1', 'z2', 'z3', 'z4', 'z5', 'z6'), self::line('TENPACK', 'z7', 'z8')]);
        self::assertSame(409, $r->status, '6 + 20 = 26 > 25');
        $r = $this->reserve($site, '2005', [self::line('A', 'w1', 'w2', 'w3', 'w4', 'w5'), self::line('TENPACK', 'w7', 'w8')]);
        self::assertSame(201, $r->status, '5 + 20 = 25');
        $this->assertBal(25, 0, 25, $sku);
    }

    public function testHeldSameLinesExtendsTheTtlAndOtherLinesAre422(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V2', $sku);

        $this->reserve($site, '3001', [self::line('V1', '1', '2')]);
        $this->now = $this->now->modify('+10 minutes');
        $r = $this->reserve($site, '3001', [self::line('V1', '2', '1')]);
        self::assertSame([200, 'extended', 1], [$r->status, $r->body['result'], $r->body['attempt']]);
        self::assertSame('2026-09-26T18:50:00.000000Z', $r->body['expires_at']);
        self::assertSame('2026-09-26 18:50:00.000000', $this->reservation($site, '3001')['expires_at']);

        $r = $this->reserve($site, '3001', [self::line('V1', '1', '2'), self::line('V2', '3')]);
        self::assertSame([422, 'lines_changed'], [$r->status, $r->body['error']]);
        $this->assertBal(10, 0, 2, $sku);
    }

    public function testReserveOnAPaidOrderSaysAlreadyPaid(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '3002', [self::line('V1', '1')]);
        $this->commit($site, '3002', [self::line('V1', '1')]);

        $r = $this->reserve($site, '3002', [self::line('V1', '1')]);
        self::assertSame([200, 'already_paid', 'committed'], [$r->status, $r->body['result'], $r->body['status']]);
        $this->assertBal(10, 1, 0, $sku);
    }

    public function testReleaseStaleAttemptIsIgnoredAndReReserveIsANewAttempt(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 10);
        $this->listing($site, 'V1', $sku);

        $this->reserve($site, '4001', [self::line('V1', '1', '2')]);
        $r = $this->release($site, '4001', 1);
        self::assertSame(['released', 'released'], [$r->body['result'], $r->body['status']]);
        $this->assertBal(10, 0, 0, $sku);
        self::assertSame('released', $this->unitState($site, '1'));

        $r = $this->reserve($site, '4001', [self::line('V1', '1', '2')]);
        self::assertSame([201, 2], [$r->status, $r->body['attempt']]);
        $this->assertBal(10, 0, 2, $sku);

        // the failed first attempt's release arrives late: ignored
        $r = $this->release($site, '4001', 1);
        self::assertSame([200, 'stale_attempt', 'held'], [$r->status, $r->body['result'], $r->body['status']]);
        $this->assertBal(10, 0, 2, $sku);

        $r = $this->release($site, '4001', 2);
        self::assertSame('released', $r->body['result']);
        $this->assertBal(10, 0, 0, $sku);
        $r = $this->release($site, '4001', 2);
        self::assertSame('already_released', $r->body['result']);
    }

    public function testReReserveOfAReleasedOrderIs409WhenShortAndKeepsItsAttempt(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 1);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '4002', [self::line('V1', '1')]);
        $this->release($site, '4002', 1);
        $this->reserve($site, '4003', [self::line('V1', '9')]); // someone else takes the last unit

        $r = $this->reserve($site, '4002', [self::line('V1', '1')]);
        self::assertSame(409, $r->status);
        self::assertSame(['released', 1], [$this->reservation($site, '4002')['status'], $this->reservation($site, '4002')['attempt']]);
        $this->assertBal(1, 0, 1, $sku);
    }

    public function testReleaseOfAPaidOrderIsUseCancel(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->commit($site, '4004', [self::line('V1', '1')]);

        $r = $this->release($site, '4004', 1);
        self::assertSame([409, 'use_cancel'], [$r->status, $r->body['error']]);
        $this->assertBal(5, 1, 0, $sku);
    }

    public function testReleaseOfAnUnknownRefTombstonesItAndALateReserveCreatesNoHold(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);

        $r = $this->release($site, '5001', 1);
        self::assertSame([200, 'tombstoned'], [$r->status, $r->body['result']]);
        $row = $this->reservation($site, '5001');
        self::assertSame(['released', 1], [$row['status'], $row['is_tombstone']]);

        $r = $this->reserve($site, '5001', [self::line('V1', '1')]);
        self::assertSame([409, 'released'], [$r->status, $r->body['error']]);
        $this->assertBal(5, 0, 0, $sku);
        self::assertNull($this->unitState($site, '1'));
    }

    public function testAPaymentCapturedForATombstonedOrderStillCommits(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->release($site, '5002', 1);

        $c = $this->commit($site, '5002', [self::line('V1', '1', '2')]);
        self::assertSame([200, 'committed', []], [$c->status, $c->body['result'], $c->body['oversell']]);
        self::assertSame('committed', $this->reservation($site, '5002')['status']);
        $this->assertBal(5, 2, 0, $sku);
        $r = $this->reserve($site, '5002', [self::line('V1', '1', '2')]);
        self::assertSame('already_paid', $r->body['result']);
    }

    public function testHoldsExpireAfterTheTtlAndAPaymentAfterExpiryStillCommitsFlagged(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 1);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '6001', [self::line('V1', '1')]);

        $this->now = $this->now->modify('+39 minutes');
        self::assertSame(0, $this->res->expireDue());
        $this->now = $this->now->modify('+2 minutes');
        self::assertSame(1, $this->res->expireDue());
        self::assertSame(0, $this->res->expireDue(), 'expiring is idempotent');
        self::assertSame('expired', $this->reservation($site, '6001')['status']);
        self::assertSame('released', $this->unitState($site, '1'));
        $this->assertBal(1, 0, 0, $sku);

        // the last unit sells to someone else meanwhile ...
        self::assertSame(201, $this->reserve($site, '6002', [self::line('V1', '2')])->status);
        // ... then the first customer's payment is captured: commit is never refused, but flagged
        $c = $this->commit($site, '6001', [self::line('V1', '1')]);
        self::assertSame(200, $c->status);
        self::assertSame([['sku_code' => sprintf('CW-%06d', $sku), 'kind' => 'commit_after_expiry', 'shortfall' => 1, 'available_after' => -1]], $c->body['oversell']);
        $this->assertBal(1, 1, 1, $sku);
        $ev = self::$db->one('SELECT kind, shortfall, order_ref, status FROM oversell_event');
        self::assertSame(['kind' => 'commit_after_expiry', 'shortfall' => 1, 'order_ref' => '6001', 'status' => 'open'], $ev);
    }

    public function testAnExpiredOrderCanBeReservedAgain(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 3);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '6003', [self::line('V1', '1')]);
        $this->now = $this->now->modify('+41 minutes');
        $this->res->expireDue();

        $r = $this->reserve($site, '6003', [self::line('V1', '1')]);
        self::assertSame([201, 2], [$r->status, $r->body['attempt']]);
        $this->assertBal(3, 0, 1, $sku);
        $this->commit($site, '6003', [self::line('V1', '1')]);
        $this->assertBal(3, 1, 0, $sku);
    }

    public function testCommitWithoutAHoldSnapshotsTodaysLinkAndFlagsAStrictShortfall(): void
    {
        $site = $this->site();
        $a = $this->item('strict', 2);
        $b = $this->item('strict', 7);
        $listing = $this->listing($site, 'V1', $a);

        $c = $this->commit($site, '7001', [self::line('V1', '1', '2', '3')]);
        self::assertSame([200, 'committed'], [$c->status, $c->body['result']]);
        self::assertSame('commit_short', $c->body['oversell'][0]['kind']);
        self::assertSame(1, $c->body['oversell'][0]['shortfall']);
        $this->assertBal(2, 3, 0, $a);
        self::assertSame($a, self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = '1'"));

        // the listing is re-linked afterwards; the order's units still ship against item A
        $this->relink($listing, $b);
        $r = $this->ship($site, '7001', ['1', '2', '3']);
        self::assertSame(['shipped', 'shipped', 'shipped'], array_column($r->body['units'], 'result'));
        $this->assertBal(-1, 0, 0, $a);
        $this->assertBal(7, 0, 0, $b);
    }

    public function testAnOutageOrderIsFlaggedOnlyWhenAProtectedItemIsShort(): void
    {
        $site = $this->site();
        $strict = $this->item('strict', 0);
        $legacy = $this->item('legacy', 0);
        $backorder = $this->item('backorder', 0);
        $this->listing($site, 'S', $strict);
        $this->listing($site, 'L', $legacy);
        $this->listing($site, 'B', $backorder);

        $c = $this->commit($site, '7002', [self::line('S', '1'), self::line('L', '2'), self::line('B', '3')], 'unreserved');
        self::assertSame(['outage_order'], array_column($c->body['oversell'], 'kind'));
        self::assertSame('unreserved', $this->reservation($site, '7002')['origin']);
        self::assertSame(1, self::$db->value('SELECT COUNT(*) FROM oversell_event'));
    }

    public function testCommitBodyLinesWinOverTheHeldOnesAndTheDifferenceIsAudited(): void
    {
        $site = $this->site();
        $s1 = $this->item('strict', 5);
        $s2 = $this->item('strict', 5);
        $s3 = $this->item('strict', 5);
        $this->listing($site, 'V1', $s1);
        $this->listing($site, 'V2', $s2);
        $this->listing($site, 'V3', $s3);
        $this->reserve($site, '8001', [self::line('V1', 'u1', 'u2'), self::line('V2', 'u3')]);

        $c = $this->commit($site, '8001', [self::line('V1', 'u1'), self::line('V3', 'u9')]);
        self::assertSame(200, $c->status);
        $this->assertBal(5, 1, 0, $s1);
        $this->assertBal(5, 0, 0, $s2);
        $this->assertBal(5, 1, 0, $s3);
        self::assertSame(['u1' => 'allocated', 'u2' => 'released', 'u3' => 'released', 'u9' => 'allocated'], array_combine(
            ['u1', 'u2', 'u3', 'u9'], array_map(fn ($u) => $this->unitState($site, $u), ['u1', 'u2', 'u3', 'u9'])));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'reservation.commit_lines_differ'"), true);
        self::assertSame(['u2', 'u3'], $audit['released_units']);
        self::assertSame(['u9'], $audit['allocated_without_hold']);
    }

    public function testStoppedItemsAndQuarantinedListingsAre409OnALiveSiteOnly(): void
    {
        $live = $this->site('vpg', 'live');
        $shadow = $this->site('alt', 'shadow');
        $stopped = $this->item('stopped', 10);
        $strict = $this->item('strict', 10);
        $this->listing($live, 'ST', $stopped);
        $this->listing($live, 'Q', $strict, 1, 'quarantined');
        $this->listing($shadow, 'ST', $stopped);
        $this->listing($shadow, 'Q', $strict, 1, 'quarantined');

        $r = $this->reserve($live, '9001', [self::line('ST', '1')]);
        self::assertSame([409, ['stopped']], [$r->status, $r->body['reasons']]);
        $r = $this->reserve($live, '9002', [self::line('Q', '2')]);
        self::assertSame([409, ['quarantined']], [$r->status, $r->body['reasons']]);
        $this->assertBal(10, 0, 0, $stopped);
        $this->assertBal(10, 0, 0, $strict);

        self::assertSame(201, $this->reserve($shadow, '9001', [self::line('ST', '1')])->status);
        self::assertSame(201, $this->reserve($shadow, '9002', [self::line('Q', '2')])->status);
        $this->assertBal(10, 0, 1, $stopped);
        $this->assertBal(10, 0, 1, $strict);
    }

    public function testUnlinkedLinesAreRecordedOnlyAndLinkedLegacyLinesMoveBucketsButAreNeverRefused(): void
    {
        $site = $this->site();
        $legacy = $this->item('legacy', 0);
        $this->listing($site, 'L', $legacy);
        $this->listing($site, 'U', null); // known but not linked

        $r = $this->reserve($site, '9101', [self::line('L', '1', '2'), self::line('U', '3'), self::line('NEW', '4')]);
        self::assertSame(201, $r->status);
        $lines = array_column($r->body['lines'], null, 'variant_id');
        self::assertSame(['legacy', 'held', -2], [$lines['L']['kind'], $lines['L']['result'], $lines['L']['available']]);
        self::assertSame(['unlinked', 'unlinked'], [$lines['U']['kind'], $lines['U']['result']]);
        self::assertSame(['unlinked', 'unlinked'], [$lines['NEW']['kind'], $lines['NEW']['result']]);
        $this->assertBal(0, 0, 2, $legacy);
        self::assertSame('unmapped', self::$db->value("SELECT status FROM channel_listing WHERE external_variant_id = 'NEW'"), 'an unknown variant becomes an unmapped listing');
        self::assertSame([null, null], self::$db->column("SELECT sku_id FROM reservation_unit WHERE unit_id IN ('3', '4')"));

        $c = $this->commit($site, '9101', [self::line('L', '1', '2'), self::line('U', '3'), self::line('NEW', '4')]);
        self::assertSame([], $c->body['oversell'], 'legacy items are never flagged');
        $this->assertBal(0, 2, 0, $legacy);
        $s = $this->ship($site, '9101', ['1', '2', '3', '4']);
        self::assertSame(['shipped', 'shipped', 'shipped', 'shipped'], array_column($s->body['units'], 'result'));
        $this->assertBal(-2, 0, 0, $legacy);
        self::assertSame(0, self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE unit_id IN ('3', '4')"), 'unlinked units never touch the ledger');
    }

    public function testBackorderItemsAreNeverRefused(): void
    {
        $site = $this->site();
        $sku = $this->item('backorder', 1);
        $this->listing($site, 'B', $sku);
        $r = $this->reserve($site, '9201', [self::line('B', '1', '2', '3')]);
        self::assertSame(201, $r->status);
        $this->commit($site, '9201', [self::line('B', '1', '2', '3')]);
        $v = $this->view($site, 'B');
        self::assertSame(['backorder', -2], [$v['state'], $v['available']]);
    }

    public function testAUnitOfAnotherOrderIs422(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '9301', [self::line('V1', '1')]);
        $r = $this->reserve($site, '9302', [self::line('V1', '1')]);
        self::assertSame([422, 'unit_conflict', ['1']], [$r->status, $r->body['error'], $r->body['unit_ids']]);
        $r = $this->commit($site, '9302', [self::line('V1', '1')]);
        self::assertSame(422, $r->status);
        $this->assertBal(5, 0, 1, $sku);
    }

    /**
     * R8, §2.3: a linked-legacy line is never refused, even through a quarantined listing (an item
     * put back to legacy, §12, while one listing is quarantined, §7.3); its state reads legacy.
     */
    public function testALegacyLineIsNeverRefusedEvenWhenItsListingIsQuarantined(): void
    {
        $site = $this->site('vpg', 'live');
        $sku = $this->item('legacy', 10);
        $this->listing($site, 'Q', $sku, 1, 'quarantined');
        $r = $this->reserve($site, '1', [self::line('Q', 'q1')]);
        $v = $this->view($site, 'Q');
        self::assertSame([201, 'legacy', 'legacy'], [$r->status, $r->body['lines'][0]['kind'], $v['state']], json_encode(['reserve' => $r->body, 'view' => $v]));
        $this->assertBal(10, 0, 1, $sku);
        // once the item is protected again, the quarantined listing is refused and reads stopped
        $this->ok($this->stock->setPolicy(self::staff(), $sku, 'strict', $this->key('p')));
        $r = $this->reserve($site, '2', [self::line('Q', 'q2')]);
        self::assertSame([409, ['quarantined']], [$r->status, $r->body['reasons']]);
        self::assertSame('stopped', $this->view($site, 'Q')['state']);
    }

    /**
     * R9, §3 "a stale attempt is ignored": a release without attempt means attempt 1, so a delayed
     * re-send of the first release (without the field) cannot drop attempt 2's hold.
     */
    public function testAReleaseWithoutAttemptCannotDropANewerAttempt(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 1);
        $this->listing($site, 'V1', $sku);
        $this->reserve($site, '1', [self::line('V1', 'a')]);
        $this->release($site, '1', 1);
        self::assertSame(2, $this->reserve($site, '1', [self::line('V1', 'a')])->body['attempt']);

        $late = $this->release($site, '1', null);
        self::assertSame(['stale_attempt', 2], [$late->body['result'], $late->body['attempt']]);
        $this->assertBal(1, 0, 1, $sku);
        self::assertSame('released', $this->release($site, '1', 2)->body['result']);
        $this->assertBal(1, 0, 0, $sku);
        try {
            $this->release($site, '1', Reservations::MAX_ATTEMPT + 1); // beyond INT UNSIGNED (R16)
            self::fail('attempt out of range');
        } catch (CwException $e) {
            self::assertSame([400, 'bad_attempt'], [$e->httpStatus, $e->errorCode]);
        }
    }

    /**
     * R15: an order has at most MAX_ORDER_UNITS units; a bigger reserve, commit, opening order or
     * unit call is refused (413) before any lock is taken or anything is stored. A 45,000-unit
     * commit used to hold the item's balance for 154 s, and every other site's call on it timed out.
     */
    public function testAnOversizedOrderIsRefusedBeforeAnyLock(): void
    {
        $site = $this->site();
        $sku = $this->item('strict', 5000);
        $this->listing($site, 'V1', $sku);
        $this->listing($site, 'V2', $sku);
        $n = Reservations::MAX_ORDER_UNITS;
        $units = static fn (string $p, int $count): array => array_map(static fn (int $i): string => $p . $i, range(1, $count));
        $big = [self::line('V1', ...$units('a', $n - 10)), self::line('V2', ...$units('b', 11))];
        $calls = [
            'reserve' => fn () => $this->reserve($site, '1', $big),
            'commit' => fn () => $this->commit($site, '1', $big, 'unreserved'),
            'opening' => fn () => $this->res->openingOrders($site, [['order_ref' => '1', 'lines' => $big]], false, $this->key()),
            'cancel' => fn () => $this->res->cancel($site, '1', $units('a', $n + 1), true, $this->key()),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail("{$name}: an order of " . ($n + 1) . ' units must be refused');
            } catch (CwException $e) {
                self::assertSame([413, 'too_many_units'], [$e->httpStatus, $e->errorCode], $name);
            }
        }
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM idempotency WHERE path LIKE '/v1/reservations%' OR path = '/v1/opening_orders'"));
        self::assertNull($this->reservation($site, '1'));
        // exactly MAX_ORDER_UNITS is fine
        $ok = [self::line('V1', ...$units('a', $n - 10)), self::line('V2', ...$units('b', 10))];
        self::assertSame(201, $this->reserve($site, '1', $ok)->status);
        $this->assertBal(5000, 0, $n, $sku);
    }
}
