<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\CwException;
use CW\Mapping\BulkDecisions;
use CW\Mapping\DecisionService;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\MappingTestCase;

/**
 * Bulk action on ticked rows (docs/decisions.md M46-M53): row by row through DecisionService, in listing id order, each with the
 * map_version and the suggestion the page showed; the rows a person must look at one at a time are skipped with why (held back,
 * in a spot check, vetoed, flagged, changed since the page was drawn, ...); a row that needs a second person waits for one; the
 * strengths and the most rows come from the settings; every decision carries the batch.
 */
final class BulkDecisionsTest extends MappingTestCase
{
    use KeyFixtures;

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
}
