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
    public const VERSION = 'b1.0';

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
     * @param array<string,mixed> $ev as provisional(), plus 'pending_alias' => bool
     * @param array{outcome:string,chosen_id:?int,confidence:int,units_per_item:?int} $judge validated, ids resolved
     * @return array{band:string,reasons:list<string>}
     */
    public static function final(array $ev, array $judge): array
    {
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
        if (in_array($out, ['cannot_tell', 'multiple_plausible'], true) || $conf < 70 || !empty($ev['pending_alias'])) {
            return ['band' => self::CANT_TELL, 'reasons' => [$out . '_' . $conf]];
        }
        // 5 Check / 6 Key
        if ($out === 'match') {
            $units1 = $units === 1;
            if ($keyLane && $chosen === $ev['target']) {
                $clean = ($ev['target_vetoes'] ?? []) === [] && ($ev['target_flags'] ?? []) === [];
                if ($conf >= 85 && $clean && $units1) {
                    return ['band' => self::KEY, 'reasons' => [$ev['lane'] . '_key+ai_' . $conf]];
                }
                return ['band' => self::CHECK, 'reasons' => ['key_with_flags_or_low_conf_' . $conf]];
            }
            if ($conf >= 80) {
                return ['band' => self::CHECK, 'reasons' => ['ai_only_' . $conf . ($units1 ? '' : '_units_' . $units)]];
            }
            return ['band' => self::CANT_TELL, 'reasons' => ['ai_only_low_conf_' . $conf]];
        }
        return ['band' => self::CANT_TELL, 'reasons' => ['unrecognised_outcome']];
    }
}
