<?php

declare(strict_types=1);

/**
 * Copies the catalogue rows used by the golden trap pairs out of the export snapshots into
 * tests/fixtures/matching/golden_listings.json, so tests/matching/run.php runs without the exports.
 *
 *   php tools/first_match/extract_fixture.php [--vpg=<gz>] [--alt=<gz>]
 *
 * Only catalogue fields are kept (no stock, cost or sales beyond the unit counts the placeholder rule needs).
 */

$opt = getopt('', ['vpg:', 'alt:']);
$base = '/root/cw_work/first_match';
$pick = function (string $p): string {
    $f = glob($p) ?: [];
    sort($f);
    return (string) end($f);
};
$files = [
    'vapeandgo' => $opt['vpg'] ?? $pick("$base/vapeandgo_listings_*.jsonl.gz"),
    'electrofag' => $opt['alt'] ?? $pick("$base/electrofag_listings_*.jsonl.gz"),
];
$want = [
    'electrofag' => [
        30, 461, 886, 1032, 1345, 1628, 2031, 2215, 2984, 3249, 3308, 3648, 4026, 4280, 4380, 4771, 4774, 4877,
        5302, 5387, 5396, 6067, 6294, 6597, 6840, 7409, 7415, 7527, 7531, 8182, 9347, 9403, 9408, 9414, 9577,
        10952, 11850,
    ],
    'vapeandgo' => [
        48, 51, 750, 847, 8553, 8554, 11714, 12937, 12938, 12939, 13420, 27424, 28198, 29380, 29794, 30201,
        30477, 31162, 31810, 31811, 32108, 33157, 34922, 36279, 36285, 36530, 36531, 36645, 37053, 37097, 37353,
        37640, 38228, 38563, 38578, 38916, 40078, 40079, 40378, 40391, 40543, 41615, 42870, 43435, 43443, 44970,
        45513, 45768,
    ],
];
$keep = ['site', 'variant_id', 'product_id', 'product_title', 'variant_title', 'brand', 'brand_id', 'product_type',
    'variant_status', 'product_status', 'is_landing', 'is_default', 'stock_mode', 'price', 'sale_price', 'permalink',
    'product_permalink', 'barcodes', 'attributes', 'units_30d', 'units_365d'];
$out = ['generated_from' => [], 'ctx' => [], 'rows' => []];
foreach ($files as $site => $file) {
    $out['generated_from'][$site] = ['file' => basename($file), 'sha256' => hash_file('sha256', $file)];
    $wantSet = array_flip($want[$site]);
    $rows = [];
    $pub = [];
    $fh = gzopen($file, 'r');
    while (($l = gzgets($fh)) !== false) {
        $r = json_decode($l, true, 512, JSON_BIGINT_AS_STRING);
        if (($r['variant_status'] ?? '') === 'Published') {
            $pub[(int) $r['product_id']] = ($pub[(int) $r['product_id']] ?? 0) + 1;
        }
        if (isset($wantSet[(int) $r['variant_id']])) {
            $rows[(int) $r['variant_id']] = array_intersect_key($r, array_flip($keep));
        }
    }
    gzclose($fh);
    ksort($rows);
    foreach ($rows as $id => $r) {
        $out['rows'][$site][(string) $id] = $r;
        $out['ctx'][$site][(string) $id] = ['published_siblings' => $pub[(int) $r['product_id']] ?? 0];
    }
    $missing = array_diff($want[$site], array_keys($rows));
    if ($missing !== []) {
        fwrite(STDERR, "$site: missing ids " . implode(',', $missing) . "\n");
        exit(1);
    }
}
$dest = __DIR__ . '/../../tests/fixtures/matching/golden_listings.json';
@mkdir(dirname($dest), 0775, true);
file_put_contents($dest, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo "wrote $dest\n";
