<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\DecisionService;
use CW\Reorder\DemandBuilder;
use CW\Tests\Integration\Reorder\ReorderFixtures;
use CW\Tests\Support\MappingTestCase;

/**
 * Vape and Go's duplicate listings handled by CW (the owner's decision of 6 Oct 2026; docs/decisions.md M31-M35): a mapping
 * lead merges two uncounted legacy items alone, and the merge moves the merged item's available stock onto the kept item
 * through CW\Stock (value seqs, feed rows for both listings, the units in flight keeping their sale-time item); a counted
 * item, a multiple or a mapper's merge waits for a second mapping lead; protected items are never merged. A group's
 * decisions run in one transaction (decideGroup). "Different products" rejects the suggestion for good. A split undoes a
 * merge for one listing with the stock that came with it. The reorder demand of the kept item counts both listings. The
 * stock invariants (D44, value sequence I3) are asserted after every test (StockTestCase).
 */
final class DuplicateMergeTest extends MappingTestCase
{
    use ReorderFixtures;

    protected function tearDown(): void
    {
        $this->cleanReorderFixtures();
        parent::tearDown();
    }

    /**
     * The Corex-like pair: listing A (the keeper, item K with 30 on hand) and listing B (item F with 8 on hand), the same pods
     * under two titles, and B's open merge suggestion (lane vpg_duplicate, proposing K), as bin/mint_vpg.php records it.
     *
     * @return array{site: Caller, k: int, f: int, a: int, b: int, pb: int}
     */
    private function corex(int $kStock = 30, int $fStock = 8): array
    {
        $site = $this->site('vapeandgo', 'shadow');
        $k = $this->item('legacy', $kStock, 'Vaporesso Xros Corex 3.0 Pods (Pack of 4) - 0.4 ohm');
        $f = $this->item('legacy', $fStock, 'Vaporesso Xros Corex Replacement Pods - 0.4ohm Corex 3.0 Pod - 4 Pack');
        $a = $this->listing($site, '23408', $k);
        $b = $this->listing($site, '25772', $f);
        foreach ([[$a, 'Vaporesso Xros Corex 3.0 Pods (Pack of 4)', '0.4 ohm'], [$b, 'Vaporesso Xros Corex Replacement Pods', '0.4ohm Corex 3.0 Pod - 4 Pack']] as [$id, $pt, $vt]) {
            self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, barcodes, features) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $pt, $vt, 'Vaporesso', '[]', json_encode(['form' => 'pod_refill', 'resistance_ohm' => 0.4, 'pack_units' => 4, 'line_numbers' => ['3.0']], JSON_THROW_ON_ERROR)]);
        }
        $pb = $this->suggest($b, $k, 7, [['23408', $k], ['25772', $f]]);
        return ['site' => $site, 'k' => $k, 'f' => $f, 'a' => $a, 'b' => $b, 'pb' => $pb];
    }

    /** A merge suggestion as mint_vpg records it (lane vpg_duplicate, band Manual, group number). @param list<array{0: string, 1: int}> $members */
    private function suggest(int $listingId, int $keeperSku, int $group, array $members, string $run = 'run2-vpg-duplicates'): int
    {
        $runId = $this->proposals->run($run, 'vpg_duplicates');
        $r = $this->proposals->add(Caller::system('mint_vpg'), $listingId, $runId, [
            'proposed_sku_id' => $keeperSku, 'band' => 'Manual', 'lane' => 'vpg_duplicate', 'flags' => ['merge_suggestion', 'identity_key'],
            'evidence' => ['group' => $group, 'kind' => 'identity_key', 'keeper' => ['vpg_variant_id' => $members[0][0], 'sku_id' => $members[0][1]],
                'members' => array_map(static fn (array $m): array => ['vpg_variant_id' => $m[0], 'sku_id' => $m[1], 'units_30d' => 1], $members)],
        ], false);
        self::assertSame('created', $r['result']);
        return $r['proposal_id'];
    }

    /** @return list<array{type: string, sku: int, qty: int, doc: ?string, actor: string}> the merge / split rows of the ledger, in order */
    private function mergeRows(): array
    {
        return array_map(static fn (array $r): array => ['type' => (string) $r['movement_type'], 'sku' => (int) $r['sku_id'], 'qty' => (int) $r['qty_delta'],
            'doc' => $r['doc_ref'], 'actor' => (string) $r['actor']], self::$db->all(
            "SELECT movement_type, sku_id, qty_delta, doc_ref, actor FROM stock_ledger WHERE movement_type IN ('merge_out', 'merge_in', 'split_out', 'split_in') ORDER BY id"));
    }

    public function testALeadMergesTwoUncountedItemsAtOnceAndTheKeptItemHoldsBothStocks(): void
    {
        $x = $this->corex();
        $alt = $this->site('electrofag', 'off');
        $e = $this->listing($alt, 'E9', $x['f']); // another site's listing of the merged item: it moves with it
        $lead = $this->staffUser('mapping_lead');
        $seq = (int) self::$db->value('SELECT COALESCE(MAX(seq), 0) FROM stock_change');

        $r = $this->decide($lead, 'merge_skus', $x['b'], ['sku_id' => $x['k'], 'merge_from_sku_id' => $x['f'], 'proposal_id' => $x['pb']]);
        self::assertSame(['applied', [], $x['k']], [$r['state'], $r['needs_second'], $r['sku_id']], 'one person: a mapping lead, two uncounted legacy items');
        self::assertSame([$x['k'], $x['k'], $x['k']], [$this->link($x['a'])['sku_id'], $this->link($x['b'])['sku_id'], $this->link($e)['sku_id']]);
        self::assertSame($x['k'], self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$x['f']]));
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$x['pb']]));

        // The stock: the merged item's 8 moved onto the kept item through Stock (one row out, one in), the merged item at zero.
        $this->assertBal(38, 0, 0, $x['k']);
        $this->assertBal(0, 0, 0, $x['f']);
        $doc = 'merge:' . $r['decision_id'];
        $actor = 'staff:' . $lead->staffUserId;
        self::assertSame([['type' => 'merge_out', 'sku' => $x['f'], 'qty' => -8, 'doc' => $doc, 'actor' => $actor],
            ['type' => 'merge_in', 'sku' => $x['k'], 'qty' => 8, 'doc' => $doc, 'actor' => $actor]], $this->mergeRows());
        // Both rows are in their items' value sequences (C0, I3): the invariants (7-9) check the whole sequence after the test.
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id "
            . "WHERE l.movement_type IN ('merge_out', 'merge_in')"));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.merge_skus' ORDER BY id DESC LIMIT 1"), true);
        self::assertEquals(['kind' => 'merge', 'from' => sprintf('CW-%06d', $x['f']), 'to' => sprintf('CW-%06d', $x['k']), 'moved' => ['MAIN' => 8]], $audit['stock']);

        // The feed: the moved listings' link rows and both items' stock rows; the site sees both pages at the combined figure.
        self::assertSame([$x['b'], $e], array_map('intval', self::$db->column("SELECT listing_id FROM stock_change WHERE seq > ? AND reason = 'link' ORDER BY listing_id", [$seq])));
        self::assertEqualsCanonicalizing([$x['k'], $x['f']], array_map('intval', self::$db->column('SELECT sku_id FROM stock_change WHERE seq > ? AND sku_id IS NOT NULL', [$seq])));
        $views = array_column($this->avail->changes((int) $x['site']->channelId, $seq, 5000, 0)['listings'], null, 'variant_id');
        self::assertSame([38, 38], [$views['23408']['available'], $views['25772']['available']]);

        // A new sale on the merged page is the kept item's.
        $this->ok($this->commit($x['site'], 'S-1', [self::line('25772', 's1')]));
        self::assertSame($x['k'], self::$db->value("SELECT sku_id FROM reservation_unit WHERE unit_id = 's1'"));
        $this->assertBal(38, 1, 0, $x['k']);
    }

    /** Units sold before the merge keep their sale-time item (I14): what they still need stays on the merged item. */
    public function testUnitsInFlightKeepTheirItemAndTheMergedItemKeepsWhatTheyNeed(): void
    {
        $x = $this->corex(30, 10);
        $lead = $this->staffUser('mapping_lead');
        $this->ok($this->commit($x['site'], 'P-1', [self::line('25772', 'p1', 'p2')]));     // paid, on F
        $this->ok($this->reserve($x['site'], 'H-1', [self::line('25772', 'h1')]));          // a checkout, on F
        $this->assertBal(10, 2, 1, $x['f']);

        $r = $this->decide($lead, 'merge_skus', $x['b'], ['sku_id' => $x['k'], 'merge_from_sku_id' => $x['f'], 'proposal_id' => $x['pb']]);
        self::assertSame('applied', $r['state']);
        // F's available (10 - 2 - 1 = 7) moved; F keeps on_hand for its own units in flight and ends at available 0.
        $this->assertBal(37, 0, 0, $x['k']);
        $this->assertBal(3, 2, 1, $x['f']);
        self::assertSame([$x['f'], $x['f'], $x['f']], array_map('intval', self::$db->column("SELECT sku_id FROM reservation_unit WHERE unit_id IN ('p1', 'p2', 'h1') ORDER BY unit_id")));

        // They ship and expire against their sale-time item, as before; what a released checkout leaves stays on it (shown on its page).
        $this->ok($this->ship($x['site'], 'P-1', ['p1', 'p2']));
        $this->ok($this->release($x['site'], 'H-1'));
        $this->assertBal(1, 0, 0, $x['f']);
        $this->assertBal(37, 0, 0, $x['k']);
    }

    public function testACountedItemAMultipleOrAMappersMergeWaitsForASecondMappingLead(): void
    {
        $x = $this->corex();
        [$mapper, $lead, $lead2] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead'), $this->staffUser('mapping_lead')];

        // A mapper's merge waits for a lead (as before M31).
        $m = $this->decide($mapper, 'merge_skus', $x['b'], ['sku_id' => $x['k'], 'merge_from_sku_id' => $x['f'], 'proposal_id' => $x['pb']]);
        self::assertSame(['pending_second', ['merge']], [$m['state'], $m['needs_second']]);
        $this->assertBal(30, 0, 0, $x['k']);
        $this->ds->withdraw($mapper, $m['decision_id']);

        // The kept item was counted: two people, and the approval opens a recount of the kept item.
        $this->book('count', $x['k'], 29, 'MAIN', '2026-09-26T10:00:00Z');
        self::assertSame([$x['k']], $this->ds->counted([$x['k'], $x['f']]));
        $c = $this->decide($lead, 'merge_skus', $x['b'], ['sku_id' => $x['k'], 'merge_from_sku_id' => $x['f'], 'proposal_id' => $x['pb']]);
        self::assertSame(['pending_second', ['counted_item']], [$c['state'], $c['needs_second']]);
        self::refused(403, 'same_person', fn () => $this->ds->approve($lead, $c['decision_id']));
        $ok = $this->ds->approve($lead2, $c['decision_id']);
        self::assertSame('applied', $ok['state']);
        $this->assertBal(37, 0, 0, $x['k']);
        self::assertSame([['sku_id' => $x['k'], 'source' => DecisionService::RECOUNT_SOURCE, 'status' => 'open']],
            self::$db->all("SELECT sku_id, source, status FROM count_review WHERE source = 'merge_recount'"));

        // A listing that would move with units per item 2 (a multiple two people verified, M21): two people again.
        $site = $x['site'];
        [$k2, $f2] = [$this->item('legacy', 5), $this->item('legacy', 5)];
        $a2 = $this->listing($site, 'A2', $k2);
        $b2 = $this->listing($site, 'B2', $f2);
        $this->listing($site, 'B2x2', $f2, 2);
        $p2 = $this->suggest($b2, $k2, 8, [['A2', $k2], ['B2', $f2]]);
        $u = $this->decide($lead, 'merge_skus', $b2, ['sku_id' => $k2, 'merge_from_sku_id' => $f2, 'proposal_id' => $p2]);
        self::assertSame(['pending_second', ['units_per_item']], [$u['state'], $u['needs_second']]);
        self::assertSame($k2, $this->link($a2)['sku_id']);

        // A protected item is never merged (its counted stock and policy need the recount flow).
        $strict = $this->item('strict', 3);
        $s1 = $this->listing($site, 'S1', $strict);
        self::refused(409, 'protected_merge', fn () => $this->decide($lead, 'merge_skus', $s1, ['sku_id' => $x['k'], 'merge_from_sku_id' => $strict]));
    }

    /** The Duplicates screen's one POST: a 3-listing group, one merge and one "different product", in one transaction. */
    public function testAThreeListingGroupIsDecidedInOneTransaction(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        $lead = $this->staffUser('mapping_lead');
        [$k, $f1, $f2] = [$this->item('legacy', 20), $this->item('legacy', 6), $this->item('legacy', 4)];
        [$a, $b, $c] = [$this->listing($site, '100', $k), $this->listing($site, '101', $f1), $this->listing($site, '102', $f2)];
        $members = [['100', $k], ['101', $f1], ['102', $f2]];
        $pb = $this->suggest($b, $k, 9, $members);
        $pc = $this->suggest($c, $k, 9, $members);

        // A stale form (C changed since the page was drawn) refuses the whole group: nothing moves.
        $stale = [$a => $this->version($a), $b => $this->version($b), $c => $this->version($c) + 1];
        $requests = [
            ['action' => 'merge_skus', 'listing_id' => $b, 'expected_map_version' => $this->version($b), 'sku_id' => $k, 'merge_from_sku_id' => $f1, 'proposal_id' => $pb],
            ['action' => 'reject', 'listing_id' => $c, 'expected_map_version' => $this->version($c), 'sku_id' => $k, 'proposal_id' => $pc],
        ];
        self::refused(409, 'map_version_conflict', fn () => $this->ds->decideGroup($lead, [$a, $b, $c], $requests, 'g9', $stale));
        self::assertSame([$f1, null], [$this->link($b)['sku_id'], self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$f1])]);
        self::assertSame([], $this->mergeRows());

        $out = $this->ds->decideGroup($lead, [$a, $b, $c], $requests, 'g9', [$a => $this->version($a), $b => $this->version($b), $c => $this->version($c)]);
        self::assertSame([['merge_skus', 'applied'], ['reject', 'applied']], array_map(static fn (array $r): array => [$r['action'], $r['state']], $out));
        self::assertSame([$k, $k, $f2], [$this->link($a)['sku_id'], $this->link($b)['sku_id'], $this->link($c)['sku_id']]);
        $this->assertBal(26, 0, 0, $k);
        $this->assertBal(0, 0, 0, $f1);
        $this->assertBal(4, 0, 0, $f2);
        self::assertSame(['decided', 'decided'], [self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pb]),
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pc])], 'the reject answered C\'s suggestion');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject WHERE listing_id = ? AND sku_id = ?', [$c, $k]));
        $group = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.duplicates'"), true);
        self::assertSame('g9', $group['group']);
        self::assertSame(['MAIN' => 6], $group['stock'][$out[0]['decision_id']]['moved']);
        // C is never suggested for K again, in either direction; a merge of the two is refused (M22).
        $run = $this->proposals->run('run4-vpg-duplicates', 'vpg_duplicates');
        self::assertSame('rejected_before', $this->proposals->add(Caller::system('mint_vpg'), $c, $run,
            ['proposed_sku_id' => $k, 'band' => 'Manual', 'lane' => 'vpg_duplicate'], false)['result']);
        self::assertSame('rejected_before', $this->proposals->add(Caller::system('mint_vpg'), $a, $run,
            ['proposed_sku_id' => $f2, 'band' => 'Manual', 'lane' => 'vpg_duplicate'], false)['result']);
        self::assertSame('same_item', $this->proposals->add(Caller::system('mint_vpg'), $b, $run,
            ['proposed_sku_id' => $k, 'band' => 'Manual', 'lane' => 'vpg_duplicate'], false)['result']);
        self::refused(409, 'rejected_pair', fn () => $this->decide($lead, 'merge_skus', $c, ['sku_id' => $k, 'merge_from_sku_id' => $f2]));
    }

    /** The person keeps another listing than the run's keeper: the run keeper's item folds into it, its own suggestion is settled. */
    public function testAnotherKeeperFoldsTheRunKeepersItemAndSettlesTheFulfilledSuggestions(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        $lead = $this->staffUser('mapping_lead');
        [$k, $f1, $f2] = [$this->item('legacy', 3), $this->item('legacy', 9), $this->item('legacy', 1)];
        [$a, $b, $c] = [$this->listing($site, '200', $k), $this->listing($site, '201', $f1), $this->listing($site, '202', $f2)];
        $members = [['200', $k], ['201', $f1], ['202', $f2]];
        $pb = $this->suggest($b, $k, 10, $members);
        $pc = $this->suggest($c, $k, 10, $members);
        // Keep B's item F1: A (no suggestion of its own) and C fold into it.
        $out = $this->ds->decideGroup($lead, [$a, $b, $c], [
            ['action' => 'merge_skus', 'listing_id' => $a, 'expected_map_version' => $this->version($a), 'sku_id' => $f1, 'merge_from_sku_id' => $k],
            ['action' => 'merge_skus', 'listing_id' => $c, 'expected_map_version' => $this->version($c), 'sku_id' => $f1, 'merge_from_sku_id' => $f2, 'proposal_id' => $pc],
        ], 'g10', [$b => $this->version($b)]);
        self::assertSame(['applied', 'applied'], array_column($out, 'state'));
        self::assertSame([$f1, $f1, $f1], [$this->link($a)['sku_id'], $this->link($b)['sku_id'], $this->link($c)['sku_id']]);
        $this->assertBal(13, 0, 0, $f1);
        self::assertSame(['decided', 'decided'], [self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pb]),
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pc])], 'B\'s suggestion (K) is fulfilled: K is F1 now');
        self::assertSame([$pb], json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.duplicates'"), true)['settled_proposals']);
    }

    /** M33: the undo of a wrong merge for one listing, with the stock that came with it less what it sold since. */
    public function testASplitMovesTheListingBackWithTheStockThatCameWithIt(): void
    {
        $x = $this->corex(30, 8);
        $alt = $this->site('electrofag', 'off');
        $e = $this->listing($alt, 'E9', $x['f']);
        [$mapper, $lead] = [$this->staffUser('mapper'), $this->staffUser('mapping_lead')];
        $m = $this->decide($lead, 'merge_skus', $x['b'], ['sku_id' => $x['k'], 'merge_from_sku_id' => $x['f'], 'proposal_id' => $x['pb']]);
        $this->assertBal(38, 0, 0, $x['k']);
        // After the merge: B sells 3 from the kept item (2 shipped, 1 still to ship), A sells 1.
        $this->ok($this->commit($x['site'], 'B-1', [self::line('25772', 'b1', 'b2', 'b3')]));
        $this->ok($this->ship($x['site'], 'B-1', ['b1', 'b2']));
        $this->ok($this->commit($x['site'], 'A-1', [self::line('23408', 'a1')]));
        $this->ok($this->ship($x['site'], 'A-1', ['a1']));
        $this->assertBal(35, 1, 0, $x['k']);

        self::refused(403, 'lead_required', fn () => $this->decide($mapper, 'split', $x['b']));
        self::refused(409, 'not_merged', fn () => $this->decide($lead, 'split', $x['a']));
        $s = $this->decide($lead, 'split', $x['b'], ['reason' => 'different pods after all']);
        self::assertSame(['applied', $x['f'], 'mapped'], [$s['state'], $s['sku_id'], $s['status']]);
        self::assertNull(self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$x['f']]), 'the former item lives again');
        // 8 came with B; B sold 3 from K since (2 shipped, 1 still allocated on K): 5 go back. K keeps cover for B's last unit.
        $this->assertBal(30, 1, 0, $x['k']);
        $this->assertBal(5, 0, 0, $x['f']);
        $doc = 'merge:' . $m['decision_id'];
        self::assertSame([['type' => 'split_out', 'sku' => $x['k'], 'qty' => -5, 'doc' => $doc], ['type' => 'split_in', 'sku' => $x['f'], 'qty' => 5, 'doc' => $doc]],
            array_map(static fn (array $r): array => ['type' => $r['type'], 'sku' => $r['sku'], 'qty' => $r['qty'], 'doc' => $r['doc']], array_slice($this->mergeRows(), 2)));
        self::assertSame(['b1', 'b2', 'b3'], self::$db->column('SELECT unit_id FROM reservation_unit WHERE sku_id = ? AND listing_id = ? ORDER BY unit_id', [$x['k'], $x['b']]),
            'the units keep their sale-time item');
        $this->ok($this->ship($x['site'], 'B-1', ['b3']));
        $this->assertBal(29, 0, 0, $x['k']);   // 30 of its own, less A's sale
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.split'"), true);
        self::assertSame([$m['decision_id'], 'former', true, 3, ['MAIN' => 5]],
            [$audit['undoes_decision_id'], $audit['split_to'], $audit['revived'], $audit['stock']['consumed'], $audit['stock']['moved']]);

        // "B is not K": no merge of the two again, and no suggestion either (M22, M34).
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject WHERE listing_id = ? AND sku_id = ?', [$x['b'], $x['k']]));
        self::refused(409, 'rejected_pair', fn () => $this->decide($lead, 'merge_skus', $x['b'], ['sku_id' => $x['k'], 'merge_from_sku_id' => $x['f']]));
        // M40: back to the former item undoes the merge: the other listing it moved (E, another site's page of F) went back with B,
        // its own period and feed row; there is nothing left to split.
        self::assertSame($x['f'], $this->link($e)['sku_id']);
        self::assertSame([$e], $audit['with_listings']);
        self::assertSame((int) $s['decision_id'], (int) self::$db->value('SELECT decision_id FROM listing_map_history WHERE open_listing_id = ?', [$e]));
        self::refused(409, 'not_merged', fn () => $this->decide($lead, 'split', $e));
        $this->assertBal(5, 0, 0, $x['f']);
        self::assertSame(4, count($this->mergeRows()));
    }

    public function testASplitToANewItemAndASplitOfACountedItemNeedingTwoPeople(): void
    {
        $x = $this->corex(30, 8);
        [$lead, $lead2] = [$this->staffUser('mapping_lead'), $this->staffUser('mapping_lead')];
        $this->decide($lead, 'merge_skus', $x['b'], ['sku_id' => $x['k'], 'merge_from_sku_id' => $x['f'], 'proposal_id' => $x['pb']]);

        // Counted since the merge: the split waits for a second mapping lead, then opens recounts on both items.
        $this->book('count', $x['k'], 38, 'MAIN', '2026-09-26T10:00:00Z');
        $p = $this->decide($lead, 'split', $x['b'], ['split_to' => 'new']);
        self::assertSame(['pending_second', ['counted_item'], null], [$p['state'], $p['needs_second'], self::$db->value('SELECT sku_id FROM match_decision WHERE id = ?', [$p['decision_id']])]);
        self::assertSame($x['k'], $this->link($x['b'])['sku_id']);
        $ok = $this->ds->approve($lead2, $p['decision_id']);
        $new = $ok['sku_id'];
        self::assertNotContains($new, [$x['k'], $x['f']], 'a new item minted from the listing');
        self::assertSame('new_item', self::$db->value('SELECT origin FROM sku WHERE id = ?', [$new]));
        self::assertSame($x['k'], self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$x['f']]), 'the former item stays merged');
        $this->assertBal(30, 0, 0, $x['k']);
        $this->assertBal(8, 0, 0, $new);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM count_review WHERE source = 'merge_recount'"));
        self::refused(409, 'not_merged', fn () => $this->decide($lead, 'split', $x['b']));
    }

    /** The kept item's demand counts both listings' sales after the next bin/reorder_demand.php (demand maps listings to items at read time). */
    public function testTheReorderDemandOfTheKeptItemIncludesBothListings(): void
    {
        $x = $this->corex();
        $lead = $this->staffUser('mapping_lead');
        $this->history((int) $x['site']->channelId, '2026-07-04', '2026-10-01', [...self::daily('23408', '2026-07-04', '2026-10-01', 4),
            ...self::daily('25772', '2026-07-04', '2026-10-01', 2)]);
        $b = new DemandBuilder(self::$db);
        $b->rebuild();
        $units = static fn (int $sku): ?int => ($v = self::$db->value('SELECT units_365 FROM reorder_demand WHERE sku_id = ?', [$sku])) === null ? null : (int) $v;
        self::assertSame([90 * 4, 90 * 2], [$units($x['k']), $units($x['f'])]);

        $this->decide($lead, 'merge_skus', $x['b'], ['sku_id' => $x['k'], 'merge_from_sku_id' => $x['f'], 'proposal_id' => $x['pb']]);
        $b->rebuild();
        self::assertSame([90 * 6, null], [$units($x['k']), $units($x['f'])], 'one item, both pages; the merged item is not on the list');
        $detail = json_decode((string) self::$db->value('SELECT CAST(detail AS CHAR) FROM reorder_demand WHERE sku_id = ?', [$x['k']]), true);
        self::assertEqualsCanonicalizing(['23408', '25772'], array_column($detail['listings'], 'variant'));

        // A split gives the listing's demand back to its own item.
        $this->decide($this->staffUser('mapping_lead'), 'split', $x['b']);
        $b->rebuild();
        self::assertSame([90 * 4, 90 * 2], [$units($x['k']), $units($x['f'])]);
    }

    /**
     * M39 (review of 7 Oct 2026): the counted test covers the merge family. A counted, merged into B by two people; then B merged
     * into C needs two people too (B's figure holds A's counted stock, and B carries an open recount), and the recount B waited
     * for is opened again on C, where the stock went.
     */
    public function testACountedItemMergedEarlierKeepsLaterMergesTwoPerson(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        [$lead, $lead2] = [$this->staffUser('mapping_lead'), $this->staffUser('mapping_lead')];
        [$a, $b, $c] = [$this->item('legacy', 5), $this->item('legacy', 10), $this->item('legacy', 20)];
        $la = $this->listing($site, 'A1', $a);
        $lb = $this->listing($site, 'B1', $b);
        $this->listing($site, 'C1', $c);
        $this->book('count', $a, 5, 'MAIN', '2026-09-26T10:00:00Z');
        $m1 = $this->decide($lead, 'merge_skus', $la, ['sku_id' => $b, 'merge_from_sku_id' => $a]);
        self::assertSame(['pending_second', ['counted_item']], [$m1['state'], $m1['needs_second']]);
        $this->ds->approve($lead2, $m1['decision_id']);
        $this->assertBal(15, 0, 0, $b);
        self::assertSame([$b], $this->ds->counted([$b, $c]));
        self::$db->exec("UPDATE count_review SET status = 'resolved', resolved_at = UTC_TIMESTAMP(6) WHERE source = 'merge_recount'");
        self::assertSame([$b], $this->ds->counted([$b, $c]), 'still: A, merged into B, was counted');
        self::$db->exec("UPDATE count_review SET status = 'open', resolved_at = NULL WHERE source = 'merge_recount'");

        $m2 = $this->decide($lead, 'merge_skus', $lb, ['sku_id' => $c, 'merge_from_sku_id' => $b]);
        self::assertSame(['pending_second', ['counted_item']], [$m2['state'], $m2['needs_second']], 'one person cannot move counted stock again');
        $this->assertBal(15, 0, 0, $b);
        $this->ds->approve($lead2, $m2['decision_id']);
        $this->assertBal(35, 0, 0, $c);
        self::assertSame([$c], array_map('intval', self::$db->column("SELECT sku_id FROM count_review WHERE source = 'merge_recount' AND status = 'open' "
            . 'AND JSON_EXTRACT(detail, \'$.decision_id\') = ? GROUP BY sku_id', [$m2['decision_id']])), 'the recount follows the stock to C');
        self::assertSame([$c], $this->ds->counted([$c]));
    }

    /**
     * M40: a chain (X merged into K, then K into Z). Back to the former item undoes the LAST merge whole: K comes back with both
     * its pages (its own and X's, which the merge moved) and the stock that came with it. X's page, which came onto Z through two
     * merges, cannot go back by itself (which merge was wrong?): it goes to a new item and no stock moves.
     */
    public function testASplitInAMergeChain(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        $lead = $this->staffUser('mapping_lead');
        [$xi, $k, $z] = [$this->item('legacy', 4), $this->item('legacy', 10), $this->item('legacy', 20)];
        $lx = $this->listing($site, 'X1', $xi);
        $lk = $this->listing($site, 'K1', $k);
        $lz = $this->listing($site, 'Z1', $z);
        $this->decide($lead, 'merge_skus', $lx, ['sku_id' => $k, 'merge_from_sku_id' => $xi]);
        $d2 = $this->decide($lead, 'merge_skus', $lk, ['sku_id' => $z, 'merge_from_sku_id' => $k]);
        $this->assertBal(34, 0, 0, $z);
        self::assertEquals(['chain' => true, 'former_ok' => false, 'new_exact' => false, 'new_units' => 0],
            array_intersect_key($this->ds->splitPreview($lx), array_flip(['chain', 'former_ok', 'new_exact', 'new_units'])));
        self::refused(409, 'split_chain', fn () => $this->decide($lead, 'split', $lx));
        $p = $this->ds->splitPreview($lk);
        self::assertSame([false, true, [$lx], 14], [$p['chain'], $p['former_ok'], $p['with'], $p['former_units']]);

        $s = $this->decide($lead, 'split', $lk);
        self::assertSame([$k, $k, $z], [$this->link($lk)['sku_id'], $this->link($lx)['sku_id'], $this->link($lz)['sku_id']]);
        self::assertNull(self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$k]));
        self::assertSame($k, self::$db->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$xi]), 'X stays merged into K');
        $this->assertBal(14, 0, 0, $k);
        $this->assertBal(20, 0, 0, $z);
        self::assertSame((int) $d2['decision_id'], (int) json_decode((string) self::$db->value("SELECT detail FROM match_decision WHERE id = ?", [$s['decision_id']]), true)['undoes_decision_id']);

        // A merge that moved two pages (W's own and another site's), split to a new item: that page alone, and no stock moves
        // (its share of W's stock cannot be told apart).
        $w = $this->item('legacy', 6);
        $lw1 = $this->listing($site, 'W1', $w);
        $lw2 = $this->listing($this->site('electrofag', 'off'), 'W2', $w);
        $this->decide($lead, 'merge_skus', $lw1, ['sku_id' => $z, 'merge_from_sku_id' => $w]);
        $this->assertBal(26, 0, 0, $z);
        self::assertEquals(['new_exact' => false, 'new_units' => 0, 'former_units' => 6, 'with' => [$lw2]],
            array_intersect_key($this->ds->splitPreview($lw1), array_flip(['new_exact', 'new_units', 'former_units', 'with'])));
        $n = $this->decide($lead, 'split', $lw1, ['split_to' => 'new', 'card' => ['name' => 'W1 page']]);
        self::assertSame('applied', $n['state']);
        $this->assertBal(0, 0, 0, $n['sku_id']);
        $this->assertBal(26, 0, 0, $z);
        self::assertSame($z, $this->link($lw2)['sku_id'], 'the other page stays');
        self::assertTrue(json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.split' ORDER BY id DESC LIMIT 1"), true)['stock']['not_exact']);
    }

    /** M39: a merge into an item that has a pack listing (units per item other than 1) needs two people, as one moving a pack listing does. */
    public function testAMergeIntoAnItemWithAPackListingNeedsTwoPeople(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        $lead = $this->staffUser('mapping_lead');
        [$k, $a] = [$this->item('legacy', 8), $this->item('legacy', 2)];
        $this->listing($site, 'K1', $k);
        $this->listing($site, 'K4', $k, 4);
        $la = $this->listing($site, 'A4PACK', $a);
        $r = $this->decide($lead, 'merge_skus', $la, ['sku_id' => $k, 'merge_from_sku_id' => $a]);
        self::assertSame(['pending_second', ['units_per_item']], [$r['state'], $r['needs_second']]);
    }

    /**
     * M41 (review of 7 Oct 2026): a group of three whose run keeper K is not kept. "Different products" for one page and the
     * merge of K into the kept item, in either order: every suggestion the answers leave nothing to decide for is settled at the
     * end of the group decision, so the group closes.
     */
    public function testAChangedKeeperGroupClosesWhateverTheOrderOfItsDecisions(): void
    {
        $site = $this->site('vapeandgo', 'shadow');
        $lead = $this->staffUser('mapping_lead');
        [$k, $a, $b] = [$this->item('legacy', 3), $this->item('legacy', 9), $this->item('legacy', 1)];
        [$lk, $la, $lb] = [$this->listing($site, '300', $k), $this->listing($site, '301', $a), $this->listing($site, '302', $b)];
        $members = [['300', $k], ['301', $a], ['302', $b]];
        $pa = $this->suggest($la, $k, 11, $members);
        $pb = $this->suggest($lb, $k, 11, $members);
        // Keep A: B is a different product (rejects A), K is the same (folds into A). The reject comes first (listing id order).
        $out = $this->ds->decideGroup($lead, [$lk, $la, $lb], [
            ['action' => 'reject', 'listing_id' => $lb, 'expected_map_version' => $this->version($lb), 'sku_id' => $a, 'proposal_id' => $pb],
            ['action' => 'merge_skus', 'listing_id' => $lk, 'expected_map_version' => $this->version($lk), 'sku_id' => $a, 'merge_from_sku_id' => $k],
        ], 'g11', [$la => $this->version($la)], [$pa, $pb]);
        self::assertSame(['applied', 'applied'], array_column($out, 'state'));
        self::assertSame(['decided', 'decided'], [self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pa]),
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pb])], 'B said "not A", and K is A now: nothing left to decide');
        self::assertEqualsCanonicalizing([$pa, $pb], json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.duplicates'"), true)['settled_proposals']);
        self::assertSame($b, $this->link($lb)['sku_id']);
    }
}
