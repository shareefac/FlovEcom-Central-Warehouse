<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * The band of a STORED first-match proposal, recomputed from its evidence (match_proposal.evidence as
 * bin/import_proposals.php writes it) with Band::final and the two routing rules tools/first_match/assemble.php
 * applies on top of it (docs/decisions.md M26):
 *   - a pending line relabel goes to Manual (relabel_pending set; the judge picked a relabel partner; a non-match whose
 *     closest item, or on the candidates lane any listed item, is a relabel partner);
 *   - a Key whose listing quote was not verbatim (answer warning quote_not_verbatim*) is capped at Check;
 * plus one guard of our own: Key also needs the evidence to say the key was possible and clean (key_possible true,
 * key_blocked_by empty, no veto or soft flag on the judge's pick). In a consistent run that guard never fires (assemble
 * refuses a Key without key_possible); when it does, the replay of the run's own version disagrees with the stored band
 * and Reband leaves the proposal alone.
 *
 * Ids: the evidence names items by their run id (`CWP-<vpg id>`); they are mapped to small integers for Band.
 * Candidates: the evidence holds the list the judge saw (the private ref map), not the engine's full candidate list,
 * so a New item / Can't tell result that depended on an unjudged candidate's prescore may not replay; the threshold
 * of Key (the only rule that differs between band versions) depends on the lane target and the judge's pick only.
 *
 * Pure: no database, no clock.
 */
final class StoredBand
{
    public const MANUAL = 'Manual';

    /**
     * @param array<string, mixed> $evidence a first-match proposal's evidence
     * @param int $keyMinConfidence Key's minimum judge confidence (Band::KEY_MIN_BY_VERSION[version])
     * @return array{band: ?string, reasons: list<string>, error: ?string} band null (+ error) when the evidence cannot be replayed
     */
    public static function evaluate(array $evidence, int $keyMinConfidence): array
    {
        $in = self::inputs($evidence);
        if ($in === null) {
            return ['band' => null, 'reasons' => [], 'error' => 'evidence_unreadable'];
        }
        [$ev, $judge, $route] = $in;
        $r = Band::final($ev, $judge, $keyMinConfidence);
        $band = $r['band'];
        $reasons = $r['reasons'];
        $keyLane = in_array($ev['lane'], ['barcode', 'transfer'], true);

        // routing of assemble.php: pending relabels are mapped by hand, never Key
        $why = null;
        if ($route['relabel_pending']) {
            $why = 'lane_target_differs_by_pending_relabel';
        } elseif ($judge['chosen_id'] !== null && in_array($judge['chosen_id'], $route['partners'], true)) {
            $why = 'ai_pick_is_relabel_partner';
        } elseif ($judge['outcome'] !== 'match' && $route['partners'] !== []
            && (($route['closest'] !== null && in_array($route['closest'], $route['partners'], true)) || !$keyLane)) {
            $why = $route['closest'] !== null && in_array($route['closest'], $route['partners'], true) ? 'ai_closest_is_relabel_partner' : 'relabel_partner_in_list';
        }
        if ($why !== null) {
            return ['band' => self::MANUAL, 'reasons' => array_merge([$why, 'band_v2:' . $band], $reasons), 'error' => null];
        }
        if ($band === Band::KEY && $route['quote_not_verbatim']) {
            return ['band' => Band::CHECK, 'reasons' => array_merge(['quote_not_verbatim_capped_at_check'], $reasons), 'error' => null];
        }
        if ($band === Band::KEY && !$route['key_clean']) {
            return ['band' => Band::CHECK, 'reasons' => array_merge(['key_evidence_not_clean'], $reasons), 'error' => null];
        }
        return ['band' => $band, 'reasons' => $reasons, 'error' => null];
    }

    /**
     * Band's inputs rebuilt from the evidence, or null when it lacks the first-match shape (no judge answer, no lane).
     *
     * @param array<string, mixed> $e
     * @return array{0: array<string, mixed>, 1: array{outcome: string, chosen_id: ?int, confidence: int, units_per_item: ?int},
     *               2: array{relabel_pending: bool, partners: list<int>, closest: ?int, quote_not_verbatim: bool, key_clean: bool}}|null
     */
    public static function inputs(array $e): ?array
    {
        $ai = $e['ai'] ?? null;
        $lane = $e['lane'] ?? null;
        if (!is_array($ai) || !is_string($lane) || $lane === '' || !is_string($ai['outcome'] ?? null) || !is_int($ai['confidence'] ?? null)) {
            return null;
        }
        $ids = [];
        $id = static function (mixed $item) use (&$ids): ?int {
            $cw = is_array($item) ? ($item['cw_id'] ?? null) : null;
            if (!is_string($cw) || $cw === '') {
                return null;
            }
            return $ids[$cw] ??= count($ids) + 1;
        };
        $codes = static fn (mixed $v): array => array_values(array_filter(is_array($v) ? $v : [], 'is_string'));

        $target = $id($e['lane_target'] ?? null);
        $chosen = $id($ai['chosen'] ?? null);
        $closest = $id($ai['closest'] ?? null);
        $laneFlags = [];
        $sep = null;
        foreach ($codes($e['lane_flags'] ?? []) as $f) {
            if (str_starts_with($f, 'separating_field_missing:')) {
                $sep = substr($f, strlen('separating_field_missing:'));
            } elseif (!str_contains($f, ':') && $f !== 'ignored_but_sold') {
                $laneFlags[] = $f;
            }
        }
        $targetVetoes = array_map(static fn (string $c): array => ['code' => $c], $codes($e['target_vetoes'] ?? []));
        $cands = [];
        foreach (is_array($e['candidates'] ?? null) ? $e['candidates'] : [] as $c) {
            $cid = is_array($c) ? $id($c) : null;
            if ($cid === null) {
                continue;
            }
            $cands[$cid] = ['id' => $cid, 'prescore' => is_int($c['prescore'] ?? null) ? $c['prescore'] : (int) ($c['prescore'] ?? 0),
                'vetoes' => $codes($c['vetoes'] ?? [])];
        }
        // The judge's pick and the lane target are always among the candidates the judge saw (assemble.php); an evidence
        // without a candidate list still carries the vetoes shown on them.
        if ($chosen !== null && !isset($cands[$chosen])) {
            $cands[$chosen] = ['id' => $chosen, 'prescore' => 0, 'vetoes' => $codes($ai['vetoes_on_chosen'] ?? [])];
        }
        if ($target !== null && !isset($cands[$target])) {
            $cands[$target] = ['id' => $target, 'prescore' => 0, 'vetoes' => $codes($e['target_vetoes'] ?? [])];
        }
        $units = $ai['units_per_item'] ?? null;
        $outcome = $ai['outcome'] === 'match' && $ai['confidence'] < 50 ? 'cannot_tell' : $ai['outcome'];
        $ev = ['lane' => $lane, 'is_placeholder' => false, 'flags' => $laneFlags, 'target' => $target, 'target_vetoes' => $targetVetoes,
            'target_flags' => $codes($e['target_soft_flags'] ?? []), 'candidates' => array_values($cands), 'separating_field_missing' => $sep,
            'pending_alias' => ($e['relabel_pending'] ?? null) !== null];
        $judge = ['outcome' => $outcome, 'chosen_id' => $chosen, 'confidence' => $ai['confidence'], 'units_per_item' => is_int($units) ? $units : null];
        $partners = [];
        foreach (is_array($e['relabel_partners'] ?? null) ? $e['relabel_partners'] : [] as $p) {
            if (($pid = $id($p)) !== null) {
                $partners[] = $pid;
            }
        }
        $quote = false;
        foreach ($codes($ai['warnings'] ?? []) as $w) {
            $quote = $quote || str_starts_with($w, 'quote_not_verbatim');
        }
        $keyClean = ($e['key_possible'] ?? null) === true && $codes($e['key_blocked_by'] ?? []) === []
            && $codes($ai['vetoes_on_chosen'] ?? []) === [] && $codes($ai['soft_flags_on_chosen'] ?? []) === [];
        return [$ev, $judge, ['relabel_pending' => ($e['relabel_pending'] ?? null) !== null, 'partners' => $partners, 'closest' => $closest,
            'quote_not_verbatim' => $quote, 'key_clean' => $keyClean]];
    }
}
