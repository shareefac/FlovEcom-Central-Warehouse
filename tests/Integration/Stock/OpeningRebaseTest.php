<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Stock;

use CW\Caller;
use CW\CwException;
use CW\Mapping\DecisionService;
use CW\Ops\OpeningEstimate;
use CW\Ops\OpeningRebase;
use CW\Tests\Support\StockTestCase;
use CW\Tests\Support\TestDb;
use DateTimeImmutable;

/**
 * The rebase of a site's opening estimate at its T0 (plan §8.1, D40a "Rule at a site's T0", D40b):
 * estimate -> opening_orders -> ships -> rebase gives available = the site's figure at T0, and on_hand =
 * max(S_T0, 0) x u once every opening unit has shipped. CW\Ops\OpeningRebase and
 * bin/import_opening_estimate.php --rebase.
 */
final class OpeningRebaseTest extends StockTestCase
{
    private const EST = 'opening:vpg:2026-09-01T00:00+01:00';
    private const T0 = ['at' => '2026-09-26T17:00:00Z', 'last_order_id' => 900, 'last_stock_log_id' => 5501];
    private const REBASE = 'opening-rebase:vpg:20260926T170000Z';

    private function sys(): Caller
    {
        return Caller::system(OpeningEstimate::ACTOR_JOB);
    }

    private function op(): OpeningEstimate
    {
        return new OpeningEstimate(self::$db, $this->moves);
    }

    /** Books the estimate as of 1 Sep (before every fixture row). @param list<array{0: string, 1: int}> $rows */
    private function estimate(array $rows, string $doc = self::EST): void
    {
        $plan = $this->op()->plan('vpg', $rows, $doc, new DateTimeImmutable('2000-01-01T00:00:00Z'));
        $this->op()->apply($this->sys(), $plan['lines'], $doc, 'opening estimate (not a count): test');
    }

    /** One final opening batch with the T0 watermarks. @param list<array<string, mixed>> $orders */
    private function opening(Caller $site, array $orders): void
    {
        $this->ok($this->res->openingOrders($site, $orders, true, $this->key('opening'), self::T0));
    }

    /** @param list<array{0: string, 1: int}> $rows @return array{lines: array<int, int>, report: array<string, mixed>, opening: array<string, mixed>} */
    private function plan(array $rows, string $doc = self::REBASE, ?string $est = self::EST, string $channel = 'vpg'): array
    {
        return (new OpeningRebase(self::$db))->plan($channel, $rows, $doc, $est);
    }

    /** @param array<int, int> $lines @return array{booked_items: int, booked_units: int, replayed_items: int} */
    private function bookRebase(array $lines, string $doc = self::REBASE): array
    {
        return $this->op()->apply($this->sys(), $lines, $doc, 'opening rebase (not a count): test', 'MAIN', null, OpeningRebase::DOC_TYPE);
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (CwException $e) {
            return $e->errorCode;
        }
        self::fail('expected a refusal');
    }

    private function available(int $sku): int
    {
        $b = $this->bal($sku);
        return $b['on_hand'] - $b['allocated'] - $b['held'];
    }

    public function testEstimateOpeningOrdersShipsThenRebaseGiveTheSiteFigureAtT0(): void
    {
        $site = $this->site('vpg', 'shadow');
        [$a, $b, $c, $d, $e] = [$this->item('legacy'), $this->item('legacy'), $this->item('legacy'), $this->item('legacy'), $this->item('legacy')];
        $this->listing($site, 'A', $a);
        $this->listing($site, 'B10', $b, 10);   // "10 x" listing
        $this->listing($site, 'C', $c);
        $this->listing($site, 'D1', $d);        // two listings of one item
        $this->listing($site, 'D2', $d);
        $this->listing($site, 'E', $e);
        $this->listing($site, 'U', null);       // unlinked

        // The estimate, as of an earlier moment: C's figure is negative, so C gets nothing.
        $this->estimate([['A', 10], ['B10', 2], ['C', -5], ['D1', 5], ['D2', 5], ['E', 8]]);
        // The open paid units at T0 go to `allocated` only.
        $this->opening($site, [
            ['order_ref' => '901', 'lines' => [self::line('A', 'a1', 'a2', 'a3'), self::line('B10', 'b1')]],
            ['order_ref' => '902', 'lines' => [self::line('B10', 'b2'), self::line('C', 'c1', 'c2', 'c3', 'c4'), self::line('U', 'u1')]],
            ['order_ref' => '903', 'lines' => [self::line('D2', 'd1')]],
        ]);
        $this->assertBal(10, 3, 0, $a);
        $this->assertBal(20, 20, 0, $b);
        $this->assertBal(0, 4, 0, $c);
        self::assertSame(-4, $this->available($c), 'without the rebase C sits below zero by its open units');
        // Some opening units ship before the rebase runs.
        $this->ship($site, '901', ['a1', 'a2', 'b1']);
        $this->ship($site, '902', ['c1']);

        // The site's figures in the T0 snapshot (the connector's t0_site_stock file).
        $t0 = [['A', 6], ['B10', 3], ['C', -2], ['D1', 4], ['D2', 6], ['E', 8], ['U', 7], ['GONE', 3]];
        $plan = $this->plan($t0);
        // target = max(S_T0, 0) x u + opening units x u; delta = target - the estimate's rows
        self::assertSame([$a => 6 + 3 - 10, $b => 30 + 20 - 20, $c => 0 + 4 - 0, $d => 10 + 1 - 10], $plan['lines']);
        $r = $plan['report'];
        self::assertSame(['units' => 10, 'central_units' => 28, 'unlinked' => 1, 'released' => 0, 'never_committed' => 0], $r['opening_units']);
        self::assertSame(['items' => 4, 'up' => 35, 'down' => 1, 'net' => 34], $r['to_book']);
        self::assertSame([5, 1, 0], [$r['items'], $r['no_change'], $r['skipped']['items']]);
        self::assertSame(['rows' => 1, 'units' => 3], $r['no_listing']);
        self::assertSame(['unmapped' => ['rows' => 1, 'units' => 7]], $r['not_linked_by_status']);
        self::assertSame(['MAIN', '2026-09-26T17:00:00.000000Z'], [$r['warehouse'], $r['t0']]);

        self::assertSame(['booked_items' => 4, 'booked_units' => 34, 'replayed_items' => 0], $this->bookRebase($plan['lines']));

        // available = the site's figure at T0 (x u), whatever shipped before the rebase
        self::assertSame([6, 30, 0, 10, 8], [$this->available($a), $this->available($b), $this->available($c), $this->available($d), $this->available($e)]);
        self::assertSame([6, 3, 0, 10, 10, 8], [$this->view($site, 'A')['available'], $this->view($site, 'B10')['available'],
            $this->view($site, 'C')['available'], $this->view($site, 'D1')['available'], $this->view($site, 'D2')['available'], $this->view($site, 'E')['available']]);
        $rows = self::$db->all('SELECT sku_id, bucket, qty_delta, movement_type, actor, note FROM stock_ledger WHERE doc_ref = ? ORDER BY sku_id', [self::REBASE]);
        self::assertSame([[$a, 'on_hand', -1], [$b, 'on_hand', 30], [$c, 'on_hand', 4], [$d, 'on_hand', 1]],
            array_map(static fn (array $x): array => [(int) $x['sku_id'], $x['bucket'], (int) $x['qty_delta']], $rows));
        self::assertSame(['adjustment'], array_values(array_unique(array_column($rows, 'movement_type'))));
        self::assertSame(['system:opening_estimate'], array_values(array_unique(array_column($rows, 'actor'))));

        // Every opening unit ships: on_hand ends at max(S_T0, 0) x u, allocated at 0.
        $this->ship($site, '901', ['a3']);
        $this->ship($site, '902', ['b2', 'c2', 'c3', 'c4', 'u1']);
        $this->ship($site, '903', ['d1']);
        $this->assertBal(6, 0, 0, $a);
        $this->assertBal(30, 0, 0, $b);
        $this->assertBal(0, 0, 0, $c);
        $this->assertBal(10, 0, 0, $d);
        $this->assertBal(8, 0, 0, $e);

        // A re-run finds everything rebased and books nothing.
        $ledger = $this->ledgerCount();
        $again = $this->plan($t0);
        self::assertSame([], $again['lines']);
        self::assertSame(['items' => 4, 'units' => 34], $again['report']['already_rebased']);
        self::assertSame($ledger, $this->ledgerCount());
        // ... and the estimate tool sees the rebase as an earlier opening of those items.
        $est = $this->op()->plan('vpg', [['C', 9]], self::EST, new DateTimeImmutable('2000-01-01T00:00:00Z'));
        self::assertSame([], $est['lines']);
        self::assertSame(['earlier_opening' => 1], $est['report']['skipped']['has_stock_history']['why']);
    }

    public function testCancelsUncancelsAndReturnsOfOpeningUnitsAreTheOpeningsOwnHistory(): void
    {
        $site = $this->site('vpg', 'shadow');
        $a = $this->item('legacy');
        $this->listing($site, 'A', $a);
        $this->estimate([['A', 5]]);
        // A hold before the opening: the opening batch pays r1 only, so r2 is released (held, never paid).
        $this->ok($this->reserve($site, '902', [self::line('A', 'r1', 'r2')]));
        $this->opening($site, [
            ['order_ref' => '901', 'lines' => [self::line('A', 'a1', 'a2', 'a3', 'a4')]],
            ['order_ref' => '902', 'lines' => [self::line('A', 'r1')]],
        ]);
        self::assertSame('released', $this->unitState($site, 'r2'));
        $this->ok($this->res->cancel($site, '901', ['a1'], true, $this->key('cancel')));            // back on the shelf
        $this->ok($this->res->cancel($site, '901', ['a2'], false, $this->key('cancel')));           // parked in VERIFY ...
        $this->ok($this->res->uncancel($site, '901', ['a2'], $this->key('uncancel')));              // ... and taken back
        $this->ship($site, '901', ['a3']);
        $this->ok($this->res->returnUnits($site, '901', ['a3'], $this->key('return')));             // a refund with restock

        $plan = $this->plan([['A', 3]]);
        self::assertSame(0, $plan['report']['skipped']['items'], 'opening units\' own movements are not "other history"');
        self::assertSame(['units' => 5, 'central_units' => 5, 'unlinked' => 0, 'released' => 1, 'never_committed' => 0], $plan['report']['opening_units']);
        self::assertSame([$a => 3 + 5 - 5], $plan['lines']);
        $this->bookRebase($plan['lines']);
        // available = S_T0 + what came back on sale since: the restockable cancel (a1) and the return (a3)
        self::assertSame(3 + 1 + 1, $this->available($a));
        $this->assertBal(8, 3, 0, $a);
        $this->assertBal(0, 0, 0, $a, 'VERIFY');
        $this->ship($site, '901', ['a2', 'a4']);
        $this->ship($site, '902', ['r1']);
        $this->assertBal(5, 0, 0, $a);
    }

    /**
     * Review fix: a unit held, cancelled while held and left out of the opening body was never paid at T0. It stays
     * `cancelled` under the (now origin=opening) reservation, but it has no commit row, so it is not in the target.
     */
    public function testAUnitCancelledWhileHeldAndLeftOutOfTheOpeningBodyDoesNotCount(): void
    {
        $site = $this->site('vpg', 'shadow');
        $a = $this->item('legacy');
        $this->listing($site, 'A', $a);
        $this->estimate([['A', 10]]);
        $this->ok($this->reserve($site, '901', [self::line('A', 'x1', 'x2')]));
        $this->ok($this->res->cancel($site, '901', ['x2'], true, $this->key('cancel')));
        $this->opening($site, [['order_ref' => '901', 'lines' => [self::line('A', 'x1')]]]);
        self::assertSame(['allocated', 'cancelled'], [$this->unitState($site, 'x1'), $this->unitState($site, 'x2')]);
        self::assertSame('opening', self::$db->value("SELECT origin FROM reservation WHERE order_ref = '901'"));

        $plan = $this->plan([['A', 4]]);
        self::assertSame([$a => 4 + 1 - 10], $plan['lines'], 'x1 only: target 5, not 6');
        self::assertSame(['units' => 1, 'central_units' => 1, 'unlinked' => 0, 'released' => 0, 'never_committed' => 1], $plan['report']['opening_units']);
        $this->bookRebase($plan['lines']);
        $this->assertBal(5, 1, 0, $a);
        self::assertSame(4, $this->available($a), 'the site figure at T0');

        // Taken back after T0, it is a sale after T0: availability falls, and the rebase still stands.
        $this->ok($this->res->uncancel($site, '901', ['x2'], $this->key('uncancel')));
        self::assertSame(3, $this->available($a));
        $again = $this->plan([['A', 4]]);
        self::assertSame([], $again['lines']);
        self::assertSame(['items' => 1, 'units' => -5], $again['report']['already_rebased']);
        self::assertSame(1, $again['report']['opening_units']['never_committed']);
    }

    public function testItemsWithOtherHistoryAreSkippedAndReportedWithTheirDelta(): void
    {
        $site = $this->site('vpg', 'shadow');
        $skus = [];
        foreach (['F', 'K', 'M', 'O', 'Q', 'Q0', 'N', 'P', 'X'] as $v) {
            $skus[$v] = $this->item('legacy');
        }
        foreach (['F', 'K', 'M', 'O', 'Q', 'Q0', 'P', 'X'] as $v) {
            $this->listing($site, $v, $skus[$v]);
        }
        $this->listing($site, 'Q-quarantined', $skus['Q'], 1, 'quarantined');
        $this->listing($site, 'Q0-quarantined', $skus['Q0'], 1, 'quarantined');
        $this->listing($site, 'N', $skus['N']);
        $this->listing($site, 'N-missing', $skus['N']);
        $x = (int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = 'X'");
        $this->estimate([['F', 4], ['K', 4], ['M', 4], ['Q', 4], ['Q0', 4], ['N', 4], ['P', 4], ['X', 4]]);
        $this->estimate([['O', 4]], 'opening:vpg:another');                                  // another opening
        // Another site's opening of an item this site does not sell: none of this rebase's business.
        $oth = $this->site('oth', 'shadow');
        $z = $this->item('legacy');
        $this->listing($oth, 'Z', $z);
        $zp = $this->op()->plan('oth', [['Z', 3]], 'opening:oth:x', new DateTimeImmutable('2000-01-01T00:00:00Z'));
        $this->op()->apply($this->sys(), $zp['lines'], 'opening:oth:x', 'n');
        self::$db->exec('UPDATE sku SET counted_at = ? WHERE id = ?', ['2026-09-26 10:00:00.000000', $skus['K']]);   // counted
        $this->book('goods_in', $skus['M'], 6);                                                 // a goods-in after the estimate
        $this->relink($x, null, 'unmapped');                                                    // X's only link is gone
        $this->opening($site, [['order_ref' => '901', 'lines' => [self::line('P', 'p1'), self::line('F', 'f1')]]]);
        // A later order (after T0) of P is paid and dispatched before the rebase runs.
        $this->commit($site, '950', [self::line('P', 'n1')]);
        $this->ship($site, '950', ['n1']);

        $plan = $this->plan([['F', 2], ['K', 2], ['M', 2], ['O', 2], ['Q', 2], ['Q-quarantined', 5], ['Q0', 2], ['Q0-quarantined', 0],
            ['N', 2], ['P', 2], ['X', 2]]);
        self::assertSame([$skus['F'] => 2 + 1 - 4, $skus['Q0'] => 2 - 4], $plan['lines']);
        $s = $plan['report']['skipped'];
        self::assertSame(7, $s['items']);
        self::assertSame(['counted' => 1, 'moved' => 2, 'no_mapped_listing' => 1, 'no_t0_figure' => 1, 'other_opening' => 1, 'quarantined_listing' => 1],
            $s['why']);
        $byCode = [];
        foreach ($s['details'] as $d) {
            $byCode[(int) $d['sku_id']] = $d;
        }
        self::assertSame(['why' => ['counted'], 'target' => 2, 'earlier' => 4, 'delta' => -2],
            array_intersect_key($byCode[$skus['K']], ['why' => 0, 'target' => 0, 'earlier' => 0, 'delta' => 0]));
        self::assertSame(['moved'], $byCode[$skus['M']]['why']);
        self::assertSame(['goods_in' => 1], $byCode[$skus['M']]['moved']);
        self::assertSame(['moved'], $byCode[$skus['P']]['why']);
        self::assertSame(['ship' => 1], $byCode[$skus['P']]['moved'], 'p1 is the opening\'s; n1 (a later order) is not');
        self::assertSame(['other_opening'], $byCode[$skus['O']]['why']);
        self::assertSame(['quarantined_listing'], $byCode[$skus['Q']]['why']);
        self::assertSame(['no_t0_figure'], $byCode[$skus['N']]['why']);
        // X's only link was unmapped after the estimate (review fix): not written down to 0 silently, but listed
        self::assertSame(['why' => ['no_mapped_listing'], 'target' => 0, 'earlier' => 4, 'delta' => -4],
            array_intersect_key($byCode[$skus['X']], ['why' => 0, 'target' => 0, 'earlier' => 0, 'delta' => 0]));
        self::assertSame(['delta' => 2 + 1 - 4, 'earlier' => 4], ['delta' => $byCode[$skus['P']]['delta'], 'earlier' => $byCode[$skus['P']]['earlier']]);
        self::assertArrayNotHasKey($z, $byCode, 'another site\'s opening of an item not linked here is not listed');

        // Without the estimate's doc_ref every estimated item linked here has an "other" opening; X, whose link is
        // gone, is no longer this site's at all.
        $none = $this->plan([['F', 2]], self::REBASE, null);
        self::assertSame([], $none['lines']);
        self::assertSame(8, $none['report']['skipped']['why']['other_opening']);
    }

    /**
     * M35: Vape and Go's duplicates are merged between the estimate and T0. The merge moves the merged item's estimate onto
     * the kept item (merge_out / merge_in), the rebase counts those rows as the kept item's earlier rows and sums both its
     * listings' T0 figures, the merged item has nothing left to rebase, and items a merge joined share their history (a
     * goods-in on one makes both `moved`).
     */
    public function testAMergeBetweenTheEstimateAndT0RebasesTheKeptItemOnBothListings(): void
    {
        $site = $this->site('vpg', 'shadow');
        [$k, $f, $g, $h] = [$this->item('legacy'), $this->item('legacy'), $this->item('legacy'), $this->item('legacy')];
        $this->listing($site, 'A', $k);
        $b = $this->listing($site, 'B', $f);
        $this->listing($site, 'G', $g);
        $hl = $this->listing($site, 'H', $h);
        $this->estimate([['A', 10], ['B', 4], ['G', 5], ['H', 2]]);
        $lead = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash, is_active) VALUES ('lead@test.invalid', 'Lead', 'lead@test.invalid', 'x', 1)");
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'mapping_lead')", [$lead]);
        $ds = new DecisionService(self::$db, $this->stock, $this->res);
        $merge = fn (int $anchor, int $keep, int $from): array => $ds->decide(Caller::staff($lead), ['action' => 'merge_skus', 'listing_id' => $anchor,
            'expected_map_version' => (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$anchor]), 'sku_id' => $keep, 'merge_from_sku_id' => $from]);
        self::assertSame('applied', $merge($b, $k, $f)['state']);
        $this->assertBal(14, 0, 0, $k);
        $this->assertBal(0, 0, 0, $f);
        // G was moved by a goods-in, then H was merged into it: both share G's history now.
        $this->book('goods_in', $g, 3);
        self::assertSame('applied', $merge($hl, $g, $h)['state']);
        // The open paid unit of page B is the kept item's (B is linked to it at T0).
        $this->opening($site, [['order_ref' => '901', 'lines' => [self::line('B', 'b1')]]]);
        $this->assertBal(14, 1, 0, $k);

        $plan = $this->plan([['A', 6], ['B', 3], ['G', 5], ['H', 2]]);
        // K: target = A 6 + B 3 + the opening unit 1 = 10; its earlier rows = its estimate 10 + the 4 that came with the merge.
        self::assertSame([$k => 10 - 14], $plan['lines']);
        $r = $plan['report'];
        self::assertSame(['items' => 2, 'why' => ['moved' => 2]], ['items' => $r['skipped']['items'], 'why' => $r['skipped']['why']]);
        self::assertEqualsCanonicalizing([$g, $h], array_column($r['skipped']['details'], 'sku_id'));
        self::assertSame(1, $r['no_change'], 'the merged item F: nothing left to rebase, and not listed as no_mapped_listing');
        $this->bookRebase($plan['lines']);
        self::assertSame(9, $this->available($k), 'available = both pages\' T0 figures');
        $this->assertBal(0, 0, 0, $f);
    }

    /**
     * M42 (review of 7 Oct 2026): a merged item that still holds what its own opening unit in flight needs (earlier rows = units
     * term) has nothing to rebase: no change, never `no_mapped_listing` (whose advice, relink it, would undo the merge). One that
     * holds anything else (here what a later order in flight needs) is skipped as `merged_item`.
     */
    public function testAMergedItemWithAnOpeningUnitInFlightIsNoChange(): void
    {
        [$plan, $k, $f] = $this->mergedWithUnitsInFlight(false);
        self::assertSame([$k => 9 - 13], $plan['lines']);
        self::assertSame(0, $plan['report']['skipped']['items'], json_encode($plan['report']['skipped']));
        self::assertSame(1, $plan['report']['no_change'], 'F: earlier 1 = its opening unit in flight');
    }

    public function testAMergedItemHoldingMoreThanItsOpeningUnitsIsSkippedAsMergedItem(): void
    {
        [$plan, $k, $f] = $this->mergedWithUnitsInFlight(true);
        self::assertSame([$k => 9 - 12], $plan['lines']);
        self::assertSame(['merged_item' => 1], $plan['report']['skipped']['why']);
        self::assertSame([['sku_id' => $f, 'why' => ['merged_item'], 'target' => 1, 'earlier' => 2, 'delta' => -1]],
            array_map(static fn (array $d): array => array_intersect_key($d, array_flip(['sku_id', 'why', 'target', 'earlier', 'delta'])), $plan['report']['skipped']['details']));
    }

    /** Estimate A 10, B 4; an opening unit of page B (and, with $later, a later order of page B); B's item F merged into A's K; T0 A 6, B 3. @return array{0: array<string, mixed>, 1: int, 2: int} */
    private function mergedWithUnitsInFlight(bool $later): array
    {
        $site = $this->site('vpg', 'shadow');
        [$k, $f] = [$this->item('legacy'), $this->item('legacy')];
        $this->listing($site, 'A', $k);
        $b = $this->listing($site, 'B', $f);
        $this->estimate([['A', 10], ['B', 4]]);
        $this->opening($site, [['order_ref' => '902', 'lines' => [self::line('B', 'b1')]]]);
        if ($later) {
            $this->ok($this->commit($site, 'L-1', [self::line('B', 'l1')]));
        }
        $this->assertBal(4, $later ? 2 : 1, 0, $f);
        $lead = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash, is_active) VALUES ('lead2@test.invalid', 'Lead', 'lead2@test.invalid', 'x', 1)");
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'mapping_lead')", [$lead]);
        $ds = new DecisionService(self::$db, $this->stock, $this->res);
        self::assertSame('applied', $ds->decide(Caller::staff($lead), ['action' => 'merge_skus', 'listing_id' => $b, 'expected_map_version' =>
            (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$b]), 'sku_id' => $k, 'merge_from_sku_id' => $f])['state']);
        $this->assertBal($later ? 12 : 13, 0, 0, $k);
        return [$this->plan([['A', 6], ['B', 3]]), $k, $f];
    }

    public function testRefusalsResumeAndConflict(): void
    {
        $site = $this->site('vpg', 'shadow');
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $this->listing($site, 'A', $a);
        $this->listing($site, 'B', $b);
        $this->estimate([['A', 5], ['B', 5]]);

        // Before the final opening batch: refused.
        self::assertSame('opening_not_final', $this->code(fn () => $this->plan([['A', 1]])));
        $this->ok($this->res->openingOrders($site, [['order_ref' => '901', 'lines' => [self::line('A', 'a1')]]], false, $this->key('opening'), self::T0));
        self::assertSame('opening_not_final', $this->code(fn () => $this->plan([['A', 1]])));
        $this->ok($this->res->openingOrders($site, [['order_ref' => '902', 'lines' => [self::line('B', 'b1')]]], true, $this->key('opening')));

        self::assertSame('unknown_channel', $this->code(fn () => $this->plan([['A', 1]], self::REBASE, self::EST, 'nope')));
        self::assertSame('bad_doc_ref', $this->code(fn () => $this->plan([['A', 1]], strtoupper(self::EST))));
        self::assertSame('duplicate_variant', $this->code(fn () => $this->plan([['A', 1], ['A', 2]])));
        self::assertSame('empty_input', $this->code(fn () => $this->plan([])));
        self::assertSame(self::REBASE, OpeningRebase::defaultDocRef('vpg', '2026-09-26 17:00:00.000000'));

        // A run that stopped after the first item; the resume books the rest only.
        $rows = [['A', 7], ['B', 1]];
        $plan = $this->plan($rows);
        self::assertSame([$a => 7 + 1 - 5, $b => 1 + 1 - 5], $plan['lines']);
        $this->bookRebase(array_slice($plan['lines'], 0, 1, true));
        $resume = $this->plan($rows);
        self::assertSame([$b => -3], $resume['lines']);
        self::assertSame(['items' => 1, 'units' => 3], $resume['report']['already_rebased']);
        $this->bookRebase($resume['lines']);
        self::assertSame([7, 1], [$this->available($a), $this->available($b)]);
        $ledger = $this->ledgerCount();
        self::assertSame([], $this->plan($rows)['lines']);

        // Another file under the same doc_ref: refused before anything is booked.
        self::assertSame('rebase_conflict', $this->code(fn () => $this->plan([['A', 8], ['B', 1]])));
        self::assertSame($ledger, $this->ledgerCount());
    }

    public function testTheConnectorsT0FileThroughTheCli(): void
    {
        $site = $this->site('vpg', 'shadow');
        $a = $this->item('legacy');
        $b = $this->item('legacy');
        $this->listing($site, '101', $a);
        $this->listing($site, '102', $b, 10);
        $this->estimate([['101', 5], ['102', 1]]);
        $dir = sys_get_temp_dir() . '/cw_rebase_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        // The connector's cw_opening_stock_csv() format: four columns, the figure second.
        $csv = "variant_id,prodt_stock,prodt_stock_mode,prodt_allow_backorders\n101,3,From-Warehouse,0\n102,2,In-Stock,\n999,4,In-Stock,1\n";
        $file = "{$dir}/t0_site_stock_20260926T170000Z.csv";
        file_put_contents($file, $csv);
        $sha = hash('sha256', $csv);
        $args = ['--rebase', "--csv={$file}", '--source=connector T0 snapshot (test)', '--estimate-doc-ref=' . self::EST, '--channel=vpg'];
        try {
            // Before the final opening batch: refused, exit 1.
            $r = self::script(...$args, ...['--dry-run']);
            self::assertSame(1, $r['code'], $r['out'] . $r['err']);
            self::assertStringContainsString('no accepted final opening_orders batch', $r['err']);

            $this->opening($site, [['order_ref' => '901', 'lines' => [self::line('101', 'a1'), self::line('102', 'b1')]]]);
            $r = self::script(...$args, ...['--dry-run']);
            self::assertSame(0, $r['code'], $r['out'] . $r['err']);
            self::assertStringContainsString('rebase of vpg at T0 2026-09-26T17:00:00Z', $r['out']);
            self::assertStringContainsString('doc_ref ' . self::REBASE, $r['out']);
            // 101: max(3, 0) x 1 + 1 opening unit - 5 = -1; 102: max(2, 0) x 10 + 10 - 10 = +20
            self::assertStringContainsString('to book: 2 items, +20 / -1 units (net +19)', $r['out']);
            self::assertStringContainsString('dry run: nothing booked', $r['out']);
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE doc_ref = ?', [self::REBASE]));

            // Guards: the file's hash, --as-of and the T0 in the file name must match.
            $bad = self::script(...$args, ...['--sha256=' . str_repeat('0', 64)]);
            self::assertSame(1, $bad['code']);
            $bad = self::script(...$args, ...["--sha256={$sha}", '--as-of=2026-09-26T18:00:00Z']);
            self::assertSame(1, $bad['code']);
            self::assertStringContainsString('is not the T0 CW recorded', $bad['err']);
            $other = "{$dir}/t0_site_stock_20260927T000000Z.csv";
            copy($file, $other);
            $bad = self::script('--rebase', "--csv={$other}", '--source=x', '--estimate-doc-ref=' . self::EST, '--channel=vpg', "--sha256={$sha}");
            self::assertSame(1, $bad['code']);
            self::assertStringContainsString('another T0', $bad['err']);
            // --sha256 proves the bytes, not the snapshot: a real run names its T0 (review fix), whatever the file is called
            $renamed = "{$dir}/stock.csv";
            copy($file, $renamed);
            $bad = self::script('--rebase', "--csv={$renamed}", '--source=x', '--estimate-doc-ref=' . self::EST, '--channel=vpg', "--sha256={$sha}");
            self::assertSame(1, $bad['code']);
            self::assertStringContainsString('needs --as-of', $bad['err']);
            $bad = self::script(...$args, ...["--sha256={$sha}"]);
            self::assertSame(1, $bad['code']);
            self::assertStringContainsString('needs --as-of', $bad['err']);
            $dry = self::script('--rebase', "--csv={$renamed}", '--source=x', '--estimate-doc-ref=' . self::EST, '--channel=vpg', '--dry-run');
            self::assertSame(0, $dry['code'], $dry['err']);
            self::assertStringContainsString('T0 check: --as-of not given (a real run needs it); the file name names no T0', $dry['out']);
            // The estimate mode reads the same file (extra columns ignored): both items already carry this opening.
            $est = self::script("--csv={$file}", '--as-of=2026-09-26T17:00:00Z', '--doc-ref=opening:vpg:again', '--source=x', '--channel=vpg', '--dry-run');
            self::assertSame(0, $est['code'], $est['out'] . $est['err']);
            self::assertStringContainsString('3 rows (sha256 ' . substr($sha, 0, 16) . ')', $est['out']);
            self::assertStringContainsString('to book: 0 items', $est['out']);
            // Usage errors.
            self::assertSame(2, self::script('--rebase', "--csv={$file}", '--source=x', '--channel=vpg', '--dry-run')['code']);
            self::assertSame(2, self::script("--csv={$file}", '--source=x', '--as-of=2026-09-26T17:00:00Z', '--doc-ref=x',
                '--estimate-doc-ref=' . self::EST, '--channel=vpg', '--dry-run')['code']);
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE doc_ref = ?', [self::REBASE]));

            $r = self::script(...$args, ...["--sha256={$sha}", '--as-of=2026-09-26T18:00:00+01:00', '--approved-by=tester']);
            self::assertSame(0, $r['code'], $r['out'] . $r['err']);
            self::assertStringContainsString('booked 2 items, net +19 units', $r['out']);
            self::assertSame([3, 20], [$this->available($a), $this->available($b)], 'available = the T0 figures x u');
            $note = (string) self::$db->value('SELECT note FROM stock_ledger WHERE doc_ref = ? LIMIT 1', [self::REBASE]);
            self::assertStringContainsString('opening rebase (not a count) to T0 2026-09-26T17:00:00Z', $note);
            self::assertStringContainsString('approved by tester', $note);

            $again = self::script(...$args, ...["--sha256={$sha}", '--as-of=2026-09-26T17:00:00Z']);
            self::assertSame(0, $again['code'], $again['err']);
            self::assertStringContainsString('nothing to book', $again['out']);
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
    }

    /** bin/import_opening_estimate.php against this slot's schema (admin login, like the other job tests). @return array{code: int, out: string, err: string} */
    private static function script(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/import_opening_estimate.php", '--db=' . TestDb::name(), '--admin', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
