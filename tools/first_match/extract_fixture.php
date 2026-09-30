<?php

declare(strict_types=1);

/**
 * Copies the catalogue rows used by the golden trap pairs out of the export snapshots into
 * tests/fixtures/matching/golden_listings.json, so tests/matching/run.php runs without the exports.
 *
 *   php tools/first_match/extract_fixture.php [--vpg=<gz>] [--alt=<gz>]
 *
 * Only catalogue fields are kept (no stock, cost or sales beyond the unit counts the placeholder rule needs).
 * Each row's normalisation context is stored with it: published siblings, and (n2.0) the line lexicon of its
 * brand on its site, computed exactly as tools/first_match/run.php does (published or sold rows).
 */

require __DIR__ . '/../../src/Matching/autoload.php';

use CW\Matching\TitlePattern;

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
        // pilot-1 misses (run1 pilot_eval): Bar Juice 5000, ELFLIQ, IVG Original, Lost Mary BM600, Elux Legend, Hayati Pro Max
        536, 551, 613, 2261, 2265, 3296, 3302, 5320, 5326, 5879, 6072, 6073, 8334, 13130,
    ],
    'vapeandgo' => [
        48, 51, 750, 847, 8553, 8554, 11714, 12937, 12938, 12939, 13420, 27424, 28198, 29380, 29794, 30201,
        30477, 31162, 31810, 31811, 32108, 33157, 34922, 36279, 36285, 36530, 36531, 36645, 37053, 37097, 37353,
        37640, 38228, 38563, 38578, 38916, 40078, 40079, 40378, 40391, 40543, 41615, 42870, 43435, 43443, 44970,
        45513, 45768,
        // pilot-1 misses: the decoys that no veto caught, and the true items
        9700, 10740, 10851, 11706, 11708, 12721, 12724, 12730, 12773, 26525, 26646, 27583, 28034, 28173, 28194, 28215,
        28223, 28262, 28673, 29364, 29515, 30464, 30514, 33145, 35274, 36306, 36307, 36318, 36572, 36999, 37702, 42419,
        42424, 42426, 46992,
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
    $lexRows = [];
    $fh = gzopen($file, 'r');
    while (($l = gzgets($fh)) !== false) {
        $r = json_decode($l, true, 512, JSON_BIGINT_AS_STRING);
        if (($r['variant_status'] ?? '') === 'Published') {
            $pub[(int) $r['product_id']] = ($pub[(int) $r['product_id']] ?? 0) + 1;
        }
        if (($r['variant_status'] ?? '') === 'Published' || (int) ($r['units_365d'] ?? 0) > 0) {
            $lexRows[] = ['brand' => $r['brand'] ?? '', 'product_id' => $r['product_id'], 'product_title' => $r['product_title'] ?? ''];
        }
        if (isset($wantSet[(int) $r['variant_id']])) {
            $rows[(int) $r['variant_id']] = array_intersect_key($r, array_flip($keep));
        }
    }
    gzclose($fh);
    $lex = TitlePattern::lexicon($lexRows);
    ksort($rows);
    foreach ($rows as $id => $r) {
        $out['rows'][$site][(string) $id] = $r;
        $out['ctx'][$site][(string) $id] = ['published_siblings' => $pub[(int) $r['product_id']] ?? 0,
            'line_lexicon' => $lex[TitlePattern::brandKey((string) ($r['brand'] ?? ''))] ?? []];
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
