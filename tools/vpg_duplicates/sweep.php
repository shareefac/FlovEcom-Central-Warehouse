<?php

declare(strict_types=1);

/**
 * The wider duplicate sweep of Vape and Go's own mapped listings (docs/decisions.md M37): which pairs of DIFFERENT CW items
 * are the same physical product on two pages, also when one has no barcode or the titles are worded differently. Rules
 * only (CW\Matching\DuplicateSweep, CW\Matching\DuplicateSweepRun): the matcher's normaliser, blocking and hard vetoes,
 * plus the sweep's stricter same-site rules. Reads an export file only: no database, no network. Deterministic.
 *
 *   php -d memory_limit=3G tools/vpg_duplicates/sweep.php --export=<export.jsonl[.gz]> --out=<dir> [--sample=40] [--seed=1]
 *       [--top=25] [--max-block=60] [--max-group=6]
 *
 * Writes under --out (created mode 0700; catalogue text only):
 *   groups.jsonl    one line per group to suggest: keeper, members, every pair explained (bin/import_vpg_duplicates.php reads it)
 *   pairs.jsonl     every pair the rules accepted, and what became of it (grouped, already_suggested, rejected, in_open_group, ...)
 *   summary.json    counts: candidates, judged, accepted, kept out by reason, groups by size, refusal reasons, the run id
 *   sample.txt      with --sample=N: N pairs of the groups drawn at random (seeded), side by side, for a person to check
 * Exit codes: 0 ok · 2 usage · 3 cannot run.
 */

require __DIR__ . '/../../src/Matching/autoload.php';

use CW\Matching\DuplicateSweep;
use CW\Matching\DuplicateSweepRun;
use CW\Matching\Text;

ini_set('memory_limit', '3G');
$o = getopt('', ['export:', 'out:', 'sample:', 'seed:', 'top:', 'max-block:', 'max-group:']);
$export = is_string($o['export'] ?? null) ? $o['export'] : null;
$out = is_string($o['out'] ?? null) ? rtrim($o['out'], '/') : null;
$int = static function (string $k, int $default, int $min, int $max) use ($o): int {
    $v = $o[$k] ?? null;
    if ($v === null) {
        return $default;
    }
    if (!is_string($v) || !ctype_digit($v) || (int) $v < $min || (int) $v > $max) {
        fwrite(STDERR, "--{$k} must be an integer from {$min} to {$max}\n");
        exit(2);
    }
    return (int) $v;
};
if ($export === null || $out === null || $out === '' || !is_readable($export)) {
    fwrite(STDERR, "usage: php tools/vpg_duplicates/sweep.php --export=<export.jsonl[.gz]> --out=<dir> [--sample=40] [--seed=1] [--top=25] [--max-block=60] [--max-group=6]\n");
    exit(2);
}
$sample = $int('sample', 0, 0, 1000);
$seed = $int('seed', 1, 0, PHP_INT_MAX);
$opts = ['top' => $int('top', DuplicateSweepRun::TOP_K, 1, 200), 'max_block' => $int('max-block', DuplicateSweepRun::MAX_BLOCK, 2, 1000),
    'max_group' => $int('max-group', DuplicateSweepRun::MAX_GROUP, 2, 20)];
$t0 = microtime(true);

$records = (static function (string $file): Generator {
    $fh = str_ends_with($file, '.gz') ? gzopen($file, 'rb') : fopen($file, 'rb');
    if ($fh === false) {
        throw new RuntimeException("cannot open {$file}");
    }
    $gets = str_ends_with($file, '.gz') ? 'gzgets' : 'fgets';
    while (($line = $gets($fh)) !== false) {
        $line = trim($line);
        if ($line !== '') {
            yield json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
    }
})($export);

try {
    $run = new DuplicateSweepRun($records);
    $r = $run->run($opts);
} catch (Throwable $e) {
    fwrite(STDERR, 'sweep failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(3);
}
if (!is_dir($out) && !mkdir($out, 0700, true) && !is_dir($out)) {
    fwrite(STDERR, "cannot create {$out}\n");
    exit(3);
}
@chmod($out, 0700);
$json = static fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$write = static function (string $path, string $body): void {
    $tmp = $path . '.tmp';
    file_put_contents($tmp, $body);
    chmod($tmp, 0600);
    rename($tmp, $path);
};
$write("{$out}/groups.jsonl", implode('', array_map(static fn (array $g): string => $json($g) . "\n", $r['groups'])));
$write("{$out}/pairs.jsonl", implode('', array_map(static fn (array $p): string => $json($p) . "\n", $r['pairs'])));
$groupsSha = hash_file('sha256', "{$out}/groups.jsonl");
$summary = ['engine' => DuplicateSweep::VERSION, 'export' => ['file' => basename($export), 'sha256' => hash_file('sha256', $export)],
    'groups_sha256' => $groupsSha, 'run_id' => 'sweep-' . DuplicateSweep::VERSION . '-' . substr($groupsSha, 0, 12), 'options' => $opts] + $r['summary'];

// The hand-check sample, drawn with a fixed seed: pairs of the new groups first; when they are fewer than --sample, topped up
// with the other pairs the rules accepted (suggested before, kept out), so the sample measures the rules' precision.
if ($sample > 0) {
    $draw = static function (array $all, int $n) use ($seed): array {
        mt_srand($seed);
        $pick = [];
        $count = count($all);
        for ($i = 0; $i < min($n, $count); $i++) {
            $j = mt_rand($i, $count - 1);
            [$all[$i], $all[$j]] = [$all[$j], $all[$i]];
            $pick[] = $all[$i];
        }
        return $pick;
    };
    $inGroups = [];
    $idOf = [];
    foreach ($r['groups'] as $g) {
        foreach ($g['members'] as $m) {
            $idOf[$m['vpg_variant_id']] = $m['listing_id'];
        }
        foreach ($g['pairs'] as $p) {
            $inGroups[] = ['label' => "group {$g['group']}", 'a' => $idOf[$p['a']], 'b' => $idOf[$p['b']]];
        }
    }
    $others = array_values(array_map(static fn (array $p): array => ['label' => $p['outcome'], 'a' => $p['a'], 'b' => $p['b']],
        array_filter($r['pairs'], static fn (array $p): bool => $p['outcome'] !== 'grouped')));
    $pick = $draw($inGroups, $sample);
    $more = $draw($others, $sample - count($pick));
    $lines = ["Hand-check sample: {$summary['run_id']}, seed {$seed}: " . count($pick) . ' of ' . count($inGroups) . ' pairs in ' . count($r['groups'])
        . ' groups, then ' . count($more) . ' of the ' . count($others) . " other pairs the rules accepted (suggested before or kept out)\n"];
    foreach (array_merge($pick, $more) as $i => $x) {
        $p = $run->judge($x['a'], $x['b']);
        $lines[] = sprintf("\n%2d. %s, score %d, barcode %s, price ratio %s%s", $i + 1, $x['label'], $p['score'], $p['barcode'],
            $p['price_ratio'] ?? '-', $p['same_product'] ? ', two options of one product page' : '');
        foreach ([$x['a'], $x['b']] as $id) {
            $m = $run->memberOf($id);
            $v = $m['vpg_variant_id'];
            $f = $run->features($id);
            $lines[] = sprintf('    vpg %s %s  %s  £%s  sold %d/365d  barcodes %d  [%s]', $v, $m['code'] ?? '-', $m['title'], $m['price'] ?? '-', $m['units_365d'], $m['barcodes'],
                implode(' ', array_filter([
                    $f['form'] ?? null, isset($f['strength_mg']) ? Text::num($f['strength_mg']) . 'mg' : null, isset($f['volume_ml']) ? Text::num($f['volume_ml']) . 'ml' : null,
                    isset($f['puffs']) ? $f['puffs'] . ' puffs' : null, isset($f['pack_units']) ? 'pack ' . $f['pack_units'] : null,
                    isset($f['resistance_ohm']) ? Text::num($f['resistance_ohm']) . 'ohm' : null, $f['colour'] ?? null, isset($f['vgpg']) ? 'vg ' . $f['vgpg'] : null,
                ])));
        }
        $lines[] = '    agree: ' . implode(', ', $p['agree']) . '; unknown on both: ' . (implode(', ', $p['unknown']) ?: '-');
    }
    $write("{$out}/sample.txt", implode("\n", $lines) . "\n");
}
$summary['ms'] = (int) round((microtime(true) - $t0) * 1000);
$write("{$out}/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDOUT, sprintf("sweep %s: listings=%d eligible=%d candidates=%d judged=%d accepted=%d excluded=%s groups=%d proposals=%d no_barcode_groups=%d run_id=%s ms=%d\n",
    DuplicateSweep::VERSION, $summary['listings'], $summary['eligible'], $summary['candidates'], $summary['judged'], $summary['accepted'],
    $json(array_filter($summary['excluded'])), $summary['groups'], $summary['proposals'], $summary['groups_with_a_listing_without_barcode'],
    $summary['run_id'], $summary['ms']));
exit(0);
