<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Db;
use CW\Matching\Band;
use CW\Matching\StoredBand;
use CW\Matching\Text;

/**
 * Which Key proposals a bulk confirm may link (docs/decisions.md M28, the owner's safety rules of 2 Oct 2026). A
 * proposal qualifies only when NONE of these holds (reasons, in report order; the first is the one counted):
 *
 *   proposal_decided / proposal_superseded   it is no longer open
 *   not_key                                  its stored band is not Key
 *   listing_<status>                         the listing is linked, ignored or quarantined (only unmapped / suggested)
 *   pending_decision                         a decision on the listing waits for a second person
 *   held_for_review                          its LISTING is held back from every bulk confirm for one-at-a-time review
 *                                            (KeyHold, M30), whatever sample or proposal the hold was written for (a newer
 *                                            proposal of a held listing too); the row's `hold` says why, who and when
 *   no_item / item_merged / protected_item   no proposed item, or it was merged away, or it is protected (sell policy
 *                                            not legacy, or sku.counted_at set)
 *   counted_item                             counted at a warehouse (stock_balance.counted_at, or a `count` movement)
 *   item_quarantined                         a listing of the item is quarantined
 *   units_per_item                           the judge's units per item is not 1 (two people)
 *   flag:<flag>                              two_person_confirm, relabel_pending, target_not_minted, merge_suggestion
 *   listing_rejected_before                  the listing rejected some item before (match_reject)
 *   item_rejected_before                     some listing rejected the item or an item merged into it
 *   listing_decided_since                    any decision (other than the system suggest) on the listing since the proposal
 *   bulk_undone_before                       a bulk confirm of this listing was undone once (bin/bulk_unlink.php): one at a time
 *   failed_sample_population                 the listing was in the population of a spot-check sample that FAILED (unless the
 *                                            sample acted for holds an override for it and the proposal is newer than it)
 *   in_other_sample                          the proposal is in the population of another spot-check sample
 *   no_basis / changed:<part>                the proposal's basis (M27) is missing, or its listing profile, link, item, the
 *                                            item's origin listing or the lane target's link changed since it was made
 *   target_unresolved                        the evidence names a lane target but no listing of the item was found for it
 *   target_relinked                          the lane target (the Vape and Go listing the key named) is not linked to the
 *                                            proposed item with units per item 1 now (relinked, unlinked, ignored, a multiple)
 *   evidence_title_differs                   the listing's title now is not the one the judge saw (evidence.title)
 *   evidence_target_title_differs            the lane target's title now is not the one the judge saw (evidence.lane_target.title)
 *   evidence_unreadable / not_key_now        the stored evidence does not replay to Key under the CURRENT band rules
 *   not_key_target                           the proposed item is not the barcode/transfer target and the judge's pick
 *
 * Merges and protected items need two people (plan §7.1) and never reach this list; DecisionService checks the
 * two-person rule again at the decision itself.
 */
final class KeyEligibility
{
    public const OPEN_LISTING = ['unmapped', 'suggested'];
    public const FLAG_BLOCKS = ['merge_suggestion', 'relabel_pending', 'target_not_minted', 'two_person_confirm'];

    /**
     * @param int|null $forSample the sample whose population is being acted on (its own population is not "another sample")
     * @param list<int> $overrides failed samples whose listings may be acted on again, for proposals made after them only
     */
    public function __construct(private readonly Db $db, private readonly ?int $forSample = null, private readonly array $overrides = [])
    {
    }

    /**
     * The title the matcher showed the judge for a listing (CW\Matching\Normalizer: the variant title, else the product title,
     * cleaned), compared loosely (Text::squash: case and spacing).
     */
    public static function sameTitle(mixed $judged, ?string $productTitle, ?string $variantTitle): bool
    {
        if (!is_string($judged) || trim($judged) === '') {
            return false;
        }
        $vt = Text::clean($variantTitle);
        return Text::squash($judged) === Text::squash($vt !== '' ? $vt : Text::clean($productTitle));
    }

    /**
     * @param list<int> $proposalIds
     * @param array<int, array<string, mixed>> $virtualBases proposal id => a proved basis not written yet (dry runs)
     * @return array<int, array{proposal_id: int, listing_id: int, sku_id: ?int, confidence: ?int, units_30d: int, units_365d: int,
     *         reasons: list<string>, basis: ?array<string, mixed>, map_version: int, channel_id: int, variant: string, title: string,
     *         item_code: ?string, item_name: ?string, target_listing_id: ?int, created_at: string, listing_status: string,
     *         hold: ?array{hold_id: int, sample_id: int, sample: string, proposal_id: int, reason: string, by: ?string, by_email: ?string, at: string}}>
     */
    public function check(array $proposalIds, array $virtualBases = []): array
    {
        $proposalIds = array_values(array_unique(array_map('intval', $proposalIds)));
        $out = [];
        if ($proposalIds === []) {
            return $out;
        }
        $merged = [];
        foreach ($this->db->all('SELECT id, merged_into_sku_id FROM sku WHERE merged_into_sku_id IS NOT NULL') as $m) {
            $merged[(int) $m['merged_into_sku_id']][] = (int) $m['id'];
        }
        $rejectedItems = array_fill_keys(array_map('intval', $this->db->column('SELECT DISTINCT sku_id FROM match_reject')), true);
        $itemCols = implode(', ', array_map(static fn (string $c): string => "s.{$c} AS item_{$c}", ProposalBasis::ITEM_COLUMNS));
        $verdicts = [];
        foreach (array_chunk($proposalIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->db->all(
                'SELECT p.id, p.listing_id, p.proposed_sku_id, p.band, p.ai_confidence, p.ai_units_per_item, p.evidence, p.flags, p.status, p.created_at, '
                . 'cl.status AS listing_status, cl.map_version, cl.channel_id, cl.external_variant_id, lp.identity_hash, lp.units_30d, lp.units_365d, '
                . 'lp.product_title, lp.variant_title, '
                . "s.id AS item_id, {$itemCols}, olp.identity_hash AS origin_identity_hash "
                . 'FROM match_proposal p JOIN channel_listing cl ON cl.id = p.listing_id LEFT JOIN listing_profile lp ON lp.listing_id = p.listing_id '
                . 'LEFT JOIN sku s ON s.id = p.proposed_sku_id LEFT JOIN listing_profile olp ON olp.listing_id = s.origin_listing_id '
                . "WHERE p.id IN ({$in})",
                $chunk,
            );
            $listingIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['listing_id'], $rows)));
            $skuIds = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => $r['proposed_sku_id'] === null ? null : (int) $r['proposed_sku_id'], $rows))));
            $pending = $this->set('SELECT pending_listing_id FROM match_decision WHERE pending_listing_id IN (%s)', $listingIds);
            $listingRejects = $this->set('SELECT DISTINCT listing_id FROM match_reject WHERE listing_id IN (%s)', $listingIds);
            $quarantined = $this->set("SELECT DISTINCT sku_id FROM channel_listing WHERE status = 'quarantined' AND sku_id IN (%s)", $skuIds);
            // counted, as bin/import_opening_estimate.php reads it: a count time on a balance, or a count movement
            $counted = $this->set('SELECT DISTINCT sku_id FROM stock_balance WHERE counted_at IS NOT NULL AND sku_id IN (%s)', $skuIds)
                + $this->set("SELECT DISTINCT b.sku_id FROM stock_balance b WHERE b.sku_id IN (%s) AND EXISTS (SELECT 1 FROM stock_ledger l "
                    . "WHERE l.warehouse_id = b.warehouse_id AND l.sku_id = b.sku_id AND l.movement_type = 'count')", $skuIds);
            $decidedSince = $this->set(
                "SELECT DISTINCT p.id FROM match_proposal p JOIN match_decision d ON d.listing_id = p.listing_id AND d.action <> 'suggest' "
                . 'AND d.created_at >= p.created_at WHERE p.id IN (%s)',
                $chunk,
            );
            $undone = $this->set("SELECT DISTINCT listing_id FROM match_decision WHERE listing_id IN (%s) AND bulk_batch_id LIKE 'undo:%%'", $listingIds);
            $held = KeyHold::activeForListings($this->db, $listingIds);
            // spot-check populations the listings (or the proposals) are in, and those samples' verdicts (M28)
            $samplesOfListing = [];
            $samplesOfProposal = [];
            foreach (array_chunk($listingIds, 500) as $lc) {
                foreach ($this->db->all('SELECT sample_id, proposal_id, listing_id FROM key_sample_member WHERE listing_id IN ('
                    . implode(',', array_fill(0, count($lc), '?')) . ')', $lc) as $m) {
                    $samplesOfListing[(int) $m['listing_id']][(int) $m['sample_id']] = true;
                    $samplesOfProposal[(int) $m['proposal_id']][(int) $m['sample_id']] = true;
                }
            }
            $need = [];
            foreach ($samplesOfListing as $ss) {
                foreach (array_keys($ss) as $sid) {
                    if (!isset($verdicts[$sid])) {
                        $need[$sid] = true;
                    }
                }
            }
            if ($need !== []) {
                $verdicts += (new KeySample($this->db))->verdicts(array_keys($need));
            }
            $bases = ProposalBasis::ofMany($this->db, $chunk);
            $targetIds = [];
            foreach ($rows as $r) {
                $b = $bases[(int) $r['id']] ?? $virtualBases[(int) $r['id']] ?? null;
                if (($b['target_listing_id'] ?? null) !== null) {
                    $targetIds[(int) $b['target_listing_id']] = true;
                }
            }
            $targets = [];
            foreach (array_chunk(array_keys($targetIds), 500) as $tc) {
                foreach ($this->db->all('SELECT cl.id, cl.map_version, cl.status, cl.sku_id, cl.units_per_item, lp.product_title, lp.variant_title '
                    . 'FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id WHERE cl.id IN ('
                    . implode(',', array_fill(0, count($tc), '?')) . ')', $tc) as $t) {
                    $targets[(int) $t['id']] = $t;
                }
            }
            foreach ($rows as $r) {
                $pid = (int) $r['id'];
                $lid = (int) $r['listing_id'];
                $sku = $r['proposed_sku_id'] === null ? null : (int) $r['proposed_sku_id'];
                $item = null;
                if ($r['item_id'] !== null) {
                    $item = [];
                    foreach (ProposalBasis::ITEM_COLUMNS as $c) {
                        $item[$c] = $r['item_' . $c];
                    }
                }
                $basis = $bases[$pid] ?? $virtualBases[$pid] ?? null;
                $targetId = $basis['target_listing_id'] ?? null;
                $t = $targetId !== null ? ($targets[(int) $targetId] ?? null) : null;
                $now = ProposalBasis::fromParts($lid, (int) $r['map_version'], $r['identity_hash'], $sku, $item, $r['origin_identity_hash'],
                    $t === null ? null : ProposalBasis::targetRow($t));
                $ev = json_decode((string) ($r['evidence'] ?? 'null'), true);
                $ev = is_array($ev) ? $ev : null;
                $reasons = [];
                if ($r['status'] !== 'open') {
                    $reasons[] = 'proposal_' . $r['status'];
                }
                if ($r['band'] !== Band::KEY) {
                    $reasons[] = 'not_key';
                }
                if (!in_array($r['listing_status'], self::OPEN_LISTING, true)) {
                    $reasons[] = 'listing_' . $r['listing_status'];
                }
                if (isset($pending[$lid])) {
                    $reasons[] = 'pending_decision';
                }
                $hold = $held[$lid] ?? null;
                if ($hold !== null) {
                    $reasons[] = 'held_for_review';
                }
                if ($sku === null || $item === null) {
                    $reasons[] = 'no_item';
                } else {
                    if ($item['merged_into_sku_id'] !== null) {
                        $reasons[] = 'item_merged';
                    }
                    if ($item['sell_policy'] !== 'legacy' || $item['counted_at'] !== null) {
                        $reasons[] = 'protected_item';
                    }
                    if (isset($counted[$sku])) {
                        $reasons[] = 'counted_item';
                    }
                    if (isset($quarantined[$sku])) {
                        $reasons[] = 'item_quarantined';
                    }
                }
                if ($r['ai_units_per_item'] === null || (int) $r['ai_units_per_item'] !== 1) {
                    $reasons[] = 'units_per_item';
                }
                $flags = json_decode((string) ($r['flags'] ?? '[]'), true);
                foreach (array_intersect(self::FLAG_BLOCKS, is_array($flags) ? $flags : []) as $f) {
                    $reasons[] = 'flag:' . $f;
                }
                if (isset($listingRejects[$lid])) {
                    $reasons[] = 'listing_rejected_before';
                }
                if ($sku !== null && array_intersect_key($rejectedItems, array_flip(self::family($sku, $merged))) !== []) {
                    $reasons[] = 'item_rejected_before';
                }
                if (isset($decidedSince[$pid])) {
                    $reasons[] = 'listing_decided_since';
                }
                if (isset($undone[$lid])) {
                    $reasons[] = 'bulk_undone_before';
                }
                foreach (array_keys($samplesOfListing[$lid] ?? []) as $sid) {
                    $v = $verdicts[$sid] ?? null;
                    if ($v !== null && $v['verdict'] === 'failed'
                        && !(in_array($sid, $this->overrides, true) && (string) $r['created_at'] > $v['created_at'])) {
                        $reasons[] = 'failed_sample_population';
                        break;
                    }
                }
                if (array_diff(array_keys($samplesOfProposal[$pid] ?? []), [$this->forSample]) !== []) {
                    $reasons[] = 'in_other_sample';
                }
                if ($basis === null) {
                    $reasons[] = 'no_basis';
                } else {
                    foreach (ProposalBasis::changes($basis, $now) as $part) {
                        $reasons[] = 'changed:' . $part;
                    }
                }
                $hasTarget = ProposalBasis::targetVariant($ev) !== null;
                if ($basis !== null && $hasTarget && $targetId === null) {
                    $reasons[] = 'target_unresolved';
                } elseif ($t !== null && ($t['status'] !== 'mapped' || $t['sku_id'] === null || (int) $t['sku_id'] !== $sku || (int) $t['units_per_item'] !== 1)) {
                    $reasons[] = 'target_relinked';
                }
                if (!self::sameTitle($ev['title'] ?? null, $r['product_title'], $r['variant_title'])) {
                    $reasons[] = 'evidence_title_differs';
                }
                if ($t !== null && !self::sameTitle($ev['lane_target']['title'] ?? null, $t['product_title'], $t['variant_title'])) {
                    $reasons[] = 'evidence_target_title_differs';
                }
                $band = StoredBand::evaluate($ev ?? [], Band::KEY_MIN_CONFIDENCE);
                if ($band['band'] === null) {
                    $reasons[] = 'evidence_unreadable';
                } elseif ($band['band'] !== Band::KEY) {
                    $reasons[] = 'not_key_now';
                }
                $target = is_array($ev['lane_target'] ?? null) ? ($ev['lane_target']['sku_id'] ?? null) : null;
                $chosen = is_array($ev['ai']['chosen'] ?? null) ? ($ev['ai']['chosen']['sku_id'] ?? null) : null;
                if ($sku === null || $target !== $sku || $chosen !== $sku) {
                    $reasons[] = 'not_key_target';
                }
                $vt = Text::clean($r['variant_title']);
                $out[$pid] = ['proposal_id' => $pid, 'listing_id' => $lid, 'sku_id' => $sku,
                    'confidence' => $r['ai_confidence'] === null ? null : (int) $r['ai_confidence'],
                    'units_30d' => (int) ($r['units_30d'] ?? 0), 'units_365d' => (int) ($r['units_365d'] ?? 0),
                    'reasons' => array_values(array_unique($reasons)), 'basis' => $basis, 'map_version' => (int) $r['map_version'],
                    'channel_id' => (int) $r['channel_id'], 'variant' => (string) $r['external_variant_id'],
                    'title' => $vt !== '' ? $vt : Text::clean($r['product_title']),
                    'item_code' => $item === null ? null : (string) $item['code'], 'item_name' => $item === null ? null : (string) $item['name'],
                    'target_listing_id' => $targetId === null ? null : (int) $targetId, 'created_at' => (string) $r['created_at'],
                    'listing_status' => (string) $r['listing_status'],
                    'hold' => $hold === null ? null : ['hold_id' => $hold['hold_id'], 'sample_id' => $hold['sample_id'], 'sample' => $hold['sample'],
                        'proposal_id' => $hold['proposal_id'], 'reason' => $hold['reason'], 'by' => $hold['by'], 'by_email' => $hold['by_email'], 'at' => $hold['at']]];
            }
        }
        return $out;
    }

    /**
     * Counts by first reason, and the units of the eligible ones.
     *
     * @param array<int, array<string, mixed>> $checked check() output
     * @return array{eligible: list<int>, excluded: array<string, int>, units_30d: int, units_365d: int}
     */
    public static function summarise(array $checked): array
    {
        $eligible = [];
        $excluded = [];
        $u30 = 0;
        $u365 = 0;
        foreach ($checked as $pid => $c) {
            if ($c['reasons'] === []) {
                $eligible[] = (int) $pid;
                $u30 += $c['units_30d'];
                $u365 += $c['units_365d'];
                continue;
            }
            $excluded[$c['reasons'][0]] = ($excluded[$c['reasons'][0]] ?? 0) + 1;
        }
        ksort($excluded);
        sort($eligible);
        return ['eligible' => $eligible, 'excluded' => $excluded, 'units_30d' => $u30, 'units_365d' => $u365];
    }

    /**
     * An item and every item merged into it (recursively; a merged item is never merged again, so no cycles).
     *
     * @param array<int, list<int>> $merged kept item => items merged into it
     * @return list<int>
     */
    private static function family(int $sku, array $merged): array
    {
        $out = [$sku];
        for ($i = 0; $i < count($out); $i++) {
            foreach ($merged[$out[$i]] ?? [] as $child) {
                $out[] = $child;
            }
        }
        return $out;
    }

    /** @param list<int> $ids @return array<int, true> */
    private function set(string $sql, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->db->column(sprintf($sql, implode(',', array_fill(0, count($chunk), '?'))), $chunk) as $v) {
                $out[(int) $v] = true;
            }
        }
        return $out;
    }
}
