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
