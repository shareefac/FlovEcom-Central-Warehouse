<?php

declare(strict_types=1);

/**
 * First-time match, assembly of a full judge run (plan §7.3 steps 6→7): joins the barcode-blind judges' answers to
 * real ids through the private ref maps, scores the canaries, bands every listing with CW\Matching\Band (b2.1) and
 * writes the staff proposals. Nothing is linked: every output is a proposal a person confirms.
 *
 *   nice -n 19 php -d memory_limit=2G tools/first_match/assemble.php --run=/root/cw_work/first_match/run3 \
 *       [--private=/root/cw_work/first_match/private/run3] [--run-id=run3-sold] \
 *       [--journal=<workflow journal.jsonl> [--label-prefix=run3-judge:] [--session-model=claude-opus-5-5]] \
 *       [--add=<chunk>=<result.json> --add-model=<model id>]
 *
 * --journal  (re)extracts the judges' results from a workflow journal (lines {"type":"result"} whose "started" label
 *            is <prefix>cNNN; the model comes from the agent's .meta.json next to the journal, absent = the session
 *            model) into <run>/judge_raw.jsonl. The last result per chunk wins.
 * --add      replaces one chunk's result with a re-judged one (a file holding {"items":[...]} or the bare array), e.g.
 *            after re-running a chunk that failed validation or a canary; kept in judge_raw.jsonl with its model.
 * Without either, the existing <run>/judge_raw.jsonl is used, so the assembly is re-runnable offline.
 *
 * Reads: <run>/judge/<run>_cNNN.json (+ judge_manifest.json sha256), <private>/<run>_answers.json, <private>/<chunk>.refmap.json,
 *        <run>/alt_deterministic.jsonl, <run>/listings_features.jsonl (Vape and Go titles).
 * Writes: <run>/judge_raw.jsonl, <run>/judgements.jsonl, <run>/proposals.jsonl, <run>/proposals.csv,
 *         <run>/proposals_summary.json; <private>/<run>_canary_results.json (canary truths stay outside the run folder).
 *
 * Band rules (Band::final, b2.1 since 2 Oct 2026 (docs/decisions.md M26; b2.0 until then), then two routing rules on top that
 * can only lower a band; CW\Matching\StoredBand replays the same on a stored proposal):
 *  - Key only on the barcode/transfer lane when the judge picks the lane target at confidence >= 85 (b2.0: 90), units 1, the
 *    target carries no veto or soft flag, and no line relabel is pending (Band + the answer file's key_possible);
 *  - the four pending line relabels (Veto::PENDING_LINE_ALIASES) are never Key: a listing whose lane target differs by a
 *    pending relabel, a judge pick on a relabel partner, or a non-match whose closest item (or, on the candidates lane,
 *    any listed item) is a relabel partner goes to "Manual (relabel)" for the business to map by hand;
 *  - an answer whose listing_extract quote is not verbatim is capped at Check;
 *  - a chunk that fails validation or a canary (wrong-ref pair match, leave-one-out match) is not used: its listings are
 *    "Not judged" until the chunk is re-run.
 * The summary's listing_extract_form counts the judges' form answers against the enum (CW\Matching\Form; M29): in the
 * enum, a known spelling normalised ("prefilled pod kit -> pod_kit/prefilled"), or unrecognised. Information only.
 */

require __DIR__ . '/../../src/Matching/autoload.php';

use CW\Matching\Band;
use CW\Matching\Form;

ini_set('memory_limit', '2G');
$t0 = microtime(true);

$opt = getopt('', ['run:', 'private:', 'run-id:', 'journal:', 'label-prefix:', 'session-model:', 'add:', 'add-model:']);
$runDir = rtrim((string) ($opt['run'] ?? ''), '/');
if ($runDir === '' || !is_dir("$runDir/judge")) {
    fwrite(STDERR, "--run=<run folder with judge/> is required\n");
    exit(2);
}
$run = basename($runDir);
$private = rtrim((string) ($opt['private'] ?? dirname($runDir) . "/private/$run"), '/');
$runId = (string) ($opt['run-id'] ?? "$run-sold");
$sessionModel = (string) ($opt['session-model'] ?? 'claude-opus-5-5');
$labelPrefix = (string) ($opt['label-prefix'] ?? "$run-judge:");
const MANUAL = 'Manual (relabel)';
const NOT_JUDGED = 'Not judged';
$FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

function logmsg(string $m): void
{
    fwrite(STDERR, '[' . gmdate('H:i:s') . "] $m\n");
}

function readJson(string $f): array
{
    return json_decode((string) file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
}

// ───────────── 1 raw judge results (journal → judge_raw.jsonl, or --add, or the existing file)
$rawFile = "$runDir/judge_raw.jsonl";
$raw = [];
if (is_file($rawFile)) {
    foreach (file($rawFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $r = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
        $raw[$r['chunk']] = $r;
    }
}
if (isset($opt['journal'])) {
    $jf = (string) $opt['journal'];
    $started = [];
    $n = 0;
    $fromJournal = [];
    foreach (file($jf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $i => $l) {
        $d = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
        if (($d['type'] ?? '') === 'started') {
            $started[$d['key']] = $d;
        } elseif (($d['type'] ?? '') === 'result' && isset($started[$d['key']])) {
            $s = $started[$d['key']];
            $label = (string) ($s['label'] ?? '');
            if (strncmp($label, $labelPrefix, strlen($labelPrefix)) !== 0 || !preg_match('/^c(\d{3})$/', substr($label, strlen($labelPrefix)), $m)) {
                continue;
            }
            $chunk = "{$run}_c$m[1]";
            $metaFile = dirname($jf) . '/agent-' . $d['agentId'] . '.meta.json';
            $meta = is_file($metaFile) ? readJson($metaFile) : [];
            $attempts = count(array_filter($started, fn ($x) => ($x['label'] ?? '') === $label));
            $fromJournal[$chunk] = ['chunk' => $chunk, 'label' => $label, 'source' => 'journal', 'journal' => $jf, 'journal_line' => $i + 1,
                'journal_key' => $d['key'], 'agent_id' => $d['agentId'], 'model' => $meta['model'] ?? $sessionModel,
                'model_source' => isset($meta['model']) ? 'agent meta.json' : 'session model (no model in agent meta.json)',
                'attempts_started' => $attempts, 'result' => $d['result']];
            $n++;
        }
    }
    foreach ($fromJournal as $c => $r) {
        $raw[$c] = $r;
    }
    logmsg("journal: $n results for $labelPrefix* -> " . count($fromJournal) . ' chunks');
}
if (isset($opt['add'])) {
    if (!isset($opt['add-model']) || !preg_match('/^([a-z0-9]+_c\d{3})=(.+)$/', (string) $opt['add'], $m)) {
        fwrite(STDERR, "--add=<chunk>=<result.json> needs --add-model=<model id>\n");
        exit(2);
    }
    $res = readJson($m[2]);
    $raw[$m[1]] = ['chunk' => $m[1], 'label' => null, 'source' => 'file', 'file' => realpath($m[2]), 'model' => (string) $opt['add-model'],
        'model_source' => '--add-model', 'saved_at_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'result' => array_is_list($res) ? ['items' => $res] : $res];
}
ksort($raw);
if ($raw === []) {
    fwrite(STDERR, "no judge results: pass --journal or --add, or keep $rawFile\n");
    exit(2);
}
if (isset($opt['journal']) || isset($opt['add'])) {
    $fh = fopen("$rawFile.tmp", 'w');
    foreach ($raw as $r) {
        fwrite($fh, json_encode($r, $FLAGS) . "\n");
    }
    fclose($fh);
    rename("$rawFile.tmp", $rawFile);
}

// ───────────── 2 inputs
$answers = readJson("$private/{$run}_answers.json");
$manifest = readJson("$runDir/judge_manifest.json");
$promptSha = (string) $answers['prompt_version'];
$promptFile = __DIR__ . '/prompts/' . basename((string) $answers['prompt']);
$promptShaFile = is_file($promptFile) ? hash_file('sha256', $promptFile) : null;
$engine = (string) $answers['engine'];
// The engine's Band version must be one this Band knows. b2.0 -> b2.1 (M26) changed only final()'s Key threshold, not
// provisional(), so answers built with b2.0 assemble under the current rules (the threshold of Band::VERSION).
if (Band::versionOf($engine) === null) {
    fwrite(STDERR, "engine $engine was not built with a known Band version (" . implode(', ', array_keys(Band::KEY_MIN_BY_VERSION)) . ")\n");
    exit(2);
}
$manifestBy = array_column($manifest['chunks'], null, 'chunk');
$chunkNames = array_keys($answers['chunks']);

// ───────────── 3 validation (prompt schema + consistency rules, refs of the item, verbatim quotes) and canaries
$FIELDS = ['brand_line', 'flavour', 'strength', 'nic_type', 'volume', 'pack', 'puffs', 'form', 'colour', 'resistance'];
$EXTRACT = ['brand_line', 'flavour', 'strength_mg', 'nic_type', 'volume_ml', 'pack_units', 'puffs', 'form'];
$OUTCOMES = ['match', 'no_match_in_list', 'cannot_tell', 'multiple_plausible', 'not_a_product'];
$FVALS = ['agree', 'conflict', 'unknown', 'n_a'];
$validateItem = function ($it, array $card) use ($FIELDS, $EXTRACT, $OUTCOMES, $FVALS): array {
    $e = [];
    $w = [];
    if (!is_array($it) || array_is_list($it)) {
        return [['not an object'], []];
    }
    $req = ['ref', 'outcome', 'chosen_ref', 'confidence', 'fields', 'units_per_item', 'listing_extract', 'reason'];
    foreach ($req as $k) {
        if (!array_key_exists($k, $it)) {
            $e[] = "missing $k";
        }
    }
    foreach (array_keys($it) as $k) {
        if (!in_array($k, array_merge($req, ['closest_ref']), true)) {
            $e[] = "extra key $k";
        }
    }
    if ($e !== []) {
        return [$e, $w];
    }
    $crefs = array_column($card['candidates'], 'ref');
    if (!is_string($it['ref']) || $it['ref'] !== $card['ref']) {
        $e[] = 'ref ' . json_encode($it['ref']) . ' != ' . $card['ref'];
    }
    if (!in_array($it['outcome'], $OUTCOMES, true)) {
        $e[] = 'outcome';
    }
    foreach (['chosen_ref', 'closest_ref'] as $k) {
        $v = $it[$k] ?? null;
        if ($v !== null && (!is_string($v) || !preg_match('/^C[0-9]+$/', $v) || !in_array($v, $crefs, true))) {
            $e[] = "$k " . json_encode($v) . ' not a candidate of this item';
        }
    }
    if (!is_int($it['confidence']) || $it['confidence'] < 0 || $it['confidence'] > 100) {
        $e[] = 'confidence';
    }
    if (!is_array($it['fields']) || array_diff($FIELDS, array_keys($it['fields'])) !== [] || array_diff(array_keys($it['fields']), $FIELDS) !== []) {
        $e[] = 'fields keys';
    } else {
        foreach ($it['fields'] as $k => $v) {
            if (!in_array($v, $FVALS, true)) {
                $e[] = "fields.$k";
            }
        }
    }
    $u = $it['units_per_item'];
    if ($u !== null && (!is_int($u) || $u < 1 || $u > 200)) {
        $e[] = 'units_per_item';
    }
    if (!is_array($it['listing_extract']) || array_diff($EXTRACT, array_keys($it['listing_extract'])) !== [] || array_diff(array_keys($it['listing_extract']), $EXTRACT) !== []) {
        $e[] = 'listing_extract keys';
    } else {
        $L = $card['listing'];
        $srcs = array_merge([(string) ($L['product_title'] ?? ''), (string) ($L['variant_title'] ?? ''), (string) ($L['brand'] ?? '')], array_map('strval', $L['attributes'] ?? []));
        foreach ($it['listing_extract'] as $k => $q) {
            if (!is_array($q) || count($q) !== 2 || !array_key_exists('value', $q) || !array_key_exists('quote', $q)) {
                $e[] = "listing_extract.$k shape";
                continue;
            }
            if (!(is_string($q['value']) || is_int($q['value']) || is_float($q['value']) || $q['value'] === null) || !(is_string($q['quote']) || $q['quote'] === null)) {
                $e[] = "listing_extract.$k types";
                continue;
            }
            if (is_string($q['quote'])) {
                $ok = false;
                foreach ($srcs as $s) {
                    if ($q['quote'] !== '' && str_contains($s, $q['quote'])) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    $w[] = "quote_not_verbatim:$k";
                }
            }
        }
    }
    if (!is_string($it['reason']) || mb_strlen($it['reason']) > 400) {
        $e[] = 'reason';
    }
    if ($e !== []) {
        return [$e, $w];
    }
    // consistency rules of the prompt
    $isMatch = $it['outcome'] === 'match';
    if (($it['chosen_ref'] !== null) !== $isMatch) {
        $e[] = 'chosen_ref iff match';
    }
    if ($isMatch && ($it['closest_ref'] ?? null) !== null) {
        $e[] = 'closest_ref on a match';
    }
    if (!$isMatch && $u !== null) {
        $e[] = 'units_per_item on a non-match';
    }
    if ($isMatch && in_array('conflict', $it['fields'], true)) {
        $e[] = 'match with a conflict field';
    }
    if ($isMatch && $it['fields']['strength'] === 'unknown' && $it['confidence'] > 89) {
        $e[] = 'match with strength unknown above 89';
    }
    if ($isMatch && $u === null) {
        $w[] = 'match_without_units';
    }
    return [$e, $w];
};

$chunkInfo = [];
$canaryRows = [];
$useChunk = [];
$judged = [];
foreach ($chunkNames as $ch) {
    $file = "$runDir/judge/$ch.json";
    $sha = hash_file('sha256', $file);
    $card = readJson($file);
    $refmap = readJson("$private/$ch.refmap.json")['refs'];
    $ans = $answers['chunks'][$ch]['items'];
    $info = ['chunk' => $ch, 'lane' => $answers['chunks'][$ch]['lane'], 'file' => $file, 'sha256' => $sha,
        'sha256_matches_manifest' => $sha === ($manifestBy[$ch]['sha256'] ?? null), 'chunk_prompt_version_ok' => ($card['prompt_version'] ?? null) === $promptSha,
        'items' => count($card['items']), 'status' => null, 'errors' => [], 'warnings' => [], 'model' => null, 'agent_id' => null, 'canaries' => []];
    $r = $raw[$ch] ?? null;
    if ($r === null) {
        $info['status'] = 'missing';
        $chunkInfo[$ch] = $info;
        continue;
    }
    $info['model'] = $r['model'];
    $info['agent_id'] = $r['agent_id'] ?? null;
    $items = $r['result']['items'] ?? null;
    if (!$info['sha256_matches_manifest'] || !$info['chunk_prompt_version_ok']) {
        $info['errors'][] = 'chunk file or prompt version changed since the build';
    }
    if (!is_array($items) || !array_is_list($items) || count($items) !== count($card['items'])) {
        $info['errors'][] = 'items: expected ' . count($card['items']) . ', got ' . (is_array($items) ? count($items) : 'none');
    } else {
        foreach ($items as $k => $it) {
            [$e, $w] = $validateItem($it, $card['items'][$k]);
            foreach ($e as $x) {
                $info['errors'][] = $card['items'][$k]['ref'] . ": $x";
            }
            foreach ($w as $x) {
                $info['warnings'][] = $card['items'][$k]['ref'] . ": $x";
            }
        }
    }
    if ($info['errors'] === []) {
        // canaries
        foreach ($items as $it) {
            $a = $ans[$it['ref']];
            if (($a['role'] ?? null) !== 'canary') {
                continue;
            }
            $out = $it['outcome'] === 'match' && $it['confidence'] < 50 ? 'cannot_tell' : $it['outcome'];
            if ($a['kind'] === 'pair') {
                $status = $out === 'match' ? ($it['chosen_ref'] === $a['expected_ref'] ? 'correct' : 'wrong_ref') : (in_array($out, $a['acceptable_outcomes'], true) ? 'accepted_abstain' : 'safe_miss');
            } else {
                $status = $out === 'match' ? 'false_merge' : 'safe';
            }
            $map = $refmap[$it['ref']]['candidates'];
            $row = ['chunk' => $ch, 'ref' => $it['ref'], 'kind' => $a['kind'], 'source' => $a['source'], 'status' => $status, 'outcome' => $it['outcome'],
                'confidence' => $it['confidence'], 'chosen_ref' => $it['chosen_ref'], 'expected_ref' => $a['expected_ref'], 'closest_ref' => $it['closest_ref'] ?? null,
                'closest_is_truth' => $a['kind'] === 'pair' && ($it['closest_ref'] ?? null) === $a['expected_ref'],
                'listing' => $a['listing'], 'truth' => ['cw_id' => $a['truth']['cw_id'], 'title' => $a['truth']['title']],
                'chosen' => $it['chosen_ref'] !== null ? $map[$it['chosen_ref']]['cw_id'] : null, 'reason' => $it['reason'], 'model' => $r['model']];
            $canaryRows[] = $row;
            $info['canaries'][] = ['ref' => $it['ref'], 'kind' => $a['kind'], 'status' => $status];
            if (in_array($status, ['wrong_ref', 'false_merge'], true)) {
                $info['errors'][] = $it['ref'] . ": canary $status";
            }
        }
    }
    $info['status'] = $info['errors'] === [] ? 'used' : 'rejected';
    if ($info['status'] === 'used') {
        $useChunk[$ch] = true;
        foreach ($items as $it) {
            $judged[$ch][$it['ref']] = $it;
        }
    }
    $chunkInfo[$ch] = $info + ['refmap' => $refmap];
}
logmsg(sprintf('chunks: %d used, %d rejected, %d missing', count($useChunk),
    count(array_filter($chunkInfo, fn ($c) => $c['status'] === 'rejected')), count(array_filter($chunkInfo, fn ($c) => $c['status'] === 'missing'))));

// ───────────── 4 deterministic records and Vape and Go titles
$need = [];
foreach ($answers['chunks'] as $ch => $c) {
    foreach ($c['items'] as $L => $a) {
        if ($a['kind'] === 'main') {
            $need[(int) $a['listing']['alt_variant_id']] = [$ch, $L];
        }
    }
}
$records = [];
$fh = fopen("$runDir/alt_deterministic.jsonl", 'r');
while (($l = fgets($fh)) !== false) {
    if (!preg_match('/^\{"alt_variant_id":(\d+),/', $l, $m) || !isset($need[(int) $m[1]])) {
        continue;
    }
    $records[(int) $m[1]] = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
}
fclose($fh);
$vpgTitle = [];
$fh = fopen("$runDir/listings_features.jsonl", 'r');
while (($l = fgets($fh)) !== false) {
    if (strncmp($l, '{"site":"vapeandgo"', 19) !== 0) {
        continue;
    }
    $d = json_decode($l, true, 512, JSON_THROW_ON_ERROR);
    $vpgTitle[(int) $d['variant_id']] = (string) $d['title'];
}
fclose($fh);
if (count($records) !== count($need)) {
    fwrite(STDERR, sprintf("alt_deterministic.jsonl holds %d of %d main listings\n", count($records), count($need)));
    exit(2);
}
logmsg(sprintf('%d main listings, %d Vape and Go titles', count($records), count($vpgTitle)));

// ───────────── 5 per listing: join, band, proposal
$item = fn (?int $vid) => $vid === null ? null : ['cw_id' => 'CWP-' . $vid, 'vpg_variant_id' => $vid, 'title' => $vpgTitle[$vid] ?? null];
$judgements = [];
$proposals = [];
$checks = ['key_without_key_possible' => 0, 'key_on_candidates_lane' => 0, 'candidates_above_check' => 0, 'key_not_on_target' => 0, 'relabel_key' => 0];
foreach ($need as $id => [$ch, $L]) {
    $a = $answers['chunks'][$ch]['items'][$L];
    $rec = $records[$id];
    $info = $chunkInfo[$ch];
    $refmap = $info['refmap'][$L] ?? readJson("$private/$ch.refmap.json")['refs'][$L];
    if ((int) $refmap['listing']['variant_id'] !== $id) {
        throw new RuntimeException("$ch $L: refmap listing mismatch");
    }
    $map = $refmap['candidates'];
    $vidOf = fn (?string $ref) => $ref === null ? null : (int) $map[$ref]['vpg_variant_id'];
    $tgt = $rec['target_vpg_variant_id'] !== null ? (int) $rec['target_vpg_variant_id'] : null;
    if ($tgt !== null && $a['lane_target_ref'] !== null && $vidOf($a['lane_target_ref']) !== $tgt) {
        throw new RuntimeException("$ch $L: lane target ref does not resolve to the record's target");
    }
    $partners = (array) ($a['relabel_partner_refs'] ?? []);
    $keyLane = in_array($rec['lane'], ['barcode', 'transfer'], true);
    $j = $judged[$ch][$L] ?? null;
    $prov = ['run_id' => $runId, 'chunk' => $ch, 'ref' => $L, 'chunk_file' => $info['file'], 'chunk_sha256' => $info['sha256'],
        'prompt_file' => (string) $answers['prompt'], 'prompt_sha256' => $promptSha, 'engine' => $engine, 'band_version' => Band::VERSION,
        'model' => $info['model'], 'judge_agent_id' => $info['agent_id']];

    $chosenVid = $j !== null ? $vidOf($j['chosen_ref']) : null;
    $closestVid = $j !== null ? $vidOf($j['closest_ref'] ?? null) : null;
    $warnings = [];
    foreach ($info['warnings'] as $w) {
        if (str_starts_with($w, "$L: ")) {
            $warnings[] = substr($w, strlen("$L: "));
        }
    }

    if ($j === null) {
        $band = NOT_JUDGED;
        $reasons = ['chunk_' . $info['status']];
        $bandV2 = null;
        $outcome = null;
    } else {
        $outcome = $j['outcome'] === 'match' && $j['confidence'] < 50 ? 'cannot_tell' : $j['outcome'];
        // candidates Band sees: the engine's list plus anything shuffled into the judged list, vetoes as shown to the judge
        $cands = [];
        foreach ($rec['candidates'] as $c) {
            $cands[(int) $c['vpg_variant_id']] = ['id' => (int) $c['vpg_variant_id'], 'prescore' => (int) $c['prescore'], 'vetoes' => $c['vetoes']];
        }
        $vetoedRefs = (array) $a['vetoed_refs'];
        foreach ($map as $ref => $m) {
            $vid = (int) $m['vpg_variant_id'];
            $shownVetoes = $vetoedRefs[$ref] ?? [];
            if (!isset($cands[$vid])) {
                $cands[$vid] = ['id' => $vid, 'prescore' => (int) ($m['prescore'] ?? 0), 'vetoes' => $shownVetoes];
            } elseif ((($cands[$vid]['vetoes'] ?? []) === []) !== ($shownVetoes === [])) {
                throw new RuntimeException("$ch $L $ref: vetoes differ between the record and the judged list");
            }
        }
        $laneFlags = array_values(array_filter($rec['flags'], fn ($f) => !str_contains($f, ':') && $f !== 'ignored_but_sold'));
        $sep = null;
        foreach ($rec['flags'] as $f) {
            if (str_starts_with($f, 'separating_field_missing:')) {
                $sep = substr($f, strlen('separating_field_missing:'));
            }
        }
        $ev = ['lane' => $rec['lane'], 'is_placeholder' => false, 'flags' => $laneFlags, 'target' => $tgt,
            'target_vetoes' => $rec['target_vetoes'], 'target_flags' => $rec['target_soft_flags'], 'candidates' => array_values($cands),
            'separating_field_missing' => $sep, 'pending_alias' => $a['relabel_pending'] !== null];
        $bandV2 = Band::final($ev, ['outcome' => $outcome, 'chosen_id' => $chosenVid, 'confidence' => (int) $j['confidence'], 'units_per_item' => $j['units_per_item']]);
        $band = $bandV2['band'];
        // a key-lane no-match below 80 is labelled by Band itself since b2.1 (ai_no_match_on_key_below_80_<conf>; M29)
        $reasons = $bandV2['reasons'];
        // routing on top of Band: pending relabels are mapped by hand, never Key
        $why = null;
        if ($a['relabel_pending'] !== null) {
            $why = 'lane_target_differs_by_pending_relabel';
        } elseif ($j['chosen_ref'] !== null && isset($partners[$j['chosen_ref']])) {
            $why = 'ai_pick_is_relabel_partner';
        } elseif ($outcome !== 'match' && $partners !== [] && (isset($partners[$j['closest_ref'] ?? '']) || !$keyLane)) {
            $why = isset($partners[$j['closest_ref'] ?? '']) ? 'ai_closest_is_relabel_partner' : 'relabel_partner_in_list';
        }
        if ($why !== null) {
            $reasons = array_merge(["$why: " . ($a['relabel_pending'] ?? implode('; ', array_unique(array_values($partners))))], ['band_v2:' . $band], $reasons);
            $band = MANUAL;
        }
        if ($band === Band::KEY && array_filter($warnings, fn ($w) => str_starts_with($w, 'quote_not_verbatim')) !== []) {
            $band = Band::CHECK;
            $reasons = array_merge(['quote_not_verbatim_capped_at_check'], $reasons);
        }
        // invariants of the Key rule
        if ($band === Band::KEY) {
            $checks['key_without_key_possible'] += $a['key_possible'] ? 0 : 1;
            $checks['key_on_candidates_lane'] += $keyLane ? 0 : 1;
            $checks['key_not_on_target'] += ($chosenVid === $tgt && $j['confidence'] >= Band::KEY_MIN_CONFIDENCE && $j['units_per_item'] === 1) ? 0 : 1;
            $checks['relabel_key'] += $a['relabel_pending'] !== null ? 1 : 0;
        }
        if (!$keyLane && in_array($band, [Band::KEY], true)) {
            $checks['candidates_above_check']++;
        }
    }

    $proposedVid = in_array($band, [Band::KEY, Band::CHECK], true) ? $chosenVid : null;
    $proposed = $band === Band::NEW_ITEM ? 'NEW' : ($proposedVid !== null ? 'CWP-' . $proposedVid : null);
    $shown = [];
    foreach ($map as $ref => $m) {
        $shown[$ref] = (int) $m['vpg_variant_id'];
    }
    $judgements[] = $prov + [
        'alt_site' => 'electrofag', 'alt_variant_id' => $id, 'alt_title' => $rec['title'], 'lane' => $rec['lane'], 'chunk_lane' => $info['lane'],
        'units_365d' => $rec['units_365d'], 'units_30d' => $rec['units_30d'],
        'answer' => $j, 'answer_status' => $j === null ? $info['status'] : 'valid', 'answer_warnings' => $warnings,
        'effective_outcome' => $outcome,
        'chosen' => $j !== null && $j['chosen_ref'] !== null ? ['ref' => $j['chosen_ref'], 'role' => $map[$j['chosen_ref']]['role']] + $item($chosenVid) : null,
        'closest' => $j !== null && ($j['closest_ref'] ?? null) !== null ? ['ref' => $j['closest_ref'], 'role' => $map[$j['closest_ref']]['role']] + $item($closestVid) : null,
        'lane_target' => $tgt !== null ? ['ref' => $a['lane_target_ref']] + $item($tgt) : null,
        'agrees_with_lane_target' => $tgt !== null && $j !== null ? ($outcome === 'match' ? $chosenVid === $tgt : null) : null,
        'shown_candidates' => $shown,
    ];

    $vetoOnChosen = $j !== null && $j['chosen_ref'] !== null ? (((array) $a['vetoed_refs'])[$j['chosen_ref']] ?? []) : [];
    $softOnChosen = $j !== null && $j['chosen_ref'] !== null ? (((array) $a['soft_flagged_refs'])[$j['chosen_ref']] ?? []) : [];
    $proposals[] = [
        'run_id' => $runId, 'alt_site' => 'electrofag', 'alt_variant_id' => $id, 'alt_product_id' => $rec['alt_product_id'], 'alt_title' => $rec['title'],
        'variant_status' => $rec['variant_status'], 'units_365d' => $rec['units_365d'], 'units_30d' => $rec['units_30d'],
        'band' => $band, 'band_reasons' => $reasons, 'band_v2' => $bandV2['band'] ?? null,
        'proposed_cw_id' => $proposed, 'proposed_title' => $proposedVid !== null ? ($vpgTitle[$proposedVid] ?? null) : null,
        'two_person_confirm' => $proposedVid !== null && $j['units_per_item'] !== 1,
        'evidence' => [
            'lane' => $rec['lane'],
            'lane_target' => $item($tgt),
            'lane_flags' => array_values(array_filter($rec['flags'], fn ($f) => $f !== 'ignored_but_sold')),
            'target_vetoes' => array_values(array_unique(array_column($rec['target_vetoes'], 'code'))),
            'target_soft_flags' => $rec['target_soft_flags'],
            'key_possible' => $a['key_possible'] ?? false, 'key_blocked_by' => $a['key_blocked_by'] ?? [],
            'relabel_pending' => $a['relabel_pending'] ?? null,
            'relabel_partners' => array_values(array_map(fn ($ref) => $item($shown[$ref]) + ['note' => $partners[$ref]], array_keys($partners))),
            'ai' => $j === null ? null : [
                'outcome' => $j['outcome'], 'confidence' => $j['confidence'], 'units_per_item' => $j['units_per_item'], 'reason' => $j['reason'],
                'chosen' => $item($chosenVid), 'closest' => $item($closestVid),
                'fields_not_agree' => array_filter($j['fields'], fn ($v) => in_array($v, ['conflict', 'unknown'], true)),
                'vetoes_on_chosen' => $vetoOnChosen, 'soft_flags_on_chosen' => $softOnChosen, 'warnings' => $warnings,
                'model' => $info['model'], 'chunk' => $ch, 'ref' => $L,
            ],
        ],
    ];
}
usort($proposals, fn ($x, $y) => [$y['units_365d'], $y['units_30d'], $x['alt_variant_id']] <=> [$x['units_365d'], $x['units_30d'], $y['alt_variant_id']]);
if (array_sum($checks) !== 0) {
    fwrite(STDERR, 'Key rule invariants broken: ' . json_encode($checks) . "\n");
    exit(3);
}

// ───────────── 6 files
$fh = fopen("$runDir/judgements.jsonl.tmp", 'w');
foreach ($judgements as $r) {
    fwrite($fh, json_encode($r, $FLAGS | JSON_PRESERVE_ZERO_FRACTION) . "\n");
}
fclose($fh);
rename("$runDir/judgements.jsonl.tmp", "$runDir/judgements.jsonl");
$fh = fopen("$runDir/proposals.jsonl.tmp", 'w');
foreach ($proposals as $r) {
    fwrite($fh, json_encode($r, $FLAGS | JSON_PRESERVE_ZERO_FRACTION) . "\n");
}
fclose($fh);
rename("$runDir/proposals.jsonl.tmp", "$runDir/proposals.jsonl");

$fh = fopen("$runDir/proposals.csv.tmp", 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['rank', 'units_365d', 'units_30d', 'band', 'proposed_central_id', 'electrofag_variant_id', 'electrofag_title', 'proposed_vape_and_go_title',
    'lane', 'lane_target_id', 'lane_target_title', 'ai_outcome', 'ai_confidence', 'ai_units_per_item', 'ai_closest_id', 'ai_closest_title',
    'relabel_pending', 'flags', 'ai_reason', 'band_reasons', 'two_person_confirm', 'chunk_ref'], ',', '"', '');
foreach ($proposals as $i => $p) {
    $e = $p['evidence'];
    $ai = $e['ai'];
    $flags = array_merge($e['lane_flags'], array_map(fn ($x) => "veto:$x", $e['target_vetoes']), array_map(fn ($x) => "soft:$x", $e['target_soft_flags']),
        $ai !== null ? array_map(fn ($x) => "chosen_veto:$x", $ai['vetoes_on_chosen']) : [], $ai !== null ? array_map(fn ($x) => "chosen_soft:$x", $ai['soft_flags_on_chosen']) : [],
        $ai !== null ? $ai['warnings'] : []);
    $closest = $ai['closest'] ?? null;
    $rel = $e['relabel_pending'] ?? ($e['relabel_partners'] !== [] ? 'partner in list: ' . implode('; ', array_unique(array_map(fn ($x) => $x['note'], $e['relabel_partners']))) : '');
    fputcsv($fh, [$i + 1, $p['units_365d'], $p['units_30d'], $p['band'], $p['proposed_cw_id'] ?? '', $p['alt_variant_id'], $p['alt_title'], $p['proposed_title'] ?? '',
        $e['lane'], $e['lane_target']['cw_id'] ?? '', $e['lane_target']['title'] ?? '', $ai['outcome'] ?? '', $ai['confidence'] ?? '', $ai['units_per_item'] ?? '',
        $closest['cw_id'] ?? '', $closest['title'] ?? '', $rel, implode(' ', $flags), $ai['reason'] ?? '', implode(' ', $p['band_reasons']),
        $p['two_person_confirm'] ? 'yes' : '', $ai !== null ? $ai['chunk'] . ' ' . $ai['ref'] : ''], ',', '"', '');
}
fclose($fh);
rename("$runDir/proposals.csv.tmp", "$runDir/proposals.csv");

// ───────────── 7 summary (numbers for the report; canary truths only in the private file)
$sum = function (array $rows, callable $key): array {
    $o = [];
    foreach ($rows as $r) {
        $k = $key($r);
        $o[$k] ??= ['listings' => 0, 'units_365d' => 0, 'units_30d' => 0];
        $o[$k]['listings']++;
        $o[$k]['units_365d'] += $r['units_365d'];
        $o[$k]['units_30d'] += $r['units_30d'];
    }
    ksort($o);
    return $o;
};
$byId = array_column($proposals, null, 'alt_variant_id');
$laneAgreement = [];
$disagree = [];
$noMatchOnKey = [];
foreach ($judgements as $jl) {
    if (!in_array($jl['lane'], ['barcode', 'transfer'], true) || $jl['answer'] === null) {
        continue;
    }
    $lane = $jl['lane'];
    $laneAgreement[$lane] ??= ['listings' => 0, 'single_target' => 0, 'no_single_target' => 0, 'match_on_target' => 0, 'match_on_target_conf90' => 0,
        'match_on_target_conf_below90' => 0, 'match_other_item' => 0, 'abstain' => 0, 'abstain_closest_is_target' => 0, 'no_match_in_list' => 0,
        'cannot_tell' => 0, 'multiple_plausible' => 0, 'not_a_product' => 0, 'units_365d' => 0, 'units_365d_match_on_target' => 0];
    $s = &$laneAgreement[$lane];
    $s['listings']++;
    $s['units_365d'] += $jl['units_365d'];
    if ($jl['lane_target'] === null) {
        $s['no_single_target']++;
        unset($s);
        continue;
    }
    $s['single_target']++;
    $o = $jl['effective_outcome'];
    if ($o === 'match') {
        if ($jl['agrees_with_lane_target']) {
            $s['match_on_target']++;
            $s['units_365d_match_on_target'] += $jl['units_365d'];
            $s[$jl['answer']['confidence'] >= 90 ? 'match_on_target_conf90' : 'match_on_target_conf_below90']++;
        } else {
            $s['match_other_item']++;
            $p = $byId[$jl['alt_variant_id']];
            $disagree[] = ['lane' => $lane, 'alt_variant_id' => $jl['alt_variant_id'], 'units_365d' => $jl['units_365d'], 'listing' => $jl['alt_title'],
                'lane_target' => $jl['lane_target']['cw_id'] . ' ' . $jl['lane_target']['title'], 'ai_pick' => $jl['chosen']['cw_id'] . ' ' . $jl['chosen']['title'],
                'ai_pick_role' => $jl['chosen']['role'], 'confidence' => $jl['answer']['confidence'], 'band' => $p['band'], 'reason' => $jl['answer']['reason'],
                'model' => $jl['model'], 'chunk_ref' => $jl['chunk'] . ' ' . $jl['ref']];
        }
    } else {
        $s['abstain']++;
        $s[$o]++;
        if (($jl['closest']['vpg_variant_id'] ?? null) === $jl['lane_target']['vpg_variant_id']) {
            $s['abstain_closest_is_target']++;
        }
        if ($o === 'no_match_in_list') {
            $p = $byId[$jl['alt_variant_id']];
            $noMatchOnKey[] = ['lane' => $lane, 'alt_variant_id' => $jl['alt_variant_id'], 'units_365d' => $jl['units_365d'], 'listing' => $jl['alt_title'],
                'lane_target' => $jl['lane_target']['cw_id'] . ' ' . $jl['lane_target']['title'], 'confidence' => $jl['answer']['confidence'],
                'closest_is_target' => ($jl['closest']['vpg_variant_id'] ?? null) === $jl['lane_target']['vpg_variant_id'], 'band' => $p['band'],
                'reason' => $jl['answer']['reason'], 'model' => $jl['model'], 'chunk_ref' => $jl['chunk'] . ' ' . $jl['ref']];
        }
    }
    unset($s);
}
usort($disagree, fn ($x, $y) => $y['units_365d'] <=> $x['units_365d']);
usort($noMatchOnKey, fn ($x, $y) => $y['units_365d'] <=> $x['units_365d']);
$candMix = [];
foreach ($judgements as $jl) {
    if ($jl['lane'] !== 'candidates' || $jl['answer'] === null) {
        continue;
    }
    $o = $jl['effective_outcome'];
    $candMix[$o] ??= ['listings' => 0, 'units_365d' => 0, 'conf90' => 0];
    $candMix[$o]['listings']++;
    $candMix[$o]['units_365d'] += $jl['units_365d'];
    $candMix[$o]['conf90'] += $jl['answer']['confidence'] >= 90 ? 1 : 0;
}
ksort($candMix);
$modelOf = [];
foreach ($chunkInfo as $ch => $c) {
    $modelOf[$c['model'] ?? 'none'][] = $ch;
}
$canarySummary = [];
foreach ($canaryRows as $c) {
    $canarySummary[$c['kind']][$c['status']] = ($canarySummary[$c['kind']][$c['status']] ?? 0) + 1;
}
$relabelRows = array_values(array_filter($proposals, fn ($p) => $p['band'] === MANUAL));
// the judges' listing_extract.form against the form enum (Form::ALL; M29): run3's judges wrote 22 spellings for 14 values
$extractForm = ['in_enum' => 0, 'null' => 0, 'normalised' => [], 'unrecognised' => []];
foreach ($judged as $its) {
    foreach ($its as $it) {
        $raw = $it['listing_extract']['form']['value'] ?? null;
        if (!is_string($raw)) {
            $extractForm['null']++;
        } elseif (Form::isForm($raw)) {
            $extractForm['in_enum']++;
        } elseif (($c = Form::canonical($raw)) !== null) {
            $extractForm['normalised'][$raw . ' -> ' . Form::label($c['form'], $c['form_sub'])] = ($extractForm['normalised'][$raw . ' -> ' . Form::label($c['form'], $c['form_sub'])] ?? 0) + 1;
        } else {
            $extractForm['unrecognised'][$raw] = ($extractForm['unrecognised'][$raw] ?? 0) + 1;
        }
    }
}
ksort($extractForm['normalised']);
ksort($extractForm['unrecognised']);
$summary = [
    'run_id' => $runId, 'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'engine' => $engine, 'band_version' => Band::VERSION,
    'prompt' => ['file' => (string) $answers['prompt'], 'sha256' => $promptSha, 'file_sha256_now' => $promptShaFile, 'matches' => $promptSha === $promptShaFile],
    'chunks' => [
        'total' => count($chunkNames), 'used' => count($useChunk),
        'rejected' => array_values(array_map(fn ($c) => ['chunk' => $c['chunk'], 'errors' => $c['errors']], array_filter($chunkInfo, fn ($c) => $c['status'] === 'rejected'))),
        'missing' => array_keys(array_filter($chunkInfo, fn ($c) => $c['status'] === 'missing')),
        'warnings' => array_values(array_filter(array_map(fn ($c) => $c['warnings'] === [] ? null : ['chunk' => $c['chunk'], 'warnings' => $c['warnings']], $chunkInfo))),
        'by_model' => array_map(fn ($l) => ['chunks' => count($l), 'first' => $l[0], 'last' => end($l)], $modelOf),
        'sha256_all_match_manifest' => array_filter($chunkInfo, fn ($c) => !$c['sha256_matches_manifest']) === [],
    ],
    'canaries' => ['results' => $canarySummary, 'detail' => "$private/{$run}_canary_results.json"],
    'listings' => count($proposals),
    'by_band' => $sum($proposals, fn ($p) => $p['band']),
    'by_lane_band' => $sum($proposals, fn ($p) => $p['evidence']['lane'] . ' | ' . $p['band']),
    'by_band_reason' => $sum($proposals, fn ($p) => $p['band'] . ' | ' . preg_replace('/_\d+(?=$|_|\+)/', '_N', preg_replace('/:.*$/', '', $p['band_reasons'][0] ?? ''))),
    'lane_agreement' => $laneAgreement,
    'ai_match_other_than_lane_target' => $disagree,
    'ai_no_match_on_lane_target' => $noMatchOnKey,
    'candidates_outcomes' => $candMix,
    'manual_relabel' => array_map(fn ($p) => ['alt_variant_id' => $p['alt_variant_id'], 'units_365d' => $p['units_365d'], 'title' => $p['alt_title'], 'lane' => $p['evidence']['lane'],
        'relabel' => $p['evidence']['relabel_pending'] ?? implode('; ', array_unique(array_map(fn ($x) => $x['note'], $p['evidence']['relabel_partners']))),
        'lane_target' => $p['evidence']['lane_target'] !== null ? $p['evidence']['lane_target']['cw_id'] . ' ' . $p['evidence']['lane_target']['title'] : null,
        'ai' => $p['evidence']['ai'] !== null ? $p['evidence']['ai']['outcome'] . ' ' . $p['evidence']['ai']['confidence'] : null,
        'ai_item' => ($p['evidence']['ai']['chosen'] ?? $p['evidence']['ai']['closest'] ?? null) !== null ? ($p['evidence']['ai']['chosen'] ?? $p['evidence']['ai']['closest'])['cw_id'] . ' ' . ($p['evidence']['ai']['chosen'] ?? $p['evidence']['ai']['closest'])['title'] : null,
        'band_v2' => $p['band_v2'], 'reason' => $p['band_reasons'][0]], $relabelRows),
    'key_rule_invariants' => $checks,
    'listing_extract_form' => $extractForm,
    'files' => ['judge_raw' => $rawFile, 'judgements' => "$runDir/judgements.jsonl", 'proposals' => "$runDir/proposals.jsonl", 'csv' => "$runDir/proposals.csv"],
    'seconds' => round(microtime(true) - $t0, 1),
];
file_put_contents("$runDir/proposals_summary.json", json_encode($summary, JSON_PRETTY_PRINT | $FLAGS));
file_put_contents("$private/{$run}_canary_results.json", json_encode(['run_id' => $runId, 'summary' => $canarySummary, 'canaries' => $canaryRows], JSON_PRETTY_PRINT | $FLAGS));
logmsg('done');
echo json_encode(['listings' => count($proposals), 'by_band' => $summary['by_band'], 'chunks_used' => count($useChunk), 'canaries' => $canarySummary], $FLAGS) . "\n";
