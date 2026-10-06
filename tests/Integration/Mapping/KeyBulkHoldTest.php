<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Db;
use CW\Mapping\KeyBulk;
use CW\Mapping\KeyEligibility;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Mapping\Reband;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\TestDb;

/**
 * Holding listings of a Key spot-check's population back from every bulk confirm (docs/decisions.md M30): the file and
 * its refusals, the durable hold that every later bulk run respects (no file needed), the proposal staying open in the Key
 * queue, the release by a mapping lead, the audit, what the schema itself refuses, the hold following the listing to a
 * newer proposal (a new matching run, a re-band round trip) and into every later sample, and a hold that commits while a
 * bulk run is linking the same listing (two processes).
 */
final class KeyBulkHoldTest extends MappingTestCase
{
    use KeyFixtures;

    private function bulk(): KeyBulk
    {
        return new KeyBulk(self::$db, $this->ds, $this->proposals);
    }

    private function holds(?Db $db = null): KeyHold
    {
        return new KeyHold($db ?? self::$db);
    }

    private function rowCount(string $sql, array $args = []): int
    {
        return (int) self::$db->value($sql, $args);
    }

    /**
     * A sample its owner has confirmed (20 of a population of 24 at 90-99 and 8 re-banded from 85-89): the 12 others are
     * what its bulk confirm would link.
     *
     * @return array{owner: Caller, sample: array<string, mixed>, rest: list<array{listing: int, proposal: int, sku: int}>}
     */
    private function readySample(string $name): array
    {
        $this->keySetup();
        $owner = $this->staffUser(['mapping_lead', 'reviewer']);
        $made = [];
        for ($i = 0; $i < 24; $i++) {
            $made[] = $this->firstMatch($this->vpgItem(), 90 + $i % 10, [], [], null, [], 10 + $i);
        }
        $los = [];
        for ($i = 0; $i < 8; $i++) {
            $los[] = $this->firstMatch($this->vpgItem(), 85 + $i % 5, [], [], null, [], 500 + $i);
        }
        self::assertSame(['Check>Key' => 8], (new Reband(self::$db, $this->proposals))->run(Caller::system('reband_proposals'), true)['applied']);
        foreach ($los as $m) {
            $made[] = ['proposal' => (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$m['listing']])] + $m;
        }
        $s = (new KeySample(self::$db))->create($owner, $name, 20, true);
        foreach ($s['members'] as $m) {
            $this->confirm($owner, $m['proposal_id']);
        }
        self::assertSame('complete', (new KeySample(self::$db))->status($name)['verdict']);
        $inSample = array_column($s['members'], 'proposal_id');
        $rest = array_values(array_filter($made, static fn (array $m): bool => !in_array($m['proposal'], $inSample, true)));
        usort($rest, static fn (array $a, array $b): int => $a['proposal'] <=> $b['proposal']);
        self::assertCount(12, $rest);
        return ['owner' => $owner, 'sample' => $s, 'rest' => $rest];
    }

    /** A hold file's text. @param list<list<mixed>> $rows @param list<string> $header */
    private static function csv(array $rows, array $header = ['proposal_id', 'listing_id', 'reason']): string
    {
        $fh = fopen('php://memory', 'w+b');
        self::assertIsResource($fh);
        fputcsv($fh, $header, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, array_map('strval', $r), ',', '"', '');
        }
        rewind($fh);
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        return $text;
    }

    /** @param list<list<mixed>> $rows @return list<array<string, mixed>> */
    private static function rowsOf(array $rows): array
    {
        return KeyHold::parse(self::csv($rows));
    }

    /** @param array<string, mixed> $r run() @return array<int, string> row => outcome or refusal */
    private static function outcomes(array $r): array
    {
        $out = [];
        foreach ($r['lines'] as $l) {
            $out[$l['row']] = $l['outcome'] === 'refused' ? 'refused:' . $l['code'] : $l['outcome'];
        }
        return $out;
    }

    public function testTheFileNamesItsColumnsAndEveryUnreadableRowSaysWhy(): void
    {
        $text = "\u{FEFF}note,REASON,listing_id,proposal_id\n"
            . "x,\"Flavour differs: \"\"mango ice\"\" vs mango,\nsee photo\",12,34\n"
            . "\n"
            . ",,,\n"
            . "x,  the strength  , 12, 35 \n"
            . "x,ok,12,3a\n"
            . "x,ok,,36\n"
            . "x,   ,12,37\n"
            . 'x,' . str_repeat('é', 501) . ",12,38\n"
            . "x,bell\x07,12,39\n"
            . "x,\"\xC3\x28\",12,40\n"
            . "x,ok,12,0\n";
        $rows = KeyHold::parse($text);
        self::assertSame([
            [2, 34, 12, 'Flavour differs: "mango ice" vs mango, see photo', null],
            [5, 35, 12, 'the strength', null],
            [6, null, 12, 'ok', 'bad_proposal_id'],
            [7, 36, null, 'ok', 'bad_listing_id'],
            [8, 37, 12, '', 'no_reason'],
            [9, 38, 12, '', 'reason_too_long'],
            [10, 39, 12, '', 'bad_reason'],
            [11, 40, 12, '', 'bad_reason'],
            [12, null, 12, 'ok', 'bad_proposal_id'],
        ], array_map(static fn (array $r): array => [$r['row'], $r['proposal_id'], $r['listing_id'], $r['reason'], $r['problem']], $rows),
            'columns by name in any order, a BOM, blank rows skipped (a row is a record: a quoted line break does not count), quotes, line breaks and spaces in the reason');
        self::assertSame(500, mb_strlen(KeyHold::parse("proposal_id,listing_id,reason\n1,2," . str_repeat('é', 500))[0]['reason']));
        foreach (["proposal_id,listing_id\n1,2\n", "proposal_id,listing_id,reason,reason\n1,2,x,y\n", "1,2,x\n", '', "\n\n"] as $bad) {
            self::refused(400, 'bad_file', static fn () => KeyHold::parse($bad));
        }
        self::assertSame([], KeyHold::parse("proposal_id,listing_id,reason\n"));
    }

    public function testAHoldKeepsTheProposalOutOfEveryBulkRunUntilALeadReleasesIt(): void
    {
        $x = $this->readySample('h1');
        $owner = $x['owner'];
        $rest = $x['rest'];
        [$a, $b, $c] = $rest;
        $member = $x['sample']['members'][0];
        $newcomer = $this->firstMatch($this->vpgItem(), 97); // Key after the draw: not in the population
        $file = [
            [$a['proposal'], $a['listing'], 'Flavour differs: mango ice vs mango'],
            [$b['proposal'], $b['listing'], 'Strength 10mg vs 20mg'],
            [$c['proposal'], $c['listing'], 'Pack of 3 on the Vape and Go page'],
            [$newcomer['proposal'], $newcomer['listing'], 'not in the population'],
            [$member['proposal_id'], $member['listing_id'], 'one of the 20'],
            [$rest[3]['proposal'], $rest[4]['listing'], 'a typo in the listing'],
            [$a['proposal'], $a['listing'], 'twice'],
            ['x', $a['listing'], 'unreadable'],
        ];
        $want = [2 => 'hold', 3 => 'hold', 4 => 'hold', 5 => 'refused:not_in_population', 6 => 'refused:sample_member', 7 => 'refused:listing_mismatch',
            8 => 'refused:duplicate_in_file', 9 => 'refused:bad_proposal_id'];
        $audits = $this->rowCount('SELECT COUNT(*) FROM audit_log');

        // Who may: an active mapping lead only.
        self::refused(403, 'lead_required', fn () => $this->holds()->run($this->staffUser('mapper'), 'h1', self::rowsOf($file), false, false));
        self::refused(403, 'staff_required', fn () => $this->holds()->run(Caller::system('test'), 'h1', self::rowsOf($file), false, false));
        self::refused(403, 'staff_not_allowed', fn () => $this->holds()->run($this->staffUser('mapping_lead', false), 'h1', self::rowsOf($file), false, false));
        self::refused(404, 'unknown_sample', fn () => $this->holds()->run($owner, 'nope', self::rowsOf($file), false, false));

        // The dry run checks every row and writes nothing.
        $dry = $this->holds()->run($owner, 'h1', self::rowsOf($file), false, false);
        self::assertSame($want, self::outcomes($dry));
        self::assertSame(['hold', 8, 3, 0, ['bad_proposal_id' => 1, 'duplicate_in_file' => 1, 'listing_mismatch' => 1, 'not_in_population' => 1, 'sample_member' => 1], 0, 0, 3],
            [$dry['mode'], $dry['rows'], $dry['todo'], $dry['already'], $dry['refused'], $dry['written'], $dry['held_before'], $dry['held_after']]);
        self::assertSame(['h1', 'complete'], [$dry['sample']['name'], $dry['sample']['verdict']]);
        self::assertSame([0, $audits], [$this->rowCount('SELECT COUNT(*) FROM key_bulk_hold'), $this->rowCount('SELECT COUNT(*) FROM audit_log')], 'a dry run writes nothing');

        // --apply: the rows that pass are held, the refused ones are listed and not held.
        $done = $this->holds()->run($owner, 'h1', self::rowsOf($file), false, true, ['name' => 'hold.csv', 'sha256' => str_repeat('a', 64)]);
        self::assertSame($want, self::outcomes($done));
        self::assertSame([3, 0, 3], [$done['written'], $done['held_before'], $done['held_after']]);
        $rows = self::$db->all('SELECT * FROM key_bulk_hold ORDER BY id');
        self::assertSame([[(int) $x['sample']['sample_id'], $a['proposal'], $a['listing'], 'hold', null, 'Flavour differs: mango ice vs mango', $owner->staffUserId, $owner->actor]],
            [[$rows[0]['sample_id'], $rows[0]['proposal_id'], $rows[0]['listing_id'], $rows[0]['kind'], $rows[0]['released_hold_id'], $rows[0]['reason'],
                $rows[0]['staff_user_id'], $rows[0]['actor']]]);
        self::assertSame([$a['proposal'], $b['proposal'], $c['proposal']], array_column($rows, 'proposal_id'));
        $audit = self::$db->one("SELECT actor, entity_type, entity_id, detail FROM audit_log WHERE action = 'mapping.key_hold'");
        self::assertSame([$owner->actor, 'key_sample', (string) $x['sample']['sample_id']], [$audit['actor'], $audit['entity_type'], $audit['entity_id']]);
        $detail = json_decode((string) $audit['detail'], true);
        self::assertSame(['h1', 'hold', 'hold.csv', str_repeat('a', 64), 8, 0, 'M30'], [$detail['sample'], $detail['mode'], $detail['file'], $detail['sha256'],
            $detail['rows'], $detail['already'], $detail['decision']]);
        self::assertSame([[(int) $rows[0]['id'], $a['proposal'], $a['listing'], 'Flavour differs: mango ice vs mango']], array_slice($detail['held'], 0, 1));
        self::assertSame([[5, $newcomer['proposal'], 'not_in_population'], [6, $member['proposal_id'], 'sample_member'], [7, $rest[3]['proposal'], 'listing_mismatch'],
            [8, $a['proposal'], 'duplicate_in_file'], [9, null, 'bad_proposal_id']], $detail['refused']);

        // A re-run of the same file writes nothing.
        $again = $this->holds()->run($owner, 'h1', self::rowsOf($file), false, true);
        self::assertSame(['already_held', 'already_held', 'already_held'], array_slice(array_values(self::outcomes($again)), 0, 3));
        self::assertSame('Flavour differs: mango ice vs mango', $again['lines'][0]['note'], 'the reason it is held by');
        self::assertSame([0, 3, 0, 3, 3], [$again['todo'], $again['already'], $again['written'], $again['held_before'], $again['held_after']]);
        self::assertSame([3, 1], [$this->rowCount('SELECT COUNT(*) FROM key_bulk_hold'), $this->rowCount("SELECT COUNT(*) FROM audit_log WHERE action = 'mapping.key_hold'")]);

        // Held: still an open Key proposal on a suggested listing (the Key queue), and left out of the bulk with its reason.
        foreach ([$a, $b, $c] as $m) {
            self::assertSame(['open', 'Key'], [self::proposalRow($m['proposal'])['status'], self::proposalRow($m['proposal'])['band']]);
            self::assertSame('suggested', $this->link($m['listing'])['status']);
        }
        $sid = (int) $x['sample']['sample_id'];
        $checked = (new KeyEligibility(self::$db, $sid))->check([$a['proposal']])[$a['proposal']];
        self::assertSame(['held_for_review'], $checked['reasons']);
        self::assertSame(['Flavour differs: mango ice vs mango', 'h1', $owner->staffUserId], [$checked['hold']['reason'], $checked['hold']['sample'],
            (int) self::$db->value('SELECT staff_user_id FROM key_bulk_hold WHERE id = ?', [$checked['hold']['hold_id']])]);
        self::assertNull((new KeyEligibility(self::$db, $sid))->check([$rest[3]['proposal']])[$rest[3]['proposal']]['hold']);
        self::assertSame([$a['listing'], $b['listing'], $c['listing']], array_keys(KeyHold::activeForListings(self::$db, array_column($rest, 'listing'))));

        $dryBulk = $this->bulk()->confirm($owner, 'h1', false);
        self::assertSame([12, 9, ['held_for_review' => 3], 3], [$dryBulk['population'], $dryBulk['eligible'], $dryBulk['excluded'], $dryBulk['held']]);
        self::assertSame('Strength 10mg vs 20mg', $dryBulk['checked'][$b['proposal']]['hold']['reason']);
        $canary = $this->bulk()->confirm($owner, 'h1', true, 4);
        self::assertSame(4, $canary['applied']);
        $all = $this->bulk()->confirm($owner, 'h1', true);
        self::assertSame([5, 3, []], [$all['applied'], $all['held'], $all['failed']]);
        // Re-run without any file: the holds are in the database, not in the file.
        $third = $this->bulk()->confirm($owner, 'h1', true);
        self::assertSame([0, 0, ['held_for_review' => 3, 'proposal_decided' => 9]], [$third['eligible'], $third['applied'], $third['excluded']]);
        foreach ([$a, $b, $c] as $m) {
            self::assertSame(['suggested', null], [$this->link($m['listing'])['status'], $this->link($m['listing'])['sku_id']], 'never linked by the bulk');
            self::assertSame('open', self::proposalRow($m['proposal'])['status']);
        }
        self::assertSame(3, json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.key_bulk' ORDER BY id DESC LIMIT 1"), true)['held']);

        // A listing the bulk linked already cannot be held (the hold would change nothing): the row is refused.
        $linked = $rest[5];
        self::assertSame('mapped', $this->link($linked['listing'])['status']);
        $late = $this->holds()->run($owner, 'h1', self::rowsOf([[$linked['proposal'], $linked['listing'], 'too late']]), false, true);
        self::assertSame([2 => 'refused:listing_mapped'], self::outcomes($late));
        self::assertSame([0, 3], [$late['written'], $this->rowCount("SELECT COUNT(*) FROM key_bulk_hold WHERE kind = 'hold'")]);

        // The release: a mapping lead only, with a reason; it makes the proposal eligible again.
        $otherLead = $this->staffUser('mapping_lead');
        $release = [[$a['proposal'], $a['listing'], 'Checked: the flavour is the same, the title differs'], [$linked['proposal'], $linked['listing'], 'never held'],
            [$member['proposal_id'], $member['listing_id'], 'a member']];
        self::refused(403, 'lead_required', fn () => $this->holds()->run($this->staffUser('mapper'), 'h1', self::rowsOf($release), true, true));
        $rel = $this->holds()->run($otherLead, 'h1', self::rowsOf($release), true, true);
        self::assertSame([2 => 'release', 3 => 'refused:not_held', 4 => 'refused:sample_member'], self::outcomes($rel));
        self::assertSame(['release', 1, 3, 2], [$rel['mode'], $rel['written'], $rel['held_before'], $rel['held_after']]);
        $r = self::$db->one("SELECT * FROM key_bulk_hold WHERE kind = 'release'");
        self::assertSame([$a['proposal'], (int) $rows[0]['id'], $otherLead->staffUserId, 'Checked: the flavour is the same, the title differs'],
            [$r['proposal_id'], $r['released_hold_id'], $r['staff_user_id'], $r['reason']]);
        $ra = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.key_hold_release'"), true);
        self::assertSame([[(int) $r['id'], (int) $rows[0]['id'], $a['proposal'], $a['listing'], 'Checked: the flavour is the same, the title differs']], $ra['released']);
        self::assertSame([2 => 'already_released', 3 => 'refused:not_held', 4 => 'refused:sample_member'],
            self::outcomes($this->holds()->run($otherLead, 'h1', self::rowsOf($release), true, true)), 'a re-run releases nothing twice');
        self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM key_bulk_hold WHERE kind = 'release'"));
        self::assertSame([], (new KeyEligibility(self::$db, $sid))->check([$a['proposal']])[$a['proposal']]['reasons']);
        $after = $this->bulk()->confirm($owner, 'h1', true);
        self::assertSame([1, 2, ['held_for_review' => 2, 'proposal_decided' => 9]], [$after['applied'], $after['held'], $after['excluded']]);
        self::assertSame(['mapped', $a['sku']], [$this->link($a['listing'])['status'], $this->link($a['listing'])['sku_id']]);

        // Released and held again: a new hold, in force again.
        $this->holds()->run($otherLead, 'h1', self::rowsOf([[$b['proposal'], $b['listing'], 'looked fine']]), true, true);
        self::assertSame([], KeyHold::activeForListings(self::$db, [$b['listing']]));
        $re = $this->holds()->run($owner, 'h1', self::rowsOf([[$b['proposal'], $b['listing'], 'No: the pack size differs after all']]), false, true);
        self::assertSame([2 => 'hold'], self::outcomes($re));
        self::assertSame('No: the pack size differs after all', KeyHold::activeForListings(self::$db, [$b['listing']])[$b['listing']]['reason']);
        self::assertSame(0, $this->bulk()->confirm($owner, 'h1', true)['applied']);
        self::assertSame(['open', 'suggested'], [self::proposalRow($b['proposal'])['status'], $this->link($b['listing'])['status']]);
    }

    public function testTheSchemaHoldsTheRulesToo(): void
    {
        $x = $this->readySample('h2');
        $owner = (int) $x['owner']->staffUserId;
        $sid = (int) $x['sample']['sample_id'];
        [$a, $b] = $x['rest'];
        $member = $x['sample']['members'][0];
        $insert = static fn (array $v): int => self::$db->insert('INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, released_hold_id, reason, staff_user_id, actor) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $v);
        $hold = $insert([$sid, $a['proposal'], $a['listing'], 'hold', null, 'why', $owner, 'staff:x']);
        $hb = $insert([$sid, $b['proposal'], $b['listing'], 'hold', null, 'why', $owner, 'staff:x']);
        $newcomer = $this->firstMatch($this->vpgItem(), 97);
        self::assertSame(1452, self::mysqlError(fn () => $insert([$sid, $newcomer['proposal'], $newcomer['listing'], 'hold', null, 'why', $owner, 'staff:x'])),
            'only a proposal of the sample\'s population');
        self::assertSame(3819, self::mysqlError(fn () => $insert([$sid, $a['proposal'], $a['listing'], 'hold', $hold, 'why', $owner, 'staff:x'])), 'a hold ends nothing');
        self::assertSame(3819, self::mysqlError(fn () => $insert([$sid, $a['proposal'], $a['listing'], 'release', null, 'why', $owner, 'staff:x'])), 'a release ends a hold');
        self::assertSame(3819, self::mysqlError(fn () => $insert([$sid, $a['proposal'], $a['listing'], 'hold', null, '   ', $owner, 'staff:x'])), 'a reason');
        self::assertSame(1452, self::mysqlError(fn () => $insert([$sid, $a['proposal'], $a['listing'], 'release', $hb, 'why', $owner, 'staff:x'])),
            'a release ends a hold of the same proposal');
        $insert([$sid, $a['proposal'], $a['listing'], 'release', $hold, 'why', $owner, 'staff:x']);
        $released = (int) self::$db->value("SELECT id FROM key_bulk_hold WHERE kind = 'release' AND released_hold_id = ?", [$hold]);
        self::assertSame(1062, self::mysqlError(fn () => $insert([$sid, $a['proposal'], $a['listing'], 'release', $hold, 'again', $owner, 'staff:x'])), 'once');
        self::assertSame(1452, self::mysqlError(fn () => $insert([$sid, $a['proposal'], $a['listing'], 'release', $released, 'why', $owner, 'staff:x'])),
            'a release ends a HOLD row, never another release');
        self::assertSame(3105, self::mysqlError(fn () => self::$db->insert('INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, released_hold_id, released_kind, '
            . "reason, staff_user_id, actor) VALUES (?, ?, ?, 'release', ?, 'hold', 'why', ?, 'staff:x')", [$sid, $b['proposal'], $b['listing'], $hb, $owner])),
            'released_kind is the schema\'s, never written');
        self::assertSame([$b['listing']], array_keys(KeyHold::activeForListings(self::$db, [$a['listing'], $b['listing'], $member['listing_id']])));
        self::assertSame([$b['proposal']], array_column(KeyHold::ofSample(self::$db, $sid), 'proposal_id'));
    }

    /** A later run's proposal for a listing: the same item and judge answer as the open proposal, as a new import would. */
    private function newRunProposal(int $runId, int $listingId): int
    {
        $old = self::$db->one('SELECT * FROM match_proposal WHERE open_listing_id = ?', [$listingId]);
        self::assertNotNull($old);
        return $this->proposals->add(Caller::system('import_proposals'), $listingId, $runId, ['band' => 'Key', 'proposed_sku_id' => (int) $old['proposed_sku_id'],
            'lane' => 'barcode', 'ai_outcome' => 'match', 'ai_confidence' => (int) $old['ai_confidence'], 'ai_units_per_item' => 1,
            'evidence' => json_decode((string) $old['evidence'], true)])['proposal_id'];
    }

    /**
     * Review blocker of M30 (6 Oct 2026): the hold was keyed on the proposal, so once a new matching run replaced the held
     * proposal, the listing went into a later sample's population and that sample's bulk confirm linked it. The hold is on
     * the LISTING: its newer proposal is `held_for_review`, no later sample draws it, a hold written (for the older,
     * replaced proposal) after a later sample drew the listing keeps that sample's bulk off it too, and the release names
     * the proposal the hold was written for.
     */
    public function testTheHoldFollowsTheListingToANewerProposalAndIntoEveryLaterSample(): void
    {
        $x = $this->readySample('h4');
        $owner = $x['owner'];
        $sid = (int) $x['sample']['sample_id'];
        $rest = $x['rest'];
        $a = $rest[0];
        self::assertSame(1, $this->holds()->run($owner, 'h4', self::rowsOf([[$a['proposal'], $a['listing'], 'Pack of 3 vs single']]), false, true)['written']);

        // 1. A new matching run proposes again for every listing of the population still open: each replaces the old proposal.
        $run4 = $this->proposals->run('run4-sold', 'first_match', null, 'n2.1/c1.0/v2.1/b2.1', null, ['band_version' => 'b2.1']);
        $fresh = [];
        foreach ($rest as $m) {
            $fresh[$m['listing']] = $this->newRunProposal($run4, $m['listing']);
            self::assertSame('superseded', self::proposalRow($m['proposal'])['status']);
        }
        $checked = (new KeyEligibility(self::$db))->check(array_values($fresh));
        self::assertSame(['held_for_review'], $checked[$fresh[$a['listing']]]['reasons'], 'the hold is on the listing, not on the proposal it was written for');
        self::assertSame([$a['proposal'], 'h4', 'Pack of 3 vs single'], [$checked[$fresh[$a['listing']]]['hold']['proposal_id'],
            $checked[$fresh[$a['listing']]]['hold']['sample'], $checked[$fresh[$a['listing']]]['hold']['reason']]);
        foreach (array_slice($rest, 1) as $m) {
            self::assertSame([], $checked[$fresh[$m['listing']]]['reasons'], 'control: the same new run on a listing nobody held');
        }
        $h = KeyHold::ofSample(self::$db, $sid);
        self::assertSame([[$a['proposal'], 'superseded', true, $fresh[$a['listing']], null]],
            array_map(static fn (array $r): array => [$r['proposal_id'], $r['proposal_status'], $r['open'], $r['open_proposal_id'], $r['linked_by_batch']], $h));
        // h4's own bulk: the held listing still counts as held (it is waiting), the others' proposals were replaced.
        $dry = $this->bulk()->confirm($owner, 'h4', false);
        self::assertSame([0, ['proposal_superseded' => 12], 1], [$dry['eligible'], $dry['excluded'], $dry['held']]);

        // 2. A later sample of another lead: the held listing is not drawn, the other new proposals are.
        $lead2 = $this->staffUser(['mapping_lead', 'reviewer']);
        for ($i = 0; $i < 30; $i++) {
            $this->firstMatch($this->vpgItem(), 90 + $i % 10);
        }
        $s5 = (new KeySample(self::$db))->create($lead2, 'h5', 20, true);
        self::assertSame([41, ['held_for_review' => 1]], [$s5['population'], $s5['excluded']]);
        $pop = array_map('intval', self::$db->column('SELECT listing_id FROM key_sample_member WHERE sample_id = ?', [$s5['sample_id']]));
        self::assertNotContains($a['listing'], $pop);
        $members = array_column($s5['members'], 'listing_id');
        // 3. One of the new run's listings that h5 drew into its population (not one of its 20) is screened from h4's report
        //    and held there AFTER h5's draw: the file names h4's (replaced) proposal of it, and the hold covers h5's proposal.
        $late = null;
        foreach (array_slice($rest, 1) as $m) {
            if (!in_array($m['listing'], $members, true)) {
                $late = $m;
                break;
            }
        }
        self::assertNotNull($late, 'some of the 11 is outside the 20 drawn from 41');
        self::assertSame([2 => 'refused:not_in_population'], self::outcomes($this->holds()->run($owner, 'h4',
            self::rowsOf([[$fresh[$late['listing']], $late['listing'], 'the newer proposal']]), false, false)), 'h4\'s file names h4\'s proposals');
        $hold = $this->holds()->run($owner, 'h4', self::rowsOf([[$late['proposal'], $late['listing'], 'Strength 10mg vs 20mg']]), false, true);
        self::assertSame([[2 => 'hold'], 1, 1, 2], [self::outcomes($hold), $hold['written'], $hold['held_before'], $hold['held_after']],
            'a replaced proposal whose listing still waits is held: the hold covers the listing\'s newer proposal');
        $twice = $this->holds()->run($lead2, 'h5', self::rowsOf([[$fresh[$late['listing']], $late['listing'], 'again, in h5']]), false, true);
        self::assertSame([[2 => 'already_held'], 0], [self::outcomes($twice), $twice['written']]);
        self::assertSame("Strength 10mg vs 20mg (the listing is held under proposal {$late['proposal']} of the sample h4)", $twice['lines'][0]['note']);

        // 4. h5's owner confirms its 20 and runs its bulk confirm: neither held listing is linked.
        foreach ($s5['members'] as $m) {
            $this->confirm($lead2, $m['proposal_id']);
        }
        $dry5 = $this->bulk()->confirm($lead2, 'h5', false);
        self::assertSame([21, 20, ['held_for_review' => 1], 1], [$dry5['population'], $dry5['eligible'], $dry5['excluded'], $dry5['held']]);
        self::assertSame([$late['proposal'], 'h4'], [$dry5['checked'][$fresh[$late['listing']]]['hold']['proposal_id'], $dry5['checked'][$fresh[$late['listing']]]['hold']['sample']]);
        $all5 = $this->bulk()->confirm($lead2, 'h5', true);
        self::assertSame([20, []], [$all5['applied'], $all5['failed']]);
        foreach ([$a, $late] as $m) {
            self::assertSame(['suggested', null], [$this->link($m['listing'])['status'], $this->link($m['listing'])['sku_id']], 'no bulk confirm linked a held listing');
            self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM match_decision WHERE listing_id = ? AND bulk_batch_id IS NOT NULL', [$m['listing']]));
            self::assertSame('open', self::proposalRow($fresh[$m['listing']])['status']);
        }
        self::assertSame([], KeyHold::ofSample(self::$db, (int) $s5['sample_id']), 'the holds are h4\'s');

        // 5. The release names the proposal the hold was written for, replaced or not (the newer one is not h4's); then
        //    h5's next bulk run links the released listing.
        $rel = $this->holds()->run($owner, 'h4', self::rowsOf([[$fresh[$late['listing']], $late['listing'], 'the newer one'],
            [$late['proposal'], $late['listing'], 'Checked: 10mg on both pages']]), true, true);
        self::assertSame([2 => 'refused:not_in_population', 3 => 'release'], self::outcomes($rel));
        self::assertSame("Strength 10mg vs 20mg (the listing is held under proposal {$late['proposal']} of the sample h4)", $rel['lines'][0]['note']);
        self::assertSame([2, 1], [$rel['held_before'], $rel['held_after']]);
        $now5 = (new KeyEligibility(self::$db, (int) $s5['sample_id']))->check([$fresh[$late['listing']]])[$fresh[$late['listing']]];
        self::assertSame([[], null], [$now5['reasons'], $now5['hold']]);
        $again5 = $this->bulk()->confirm($lead2, 'h5', true);
        self::assertSame([1, 0], [$again5['applied'], $again5['held']]);
        self::assertSame('mapped', $this->link($late['listing'])['status']);
        self::assertSame(['suggested', ['held_for_review']], [$this->link($a['listing'])['status'],
            (new KeyEligibility(self::$db))->check([$fresh[$a['listing']]])[$fresh[$a['listing']]]['reasons']]);
    }

    /**
     * The same through a re-band round trip: the held Key proposal is moved to Check (as a stricter band would, a re-band
     * proposal superseding it), then back to Key by the real re-band under b2.1. The new Key proposal is held.
     */
    public function testAReBandRoundTripKeepsTheListingHeld(): void
    {
        $x = $this->readySample('h6');
        $owner = $x['owner'];
        $sid = (int) $x['sample']['sample_id'];
        $a = null;
        foreach ($x['rest'] as $m) {
            if ((int) self::proposalRow($m['proposal'])['ai_confidence'] >= 90) {
                $a = $m;
                break;
            }
        }
        self::assertNotNull($a);
        self::assertSame(1, $this->holds()->run($owner, 'h6', self::rowsOf([[$a['proposal'], $a['listing'], 'Flavour: mango ice vs mango']]), false, true)['written']);

        // Key>Check, written as Reband::apply writes a move (run reband-<version>, source reband, evidence.reband): a band
        // under which this judge answer (confidence 87 here) is Check.
        $strict = $this->proposals->run('reband-b2.0', Reband::SOURCE, null, 'reband/b2.0', null, ['band_version' => 'b2.0', 'decision' => 'M26']);
        $title = (string) self::$db->value('SELECT product_title FROM listing_profile WHERE listing_id = ?', [$a['listing']]);
        $ev = self::firstMatchEvidence($a['sku'], 87, ['title' => $title, 'reband' => ['from_proposal_id' => $a['proposal'], 'from_band' => 'Key',
            'from_band_version' => 'b2.0', 'band_version' => 'b2.0']]);
        $check = $this->proposals->add(Caller::system('reband_proposals'), $a['listing'], $strict, ['band' => 'Check', 'proposed_sku_id' => $a['sku'], 'lane' => 'barcode',
            'ai_outcome' => 'match', 'ai_confidence' => 87, 'ai_units_per_item' => 1, 'evidence' => $ev])['proposal_id'];
        self::assertSame('superseded', self::proposalRow($a['proposal'])['status']);
        self::assertContains('held_for_review', (new KeyEligibility(self::$db))->check([$check])[$check]['reasons']);

        // Check>Key by the real re-band (b2.1: Key from 85). A hold is not a re-band blocker: the hold follows the listing.
        $r = (new Reband(self::$db, $this->proposals))->run($owner, true);
        self::assertSame(['Check>Key' => 1], $r['applied']);
        $key = (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$a['listing']]);
        self::assertSame(['Key', 'reband-b2.1'], [self::proposalRow($key)['band'],
            (string) self::$db->value('SELECT r.run_id FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE p.id = ?', [$key])]);
        $c = (new KeyEligibility(self::$db))->check([$key])[$key];
        self::assertSame(['held_for_review'], $c['reasons'], 'otherwise a Key proposal that qualifies');
        self::assertSame([$a['proposal'], 'Flavour: mango ice vs mango'], [$c['hold']['proposal_id'], $c['hold']['reason']]);
        self::assertSame([[$a['proposal'], true, $key]], array_map(static fn (array $h): array => [$h['proposal_id'], $h['open'], $h['open_proposal_id']],
            KeyHold::ofSample(self::$db, $sid)));

        // No sample draws it, and h6's bulk confirm links everything else.
        $e = self::refused(409, 'population_too_small', fn () => (new KeySample(self::$db))->create($owner, 'h7', 20, false));
        self::assertSame(['held_for_review' => 1, 'in_other_sample' => 11], $e->detail['excluded']);
        $all = $this->bulk()->confirm($owner, 'h6', true);
        self::assertSame([11, 1, ['proposal_superseded' => 1]], [$all['applied'], $all['held'], $all['excluded']]);
        self::assertSame(['suggested', null], [$this->link($a['listing'])['status'], $this->link($a['listing'])['sku_id']]);
    }

    /**
     * The race the listing lock closes: the bulk confirm (its own process) has checked a proposal and is about to link it
     * when a hold of it commits. The bulk's link waits for the hold (KeyHold reads the listing FOR SHARE), then finds it under
     * its own lock and rolls back: the proposal stays open and held, the others are linked.
     */
    public function testAHoldThatCommitsWhileTheBulkIsLinkingItsListingWins(): void
    {
        $x = $this->readySample('h3');
        $owner = $x['owner'];
        $email = (string) self::$db->value('SELECT email FROM staff_user WHERE id = ?', [$owner->staffUserId]);
        $target = $x['rest'][0];
        $b = TestDb::connect();
        $b->pdo()->beginTransaction();
        $proc = null;
        $pipes = [];
        try {
            // The hold is written, not committed: the bulk's checks cannot see it yet (READ COMMITTED).
            $r = $this->holds($b)->run($owner, 'h3', self::rowsOf([[$target['proposal'], $target['listing'], 'Spotted in the report: 2 x 10ml']]), false, true);
            self::assertSame(1, $r['written']);
            $root = dirname(__DIR__, 3);
            $proc = proc_open([PHP_BINARY, "{$root}/bin/bulk_confirm_key.php", '--db=' . TestDb::name(), '--admin', '--sample=h3', "--lead={$email}", '--apply'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            self::assertIsResource($proc);
            self::waitForTheLink($proc, $target['listing']);
            $b->pdo()->commit();
        } finally {
            if ($b->inTransaction()) {
                $b->pdo()->rollBack();
            }
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertSame(1, $code, $out . $err);
        self::assertStringContainsString('population=12 eligible=12 excluded={} held=0', $out, 'the run started before the hold committed');
        self::assertStringContainsString('applied=11 skipped={} failed={"held_meanwhile":1}', $out);
        self::assertSame(['suggested', null], [$this->link($target['listing'])['status'], $this->link($target['listing'])['sku_id']]);
        self::assertSame('open', self::proposalRow($target['proposal'])['status']);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM match_decision WHERE listing_id = ? AND bulk_batch_id IS NOT NULL', [$target['listing']]));
        self::assertSame('Spotted in the report: 2 x 10ml', KeyHold::activeForListings(self::$db, [$target['listing']])[$target['listing']]['reason']);
        $again = $this->bulk()->confirm($owner, 'h3', true);
        self::assertSame([0, ['held_for_review' => 1, 'proposal_decided' => 11]], [$again['applied'], $again['excluded']]);
    }

    /**
     * Waits until the bulk confirm's connection (on this test schema) is locking the listing for its link, which the open
     * hold's FOR SHARE makes it wait for. (Seen in the process list: on the managed cluster INNODB_TRX does not list that
     * transaction.) @param resource $proc
     */
    private static function waitForTheLink($proc, int $listingId): void
    {
        $deadline = microtime(true) + 60;
        while (microtime(true) < $deadline) {
            $waiting = (int) self::$db->value(
                'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID() AND INFO LIKE ?',
                [TestDb::name(), "%FROM channel_listing WHERE id = {$listingId} FOR UPDATE%"],
            );
            if ($waiting > 0) {
                return;
            }
            if (!proc_get_status($proc)['running']) {
                self::fail('the bulk confirm ended without waiting for the hold\'s lock');
            }
            usleep(50_000);
        }
        self::fail('the bulk confirm never waited for the hold\'s lock: ' . json_encode(
            self::$db->all('SELECT ID, COMMAND, STATE, INFO FROM information_schema.PROCESSLIST WHERE DB = ?', [TestDb::name()])));
    }
}
