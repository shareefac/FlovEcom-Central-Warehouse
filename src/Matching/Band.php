<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Bands (plan §7.3 step 7; design A.8, evaluated in a fixed order, first match wins):
 *   Conflict > Ignore > New item > Can't tell > Check > Key.
 *
 * Nothing here links anything: a band only routes a proposal to a review queue.
 *
 * provisional(): deterministic evidence only (no AI yet). Returns a final band only for Ignore and
 * Conflict; otherwise band = null (awaiting the barcode-blind judge) plus the CEILING — the best band
 * the listing can reach if the judge agrees (Key needs a deterministic key AND the blind judge).
 *
 * final(): the same order with a validated judge answer (for use after the judge runs).
 */
final class Band
{
    /**
     * b2.1 (docs/decisions.md M26, the owner, 2 Oct 2026): Key from judge confidence 85 (b2.0: 90, pilot-1 rec. 9). Every other
     * Key condition is unchanged: barcode/transfer lane, the judge picks that target, units per item 1, no veto or soft flag on
     * the target, no pending line alias, no Conflict. A pending line alias routes to Can't tell.
     *
     * Reasons only (no band moves, M29): a key-lane no_match_in_list below 80 is labelled ai_no_match_on_key_below_80_<conf>
     * (b2.0 fell through to unrecognised_outcome for 70-79, which assemble.php relabelled, and said no_match_in_list_<conf>
     * below 70); unrecognised_outcome now means an outcome outside OUTCOMES.
     */
    public const VERSION = 'b2.1';

    public const KEY_MIN_CONFIDENCE = 85;

    /**
     * Key's minimum judge confidence per band version: the only rule that differs between the versions, so a stored
     * proposal can be re-banded (bin/reband_proposals.php) and its stored band checked against the version it was made with.
     */
    public const KEY_MIN_BY_VERSION = ['b2.0' => 90, 'b2.1' => 85];

    /** Soft flags meaning "same item only if a pending line alias is confirmed" (never Key, Can't tell until then). */
    public const ALIAS_PENDING_FLAGS = ['line_alias_pending', 'relabelled_line_unconfirmed'];

    public const KEY = 'Key';
    public const CHECK = 'Check';
    public const NEW_ITEM = 'New item';
    public const CANT_TELL = "Can't tell";
    public const CONFLICT = 'Conflict';
    public const IGNORE = 'Ignore';

    /** Lane-level conditions that are always a Conflict (design A.8 X). */
    public const CONFLICT_FLAGS = [
        'multi_sku_gtin', 'gtin_on_multiple_items', 'gtin_dup_in_channel', 'barcode_transfer_disagree',
    ];

    public const NEW_ITEM_MAX_PRESCORE = 60;

    /** The judge's outcomes (judge_v2.md). Anything else is Can't tell, reason `unrecognised_outcome`. */
    public const OUTCOMES = ['match', 'no_match_in_list', 'cannot_tell', 'multiple_plausible', 'not_a_product'];

    /**
     * @param array{lane:string,is_placeholder?:bool,flags?:list<string>,target?:?int,
     *              target_vetoes?:list<array{code:string}>,target_flags?:list<string>,
     *              candidates?:list<array{id:int,prescore:int,vetoes?:list<array>,flags?:list<string>}>,
     *              separating_field_missing?:?string} $ev
     * @return array{band:?string,ceiling:string,reasons:list<string>}
     */
    public static function provisional(array $ev): array
    {
        $reasons = [];
        if (!empty($ev['is_placeholder']) || ($ev['lane'] ?? '') === 'ignore') {
            return ['band' => self::IGNORE, 'ceiling' => self::IGNORE, 'reasons' => ['placeholder']];
        }
        $cf = array_values(array_intersect($ev['flags'] ?? [], self::CONFLICT_FLAGS));
        if ($cf !== []) {
            return ['band' => self::CONFLICT, 'ceiling' => self::CONFLICT, 'reasons' => $cf];
        }
        $lane = $ev['lane'] ?? 'candidates';
        if (in_array($lane, ['barcode', 'transfer'], true) && ($ev['target'] ?? null) !== null) {
            $tv = $ev['target_vetoes'] ?? [];
            if ($tv !== []) {
                return ['band' => self::CONFLICT, 'ceiling' => self::CONFLICT,
                    'reasons' => array_map(fn ($v) => 'veto_on_key:' . $v['code'], $tv)];
            }
            $soft = $ev['target_flags'] ?? [];
            $alias = array_values(array_intersect($soft, self::ALIAS_PENDING_FLAGS));
            if ($alias !== []) {
                return ['band' => null, 'ceiling' => self::CANT_TELL, 'reasons' => array_map(fn ($x) => 'alias_pending:' . $x, $alias)];
            }
            if ($soft !== []) {
                return ['band' => null, 'ceiling' => self::CHECK, 'reasons' => array_map(fn ($x) => 'soft:' . $x, $soft)];
            }
            return ['band' => null, 'ceiling' => self::KEY, 'reasons' => [$lane . '_key']];
        }
        $usable = array_values(array_filter($ev['candidates'] ?? [], fn ($c) => ($c['vetoes'] ?? []) === []));
        if ($usable === []) {
            return ['band' => null, 'ceiling' => self::NEW_ITEM, 'reasons' => [($ev['candidates'] ?? []) === [] ? 'no_candidates' : 'all_candidates_vetoed']];
        }
        $best = max(array_map(fn ($c) => $c['prescore'], $usable));
        if ($best < self::NEW_ITEM_MAX_PRESCORE) {
            return ['band' => null, 'ceiling' => self::NEW_ITEM, 'reasons' => ['best_prescore_' . $best]];
        }
        if (!empty($ev['separating_field_missing'])) {
            return ['band' => null, 'ceiling' => self::CANT_TELL, 'reasons' => ['separating_field_missing:' . $ev['separating_field_missing']]];
        }
        $reasons[] = 'ai_only_max_check';
        return ['band' => null, 'ceiling' => self::CHECK, 'reasons' => $reasons];
    }

    /**
     * The band version named by an engine string ('n2.0/c1.0/v2.0/b2.0', 'reband/b2.1') or a bare version, if this class
     * knows it (KEY_MIN_BY_VERSION); null otherwise.
     */
    public static function versionOf(?string $engineOrVersion): ?string
    {
        if ($engineOrVersion === null) {
            return null;
        }
        foreach (explode('/', $engineOrVersion) as $part) {
            if (isset(self::KEY_MIN_BY_VERSION[$part])) {
                return $part;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $ev as provisional(), plus 'pending_alias' => bool
     * @param array{outcome:string,chosen_id:?int,confidence:int,units_per_item:?int} $judge validated, ids resolved
     * @param int|null $keyMinConfidence Key's minimum confidence; null = this version's (KEY_MIN_CONFIDENCE). Another value
     *        only replays an older version's rule (KEY_MIN_BY_VERSION) on stored evidence.
     * @return array{band:string,reasons:list<string>}
     */
    public static function final(array $ev, array $judge, ?int $keyMinConfidence = null): array
    {
        $keyMin = $keyMinConfidence ?? self::KEY_MIN_CONFIDENCE;
        $p = self::provisional($ev);
        if ($p['band'] === self::CONFLICT) {
            return ['band' => self::CONFLICT, 'reasons' => $p['reasons']];
        }
        $out = $judge['outcome'];
        $conf = (int) $judge['confidence'];
        $chosen = $judge['chosen_id'];
        $units = $judge['units_per_item'];
        $keyLane = in_array($ev['lane'] ?? '', ['barcode', 'transfer'], true) && ($ev['target'] ?? null) !== null;
        $cands = [];
        foreach ($ev['candidates'] ?? [] as $c) {
            $cands[$c['id']] = $c;
        }
        // 1 Conflict
        if ($out === 'match' && $chosen !== null && isset($cands[$chosen]) && ($cands[$chosen]['vetoes'] ?? []) !== []) {
            return ['band' => self::CONFLICT, 'reasons' => ['ai_match_on_vetoed_pair']];
        }
        if ($keyLane && $out === 'match' && $chosen !== $ev['target'] && $conf >= 80) {
            return ['band' => self::CONFLICT, 'reasons' => ['ai_disagrees_with_key']];
        }
        if ($keyLane && $out === 'no_match_in_list' && $conf >= 80) {
            return ['band' => self::CONFLICT, 'reasons' => ['ai_no_match_on_key']];
        }
        // 2 Ignore
        if ($p['band'] === self::IGNORE || $out === 'not_a_product') {
            return ['band' => self::IGNORE, 'reasons' => ['placeholder_or_not_a_product']];
        }
        // 3 New item
        if (!$keyLane && $out === 'no_match_in_list') {
            $usable = array_filter($ev['candidates'] ?? [], fn ($c) => ($c['vetoes'] ?? []) === []);
            $best = $usable === [] ? 0 : max(array_map(fn ($c) => $c['prescore'], $usable));
            if ($conf >= 90 && $best < self::NEW_ITEM_MAX_PRESCORE && empty($ev['pending_alias'])) {
                return ['band' => self::NEW_ITEM, 'reasons' => ['ai_no_match_' . $conf]];
            }
            return ['band' => self::CANT_TELL, 'reasons' => ['no_match_fails_new_item']];
        }
        // 4 Can't tell
        if (!in_array($out, self::OUTCOMES, true)) {
            return ['band' => self::CANT_TELL, 'reasons' => ['unrecognised_outcome']];
        }
        if ($keyLane && $out === 'no_match_in_list') {
            // the judge doubts the key, below 80 (80+ is a Conflict, step 1). b2.0 had no label of its own here and fell through
            // to `unrecognised_outcome` for 70-79 (assemble.php relabelled it); run3 follow-up (e)
            return ['band' => self::CANT_TELL, 'reasons' => ['ai_no_match_on_key_below_80_' . $conf]];
        }
        $pendingAlias = !empty($ev['pending_alias'])
            || ($keyLane && array_intersect($ev['target_flags'] ?? [], self::ALIAS_PENDING_FLAGS) !== []);
        if (in_array($out, ['cannot_tell', 'multiple_plausible'], true) || $conf < 70 || $pendingAlias) {
            return ['band' => self::CANT_TELL, 'reasons' => [$out . '_' . $conf]];
        }
        // 5 Check / 6 Key
        if ($out === 'match') {
            $units1 = $units === 1;
            if ($keyLane && $chosen === $ev['target']) {
                $clean = ($ev['target_vetoes'] ?? []) === [] && ($ev['target_flags'] ?? []) === [];
                if ($conf >= $keyMin && $clean && $units1) {
                    return ['band' => self::KEY, 'reasons' => [$ev['lane'] . '_key+ai_' . $conf]];
                }
                return ['band' => self::CHECK, 'reasons' => ['key_with_flags_or_low_conf_' . $conf]];
            }
            if ($conf >= 80) {
                return ['band' => self::CHECK, 'reasons' => ['ai_only_' . $conf . ($units1 ? '' : '_units_' . $units)]];
            }
            return ['band' => self::CANT_TELL, 'reasons' => ['ai_only_low_conf_' . $conf]];
        }
        // not reached: every outcome of OUTCOMES returned above
        return ['band' => self::CANT_TELL, 'reasons' => ['unrecognised_outcome']];
    }
}
