<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\DecisionService;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\OpWorkers;

/**
 * DecisionService (plan §7.1, design A.1/A.9): the only writer of a listing's link. Every rule:
 * the optimistic map_version check, concurrent confirmations, the two-person rule (protected
 * items, units_per_item <> 1, merges, a previously rejected item), roles and the Conflict band,
 * reject, new_item, ignore, suggest, merges, history periods, adoption of units sold while
 * unlinked (R4) and the feed. The stock invariants are asserted after every test.
 */
final class DecisionServiceTest extends MappingTestCase
{
    use OpWorkers;

    protected function tearDown(): void
    {
        $this->stopWorkers();
        parent::tearDown();
    }

    public function testALinkChangesTheListingOpensAHistoryPeriodAuditsAndReachesTheFeed(): void
    {
        $alt = $this->site('alt', 'live');
        $mapper = $this->staffUser('mapper');
        $sku = $this->item('legacy', 5);
        $e1 = $this->listing($alt, 'E1', null);
        $seq = (int) self::$db->value('SELECT COALESCE(MAX(seq), 0) FROM stock_change');

        $r = $this->decide($mapper, 'link', $e1, ['sku_id' => $sku, 'reason' => 'same barcode']);
        self::assertSame(['applied', 'mapped', $sku, 1, 1, []], [$r['state'], $r['status'], $r['sku_id'], $r['units_per_item'], $r['map_version'], $r['needs_second']]);
        self::assertSame(['sku_id' => $sku, 'units_per_item' => 1, 'status' => 'mapped', 'map_version' => 1], $this->link($e1));
        $d = self::$db->one('SELECT * FROM match_decision WHERE id = ?', [$r['decision_id']]);
        self::assertSame(['link', 'applied', $mapper->staffUserId, null, 0, 'unmapped', null, 'same barcode', 'staff:' . $mapper->staffUserId],
            [$d['action'], $d['state'], $d['decided_by'], $d['second_by'], $d['expected_map_version'], $d['prev_status'], $d['prev_sku_id'], $d['reason'], $d['actor']]);
        self::assertNotNull($d['applied_at']);
        self::assertSame([['sku_id' => $sku, 'units_per_item' => 1, 'valid_to' => null, 'decision_id' => $r['decision_id']]],
            self::$db->all('SELECT sku_id, units_per_item, valid_to, decision_id FROM listing_map_history WHERE listing_id = ?', [$e1]));
        $audit = self::$db->one("SELECT actor, staff_user_id, detail FROM audit_log WHERE action = 'mapping.link' AND entity_id = ?", [(string) $e1]);
        self::assertSame(['staff:' . $mapper->staffUserId, $mapper->staffUserId], [$audit['actor'], $audit['staff_user_id']]);
        self::assertSame($r['decision_id'], json_decode((string) $audit['detail'], true)['decision_id']);

        // The feed: a listing row (reason link) that changes() turns into the new view.
        self::assertSame('link', self::$db->value('SELECT reason FROM stock_change WHERE listing_id = ? ORDER BY seq DESC LIMIT 1', [$e1]));
        $views = array_column($this->avail->changes((int) $alt->channelId, $seq, 5000, 0)['listings'], null, 'variant_id');
        self::assertSame(['mapped', 'legacy', 5], [$views['E1']['link'], $views['E1']['state'], $views['E1']['available']]);
        self::assertGreaterThan($seq, $views['E1']['version']);
        self::assertSame(1, $this->decisions());
    }

    public function testAStaleMapVersionIsA409AndChangesNothing(): void
    {
        $alt = $this->site('alt');
        $mapper = $this->staffUser('mapper');
        [$a, $b] = [$this->item('legacy'), $this->item('legacy')];
        $e = $this->listing($alt, 'E', null);
        $this->decide($mapper, 'link', $e, ['sku_id' => $a, 'expected_map_version' => 0]);
        $ex = self::refused(409, 'map_version_conflict', fn () => $this->decide($mapper, 'link', $e, ['sku_id' => $b, 'expected_map_version' => 0]));
        self::assertSame(['current_map_version' => 1, 'expected_map_version' => 0, 'status' => 'mapped', 'sku_id' => $a], $ex->detail);
        self::assertSame(['sku_id' => $a, 'units_per_item' => 1, 'status' => 'mapped', 'map_version' => 1], $this->link($e));
        self::assertSame(1, $this->decisions());
        self::refused(409, 'no_change', fn () => $this->decide($mapper, 'link', $e, ['sku_id' => $a]));
        self::refused(404, 'unknown_listing', fn () => $this->decide($mapper, 'link', 999_999, ['sku_id' => $a, 'expected_map_version' => 0]));
        self::refused(404, 'unknown_sku', fn () => $this->decide($mapper, 'link', $e, ['sku_id' => 999_999]));
        self::refused(400, 'bad_request', fn () => $this->ds->decide($mapper, ['action' => 'link', 'listing_id' => $e, 'sku_id' => $b]));
        self::refused(400, 'bad_action', fn () => $this->ds->decide($mapper, ['action' => 'relink', 'listing_id' => $e, 'expected_map_version' => 1]));
        self::refused(400, 'bad_units', fn () => $this->decide($mapper, 'link', $e, ['sku_id' => $b, 'units_per_item' => 1001]));
        self::refused(400, 'bad_request', fn () => $this->decide($mapper, 'unlink', $e, ['units_per_item' => 2]));
    }

    /** Two people confirm the same listing at the same moment: one wins, the other gets 409. */
    public function testTwoConcurrentConfirmationsOfOneListingOneWinsTheOtherGets409(): void
    {
        $alt = $this->site('alt');
        [$m1, $m2] = [$this->staffUser('mapper'), $this->staffUser('mapper')];
        [$a, $b] = [$this->item('legacy'), $this->item('legacy')];
        $e = $this->listing($alt, 'E', null);

        // m1's confirmation is in flight and holds the listing row; m2's waits for it, then sees v1.
        $first = self::session();
        (new DecisionService($first))->decide($m1, ['action' => 'link', 'listing_id' => $e, 'expected_map_version' => 0, 'sku_id' => $a]);
        $w = $this->spawn([['op' => 'decide', 'staff_id' => $m2->staffUserId,
            'request' => ['action' => 'link', 'listing_id' => $e, 'expected_map_version' => 0, 'sku_id' => $b]]]);
        $this->waitForLock('channel_listing', 'WAITING');
        $first->pdo()->commit();
        $r = $this->collect($w)['results'][0];
        self::assertSame([409, 'map_version_conflict'], [$r['status'], $r['error'] ?? null], json_encode($r));
        self::assertSame(['sku_id' => $a, 'units_per_item' => 1, 'status' => 'mapped', 'map_version' => 1], $this->link($e));

        // Truly simultaneous: exactly one 200 and one 409, whatever the order.
        $f = $this->listing($alt, 'F', null);
        $ws = [];
        foreach ([[$m1, $a], [$m2, $b]] as [$who, $sku]) {
            $ws[] = $this->spawn([['op' => 'decide', 'staff_id' => $who->staffUserId,
                'request' => ['action' => 'link', 'listing_id' => $f, 'expected_map_version' => 0, 'sku_id' => $sku]]]);
        }
        $statuses = array_map(fn (int $i): int => $this->collect($i)['results'][0]['status'], $ws);
        sort($statuses);
        self::assertSame([200, 409], $statuses);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_decision WHERE listing_id = ?', [$f]));
        self::assertSame(1, $this->link($f)['map_version']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM listing_map_history WHERE listing_id = ?', [$f]));
    }

    public function testUnitsOtherThanOneWaitForASecondPersonWhoMustBeAnotherMappingLead(): void
    {
        $alt = $this->site('alt', 'live');
        [$mapper, $mapper2, $lead, $lead2] = [$this->staffUser('mapper'), $this->staffUser('mapper'), $this->staffUser('mapping_lead'), $this->staffUser('mapping_lead')];
        $sku = $this->item('legacy', 50);
        $e = $this->listing($alt, 'P10', null);
        $this->ok($this->commit($alt, 'A-1', [self::line('P10', 'p1')])); // sold while unlinked

        $r = $this->decide($mapper, 'link', $e, ['sku_id' => $sku, 'units_per_item' => 10]);
        self::assertSame(['pending_second', ['units_per_item'], 0], [$r['state'], $r['needs_second'], $r['map_version']]);
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'unmapped', 'map_version' => 0], $this->link($e), 'nothing applied yet');
        $this->assertBal(50, 0, 0, $sku);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM listing_map_history'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE listing_id = ?', [$e]));

        self::refused(409, 'pending_second_exists', fn () => $this->decide($mapper2, 'link', $e, ['sku_id' => $sku]));
        self::refused(403, 'lead_required', fn () => $this->ds->approve($mapper2, $r['decision_id']));
        $ok = $this->ds->approve($lead, $r['decision_id'], 'the box says 10 x');
        self::assertSame(['applied', 'mapped', $sku, 10, 1, 1], [$ok['state'], $ok['status'], $ok['sku_id'], $ok['units_per_item'], $ok['map_version'], $ok['adopted']]);
        $d = self::$db->one('SELECT state, second_by, decided_by, applied_at FROM match_decision WHERE id = ?', [$r['decision_id']]);
        self::assertSame(['applied', $lead->staffUserId, $mapper->staffUserId], [$d['state'], $d['second_by'], $d['decided_by']]);
        self::assertNotNull($d['applied_at']);
        $this->assertBal(50, 10, 0, $sku); // p1 adopted with u = 10
        self::assertSame(10, self::$db->value("SELECT units_per_item FROM reservation_unit WHERE unit_id = 'p1'"));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.approve'"));
        self::refused(409, 'not_pending', fn () => $this->ds->approve($lead2, $r['decision_id']));
        self::refused(409, 'not_pending', fn () => $this->ds->withdraw($lead2, $r['decision_id']));
    }

    public function testTheDeciderCannotApproveTheirOwnAndAWithdrawnDecisionChangesNothing(): void
    {
        $alt = $this->site('alt');
        [$mapper, $lead, $lead2] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead'), $this->staffUser('mapping_lead')];
        $sku = $this->item('legacy');
        $e = $this->listing($alt, 'E', null);

        $r = $this->decide($lead, 'link', $e, ['sku_id' => $sku, 'units_per_item' => 2]);
        self::assertSame('pending_second', $r['state']);
        self::refused(403, 'same_person', fn () => $this->ds->approve($lead, $r['decision_id']));
        self::refused(403, 'lead_required', fn () => $this->ds->withdraw($mapper, $r['decision_id']));
        $w = $this->ds->withdraw($lead2, $r['decision_id'], 'it is a 3-pack');
        self::assertSame('withdrawn', $w['state']);
        self::assertSame(['withdrawn', $lead2->staffUserId, null],
            array_values(self::$db->one('SELECT state, second_by, applied_at FROM match_decision WHERE id = ?', [$r['decision_id']])));
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'unmapped', 'map_version' => 0], $this->link($e));
        self::refused(409, 'not_pending', fn () => $this->ds->approve($lead2, $r['decision_id']));

        // The listing is free again; a decider may withdraw their own pending decision.
        $again = $this->decide($mapper, 'link', $e, ['sku_id' => $sku, 'units_per_item' => 3]);
        self::assertSame('withdrawn', $this->ds->withdraw($mapper, $again['decision_id'])['state']);
        $one = $this->decide($mapper, 'link', $e, ['sku_id' => $sku]);
        self::assertSame(['applied', 1], [$one['state'], $one['map_version']]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.withdraw'"));
    }

    public function testLinksUnlinksAndIgnoresTouchingAProtectedItemNeedASecondPerson(): void
    {
        $alt = $this->site('alt');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        $strict = $this->item('strict', 5);
        $legacy = $this->item('legacy', 5);
        $e1 = $this->listing($alt, 'E1', null);
        $e2 = $this->listing($alt, 'E2', null);

        $r = $this->decide($mapper, 'link', $e1, ['sku_id' => $strict]);
        self::assertSame(['pending_second', ['protected_sku']], [$r['state'], $r['needs_second']]);
        $this->ds->approve($lead, $r['decision_id']);
        self::assertSame($strict, $this->link($e1)['sku_id']);

        foreach ([['unlink', []], ['link', ['sku_id' => $legacy]], ['ignore', []]] as [$action, $req]) {
            $p = $this->decide($mapper, $action, $e1, $req);
            self::assertSame(['pending_second', ['protected_sku']], [$p['state'], $p['needs_second']], $action);
            $this->ds->withdraw($lead, $p['decision_id']);
        }
        self::assertSame(['sku_id' => $strict, 'units_per_item' => 1, 'status' => 'mapped', 'map_version' => 1], $this->link($e1));
        // A legacy item, units 1: one person.
        self::assertSame('applied', $this->decide($mapper, 'link', $e2, ['sku_id' => $legacy])['state']);
    }

    public function testRejectKeepsTheProposalOpenAndALaterLinkToTheRejectedItemNeedsASecondPerson(): void
    {
        $alt = $this->site('alt');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        [$a, $b] = [$this->item('legacy'), $this->item('legacy')];
        $e = $this->listing($alt, 'E', null);
        $pid = $this->propose($e, 'Check', $a);
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'suggested', 'map_version' => 1], $this->link($e));

        $r = $this->decide($mapper, 'reject', $e, ['sku_id' => $a, 'proposal_id' => $pid, 'reason' => 'strength differs']);
        self::assertSame(['applied', 1, 'suggested'], [$r['state'], $r['map_version'], $r['status']]);
        self::assertSame([[$e, $a, $mapper->staffUserId, $r['decision_id']]],
            array_map('array_values', self::$db->all('SELECT listing_id, sku_id, decided_by, decision_id FROM match_reject')));
        self::assertSame('open', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pid]));
        self::assertSame(1, $this->version($e), 'a reject does not change the listing');
        $this->decide($mapper, 'reject', $e, ['sku_id' => $a, 'proposal_id' => $pid]);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject'), 'the pair is recorded once');

        $linked = $this->decide($mapper, 'link', $e, ['sku_id' => $b, 'proposal_id' => $pid]);
        self::assertSame(['applied', 'mapped', $b], [$linked['state'], $linked['status'], $linked['sku_id']]);
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pid]));
        self::refused(409, 'proposal_closed', fn () => $this->decide($mapper, 'reject', $e, ['sku_id' => $a, 'proposal_id' => $pid]));
        self::refused(409, 'reject_current_link', fn () => $this->decide($mapper, 'reject', $e, ['sku_id' => $b]));

        $this->decide($mapper, 'unlink', $e);
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'unmapped', 'map_version' => 3], $this->link($e));
        $back = $this->decide($mapper, 'link', $e, ['sku_id' => $a]);
        self::assertSame(['pending_second', ['previously_rejected']], [$back['state'], $back['needs_second']]);
        self::assertSame('applied', $this->ds->approve($lead, $back['decision_id'])['state']);
        self::assertSame($a, $this->link($e)['sku_id']);
        // A proposal of another listing cannot be used here.
        $other = $this->listing($alt, 'O', null);
        $op = $this->propose($other, 'Check', $a);
        self::refused(422, 'proposal_mismatch', fn () => $this->decide($mapper, 'ignore', $e, ['proposal_id' => $op]));
    }

    public function testNewItemMintsAnItemFromTheListingProfileAndFeaturesThenLinksIt(): void
    {
        $alt = $this->site('alt', 'live');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        $e = $this->profiled($alt, 'N1', 'Elux Legend 3500 Nic Salt - Blue Razz 20mg', [
            'strength_mg' => 20.0, 'nic_type' => 'salt', 'form' => 'nic_salt', 'volume_ml' => 10.0, 'puffs' => null, 'pack_units' => 2,
            'line_tokens' => ['elux', 'legend'], 'line_numbers' => ['3500'], 'flavour_tokens' => ['blue', 'razz'],
        ]);
        $this->ok($this->commit($alt, 'N-1', [self::line('N1', 'n1')]));
        $before = (int) self::$db->value('SELECT COUNT(*) FROM sku');

        $r = $this->decide($mapper, 'new_item', $e, ['card' => ['flavour' => 'Blue Razz Ice']]);
        self::assertSame(['applied', 'mapped', 1], [$r['state'], $r['status'], $r['adopted']]);
        $s = self::$db->one('SELECT * FROM sku WHERE id = ?', [$r['sku_id']]);
        self::assertSame([sprintf('CW-%06d', $r['sku_id']), 'Elux Legend 3500 Nic Salt - Blue Razz 20mg', 'Elux', '20.00', 'salt', 'elux legend 3500', 'nic_salt',
            'Blue Razz Ice', '10.00', null, 2, 'legacy', 'new_item', $e],
            [$s['code'], $s['name'], $s['brand'], $s['strength_mg'], $s['nic_type'], $s['line'], $s['form'], $s['flavour'], $s['volume_ml'],
                $s['puffs'], $s['pack_units'], $s['sell_policy'], $s['origin'], $s['origin_listing_id']]);
        self::assertSame($r['sku_code'], $s['code']);
        self::assertSame($r['sku_id'], self::$db->value('SELECT sku_id FROM match_decision WHERE id = ?', [$r['decision_id']]));
        self::assertSame($r['sku_id'], self::$db->value('SELECT sku_id FROM listing_map_history WHERE listing_id = ?', [$e]));
        $this->assertBal(0, 1, 0, $r['sku_id']); // n1 adopted into the new item

        // units 2: pending, nothing minted until the second person approves.
        $e2 = $this->profiled($alt, 'N2', 'Twin pack', ['pack_units' => 2]);
        $p = $this->decide($mapper, 'new_item', $e2, ['units_per_item' => 2, 'card' => ['brand' => 'Acme']]);
        self::assertSame(['pending_second', ['units_per_item']], [$p['state'], $p['needs_second']]);
        self::assertSame($before + 1, (int) self::$db->value('SELECT COUNT(*) FROM sku'));
        self::assertNull(self::$db->value('SELECT sku_id FROM match_decision WHERE id = ?', [$p['decision_id']]));
        $ok = $this->ds->approve($lead, $p['decision_id']);
        self::assertSame(['applied', 2], [$ok['state'], $ok['units_per_item']]);
        self::assertSame(['Twin pack', 'Acme', 'new_item'], array_values(self::$db->one('SELECT name, brand, origin FROM sku WHERE id = ?', [$ok['sku_id']])));
        self::assertSame([$ok['sku_id'], 2], array_values(self::$db->one('SELECT sku_id, units_per_item FROM listing_map_history WHERE listing_id = ?', [$e2])));

        self::refused(400, 'bad_card', fn () => $this->decide($mapper, 'new_item', $this->listing($alt, 'N3', null), ['card' => ['colour' => 'red']]));
        self::refused(422, 'name_required', fn () => $this->decide($mapper, 'new_item', $this->listing($alt, 'N4', null)));
        self::refused(400, 'bad_card', fn () => $this->decide($mapper, 'link', $e, ['sku_id' => $r['sku_id'], 'card' => ['name' => 'x']]));
    }

    /** R4 through the DecisionService: held and paid units of an unlinked listing enter the buckets at link time. */
    public function testALinkAdoptsTheUnitsTheListingSoldWhileUnlinked(): void
    {
        $vpg = $this->site('vpg', 'live');
        $alt = $this->site('alt', 'live');
        $mapper = $this->staffUser('mapper');
        $sku = $this->item('legacy', 5);
        $this->listing($vpg, 'V1', $sku);
        $e = $this->listing($alt, 'E1', null);
        $this->ok($this->commit($alt, 'A-1', [self::line('E1', 'a1', 'a2')]));
        $this->ok($this->reserve($alt, 'A-2', [self::line('E1', 'b1')]));
        $this->ship($alt, 'A-1', ['a2']); // left the building before the link: not adopted
        $this->assertBal(5, 0, 0, $sku);

        $r = $this->decide($mapper, 'link', $e, ['sku_id' => $sku]);
        self::assertSame(2, $r['adopted']);
        $this->assertBal(5, 1, 1, $sku);
        self::assertSame(3, $this->view($vpg, 'V1')['available']);
        self::assertSame(['a1' => $sku, 'a2' => null, 'b1' => $sku],
            array_column(self::$db->all('SELECT unit_id, sku_id FROM reservation_unit ORDER BY unit_id'), 'sku_id', 'unit_id'));
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE movement_type = 'adopt'"));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'listing.adopt_units'"));
        self::assertGreaterThan(0, (int) self::$db->value("SELECT COUNT(*) FROM stock_change WHERE sku_id = ? AND reason = 'stock'", [$sku]));
        // Later steps use the adopted snapshot: paying the hold, shipping.
        $this->ok($this->commit($alt, 'A-2', [self::line('E1', 'b1')]));
        $this->ship($alt, 'A-2', ['b1']);
        $this->assertBal(4, 1, 0, $sku);
    }

    public function testHistoryHasOnePeriodPerLinkChainedByTheDecisions(): void
    {
        $alt = $this->site('alt');
        $mapper = $this->staffUser('mapper');
        [$a, $b] = [$this->item('legacy'), $this->item('legacy')];
        $e = $this->listing($alt, 'E', null);
        $d1 = $this->decide($mapper, 'link', $e, ['sku_id' => $a])['decision_id'];
        $d2 = $this->decide($mapper, 'link', $e, ['sku_id' => $b])['decision_id'];
        $d3 = $this->decide($mapper, 'unlink', $e)['decision_id'];
        $h = self::$db->all('SELECT sku_id, valid_from, valid_to, decision_id, closed_by_decision_id FROM listing_map_history WHERE listing_id = ? ORDER BY id', [$e]);
        self::assertCount(2, $h);
        self::assertSame([$a, $d1, $d2], [$h[0]['sku_id'], $h[0]['decision_id'], $h[0]['closed_by_decision_id']]);
        self::assertSame([$b, $d2, $d3], [$h[1]['sku_id'], $h[1]['decision_id'], $h[1]['closed_by_decision_id']]);
        self::assertSame($h[0]['valid_to'], $h[1]['valid_from']);
        self::assertNotNull($h[1]['valid_to']);
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'unmapped', 'map_version' => 3], $this->link($e));
        self::assertSame([$a, 'mapped'], array_values(self::$db->one('SELECT prev_sku_id, prev_status FROM match_decision WHERE id = ?', [$d2])));
        self::refused(409, 'not_linked', fn () => $this->decide($mapper, 'unlink', $e));
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE listing_id = ?', [$e]));
    }

    public function testAConflictProposalIsDecidedByAMappingLeadOnly(): void
    {
        $alt = $this->site('alt');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        $sku = $this->item('legacy');
        $e = $this->listing($alt, 'E', null);
        $pid = $this->propose($e, 'Conflict', null, ['lane' => 'barcode', 'flags' => ['multi_sku_gtin']]);
        foreach ([['link', ['sku_id' => $sku]], ['reject', ['sku_id' => $sku]], ['ignore', []], ['new_item', ['card' => ['name' => 'X']]]] as [$action, $req]) {
            self::refused(403, 'lead_required', fn () => $this->decide($mapper, $action, $e, $req + ['proposal_id' => $pid]));
            self::refused(403, 'lead_required', fn () => $this->decide($mapper, $action, $e, $req)); // not naming it changes nothing
        }
        $r = $this->decide($lead, 'link', $e, ['sku_id' => $sku, 'proposal_id' => $pid]);
        self::assertSame(['applied', 'mapped'], [$r['state'], $r['status']]);
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pid]));
    }

    public function testRolesAndCallers(): void
    {
        $alt = $this->site('alt');
        $sku = $this->item('legacy');
        $e = $this->listing($alt, 'E', null);
        self::refused(403, 'role_not_allowed', fn () => $this->decide($this->staffUser('viewer'), 'link', $e, ['sku_id' => $sku]));
        self::refused(403, 'role_not_allowed', fn () => $this->decide($this->staffUser('manager'), 'link', $e, ['sku_id' => $sku]));
        self::refused(403, 'staff_not_allowed', fn () => $this->decide($this->staffUser('mapper', false), 'link', $e, ['sku_id' => $sku]));
        self::refused(403, 'staff_not_allowed', fn () => $this->decide(Caller::staff(999_999), 'link', $e, ['sku_id' => $sku]));
        self::refused(403, 'staff_required', fn () => $this->decide($alt, 'link', $e, ['sku_id' => $sku]));
        self::refused(403, 'staff_required', fn () => $this->decide(Caller::system('importer'), 'link', $e, ['sku_id' => $sku]));
        self::refused(403, 'lead_required', fn () => $this->decide($this->staffUser('mapper'), 'link', $e, ['sku_id' => $sku, 'bulk_batch_id' => 'b1']));
        self::assertSame(0, $this->decisions());
        $r = $this->decide($this->staffUser('mapping_lead'), 'link', $e, ['sku_id' => $sku, 'bulk_batch_id' => 'b1']);
        self::assertSame('b1', self::$db->value('SELECT bulk_batch_id FROM match_decision WHERE id = ?', [$r['decision_id']]));
    }

    public function testSuggestMovesAnUnmappedListingWithAnOpenProposalAndIsTheOnlySystemAction(): void
    {
        $alt = $this->site('alt');
        $sku = $this->item('legacy');
        $e = $this->listing($alt, 'E', null);
        $seq = (int) self::$db->value('SELECT COALESCE(MAX(seq), 0) FROM stock_change');
        self::refused(409, 'no_open_proposal', fn () => $this->decide(Caller::system('importer'), 'suggest', $e));
        $pid = $this->propose($e, 'Key', $sku, ['lane' => 'barcode', 'ai_outcome' => 'match', 'ai_confidence' => 95]);
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'suggested', 'map_version' => 1], $this->link($e));
        $d = self::$db->one("SELECT action, decided_by, actor, proposal_id, state FROM match_decision WHERE listing_id = ?", [$e]);
        self::assertSame(['suggest', null, 'system:test', $pid, 'applied'], array_values($d));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.suggest'"));
        $views = array_column($this->avail->changes((int) $alt->channelId, $seq, 5000, 0)['listings'], null, 'variant_id');
        self::assertSame(['suggested', 'unlinked'], [$views['E']['link'], $views['E']['state']]);
        self::refused(409, 'no_change', fn () => $this->decide(Caller::system('importer'), 'suggest', $e));
        // A mapper may suggest too; an unlink of a listing with an open proposal returns it to suggested.
        $mapper = $this->staffUser('mapper');
        $this->decide($mapper, 'link', $e, ['sku_id' => $sku, 'proposal_id' => $pid]);
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pid]));
        $p2 = $this->propose($e, 'Check', $sku, [], false, 'run-2');
        $this->decide($mapper, 'unlink', $e, ['proposal_id' => $p2]);
        self::assertSame('suggested', $this->link($e)['status']);
        self::assertSame('open', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p2]), 'an unlink leaves the proposal open');
    }

    public function testIgnoreSettlesTheProposalAndALaterRunSupersedesAnOpenOne(): void
    {
        $alt = $this->site('alt');
        $mapper = $this->staffUser('mapper');
        $sku = $this->item('legacy');
        $e = $this->listing($alt, 'E', null);
        $p1 = $this->propose($e, "Can't tell");
        $p2 = $this->propose($e, 'Check', $sku, [], true, 'run-2');
        self::assertSame(['superseded', 'open'], [
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p1]),
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p2]),
        ]);
        self::assertSame('exists', $this->proposals->add(Caller::system('test'), $e, $this->proposals->run('run-2', 'manual'), ['band' => 'Check'])['result']);
        $r = $this->decide($mapper, 'ignore', $e, ['proposal_id' => $p2, 'reason' => 'placeholder row']);
        self::assertSame(['applied', 'ignored', null], [$r['state'], $r['status'], $r['sku_id']]);
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p2]));
        self::refused(409, 'no_change', fn () => $this->decide($mapper, 'ignore', $e));
        // An ignored listing can still be linked later.
        self::assertSame('mapped', $this->decide($mapper, 'link', $e, ['sku_id' => $sku])['status']);
    }

    public function testMergeSkusWaitsForASecondPersonThenMovesEveryListingOfTheMergedItem(): void
    {
        $vpg = $this->site('vpg', 'live');
        $alt = $this->site('alt', 'live');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        [$keep, $dup] = [$this->item('legacy', 3), $this->item('legacy', 2)];
        [$v1, $v2, $e] = [$this->listing($vpg, 'V1', null), $this->listing($vpg, 'V2', null), $this->listing($alt, 'E', null)];
        $this->decide($lead, 'link', $v1, ['sku_id' => $keep]);
        $this->decide($lead, 'link', $v2, ['sku_id' => $dup]);
        $this->decide($lead, 'link', $e, ['sku_id' => $dup]);
        $this->ok($this->commit($alt, 'M-1', [self::line('E', 'm1')])); // sold on the item merged away
        $pid = $this->propose($v2, 'Manual', $keep, ['lane' => 'vpg_duplicate'], false, 'dups');

        self::refused(409, 'anchor_not_on_item', fn () => $this->decide($mapper, 'merge_skus', $v1, ['sku_id' => $keep, 'merge_from_sku_id' => $dup]));
        $r = $this->decide($mapper, 'merge_skus', $v2, ['sku_id' => $keep, 'merge_from_sku_id' => $dup, 'proposal_id' => $pid]);
        self::assertSame(['pending_second', ['merge']], [$r['state'], $r['needs_second']]);
        self::assertSame($dup, $this->link($e)['sku_id']);
        $seq = (int) self::$db->value('SELECT MAX(seq) FROM stock_change');

        $ok = $this->ds->approve($lead, $r['decision_id']);
        self::assertSame(['applied', $keep, 2], [$ok['state'], $ok['sku_id'], $ok['map_version']]);
        self::assertSame([$keep, $keep, $keep], [$this->link($v1)['sku_id'], $this->link($v2)['sku_id'], $this->link($e)['sku_id']]);
        self::assertSame($keep, self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$dup]));
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pid]));
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE seq > ? AND listing_id IN (?, ?)', [$seq, $v2, $e]));
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM listing_map_history WHERE closed_by_decision_id = ?', [$r['decision_id']]));
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM listing_map_history WHERE decision_id = ? AND valid_to IS NULL', [$r['decision_id']]));
        // The unit sold before the merge keeps its sale-time item; new sales go to the kept item.
        self::assertSame($dup, self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = 'm1'"));
        $this->ok($this->commit($alt, 'M-2', [self::line('E', 'm2')]));
        self::assertSame($keep, self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = 'm2'"));
        // A merged item is never linked again; protected items are not merged in v1.
        $x = $this->listing($alt, 'X', null);
        self::refused(409, 'sku_merged', fn () => $this->decide($mapper, 'link', $x, ['sku_id' => $dup]));
        $strict = $this->item('strict', 1);
        $pending = $this->decide($lead, 'link', $x, ['sku_id' => $strict]); // protected: two people
        $this->ds->approve($this->staffUser('mapping_lead'), $pending['decision_id']);
        self::refused(409, 'protected_merge', fn () => $this->decide($mapper, 'merge_skus', $x, ['sku_id' => $keep, 'merge_from_sku_id' => $strict]));
        self::assertSame(['pending_second', null], array_map(static fn (string $d): ?string => json_decode($d, true)['state'] ?? null,
            self::$db->column("SELECT detail FROM audit_log WHERE action = 'mapping.merge_skus' ORDER BY id")), 'the request, then the merge');
    }

    public function testMintAndLinkIsAOnePersonLinkByAMappingLead(): void
    {
        $vpg = $this->site('vpg');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        $v = $this->listing($vpg, '48', null);
        $card = DecisionService::cardFrom(['variant_title' => 'Nic Nic Nicotine Shots - 18mg/ml', 'brand' => 'Nic Nic'],
            ['strength_mg' => 18, 'form' => 'nic_shot', 'nic_type' => 'nic_shot']);
        self::refused(403, 'lead_required', fn () => $this->ds->mintAndLink($mapper, $v, 0, $card, 'vpg_mint:t'));
        $r = $this->ds->mintAndLink($lead, $v, 0, $card, 'vpg_mint:t', 'seed');
        self::assertSame(['applied', 'link', 'mapped', 1], [$r['state'], $r['action'], $r['status'], $r['map_version']]);
        self::assertSame(['vpg_mint', $v, 'Nic Nic Nicotine Shots - 18mg/ml', '18.00'],
            array_values(self::$db->one('SELECT origin, origin_listing_id, name, strength_mg FROM sku WHERE id = ?', [$r['sku_id']])));
        self::assertSame(['link', $r['sku_id'], 'vpg_mint:t'],
            array_values(self::$db->one('SELECT action, sku_id, bulk_batch_id FROM match_decision WHERE id = ?', [$r['decision_id']])));
        self::refused(409, 'already_linked', fn () => $this->ds->mintAndLink($lead, $v, 1, $card, 'vpg_mint:t'));
    }

    // ---- review fixes (fix2): design I7, the two-person rule for multiples, rejects across merges, remap corrections ----

    /**
     * I7: a decision is taken against a proposal. When a later run supersedes it while the decision waits
     * for its second person, approving is refused (as decide() refuses a stale proposal_id); the new
     * (here Conflict) proposal stays open for someone to see. Regression of review finding (linking lens).
     */
    public function testApprovalOfADecisionWhoseProposalWasSupersededIsRefused(): void
    {
        $alt = $this->site('alt');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        $a = $this->item('legacy');
        $e = $this->listing($alt, 'E', null);
        $p1 = $this->propose($e, 'Check', $a);
        $pending = $this->decide($mapper, 'link', $e, ['sku_id' => $a, 'units_per_item' => 2, 'proposal_id' => $p1]);
        self::assertSame('pending_second', $pending['state']);
        $p2 = $this->propose($e, 'Conflict', null, ['lane' => 'barcode', 'flags' => ['multi_sku_gtin']], true, 'run-2');
        self::assertSame('superseded', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p1]));

        $ex = self::refused(409, 'proposal_changed', fn () => $this->ds->approve($lead, $pending['decision_id']));
        self::assertSame(['decision_proposal_id' => $p1, 'open_proposal_id' => $p2], $ex->detail);
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'suggested', 'map_version' => 1], $this->link($e));
        self::assertSame(['pending_second', 'open'], [self::$db->value('SELECT state FROM match_decision WHERE id = ?', [$pending['decision_id']]),
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p2])]);
        // Withdraw, then decide again against the proposal that is open now: that one is settled, the old one stays superseded.
        $this->ds->withdraw($lead, $pending['decision_id']);
        $again = $this->decide($lead, 'link', $e, ['sku_id' => $a, 'proposal_id' => $p2]);
        self::assertSame(['applied', 'decided', 'superseded'], [$again['state'], self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p2]),
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p1])]);
        // A decision that named no proposal (there was none) is refused once one has opened (a listing with a
        // pending decision is not moved to suggested, so its version does not change either).
        $f = $this->listing($alt, 'F', null);
        $late = $this->decide($mapper, 'link', $f, ['sku_id' => $a, 'units_per_item' => 3]);
        $p3 = $this->propose($f, 'Key', $a, [], true, 'run-3');
        self::assertSame(0, $this->version($f));
        $ex = self::refused(409, 'proposal_changed', fn () => $this->ds->approve($lead, $late['decision_id']));
        self::assertSame(['decision_proposal_id' => null, 'open_proposal_id' => $p3], $ex->detail);
    }

    /** I7 in decide(): naming no proposal while one is open is refused; the unseen proposal is not settled. */
    public function testADecisionThatNamesNoProposalDoesNotSilentlySettleAnOpenOne(): void
    {
        $alt = $this->site('alt');
        $mapper = $this->staffUser('mapper');
        [$a, $b, $c] = [$this->item('legacy'), $this->item('legacy'), $this->item('legacy')];
        $e = $this->listing($alt, 'E', null);
        $this->decide($mapper, 'link', $e, ['sku_id' => $a]);
        $shown = $this->version($e); // the screen is drawn: no proposal yet
        $p = $this->propose($e, 'Check', $b, ['ai_outcome' => 'match'], true, 'run-2'); // arrives meanwhile
        self::assertSame($shown, $this->version($e), 'a proposal on a mapped listing does not move map_version');

        foreach ([['link', ['sku_id' => $c]], ['ignore', ['reason' => 'x']], ['unlink', []], ['new_item', ['card' => ['name' => 'N']]], ['reject', ['sku_id' => $b]]] as [$action, $req]) {
            $ex = self::refused(409, 'proposal_changed', fn () => $this->decide($this->staffUser('mapper'), $action, $e, $req + ['expected_map_version' => $shown]));
            self::assertSame($p, $ex->detail['open_proposal_id'], $action);
        }
        self::assertSame(['open', $a], [self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p]), $this->link($e)['sku_id']]);
        // Naming it works, and settles exactly it.
        $r = $this->decide($mapper, 'link', $e, ['sku_id' => $c, 'proposal_id' => $p]);
        self::assertSame(['applied', $c, 'decided'], [$r['state'], $r['sku_id'], self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$p])]);
    }

    /**
     * I7: the site renames the variant from a 10-pack to a 5-pack while a u = 10 link waits for its second
     * person. The identity change moves map_version on (ListingIngestService -> DecisionService::identityChanged),
     * so the approval and any form drawn before it are refused. A profile that is new with its listing does not.
     */
    public function testAnIdentityChangeAfterTheDecisionBlocksItsApproval(): void
    {
        $alt = $this->site('alt');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        $a = $this->item('legacy', 100, 'Elux Legend Blue Razz 10ml');
        $ingest = new \CW\Mapping\ListingIngestService(self::$db);
        $row = ['variant_id' => 'E10', 'product_title' => 'Elux Legend 10ml e-liquid', 'variant_title' => 'Blue Razz - 10 x 10ml', 'brand' => 'Elux'];
        $ingest->ingest(Caller::system('test'), (int) $alt->channelId, [$row]);
        $e = (int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = 'E10'");
        self::assertSame(0, $this->version($e), 'a listing created with its profile starts at 0');

        $pending = $this->decide($mapper, 'link', $e, ['sku_id' => $a, 'units_per_item' => 10]);
        $price = $ingest->ingest(Caller::system('test'), (int) $alt->channelId, [$row + ['price' => 4.99]]);
        self::assertFalse($price['listings'][0]['identity_changed']);
        self::assertSame(0, $this->version($e), 'a price change is not an identity change');
        $r = $ingest->ingest(Caller::system('test'), (int) $alt->channelId, [['variant_title' => 'Blue Razz - 5 x 10ml'] + $row]);
        self::assertTrue($r['listings'][0]['identity_changed']);
        self::assertSame(1, $this->version($e));
        self::assertSame(1, json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'listing.profiles' ORDER BY id DESC LIMIT 1"), true)['identity_changed']);

        $ex = self::refused(409, 'map_version_conflict', fn () => $this->ds->approve($lead, $pending['decision_id']));
        self::assertSame(['current_map_version' => 1, 'expected_map_version' => 0], $ex->detail);
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'unmapped', 'map_version' => 1], $this->link($e));
        $this->ds->withdraw($mapper, $pending['decision_id']);
        self::refused(409, 'map_version_conflict', fn () => $this->decide($mapper, 'link', $e, ['sku_id' => $a, 'units_per_item' => 5, 'expected_map_version' => 0]));
        self::assertSame('pending_second', $this->decide($mapper, 'link', $e, ['sku_id' => $a, 'units_per_item' => 5])['state']);

        // A listing sold before its profile arrived (no profile, a pending decision) moves on when the profile comes.
        $this->ok($this->reserve($alt, 'S-1', [self::line('E11', 's1')]));
        $e11 = (int) self::$db->value("SELECT id FROM channel_listing WHERE external_variant_id = 'E11'");
        $p11 = $this->decide($mapper, 'link', $e11, ['sku_id' => $a, 'units_per_item' => 2]);
        $ingest->ingest(Caller::system('test'), (int) $alt->channelId, [['variant_id' => 'E11', 'product_title' => 'Something else entirely']]);
        self::refused(409, 'map_version_conflict', fn () => $this->ds->approve($lead, $p11['decision_id']));
        $this->ds->withdraw($lead, $p11['decision_id']);
        $this->ok($this->release($alt, 'S-1'));
    }

    /**
     * Two-person rule for multiples (plan §7.1 "units_per_item <> 1"; design A.9 rule 4): a verified 10-pack
     * link (two people) is not turned back into u = 1, relinked, replaced by a new item, unlinked or ignored
     * by one person. Regression of review finding (linking lens).
     */
    public function testChangingAVerifiedMultipleNeedsASecondPerson(): void
    {
        $alt = $this->site('alt');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        [$a, $b] = [$this->item('legacy', 100), $this->item('legacy', 100)];
        $e = $this->listing($alt, 'P10', null);
        self::$db->exec("INSERT INTO listing_profile (listing_id, product_title) VALUES (?, 'Ten pack')", [$e]);
        $this->ds->approve($lead, $this->decide($mapper, 'link', $e, ['sku_id' => $a, 'units_per_item' => 10])['decision_id']);
        self::assertSame(10, $this->link($e)['units_per_item']);

        foreach ([['link', ['sku_id' => $a, 'units_per_item' => 1]], ['link', ['sku_id' => $b]], ['new_item', []], ['unlink', []], ['ignore', ['reason' => 'x']]] as [$action, $req]) {
            $r = $this->decide($this->staffUser('mapper'), $action, $e, $req);
            self::assertSame(['pending_second', ['units_per_item']], [$r['state'], $r['needs_second']], $action . ' ' . json_encode($req));
            $this->ds->withdraw($lead, $r['decision_id']);
        }
        self::assertSame(['sku_id' => $a, 'units_per_item' => 10, 'status' => 'mapped', 'map_version' => 1], $this->link($e));
        $r = $this->decide($mapper, 'link', $e, ['sku_id' => $a, 'units_per_item' => 1]);
        self::assertSame('applied', $this->ds->approve($lead, $r['decision_id'])['state']);
        // Back at u = 1, one person again.
        self::assertSame('applied', $this->decide($mapper, 'link', $e, ['sku_id' => $b])['state']);
    }

    /** match_reject survives merges: a merge that would contradict one is refused; a reject of a merged item counts for its keeper. */
    public function testAMergeDoesNotLinkAListingToAnItemItRejected(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        [$keep, $dup] = [$this->item('legacy'), $this->item('legacy')];
        [$v1, $v2, $e, $k] = [$this->listing($vpg, 'V1', null), $this->listing($vpg, 'V2', null), $this->listing($alt, 'E', null), $this->listing($alt, 'K', null)];
        $this->decide($lead, 'link', $v1, ['sku_id' => $keep]);
        $this->decide($lead, 'link', $v2, ['sku_id' => $dup]);
        $this->decide($mapper, 'link', $e, ['sku_id' => $dup]);
        $this->decide($mapper, 'reject', $e, ['sku_id' => $keep, 'reason' => 'E is 20mg, the kept item is 10mg']);

        $ex = self::refused(409, 'rejected_pair', fn () => $this->decide($mapper, 'merge_skus', $v2, ['sku_id' => $keep, 'merge_from_sku_id' => $dup]));
        self::assertSame(['listing_ids' => [$e]], $ex->detail);
        // The other direction: a listing of the kept item that rejected the item merged away.
        $this->decide($mapper, 'unlink', $e);
        $this->decide($mapper, 'link', $k, ['sku_id' => $keep]);
        $this->decide($mapper, 'reject', $k, ['sku_id' => $dup]);
        $ex = self::refused(409, 'rejected_pair', fn () => $this->decide($mapper, 'merge_skus', $v2, ['sku_id' => $keep, 'merge_from_sku_id' => $dup]));
        self::assertSame(['listing_ids' => [$k]], $ex->detail);
        // Once no linked listing contradicts it the merge may wait for approval; a reject recorded meanwhile blocks the approval.
        $this->decide($mapper, 'unlink', $k);
        $m = $this->decide($mapper, 'merge_skus', $v2, ['sku_id' => $keep, 'merge_from_sku_id' => $dup]);
        self::assertSame(['pending_second', ['merge']], [$m['state'], $m['needs_second']]);
        $this->decide($lead, 'reject', $v1, ['sku_id' => $dup]);
        self::refused(409, 'rejected_pair', fn () => $this->ds->approve($this->staffUser('mapping_lead'), $m['decision_id']));
        self::assertSame(0, (int) self::$db->value(
            "SELECT COUNT(*) FROM match_reject r JOIN channel_listing l ON l.id = r.listing_id AND l.sku_id = r.sku_id WHERE l.status IN ('mapped', 'quarantined')",
        ));
        self::assertNull(self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$dup]));
    }

    public function testARejectOfAMergedItemStillCountsForTheItemItWasMergedInto(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        [$keep, $dup, $third] = [$this->item('legacy'), $this->item('legacy'), $this->item('legacy')];
        [$v1, $v2, $v3, $x] = [$this->listing($vpg, 'V1', null), $this->listing($vpg, 'V2', null), $this->listing($vpg, 'V3', null), $this->listing($alt, 'X', null)];
        $this->decide($lead, 'link', $v1, ['sku_id' => $keep]);
        $this->decide($lead, 'link', $v2, ['sku_id' => $dup]);
        $this->decide($lead, 'link', $v3, ['sku_id' => $third]);
        $this->decide($mapper, 'reject', $x, ['sku_id' => $dup, 'reason' => 'not this flavour']);
        $this->ds->approve($lead, $this->decide($mapper, 'merge_skus', $v2, ['sku_id' => $keep, 'merge_from_sku_id' => $dup])['decision_id']);
        // A chain: keep is merged into third later; the reject of dup still counts for third.
        $this->ds->approve($lead, $this->decide($mapper, 'merge_skus', $v1, ['sku_id' => $third, 'merge_from_sku_id' => $keep])['decision_id']);

        $r = $this->decide($mapper, 'link', $x, ['sku_id' => $third]);
        self::assertSame(['pending_second', ['previously_rejected']], [$r['state'], $r['needs_second']]);
    }

    /**
     * Design A.9 rule 5 / A.12: relinking a listing whose units are in flight on a counted (strict) item queues
     * a recount of both items (count_review, source remap_correction, one per item and warehouse, deduplicated
     * per decision); the units keep their sale-time item (I14). Legacy -> legacy queues nothing.
     */
    public function testRelinkingAListingWithUnitsInFlightOnACountedItemQueuesARecount(): void
    {
        $alt = $this->site('alt', 'live');
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        $p = $this->item('strict', 5);
        $q = $this->item('legacy', 5);
        $e = $this->listing($alt, 'E', null);
        $this->ds->approve($lead, $this->decide($mapper, 'link', $e, ['sku_id' => $p])['decision_id']);
        $this->ok($this->commit($alt, 'O-1', [self::line('E', 'u1')]));
        $this->ok($this->commit($alt, 'O-2', [self::line('E', 'u2')]));
        $this->ship($alt, 'O-2', ['u2']);
        $this->assertBal(4, 1, 0, $p);

        $d = $this->decide($mapper, 'link', $e, ['sku_id' => $q, 'reason' => 'wrong item']);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'remap_correction'"), 'nothing until it applies');
        $this->ds->approve($lead, $d['decision_id']);
        self::assertSame($p, self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = 'u1'"), 'snapshot kept (I14)');
        $rows = self::$db->all("SELECT sku_id, warehouse_id, ref, detail, dedupe_key, status FROM count_review WHERE source = 'remap_correction' ORDER BY sku_id");
        self::assertSame([$p, $q], array_map(static fn (array $r): int => (int) $r['sku_id'], $rows));
        foreach ($rows as $r) {
            self::assertSame([self::warehouseId('MAIN'), "listing {$e}", 'open', "remap:{$d['decision_id']}:{$r['sku_id']}:" . self::warehouseId('MAIN')],
                [(int) $r['warehouse_id'], $r['ref'], $r['status'], $r['dedupe_key']]);
            $detail = json_decode((string) $r['detail'], true);
            self::assertSame([$d['decision_id'], $p, $q, 2, 2], [$detail['decision_id'], $detail['from_sku_id'], $detail['to_sku_id'], $detail['unit_count'], $detail['central_units']]);
            self::assertEquals(['allocated' => 1, 'shipped' => 1], $detail['states']); // JSON objects come back in MySQL's key order
            self::assertEqualsCanonicalizing(['u1', 'u2'], $detail['unit_ids']);
        }
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.link' ORDER BY id DESC LIMIT 1"), true);
        self::assertEquals(['units' => 2, 'count_reviews' => 2, 'sku_ids' => [$p, $q]], $audit['remap_correction']);

        // Legacy -> legacy: uncounted estimates, nothing queued. Unlinking a listing with units on a strict item: its item only.
        $r2 = $this->item('legacy', 5);
        $this->decide($mapper, 'link', $e, ['sku_id' => $r2]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'remap_correction'"));
        $f = $this->listing($alt, 'F', null);
        $this->ds->approve($lead, $this->decide($mapper, 'link', $f, ['sku_id' => $p])['decision_id']);
        $this->ok($this->reserve($alt, 'H-1', [self::line('F', 'h1')]));
        $un = $this->decide($mapper, 'unlink', $f);
        $this->ds->approve($lead, $un['decision_id']);
        self::assertSame([[$p, 'held']], array_map(static fn (array $r): array => [(int) $r['sku_id'], (string) array_key_first(json_decode((string) $r['detail'], true)['states'])],
            self::$db->all("SELECT sku_id, detail FROM count_review WHERE source = 'remap_correction' AND dedupe_key LIKE ?", ["remap:{$un['decision_id']}:%"])));
        $this->ok($this->release($alt, 'H-1'));
    }

    /**
     * Control (I14): open reservations across a relink keep their sale-time item: a held unit paid and shipped
     * after the listing moved stays on the old item, a new order goes to the new one, the invariants hold.
     */
    public function testOpenReservationsKeepTheirSnapshotAcrossARelink(): void
    {
        $alt = $this->site('alt', 'live');
        $mapper = $this->staffUser('mapper');
        [$a, $b] = [$this->item('legacy', 5), $this->item('legacy', 5)];
        $e = $this->listing($alt, 'E', null);
        $this->ok($this->reserve($alt, 'H-0', [self::line('E', 'z1')])); // unlinked hold
        $this->decide($mapper, 'link', $e, ['sku_id' => $a]);
        $this->assertBal(5, 0, 1, $a);
        $this->ok($this->reserve($alt, 'H-1', [self::line('E', 'h1')]));
        $this->decide($mapper, 'link', $e, ['sku_id' => $b]);
        $this->ok($this->commit($alt, 'H-1', [self::line('E', 'h1')]));
        $this->ok($this->commit($alt, 'H-0', [self::line('E', 'z1')]));
        $this->ship($alt, 'H-1', ['h1']);
        $this->assertBal(4, 1, 0, $a);
        $this->assertBal(5, 0, 0, $b);
        $this->ok($this->commit($alt, 'N-1', [self::line('E', 'n1')]));
        $this->assertBal(5, 1, 0, $b);
    }

    /** @param array<string, mixed> $features */
    private function profiled(Caller $site, string $variant, string $title, array $features): int
    {
        $id = $this->listing($site, $variant, null);
        self::$db->exec(
            "INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, features, features_version) VALUES (?, ?, ?, 'Elux', ?, 'n2.0')",
            [$id, $title, $title, json_encode($features, JSON_THROW_ON_ERROR)],
        );
        return $id;
    }
}
