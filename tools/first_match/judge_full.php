<?php

declare(strict_types=1);

/**
 * Section 11 of run.php: judge chunks for a full run (plan §7.3 step 6), built after pass 2 from run.php's in-memory
 * state. Not a standalone script: run.php requires it for --judge=sold, e.g.
 *
 *   nice -n 19 php -d memory_limit=2G tools/first_match/run.php --out=/root/cw_work/first_match/run3 --judge=sold
 *
 * Scope "sold" (pilot-2 evaluation): every in-scope ALT listing with units_365d > 0 that is not Ignore. Barcode and
 * transfer lanes first, then the candidates lane; inside each part by units_365d, units_30d (highest first).
 *
 * Open style exactly as pilot 2: each listing with its own top 15 search candidates (the engine's candidate list in
 * [prescore desc, id asc] order); for a barcode/transfer listing the lane target is shuffled in when it is not
 * already in the top 15, replacing the lowest-ranked candidate. A barcode/transfer Conflict with no single target
 * (a GTIN on 2 seed items) gets its barcode items the same way, and one where barcode and transfer disagree gets the
 * transfer item too, so the judge's answer can inform the mapping lead. Candidate order is shuffled. Nothing is
 * removed from a real listing's list (no duplicate or sibling filtering: the judge sees what the search found).
 *
 * 27 listings per chunk (the last chunk of each part takes the rest) + 3 canaries (1 known pair + 2 leave-one-out)
 * at random refs. Canaries are UNSOLD (units_365d = 0, so never in the main set) clean pairs with an unambiguous
 * answer, each used once, built with pilot 2's $buildItem (truth shuffled in / removed, VPG duplicates and
 * indistinguishable siblings of the truth left out). Barcode pairs are used first; the pool is topped up with
 * unsold clean transfer-lane pairs only when the barcode pairs run out (reported in the canary pool file).
 *
 * The four pending line relabels (Veto::PENDING_LINE_ALIASES) are never Key: a listing whose lane target differs
 * from it only by a pending relabel is marked relabel_pending / key_possible=false in the answer file (the business
 * maps those manually), and no canary may involve one. context.confirmed_aliases stays empty.
 *
 * Writes <out>/judge/<run>_cNNN.json (the only files a judge reads, with the prompt) and <out>/judge_manifest.json;
 * under --private (outside the run folder): <run>_cNNN.refmap.json, <run>_answers.json, <run>_canary_pool.json,
 * <run>_chunks.json (chunk list with canary refs and each chunk's scratch_dir).
 *
 * Scratch (run3 follow-up (c), docs/decisions.md M29): every chunk gets its own empty directory (mode 0700) under --scratch
 * (default <parent of --out>/judge_scratch/<run>), named in the chunk file (`scratch_dir`, with the instruction in
 * `purpose`) and in <run>_chunks.json, so the orchestrator can hand each judge its own. run3's judges, running in
 * parallel, all wrote into one session scratchpad under the same file names. The build refuses a scratch directory that
 * already holds files.
 */

use CW\Matching\Band;
use CW\Matching\Candidates;
use CW\Matching\JudgeCard;
use CW\Matching\JudgeScratch;
use CW\Matching\Text;
use CW\Matching\Veto;

if (!isset($records, $lanes, $seed, $FA, $FV, $A, $V, $buildItem, $forbiddenFor, $strengthSet, $context, $vetoCtx, $DIFF_FLAGS)) {
    fwrite(STDERR, "judge_full.php runs inside tools/first_match/run.php (--judge=sold)\n");
    exit(2);
}
$scopeName = (string) $opt['judge'];
$run = basename($out);
$PER_CHUNK = 27;
$CANARY_PER_PRODUCT = 3;
$RNG_SEED = 20260930;
$KEY_LANES = ['barcode', 'transfer'];
mt_srand($RNG_SEED);
$promptVersion = hash_file('sha256', $promptFile);
$promptRel = 'tools/first_match/prompts/' . basename($promptFile);
$genAt = gmdate('Y-m-d\TH:i:s\Z');

// ── pending relabels: the words of Veto::PENDING_LINE_ALIASES, with the context both sides must share and whether
// the brand field counts ("OXBAR" listings sit under the Vape and Go brand "OXVA"; "SKE Crystal Original" listings
// carry the brand "Crystal Bar", so only titles count there)
$RELABEL_RULES = [
    'crystal|hayati' => ['context' => ['pro', 'max'], 'brand' => true],
    'oxbar|oxva' => ['context' => [], 'brand' => true],
    'original|bar' => ['context' => ['crystal'], 'brand' => false],
    'echo|eco' => ['context' => ['bash'], 'brand' => true],
];
$relabels = [];
foreach (Veto::PENDING_LINE_ALIASES as $al) {
    $k = implode(' ', $al['a']) . '|' . implode(' ', $al['b']);
    if (!isset($RELABEL_RULES[$k])) {
        fwrite(STDERR, "pending alias $k has no relabel rule in judge_full.php\n");
        exit(2);
    }
    $relabels[] = ['a' => $al['a'], 'b' => $al['b'], 'note' => $al['note']] + $RELABEL_RULES[$k];
}
$words = function (array $f, bool $brand): array {
    $w = Text::tokens((string) ($f['title'] ?? '') . ' ' . (string) ($f['product_title'] ?? ''));
    if ($brand) {
        $w = array_merge($w, Text::tokens((string) ($f['brand_raw'] ?? '')));
    }
    return array_values(array_unique(array_map('strval', $w)));
};
/** the pending relabel note when one side names the a-words (without the b-words) and the other names the b-words */
$relabel = function (array $x, array $y) use ($relabels, $words): ?string {
    foreach ($relabels as $al) {
        foreach ([[$x, $y], [$y, $x]] as [$p, $q]) {
            $wp = $words($p, $al['brand']);
            $wq = $words($q, $al['brand']);
            if (array_diff($al['a'], $wp) === [] && array_intersect($al['b'], $wp) === [] && array_diff($al['b'], $wq) === []
                && array_diff($al['context'], $wp) === [] && array_diff($al['context'], $wq) === []) {
                return $al['note'];
            }
        }
    }
    return null;
};

// ── main set
$byUnits = fn (int $a, int $b) => [$records[$b]['units_365d'], $records[$b]['units_30d'], $a] <=> [$records[$a]['units_365d'], $records[$a]['units_30d'], $b];
$mainBT = [];
$mainC = [];
foreach ($records as $id => $r) {
    if ($r['units_365d'] <= 0 || $r['lane'] === 'ignore' || $r['band'] === Band::IGNORE) {
        continue;
    }
    if (in_array($r['lane'], $KEY_LANES, true)) {
        $mainBT[] = $id;
    } else {
        $mainC[] = $id;
    }
}
usort($mainBT, $byUnits);
usort($mainC, $byUnits);
$plan = [];
foreach ([['barcode_transfer', $mainBT], ['candidates', $mainC]] as [$part, $ids]) {
    foreach (array_chunk($ids, $PER_CHUNK) as $slice) {
        $plan[] = ['lane' => $part, 'ids' => $slice];
    }
}
$nChunks = count($plan);
logmsg(sprintf('judge %s: %d barcode+transfer + %d candidates listings -> %d chunks', $scopeName, count($mainBT), count($mainC), $nChunks));
$chunkName = fn (int $k): string => sprintf('%s_c%03d', $run, $k + 1);
// one scratch directory per judge, made before any chunk is written (refuses one that already holds files)
$scratchRoot = rtrim((string) ($opt['scratch'] ?? JudgeScratch::defaultRoot($out)), '/');
$scratchDirs = JudgeScratch::prepare($scratchRoot, array_map($chunkName, array_keys($plan)), [$out, $private]);
logmsg("scratch: $nChunks directories under $scratchRoot");

// ── one real listing: its search list (top 15) with the lane evidence shuffled in
$stats = ['lane_target_in_top15' => 0, 'lane_target_shuffled_in' => 0, 'barcode_items_shuffled_in' => 0, 'transfer_items_shuffled_in' => 0,
    'no_single_target' => 0, 'short_lists' => 0, 'relabel_pending_key_lane' => 0, 'relabel_partner_listings' => 0, 'key_possible' => 0];
$mainItem = function (int $id) use ($records, $lanes, $seed, $FA, $vetoCtx, $KEY_LANES, &$stats): array {
    $r = $records[$id];
    $L = $lanes[$id];
    $cands = $r['candidates'];
    usort($cands, fn ($a, $b) => [$b['prescore'], $a['vpg_variant_id']] <=> [$a['prescore'], $b['vpg_variant_id']]);
    $rank = [];
    foreach ($cands as $i => $c) {
        $rank[(int) $c['vpg_variant_id']] = $i + 1;
    }
    $byVid = array_column($cands, null, 'vpg_variant_id');
    $tgt = $r['target_vpg_variant_id'] !== null ? (int) $r['target_vpg_variant_id'] : null;
    $evidence = [];
    if (in_array($r['lane'], $KEY_LANES, true)) {
        if ($tgt !== null) {
            $evidence[$tgt] = 'lane_target';
        }
        if (array_intersect($r['flags'], ['multi_sku_gtin', 'gtin_on_multiple_items']) !== []) {
            foreach ($L['barcode']['seed_hits'] as $v) {
                $evidence[(int) $v] ??= 'barcode_item';
            }
        }
        if ($L['transfer_target'] !== null) {
            $evidence[(int) $L['transfer_target']] ??= 'transfer_item';
        }
        if ($tgt === null) {
            $stats['no_single_target']++;
        }
    }
    $entry = function (int $vid, string $role) use ($byVid, $rank, $r, $tgt, $FA, $seed, $id, $vetoCtx): array {
        if ($vid === $tgt) {
            $vet = array_values(array_unique(array_column($r['target_vetoes'], 'code')));
            $soft = $r['target_soft_flags'];
        } elseif (isset($byVid[$vid])) {
            $vet = $byVid[$vid]['vetoes'];
            $soft = $byVid[$vid]['soft_flags'];
        } else {
            $chk = Veto::check($FA[$id], $seed[$vid], 1, $vetoCtx($vid));
            $vet = array_values(array_unique(array_column($chk['vetoes'], 'code')));
            $soft = $chk['flags'];
        }
        return ['vpg_variant_id' => $vid, 'role' => $role, 'prescore' => $byVid[$vid]['prescore'] ?? null, 'search_rank' => $rank[$vid] ?? null,
            'vetoes' => $vet, 'soft' => $soft];
    };
    $set = [];
    foreach (array_slice($cands, 0, Candidates::TOP_K) as $c) {
        $vid = (int) $c['vpg_variant_id'];
        $set[] = $entry($vid, $evidence[$vid] ?? 'search');
    }
    $inSet = array_flip(array_column($set, 'vpg_variant_id'));
    $shuffledIn = [];
    foreach ($evidence as $vid => $role) {
        if (isset($inSet[$vid])) {
            if ($role === 'lane_target') {
                $stats['lane_target_in_top15']++;
            }
            continue;
        }
        if (count($set) >= Candidates::TOP_K) {
            for ($j = count($set) - 1; $j >= 0; $j--) {
                if ($set[$j]['role'] === 'search') {
                    array_splice($set, $j, 1);
                    break;
                }
            }
        }
        $set[] = $entry($vid, $role);
        $shuffledIn[] = $vid;
        $stats[$role === 'lane_target' ? 'lane_target_shuffled_in' : ($role === 'barcode_item' ? 'barcode_items_shuffled_in' : 'transfer_items_shuffled_in')]++;
    }
    if (count($set) < Candidates::TOP_K) {
        $stats['short_lists']++;
    }
    shuffle($set);
    return ['id' => $id, 'kind' => 'main', 'set' => $set, 'target' => $tgt, 'evidence' => $evidence, 'shuffled_in' => $shuffledIn];
};

// ── canary pool: unsold clean pairs with an unambiguous answer
$answerFor = function (int $id, int $tgt, bool $loo) use ($records, $FA, $seed, $strengthSet, $DIFF_FLAGS): array {
    // pilot 2's answer-key rules (strength-missing rule both ways, pending alias, text-difference flags, one-sided N in 1)
    $rec = $records[$id];
    $multiStrength = count($strengthSet($tgt)) >= 2;
    $strengthMissing = $multiStrength && ($FA[$id]['strength_mg'] === null || $seed[$tgt]['strength_mg'] === null);
    $aliasPending = array_intersect($rec['target_soft_flags'], Band::ALIAS_PENDING_FLAGS) !== [];
    $textDiff = array_values(array_intersect($rec['target_soft_flags'], $DIFF_FLAGS));
    $lf = $FA[$id];
    $tf = $seed[$tgt];
    $nIn1OneSide = ($lf['multi_n'] === null) !== ($tf['multi_n'] === null);
    $otherPack = $lf['multi_n'] === null ? (int) ($lf['pack_units'] ?? 0) : (int) ($tf['pack_units'] ?? 0);
    if ($loo) {
        $expected = 'no_match_in_list';
        $acceptable = ['no_match_in_list', 'cannot_tell'];
    } elseif ($strengthMissing || $aliasPending) {
        $expected = 'cannot_tell';
        $acceptable = ['cannot_tell'];
    } elseif ($nIn1OneSide) {
        $expected = $otherPack >= 2 ? 'match' : 'cannot_tell';
        $acceptable = ['match', 'cannot_tell'];
        $textDiff[] = 'n_in_1_one_side';
    } elseif ($textDiff !== []) {
        $expected = 'match';
        $acceptable = ['match', 'cannot_tell'];
    } else {
        $expected = 'match';
        $acceptable = ['match'];
    }
    return ['expected' => $expected, 'acceptable' => $acceptable, 'text_diff' => $textDiff, 'strength_missing' => $strengthMissing, 'alias_pending' => $aliasPending];
};
$poolRows = [];
$poolBy = ['barcode' => [], 'transfer' => []];
$poolExcluded = [];
foreach ($records as $id => $r) {
    if ($r['units_365d'] > 0 || !in_array($r['lane'], $KEY_LANES, true) || $r['target_vpg_variant_id'] === null) {
        continue;
    }
    $tgt = (int) $r['target_vpg_variant_id'];
    $l = $FA[$id];
    $t = $seed[$tgt];
    $why = null;
    if (array_diff(array_keys($lanes[$id]['flags']), ['transfer_agrees']) !== []) {
        $why = 'lane_flags';
    } elseif ($r['target_vetoes'] !== []) {
        $why = 'veto_on_pair';
    } elseif ($r['target_soft_flags'] !== []) {
        $why = 'soft_flag_on_pair';
    } elseif (!(Veto::consumable($t) && $l['strength_mg'] !== null && $t['strength_mg'] !== null && $l['flavour_tokens'] !== null && $t['flavour_tokens'] !== null)) {
        $why = 'not_a_stated_strength_and_flavour_consumable';
    } elseif (($l['multi_n'] === null) !== ($t['multi_n'] === null)) {
        $why = 'n_in_1_one_side';
    } elseif ($relabel($l, $t) !== null) {
        $why = 'pending_relabel';
    } elseif ($answerFor($id, $tgt, false)['acceptable'] !== ['match']) {
        $why = 'answer_not_unambiguous';
    }
    if ($why !== null) {
        $poolExcluded[$r['lane'] . ':' . $why] = ($poolExcluded[$r['lane'] . ':' . $why] ?? 0) + 1;
        continue;
    }
    $poolBy[$r['lane']][] = $id;
    $poolRows[$id] = ['alt_variant_id' => $id, 'title' => $l['title'], 'variant_status' => $r['variant_status'], 'units_365d' => $r['units_365d'],
        'source' => $r['lane'] . '_pair', 'truth' => ['vpg_variant_id' => $tgt, 'cw_id' => cwId($tgt), 'title' => $t['title']], 'assigned' => null];
}
ksort($poolExcluded);
foreach ($poolBy as $k => $ids) {
    sort($ids);
    shuffle($ids);
    $poolBy[$k] = $ids;
}
logmsg(sprintf('canary pool: %d unsold clean barcode pairs, %d unsold clean transfer pairs; need %d pair + %d loo',
    count($poolBy['barcode']), count($poolBy['transfer']), $nChunks, 2 * $nChunks));

$usedCanary = [];
$usedCanaryTarget = [];
$canaryPerProduct = [];
$skipped = [];
$take = function (array $ids, int $want, bool $loo, bool $capped) use ($CANARY_PER_PRODUCT, &$usedCanary, &$usedCanaryTarget, &$canaryPerProduct, &$skipped, &$poolRows, $buildItem, $records, $seed, $FA, $relabel, $answerFor): array {
    $out = [];
    foreach ($ids as $id) {
        if (count($out) >= $want) {
            break;
        }
        $tgt = (int) $records[$id]['target_vpg_variant_id'];
        $pid = (int) $seed[$tgt]['product_id'];
        if (isset($usedCanary[$id]) || isset($usedCanaryTarget[$tgt]) || ($capped && ($canaryPerProduct[$pid] ?? 0) >= $CANARY_PER_PRODUCT)) {
            continue;
        }
        $it = $buildItem($id, $loo, null);
        if ($it === null) {
            $skipped[$id] = 'too_few_negatives';
            continue;
        }
        foreach ($it['set'] as $c) {
            if ($c['role'] === 'negative' && $relabel($FA[$id], $seed[$c['vpg_variant_id']]) !== null) {
                $skipped[$id] = 'pending_relabel_among_negatives';
                continue 2;
            }
        }
        $it['answer'] = $answerFor($id, $tgt, $loo);
        $it['source'] = $poolRows[$id]['source'];
        $out[] = $it;
        $usedCanary[$id] = true;
        $usedCanaryTarget[$tgt] = true;
        $canaryPerProduct[$pid] = ($canaryPerProduct[$pid] ?? 0) + 1;
    }
    return $out;
};
$canPairs = $take($poolBy['barcode'], $nChunks, false, false);
if (count($canPairs) < $nChunks) {
    $canPairs = array_merge($canPairs, $take($poolBy['transfer'], $nChunks - count($canPairs), false, true));
}
$canLoo = $take($poolBy['barcode'], 2 * $nChunks, true, false);
if (count($canLoo) < 2 * $nChunks) {
    $canLoo = array_merge($canLoo, $take($poolBy['transfer'], 2 * $nChunks - count($canLoo), true, true));
}
if (count($canPairs) < $nChunks || count($canLoo) < 2 * $nChunks) {
    fwrite(STDERR, sprintf("canary pool too small: %d/%d pairs, %d/%d loo; skipped %s\n", count($canPairs), $nChunks, count($canLoo), 2 * $nChunks, json_encode(array_count_values($skipped))));
    exit(3);
}
$canarySources = ['pair' => array_count_values(array_column($canPairs, 'source')), 'loo' => array_count_values(array_column($canLoo, 'source'))];
logmsg('canaries: ' . json_encode($canarySources));

// ── chunks
$answers = ['run' => $run, 'scope' => $scopeName, 'prompt_version' => $promptVersion, 'prompt' => $promptRel, 'engine' => $engine,
    'rng_seed' => $RNG_SEED, 'generated_at_utc' => $genAt,
    'design' => 'full run, sold scope: open style (top 15 search candidates, lane evidence shuffled in), 27 listings + 1 pair and 2 leave-one-out canaries per chunk at random refs',
    'key_rule' => [
        'Key only when the lane is barcode/transfer, key_possible is true, and the judge matches lane_target_ref with confidence >= ' . Band::KEY_MIN_CONFIDENCE
            . ' and units_per_item 1 (Band::final ' . Band::VERSION . '); every other match is at most Check; a person confirms every link',
        'relabel_pending (Crystal Pro Max/Hayati Pro Max, Oxbar/Oxva, SKE Crystal Original/Crystal Bar, Bash Echo/Eco) is never Key: the business maps those listings manually',
        'candidates lane: at most Check (no second signal)',
    ],
    'scoring' => ['canary_pair' => 'correct = outcome in acceptable_outcomes (match on expected_ref); a match on any other ref = wrong-ref: stop the wave',
        'canary_loo' => 'any match = would-be false merge: stop the wave; no_match_in_list or cannot_tell = safe',
        'main' => 'no answer key: lane_target_ref is deterministic evidence (barcode or transfer), not ground truth'],
    'chunks' => []];
$chunkList = [];
$manifest = [];
$blindChecked = 0;
$forbidTotal = 0;
foreach ($plan as $k => $p) {
    $name = $chunkName($k);
    $items = [];
    foreach ($p['ids'] as $id) {
        $items[] = $mainItem($id);
    }
    $items[] = $canPairs[$k] + ['kind' => 'canary_pair'];
    $items[] = $canLoo[2 * $k] + ['kind' => 'canary_loo'];
    $items[] = $canLoo[2 * $k + 1] + ['kind' => 'canary_loo'];
    shuffle($items);

    $cards = [];
    $refmap = [];
    $ans = [];
    $forbid = [];
    $canaryOut = [];
    foreach ($items as $li => $it) {
        $L = 'L' . ($li + 1);
        $id = $it['id'];
        $rec = $records[$id];
        $cs = [];
        $map = [];
        $refOf = [];
        $vetoed = [];
        $soft = [];
        foreach ($it['set'] as $i => $c) {
            $ref = 'C' . ($i + 1);
            $vid = (int) $c['vpg_variant_id'];
            $cs[] = JudgeCard::card($ref, $V[$vid], $FV[$vid], $strengthSet($vid));
            $map[$ref] = ['cw_id' => cwId($vid), 'vpg_variant_id' => $vid, 'role' => $c['role'], 'prescore' => $c['prescore']]
                + ($it['kind'] === 'main' ? ['search_rank' => $c['search_rank']] : []);
            $refOf[$vid] = $ref;
            if ($c['vetoes'] !== []) {
                $vetoed[$ref] = $c['vetoes'];
            }
            if ($c['soft'] !== []) {
                $soft[$ref] = $c['soft'];
            }
        }
        if (count($refOf) !== count($it['set'])) {
            throw new RuntimeException("$name $L: a candidate is listed twice");
        }
        $cards[] = ['ref' => $L, 'listing' => JudgeCard::card($L, $A[$id], $FA[$id]), 'candidates' => $cs];
        $listingInfo = ['alt_variant_id' => $id, 'title' => $FA[$id]['title'], 'units_365d' => $rec['units_365d'], 'units_30d' => $rec['units_30d']];
        $shownIds = array_map(fn ($c) => (int) $c['vpg_variant_id'], $it['set']);

        if ($it['kind'] === 'main') {
            $tgt = $it['target'];
            $rl = $tgt !== null ? $relabel($FA[$id], $seed[$tgt]) : null;
            $aliasFlag = array_values(array_intersect($rec['target_soft_flags'], Band::ALIAS_PENDING_FLAGS));
            $relabelNote = $rl ?? ($aliasFlag !== [] ? 'alias flag: ' . implode(',', $aliasFlag) : null);
            $partners = [];
            foreach ($it['set'] as $c) {
                $note = $relabel($FA[$id], $seed[(int) $c['vpg_variant_id']]);
                if ($note !== null) {
                    $partners[$refOf[(int) $c['vpg_variant_id']]] = $note;
                }
            }
            $keyLane = in_array($rec['lane'], $KEY_LANES, true);
            $blocked = [];
            if (!$keyLane) {
                $blocked[] = 'candidates_lane';
            } else {
                if ($tgt === null) {
                    $blocked[] = 'no_single_target';
                }
                if ($rec['band_ceiling'] !== Band::KEY) {
                    $blocked[] = 'ceiling:' . $rec['band_ceiling'];
                }
                if ($relabelNote !== null) {
                    $blocked[] = 'relabel_pending';
                }
            }
            $keyPossible = $blocked === [];
            $stats['key_possible'] += $keyPossible ? 1 : 0;
            if ($keyLane && $relabelNote !== null) {
                $stats['relabel_pending_key_lane']++;
            }
            if ($partners !== []) {
                $stats['relabel_partner_listings']++;
            }
            $evRefs = [];
            foreach ($it['evidence'] as $vid => $role) {
                if ($role !== 'lane_target') {
                    $evRefs[$refOf[$vid]] = $role;
                }
            }
            if ($tgt !== null && !isset($refOf[$tgt])) {
                throw new RuntimeException("$name $L: lane target not shown");
            }
            $ans[$L] = [
                'kind' => 'main', 'lane' => $rec['lane'], 'band_ceiling' => $rec['band_ceiling'], 'provisional_band' => $rec['band'],
                'band_reasons' => $rec['band_reasons'], 'lane_flags' => $rec['flags'],
                'lane_target_ref' => $tgt !== null ? $refOf[$tgt] : null, 'lane_evidence_refs' => (object) $evRefs,
                'lane_evidence_shuffled_in' => array_map(fn ($v) => $refOf[$v], $it['shuffled_in']),
                'key_possible' => $keyPossible, 'key_blocked_by' => $blocked,
                'relabel_pending' => $relabelNote, 'relabel_partner_refs' => (object) $partners,
                'vetoed_refs' => (object) $vetoed, 'soft_flagged_refs' => (object) $soft,
                'listing' => $listingInfo,
                'target' => $tgt === null ? null : ['vpg_variant_id' => $tgt, 'cw_id' => cwId($tgt), 'title' => $seed[$tgt]['title'],
                    'target_vetoes' => array_values(array_unique(array_column($rec['target_vetoes'], 'code'))), 'target_soft_flags' => $rec['target_soft_flags'],
                    'product_strengths_mg' => $strengthSet($tgt)],
            ];
            $refmap[$L] = ['listing' => ['site' => 'electrofag', 'variant_id' => $id], 'kind' => 'main', 'candidates' => $map];
            $forbid = array_merge($forbid, $forbiddenFor([$id], $A), $forbiddenFor($shownIds, $V));
            continue;
        }

        // canary
        $tgt = (int) $it['target'];
        $loo = $it['kind'] === 'canary_loo';
        $expRef = $loo ? null : $refOf[$tgt];
        if ($loo === isset($refOf[$tgt])) {
            throw new RuntimeException("$name $L: canary truth placement is wrong");
        }
        $a = $it['answer'];
        $ans[$L] = [
            'kind' => $loo ? 'loo' : 'pair', 'role' => 'canary', 'source' => $it['source'],
            'expected_outcome' => $a['expected'], 'acceptable_outcomes' => $a['acceptable'], 'expected_ref' => $expRef, 'expected_closest_ref' => null,
            'indistinguishable_from_truth_excluded' => array_map(fn ($v) => ['vpg_variant_id' => $v, 'title' => $seed[$v]['title']], $it['indistinguishable_excluded']),
            'negatives_unseparated_from_truth_refs' => array_values(array_map(fn ($c) => $refOf[(int) $c['vpg_variant_id']], array_filter($it['set'], fn ($c) => $c['unseparated']))),
            'vetoed_refs' => (object) $vetoed, 'soft_flagged_refs' => (object) $soft,
            'listing' => $listingInfo,
            'truth' => ['vpg_variant_id' => $tgt, 'cw_id' => cwId($tgt), 'title' => $seed[$tgt]['title'], 'shown' => !$loo,
                'target_soft_flags' => $rec['target_soft_flags'], 'strength_missing_rule' => $a['strength_missing'], 'alias_pending' => $a['alias_pending'],
                'text_difference_flags' => $a['text_diff'], 'product_strengths_mg' => $strengthSet($tgt)],
            'note' => 'unsold canary; truth from a clean ' . ($it['source'] === 'barcode_pair' ? 'barcode' : 'transfer (db-transfer permalink)') . ' pair (strong evidence, not ground truth)',
        ];
        $refmap[$L] = ['listing' => ['site' => 'electrofag', 'variant_id' => $id], 'kind' => $loo ? 'canary_loo' : 'canary_pair', 'candidates' => $map]
            + ($loo ? ['removed_true_target' => ['cw_id' => cwId($tgt), 'vpg_variant_id' => $tgt]] : []);
        $canaryOut[] = ['ref' => $L, 'kind' => $loo ? 'loo' : 'pair', 'expected_ref' => $expRef];
        $poolRows[$id]['assigned'] = ['chunk' => $name, 'ref' => $L, 'kind' => $loo ? 'loo' : 'pair', 'expected_ref' => $expRef];
        $forbid = array_merge($forbid, $forbiddenFor([$id], $A), $forbiddenFor(array_merge([$tgt], $shownIds), $V));
    }
    $forbid = array_values(array_unique($forbid));
    $forbidTotal += count($forbid);

    $payload = [
        'chunk' => $name,
        'prompt_version' => $promptVersion,
        'instructions' => $promptRel,
        'purpose' => 'Judge each listing against its own candidates only, following the instructions file. ' . JudgeScratch::INSTRUCTION,
        'scratch_dir' => $scratchDirs[$name],
        'context' => $context,
        'items' => $cards,
    ];
    // as pilot 2: the cards must be blind; the head is fixed text of this tool (the prompt sha256 may hold 8 digits in a row)
    JudgeCard::assertBlind(['items' => $cards], $forbid);
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $head = $payload;
    unset($head['items']);
    $lines = array_map(fn ($x) => json_encode($x, $flags), $cards);
    $json = substr((string) json_encode($head, $flags), 0, -1) . ',"items":[' . "\n" . implode(",\n", $lines) . "\n]}\n";
    $file = "$out/judge/$name.json";
    file_put_contents($file, $json);
    // re-read what was written and check it again
    $back = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    JudgeCard::assertBlind(['items' => $back['items']], $forbid);
    if ($back['prompt_version'] !== $promptVersion || count($back['items']) !== count($items)) {
        throw new RuntimeException("$name: re-read check failed");
    }
    $blindChecked++;

    file_put_contents("$private/$name.refmap.json", json_encode(['chunk' => $name, 'prompt_version' => $promptVersion, 'refs' => $refmap],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $answers['chunks'][$name] = ['lane' => $p['lane'], 'n_listings' => count($p['ids']), 'items' => $ans];
    $chunkList[] = ['path' => $file, 'scratch_dir' => $scratchDirs[$name], 'n_listings' => count($p['ids']), 'lane' => $p['lane'], 'canaries' => $canaryOut];
    $manifest[] = ['chunk' => $name, 'file' => $file, 'lane' => $p['lane'], 'items' => count($items), 'bytes' => strlen($json), 'sha256' => hash('sha256', $json)];
    if (($k + 1) % 10 === 0) {
        logmsg('chunks written: ' . ($k + 1));
    }
}

// ── private files
file_put_contents("$private/{$run}_answers.json", json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$poolFile = [
    'run' => $run, 'generated_at_utc' => $genAt, 'rng_seed' => $RNG_SEED,
    'criteria' => 'in-scope ALT listing with units_365d = 0 (never in the sold main set), barcode or transfer lane, one seed target, no lane flag other than transfer_agrees, '
        . 'no veto and no soft flag on the pair, a consumable with strength and flavour stated on both sides, no one-sided "N in 1", no pending relabel on the pair; '
        . 'answer key must be match-only. Chosen in seeded random order, each listing and each truth item once, every usable barcode pair first (pair canaries, then leave-one-out), then transfer pairs '
        . 'with at most ' . $CANARY_PER_PRODUCT . ' canaries per Vape and Go product (barcode canaries count towards it), '
        . 'none whose shown negatives include a pending-relabel partner; transfer pairs are used only because the unsold barcode pairs run out.',
    'needed' => ['pair' => $nChunks, 'loo' => 2 * $nChunks],
    'eligible' => ['barcode_pair' => count($poolBy['barcode']), 'transfer_pair' => count($poolBy['transfer'])],
    'excluded_unsold_key_lane' => $poolExcluded,
    'skipped_at_build' => array_count_values($skipped),
    'used' => $canarySources,
    'members' => array_values($poolRows),
];
file_put_contents("$private/{$run}_canary_pool.json", json_encode($poolFile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$totals = [
    'listings' => count($mainBT) + count($mainC), 'chunks' => $nChunks, 'barcode_transfer' => count($mainBT), 'candidates' => count($mainC),
    'barcode_transfer_chunks' => count(array_filter($plan, fn ($p) => $p['lane'] === 'barcode_transfer')),
    'candidates_chunks' => count(array_filter($plan, fn ($p) => $p['lane'] === 'candidates')),
    'listings_per_chunk' => $PER_CHUNK, 'canaries_per_chunk' => ['pair' => 1, 'loo' => 2],
    'canaries' => ['pair' => count($canPairs), 'loo' => count($canLoo), 'sources' => $canarySources,
        'eligible_pool' => ['unsold_clean_barcode_pairs' => count($poolBy['barcode']), 'unsold_clean_transfer_pairs' => count($poolBy['transfer'])]],
    'search_lists' => $stats,
    'units_365d' => ['barcode_transfer' => array_sum(array_map(fn ($i) => $records[$i]['units_365d'], $mainBT)),
        'candidates' => array_sum(array_map(fn ($i) => $records[$i]['units_365d'], $mainC))],
    'assert_blind' => "passed on $blindChecked/$nChunks chunks (before and after writing; $forbidTotal forbidden barcode/permalink values)",
    'prompt_sha256' => $promptVersion, 'engine' => $engine, 'rng_seed' => $RNG_SEED,
];
$orchestrator = ['chunks' => $chunkList, 'totals' => $totals, 'private_dir' => $private, 'scratch_root' => $scratchRoot,
    'judge_task' => 'give each judge its chunk path, the prompt, and its own scratch_dir (judges run in parallel; never a shared directory)'];
file_put_contents("$private/{$run}_chunks.json", json_encode($orchestrator, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// ── run folder: manifest and summary (no refs, ids or canary positions)
file_put_contents("$out/judge_manifest.json", json_encode(['run' => $run, 'scope' => $scopeName, 'prompt' => $promptRel, 'prompt_sha256' => $promptVersion,
    'engine' => $engine, 'inputs' => $summary['run']['inputs'] ?? null, 'generated_at_utc' => $genAt, 'chunks' => $manifest],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$summary['judge_' . $run] = ['scope' => $scopeName, 'prompt' => $promptRel, 'prompt_sha256' => $promptVersion, 'manifest' => "$out/judge_manifest.json",
    'totals' => array_diff_key($totals, ['assert_blind' => 1]) + ['assert_blind' => 'passed'],
    'private' => "$private (refmaps, {$run}_answers.json, {$run}_canary_pool.json, {$run}_chunks.json; outside the run folder; judges must never read)"];
$summary['run']['seconds'] = round(microtime(true) - $t0, 1);
file_put_contents("$out/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
logmsg('done');
echo json_encode(['chunks_file' => "$private/{$run}_chunks.json", 'chunks' => $nChunks, 'totals' => $totals], JSON_UNESCAPED_SLASHES) . "\n";
