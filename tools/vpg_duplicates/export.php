<?php

declare(strict_types=1);

/**
 * The input of the wider duplicate sweep (tools/vpg_duplicates/sweep.php, docs/decisions.md M37): one site's MAPPED listings
 * with their profile and stored rules features, the identity card of their items, the other links of those items, every
 * merge suggestion of a duplicate lane (any status), the rejects, merged items, pending decisions and open proposals on
 * them. SELECT only, inside START TRANSACTION READ ONLY, each statement capped at 30 s (MAX_EXECUTION_TIME); app login.
 * Catalogue text only: no customer or order data. Writes JSONL to stdout, one record per line with a "type".
 *
 * On the staging box from a checkout (or piped from this repo: the script needs no file of its own there):
 *   php tools/vpg_duplicates/export.php --db=cw_staging [--channel=vapeandgo] [--admin] > export.jsonl   (--admin: test schemas)
 *   ssh -i /root/.ssh/cw_staging root@46.101.55.135 'php -- --root=/opt/cw-staging --db=cw_staging' \
 *       < tools/vpg_duplicates/export.php > /root/cw_work/vpg_dups/export_<UTC>.jsonl
 *
 * Exit codes: 0 ok · 2 usage · 3 cannot run.
 */

$o = getopt('', ['db:', 'channel:', 'root:', 'chunk:', 'admin']);
$schema = is_string($o['db'] ?? null) ? $o['db'] : null;
$code = is_string($o['channel'] ?? null) ? $o['channel'] : 'vapeandgo';
$root = is_string($o['root'] ?? null) ? rtrim($o['root'], '/') : dirname(__DIR__, 2);
$chunk = isset($o['chunk']) && is_string($o['chunk']) && ctype_digit($o['chunk']) ? max(100, (int) $o['chunk']) : 2000;
if ($schema === null || preg_match('/^[A-Za-z0-9_]{1,64}$/', $schema) !== 1 || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code) !== 1) {
    fwrite(STDERR, "usage: php tools/vpg_duplicates/export.php --db=<schema> [--channel=vapeandgo] [--root=<checkout>] > export.jsonl\n");
    exit(2);
}
if (!is_readable("{$root}/vendor/autoload.php")) {
    fwrite(STDERR, "no {$root}/vendor/autoload.php (give --root=<checkout>)\n");
    exit(3);
}
chdir($root);
require "{$root}/vendor/autoload.php";

$out = static function (array $rec): void {
    fwrite(STDOUT, json_encode($rec, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n");
};
$json = static fn (mixed $v): mixed => is_string($v) ? json_decode($v, true) : $v;
$in = static fn (array $ids): string => implode(',', array_fill(0, count($ids), '?'));

try {
    $config = CW\Config::load();
    $db = CW\Db::connect((array_key_exists('admin', $o) ? $config->dbAdmin() : $config->dbApp())->withDatabase($schema));
    $db->exec('SET SESSION MAX_EXECUTION_TIME = 30000');
    $db->exec('START TRANSACTION READ ONLY');
    $t0 = hrtime(true);
    $channelId = $db->value('SELECT id FROM channel WHERE code = ?', [$code]);
    if ($channelId === null) {
        fwrite(STDERR, "no channel {$code}\n");
        exit(2);
    }
    $channelId = (int) $channelId;
    $n = ['listings' => 0, 'skus' => 0, 'links' => 0, 'proposals' => 0, 'open' => 0, 'rejects' => 0, 'merged' => 0, 'pending' => 0];

    // 1. The site's mapped listings (and quarantined ones: their items are left out by the sweep), in id ranges.
    $skuIds = [];
    $listingIds = [];
    $last = 0;
    do {
        $rows = $db->all(
            'SELECT cl.id, cl.external_variant_id, cl.sku_id, cl.units_per_item, cl.status, cl.map_version, lp.product_title, lp.variant_title, '
            . 'lp.brand, lp.attributes, lp.barcodes, lp.price, lp.perma_link, lp.units_30d, lp.units_365d, lp.features, lp.features_version, '
            . 'lp.identity_hash FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id '
            . "WHERE cl.channel_id = ? AND cl.status IN ('mapped', 'quarantined') AND cl.id > ? ORDER BY cl.id LIMIT {$chunk}",
            [$channelId, $last],
        );
        foreach ($rows as $r) {
            $last = (int) $r['id'];
            $listingIds[] = (int) $r['id'];
            $skuIds[(int) $r['sku_id']] = true;
            $attrs = $json($r['attributes']);
            $out(['type' => 'listing', 'id' => (int) $r['id'], 'variant' => (string) $r['external_variant_id'], 'sku_id' => (int) $r['sku_id'],
                'units_per_item' => (int) $r['units_per_item'], 'status' => (string) $r['status'], 'map_version' => (int) $r['map_version'],
                'product_title' => $r['product_title'], 'variant_title' => $r['variant_title'], 'brand' => $r['brand'],
                'attributes' => is_array($attrs['items'] ?? null) ? $attrs['items'] : [], 'barcodes' => $json($r['barcodes']) ?? [],
                'price' => $r['price'] === null ? null : (string) $r['price'], 'perma_link' => $r['perma_link'],
                'units_30d' => $r['units_30d'] === null ? null : (int) $r['units_30d'], 'units_365d' => $r['units_365d'] === null ? null : (int) $r['units_365d'],
                'features' => $json($r['features']), 'features_version' => $r['features_version'], 'identity_hash' => $r['identity_hash']]);
            $n['listings']++;
        }
    } while (count($rows) === $chunk);

    // 2. Their items: the identity card, provenance, merges, and whether counted (DecisionService::counted's reading).
    $skuIds = array_keys($skuIds);
    sort($skuIds);
    foreach (array_chunk($skuIds, $chunk) as $ids) {
        // DecisionService::counted() written out (M39: the item or an item merged into it counted, or a recount open on one of them).
        $counted = array_flip(array_map('intval', $db->column(
            'WITH RECURSIVE fam (root, id, depth) AS (SELECT id, id, 0 FROM sku WHERE id IN (' . $in($ids) . ') UNION ALL '
            . 'SELECT fam.root, s.id, fam.depth + 1 FROM sku s JOIN fam ON s.merged_into_sku_id = fam.id WHERE fam.depth < 100) '
            . 'SELECT DISTINCT fam.root FROM fam JOIN sku s ON s.id = fam.id WHERE s.counted_at IS NOT NULL '
            . 'OR EXISTS (SELECT 1 FROM stock_balance b WHERE b.sku_id = fam.id AND (b.counted_at IS NOT NULL OR EXISTS ('
            . "SELECT 1 FROM stock_ledger l WHERE l.warehouse_id = b.warehouse_id AND l.sku_id = b.sku_id AND l.movement_type = 'count'))) "
            . "OR EXISTS (SELECT 1 FROM count_review r WHERE r.sku_id = fam.id AND r.status = 'open' AND r.source IN ('merge_recount', 'remap_correction'))",
            $ids,
        )));
        foreach ($db->all('SELECT id, code, name, brand, sell_policy, counted_at, origin, origin_listing_id, merged_into_sku_id, strength_mg, nic_type, line, '
            . 'form, flavour, volume_ml, puffs, pack_units FROM sku WHERE id IN (' . $in($ids) . ') ORDER BY id', $ids) as $s) {
            $out(['type' => 'sku', 'id' => (int) $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'brand' => $s['brand'], 'sell_policy' => $s['sell_policy'],
                'counted' => isset($counted[(int) $s['id']]), 'origin' => $s['origin'], 'origin_listing_id' => $s['origin_listing_id'] === null ? null : (int) $s['origin_listing_id'],
                'merged_into_sku_id' => $s['merged_into_sku_id'] === null ? null : (int) $s['merged_into_sku_id'],
                'card' => ['strength_mg' => $s['strength_mg'], 'nic_type' => $s['nic_type'], 'line' => $s['line'], 'form' => $s['form'], 'flavour' => $s['flavour'],
                    'volume_ml' => $s['volume_ml'], 'puffs' => $s['puffs'] === null ? null : (int) $s['puffs'], 'pack_units' => $s['pack_units'] === null ? null : (int) $s['pack_units']]]);
            $n['skus']++;
        }
        // Every link of these items, on any site (another site's listing moves with a merge; a quarantined one keeps it out).
        foreach ($db->all('SELECT cl.id, ch.code, cl.external_variant_id, cl.sku_id, cl.status, cl.units_per_item FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id '
            . 'WHERE cl.sku_id IN (' . $in($ids) . ') ORDER BY cl.id', $ids) as $l) {
            $out(['type' => 'link', 'listing_id' => (int) $l['id'], 'channel' => $l['code'], 'variant' => (string) $l['external_variant_id'], 'sku_id' => (int) $l['sku_id'],
                'status' => $l['status'], 'units_per_item' => (int) $l['units_per_item']]);
            $n['links']++;
        }
    }

    // 3. Merged items (for the item a listing is now), every merge suggestion (any status) and the rejects of these listings.
    foreach ($db->all('SELECT id, merged_into_sku_id FROM sku WHERE merged_into_sku_id IS NOT NULL ORDER BY id') as $s) {
        $out(['type' => 'merged', 'id' => (int) $s['id'], 'merged_into_sku_id' => (int) $s['merged_into_sku_id']]);
        $n['merged']++;
    }
    foreach ($db->all('SELECT p.id, r.run_id, r.source, p.match_run_id, p.listing_id, cl.channel_id, cl.external_variant_id, p.proposed_sku_id, p.status, p.lane, p.evidence, p.flags '
        . 'FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id JOIN channel_listing cl ON cl.id = p.listing_id '
        // DecisionService::duplicateLaneSql('p.lane'), written out: the export also runs against a checkout older than M34
        . "WHERE (p.lane = 'duplicate' OR RIGHT(p.lane, 10) = '_duplicate') ORDER BY p.id") as $p) {
        $out(['type' => 'proposal', 'id' => (int) $p['id'], 'run_id' => $p['run_id'], 'source' => $p['source'], 'match_run_id' => (int) $p['match_run_id'],
            'listing_id' => (int) $p['listing_id'], 'channel_id' => (int) $p['channel_id'], 'variant' => (string) $p['external_variant_id'],
            'proposed_sku_id' => $p['proposed_sku_id'] === null ? null : (int) $p['proposed_sku_id'], 'status' => $p['status'], 'lane' => $p['lane'],
            'evidence' => $json($p['evidence']), 'flags' => $json($p['flags'])]);
        $n['proposals']++;
    }
    foreach (array_chunk($listingIds, $chunk) as $ids) {
        foreach ($db->all('SELECT id, listing_id, lane, match_run_id FROM match_proposal WHERE open_listing_id IN (' . $in($ids) . ') ORDER BY id', $ids) as $p) {
            $out(['type' => 'open', 'proposal_id' => (int) $p['id'], 'listing_id' => (int) $p['listing_id'], 'lane' => $p['lane'], 'match_run_id' => (int) $p['match_run_id']]);
            $n['open']++;
        }
        foreach ($db->all('SELECT listing_id, sku_id FROM match_reject WHERE listing_id IN (' . $in($ids) . ') ORDER BY listing_id, sku_id', $ids) as $r) {
            $out(['type' => 'reject', 'listing_id' => (int) $r['listing_id'], 'sku_id' => (int) $r['sku_id']]);
            $n['rejects']++;
        }
        foreach ($db->all('SELECT id, pending_listing_id, action FROM match_decision WHERE pending_listing_id IN (' . $in($ids) . ') ORDER BY id', $ids) as $d) {
            $out(['type' => 'pending', 'decision_id' => (int) $d['id'], 'listing_id' => (int) $d['pending_listing_id'], 'action' => $d['action']]);
            $n['pending']++;
        }
    }
    // A reject recorded by a listing of ANOTHER item against one of these items counts too (M22 reads both directions).
    foreach (array_chunk($skuIds, $chunk) as $ids) {
        foreach ($db->all('SELECT r.listing_id, r.sku_id FROM match_reject r JOIN channel_listing cl ON cl.id = r.listing_id WHERE r.sku_id IN (' . $in($ids)
            . ') AND cl.channel_id <> ? ORDER BY r.listing_id, r.sku_id', [...$ids, $channelId]) as $r) {
            $out(['type' => 'reject', 'listing_id' => (int) $r['listing_id'], 'sku_id' => (int) $r['sku_id']]);
            $n['rejects']++;
        }
    }
    $db->exec('ROLLBACK');
    $out(['type' => 'meta', 'channel' => $code, 'channel_id' => $channelId, 'db' => $schema, 'exported_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'counts' => $n, 'ms' => intdiv(hrtime(true) - $t0, 1_000_000)]);
    fwrite(STDERR, 'export: ' . json_encode($n) . "\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'export failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(3);
}
