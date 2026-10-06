<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;

/**
 * The owner's bulk confirm of Key proposals, and its single undo (docs/decisions.md M28; the owner's decision (2) of
 * 2 Oct 2026, which overrides U20's "no bulk confirm of Key" in this controlled CLI form only).
 *
 * confirm(): only the sample's owner (the mapping lead who drew it) runs it, and it refuses unless every member of the
 * named spot-check sample (KeySample) is CONFIRMED by that owner, none was rejected or decided otherwise, and the sample is
 * fit (at least 20 members, every non-empty stratum represented, the members exactly what its stored seed draws). Then it
 * links the rest of the sample's POPULATION (never a proposal that became Key after the sample was drawn) that
 * KeyEligibility still lets through, one DecisionService `link` per listing, each in its own transaction: the lane target's
 * listing is read FOR SHARE first (no decision on it can commit until this one does), the eligibility is checked again,
 * then the link: u = 1, the proposal named, expected_map_version = the map_version the proposal was made against (its
 * basis, M27: DecisionService refuses 409 when the listing moved since), bulk_batch_id `key_bulk:<sample>`, attributed to
 * the owner. A decision that would need a second person is rolled back (never left pending). After the link (inside the
 * same transaction, under the locks it holds) the item, its origin, the lane target's link and the rejects are checked
 * again, and that no hold of the LISTING arrived meanwhile (KeyHold, M30: a held listing is never linked by any sample's
 * bulk confirm, `held_for_review`). Dry run by default; re-runs link only what is left; --limit links a first few (a canary;
 * only links count). Every row of the check (eligible or not, with its reasons and its hold) is in the result for a report.
 *
 * undo(): for a `key_bulk:` batch only, every listing still linked by a decision of the batch is unlinked through
 * DecisionService (bulk_batch_id `undo:<batch>`, any mapping lead) and its proposal re-opened as a new proposal (run
 * `undo:<batch>`, source `key_bulk_undo`), so the listing is back in the Key queue for one-at-a-time review. Listings
 * relinked by hand since are left alone. An unlink that needs a second person (the item was counted since, M6) waits
 * for one like any decision; a later re-run re-opens its proposal once it is approved.
 */
final class KeyBulk
{
    public const BATCH_PREFIX = 'key_bulk:';
    public const UNDO_PREFIX = 'undo:';
    public const UNDO_SOURCE = 'key_bulk_undo';
    /** The sample's own verdict is read again every this many links (a member decided otherwise mid-run stops the run). */
    public const RECHECK_EVERY = 25;

    public function __construct(private readonly Db $db, private readonly DecisionService $ds, private readonly Proposals $proposals)
    {
    }

    public static function batchOf(string $sampleName): string
    {
        return self::BATCH_PREFIX . $sampleName;
    }

    /**
     * @return array<string, mixed> sample (status without members), lead, batch, population, eligible, excluded, held (proposals of the
     *         population whose listing is held now and still waiting, unmapped or suggested, whatever their first reason: the
     *         number the hold tool's held_after gave), units_30d, units_365d, already_in_batch, applied, skipped, failed, limit,
     *         refused (null or why), problems, checked (proposal id => KeyEligibility row)
     */
    public function confirm(Caller $lead, string $sampleName, bool $apply, ?int $limit = null): array
    {
        $staff = KeySample::lead($this->db, $lead);
        $samples = new KeySample($this->db);
        $st = $samples->status($sampleName);
        if ($staff['id'] !== $st['created_by_id']) {
            throw new CwException('not_sample_owner', "sample {$st['name']} was drawn by " . ($st['created_by_email'] ?? '#' . $st['created_by_id'])
                . ': only that mapping lead confirms its members and runs its bulk confirm', 403);
        }
        $batch = self::batchOf($st['name']);
        $members = $st['members'];
        unset($st['members']);
        $report = ['sample' => $st, 'lead' => $staff['id'], 'batch' => $batch, 'population' => 0, 'eligible' => 0, 'excluded' => [], 'held' => 0,
            'units_30d' => 0, 'units_365d' => 0, 'already_in_batch' => 0, 'applied' => 0, 'skipped' => [], 'failed' => [], 'limit' => $limit,
            'refused' => null, 'problems' => [], 'checked' => []];
        if ($st['verdict'] !== 'complete') {
            $report['refused'] = $st['verdict'] === 'failed' ? 'sample_failed' : 'sample_incomplete';
            foreach ($members as $m) {
                if ($m['state'] !== 'confirmed') {
                    $report['problems'][] = ['position' => $m['position'], 'proposal_id' => $m['proposal_id'], 'listing_id' => $m['listing_id'], 'state' => $m['state']];
                }
            }
            return $report;
        }
        if ($st['fit'] !== []) {
            $report['refused'] = 'sample_unfit';
            $report['problems'] = array_map(static fn (string $p): array => ['state' => $p], $st['fit']);
            return $report;
        }
        $ids = array_map('intval', $this->db->column('SELECT proposal_id FROM key_sample_member WHERE sample_id = ? AND position IS NULL ORDER BY proposal_id', [$st['id']]));
        $report['population'] = count($ids);
        $report['already_in_batch'] = (int) $this->db->value("SELECT COUNT(*) FROM match_decision WHERE bulk_batch_id = ? AND action = 'link' AND state = 'applied'", [$batch]);
        $elig = new KeyEligibility($this->db, $st['id'], array_map(static fn (array $o): int => (int) $o['sample_id'], $st['overrides']));
        $checked = $elig->check($ids);
        $sum = KeyEligibility::summarise($checked);
        $report['checked'] = $checked;
        $report['eligible'] = count($sum['eligible']);
        $report['excluded'] = $sum['excluded'];
        $report['held'] = count(array_filter($checked, static fn (array $c): bool => $c['hold'] !== null
            && in_array($c['listing_status'], KeyEligibility::OPEN_LISTING, true)));
        $report['units_30d'] = $sum['units_30d'];
        $report['units_365d'] = $sum['units_365d'];
        if (!$apply) {
            return $report;
        }
        $reason = "Key bulk confirm after the spot-check {$st['name']} ({$st['confirmed']} of {$st['size']} confirmed by its owner; M28)";
        $sinceCheck = 0;
        foreach ($sum['eligible'] as $pid) {
            if ($limit !== null && $report['applied'] >= $limit) {
                break;
            }
            if (++$sinceCheck > self::RECHECK_EVERY) {
                $sinceCheck = 1;
                $again = $samples->status($st['id']);
                if ($again['verdict'] !== 'complete' || $again['fit'] !== []) {
                    $report['refused'] = 'sample_changed_during_run';
                    break;
                }
            }
            $target = $checked[$pid]['target_listing_id'];
            try {
                $why = $this->db->transaction(function (Db $db) use ($lead, $pid, $batch, $reason, $elig, $target): ?string {
                    // The lane target first, FOR SHARE: a relink, ignore or unit change of it waits until this decision commits.
                    ProposalBasis::targetNow($db, $target, true);
                    $c = $elig->check([$pid])[$pid] ?? null;
                    if ($c === null || $c['reasons'] !== []) {
                        return $c['reasons'][0] ?? 'proposal_gone';
                    }
                    if ($c['target_listing_id'] !== $target) {
                        return 'changed:target_link';
                    }
                    $r = $this->ds->decide($lead, ['action' => 'link', 'listing_id' => $c['listing_id'], 'sku_id' => $c['sku_id'], 'units_per_item' => 1,
                        'proposal_id' => $pid, 'expected_map_version' => $c['basis']['map_version'], 'bulk_batch_id' => $batch, 'reason' => $reason]);
                    if ($r['state'] !== 'applied') {
                        throw new CwException('needs_second', 'the link would need a second person: ' . implode(', ', $r['needs_second']), 409);
                    }
                    // Under the locks the decision holds (listing X, item S, lane target S): no hold of X, and item, origin, key target
                    // and rejects as checked. A hold is written under the listing's lock (KeyHold reads it FOR SHARE): one that
                    // committed after the check above is seen here (READ COMMITTED), one still to come waits for this decision and
                    // then refuses. By the listing, so a hold written for an older proposal of X, in any sample, counts too.
                    if (KeyHold::activeForListings($db, [$c['listing_id']]) !== []) {
                        throw new CwException('held_meanwhile', 'the listing was held back for one-at-a-time review while it was being linked', 409);
                    }
                    $b = $c['basis'];
                    $now = ProposalBasis::current($db, $c['listing_id'], $c['sku_id']);
                    if ($now['item_hash'] !== $b['item_hash'] || $now['origin_identity_hash'] !== $b['origin_identity_hash']) {
                        throw new CwException('item_changed', 'the item changed while it was being linked', 409);
                    }
                    $t = ProposalBasis::targetNow($db, $target);
                    if ($t === null || $t['map_version'] !== $b['target_map_version'] || $t['status'] !== 'mapped' || $t['sku_id'] !== $c['sku_id']
                        || $t['units_per_item'] !== 1) {
                        throw new CwException('target_changed', 'the lane target listing changed while the item was being linked', 409);
                    }
                    if ($db->value('SELECT 1 FROM match_reject WHERE listing_id = ? OR sku_id = ? LIMIT 1', [$c['listing_id'], $c['sku_id']]) !== null) {
                        throw new CwException('rejected_meanwhile', 'a reject arrived while the item was being linked', 409);
                    }
                    return null;
                });
            } catch (CwException $e) {
                $report['failed'][$e->errorCode] = ($report['failed'][$e->errorCode] ?? 0) + 1;
                continue;
            }
            if ($why === null) {
                $report['applied']++;
            } else {
                $report['skipped'][$why] = ($report['skipped'][$why] ?? 0) + 1;
            }
        }
        ksort($report['skipped']);
        ksort($report['failed']);
        Audit::write($this->db, $lead, 'mapping.key_bulk', 'key_sample', (string) $st['id'], null, [
            'sample' => $st['name'], 'batch' => $batch, 'population' => $report['population'], 'eligible' => $report['eligible'],
            'excluded' => $report['excluded'], 'held' => $report['held'], 'applied' => $report['applied'], 'skipped' => $report['skipped'], 'failed' => $report['failed'],
            'limit' => $limit, 'already_in_batch' => $report['already_in_batch'], 'units_30d' => $report['units_30d'], 'units_365d' => $report['units_365d'],
            'refused' => $report['refused'],
        ]);
        return $report;
    }

    /**
     * @return array<string, mixed> batch, undo_batch, decisions, linked, waiting_second, undone, reopen, changed_since, applied,
     *         pending, reopened, failed
     */
    public function undo(Caller $lead, string $batch, bool $apply): array
    {
        if (!str_starts_with($batch, self::BATCH_PREFIX) || preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $batch) !== 1
            || strlen(self::UNDO_PREFIX . $batch) > 64) {
            throw new CwException('bad_batch', 'only a ' . self::BATCH_PREFIX . '<sample> batch can be undone here (at most '
                . (64 - strlen(self::UNDO_PREFIX)) . ' characters)', 400);
        }
        $staff = KeySample::lead($this->db, $lead);
        $undoBatch = self::UNDO_PREFIX . $batch;
        $report = ['batch' => $batch, 'undo_batch' => $undoBatch, 'lead' => $staff['id'], 'decisions' => 0, 'linked' => 0, 'waiting_second' => 0,
            'undone' => 0, 'reopen' => 0, 'changed_since' => 0, 'applied' => 0, 'pending' => 0, 'reopened' => 0, 'failed' => []];
        $plan = [];
        foreach ($this->db->all("SELECT id, listing_id, proposal_id FROM match_decision WHERE bulk_batch_id = ? AND action = 'link' AND state = 'applied' ORDER BY id", [$batch]) as $d) {
            $report['decisions']++;
            $what = $this->classify((int) $d['id'], (int) $d['listing_id'], $undoBatch);
            $report[$what]++;
            if (in_array($what, ['linked', 'reopen'], true)) {
                $plan[] = ['decision_id' => (int) $d['id'], 'listing_id' => (int) $d['listing_id'],
                    'proposal_id' => $d['proposal_id'] === null ? null : (int) $d['proposal_id'], 'what' => $what];
            }
        }
        if (!$apply) {
            return $report;
        }
        // The re-opened proposals' run, written outside the per-listing transactions (a deadlock retry re-runs those).
        $run = $plan === [] ? 0 : $this->proposals->run($undoBatch, self::UNDO_SOURCE, null, null, null, ['batch' => $batch, 'decision' => 'M28']);
        foreach ($plan as $x) {
            try {
                $res = $this->db->transaction(function (Db $db) use ($lead, $x, $batch, $undoBatch, $run): string {
                    $what = $this->classify($x['decision_id'], $x['listing_id'], $undoBatch);
                    if ($what === 'linked') {
                        $v = (int) $db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$x['listing_id']]);
                        $open = $this->openProposalId($x['listing_id']);
                        $r = $this->ds->decide($lead, ['action' => 'unlink', 'listing_id' => $x['listing_id'], 'expected_map_version' => $v,
                            'bulk_batch_id' => $undoBatch, 'reason' => "Undo of the bulk confirm {$batch} (M28)"] + ($open !== null ? ['proposal_id' => $open] : []));
                        if ($r['state'] !== 'applied') {
                            return 'pending';
                        }
                        $what = $this->classify($x['decision_id'], $x['listing_id'], $undoBatch);
                    }
                    if ($what !== 'reopen') {
                        return $what;
                    }
                    $this->reopen($lead, $run, $x);
                    return 'reopened';
                });
            } catch (CwException $e) {
                $report['failed'][$e->errorCode] = ($report['failed'][$e->errorCode] ?? 0) + 1;
                continue;
            }
            if ($res === 'reopened' || $res === 'undone') {
                $report['reopened'] += $res === 'reopened' ? 1 : 0;
                $report['applied'] += $x['what'] === 'linked' ? 1 : 0;
            } elseif ($res === 'pending') {
                $report['pending']++;
            }
        }
        ksort($report['failed']);
        Audit::write($this->db, $lead, 'mapping.key_bulk_undo', 'match_decision', $batch, null, array_diff_key($report, ['lead' => true]));
        return $report;
    }

    /**
     * What became of one decision of the batch: `linked` (its link is still the listing's), `waiting_second` (its undo waits
     * for a second person), `reopen` (undone, its proposal not re-opened yet), `undone` (done), `changed_since` (relinked or
     * unlinked otherwise: left alone).
     */
    private function classify(int $decisionId, int $listingId, string $undoBatch): string
    {
        $open = $this->db->value('SELECT decision_id FROM listing_map_history WHERE open_listing_id = ?', [$listingId]);
        if ($open !== null && (int) $open === $decisionId) {
            return $this->db->value('SELECT 1 FROM match_decision WHERE pending_listing_id = ? AND bulk_batch_id = ?', [$listingId, $undoBatch]) !== null
                ? 'waiting_second' : 'linked';
        }
        $closedBy = $this->db->one(
            'SELECT d.id, d.bulk_batch_id, d.action FROM listing_map_history h JOIN match_decision d ON d.id = h.closed_by_decision_id WHERE h.decision_id = ? AND h.listing_id = ?',
            [$decisionId, $listingId],
        );
        if ($closedBy === null || $closedBy['bulk_batch_id'] !== $undoBatch || $closedBy['action'] !== 'unlink') {
            return 'changed_since';
        }
        $l = $this->db->one('SELECT status FROM channel_listing WHERE id = ?', [$listingId]);
        return ($l['status'] ?? null) === 'unmapped' && $this->openProposalId($listingId) === null ? 'reopen' : 'undone';
    }

    private function openProposalId(int $listingId): ?int
    {
        $v = $this->db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listingId]);
        return $v === null ? null : (int) $v;
    }

    /**
     * A new open proposal, the copy of the one the bulk decision settled, so the listing is back in its queue.
     *
     * @param array{decision_id: int, listing_id: int, proposal_id: ?int} $x
     */
    private function reopen(Caller $lead, int $run, array $x): void
    {
        $p = $x['proposal_id'] === null ? null : $this->db->one('SELECT * FROM match_proposal WHERE id = ?', [$x['proposal_id']]);
        if ($p === null) {
            return;
        }
        $ev = json_decode((string) ($p['evidence'] ?? 'null'), true);
        $ev = is_array($ev) ? $ev : [];
        $ev['reopened'] = ['from_proposal_id' => (int) $p['id'], 'batch' => $this->batchOfDecision($x['decision_id']),
            'bulk_decision_id' => $x['decision_id'], 'decision' => 'M28'];
        $flags = json_decode((string) ($p['flags'] ?? '[]'), true);
        $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $this->proposals->add($lead, $x['listing_id'], $run, [
            'proposed_sku_id' => $int($p['proposed_sku_id']), 'proposed_new_item' => (bool) $p['proposed_new_item'], 'band' => (string) $p['band'],
            'lane' => $p['lane'], 'ai_outcome' => $p['ai_outcome'], 'ai_confidence' => $int($p['ai_confidence']),
            'ai_units_per_item' => $int($p['ai_units_per_item']), 'ai_model' => $p['ai_model'], 'closest_sku_id' => $int($p['closest_sku_id']),
            'evidence' => $ev, 'flags' => is_array($flags) ? array_map('strval', $flags) : [],
        ]);
    }

    private function batchOfDecision(int $decisionId): ?string
    {
        $b = $this->db->value('SELECT bulk_batch_id FROM match_decision WHERE id = ?', [$decisionId]);
        return $b === null ? null : (string) $b;
    }

    /** @param array<string, int> $m */
    public static function counts(array $m): string
    {
        return $m === [] ? '{}' : Idempotency::json($m);
    }
}
