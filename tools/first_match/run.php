<?php

declare(strict_types=1);

/**
 * First-time match, deterministic part (plan §7.3 steps 1, 2, 4, 5, 7 — no AI).
 *
 *   nice -n 19 php -d memory_limit=2G tools/first_match/run.php \
 *       [--vpg=<vapeandgo_listings_*.jsonl.gz>] [--alt=<electrofag_listings_*.jsonl.gz>] [--out=<dir>]
 *
 * Reads the two catalogue exports (read-only files; no database, no network) and writes under --out:
 *   listings_features.jsonl   normalised features of every listing of both sites
 *   alt_deterministic.jsonl   per in-scope ALT listing: lane, target, candidates + prescores, vetoes, flags, band
 *   vpg_duplicates.jsonl      VPG within-site duplicate groups (reported only, never merged)
 *   crosswalk.json            brand crosswalk from barcode pairs, distributor brands, relabel alias proposals
 *   summary.json              counts per lane / flag / band, units per lane, unknown-field rates, veto stats
 *   judge/pilot_*.json        barcode-blind judge chunks (refs only)
 *   judge_private/*.json      ref -> id maps and the pilot answer key (judges must never read these)
 *
 * Nothing is linked: every output is a proposal for staff review.
 */

require __DIR__ . '/../../src/Matching/autoload.php';

use CW\Matching\Band;
use CW\Matching\Candidates;
use CW\Matching\Gtin;
use CW\Matching\JudgeCard;
use CW\Matching\Normalizer;
use CW\Matching\Text;
use CW\Matching\Veto;

ini_set('memory_limit', '2G');
$t0 = microtime(true);
$opt = getopt('', ['vpg:', 'alt:', 'out:', 'prompt:']);
$base = '/root/cw_work/first_match';
$latest = function (string $pattern): string {
    $f = glob($pattern) ?: [];
    sort($f);
    if ($f === []) {
        fwrite(STDERR, "no file matches $pattern\n");
        exit(2);
    }
    return (string) end($f);
};
$vpgFile = $opt['vpg'] ?? $latest("$base/vapeandgo_listings_*.jsonl.gz");
$altFile = $opt['alt'] ?? $latest("$base/electrofag_listings_*.jsonl.gz");
$out = rtrim($opt['out'] ?? "$base/run1", '/');
$promptFile = $opt['prompt'] ?? __DIR__ . '/prompts/judge_v1.md';
@mkdir("$out/judge", 0775, true);
@mkdir("$out/judge_private", 0770, true);

function logmsg(string $m): void
{
    global $t0;
    fwrite(STDERR, sprintf("[%6.1fs] %s\n", microtime(true) - $t0, $m));
}

/** @return array<int, array<string,mixed>> */
function loadGz(string $file): array
{
    $rows = [];
    $fh = gzopen($file, 'r');
    if ($fh === false) {
        throw new RuntimeException("cannot open $file");
    }
    while (($line = gzgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $r = json_decode($line, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        $rows[(int) $r['variant_id']] = $r;
    }
    gzclose($fh);
    return $rows;
}

function permaKey(?string $p): string
{
    return rtrim(strtolower(trim((string) $p)), '/');
}

function cwId(int $vpgVariantId): string
{
    return 'CWP-' . $vpgVariantId;
}

function jsonl($fh, array $rec): void
{
    fwrite($fh, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
}

// ───────────────────────────── 1 load + normalise ─────────────────────────────
$V = loadGz($vpgFile);
$A = loadGz($altFile);
logmsg(sprintf('loaded VPG %d, ALT %d', count($V), count($A)));

$pubSiblings = function (array $rows): array {
    $c = [];
    foreach ($rows as $r) {
        if (($r['variant_status'] ?? '') === 'Published') {
            $c[(int) $r['product_id']] = ($c[(int) $r['product_id']] ?? 0) + 1;
        }
    }
    return $c;
};
$vSib = $pubSiblings($V);
$aSib = $pubSiblings($A);
$FV = [];
foreach ($V as $id => $r) {
    $FV[$id] = Normalizer::normalize($r, ['published_siblings' => $vSib[(int) $r['product_id']] ?? 0]);
    $FV[$id]['product_live'] = ($r['product_status'] ?? '') === 'Published';
}
$FA = [];
foreach ($A as $id => $r) {
    $FA[$id] = Normalizer::normalize($r, ['published_siblings' => $aSib[(int) $r['product_id']] ?? 0]);
    $FA[$id]['product_live'] = ($r['product_status'] ?? '') === 'Published';
}
logmsg('normalised');

// ───────────────────────────── 2 VPG provisional seed ─────────────────────────────
$seed = [];
foreach ($V as $id => $r) {
    if (($r['variant_status'] ?? '') === 'Published' && (int) $r['is_landing'] === 0 && !$FV[$id]['is_placeholder']) {
        $seed[$id] = $FV[$id];
    }
}
logmsg('seed items: ' . count($seed));

// strengths per VPG product (for strength_missing / separating-field checks)
$prodStrengths = [];
foreach ($seed as $id => $f) {
    if ($f['strength_mg'] !== null) {
        $prodStrengths[$f['product_id']][Text::num($f['strength_mg'])] = true;
    }
}

// ───────────────────────────── 3 GTIN indexes ─────────────────────────────
$vIdx = Gtin::index(array_map(fn ($r) => (array) $r['barcodes'], $V));
$aIdx = Gtin::index(array_map(fn ($r) => (array) $r['barcodes'], $A));
$vBadCheck = 0;
foreach ($V as $r) {
    foreach (Gtin::listingKeys((array) $r['barcodes'])['unusable'] as $u) {
        if ($u['reason'] === 'bad_check_digit') {
            $vBadCheck++;
        }
    }
}
// exact raw-key index (including unusable codes) to report hits that were refused
$vRawKey = [];
foreach ($V as $id => $r) {
    foreach ((array) $r['barcodes'] as $b) {
        $k = Gtin::key($b);
        if ($k !== null) {
            $vRawKey[$k][$id] = true;
        }
    }
}
logmsg(sprintf('GTIN: VPG usable keys %d (on >1 item: %d), ALT usable keys %d (dup in channel: %d)',
    count($vIdx['by_key']), count($vIdx['multi']), count($aIdx['by_key']), count($aIdx['multi'])));

// ───────────────────────────── 4 VPG duplicate report ─────────────────────────────
$idKey = function (array $f): string {
    $t = $f['id_tokens'];
    $t = array_values(array_unique($t));
    sort($t);
    $fam = $f['brand_family'];
    sort($fam);
    return implode(' ', $fam) . '|' . implode(' ', $t) . '|' . implode(',', [
        $f['form_class'] ?? '-', $f['strength_mg'] === null ? '-' : Text::num($f['strength_mg']), $f['nic_type'] ?? '-',
        $f['volume_ml'] === null ? '-' : Text::num($f['volume_ml']), $f['pack_units'] ?? '-', $f['puffs'] ?? '-',
        $f['colour'] ?? '-', $f['resistance_ohm'] === null ? '-' : Text::num($f['resistance_ohm']),
        implode('/', $f['line_numbers']), $f['multi_n'] ?? '-',
    ]);
};
$byIdKey = [];
foreach ($seed as $id => $f) {
    if ($f['id_tokens'] === [] && $f['line_numbers'] === []) {
        continue;
    }
    $byIdKey[$idKey($f)][] = $id;
}
$dupGroupOf = [];
$dupGroups = [];
foreach ($byIdKey as $k => $ids) {
    if (count($ids) > 1) {
        $g = count($dupGroups) + 1;
        $dupGroups[$g] = ['kind' => 'identity_key', 'key' => $k, 'ids' => $ids];
        foreach ($ids as $id) {
            $dupGroupOf[$id][] = $g;
        }
    }
}
$gtinGroups = 0;
foreach ($vIdx['multi'] as $k => $_) {
    $ids = array_values(array_filter($vIdx['by_key'][$k], fn ($id) => isset($seed[$id])));
    $g = count($dupGroups) + 1;
    $dupGroups[$g] = ['kind' => 'shared_gtin', 'key' => (string) $k, 'ids' => $vIdx['by_key'][$k], 'seed_ids' => $ids];
    $gtinGroups++;
    foreach ($ids as $id) {
        $dupGroupOf[$id][] = $g;
    }
}
$fh = fopen("$out/vpg_duplicates.jsonl", 'w');
foreach ($dupGroups as $g => $d) {
    $members = [];
    foreach ($d['ids'] as $id) {
        $members[] = ['cw_id' => cwId((int) $id), 'vpg_variant_id' => (int) $id, 'title' => $FV[$id]['title'],
            'status' => $V[$id]['variant_status'], 'units_30d' => (int) $V[$id]['units_30d'], 'in_seed' => isset($seed[$id])];
    }
    jsonl($fh, ['group' => $g, 'kind' => $d['kind'], 'key' => $d['key'], 'members' => $members, 'action' => 'report_only']);
}
fclose($fh);
$identityGroups = count($dupGroups) - $gtinGroups;
logmsg("VPG duplicate groups: identity $identityGroups, shared GTIN $gtinGroups");

// ───────────────────────────── 5 transfer index ─────────────────────────────
$vPerma = [];
foreach ($V as $id => $r) {
    $k = permaKey($r['permalink'] ?? null);
    if ($k !== '') {
        $vPerma[$k][] = $id;
    }
}

// ───────────────────────────── 6 ALT scope + deterministic lanes (pass 1) ─────────────────────────────
$scope = [];
foreach ($A as $id => $r) {
    if (($r['variant_status'] ?? '') === 'Published' || (int) ($r['units_365d'] ?? 0) > 0) {
        $scope[$id] = true;
    }
}
logmsg('ALT in scope: ' . count($scope));

$lanes = [];
$stat = ['alt_with_vpg_barcode_hit' => 0, 'alt_with_vpg_barcode_hit_nonplaceholder' => 0, 'alt_with_vpg_barcode_hit_published' => 0, 'clean_one_to_one_published' => 0];
foreach ($A as $id => $r) {
    $f = $FA[$id];
    $keys = Gtin::listingKeys((array) $r['barcodes']);
    $hitsAll = [];
    $perKey = [];
    $flags = [];
    foreach ($keys['usable'] as $k) {
        $vids = $vIdx['by_key'][$k] ?? [];
        $perKey[] = ['gtin14' => str_pad((string) $k, 14, '0', STR_PAD_LEFT), 'vpg_variant_ids' => array_map('intval', $vids),
            'on_multiple_vpg_items' => isset($vIdx['multi'][$k]), 'dup_in_alt' => isset($aIdx['multi'][$k])];
        foreach ($vids as $v) {
            $hitsAll[(int) $v] = true;
        }
        if (isset($vIdx['multi'][$k]) && $vids !== []) {
            $flags['gtin_on_multiple_items'] = true;
        }
        if (isset($aIdx['multi'][$k])) {
            $flags['gtin_dup_in_channel'] = true;
        }
    }
    foreach ($keys['unusable'] as $u) {
        $k = Gtin::key($u['raw']);
        if ($k !== null && isset($vRawKey[$k])) {
            $flags['unusable_code_hit'] = true;
        }
    }
    $seedHits = array_values(array_filter(array_keys($hitsAll), fn ($v) => isset($seed[$v])));
    $nonSeedHits = array_values(array_filter(array_keys($hitsAll), fn ($v) => !isset($seed[$v])));

    // reconciliation with the profiling numbers (plan §7.2)
    if ($hitsAll !== []) {
        $stat['alt_with_vpg_barcode_hit']++;
        if (!$f['is_placeholder']) {
            $stat['alt_with_vpg_barcode_hit_nonplaceholder']++;
        }
        if (($r['variant_status'] ?? '') === 'Published') {
            $stat['alt_with_vpg_barcode_hit_published']++;
        }
        $pubHits = array_filter(array_keys($hitsAll), fn ($v) => ($V[$v]['variant_status'] ?? '') === 'Published');
        if (($r['variant_status'] ?? '') === 'Published' && count($pubHits) === 1 && count($hitsAll) === 1 && !$f['is_placeholder']) {
            $stat['clean_one_to_one_published']++;
        }
    }
    if (!isset($scope[$id])) {
        continue;
    }

    $lane = 'candidates';
    $target = null;
    if ($f['is_placeholder']) {
        $lane = 'ignore';
    }
    if (count($seedHits) >= 2) {
        $flags['multi_sku_gtin'] = true;
    } elseif (count($seedHits) === 1) {
        $target = (int) $seedHits[0];
        if ($nonSeedHits !== []) {
            $flags['gtin_also_on_inactive_item'] = true;
        }
    } elseif ($nonSeedHits !== []) {
        $flags['gtin_target_not_in_seed'] = true;
    }

    // transfer lane (db-transfer copies keep the VPG variant permalink minus the trailing slash)
    $tr = ['via' => null, 'vpg_variant_ids' => []];
    $pk = permaKey($r['permalink'] ?? null);
    $tids = $pk !== '' ? ($vPerma[$pk] ?? []) : [];
    if ($tids !== []) {
        $tr = ['via' => 'permalink', 'vpg_variant_ids' => array_map('intval', $tids)];
    } else {
        $ppk = permaKey($r['product_permalink'] ?? null);
        $tids = $ppk !== '' ? ($vPerma[$ppk] ?? []) : [];
        if (count($tids) === 1) {
            $tr = ['via' => 'product_permalink', 'vpg_variant_ids' => array_map('intval', $tids)];
        }
    }
    $transferTarget = null;
    if (count($tr['vpg_variant_ids']) === 1) {
        $tv = $tr['vpg_variant_ids'][0];
        if (isset($seed[$tv])) {
            $transferTarget = $tv;
        } else {
            $flags['transfer_target_not_in_seed'] = true;
        }
    } elseif (count($tr['vpg_variant_ids']) > 1) {
        $seedT = array_values(array_filter($tr['vpg_variant_ids'], fn ($v) => isset($seed[$v])));
        if (count($seedT) === 1) {
            $transferTarget = $seedT[0];
            $flags['transfer_resolved_by_seed'] = true;
        } else {
            $flags['transfer_ambiguous'] = true;
        }
    }
    if ($tr['vpg_variant_ids'] !== [] && $hitsAll !== []) {
        $trAll = $tr['vpg_variant_ids'];
        if ($transferTarget !== null && $target !== null) {
            if ($transferTarget !== $target) {
                $flags['barcode_transfer_disagree'] = true;
            } else {
                $flags['transfer_agrees'] = true;
            }
        } elseif (array_intersect($trAll, array_keys($hitsAll)) === [] && ($target !== null || $transferTarget !== null)) {
            $flags['barcode_transfer_disagree'] = true;
        }
    }

    if ($lane !== 'ignore') {
        if (!empty($flags['multi_sku_gtin']) || $target !== null || !empty($flags['gtin_on_multiple_items'])) {
            $lane = 'barcode';
        } elseif ($transferTarget !== null) {
            $lane = 'transfer';
            $target = $transferTarget;
        }
    }
    if (!empty($flags['multi_sku_gtin']) || !empty($flags['gtin_on_multiple_items'])) {
        $target = null;
    }
    $lanes[$id] = ['lane' => $lane, 'target' => $target, 'flags' => $flags, 'barcode' => [
        'usable_keys' => count($keys['usable']), 'unusable' => $keys['unusable'], 'hits' => $perKey,
        'seed_hits' => $seedHits, 'inactive_hits' => $nonSeedHits], 'transfer' => $tr, 'transfer_target' => $transferTarget];
}
logmsg('pass 1 lanes done');

// ───────────────────────────── 7 crosswalk from clean barcode pairs ─────────────────────────────
$xw = [];          // alt brand_key => vpg family token => pairs
$xwBrands = [];    // alt brand_key => vpg brand_key => pairs
$relabel = [];     // "alt family|vpg family|form_class" => pairs where the ALT text lacks the VPG family
foreach ($lanes as $id => $L) {
    if ($L['lane'] !== 'barcode' || $L['target'] === null) {
        continue;
    }
    $a = $FA[$id];
    $s = $seed[$L['target']];
    foreach ($s['brand_family'] as $t) {
        $xw[$a['brand_key']][$t] = ($xw[$a['brand_key']][$t] ?? 0) + 1;
    }
    $xwBrands[$a['brand_key']][$s['brand_key']] = ($xwBrands[$a['brand_key']][$s['brand_key']] ?? 0) + 1;
    if (array_intersect($a['brand_family'], $s['brand_family']) === []) {
        $inText = false;
        foreach ($s['brand_family'] as $t) {
            if (in_array($t, $a['full_tokens'], true)) {
                $inText = true;
            }
        }
        if (!$inText) {
            $k = implode(' ', $a['brand_family']) . '|' . implode(' ', $s['brand_family']) . '|' . ($s['form_class'] ?? '-');
            $relabel[$k] = ($relabel[$k] ?? 0) + 1;
        }
    }
}
$crosswalk = [];
foreach ($xw as $bk => $fams) {
    foreach ($fams as $t => $n) {
        if ($n >= 2) {
            $crosswalk[$bk][] = (string) $t;
        }
    }
}
$distributors = [];
foreach ($xwBrands as $bk => $vb) {
    $n = count(array_filter($vb, fn ($c) => $c >= 1));
    if ($n >= 3) {
        $distributors[$bk] = $vb;
    }
}
arsort($relabel);
$aliasProposals = [];
foreach ($relabel as $k => $n) {
    [$af, $vf, $fc] = explode('|', $k);
    $aliasProposals[] = ['alt_family' => $af, 'vpg_family' => $vf, 'form_class' => $fc, 'gtin_pairs' => $n,
        'status' => $n >= 3 ? 'proposed (>=3 pairs, needs lead confirmation)' : 'insufficient_pairs'];
}
file_put_contents("$out/crosswalk.json", json_encode([
    'crosswalk_blocks' => $crosswalk,
    'distributor_brands' => $distributors,
    'line_alias_proposals' => $aliasProposals,
    'note' => 'Blocking only. No alias is confirmed; relabelled pairs carry relabelled_line_unconfirmed.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
logmsg('crosswalk: ' . count($crosswalk) . ' brands, distributors ' . count($distributors) . ', relabel proposals ' . count(array_filter($aliasProposals, fn ($p) => $p['gtin_pairs'] >= 3)));

// ───────────────────────────── 8 candidates, vetoes, bands (pass 2) ─────────────────────────────
$C = new Candidates($seed, $crosswalk);
$vetoCtx = function (int $sid) use ($seed, $V, $prodStrengths): array {
    $s = $seed[$sid] ?? null;
    $ps = $s !== null ? ($prodStrengths[$s['product_id']] ?? []) : [];
    return [
        's_in_seed' => $s !== null,
        's_product_published' => ($V[$sid]['product_status'] ?? '') === 'Published',
        's_strength_sibling' => count($ps) > 1,
    ];
};
$separatingMissing = function (array $f, array $cands) use ($seed): ?string {
    $usable = array_values(array_filter($cands, fn ($c) => $c['vetoes'] === []));
    if (count($usable) < 2) {
        return null;
    }
    $best = max(array_map(fn ($c) => $c['prescore'], $usable));
    $top = array_values(array_filter($usable, fn ($c) => $c['prescore'] >= $best - 5));
    if (count($top) < 2) {
        return null;
    }
    foreach (['strength_mg' => 'strength', 'volume_ml' => 'volume', 'resistance_ohm' => 'resistance', 'colour' => 'colour', 'puffs' => 'puffs', 'pack_units' => 'pack'] as $k => $name) {
        if ($f[$k] !== null) {
            continue;
        }
        $vals = [];
        foreach ($top as $c) {
            $v = $seed[$c['id']][$k];
            if ($v !== null) {
                $vals[is_float($v) || is_int($v) ? Text::num($v) : (string) $v] = true;
            }
        }
        if (count($vals) >= 2) {
            return $name;
        }
    }
    return null;
};

// two ALT listings on one central item: design A.8 G needs "no same-channel listing already on T"
$targetCount = [];
foreach ($lanes as $L) {
    if ($L['target'] !== null && in_array($L['lane'], ['barcode', 'transfer'], true)) {
        $targetCount[$L['target']] = ($targetCount[$L['target']] ?? 0) + 1;
    }
}

$records = [];
$vetoPairStats = ['pairs' => 0, 'vetoed' => 0, 'by_code' => [], 'flag_counts' => []];
$recall = ['n' => 0, 'top1' => 0, 'top5' => 0, 'top15' => 0, 'top25' => 0];
$n = 0;
foreach ($scope as $id => $_) {
    $f = $FA[$id];
    $r = $A[$id];
    $L = $lanes[$id];
    $cands = [];
    if ($L['lane'] !== 'ignore') {
        foreach ($C->generate($f) as $c) {
            $chk = Veto::check($f, $seed[$c['id']], 1, $vetoCtx($c['id']));
            $cands[] = $c + ['vetoes' => $chk['vetoes'], 'soft' => $chk['flags']];
        }
    }
    $tv = [];
    $tf = [];
    $tfields = null;
    if ($L['target'] !== null) {
        $chk = Veto::check($f, $seed[$L['target']], 1, $vetoCtx($L['target']));
        $tv = $chk['vetoes'];
        $tf = $chk['flags'];
        $tfields = $chk['fields'];
        foreach (['gtin_also_on_inactive_item'] as $lf) {
            if (!empty($L['flags'][$lf])) {
                $tf[] = $lf;
            }
        }
        if (($targetCount[$L['target']] ?? 0) > 1) {
            $tf[] = 'same_channel_target_shared';
        }
        foreach ($cands as $i => $c) {
            if ($c['id'] === $L['target']) {
                $cands[$i]['is_lane_target'] = true;
            }
        }
    }
    $ev = [
        'lane' => $L['lane'], 'is_placeholder' => $f['is_placeholder'], 'flags' => array_keys($L['flags']),
        'target' => $L['target'], 'target_vetoes' => $tv, 'target_flags' => $tf,
        'candidates' => array_map(fn ($c) => ['id' => $c['id'], 'prescore' => $c['prescore'], 'vetoes' => $c['vetoes']], $cands),
        'separating_field_missing' => $L['lane'] === 'candidates' ? $separatingMissing($f, $cands) : null,
    ];
    $band = Band::provisional($ev);

    // barcode-lane quality metrics (false-veto proxy, candidate recall)
    if ($L['lane'] === 'barcode' && $L['target'] !== null && array_diff(array_keys($L['flags']), ['transfer_agrees']) === []) {
        $vetoPairStats['pairs']++;
        if ($tv !== []) {
            $vetoPairStats['vetoed']++;
            foreach (array_unique(array_column($tv, 'code')) as $code) {
                $vetoPairStats['by_code'][$code] = ($vetoPairStats['by_code'][$code] ?? 0) + 1;
            }
        }
        foreach ($tf as $x) {
            $vetoPairStats['flag_counts'][$x] = ($vetoPairStats['flag_counts'][$x] ?? 0) + 1;
        }
        $recall['n']++;
        $rank = null;
        $sorted = $cands;
        usort($sorted, fn ($a, $b) => $b['prescore'] <=> $a['prescore']);
        foreach ($sorted as $i => $c) {
            if ($c['id'] === $L['target']) {
                $rank = $i + 1;
                break;
            }
        }
        if ($rank !== null) {
            $recall['top1'] += $rank === 1 ? 1 : 0;
            $recall['top5'] += $rank <= 5 ? 1 : 0;
            $recall['top15'] += $rank <= 15 ? 1 : 0;
            $recall['top25'] += 1;
        }
    }

    $allFlags = array_keys($L['flags']);
    if ($ev['separating_field_missing'] !== null) {
        $allFlags[] = 'separating_field_missing:' . $ev['separating_field_missing'];
    }
    if ($f['is_placeholder'] && (int) $r['units_365d'] > 0) {
        $allFlags[] = 'ignored_but_sold';
    }
    $records[$id] = [
        'alt_variant_id' => $id,
        'alt_product_id' => (int) $r['product_id'],
        'title' => $f['title'],
        'brand' => $f['brand_raw'],
        'variant_status' => $r['variant_status'],
        'units_30d' => (int) $r['units_30d'],
        'units_365d' => (int) $r['units_365d'],
        'lane' => $L['lane'],
        'target' => $L['target'] !== null ? cwId($L['target']) : null,
        'target_vpg_variant_id' => $L['target'],
        'target_title' => $L['target'] !== null ? $seed[$L['target']]['title'] : null,
        'target_vetoes' => $tv,
        'target_soft_flags' => $tf,
        'target_fields' => $tfields,
        'barcode' => $L['barcode'],
        'transfer' => $L['transfer'] + ['seed_target' => $L['transfer_target'] !== null ? cwId($L['transfer_target']) : null],
        'flags' => $allFlags,
        'candidates' => array_map(fn ($c) => [
            'cw_id' => cwId($c['id']), 'vpg_variant_id' => $c['id'], 'prescore' => $c['prescore'], 'via' => $c['via'],
            'vetoes' => array_column($c['vetoes'], 'code'), 'soft_flags' => $c['soft'], 'prescore_flags' => $c['flags'],
            'is_lane_target' => $c['is_lane_target'] ?? false,
        ], $cands),
        'band' => $band['band'],
        'band_ceiling' => $band['ceiling'],
        'band_reasons' => $band['reasons'],
        'engine' => Normalizer::VERSION . '/' . Candidates::VERSION . '/' . Veto::VERSION . '/' . Band::VERSION,
    ];
    if (++$n % 1000 === 0) {
        logmsg("pass 2: $n");
    }
}
logmsg('pass 2 done: ' . count($records));

// ───────────────────────────── 9 outputs ─────────────────────────────
$fh = fopen("$out/listings_features.jsonl", 'w');
foreach ([['vapeandgo', $FV, $V], ['electrofag', $FA, $A]] as [$site, $F, $R]) {
    foreach ($F as $id => $f) {
        $rec = $f;
        unset($rec['full_tokens']);
        $rec['variant_status'] = $R[$id]['variant_status'];
        $rec['in_seed'] = $site === 'vapeandgo' ? isset($seed[$id]) : null;
        $rec['cw_id'] = $site === 'vapeandgo' && isset($seed[$id]) ? cwId($id) : null;
        $rec['in_scope'] = $site === 'electrofag' ? isset($scope[$id]) : null;
        jsonl($fh, $rec);
    }
}
fclose($fh);
$fh = fopen("$out/alt_deterministic.jsonl", 'w');
foreach ($records as $rec) {
    jsonl($fh, $rec);
}
fclose($fh);

// summary
$count = fn (array $xs) => array_count_values($xs);
$laneCounts = $count(array_column($records, 'lane'));
$bandKey = fn ($r) => $r['band'] ?? ('pending_ai:' . $r['band_ceiling']);
$bandCounts = $count(array_map($bandKey, $records));
$units = [];
foreach ($records as $r) {
    foreach (['lane' => $r['lane'], 'band' => $bandKey($r)] as $dim => $k) {
        $units[$dim][$k]['listings'] = ($units[$dim][$k]['listings'] ?? 0) + 1;
        $units[$dim][$k]['units_30d'] = ($units[$dim][$k]['units_30d'] ?? 0) + $r['units_30d'];
        $units[$dim][$k]['units_365d'] = ($units[$dim][$k]['units_365d'] ?? 0) + $r['units_365d'];
    }
}
$flagCounts = [];
foreach ($records as $r) {
    foreach ($r['flags'] as $x) {
        $flagCounts[$x] = ($flagCounts[$x] ?? 0) + 1;
    }
}
ksort($flagCounts);
$vetoCounts = [];
foreach ($records as $r) {
    foreach (array_unique(array_column($r['target_vetoes'], 'code')) as $x) {
        $vetoCounts[$x] = ($vetoCounts[$x] ?? 0) + 1;
    }
}
ksort($vetoCounts);
$candVeto = ['candidate_pairs' => 0, 'vetoed_pairs' => 0, 'by_code' => []];
foreach ($records as $r) {
    foreach ($r['candidates'] as $c) {
        $candVeto['candidate_pairs']++;
        if ($c['vetoes'] !== []) {
            $candVeto['vetoed_pairs']++;
        }
        foreach ($c['vetoes'] as $x) {
            $candVeto['by_code'][$x] = ($candVeto['by_code'][$x] ?? 0) + 1;
        }
    }
}
ksort($candVeto['by_code']);
$unknownRates = function (array $F, array $ids): array {
    $n = count($ids);
    $fields = ['form', 'strength_mg', 'nic_type', 'volume_ml', 'pack_units', 'puffs', 'colour', 'resistance_ohm', 'flavour_tokens'];
    $out = ['n' => $n];
    foreach ($fields as $k) {
        $null = 0;
        $applicable = 0;
        foreach ($ids as $id) {
            $f = $F[$id];
            $app = match ($k) {
                'nic_type' => in_array($f['form_class'], ['liquid', 'nic_shot'], true),
                'puffs' => in_array($f['form_class'], ['device', 'pod_refill'], true),
                'resistance_ohm' => in_array($f['form_class'], ['coil'], true) || $f['form'] === 'refill_pod_cartridge',
                'volume_ml' => in_array($f['form_class'], ['liquid', 'nic_shot'], true),
                'strength_mg' => in_array($f['form_class'], ['liquid', 'nic_shot', 'device', 'pod_refill'], true) || $f['form_sub'] === 'nicotine_pouch',
                default => true,
            };
            if (!$app) {
                continue;
            }
            $applicable++;
            if ($f[$k] === null) {
                $null++;
            }
        }
        $out[$k] = ['applicable' => $applicable, 'unknown' => $null, 'unknown_rate' => $applicable ? round($null / $applicable, 4) : null];
    }
    $conf = [];
    foreach ($ids as $id) {
        foreach ($F[$id]['conflict_fields'] as $c) {
            $conf[$c] = ($conf[$c] ?? 0) + 1;
        }
    }
    ksort($conf);
    $out['internal_conflict_listings_by_field'] = $conf;
    $forms = array_count_values(array_map(fn ($id) => $F[$id]['form'] ?? 'unknown', $ids));
    arsort($forms);
    $out['form_distribution'] = $forms;
    return $out;
};
$altScoped = array_keys(array_filter($records, fn ($r) => $r['lane'] !== 'ignore'));

$summary = [
    'run' => [
        'at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'engine' => Normalizer::VERSION . '/' . Candidates::VERSION . '/' . Veto::VERSION . '/' . Band::VERSION,
        'inputs' => [basename($vpgFile) => hash_file('sha256', $vpgFile), basename($altFile) => hash_file('sha256', $altFile)],
        'seconds' => null,
        'ai_used' => false,
        'note' => 'Deterministic evidence only. Bands other than Ignore/Conflict are pending the barcode-blind judge; band_ceiling is the best band reachable.',
    ],
    'vpg' => [
        'variants' => count($V),
        'seed_items' => count($seed),
        'seed_rule' => 'variant Published, is_landing=0, not placeholder; one provisional central id CWP-<vpg variant_id> each',
        'placeholders_excluded_non_landing' => count(array_filter($V, fn ($r) => ($r['variant_status'] ?? '') === 'Published' && (int) $r['is_landing'] === 0 && $FV[(int) $r['variant_id']]['is_placeholder'])),
        'seed_product_not_published' => count(array_filter($seed, fn ($f) => !$f['product_live'])),
        'duplicate_groups' => ['identity_key' => $identityGroups, 'shared_gtin' => $gtinGroups, 'total' => count($dupGroups),
            'listings_in_identity_groups' => array_sum(array_map(fn ($d) => $d['kind'] === 'identity_key' ? count($d['ids']) : 0, $dupGroups)),
            'action' => 'reported only (vpg_duplicates.jsonl); nothing merged'],
        'gtin' => ['usable_keys' => count($vIdx['by_key']), 'keys_on_more_than_one_item' => count($vIdx['multi']), 'bad_check_digit_codes' => $vBadCheck],
    ],
    'alt' => [
        'variants' => count($A),
        'in_scope' => count($scope),
        'in_scope_rule' => 'variant Published OR units_365d > 0',
        'reconciliation_with_plan_7_2' => $stat + ['plan_figures' => '2,072 barcoded with a VPG hit -> 2,063 non-placeholder -> ~1,977 clean 1:1 published pairs'],
        'gtin' => ['usable_keys' => count($aIdx['by_key']), 'dup_in_channel_keys' => count($aIdx['multi'])],
        'transfer' => [
            'permalink_equal_to_exactly_one_vpg_variant' => count(array_filter($lanes, fn ($L) => $L['transfer']['via'] !== null && count($L['transfer']['vpg_variant_ids']) === 1)),
            'resolved_to_a_seed_item' => count(array_filter($lanes, fn ($L) => $L['transfer_target'] !== null)),
            'transfer_lane_listings' => $laneCounts['transfer'] ?? 0,
            'agrees_with_barcode' => $flagCounts['transfer_agrees'] ?? 0,
            'disagrees_with_barcode' => $flagCounts['barcode_transfer_disagree'] ?? 0,
        ],
    ],
    'lanes' => $laneCounts,
    'bands' => $bandCounts,
    'units_by_lane' => $units['lane'] ?? [],
    'units_by_band' => $units['band'] ?? [],
    'flags' => $flagCounts,
    'lane_target_vetoes' => $vetoCounts,
    'barcode_pair_quality' => [
        'clean_barcode_pairs' => $vetoPairStats['pairs'],
        'vetoed' => $vetoPairStats['vetoed'],
        'veto_rate' => $vetoPairStats['pairs'] ? round($vetoPairStats['vetoed'] / $vetoPairStats['pairs'], 4) : null,
        'vetoes_by_code' => $vetoPairStats['by_code'],
        'soft_flags_on_pairs' => $vetoPairStats['flag_counts'],
        'note' => 'Barcode pairs are strong evidence, not ground truth (~1.2% suspect per profiling): vetoed pairs go to Conflict, never Key.',
    ],
    'candidate_recall_on_barcode_pairs' => $recall + [
        'recall_top1' => $recall['n'] ? round($recall['top1'] / $recall['n'], 4) : null,
        'recall_top5' => $recall['n'] ? round($recall['top5'] / $recall['n'], 4) : null,
        'recall_top15' => $recall['n'] ? round($recall['top15'] / $recall['n'], 4) : null,
        'recall_top25_incl_siblings' => $recall['n'] ? round($recall['top25'] / $recall['n'], 4) : null,
    ],
    'candidate_vetoes' => $candVeto,
    'crosswalk' => ['brands' => count($crosswalk), 'distributor_brands' => array_keys($distributors),
        'line_alias_proposals_ge3_pairs' => array_values(array_filter($aliasProposals, fn ($p) => $p['gtin_pairs'] >= 3))],
    'unknown_field_rates' => [
        'vapeandgo_seed' => $unknownRates($FV, array_keys($seed)),
        'electrofag_in_scope_non_placeholder' => $unknownRates($FA, $altScoped),
    ],
];
logmsg('summary built');

// ───────────────────────────── 10 barcode-blind judge chunks ─────────────────────────────
$promptVersion = is_file($promptFile) ? hash_file('sha256', $promptFile) : null;
mt_srand(20260926);
$byUnits = function (array $ids) use ($records): array {
    usort($ids, fn ($a, $b) => [$records[$b]['units_365d'], $records[$b]['units_30d'], $a] <=> [$records[$a]['units_365d'], $records[$a]['units_30d'], $b]);
    return $ids;
};
$barcodeClean = array_keys(array_filter($records, fn ($r) => $r['lane'] === 'barcode' && $r['target'] !== null && $r['band'] === null));
$barcodeClean = $byUnits($barcodeClean);
$openIds = $byUnits(array_keys(array_filter($records, fn ($r) => $r['lane'] === 'candidates' && $r['band'] === null
    && !array_intersect($r['flags'], ['gtin_target_not_in_seed', 'transfer_target_not_in_seed', 'transfer_ambiguous', 'unusable_code_hit']))));

$context = [
    'naming_hints' => [
        'listing_channel' => 'electrofag: names are usually "<Line> <Flavour> 10ml Nic Salt E Liquid - 20mg" or "<Line> Prefilled Pods - <Flavour>" (flavour after the line)',
        'central_items' => 'central items are Vape and Go listings: usually "<Flavour> Nic Salt E-Liquid by <Line> 10ml | 20mg" or "<Flavour> <Line> Pods" (flavour first); Vape and Go "brands" are often product lines',
    ],
    'confirmed_aliases' => [],
    'confirmed_flavour_synonyms' => [],
];

$forbiddenFor = function (array $ids, array $siteRows) : array {
    $out = [];
    foreach ($ids as $id) {
        $r = $siteRows[$id];
        foreach ((array) $r['barcodes'] as $b) {
            $k = Gtin::key($b);
            if ($k !== null && strlen($k) >= 6) {
                $out[] = $k;
            }
        }
        foreach (['permalink', 'product_permalink'] as $p) {
            $pk = permaKey($r[$p] ?? null);
            if (strlen($pk) >= 6) {
                $out[] = $pk;
            }
        }
    }
    return $out;
};

$writeChunk = function (string $name, array $items, array $refmap, string $purpose) use ($out, $promptVersion, $context): void {
    $payload = [
        'chunk' => $name,
        'prompt_version' => $promptVersion,
        'instructions' => 'tools/first_match/prompts/judge_v1.md',
        'purpose' => $purpose,
        'context' => $context,
        'items' => $items,
    ];
    // compact, one item per line (readable in chunks without pretty-print bloat)
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $head = $payload;
    unset($head['items']);
    $lines = array_map(fn ($it) => json_encode($it, $flags), $items);
    $json = substr((string) json_encode($head, $flags), 0, -1) . ',"items":[' . "\n" . implode(",\n", $lines) . "\n]}\n";
    json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    file_put_contents("$out/judge/$name.json", $json);
    file_put_contents("$out/judge_private/$name.refmap.json", json_encode(['chunk' => $name, 'prompt_version' => $promptVersion, 'refs' => $refmap],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};

$sameDupGroup = function (int $a, int $b) use ($dupGroupOf): bool {
    return array_intersect($dupGroupOf[$a] ?? [], $dupGroupOf[$b] ?? []) !== [];
};

// the same neutral text in every chunk: nothing may hint how a chunk was built (barcode, leave-one-out)
$neutralPurpose = 'Judge each listing against its own candidates only, following the instructions file.';
// target position balanced exactly across C1..C3 in pilot_pairs (a judge must not learn a position bias)
$slots = [];
for ($i = 0; $i < 60; $i++) {
    $slots[] = $i % 3;
}
shuffle($slots);

$answers = ['prompt_version' => $promptVersion, 'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'chunks' => []];

// (a) pilot_pairs: 60 barcode-lane listings, target + 2 same-brand decoys by prescore, shuffled
$items = [];
$refmap = [];
$ans = [];
$forbid = [];
$li = 0;
foreach ($barcodeClean as $id) {
    if ($li >= 60) {
        break;
    }
    $rec = $records[$id];
    $tgt = (int) $rec['target_vpg_variant_id'];
    $tfam = $seed[$tgt]['brand_family'];
    $decoys = [];
    $pool = $rec['candidates'];
    usort($pool, fn ($a, $b) => $b['prescore'] <=> $a['prescore']);
    foreach ($pool as $c) {
        $cid = (int) $c['vpg_variant_id'];
        if ($cid === $tgt || $sameDupGroup($cid, $tgt) || array_intersect($seed[$cid]['brand_family'], $tfam) === []) {
            continue;
        }
        $decoys[] = $c;
        if (count($decoys) === 2) {
            break;
        }
    }
    if (count($decoys) < 2) {
        continue;
    }
    $li++;
    $L = 'L' . $li;
    $set = array_map(fn ($c) => ['vpg_variant_id' => (int) $c['vpg_variant_id'], 'role' => 'decoy', 'vetoes' => $c['vetoes']], $decoys);
    shuffle($set);
    array_splice($set, $slots[$li - 1], 0, [['vpg_variant_id' => $tgt, 'role' => 'target', 'vetoes' => []]]);
    $cards = [];
    $map = [];
    $exp = null;
    $decoyRefs = [];
    $vetoed = [];
    foreach ($set as $i => $c) {
        $ref = 'C' . ($i + 1);
        $cards[] = JudgeCard::card($ref, $V[$c['vpg_variant_id']], $FV[$c['vpg_variant_id']]);
        $map[$ref] = ['cw_id' => cwId($c['vpg_variant_id']), 'vpg_variant_id' => $c['vpg_variant_id']];
        if ($c['role'] === 'target') {
            $exp = $ref;
        } else {
            $decoyRefs[] = $ref;
            if ($c['vetoes'] !== []) {
                $vetoed[$ref] = $c['vetoes'];
            }
        }
    }
    $items[] = ['ref' => $L, 'listing' => JudgeCard::card($L, $A[$id], $FA[$id]), 'candidates' => $cards];
    $refmap[$L] = ['listing' => ['site' => 'electrofag', 'variant_id' => $id], 'candidates' => $map];
    $ans[$L] = ['expected_outcome' => 'match', 'expected_ref' => $exp, 'decoy_refs' => $decoyRefs, 'vetoed_decoys' => $vetoed,
        'target_soft_flags' => $rec['target_soft_flags'], 'units_365d' => $rec['units_365d'],
        'note' => 'barcode lane: usable GTIN resolves to exactly one seed item; barcode is strong evidence, not ground truth'];
    $forbid = array_merge($forbid, $forbiddenFor([$id], $A), $forbiddenFor(array_column($set, 'vpg_variant_id'), $V));
}
$pairsIds = array_map(fn ($m) => $m['listing']['variant_id'], $refmap);
$payload = ['items' => $items];
JudgeCard::assertBlind($payload, $forbid);
$writeChunk('pilot_pairs', $items, $refmap, $neutralPurpose);
$answers['chunks']['pilot_pairs'] = $ans;

// (b) pilot_open: 20 listings with no barcode/transfer match, top-15 candidates
$items = [];
$refmap = [];
$ans = [];
$forbid = [];
$li = 0;
foreach ($openIds as $id) {
    if ($li >= 20) {
        break;
    }
    $rec = $records[$id];
    $pool = array_values(array_filter($rec['candidates'], fn ($c) => $c['via'] === 'block'));
    usort($pool, fn ($a, $b) => $b['prescore'] <=> $a['prescore']);
    $pool = array_slice($pool, 0, 15);
    if ($pool === []) {
        continue;
    }
    $li++;
    $L = 'L' . $li;
    $topId = (int) $pool[0]['vpg_variant_id'];
    shuffle($pool);
    $cards = [];
    $map = [];
    $vetoed = [];
    $topRef = null;
    foreach ($pool as $i => $c) {
        $ref = 'C' . ($i + 1);
        $vid = (int) $c['vpg_variant_id'];
        $cards[] = JudgeCard::card($ref, $V[$vid], $FV[$vid]);
        $map[$ref] = ['cw_id' => cwId($vid), 'vpg_variant_id' => $vid, 'prescore' => $c['prescore']];
        if ($c['vetoes'] !== []) {
            $vetoed[$ref] = $c['vetoes'];
        }
        if ($vid === $topId) {
            $topRef = $ref;
        }
    }
    $items[] = ['ref' => $L, 'listing' => JudgeCard::card($L, $A[$id], $FA[$id]), 'candidates' => $cards];
    $refmap[$L] = ['listing' => ['site' => 'electrofag', 'variant_id' => $id], 'candidates' => $map];
    $ans[$L] = ['expected_outcome' => null, 'expected_ref' => null, 'gold' => 'none (needs blind human label)',
        'deterministic' => ['top_prescore_ref' => $topRef, 'vetoed_refs' => $vetoed, 'band_ceiling' => $rec['band_ceiling'],
            'band_reasons' => $rec['band_reasons']], 'units_365d' => $rec['units_365d']];
    $forbid = array_merge($forbid, $forbiddenFor([$id], $A), $forbiddenFor(array_map(fn ($c) => (int) $c['vpg_variant_id'], $pool), $V));
}
JudgeCard::assertBlind(['items' => $items], $forbid);
$writeChunk('pilot_open', $items, $refmap, $neutralPurpose);
$answers['chunks']['pilot_open'] = $ans;

// (c) pilot_loo: 30 barcode-lane listings (not in pilot_pairs) with the TRUE target removed
$items = [];
$refmap = [];
$ans = [];
$forbid = [];
$li = 0;
$pairsSet = array_flip($pairsIds);
foreach ($barcodeClean as $id) {
    if ($li >= 30) {
        break;
    }
    if (isset($pairsSet[$id])) {
        continue;
    }
    $rec = $records[$id];
    $tgt = (int) $rec['target_vpg_variant_id'];
    $pool = [];
    foreach ($C->generate($FA[$id], Candidates::TOP_K, [$tgt => true]) as $c) {
        if ($c['via'] !== 'block') {
            continue;
        }
        $chk = Veto::check($FA[$id], $seed[$c['id']], 1, $vetoCtx($c['id']));
        $pool[] = $c + ['vetoes' => array_column($chk['vetoes'], 'code')];
    }
    $pool = array_slice($pool, 0, 15);
    if ($pool === []) {
        continue;
    }
    $li++;
    $L = 'L' . $li;
    shuffle($pool);
    $cards = [];
    $map = [];
    $vetoed = [];
    $dups = [];
    foreach ($pool as $i => $c) {
        $ref = 'C' . ($i + 1);
        $cards[] = JudgeCard::card($ref, $V[$c['id']], $FV[$c['id']]);
        $map[$ref] = ['cw_id' => cwId($c['id']), 'vpg_variant_id' => $c['id'], 'prescore' => $c['prescore']];
        if ($c['vetoes'] !== []) {
            $vetoed[$ref] = $c['vetoes'];
        }
        if ($sameDupGroup($c['id'], $tgt)) {
            $dups[] = $ref;
        }
    }
    $items[] = ['ref' => $L, 'listing' => JudgeCard::card($L, $A[$id], $FA[$id]), 'candidates' => $cards];
    $refmap[$L] = ['listing' => ['site' => 'electrofag', 'variant_id' => $id],
        'removed_true_target' => ['cw_id' => cwId($tgt), 'vpg_variant_id' => $tgt], 'candidates' => $map];
    $ans[$L] = ['expected_outcome' => 'no_match_in_list', 'expected_ref' => null,
        'any_match_is' => 'a would-be false merge unless a human adjudicates the chosen item as a true VPG duplicate of the removed target',
        'possible_vpg_duplicate_refs' => $dups, 'vetoed_refs' => $vetoed, 'units_365d' => $rec['units_365d']];
    $forbid = array_merge($forbid, $forbiddenFor([$id], $A), $forbiddenFor(array_merge([$tgt], array_column($pool, 'id')), $V));
}
JudgeCard::assertBlind(['items' => $items], $forbid);
$writeChunk('pilot_loo', $items, $refmap, $neutralPurpose);
$answers['chunks']['pilot_loo'] = $ans;
file_put_contents("$out/judge_private/pilot_answers.json", json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$summary['judge_pilot'] = [
    'prompt_version' => $promptVersion,
    'files' => ['pairs' => "$out/judge/pilot_pairs.json", 'open' => "$out/judge/pilot_open.json", 'loo' => "$out/judge/pilot_loo.json"],
    'items' => ['pairs' => count($answers['chunks']['pilot_pairs']), 'open' => count($answers['chunks']['pilot_open']), 'loo' => count($answers['chunks']['pilot_loo'])],
    'private' => "$out/judge_private (refmaps + pilot_answers.json; judges must never read)",
];
$summary['run']['seconds'] = round(microtime(true) - $t0, 1);
file_put_contents("$out/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
logmsg('done');
echo json_encode(['summary' => "$out/summary.json", 'seconds' => $summary['run']['seconds']]) . "\n";
