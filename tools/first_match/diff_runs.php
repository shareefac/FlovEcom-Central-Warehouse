<?php

declare(strict_types=1);

/**
 * Compares two deterministic first-match runs (tools/first_match/run.php outputs) listing by listing.
 *
 *   nice -n 19 php -d memory_limit=2G tools/first_match/diff_runs.php --base=<run1 dir> --new=<run2 dir> [--out=<json>]
 *
 * Writes <new>/diff_vs_<basename(base)>.json (or --out): counts per lane and band (and the band transition
 * matrix), barcode-pair veto rate before/after with the pairs whose vetoes changed, candidate recall, candidate
 * veto rates, flavour separation on the in-scope Electrofag listings, and the new run's line_word veto impact and
 * pilot-1 recheck. Reads run files only: no database, no network, no exports.
 */

ini_set('memory_limit', '2G');
$opt = getopt('', ['base:', 'new:', 'out:']);
$base = rtrim($opt['base'] ?? '/root/cw_work/first_match/run1', '/');
$new = rtrim($opt['new'] ?? '/root/cw_work/first_match/run2', '/');
$outFile = $opt['out'] ?? "$new/diff_vs_" . basename($base) . '.json';
foreach ([$base, $new] as $d) {
    foreach (['summary.json', 'alt_deterministic.jsonl', 'listings_features.jsonl'] as $f) {
        if (!is_file("$d/$f")) {
            fwrite(STDERR, "missing $d/$f\n");
            exit(2);
        }
    }
}

/** @return array<int, array<string,mixed>> */
function records(string $file): array
{
    $out = [];
    $fh = fopen($file, 'r');
    while (($l = fgets($fh)) !== false) {
        $r = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
        unset($r['barcode'], $r['transfer'], $r['target_fields']);
        $out[(int) $r['alt_variant_id']] = $r;
    }
    fclose($fh);
    return $out;
}

/** Electrofag features only (flavour and form fields). @return array<int, array<string,mixed>> */
function altFeatures(string $file): array
{
    $out = [];
    $fh = fopen($file, 'r');
    while (($l = fgets($fh)) !== false) {
        if (!str_contains(substr($l, 0, 40), '"electrofag"')) {
            continue;
        }
        $f = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
        $out[(int) $f['variant_id']] = ['form_class' => $f['form_class'] ?? null, 'flavour_tokens' => $f['flavour_tokens'] ?? null,
            'flavour_src' => $f['flavour_src'] ?? null];
    }
    fclose($fh);
    return $out;
}

$S1 = json_decode((string) file_get_contents("$base/summary.json"), true, 512, JSON_THROW_ON_ERROR);
$S2 = json_decode((string) file_get_contents("$new/summary.json"), true, 512, JSON_THROW_ON_ERROR);
$R1 = records("$base/alt_deterministic.jsonl");
$R2 = records("$new/alt_deterministic.jsonl");
$F1 = altFeatures("$base/listings_features.jsonl");
$F2 = altFeatures("$new/listings_features.jsonl");

$delta = function (array $a, array $b): array {
    $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
    sort($keys);
    $out = [];
    foreach ($keys as $k) {
        $x = $a[$k] ?? 0;
        $y = $b[$k] ?? 0;
        $out[$k] = ['before' => $x, 'after' => $y, 'delta' => $y - $x];
    }
    return $out;
};
$bandKey = fn (array $r): string => $r['band'] ?? ('pending_ai:' . $r['band_ceiling']);

// lanes and bands, per listing
$lane1 = [];
$lane2 = [];
$band1 = [];
$band2 = [];
$trans = [];
$laneChanged = 0;
$units = [];
$transList = [];
foreach ($R1 as $id => $a) {
    $b = $R2[$id] ?? null;
    $lane1[$a['lane']] = ($lane1[$a['lane']] ?? 0) + 1;
    $band1[$bandKey($a)] = ($band1[$bandKey($a)] ?? 0) + 1;
    $units[$bandKey($a)]['units_365d_before'] = ($units[$bandKey($a)]['units_365d_before'] ?? 0) + (int) $a['units_365d'];
    if ($b === null) {
        continue;
    }
    if ($a['lane'] !== $b['lane']) {
        $laneChanged++;
    }
    $k = $bandKey($a) . ' -> ' . $bandKey($b);
    $trans[$k] = ($trans[$k] ?? 0) + 1;
    if ($bandKey($a) !== $bandKey($b) && count($transList[$k] ?? []) < 40) {
        $transList[$k][] = ['alt_variant_id' => $id, 'title' => $a['title'], 'lane' => $b['lane'], 'target_title' => $b['target_title'],
            'units_365d' => (int) $b['units_365d'], 'reasons_after' => $b['band_reasons']];
    }
}
foreach ($R2 as $b) {
    $lane2[$b['lane']] = ($lane2[$b['lane']] ?? 0) + 1;
    $band2[$bandKey($b)] = ($band2[$bandKey($b)] ?? 0) + 1;
    $units[$bandKey($b)]['units_365d_after'] = ($units[$bandKey($b)]['units_365d_after'] ?? 0) + (int) $b['units_365d'];
}
arsort($trans);
ksort($units);

// barcode pairs: same definition as run.php (barcode lane, one seed target, no lane flag except transfer_agrees)
$pairVetoChanges = [];
$pairs = 0;
foreach ($R2 as $id => $b) {
    $a = $R1[$id] ?? null;
    if ($a === null || $b['lane'] !== 'barcode' || $b['target_vpg_variant_id'] === null) {
        continue;
    }
    if (array_diff($b['flags'], ['transfer_agrees']) !== []) {
        continue;
    }
    $pairs++;
    $ca = array_values(array_unique(array_column($a['target_vetoes'], 'code')));
    $cb = array_values(array_unique(array_column($b['target_vetoes'], 'code')));
    sort($ca);
    sort($cb);
    if ($ca !== $cb) {
        $pairVetoChanges[] = ['alt_variant_id' => $id, 'alt_title' => $b['title'], 'vpg_title' => $b['target_title'],
            'before' => $ca, 'after' => $cb, 'detail_after' => array_column($b['target_vetoes'], 'detail'), 'units_365d' => (int) $b['units_365d']];
    }
}
$bq = fn (array $s) => $s['barcode_pair_quality'] ?? [];
$recallKeys = ['n', 'top1', 'top5', 'top15', 'recall_top1', 'recall_top5', 'recall_top15'];
$recall = [];
foreach ($recallKeys as $k) {
    $recall[$k] = ['before' => $S1['candidate_recall_on_barcode_pairs'][$k] ?? null, 'after' => $S2['candidate_recall_on_barcode_pairs'][$k] ?? null];
}

// flavour separation on in-scope, non-ignored Electrofag listings (same scope in both runs)
$flav = function (array $R, array $F): array {
    $out = ['n' => 0, 'no_flavour' => 0, 'by_form_class' => []];
    foreach ($R as $id => $r) {
        if ($r['lane'] === 'ignore' || !isset($F[$id])) {
            continue;
        }
        $fc = $F[$id]['form_class'] ?? 'unknown';
        $none = $F[$id]['flavour_tokens'] === null;
        $out['n']++;
        $out['no_flavour'] += $none ? 1 : 0;
        $out['by_form_class'][$fc]['n'] = ($out['by_form_class'][$fc]['n'] ?? 0) + 1;
        $out['by_form_class'][$fc]['no_flavour'] = ($out['by_form_class'][$fc]['no_flavour'] ?? 0) + ($none ? 1 : 0);
    }
    ksort($out['by_form_class']);
    return $out;
};
$fl1 = $flav($R1, $F1);
$fl2 = $flav($R2, $F2);
$flByClass = [];
foreach (array_unique(array_merge(array_keys($fl1['by_form_class']), array_keys($fl2['by_form_class']))) as $fc) {
    $flByClass[$fc] = ['n' => $fl2['by_form_class'][$fc]['n'] ?? 0, 'no_flavour_before' => $fl1['by_form_class'][$fc]['no_flavour'] ?? 0,
        'no_flavour_after' => $fl2['by_form_class'][$fc]['no_flavour'] ?? 0];
}
ksort($flByClass);

$cv = fn (array $s) => $s['candidate_vetoes'] ?? ['candidate_pairs' => 0, 'vetoed_pairs' => 0, 'by_code' => []];
$lw = $S2['line_word_veto_impact'] ?? null;
$p1 = $S2['pilot1_recheck'] ?? null;

$diff = [
    'base' => ['dir' => $base, 'engine' => $S1['run']['engine'] ?? null, 'at_utc' => $S1['run']['at_utc'] ?? null],
    'new' => ['dir' => $new, 'engine' => $S2['run']['engine'] ?? null, 'at_utc' => $S2['run']['at_utc'] ?? null],
    'same_inputs' => ($S1['run']['inputs'] ?? null) === ($S2['run']['inputs'] ?? null),
    'alt_in_scope' => ['before' => count($R1), 'after' => count($R2)],
    'lanes' => $delta($lane1, $lane2) + ['_listings_changing_lane' => $laneChanged],
    'bands' => $delta($band1, $band2),
    'units_365d_by_band' => $units,
    'band_transitions' => $trans,
    'band_transition_examples' => $transList,
    'barcode_pair_veto_rate' => [
        'clean_barcode_pairs' => ['before' => $bq($S1)['clean_barcode_pairs'] ?? null, 'after' => $bq($S2)['clean_barcode_pairs'] ?? null],
        'vetoed' => ['before' => $bq($S1)['vetoed'] ?? null, 'after' => $bq($S2)['vetoed'] ?? null],
        'veto_rate' => ['before' => $bq($S1)['veto_rate'] ?? null, 'after' => $bq($S2)['veto_rate'] ?? null],
        'by_code' => $delta($bq($S1)['vetoes_by_code'] ?? [], $bq($S2)['vetoes_by_code'] ?? []),
        'soft_flags_on_pairs' => $delta($bq($S1)['soft_flags_on_pairs'] ?? [], $bq($S2)['soft_flags_on_pairs'] ?? []),
        'pairs_with_changed_vetoes' => $pairVetoChanges,
        'pairs_compared_here' => $pairs,
    ],
    'candidate_recall_on_barcode_pairs' => $recall,
    'candidate_vetoes' => [
        'candidate_pairs' => ['before' => $cv($S1)['candidate_pairs'], 'after' => $cv($S2)['candidate_pairs']],
        'vetoed_pairs' => ['before' => $cv($S1)['vetoed_pairs'], 'after' => $cv($S2)['vetoed_pairs']],
        'veto_rate' => ['before' => $cv($S1)['candidate_pairs'] ? round($cv($S1)['vetoed_pairs'] / $cv($S1)['candidate_pairs'], 4) : null,
            'after' => $cv($S2)['candidate_pairs'] ? round($cv($S2)['vetoed_pairs'] / $cv($S2)['candidate_pairs'], 4) : null],
        'by_code' => $delta($cv($S1)['by_code'], $cv($S2)['by_code']),
    ],
    'flavour_separation_electrofag_in_scope_non_ignored' => [
        'n' => $fl2['n'],
        'no_flavour' => ['before' => $fl1['no_flavour'], 'after' => $fl2['no_flavour']],
        'by_form_class' => $flByClass,
    ],
    'line_word_veto_impact' => $lw === null ? null : array_diff_key($lw, ['vetoed_pairs' => 1, 'alias_pending_pairs' => 1, 'pending_aliases' => 1])
        + ['vetoed_pairs' => $lw['vetoed_pairs'] ?? [], 'alias_pending_pairs' => array_map(fn ($p) => $p['alt_title'] . ' || ' . $p['vpg_title'], $lw['alias_pending_pairs'] ?? [])],
    'pilot1_recheck' => $p1 === null ? null : ['chunks' => $p1['chunks'], 'file' => $p1['file'] ?? null],
];
file_put_contents($outFile, json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
echo json_encode(['wrote' => $outFile, 'bands' => array_map(fn ($x) => $x['delta'], $diff['bands']),
    'barcode_pair_veto_rate' => $diff['barcode_pair_veto_rate']['veto_rate']]) . "\n";
