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
