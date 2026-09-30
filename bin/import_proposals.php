<?php

declare(strict_types=1);

/**
 * Imports a first-match judge run (tools/first_match/assemble.php output) as one match_run and one
 * match_proposal per Electrofag listing (plan §7.3 step 7). A run's central ids CWP-<vpg id> are
 * mapped to the item minted for that Vape and Go listing (bin/mint_vpg.php first); the private
 * ref maps add every candidate the judge saw (item, prescore, vetoes) to the evidence. Listings
 * with an open proposal move unmapped -> suggested through DecisionService (`suggest`). Nothing is
 * linked: a person confirms every link.
 *
 *   php bin/import_proposals.php --run-dir=<first_match>/run3 --private=<first_match>/private/run3
 *       [--channel=alt] [--vpg-channel=vpg] [--dry-run] [--db=<schema>] [--admin]
 *
 * Reads <run-dir>/proposals.jsonl, proposals_summary.json, listings_features.jsonl (Electrofag
 * features, stored in listing_profile.features for new-item cards) and <private>/<chunk>.refmap.json,
 * <private>/<run>_answers.json. Idempotent: a proposal of this run already recorded is left alone;
 * a later run supersedes the listing's open proposal.
 * Exit codes: 0 ok · 1 some proposals skipped (listing not imported) · 2 usage · 3 cannot run.
 */

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Mapping\DecisionService;
use CW\Mapping\Proposals;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

const BAND_MAP = ['Key' => 'Key', 'Check' => 'Check', 'New item' => 'New item', "Can't tell" => "Can't tell", 'Conflict' => 'Conflict',
    'Manual (relabel)' => 'Manual', 'Manual' => 'Manual'];

/** @return array<string, mixed> */
function readJsonFile(string $f): array
{
    $d = json_decode((string) @file_get_contents($f), true);
    if (!is_array($d)) {
        throw new InvalidArgumentException("cannot read JSON {$f}");
    }
    return $d;
}

function vpgId(mixed $cwId): ?string
{
    return is_string($cwId) && preg_match('/^CWP-(\d{1,20})$/', $cwId, $m) === 1 ? $m[1] : null;
}

exit(Cli::main('import_proposals', ['run-dir:', 'private:', 'channel:', 'vpg-channel:', 'dry-run'],
    'usage: php bin/import_proposals.php --run-dir=<run3 dir> --private=<private run3 dir> [--channel=alt] [--vpg-channel=vpg] [--dry-run]',
    static function (Cli $cli, array $opts): int {
        $runDir = is_string($opts['run-dir'] ?? null) ? rtrim($opts['run-dir'], '/') : null;
        $private = is_string($opts['private'] ?? null) ? rtrim($opts['private'], '/') : null;
        if ($runDir === null || $private === null || !is_readable("{$runDir}/proposals.jsonl") || !is_dir($private)) {
            throw new InvalidArgumentException('--run-dir=<dir with proposals.jsonl> and --private=<private run dir> are required');
        }
        $code = is_string($opts['channel'] ?? null) ? $opts['channel'] : 'alt';
        $vpgCode = is_string($opts['vpg-channel'] ?? null) ? $opts['vpg-channel'] : 'vpg';
        $dry = array_key_exists('dry-run', $opts);
        $db = $cli->db;
        $t0 = hrtime(true);
        $channelId = $db->value('SELECT id FROM channel WHERE code = ?', [$code]);
        $vpgId = $db->value('SELECT id FROM channel WHERE code = ?', [$vpgCode]);
        if ($channelId === null || $vpgId === null) {
            throw new InvalidArgumentException("no channel {$code} or {$vpgCode}");
        }
        $summary = is_readable("{$runDir}/proposals_summary.json") ? readJsonFile("{$runDir}/proposals_summary.json") : [];
        $run = basename($runDir);
        $runId = (string) ($summary['run_id'] ?? "{$run}-sold");
        $answers = is_readable("{$private}/{$run}_answers.json") ? readJsonFile("{$private}/{$run}_answers.json") : [];

        // 1. The proposals, and every Vape and Go id they name.
        $rows = [];
        $vpgIds = [];
        foreach (file("{$runDir}/proposals.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $p = json_decode($line, true);
            if (!is_array($p) || !isset($p['alt_variant_id'])) {
                continue;
            }
            $rows[] = $p;
            $e = $p['evidence'] ?? [];
            foreach ([$p['proposed_cw_id'] ?? null, $e['lane_target']['cw_id'] ?? null, $e['ai']['chosen']['cw_id'] ?? null,
                $e['ai']['closest']['cw_id'] ?? null] as $c) {
                if (($v = vpgId($c)) !== null) {
                    $vpgIds[$v] = true;
                }
            }
            foreach ($e['relabel_partners'] ?? [] as $rp) {
                if (($v = vpgId($rp['cw_id'] ?? null)) !== null) {
                    $vpgIds[$v] = true;
                }
            }
        }
        // The judge's candidate lists (private ref maps).
        $refmaps = [];
        $refmap = static function (string $chunk) use (&$refmaps, $private): array {
            if (!array_key_exists($chunk, $refmaps)) {
                $f = "{$private}/{$chunk}.refmap.json";
                $refmaps[$chunk] = preg_match('/^[a-z0-9_]{1,40}$/', $chunk) === 1 && is_readable($f) ? readJsonFile($f)['refs'] ?? [] : [];
            }
            return $refmaps[$chunk];
        };
        foreach ($rows as $p) {
            $ai = $p['evidence']['ai'] ?? [];
            if (is_string($ai['chunk'] ?? null) && is_string($ai['ref'] ?? null)) {
                foreach ($refmap($ai['chunk'])[$ai['ref']]['candidates'] ?? [] as $c) {
                    if (($v = vpgId($c['cw_id'] ?? null)) !== null) {
                        $vpgIds[$v] = true;
                    }
                }
            }
        }
        $skuByVpg = [];
        foreach (array_chunk(array_map('strval', array_keys($vpgIds)), 1000) as $chunk) {
            foreach ($db->all(
                "SELECT external_variant_id, sku_id FROM channel_listing WHERE channel_id = ? AND status IN ('mapped', 'quarantined') "
                . 'AND external_variant_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                [(int) $vpgId, ...$chunk],
            ) as $r) {
                $skuByVpg[(string) $r['external_variant_id']] = (int) $r['sku_id'];
            }
        }
        $sku = static fn (mixed $cwId): ?int => ($v = vpgId($cwId)) === null ? null : ($skuByVpg[$v] ?? null);
        $withSku = static function (mixed $x) use ($sku): mixed {
            if (!is_array($x)) {
                return $x;
            }
            return $x + (isset($x['cw_id']) ? ['sku_id' => $sku($x['cw_id'])] : []);
        };

        // 2. The Electrofag listings (imported beforehand).
        $listing = [];
        $altIds = array_values(array_unique(array_map(static fn (array $p): string => (string) $p['alt_variant_id'], $rows)));
        foreach (array_chunk($altIds, 1000) as $chunk) {
            foreach ($db->all(
                'SELECT l.id, l.external_variant_id, l.status, p.listing_id AS has_profile FROM channel_listing l '
                . 'LEFT JOIN listing_profile p ON p.listing_id = l.id WHERE l.channel_id = ? AND l.external_variant_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')',
                [(int) $channelId, ...$chunk],
            ) as $r) {
                $listing[(string) $r['external_variant_id']] = $r;
            }
        }

        // 3. Their rules features (new-item cards).
        $wrote = 0;
        $site = (string) ($rows[0]['alt_site'] ?? 'electrofag');
        if (!$dry && is_readable("{$runDir}/listings_features.jsonl")) {
            $fh = fopen("{$runDir}/listings_features.jsonl", 'rb');
            $pendingFeatures = [];
            $write = static function () use (&$pendingFeatures, &$wrote, $db): void {
                if ($pendingFeatures === []) {
                    return;
                }
                $wrote += $db->transaction(static function (Db $db) use ($pendingFeatures): int {
                    foreach ($pendingFeatures as [$id, $json, $ver]) {
                        $db->exec('UPDATE listing_profile SET features = ?, features_version = ? WHERE listing_id = ?', [$json, $ver, $id]);
                    }
                    return count($pendingFeatures);
                });
                $pendingFeatures = [];
            };
            while (($line = fgets($fh)) !== false) {
                if (!str_contains($line, '"' . $site . '"')) {
                    continue;
                }
                $f = json_decode($line, true);
                if (!is_array($f) || ($f['site'] ?? null) !== $site) {
                    continue;
                }
                $l = $listing[(string) ($f['variant_id'] ?? '')] ?? null;
                if ($l === null || $l['has_profile'] === null) {
                    continue;
                }
                $f = array_diff_key($f, ['cw_id' => 1, 'in_seed' => 1, 'in_scope' => 1]);
                $pendingFeatures[] = [(int) $l['id'], Idempotency::json($f), isset($f['normalizer']) ? substr((string) $f['normalizer'], 0, 32) : null];
                if (count($pendingFeatures) >= 500) {
                    $write();
                }
            }
            fclose($fh);
            $write();
        }

        // 4. The run and its proposals.
        $models = [];
        foreach ($summary['chunks']['by_model'] ?? [] as $model => $x) {
            $models[(string) $model] = ['chunks' => $x['chunks'] ?? null, 'first' => $x['first'] ?? null, 'last' => $x['last'] ?? null];
        }
        $proposals = new Proposals($db, new DecisionService($db));
        $caller = Caller::system('import_proposals');
        $runRow = null;
        $c = ['proposals' => count($rows), 'created' => 0, 'exists' => 0, 'superseded' => 0, 'suggested' => 0, 'missing_listing' => 0,
            'target_not_minted' => 0, 'bad_band' => 0, 'failed' => 0];
        $byBand = [];
        foreach ($rows as $p) {
            $alt = (string) $p['alt_variant_id'];
            $l = $listing[$alt] ?? null;
            if ($l === null) {
                if (++$c['missing_listing'] <= 20) {
                    $cli->error("{$site} variant {$alt}: no listing row (run bin/import_listings.php first)");
                }
                continue;
            }
            $band = BAND_MAP[(string) ($p['band'] ?? '')] ?? null;
            if ($band === null) {
                $c['bad_band']++;
                continue;
            }
            $e = is_array($p['evidence'] ?? null) ? $p['evidence'] : [];
            $ai = is_array($e['ai'] ?? null) ? $e['ai'] : [];
            $proposed = $p['proposed_cw_id'] ?? null;
            $proposedSku = $sku($proposed);
            $flags = array_merge($e['lane_flags'] ?? [], $e['target_soft_flags'] ?? [], $e['target_vetoes'] ?? [], $e['key_blocked_by'] ?? []);
            if (!empty($e['relabel_pending'])) {
                $flags[] = 'relabel_pending';
            }
            if (!empty($p['two_person_confirm'])) {
                $flags[] = 'two_person_confirm';
            }
            if (vpgId($proposed) !== null && $proposedSku === null) {
                $flags[] = 'target_not_minted';
                $c['target_not_minted']++;
            }
            $candidates = [];
            if (is_string($ai['chunk'] ?? null) && is_string($ai['ref'] ?? null)) {
                $item = $answers['chunks'][$ai['chunk']]['items'][$ai['ref']] ?? [];
                foreach ($refmap($ai['chunk'])[$ai['ref']]['candidates'] ?? [] as $ref => $cand) {
                    $candidates[] = ['ref' => (string) $ref, 'cw_id' => $cand['cw_id'] ?? null, 'sku_id' => $sku($cand['cw_id'] ?? null),
                        'role' => $cand['role'] ?? null, 'prescore' => $cand['prescore'] ?? null, 'search_rank' => $cand['search_rank'] ?? null,
                        'vetoes' => $item['vetoed_refs'][$ref] ?? [], 'soft_flags' => $item['soft_flagged_refs'][$ref] ?? []];
                }
            }
            $evidence = [
                'run_id' => $runId, 'site' => $p['alt_site'] ?? $site, 'alt_variant_id' => $p['alt_variant_id'], 'alt_product_id' => $p['alt_product_id'] ?? null,
                'title' => $p['alt_title'] ?? null, 'variant_status' => $p['variant_status'] ?? null,
                'units_30d' => $p['units_30d'] ?? null, 'units_365d' => $p['units_365d'] ?? null,
                'band' => $p['band'] ?? null, 'band_v2' => $p['band_v2'] ?? null, 'band_reasons' => $p['band_reasons'] ?? [],
                'proposed' => ['cw_id' => $proposed, 'sku_id' => $proposedSku, 'title' => $p['proposed_title'] ?? null],
                'lane' => $e['lane'] ?? null, 'lane_target' => $withSku($e['lane_target'] ?? null), 'lane_flags' => $e['lane_flags'] ?? [],
                'target_vetoes' => $e['target_vetoes'] ?? [], 'target_soft_flags' => $e['target_soft_flags'] ?? [],
                'key_possible' => $e['key_possible'] ?? null, 'key_blocked_by' => $e['key_blocked_by'] ?? [],
                'relabel_pending' => $e['relabel_pending'] ?? null, 'relabel_partners' => array_map($withSku, $e['relabel_partners'] ?? []),
                'ai' => ['outcome' => $ai['outcome'] ?? null, 'confidence' => $ai['confidence'] ?? null, 'units_per_item' => $ai['units_per_item'] ?? null,
                    'reason' => $ai['reason'] ?? null, 'chosen' => $withSku($ai['chosen'] ?? null), 'closest' => $withSku($ai['closest'] ?? null),
                    'fields_not_agree' => $ai['fields_not_agree'] ?? null, 'vetoes_on_chosen' => $ai['vetoes_on_chosen'] ?? [],
                    'soft_flags_on_chosen' => $ai['soft_flags_on_chosen'] ?? [], 'warnings' => $ai['warnings'] ?? [],
                    'model' => $ai['model'] ?? null, 'chunk' => $ai['chunk'] ?? null, 'ref' => $ai['ref'] ?? null],
                'candidates' => $candidates,
            ];
            $byBand[$band] = ($byBand[$band] ?? 0) + 1;
            if ($dry) {
                continue;
            }
            $runRow ??= $proposals->run($runId, 'first_match', is_string($summary['prompt']['sha256'] ?? null) ? $summary['prompt']['sha256'] : null,
                is_string($summary['engine'] ?? null) ? $summary['engine'] : null, $models === [] ? null : $models, [
                    'band_version' => $summary['band_version'] ?? null, 'listings' => $summary['listings'] ?? count($rows),
                    'proposals_sha256' => hash_file('sha256', "{$runDir}/proposals.jsonl"),
                    'summary_generated_at_utc' => $summary['generated_at_utc'] ?? null,
                ]);
            try {
                $r = $proposals->add($caller, (int) $l['id'], $runRow, [
                    'proposed_sku_id' => $proposedSku, 'proposed_new_item' => $proposed === 'NEW', 'band' => $band, 'lane' => $e['lane'] ?? null,
                    'ai_outcome' => $ai['outcome'] ?? null, 'ai_confidence' => is_int($ai['confidence'] ?? null) ? $ai['confidence'] : null,
                    'ai_units_per_item' => is_int($ai['units_per_item'] ?? null) ? $ai['units_per_item'] : null,
                    'ai_model' => $ai['model'] ?? null, 'closest_sku_id' => $sku($ai['closest']['cw_id'] ?? null),
                    'evidence' => $evidence, 'flags' => array_map('strval', $flags),
                ]);
                $c[$r['result']]++;
                $c['superseded'] += $r['superseded'] !== null ? 1 : 0;
                $c['suggested'] += $r['suggested'] ? 1 : 0;
            } catch (CwException $e) {
                if (++$c['failed'] <= 20) {
                    $cli->error("{$site} variant {$alt}: {$e->errorCode}: {$e->getMessage()}");
                }
            }
        }
        ksort($byBand);
        $cli->log(sprintf('%srun=%s channel=%s features_written=%d proposals=%d created=%d exists=%d superseded=%d suggested=%d missing_listing=%d '
            . 'target_not_minted=%d bad_band=%d failed=%d bands=%s ms=%d',
            $dry ? 'DRY RUN (nothing written) ' : '', $runId, $code, $wrote, $c['proposals'], $c['created'], $c['exists'], $c['superseded'],
            $c['suggested'], $c['missing_listing'], $c['target_not_minted'], $c['bad_band'], $c['failed'],
            json_encode($byBand, JSON_UNESCAPED_UNICODE), intdiv(hrtime(true) - $t0, 1_000_000)));
        return $c['missing_listing'] + $c['bad_band'] + $c['failed'] > 0 ? Cli::PROBLEM : Cli::OK;
    }));
