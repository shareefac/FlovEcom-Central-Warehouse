<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Matching\DuplicateSweep;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;
use CW\Ui\Duplicates;

/**
 * bin/import_vpg_duplicates.php (docs/decisions.md M37), as a separate process against the test schema: the sweep's groups
 * become merge suggestions for the Duplicates screen (lane vpg_duplicate, band Manual, one per member other than the keeper,
 * proposing the keeper's item, the sweep's evidence), a dry run writes nothing, a re-run changes nothing, and every member is
 * checked against the database as it is now: stale, protected, quarantined, waiting for a second person, an open proposal of
 * another run, and a pair a person kept separate are left out (and out of the evidence); nothing is ever merged.
 */
final class ImportVpgDuplicatesTest extends MappingTestCase
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

    /** @return array{code: int, out: string, err: string} */
    private static function tool(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/import_vpg_duplicates.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /**
     * A sweep group line (tools/vpg_duplicates/sweep.php's shape) of these members [variant, item], the first the keeper.
     *
     * @param list<array{0: string, 1: int}> $members
     * @return array<string, mixed>
     */
    private static function group(int $n, array $members): array
    {
        $m = array_map(static fn (array $x): array => ['vpg_variant_id' => $x[0], 'listing_id' => 0, 'sku_id' => $x[1], 'code' => sprintf('CW-%06d', $x[1]),
            'title' => "page {$x[0]}", 'status' => 'Published', 'units_30d' => 1, 'units_365d' => 10, 'barcodes' => 0, 'price' => '9.99', 'counted' => false, 'u_not_1' => false], $members);
        $pairs = [];
        foreach ($m as $i => $a) {
            foreach (array_slice($m, $i + 1) as $b) {
                $pairs[] = ['a' => $a['vpg_variant_id'], 'b' => $b['vpg_variant_id'], 'score' => 61, 'agree' => ['form', 'resistance', 'pack'],
                    'unknown' => ['strength'], 'n_a' => ['nic_type'], 'barcode' => 'one_side', 'price_ratio' => 0.909, 'name' => 1.0, 'flags' => [], 'same_product_page' => false];
            }
        }
        $ids = array_column($members, 1);
        sort($ids);
        return ['group' => $n, 'kind' => 'sweep', 'key' => DuplicateSweep::VERSION . ':' . implode('-', $ids), 'score' => 61, 'units_365d' => 10 * count($m),
            'keeper' => $m[0], 'members' => $m, 'pairs' => $pairs, 'no_barcode' => count($m), 'counted' => [], 'units_per_item_not_1' => []];
    }

    /** @param list<array<string, mixed>> $groups */
    private function file(array $groups, string $name = 'groups.jsonl'): string
    {
        if ($this->dir === '') {
            $this->dir = sys_get_temp_dir() . '/cw-dups-' . bin2hex(random_bytes(4));
            mkdir($this->dir, 0700);
        }
        $f = "{$this->dir}/{$name}";
        file_put_contents($f, implode('', array_map(static fn (array $g): string => json_encode($g, JSON_THROW_ON_ERROR) . "\n", $groups)));
        return $f;
    }

    public function testTheSweepsGroupsBecomeMergeSuggestionsAndOnlyWhatStillHoldsIsWritten(): void
    {
        $site = $this->site('vapeandgo', 'off');
        $item = [];
        $listing = [];
        foreach (['41615', '44202', '44206', '50001', '50002', '50003', '50004', '50005', '50006', '50007', '50008', '50009', '50010', '50011', '50012'] as $v) {
            $item[$v] = $this->item('legacy', 0, "page {$v}");
            $listing[$v] = $this->listing($site, $v, $item[$v]);
        }
        $alt = $this->site('electrofag', 'off');
        $lead = $this->staffUser('mapping_lead');
        $mapper = $this->staffUser('mapper');
        // 50004 is protected; 50005's item has a quarantined listing on another site; 50006 waits for a second person;
        // 50007 has an open proposal of another run; 50008 was kept separate from 44202's item; 50009 moved to another item.
        self::$db->exec("UPDATE sku SET sell_policy = 'strict' WHERE id = ?", [$item['50004']]);
        $this->listing($alt, 'E5', $item['50005'], 1, 'quarantined');
        $pend = $this->decide($mapper, 'merge_skus', $listing['50006'], ['sku_id' => $item['50012'], 'merge_from_sku_id' => $item['50006']]);
        self::assertSame('pending_second', $pend['state']);
        $other = $this->propose($listing['50007'], 'Manual', $item['50011'], ['lane' => 'vpg_duplicate', 'evidence' => ['group' => 9]], false, 'run2-vpg-duplicates');
        $this->decide($lead, 'reject', $listing['50008'], ['sku_id' => $item['44202']]);
        $this->relink($listing['50009'], $item['50010']);
        $rejects = (int) self::$db->value('SELECT COUNT(*) FROM match_reject');

        $file = $this->file([
            self::group(1, [['44202', $item['44202']], ['41615', $item['41615']], ['44206', $item['44206']], ['50004', $item['50004']], ['50005', $item['50005']],
                ['50006', $item['50006']]]),
            self::group(2, [['44202', $item['44202']], ['50007', $item['50007']], ['50008', $item['50008']], ['50009', $item['50009']]]),
            self::group(3, [['50004', $item['50004']], ['50001', $item['50001']]]),                 // a protected keeper: the group is skipped
            self::group(4, [['50002', $item['50002']], ['50003', $item['50003']]]),
        ]);
        file_put_contents($file, "{\"group\": 5, \"kind\": \"identity_key\"}\n", FILE_APPEND);   // not a sweep line
        $run = 'sweep-' . DuplicateSweep::VERSION . '-' . substr((string) hash_file('sha256', $file), 0, 12);

        // 1. The dry run: what would be written, nothing written.
        $before = [(int) self::$db->value('SELECT COUNT(*) FROM match_proposal'), (int) self::$db->value('SELECT COUNT(*) FROM match_run'),
            (int) self::$db->value('SELECT COUNT(*) FROM audit_log')];
        $r = self::tool("--file={$file}");
        self::assertSame(2, $r['code'], 'usage: --groups');
        $r = self::tool("--groups={$file}");
        self::assertSame(1, $r['code'], $r['err'] . $r['out']);
        self::assertStringContainsString('line 5: not a sweep group', $r['err']);
        self::assertStringContainsString("DRY RUN (nothing written) run={$run} channel=vapeandgo", $r['out']);
        self::assertStringContainsString('groups=4 written=2 skipped=2 proposals: created=0 would_create=3 exists=0; left out: stale=1 same_item=0 answered=1 '
            . 'protected=2 quarantined=1 pending=1 open_elsewhere=1 missing=0; failed=0 bad_lines=1', $r['out']);
        self::assertStringContainsString('group 3 (' . DuplicateSweep::VERSION . ':' . min($item['50004'], $item['50001']) . '-' . max($item['50004'], $item['50001']) . '): keep vpg 50004', $r['out']);
        self::assertStringContainsString('skipped: the keeper is protected', $r['out']);
        self::assertSame($before, [(int) self::$db->value('SELECT COUNT(*) FROM match_proposal'), (int) self::$db->value('SELECT COUNT(*) FROM match_run'),
            (int) self::$db->value('SELECT COUNT(*) FROM audit_log')]);

        // 2. Apply.
        $r = self::tool("--groups={$file}", '--apply');
        self::assertSame(1, $r['code'], 'the unreadable line is still a problem: ' . $r['err']);
        self::assertStringNotContainsString('DRY RUN', $r['out']);
        self::assertStringContainsString('proposals: created=3 would_create=0 exists=0; left out: stale=1 same_item=0 answered=1 protected=2 quarantined=1 pending=1 open_elsewhere=1', $r['out']);
        $runRow = self::$db->one('SELECT * FROM match_run WHERE run_id = ?', [$run]);
        self::assertSame(['vpg_dup_sweep', DuplicateSweep::VERSION], [$runRow['source'], $runRow['engine_version']]);
        self::assertEquals(['file' => 'groups.jsonl', 'sha256' => hash_file('sha256', $file), 'groups' => 4], json_decode((string) $runRow['detail'], true));
        $props = self::$db->all('SELECT p.*, l.external_variant_id AS v FROM match_proposal p JOIN channel_listing l ON l.id = p.listing_id WHERE p.match_run_id = ? ORDER BY l.external_variant_id',
            [(int) $runRow['id']]);
        self::assertSame(['41615', '44206', '50003'], array_column($props, 'v'));
        foreach ($props as $p) {
            self::assertSame(['Manual', 'vpg_duplicate', 'open', 0], [$p['band'], $p['lane'], $p['status'], (int) $p['proposed_new_item']]);
            self::assertSame(['merge_suggestion', 'sweep'], json_decode((string) $p['flags'], true));
        }
        self::assertSame([$item['44202'], $item['44202'], $item['50002']], array_map('intval', array_column($props, 'proposed_sku_id')));
        $ev = json_decode((string) $props[0]['evidence'], true);
        self::assertSame([1, 'sweep', '44202', $item['44202']], [$ev['group'], $ev['kind'], $ev['keeper']['vpg_variant_id'], $ev['keeper']['sku_id']]);
        self::assertSame(['44202', '41615', '44206'], array_column($ev['members'], 'vpg_variant_id'), 'the evidence names only the members that were written');
        self::assertCount(3, $ev['sweep']['pairs'], 'the pairs among them');
        self::assertSame([DuplicateSweep::VERSION, 61, hash_file('sha256', $file)], [$ev['sweep']['engine'], $ev['sweep']['score'], $ev['sweep']['file_sha256']]);
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.propose' AND actor = 'system:import_vpg_duplicates'"));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.import_duplicates'"), true);
        self::assertSame([$run, 3, 1], [$audit['run_id'], $audit['created'], $audit['open_elsewhere']]);
        // Nothing merged, nothing relinked, the other run's suggestion untouched, the reject still there.
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE action = 'merge_skus' AND state = 'applied'"));
        self::assertSame($item['41615'], $this->link($listing['41615'])['sku_id']);
        self::assertSame('open', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$other]));
        self::assertSame($rejects, (int) self::$db->value('SELECT COUNT(*) FROM match_reject'));

        // 3. The Duplicates screen sees the groups, with the sweep's reasons.
        $dup = new Duplicates(self::$db);
        $g = $dup->group((int) $props[0]['id']);
        self::assertNotNull($g);
        self::assertSame(['sweep', '1', 2, 0], [$g['kind'], $g['group'], $g['open'], $g['decided']]);
        self::assertEqualsCanonicalizing([$listing['44202'], $listing['41615'], $listing['44206']], $g['listings']);
        self::assertSame([DuplicateSweep::VERSION, 61], [$g['sweep']['engine'], $g['sweep']['score']]);
        self::assertSame(['form', 'ohm', 'pack'], $g['sweep']['pairs'][0]['agree']);
        self::assertSame('a barcode on one page only', $g['sweep']['pairs'][0]['barcode']);
        self::assertContains((int) $props[0]['id'], array_column(array_filter($dup->openGroups(), static fn (array $x): bool => $x['kind'] === 'sweep'), 'id'));

        // 4. A re-run changes nothing.
        $r = self::tool("--groups={$file}", '--apply');
        self::assertStringContainsString('proposals: created=0 would_create=0 exists=3', $r['out']);
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE match_run_id = ?', [(int) $runRow['id']]));
        $r = self::tool("--groups={$file}");
        self::assertStringContainsString('proposals: created=0 would_create=0 exists=3', $r['out'], 'the dry run knows the run');

        // 5. A person keeps 50003 separate; a later sweep file suggesting the pair again is answered, not written.
        $this->decide($lead, 'reject', $listing['50003'], ['sku_id' => $item['50002'], 'proposal_id' => (int) $props[2]['id']]);
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [(int) $props[2]['id']]));
        $again = $this->file([self::group(1, [['50002', $item['50002']], ['50003', $item['50003']]])], 'again.jsonl');
        $r = self::tool("--groups={$again}", '--apply');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('groups=1 written=0 skipped=1 proposals: created=0 would_create=0 exists=0; left out: stale=0 same_item=0 answered=1', $r['out']);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM match_run WHERE run_id LIKE 'sweep-%' AND id <> ?", [(int) $runRow['id']]),
            'no run is recorded when nothing is written');
    }

    /**
     * M41 (review of 7 Oct 2026): a member with an open suggestion of another run is left out BEFORE the evidence is written, so
     * the group never names it, and deciding the group (the screen's one decideGroup) leaves that other suggestion open.
     */
    public function testAMemberWithAnotherRunsOpenSuggestionIsLeftOutOfTheEvidence(): void
    {
        $site = $this->site('vapeandgo', 'off');
        $item = [];
        $listing = [];
        foreach (['60001', '60002', '60003', '60004'] as $v) {
            $item[$v] = $this->item('legacy', 2, "page {$v}");
            $listing[$v] = $this->listing($site, $v, $item[$v]);
        }
        $run2 = $this->propose($listing['60003'], 'Manual', $item['60004'], ['lane' => 'vpg_duplicate', 'evidence' => ['group' => 3,
            'keeper' => ['vpg_variant_id' => '60004'], 'members' => [['vpg_variant_id' => '60004'], ['vpg_variant_id' => '60003']]]], false, 'run2-vpg-duplicates');
        $file = $this->file([self::group(1, [['60001', $item['60001']], ['60002', $item['60002']], ['60003', $item['60003']]])]);
        $r = self::tool("--groups={$file}");
        self::assertStringContainsString('would_create=1 exists=0; left out: stale=0 same_item=0 answered=0 protected=0 quarantined=0 pending=0 open_elsewhere=1', $r['out']);
        $r = self::tool("--groups={$file}", '--apply');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('created=1 would_create=0 exists=0; left out: stale=0 same_item=0 answered=0 protected=0 quarantined=0 pending=0 open_elsewhere=1', $r['out']);
        $p = self::$db->one("SELECT id, evidence FROM match_proposal WHERE listing_id = ? AND status = 'open'", [$listing['60002']]);
        $ev = json_decode((string) $p['evidence'], true);
        self::assertSame(['60001', '60002'], array_column($ev['members'], 'vpg_variant_id'), 'the evidence never names 60003');
        self::assertSame(['60001', '60002'], array_values(array_unique(array_merge(...array_map(static fn (array $x): array => [$x['a'], $x['b']], $ev['sweep']['pairs'])))));
        $g = (new Duplicates(self::$db))->group((int) $p['id']);
        self::assertEqualsCanonicalizing([$listing['60001'], $listing['60002']], $g['listings']);

        // Merging the group (as the screen does: this group's suggestions only) leaves run2's suggestion of 60003 open.
        $lead = $this->staffUser('mapping_lead');
        $this->ds->decideGroup($lead, $g['listings'], [['action' => 'merge_skus', 'listing_id' => $listing['60002'], 'expected_map_version' => $this->version($listing['60002']),
            'sku_id' => $item['60001'], 'merge_from_sku_id' => $item['60002'], 'proposal_id' => (int) $p['id']]], 'g', [], array_column($g['proposals'], 'id'));
        self::assertSame(['decided', 'open'], [self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [(int) $p['id']]),
            self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$run2])]);
        self::assertSame($item['60003'], $this->link($listing['60003'])['sku_id']);
    }

    /** The whole chain on the test schema: export (read only) -> sweep (no database) -> import; the owner's Corex pair is found. */
    public function testExportSweepAndImportEndToEnd(): void
    {
        $site = $this->site('vapeandgo', 'off');
        $profiles = [
            '41615' => ['Vaporesso Xros Corex 3.0 Pods (Pack of 4)', 'Vaporesso Xros Corex 3.0 Pods (Pack of 4) - 0.4 ohm', '9.99', ['6943498686179'], 177, 8501,
                [['name' => 'Resistance', 'value' => '0.4 ohm', 'attr_id' => 8, 'is_variable' => 1], ['name' => 'Pack Size', 'value' => '4 Pack', 'attr_id' => 19, 'is_variable' => 0]]],
            '44202' => ['Vaporesso Xros Corex Replacement Pods', 'Vaporesso Xros Corex Replacement Pods - 0.4ohm Corex 3.0 Pod - 4 Pack', '10.99', [], 257, 2077,
                [['name' => 'Type', 'value' => '0.4ohm Corex 3.0 Pod - 4 Pack', 'attr_id' => 18, 'is_variable' => 1]]],
            '44203' => ['Vaporesso Xros Corex Replacement Pods', 'Vaporesso Xros Corex Replacement Pods - 0.6ohm Corex 3.0 Pod - 4 Pack', '10.99', [], 90, 2077,
                [['name' => 'Type', 'value' => '0.6ohm Corex 3.0 Pod - 4 Pack', 'attr_id' => 18, 'is_variable' => 1]]],
        ];
        $item = [];
        foreach ($profiles as $v => [$pt, $vt, $price, $codes, $u365, $pid, $attrs]) {
            $item[$v] = $this->item('legacy', 0, $vt);
            $id = $this->listing($site, (string) $v, $item[$v]);
            self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, attributes, barcodes, price, units_30d, units_365d, features) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, $pt, $vt, 'Vaporesso Vape Kits & Accessories', json_encode(['items' => $attrs], JSON_THROW_ON_ERROR),
                    json_encode($codes, JSON_THROW_ON_ERROR), $price, 10, $u365, json_encode(['product_id' => $pid, 'variant_status' => 'Published'], JSON_THROW_ON_ERROR)]);
        }
        $this->dir = sys_get_temp_dir() . '/cw-dups-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $root = dirname(__DIR__, 3);
        $sh = static function (array $cmd, ?string $stdout = null) use ($root): array {
            $p = proc_open($cmd, [1 => $stdout === null ? ['pipe', 'w'] : ['file', $stdout, 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            self::assertIsResource($p);
            $out = $stdout === null ? (string) stream_get_contents($pipes[1]) : '';
            $err = (string) stream_get_contents($pipes[2]);
            if ($stdout === null) {
                fclose($pipes[1]);
            }
            fclose($pipes[2]);
            return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
        };
        $before = (int) self::$db->value('SELECT COUNT(*) FROM audit_log');
        $e = $sh([PHP_BINARY, "{$root}/tools/vpg_duplicates/export.php", '--db=' . TestDb::name(), '--admin', '--channel=vapeandgo'], "{$this->dir}/export.jsonl");
        self::assertSame(0, $e['code'], $e['err']);
        self::assertStringContainsString('"listings":3', $e['err']);
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM audit_log'), 'the export writes nothing');
        $s = $sh([PHP_BINARY, "{$root}/tools/vpg_duplicates/sweep.php", "--export={$this->dir}/export.jsonl", "--out={$this->dir}/out", '--top=10']);
        self::assertSame(0, $s['code'], $s['err']);
        self::assertStringContainsString('groups=1 proposals=1', $s['out']);
        $g = json_decode((string) file_get_contents("{$this->dir}/out/groups.jsonl"), true);
        self::assertSame(['44202', '41615'], array_column($g['members'], 'vpg_variant_id'), 'the 0.4 ohm pair; 0.6 ohm is another product; the keeper sold most');
        $i = self::tool("--groups={$this->dir}/out/groups.jsonl", '--apply');
        self::assertSame(0, $i['code'], $i['err']);
        self::assertStringContainsString('created=1', $i['out']);
        self::assertSame($item['44202'], (int) self::$db->value("SELECT p.proposed_sku_id FROM match_proposal p JOIN channel_listing l ON l.id = p.listing_id "
            . "WHERE l.external_variant_id = '41615' AND p.status = 'open' AND p.lane = 'vpg_duplicate'"));
        foreach (glob("{$this->dir}/out/*") ?: [] as $f) {
            unlink($f);
        }
        rmdir("{$this->dir}/out");
    }

    public function testAMergeOnTheScreenSettlesTheSuggestionAndALaterFileFindsTheSameItem(): void
    {
        $site = $this->site('vapeandgo', 'off');
        $k = $this->item('legacy', 0, 'keeper');
        $f = $this->item('legacy', 0, 'other');
        $a = $this->listing($site, '61001', $k);
        $b = $this->listing($site, '61002', $f);
        $file = $this->file([self::group(1, [['61001', $k], ['61002', $f]])]);
        $r = self::tool("--groups={$file}", '--apply');
        self::assertSame(0, $r['code'], $r['err']);
        $pid = (int) self::$db->value('SELECT id FROM match_proposal WHERE listing_id = ?', [$b]);
        $lead = $this->staffUser('mapping_lead');
        $m = $this->decide($lead, 'merge_skus', $b, ['sku_id' => $k, 'merge_from_sku_id' => $f, 'proposal_id' => $pid]);
        self::assertSame('applied', $m['state'], 'two uncounted legacy items: one mapping lead (M31)');
        self::assertSame('decided', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$pid]));
        // The same file again: 61002 is on the keeper's item now (merges followed): same_item, nothing written.
        $r = self::tool("--groups={$file}", '--apply');
        self::assertStringContainsString('created=0 would_create=0 exists=0; left out: stale=0 same_item=1', $r['out']);
        self::assertSame($k, $this->link($a)['sku_id']);
        self::assertSame(Caller::system('import_vpg_duplicates')->actor, (string) self::$db->value("SELECT actor FROM audit_log WHERE action = 'mapping.propose' ORDER BY id DESC LIMIT 1"));
    }
}
