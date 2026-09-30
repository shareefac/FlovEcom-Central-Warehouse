<?php

declare(strict_types=1);

/**
 * First-time match, deterministic part (plan §7.3 steps 1, 2, 4, 5, 7 — no AI).
 *
 *   nice -n 19 php -d memory_limit=2G tools/first_match/run.php \
 *       [--vpg=<vapeandgo_listings_*.jsonl.gz>] [--alt=<electrofag_listings_*.jsonl.gz>] [--out=<dir>] \
 *       [--private=<dir>] [--prompt=<judge prompt .md>] [--pilot1=<run1 dir>] [--no-judge]
 *
 * Reads the two catalogue exports (read-only files; no database, no network) and writes under --out:
 *   listings_features.jsonl   normalised features of every listing of both sites
 *   alt_deterministic.jsonl   per in-scope ALT listing: lane, target, candidates + prescores, vetoes, flags, band
 *   vpg_duplicates.jsonl      VPG within-site duplicate groups (reported only, never merged)
 *   crosswalk.json            brand crosswalk from barcode pairs, distributor brands, relabel alias proposals
 *   line_lexicon.json         per-brand line lexicons used to split untagged titles (TitlePattern)
 *   summary.json              counts per lane / flag / band, units per lane, unknown-field rates, veto stats,
 *                             line_word veto impact on clean barcode pairs
 *   pilot1_recheck.json       every pilot-1 listing x candidate pair re-vetoed with this engine vs v1 (when --pilot1,
 *                             default /root/cw_work/first_match/run1, holds judge_private/ and pilot_eval/)
 *   judge/pilot2_c*.json      barcode-blind judge chunks (refs only; open style, stratified; see section 10)
 * and under --private (default /root/cw_work/first_match/private/<run name>, OUTSIDE the run folder so a judge
 * that can read the run folder still cannot reach them):
 *   <chunk>.refmap.json       ref -> id maps
 *   pilot2_answers.json       the answer key (judges must never read these)
 *
 * Nothing is linked: every output is a proposal for staff review.
 */

require __DIR__ . '/../../src/Matching/autoload.php';

use CW\Matching\Band;
use CW\Matching\Candidates;
use CW\Matching\Flavour;
use CW\Matching\FlavourVocab;
use CW\Matching\Gtin;
use CW\Matching\JudgeCard;
use CW\Matching\Normalizer;
use CW\Matching\Text;
use CW\Matching\TitlePattern;
use CW\Matching\Veto;

ini_set('memory_limit', '2G');
$t0 = microtime(true);
$opt = getopt('', ['vpg:', 'alt:', 'out:', 'prompt:', 'private:', 'pilot1:', 'no-judge']);
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
$out = rtrim($opt['out'] ?? "$base/run2", '/');
$private = rtrim($opt['private'] ?? "$base/private/" . basename($out), '/');
$promptFile = $opt['prompt'] ?? __DIR__ . '/prompts/judge_v2.md';
@mkdir("$out/judge", 0775, true);
@mkdir($private, 0770, true);
if (str_starts_with(realpath($private) . '/', realpath($out) . '/')) {
    fwrite(STDERR, "--private must be outside the run folder\n");
    exit(2);
}
$engine = Normalizer::VERSION . '/' . Candidates::VERSION . '/' . Veto::VERSION . '/' . Band::VERSION
    . '/' . Flavour::VERSION . '+' . FlavourVocab::VERSION . '/' . TitlePattern::VERSION;

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
// per-site, per-brand line lexicons (published or sold rows) for titles with no line/flavour separator
$lexScope = fn (array $rows) => array_filter($rows, fn ($r) => ($r['variant_status'] ?? '') === 'Published' || (int) ($r['units_365d'] ?? 0) > 0);
$lexV = TitlePattern::lexicon($lexScope($V));
$lexA = TitlePattern::lexicon($lexScope($A));
file_put_contents("$out/line_lexicon.json", json_encode(['version' => TitlePattern::VERSION, 'min_products' => TitlePattern::LEXICON_MIN_PRODUCTS,
    'vapeandgo' => $lexV, 'electrofag' => $lexA], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$FV = [];
foreach ($V as $id => $r) {
    $FV[$id] = Normalizer::normalize($r, ['published_siblings' => $vSib[(int) $r['product_id']] ?? 0,
        'line_lexicon' => $lexV[TitlePattern::brandKey((string) ($r['brand'] ?? ''))] ?? []]);
    $FV[$id]['product_live'] = ($r['product_status'] ?? '') === 'Published';
}
$FA = [];
foreach ($A as $id => $r) {
    $FA[$id] = Normalizer::normalize($r, ['published_siblings' => $aSib[(int) $r['product_id']] ?? 0,
        'line_lexicon' => $lexA[TitlePattern::brandKey((string) ($r['brand'] ?? ''))] ?? []]);
    $FA[$id]['product_live'] = ($r['product_status'] ?? '') === 'Published';
}
logmsg('normalised');
$vocabSha = FlavourVocab::SOURCE['sha256'] ?? null;
if ($vocabSha !== hash_file('sha256', $vpgFile)) {
    logmsg('WARNING: FlavourVocab was built from a different Vape and Go export; re-run tools/first_match/build_flavour_vocab.php');
}

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

// soft flags that name a text difference between the two sides (such a pair can never reach Key)
$DIFF_FLAGS = ['line_number_one_side', 'line_number_extra', 'modifier_extra', 'flavour_extra', 'colour_extra',
    'line_alias_pending', 'relabelled_line_unconfirmed', 'volume_diff_attr'];

// two ALT listings on one central item: design A.8 G needs "no same-channel listing already on T"
$targetCount = [];
foreach ($lanes as $L) {
    if ($L['target'] !== null && in_array($L['lane'], ['barcode', 'transfer'], true)) {
        $targetCount[$L['target']] = ($targetCount[$L['target']] ?? 0) + 1;
    }
}

$records = [];
$vetoPairStats = ['pairs' => 0, 'vetoed' => 0, 'by_code' => [], 'flag_counts' => []];
$lineWordHits = ['vetoed' => [], 'alias_pending' => []];
$transferPairs = ['pairs' => 0, 'vetoed' => 0, 'line_word_vetoed' => [], 'line_alias_pending' => 0];
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
        foreach ($tv as $x) {
            if ($x['code'] === 'line_word') {
                $lineWordHits['vetoed'][] = ['alt_variant_id' => $id, 'alt_title' => $f['title'], 'vpg_variant_id' => $L['target'],
                    'vpg_title' => $seed[$L['target']]['title'], 'detail' => $x['detail'], 'other_vetoes' => array_values(array_diff(array_column($tv, 'code'), ['line_word'])),
                    'units_365d' => (int) $r['units_365d']];
            }
        }
        if (in_array('line_alias_pending', $tf, true)) {
            $chk = Veto::check($f, $seed[$L['target']], 1, $vetoCtx($L['target']));
            $lw = Veto::lineWords($f);
            $sw = Veto::lineWords($seed[$L['target']]);
            $lineWordHits['alias_pending'][] = ['alt_variant_id' => $id, 'alt_title' => $f['title'], 'vpg_variant_id' => $L['target'],
                'vpg_title' => $seed[$L['target']]['title'], 'listing_line_words' => $lw, 'item_line_words' => $sw,
                'units_365d' => (int) $r['units_365d']];
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

    // the same line_word measurement on clean transfer-lane pairs (information only; the threshold is on barcode pairs)
    if ($L['lane'] === 'transfer' && $L['target'] !== null && $L['flags'] === []) {
        $transferPairs['pairs']++;
        $transferPairs['vetoed'] += $tv !== [] ? 1 : 0;
        if (in_array('line_word', array_column($tv, 'code'), true)) {
            $transferPairs['line_word_vetoed'][] = ['alt_variant_id' => $id, 'alt_title' => $f['title'], 'vpg_variant_id' => $L['target'],
                'vpg_title' => $seed[$L['target']]['title'], 'units_365d' => (int) $r['units_365d']];
        }
        $transferPairs['line_alias_pending'] += in_array('line_alias_pending', $tf, true) ? 1 : 0;
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
        'engine' => $engine,
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
$flavourSeparation = function (array $F, array $ids): array {
    $out = [];
    foreach ($ids as $id) {
        $f = $F[$id];
        $k = $f['form_class'] ?? 'unknown';
        $out[$k]['n'] = ($out[$k]['n'] ?? 0) + 1;
        $src = $f['flavour_src'] ?? 'none';
        $out[$k]['by_source'][$src] = ($out[$k]['by_source'][$src] ?? 0) + 1;
        if ($f['flavour_tokens'] === null) {
            $out[$k]['no_flavour'] = ($out[$k]['no_flavour'] ?? 0) + 1;
        }
    }
    ksort($out);
    $tot = ['n' => count($ids), 'no_flavour' => array_sum(array_map(fn ($x) => $x['no_flavour'] ?? 0, $out))];
    return ['all' => $tot, 'by_form_class' => $out];
};

$summary = [
    'run' => [
        'at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'engine' => $engine,
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
    'transfer_pair_quality' => $transferPairs + ['veto_rate' => $transferPairs['pairs'] ? round($transferPairs['vetoed'] / $transferPairs['pairs'], 4) : null,
        'note' => 'clean transfer-lane pairs (db-transfer permalink copy, no lane flag); information only'],
    'line_word_veto_impact' => (function () use ($lineWordHits, $vetoPairStats, $transferPairs): array {
        $n = $vetoPairStats['pairs'];
        $v = count($lineWordHits['vetoed']);
        $a = count($lineWordHits['alias_pending']);
        return [
            'clean_barcode_pairs' => $n,
            'would_hit_without_alias_rule' => $v + $a,
            'vetoed' => $v,
            'vetoed_rate' => $n ? round($v / $n, 4) : null,
            'alias_pending_instead_of_vetoed' => $a,
            'threshold' => 'keep the veto only if vetoed <= 0.5% of clean barcode pairs (hits covered by a pending alias are flagged line_alias_pending, not vetoed)',
            'kept' => Veto::LINE_WORD_VETO,
            'pending_aliases' => Veto::PENDING_LINE_ALIASES,
            'vetoed_pairs' => $lineWordHits['vetoed'],
            'alias_pending_pairs' => $lineWordHits['alias_pending'],
            'transfer_lane_info' => ['clean_transfer_pairs' => $transferPairs['pairs'], 'line_word_vetoed' => count($transferPairs['line_word_vetoed']),
                'line_alias_pending' => $transferPairs['line_alias_pending']],
        ];
    })(),
    'crosswalk' => ['brands' => count($crosswalk), 'distributor_brands' => array_keys($distributors),
        'line_alias_proposals_ge3_pairs' => array_values(array_filter($aliasProposals, fn ($p) => $p['gtin_pairs'] >= 3))],
    'unknown_field_rates' => [
        'vapeandgo_seed' => $unknownRates($FV, array_keys($seed)),
        'electrofag_in_scope_non_placeholder' => $unknownRates($FA, $altScoped),
    ],
    'flavour_separation' => [
        'vapeandgo_seed' => $flavourSeparation($FV, array_keys($seed)),
        'electrofag_in_scope_non_placeholder' => $flavourSeparation($FA, $altScoped),
    ],
    'flavour_vocabulary' => ['version' => FlavourVocab::VERSION, 'words' => count(FlavourVocab::WORDS), 'source' => FlavourVocab::SOURCE,
        'matches_this_vpg_export' => $vocabSha === hash_file('sha256', $vpgFile)],
    'line_lexicon' => ['version' => TitlePattern::VERSION, 'vapeandgo_brands' => count($lexV), 'electrofag_brands' => count($lexA), 'file' => "$out/line_lexicon.json"],
];
logmsg('summary built');

// ───────────────────────────── 9b pilot-1 recheck (regression on the real pilot pairs) ─────────────────────────────
// Every listing x candidate pair the pilot-1 judges saw, re-vetoed with this engine, next to the v1 vetoes (the
// evaluator's pilot_eval/veto_recheck.json) and the judges' pilot-1 answers: which traps only the judge stopped
// before and are now vetoed or flagged, and whether any pair the judge and the barcode agreed on is now vetoed.
$p1Dir = rtrim($opt['pilot1'] ?? "$base/run1", '/');
if (is_file("$p1Dir/judge_private/pilot_answers.json")) {
    $p1Ans = json_decode((string) file_get_contents("$p1Dir/judge_private/pilot_answers.json"), true, 512, JSON_THROW_ON_ERROR);
    $p1V1 = is_file("$p1Dir/pilot_eval/veto_recheck.json")
        ? json_decode((string) file_get_contents("$p1Dir/pilot_eval/veto_recheck.json"), true, 512, JSON_THROW_ON_ERROR)['pairs'] : [];
    $p1Judge = [];
    if (is_file("$p1Dir/pilot_judgements.json")) {
        foreach (json_decode((string) file_get_contents("$p1Dir/pilot_judgements.json"), true, 512, JSON_THROW_ON_ERROR)['chunks'] as $jc) {
            foreach ($jc['result']['items'] ?? [] as $ji) {
                $p1Judge[$jc['chunk_name']][$ji['ref']] = $ji;
            }
        }
    }
    $sideGuard = function (array $vetoes, array $flags) use ($DIFF_FLAGS): string {
        if ($vetoes !== []) {
            return 'vetoed';
        }
        return array_intersect($flags, $DIFF_FLAGS) !== [] ? 'flagged' : 'unguarded';
    };
    $p1 = ['engine' => $engine, 'source' => $p1Dir, 'chunks' => []];
    $p1Items = [];
    foreach (glob("$p1Dir/judge_private/*.refmap.json") ?: [] as $rf) {
        $m = json_decode((string) file_get_contents($rf), true, 512, JSON_THROW_ON_ERROR);
        $ch = $m['chunk'];
        $st = ['items' => 0, 'candidate_pairs' => 0, 'vetoed_v1' => 0, 'vetoed_now' => 0,
            'true_pairs' => 0, 'true_pairs_vetoed_v1' => 0, 'true_pairs_vetoed_now' => [],
            'judge_matches' => 0, 'judge_match_now_vetoed' => [],
            'items_with_unguarded_non_true_candidate_v1' => 0, 'items_with_unguarded_non_true_candidate_now' => 0];
        foreach ($m['refs'] as $L => $x) {
            $aid = (int) $x['listing']['variant_id'];
            $trueVid = isset($x['removed_true_target']) ? (int) $x['removed_true_target']['vpg_variant_id'] : null;
            $expRef = $p1Ans['chunks'][$ch][$L]['expected_ref'] ?? null;
            if ($expRef !== null) {
                $trueVid = (int) $x['candidates'][$expRef]['vpg_variant_id'];
            }
            $cands = $x['candidates'];
            if (isset($x['removed_true_target'])) {
                $cands['TRUE_TARGET'] = $x['removed_true_target'];
            }
            $st['items']++;
            $item = ['chunk' => $ch, 'ref' => $L, 'listing' => $FA[$aid]['title'], 'candidates' => []];
            $unguardedV1 = false;
            $unguardedNow = false;
            foreach ($cands as $cref => $c) {
                $vid = (int) $c['vpg_variant_id'];
                $chk = Veto::check($FA[$aid], $FV[$vid], 1, $vetoCtx($vid));
                $nowCodes = array_values(array_unique(array_column($chk['vetoes'], 'code')));
                $v1 = $p1V1[$ch][$L][$cref] ?? null;
                $v1Codes = $v1 !== null ? array_values(array_unique(array_column($v1['vetoes'], 'code'))) : null;
                $isTrue = $vid === $trueVid;
                $st['candidate_pairs']++;
                $st['vetoed_now'] += $nowCodes !== [] ? 1 : 0;
                $st['vetoed_v1'] += ($v1Codes ?? []) !== [] ? 1 : 0;
                $gNow = $sideGuard($nowCodes, $chk['flags']);
                $gV1 = $v1 !== null ? $sideGuard($v1Codes, $v1['flags']) : null;
                if ($isTrue) {
                    $st['true_pairs']++;
                    $st['true_pairs_vetoed_v1'] += ($v1Codes ?? []) !== [] ? 1 : 0;
                    if ($nowCodes !== []) {
                        $st['true_pairs_vetoed_now'][] = ['ref' => $L, 'listing' => $FA[$aid]['title'], 'item' => $FV[$vid]['title'], 'vetoes' => $nowCodes];
                    }
                } else {
                    $unguardedV1 = $unguardedV1 || $gV1 === 'unguarded';
                    $unguardedNow = $unguardedNow || $gNow === 'unguarded';
                }
                $j = $p1Judge[$ch][$L] ?? null;
                if ($j !== null && ($j['outcome'] ?? '') === 'match' && ($j['chosen_ref'] ?? null) === $cref) {
                    $st['judge_matches']++;
                    if ($nowCodes !== []) {
                        $st['judge_match_now_vetoed'][] = ['ref' => $L, 'listing' => $FA[$aid]['title'], 'item' => $FV[$vid]['title'], 'vetoes' => $nowCodes,
                            'judge_confidence' => $j['confidence'] ?? null, 'is_barcode_truth' => $isTrue];
                    }
                }
                $item['candidates'][$cref] = ['vpg_variant_id' => $vid, 'title' => $FV[$vid]['title'], 'is_true' => $isTrue,
                    'v1' => $v1 !== null ? ['vetoes' => $v1Codes, 'flags' => $v1['flags'], 'guard' => $gV1] : null,
                    'now' => ['vetoes' => $nowCodes, 'flags' => $chk['flags'], 'guard' => $gNow]];
            }
            $st['items_with_unguarded_non_true_candidate_v1'] += $unguardedV1 ? 1 : 0;
            $st['items_with_unguarded_non_true_candidate_now'] += $unguardedNow ? 1 : 0;
            $p1Items[] = $item;
        }
        $p1['chunks'][$ch] = $st;
    }
    file_put_contents("$out/pilot1_recheck.json", json_encode($p1 + ['items' => $p1Items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $summary['pilot1_recheck'] = $p1 + ['file' => "$out/pilot1_recheck.json",
        'guard' => 'vetoed = hard veto; flagged = a soft flag naming a text difference (never Key); unguarded = neither'];
    logmsg('pilot-1 recheck written');
}

if (isset($opt['no-judge'])) {
    $summary['run']['seconds'] = round(microtime(true) - $t0, 1);
    file_put_contents("$out/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    logmsg('done (no judge chunks)');
    exit(0);
}

// ───────────────────────────── 10 pilot-2 judge chunks (barcode-blind, open style, stratified) ─────────────────────────────
// Pilot-1 recommendations 6, 10, 11: every item is open style (a search list of 15, the true item shuffled in
// or removed), strata cover the non-liquid traps pilot 1 missed, every chunk carries canaries (2 leave-one-out
// + 1 barcode pair), and answer keys + ref maps live in --private, outside the run folder.
$promptVersion = hash_file('sha256', $promptFile);
mt_srand(20260927);
$promptRel = 'tools/first_match/prompts/' . basename($promptFile);

$context = [
    'naming_hints' => [
        'listing_channel' => 'electrofag: liquids are "<Line> <Flavour> 10ml Nic Salt E Liquid - 20mg" or "<Flavour> <Line> Nic Salt 10ml - 20mg"; pods/devices "<Line> Prefilled Pods - <Flavour>"',
        'central_items' => 'central items are Vape and Go listings: usually "<Flavour> Nic Salt E-Liquid by <Line> 10ml | 20mg" or "<Flavour> <Line> Pods" (flavour first); Vape and Go "brands" are often product lines',
    ],
    'confirmed_aliases' => [],
    'confirmed_flavour_synonyms' => [],
];

$forbiddenFor = function (array $ids, array $siteRows): array {
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
$sameDupGroup = function (int $a, int $b) use ($dupGroupOf): bool {
    return array_intersect($dupGroupOf[$a] ?? [], $dupGroupOf[$b] ?? []) !== [];
};
$strengthSet = function (int $vid) use ($seed, $prodStrengths): array {
    return array_map('floatval', array_keys($prodStrengths[$seed[$vid]['product_id']] ?? []));
};
$fam = fn (array $f) => array_values(array_diff($f['brand_family'] ?? [], Veto::GENERIC_LINE_WORDS));
$sameLine = fn (array $a, array $b) => array_intersect($fam($a), $fam($b)) !== [] || ((int) $a['product_id'] === (int) $b['product_id']);

// truth pool: ALT listings sold in 365 days whose truth is a clean barcode pair (one seed item, no lane flag,
// no veto on the pair)
$truth = [];
foreach ($records as $id => $r) {
    if ($r['lane'] !== 'barcode' || $r['target_vpg_variant_id'] === null || $r['units_365d'] <= 0 || $r['target_vetoes'] !== []) {
        continue;
    }
    if (array_diff(array_keys($lanes[$id]['flags']), ['transfer_agrees']) !== []) {
        continue;
    }
    $truth[] = $id;
}
usort($truth, fn ($a, $b) => [$records[$b]['units_365d'], $records[$b]['units_30d'], $a] <=> [$records[$a]['units_365d'], $records[$a]['units_30d'], $b]);

// search list for one listing: the listing's own candidates in prescore order (the usual top 15 + siblings, and
// a deeper top 60 from which a stratum's hard negative may be drawn), VPG duplicates of the truth removed.
// A negative should differ from the truth itself: compared truth-vs-negative, it needs a hard veto or a soft flag
// that names a text difference. A sibling variant of the truth's own product (or one with the same identity words)
// that the rules cannot tell apart from the truth ("Xlim V3 Pods - 0.8 ohm" and "Xlim V3 Pods - 0.8 ohm | 3ml")
// would make a pair ambiguous and would score a correct loo match as a false merge, so it is left out and listed
// in the answer key. Other unseparated negatives (another product: "Aspire Pixo" vs "Aspire Gotek X", where the
// rules read no line words on hardware) stay in, and the answer key names them so a person reviews any match on one.
$idSet = function (array $x): string {
    $t = array_values(array_unique($x['id_tokens']));
    sort($t);
    return implode(' ', $t);
};
$negCache = [];
$negatives = function (int $id, int $tgt) use (&$negCache, $C, $FA, $seed, $vetoCtx, $sameDupGroup, $DIFF_FLAGS, $idSet): array {
    if (isset($negCache[$id])) {
        return $negCache[$id];
    }
    $f = $FA[$id];
    $byId = [];
    $dropped = [];
    foreach (array_merge($C->generate($f, Candidates::TOP_K, [$tgt => true]), $C->generate($f, 60, [$tgt => true])) as $c) {
        if (isset($byId[$c['id']]) || $c['id'] === $tgt || $sameDupGroup($c['id'], $tgt)) {
            continue;
        }
        $vsTruth = Veto::check($seed[$tgt], $seed[$c['id']], 1, $vetoCtx($c['id']));
        $unseparated = $vsTruth['vetoes'] === [] && array_intersect($vsTruth['flags'], $DIFF_FLAGS) === [];
        if ($unseparated && ((int) $seed[$c['id']]['product_id'] === (int) $seed[$tgt]['product_id'] || $idSet($seed[$c['id']]) === $idSet($seed[$tgt]))) {
            $byId[$c['id']] = false;
            $dropped[] = (int) $c['id'];
            continue;
        }
        $chk = Veto::check($f, $seed[$c['id']], 1, $vetoCtx($c['id']));
        $byId[$c['id']] = $c + ['vetoes' => array_column($chk['vetoes'], 'code'), 'soft' => $chk['flags'], 'unseparated' => $unseparated];
    }
    $byId = array_filter($byId);
    $pool = array_values($byId);
    usort($pool, fn ($a, $b) => [$b['prescore'], $a['id']] <=> [$a['prescore'], $b['id']]);
    foreach ($pool as $i => $c) {
        $pool[$i]['rank'] = $i + 1;
    }
    if (count($negCache) > 400) {
        $negCache = [];
    }
    return $negCache[$id] = ['pool' => $pool, 'dropped' => $dropped];
};

// strata: [predicate on (listing, truth), hard negative predicate on (truth, candidate)]
$num = fn ($x) => $x === null ? null : Text::num($x);
$flav = fn (array $x) => $x['flavour_tokens'] === null ? [] : array_values(array_unique(Flavour::clean($x['flavour_tokens'])));
$strata = [
    'multipack_pack_size' => [
        fn (array $l, array $t) => (int) ($t['pack_units'] ?? 0) >= 2 || (int) ($l['pack_units'] ?? 0) >= 2
            || !empty($t['listing_multiplier']) || !empty($l['listing_multiplier']),
        fn (array $t, array $c) => $sameLine($t, $c) && ($c['pack_units'] !== $t['pack_units'] || ($c['listing_multiplier'] ?? null) !== ($t['listing_multiplier'] ?? null))
            && $flav($c) == $flav($t),
    ],
    'coil_cartridge_ohm' => [
        fn (array $l, array $t) => in_array($t['form'], ['coil', 'refill_pod_cartridge'], true) && $t['resistance_ohm'] !== null,
        fn (array $t, array $c) => in_array($c['form'], ['coil', 'refill_pod_cartridge'], true) && $c['resistance_ohm'] !== null
            && $num($c['resistance_ohm']) !== $num($t['resistance_ohm']) && $sameLine($t, $c),
    ],
    'device_colour' => [
        fn (array $l, array $t) => in_array($t['form'], ['kit', 'pod_kit'], true) && $t['colour'] !== null,
        fn (array $t, array $c) => $c['form_class'] === 'device' && $c['colour'] !== null && $c['colour'] !== $t['colour'] && $sameLine($t, $c),
    ],
    'kit_vs_refill' => [
        fn (array $l, array $t) => in_array($t['form_class'], ['device', 'pod_refill'], true),
        fn (array $t, array $c) => in_array($c['form_class'], ['device', 'pod_refill'], true) && $c['form_class'] !== $t['form_class']
            && $sameLine($t, $c),
    ],
    'puff_count_variant' => [
        fn (array $l, array $t) => in_array($t['form_class'], ['device', 'pod_refill'], true) && ($t['puffs'] !== null || $t['line_numbers'] !== []),
        fn (array $t, array $c) => in_array($c['form_class'], ['device', 'pod_refill'], true) && $sameLine($t, $c)
            && (($c['puffs'] !== null && $t['puffs'] !== null && (int) $c['puffs'] !== (int) $t['puffs'])
                || ($c['line_numbers'] !== [] && $t['line_numbers'] !== [] && array_diff($c['line_numbers'], $t['line_numbers']) !== []
                    && array_diff($t['line_numbers'], $c['line_numbers']) !== [])),
    ],
    'liquid_flavour_superset' => [
        fn (array $l, array $t) => $t['form_class'] === 'liquid' && $flav($t) !== [],
        fn (array $t, array $c) => $c['form_class'] === 'liquid' && $sameLine($t, $c) && $flav($c) !== [] && $flav($c) != $flav($t)
            && (array_diff($flav($t), $flav($c)) === [] || array_diff($flav($c), $flav($t)) === []),
    ],
];
$quota = ['device_colour' => 15, 'coil_cartridge_ohm' => 10, 'kit_vs_refill' => 10, 'multipack_pack_size' => 5,
    'puff_count_variant' => 10, 'liquid_flavour_superset' => 10];
$looQuota = ['device_colour' => 4, 'coil_cartridge_ohm' => 3, 'kit_vs_refill' => 3, 'multipack_pack_size' => 2,
    'puff_count_variant' => 4, 'liquid_flavour_superset' => 4];
$order = ['multipack_pack_size', 'coil_cartridge_ohm', 'device_colour', 'kit_vs_refill', 'puff_count_variant', 'liquid_flavour_superset'];
// canaries: high-volume consumables with a stated strength and flavour on both sides (unambiguous known answers)
$strata['canary'] = [
    fn (array $l, array $t) => Veto::consumable($t) && $l['strength_mg'] !== null && $t['strength_mg'] !== null
        && $l['flavour_tokens'] !== null && $t['flavour_tokens'] !== null,
    null,
];

$used = [];
$usedTargets = [];
/**
 * Build one open-style item: 14 negatives + the truth (pair) or 15 negatives (loo), with at least one hard
 * negative for the stratum when $hard is given. Returns null when the stratum cannot be shown for this listing.
 */
$buildItem = function (int $id, bool $loo, ?callable $hard) use ($records, $seed, $negatives): ?array {
    $tgt = (int) $records[$id]['target_vpg_variant_id'];
    ['pool' => $pool, 'dropped' => $dropped] = $negatives($id, $tgt);
    $n = $loo ? 15 : 14;
    $pick = array_slice($pool, 0, $n);
    $hardIds = [];
    $inserted = null;
    if ($hard !== null) {
        $hardAll = array_values(array_filter($pool, fn ($c) => $hard($seed[$tgt], $seed[$c['id']])));
        if ($hardAll === []) {
            return null;
        }
        $inPick = array_values(array_filter($pick, fn ($c) => $hard($seed[$tgt], $seed[$c['id']])));
        if ($inPick === []) {
            array_splice($pick, count($pick) - 1, 1, [$hardAll[0]]);
            $inPick = [$hardAll[0]];
            $inserted = $hardAll[0]['rank'];
        }
        $hardIds = array_map(fn ($c) => (int) $c['id'], $inPick);
    }
    if (count($pick) < ($loo ? 5 : 4)) {
        return null;
    }
    $set = array_map(fn ($c) => ['vpg_variant_id' => (int) $c['id'], 'role' => 'negative', 'prescore' => $c['prescore'], 'vetoes' => $c['vetoes'], 'soft' => $c['soft'],
        'unseparated' => $c['unseparated']], $pick);
    if (!$loo) {
        $set[] = ['vpg_variant_id' => $tgt, 'role' => 'truth', 'prescore' => null, 'vetoes' => array_column($records[$id]['target_vetoes'], 'code'),
            'soft' => $records[$id]['target_soft_flags'], 'unseparated' => false];
    }
    shuffle($set);
    return ['id' => $id, 'target' => $tgt, 'loo' => $loo, 'set' => $set, 'hard' => $hardIds, 'hard_inserted_from_rank' => $inserted,
        'indistinguishable_excluded' => $dropped];
};

$select = function (string $stratum, int $want, bool $loo) use (&$used, &$usedTargets, $truth, $strata, $FA, $seed, $records, $A, $buildItem): array {
    [$is, $hard] = $strata[$stratum];
    $out = [];
    $perProduct = [];
    $perBrand = [];
    $brandCap = max(2, (int) ceil($want / 3));
    foreach ($truth as $id) {
        if (count($out) >= $want) {
            break;
        }
        $tgt = (int) $records[$id]['target_vpg_variant_id'];
        if (isset($used[$id]) || isset($usedTargets[$tgt]) || !$is($FA[$id], $seed[$tgt])) {
            continue;
        }
        $pid = (int) $seed[$tgt]['product_id'];
        $bk = TitlePattern::brandKey((string) ($A[$id]['brand'] ?? ''));
        if (($perProduct[$pid] ?? 0) >= 2 || ($perBrand[$bk] ?? 0) >= $brandCap) {
            continue;
        }
        $item = $buildItem($id, $loo, $hard);
        if ($item === null) {
            continue;
        }
        $item['stratum'] = $stratum;
        $out[] = $item;
        $used[$id] = true;
        $usedTargets[$tgt] = true;
        $perProduct[$pid] = ($perProduct[$pid] ?? 0) + 1;
        $perBrand[$bk] = ($perBrand[$bk] ?? 0) + 1;
    }
    return $out;
};

$strataItems = [];
foreach ($order as $st) {
    $strataItems[$st] = $select($st, $quota[$st], false);
}
$looItems = [];
foreach ($order as $st) {
    $looItems[$st] = $select($st, $looQuota[$st], true);
}
$canPairs = $select('canary', 3, false);
$canLoo = $select('canary', 6, true);

// three chunks of about equal size: strata round-robin, then 1 canary pair + 2 canary loo each
$chunks = [[], [], []];
$k = 0;
foreach ([$strataItems, $looItems] as $group) {
    foreach ($order as $st) {
        foreach ($group[$st] as $it) {
            $chunks[$k % 3][] = $it + ['role' => 'stratum'];
            $k++;
        }
    }
}
for ($c = 0; $c < 3; $c++) {
    if (isset($canPairs[$c])) {
        $chunks[$c][] = $canPairs[$c] + ['role' => 'canary'];
    }
    foreach ([2 * $c, 2 * $c + 1] as $j) {
        if (isset($canLoo[$j])) {
            $chunks[$c][] = $canLoo[$j] + ['role' => 'canary'];
        }
    }
    shuffle($chunks[$c]);
}

$answers = ['prompt_version' => $promptVersion, 'prompt' => $promptRel, 'engine' => $engine, 'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'design' => 'pilot 2: open style only (15 candidates; truth shuffled in for pairs, removed for loo), stratified, 2 loo + 1 pair canaries per chunk',
    'scoring' => ['pair' => 'correct = outcome in acceptable_outcomes (a match must be on expected_ref); expected_outcome is the ideal answer (cannot_tell where the strength-missing rule, a pending alias or a one-sided "N in 1" applies); a match on any other ref = wrong-ref; any other outcome = safe miss',
        'loo' => 'any match = would-be false merge; no_match_in_list or cannot_tell = safe',
        'closest_ref' => 'review hint only: for a cannot_tell pair, expected_closest_ref is the truth; never scored as a link',
        'unseparated' => 'a match on a ref in negatives_unseparated_from_truth_refs counts as wrong-ref / false merge but needs a person to confirm it (the rules cannot separate that candidate from the truth)'],
    'chunks' => []];
$chunkFiles = [];
$strataCounts = [];
$nearDupExcluded = ['items' => 0, 'candidates' => 0, 'unseparated_negatives_kept' => 0,
    'rule' => 'a same-product (or same identity words) negative with no veto and no text-difference flag against the truth is left out; other unseparated negatives stay and are named in the answer key'];
foreach ($chunks as $ci => $items) {
    $name = 'pilot2_c' . ($ci + 1);
    $cards = [];
    $refmap = [];
    $ans = [];
    $forbid = [];
    foreach (array_values($items) as $li => $it) {
        $L = 'L' . ($li + 1);
        $id = $it['id'];
        $tgt = $it['target'];
        $cs = [];
        $map = [];
        $expRef = null;
        $hardRefs = [];
        $vetoed = [];
        $soft = [];
        $unsep = [];
        foreach ($it['set'] as $i => $c) {
            $ref = 'C' . ($i + 1);
            $vid = $c['vpg_variant_id'];
            $cs[] = JudgeCard::card($ref, $V[$vid], $FV[$vid], $strengthSet($vid));
            $map[$ref] = ['cw_id' => cwId($vid), 'vpg_variant_id' => $vid, 'role' => $c['role'], 'prescore' => $c['prescore']];
            if ($c['role'] === 'truth') {
                $expRef = $ref;
            }
            if (in_array($vid, $it['hard'], true)) {
                $hardRefs[] = $ref;
            }
            if ($c['vetoes'] !== []) {
                $vetoed[$ref] = $c['vetoes'];
            }
            if ($c['soft'] !== []) {
                $soft[$ref] = $c['soft'];
            }
            if ($c['unseparated']) {
                $unsep[] = $ref;
            }
        }
        $cards[] = ['ref' => $L, 'listing' => JudgeCard::card($L, $A[$id], $FA[$id]), 'candidates' => $cs];
        $refmap[$L] = ['listing' => ['site' => 'electrofag', 'variant_id' => $id], 'candidates' => $map]
            + ($it['loo'] ? ['removed_true_target' => ['cw_id' => cwId($tgt), 'vpg_variant_id' => $tgt]] : []);
        $rec = $records[$id];
        // the prompt's strength-missing rule, both directions (2: the listing states none; 3: the candidate states
        // none but its product is sold in 2+ strengths)
        $multiStrength = count($strengthSet($tgt)) >= 2;
        $strengthMissing = $multiStrength && ($FA[$id]['strength_mg'] === null || $seed[$tgt]['strength_mg'] === null);
        $aliasPending = array_intersect($rec['target_soft_flags'], Band::ALIAS_PENDING_FLAGS) !== [];
        // the truth pair itself carries a text difference the prompt tells the judge to respect ("Replaceable Pod
        // Vape Kit" vs "Prefilled Pod Kit", a one-sided model number): a cautious cannot_tell is also acceptable
        $textDiff = array_values(array_intersect($rec['target_soft_flags'], $DIFF_FLAGS));
        // "N in 1" on one side only: the prompt makes it missing information (cannot_tell) unless the other side
        // states a pack ("4 in 1" vs "Pack Size: 4 Pack" is the same box)
        $lf = $FA[$id];
        $tf = $seed[$tgt];
        $nIn1OneSide = ($lf['multi_n'] === null) !== ($tf['multi_n'] === null);
        $otherPack = $lf['multi_n'] === null ? (int) ($lf['pack_units'] ?? 0) : (int) ($tf['pack_units'] ?? 0);
        if ($it['loo']) {
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
        $ans[$L] = [
            'kind' => $it['loo'] ? 'loo' : 'pair', 'role' => $it['role'], 'stratum' => $it['stratum'],
            'expected_outcome' => $expected, 'acceptable_outcomes' => $acceptable, 'expected_ref' => $it['loo'] ? null : $expRef,
            'expected_closest_ref' => ($expected === 'cannot_tell' && !$it['loo']) ? $expRef : null,
            'hard_negative_refs' => $hardRefs, 'hard_negative_inserted_from_search_rank' => $it['hard_inserted_from_rank'],
            'indistinguishable_from_truth_excluded' => array_map(fn ($v) => ['vpg_variant_id' => $v, 'title' => $seed[$v]['title']], $it['indistinguishable_excluded']),
            'negatives_unseparated_from_truth_refs' => $unsep,
            'vetoed_refs' => $vetoed, 'soft_flagged_refs' => $soft,
            'listing' => ['alt_variant_id' => $id, 'title' => $FA[$id]['title'], 'units_365d' => $rec['units_365d']],
            'truth' => ['vpg_variant_id' => $tgt, 'cw_id' => cwId($tgt), 'title' => $seed[$tgt]['title'], 'shown' => !$it['loo'],
                'target_soft_flags' => $rec['target_soft_flags'], 'strength_missing_rule' => $strengthMissing, 'alias_pending' => $aliasPending,
                'text_difference_flags' => $textDiff, 'product_strengths_mg' => $strengthSet($tgt)],
            'note' => 'truth from a clean barcode pair (strong evidence, not ground truth)',
        ];
        $forbid = array_merge($forbid, $forbiddenFor([$id], $A), $forbiddenFor(array_merge([$tgt], array_column($it['set'], 'vpg_variant_id')), $V));
        $nearDupExcluded['unseparated_negatives_kept'] += count($unsep);
        if ($it['indistinguishable_excluded'] !== []) {
            $nearDupExcluded['items']++;
            $nearDupExcluded['candidates'] += count($it['indistinguishable_excluded']);
        }
        $sk = $it['role'] === 'canary' ? 'canary_' . ($it['loo'] ? 'loo' : 'pair') : $it['stratum'] . ($it['loo'] ? ':loo' : ':pair');
        $strataCounts[$sk] = ($strataCounts[$sk] ?? 0) + 1;
    }
    $payload = [
        'chunk' => $name,
        'prompt_version' => $promptVersion,
        'instructions' => $promptRel,
        'purpose' => 'Judge each listing against its own candidates only, following the instructions file.',
        'context' => $context,
        'items' => $cards,
    ];
    // the cards (everything that comes from the catalogues) must be blind; the head is fixed text of this tool
    // (its prompt sha256 may hold 8 digits in a row, which the card check would refuse)
    JudgeCard::assertBlind(['items' => $cards], $forbid);
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $head = $payload;
    unset($head['items']);
    $lines = array_map(fn ($x) => json_encode($x, $flags), $cards);
    $json = substr((string) json_encode($head, $flags), 0, -1) . ',"items":[' . "\n" . implode(",\n", $lines) . "\n]}\n";
    JudgeCard::assertBlind(['items' => json_decode($json, true, 512, JSON_THROW_ON_ERROR)['items']], $forbid);
    file_put_contents("$out/judge/$name.json", $json);
    file_put_contents("$private/$name.refmap.json", json_encode(['chunk' => $name, 'prompt_version' => $promptVersion, 'refs' => $refmap],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $answers['chunks'][$name] = $ans;
    $chunkFiles[$name] = ['file' => "$out/judge/$name.json", 'items' => count($cards), 'bytes' => strlen($json), 'assert_blind' => 'passed'];
}
file_put_contents("$private/pilot2_answers.json", json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
ksort($strataCounts);

$summary['judge_pilot2'] = [
    'prompt' => $promptRel,
    'prompt_sha256' => $promptVersion,
    'chunks' => $chunkFiles,
    'strata_counts' => $strataCounts,
    'quota' => ['pairs' => $quota, 'loo' => $looQuota, 'canaries_per_chunk' => ['loo' => 2, 'pair' => 1]],
    'truth_pool' => count($truth),
    'negatives_indistinguishable_from_truth_excluded' => $nearDupExcluded,
    'private' => "$private (refmaps + pilot2_answers.json; outside the run folder; judges must never read)",
];
$summary['run']['seconds'] = round(microtime(true) - $t0, 1);
file_put_contents("$out/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
logmsg('done');
echo json_encode(['summary' => "$out/summary.json", 'seconds' => $summary['run']['seconds'], 'chunks' => array_keys($chunkFiles), 'strata_counts' => $strataCounts]) . "\n";
