<?php

declare(strict_types=1);

/**
 * Imports the groups of the wider duplicate sweep (tools/vpg_duplicates/sweep.php; docs/decisions.md M37) as merge
 * suggestions for the Duplicates screen: one match_run (source `vpg_dup_sweep`, run id `sweep-<engine>-<first 12 hex of the
 * file's sha256>`, the sweep's own run_id) and, per group, one open proposal per listing other than the keeper (lane
 * `vpg_duplicate`, band Manual, proposing the keeper's item, flags merge_suggestion + sweep), each with the group's evidence
 * (keeper, members, and why the sweep found each pair). Nothing is merged: a mapping lead decides every group on the
 * Duplicates screen (M31-M34).
 *
 *   php bin/import_vpg_duplicates.php --groups=<sweep out>/groups.jsonl [--channel=vapeandgo] [--apply] [--limit=N]
 *       [--db=<schema>] [--admin]
 *
 * Dry run unless --apply: the dry run reads the same and writes nothing (no run, no proposal, no audit row).
 * Every member is checked against the database as it is NOW, under its listing's lock, and left out when it no longer holds:
 *   stale         not linked, or linked to another item than the sweep saw (merges followed);
 *   same_item     one item with the keeper already (merged meanwhile);
 *   answered      a person kept it separate from the keeper's item, or the merge would contradict a reject (M22, M34);
 *   protected     its item (or the keeper's) is not legacy: a protected item cannot be merged (M31);
 *   quarantined   a listing of its item (or the keeper's) is quarantined or ignored;
 *   pending       a decision on it waits for a second person;
 *   open_elsewhere it already has an open proposal of another run (never superseded here: decide that one first).
 * All of these are worked out BEFORE the group's evidence is built: a group keeps only the members that pass (the evidence
 * names those and no other page, M41); a group left with the keeper alone is not written, nor
 * is one whose keeper is stale, protected, quarantined, waiting for a second person or suggested itself (`open_elsewhere`).
 * Idempotent: the run of a file is found again by its id, a proposal already recorded for it is `exists`.
 * Audited: every proposal (`mapping.propose`, by Proposals) and the run (`mapping.import_duplicates`, its counts).
 * Exit codes: 0 ok · 1 some lines unreadable or a proposal failed · 2 usage · 3 cannot run.
 */

use CW\Audit;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\Mapping\Proposals;
use CW\Ops\Cli;

require dirname(__DIR__) . '/vendor/autoload.php';

ini_set('memory_limit', '1G');

const SOURCE = 'vpg_dup_sweep';
const LANE = 'vpg_duplicate';
const CALLER = 'import_vpg_duplicates';

/**
 * A groups.jsonl line, checked: [group, kind, key, engine, keeper variant, members by variant], or a reason it is unreadable.
 *
 * @return array<string, mixed>|string
 */
function readGroup(mixed $g): array|string
{
    if (!is_array($g) || !is_int($g['group'] ?? null) || $g['group'] < 1 || ($g['kind'] ?? null) !== 'sweep' || !is_string($g['key'] ?? null)
        || preg_match('/^(ds\d+\.\d+):[0-9-]+$/D', $g['key'], $m) !== 1 || !is_array($g['members'] ?? null) || count($g['members']) < 2
        || !is_array($g['keeper'] ?? null)) {
        return 'not a sweep group (group, kind sweep, key, keeper, two or more members)';
    }
    $members = [];
    foreach ($g['members'] as $x) {
        $v = is_array($x) ? ($x['vpg_variant_id'] ?? null) : null;
        if (!is_string($v) || preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $v) !== 1 || !is_int($x['sku_id'] ?? null) || isset($members[$v])) {
            return 'a member without a variant id and item id (or twice)';
        }
        $members[$v] = $x;
    }
    $keeper = $g['keeper']['vpg_variant_id'] ?? null;
    if (!is_string($keeper) || !isset($members[$keeper])) {
        return 'the keeper is not a member';
    }
    return ['group' => $g['group'], 'key' => $g['key'], 'engine' => $m[1], 'keeper' => $keeper, 'members' => $members,
        'pairs' => is_array($g['pairs'] ?? null) ? $g['pairs'] : [], 'score' => is_int($g['score'] ?? null) ? $g['score'] : null];
}

exit(Cli::main('import_vpg_duplicates', ['groups:', 'channel:', 'apply', 'limit:'],
    'usage: php bin/import_vpg_duplicates.php --groups=<sweep out>/groups.jsonl [--channel=vapeandgo] [--apply] [--limit=N]',
    static function (Cli $cli, array $opts): int {
        $file = is_string($opts['groups'] ?? null) ? $opts['groups'] : null;
        if ($file === null || !is_readable($file) || !is_file($file)) {
            throw new InvalidArgumentException('--groups=<groups.jsonl of tools/vpg_duplicates/sweep.php> is required');
        }
        $code = is_string($opts['channel'] ?? null) ? $opts['channel'] : 'vapeandgo';
        $apply = array_key_exists('apply', $opts);
        $limit = Cli::intOpt($opts, 'limit', PHP_INT_MAX, 1, PHP_INT_MAX);
        $db = $cli->db;
        $t0 = hrtime(true);
        $channelId = $db->value('SELECT id FROM channel WHERE code = ?', [$code]);
        if ($channelId === null) {
            throw new InvalidArgumentException("no channel {$code}");
        }
        $channelId = (int) $channelId;
        $sha = hash_file('sha256', $file);

        // 1. The file.
        $groups = [];
        $bad = 0;
        $engine = null;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $i => $line) {
            $g = readGroup(json_decode($line, true));
            if (is_string($g)) {
                if (++$bad <= 20) {
                    $cli->error('line ' . ($i + 1) . ": {$g}");
                }
                continue;
            }
            if ($engine !== null && $g['engine'] !== $engine) {
                throw new InvalidArgumentException("line " . ($i + 1) . ": engine {$g['engine']}, the file's first group says {$engine} (one sweep per file)");
            }
            $engine = $g['engine'];
            $groups[] = $g;
        }
        $runName = 'sweep-' . ($engine ?? 'ds') . '-' . substr($sha, 0, 12);

        $ds = new DecisionService($db);
        $proposals = new Proposals($db, $ds);
        $caller = Caller::system(CALLER);
        // The run of this file, when an earlier --apply recorded it (its own proposals are `exists`, never `open_elsewhere`).
        $runRow = $db->value('SELECT id FROM match_run WHERE run_id = ? AND source = ?', [$runName, SOURCE]);
        $runRow = $runRow === null ? null : (int) $runRow;
        $c = ['groups' => count($groups), 'groups_written' => 0, 'groups_skipped' => 0, 'created' => 0, 'would_create' => 0, 'exists' => 0,
            'stale' => 0, 'same_item' => 0, 'answered' => 0, 'protected' => 0, 'quarantined' => 0, 'pending' => 0, 'open_elsewhere' => 0,
            'missing' => 0, 'failed' => 0, 'bad_lines' => $bad];
        $itemState = static function (Db $db, int $sku): ?string {
            $s = $db->one('SELECT sell_policy FROM sku WHERE id = ?', [$sku]);
            if ($s === null || $s['sell_policy'] !== 'legacy') {
                return 'protected';
            }
            if ($db->value("SELECT id FROM channel_listing WHERE sku_id = ? AND status IN ('quarantined', 'ignored') LIMIT 1", [$sku]) !== null) {
                return 'quarantined';
            }
            return null;
        };

        foreach (array_slice($groups, 0, $limit) as $g) {
            $vids = array_map('strval', array_keys($g['members']));
            $rows = [];
            foreach ($db->all('SELECT id, external_variant_id, sku_id, status FROM channel_listing WHERE channel_id = ? AND external_variant_id IN ('
                . implode(',', array_fill(0, count($vids), '?')) . ')', [$channelId, ...$vids]) as $r) {
                $rows[(string) $r['external_variant_id']] = $r;
            }
            // The keeper: still linked to the item the sweep saw (or the item that one was merged into), mergeable.
            $k = $rows[$g['keeper']] ?? null;
            $keepSku = $k === null || $k['status'] !== 'mapped' ? null : $ds->rootOf((int) $k['sku_id']);
            $why = $keepSku === null || $keepSku !== $ds->rootOf((int) $g['members'][$g['keeper']]['sku_id']) ? 'stale' : $itemState($db, $keepSku);
            if ($why === null && $db->value('SELECT id FROM match_decision WHERE pending_listing_id = ?', [(int) $k['id']]) !== null) {
                $why = 'pending';
            }
            if ($why === null && $db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [(int) $k['id']]) !== null) {
                $why = 'open_elsewhere';   // the keeper is itself suggested for something: decide that first
            }
            $report = sprintf('group %d (%s): keep vpg %s%s <-', $g['group'], $g['key'], $g['keeper'],
                $keepSku === null ? '' : ' ' . (string) $db->value('SELECT code FROM sku WHERE id = ?', [$keepSku]));
            if ($why !== null) {
                $c[$why] += count($g['members']) - 1;
                $c['groups_skipped']++;
                fwrite(STDOUT, "{$report} skipped: the keeper is {$why}\n");
                continue;
            }
            // 2. Each other member, checked as it is now.
            $ok = [];
            $results = [];
            foreach ($g['members'] as $v => $m) {
                $v = (string) $v;
                if ($v === $g['keeper']) {
                    continue;
                }
                $l = $rows[$v] ?? null;
                if ($l === null) {
                    $results[$v] = 'missing';
                    continue;
                }
                $results[$v] = $db->transaction(static function (Db $db) use ($ds, $itemState, $l, $m, $keepSku, $runRow): string {
                    $l = $db->one('SELECT id, sku_id, status FROM channel_listing WHERE id = ? FOR UPDATE', [(int) $l['id']]);
                    if ($l === null || $l['status'] !== 'mapped' || $ds->rootOf((int) $l['sku_id']) !== $ds->rootOf((int) $m['sku_id'])) {
                        return 'stale';
                    }
                    $blocked = $ds->duplicateBlocked((int) $l['id'], $keepSku);
                    if ($blocked !== null) {
                        return $blocked === 'same_item' ? 'same_item' : 'answered';
                    }
                    $state = $itemState($db, $ds->rootOf((int) $l['sku_id']));
                    if ($state !== null) {
                        return $state;
                    }
                    if ($db->value('SELECT id FROM match_decision WHERE pending_listing_id = ?', [(int) $l['id']]) !== null) {
                        return 'pending';
                    }
                    // An open proposal of another run (M41): left out HERE, before the group's evidence is written, so the evidence never
                    // names a page whose suggestion belongs to another group (a merge on the screen would settle that one unseen).
                    $open = $db->one('SELECT id, match_run_id FROM match_proposal WHERE open_listing_id = ?', [(int) $l['id']]);
                    if ($open !== null && ($runRow === null || (int) $open['match_run_id'] !== $runRow)) {
                        return 'open_elsewhere';
                    }
                    return 'ok';
                });
                if ($results[$v] === 'ok') {
                    $ok[$v] = (int) $l['id'];
                }
            }
            $kept = array_map('strval', array_merge([$g['keeper']], array_keys($ok)));   // (numeric variant ids are int array keys)
            $members = array_values(array_map(static fn (string $v): array => array_intersect_key($g['members'][$v],
                array_flip(['vpg_variant_id', 'listing_id', 'sku_id', 'code', 'title', 'status', 'units_30d', 'units_365d', 'barcodes', 'price'])), $kept));
            $evidence = ['group' => $g['group'], 'kind' => 'sweep', 'key' => $g['key'],
                'keeper' => ['vpg_variant_id' => $g['keeper'], 'sku_id' => $keepSku, 'code' => $g['members'][$g['keeper']]['code'] ?? null,
                    'title' => $g['members'][$g['keeper']]['title'] ?? null],
                'members' => $members,
                'sweep' => ['engine' => $g['engine'], 'score' => $g['score'], 'file_sha256' => $sha,
                    'pairs' => array_values(array_filter($g['pairs'], static fn (mixed $p): bool => is_array($p)
                        && in_array((string) ($p['a'] ?? ''), $kept, true) && in_array((string) ($p['b'] ?? ''), $kept, true)))],
                'action' => 'merge_skus of this listing\'s item into the keeper\'s item, if a mapping lead agrees (Duplicates screen)'];
            // 3. The proposals (apply), under each listing's lock: an open proposal of another run is never superseded here.
            foreach ($ok as $v => $listingId) {
                if (!$apply) {
                    $mine = $runRow === null ? null : $db->value('SELECT id FROM match_proposal WHERE match_run_id = ? AND listing_id = ?', [$runRow, $listingId]);
                    $open = $db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listingId]);
                    $results[$v] = $mine !== null ? 'exists' : ($open === null ? 'would_create' : 'open_elsewhere');
                    continue;
                }
                $runRow ??= $proposals->run($runName, SOURCE, null, $g['engine'], null, ['file' => basename($file), 'sha256' => $sha, 'groups' => count($groups)]);
                try {
                    $results[$v] = $db->transaction(static function (Db $db) use ($proposals, $caller, $listingId, $runRow, $keepSku, $evidence): string {
                        $db->one('SELECT id FROM channel_listing WHERE id = ? FOR UPDATE', [$listingId]);
                        $mine = $db->value('SELECT id FROM match_proposal WHERE match_run_id = ? AND listing_id = ?', [$runRow, $listingId]);
                        if ($mine === null && $db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listingId]) !== null) {
                            return 'open_elsewhere';
                        }
                        $r = $proposals->add($caller, $listingId, $runRow, ['proposed_sku_id' => $keepSku, 'band' => 'Manual', 'lane' => LANE,
                            'evidence' => $evidence, 'flags' => ['merge_suggestion', 'sweep']], false);
                        return match ($r['result']) {
                            'rejected_before' => 'answered',
                            default => $r['result'],
                        };
                    });
                } catch (CwException $e) {
                    $results[$v] = 'failed';
                    if ($c['failed'] < 20) {
                        $cli->error("group {$g['group']} vpg {$v}: {$e->errorCode}: {$e->getMessage()}");
                    }
                }
            }
            $written = false;
            foreach ($results as $v => $res) {
                $c[$res]++;
                $written = $written || in_array($res, ['created', 'exists', 'would_create'], true);
                $report .= sprintf(' vpg %s %s (%s)', $v, (string) ($g['members'][$v]['code'] ?? '-'), $res);
            }
            $c[$written ? 'groups_written' : 'groups_skipped']++;
            fwrite(STDOUT, $report . "\n");
        }

        if ($apply && $runRow !== null) {
            Audit::write($db, $caller, 'mapping.import_duplicates', 'match_run', (string) $runRow, null,
                ['run_id' => $runName, 'source' => SOURCE, 'file' => basename($file), 'sha256' => $sha] + $c);
        }
        $cli->log(sprintf('%srun=%s channel=%s file_sha256=%s groups=%d written=%d skipped=%d proposals: created=%d would_create=%d exists=%d; '
            . 'left out: stale=%d same_item=%d answered=%d protected=%d quarantined=%d pending=%d open_elsewhere=%d missing=%d; failed=%d bad_lines=%d ms=%d',
            $apply ? '' : 'DRY RUN (nothing written) ', $runName, $code, substr($sha, 0, 12), $c['groups'], $c['groups_written'], $c['groups_skipped'],
            $c['created'], $c['would_create'], $c['exists'], $c['stale'], $c['same_item'], $c['answered'], $c['protected'], $c['quarantined'], $c['pending'],
            $c['open_elsewhere'], $c['missing'], $c['failed'], $c['bad_lines'], intdiv(hrtime(true) - $t0, 1_000_000)));
        return $c['failed'] + $c['bad_lines'] > 0 ? Cli::PROBLEM : Cli::OK;
    }));
