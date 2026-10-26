<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Config;
use CW\Staff\SecretBox;
use CW\Staff\Totp;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;

/**
 * The first-match tools end to end, as separate processes against the test schema (admin login),
 * on the small fixtures in tests/fixtures/mapping (the same shapes as the real run2/run3 files):
 * import_listings -> mint_vpg (dry run, then for real) -> import_proposals, each re-run to show it
 * is idempotent; and create_staff with a throwaway app.env.
 */
final class ImportToolsTest extends MappingTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/mapping';

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
        parent::tearDown();
    }

    public function testImportListingsMintVpgAndImportProposalsEndToEnd(): void
    {
        $dir = $this->fixtures();
        $vpg = $this->site('vpg', 'shadow');
        $alt = $this->site('alt', 'shadow');
        $lead = $this->staffUser('mapping_lead');
        $email = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$lead->staffUserId]);

        // 1. Listings. A line that fails the PUT checks is skipped (exit 1); re-runs change nothing.
        $r = self::tool('import_listings', '--channel=vpg', "--file={$dir}/vpg_export.jsonl.gz");
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('lines=6 received=6 created=6 updated=0 unchanged=0', $r['out']);
        $r = self::tool('import_listings', '--channel=vpg', "--file={$dir}/vpg_export.jsonl.gz", '--batch=2');
        self::assertStringContainsString('received=6 created=0 updated=0 unchanged=6 identity_changed=0 skipped=0 batches=3', $r['out']);
        $r = self::tool('import_listings', '--channel=alt', "--file={$dir}/alt_export.jsonl.gz");
        self::assertSame(1, $r['code']);
        self::assertStringContainsString('received=4 created=4', $r['out']);
        self::assertStringContainsString('line 5 skipped', $r['err']);
        self::assertSame(10, (int) self::$db->value("SELECT COUNT(*) FROM channel_listing WHERE status = 'unmapped'"));
        self::assertSame(2, self::tool('import_listings', '--channel=nope', "--file={$dir}/vpg_export.jsonl.gz")['code']);
        $attrs = json_decode((string) self::$db->value("SELECT p.attributes FROM listing_profile p JOIN channel_listing l ON l.id = p.listing_id WHERE l.channel_id = ? AND l.external_variant_id = '12723'", [$vpg->channelId]), true);
        self::assertSame(['Mr Blue', '10mg'], array_column($attrs['items'], 'value'));

        // 2. The seed: a dry run writes nothing.
        $r = self::tool('mint_vpg', "--features={$dir}/run2/listings_features.jsonl", "--staff={$email}", '--dry-run');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('DRY RUN', $r['out']);
        self::assertStringContainsString('vpg=6 seed=4 not_seed=2 written=0; mint: minted=0 would_mint=4', $r['out']);
        self::assertStringContainsString('duplicates group 1 (identity_key): keep vpg 48', $r['out']);
        self::assertSame([0, 0, 0], [(int) self::$db->value('SELECT COUNT(*) FROM sku'), $this->decisions(),
            (int) self::$db->value('SELECT COUNT(*) FROM listing_profile WHERE features IS NOT NULL')]);
        self::assertSame(2, self::tool('mint_vpg', "--features={$dir}/run2/listings_features.jsonl", '--staff=' . self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$this->staffUser('mapper')->staffUserId]))['code'], 'a mapper cannot mint');
        // admin + mapping_lead (only admin SQL can write it) is read fail-closed, as DecisionService reads it (I35): refused up front.
        $bad = self::tool('mint_vpg', "--features={$dir}/run2/listings_features.jsonl", '--staff=' . self::$db->value('SELECT email FROM staff_user WHERE id = ?',
            [$this->staffUser(['admin', 'mapping_lead'])->staffUserId]));
        self::assertSame(2, $bad['code'], 'admin + mapping_lead cannot mint');
        self::assertStringContainsString('admin, mapping_lead', $bad['err']);

        $r = self::tool('mint_vpg', "--features={$dir}/run2/listings_features.jsonl", "--staff={$email}");
        self::assertSame(0, $r['code'], $r['err'] . $r['out']);
        // (the fixtures' two barcodes have wrong check digits: nothing to seed into sku_barcode, BarcodeSeederTest covers it)
        self::assertStringContainsString('written=6; mint: minted=4 would_mint=0 already_linked=0 ignored=0 missing_listing=0 failed=0; '
            . 'barcodes: added=0 clashes=0; duplicates: groups=1 proposals=1', $r['out']);
        $seed = self::$db->all(
            "SELECT l.external_variant_id AS v, s.code, s.name, s.origin, s.strength_mg, d.action, d.bulk_batch_id, d.decided_by, d.state FROM channel_listing l "
            . 'JOIN sku s ON s.id = l.sku_id JOIN match_decision d ON d.listing_id = l.id WHERE l.channel_id = ? ORDER BY CAST(l.external_variant_id AS UNSIGNED)',
            [$vpg->channelId],
        );
        self::assertSame(['48', '135', '12723', '26627'], array_column($seed, 'v'), 'Bin and landing listings are not minted');
        foreach ($seed as $row) {
            self::assertSame(['vpg_mint', 'link', 'vpg_mint:run2', $lead->staffUserId, 'applied'],
                [$row['origin'], $row['action'], $row['bulk_batch_id'], $row['decided_by'], $row['state']]);
            self::assertMatchesRegularExpression('/^CW-\d{6}$/', $row['code']);
        }
        self::assertSame(['Nic Nic Nicotine Shots - 18mg/ml - 100% VG', '18.00'], [$seed[0]['name'], $seed[0]['strength_mg']]);
        self::assertSame(6, (int) self::$db->value('SELECT COUNT(*) FROM listing_profile p JOIN channel_listing l ON l.id = p.listing_id WHERE l.channel_id = ? AND p.features_version = ?', [$vpg->channelId, 'n2.0']));
        $skuOf = static fn (int $channel, string $v): int => (int) self::$db->value('SELECT sku_id FROM channel_listing WHERE channel_id = ? AND external_variant_id = ?', [$channel, $v]);
        // The duplicate group: 135 (fewer sales) is proposed for a merge into 48's item; nothing merged.
        $dup = self::$db->one("SELECT p.*, r.run_id, r.source FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE p.lane = 'vpg_duplicate'");
        self::assertSame(['run2-vpg-duplicates', 'vpg_duplicates', 'Manual', 'open', $skuOf((int) $vpg->channelId, '48')],
            [$dup['run_id'], $dup['source'], $dup['band'], $dup['status'], $dup['proposed_sku_id']]);
        self::assertSame((int) self::$db->value('SELECT id FROM channel_listing WHERE channel_id = ? AND external_variant_id = ?', [$vpg->channelId, '135']), $dup['listing_id']);
        self::assertSame(['identity_key', 'merge_suggestion'], json_decode((string) $dup['flags'], true));
        self::assertNotSame($skuOf((int) $vpg->channelId, '48'), $skuOf((int) $vpg->channelId, '135'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE action = 'merge_skus'"));

        $r = self::tool('mint_vpg', "--features={$dir}/run2/listings_features.jsonl", "--staff={$email}");
        self::assertStringContainsString('minted=0 would_mint=0 already_linked=4', $r['out']);
        self::assertStringContainsString('proposals=0 exists=1', $r['out']);
        self::assertSame(4, (int) self::$db->value('SELECT COUNT(*) FROM sku'));

        // 3. The judge run's proposals for Electrofag.
        $r = self::tool('import_proposals', "--run-dir={$dir}/run3", "--private={$dir}/private/run3");
        self::assertSame(1, $r['code'], 'one proposal names a listing that was not imported');
        self::assertStringContainsString('electrofag variant 999: no listing row', $r['err']);
        self::assertStringContainsString('run=run3t-sold channel=alt features_written=4 proposals=5 created=4 exists=0 superseded=0 suggested=4 missing_listing=1 target_not_minted=0', $r['out']);
        $run = self::$db->one("SELECT * FROM match_run WHERE source = 'first_match'");
        self::assertSame(['run3t-sold', '0bfb5977276db84336b14283287c0111d76046dfb6fb1d159097a13968e86873', 'n2.0/c1.0/v2.0/b2.0'],
            [$run['run_id'], $run['prompt_sha'], $run['engine_version']]);
        self::assertSame(1, json_decode((string) $run['model_summary'], true)['claude-opus-5-5']['chunks']);
        $props = [];
        foreach (self::$db->all('SELECT p.*, l.external_variant_id AS v, l.status AS listing_status FROM match_proposal p JOIN channel_listing l ON l.id = p.listing_id WHERE l.channel_id = ?', [$alt->channelId]) as $p) {
            $props[(string) $p['v']] = $p;
        }
        self::assertSame(['2387', '5894', '700', '701'], self::sorted(array_keys($props)));
        $k = $props['5894'];
        self::assertSame(['Key', 'barcode', 'match', 95, 1, 'claude-opus-5-5', $skuOf((int) $vpg->channelId, '12723'), 0, 'suggested', 'open'],
            [$k['band'], $k['lane'], $k['ai_outcome'], $k['ai_confidence'], $k['ai_units_per_item'], $k['ai_model'], $k['proposed_sku_id'],
                $k['proposed_new_item'], $k['listing_status'], $k['status']]);
        $ev = json_decode((string) $k['evidence'], true);
        self::assertSame([[$skuOf((int) $vpg->channelId, '12723'), 'lane_target', []], [$skuOf((int) $vpg->channelId, '26627'), 'search', ['flavour_diff']]],
            array_map(static fn (array $c): array => [$c['sku_id'], $c['role'], $c['vetoes']], $ev['candidates']));
        self::assertSame($skuOf((int) $vpg->channelId, '12723'), $ev['lane_target']['sku_id']);
        self::assertSame(['Check', ['ceiling:Check', 'price_outlier']], [$props['2387']['band'], json_decode((string) $props['2387']['flags'], true)]);
        self::assertSame(['New item', 1, null, $skuOf((int) $vpg->channelId, '26627'), 'claude-sonnet-5-5'],
            [$props['700']['band'], $props['700']['proposed_new_item'], $props['700']['proposed_sku_id'], $props['700']['closest_sku_id'], $props['700']['ai_model']]);
        self::assertSame(['Manual', null, ['relabel_pending']], [$props['701']['band'], $props['701']['proposed_sku_id'], json_decode((string) $props['701']['flags'], true)]);
        self::assertSame("Manual (relabel)", json_decode((string) $props['701']['evidence'], true)['band']);
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE action = 'suggest' AND decided_by IS NULL AND actor = 'system:import_proposals'"));

        $r = self::tool('import_proposals', "--run-dir={$dir}/run3", "--private={$dir}/private/run3");
        self::assertStringContainsString('proposals=5 created=0 exists=4 superseded=0 suggested=0', $r['out']);
        self::assertSame(4, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE match_run_id = ?', [$run['id']]));

        // 4. People decide from the proposals: the Key link, and a new item from the stored features.
        $mapper = $this->staffUser('mapper');
        $l5894 = (int) $k['listing_id'];
        $done = $this->decide($mapper, 'link', $l5894, ['sku_id' => $k['proposed_sku_id'], 'proposal_id' => $k['id']]);
        self::assertSame(['applied', 'mapped'], [$done['state'], $done['status']]);
        $n = $this->decide($mapper, 'new_item', (int) $props['700']['listing_id'], ['proposal_id' => $props['700']['id']]);
        self::assertSame(['Acme Cloud Bar 6000 Puffs - Cola Ice 20mg', 'Acme', '20.00', 6000, 'acme cloud bar 6000', 'disposable', 'cola ice'],
            array_values(self::$db->one('SELECT name, brand, strength_mg, puffs, line, form, flavour FROM sku WHERE id = ?', [$n['sku_id']])));
    }

    /**
     * The real Vape and Go export has listings whose barcode field holds junk ("Black Grey", "85104 - 1"):
     * the PUT checks refuse such a code, which used to skip the whole listing (5 of 29,105). The importer
     * drops the unusable codes, keeps the listing and the usable codes, and reports every drop (M17).
     */
    public function testImportListingsDropsUnusableBarcodesButKeepsTheListing(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw-barcodes-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $line = static fn (int $id, array $barcodes): string => json_encode([
            'site' => 'vapeandgo', 'variant_id' => $id, 'product_id' => $id, 'product_title' => "Product {$id}", 'variant_title' => "Variant {$id}",
            'brand' => 'Acme', 'barcodes' => $barcodes, 'attributes' => [], 'price' => 4.99, 'units_30d' => 1, 'units_365d' => 2,
        ], JSON_THROW_ON_ERROR);
        $lines = [
            $line(9001, ['85104 - 1', 5060505372588]),           // junk + a good EAN (the real 12600 shape)
            $line(9002, ['Black Grey']),                          // only junk (the real 8779 shape)
            $line(9003, [str_repeat('9', 65), '5012345678900', "12\x0034"]), // too long + good + a control character
            $line(9004, ['5000000000017']),                       // nothing to drop
        ];
        $file = "{$this->dir}/export.jsonl.gz";
        file_put_contents($file, gzencode(implode("\n", $lines) . "\n"));
        $vpg = $this->site('vpg', 'shadow');

        $r = self::tool('import_listings', '--channel=vpg', "--file={$file}");
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('lines=4 received=4 created=4 updated=0 unchanged=0 identity_changed=0 skipped=0 batches=1 barcodes_dropped=4 listings_with_dropped=3', $r['out']);
        self::assertStringContainsString('line 1 variant 9001: barcode "85104 - 1" dropped', $r['err']);
        self::assertStringContainsString('line 2 variant 9002: barcode "Black Grey" dropped', $r['err']);
        self::assertStringNotContainsString('line 4 ', $r['err']);
        $stored = [];
        foreach (self::$db->all('SELECT l.external_variant_id AS v, p.barcodes FROM listing_profile p JOIN channel_listing l ON l.id = p.listing_id WHERE l.channel_id = ?', [$vpg->channelId]) as $row) {
            $stored[(string) $row['v']] = $row['barcodes'] === null ? null : json_decode((string) $row['barcodes'], true);
        }
        ksort($stored);
        self::assertSame(['9001' => ['5060505372588'], '9002' => null, '9003' => ['5012345678900'], '9004' => ['5000000000017']], $stored);
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM channel_listing WHERE channel_id = ? AND status = 'unmapped'", [$vpg->channelId]));

        // Idempotent: the same cleaning gives the same profile hash, so nothing changes.
        $r = self::tool('import_listings', '--channel=vpg', "--file={$file}");
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('received=4 created=0 updated=0 unchanged=4 identity_changed=0 skipped=0', $r['out']);
    }

    /** One line of an export file with its website state (tools/first_match/export.php writes these fields). @param array<string, mixed> $over */
    private static function exportLine(int $id, array $over = []): string
    {
        return json_encode($over + ['site' => 'electrofag', 'variant_id' => $id, 'product_id' => 500, 'product_title' => "Product {$id}",
            'variant_title' => "Variant {$id}", 'brand' => 'Acme', 'product_type' => 'variable', 'variant_status' => 'Published', 'product_status' => 'Published', 'is_landing' => 0,
            'is_default' => 0, 'permalink' => "product-{$id}/variant", 'product_permalink' => "product-{$id}", 'barcodes' => ['50000000' . $id], 'attributes' => [], 'price' => 4.99,
            'units_30d' => 1, 'units_365d' => 2], JSON_THROW_ON_ERROR);
    }

    /** Every table's checksum: what any write at all would move. @return array<string, mixed> */
    private static function checksums(): array
    {
        $out = [];
        foreach (self::$db->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $t) {
            $out[(string) $t] = self::$db->one('CHECKSUM TABLE ' . \CW\Db::ident((string) $t))['Checksum'];
        }
        return $out;
    }

    /** Everything of the variants but their website state: the listings, and every other column of their profiles. @return list<array<string, mixed>> */
    private static function allButTheState(): array
    {
        return self::$db->all('SELECT cl.id, cl.channel_id, cl.external_variant_id, cl.sku_id, cl.units_per_item, cl.status, cl.map_version, lp.product_title, lp.variant_title, lp.brand, '
            . 'CAST(lp.attributes AS CHAR) AS attributes, CAST(lp.barcodes AS CHAR) AS barcodes, lp.price, lp.perma_link, lp.units_30d, lp.units_365d, lp.profile_hash, lp.identity_hash, '
            . 'CAST(lp.features AS CHAR) AS features, lp.features_version, lp.pushed_at, lp.arrival_state, lp.arrival_seq, lp.arrival_channel_id, lp.arrival_checked_at, lp.arrival_outcome '
            . 'FROM channel_listing cl LEFT JOIN listing_profile lp ON lp.listing_id = cl.id ORDER BY cl.id');
    }

    /** The website state of the store's variants, by variant: status, product's status, product's number, date. @return array<string, list<mixed>> */
    private static function states(): array
    {
        $out = [];
        foreach (self::$db->all('SELECT cl.external_variant_id AS v, lp.site_variant_status, lp.site_product_status, lp.site_product_type, lp.site_product_id, lp.site_is_landing, '
            . 'lp.site_is_default, lp.site_product_link, lp.site_state_at FROM channel_listing cl JOIN listing_profile lp ON lp.listing_id = cl.id ORDER BY cl.id') as $r) {
            $out[(string) $r['v']] = array_slice(array_values($r), 1);
        }
        return $out;
    }

    /**
     * The state-only load (docs/decisions.md M66; bin/import_listings.php --state-only): the website state of the variants that
     * are already there, from an export file, and NOTHING else. Without --apply it counts and writes nothing at all (every
     * table's checksum is the same afterwards). With --apply it writes the eight state columns of a variant whose line is the
     * stored profile (the same profile_hash) and leaves the rest: a line that differs and a variant the warehouse does not know
     * are counted and left. It creates no listing, moves no identity, no map_version, no features and no mark, and queues nothing.
     * A command line it cannot read whole is refused (it must never turn into an apply).
     */
    public function testTheStateOnlyLoadWritesOnlyTheStateAndOnlyWhereTheLineIsTheStoredProfile(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw-state-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $site = $this->site('electrofag', 'off');
        // What is there: four variants loaded before the state was kept (a file with no time in its name: the import cannot date
        // a state, so it writes none), as staging's are. 9003's barcode field holds junk beside its barcode.
        $base = [self::exportLine(9001), self::exportLine(9002), self::exportLine(9003, ['barcodes' => ['85104 - 1', 5060505372588]]), self::exportLine(9005)];
        file_put_contents("{$this->dir}/base.jsonl.gz", gzencode(implode("\n", $base) . "\n"));
        $r = self::tool('import_listings', '--channel=electrofag', "--file={$this->dir}/base.jsonl.gz");
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('lines=4 received=4 created=4 updated=0 unchanged=0', $r['out']);
        self::assertStringContainsString('state_at=not_dated state_written=0', $r['out']);
        $none = [null, null, null, null, null, null, null, null];
        self::assertSame(['9001' => $none, '9002' => $none, '9003' => $none, '9005' => $none], self::states());
        // What people and the computer did since: an item made from 9001 (matched), features stored on 9002, the check on arrival
        // looked at 9003, and 9005 was there before the check.
        $id = static fn (string $v): int => (int) self::$db->value('SELECT id FROM channel_listing WHERE external_variant_id = ?', [$v]);
        $this->decide($this->staffUser('mapping_lead'), 'new_item', $id('9001'));
        self::$db->exec("UPDATE listing_profile SET features = JSON_OBJECT('variant_status', 'Published', 'strength_mg', 20), features_version = 'n2.1' WHERE listing_id = ?", [$id('9002')]);
        self::$db->exec("UPDATE listing_profile SET arrival_state = 'done', arrival_checked_at = '2026-10-12 08:00:00.000000', arrival_outcome = 'no_clue' WHERE listing_id = ?", [$id('9003')]);
        self::$db->exec('UPDATE listing_profile SET arrival_state = NULL, arrival_seq = 0, arrival_channel_id = NULL WHERE listing_id = ?', [$id('9005')]);

        // The export file of the state, with its time in its name: 9001 and 9003 as they are stored (9003 with the same junk, which
        // the tool drops the same way); 9002 was renamed on the website since; 9004 the warehouse has never seen; a line that is
        // no listing; 9005 without the fields of the state; and 9001 a second time.
        $file = "{$this->dir}/electrofag_listings_20260926T094152Z.jsonl.gz";
        $lines = [self::exportLine(9001, ['variant_status' => 'Bin', 'is_default' => 1]), self::exportLine(9002, ['variant_title' => 'Variant 9002, renamed']),
            self::exportLine(9003, ['barcodes' => ['85104 - 1', 5060505372588], 'product_status' => 'Draft']), self::exportLine(9004), '{"site": "electrofag", "variant_id": "a b"}',
            json_encode(array_diff_key(json_decode(self::exportLine(9005), true), array_flip(['product_id', 'product_type', 'variant_status', 'product_status', 'is_landing',
                'is_default', 'product_permalink'])), JSON_THROW_ON_ERROR), self::exportLine(9001)];
        file_put_contents($file, gzencode(implode("\n", $lines) . "\n"));
        $before = self::allButTheState();
        $marks = self::$db->all('SELECT listing_id, arrival_state, arrival_seq FROM listing_profile ORDER BY listing_id');
        self::assertSame(['queued', 'queued', 'done', null], array_column($marks, 'arrival_state'), 'two wait for the check on arrival, one was looked at, one was there before it');
        $sums = self::checksums();

        // 1. Without --apply: it counts, says so, and writes NOTHING (no state, no audit row, no listing for 9004).
        $dry = self::tool('import_listings', '--channel=electrofag', "--file={$file}", '--state-only');
        self::assertSame(1, $dry['code'], 'lines were left: said by the exit code');
        self::assertStringContainsString('STATE ONLY, DRY RUN (nothing written) channel=electrofag file=electrofag_listings_20260926T094152Z.jsonl.gz sites=electrofag '
            . 'state_at=2026-09-26T09:41:52Z lines=7 received=5 would_write=2 (first=2 changed=0 same=0) differs=1 unknown=1 no_state=1 older=0 values_ignored=0 skipped=2', $dry['out']);
        self::assertStringContainsString('variant 9002 differs from the stored profile: left', $dry['err']);
        self::assertStringContainsString('variant 9004 is not known to the warehouse: left', $dry['err']);
        self::assertSame($sums, self::checksums(), 'a dry run changed no table');

        // 2. A command line it cannot read whole, and one that does not make sense, is refused before anything is read: never an apply.
        foreach ([['--state-only=1', '--apply'], ['--state-only', '--apply=false'], ['--state-only', '--apply=0'], ['--stateonly', '--apply'], ['--state-only', '--apply', '--apply'],
            ['--state-only', 'x', '--apply'], ['--state-only', '--apply', '--exported-at'], ['--state-only', '--apply', '--exported-at='], ['--state-only', '--apply', '--batch', '5'],
            ['--state-only', '--aply']] as $args) {
            $r = self::tool('import_listings', '--channel=electrofag', "--file={$file}", ...$args);
            self::assertSame([2, ''], [$r['code'], $r['out']], implode(' ', $args) . ': ' . $r['err']);
            self::assertStringContainsString('usage: php bin/import_listings.php', $r['err'], implode(' ', $args));
        }
        foreach ([['--apply'], ['--state-only', '--apply', '--exported-at=yesterday'], ['--state-only', '--apply', '--exported-at=2999-01-01T00:00:00Z'], ['--state-only', '--apply', '--batch=0']] as $args) {
            $r = self::tool('import_listings', '--channel=electrofag', "--file={$file}", ...$args);
            self::assertSame([2, ''], [$r['code'], $r['out']], implode(' ', $args) . ': ' . $r['err']);
        }
        // A file whose name has no time, and no --exported-at: a state with no date is no state.
        $r = self::tool('import_listings', '--channel=electrofag', "--file={$this->dir}/base.jsonl.gz", '--state-only', '--apply');
        self::assertSame([2, ''], [$r['code'], $r['out']], $r['err']);
        self::assertStringContainsString('--exported-at', $r['err']);
        self::assertSame($sums, self::checksums(), 'none of the refused lines wrote anything');

        // 3. With --apply: the state of 9001 and 9003, with the export's time, and nothing else.
        $audit = (int) self::$db->value('SELECT COALESCE(MAX(id), 0) FROM audit_log');
        $apply = self::tool('import_listings', '--channel=electrofag', "--file={$file}", '--state-only', '--apply', '--batch=2');
        self::assertSame(1, $apply['code'], $apply['err']);
        self::assertStringContainsString('STATE ONLY channel=electrofag file=electrofag_listings_20260926T094152Z.jsonl.gz sites=electrofag state_at=2026-09-26T09:41:52Z '
            . 'lines=7 received=5 written=2 (first=2 changed=0 same=0) differs=1 unknown=1 no_state=1 older=0 values_ignored=0 skipped=2 batches=3', $apply['out']);
        self::assertStringNotContainsString('DRY RUN', $apply['out']);
        $at = '2026-09-26 09:41:52.000000';
        self::assertSame(['9001' => ['Bin', 'Published', 'variable', '500', 0, 1, 'product-9001', $at], '9002' => $none,
            '9003' => ['Published', 'Draft', 'variable', '500', 0, 0, 'product-9003', $at], '9005' => $none], self::states());
        self::assertSame($before, self::allButTheState(), 'no listing made, no identity, map_version, features or mark moved, no pushed_at: only the state');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM channel_listing WHERE external_variant_id = '9004'"), 'an unknown variant is not created');
        self::assertSame($marks, self::$db->all('SELECT listing_id, arrival_state, arrival_seq FROM listing_profile ORDER BY listing_id'),
            'it queues nothing: no mark moved, also not the one of a variant the check has looked at, whose state is now known for the first time');
        // What it leaves behind besides: one audit row a batch, and no row of a listing push.
        $rows = self::$db->all('SELECT action, actor, entity_type, entity_id, CAST(detail AS CHAR) AS detail FROM audit_log WHERE id > ? ORDER BY id', [$audit]);
        self::assertSame(['listing.website_state', 'listing.website_state', 'listing.website_state'], array_column($rows, 'action'));
        self::assertSame([['system:import_listings', 'channel', (string) $site->channelId]], array_values(array_unique(array_map(static fn (array $a): array => [$a['actor'], $a['entity_type'], $a['entity_id']], $rows), SORT_REGULAR)));
        self::assertSame([2, 1, 1, 1], [array_sum(array_map(static fn (array $a): int => json_decode((string) $a['detail'], true)['written'], $rows)),
            array_sum(array_map(static fn (array $a): int => json_decode((string) $a['detail'], true)['differs'], $rows)),
            array_sum(array_map(static fn (array $a): int => json_decode((string) $a['detail'], true)['unknown'], $rows)),
            array_sum(array_map(static fn (array $a): int => json_decode((string) $a['detail'], true)['no_state'], $rows))]);
        self::assertSame('2026-09-26 09:41:52.000000', json_decode((string) $rows[0]['detail'], true)['state_at']);

        // 4. Again: the same states, counted as the same; nothing but the audit rows of the run is new.
        $again = self::tool('import_listings', '--channel=electrofag', "--file={$file}", '--state-only', '--apply');
        self::assertStringContainsString('written=2 (first=0 changed=0 same=2) differs=1 unknown=1 no_state=1 older=0', $again['out']);
        self::assertSame($before, self::allButTheState());
        // --exported-at instead of the file's own time; a later state that says something else is "changed".
        file_put_contents($file, gzencode(implode("\n", [self::exportLine(9001, ['variant_status' => 'Published', 'is_default' => 1]), self::exportLine(9003, ['barcodes' => ['85104 - 1', 5060505372588], 'product_status' => 'Draft'])]) . "\n"));
        $later = self::tool('import_listings', '--channel=electrofag', "--file={$file}", '--state-only', '--apply', '--exported-at=2026-10-01T06:00:00Z');
        self::assertSame(0, $later['code'], $later['err']);
        self::assertStringContainsString('state_at=2026-10-01T06:00:00Z lines=2 received=2 written=2 (first=0 changed=1 same=1) differs=0 unknown=0 no_state=0 older=0', $later['out']);
        self::assertSame([['Published', '2026-10-01 06:00:00.000000'], ['Published', '2026-10-01 06:00:00.000000']], [[self::states()['9001'][0], self::states()['9001'][7]],
            [self::states()['9003'][0], self::states()['9003'][7]]]);
        // The file's own (older) time now: the stored state is newer and stays.
        $old = self::tool('import_listings', '--channel=electrofag', "--file={$file}", '--state-only', '--apply');
        self::assertSame(1, $old['code']);
        self::assertStringContainsString('written=0 (first=0 changed=0 same=0) differs=0 unknown=0 no_state=0 older=2', $old['out']);
        self::assertSame('2026-10-01 06:00:00.000000', self::states()['9001'][7]);
        self::assertSame($before, self::allButTheState(), 'after every run of it: only the state ever moved');

        // The ordinary import of a file with a time in its name writes the state too (it is the listing intake), and creates 9004.
        file_put_contents($file, gzencode(implode("\n", [self::exportLine(9004), self::exportLine(9005)]) . "\n"));
        $full = self::tool('import_listings', '--channel=electrofag', "--file={$file}");
        self::assertSame(0, $full['code'], $full['err']);
        self::assertStringContainsString('received=2 created=1 updated=0 unchanged=1', $full['out']);
        self::assertStringContainsString('state_at=2026-09-26T09:41:52Z state_written=2 state_values_ignored=0 state_older=0', $full['out']);
        self::assertSame(['Published', 'Published', 'variable', '500', 0, 0, 'product-9004', $at], self::states()['9004']);
        self::assertSame([null, 0], array_slice(array_values((array) self::$db->one('SELECT arrival_state, arrival_seq FROM listing_profile WHERE listing_id = ?', [$id('9005')])), 0, 2),
            'a first state does not queue a variant that was there before the check');
    }

    public function testCreateStaffPrintsTheSecretsOnceAndStoresOnlyTheirProtectedForms(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw-staff-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $env = "{$this->dir}/app.env";
        file_put_contents($env, "# test app.env\nother_key=kept\n");
        chmod($env, 0640);

        $r = self::tool('create_staff', '--email=Ann@Example.test', '--role=mapping_lead', '--name=Ann Lead', ['CW_APP_ENV' => $env]);
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(1, preg_match('/^password=([A-Za-z0-9]{20})\notpauth=(otpauth:\/\/totp\/\S+)\n$/', $r['out'], $m), $r['out']);
        [, $password, $uri] = $m;
        self::assertStringContainsString('shown ONLY now', $r['err']);
        self::assertStringContainsString('added a new ui_secret_key', $r['err']);
        $file = Config::parseEnvFile($env);
        self::assertSame('kept', $file['other_key']);
        $key = $file['ui_secret_key'] ?? '';
        self::assertSame(32, strlen((string) base64_decode($key, true)));
        self::assertStringNotContainsString($key, $r['out'] . $r['err'], 'the key is never printed');
        self::assertSame(0640, fileperms($env) & 0777);

        $u = self::$db->one("SELECT * FROM staff_user WHERE email = 'ann@example.test'");
        self::assertSame(['ann@example.test', 'Ann Lead', 1, 1], [$u['username'], $u['display_name'], $u['password_must_change'], $u['is_active']]);
        self::assertSame(['mapping_lead'], array_map('strval', self::$db->column('SELECT role FROM staff_role WHERE staff_user_id = ? AND revoked_at IS NULL', [$u['id']])));
        self::assertStringContainsString('roles mapping_lead)', $r['err']);
        self::assertStringStartsWith('$argon2id$', (string) $u['password_hash']);
        self::assertTrue(password_verify($password, (string) $u['password_hash']));
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
        self::assertSame(['CW Warehouse', 'SHA1', '6', '30'], [$q['issuer'], $q['algorithm'], $q['digits'], $q['period']]);
        self::assertStringContainsString('ann%40example.test', $uri);
        $secret = SecretBox::fromBase64($key)->decrypt((string) $u['totp_secret_enc']);
        self::assertSame($q['secret'], $secret);
        self::assertNotNull(Totp::verify($secret, Totp::code($secret)));
        self::assertStringNotContainsString($secret, (string) $u['totp_secret_enc']);
        $audit = (string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'staff.create'");
        self::assertEquals(['email' => 'ann@example.test', 'roles' => ['mapping_lead']], json_decode($audit, true), 'no secret in the audit');

        // The same address again, or a bad role: refused, nothing printed; the key stays.
        $again = self::tool('create_staff', '--email=ann@example.test', '--role=mapper', ['CW_APP_ENV' => $env]);
        self::assertSame([1, ''], [$again['code'], $again['out']]);
        self::assertStringContainsString('already exists', $again['err']);
        self::assertSame(1, self::tool('create_staff', '--email=bob@example.test', '--role=boss', ['CW_APP_ENV' => $env])['code']);
        self::assertSame($key, Config::parseEnvFile($env)['ui_secret_key']);
        self::assertSame(2, self::tool('create_staff', '--email=bob@example.test', ['CW_APP_ENV' => $env])['code']);
    }

    // ---- HU9 (hide-unsold, 26 Oct 2026; the owner's answer "b") ----------------------------------------------------------------------

    /**
     * The import tool records a NEW store's first load (`channel.first_load_at`): the moment its first run that made website products ended
     * (the last product it made). A later run never moves it: what it adds is new (shown until a year old even if nothing sells), and a store
     * whose first load is recorded keeps it.
     */
    public function testTheFirstImportOfANewStoreRecordsItsFirstLoad(): void
    {
        $this->dir = sys_get_temp_dir() . '/cw-firstload-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $write = function (string $name, array $ids): string {
            $file = "{$this->dir}/{$name}.jsonl.gz";
            file_put_contents($file, gzencode(implode("\n", array_map(static fn (int $id): string => self::exportLine($id, ['units_365d' => 0]), $ids)) . "\n"));
            return $file;
        };
        $site = $this->site('newstore', 'shadow');
        $first = static fn (): ?string => ($v = self::$db->value('SELECT first_load_at FROM channel WHERE id = ?', [$site->channelId])) === null ? null : (string) $v;
        self::assertNull($first(), 'a new store: no first load yet');
        $r = self::tool('import_listings', '--channel=newstore', '--file=' . $write('one', [7101, 7102, 7103]));
        self::assertSame(0, $r['code'], $r['err']);
        $loaded = (string) self::$db->value('SELECT MAX(created_at) FROM channel_listing WHERE channel_id = ?', [$site->channelId]);
        self::assertSame($loaded, $first(), 'the end of its first load: the last product it made');
        self::assertStringContainsString('first_load=', $r['out']);
        $r = self::tool('import_listings', '--channel=newstore', '--file=' . $write('two', [7101, 7104]));
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame($loaded, $first(), 'a later run does not move it');
        self::assertStringNotContainsString('first_load=', $r['out']);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM channel_listing WHERE channel_id = ? AND created_at > ?', [$site->channelId, $loaded]),
            'what the later run made is new');
        // A dry state-only run, or a run that makes nothing, records nothing for a store that has none.
        $other = $this->site('emptystore', 'shadow');
        self::tool('import_listings', '--channel=emptystore', '--file=' . $write('three', [7201]), '--state-only', '--exported-at=2026-10-20T10:00:00Z');
        self::assertNull(self::$db->value('SELECT first_load_at FROM channel WHERE id = ?', [$other->channelId]));
    }

    // ---- end of HU9

    /** Copies the fixtures to a temp dir and gzips the exports (the tools read .jsonl.gz). */
    private function fixtures(): string
    {
        $this->dir = sys_get_temp_dir() . '/cw-fixtures-' . bin2hex(random_bytes(4));
        exec('cp -r ' . escapeshellarg(self::FIXTURES) . ' ' . escapeshellarg($this->dir), $o, $code);
        self::assertSame(0, $code);
        foreach (['vpg_export', 'alt_export'] as $f) {
            file_put_contents("{$this->dir}/{$f}.jsonl.gz", gzencode((string) file_get_contents("{$this->dir}/{$f}.jsonl")));
        }
        return $this->dir;
    }

    /** @return array{code: int, out: string, err: string} */
    private static function tool(string $name, string|array ...$args): array
    {
        $env = null;
        if ($args !== [] && is_array(end($args))) {
            $env = array_pop($args) + getenv();
        }
        $root = dirname(__DIR__, 3);
        $cmd = [PHP_BINARY, "{$root}/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /** @param list<int|string> $keys @return list<string> */
    private static function sorted(array $keys): array
    {
        $keys = array_map('strval', $keys);
        sort($keys, SORT_STRING);
        return $keys;
    }
}
