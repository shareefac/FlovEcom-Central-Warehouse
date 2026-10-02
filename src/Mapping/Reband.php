<?php

declare(strict_types=1);

namespace CW\Mapping;

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Matching\Band;
use CW\Matching\StoredBand;

/**
 * Re-bands the OPEN proposals from their stored evidence and judge answer with the CURRENT band rules
 * (bin/reband_proposals.php; docs/decisions.md M26). Decided and superseded proposals are never touched.
 *
 * For each open proposal: the band version of its run (match_run.detail.band_version, else the engine string) must be
 * known, and replaying its evidence under THAT version must give back its stored band (else `evidence_mismatch`: the
 * evidence does not carry what the band was made from, and nothing is moved). Then the evidence is replayed under the
 * current version; a different band is a move. Only moves between Key and Check are applied (the proposed item is the
 * judge's pick in both); any other move is reported and needs a new matching run.
 *
 * A move is applied like any later run's proposal: under the listing's row lock, a NEW proposal (run
 * `reband-<version>`, source `reband`, same item, judge answer, flags and evidence plus `evidence.reband`) supersedes the
 * old one (Proposals::add, audited `mapping.propose`). Never when the listing is linked, ignored or quarantined, has a
 * decision waiting for a second person, or its proposal's basis (M27) is missing or no longer holds (the evidence would be
 * stale), or its lane target was relinked or a title the judge saw changed since. Re-runs move nothing twice: a re-banded
 * proposal replays to its own band. The caller is the mapping lead the run is attributed to (bin/reband_proposals.php --by),
 * or the system.
 */
final class Reband
{
    public const SOURCE = 'reband';
    /** Moves applied (stored band > band now). */
    public const APPLIED_MOVES = ['Check>Key', 'Key>Check'];

    public function __construct(private readonly Db $db, private readonly Proposals $proposals)
    {
    }

    /**
     * @return array{run_id: string, band_version: string, key_min_confidence: int, open: int, unchanged: int, skipped: array<string, int>,
     *               moves: array<string, int>, blocked: array<string, int>, applied: array<string, int>, failed: array<string, int>,
     *               examples: array<string, list<array<string, mixed>>>, backfilled: int}
     */
    public function run(Caller $caller, bool $apply, ?int $channelId = null, ?string $runId = null): array
    {
        $runId ??= 'reband-' . Band::VERSION;
        $report = ['run_id' => $runId, 'band_version' => Band::VERSION, 'key_min_confidence' => Band::KEY_MIN_CONFIDENCE, 'open' => 0, 'unchanged' => 0,
            'skipped' => [], 'moves' => [], 'blocked' => [], 'applied' => [], 'failed' => [], 'examples' => [], 'backfilled' => 0];
        $plan = [];
        $last = 0;
        do {
            $rows = $this->db->all(
                'SELECT p.id, p.listing_id, p.band, p.evidence, p.ai_confidence, r.detail AS run_detail, r.engine_version, cl.status AS listing_status '
                . 'FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id JOIN channel_listing cl ON cl.id = p.listing_id '
                . "WHERE p.status = 'open' AND p.id > ?" . ($channelId !== null ? ' AND cl.channel_id = ?' : '') . ' ORDER BY p.id LIMIT 500',
                $channelId !== null ? [$last, $channelId] : [$last],
            );
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                $report['open']++;
                $c = self::classify($r);
                if ($c['skip'] !== null) {
                    $report['skipped'][$c['skip']] = ($report['skipped'][$c['skip']] ?? 0) + 1;
                    continue;
                }
                if ($c['to'] === $c['from']) {
                    $report['unchanged']++;
                    continue;
                }
                $move = $c['from'] . '>' . $c['to'];
                $report['moves'][$move] = ($report['moves'][$move] ?? 0) + 1;
                if (count($report['examples'][$move] ?? []) < 10) {
                    $report['examples'][$move][] = ['proposal_id' => (int) $r['id'], 'listing_id' => (int) $r['listing_id'],
                        'confidence' => $r['ai_confidence'] === null ? null : (int) $r['ai_confidence']];
                }
                if (!in_array($move, self::APPLIED_MOVES, true)) {
                    $report['blocked']['move_not_applied:' . $move] = ($report['blocked']['move_not_applied:' . $move] ?? 0) + 1;
                    continue;
                }
                $plan[(int) $r['id']] = $c + ['listing_id' => (int) $r['listing_id'], 'move' => $move];
            }
        } while (count($rows) === 500);

        // The listing and the basis of each move (the evidence must still describe the listing and the item).
        $bf = ProposalBasis::backfill($this->db, array_keys($plan), $apply);
        $report['backfilled'] = $bf['backfilled'];
        $now = (new KeyEligibility($this->db))->check(array_keys($plan), $apply ? [] : $bf['proved']);
        foreach ($plan as $pid => $c) {
            $why = self::blocker($now[$pid] ?? null);
            if ($why !== null) {
                $report['blocked'][$why] = ($report['blocked'][$why] ?? 0) + 1;
                unset($plan[$pid]);
            }
        }
        if ($apply && $plan !== []) {
            $run = $this->proposals->run($runId, self::SOURCE, null, 'reband/' . Band::VERSION, null,
                ['band_version' => Band::VERSION, 'key_min_confidence' => Band::KEY_MIN_CONFIDENCE, 'decision' => 'M26']);
            foreach ($plan as $pid => $c) {
                try {
                    $res = $this->apply($caller, $run, $pid, $c);
                    $key = $res === null ? $c['move'] : $res;
                    $bucket = $res === null ? 'applied' : 'blocked';
                    $report[$bucket][$key] = ($report[$bucket][$key] ?? 0) + 1;
                } catch (CwException $e) {
                    $report['failed'][$e->errorCode] = ($report['failed'][$e->errorCode] ?? 0) + 1;
                }
            }
        }
        foreach (['skipped', 'moves', 'blocked', 'applied', 'failed'] as $k) {
            ksort($report[$k]);
        }
        if ($apply && $report['moves'] !== []) {
            Audit::write($this->db, $caller, 'mapping.reband', 'match_run', $runId, null, array_diff_key($report, ['examples' => true]));
        }
        return $report;
    }

    /**
     * The band a stored proposal had under its own version and has now.
     *
     * @param array<string, mixed> $r match_proposal row + run_detail, engine_version
     * @return array{skip: ?string, from: string, to: ?string, version: ?string, reasons: list<string>}
     */
    public static function classify(array $r): array
    {
        $from = (string) $r['band'];
        $out = ['skip' => null, 'from' => $from, 'to' => null, 'version' => null, 'reasons' => []];
        if ($from === StoredBand::MANUAL) {
            return ['skip' => 'manual'] + $out;
        }
        $detail = json_decode((string) ($r['run_detail'] ?? 'null'), true);
        $version = Band::versionOf(is_array($detail) && is_string($detail['band_version'] ?? null) ? $detail['band_version'] : null)
            ?? Band::versionOf(is_string($r['engine_version'] ?? null) ? $r['engine_version'] : null);
        if ($version === null) {
            return ['skip' => 'unknown_band_version'] + $out;
        }
        $ev = json_decode((string) ($r['evidence'] ?? 'null'), true);
        $ev = is_array($ev) ? $ev : [];
        $then = StoredBand::evaluate($ev, Band::KEY_MIN_BY_VERSION[$version]);
        if ($then['band'] === null) {
            return ['skip' => 'evidence_unreadable', 'version' => $version] + $out;
        }
        if ($then['band'] !== $from) {
            return ['skip' => 'evidence_mismatch', 'version' => $version] + $out;
        }
        $now = StoredBand::evaluate($ev, Band::KEY_MIN_CONFIDENCE);
        return ['skip' => null, 'from' => $from, 'to' => $now['band'], 'version' => $version, 'reasons' => $now['reasons']];
    }

    /**
     * Why a move cannot be applied to this proposal now (null: it can): the proposal must be open, the listing unlinked
     * (unmapped / suggested) with no decision waiting, nobody may have decided anything on it since the proposal (a
     * reject included: a person who said "not this item" is not overruled by a band), its basis must hold, the lane target
     * must still be linked to the item with units 1, and the titles the judge saw must still be the listings' titles (else
     * the evidence is stale). The item-level rules (protected item, units, rejects by other listings) are the bulk
     * confirm's: they do not change what the evidence says, and DecisionService applies the two-person rule at every
     * decision anyway.
     *
     * @param array<string, mixed>|null $c KeyEligibility::check() of the proposal
     */
    private static function blocker(?array $c): ?string
    {
        if ($c === null) {
            return 'proposal_gone';
        }
        foreach ($c['reasons'] as $why) {
            $listingState = str_starts_with($why, 'listing_') && !in_array($why, ['listing_rejected_before', 'listing_decided_since'], true);
            if ($listingState || str_starts_with($why, 'proposal_') || str_starts_with($why, 'changed:')
                || in_array($why, ['pending_decision', 'listing_rejected_before', 'listing_decided_since', 'no_basis', 'target_unresolved',
                    'target_relinked', 'evidence_title_differs', 'evidence_target_title_differs'], true)) {
                return $why;
            }
        }
        return null;
    }

    /**
     * One move, in one transaction under the listing's row lock. Returns null when applied, else why not.
     *
     * @param array<string, mixed> $c classify() + listing_id, move
     */
    private function apply(Caller $caller, int $run, int $pid, array $c): ?string
    {
        return $this->db->transaction(function (Db $db) use ($caller, $run, $pid, $c): ?string {
            $db->one('SELECT id FROM channel_listing WHERE id = ? FOR UPDATE', [$c['listing_id']]);
            $p = $db->one('SELECT p.*, r.detail AS run_detail, r.engine_version, cl.status AS listing_status FROM match_proposal p '
                . 'JOIN match_run r ON r.id = p.match_run_id JOIN channel_listing cl ON cl.id = p.listing_id WHERE p.id = ?', [$pid]);
            if ($p === null || $p['status'] !== 'open') {
                return 'proposal_' . ($p['status'] ?? 'gone');
            }
            $again = self::classify($p);
            if ($again['skip'] !== null || $again['from'] . '>' . $again['to'] !== $c['move']) {
                return 'changed_meanwhile';
            }
            $why = self::blocker((new KeyEligibility($db))->check([$pid])[$pid] ?? null);
            if ($why === 'no_basis') {
                $proof = ProposalBasis::prove($db, $p);
                if ($proof['basis'] === null) {
                    return 'no_basis';
                }
                ProposalBasis::record($db, $pid, $proof['basis'], 'backfill', $proof['detail']);
                $why = self::blocker((new KeyEligibility($db))->check([$pid])[$pid] ?? null);
            }
            if ($why !== null) {
                return $why;
            }
            $ev = json_decode((string) $p['evidence'], true);
            $ev = is_array($ev) ? $ev : [];
            $ev['reband'] = ['from_proposal_id' => $pid, 'from_band' => $c['from'], 'from_band_version' => $c['version'],
                'from_reasons' => is_array($ev['band_reasons'] ?? null) ? $ev['band_reasons'] : [], 'band_version' => Band::VERSION,
                'key_min_confidence' => Band::KEY_MIN_CONFIDENCE, 'decision' => 'M26'];
            $ev['band'] = $c['to'];
            $ev['band_reasons'] = $again['reasons'];
            $flags = json_decode((string) ($p['flags'] ?? '[]'), true);
            $int = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
            $this->proposals->add($caller, (int) $p['listing_id'], $run, [
                'proposed_sku_id' => $int($p['proposed_sku_id']), 'proposed_new_item' => (bool) $p['proposed_new_item'], 'band' => $c['to'],
                'lane' => $p['lane'], 'ai_outcome' => $p['ai_outcome'], 'ai_confidence' => $int($p['ai_confidence']),
                'ai_units_per_item' => $int($p['ai_units_per_item']), 'ai_model' => $p['ai_model'], 'closest_sku_id' => $int($p['closest_sku_id']),
                'evidence' => $ev, 'flags' => is_array($flags) ? array_map('strval', $flags) : [],
            ]);
            return null;
        });
    }

    /** One line per move for the CLI report. @param array<string, mixed> $report @return list<string> */
    public static function lines(array $report): array
    {
        $out = [];
        foreach ($report['examples'] as $move => $list) {
            $out[] = sprintf('move %s: %d (e.g. %s)', $move, $report['moves'][$move] ?? 0, implode(', ', array_map(
                static fn (array $x): string => "proposal {$x['proposal_id']} listing {$x['listing_id']} conf {$x['confidence']}", array_slice($list, 0, 5))));
        }
        return $out;
    }

    /** @param array<string, int> $m */
    public static function counts(array $m): string
    {
        return $m === [] ? '{}' : Idempotency::json($m);
    }
}
