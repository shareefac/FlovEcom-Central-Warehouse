<?php

declare(strict_types=1);

/**
 * The Vape and Go seed (plan §7.1): one central item per published, non-landing, non-placeholder
 * Vape and Go listing (features `in_seed` from the first-match run), each linked by an applied
 * `link` decision of --staff (a mapping_lead) carrying one bulk_batch_id. Rules only: the seed
 * cannot create a cross-site false merge. Vape and Go's own duplicate groups (vpg_duplicates.jsonl
 * of the same run) are reported and queued as merge suggestions (open proposals, lane
 * vpg_duplicate, band Manual) for two people to decide — never merged here.
 *
 *   php bin/mint_vpg.php --features=<run2>/listings_features.jsonl --staff=<email> [--dry-run]
 *       [--duplicates=<run2>/vpg_duplicates.jsonl] [--channel=vpg] [--batch-id=vpg_mint:run2] [--limit=N]
 *       [--db=<schema>] [--admin]
 *
 * Needs the listings imported first (bin/import_listings.php). Also stores every Vape and Go
 * listing's rules features (listing_profile.features), and seeds sku_barcode with the usable GTINs of
 * the listings it minted from (CW\Mapping\BarcodeSeeder; bin/seed_barcodes.php does it for all items).
 * Idempotent: a linked listing is skipped, a proposal already recorded for the run is left alone.
 * Exit codes: 0 ok · 1 some listings missing or failed · 2 usage · 3 cannot run.
 */

use CW\Auth\Permissions;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Idempotency;
use CW\Mapping\BarcodeSeeder;
use CW\Mapping\DecisionService;
use CW\Mapping\Proposals;
use CW\Ops\Cli;
use CW\Staff\StaffRoles;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

/** Keys of a features line that are run bookkeeping, not features. */
const RUN_KEYS = ['cw_id', 'in_seed', 'in_scope'];

exit(Cli::main('mint_vpg', ['features:', 'staff:', 'duplicates:', 'channel:', 'batch-id:', 'limit:', 'dry-run'],
    'usage: php bin/mint_vpg.php --features=<listings_features.jsonl> --staff=<email> [--dry-run] [--duplicates=<vpg_duplicates.jsonl>] [--channel=vpg] [--batch-id=<id>] [--limit=N]',
    static function (Cli $cli, array $opts): int {
        $featuresFile = $opts['features'] ?? null;
        $email = $opts['staff'] ?? null;
        if (!is_string($featuresFile) || !is_string($email)) {
            throw new InvalidArgumentException('--features=<listings_features.jsonl> and --staff=<email> are required');
        }
        if (!is_readable($featuresFile)) {
            throw new InvalidArgumentException("cannot read {$featuresFile}");
        }
        $dupFile = is_string($opts['duplicates'] ?? null) ? $opts['duplicates'] : dirname($featuresFile) . '/vpg_duplicates.jsonl';
        $code = is_string($opts['channel'] ?? null) ? $opts['channel'] : 'vpg';
        $runName = basename(dirname((string) realpath($featuresFile)));
        $batchId = is_string($opts['batch-id'] ?? null) ? $opts['batch-id'] : 'vpg_mint:' . $runName;
        $limit = Cli::intOpt($opts, 'limit', PHP_INT_MAX, 1, PHP_INT_MAX);
        $dry = array_key_exists('dry-run', $opts);
        $db = $cli->db;

        $staff = $db->one('SELECT id, is_active FROM staff_user WHERE email = ?', [strtolower($email)]);
        $staffRoles = $staff === null ? [] : StaffRoles::of($db, (int) $staff['id']);
        // Permissions::can, not the raw list: a set that breaks the separation of duties (admin + mapping_lead, only admin SQL
        // can write one) is read fail-closed, as DecisionService will read it (I12, I35), so the run stops here, not part-way.
        if ($staff === null || (int) $staff['is_active'] !== 1 || !Permissions::can($staffRoles, 'mapping.approve')) {
            throw new InvalidArgumentException("--staff must be an active mapping_lead ({$email} is " . ($staff === null ? 'unknown'
                : (implode(', ', $staffRoles) ?: 'without roles') . ", active={$staff['is_active']}") . ')');
        }
        $channelId = $db->value('SELECT id FROM channel WHERE code = ?', [$code]);
        if ($channelId === null) {
            throw new InvalidArgumentException("no channel {$code}");
        }
        $channelId = (int) $channelId;
        $t0 = hrtime(true);

        // 1. The run's Vape and Go lines: the seed, and every line's features.
        $seed = [];
        $features = [];
        $n = ['lines' => 0, 'vpg' => 0, 'seed' => 0, 'not_seed' => 0];
        $fh = fopen($featuresFile, 'rb');
        while (($line = fgets($fh)) !== false) {
            $f = json_decode($line, true);
            if (!is_array($f)) {
                continue;
            }
            $n['lines']++;
            if (($f['site'] ?? null) !== 'vapeandgo' || !isset($f['variant_id'])) {
                continue;
            }
            $n['vpg']++;
            $v = (string) $f['variant_id'];
            $features[$v] = Idempotency::json(array_diff_key($f, array_flip(RUN_KEYS))); // kept as JSON text: ~30k lines
            if (($f['in_seed'] ?? false) === true && ($f['variant_status'] ?? null) === 'Published' && ($f['is_placeholder'] ?? true) === false) {
                $seed[$v] = true;
                $n['seed']++;
            } else {
                $n['not_seed']++;
            }
        }
        fclose($fh);

        // 2. Their listing rows (imported beforehand) and profiles.
        $listings = [];
        foreach (array_chunk(array_map('strval', array_keys($features)), 1000) as $chunk) {
            foreach ($db->all(
                'SELECT l.id, l.external_variant_id, l.status, l.map_version, p.listing_id AS has_profile, p.product_title, p.variant_title, p.brand '
                . 'FROM channel_listing l LEFT JOIN listing_profile p ON p.listing_id = l.id WHERE l.channel_id = ? AND l.external_variant_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')',
                [$channelId, ...$chunk],
            ) as $r) {
                $listings[(string) $r['external_variant_id']] = $r;
            }
        }

        // 3. Features into listing_profile (matching inputs for later runs and new-item cards).
        $wrote = 0;
        if (!$dry) {
            foreach (array_chunk(array_keys($features), 500, true) as $chunk) {
                $wrote += $db->transaction(static function (Db $db) use ($chunk, $features, $listings): int {
                    $w = 0;
                    foreach ($chunk as $v) {
                        $l = $listings[(string) $v] ?? null;
                        if ($l === null || $l['has_profile'] === null) {
                            continue;
                        }
                        $f = json_decode($features[(string) $v], true);
                        $db->exec('UPDATE listing_profile SET features = ?, features_version = ? WHERE listing_id = ?',
                            [$features[(string) $v], isset($f['normalizer']) ? substr((string) $f['normalizer'], 0, 32) : null, (int) $l['id']]);
                        $w++;
                    }
                    return $w;
                });
            }
        }

        // 4. Mint + link, one transaction per listing.
        $ds = new DecisionService($db);
        $caller = Caller::staff((int) $staff['id']);
        $m = ['minted' => 0, 'would_mint' => 0, 'already_linked' => 0, 'ignored' => 0, 'missing_listing' => 0, 'failed' => 0];
        $minted = [];
        foreach (array_keys($seed) as $v) {
            $v = (string) $v;
            $l = $listings[$v] ?? null;
            if ($l === null) {
                if (++$m['missing_listing'] <= 20) {
                    $cli->error("vpg variant {$v}: no listing row (run bin/import_listings.php first)");
                }
                continue;
            }
            if (in_array($l['status'], DecisionService::LINKED, true)) {
                $m['already_linked']++;
                continue;
            }
            if ($l['status'] === 'ignored') {
                $m['ignored']++;
                continue;
            }
            if ($m['minted'] + $m['would_mint'] >= $limit) {
                continue;
            }
            try {
                $card = DecisionService::cardFrom($l['has_profile'] === null ? [] : $l, (array) json_decode($features[$v], true));
                if ($dry) {
                    $m['would_mint']++;
                    continue;
                }
                $minted[] = (int) $ds->mintAndLink($caller, (int) $l['id'], (int) $l['map_version'], $card, $batchId, "Vape and Go seed ({$runName})")['sku_id'];
                $m['minted']++;
            } catch (CwException $e) {
                if (++$m['failed'] <= 20) {
                    $cli->error("vpg variant {$v}: {$e->errorCode}: {$e->getMessage()}");
                }
            }
        }

        // 5. Their barcodes (sku_barcode, design S3): what the review screens compare a listing with.
        $bc = ['added' => 0, 'clashes' => 0];
        if (!$dry && $minted !== []) {
            $bc = (new BarcodeSeeder($db))->seed(Caller::system('mint_vpg'), $minted);
        }

        // 6. Duplicate groups: report, and queue each non-keeper as a merge suggestion.
        $d = ['groups' => 0, 'proposals' => 0, 'exists' => 0, 'skipped' => 0];
        if (is_readable($dupFile)) {
            $skuOf = [];
            $proposals = new Proposals($db, $ds);
            $runId = null;
            foreach (file($dupFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $g = json_decode($line, true);
                if (!is_array($g) || !is_array($g['members'] ?? null) || count($g['members']) < 2) {
                    continue;
                }
                $d['groups']++;
                $members = $g['members'];
                usort($members, static fn (array $a, array $b): int => [(int) ($b['units_30d'] ?? 0), (int) $a['vpg_variant_id']] <=> [(int) ($a['units_30d'] ?? 0), (int) $b['vpg_variant_id']]);
                $keeper = $members[0];
                $vids = array_map(static fn (array $x): string => (string) $x['vpg_variant_id'], $members);
                foreach ($db->all(
                    "SELECT l.id, l.external_variant_id, l.sku_id, s.code FROM channel_listing l JOIN sku s ON s.id = l.sku_id "
                    . "WHERE l.channel_id = ? AND l.status IN ('mapped', 'quarantined') AND l.external_variant_id IN (" . implode(',', array_fill(0, count($vids), '?')) . ')',
                    [$channelId, ...$vids],
                ) as $r) {
                    $skuOf[(string) $r['external_variant_id']] = ['listing_id' => (int) $r['id'], 'sku_id' => (int) $r['sku_id'], 'code' => (string) $r['code']];
                }
                $k = $skuOf[(string) $keeper['vpg_variant_id']] ?? null;
                $report = sprintf('duplicates group %d (%s): keep vpg %s%s "%s" <-', $g['group'] ?? 0, $g['kind'] ?? '?', $keeper['vpg_variant_id'],
                    $k === null ? '' : " {$k['code']}", mb_substr((string) ($keeper['title'] ?? ''), 0, 60));
                foreach (array_slice($members, 1) as $mem) {
                    $x = $skuOf[(string) $mem['vpg_variant_id']] ?? null;
                    $report .= sprintf(' vpg %s%s', $mem['vpg_variant_id'], $x === null ? '' : " {$x['code']}");
                    if ($dry) {
                        continue;
                    }
                    if ($k === null || $x === null || $x['sku_id'] === $k['sku_id']) {
                        $d['skipped']++;
                        $report .= ' (not linked: skipped)';
                        continue;
                    }
                    $runId ??= $proposals->run($runName . '-vpg-duplicates', 'vpg_duplicates', null, null, null,
                        ['file' => basename($dupFile), 'sha256' => hash_file('sha256', $dupFile)]);
                    $res = $proposals->add(Caller::system('mint_vpg'), $x['listing_id'], $runId, [
                        'proposed_sku_id' => $k['sku_id'], 'band' => 'Manual', 'lane' => 'vpg_duplicate',
                        'evidence' => ['group' => $g['group'] ?? null, 'kind' => $g['kind'] ?? null, 'key' => $g['key'] ?? null,
                            'keeper' => ['vpg_variant_id' => $keeper['vpg_variant_id'], 'sku_id' => $k['sku_id'], 'code' => $k['code'], 'title' => $keeper['title'] ?? null],
                            'members' => array_map(static fn (array $y): array => [
                                'vpg_variant_id' => $y['vpg_variant_id'], 'title' => $y['title'] ?? null, 'status' => $y['status'] ?? null,
                                'units_30d' => $y['units_30d'] ?? null, 'sku_id' => $skuOf[(string) $y['vpg_variant_id']]['sku_id'] ?? null,
                            ], $members),
                            'action' => 'merge_skus of this listing\'s item into the keeper\'s item, if two people agree'],
                        'flags' => ['merge_suggestion', (string) ($g['kind'] ?? 'duplicate')],
                    ], false);
                    $d[$res['result'] === 'created' ? 'proposals' : 'exists']++;
                }
                fwrite(STDOUT, $report . "\n");
            }
        } else {
            $cli->error("no duplicates file {$dupFile}: nothing queued");
        }

        $cli->log(sprintf('%schannel=%s staff=%s batch=%s features: lines=%d vpg=%d seed=%d not_seed=%d written=%d; mint: minted=%d would_mint=%d '
            . 'already_linked=%d ignored=%d missing_listing=%d failed=%d; barcodes: added=%d clashes=%d; duplicates: groups=%d proposals=%d exists=%d skipped=%d; ms=%d',
            $dry ? 'DRY RUN (nothing written) ' : '', $code, $email, $batchId, $n['lines'], $n['vpg'], $n['seed'], $n['not_seed'], $wrote,
            $m['minted'], $m['would_mint'], $m['already_linked'], $m['ignored'], $m['missing_listing'], $m['failed'],
            $bc['added'], $bc['clashes'], $d['groups'], $d['proposals'], $d['exists'], $d['skipped'], intdiv(hrtime(true) - $t0, 1_000_000)));
        return $m['missing_listing'] + $m['failed'] > 0 ? Cli::PROBLEM : Cli::OK;
    }));
