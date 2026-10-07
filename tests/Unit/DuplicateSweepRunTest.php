<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Matching\DuplicateSweep;
use CW\Matching\DuplicateSweepRun;
use PHPUnit\Framework\TestCase;

/**
 * One run of the wider duplicate sweep over an export (docs/decisions.md M37; CW\Matching\DuplicateSweepRun and
 * tools/vpg_duplicates/sweep.php): a group of three Corex pages (every two of them a pair; a fourth page that is a pair with
 * one of them but not with another stays out: `not_clique`), the keeper by units sold, and the pairs kept out: suggested
 * before (any status), kept separate (a reject), a quarantined listing, an item in an open suggestion group, a protected
 * item. The tool writes the same groups for the same export (deterministic), private files, and a seeded sample.
 */
final class DuplicateSweepRunTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (glob("{$this->dir}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    private static function ean(string $twelve): string
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }
        return $twelve . (10 - $sum % 10) % 10;
    }

    /**
     * A listing record of the export (mapped to item $id, its own item unless $sku says otherwise).
     *
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private static function listing(int $id, array $p, ?int $sku = null): array
    {
        return ['type' => 'listing', 'id' => $id, 'variant' => (string) (40000 + $id), 'sku_id' => $sku ?? $id, 'units_per_item' => 1, 'status' => 'mapped',
            'map_version' => 1, 'product_title' => $p['product_title'], 'variant_title' => $p['variant_title'] ?? $p['product_title'], 'brand' => $p['brand'],
            'attributes' => $p['attributes'] ?? [], 'barcodes' => $p['barcodes'] ?? [], 'price' => $p['price'] ?? '9.99', 'perma_link' => null,
            'units_30d' => $p['units_30d'] ?? 0, 'units_365d' => $p['units_365d'] ?? 0,
            'features' => ['product_id' => $p['product_id'] ?? 1000 + $id, 'variant_status' => 'Published'], 'features_version' => 'n2.0', 'identity_hash' => null];
    }

    /** @return list<array<string, mixed>> the export */
    private static function export(): array
    {
        $corexAttrs = [['name' => 'Resistance', 'value' => '0.4 ohm', 'attr_id' => 8, 'is_variable' => 1], ['name' => 'Pack Size', 'value' => '4 Pack', 'attr_id' => 19, 'is_variable' => 0],
            ['name' => 'Tank Capacity', 'value' => '2ml', 'attr_id' => 29, 'is_variable' => 0]];
        $liquid = static fn (string $flavour): array => ['product_title' => "{$flavour} Nic Salt E-Liquid by Elfliq 10ml", 'variant_title' => "{$flavour} Nic Salt E-Liquid by Elfliq 10ml - 20mg",
            'brand' => 'Elf Bar', 'price' => '3.99'];
        $r = [
            // the Corex group: three pages, every two a pair; the second sells most (the keeper)
            self::listing(1, ['product_title' => 'Vaporesso Xros Corex 3.0 Pods (Pack of 4)', 'variant_title' => 'Vaporesso Xros Corex 3.0 Pods (Pack of 4) - 0.4 ohm',
                'brand' => 'Vaporesso', 'price' => '9.99', 'barcodes' => [self::ean('694349868617')], 'attributes' => $corexAttrs, 'units_365d' => 100, 'units_30d' => 9]),
            self::listing(2, ['product_title' => 'Vaporesso Xros Corex Replacement Pods', 'variant_title' => 'Vaporesso Xros Corex Replacement Pods - 0.4ohm Corex 3.0 Pod - 4 Pack',
                'brand' => 'Vaporesso', 'price' => '10.99', 'units_365d' => 300, 'units_30d' => 20,
                'attributes' => [['name' => 'Type', 'value' => '0.4ohm Corex 3.0 Pod - 4 Pack', 'attr_id' => 18, 'is_variable' => 1]]]),
            self::listing(3, ['product_title' => 'Vaporesso Xros Corex 3.0 Pod - 4 Pack', 'variant_title' => 'Vaporesso Xros Corex 3.0 Pod - 4 Pack - 0.4ohm',
                'brand' => 'Vaporesso', 'price' => '9.49', 'units_365d' => 5]),
            // a fourth page: a pair with page 2 and 3, not with page 1 (another barcode): it stays out of the group
            self::listing(4, ['product_title' => 'Vaporesso Xros Corex 3.0 Pods (Pack of 4)', 'variant_title' => 'Vaporesso Xros Corex 3.0 Pods (Pack of 4) - 0.4 ohm',
                'brand' => 'Vaporesso', 'price' => '9.99', 'barcodes' => [self::ean('694349869755')], 'attributes' => $corexAttrs, 'units_365d' => 1]),
            // kept separate before (a reject of item 5 by listing 6)
            self::listing(5, ['product_title' => 'Pink Fizz Nic Salt E-Liquid by IVG Bar Salt Favourites', 'variant_title' => 'Pink Fizz Nic Salt E-Liquid by IVG Bar Salt Favourites - 10mg',
                'brand' => 'IVG', 'price' => '2.99', 'attributes' => [['name' => 'Bottle Size', 'value' => '10ml', 'attr_id' => 4, 'is_variable' => 0]]]),
            self::listing(6, ['product_title' => 'IVG Nic Salt 10ml', 'variant_title' => 'IVG Nic Salt 10ml - 10mg | Pink Fizz (Bar Favourites)', 'brand' => 'IVG', 'price' => '2.99']),
            // suggested before (a superseded suggestion still counts)
            self::listing(7, ['product_title' => 'Peeky Blenders E Liquid Menthol - Godfellas (Sweet Tropical Fruit) - 100ml', 'brand' => 'Peeky Blenders Vape Juice', 'price' => '5.99',
                'attributes' => [['name' => 'PG/VG', 'value' => '50/50', 'attr_id' => 5, 'is_variable' => 0]]]),
            self::listing(8, ['product_title' => 'Peeky Blenders E Liquid Menthol - Goodfellas (Sweet Tropical Fruit) - 100ml', 'brand' => 'Peeky Blenders Vape Juice', 'price' => '5.99',
                'attributes' => [['name' => 'PG/VG', 'value' => '50/50', 'attr_id' => 5, 'is_variable' => 0]]]),
            // a quarantined listing of item 10 (on another site)
            self::listing(9, $liquid('Banana Ice')), self::listing(10, $liquid('Banana Ice')),
            // item 11 is in an open suggestion group with item 15
            self::listing(11, $liquid('Strawberry Kiwi')), self::listing(12, $liquid('Strawberry Kiwi')), self::listing(15, $liquid('Lemon Lime')),
            // item 14 is protected
            self::listing(13, $liquid('Grape Ice')), self::listing(14, $liquid('Grape Ice')),
        ];
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15] as $i) {
            $r[] = ['type' => 'sku', 'id' => $i, 'code' => sprintf('CW-%06d', $i), 'name' => "item {$i}", 'brand' => null, 'sell_policy' => $i === 14 ? 'strict' : 'legacy',
                'counted' => false, 'origin' => 'vpg_mint', 'origin_listing_id' => $i, 'merged_into_sku_id' => null, 'card' => []];
            $r[] = ['type' => 'link', 'listing_id' => $i, 'channel' => 'vapeandgo', 'variant' => (string) (40000 + $i), 'sku_id' => $i, 'status' => 'mapped', 'units_per_item' => 1];
        }
        $r[] = ['type' => 'link', 'listing_id' => 900, 'channel' => 'electrofag', 'variant' => 'E900', 'sku_id' => 10, 'status' => 'quarantined', 'units_per_item' => 1];
        $r[] = ['type' => 'reject', 'listing_id' => 6, 'sku_id' => 5];
        $r[] = ['type' => 'proposal', 'id' => 145, 'run_id' => 'run2-vpg-duplicates', 'source' => 'vpg_duplicates', 'match_run_id' => 1, 'listing_id' => 8, 'channel_id' => 1,
            'variant' => '40008', 'proposed_sku_id' => 7, 'status' => 'superseded', 'lane' => 'vpg_duplicate',
            'evidence' => ['group' => 4, 'kind' => 'identity_key', 'keeper' => ['vpg_variant_id' => '40007'], 'members' => [['vpg_variant_id' => '40007'], ['vpg_variant_id' => '40008']]],
            'flags' => ['identity_key', 'merge_suggestion']];
        $r[] = ['type' => 'proposal', 'id' => 146, 'run_id' => 'run2-vpg-duplicates', 'source' => 'vpg_duplicates', 'match_run_id' => 1, 'listing_id' => 11, 'channel_id' => 1,
            'variant' => '40011', 'proposed_sku_id' => 15, 'status' => 'open', 'lane' => 'vpg_duplicate', 'evidence' => ['group' => 5, 'kind' => 'identity_key'], 'flags' => []];
        $r[] = ['type' => 'open', 'proposal_id' => 146, 'listing_id' => 11, 'lane' => 'vpg_duplicate', 'match_run_id' => 1];
        $r[] = ['type' => 'meta', 'channel' => 'vapeandgo', 'channel_id' => 1, 'db' => 'test', 'exported_at_utc' => '2026-10-06T22:57:05Z', 'counts' => []];
        return $r;
    }

    public function testTheRunGroupsCliquesAndKeepsOutWhatMustNotBeSuggested(): void
    {
        $out = (new DuplicateSweepRun(self::export()))->run(['top' => 10]);
        $s = $out['summary'];
        self::assertSame(15, $s['eligible']);
        self::assertSame(['already_suggested' => 1, 'rejected' => 1, 'protected' => 1, 'unknown_item' => 0, 'quarantined' => 1, 'pending' => 0,
            'in_open_group' => 1, 'open_proposal' => 0], $s['excluded']);
        self::assertSame(1, $s['groups']);
        self::assertGreaterThanOrEqual(1, $s['not_clique']);
        $g = $out['groups'][0];
        self::assertSame(1, $g['group']);
        self::assertSame('sweep', $g['kind']);
        self::assertSame(DuplicateSweep::VERSION . ':1-2-3', $g['key']);
        self::assertSame('40002', $g['keeper']['vpg_variant_id'], 'the keeper sold most');
        self::assertSame(['40002', '40001', '40003'], array_column($g['members'], 'vpg_variant_id'));
        self::assertSame([2, 1, 3], array_column($g['members'], 'sku_id'));
        self::assertCount(3, $g['pairs'], 'every two members, explained');
        foreach ($g['pairs'] as $p) {
            self::assertContains('resistance', $p['agree']);
            self::assertContains($p['barcode'], ['one_side', 'none']);
        }
        self::assertSame(2, $g['no_barcode']);
        $outcome = [];
        foreach ($out['pairs'] as $p) {
            $outcome[$p['a'] . '-' . $p['b']] = $p['outcome'];
        }
        self::assertSame('rejected', $outcome['5-6']);
        self::assertSame('already_suggested', $outcome['7-8']);
        self::assertSame('quarantined', $outcome['9-10']);
        self::assertSame('in_open_group', $outcome['11-12']);
        self::assertSame('protected', $outcome['13-14']);
        self::assertSame('grouped', $outcome['1-2']);
        self::assertContains($outcome['2-4'], ['not_clique', 'grouped']);
        self::assertSame('not_clique', $outcome['3-4']);
        self::assertArrayNotHasKey('1-4', $outcome, 'two Corex pages with different barcodes are not a pair');
    }

    public function testTheToolIsDeterministicAndWritesPrivateFiles(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw-sweep-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $export = "{$this->dir}/export.jsonl";
        file_put_contents($export, implode('', array_map(static fn (array $r): string => json_encode($r, JSON_THROW_ON_ERROR) . "\n", self::export())));
        $run = function (string $out): array {
            $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/vpg_duplicates/sweep.php', "--export={$this->dir}/export.jsonl", "--out={$out}", '--sample=40', '--seed=7', '--top=10'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($p);
            $o = (string) stream_get_contents($pipes[1]);
            $e = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return ['code' => proc_close($p), 'out' => $o, 'err' => $e];
        };
        $a = $run("{$this->dir}/a");
        self::assertSame(0, $a['code'], $a['err']);
        self::assertStringContainsString('groups=1 proposals=2', $a['out']);
        $b = $run("{$this->dir}/b");
        self::assertSame(0, $b['code'], $b['err']);
        self::assertSame(file_get_contents("{$this->dir}/a/groups.jsonl"), file_get_contents("{$this->dir}/b/groups.jsonl"), 'the same export, the same groups');
        self::assertSame(file_get_contents("{$this->dir}/a/sample.txt"), file_get_contents("{$this->dir}/b/sample.txt"), 'the same seed, the same sample');
        $summary = json_decode((string) file_get_contents("{$this->dir}/a/summary.json"), true);
        self::assertSame('sweep-' . DuplicateSweep::VERSION . '-' . substr(hash_file('sha256', "{$this->dir}/a/groups.jsonl"), 0, 12), $summary['run_id']);
        self::assertSame(1, $summary['groups']);
        self::assertSame(0700, fileperms("{$this->dir}/a") & 0777);
        foreach (['groups.jsonl', 'pairs.jsonl', 'summary.json', 'sample.txt'] as $f) {
            self::assertSame(0600, fileperms("{$this->dir}/a/{$f}") & 0777, $f);
        }
        $sample = (string) file_get_contents("{$this->dir}/a/sample.txt");
        self::assertMatchesRegularExpression('/3 of 3 pairs in 1 groups, then (\d+) of the \1 other pairs the rules accepted/', $sample);
        self::assertStringContainsString('rejected, score', $sample, 'the pairs kept out are sampled too, with what kept them out');
        foreach (glob("{$this->dir}/{a,b}/*", GLOB_BRACE) ?: [] as $f) {
            unlink($f);
        }
        rmdir("{$this->dir}/a");
        rmdir("{$this->dir}/b");
        self::assertSame(2, $run('')['code'], 'no --out: usage');
    }
}
