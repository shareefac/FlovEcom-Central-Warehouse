<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\KeySample;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;

/**
 * The owner's runbook (docs/ops.md "Key spot-check and bulk confirm") end to end, each tool as its own process on the
 * test schema: bin/reband_proposals.php (attributed with --by), bin/sample_proposals.php (a dry run that draws nothing, a
 * server seed on --apply, --verify), the owner confirming the 20 on the review screen (here: DecisionService),
 * bin/bulk_confirm_key.php (only by the sample's owner; --report) and its undo bin/bulk_unlink.php; dry runs first,
 * re-runs, refusals and exit codes. And the hold of screened proposals before the bulk runs (bin/key_bulk_hold.php, M30):
 * from the --report file itself, a re-run without any file, the release.
 */
final class KeyBulkToolsTest extends MappingTestCase
{
    use KeyFixtures;

    public function testTheRunbookEndToEnd(): void
    {
        $this->keySetup();
        $owner = $this->staffUser(['mapping_lead', 'reviewer']);
        $email = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$owner->staffUserId]);
        $mapperEmail = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$this->staffUser('mapper')->staffUserId]);
        $otherLeadEmail = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$this->staffUser('mapping_lead')->staffUserId]);
        for ($i = 0; $i < 16; $i++) {
            $this->firstMatch($this->vpgItem(), 90 + $i % 10);
        }
        for ($i = 0; $i < 6; $i++) {
            $this->firstMatch($this->vpgItem(), 86 + $i % 3);
        }
        $this->firstMatch($this->vpgItem(), 88, ['target_soft_flags' => ['price_outlier']]);

        // 1. Re-band (M26): dry run, then for real as the owner; a re-run moves nothing.
        $r = self::tool('reband_proposals');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('DRY RUN (nothing written) by=system run=reband-b2.1 band=b2.1 key_min=85 open=23 unchanged=17 skipped={} '
            . 'moves={"Check>Key":6} blocked={} applied={}', $r['out']);
        self::assertStringContainsString('move Check>Key: 6 (e.g. proposal ', $r['out']);
        $r = self::tool('reband_proposals', "--by={$email}", '--apply');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString("by={$email} run=reband-b2.1", $r['out']);
        self::assertStringContainsString('applied={"Check>Key":6} failed={}', $r['out']);
        self::assertSame($owner->actor, self::$db->value("SELECT actor FROM audit_log WHERE action = 'mapping.reband'"));
        self::assertStringContainsString('moves={} blocked={} applied={}', self::tool('reband_proposals', '--apply')['out']);
        self::assertSame(2, self::tool('reband_proposals', '--channel=nope')['code']);
        self::assertSame(2, self::tool('reband_proposals', "--by={$mapperEmail}")['code'], '--by must be a mapping lead');

        // 2. The sample (M28): the dry run shows the strata and draws nothing; --apply draws the seed and stores it.
        $r = self::tool('sample_proposals', '--name=t1', "--by={$email}");
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(0, preg_match_all('/^#\d+ proposal/m', $r['out']), 'no members before the sample is stored');
        self::assertStringContainsString('stratum conf_90_100 (confidence 90-100): 15 of 16', $r['out']);
        self::assertStringContainsString('stratum conf_85_89 (confidence 85-89): 5 of 6', $r['out']);
        self::assertStringContainsString("DRY RUN (nothing drawn, nothing written) name=t1 by={$email} size=20 population=22", $r['out']);
        self::assertStringNotContainsString('seed=', $r['out']);
        $seeded = self::tool('sample_proposals', '--name=t1', "--by={$email}", '--seed=5', '--apply');
        self::assertSame(2, $seeded['code']);
        self::assertStringContainsString('--seed is not taken', $seeded['err']);
        self::assertSame(2, self::tool('sample_proposals', '--name=t1', "--by={$email}", '--size=19')['code'], 'a sample is 20 or more');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM key_sample'));
        $r = self::tool('sample_proposals', '--name=t1', "--by={$email}", '--apply');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(20, preg_match_all('/^#\d+ proposal \d+ listing \d+ confidence \d+ stratum conf_\d+_\d+$/m', $r['out']));
        self::assertMatchesRegularExpression('/ seed=\d+ size=20 population=22 .* sample_id=\d+$/m', $r['out']);
        self::assertSame((string) self::$db->value("SELECT seed FROM key_sample WHERE name = 't1'"), preg_match('/ seed=(\d+) /', $r['out'], $m) === 1 ? $m[1] : null);
        $v = self::tool('sample_proposals', '--name=t1', '--verify');
        self::assertSame(0, $v['code'], $v['err']);
        self::assertStringContainsString('matches: the stored members are what the seed draws from the stored population', $v['out']);
        $again = self::tool('sample_proposals', '--name=t1', "--by={$email}", '--apply');
        self::assertSame(1, $again['code']);
        self::assertStringContainsString('sample_exists', $again['err']);
        $notLead = self::tool('sample_proposals', '--name=t2', "--by={$mapperEmail}");
        self::assertSame(2, $notLead['code']);
        self::assertStringContainsString('lead_required', $notLead['err']);
        self::assertSame(2, self::tool('sample_proposals', '--name=t3')['code'], '--by is required');
        self::assertSame(2, self::tool('sample_proposals', '--name=nope', '--verify')['code']);

        // 3. The bulk confirm refuses until the owner has confirmed every sample member, and only the owner runs it.
        $r = self::tool('bulk_confirm_key', '--sample=t1', "--lead={$email}");
        self::assertSame(1, $r['code']);
        self::assertMatchesRegularExpression('/sample t1: seed \d+, 0 of 20 decided, 0 confirmed by its owner, verdict waiting/', $r['out']);
        self::assertSame(20, preg_match_all('/^  #\d+ proposal \d+ listing \d+: open$/m', $r['out']));
        self::assertStringContainsString('refused (sample_incomplete)', $r['err']);
        foreach (self::$db->column('SELECT proposal_id FROM key_sample_member WHERE position IS NOT NULL ORDER BY position') as $pid) {
            $this->confirm($owner, (int) $pid);
        }
        $notOwner = self::tool('bulk_confirm_key', '--sample=t1', "--lead={$otherLeadEmail}");
        self::assertSame(2, $notOwner['code']);
        self::assertStringContainsString('not_sample_owner', $notOwner['err']);
        $csv = sys_get_temp_dir() . '/cw_key_bulk_' . bin2hex(random_bytes(6)) . '.csv';
        try {
            $r = self::tool('bulk_confirm_key', '--sample=t1', "--lead={$email}", "--report={$csv}");
            self::assertSame(0, $r['code'], $r['err']);
            self::assertStringContainsString('verdict complete', $r['out']);
            self::assertStringContainsString("DRY RUN (nothing written) sample=t1 batch=key_bulk:t1 lead={$email} population=2 eligible=2 excluded={}", $r['out']);
            self::assertStringContainsString("report: 2 proposals written to {$csv}", $r['out']);
            $rows = array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES) ?: []);
            self::assertSame(['outcome', 'first_reason', 'all_reasons', 'proposal_id', 'listing_id', 'channel', 'variant', 'title', 'item_id', 'item_code',
                'item_name', 'confidence', 'stratum', 'units_30d', 'units_365d', 'lane_target_listing_id', 'hold_reason', 'held_by', 'held_at'], $rows[0]);
            self::assertCount(3, $rows);
            foreach (array_slice($rows, 1) as $row) {
                self::assertSame(['eligible', '', 'alt', '', '', ''], [$row[0], $row[1], $row[5], $row[16], $row[17], $row[18]]);
                self::assertStringStartsWith('Electrofag listing ', $row[7]);
                self::assertSame((string) self::$db->value('SELECT code FROM sku WHERE id = ?', [(int) $row[8]]), $row[9]);
            }
            $exists = self::tool('bulk_confirm_key', '--sample=t1', "--lead={$email}", "--report={$csv}");
            self::assertSame(2, $exists['code'], 'a report is never overwritten');
        } finally {
            @unlink($csv);
        }
        $r = self::tool('bulk_confirm_key', '--sample=t1', "--lead={$email}", '--apply', '--limit=1');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('already_in_batch=0 applied=1 skipped={} failed={} limit=1', $r['out']);
        $r = self::tool('bulk_confirm_key', '--sample=t1', "--lead={$email}", '--apply');
        self::assertStringContainsString('eligible=1 excluded={"proposal_decided":1}', $r['out']);
        self::assertStringContainsString('already_in_batch=1 applied=1 skipped={} failed={}', $r['out']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM match_decision WHERE bulk_batch_id = 'key_bulk:t1' AND decided_by = ? AND state = 'applied'", [$owner->staffUserId]));
        self::assertSame(2, self::tool('bulk_confirm_key', '--sample=t1', "--lead={$mapperEmail}")['code']);
        self::assertSame(2, self::tool('bulk_confirm_key', '--sample=nope', "--lead={$email}")['code']);

        // 4. The undo.
        $r = self::tool('bulk_unlink', '--batch=key_bulk:t1', "--lead={$email}");
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString("DRY RUN (nothing written) batch=key_bulk:t1 undo_batch=undo:key_bulk:t1 lead={$email} decisions=2 linked=2", $r['out']);
        $r = self::tool('bulk_unlink', '--batch=key_bulk:t1', "--lead={$email}", '--apply');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('applied=2 pending=0 reopened=2 failed={}', $r['out']);
        self::assertStringContainsString('decisions=2 linked=0 waiting_second=0 reopen=0 undone=2', self::tool('bulk_unlink', '--batch=key_bulk:t1', "--lead={$email}")['out']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE r.run_id = 'undo:key_bulk:t1' AND p.status = 'open'"));
        $bad = self::tool('bulk_unlink', '--batch=vpg_mint:test', "--lead={$email}");
        self::assertSame(2, $bad['code']);
        self::assertStringContainsString('bad_batch', $bad['err']);
    }

    public function testTheScreenedProposalsAreHeldBeforeTheBulkConfirm(): void
    {
        $this->keySetup();
        $owner = $this->staffUser(['mapping_lead', 'reviewer']);
        $email = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$owner->staffUserId]);
        $mapperEmail = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$this->staffUser('mapper')->staffUserId]);
        for ($i = 0; $i < 25; $i++) {
            $this->firstMatch($this->vpgItem(), 90 + $i % 10);
        }
        $s = (new KeySample(self::$db))->create($owner, 'held', 20, true);
        foreach ($s['members'] as $m) {
            $this->confirm($owner, $m['proposal_id']);
        }
        $dir = sys_get_temp_dir() . '/cw_key_hold_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700));
        try {
            // 1. The report to screen; the screener copies it and adds a reason to the rows to hold back.
            $r = self::tool('bulk_confirm_key', '--sample=held', "--lead={$email}", "--report={$dir}/screen.csv");
            self::assertSame(0, $r['code'], $r['err']);
            self::assertStringContainsString('population=5 eligible=5 excluded={} held=0', $r['out']);
            $report = array_map('str_getcsv', file("{$dir}/screen.csv", FILE_IGNORE_NEW_LINES) ?: []);
            $held = [$report[1], $report[3]];
            $reasons = ['-2 x 10ml on the Electrofag page', 'Flavour: "mango ice" vs mango'];
            $fh = fopen("{$dir}/hold.csv", 'xb');
            self::assertIsResource($fh);
            fputcsv($fh, [...$report[0], 'reason'], ',', '"', '');
            foreach ($held as $i => $row) {
                fputcsv($fh, [...$row, $reasons[$i]], ',', '"', '');
            }
            fclose($fh);
            [$p1, $l1, $p2, $l2] = [(int) $held[0][3], (int) $held[0][4], (int) $held[1][3], (int) $held[1][4]];

            // 2. Hold them: a dry run, then --apply; a re-run writes nothing.
            $r = self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/hold.csv", "--by={$email}");
            self::assertSame(0, $r['code'], $r['err']);
            self::assertStringContainsString("sample held (drawn by {$email}, verdict complete): 2 rows in hold.csv (sha256 ", $r['out']);
            self::assertStringContainsString("  row 2 proposal {$p1} listing {$l1}: to hold: -2 x 10ml on the Electrofag page", $r['out']);
            self::assertStringContainsString("DRY RUN (nothing written) mode=hold sample=held by={$email} rows=2 hold=2 already=0 refused={} held_before=0 held_after=2", $r['out']);
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM key_bulk_hold'));
            $r = self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/hold.csv", "--by={$email}", '--apply');
            self::assertSame(0, $r['code'], $r['err']);
            self::assertStringContainsString("  row 3 proposal {$p2} listing {$l2}: held: Flavour: \"mango ice\" vs mango", $r['out']);
            self::assertStringContainsString("mode=hold sample=held by={$email} rows=2 hold=2 already=0 refused={} held_before=0 held_after=2", $r['out']);
            $r = self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/hold.csv", "--by={$email}", '--apply');
            self::assertSame(0, $r['code'], $r['err']);
            self::assertStringContainsString('hold=0 already=2 refused={} held_before=2 held_after=2', $r['out']);
            self::assertSame([2, 1], [(int) self::$db->value('SELECT COUNT(*) FROM key_bulk_hold'),
                (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.key_hold'")]);

            // Refusals: a row that names one of the 20 (the rest still go ahead), a file without the columns, no file, not a lead.
            file_put_contents("{$dir}/member.csv", "proposal_id,listing_id,reason\n{$s['members'][0]['proposal_id']},{$s['members'][0]['listing_id']},one of the 20\n");
            $r = self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/member.csv", "--by={$email}", '--apply');
            self::assertSame(1, $r['code']);
            self::assertStringContainsString('REFUSED sample_member', $r['out']);
            self::assertStringContainsString('1 rows refused (listed above, REFUSED): they are NOT held', $r['err']);
            file_put_contents("{$dir}/bad.csv", "proposal,listing,why\n1,2,x\n");
            $r = self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/bad.csv", "--by={$email}");
            self::assertSame(2, $r['code']);
            self::assertStringContainsString('bad_file', $r['err']);
            self::assertSame(2, self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/none.csv", "--by={$email}")['code']);
            self::assertSame(2, self::tool('key_bulk_hold', '--sample=nope', "--file={$dir}/hold.csv", "--by={$email}")['code']);
            $r = self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/hold.csv", "--by={$mapperEmail}", '--apply');
            self::assertSame(2, $r['code']);
            self::assertStringContainsString('lead_required', $r['err']);
            self::assertSame(2, self::tool('key_bulk_hold', '--sample=held', "--by={$email}")['code'], '--file is required');

            // 3. The bulk dry run says what is held and why (and the report carries it); then the bulk links the rest only.
            $r = self::tool('bulk_confirm_key', '--sample=held', "--lead={$email}", "--report={$dir}/dry.csv");
            self::assertSame(0, $r['code'], $r['err']);
            self::assertStringContainsString('population=5 eligible=3 excluded={"held_for_review":2} held=2', $r['out']);
            self::assertStringContainsString("  held: proposal {$p1} listing {$l1} (by {$email}, ", $r['out']);
            self::assertStringContainsString('): -2 x 10ml on the Electrofag page', $r['out']);
            $dry = [];
            foreach (array_slice(array_map('str_getcsv', file("{$dir}/dry.csv", FILE_IGNORE_NEW_LINES) ?: []), 1) as $row) {
                $dry[(int) $row[3]] = [$row[0], $row[1], $row[16], $row[17]];
            }
            self::assertSame(['excluded', 'held_for_review', "'-2 x 10ml on the Electrofag page", $email], $dry[$p1], 'a cell a spreadsheet would run is escaped');
            self::assertSame(['excluded', 'held_for_review', 'Flavour: "mango ice" vs mango', $email], $dry[$p2]);
            self::assertSame(3, count(array_filter($dry, static fn (array $d): bool => $d[0] === 'eligible' && $d[2] === '')));
            $r = self::tool('bulk_confirm_key', '--sample=held', "--lead={$email}", '--apply');
            self::assertSame(0, $r['code'], $r['err']);
            self::assertStringContainsString('applied=3 skipped={} failed={}', $r['out']);
            self::assertSame(['suggested', 'suggested'], [$this->link($l1)['status'], $this->link($l2)['status']]);

            // 4. A lead releases one (and only a lead); the next run links it.
            file_put_contents("{$dir}/release.csv", "proposal_id,listing_id,reason\n{$p2},{$l2},Checked on the page: same flavour\n");
            self::assertSame(2, self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/release.csv", "--by={$mapperEmail}", '--release', '--apply')['code']);
            $r = self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/release.csv", "--by={$email}", '--release', '--apply');
            self::assertSame(0, $r['code'], $r['err']);
            self::assertStringContainsString("  row 2 proposal {$p2} listing {$l2}: released: Checked on the page: same flavour (held for: Flavour: \"mango ice\" vs mango)", $r['out']);
            self::assertStringContainsString("mode=release sample=held by={$email} rows=1 release=1 already=0 refused={} held_before=2 held_after=1", $r['out']);
            self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.key_hold_release'"));
            $r = self::tool('bulk_confirm_key', '--sample=held', "--lead={$email}", '--apply');
            self::assertStringContainsString('excluded={"held_for_review":1,"proposal_decided":3} held=1', $r['out']);
            self::assertStringContainsString('applied=1 skipped={} failed={}', $r['out']);
            self::assertSame(['suggested', 'mapped'], [$this->link($l1)['status'], $this->link($l2)['status']]);

            // 5. A new matching run replaces the held proposal: the listing stays held (the hold is on the listing), and a
            //    release must name the proposal the hold was written for (the tool says which).
            $run4 = $this->proposals->run('run4-sold', 'first_match', null, 'n2.1/c1.0/v2.1/b2.1', null, ['band_version' => 'b2.1']);
            $old = self::proposalRow($p1);
            $newer = $this->proposals->add(Caller::system('import_proposals'), $l1, $run4, ['band' => 'Key', 'proposed_sku_id' => (int) $old['proposed_sku_id'],
                'lane' => 'barcode', 'ai_outcome' => 'match', 'ai_confidence' => (int) $old['ai_confidence'], 'ai_units_per_item' => 1,
                'evidence' => json_decode((string) $old['evidence'], true)])['proposal_id'];
            $r = self::tool('bulk_confirm_key', '--sample=held', "--lead={$email}");
            self::assertStringContainsString('excluded={"proposal_decided":4,"proposal_superseded":1} held=1', $r['out']);
            self::assertStringContainsString("  held: proposal {$p1} listing {$l1} (by {$email}, ", $r['out']);
            file_put_contents("{$dir}/release2.csv", "proposal_id,listing_id,reason
{$newer},{$l1},the newer one
");
            $r = self::tool('key_bulk_hold', '--sample=held', "--file={$dir}/release2.csv", "--by={$email}", '--release');
            self::assertSame(1, $r['code'], $r['err']);
            self::assertStringContainsString("  row 2 proposal {$newer} listing {$l1}: REFUSED not_in_population (held for: -2 x 10ml on the Electrofag page "
                . "(the listing is held under proposal {$p1} of the sample held))", $r['out']);
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /** @return array{code: int, out: string, err: string} */
    private static function tool(string $name, string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $cmd = [PHP_BINARY, "{$root}/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
