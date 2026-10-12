<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\CwException;
use CW\Mapping\BulkDecisions;
use CW\Mapping\DecisionService;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Mapping\OneAtATime;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\MappingTestCase;

/**
 * Bulk action on ticked rows (docs/decisions.md M46-M53): row by row through DecisionService, in listing id order, each with the
 * map_version and the suggestion the page showed; the rows a person must look at one at a time are skipped with why (held back,
 * in a spot check, vetoed, flagged, changed since the page was drawn, ...); a row that needs a second person waits for one; the
 * strengths and the most rows come from the settings; every decision carries the batch. New Variants (M60-M62): "Create as new
 * items" takes only a variant with an open "new product" suggestion and skips the rest with why; the barcode rule finds a code
 * stored with other leading zeros; a number out of range and a note that is not text are refused before anything is written.
 * One rule for every bulk "create as new" (M61), on New Variants and on the New products list alike: never a second item from a
 * variant an item was already made from, none under a name an item already has or two ticked rows share, none from a variant that
 * changed on the website after the computer checked it, a page heading or one that is not published.
 */
final class BulkDecisionsTest extends MappingTestCase
{
    use KeyFixtures;
    use \CW\Tests\Support\ArrivalFixtures;

    /** @var array<string, string> setting key => its stored JSON before the test */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->saved = [];
        parent::tearDown();
    }

    /** Changes a setting with the admin connection for this test only (restored in tearDown). */
    private function setting(string $key, string $json): void
    {
        $this->saved[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    private function bulk(): BulkDecisions
    {
        return new BulkDecisions(self::$db, $this->ds);
    }

    /** The row a page shows for a listing now. @return array{listing_id: int, proposal_id: ?int, map_version: int} */
    private function seen(int $listingId): array
    {
        $p = self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listingId]);
        return ['listing_id' => $listingId, 'proposal_id' => $p === null ? null : (int) $p, 'map_version' => $this->version($listingId)];
    }

    /** @return array<int, array{outcome: string, skip_code: ?string, decision_id: ?int, detail: ?string}> listing => its batch row */
    private static function batchRows(int $batchId): array
    {
        $out = [];
        foreach (self::$db->all('SELECT listing_id, outcome, skip_code, decision_id, CAST(detail AS CHAR) AS detail, seq FROM mapping_batch_row WHERE batch_id = ? ORDER BY seq',
            [$batchId]) as $r) {
            $out[(int) $r['listing_id']] = ['outcome' => (string) $r['outcome'], 'skip_code' => $r['skip_code'], 'decision_id' => $r['decision_id'] === null ? null : (int) $r['decision_id'],
                'detail' => $r['detail'], 'seq' => (int) $r['seq']];
        }
        return $out;
    }

    public function testConfirmLinksTheAllowedRowsAndSkipsHeldSampledVetoedAndStaleRowsWithWhy(): void
    {
        $this->keySetup();
        $this->setting('approvals.spot_check_size', '5');
        $lead = $this->staffUser('mapping_lead');
        // A spot check of 5 drawn from 7 strong matches: its 5 are answered by its owner only; one of the other 2 is held back.
        $pop = [];
        for ($i = 0; $i < 7; $i++) {
            $pop[] = $this->firstMatch($this->vpgItem(), 90 + $i, [], [], null, [], 100 + $i);
        }
        $sample = (new KeySample(self::$db))->create($lead, 'bk1', 5, true);
        $members = array_column($sample['members'], 'listing_id');
        $others = array_values(array_filter($pop, static fn (array $m): bool => !in_array($m['listing'], $members, true)));
        self::assertCount(2, $others);
        $held = $others[0];
        $free = $others[1];
        $text = "proposal_id,listing_id,reason\n{$held['proposal']},{$held['listing']},Pack size differs\n";
        $h = (new KeyHold(self::$db))->run($lead, 'bk1', KeyHold::parse($text), false, true);
        self::assertSame(1, $h['held_after']);
        // Made after the spot check (in no population): a vetoed one, a stale one, one whose suggestion changed, one that needs two people.
        $vetoed = $this->firstMatch($this->vpgItem(), 95, ['target_vetoes' => ['strength']]);
        $flagged = $this->firstMatch($this->vpgItem(), 95, [], [], null, ['flags' => ['two_person_confirm']]);
        $stale = $this->firstMatch($this->vpgItem(), 95);
        $moved = $this->firstMatch($this->vpgItem(), 95);
        $pack = $this->firstMatch($this->vpgItem(), 95, [], ['units_per_item' => 2]);
        $protectedSku = $this->item('strict');
        $protected = $this->firstMatch($protectedSku, 95);

        $rows = [];
        foreach ([$free, $held, $vetoed, $flagged, $stale, $moved, $pack, $protected] as $m) {
            $rows[] = $this->seen($m['listing']);
        }
        $rows[] = $this->seen($members[0]);
        // After the page was drawn: the website renames one; a new computer check replaces another's suggestion.
        $this->rename($stale['listing'], 'Renamed on the website 10mg');
        $this->propose($moved['listing'], 'Key', $moved['sku'], [], false, 'later-run');

        $mapper = $this->staffUser('mapper'); // a person who may confirm one match may confirm many (M49)
        $before = $this->decisions();
        $r = $this->bulk()->run($mapper, 'link', array_reverse($rows), ['source' => 'review', 'band' => 'Key']);
        self::assertSame(['done' => 1, 'pending' => 2, 'skipped' => 6], array_intersect_key($r, ['done' => 0, 'pending' => 0, 'skipped' => 0]));
        $b = self::batchRows($r['batch_id']);
        self::assertSame('done', $b[$free['listing']]['outcome']);
        self::assertSame(['mapped', $free['sku'], 1], [$this->link($free['listing'])['status'], $this->link($free['listing'])['sku_id'], $this->link($free['listing'])['units_per_item']]);
        self::assertSame('held', $b[$held['listing']]['skip_code']);
        self::assertStringContainsString('Pack size differs', (string) $b[$held['listing']]['detail']);
        self::assertSame('in_spot_check', $b[$members[0]]['skip_code']);
        self::assertStringContainsString('bk1', (string) $b[$members[0]]['detail']);
        self::assertSame('vetoed', $b[$vetoed['listing']]['skip_code']);
        self::assertSame(['vetoes' => ['strength']], json_decode((string) $b[$vetoed['listing']]['detail'], true));
        self::assertSame('flagged', $b[$flagged['listing']]['skip_code']);
        self::assertSame('changed', $b[$stale['listing']]['skip_code']);
        self::assertSame('suggestion_changed', $b[$moved['listing']]['skip_code']);
        self::assertSame(['pending_second', 'pending_second'], [$b[$pack['listing']]['outcome'], $b[$protected['listing']]['outcome']]);
        self::assertSame(['units_per_item'], json_decode((string) self::$db->value('SELECT needs_second FROM match_decision WHERE id = ?', [$b[$pack['listing']]['decision_id']]), true));
        self::assertSame(['protected_sku'], json_decode((string) self::$db->value('SELECT needs_second FROM match_decision WHERE id = ?', [$b[$protected['listing']]['decision_id']]), true));
        foreach ([$held, $vetoed, $flagged, $stale, $moved] as $m) {
            self::assertNotSame('mapped', $this->link($m['listing'])['status'], 'a skipped row is never changed');
        }
        self::assertSame('suggested', $this->link($members[0])['status'], 'a spot check\'s match is left to its owner');
        // Done in listing id order, whatever order the page sent; every decision of the batch carries it, in its audit row too.
        $seqs = $b;
        ksort($seqs);
        self::assertSame(range(1, 9), array_column($seqs, 'seq'));
        self::assertSame(3, $this->decisions() - $before);
        self::assertSame([$r['batch']], array_values(array_unique(self::$db->column('SELECT bulk_batch_id FROM match_decision WHERE id > (SELECT MAX(id) - 3 FROM match_decision)'))));
        self::assertSame(DecisionService::SCREEN_BATCH_PREFIX . $r['batch_id'], $r['batch']);
        foreach ([$free, $pack, $protected] as $m) {
            $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.link' AND entity_id = ? ORDER BY id DESC LIMIT 1",
                [(string) $m['listing']]), true);
            self::assertSame($r['batch'], $audit['bulk_batch_id'] ?? null, 'the audit row of a done or waiting decision names the batch');
        }
        $sum = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'mapping.bulk' AND entity_id = ?", [(string) $r['batch_id']]), true);
        self::assertEquals(['asked' => 9, 'done' => 1, 'pending_second' => 2, 'skipped' => 6], array_intersect_key($sum, ['asked' => 0, 'done' => 0, 'pending_second' => 0, 'skipped' => 0]),
            'MySQL keeps a JSON object in its own key order');
        self::assertSame('waiting', (new KeySample(self::$db))->status('bk1')['verdict'], 'the spot check is untouched');
    }

    /**
     * M64: a variant whose open suggestion was found by the check on arrival is the third kind OneAtATime answers, after held back
     * and in a spot check. While `mapping.arrival_bulk_confirm` is off, every such suggestion: the pre-checks skip the row for every
     * list and every action with the code `on_arrival`, a real run records it so and decides nothing. With the setting on, a clean
     * match is an ordinary row (a ticked confirm matches it to the item the check named); one the check wrote with a warning, a
     * "not sure" and a conflict stay one at a time, whatever the setting. The rows are told by their run alone (arrivalRuns()),
     * and with nothing found by the check nothing is read and nothing changes.
     */
    public function testAVariantFoundByTheCheckOnArrivalIsDecidedOneAtATimeUnlessTheSettingAllowsACleanMatch(): void
    {
        $this->keySetup();
        foreach (['mapping.arrival_stores' => '"alt"', 'mapping.arrival_main_store' => '"vpg"', 'mapping.arrival_bulk_confirm' => 'false'] as $key => $json) {
            $this->setting($key, $json);
        }
        $this->arrivalMain = $this->vpgSite;
        $this->arrival = new \CW\Mapping\ArrivalCheck(self::$db, $this->ds);
        $free = $this->firstMatch($this->vpgItem(), 95)['listing']; // a judged strong match: an ordinary row throughout
        self::assertSame([[], []], [OneAtATime::arrivalRuns(self::$db), OneAtATime::of(self::$db, [$free])], 'nothing found by the check: nothing to tell');

        $title = 'Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid';
        $a = $this->mainItem($title, '10mg', [self::gtin(1)]);
        $clean = $this->arrives($this->altSite, $title, '10mg', [self::gtin(1)]);
        $this->mainItem($title, '20mg', [self::gtin(2)]);
        $warned = $this->arrives($this->altSite, $title, '', [self::gtin(2)]);          // a match with a warning (no strength stated)
        $this->mainItem($title, '10mg', [self::gtin(3)]);
        $this->mainItem($title, '10mg', [self::gtin(3)]);
        $clash = $this->arrives($this->altSite, $title, '10mg', [self::gtin(3)]);        // the barcode is on two items: a conflict
        $none = $this->arrives($this->altSite, 'Nothing like it', 'Red');                // no clue: no suggestion
        $this->arrival->run(['apply' => true]);
        $ids = [$free, $clean, $warned, $clash, $none];
        sort($ids);
        $lead = $this->staffUser('mapping_lead');
        $rows = array_map(fn (int $id): array => $this->seen($id), $ids);
        $alt = (int) $this->altSite->channelId;
        $lists = [['review', 'link', ['band' => 'Check']], ['review', 'reject', ['band' => 'Check']], ['review', 'ignore', ['band' => 'Check', 'reason' => 'x']],
            ['review', 'ignore', ['band' => 'Conflict', 'reason' => 'x']], ['store', 'ignore', ['reason' => 'x']], ['store', 'send_back', []],
            ['new', 'new_item', ['channel_id' => $alt]], ['new', 'link', ['channel_id' => $alt]], ['new', 'ignore', ['channel_id' => $alt, 'reason' => 'x']],
            ['item', 'link', []], ['item', 'reject', []], ['item', 'send_back', []]];
        $agree = function (array $expect, string $when) use ($lead, $rows, $ids, $lists): void {
            $never = OneAtATime::of(self::$db, $ids);
            ksort($never);
            self::assertSame($expect, array_map(static fn (array $n): string => $n['code'], $never), $when);
            foreach ($never as $n) {
                self::assertSame([[], 0, ''], [$n['detail'], $n['sample_id'], $n['sample']], 'found on arrival: nothing to fill in, and no spot check');
            }
            foreach ($lists as [$source, $action, $more]) {
                $p = $this->bulk()->preview($lead, $action, $rows, ['source' => $source] + $more);
                foreach ($ids as $id) {
                    $row = $p['rows'][$id];
                    if (isset($never[$id])) {
                        self::assertSame(['skipped', 'on_arrival', []], [$row['outcome'], $row['code'], $row['detail']], "{$when}: {$source} {$action} row {$id}");
                    } else {
                        self::assertNotContains($row['code'], OneAtATime::KINDS, "{$when}: {$source} {$action} row {$id} is not one of them");
                    }
                }
            }
        };

        // The setting off: every suggestion of the check, whatever it says. Told by two run rows.
        $both = [$clean => 'on_arrival', $warned => 'on_arrival', $clash => 'on_arrival'];
        ksort($both);
        $agree($both, 'setting off');
        self::assertCount(2, OneAtATime::arrivalRuns(self::$db));
        self::assertContains('on_arrival', BulkDecisions::SKIPS);
        self::assertSame(['held', 'in_spot_check', 'on_arrival'], OneAtATime::KINDS);
        $decisions = $this->decisions();
        $r = $this->bulk()->run($lead, 'link', [$this->seen($clean), $this->seen($warned), $this->seen($clash), $this->seen($none)], ['source' => 'review', 'band' => 'Check']);
        $batch = self::batchRows($r['batch_id']);
        self::assertSame([0, 4], [$r['done'], $r['skipped']]);
        self::assertSame(['on_arrival', 'on_arrival', 'on_arrival', 'no_suggestion'], [$batch[$clean]['skip_code'], $batch[$warned]['skip_code'], $batch[$clash]['skip_code'], $batch[$none]['skip_code']]);
        self::assertSame([null, null, null], [$batch[$clean]['detail'], $batch[$warned]['detail'], $batch[$clash]['detail']]);
        self::assertSame(['suggested', 'suggested', 'suggested', $decisions], [$this->link($clean)['status'], $this->link($warned)['status'], $this->link($clash)['status'], $this->decisions()],
            'nothing was decided');
        // The other bulk actions too: an ignore does not take them either.
        $r = $this->bulk()->run($lead, 'ignore', [$this->seen($clean), $this->seen($clash)], ['source' => 'store', 'reason' => 'not wanted']);
        self::assertSame([0, 2], [$r['done'], $r['skipped']]);
        self::assertSame(['suggested', 'suggested'], [$this->link($clean)['status'], $this->link($clash)['status']]);

        // The setting on: the clean match is an ordinary row; the warned one and the conflict never.
        $this->setting('mapping.arrival_bulk_confirm', 'true');
        $rest = [$warned => 'on_arrival', $clash => 'on_arrival'];
        ksort($rest);
        $agree($rest, 'setting on');
        self::assertCount(1, OneAtATime::arrivalRuns(self::$db), 'only the run row of the ones with a warning');
        $p = $this->bulk()->preview($lead, 'link', $rows, ['source' => 'review', 'band' => 'Check']);
        self::assertSame(['done', null], [$p['rows'][$clean]['outcome'], $p['rows'][$clean]['code']]);
        $r = $this->bulk()->run($lead, 'link', [$this->seen($clean), $this->seen($warned), $this->seen($clash)], ['source' => 'review', 'band' => 'Check']);
        $batch = self::batchRows($r['batch_id']);
        self::assertSame([1, 2, 'done', 'on_arrival', 'on_arrival'], [$r['done'], $r['skipped'], $batch[$clean]['outcome'], $batch[$warned]['skip_code'], $batch[$clash]['skip_code']]);
        self::assertSame(['mapped', $a['sku'], 1], [$this->link($clean)['status'], $this->link($clean)['sku_id'], $this->link($clean)['units_per_item']], 'matched to the item the barcode named');
        self::assertSame(['suggested', 'suggested'], [$this->link($warned)['status'], $this->link($clash)['status']]);
        // Off again: the two that are left are as before; a run row with no open suggestion left is not named any more.
        $this->setting('mapping.arrival_bulk_confirm', 'false');
        self::assertSame($rest, array_map(static fn (array $n): string => $n['code'], OneAtATime::of(self::$db, $ids)));
        self::assertCount(1, OneAtATime::arrivalRuns(self::$db), 'the clean matches\' run row has no open suggestion any more');
    }

    /**
     * M63: OneAtATime is the ONE read of what no bulk action takes, and the pre-checks ask it. On a mixed set (free; held back; a
     * hold released again; matched since and still held; the matches of a spot check that waits; one of them held back as well;
     * the matches of a spot check that is complete; one made after the spot checks) it names exactly the rows the pre-checks skip
     * as `held` or `in_spot_check`, with the same code and detail, for every list and every action, and it still does once the
     * spot check has failed. What a batch records for such a row is that code and detail.
     */
    public function testTheOneReadOfWhatNoBulkActionTakesAgreesWithThePreChecksRowByRow(): void
    {
        $this->keySetup();
        $this->setting('approvals.spot_check_size', '5');
        $lead = $this->staffUser('mapping_lead');
        $samples = new KeySample(self::$db);
        // A first spot check, answered in full by its owner: complete. Its matches are in no spot check any more.
        for ($i = 0; $i < 5; $i++) {
            $this->firstMatch($this->vpgItem(), 95);
        }
        $complete = $samples->create($lead, 'ok1', 5, true);
        foreach ($complete['members'] as $m) {
            $this->confirm($lead, (int) $m['proposal_id']);
        }
        self::assertSame('complete', $samples->status('ok1')['verdict']);
        // A second one of 5 drawn from 9, not answered: it waits.
        $pop = [];
        for ($i = 0; $i < 9; $i++) {
            $pop[] = $this->firstMatch($this->vpgItem(), 90 + $i);
        }
        $open = $samples->create($lead, 'open1', 5, true);
        $members = array_map('intval', array_column($open['members'], 'listing_id'));
        [$held, $released, $linked, $free] = array_values(array_filter($pop, static fn (array $m): bool => !in_array($m['listing'], $members, true)));
        $file = static fn (array ...$rows): array => KeyHold::parse("proposal_id,listing_id,reason\n" . implode('', array_map(
            static fn (array $r): string => "{$r[0]['proposal']},{$r[0]['listing']},{$r[1]}\n", $rows)));
        (new KeyHold(self::$db))->run($lead, 'open1', $file([$held, 'Pack size differs'], [$released, 'Looked doubtful'], [$linked, 'Check the flavour']), false, true);
        (new KeyHold(self::$db))->run($lead, 'open1', $file([$released, 'It is fine after all']), true, true);
        $this->confirm($lead, $linked['proposal']); // decided one at a time since: matched, and its hold is still on record
        // One of the spot check's own matches is held back as well. The hold tool refuses that (sample_member); the table does
        // not, so the row is written straight in: such a variant is `held`, the first of the two that is asked.
        $both = $members[0];
        self::$db->exec("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, reason, staff_user_id, actor) VALUES (?, ?, ?, 'hold', ?, ?, ?)",
            [(int) $open['sample_id'], (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$both]), $both, 'Held as well',
                $lead->staffUserId, $lead->actor]);
        $after = $this->firstMatch($this->vpgItem(), 95); // made after both spot checks: in no population
        $ids = [...array_map('intval', array_column($complete['members'], 'listing_id')), ...array_column($pop, 'listing'), $after['listing']];
        sort($ids);

        $expect = [$held['listing'] => 'held', $linked['listing'] => 'held', $both => 'held'];
        foreach (array_slice($members, 1) as $m) {
            $expect[$m] = 'in_spot_check';
        }
        ksort($expect);
        $never = OneAtATime::of(self::$db, array_reverse($ids));
        ksort($never);
        self::assertSame($expect, array_map(static fn (array $n): string => $n['code'], $never));
        $by = (string) self::$db->value('SELECT display_name FROM staff_user WHERE id = ?', [$lead->staffUserId]);
        self::assertSame(['reason' => 'Pack size differs', 'by' => $by, 'at' => (string) self::$db->value('SELECT created_at FROM key_bulk_hold WHERE listing_id = ?', [$held['listing']])],
            $never[$held['listing']]['detail'], 'held: the hold\'s reason, who set it aside and when');
        self::assertSame(['Held as well', 'open1', (int) $open['sample_id']], [$never[$both]['detail']['reason'], $never[$both]['sample'], $never[$both]['sample_id']]);
        self::assertSame([['sample' => 'open1'], 'open1', (int) $open['sample_id']], [$never[$members[1]]['detail'], $never[$members[1]]['sample'], $never[$members[1]]['sample_id']],
            'in a spot check: its name');
        self::assertSame([], OneAtATime::of(self::$db, []));
        self::assertEquals(array_fill_keys($members, ['id' => (int) $open['sample_id'], 'name' => 'open1']), OneAtATime::spotMatchesToDecide(self::$db),
            'what a list\'s filter names: the matches still to decide of the spot check that is not complete, and none of the complete one');
        self::assertEquals(array_fill_keys($members, ['id' => (int) $open['sample_id'], 'name' => 'open1']), OneAtATime::spotChecksOf(self::$db, $ids),
            'the spot check\'s five, whether or not one of them is held back too');

        // Every list and every action: the pre-checks skip exactly these rows, with this code and detail.
        $mapper = $this->staffUser('mapper');
        $rows = array_map(fn (int $id): array => $this->seen($id), $ids);
        $alt = (int) $this->altSite->channelId;
        $agree = function (string $when) use ($mapper, $rows, $ids, $alt): void {
            $never = OneAtATime::of(self::$db, $ids);
            foreach ([['review', 'link', ['band' => 'Key']], ['review', 'reject', ['band' => 'Key']], ['review', 'ignore', ['band' => 'Key', 'reason' => 'x']],
                ['store', 'ignore', ['reason' => 'x']], ['store', 'unignore', []], ['store', 'send_back', []],
                ['new', 'new_item', ['channel_id' => $alt]], ['new', 'link', ['channel_id' => $alt]], ['new', 'ignore', ['channel_id' => $alt, 'reason' => 'x']],
                ['item', 'link', []], ['item', 'reject', []], ['item', 'send_back', []]] as [$source, $action, $more]) {
                $p = $this->bulk()->preview($mapper, $action, $rows, ['source' => $source] + $more);
                self::assertSame($ids, array_keys($p['rows']));
                foreach ($ids as $id) {
                    $row = $p['rows'][$id];
                    if (isset($never[$id])) {
                        self::assertSame(['skipped', $never[$id]['code'], $never[$id]['detail']], [$row['outcome'], $row['code'], $row['detail']], "{$when}: {$source} {$action} row {$id}");
                    } else {
                        self::assertNotContains($row['code'], OneAtATime::KINDS, "{$when}: {$source} {$action} row {$id} is not one of them");
                    }
                }
            }
        };
        $agree('while the spot check waits');
        // The owner says "not a match" to one of the spot check's matches: it has failed. Its matches still waiting stay the spot
        // check's (`in_spot_check` is asked before a confirm's "the spot check failed").
        $wrong = $members[1];
        $this->decide($lead, 'reject', $wrong, ['sku_id' => (int) self::$db->value('SELECT proposed_sku_id FROM match_proposal WHERE open_listing_id = ?', [$wrong]),
            'proposal_id' => (int) self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$wrong])]);
        self::assertSame('failed', $samples->status('open1')['verdict']);
        $rows = array_map(fn (int $id): array => $this->seen($id), $ids);
        $now = OneAtATime::of(self::$db, $ids);
        ksort($now);
        self::assertSame($expect, array_map(static fn (array $n): string => $n['code'], $now), 'a spot check that failed is not complete either');
        $agree('after the spot check failed');

        // What a batch records for such a row is the same code and detail.
        $r = $this->bulk()->run($mapper, 'link', $rows, ['source' => 'review', 'band' => 'Key']);
        $b = self::batchRows($r['batch_id']);
        foreach ($ids as $id) {
            if (isset($now[$id])) {
                self::assertSame(['skipped', $now[$id]['code']], [$b[$id]['outcome'], $b[$id]['skip_code']], "row {$id}");
                self::assertEquals($now[$id]['detail'], json_decode((string) $b[$id]['detail'], true), "row {$id}");
            } else {
                self::assertNotContains($b[$id]['skip_code'], OneAtATime::KINDS, "row {$id}");
            }
        }
        self::assertSame('mapped', $this->link($after['listing'])['status'], 'a row a bulk action can take is done');
        self::assertSame('suggested', $this->link($held['listing'])['status']);
    }

    public function testADisallowedStrengthIsRefusedEvenWhenPostedAndTheSettingDecides(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $l = $this->listing($site, 'X1', null);
        $this->propose($l, "Can't tell", $sku);
        $before = (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch');
        $e = self::refused(403, 'band_not_allowed', fn () => $this->bulk()->run($lead, 'link', [$this->seen($l)], ['source' => 'review', 'band' => "Can't tell"]));
        self::assertSame(['Key', 'Check'], $e->detail['allowed']);
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'), 'nothing is written');
        self::assertSame('suggested', $this->link($l)['status']);
        // Posted under an allowed list, a row of another strength is skipped, not confirmed.
        $r = $this->bulk()->run($lead, 'link', [$this->seen($l)], ['source' => 'review', 'band' => 'Key']);
        self::assertSame('band_not_allowed', self::batchRows($r['batch_id'])[$l]['skip_code']);
        // New product only on its own list; the owner may add a strength on the Settings page.
        self::refused(403, 'band_not_allowed', fn () => $this->bulk()->run($lead, 'new_item', [$this->seen($l)], ['source' => 'review', 'band' => 'Key']));
        $this->setting('mapping.bulk_confirm_bands', '"Key,Check,Can\'t tell"');
        self::assertSame(['Key', 'Check', "Can't tell"], BulkDecisions::confirmBands(self::$db));
        $r = $this->bulk()->run($lead, 'link', [$this->seen($l)], ['source' => 'review', 'band' => "Can't tell"]);
        self::assertSame(1, $r['done']);
        $this->setting('mapping.bulk_confirm_bands', '""');
        self::assertSame([], BulkDecisions::confirmBands(self::$db));
        self::assertFalse(BulkDecisions::offered('link', 'review', 'Key', ['mapping_lead'], []));
        // Clues disagree is a matching lead's, in bulk as one at a time.
        self::assertFalse(BulkDecisions::offered('reject', 'review', 'Conflict', ['mapper'], ['Key']));
        self::assertTrue(BulkDecisions::offered('reject', 'review', 'Conflict', ['mapping_lead'], ['Key']));
        self::refused(403, 'lead_required', fn () => $this->bulk()->run($this->staffUser('mapper'), 'reject', [$this->seen($l)], ['source' => 'review', 'band' => 'Conflict']));
        self::refused(403, 'role_not_allowed', fn () => $this->bulk()->run($this->staffUser('viewer'), 'ignore', [$this->seen($l)],
            ['source' => 'review', 'band' => 'Key', 'reason' => 'placeholder']));
        self::refused(400, 'bad_action', fn () => $this->bulk()->run($lead, 'link', [$this->seen($l)], ['source' => 'store']));
    }

    public function testTheMostRowsComeFromTheSettingAndMoreAreRefusedWhole(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $site = $this->site('alt', 'shadow');
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $l = $this->listing($site, 'M' . $i, null);
            $this->propose($l, 'Key', $this->item('legacy'));
            $rows[] = $this->seen($l);
        }
        self::assertSame(100, BulkDecisions::maxRows(self::$db), 'the default');
        $this->setting('mapping.bulk_max_rows', '2');
        $before = (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch');
        $e = self::refused(422, 'too_many_rows', fn () => $this->bulk()->run($lead, 'link', $rows, ['source' => 'review', 'band' => 'Key']));
        self::assertSame(['max' => 2, 'asked' => 3], $e->detail);
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM channel_listing WHERE status = 'mapped' AND channel_id = ?", [$site->channelId]));
        self::assertSame(2, $this->bulk()->run($lead, 'link', array_slice($rows, 0, 2), ['source' => 'review', 'band' => 'Key'])['done']);
        self::refused(422, 'nothing_ticked', fn () => $this->bulk()->run($lead, 'link', [], ['source' => 'review', 'band' => 'Key']));
    }

    public function testTheSecondOkSwitchSendsEveryConfirmToSecondApprovalAndAnotherLeadApproves(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $other = $this->staffUser('mapping_lead');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $a = $this->listing($site, 'S1', null);
        $this->propose($a, 'Key', $sku);
        $b = $this->listing($site, 'S2', null);
        $this->propose($b, 'Key', $sku);
        self::assertFalse(BulkDecisions::secondOk(self::$db), 'off by default (the owner\'s "extra approvals off")');
        $this->setting('approvals.mapping_bulk_second_ok', 'true');
        $r = $this->bulk()->run($lead, 'link', [$this->seen($a)], ['source' => 'review', 'band' => 'Key']);
        self::assertSame(['done' => 0, 'pending' => 1], ['done' => $r['done'], 'pending' => $r['pending']]);
        $d = self::$db->one('SELECT id, needs_second, bulk_batch_id FROM match_decision WHERE pending_listing_id = ?', [$a]);
        self::assertSame(['bulk'], json_decode((string) $d['needs_second'], true));
        self::assertSame($r['batch'], $d['bulk_batch_id']);
        self::refused(403, 'same_person', fn () => $this->ds->approve($lead, (int) $d['id']));
        $this->ds->approve($other, (int) $d['id']);
        self::assertSame(['mapped', $sku], [$this->link($a)['status'], $this->link($a)['sku_id']]);
        // One at a time on the listing page stays as it was.
        $this->decide($lead, 'link', $b, ['sku_id' => $sku, 'units_per_item' => 1, 'proposal_id' => $this->seen($b)['proposal_id']]);
        self::assertSame('mapped', $this->link($b)['status']);
    }

    public function testNotAMatchMarksEachSuggestionWrongAndKeepsItInItsList(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $a = $this->listing($site, 'R1', null);
        $pa = $this->propose($a, 'Check', $sku);
        $none = $this->listing($site, 'R2', null);
        $this->propose($none, "Can't tell", null);
        $r = $this->bulk()->run($lead, 'reject', [$this->seen($a), $this->seen($none)], ['source' => 'review', 'band' => 'Check']);
        self::assertSame(['done' => 1, 'skipped' => 1], ['done' => $r['done'], 'skipped' => $r['skipped']]);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject WHERE listing_id = ? AND sku_id = ?', [$a, $sku]));
        self::assertSame(['suggested', 'open'], [$this->link($a)['status'], self::proposalRow($pa)['status']], 'exactly as the single "No, wrong product"');
        self::assertSame('no_product', self::batchRows($r['batch_id'])[$none]['skip_code']);
        $again = $this->bulk()->run($lead, 'reject', [$this->seen($a)], ['source' => 'review', 'band' => 'Check']);
        self::assertSame('already_rejected', self::batchRows($again['batch_id'])[$a]['skip_code']);
        // A later confirm of a pair marked wrong needs a second person.
        $c = $this->bulk()->run($lead, 'link', [$this->seen($a)], ['source' => 'review', 'band' => 'Check']);
        self::assertSame(1, $c['pending']);
        self::assertSame(['previously_rejected'], json_decode((string) self::$db->value('SELECT needs_second FROM match_decision WHERE pending_listing_id = ?', [$a]), true));
    }

    public function testCreateAsNewProductFollowsTheSingleRulesAndSkipsABarcodeFoundElsewhere(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $a = $this->listing($site, 'N1', null);
        self::$db->exec("INSERT INTO listing_profile (listing_id, product_title, barcodes, units_30d, units_365d) VALUES (?, 'Brand new kit', JSON_ARRAY('5012345678900'), 1, 2)", [$a]);
        $this->propose($a, 'New item', null, ['proposed_new_item' => 1]);
        $b = $this->listing($site, 'N2', null);
        self::$db->exec("INSERT INTO listing_profile (listing_id, product_title, barcodes, units_30d, units_365d) VALUES (?, 'Another kit', JSON_ARRAY('5000000000017'), 1, 2)", [$b]);
        $this->propose($b, 'New item', null, ['proposed_new_item' => 1]);
        $sku = $this->item('legacy');
        self::$db->exec("INSERT INTO sku_barcode (sku_id, barcode, is_usable, units_per_scan, source) VALUES (?, '5000000000017', 1, 1, 'manual')", [$sku]);
        $r = $this->bulk()->run($mapper, 'new_item', [$this->seen($a), $this->seen($b)], ['source' => 'review', 'band' => 'New item']);
        self::assertSame(['done' => 1, 'skipped' => 1], ['done' => $r['done'], 'skipped' => $r['skipped']]);
        $l = $this->link($a);
        self::assertSame('mapped', $l['status']);
        self::assertSame(['new_item', $a], [self::$db->value('SELECT origin FROM sku WHERE id = ?', [$l['sku_id']]), (int) self::$db->value('SELECT origin_listing_id FROM sku WHERE id = ?', [$l['sku_id']])]);
        self::assertSame('Brand new kit', self::$db->value('SELECT name FROM sku WHERE id = ?', [$l['sku_id']]));
        self::assertSame('barcode_elsewhere', self::batchRows($r['batch_id'])[$b]['skip_code']);
        self::assertSame('suggested', $this->link($b)['status']);
    }

    /** A listing of $site with a profile. @param list<string> $barcodes @param array<string, mixed>|null $features */
    private function withProfile(Caller $site, string $variant, string $title, array $barcodes = [], ?array $features = null): int
    {
        $id = $this->listing($site, $variant, null);
        self::$db->exec('INSERT INTO listing_profile (listing_id, product_title, barcodes, units_30d, units_365d, features) VALUES (?, ?, ?, 0, 0, ?)',
            [$id, $title, json_encode($barcodes, JSON_THROW_ON_ERROR), $features === null ? null : json_encode($features, JSON_THROW_ON_ERROR)]);
        return $id;
    }

    /**
     * New Variants (M60, M61): a duplicate item splits one product's stock over two records and is never deleted, so the bulk seed
     * takes ONLY a variant whose open suggestion says "new product" and skips everything else, each with its reason.
     */
    public function testOnNewVariantsTheSeedTakesOnlyAVariantTheComputerFoundNewAndSkipsTheRestWithWhy(): void
    {
        $mapper = $this->staffUser('mapper');
        $lead = $this->staffUser('mapping_lead');
        $site = $this->site('alt', 'shadow');
        $other = $this->site('vbig', 'off');
        $sku = $this->item('legacy');
        $new = fn (int $l): int => $this->propose($l, 'New item', null, ['proposed_new_item' => 1]);
        // Found new by the computer: with no stored features (a variant that arrived by itself), and with features that say Published.
        $plain = $this->withProfile($site, 'S1', 'Found new, no features');
        $new($plain);
        $published = $this->withProfile($site, 'S2', 'Found new, published', ['5056168800097'], ['variant_status' => 'Published', 'is_placeholder' => false]);
        $new($published);
        // Never checked; a seed that was undone (an item was made from it: said first); sent back for matching; a suggestion marked
        // wrong and then sent back: no OPEN suggestion.
        $never = $this->withProfile($site, 'S3', 'Never checked');
        $undone = $this->withProfile($site, 'S4', 'Seeded and undone');
        $this->decide($lead, 'new_item', $undone, ['proposal_id' => $new($undone)]);
        $this->decide($lead, 'unlink', $undone, ['reason' => 'a mistake']);
        $sentBack = $this->withProfile($site, 'S5', 'Sent back');
        $this->decide($lead, 'send_back', $sentBack, ['proposal_id' => $this->propose($sentBack, 'Key', $sku)]);
        $rejected = $this->withProfile($site, 'S6', 'Marked wrong, then sent back');
        $p = $this->propose($rejected, 'Check', $sku);
        $this->decide($lead, 'reject', $rejected, ['proposal_id' => $p, 'sku_id' => $sku]);
        $this->decide($lead, 'send_back', $rejected, ['proposal_id' => $p]);
        // What the website said when the features were stored: a page heading (found new or never checked), not published.
        $heading = $this->withProfile($site, 'S7', 'A heading', [], ['is_placeholder' => true, 'placeholder_reason' => 'parent_rule', 'variant_status' => 'Published']);
        $new($heading);
        $headingToo = $this->withProfile($site, 'S8', 'A heading nobody checked', [], ['is_placeholder' => true]);
        $draft = $this->withProfile($site, 'S9', 'A draft', [], ['variant_status' => 'Draft', 'is_placeholder' => false]);
        $new($draft);
        // The computer suggests an item; could not tell; and a barcode that another variant holds with a leading zero.
        $match = $this->withProfile($site, 'S10', 'Suggested match');
        $this->propose($match, 'Check', $sku);
        $unsure = $this->withProfile($site, 'S11', 'Not sure');
        $this->propose($unsure, "Can't tell");
        $twin = $this->withProfile($site, 'S12', 'Barcode twin', ['5056168800103']);
        $new($twin);
        $this->withProfile($other, 'T1', 'The twin elsewhere', ['05056168800103']);
        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');

        $ids = [$plain, $published, $never, $undone, $sentBack, $rejected, $heading, $headingToo, $draft, $match, $unsure, $twin];
        $r = $this->bulk()->run($mapper, 'new_item', array_map($this->seen(...), $ids), ['source' => 'new', 'channel_id' => $site->channelId]);
        self::assertSame(['done' => 2, 'pending' => 0, 'skipped' => 10], ['done' => $r['done'], 'pending' => $r['pending'], 'skipped' => $r['skipped']]);
        $rows = self::batchRows($r['batch_id']);
        self::assertSame([$plain => null, $published => null, $never => 'not_checked', $undone => 'made_before', $sentBack => 'not_checked', $rejected => 'not_checked',
            $heading => 'page_heading', $headingToo => 'page_heading', $draft => 'not_published', $match => 'suggested_match', $unsure => 'not_new',
            $twin => 'barcode_elsewhere'], array_map(static fn (array $row): ?string => $row['skip_code'], $rows));
        self::assertEquals(['band' => "Can't tell"], json_decode((string) $rows[$unsure]['detail'], true), 'a suggestion that names no item and is not "new": its own reason, with its strength');
        self::assertEquals(['sku' => (string) self::$db->value('SELECT code FROM sku WHERE origin_listing_id = ?', [$undone])], json_decode((string) $rows[$undone]['detail'], true));
        self::assertSame($skus + 2, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'an item for each of the two found new, none for a skipped row');
        self::assertSame(['mapped', 'mapped'], [$this->link($plain)['status'], $this->link($published)['status']]);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM sku WHERE origin_listing_id = ?', [$undone]), 'the undone seed is not made a second time');
        self::assertEquals(['site_status' => 'Draft'], json_decode((string) $rows[$draft]['detail'], true), 'the record keeps what the website said');
        self::assertEquals(['sku' => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku])], json_decode((string) $rows[$match]['detail'], true));
        self::assertSame('new', self::$db->value('SELECT source FROM mapping_batch WHERE id = ?', [$r['batch_id']]));
        // DecisionService itself is unchanged: one at a time, a person may still make the item of a variant the computer never checked.
        self::assertSame('mapped', $this->decide($mapper, 'new_item', $never)['status']);

        // The New products list (a match-strength list) has the same guards since round 2b (M61: one rule for every bulk "create
        // as new"; before, it made its ticked rows whatever the stored features said): a draft and a page heading are skipped
        // there too. What counts as a suggestion to make stays as it was: a row without one is "nothing suggested", one of
        // another strength "not one that may be confirmed together"; `not_checked`, `suggested_match` and `not_new` are New
        // Variants' own.
        $old = $this->withProfile($site, 'S13', 'On the New products list', [], ['variant_status' => 'Draft']);
        $new($old);
        $oldHeading = $this->withProfile($site, 'S15', 'A heading on the New products list', [], ['is_placeholder' => true]);
        $new($oldHeading);
        $bare = $this->withProfile($site, 'S14', 'No suggestion on a match-strength list');
        $other = $this->withProfile($site, 'S16', 'Another strength on the New products list');
        $this->propose($other, "Can't tell");
        $withItem = $this->withProfile($site, 'S17', 'A suggested item on the New products list');
        $this->propose($withItem, 'Check', $sku);
        $good = $this->withProfile($site, 'S18', 'Found new on the New products list', [], ['variant_status' => 'Published']);
        $new($good);
        $r = $this->bulk()->run($mapper, 'new_item', array_map($this->seen(...), [$old, $oldHeading, $bare, $other, $withItem, $good]), ['source' => 'review', 'band' => 'New item']);
        self::assertSame([$old => 'not_published', $oldHeading => 'page_heading', $bare => 'no_suggestion', $other => 'band_not_allowed', $withItem => 'band_not_allowed', $good => null],
            array_map(static fn (array $row): ?string => $row['skip_code'], self::batchRows($r['batch_id'])));
        self::assertSame(['suggested', 'mapped'], [$this->link($old)['status'], $this->link($good)['status']]);
        // Store Products offers no "create as new" at all.
        self::refused(400, 'bad_action', fn () => $this->bulk()->run($mapper, 'new_item', [$this->seen($bare)], ['source' => 'store', 'channel_id' => $site->channelId]));
        // New Variants offers its three actions and nothing else; a request of it names its store.
        self::assertSame(['new_item', 'link', 'ignore'], BulkDecisions::actionsOf('new'));
        self::assertTrue(BulkDecisions::offered('new_item', 'new', null, ['mapper'], []));
        self::assertFalse(BulkDecisions::offered('link', 'new', null, ['mapper'], []), 'a confirm only while the setting allows a strength');
        self::assertFalse(BulkDecisions::offered('new_item', 'new', null, ['viewer'], ['Key']));
        self::refused(400, 'bad_action', fn () => $this->bulk()->run($mapper, 'send_back', [$this->seen($match)], ['source' => 'new', 'channel_id' => $site->channelId]));
        self::refused(400, 'bad_request', fn () => $this->bulk()->run($mapper, 'new_item', [$this->seen($draft)], ['source' => 'new']));
    }

    /** The two lists that offer a bulk "create as new": New Variants (one store's) and the New products list. @return list<array<string, mixed>> */
    private static function seedLists(Caller $site): array
    {
        return [['source' => 'new', 'channel_id' => $site->channelId], ['source' => 'review', 'band' => 'New item']];
    }

    /** @param list<int> $ids @param array<string, mixed> $context @return array<int, array<string, mixed>> the batch's rows */
    private function seed(Caller $who, array $ids, array $context): array
    {
        return self::batchRows($this->bulk()->run($who, 'new_item', array_map($this->seen(...), $ids), $context)['batch_id']);
    }

    /**
     * R1 (M61): a variant an item was already made from is never seeded again in bulk, whatever suggestion is open. A later computer
     * check compares the variant with the main store's list only, so it says "new" again of a variant whose own item it cannot see.
     */
    public function testAVariantAnItemWasAlreadyMadeFromIsNotSeededAgainWhateverSuggestionIsOpen(): void
    {
        $mapper = $this->staffUser('mapper');
        $lead = $this->staffUser('mapping_lead');
        $site = $this->site('alt', 'shadow');
        $new = fn (int $l, string $run = 'test-run'): int => $this->propose($l, 'New item', null, ['proposed_new_item' => 1], true, $run);
        foreach (self::seedLists($site) as $n => $context) {
            // Seeded, the match undone, then a fresh "new" suggestion from another run.
            $a = $this->withProfile($site, "A{$n}", "Seeded, undone, checked again {$n}");
            $new($a);
            self::assertSame([$a => null], array_map(static fn (array $row): ?string => $row['skip_code'], $this->seed($mapper, [$a], $context)));
            $first = self::$db->one('SELECT id, code FROM sku WHERE origin_listing_id = ?', [$a]);
            $this->decide($lead, 'unlink', $a, ['reason' => 'made by mistake']);
            $new($a, "later-run-{$n}");
            // The other order: the new run is imported while the variant is still matched, and the match is undone afterwards.
            $b = $this->withProfile($site, "B{$n}", "Seeded, checked again, undone {$n}");
            $new($b);
            $this->seed($mapper, [$b], $context);
            $new($b, "later-run-{$n}");
            self::assertSame('mapped', $this->link($b)['status']);
            // The undo names the suggestion the page shows (as the variant's page does) and leaves it open.
            $this->decide($lead, 'unlink', $b, ['reason' => 'made by mistake', 'proposal_id' => $this->seen($b)['proposal_id']]);
            foreach ([$a, $b] as $l) {
                self::assertSame(['suggested', 'New item', 1], [$this->link($l)['status'], self::$db->value('SELECT band FROM match_proposal WHERE open_listing_id = ?', [$l]),
                    (int) self::$db->value('SELECT proposed_new_item FROM match_proposal WHERE open_listing_id = ?', [$l])], 'it reads "new" again');
            }
            $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
            $rows = $this->seed($mapper, [$a, $b], $context);
            self::assertSame([$a => 'made_before', $b => 'made_before'], array_map(static fn (array $row): ?string => $row['skip_code'], $rows), $context['source']);
            self::assertEquals(['sku' => (string) $first['code']], json_decode((string) $rows[$a]['detail'], true), 'with the earlier item\'s CW number');
            self::assertSame($skus, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'no second item');
            self::assertSame([1, 1], [(int) self::$db->value('SELECT COUNT(*) FROM sku WHERE origin_listing_id = ?', [$a]), (int) self::$db->value('SELECT COUNT(*) FROM sku WHERE origin_listing_id = ?', [$b])]);
            self::assertSame(['suggested', 'suggested'], [$this->link($a)['status'], $this->link($b)['status']]);
        }
        // It is the first reason given, also where another would apply (here: not published), and the read is by the index.
        $c = $this->withProfile($site, 'C1', 'Seeded, undone, now a draft');
        $new($c);
        $this->seed($mapper, [$c], ['source' => 'new', 'channel_id' => $site->channelId]);
        $this->decide($lead, 'unlink', $c, ['reason' => 'made by mistake']);
        self::$db->exec("UPDATE listing_profile SET features = JSON_OBJECT('variant_status', 'Draft') WHERE listing_id = ?", [$c]);
        self::assertSame([$c => 'made_before'], array_map(static fn (array $row): ?string => $row['skip_code'], $this->seed($mapper, [$c], ['source' => 'new', 'channel_id' => $site->channelId])));
        self::assertSame([$c => (string) self::$db->value('SELECT code FROM sku WHERE origin_listing_id = ?', [$c])], BulkDecisions::madeFrom(self::$db, [$c, 999999]));
        $plan = self::$db->all('EXPLAIN SELECT /*+ INDEX(s ix_sku_origin_listing) */ s.origin_listing_id, s.id, s.code FROM sku s WHERE s.origin_listing_id IN (?, ?)', [$c, 999999]);
        self::assertSame(['range', 'ix_sku_origin_listing'], [$plan[0]['type'], $plan[0]['key']], 'one read over the ticked ids, by the index');
        // One at a time a person may still make another item of it (DecisionService is unchanged): they see what they do there.
        self::assertSame('mapped', $this->decide($mapper, 'new_item', $c)['status']);
        self::assertSame(2, (int) self::$db->value('SELECT COUNT(*) FROM sku WHERE origin_listing_id = ?', [$c]));
    }

    /**
     * R2 (M61): the name guard. A "new" suggestion means "not on the main store's list when the check ran": it never saw the items
     * made since, nor the other ticked rows. So a row whose item would get the name of an item there is (one not joined into
     * another) is skipped, and so is every row of two or more ticked rows that share a name.
     */
    public function testANameAnItemAlreadyHasOrTwoTickedRowsShareIsNotSeeded(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $other = $this->site('vbig', 'off');
        $new = fn (int $l): int => $this->propose($l, 'New item', null, ['proposed_new_item' => 1]);
        foreach (self::seedLists($other) as $n => $context) {
            // One store's variant becomes an item; the other store lists the same thing under the same name, with no barcode in common.
            $first = $this->withProfile($site, "F{$n}", "Elux Legend 3500 Blue Razz {$n}", ['5056168800' . (200 + $n)]);
            $new($first);
            self::assertSame([$first => null], array_map(static fn (array $row): ?string => $row['skip_code'], $this->seed($mapper, [$first], self::seedLists($site)[$n])));
            $code = (string) self::$db->value('SELECT code FROM sku WHERE origin_listing_id = ?', [$first]);
            self::assertSame("Elux Legend 3500 Blue Razz {$n}", self::$db->value('SELECT name FROM sku WHERE code = ?', [$code]));
            // The same name, typed with other capitals and spaces (the name an item gets is trimmed and its spaces collapsed).
            $twin = $this->withProfile($other, "T{$n}", "  ELUX legend  3500 Blue Razz {$n} ");
            $new($twin);
            // The option's title is the name when there is one, as on the item's card.
            $byOption = $this->withProfile($other, "O{$n}", 'A range page');
            self::$db->exec('UPDATE listing_profile SET variant_title = ? WHERE listing_id = ?', ["Elux Legend 3500 Blue Razz {$n}", $byOption]);
            $new($byOption);
            // Two ticked rows that share a name no item has (capitals apart), and a third of its own.
            $pairA = $this->withProfile($other, "P{$n}a", "Lost Mary BM600 Cherry Ice {$n}");
            $pairB = $this->withProfile($other, "P{$n}b", "lost mary bm600 cherry ice {$n}");
            $single = $this->withProfile($other, "P{$n}c", "Lost Mary BM600 Grape {$n}");
            // A name only an item JOINED INTO another has does not stop a seed: that item is gone from the lists.
            $joined = self::makeSku("Joined away {$n}");
            self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [(int) self::$db->value('SELECT id FROM sku WHERE code = ?', [$code]), $joined]);
            $free = $this->withProfile($other, "J{$n}", "Joined away {$n}");
            foreach ([$pairA, $pairB, $single, $free] as $l) {
                $new($l);
            }
            $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
            $rows = $this->seed($mapper, [$twin, $byOption, $pairA, $pairB, $single, $free], $context);
            self::assertSame([$twin => 'name_elsewhere', $byOption => 'name_elsewhere', $pairA => 'name_twice', $pairB => 'name_twice', $single => null, $free => null],
                array_map(static fn (array $row): ?string => $row['skip_code'], $rows), $context['source']);
            self::assertEquals(['sku' => $code, 'name' => "ELUX legend 3500 Blue Razz {$n}"], json_decode((string) $rows[$twin]['detail'], true), 'with the CW number of the item that has the name');
            self::assertEquals(['name' => "Lost Mary BM600 Cherry Ice {$n}", 'rows' => 2], json_decode((string) $rows[$pairA]['detail'], true));
            self::assertSame($skus + 2, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'an item for the two rows of their own name only');
            self::assertSame(['suggested', 'suggested', 'suggested', 'suggested', 'mapped', 'mapped'],
                array_map(fn (int $l): string => $this->link($l)['status'], [$twin, $byOption, $pairA, $pairB, $single, $free]));
            // Ticked on its own, one of the pair is made (nothing shares its name now); the other then finds that item's name.
            self::assertSame([$pairA => null], array_map(static fn (array $row): ?string => $row['skip_code'], $this->seed($mapper, [$pairA], $context)));
            self::assertSame([$pairB => 'name_elsewhere'], array_map(static fn (array $row): ?string => $row['skip_code'], $this->seed($mapper, [$pairB], $context)));
            // One at a time a person may still make it (DecisionService is unchanged): they see the name there.
            self::assertSame('mapped', $this->decide($mapper, 'new_item', $twin, ['proposal_id' => $this->seen($twin)['proposal_id']])['status']);
        }
        // The look-up is served by the index of 0026.
        $plan = self::$db->all('EXPLAIN SELECT /*+ INDEX(s ix_sku_name) */ MIN(s.code) FROM sku s WHERE s.name = ? AND s.merged_into_sku_id IS NULL', ['Lost Mary BM600 Grape 0']);
        self::assertSame(['ref', 'ix_sku_name'], [$plan[0]['type'], $plan[0]['key']]);
        self::assertSame(['name'], self::$db->column("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sku' AND INDEX_NAME = 'ix_sku_name'"));
    }

    /**
     * R3 (M61): a "new" suggestion is the computer's word on the variant as it was. (a) When the stored features give no state of
     * the website (a change of the variant's titles or barcodes wipes them), the state the suggestion recorded is used. (b) A
     * variant whose identity is no longer the one the suggestion was made on is skipped: nobody checked what it is now.
     */
    public function testAVariantThatChangedAfterTheCheckOrWasNotPublishedThenIsNotSeeded(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $new = fn (int $l, array $evidence = []): int => $this->propose($l, 'New item', null, ['proposed_new_item' => 1] + ($evidence === [] ? [] : ['evidence' => $evidence]));
        foreach (self::seedLists($site) as $n => $context) {
            // In the bin when the computer checked it (stored features and the suggestion's record both say so), then renamed.
            $binned = $this->withProfile($site, "N{$n}1", "Binned, then renamed {$n}", [], ['variant_status' => 'Bin', 'is_placeholder' => false]);
            $new($binned, ['variant_status' => 'Bin', 'title' => 'as it was']);
            // The same, where the suggestion recorded no state: all that is known is that it changed.
            $binnedOnly = $this->withProfile($site, "N{$n}2", "Binned in its features only, then renamed {$n}", [], ['variant_status' => 'Bin']);
            $new($binnedOnly);
            // Published and renamed after the check (to anything: here to the name of an item there is).
            $renamed = $this->withProfile($site, "N{$n}3", "Published, then renamed {$n}", [], ['variant_status' => 'Published']);
            $new($renamed, ['variant_status' => 'Published']);
            // The suggestion recorded "Draft"; the features were never stored (the load had no features file).
            $draft = $this->withProfile($site, "N{$n}4", "A draft by the check's record {$n}");
            $new($draft, ['variant_status' => 'Draft']);
            // Not changed: published by the features; published by the check's record only; a state that is no text says nothing.
            $same = $this->withProfile($site, "N{$n}5", "Published and unchanged {$n}", [], ['variant_status' => 'Published']);
            $new($same, ['variant_status' => 'Bin']);
            $sameByCheck = $this->withProfile($site, "N{$n}6", "Published by the check's record {$n}");
            $new($sameByCheck, ['variant_status' => 'Published']);
            $odd = $this->withProfile($site, "N{$n}7", "A state that is no text {$n}");
            $new($odd, ['variant_status' => 7]);
            // Changed, but its suggestion has no record of what it was made on (one made before such records were kept).
            $noBasis = $this->withProfile($site, "N{$n}8", "No record of the check {$n}");
            self::$db->exec('DELETE FROM match_proposal_basis WHERE proposal_id = ?', [$new($noBasis)]);
            // Before the rename the stored features say "Bin", and the seed skips both for that.
            self::assertSame([$binned => 'not_published', $binnedOnly => 'not_published'], array_map(static fn (array $row): ?string => $row['skip_code'], $this->seed($mapper, [$binned, $binnedOnly], $context)));
            $this->rename($binned, "Binned, new title {$n}");
            $this->rename($binnedOnly, "Binned in its features only, new title {$n}");
            $this->rename($renamed, (string) self::$db->value('SELECT name FROM sku WHERE id = ?', [$sku]));
            $this->rename($noBasis, "No record of the check, new title {$n}");
            foreach ([$binned, $binnedOnly, $renamed, $noBasis] as $l) {
                self::assertNull(self::$db->value('SELECT features FROM listing_profile WHERE listing_id = ?', [$l]), 'a change of the titles wipes the stored features');
                self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE open_listing_id = ?', [$l]), 'and leaves the suggestion open');
            }
            $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
            $rows = $this->seed($mapper, [$binned, $binnedOnly, $renamed, $draft, $same, $sameByCheck, $odd, $noBasis], $context);
            self::assertSame([$binned => 'not_published', $binnedOnly => 'changed_since_check', $renamed => 'changed_since_check', $draft => 'not_published', $same => null,
                $sameByCheck => null, $odd => null, $noBasis => null], array_map(static fn (array $row): ?string => $row['skip_code'], $rows), $context['source']);
            self::assertEquals(['site_status' => 'Bin'], json_decode((string) $rows[$binned]['detail'], true), 'what the check recorded');
            self::assertEquals(['site_status' => 'Draft'], json_decode((string) $rows[$draft]['detail'], true));
            self::assertSame($skus + 4, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'no item from a skipped row');
            self::assertSame(['suggested', 'suggested', 'suggested', 'suggested'], array_map(fn (int $l): string => $this->link($l)['status'], [$binned, $binnedOnly, $renamed, $draft]));
        }
        // The two questions the list asks too (so a row and its skip cannot disagree).
        self::assertSame(['Bin', 'Draft', 'Published', null, null], [BulkDecisions::siteState(null, 'Bin'), BulkDecisions::siteState('Draft', 'Bin'), BulkDecisions::siteState('null', ' Published '),
            BulkDecisions::siteState(null, 7), BulkDecisions::siteState('', null)]);
        self::assertSame([true, true, true, false, false, false], [BulkDecisions::changedSinceCheck(5, 'aa', 'bb'), BulkDecisions::changedSinceCheck(5, null, 'bb'), BulkDecisions::changedSinceCheck(5, 'aa', null),
            BulkDecisions::changedSinceCheck(5, 'aa', 'aa'), BulkDecisions::changedSinceCheck(5, null, null), BulkDecisions::changedSinceCheck(null, 'aa', 'bb')]);
    }

    /**
     * F1 (M61 point 7): the record of what a suggestion was made on dates from its IMPORT; the run that judged the variant was
     * exported earlier. A variant renamed in between has a record on its new identity, so the title the computer judged (the
     * suggestion's evidence) is compared with the title now as well: a suggestion made on another title is not seeded.
     */
    public function testAVariantRenamedBetweenTheChecksExportAndItsImportIsNotSeeded(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $new = fn (int $l, array $evidence = []): int => $this->propose($l, 'New item', null, ['proposed_new_item' => 1] + ($evidence === [] ? [] : ['evidence' => $evidence]));
        foreach (self::seedLists($site) as $n => $context) {
            // The profile already holds the new title when the suggestion is imported: its record is on the identity of now.
            $renamed = $this->withProfile($site, "E{$n}1", "Lost Mary BM600 Blueberry 20mg {$n}");
            $p = $new($renamed, ['title' => "Elux Legend 3500 10mg {$n}", 'variant_status' => 'Published']);
            self::assertSame([null, null], [self::$db->value('SELECT identity_hash FROM match_proposal_basis WHERE proposal_id = ?', [$p]),
                self::$db->value('SELECT identity_hash FROM listing_profile WHERE listing_id = ?', [$renamed])], 'the record and the profile agree: only the title tells');
            // The option's own title is the one compared when the variant has one (as the computer was shown it).
            $option = $this->withProfile($site, "E{$n}2", 'A range page');
            self::$db->exec('UPDATE listing_profile SET variant_title = ? WHERE listing_id = ?', ["Blueberry Ice 20mg {$n}", $option]);
            $new($option, ['title' => 'A range page']);
            // The same title, typed with other capitals and spaces: not a change. The option's title judged: not a change.
            $same = $this->withProfile($site, "E{$n}3", "Hayati Pro Max Cola {$n}");
            $new($same, ['title' => "  HAYATI pro  max cola {$n} "]);
            $sameOption = $this->withProfile($site, "E{$n}4", 'Another range page');
            self::$db->exec('UPDATE listing_profile SET variant_title = ? WHERE listing_id = ?', ["Peach Ice 20mg {$n}", $sameOption]);
            $new($sameOption, ['title' => "Peach Ice 20mg {$n}"]);
            // No title in the evidence, an empty one, one that is no text: not known, so nothing is skipped for it.
            $none = $this->withProfile($site, "E{$n}5", "No title was recorded {$n}");
            $new($none, ['variant_status' => 'Published']);
            $empty = $this->withProfile($site, "E{$n}6", "An empty title was recorded {$n}");
            $new($empty, ['title' => '   ']);
            $odd = $this->withProfile($site, "E{$n}7", "A title that is no text was recorded {$n}");
            $new($odd, ['title' => 7]);
            $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
            $rows = $this->seed($mapper, [$renamed, $option, $same, $sameOption, $none, $empty, $odd], $context);
            self::assertSame([$renamed => 'changed_since_check', $option => 'changed_since_check', $same => null, $sameOption => null, $none => null, $empty => null, $odd => null],
                array_map(static fn (array $row): ?string => $row['skip_code'], $rows), $context['source']);
            self::assertSame([$skus + 5, 'suggested', 'suggested'], [(int) self::$db->value('SELECT COUNT(*) FROM sku'), $this->link($renamed)['status'], $this->link($option)['status']],
                'no item from a variant the computer judged under another title');
            // One at a time a person may still make it: they see the title there.
            self::assertSame('mapped', $this->decide($mapper, 'new_item', $renamed, ['proposal_id' => $p])['status']);
        }
        // The one question, as the list asks it too: the record's identity, or the judged title.
        self::assertSame([true, true, false, false, false, false, true], [
            BulkDecisions::changedSinceCheck(null, null, null, 'Old title', 'New title', null),
            BulkDecisions::changedSinceCheck(5, 'aa', 'aa', 'Old title', 'A page', 'New option title'),
            BulkDecisions::changedSinceCheck(5, 'aa', 'aa', 'new OPTION  title', 'A page', 'New option title'),
            BulkDecisions::changedSinceCheck(5, 'aa', 'aa', null, 'New title', null),
            BulkDecisions::changedSinceCheck(5, 'aa', 'aa', '  ', 'New title', null),
            BulkDecisions::changedSinceCheck(null, null, null, 7, 'New title', null),
            BulkDecisions::changedSinceCheck(5, 'aa', 'bb', 'Same title', 'Same title', null),
        ]);
    }

    /**
     * F2 (M61): the name guard is read once before the first row, and again for each "create as new" just before its decision.
     * An item of the row's name made in between (an earlier row of the same run whose name differs by an accent only, another
     * person's create, an approval) is found by the second read: the row is skipped with that item's CW number.
     */
    public function testTheNameOfACreateIsReadAgainJustBeforeItsDecision(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $new = fn (int $l): int => $this->propose($l, 'New item', null, ['proposed_new_item' => 1]);
        foreach (self::seedLists($site) as $n => $context) {
            // Two ticked rows whose names differ by accents only: not a pair to `name_twice` (compared without letter case), but
            // one name to the item table. The first is made; at the second's turn its name is an item's.
            $first = $this->withProfile($site, "Q{$n}1", "Café Crème Tobacco 20mg {$n}");
            $second = $this->withProfile($site, "Q{$n}2", "Cafe Creme Tobacco 20mg {$n}");
            $other = $this->withProfile($site, "Q{$n}3", "Menthol Tobacco 20mg {$n}");
            foreach ([$first, $second, $other] as $l) {
                $new($l);
            }
            $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
            $rows = $this->seed($mapper, [$first, $second, $other], $context);
            self::assertSame([$first => null, $second => 'name_elsewhere', $other => null], array_map(static fn (array $row): ?string => $row['skip_code'], $rows), $context['source']);
            self::assertEquals(['sku' => (string) self::$db->value('SELECT code FROM sku WHERE origin_listing_id = ?', [$first]), 'name' => "Cafe Creme Tobacco 20mg {$n}"],
                json_decode((string) $rows[$second]['detail'], true), 'with the CW number of the item the first row made');
            self::assertSame([$skus + 2, 'suggested'], [(int) self::$db->value('SELECT COUNT(*) FROM sku'), $this->link($second)['status']]);
        }
        // The same with an item someone else makes between the pre-checks and the row's turn. The two private steps are driven
        // one after the other (docs/dev.md: a race made deterministic without a hook in the code).
        $bulk = $this->bulk();
        $prechecks = new \ReflectionMethod($bulk, 'prechecks');
        $one = new \ReflectionMethod($bulk, 'one');
        $batch = static fn (): int => self::$db->transaction(static fn (\CW\Db $db): int => $db->insert(
            "INSERT INTO mapping_batch (action, source, channel_id, band, rows_asked, staff_user_id, actor) VALUES ('new_item', 'new', ?, NULL, 2, ?, ?)",
            [$site->channelId, $mapper->staffUserId, $mapper->actor]));
        $raced = $this->withProfile($site, 'Q91', 'Made by someone else meanwhile');
        $free = $this->withProfile($site, 'Q92', 'Made by nobody meanwhile');
        $new($raced);
        $new($free);
        $pre = $prechecks->invoke($bulk, 'new_item', 'new', [$this->seen($raced), $this->seen($free)], $site->channelId, BulkDecisions::confirmBands(self::$db));
        self::assertSame([['', 'Made by someone else meanwhile'], ['', 'Made by nobody meanwhile']], [[$pre[$raced]['code'], $pre[$raced]['name']], [$pre[$free]['code'], $pre[$free]['name']]],
            'the pre-checks pass both, and carry each one\'s name to its turn');
        $theirs = self::makeSku('MADE by someone else meanwhile');
        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $b = $batch();
        self::assertSame(['skipped', 'name_elsewhere'], $one->invoke($bulk, $mapper, 'new_item', $this->seen($raced), 1, $b, DecisionService::SCREEN_BATCH_PREFIX . $b, null, $pre[$raced]));
        self::assertSame(['done', null], $one->invoke($bulk, $mapper, 'new_item', $this->seen($free), 2, $b, DecisionService::SCREEN_BATCH_PREFIX . $b, null, $pre[$free]));
        $rows = self::batchRows($b);
        self::assertSame(['skipped', 'name_elsewhere', null], [$rows[$raced]['outcome'], $rows[$raced]['skip_code'], $rows[$raced]['decision_id']]);
        self::assertEquals(['sku' => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$theirs]), 'name' => 'Made by someone else meanwhile'], json_decode((string) $rows[$raced]['detail'], true));
        self::assertSame([$skus + 1, 'suggested', 'mapped', 0], [(int) self::$db->value('SELECT COUNT(*) FROM sku'), $this->link($raced)['status'], $this->link($free)['status'],
            (int) self::$db->value('SELECT COUNT(*) FROM sku WHERE origin_listing_id = ?', [$raced])], 'no second item of the name; the other row is made');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE open_listing_id = ?', [$raced]), 'its suggestion is still open');
        // Only a "create as new" reads a name: a confirm of a variant whose name an item has is decided as before.
        $sku = $this->item('legacy', 0, 'A name an item has');
        $match = $this->withProfile($site, 'Q93', 'A name an item has');
        $this->propose($match, 'Key', $sku);
        self::assertSame(1, $bulk->run($mapper, 'link', [$this->seen($match)], ['source' => 'new', 'channel_id' => $site->channelId])['done']);
    }

    /**
     * F3 (M61 point 6): a Strong or Likely match that names no item (the import stores it so when the main-store variant the
     * computer matched has no item) is not "one the computer could not decide": on New Variants it has its own reason.
     */
    public function testAMatchThatNamesNoItemHasItsOwnReasonOnNewVariants(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $strong = $this->withProfile($site, 'W1', 'Matched strongly to a variant with no item');
        $this->propose($strong, 'Key', null, ['flags' => ['target_not_minted']]);
        // The strength is what is asked, not the flag: one stored without it reads the same.
        $likely = $this->withProfile($site, 'W2', 'Matched, likely, to a variant with no item');
        $this->propose($likely, 'Check', null);
        $unsure = $this->withProfile($site, 'W3', 'The computer could not tell');
        $this->propose($unsure, "Can't tell", null);
        $clash = $this->withProfile($site, 'W4', 'The clues disagree');
        $this->propose($clash, 'Conflict', null);
        $manual = $this->withProfile($site, 'W5', 'Left to a person');
        $this->propose($manual, 'Manual', null);
        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $rows = $this->seed($mapper, [$strong, $likely, $unsure, $clash, $manual], ['source' => 'new', 'channel_id' => $site->channelId]);
        self::assertSame([$strong => 'match_no_item', $likely => 'match_no_item', $unsure => 'not_new', $clash => 'not_new', $manual => 'not_new'],
            array_map(static fn (array $row): ?string => $row['skip_code'], $rows));
        self::assertEquals([['band' => 'Key'], ['band' => 'Check']], [json_decode((string) $rows[$strong]['detail'], true), json_decode((string) $rows[$likely]['detail'], true)]);
        self::assertSame([$skus, 'suggested'], [(int) self::$db->value('SELECT COUNT(*) FROM sku'), $this->link($strong)['status']]);
        // New Variants' own reason: on the New products list such a row is "not one of this list's strengths", as before.
        $rows = $this->seed($mapper, [$strong, $unsure], ['source' => 'review', 'band' => 'New item']);
        self::assertSame([$strong => 'band_not_allowed', $unsure => 'band_not_allowed'], array_map(static fn (array $row): ?string => $row['skip_code'], $rows));
        self::assertSame([true, true, false, false, false, false], [BulkDecisions::matchNoItem('Key', null), BulkDecisions::matchNoItem('Check', null), BulkDecisions::matchNoItem('Key', 12),
            BulkDecisions::matchNoItem("Can't tell", null), BulkDecisions::matchNoItem('New item', null), BulkDecisions::matchNoItem(null, null)]);
        self::assertContains('match_no_item', BulkDecisions::SKIPS);
    }

    /** The barcode rule of a bulk "new product" (any list): a code is found on another record whichever way its leading zeros were typed. */
    public function testTheBarcodeRuleFindsACodeStoredWithOtherLeadingZerosAndLooksAtEveryBarcode(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $other = $this->site('vbig', 'off');
        $sku = $this->item('legacy');
        $new = fn (int $l): int => $this->propose($l, 'New item', null, ['proposed_new_item' => 1]);
        // The other record holds the padded form; the ticked one the short form (it was missed), and the other way round.
        $short = $this->withProfile($site, 'Z1', 'Short form here', ['5056168800110']);
        $this->withProfile($other, 'Y1', 'Padded form there', ['05056168800110']);
        $long = $this->withProfile($site, 'Z2', 'Padded form here', ['05056168800127']);
        $this->withProfile($other, 'Y2', 'Short form there', ['5056168800127']);
        // Two different paddings of one 11-digit code: neither is the other's key.
        $twelve = $this->withProfile($site, 'Z3', 'Twelve digits here', ['012345678912']);
        $this->withProfile($other, 'Y3', 'Thirteen digits there', ['0012345678912']);
        // An item's barcode, kept as its key; the ticked variant has it padded to 14.
        self::$db->exec("INSERT INTO sku_barcode (sku_id, barcode, is_usable, units_per_scan, source) VALUES (?, '5056168800134', 1, 1, 'manual')", [$sku]);
        $onItem = $this->withProfile($site, 'Z4', 'Padded, on an item', ['05056168800134']);
        // The clash is the variant's 25th barcode (in the order they are stored).
        $many = array_map(static fn (int $i): string => (string) (4000000000000 + $i), range(1, 24));
        $late = $this->withProfile($site, 'Z5', 'Twenty-five barcodes', [...$many, '5056168800141']);
        $this->withProfile($other, 'Y5', 'Holds the last one', ['5056168800141']);
        // No clash: the same digits inside a longer code are another code.
        $free = $this->withProfile($site, 'Z6', 'Its own code', ['5056168800158']);
        $this->withProfile($other, 'Y6', 'A longer code', ['15056168800158']);
        foreach ([$short, $long, $twelve, $onItem, $late, $free] as $l) {
            $new($l);
        }
        foreach ([['source' => 'new', 'channel_id' => $site->channelId], ['source' => 'review', 'band' => 'New item']] as $context) {
            $ids = $context['source'] === 'new' ? [$short, $long, $twelve, $onItem, $late] : [$short, $long, $twelve, $onItem, $late, $free];
            $r = $this->bulk()->run($mapper, 'new_item', array_map($this->seen(...), $ids), $context);
            $codes = array_map(static fn (array $row): ?string => $row['skip_code'], self::batchRows($r['batch_id']));
            self::assertSame(array_fill_keys([$short, $long, $twelve, $onItem, $late], 'barcode_elsewhere') + ($context['source'] === 'new' ? [] : [$free => null]), $codes, $context['source']);
        }
        self::assertSame('mapped', $this->link($free)['status']);
    }

    /** A number no listing or version can have, and a note that is not text, are refused whole before the batch is written. */
    public function testANumberOutOfRangeOrANoteThatIsNotTextIsRefusedBeforeAnythingIsWritten(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $a = $this->withProfile($site, 'R1', 'A real one');
        $this->propose($a, 'Key', $sku);
        $real = $this->seen($a);
        $contexts = [['source' => 'review', 'band' => 'Key'], ['source' => 'store', 'channel_id' => $site->channelId], ['source' => 'new', 'channel_id' => $site->channelId]];
        foreach ($contexts as $context) {
            $action = $context['source'] === 'review' ? 'link' : 'ignore';
            $with = $context + ['reason' => 'a note'];
            foreach ([['listing_id' => 4294967296, 'proposal_id' => null, 'map_version' => 0], ['listing_id' => 0, 'proposal_id' => null, 'map_version' => 0],
                ['listing_id' => $a, 'proposal_id' => $real['proposal_id'], 'map_version' => 9999999999], ['listing_id' => $a, 'proposal_id' => 0, 'map_version' => 0]] as $bad) {
                self::refused(400, 'bad_request', fn () => $this->bulk()->run($mapper, $action, [$real, $bad], $with));
            }
            $e = self::refused(400, 'bad_reason', fn () => $this->bulk()->run($mapper, $action, [$real], ['reason' => "not text \xFF"] + $context));
            self::assertSame('encoding', $e->detail['why'] ?? null, $context['source']);
            self::refused(400, 'bad_reason', fn () => $this->bulk()->run($mapper, $action, [$real], ['reason' => str_repeat('é', DecisionService::MAX_REASON + 1)] + $context));
        }
        self::assertSame([0, 0, 'suggested'], [(int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'), (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch_row'),
            $this->link($a)['status']], 'nothing was written');
        // The largest number there is names no listing: recorded as a skipped row, as any unknown one.
        $r = $this->bulk()->run($mapper, 'ignore', [['listing_id' => 4294967295, 'proposal_id' => null, 'map_version' => 4294967295]], ['source' => 'store', 'reason' => 'x']);
        self::assertSame('unknown_listing', self::batchRows($r['batch_id'])[4294967295]['skip_code']);
        // One at a time the same note is refused before the decision is written (what was typed, not a rule of matching).
        $before = $this->decisions();
        $e = self::refused(400, 'bad_reason', fn () => $this->decide($mapper, 'ignore', $a, ['proposal_id' => $real['proposal_id'], 'reason' => "caf\xE9"]));
        self::assertSame('encoding', $e->detail['why'] ?? null);
        self::assertSame([$before, 'suggested'], [$this->decisions(), $this->link($a)['status']]);
        self::assertSame('ignored', $this->decide($mapper, 'ignore', $a, ['proposal_id' => $real['proposal_id'], 'reason' => 'café'])['status']);
    }

    public function testIgnoreTakeOffTheIgnoredListAndSendBackForMatching(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $a = $this->listing($site, 'I1', null);
        $pa = $this->propose($a, 'Check', $sku);
        $b = $this->listing($site, 'I2', null);
        $linked = $this->listing($site, 'I3', $sku);
        $c = $this->listing($site, 'I4', null);
        $pc = $this->propose($c, "Can't tell", null);
        self::refused(422, 'reason_required', fn () => $this->bulk()->run($mapper, 'ignore', [$this->seen($a)], ['source' => 'store', 'channel_id' => $site->channelId]));
        $r = $this->bulk()->run($mapper, 'ignore', [$this->seen($a), $this->seen($b), $this->seen($linked)],
            ['source' => 'store', 'channel_id' => $site->channelId, 'reason' => 'Bundle placeholder pages']);
        self::assertSame(['done' => 2, 'skipped' => 1], ['done' => $r['done'], 'skipped' => $r['skipped']]);
        self::assertSame(['ignored', 'ignored', 'mapped'], [$this->link($a)['status'], $this->link($b)['status'], $this->link($linked)['status']]);
        self::assertSame('linked', self::batchRows($r['batch_id'])[$linked]['skip_code'], 'a matched one changes on its own page: it changes a stock link');
        self::assertSame('decided', self::proposalRow($pa)['status']);
        self::assertSame('Bundle placeholder pages', self::$db->value('SELECT reason FROM match_decision WHERE listing_id = ? ORDER BY id DESC LIMIT 1', [$a]));
        // Back off the ignored list: to the lists (a newer suggestion opened meanwhile) or not matched yet.
        $newer = $this->propose($a, 'Key', $sku, [], false, 'later-run');
        self::assertSame('ignored', $this->link($a)['status'], 'a suggestion does not move an ignored product');
        $u = $this->bulk()->run($mapper, 'unignore', [$this->seen($a), $this->seen($b), $this->seen($c)], ['source' => 'store', 'channel_id' => $site->channelId]);
        self::assertSame(['done' => 2, 'skipped' => 1], ['done' => $u['done'], 'skipped' => $u['skipped']]);
        self::assertSame(['suggested', 'unmapped'], [$this->link($a)['status'], $this->link($b)['status']]);
        self::assertSame('open', self::proposalRow($newer)['status']);
        self::assertSame('not_ignored', self::batchRows($u['batch_id'])[$c]['skip_code']);
        // Send back for matching: the suggestion is closed, the product waits for the next computer check.
        $s = $this->bulk()->run($mapper, 'send_back', [$this->seen($c), $this->seen($b), $this->seen($linked)], ['source' => 'store', 'channel_id' => $site->channelId]);
        self::assertSame(['done' => 1, 'skipped' => 2], ['done' => $s['done'], 'skipped' => $s['skipped']]);
        self::assertSame(['unmapped', 'decided'], [$this->link($c)['status'], self::proposalRow($pc)['status']]);
        $rows = self::batchRows($s['batch_id']);
        self::assertSame(['no_suggestion', 'linked'], [$rows[$b]['skip_code'], $rows[$linked]['skip_code']]);
        self::assertSame(['unignore', 'send_back'], array_values(array_unique(self::$db->column(
            "SELECT action FROM match_decision WHERE action IN ('unignore', 'send_back') ORDER BY id"))));
        // A row of another store than the page's is skipped.
        $other = $this->site('vbig', 'shadow');
        $d = $this->listing($other, 'O1', null);
        $o = $this->bulk()->run($mapper, 'ignore', [$this->seen($d)], ['source' => 'store', 'channel_id' => $site->channelId, 'reason' => 'x']);
        self::assertSame('other_store', self::batchRows($o['batch_id'])[$d]['skip_code']);
    }

    public function testTheScreensBatchesAreAnyDecidersAndEveryOtherBatchStaysALeads(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $a = $this->listing($site, 'B1', null);
        self::refused(403, 'lead_required', fn () => $this->decide($mapper, 'link', $a, ['sku_id' => $sku, 'units_per_item' => 1, 'bulk_batch_id' => 'key_bulk:x']));
        $r = $this->decide($mapper, 'link', $a, ['sku_id' => $sku, 'units_per_item' => 1, 'bulk_batch_id' => 'screen:999']);
        self::assertSame('applied', $r['state']);
        // The new decisions' own refusals.
        self::refused(409, 'not_ignored', fn () => $this->decide($mapper, 'unignore', $a));
        self::refused(409, 'already_linked', fn () => $this->decide($mapper, 'send_back', $a));
        $b = $this->listing($site, 'B2', null);
        self::refused(409, 'no_open_proposal', fn () => $this->decide($mapper, 'send_back', $b));
        $this->decide($mapper, 'ignore', $b, ['reason' => 'placeholder']);
        self::refused(409, 'not_waiting', fn () => $this->decide($mapper, 'send_back', $b));
        self::refused(400, 'bad_action', fn () => $this->ds->decide(Caller::staff((int) $mapper->staffUserId), ['action' => 'archive', 'listing_id' => $b, 'expected_map_version' => 0]));
        try {
            $this->decide($mapper, 'unignore', $b, ['units_per_item' => 2]);
            self::fail('units are for a match only');
        } catch (CwException $e) {
            self::assertSame('bad_request', $e->errorCode);
        }
    }

    // ---- Products > By Item: the list context `item` and the preview (docs/decisions.md M55-M59) ----------------------------

    /** The row By Item shows for a suggested variant: seen() and the item it is shown under. @return array<string, mixed> */
    private function shown(int $listingId, ?int $sku = null): array
    {
        return $this->seen($listingId) + ['sku_id' => $sku ?? (int) self::$db->value('SELECT proposed_sku_id FROM match_proposal WHERE open_listing_id = ?', [$listingId])];
    }

    /** Every table's rows, content and next id (a rolled-back insert would still use one up): a preview must not move any of them. @return array<string, list<mixed>> */
    private static function everything(): array
    {
        self::$db->pdo()->exec('SET SESSION information_schema_stats_expiry = 0');
        $out = [];
        foreach (self::$db->all("SELECT TABLE_NAME AS name, AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' "
            . 'ORDER BY TABLE_NAME') as $t) {
            $name = (string) $t['name'];
            $out[$name] = [(int) self::$db->value('SELECT COUNT(*) FROM ' . \CW\Db::ident($name)), self::$db->all('CHECKSUM TABLE ' . \CW\Db::ident($name))[0]['Checksum'] ?? null,
                $t['next_id']];
        }
        return $out;
    }

    /**
     * The preview is the same checks made read-only: for the rows of the first test above (a held one, one of a spot check, a
     * vetoed, a flagged, a stale one, one whose suggestion changed, one that needs two people for its units and one for its
     * protected item) and for what a confirm from By Item leaves to a person (M59: a pair marked wrong before, an item that already
     * has a variant or a waiting match on the store, several suggestions on one store) it says exactly what run() then does, row
     * by row, and it writes nothing at all.
     */
    public function testThePreviewSaysWhatRunWillDoFromTheSameChecksAndWritesNothing(): void
    {
        $this->keySetup();
        $this->setting('approvals.spot_check_size', '5');
        $lead = $this->staffUser('mapping_lead');
        $pop = [];
        for ($i = 0; $i < 7; $i++) {
            $pop[] = $this->firstMatch($this->vpgItem(), 90 + $i, [], [], null, [], 100 + $i);
        }
        $sample = (new KeySample(self::$db))->create($lead, 'bp1', 5, true);
        $members = array_column($sample['members'], 'listing_id');
        $others = array_values(array_filter($pop, static fn (array $m): bool => !in_array($m['listing'], $members, true)));
        [$held, $free] = $others;
        (new KeyHold(self::$db))->run($lead, 'bp1', KeyHold::parse("proposal_id,listing_id,reason\n{$held['proposal']},{$held['listing']},Pack size differs\n"), false, true);
        $vetoed = $this->firstMatch($this->vpgItem(), 95, ['target_vetoes' => ['strength']]);
        $flagged = $this->firstMatch($this->vpgItem(), 95, [], [], null, ['flags' => ['two_person_confirm']]);
        $stale = $this->firstMatch($this->vpgItem(), 95);
        $moved = $this->firstMatch($this->vpgItem(), 95);
        $pack = $this->firstMatch($this->vpgItem(), 95, [], ['units_per_item' => 2]);
        $protected = $this->firstMatch($this->item('strict'), 95);
        $unsure = $this->firstMatch($this->vpgItem(), 95, [], [], "Can't tell");
        $clash = $this->firstMatch($this->vpgItem(), 95, [], [], 'Conflict');
        // A pair marked wrong before, through an item that was joined into the suggested one since (the item's family).
        $family = $this->firstMatch($this->vpgItem(), 95);
        $old = $this->item('legacy');
        $this->decide($lead, 'reject', $family['listing'], ['sku_id' => $old, 'proposal_id' => $family['proposal']]);
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$family['sku'], $old]);
        // A suggestion of an item that was joined into another one.
        $joined = $this->firstMatch($this->item('legacy'), 95);
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$free['sku'], $joined['sku']]);
        // M59. An item that already has a variant on this store; one with a match waiting there; one with two suggestions there;
        // and a pair marked wrong for the item itself.
        $taken = $this->firstMatch($this->vpgItem('Taken kit 10mg'), 95);
        $this->decide($lead, 'link', $this->altListing(), ['sku_id' => $taken['sku'], 'units_per_item' => 1]);
        $queued = $this->firstMatch($this->vpgItem('Queued kit 10mg'), 95);
        self::assertSame('pending_second', $this->decide($lead, 'link', $this->altListing(), ['sku_id' => $queued['sku'], 'units_per_item' => 3])['state']);
        $twinA = $this->firstMatch($this->vpgItem('Twin kit 10mg'), 95);
        $twinB = $this->firstMatch($twinA['sku'], 95);
        $wrong = $this->firstMatch($this->vpgItem(), 95);
        $this->decide($lead, 'reject', $wrong['listing'], ['sku_id' => $wrong['sku'], 'proposal_id' => $wrong['proposal']]);

        $all = ['free' => $free, 'held' => $held, 'vetoed' => $vetoed, 'flagged' => $flagged, 'stale' => $stale, 'moved' => $moved, 'pack' => $pack, 'protected' => $protected,
            'unsure' => $unsure, 'clash' => $clash, 'family' => $family, 'joined' => $joined, 'taken' => $taken, 'queued' => $queued, 'twinA' => $twinA, 'twinB' => $twinB,
            'wrong' => $wrong];
        $rows = [];
        foreach ($all as $m) {
            $rows[] = $this->shown($m['listing']);
        }
        $rows[] = $this->shown($members[0]);
        $this->rename($stale['listing'], 'Renamed on the website 10mg');
        $this->propose($moved['listing'], 'Key', $moved['sku'], [], false, 'later-run');
        $this->setting('mapping.bulk_confirm_bands', '"Key,Check,Conflict"');

        $mapper = $this->staffUser('mapper');
        $context = ['source' => 'item'];
        $before = self::everything();
        $p = $this->bulk()->preview($mapper, 'link', array_reverse($rows), $context);
        self::assertSame($before, self::everything(), 'no row written, changed or even begun in any table: no batch, no batch row, no audit row');
        self::assertSame(['done' => 1, 'pending' => 2, 'skipped' => 15], array_intersect_key($p, ['done' => 0, 'pending' => 0, 'skipped' => 0]));
        $ids = array_keys($p['rows']);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids, 'in listing id order, the order run() does them in');
        $said = static fn (array $m): array => [$p['rows'][$m['listing']]['outcome'], $p['rows'][$m['listing']]['code'], $p['rows'][$m['listing']]['needs']];
        self::assertSame(['done', null, []], $said($free));
        self::assertSame(['pending_second', null, ['units_per_item']], $said($pack));
        self::assertSame(['pending_second', null, ['protected_sku']], $said($protected));
        // What a confirm from By Item leaves to a person (M59), each with its own reason.
        self::assertSame(['skipped', 'rejected_before', []], $said($wrong));
        self::assertSame(['skipped', 'rejected_before', []], $said($family), 'a reject of an item joined into the suggested one counts, as in DecisionService');
        self::assertSame(['skipped', 'item_on_store', []], $said($taken));
        self::assertSame(['item' => 'Taken kit 10mg', 'store' => 'ALT test site'], array_intersect_key($p['rows'][$taken['listing']]['detail'], ['item' => 1, 'store' => 1]),
            'the record names the item and the store, for the words');
        self::assertSame(['skipped', 'item_on_store', []], $said($queued), 'a match waiting for a second OK on that store counts as well');
        self::assertSame([['skipped', 'several_suggested', []], ['skipped', 'several_suggested', []]], [$said($twinA), $said($twinB)]);
        self::assertSame(2, $p['rows'][$twinA['listing']]['detail']['variants']);
        self::assertSame(['skipped', 'held', []], $said($held));
        self::assertSame('Pack size differs', $p['rows'][$held['listing']]['detail']['reason']);
        self::assertSame(['skipped', 'in_spot_check', []], [$p['rows'][$members[0]]['outcome'], $p['rows'][$members[0]]['code'], $p['rows'][$members[0]]['needs']]);
        self::assertSame(['skipped', 'vetoed', []], $said($vetoed));
        self::assertSame(['vetoes' => ['strength']], $p['rows'][$vetoed['listing']]['detail']);
        self::assertSame(['skipped', 'flagged', []], $said($flagged));
        self::assertSame(['skipped', 'changed', []], $said($stale));
        self::assertSame(['skipped', 'suggestion_changed', []], $said($moved));
        self::assertSame(['skipped', 'band_not_allowed', []], $said($unsure), 'each row\'s own strength is checked: By Item has no list strength');
        self::assertSame(['skipped', 'lead_only', []], $said($clash), 'Clues disagree is a matching lead\'s, row by row');
        self::assertSame(['skipped', 'product_joined', []], $said($joined));

        // run() with the same rows: every row ends as the preview said, with the same reason.
        $r = $this->bulk()->run($mapper, 'link', $rows, $context);
        self::assertSame(['done' => $p['done'], 'pending' => $p['pending'], 'skipped' => $p['skipped']], ['done' => $r['done'], 'pending' => $r['pending'], 'skipped' => $r['skipped']]);
        $b = self::batchRows($r['batch_id']);
        foreach ($p['rows'] as $lid => $row) {
            self::assertSame([$row['outcome'], $row['code']], [$b[$lid]['outcome'], $b[$lid]['skip_code']], "listing {$lid}");
            if ($row['outcome'] === 'pending_second') {
                self::assertSame($row['needs'], json_decode((string) self::$db->value('SELECT needs_second FROM match_decision WHERE id = ?', [$b[$lid]['decision_id']]), true), "listing {$lid}");
            }
        }
        self::assertSame(['item', null, null], array_values(self::$db->one('SELECT source, band, channel_id FROM mapping_batch WHERE id = ?', [$r['batch_id']]) ?? []));
        self::assertSame('mapped', $this->link($free['listing'])['status']);
        foreach ([$wrong, $family, $taken, $queued, $twinA, $twinB] as $m) {
            self::assertSame(['suggested', null], [$this->link($m['listing'])['status'], $this->link($m['listing'])['sku_id']], 'left for a person, not matched and not sent to Second approval');
            self::assertNull(self::$db->value('SELECT id FROM match_decision WHERE pending_listing_id = ?', [$m['listing']]));
        }
        // "Not a match" and "Send back for matching" take every one of them (M59 is the confirm's rule only).
        $back = $this->bulk()->preview($mapper, 'send_back', [$this->shown($taken['listing']), $this->shown($queued['listing']), $this->shown($twinA['listing']), $this->shown($twinB['listing']),
            $this->shown($wrong['listing'])], $context);
        self::assertSame(['done' => 5, 'pending' => 0, 'skipped' => 0], array_intersect_key($back, ['done' => 0, 'pending' => 0, 'skipped' => 0]));
        $no = $this->bulk()->preview($mapper, 'reject', [$this->shown($taken['listing']), $this->shown($twinA['listing']), $this->shown($wrong['listing'])], $context);
        self::assertSame(['done', 'done', 'skipped'], array_column($no['rows'], 'outcome'));
        self::assertSame('already_rejected', $no['rows'][$wrong['listing']]['code']);
        // On a list of Mapping the pair marked wrong still goes to Second approval, as before: the rule is By Item's.
        $list = $this->bulk()->preview($mapper, 'link', [$this->seen($wrong['listing']), $this->seen($family['listing']), $this->seen($taken['listing'])], ['source' => 'review', 'band' => 'Key']);
        self::assertSame([['pending_second', ['previously_rejected']], ['pending_second', ['previously_rejected']], ['done', []]], array_map(
            static fn (array $m): array => [$list['rows'][$m['listing']]['outcome'], $list['rows'][$m['listing']]['needs']], [$wrong, $family, $taken]));
        // A matching lead confirms Clues disagree here too (the strength is one the setting allows).
        $again = $this->bulk()->preview($lead, 'link', [$this->shown($clash['listing'])], $context);
        self::assertSame(['done', null], [$again['rows'][$clash['listing']]['outcome'], $again['rows'][$clash['listing']]['code']]);
        self::assertSame(1, $this->bulk()->run($lead, 'link', [$this->shown($clash['listing'])], $context)['done']);
    }

    public function testThePreviewKnowsTheApprovalSwitchesAndIsRefusedWholeAsRunIs(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $a = $this->listing($site, 'W1', null);
        $this->propose($a, 'Key', $sku, ['ai_units_per_item' => 4]);
        $b = $this->listing($site, 'W2', null);
        $this->propose($b, 'Check', $this->item('legacy'));
        $rows = [$this->shown($a), $this->shown($b)];
        $context = ['source' => 'item', 'channel_id' => $site->channelId];
        $needs = fn (): array => array_column($this->bulk()->preview($lead, 'link', $rows, $context)['rows'], 'needs');
        self::assertSame([['units_per_item'], []], $needs());
        // The owner's switches, read as DecisionService reads them: 1 sale is not 1 product off, the bulk second OK on.
        $this->setting('approvals.match_multiple', 'false');
        self::assertSame([[], []], $needs());
        $this->setting('approvals.mapping_bulk_second_ok', 'true');
        self::assertSame([['bulk'], ['bulk']], $needs());
        $this->setting('approvals.match_multiple', 'true');
        self::assertSame([['units_per_item', 'bulk'], ['bulk']], $needs());
        $r = $this->bulk()->run($lead, 'link', $rows, $context);
        self::assertSame(['done' => 0, 'pending' => 2], ['done' => $r['done'], 'pending' => $r['pending']]);
        self::assertSame([['units_per_item', 'bulk'], ['bulk']], array_map(static fn (string $j): array => json_decode($j, true),
            self::$db->column('SELECT needs_second FROM match_decision WHERE pending_listing_id IN (?, ?) ORDER BY listing_id', [$a, $b])));

        // The whole request is refused by the same checks, with the same codes, and nothing is written.
        $c = $this->listing($site, 'W3', null);
        $this->propose($c, 'Key', $this->item('legacy'));
        $one = [$this->shown($c)];
        $before = (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch');
        self::refused(400, 'bad_action', fn () => $this->bulk()->preview($lead, 'ignore', $one, ['source' => 'item', 'reason' => 'x']));
        self::refused(400, 'bad_action', fn () => $this->bulk()->run($lead, 'new_item', $one, ['source' => 'item']));
        self::refused(400, 'bad_action', fn () => $this->bulk()->preview($lead, 'link', $one, ['source' => 'nowhere']));
        self::refused(403, 'role_not_allowed', fn () => $this->bulk()->preview($this->staffUser('viewer'), 'link', $one, ['source' => 'item']));
        self::refused(403, 'staff_required', fn () => $this->bulk()->preview(Caller::system('job'), 'link', $one, ['source' => 'item']));
        self::refused(422, 'nothing_ticked', fn () => $this->bulk()->preview($lead, 'link', [], ['source' => 'item']));
        $this->setting('mapping.bulk_max_rows', '1');
        $e = self::refused(422, 'too_many_rows', fn () => $this->bulk()->preview($lead, 'reject', [$this->shown($c), $this->seen($a)], ['source' => 'item']));
        self::assertSame(['max' => 1, 'asked' => 2], $e->detail, 'the limit counts rows, that is variants');
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'));
        // By Item offers its three actions to whoever may decide, whatever the strengths setting says (each row is checked).
        self::assertSame(['link', 'reject', 'send_back'], BulkDecisions::ITEM_ACTIONS);
        foreach (BulkDecisions::ITEM_ACTIONS as $action) {
            self::assertTrue(BulkDecisions::offered($action, 'item', null, ['mapper'], []), $action);
            self::assertFalse(BulkDecisions::offered($action, 'item', null, ['viewer'], ['Key']), $action);
        }
        self::assertFalse(BulkDecisions::offered('ignore', 'item', null, ['mapping_lead'], ['Key']));
        self::assertFalse(BulkDecisions::offered('new_item', 'item', 'New item', ['mapping_lead'], ['Key']));
        // The lists' own previews work the same way (the method is not By Item's alone).
        $p = $this->bulk()->preview($lead, 'link', [$this->seen($c)], ['source' => 'review', 'band' => 'Key']);
        self::assertSame(['done' => 0, 'pending' => 1, 'skipped' => 0], array_intersect_key($p, ['done' => 0, 'pending' => 0, 'skipped' => 0]));
        self::refused(403, 'band_not_allowed', fn () => $this->bulk()->preview($lead, 'link', [$this->seen($c)], ['source' => 'review', 'band' => "Can't tell"]));
    }

    /**
     * Review finding of 10 Oct 2026: a row that names a number no variant or version can be (beyond INT UNSIGNED) was pre-checked as
     * an ordinary skip, and recording that skip failed in the middle of the batch (a 500, the rows before it decided, the rest never
     * run, no audit row). The whole request is refused instead, before anything is written, on every list and in the preview.
     */
    public function testARowThatNamesANumberBeyondItsColumnIsRefusedWholeBeforeAnythingIsWritten(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $a = $this->listing($site, 'X1', null);
        $this->propose($a, 'Key', $sku);
        $good = $this->seen($a);
        $before = self::everything();
        foreach ([
            'a variant number beyond its column' => ['listing_id' => 4294967296, 'proposal_id' => 1, 'map_version' => 0],
            'a far larger one' => ['listing_id' => 9999999999, 'proposal_id' => null, 'map_version' => 0],
            'a version beyond its column' => ['map_version' => 4294967296] + $good,
            'a version below nought' => ['map_version' => -1] + $good,
            'a variant number below one' => ['listing_id' => 0, 'proposal_id' => null, 'map_version' => 0],
            'a suggestion number below one' => ['proposal_id' => -5] + $good,
        ] as $what => $bad) {
            foreach ([['link', ['source' => 'review', 'band' => 'Key']], ['send_back', ['source' => 'store', 'channel_id' => $site->channelId]], ['link', ['source' => 'item']]] as [$action, $context]) {
                $e = self::refused(400, 'bad_request', fn () => $this->bulk()->run($lead, $action, [$good, $bad], $context));
                self::assertStringNotContainsString('%', $e->getMessage());
                self::refused(400, 'bad_request', fn () => $this->bulk()->preview($lead, $action, [$bad, $good], $context));
            }
            self::assertSame($before, self::everything(), "{$what}: no batch, no row, no decision: the good row before it was not done either");
        }
        // The largest numbers that fit are ordinary rows: a variant that is not there is a recorded skip, not a failure.
        $r = $this->bulk()->run($lead, 'link', [$good, ['listing_id' => 4294967295, 'proposal_id' => null, 'map_version' => 4294967295]], ['source' => 'review', 'band' => 'Key']);
        self::assertSame(['done' => 1, 'skipped' => 1], ['done' => $r['done'], 'skipped' => $r['skipped']]);
        self::assertSame('unknown_listing', self::batchRows($r['batch_id'])[4294967295]['skip_code']);
    }

    /**
     * What By Item's apply adds to a row: the item it was shown under (a suggestion that names another item is skipped, never
     * applied to something else) and whether the preview skipped it (then it is never acted on, whatever it looks like now).
     */
    public function testARowOfByItemIsOnlyEverAppliedToTheItemItWasShownUnderAndNeverWhenItsPreviewSkippedIt(): void
    {
        $mapper = $this->staffUser('mapper');
        $site = $this->site('alt', 'shadow');
        $sku = $this->item('legacy');
        $other = $this->item('legacy');
        // (one item for each variant: two suggestions of one item on one store are left to a person, M59)
        $a = $this->listing($site, 'E1', null);
        $this->propose($a, 'Key', $sku);
        $b = $this->listing($site, 'E2', null);
        $this->propose($b, 'Key', $this->item('legacy'));
        $c = $this->listing($site, 'E3', null);
        $this->propose($c, "Can't tell", $this->item('legacy'));
        $d = $this->listing($site, 'E4', null);
        $this->propose($d, 'Key', $this->item('legacy'));
        $context = ['source' => 'item'];
        // Shown under another item than the suggestion names: skipped, in the preview and by run().
        $wrong = $this->shown($a, $other);
        self::assertSame('suggestion_changed', $this->bulk()->preview($mapper, 'link', [$wrong], $context)['rows'][$a]['code']);
        foreach (['link', 'reject', 'send_back'] as $action) {
            $r = $this->bulk()->run($mapper, $action, [$wrong], $context);
            self::assertSame('suggestion_changed', self::batchRows($r['batch_id'])[$a]['skip_code'], $action);
        }
        self::assertSame(['suggested', null], [$this->link($a)['status'], $this->link($a)['sku_id']]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_reject'));
        // Rows the preview skipped travel with the apply and are recorded, never done: one whose reason still holds keeps it; one
        // that would pass now is skipped as changed (the person did not say yes to it); one that changed is skipped as changed.
        $this->setting('mapping.bulk_confirm_bands', '"Key"');
        $p = $this->bulk()->preview($mapper, 'link', [$this->shown($b), $this->shown($c), $this->shown($d)], $context);
        self::assertSame(['done', 'skipped', 'done'], array_column($p['rows'], 'outcome'));
        $rows = [$this->shown($b) + ['preview_skipped' => true], $this->shown($c) + ['preview_skipped' => true], $this->shown($d) + ['preview_skipped' => false]];
        $r = $this->bulk()->run($mapper, 'link', $rows, $context);
        self::assertSame(['done' => 1, 'skipped' => 2], ['done' => $r['done'], 'skipped' => $r['skipped']]);
        $batch = self::batchRows($r['batch_id']);
        self::assertSame(['changed', 'band_not_allowed', null], [$batch[$b]['skip_code'], $batch[$c]['skip_code'], $batch[$d]['skip_code']]);
        self::assertSame(['status' => 'suggested'], json_decode((string) $batch[$b]['detail'], true));
        self::assertSame(['suggested', 'suggested', 'mapped'], [$this->link($b)['status'], $this->link($c)['status'], $this->link($d)['status']]);
        // The mark is By Item's alone: a list's row that carries it is done as before.
        $e = $this->listing($site, 'E5', null);
        $this->propose($e, 'Key', $this->item('legacy'));
        self::assertSame(1, $this->bulk()->run($mapper, 'link', [$this->seen($e) + ['preview_skipped' => true, 'sku_id' => $other]], ['source' => 'review', 'band' => 'Key'])['done']);
    }
}
