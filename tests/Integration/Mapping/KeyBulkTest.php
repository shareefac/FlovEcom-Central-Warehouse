<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\KeyBulk;
use CW\Mapping\KeyEligibility;
use CW\Mapping\KeySample;
use CW\Mapping\ProposalBasis;
use CW\Mapping\Reband;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\MappingTestCase;

/**
 * The owner's decisions of 2 Oct 2026 (docs/decisions.md M26-M28): Key from confidence 85 and the re-banding of the open
 * proposals; what a proposal was made against (its basis, the lane target's link included) and the proof for older ones;
 * the stratified spot-check sample of at least 20 with a seed the server draws, which no second sample can re-draw; the
 * bulk confirm that only the sample's owner runs, that refuses unless every member is confirmed by that owner and the
 * sample is fit, and then links only what still qualifies, one DecisionService decision each; and its undo.
 */
final class KeyBulkTest extends MappingTestCase
{
    use KeyFixtures;

    private function reband(): Reband
    {
        return new Reband(self::$db, $this->proposals);
    }

    private function bulk(): KeyBulk
    {
        return new KeyBulk(self::$db, $this->ds, $this->proposals);
    }

    private function samples(): KeySample
    {
        return new KeySample(self::$db);
    }

    private function rows(string $sql, array $args = []): int
    {
        return (int) self::$db->value($sql, $args);
    }

    // ------------------------------------------------------------------------------------------
    // M26: re-banding
    // ------------------------------------------------------------------------------------------

    public function testRebandMovesTheKeyLane85To89FromCheckToKeyAndNothingElse(): void
    {
        $this->keySetup();
        $mapper = $this->staffUser('mapper');
        $owner = $this->staffUser(['mapping_lead', 'reviewer']);
        $move = $this->firstMatch($this->vpgItem(), 87);
        $move85 = $this->firstMatch($this->vpgItem(), 85);
        $candidates = $this->firstMatch($this->vpgItem(), 88, ['lane' => 'candidates', 'lane_target' => null, 'key_possible' => false, 'key_blocked_by' => ['candidates_lane']]);
        $soft = $this->firstMatch($this->vpgItem(), 88, ['target_soft_flags' => ['price_outlier']]);
        $quote = $this->firstMatch($this->vpgItem(), 89, [], ['warnings' => ['quote_not_verbatim: flavour']]);
        $key = $this->firstMatch($this->vpgItem(), 95);
        $conflict = $this->firstMatch($this->vpgItem(), 99, ['target_vetoes' => ['strength']], [], 'Conflict');
        $mismatch = $this->firstMatch($this->vpgItem(), 86, [], [], 'Key');
        $manual = $this->firstMatch($this->vpgItem(), 88, ['relabel_pending' => 'Crystal Pro Max = Hayati Pro Max'], [], 'Manual');
        $renamed = $this->firstMatch($this->vpgItem(), 88);
        $this->rename($renamed['listing'], 'The site renamed it');
        $rejected = $this->firstMatch($this->vpgItem(), 86);
        $this->decide($mapper, 'reject', $rejected['listing'], ['sku_id' => $rejected['sku'], 'proposal_id' => $rejected['proposal']]);
        $waiting = $this->firstMatch($this->vpgItem(), 88);
        self::assertSame('pending_second', $this->decide($mapper, 'link', $waiting['listing'], ['sku_id' => $waiting['sku'], 'units_per_item' => 2,
            'proposal_id' => $waiting['proposal']])['state']);
        $decided = $this->firstMatch($this->vpgItem(), 87);
        $this->confirm($mapper, $decided['proposal']);
        // the key's target (the Vape and Go listing) is ignored after the proposal: the evidence no longer holds
        $targetGone = $this->firstMatch($this->vpgItem(), 88);
        $this->decide($mapper, 'ignore', self::vpgListingOf($targetGone['sku']));
        // made before 0012 (no recorded basis): one provably unchanged since, one renamed since
        $legacyOk = $this->firstMatch($this->vpgItem(), 86);
        $legacyBad = $this->firstMatch($this->vpgItem(), 87);
        self::forgetBases([$legacyOk['proposal'], $legacyBad['proposal']]);
        $this->rename($legacyBad['listing'], 'Renamed, and nothing recorded what it was');
        $proposals = $this->rows('SELECT COUNT(*) FROM match_proposal');
        $audits = $this->rows('SELECT COUNT(*) FROM audit_log');

        $dry = $this->reband()->run(Caller::system('reband_proposals'), false);
        self::assertSame(['reband-b2.1', 'b2.1', 85, 15, 5], [$dry['run_id'], $dry['band_version'], $dry['key_min_confidence'], $dry['open'], $dry['unchanged']]);
        self::assertSame(['evidence_mismatch' => 1, 'manual' => 1], $dry['skipped'], 'a stored band its own version does not give back is left alone');
        self::assertSame(['Check>Key' => 8], $dry['moves']);
        self::assertSame(['changed:listing_profile' => 1, 'changed:target_link' => 1, 'listing_rejected_before' => 1, 'no_basis' => 1, 'pending_decision' => 1],
            $dry['blocked']);
        self::assertSame([[], [], 1], [$dry['applied'], $dry['failed'], $dry['backfilled']]);
        self::assertNull(ProposalBasis::of(self::$db, $legacyOk['proposal']), 'proved, not written, in a dry run');
        self::assertSame([$proposals, $audits, 0], [$this->rows('SELECT COUNT(*) FROM match_proposal'), $this->rows('SELECT COUNT(*) FROM audit_log'),
            $this->rows("SELECT COUNT(*) FROM match_run WHERE source = 'reband'")], 'a dry run writes nothing');

        $versions = [];
        foreach ([$move, $move85, $legacyOk] as $m) {
            $versions[$m['listing']] = $this->version($m['listing']);
        }
        // attributed to the owner (bin/reband_proposals.php --by)
        $r = $this->reband()->run($owner, true);
        self::assertSame(['Check>Key' => 3], $r['applied']);
        self::assertSame(['changed:listing_profile' => 1, 'changed:target_link' => 1, 'listing_rejected_before' => 1, 'no_basis' => 1, 'pending_decision' => 1],
            $r['blocked']);
        self::assertSame(['backfill', 1], [ProposalBasis::of(self::$db, $legacyOk['proposal'])['source'], $r['backfilled']], 'the proof is kept');
        foreach ([$move, $move85, $legacyOk] as $m) {
            self::assertSame('superseded', self::proposalRow($m['proposal'])['status'], 'decided proposals are never touched; the old one is superseded');
            $new = self::$db->one("SELECT p.*, r.run_id, r.source, r.engine_version, r.detail FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE p.open_listing_id = ?", [$m['listing']]);
            self::assertSame(['Key', 'reband-b2.1', 'reband', 'reband/b2.1', $m['sku'], 'barcode', 1], [$new['band'], $new['run_id'], $new['source'],
                $new['engine_version'], $new['proposed_sku_id'], $new['lane'], $new['ai_units_per_item']]);
            self::assertEquals(['band_version' => 'b2.1', 'decision' => 'M26', 'key_min_confidence' => 85], json_decode((string) $new['detail'], true));
            $ev = json_decode((string) $new['evidence'], true);
            self::assertSame(['Key', $m['proposal'], 'Check', 'b2.0', 'b2.1'], [$ev['band'], $ev['reband']['from_proposal_id'], $ev['reband']['from_band'],
                $ev['reband']['from_band_version'], $ev['reband']['band_version']]);
            self::assertStringStartsWith('barcode_key+ai_8', $ev['band_reasons'][0]);
            $b = ProposalBasis::of(self::$db, (int) $new['id']);
            self::assertSame(['recorded', self::vpgListingOf($m['sku']), 'mapped', $m['sku'], 1], [$b['source'], $b['target_listing_id'], $b['target_status'],
                $b['target_sku_id'], $b['target_units_per_item']]);
            self::assertSame(['suggested', $versions[$m['listing']]], [$this->link($m['listing'])['status'], $this->version($m['listing'])], 'a re-band is not a decision');
            self::assertSame($owner->actor, self::$db->value("SELECT actor FROM audit_log WHERE action = 'mapping.propose' AND entity_id = ? ORDER BY id DESC LIMIT 1",
                [(string) $m['listing']]));
        }
        foreach ([$candidates, $soft, $quote, $key, $conflict, $mismatch, $manual, $renamed, $rejected, $waiting, $legacyBad, $targetGone] as $m) {
            self::assertSame('open', self::proposalRow($m['proposal'])['status']);
        }
        self::assertSame('decided', self::proposalRow($decided['proposal'])['status']);
        $audit = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'mapping.reband'");
        self::assertSame([['Check>Key' => 3], $owner->actor], [json_decode((string) $audit['detail'], true)['applied'], $audit['actor']]);

        // Re-runs move nothing twice: a re-banded proposal replays to its own band under its own version.
        $again = $this->reband()->run(Caller::system('reband_proposals'), true);
        self::assertSame([8, ['Check>Key' => 5], []], [$again['unchanged'], $again['moves'], $again['applied']]);
    }

    // ------------------------------------------------------------------------------------------
    // M27: the basis of a proposal
    // ------------------------------------------------------------------------------------------

    public function testAProposalRecordsWhatItWasMadeAgainstAndAnOlderOneIsTrustedOnlyWhenProved(): void
    {
        $this->keySetup();
        $ok = $this->firstMatch($this->vpgItem(), 95);
        $b = ProposalBasis::of(self::$db, $ok['proposal']);
        $sku = self::$db->one('SELECT * FROM sku WHERE id = ?', [$ok['sku']]);
        $v = self::vpgListingOf($ok['sku']);
        self::assertSame(['recorded', $this->version($ok['listing']), $ok['sku'], ProposalBasis::itemHash($sku)], [$b['source'], $b['map_version'], $b['sku_id'], $b['item_hash']]);
        self::assertSame(self::$db->value('SELECT identity_hash FROM listing_profile WHERE listing_id = ?', [$ok['listing']]), $b['identity_hash']);
        self::assertSame(self::$db->value('SELECT identity_hash FROM listing_profile WHERE listing_id = ?', [$sku['origin_listing_id']]), $b['origin_identity_hash']);
        self::assertSame([$v, $this->version($v), 'mapped', $ok['sku'], 1], [$b['target_listing_id'], $b['target_map_version'], $b['target_status'],
            $b['target_sku_id'], $b['target_units_per_item']], 'the lane target: the Vape and Go listing the key named, and its link');
        self::assertSame(ProposalBasis::hash($b), $b['basis_hash']);
        $evOf = static fn (int $pid): mixed => json_decode((string) self::$db->value('SELECT evidence FROM match_proposal WHERE id = ?', [$pid]), true);
        self::assertSame([], ProposalBasis::changes($b, ProposalBasis::current(self::$db, $ok['listing'], $ok['sku'], $evOf($ok['proposal']))));

        $renamed = $this->firstMatch($this->vpgItem(), 95);
        $itemChanged = $this->firstMatch($this->vpgItem(), 95);
        $originRenamed = $this->firstMatch($this->vpgItem(), 95);
        // the key named a second Vape and Go listing of the item (not the one it was minted from), relinked since
        $ts = $this->vpgItem();
        $second = $this->secondVpgListing($ts);
        $targetRelinked = $this->firstMatch($ts, 95, ['lane_target' => $second['lane_target']], ['chosen' => $second['lane_target']]);
        self::assertSame($second['listing'], ProposalBasis::of(self::$db, $targetRelinked['proposal'])['target_listing_id']);
        $first = $this->firstMatch($this->vpgItem(), 95);
        $other = $this->proposals->run('run4-sold', 'first_match', null, 'n2.0/c1.0/v2.0/b2.0');
        $later = $this->proposals->add(Caller::system('import_proposals'), $first['listing'], $other, ['band' => 'Key', 'proposed_sku_id' => $first['sku'],
            'evidence' => self::firstMatchEvidence($first['sku'], 95), 'ai_confidence' => 95, 'ai_units_per_item' => 1])['proposal_id'];
        $all = [$ok['proposal'], $renamed['proposal'], $itemChanged['proposal'], $originRenamed['proposal'], $targetRelinked['proposal'], $later];
        self::forgetBases($all); // as for the proposals imported before 0012

        $mapper = $this->staffUser('mapper');
        $this->rename($renamed['listing'], 'Renamed by the site');
        self::$db->exec('UPDATE sku SET brand = ? WHERE id = ?', ['Another brand', $itemChanged['sku']]);
        $this->rename(self::vpgListingOf($originRenamed['sku']), 'The Vape and Go listing was renamed');
        $this->decide($mapper, 'link', $second['listing'], ['sku_id' => $this->vpgItem()]);

        $dry = ProposalBasis::backfill(self::$db, $all, false);
        self::assertSame([0, 1, ['item_changed_since' => 1, 'item_origin_unproved' => 1, 'listing_changed_since' => 1, 'no_suggest_record' => 1,
            'target_unproved' => 1]], [$dry['recorded'], $dry['backfilled'], $dry['unproved']]);
        self::assertSame([$ok['proposal']], array_keys($dry['proved']));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM match_proposal_basis WHERE proposal_id IN (' . implode(',', $all) . ')'), 'a dry run writes nothing');

        $done = ProposalBasis::backfill(self::$db, $all, true);
        self::assertSame(1, $done['backfilled']);
        $proved = ProposalBasis::of(self::$db, $ok['proposal']);
        self::assertSame(['backfill', $b['basis_hash']], [$proved['source'], $proved['basis_hash']], 'the proved basis is the one it was made against');
        $detail = json_decode((string) self::$db->value('SELECT detail FROM match_proposal_basis WHERE proposal_id = ?', [$ok['proposal']]), true);
        self::assertSame(['item_updated_at', 'origin_decision_id', 'proposal_created_at', 'suggest_decision_id', 'target_decision_id'], self::sortedKeys($detail));
        self::assertSame([1, 0], [ProposalBasis::backfill(self::$db, $all, true)['recorded'], ProposalBasis::backfill(self::$db, $all, true)['backfilled']]);

        // What a stored basis tells apart.
        $fresh = $this->firstMatch($this->vpgItem(), 95);
        $basis = ProposalBasis::of(self::$db, $fresh['proposal']);
        $this->rename($fresh['listing'], 'Renamed again');
        self::assertSame(['listing_profile', 'listing_link'], ProposalBasis::changes($basis, ProposalBasis::current(self::$db, $fresh['listing'], $fresh['sku'], $evOf($fresh['proposal']))));
        $fresh2 = $this->firstMatch($this->vpgItem(), 95);
        $basis2 = ProposalBasis::of(self::$db, $fresh2['proposal']);
        $this->ok($this->stock->setPolicy(self::staff(), $fresh2['sku'], 'backorder', $this->key('policy')));
        self::assertSame(['item'], ProposalBasis::changes($basis2, ProposalBasis::current(self::$db, $fresh2['listing'], $fresh2['sku'], $evOf($fresh2['proposal']))));

        // The lane target's link (review probes P1, P1b, P5): the Vape and Go listing the key named is relinked to another
        // item, ignored, or linked to the same item with units per item 2 by two people. Each changes the basis, and the
        // proposal no longer qualifies for a bulk confirm.
        $lead = $this->staffUser('mapping_lead');
        foreach (['relink', 'ignore', 'units'] as $how) {
            $f = $this->firstMatch($this->vpgItem(), 95);
            $fb = ProposalBasis::of(self::$db, $f['proposal']);
            $vl = self::vpgListingOf($f['sku']);
            self::assertSame([], (new KeyEligibility(self::$db))->check([$f['proposal']])[$f['proposal']]['reasons'], $how);
            match ($how) {
                'relink' => $this->decide($mapper, 'link', $vl, ['sku_id' => $this->vpgItem()]),
                'ignore' => $this->decide($mapper, 'ignore', $vl),
                'units' => $this->ds->approve($this->seedLead, $this->decide($lead, 'link', $vl, ['sku_id' => $f['sku'], 'units_per_item' => 2])['decision_id']),
            };
            self::assertSame(['target_link'], ProposalBasis::changes($fb, ProposalBasis::current(self::$db, $f['listing'], $f['sku'], $evOf($f['proposal']))), $how);
            self::assertSame(['changed:target_link', 'target_relinked'], (new KeyEligibility(self::$db))->check([$f['proposal']])[$f['proposal']]['reasons'], $how);
        }
    }

    // ------------------------------------------------------------------------------------------
    // M28: the sample
    // ------------------------------------------------------------------------------------------

    /**
     * A population of $hi Key proposals at 90-99 and $lo re-banded from 85-89, plus three Key proposals that do not qualify.
     *
     * @return array{hi: list<array<string, int>>, lo: list<array<string, int>>, owner: Caller}
     */
    private function population(int $hi, int $lo): array
    {
        $this->keySetup();
        $out = ['hi' => [], 'lo' => [], 'owner' => $this->staffUser(['mapping_lead', 'reviewer'])];
        for ($i = 0; $i < $hi; $i++) {
            $out['hi'][] = $this->firstMatch($this->vpgItem(), 90 + $i % 10, [], [], null, [], 10 + $i);
        }
        $los = [];
        for ($i = 0; $i < $lo; $i++) {
            $los[] = $this->firstMatch($this->vpgItem(), 85 + $i % 5, [], [], null, [], 500 + $i);
        }
        $this->firstMatch($this->vpgItem(), 95, [], [], null, ['ai_units_per_item' => 2]);
        $this->firstMatch($this->item('strict'), 95);
        $rejected = $this->firstMatch($this->vpgItem(), 95);
        $this->decide($this->staffUser('mapper'), 'reject', $rejected['listing'], ['sku_id' => $this->vpgItem(), 'proposal_id' => $rejected['proposal']]);
        self::assertSame(['Check>Key' => $lo], $this->reband()->run(Caller::system('reband_proposals'), true)['applied']);
        foreach ($los as $m) {
            $out['lo'][] = ['proposal' => (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$m['listing']])] + $m;
        }
        return $out;
    }

    /** The owner confirms the first $n members of a sample. @return list<array<string, mixed>> the members */
    private function confirmMembers(Caller $owner, string $sample, int $n = PHP_INT_MAX): array
    {
        $members = $this->samples()->status($sample)['members'];
        foreach (array_slice($members, 0, $n) as $m) {
            $this->confirm($owner, $m['proposal_id']);
        }
        return $members;
    }

    public function testTheSampleIsAtLeast20StratifiedDrawnWithAServerSeedAndNeverRedrawn(): void
    {
        $pop = $this->population(21, 9);
        $owner = $pop['owner'];
        $dry = $this->samples()->create($owner, 'owner-1', 20, false);
        self::assertSame([30, null, 'b2.1', null, []], [$dry['population'], $dry['seed'], $dry['band_version'], $dry['sample_id'], $dry['members']],
            'a dry run draws nothing: no seed, no members');
        self::assertSame([['conf_90_100', 21, 14], ['conf_85_89', 9, 6]], array_map(static fn (array $s): array => [$s['name'], $s['population'], $s['sample']], $dry['strata']));
        self::assertSame(['listing_rejected_before' => 1, 'protected_item' => 1, 'units_per_item' => 1], $dry['excluded']);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM key_sample'), 'a dry run writes nothing');

        $made = $this->samples()->create($owner, 'owner-1', 20, true);
        $seed = $made['seed'];
        self::assertIsInt($seed);
        self::assertGreaterThanOrEqual(1, $seed);
        self::assertLessThanOrEqual(KeySample::MAX_SEED, $seed);
        // the draw: in each stratum the lowest sha256("<seed>:<proposal id>"); positions in that order
        $expect = [];
        foreach (['conf_90_100' => array_column($pop['hi'], 'proposal'), 'conf_85_89' => array_column($pop['lo'], 'proposal')] as $stratum => $ids) {
            usort($ids, static fn (int $a, int $b): int => KeySample::rank($seed, $a) <=> KeySample::rank($seed, $b));
            foreach (array_slice($ids, 0, $stratum === 'conf_90_100' ? 14 : 6) as $id) {
                $expect[] = $id;
            }
        }
        usort($expect, static fn (int $a, int $b): int => KeySample::rank($seed, $a) <=> KeySample::rank($seed, $b));
        self::assertSame($expect, array_column($made['members'], 'proposal_id'));
        self::assertSame(range(1, 20), array_column($made['members'], 'position'));
        $v = $this->samples()->verify('owner-1');
        self::assertSame([true, $seed, $expect], [$v['matches'], $v['seed'], $v['stored']], 'anyone can re-draw it from the stored seed');

        $k = self::$db->one('SELECT * FROM key_sample WHERE id = ?', [$made['sample_id']]);
        self::assertSame(['owner-1', $seed, 20, 30, $owner->staffUserId, $owner->actor, 'b2.1', null], [$k['name'], (int) $k['seed'], $k['sample_size'], $k['population'],
            $k['created_by'], $k['actor'], $k['band_version'], $k['overrides']]);
        self::assertSame(KeySample::METHOD, $k['method']);
        self::assertSame([30, 20, 14, 6], [
            $this->rows('SELECT COUNT(*) FROM key_sample_member WHERE sample_id = ?', [$k['id']]),
            $this->rows('SELECT COUNT(*) FROM key_sample_member WHERE sample_id = ? AND position IS NOT NULL', [$k['id']]),
            $this->rows("SELECT COUNT(*) FROM key_sample_member WHERE sample_id = ? AND position IS NOT NULL AND stratum = 'conf_90_100'", [$k['id']]),
            $this->rows("SELECT COUNT(*) FROM key_sample_member WHERE sample_id = ? AND position IS NOT NULL AND stratum = 'conf_85_89' AND ai_confidence BETWEEN 85 AND 89", [$k['id']]),
        ]);
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.key_sample'"), true);
        self::assertSame([$seed, 20, 30], [$audit['seed'], $audit['size'], $audit['population']]);
        self::assertSame(array_map(static fn (array $m): array => [$m['position'], $m['proposal_id']], $made['members']), $audit['sample']);
        $st = $this->samples()->status('owner-1');
        self::assertSame(['waiting', 0, 0, 0, 'key_bulk:owner-1', [], $owner->staffUserId], [$st['verdict'], $st['decided'], $st['confirmed'], $st['failed'],
            $st['batch'], $st['fit'], $st['created_by_id']]);
        self::assertSame(array_fill(0, 20, 'open'), array_column($st['members'], 'state'));

        // No second draw over the same population: no reshuffling until a sample looks easy.
        $e = self::refused(409, 'population_too_small', fn () => $this->samples()->create($owner, 'again', 20, false));
        self::assertSame(['in_other_sample' => 30, 'listing_rejected_before' => 1, 'protected_item' => 1, 'units_per_item' => 1], $e->detail['excluded']);

        self::refused(409, 'sample_exists', fn () => $this->samples()->create($owner, 'owner-1', 20, true));
        self::refused(400, 'bad_size', fn () => $this->samples()->create($owner, 'small', 19, false));
        self::refused(400, 'bad_size', fn () => $this->samples()->create($owner, 'big', 201, false));
        self::refused(403, 'lead_required', fn () => $this->samples()->create($this->staffUser('mapper'), 'm-1', 20, false));
        self::refused(403, 'lead_required', fn () => $this->samples()->create($this->staffUser('reviewer'), 'r-1', 20, false));
        self::refused(403, 'staff_required', fn () => $this->samples()->create(Caller::system('test'), 's-1', 20, false));
        self::refused(400, 'bad_name', fn () => $this->samples()->create($owner, 'no spaces', 20, false));
        self::refused(404, 'unknown_sample', fn () => $this->samples()->create($owner, 'x-1', 20, false, ['nope']));
        self::refused(409, 'override_not_allowed', fn () => $this->samples()->create($owner, 'x-2', 20, false, ['owner-1'])); // it has not failed
        self::assertSame(3819, self::mysqlError(fn () => self::$db->exec('UPDATE key_sample SET sample_size = 19 WHERE id = ?', [$k['id']])),
            'the schema holds the minimum too');
    }

    // ------------------------------------------------------------------------------------------
    // M28: the bulk confirm
    // ------------------------------------------------------------------------------------------

    /**
     * The spot-check size is the Approval rules page's (approvals.spot_check_size, 5 to 200; review findings I2, I3, Y46-Y47): a
     * smaller setting draws a smaller sample (the database's CHECK follows the setting's range), and a sample is judged by the size in
     * force when it was drawn, so raising the setting later does not disqualify it, and a new sample needs the new size.
     */
    public function testASmallerSpotCheckIsDrawnAndKeepsTheSizeItWasDrawnWith(): void
    {
        $pop = $this->population(7, 3);
        $owner = $pop['owner'];
        $settings = new \CW\Settings(self::$db);
        try {
            $settings->set(Caller::system('settings'), 'approvals.spot_check_size', '8', 'a smaller spot check for this test');
            self::assertSame(8, KeySample::minSize(self::$db));
            $made = $this->samples()->create($owner, 'small-1', null, true);
            self::assertSame([8, 10], [$made['size'], $made['population']]);
            self::assertSame([8, 8], array_map('intval', array_values((array) self::$db->one('SELECT sample_size, required_size FROM key_sample WHERE id = ?',
                [$made['sample_id']]))), 'the size in force is stored with the sample');
            self::assertSame([], $this->samples()->status('small-1')['fit']);
            $settings->set(Caller::system('settings'), 'approvals.spot_check_size', '9', 'a bigger spot check from now on');
            self::assertSame([], $this->samples()->status('small-1')['fit'], 'raising the setting does not disqualify a sample already drawn');
            self::refused(400, 'bad_size', fn () => $this->samples()->create($owner, 'small-2', 8, false));
            self::assertSame(3819, self::mysqlError(static fn () => self::$db->exec("UPDATE key_sample SET required_size = 9 WHERE name = 'small-1'")),
                'a sample is never smaller than the size it needs');
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = CAST('20' AS JSON), updated_actor = 'system:migrate' WHERE setting_key = 'approvals.spot_check_size'");
        }
    }

    public function testTheBulkConfirmRefusesUntilTheOwnerHasConfirmedEverySampleMember(): void
    {
        $pop = $this->population(16, 8);
        $owner = $pop['owner'];
        $s = $this->samples()->create($owner, 's1', 20, true);
        self::assertSame([['conf_90_100', 16, 13], ['conf_85_89', 8, 7]], array_map(static fn (array $x): array => [$x['name'], $x['population'], $x['sample']], $s['strata']));
        $r = $this->bulk()->confirm($owner, 's1', true);
        self::assertSame(['sample_incomplete', 20, 0], [$r['refused'], count($r['problems']), $r['applied']]);
        self::assertSame(array_fill(0, 20, 'open'), array_column($r['problems'], 'state'));
        $members = $this->confirmMembers($owner, 's1', 17);
        $st = $this->samples()->status('s1');
        self::assertSame(['waiting', 17, 17], [$st['verdict'], $st['decided'], $st['confirmed']]);
        self::assertSame('sample_incomplete', $this->bulk()->confirm($owner, 's1', true)['refused']);
        // #18: its item was protected since, so the owner's confirmation waited for a second person (not a plain confirmation)
        $otherLead = $this->staffUser('mapping_lead');
        $this->ok($this->stock->setPolicy(self::staff(), (int) self::proposalRow($members[17]['proposal_id'])['proposed_sku_id'], 'strict', $this->key('policy')));
        $pending = $this->confirm($owner, $members[17]['proposal_id']);
        self::assertSame('waiting_second', $this->samples()->status('s1')['members'][17]['state']);
        $this->ds->approve($otherLead, $pending['decision_id']);
        // #19 confirmed by another mapping lead, #20 by a mapper: decided, but not by the sample's owner
        $this->confirm($otherLead, $members[18]['proposal_id']);
        $this->confirm($this->staffUser('mapper'), $members[19]['proposal_id']);
        $st = $this->samples()->status('s1');
        self::assertSame(['failed', 20, 17, 3, ['needed_second', 'confirmed_by_other', 'confirmed_by_other']], [$st['verdict'], $st['decided'], $st['confirmed'],
            $st['failed'], array_column(array_slice($st['members'], 17), 'state')]);
        $r = $this->bulk()->confirm($owner, 's1', true);
        self::assertSame(['sample_failed', [18, 19, 20]], [$r['refused'], array_column($r['problems'], 'position')]);
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM match_decision WHERE bulk_batch_id LIKE 'key_bulk:%'"));
        self::refused(403, 'not_sample_owner', fn () => $this->bulk()->confirm($otherLead, 's1', false));
        self::refused(403, 'lead_required', fn () => $this->bulk()->confirm($this->staffUser('mapper'), 's1', false));
        self::refused(404, 'unknown_sample', fn () => $this->bulk()->confirm($owner, 'nope', false));
        // a failed sample's listings never go into a bulk confirm
        $rest = array_map('intval', self::$db->column('SELECT proposal_id FROM key_sample_member WHERE position IS NULL'));
        self::assertCount(4, $rest);
        foreach ((new KeyEligibility(self::$db))->check($rest) as $c) {
            self::assertSame(['failed_sample_population', 'in_other_sample'], $c['reasons']);
        }
    }

    public function testOneRejectionStopsTheBulkConfirmAndNoNewSampleGetsPastIt(): void
    {
        $pop = $this->population(16, 8);
        $owner = $pop['owner'];
        $s = $this->samples()->create($owner, 's2', 20, true);
        $members = $this->confirmMembers($owner, 's2', 18);
        $rej = self::proposalRow($members[18]['proposal_id']);
        $this->decide($owner, 'reject', (int) $rej['listing_id'], ['sku_id' => (int) $rej['proposed_sku_id'], 'proposal_id' => (int) $rej['id']]);
        $this->decide($owner, 'new_item', $members[19]['listing_id'], ['proposal_id' => $members[19]['proposal_id']]);
        $st = $this->samples()->status('s2');
        self::assertSame(['failed', 18, 2], [$st['verdict'], $st['confirmed'], $st['failed']]);
        self::assertSame(['rejected', 'decided_otherwise'], [$st['members'][18]['state'], $st['members'][19]['state']]);
        $r = $this->bulk()->confirm($owner, 's2', true);
        self::assertSame(['sample_failed', ['rejected', 'decided_otherwise']], [$r['refused'], array_column($r['problems'], 'state')]);
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM match_decision WHERE bulk_batch_id = 'key_bulk:s2'"));

        // Review probe P3: a new sample over what is left draws nothing from the failed sample's listings.
        $e = self::refused(409, 'population_too_small', fn () => $this->samples()->create($owner, 'second', 20, false));
        self::assertSame(['failed_sample_population' => 4, 'listing_rejected_before' => 2, 'protected_item' => 1, 'units_per_item' => 1], $e->detail['excluded']);
        // An override is refused while nothing changed: same band, no new matching run (a re-band or an undo is not one).
        self::refused(409, 'override_not_allowed', fn () => $this->samples()->create($owner, 'second', 20, false, ['s2']));
        // After a new matching run: only its NEW proposals on those listings may be drawn again, and only with the override.
        $rest = self::$db->all('SELECT proposal_id, listing_id FROM key_sample_member WHERE sample_id = ? AND position IS NULL ORDER BY proposal_id', [$s['sample_id']]);
        $run4 = $this->proposals->run('run4-sold', 'first_match', null, 'n2.1/c1.0/v2.1/b2.1', null, ['band_version' => 'b2.1']);
        $fresh = [];
        foreach (array_slice($rest, 0, 2) as $m) {
            $old = self::proposalRow((int) $m['proposal_id']);
            $ev = json_decode((string) $old['evidence'], true);
            $fresh[] = $this->proposals->add(Caller::system('import_proposals'), (int) $m['listing_id'], $run4, ['band' => 'Key', 'proposed_sku_id' => (int) $old['proposed_sku_id'],
                'lane' => 'barcode', 'ai_outcome' => 'match', 'ai_confidence' => (int) $old['ai_confidence'], 'ai_units_per_item' => 1, 'evidence' => $ev])['proposal_id'];
        }
        $stale = (int) $rest[2]['proposal_id'];
        $without = (new KeyEligibility(self::$db))->check([...$fresh, $stale]);
        $with = (new KeyEligibility(self::$db, null, [$s['sample_id']]))->check([...$fresh, $stale]);
        self::assertSame([['failed_sample_population'], ['failed_sample_population']], [$without[$fresh[0]]['reasons'], $without[$fresh[1]]['reasons']]);
        self::assertSame([[], []], [$with[$fresh[0]]['reasons'], $with[$fresh[1]]['reasons']], 'a newer proposal, with the override');
        self::assertSame(['failed_sample_population', 'in_other_sample'], $with[$stale]['reasons'], 'the failed sample\'s own proposals: never');
        $e = self::refused(409, 'population_too_small', fn () => $this->samples()->create($owner, 'second', 20, false, ['s2']));
        self::assertSame(2, $e->detail['population'], 'the override was accepted (a new matching run), for the 2 newer proposals only');
    }

    public function testTheBulkConfirmLinksWhatStillQualifiesInThePopulationOnly(): void
    {
        $pop = $this->population(24, 8);
        $owner = $pop['owner'];
        $s = $this->samples()->create($owner, 's3', 20, true);
        self::assertSame([15, 5], array_column($s['strata'], 'sample'));
        $members = $this->confirmMembers($owner, 's3');
        $st = $this->samples()->status('s3');
        self::assertSame(['complete', []], [$st['verdict'], $st['fit']]);
        $inSample = array_column($members, 'proposal_id');
        $rest = array_values(array_filter(array_merge($pop['hi'], $pop['lo']), static fn (array $m): bool => !in_array($m['proposal'], $inSample, true)));
        self::assertCount(12, $rest);

        // A sample that is not fit unlocks nothing, however confirmed (tampering needs the admin login: the app login cannot).
        $sid = (int) $s['sample_id'];
        self::$db->exec('UPDATE key_sample SET seed = seed + 1 WHERE id = ?', [$sid]);
        $r = $this->bulk()->confirm($owner, 's3', false);
        self::assertSame(['sample_unfit', [['state' => 'draw_not_reproducible']]], [$r['refused'], $r['problems']]);
        self::$db->exec('UPDATE key_sample SET seed = seed - 1 WHERE id = ?', [$sid]);
        self::$db->exec("UPDATE key_sample_member SET stratum = 'conf_90_100' WHERE sample_id = ? AND position IS NOT NULL AND stratum = 'conf_85_89'", [$sid]);
        self::assertSame(['stratum_short:conf_85_89', 'draw_not_reproducible'], $this->samples()->status('s3')['fit']);
        self::assertSame('sample_unfit', $this->bulk()->confirm($owner, 's3', false)['refused']);
        self::$db->exec("UPDATE key_sample_member SET stratum = 'conf_85_89' WHERE sample_id = ? AND position IS NOT NULL AND ai_confidence < 90", [$sid]);
        self::assertSame([], $this->samples()->status('s3')['fit']);

        // After the draw: a rename, an item counted, a later run's proposal, a person's own decision, and the key's target
        // (the Vape and Go listing) relinked to another item, ignored, or made a multiple by two people (probes P1, P1b, P5).
        [$renamed, $protected, $superseded, $decided, $targetRelinked, $targetIgnored, $targetMultiple] = array_slice($rest, 0, 7);
        $mapper = $this->staffUser('mapper');
        $this->rename($renamed['listing'], 'Renamed after the sample');
        $this->ok($this->stock->setPolicy(self::staff(), $protected['sku'], 'strict', $this->key('policy')));
        $later = $this->proposals->run('run4-sold', 'first_match', null, 'n2.0/c1.0/v2.0/b2.1');
        $this->proposals->add(Caller::system('import_proposals'), $superseded['listing'], $later, ['band' => 'Check', 'proposed_sku_id' => $superseded['sku']]);
        $this->confirm($mapper, $decided['proposal']);
        $this->decide($mapper, 'link', self::vpgListingOf($targetRelinked['sku']), ['sku_id' => $this->vpgItem()]);
        $this->decide($mapper, 'ignore', self::vpgListingOf($targetIgnored['sku']));
        $vMultiple = self::vpgListingOf($targetMultiple['sku']);
        $this->ds->approve($this->seedLead, $this->decide($this->staffUser('mapping_lead'), 'link', $vMultiple,
            ['sku_id' => $targetMultiple['sku'], 'units_per_item' => 2])['decision_id']);
        $newcomer = $this->firstMatch($this->vpgItem(), 97); // Key after the draw: not in the population

        $dry = $this->bulk()->confirm($owner, 's3', false);
        self::assertNull($dry['refused']);
        self::assertSame([12, 5, 0, 0], [$dry['population'], $dry['eligible'], $dry['applied'], $dry['already_in_batch']]);
        self::assertSame(['changed:listing_profile' => 1, 'changed:target_link' => 3, 'proposal_decided' => 1, 'proposal_superseded' => 1, 'protected_item' => 1],
            $dry['excluded']);
        self::assertCount(12, $dry['checked'], 'every proposal of the population, for the report');
        foreach ([$targetRelinked, $targetIgnored, $targetMultiple] as $m) {
            self::assertSame(['changed:target_link', 'target_relinked'], $dry['checked'][$m['proposal']]['reasons']);
        }
        $eligible = array_slice($rest, 7);
        $want = array_column($eligible, 'proposal');
        $got = array_keys(array_filter($dry['checked'], static fn (array $c): bool => $c['reasons'] === []));
        sort($want);
        sort($got);
        self::assertSame($want, $got);
        self::assertSame([array_sum(array_map(fn (array $m): int => (int) self::$db->value('SELECT units_365d FROM listing_profile WHERE listing_id = ?', [$m['listing']]), $eligible)),
            'key_bulk:s3'], [$dry['units_365d'], $dry['batch']]);
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM match_decision WHERE bulk_batch_id = 'key_bulk:s3'"), 'a dry run writes nothing');
        self::refused(403, 'not_sample_owner', fn () => $this->bulk()->confirm($this->seedLead, 's3', true));

        $canary = $this->bulk()->confirm($owner, 's3', true, 3);
        self::assertSame([3, [], []], [$canary['applied'], $canary['skipped'], $canary['failed']]);
        $all = $this->bulk()->confirm($owner, 's3', true);
        self::assertSame([3, 2, 2], [$all['already_in_batch'], $all['eligible'], $all['applied']]);
        $third = $this->bulk()->confirm($owner, 's3', true);
        self::assertSame([0, 0, 5], [$third['eligible'], $third['applied'], $third['already_in_batch']], 're-runs link nothing twice');

        foreach ($eligible as $m) {
            $d = self::$db->one("SELECT * FROM match_decision WHERE listing_id = ? AND action = 'link'", [$m['listing']]);
            self::assertSame(['applied', 'key_bulk:s3', $owner->staffUserId, $m['sku'], 1, $m['proposal'], null],
                [$d['state'], $d['bulk_batch_id'], $d['decided_by'], $d['sku_id'], $d['units_per_item'], $d['proposal_id'], $d['needs_second']]);
            self::assertStringContainsString('spot-check s3 (20 of 20 confirmed by its owner', (string) $d['reason']);
            self::assertSame(['mapped', $m['sku'], 1], [$this->link($m['listing'])['status'], $this->link($m['listing'])['sku_id'], $this->link($m['listing'])['units_per_item']]);
            self::assertSame('decided', self::proposalRow($m['proposal'])['status']);
        }
        foreach ([$renamed, $protected, $superseded, $newcomer, $targetRelinked, $targetIgnored, $targetMultiple] as $m) {
            self::assertNotSame('mapped', $this->link($m['listing'])['status']);
        }
        $vl = $this->link($vMultiple);
        self::assertSame(['mapped', $targetMultiple['sku'], 2], [$vl['status'], $vl['sku_id'], $vl['units_per_item']],
            'the units decision two people made stands, and nothing was linked on the strength of the old one');
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM match_decision d JOIN key_sample_member m ON m.proposal_id = d.proposal_id WHERE m.position IS NOT NULL AND d.bulk_batch_id IS NOT NULL"),
            'the sample members are the owner\'s own decisions');
        self::assertSame(3, $this->rows("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.key_bulk'"));
        self::assertSame(5, $this->samples()->status('s3')['bulk_linked']);
    }

    public function testTheUndoUnlinksTheBatchThroughDecisionServiceAndReopensTheProposals(): void
    {
        $pop = $this->population(24, 8);
        $owner = $pop['owner'];
        $this->samples()->create($owner, 's4', 20, true);
        $this->confirmMembers($owner, 's4');
        self::assertSame(12, $this->bulk()->confirm($owner, 's4', true)['applied']);
        $linked = self::$db->all("SELECT id, listing_id, sku_id, proposal_id FROM match_decision WHERE bulk_batch_id = 'key_bulk:s4' ORDER BY id");
        // one relinked by hand since (left alone), one item counted since (its unlink needs a second person)
        $mapper = $this->staffUser('mapper');
        $this->decide($mapper, 'link', (int) $linked[0]['listing_id'], ['sku_id' => $this->vpgItem()]);
        $this->ok($this->stock->setPolicy(self::staff(), (int) $linked[1]['sku_id'], 'backorder', $this->key('policy')));

        self::refused(400, 'bad_batch', fn () => $this->bulk()->undo($owner, 'vpg_mint:test', false));
        self::refused(403, 'lead_required', fn () => $this->bulk()->undo($mapper, 'key_bulk:s4', false));
        $dry = $this->bulk()->undo($owner, 'key_bulk:s4', false);
        self::assertSame([12, 11, 1, 0, 0], [$dry['decisions'], $dry['linked'], $dry['changed_since'], $dry['applied'], $dry['reopened']]);
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM match_decision WHERE bulk_batch_id = 'undo:key_bulk:s4'"), 'a dry run writes nothing');

        $r = $this->bulk()->undo($owner, 'key_bulk:s4', true);
        self::assertSame([10, 1, 10, []], [$r['applied'], $r['pending'], $r['reopened'], $r['failed']]);
        foreach (array_slice($linked, 2) as $d) {
            $lid = (int) $d['listing_id'];
            self::assertSame(['suggested', null], [$this->link($lid)['status'], $this->link($lid)['sku_id']]);
            $u = self::$db->one("SELECT * FROM match_decision WHERE listing_id = ? AND action = 'unlink'", [$lid]);
            self::assertSame(['applied', 'undo:key_bulk:s4', $owner->staffUserId], [$u['state'], $u['bulk_batch_id'], $u['decided_by']]);
            $p = self::$db->one('SELECT p.*, r.run_id, r.source FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE p.open_listing_id = ?', [$lid]);
            self::assertSame(['Key', (int) $d['sku_id'], 'undo:key_bulk:s4', 'key_bulk_undo'], [$p['band'], $p['proposed_sku_id'], $p['run_id'], $p['source']]);
            self::assertSame((int) $d['proposal_id'], json_decode((string) $p['evidence'], true)['reopened']['from_proposal_id']);
        }
        self::assertSame('mapped', $this->link((int) $linked[0]['listing_id'])['status'], 'relinked by hand: left alone');
        self::assertNotSame((int) $linked[0]['sku_id'], $this->link((int) $linked[0]['listing_id'])['sku_id']);
        $pending = self::$db->one("SELECT id, state, needs_second FROM match_decision WHERE listing_id = ? AND bulk_batch_id = 'undo:key_bulk:s4'", [$linked[1]['listing_id']]);
        self::assertSame(['pending_second', ['protected_sku']], [$pending['state'], json_decode((string) $pending['needs_second'], true)]);

        $again = $this->bulk()->undo($owner, 'key_bulk:s4', false);
        self::assertSame([0, 1, 10, 1], [$again['linked'], $again['waiting_second'], $again['undone'], $again['changed_since']]);
        $this->ds->approve($this->staffUser('mapping_lead'), (int) $pending['id']);
        $after = $this->bulk()->undo($owner, 'key_bulk:s4', true);
        self::assertSame([1, 0, 1], [$after['reopen'], $after['applied'], $after['reopened']]);
        self::assertSame('suggested', $this->link((int) $linked[1]['listing_id'])['status']);
        self::assertSame([0, 0], [$this->bulk()->undo($owner, 'key_bulk:s4', true)['reopened'], $this->bulk()->undo($owner, 'key_bulk:s4', true)['applied']]);
        self::assertSame(11, $this->samples()->status('s4')['bulk_undone']);
        // the re-opened proposals are back in the Key queue, one at a time: an undone bulk link never goes into another bulk run
        $reopened = array_map('intval', self::$db->column("SELECT p.id FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE r.source = 'key_bulk_undo'"));
        self::assertCount(11, $reopened);
        foreach ((new KeyEligibility(self::$db))->check($reopened) as $c) {
            self::assertContains('bulk_undone_before', $c['reasons']);
        }
    }

    public function testEligibilityNamesEveryReasonAProposalIsLeftOut(): void
    {
        $this->keySetup();
        $mapper = $this->staffUser('mapper');
        $good = $this->firstMatch($this->vpgItem(), 95);
        $units = $this->firstMatch($this->vpgItem(), 95, [], [], null, ['ai_units_per_item' => 3]);
        $flag = $this->firstMatch($this->vpgItem(), 95, [], [], null, ['flags' => ['two_person_confirm']]);
        $s = $this->vpgItem();
        $notTarget = $this->firstMatch($s, 95, ['lane_target' => ['sku_id' => 999999] + self::firstMatchEvidence($s, 95)['lane_target']]);
        $notKeyNow = $this->firstMatch($this->vpgItem(), 95, [], ['warnings' => ['quote_not_verbatim: x']]);
        $merged = $this->firstMatch($this->vpgItem(), 95);
        $keep = $this->vpgItem();
        $anchor = (int) self::$db->value('SELECT origin_listing_id FROM sku WHERE id = ?', [$merged['sku']]);
        $m = $this->decide($mapper, 'merge_skus', $anchor, ['sku_id' => $keep, 'merge_from_sku_id' => $merged['sku']]);
        $this->ds->approve($this->seedLead, $m['decision_id']);
        $quarantine = $this->firstMatch($this->vpgItem(), 95);
        $q = $this->listing($this->altSite, 'Q1', $quarantine['sku'], 1, 'quarantined');
        self::assertGreaterThan(0, $q);
        $itemRejected = $this->firstMatch($this->vpgItem(), 95);
        $elsewhere = $this->firstMatch($this->vpgItem(), 95);
        $this->decide($mapper, 'reject', $elsewhere['listing'], ['sku_id' => $itemRejected['sku'], 'proposal_id' => $elsewhere['proposal']]);
        $noBasis = $this->firstMatch($this->vpgItem(), 95);
        self::forgetBases([$noBasis['proposal']]);
        $counted = $this->firstMatch($this->vpgItem(), 95);
        $this->book('count', $counted['sku'], 4, 'MAIN', '2026-09-26T13:00:00Z');
        $s2 = $this->vpgItem();
        $nowhere = ['cw_id' => 'CWP-999999999', 'vpg_variant_id' => 999999999] + self::firstMatchEvidence($s2, 95)['lane_target'];
        $unresolved = $this->firstMatch($s2, 95, ['lane_target' => $nowhere], ['chosen' => $nowhere]);
        $retitled = $this->firstMatch($this->vpgItem(), 95, ['title' => 'What the site called it when the run was exported']);
        $s3 = $this->vpgItem();
        $targetRetitled = $this->firstMatch($s3, 95, ['lane_target' => ['title' => 'The Vape and Go title then'] + self::firstMatchEvidence($s3, 95)['lane_target']]);

        $c = (new KeyEligibility(self::$db))->check([$good['proposal'], $units['proposal'], $flag['proposal'], $notTarget['proposal'], $notKeyNow['proposal'],
            $merged['proposal'], $quarantine['proposal'], $itemRejected['proposal'], $noBasis['proposal'], $counted['proposal'], $unresolved['proposal'],
            $retitled['proposal'], $targetRetitled['proposal']]);
        self::assertSame([], $c[$good['proposal']]['reasons']);
        self::assertSame(['units_per_item'], $c[$units['proposal']]['reasons']);
        self::assertSame(['flag:two_person_confirm'], $c[$flag['proposal']]['reasons']);
        self::assertSame(['not_key_target'], $c[$notTarget['proposal']]['reasons']);
        self::assertSame(['not_key_now'], $c[$notKeyNow['proposal']]['reasons']);
        // merged away: its Vape and Go listing moved to the kept item, so the key's target is no longer this item's
        self::assertSame(['item_merged', 'changed:item', 'changed:target_link', 'target_relinked'], $c[$merged['proposal']]['reasons']);
        self::assertSame(['item_quarantined'], $c[$quarantine['proposal']]['reasons']);
        self::assertSame(['item_rejected_before'], $c[$itemRejected['proposal']]['reasons']);
        self::assertSame(['no_basis'], $c[$noBasis['proposal']]['reasons']);
        self::assertSame(['counted_item'], $c[$counted['proposal']]['reasons'], 'counted, though its policy is still legacy');
        self::assertSame(['target_unresolved'], $c[$unresolved['proposal']]['reasons'], 'no listing of the item has the key\'s variant id');
        self::assertSame(['evidence_title_differs'], $c[$retitled['proposal']]['reasons'], 'the judge saw another title');
        self::assertSame(['evidence_target_title_differs'], $c[$targetRetitled['proposal']]['reasons']);
        self::assertSame(['eligible' => [$good['proposal']], 'excluded' => ['counted_item' => 1, 'evidence_target_title_differs' => 1, 'evidence_title_differs' => 1,
            'flag:two_person_confirm' => 1, 'item_merged' => 1, 'item_quarantined' => 1, 'item_rejected_before' => 1, 'no_basis' => 1, 'not_key_now' => 1,
            'not_key_target' => 1, 'target_unresolved' => 1, 'units_per_item' => 1], 'units_30d' => 10, 'units_365d' => 100],
            KeyEligibility::summarise($c));
        self::assertTrue(KeyEligibility::sameTitle('Acme  bar &amp; ICE', 'Acme Bar & Ice', null), 'case, spacing and entities aside');
        self::assertTrue(KeyEligibility::sameTitle('Mango', 'Acme Bar', 'Mango'), 'the variant title when there is one');
        self::assertFalse(KeyEligibility::sameTitle(null, 'Acme Bar', null));
    }

    /** @param array<string, mixed> $a @return list<string> */
    private static function sortedKeys(array $a): array
    {
        $k = array_map('strval', array_keys($a));
        sort($k);
        return $k;
    }
}
