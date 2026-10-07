<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Catalogue;

use CW\Caller;
use CW\Catalogue\BarcodeReviews;
use CW\Catalogue\BarcodeSync;
use CW\Catalogue\ItemBarcodes;
use CW\Invariants;
use CW\Mapping\BarcodeSeeder;
use CW\Matching\Gtin;

/**
 * The barcode sync and its review (IM3; docs/decisions.md I106-I108, I116-I118): the barcodes of LINKED listings into sku_barcode,
 * a dry run by default that reports what a real run does, idempotent; a barcode already on another item goes to review and becomes
 * unusable, never moved; a multipack listing's new barcode goes to review; the decisions (keep, move, unusable, add, dismiss) and
 * their stale cases; a decided or removed pair is never offered again, a move is not taken back by the old holder's own listing,
 * a barcode ruled shared stays unusable; junk and restricted codes; merged holders and unlinked sources counted; a removal
 * committed during a sync chunk honoured; the seeder honours decisions; a person's barcodes (add with units per scan, remove,
 * change units); the CLI.
 */
final class BarcodeSyncTest extends CatalogueTestCase
{
    private const K1 = '5012345678900';
    private const K2 = '36000291452';      // UPC-A 036000291452 as a key
    private const K3 = '4006381333931';

    private function sync(bool $apply): array
    {
        return (new BarcodeSync(self::$db))->run(Caller::system('sync_barcodes'), $apply);
    }

    /** @return array<string, int> the counts that say what happened */
    private static function counts(array $r): array
    {
        return array_intersect_key($r, array_flip(['listings', 'codes', 'unusable_codes', 'junk_codes', 'restricted_codes', 'already', 'skipped_decided',
            'in_review', 'added', 'review_on_another_item', 'review_holder_merged', 'review_multipack_listing', 'made_unusable']));
    }

    /** @return array{a: int, b: int, c: int, p1: int, a1: int, a2: int} */
    private function scenario(): array
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        $a = $this->item('legacy', 0, 'Item A');
        $b = $this->item('legacy', 0, 'Item B');
        $c = $this->item('legacy', 0, 'Item C 10-pack');
        $p1 = $this->linked($vpg, 'P1', $a, [self::K1, '036000291452', 'Black Grey', '1746']); // text is not a code at all; 1746 is a shop code (unusable)
        $a1 = $this->linked($alt, 'A1', $b, [self::K1]);
        $a2 = $this->linked($alt, 'A2', $c, [self::K3], 10);
        $this->linked($alt, 'A3', $a, ['0036000291452']);
        $this->linked($alt, 'U1', null, ['96385074']);
        return ['a' => $a, 'b' => $b, 'c' => $c, 'p1' => $p1, 'a1' => $a1, 'a2' => $a2];
    }

    public function testDryRunThenApplyThenIdempotent(): void
    {
        $s = $this->scenario();
        $want = ['listings' => 4, 'codes' => 5, 'unusable_codes' => 1, 'junk_codes' => 0, 'restricted_codes' => 0, 'already' => 1, 'skipped_decided' => 0,
            'in_review' => 0, 'added' => 2, 'review_on_another_item' => 1, 'review_holder_merged' => 0, 'review_multipack_listing' => 1, 'made_unusable' => 1];
        $dry = $this->sync(false);
        self::assertSame($want, self::counts($dry), 'the dry run counts what the real run does (the second claim of K1 within the run included)');
        self::assertSame('dry_run', $dry['mode']);
        self::assertSame([0, 0, 0], [(int) self::$db->value('SELECT COUNT(*) FROM sku_barcode'), (int) self::$db->value('SELECT COUNT(*) FROM barcode_review'),
            (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'sku_barcode.sync'")], 'a dry run writes nothing');

        self::assertSame($want, self::counts($this->sync(true)));
        $k1 = $this->barcode(self::K1);
        self::assertSame([$s['a'], 0, 1, 'listing_sync'], [(int) $k1['sku_id'], (int) $k1['is_usable'], (int) $k1['units_per_scan'], $k1['source']]);
        self::assertStringStartsWith(sprintf('also on CW-%06d (listing %d): in barcode review', $s['b'], $s['a1']), (string) $k1['note']);
        self::assertSame([$s['a'], 1], [(int) $this->barcode(self::K2)['sku_id'], (int) $this->barcode(self::K2)['is_usable']], 'leading zeros: one key');
        self::assertSame([], $this->barcode(self::K3), 'a multipack listing\'s barcode waits for a person');
        self::assertSame([], $this->barcode('96385074'), 'an unlinked listing says nothing about an item');
        self::assertSame([
            [self::K1, 'on_another_item', $s['b'], $s['a'], $s['a1'], 1, 'open', 'system:sync_barcodes'],
            [self::K3, 'multipack_listing', $s['c'], null, $s['a2'], 10, 'open', 'system:sync_barcodes'],
        ], array_map(static fn (array $r): array => [(string) $r['barcode'], $r['reason'], (int) $r['claimant_sku_id'], $r['holder_sku_id'] === null ? null : (int) $r['holder_sku_id'],
            (int) $r['listing_id'], (int) $r['units_per_item'], $r['status'], $r['opened_actor']], self::$db->all('SELECT * FROM barcode_review ORDER BY id')));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'sku_barcode.sync'"), true);
        self::assertSame([2, 'apply'], [$audit['added'], $audit['mode']]);

        $again = $this->sync(true);
        self::assertSame(['listings' => 4, 'codes' => 5, 'unusable_codes' => 1, 'junk_codes' => 0, 'restricted_codes' => 0, 'already' => 3, 'skipped_decided' => 0,
            'in_review' => 2, 'added' => 0, 'review_on_another_item' => 0, 'review_holder_merged' => 0, 'review_multipack_listing' => 0, 'made_unusable' => 0],
            self::counts($again), 'idempotent');
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM barcode_review'));
        self::assertSame(2, (new BarcodeReviews(self::$db))->openCount());
    }

    public function testDecisionsAndWhatTheSyncDoesAfterThem(): void
    {
        $s = $this->scenario();
        $this->sync(true);
        $who = $this->editor();
        $reviews = new BarcodeReviews(self::$db);
        [$conflict, $multi] = array_map('intval', self::$db->column('SELECT id FROM barcode_review ORDER BY id'));

        self::refused(400, 'bad_decision', fn () => $reviews->decide($who, $conflict, 'add'));
        self::refused(403, 'role_not_allowed', fn () => $reviews->decide($this->staffUser('buyer'), $conflict, 'keep_holder'));
        $k = $reviews->decide($who, $conflict, 'keep_holder', null, 'the alt listing has a typo');
        self::assertSame([self::K1, 'keep_holder', true], [$k['barcode'], $k['decision'], $k['usable']]);
        self::assertSame([$s['a'], 1], [(int) $this->barcode(self::K1)['sku_id'], (int) $this->barcode(self::K1)['is_usable']], 'usable again on its item');
        self::refused(409, 'review_closed', fn () => $reviews->decide($who, $conflict, 'keep_holder'));
        $m = $reviews->decide($who, $multi, 'add');
        self::assertSame([10, true], [$m['units'], $m['usable']], 'the listing\'s units per item, unless the person says otherwise');
        self::assertSame([$s['c'], 10, 'review'], [(int) $this->barcode(self::K3)['sku_id'], (int) $this->barcode(self::K3)['units_per_scan'], $this->barcode(self::K3)['source']]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'barcode_review.decide'"));

        // A person removes K2 from A: the sync never adds it back from A's two listings; adding it by hand is allowed.
        $barcodes = new ItemBarcodes(self::$db);
        $r = $barcodes->remove($who, $s['a'], self::K2, 'printed wrongly on the site');
        self::assertSame([], $this->barcode(self::K2));
        self::assertSame(['removed', 'decided', 'removed', 'printed wrongly on the site'], array_values((array) self::$db->one(
            'SELECT reason, status, decision, note FROM barcode_review WHERE id = ?', [$r['review_id']])));
        self::refused(409, 'barcode_gone', fn () => $barcodes->remove($who, $s['a'], self::K2));
        $after = self::counts($this->sync(true));
        self::assertSame([0, 3, 2, 0], [$after['added'], $after['skipped_decided'], $after['already'], $after['in_review']],
            'K1 claimed by B: decided; K2 on both of A\'s listings: removed; K1 on A, K3 on C: already');
        $barcodes->add($who, $s['a'], '036000291452');
        self::assertSame([$s['a'], 'manual'], [(int) $this->barcode(self::K2)['sku_id'], $this->barcode(self::K2)['source']]);
        self::assertSame(0, self::counts($this->sync(true))['added']);
    }

    public function testMoveUnusableAndStaleDecisions(): void
    {
        $vpg = $this->site('vpg');
        [$x, $y, $z] = [$this->item('legacy', 0, 'X'), $this->item('legacy', 0, 'Y'), $this->item('legacy', 0, 'Z')];
        $who = $this->editor();
        (new ItemBarcodes(self::$db))->add($who, $x, self::K1);
        $this->linked($vpg, 'Y1', $y, [self::K1]);
        $this->linked($vpg, 'Z1', $z, [self::K1]);
        $r = self::counts($this->sync(true));
        self::assertSame([2, 1], [$r['review_on_another_item'], $r['made_unusable']], 'two claims, one barcode: made unusable once');
        [$ry, $rz] = array_map('intval', self::$db->column('SELECT id FROM barcode_review ORDER BY claimant_sku_id'));
        $reviews = new BarcodeReviews(self::$db);
        $m = $reviews->decide($who, $ry, 'move', 6);
        self::assertSame([6, false], [$m['units'], $m['usable']], 'moved, but unusable while the other review is open');
        self::assertSame([$y, 0, 6], [(int) $this->barcode(self::K1)['sku_id'], (int) $this->barcode(self::K1)['is_usable'], (int) $this->barcode(self::K1)['units_per_scan']]);
        self::assertStringContainsString('(another review is open)', (string) $this->barcode(self::K1)['note']);
        $e = self::refused(409, 'review_stale', fn () => $reviews->decide($who, $rz, 'keep_holder'));
        self::assertStringContainsString(sprintf('it is on CW-%06d now', $y), $e->getMessage());
        self::assertSame(false, $reviews->decide($who, $rz, 'unusable')['usable']);
        self::assertStringStartsWith(sprintf('shared by CW-%06d and CW-%06d', $z, $y), (string) $this->barcode(self::K1)['note']);
        self::assertSame(0, $reviews->openCount());
        $again = self::counts($this->sync(true));
        self::assertSame([1, 1, 0, 0], [$again['already'], $again['skipped_decided'], $again['review_on_another_item'], $again['in_review']]);
        self::assertSame([], Invariants::check(self::$db));
    }

    /** The review probe (I116): the old holder's own listing still carries the barcode after a move; the sync must not take it back. */
    public function testAMoveIsNotUndoneByTheOldHoldersListing(): void
    {
        $vpg = $this->site('vpg');
        $alt = $this->site('alt');
        [$h, $sx] = [$this->item('legacy', 0, 'Holder'), $this->item('legacy', 0, 'Claimant')];
        $this->linked($vpg, 'PH', $h, [self::K1]);
        self::assertSame(1, $this->sync(true)['added']);
        $this->linked($alt, 'PS', $sx, [self::K1]);
        self::assertSame(1, $this->sync(true)['review_on_another_item']);
        $id = (int) self::$db->value("SELECT id FROM barcode_review WHERE status = 'open'");
        $m = (new BarcodeReviews(self::$db))->decide($this->editor(), $id, 'move');
        self::assertSame([1, true], [$m['units'], $m['usable']]);
        self::assertSame(['on_another_item', 'decided', 'moved_away', $sx], array_values(array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, (array) self::$db->one(
            'SELECT reason, status, decision, holder_sku_id FROM barcode_review WHERE barcode = ? AND claimant_sku_id = ?', [self::K1, $h]))),
            'the item the barcode left is recorded as decided');
        foreach ([1, 2] as $run) {
            $c = self::counts($this->sync(true));
            self::assertSame([1, 1, 0, 0], [$c['already'], $c['skipped_decided'], $c['review_on_another_item'], $c['made_unusable']], "run {$run}");
        }
        self::assertSame([$sx, 1], [(int) $this->barcode(self::K1)['sku_id'], (int) $this->barcode(self::K1)['is_usable']]);
        self::assertSame(0, (new BarcodeReviews(self::$db))->openCount());
        // Both reviews of a three-way clash open at once; a move closes the holder's own claim with it.
        $t = $this->item('legacy', 0, 'Third');
        (new ItemBarcodes(self::$db))->add($this->editor(), $t, self::K3);
        $this->linked($vpg, 'PT', $h, [self::K3]);
        self::$db->exec("INSERT INTO barcode_review (barcode, reason, claimant_sku_id, holder_sku_id, opened_actor) VALUES (?, 'on_another_item', ?, ?, 'system:test')",
            [self::K3, $t, $h]); // a claim of the holder T itself, opened while the barcode was elsewhere
        $this->sync(true);
        $ids = array_map('intval', self::$db->column("SELECT id FROM barcode_review WHERE status = 'open' AND barcode = ? ORDER BY id", [self::K3]));
        self::assertCount(2, $ids);
        (new BarcodeReviews(self::$db))->decide($this->editor(), $ids[1], 'move');
        self::assertSame(0, (new BarcodeReviews(self::$db))->openCount(), 'the holder\'s own open claim was answered by the move');
        self::assertSame([$h, 1], [(int) $this->barcode(self::K3)['sku_id'], (int) $this->barcode(self::K3)['is_usable']]);
        self::assertSame([], Invariants::check(self::$db));
    }

    /** The review probe (I117): a barcode ruled shared stays unusable when another claimant's review is decided later. */
    public function testASharedRulingIsNotUndoneByALaterKeep(): void
    {
        $vpg = $this->site('vpg');
        [$h, $t, $sx] = [$this->item('legacy', 0, 'Holder'), $this->item('legacy', 0, 'T'), $this->item('legacy', 0, 'S')];
        $who = $this->editor();
        (new ItemBarcodes(self::$db))->add($who, $h, self::K1);
        $this->linked($vpg, 'T1', $t, [self::K1]);
        $this->linked($vpg, 'S1', $sx, [self::K1]);
        self::assertSame(2, $this->sync(true)['review_on_another_item'], 'one sync opens both: a code shared by three items');
        $byClaimant = array_column(self::$db->all("SELECT id, claimant_sku_id FROM barcode_review WHERE status = 'open'"), 'id', 'claimant_sku_id');
        $reviews = new BarcodeReviews(self::$db);
        self::assertFalse($reviews->decide($who, (int) $byClaimant[$t], 'unusable')['usable']);
        $k = $reviews->decide($who, (int) $byClaimant[$sx], 'keep_holder');
        self::assertFalse($k['usable'], 'a person ruled it shared: a later keep does not undo that');
        self::assertSame(0, (int) $this->barcode(self::K1)['is_usable']);
        self::assertStringContainsString("kept unusable: barcode review {$byClaimant[$t]} ruled it shared", (string) $this->barcode(self::K1)['note']);
        // Removed and added again by hand: a fresh start.
        (new ItemBarcodes(self::$db))->remove($who, $h, self::K1);
        self::assertTrue((new ItemBarcodes(self::$db))->add($who, $h, self::K1)['usable']);
        self::assertSame([], Invariants::check(self::$db));
    }

    public function testDismissingTheLastReviewFreesARowAddedMeanwhile(): void
    {
        $vpg = $this->site('vpg');
        $pack = $this->item('legacy', 0, '10-pack');
        $single = $this->item('legacy', 0, 'Single');
        $this->linked($vpg, 'M1', $pack, [self::K3], 10);
        self::assertSame(1, $this->sync(true)['review_multipack_listing']);
        self::assertFalse((new ItemBarcodes(self::$db))->add($this->editor(), $single, self::K3)['usable'], 'in review: added unusable');
        $id = (int) self::$db->value("SELECT id FROM barcode_review WHERE status = 'open'");
        $d = (new BarcodeReviews(self::$db))->decide($this->editor(), $id, 'dismiss');
        self::assertTrue($d['usable']);
        self::assertSame([$single, 1], [(int) $this->barcode(self::K3)['sku_id'], (int) $this->barcode(self::K3)['is_usable']]);
    }

    /** Stricter than the matcher (I118): letters in a code, restricted-circulation numbers, a decimal number. */
    public function testJunkAndRestrictedCodesAreSkipped(): void
    {
        $vpg = $this->site('vpg');
        $sku = $this->item('legacy', 0, 'Coded');
        $restricted13 = '200123456789' . Gtin::checkDigit('200123456789');
        $restrictedUpc = '412345678901';
        $restrictedUpc = substr($restrictedUpc, 0, 11) . Gtin::checkDigit(substr($restrictedUpc, 0, 11));
        $restricted8 = '2123456' . Gtin::checkDigit('2123456');
        self::assertTrue(Gtin::classify('SKU-12345670')['usable'], 'the matcher reads the digits of a shop code as a GTIN-8');
        $this->linked($vpg, 'J1', $sku, ['SKU-12345670', $restricted13, $restrictedUpc, $restricted8, '5012-3456-7890-0', 'Black Grey']);
        $c = self::counts($this->sync(true));
        self::assertSame([1, 4, 1, 3, 1], [$c['codes'], $c['unusable_codes'], $c['junk_codes'], $c['restricted_codes'], $c['added']]);
        self::assertSame(['usable' => [], 'unusable' => 1, 'junk' => 1, 'restricted' => 0], BarcodeSync::keys([5012345678900.5]), 'a decimal number is not a barcode');
        self::assertSame([self::K1], self::$db->column('SELECT barcode FROM sku_barcode'), 'only the real GTIN, hyphens allowed');
        self::assertTrue(BarcodeSync::restricted('2123456' . Gtin::checkDigit('2123456')));
        self::assertFalse(BarcodeSync::restricted('96385074'));
        self::assertFalse(BarcodeSync::restricted(self::K3), '400... is Germany, not 040');
        self::assertTrue(BarcodeSync::restricted(ltrim('0412345678903', '0')), 'UPC-A 4... = GTIN-13 041...');
    }

    /** Counted, never changed (I118): barcodes from a listing linked elsewhere since, and reviews whose holder was merged into the claimant. */
    public function testUnlinkedSourcesAndMergedHoldersAreCounted(): void
    {
        $vpg = $this->site('vpg');
        [$a, $m, $kept] = [$this->item('legacy', 0, 'A'), $this->item('legacy', 0, 'M'), $this->item('legacy', 0, 'Kept')];
        $la = $this->linked($vpg, 'LA', $a, [self::K1]);
        $lm = $this->linked($vpg, 'LM', $m, [self::K3]);
        $this->sync(true);
        $this->relink($la, null, 'unmapped');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$kept, $m]);
        $this->relink($lm, $kept);
        $dry = $this->sync(false);
        self::assertSame([2, 1, 1], [$dry['source_unlinked'], $dry['review_on_another_item'], $dry['review_holder_merged']]);
        $u = (new BarcodeSync(self::$db))->unlinkedSources(10);
        self::assertSame([[self::K3, sprintf('CW-%06d', $m), $lm, 'mapped', sprintf('CW-%06d', $kept)], [self::K1, sprintf('CW-%06d', $a), $la, 'unmapped', null]],
            array_map(static fn (array $r): array => array_values($r), $u['rows']));
        $c = $this->sync(true);
        self::assertSame([2, 1, 1], [$c['source_unlinked'], $c['review_on_another_item'], $c['review_holder_merged']], 'the dry run said so (as found before the run)');
        self::assertSame([$a, 1], [(int) $this->barcode(self::K1)['sku_id'], (int) $this->barcode(self::K1)['is_usable']], 'never removed by the sync');
        self::assertSame([self::K1], array_column((new BarcodeSync(self::$db))->unlinkedSources(10)['rows'], 'barcode'), 'the merged one is in the review queue now');
        $row = (new BarcodeReviews(self::$db))->rows()[0];
        self::assertTrue($row['holder_merged_into_claimant'], 'the queue suggests the move');
        $cli = self::tool('sync_barcodes');
        self::assertStringContainsString('source_unlinked=1', $cli['out']);
        self::assertStringContainsString(sprintf('%s on CW-%06d from listing %d (now unmapped)', self::K1, $a, $la), $cli['out']);
    }

    /** A person's removal committed after a sync chunk read its state is honoured under the barcode's lock (I118). */
    public function testARemovalDuringASyncChunkIsHonoured(): void
    {
        $vpg = $this->site('vpg');
        $sku = $this->item('legacy', 0, 'Item');
        $listing = $this->linked($vpg, 'R1', $sku, [self::K1]);
        $who = $this->editor();
        (new ItemBarcodes(self::$db))->add($who, $sku, self::K1);
        (new ItemBarcodes(self::$db))->remove($who, $sku, self::K1, 'wrong');
        // The chunk's state as read before the removal committed: no row, no decision.
        $stale = ['rows' => [], 'decided' => [], 'open' => []];
        $write = new \ReflectionMethod(BarcodeSync::class, 'writeLocked');
        $sync = new BarcodeSync(self::$db);
        $r = self::$db->transaction(fn () => $write->invokeArgs($sync, [self::$db, Caller::system('sync_barcodes'),
            ['listing' => $listing, 'sku' => $sku, 'u' => 1, 'key' => self::K1], &$stale]));
        self::assertSame(['skipped_decided'], $r['count']);
        self::assertSame([], $this->barcode(self::K1), 'not added back');
    }

    public function testTheSeederHonoursDecisions(): void
    {
        $vpg = $this->site('vpg');
        $seeded = $this->item('legacy', 0, 'Seeded');
        $l = $this->linked($vpg, 'S1', $seeded, [self::K1]);
        self::$db->exec('UPDATE sku SET origin_listing_id = ? WHERE id = ?', [$l, $seeded]);
        $seeder = new BarcodeSeeder(self::$db);
        self::assertSame(1, $seeder->seed(Caller::system('test'), [$seeded])['added']);
        (new ItemBarcodes(self::$db))->remove($this->editor(), $seeded, self::K1, 'printed wrongly');
        $again = $seeder->seed(Caller::system('test'), [$seeded]);
        self::assertSame([0, 1], [$again['added'], $again['skipped_decided']]);
        self::assertSame([], $this->barcode(self::K1));
    }

    public function testAPersonsBarcodesAndOuterCases(): void
    {
        $who = $this->editor('purchasing_manager');
        $sku = $this->item('legacy', 0, 'Elux 10ml');
        $other = $this->item('legacy', 0, 'Other');
        $b = new ItemBarcodes(self::$db);
        self::assertSame(['barcode' => self::K3, 'usable' => true, 'units' => 1], $b->add($who, $sku, '4006 3813 3393 1'));
        self::assertSame(['barcode' => '10012345678902', 'usable' => true, 'units' => 10], $b->add($who, $sku, '10012345678902', 10), 'an outer case: 10 units a scan');
        self::refused(409, 'barcode_exists', fn () => $b->add($who, $sku, self::K3));
        $e = self::refused(409, 'barcode_on_other_item', fn () => $b->add($who, $other, '04006381333931'));
        self::assertSame($sku, $e->detail['sku_id']);
        self::refused(422, 'bad_barcode', fn () => $b->add($who, $sku, '4006381333932'));
        self::refused(422, 'bad_units', fn () => $b->add($who, $sku, '96385074', 0));
        self::refused(422, 'bad_units', fn () => $b->add($who, $sku, '96385074', 10_001));
        self::refused(403, 'role_not_allowed', fn () => $b->add($this->staffUser('reviewer'), $sku, '96385074'));
        self::refused(403, 'admin_cannot_edit', fn () => $b->add($this->staffUser('admin'), $sku, '96385074'));
        self::assertSame('saved', $b->setUnits($who, $sku, '10012345678902', 10, 12)['result']);
        self::assertSame('unchanged', $b->setUnits($who, $sku, '10012345678902', 12, 12)['result']);
        self::refused(409, 'barcode_changed', fn () => $b->setUnits($who, $sku, '10012345678902', 10, 6));
        self::refused(409, 'barcode_gone', fn () => $b->setUnits($who, $other, '10012345678902', 12, 6));
        self::assertSame(12, (int) $this->barcode('10012345678902')['units_per_scan']);
        self::assertSame(['sku_barcode.add', 'sku_barcode.add', 'sku_barcode.units'], self::$db->column(
            "SELECT action FROM audit_log WHERE action LIKE 'sku_barcode.%' ORDER BY id"));
        // A barcode under review goes in unusable, by hand or by the seeder.
        $vpg = $this->site('vpg');
        $this->linked($vpg, 'M1', $other, ['96385074'], 6);
        $this->sync(true);
        self::assertSame(['barcode' => '96385074', 'usable' => false, 'units' => 1], $b->add($who, $sku, '96385074'));
        self::assertSame([], Invariants::check(self::$db), 'IC3: usable while in review would be a violation');
        $seeded = $this->item('legacy', 0, 'Seeded');
        $l = $this->linked($vpg, 'S1', $seeded, ['5060999888770']);
        self::$db->exec('UPDATE sku SET origin_listing_id = ? WHERE id = ?', [$l, $seeded]);
        self::$db->exec("INSERT INTO barcode_review (barcode, reason, claimant_sku_id, listing_id, units_per_item, opened_actor) VALUES ('5060999888770', 'multipack_listing', ?, ?, 2, 'system:test')",
            [$other, $l]);
        (new BarcodeSeeder(self::$db))->seed(Caller::system('test'), [$seeded]);
        self::assertSame(0, (int) $this->barcode('5060999888770')['is_usable']);
    }

    public function testTheCli(): void
    {
        $this->scenario();
        $dry = self::tool('sync_barcodes');
        self::assertSame(0, $dry['code'], $dry['err']);
        self::assertStringContainsString('DRY RUN (nothing written) listings=4 codes=5 unusable_codes=1 (junk=0 restricted=0) already=1 skipped_decided=0 in_review=0 '
            . 'would_add=2 review_on_another_item=1 (holder_merged=0) review_multipack_listing=1 made_unusable=1', $dry['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM sku_barcode'));
        $apply = self::tool('sync_barcodes', '--apply');
        self::assertSame(0, $apply['code'], $apply['err']);
        self::assertStringContainsString('listings=4 codes=5 unusable_codes=1 (junk=0 restricted=0) already=1 skipped_decided=0 in_review=0 added=2', $apply['out']);
        self::assertStringContainsString('source_unlinked=0', $apply['out']);
        self::assertStringContainsString('open_reviews_now=2', $apply['out']);
        self::assertStringContainsString('added=0', self::tool('sync_barcodes', '--apply', '--channel=alt')['out']);
        self::assertSame(2, self::tool('sync_barcodes', '--channel=nope')['code']);
        self::assertStringContainsString('listings=1 ', self::tool('sync_barcodes', '--limit=1')['out']);
    }
}
