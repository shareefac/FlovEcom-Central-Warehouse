<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\CwException;
use CW\Mapping\ArrivalCheck;
use CW\Mapping\BulkDecisions;
use CW\Mapping\KeyEligibility;
use CW\Mapping\KeySample;
use CW\Mapping\Reband;
use CW\Matching\ArrivalRules;
use CW\Matching\Band;
use CW\Matching\Normalizer;
use CW\Matching\StoredBand;
use CW\Matching\Veto;
use CW\Tests\Support\ArrivalFixtures;
use CW\Tests\Support\MappingTestCase;
use CW\Tests\Support\OpWorkers;
use CW\Tests\Support\TestDb;

/**
 * The check on arrival, build 1 (docs/decisions.md M64, M65; CW\Mapping\ArrivalCheck): every outcome end to end (the suggestion, the
 * variant `suggested`, the basis with the target's link, the evidence, the flags, never an `ai` block), ONE TEST PER REFUSAL (a
 * refused variant gets no row in match_run, match_proposal or match_decision, and the only audit row of the run is its own record),
 * the second look under the lock, a variant that moved between the look and the write, the check's own suggestion looked at again,
 * a dry run that changes no table, the timer's form and its alarm, the tool as cron runs it, and the locks against a listing push.
 * Build 2a (M66): the evidence of a suggestion carries the website state when it is known, and says so when it is not; the state
 * is one more thing that must not have moved between the look and the write; and no state changes what the rules answer.
 */
final class ArrivalCheckTest extends MappingTestCase
{
    use ArrivalFixtures;
    use OpWorkers;

    private const TITLE = 'Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid';
    private Caller $main;
    private Caller $shop;
    private Caller $third;

    protected function setUp(): void
    {
        parent::setUp();
        $this->main = $this->site('vapeandgo', 'off');
        $this->shop = $this->site('electrofag', 'off');
        $this->third = $this->site('vapebig', 'off');
        $this->arrivalSetup($this->main, $this->shop);
    }

    protected function tearDown(): void
    {
        $this->stopWorkers();
        $this->arrivalTearDown();
        parent::tearDown();
    }

    /** The `website` part of a suggestion's evidence, as it comes back from the database (a JSON object's keys are stored in its own order). @return array<string, mixed> */
    private static function website(bool $known, ?string $asOf, ?string $productStatus, ?bool $heading): array
    {
        return ['as_of' => $asOf, 'known' => $known, 'heading' => $heading, 'product_status' => $productStatus];
    }

    /** What a suggestion would add to: the runs, the suggestions, the decisions, the audit rows other than a run's own record, the feed. @return list<int> */
    private static function written(): array
    {
        return [(int) self::$db->value('SELECT COUNT(*) FROM match_run'), (int) self::$db->value('SELECT COUNT(*) FROM match_proposal'),
            (int) self::$db->value('SELECT COUNT(*) FROM match_decision'), (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE action <> ?', [ArrivalCheck::AUDIT]),
            (int) self::$db->value('SELECT COUNT(*) FROM stock_change')];
    }

    private static function runRecords(): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM audit_log WHERE action = ?', [ArrivalCheck::AUDIT]);
    }

    /** An item with a barcode, and a variant of the shop that arrived with that barcode: a clean match waiting to be found. @return array{0: array<string, mixed>, 1: int} */
    private function clean(int $n): array
    {
        $item = $this->mainItem(self::TITLE, '10mg', [self::gtin($n)]);
        return [$item, $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)])];
    }

    /**
     * A real run, one variant refused: nothing but the mark and the run's own record. $leaves: what the variant's store counts it as.
     *
     * @param callable(): void $then extra assertions while the counts are still known
     */
    private function assertLeftAlone(int $listing, string $outcome, ?callable $then = null): void
    {
        $before = self::written();
        $records = self::runRecords();
        $status = $this->link($listing);
        $open = self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listing]);
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame($before, self::written(), "refused ({$outcome}): no run, no suggestion, no decision, no audit row of a suggestion, no feed row");
        self::assertSame($records + 1, self::runRecords(), 'the run itself is recorded, once');
        self::assertSame([$outcome => 1], $r['per_store']['electrofag']['left']);
        self::assertSame([0, 0, 0, null], [$r['per_store']['electrofag']['match'], $r['per_store']['electrofag']['conflict'], $r['per_store']['electrofag']['not_sure'], $r['run_id']]);
        self::assertSame($status, $this->link($listing), 'the variant is as it was');
        self::assertSame($open, self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listing]), 'its open suggestion is the one it had');
        self::assertSame(['done', $outcome], [self::arrivalMark($listing)[0], self::arrivalMark($listing)[2]], 'the profile says why it was left');
        if ($then !== null) {
            $then();
        }
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The outcomes

    public function testABarcodeOnOneItemBecomesALikelySuggestionWithItsEvidenceItsBasisAndItsRunRecord(): void
    {
        $item = $this->mainItem(self::TITLE, '10mg', [self::gtin(1)], 'bar-juice-5000-blue-razz/10mg');
        $v = $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin(1)]);
        self::assertSame(['queued', 1, null], self::arrivalMark($v), 'the intake marked it');
        self::assertSame(1, ArrivalCheck::waiting(self::$db, (int) $this->shop->channelId));
        $audit = (int) self::$db->value('SELECT COALESCE(MAX(id), 0) FROM audit_log');
        $feed = (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE listing_id = ?', [$v]);

        $r = $this->arrival->run(['apply' => true]);

        self::assertSame(['apply', false, 1, null], [$r['mode'], $r['switched_off'], $r['looked'], $r['stopped']]);
        self::assertMatchesRegularExpression('/^arrival-\d{8}T\d{6}Z$/', (string) $r['run_id']);
        self::assertSame(['waiting' => 1, 'looked' => 1, 'match' => 1, 'conflict' => 0, 'not_sure' => 0, 'kept' => 0, 'moved' => 0, 'failed' => 0, 'left' => [], 'last_id' => $v],
            $r['per_store']['electrofag']);
        self::assertSame(['electrofag'], array_keys($r['per_store']), 'only the stores of the setting are looked at');

        $p = self::arrivalOpen($v);
        self::assertNotNull($p);
        self::assertSame(['Check', 'barcode', $item['sku'], 0, null, 'open'], [$p['band'], $p['lane'], (int) $p['proposed_sku_id'], (int) $p['proposed_new_item'], $p['closest_sku_id'], $p['status']]);
        self::assertSame([null, null, null, null], [$p['ai_outcome'], $p['ai_confidence'], $p['ai_units_per_item'], $p['ai_model']], 'no AI answered');
        self::assertSame(['rules_only'], $p['flags']);
        // The run: its clean matches under source `arrival`, made by the rules, the Normalizer and the Veto, with no band version.
        self::assertSame([ArrivalCheck::SOURCE, $r['run_id'], ArrivalRules::VERSION . '/' . Normalizer::VERSION . '/' . Veto::VERSION], [$p['source'], $p['run_name'], $p['engine_version']]);
        self::assertSame('ar1.0', ArrivalRules::VERSION);
        self::assertNull(Band::versionOf((string) $p['engine_version']), 'no band version in the engine text');
        $detail = json_decode((string) $p['run_detail'], true);
        self::assertSame(['ar1.0', 'arrival', false, 'vapeandgo', ['electrofag'], false],
            [$detail['rules'], $detail['check'], $detail['warned'], $detail['main_store'], $detail['stores'], $detail['bulk_confirm']]);
        self::assertArrayNotHasKey('band_version', $detail);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_run'), 'one run row: the other is made only for a suggestion with a warning');

        // The evidence: what was read and why, the title as the Key checks compare it, the target's variant; never an `ai` block.
        $ev = $p['evidence'];
        self::assertArrayNotHasKey('ai', $ev);
        self::assertNull(StoredBand::inputs($ev), 'the evidence cannot be replayed as a judged match');
        self::assertSame(['arrival', 'ar1.0', $r['run_id'], '10mg', 'barcode', ['arrival_barcode'], [], [], [], 7],
            [$ev['check'], $ev['rules'], $ev['run_id'], $ev['title'], $ev['lane'], $ev['band_reasons'], $ev['lane_flags'], $ev['target_vetoes'], $ev['target_soft_flags'], $ev['units_365d']]);
        self::assertTrue(KeyEligibility::sameTitle($ev['title'], self::TITLE, '10mg'));
        self::assertArrayNotHasKey('variant_status', $ev, 'the website state is not known, so it is not written');
        self::assertEquals(['sku_id' => $item['sku'], 'code' => $item['code'], 'title' => '10mg', 'via' => 'barcode', 'listing_id' => $item['listing'], 'vpg_variant_id' => $item['variant']],
            $ev['lane_target']);
        $key = ltrim(self::gtin(1), '0');
        self::assertSame(['arrival_barcode', [$key], [$item['sku']], false, 0], [$ev['arrival']['reason'], $ev['arrival']['barcode']['keys'], $ev['arrival']['barcode']['items'],
            $ev['arrival']['barcode']['not_usable'], $ev['arrival']['barcode']['same_store']]);

        // The variant: suggested through DecisionService's own `suggest`, by the system, and nothing matched.
        self::assertSame(['sku_id' => null, 'units_per_item' => 1, 'status' => 'suggested', 'map_version' => 1], $this->link($v));
        $d = self::$db->all('SELECT action, state, decided_by, actor, proposal_id, sku_id FROM match_decision WHERE listing_id = ?', [$v]);
        self::assertSame([['action' => 'suggest', 'state' => 'applied', 'decided_by' => null, 'actor' => 'system:check_arrivals', 'proposal_id' => (int) $p['id'], 'sku_id' => null]], $d);
        // The basis: the identity the rules read, the item, and the link of the target's variant.
        $b = self::$db->one('SELECT * FROM match_proposal_basis WHERE proposal_id = ?', [(int) $p['id']]);
        self::assertSame([(string) self::$db->value('SELECT identity_hash FROM listing_profile WHERE listing_id = ?', [$v]), 1, $item['sku'], $item['listing'], 'mapped', $item['sku'], 1, 'recorded'],
            [$b['identity_hash'], (int) $b['map_version'], (int) $b['sku_id'], (int) $b['target_listing_id'], $b['target_status'], (int) $b['target_sku_id'], (int) $b['target_units_per_item'], $b['source']]);
        // The audit: the suggestion, its suggest, and the run's record.
        $rows = self::$db->all('SELECT action, actor, entity_type, entity_id, CAST(detail AS CHAR) AS detail FROM audit_log WHERE id > ? ORDER BY id', [$audit]);
        self::assertSame(['mapping.propose', 'mapping.suggest', 'mapping.arrival_check'], array_column($rows, 'action'));
        self::assertSame(['system:check_arrivals'], array_values(array_unique(array_column($rows, 'actor'))));
        $record = json_decode((string) $rows[2]['detail'], true);
        self::assertSame(['match_run', $r['run_id'], 'timer', $r['run_id'], 'ar1.0', 1, 1], [$rows[2]['entity_type'], $rows[2]['entity_id'], $record['mode'], $record['run_id'], $record['rules'],
            $record['looked'], $record['per_store']['electrofag']['match']]);
        self::assertSame($feed + 1, (int) self::$db->value('SELECT COUNT(*) FROM stock_change WHERE listing_id = ?', [$v]), 'the status change is on the feed');
        // The mark, and what New Variants reads above its list.
        $m = self::$db->one('SELECT arrival_state, arrival_seq, arrival_channel_id, arrival_outcome, arrival_checked_at FROM listing_profile WHERE listing_id = ?', [$v]);
        self::assertSame(['done', 1, (int) $this->shop->channelId, 'arrival_barcode'], [$m['arrival_state'], (int) $m['arrival_seq'], (int) $m['arrival_channel_id'], $m['arrival_outcome']]);
        self::assertNotNull($m['arrival_checked_at']);
        self::assertSame(0, ArrivalCheck::waiting(self::$db, (int) $this->shop->channelId));
        $last = ArrivalCheck::lastRun(self::$db);
        self::assertSame([$r['run_id'], 1], [$last['run_id'], $last['per_store']['electrofag']['match']]);
        self::assertSame([(int) $this->shop->channelId => 'electrofag'], ArrivalCheck::stores(self::$db));
        self::assertSame((int) $this->main->channelId, ArrivalCheck::mainStore(self::$db));

        // A second run finds nothing to do and records nothing.
        $again = $this->arrival->run(['apply' => true]);
        self::assertSame([0, null], [$again['looked'], $again['run_id']]);
        self::assertSame(1, self::runRecords(), 'a run that looked at nothing leaves no record');
    }

    /**
     * Every other line of the rules' table, end to end: what is written for it. Each case has its own item and barcode.
     */
    public function testEveryOutcomeOfTheRulesIsWrittenAsItsStrengthItsItemAndItsFlags(): void
    {
        $n = 100;
        $cases = [];
        // A web address copied from the main store (case and the closing slash do not count).
        $item = $this->mainItem(self::TITLE, '10mg', [], 'Bar-Juice-5000/Blue-Razz/10mg/');
        $cases['copy'] = [$this->arrives($this->shop, self::TITLE, '10mg', [], 'bar-juice-5000/blue-razz/10mg'), 'Check', 'transfer', $item['sku'], null, 'arrival_copy', ['rules_only']];
        // Both clues, the same item.
        $item = $this->mainItem(self::TITLE, '10mg', [self::gtin(++$n)], 'both/10mg');
        $cases['both'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)], 'both/10mg/'), 'Check', 'barcode', $item['sku'], null, 'arrival_barcode_and_copy',
            ['rules_only', 'transfer_agrees']];
        // A soft flag: the variant states no strength, the item has one.
        $item = $this->mainItem(self::TITLE, '20mg', [self::gtin(++$n)]);
        $cases['warned'] = [$this->arrives($this->shop, self::TITLE, '', [self::gtin($n)]), 'Check', 'barcode', $item['sku'], null, 'arrival_barcode',
            ['arrival_warned', 'rules_only', 'strength_missing']];
        // An address two main-store variants share; one has an item.
        $item = $this->mainItem(self::TITLE, '10mg', [], 'shared/page');
        $this->arrives($this->main, self::TITLE, '10mg', [], 'shared/page/');
        $cases['shared'] = [$this->arrives($this->shop, self::TITLE, '10mg', [], 'shared/page'), 'Check', 'transfer', $item['sku'], null, 'arrival_copy',
            ['arrival_warned', 'rules_only', 'transfer_resolved_by_seed']];
        // A renamed range.
        $item = $this->mainItem('Hayati Pro Max 4000 Blue Razz Lemonade Disposable', '20mg', [self::gtin(++$n)], null, 'Hayati');
        $cases['renamed'] = [$this->arrives($this->shop, 'Crystal Pro Max 4000 Blue Razz Lemonade Disposable', '20mg', [self::gtin($n)], null, 'Crystal'), "Can't tell", 'barcode', null,
            $item['sku'], 'arrival_renamed_range', null];
        // A hard veto: another strength.
        $item = $this->mainItem(self::TITLE, '20mg', [self::gtin(++$n)]);
        $cases['veto'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)]), 'Conflict', 'barcode', null, $item['sku'], 'veto_on_key',
            ['arrival_warned', 'rules_only', 'strength']];
        // The barcode says one item, the address another.
        $this->mainItem(self::TITLE, '10mg', [self::gtin(++$n)]);
        $this->mainItem(self::TITLE, '10mg', [], 'differ/page');
        $cases['differ'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)], 'differ/page'), 'Conflict', 'barcode', null, null, 'arrival_clues_differ',
            ['arrival_warned', 'barcode_transfer_disagree', 'rules_only']];
        // The barcode is on two items.
        $this->mainItem(self::TITLE, '10mg', [self::gtin(++$n)]);
        $this->mainItem(self::TITLE, '10mg', [self::gtin($n)]);
        $cases['two'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)]), 'Conflict', 'barcode', null, null, 'arrival_barcode_two_items',
            ['arrival_warned', 'gtin_on_multiple_items', 'rules_only']];
        // ... or on a barcode record marked not usable.
        $item = $this->mainItem(self::TITLE, '10mg');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source) VALUES (?, ?, 0, 1, 'test')", [ltrim(self::gtin(++$n), '0'), $item['sku']]);
        $cases['unusable'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)]), 'Conflict', 'barcode', null, null, 'arrival_barcode_two_items', null];
        // The barcode is on another variant of the same store (both are told so).
        $this->mainItem(self::TITLE, '10mg', [self::gtin(++$n)]);
        $twin = $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)]);
        $cases['twin_a'] = [$twin, 'Conflict', 'barcode', null, null, 'arrival_barcode_same_store', ['arrival_warned', 'gtin_dup_in_channel', 'rules_only']];
        $cases['twin_b'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)]), 'Conflict', 'barcode', null, null, 'arrival_barcode_same_store', null];
        // The item already has a variant of this store.
        $item = $this->mainItem(self::TITLE, '10mg', [self::gtin(++$n)]);
        $has = $this->listing($this->shop, 'HAS-1', null);
        $this->decide($this->staffUser('mapping_lead'), 'link', $has, ['sku_id' => $item['sku'], 'units_per_item' => 1]);
        $cases['taken'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)]), "Can't tell", 'barcode', null, $item['sku'], 'arrival_item_taken',
            ['arrival_warned', 'rules_only', 'same_channel_target_shared']];
        // The barcode stands for a case of ten.
        $item = $this->mainItem(self::TITLE, '10mg');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source) VALUES (?, ?, 1, 10, 'test')", [ltrim(self::gtin(++$n), '0'), $item['sku']]);
        $cases['pack'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)]), "Can't tell", 'barcode', null, $item['sku'], 'arrival_barcode_pack', ['arrival_warned', 'rules_only']];
        // The barcode is only on a variant that has no item (a third store's).
        $this->arrives($this->third, self::TITLE, '10mg', [self::gtin(++$n)]);
        $cases['no_item'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)]), "Can't tell", 'barcode', null, null, 'arrival_barcode_no_item', null];
        // The address is a main-store variant with no item; and one that two items share.
        $this->arrives($this->main, self::TITLE, '10mg', [], 'no-item/page');
        $cases['copy_no_item'] = [$this->arrives($this->shop, self::TITLE, '10mg', [], 'no-item/page'), "Can't tell", 'transfer', null, null, 'arrival_copy_no_item', null];
        $this->mainItem(self::TITLE, '10mg', [], 'several/page');
        $this->mainItem(self::TITLE, '10mg', [], 'several/page/');
        $cases['several'] = [$this->arrives($this->shop, self::TITLE, '10mg', [], 'several/page'), "Can't tell", 'transfer', null, null, 'arrival_copy_several', null];
        // An item that was joined into another: the one it is now is named, never the one that is gone.
        $kept = $this->mainItem(self::TITLE, '10mg');
        $gone = self::makeSku('Joined away');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source) VALUES (?, ?, 1, 1, 'test')", [ltrim(self::gtin(++$n), '0'), $gone]);
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$kept['sku'], $gone]);
        $cases['joined'] = [$this->arrives($this->shop, self::TITLE, '10mg', [self::gtin($n)]), 'Check', 'barcode', $kept['sku'], null, 'arrival_barcode', ['rules_only']];
        // No clue at all.
        $none = $this->arrives($this->shop, 'Something nobody has', 'Red', [self::gtin(++$n)], 'nowhere/else');

        $r = $this->arrival->run(['apply' => true]);
        self::assertSame(count($cases) + 1, $r['looked']);
        self::assertSame(['no_clue' => 1], $r['per_store']['electrofag']['left']);

        foreach ($cases as $name => [$listing, $band, $lane, $sku, $closest, $reason, $flags]) {
            $p = self::arrivalOpen($listing);
            self::assertNotNull($p, $name);
            self::assertSame([$band, $lane, $sku, $closest], [$p['band'], $p['lane'], $p['proposed_sku_id'] === null ? null : (int) $p['proposed_sku_id'],
                $p['closest_sku_id'] === null ? null : (int) $p['closest_sku_id']], $name);
            self::assertSame($reason, $p['evidence']['band_reasons'][0], $name);
            self::assertSame($reason, self::arrivalMark($listing)[2], $name);
            if ($flags !== null) {
                self::assertSame($flags, $p['flags'], $name);
            }
            // Every one says "rules only"; all but a clean match also carry the warning, and sit in the run's second row.
            $clean = $p['flags'] === ['rules_only'] || $p['flags'] === ['rules_only', 'transfer_agrees'];
            self::assertContains('rules_only', $p['flags'], $name);
            self::assertSame(!$clean, in_array('arrival_warned', $p['flags'], true), $name);
            self::assertSame($clean ? ArrivalCheck::SOURCE : ArrivalCheck::SOURCE_WARNED, $p['source'], $name);
            self::assertSame($r['run_id'], $p['run_name'], $name);
            self::assertContains($p['band'], ArrivalRules::BANDS, $name);
            self::assertSame(0, (int) $p['proposed_new_item'], $name);
            self::assertArrayNotHasKey('ai', $p['evidence'], $name);
            self::assertNull($p['ai_outcome'] ?? $p['ai_confidence'] ?? $p['ai_model'], $name);
            self::assertSame('suggested', $this->link($listing)['status'], $name);
            // A match records the link of its target's variant in its basis; an answer that names no item to match has none.
            $target = self::$db->value('SELECT target_listing_id FROM match_proposal_basis WHERE proposal_id = ?', [(int) $p['id']]);
            self::assertSame($sku !== null, $target !== null, $name);
        }
        self::assertSame(['strength'], self::arrivalOpen($cases['veto'][0])['evidence']['target_vetoes']);
        self::assertContains('line_alias_pending', self::arrivalOpen($cases['renamed'][0])['flags']);
        self::assertSame($kept['code'], self::arrivalOpen($cases['joined'][0])['evidence']['lane_target']['code']);
        // No clue: nothing suggested, the profile says so.
        self::assertNull(self::arrivalOpen($none));
        self::assertSame(['done', 1, 'no_clue'], self::arrivalMark($none));
        self::assertSame('unmapped', $this->link($none)['status']);
        // Two run rows, one id: the clean matches and the rest.
        self::assertSame([ArrivalCheck::SOURCE, ArrivalCheck::SOURCE_WARNED], array_map('strval', self::$db->column('SELECT source FROM match_run WHERE run_id = ? ORDER BY source', [$r['run_id']])));
        // Nothing but suggestions: no match, no item made, nothing ignored, no "not a match".
        self::assertSame(['suggest'], array_map('strval', self::$db->column("SELECT DISTINCT action FROM match_decision WHERE actor = 'system:check_arrivals'")));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE r.source LIKE 'arrival%' "
            . "AND (p.band NOT IN ('Check', 'Can''t tell', 'Conflict') OR p.proposed_new_item = 1 OR JSON_CONTAINS_PATH(p.evidence, 'one', '$.ai'))"));
    }

    public function testTheMainStoreGetsABarcodeAnswerAndNeverAnAddressAnswer(): void
    {
        $this->arrivalSetting('mapping.arrival_stores', '"vapeandgo,electrofag"');
        $item = $this->mainItem(self::TITLE, '10mg', [], 'main/page');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source) VALUES (?, ?, 1, 1, 'test')", [ltrim(self::gtin(1), '0'), $item['sku']]);
        // A new page of the main store with the item's barcode: the item has a variant of this store already.
        $dup = $this->arrives($this->main, self::TITLE, '10mg', [self::gtin(1)]);
        // A new page of the main store with another page's address and no barcode: its own store's addresses are no clue.
        $same = $this->arrives($this->main, self::TITLE, '10mg', [], 'main/page');
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame(['electrofag', 'vapeandgo'], array_keys($r['per_store']));
        $p = self::arrivalOpen($dup);
        self::assertSame(["Can't tell", $item['sku'], 'arrival_item_taken'], [$p['band'], (int) $p['closest_sku_id'], $p['evidence']['band_reasons'][0]]);
        self::assertNull(self::arrivalOpen($same));
        self::assertSame('no_clue', self::arrivalMark($same)[2]);
        // The item's own variant was marked by the intake too: it is matched, so it is left.
        self::assertSame(['done', 'not_open'], [self::arrivalMark($item['listing'])[0], self::arrivalMark($item['listing'])[2]]);
        // With no main store named, no address is a clue for anybody.
        $this->arrivalSetting('mapping.arrival_main_store', '""');
        $copy = $this->arrives($this->shop, self::TITLE, '10mg', [], 'main/page');
        $this->arrival->run(['apply' => true]);
        self::assertSame('no_clue', self::arrivalMark($copy)[2]);
        self::assertNull(ArrivalCheck::mainStore(self::$db));
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The refusals: one test each

    public function testAStoreThatIsNotNamedInTheSettingIsNotLookedAtAndAnApplyForItIsRefused(): void
    {
        $item = $this->mainItem(self::TITLE, '10mg', [self::gtin(1)]);
        $v = $this->arrives($this->third, self::TITLE, '10mg', [self::gtin(1)]);
        $before = self::written();
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame([0, []], [$r['looked'], array_keys(array_filter($r['per_store'], static fn (array $s): bool => $s['looked'] > 0))]);
        self::assertArrayNotHasKey('vapebig', $r['per_store']);
        $e = self::refused(400, 'store_not_switched_on', fn () => $this->arrival->run(['apply' => true, 'channel' => 'vapebig']));
        self::assertStringContainsString('vapebig', $e->getMessage());
        self::refused(400, 'store_not_switched_on', fn () => $this->arrival->run(['apply' => true, 'channel' => 'vapebig', 'backfill' => true]));
        self::assertSame($before, self::written());
        self::assertSame(0, self::runRecords(), 'nothing was looked at: no record');
        self::assertSame(['queued', 1, null], self::arrivalMark($v), 'it waits until its store is named');
        self::assertSame('unmapped', $this->link($v)['status']);
        // With no store named at all the check is switched off.
        $this->arrivalSetting('mapping.arrival_stores', '""');
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame([true, 0, []], [$r['switched_off'], $r['looked'], $r['per_store']]);
        self::assertSame([], ArrivalCheck::stores(self::$db));
        // Named: it is looked at.
        $this->arrivalSetting('mapping.arrival_stores', '"vapebig"');
        self::assertSame(1, $this->arrival->run(['apply' => true])['per_store']['vapebig']['match']);
        self::assertSame($item['sku'], (int) self::arrivalOpen($v)['proposed_sku_id']);
    }

    public function testAVariantThatIsMatchedOrIgnoredIsLeft(): void
    {
        [$item, $v] = $this->clean(1);
        $this->decide($this->staffUser('mapping_lead'), 'link', $v, ['sku_id' => $item['sku'], 'units_per_item' => 1]);
        // (a decision is history too: the state is asked first, so the outcome names it)
        $this->assertLeftAlone($v, 'not_open');
        [$item2, $w] = $this->clean(2);
        $this->decide($this->staffUser('mapper'), 'ignore', $w, ['reason' => 'not for the warehouse']);
        $this->assertLeftAlone($w, 'not_open');
        self::assertSame('ignored', $this->link($w)['status']);
    }

    public function testAVariantWithASuggestionOfAnotherRunInAnyStateIsLeft(): void
    {
        // An open suggestion of a judged run: never replaced.
        [$item, $v] = $this->clean(1);
        $judged = $this->propose($v, 'Key', $item['sku'], ['lane' => 'barcode', 'ai_outcome' => 'match', 'ai_confidence' => 96], true, 'run3-sold');
        $this->assertLeftAlone($v, 'has_history', function () use ($judged): void {
            self::assertSame('open', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$judged]), 'the judged suggestion was not superseded');
        });
        // An open suggestion of another run on a variant that still reads "unmapped" (written without the suggest).
        [$item2, $w] = $this->clean(2);
        $other = $this->propose($w, "Can't tell", null, [], false, 'by-hand');
        self::assertSame('unmapped', $this->link($w)['status']);
        $this->assertLeftAlone($w, 'has_history', function () use ($other): void {
            self::assertSame('open', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [$other]));
        });
        // A superseded one is history as well: only a variant no other run ever suggested for is the check's.
        [$item3, $x] = $this->clean(3);
        $old = $this->propose($x, "Can't tell", null, [], false, 'by-hand');
        self::$db->exec("UPDATE match_proposal SET status = 'superseded' WHERE id = ?", [$old]);
        $this->assertLeftAlone($x, 'has_history');
    }

    public function testAVariantAPersonHasDecidedOnIsLeftWhateverTheDecisionWas(): void
    {
        // Ignored and taken off the ignored list again: it reads "unmapped" with no suggestion, and it has a history.
        [$item, $v] = $this->clean(1);
        $mapper = $this->staffUser('mapper');
        $this->decide($mapper, 'ignore', $v, ['reason' => 'looked wrong']);
        $this->decide($mapper, 'unignore', $v);
        self::assertSame(['unmapped', 0], [$this->link($v)['status'], (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE listing_id = ?', [$v])]);
        $this->assertLeftAlone($v, 'has_history');
        // Matched and un-matched again.
        [$item2, $w] = $this->clean(2);
        $lead = $this->staffUser('mapping_lead');
        $this->decide($lead, 'link', $w, ['sku_id' => $item2['sku'], 'units_per_item' => 1]);
        $this->decide($lead, 'unlink', $w, ['reason' => 'wrong one']);
        self::assertSame('unmapped', $this->link($w)['status']);
        $this->assertLeftAlone($w, 'has_history');
    }

    public function testAVariantWithADecisionWaitingForASecondPersonIsLeft(): void
    {
        [$item, $v] = $this->clean(1);
        // A match to a protected item waits for a second person: the variant still reads "unmapped".
        $this->ok($this->stock->setPolicy(self::staff(), $item['sku'], 'strict', $this->key('policy')));
        $d = $this->decide($this->staffUser('mapper'), 'link', $v, ['sku_id' => $item['sku'], 'units_per_item' => 1]);
        self::assertSame(['pending_second', 'unmapped'], [$d['state'], $this->link($v)['status']]);
        $this->assertLeftAlone($v, 'has_history', function () use ($d): void {
            self::assertSame('pending_second', self::$db->value('SELECT state FROM match_decision WHERE id = ?', [(int) $d['decision_id']]), 'the waiting decision is untouched');
        });
    }

    public function testAVariantMarkedNotThisItemIsLeft(): void
    {
        [$item, $v] = $this->clean(1);
        // Only the "not this item" record (no decision row beside it): the record alone is enough.
        self::$db->exec('INSERT INTO match_reject (listing_id, sku_id, decided_by) VALUES (?, ?, ?)', [$v, $item['sku'], $this->staffUser('mapper')->staffUserId]);
        $this->assertLeftAlone($v, 'has_history');
    }

    public function testAVariantHeldBackIsLeft(): void
    {
        [$item, $v] = $this->clean(1);
        // A hold in force on this variant (named through a member of a spot check's population, as every hold is): the hold alone,
        // with no suggestion and no decision on the variant itself.
        [$sample, $member] = $this->spotCheckWithOneMember();
        self::$db->exec("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, reason, staff_user_id, actor) VALUES (?, ?, ?, 'hold', 'looked doubtful', ?, 'staff:1')",
            [$sample, $member, $v, $this->staffUser('mapping_lead')->staffUserId]);
        $this->assertLeftAlone($v, 'held');
        // Released: it is the check's again.
        $hold = (int) self::$db->value('SELECT id FROM key_bulk_hold WHERE listing_id = ?', [$v]);
        self::$db->exec("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, released_hold_id, reason, staff_user_id, actor) VALUES (?, ?, ?, 'release', ?, 'fine', ?, 'staff:1')",
            [$sample, $member, $v, $hold, $this->staffUser('mapping_lead')->staffUserId]);
        self::$db->exec("UPDATE listing_profile SET arrival_state = 'queued', arrival_seq = arrival_seq + 1 WHERE listing_id = ?", [$v]);
        self::assertSame(1, $this->arrival->run(['apply' => true])['per_store']['electrofag']['match']);
    }

    public function testAVariantOfASpotCheckIsLeftAsOneOfItsMatchesAndAsOneOfItsPopulation(): void
    {
        foreach ([3, null] as $i => $position) {
            [$item, $v] = $this->clean($i + 1);
            [$sample, $member] = $this->spotCheckWithOneMember('sc' . $i);
            // The member row names this variant (its own suggestion row belongs to another variant): the membership alone.
            self::$db->exec('UPDATE key_sample_member SET listing_id = ?, position = ? WHERE sample_id = ? AND proposal_id = ?', [$v, $position, $sample, $member]);
            $this->assertLeftAlone($v, 'in_spot_check');
            self::$db->exec("UPDATE listing_profile SET arrival_state = 'done' WHERE listing_id = ?", [$v]);
        }
    }

    public function testAVariantWithNoTitleIsLeft(): void
    {
        $item = $this->mainItem(self::TITLE, '10mg', [self::gtin(1)]);
        $v = (int) (new \CW\Mapping\ListingIngestService(self::$db))->ingest(Caller::system('test'), (int) $this->shop->channelId,
            [['variant_id' => '900001', 'barcodes' => [self::gtin(1)], 'brand' => 'Bar Juice 5000']])['listings'][0]['listing_id'];
        $this->assertLeftAlone($v, 'no_title');
    }

    /**
     * The second time: everything is asked again under the variant's lock, just before the suggestion is written. A person's
     * decision, another run's suggestion, a hold or a spot check that arrived after the look stops it there.
     */
    public function testEveryRefusalIsAskedAgainUnderTheLockJustBeforeTheSuggestionIsWritten(): void
    {
        $touch = [
            'has_history' => function (int $v, array $item): void {
                $mapper = $this->staffUser('mapper');
                $this->decide($mapper, 'ignore', $v, ['reason' => 'x']);
                $this->decide($mapper, 'unignore', $v);
            },
            'not_open' => function (int $v, array $item): void {
                $this->decide($this->staffUser('mapping_lead'), 'link', $v, ['sku_id' => $item['sku'], 'units_per_item' => 1]);
            },
            'held' => function (int $v, array $item): void {
                [$sample, $member] = $this->spotCheckWithOneMember('late');
                self::$db->exec("INSERT INTO key_bulk_hold (sample_id, proposal_id, listing_id, kind, reason, staff_user_id, actor) VALUES (?, ?, ?, 'hold', 'late', ?, 'staff:1')",
                    [$sample, $member, $v, $this->staffUser('mapping_lead')->staffUserId]);
            },
            'in_spot_check' => function (int $v, array $item): void {
                [$sample, $member] = $this->spotCheckWithOneMember('late2');
                self::$db->exec('UPDATE key_sample_member SET listing_id = ? WHERE sample_id = ? AND proposal_id = ?', [$v, $sample, $member]);
            },
        ];
        $n = 0;
        foreach ($touch as $outcome => $fn) {
            [$item, $v] = $this->clean(++$n);
            $look = $this->arrival->look($v);
            self::assertSame(['write', 'arrival_barcode'], [$look['action'], $look['outcome']], 'looked at before anything touched it');
            $fn($v, $item);
            $before = self::written();
            $status = $this->link($v);
            $open = self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$v]);
            $done = $this->arrival->write($look);
            self::assertSame(['refused', $outcome, null], [$done['result'], $done['outcome'], $done['proposal_id']]);
            self::assertSame($before, self::written(), "{$outcome}: nothing written, not even a run row");
            self::assertSame([$status, $open], [$this->link($v), self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$v])]);
            self::assertSame(['done', $outcome], [self::arrivalMark($v)[0], self::arrivalMark($v)[2]]);
        }
        // Another run's suggestion that arrived meanwhile is not superseded.
        [$item, $v] = $this->clean(++$n);
        $look = $this->arrival->look($v);
        $judged = $this->propose($v, 'Key', $item['sku'], ['lane' => 'barcode'], true, 'run9-sold');
        self::assertSame('refused', $this->arrival->write($look)['result']);
        self::assertSame([$judged, 'open'], [(int) self::arrivalOpen($v)['id'], self::arrivalOpen($v)['status']]);
    }

    public function testAVariantThatMovedBetweenTheLookAndTheWriteGetsNothingAndStaysToCheck(): void
    {
        $moves = [
            'identity' => fn (int $v) => $this->arrivesAgain($v, ['variant_title' => '20mg']),
            'address' => fn (int $v) => $this->arrivesAgain($v, ['perma_link' => 'somewhere/new']),
            'number' => fn (int $v) => self::$db->exec("UPDATE listing_profile SET arrival_state = 'queued', arrival_seq = arrival_seq + 1 WHERE listing_id = ?", [$v]),
            // The website state (M66), moved as the state-only load moves it: the columns only, the mark's number stays. A state
            // that becomes known for the first time; and one that was known and says something else now.
            'state, first known' => fn (int $v) => self::siteState($v),
            'state, changed' => fn (int $v) => self::siteState($v, ['site_variant_status' => 'Bin']),
        ];
        $n = 0;
        foreach ($moves as $what => $fn) {
            [$item, $v] = $this->clean(++$n);
            if ($what === 'state, changed') {
                self::siteState($v);
            }
            $look = $this->arrival->look($v);
            self::assertSame('write', $look['action']);
            $fn($v);
            $before = self::written();
            $done = $this->arrival->write($look);
            self::assertSame(['moved', null], [$done['result'], $done['proposal_id']], $what);
            self::assertSame($before, self::written(), "{$what}: nothing written, no run row left behind");
            self::assertSame(['queued', str_starts_with($what, 'state') ? 1 : 2, null], self::arrivalMark($v), "{$what}: still to check" . (str_starts_with($what, 'state') ? '' : ', by its new number'));
            self::assertSame('unmapped', $this->link($v)['status']);
        }
        // The same state seen again (only its date moved on) is no move: the suggestion is written.
        [$item, $v] = $this->clean(++$n);
        self::siteState($v, ['site_state_at' => '2026-09-26 09:41:52.000000']);
        $look = $this->arrival->look($v);
        self::siteState($v);
        self::assertSame('written', $this->arrival->write($look)['result'], 'a state that says the same under a newer date is what the rules ran on');
        self::assertSame('2026-09-26 09:41:52.000000', self::arrivalOpen($v)['evidence']['website']['as_of'], 'the evidence keeps the date that was read');
        // The same inside a run: the variant changes while the run is under way (here: just before it).
        [$item, $v] = $this->clean(++$n);
        $look = $this->arrival->look($v);
        // The profile changed after the lock was taken and before the basis was read: the identity the basis records is not the
        // one the rules ran on, and the whole transaction is rolled back. (Shown with a look made on an identity that is gone.)
        $stale = $look;
        $stale['snapshot']['identity_hash'] = str_repeat('0', 64);
        self::assertSame('moved', $this->arrival->write($stale)['result']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM match_proposal WHERE listing_id = ?', [$v]));
        // The next run looks at each again, with what it is now (the one written above is done).
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame(6, $r['looked']);
        self::assertSame(7, (int) self::$db->value("SELECT COUNT(*) FROM match_proposal p JOIN match_run r ON r.id = p.match_run_id WHERE p.status = 'open' AND r.source LIKE 'arrival%'"));
    }

    /**
     * The website state in a suggestion's evidence (M66): `variant_status` (a text, only when known) and `website` {known, as_of,
     * product_status, heading}. `known` is true only for a STORED state that has the variant's status, its product's status and
     * an answer for the page heading; a variant whose state is not known says so (`known` false, nothing else), and so "not known"
     * is recorded as what it is. And the state is recorded only: no state makes a new outcome or changes a rule's answer. A
     * variant in the bin, one whose product is a draft and a page heading get the same match as one on sale ("a match makes
     * nothing"); the rules' version is what it was.
     */
    public function testTheEvidenceCarriesTheWebsiteStateWhenItIsKnownAndSaysSoWhenItIsNot(): void
    {
        self::assertSame('ar1.0', ArrivalRules::VERSION, 'the rules did not change with the state');
        $at = '2026-09-26 09:41:52.000000';
        $cases = [
            'not known' => [null, [false, null, null, null], null],
            'on sale' => [['site_state_at' => $at], [true, $at, 'Published', false], 'Published'],
            'in the bin' => [['site_variant_status' => 'Bin', 'site_state_at' => $at], [true, $at, 'Published', false], 'Bin'],
            'its product a draft' => [['site_product_status' => 'Draft', 'site_state_at' => $at], [true, $at, 'Draft', false], 'Published'],
            'a landing row' => [['site_is_landing' => 1, 'site_state_at' => $at], [true, $at, 'Published', true], 'Published'],
            'the status not known' => [['site_variant_status' => null, 'site_state_at' => $at], [false, $at, 'Published', false], null],
            'the product status not known' => [['site_product_status' => null, 'site_state_at' => $at], [false, $at, null, false], 'Published'],
            'an input of the heading not known' => [['site_product_type' => null, 'site_state_at' => $at], [false, $at, 'Published', null], 'Published'],
        ];
        $n = 0;
        $made = [];
        foreach ($cases as $what => [$state, $website, $status]) {
            [$item, $v] = $this->clean(++$n);
            if ($state !== null) {
                self::siteState($v, $state);
            }
            $made[$what] = [$item, $v];
        }
        // The state as a matching run stored it in the features, with no stored state: the status is recorded as build 1 recorded
        // it, with the heading the features hold; it is not a state the warehouse "knows" (it has no date).
        [$item, $byFeatures] = $this->clean(++$n);
        self::$db->exec("UPDATE listing_profile SET features = JSON_OBJECT('variant_status', 'Draft', 'is_placeholder', false) WHERE listing_id = ?", [$byFeatures]);
        // A stored state comes first: the features are not read beside it.
        [$item, $both] = $this->clean(++$n);
        self::$db->exec("UPDATE listing_profile SET features = JSON_OBJECT('variant_status', 'Draft', 'is_placeholder', true) WHERE listing_id = ?", [$both]);
        self::siteState($both, ['site_state_at' => $at]);
        // The default row of a range with two published variants in this store: a heading by the Normalizer's rule, counted now.
        $range = $this->mainItem('Bar Juice 5000 Range', 'Blue Razz 10mg', [self::gtin(++$n)]);
        $parent = $this->arrives($this->shop, 'Bar Juice 5000 Range');
        self::$db->exec('UPDATE listing_profile SET units_30d = 0 WHERE listing_id = ?', [$parent]);
        self::siteState($parent, ['site_product_type' => 'variable', 'site_product_id' => 'range', 'site_is_default' => 1]);
        $child = $this->arrives($this->shop, 'Bar Juice 5000 Range', 'Blue Razz 10mg', [self::gtin($n)]);
        self::siteState($child, ['site_product_type' => 'variable', 'site_product_id' => 'range', 'site_is_default' => 0]);

        $r = $this->arrival->run(['apply' => true]);
        self::assertSame([count($cases) + 3, 0, 0, ['no_clue' => 1]], [$r['per_store']['electrofag']['match'], $r['per_store']['electrofag']['conflict'], $r['per_store']['electrofag']['not_sure'],
            $r['per_store']['electrofag']['left']], 'every one of them is the same clean match, whatever its state; the range page has no clue');
        foreach ($cases as $what => [$state, $website, $status]) {
            [$item, $v] = $made[$what];
            $p = self::arrivalOpen($v);
            self::assertSame(['Check', 'barcode', $item['sku'], ['rules_only'], ['arrival_barcode'], ArrivalCheck::SOURCE],
                [$p['band'], $p['lane'], (int) $p['proposed_sku_id'], $p['flags'], $p['evidence']['band_reasons'], $p['source']], "{$what}: the match is written whatever the state");
            self::assertSame(self::website(...$website), $p['evidence']['website'], $what);
            self::assertSame($status, $p['evidence']['variant_status'] ?? null, $what);
            self::assertSame($status !== null, array_key_exists('variant_status', $p['evidence']), "{$what}: the status is written only when it is known");
        }
        $ev = self::arrivalOpen($byFeatures)['evidence'];
        self::assertSame(['Draft', self::website(false, null, null, false)], [$ev['variant_status'], $ev['website']]);
        $ev = self::arrivalOpen($both)['evidence'];
        self::assertSame(['Published', self::website(true, $at, 'Published', false)], [$ev['variant_status'], $ev['website']]);
        $ev = self::arrivalOpen($child)['evidence'];
        self::assertSame([true, false], [$ev['website']['known'], $ev['website']['heading']], 'a flavour of the range is no heading');
        // The range page itself has no clue and gets no suggestion in this build, whatever its state. What the check would record of
        // it is what the seed works out (the same function): asked here of the look.
        self::assertNull(self::arrivalOpen($parent));
        self::assertSame(['done', 'no_clue'], [self::arrivalMark($parent)[0], self::arrivalMark($parent)[2]]);
        // No evidence has an `ai` block, and none can be replayed as a judged match: as in build 1.
        self::assertArrayNotHasKey('ai', self::arrivalOpen($child)['evidence']);
        self::assertNull(StoredBand::inputs(self::arrivalOpen($child)['evidence']));
        // A page heading with a clue is matched like anything else, and its evidence says it is a heading.
        $this->mainItem('Bar Juice 5000 Range', '', [], 'bar-juice-5000-range');
        $this->arrivesAgain($parent, ['perma_link' => 'bar-juice-5000-range/']);
        $look = $this->arrival->look($parent);
        self::assertSame(['write', 'transfer', true], [$look['action'], $look['answer']['lane'], $look['evidence']['website']['heading']],
            'two published variants in its product, the default one, the product\'s own name, no barcode, no sale: a heading; and still matched');
    }

    // ------------------------------------------------------------------------------------------------------------------
    // A dry run, the timer's form, the backfill

    public function testADryRunChangesNoTable(): void
    {
        [$item, $v] = $this->clean(1);
        $this->arrives($this->shop, 'Nothing like it', 'Red');
        [$item2, $old] = $this->clean(2);
        self::$db->exec('UPDATE listing_profile SET arrival_state = NULL, arrival_seq = 0, arrival_channel_id = NULL WHERE listing_id = ?', [$old]);
        $sums = static function (): array {
            $out = [];
            foreach (self::$db->column("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $t) {
                $out[(string) $t] = self::$db->one('CHECKSUM TABLE ' . \CW\Db::ident((string) $t))['Checksum'];
            }
            return $out;
        };
        $before = $sums();
        $lines = [];
        $r = $this->arrival->run(['each' => static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }]);
        self::assertSame(['dry_run', 2, null], [$r['mode'], $r['looked'], $r['run_id']]);
        self::assertSame([1, ['no_clue' => 1]], [$r['per_store']['electrofag']['match'], $r['per_store']['electrofag']['left']], 'it says what a real run would do');
        self::assertCount(2, $lines);
        self::assertStringContainsString('written outcome=arrival_barcode strength="Check" lane=barcode item=' . $item['code'], $lines[0]);
        $b = $this->arrival->run(['channel' => 'electrofag', 'backfill' => true]);
        self::assertSame(['dry_run', 1, 1], [$b['mode'], $b['looked'], $b['per_store']['electrofag']['match']]);
        // A store that is not in the setting may be looked at dry, by name; and one variant may be explained.
        self::assertSame(0, $this->arrival->run(['channel' => 'vapebig'])['looked']);
        self::assertSame('write', $this->arrival->look($v)['action']);
        self::assertSame($before, $sums(), 'no table changed: no suggestion, no mark, no run row, no audit row');
        self::assertSame([['queued', 1, null], [null, 0, null]], [self::arrivalMark($v), self::arrivalMark($old)]);
        self::assertSame(0, self::runRecords());
    }

    public function testTheTimerTakesOnlyTheQueueAndABackfillByHandTakesWhatWasThereBefore(): void
    {
        // Two variants that were there before the check (no mark), one of them with a clue; and one that arrived since.
        $item = $this->mainItem(self::TITLE, '10mg', [self::gtin(1)]);
        $old = $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin(1)]);
        $oldNoClue = $this->arrives($this->shop, 'Old and unknown', 'Red');
        $matchedOld = $this->arrives($this->shop, 'Old and matched', 'Green');
        $this->decide($this->staffUser('mapping_lead'), 'link', $matchedOld, ['sku_id' => $this->mainItem('Another item', 'Green')['sku'], 'units_per_item' => 1]);
        self::$db->exec('UPDATE listing_profile SET arrival_state = NULL, arrival_seq = 0, arrival_channel_id = NULL WHERE listing_id IN (?, ?, ?)', [$old, $oldNoClue, $matchedOld]);
        $new = $this->arrives($this->shop, 'New and unknown', 'Blue');

        $r = $this->arrival->run(['apply' => true]);
        self::assertSame([1, $new], [$r['looked'], $r['per_store']['electrofag']['last_id']], 'the timer never drains what was there before');
        self::assertSame([null, 0, null], self::arrivalMark($old));
        self::assertNull(self::arrivalOpen($old));
        // A hand run that names the store takes the queue too, not the old ones.
        self::assertSame(0, $this->arrival->run(['apply' => true, 'channel' => 'electrofag'])['looked']);
        self::assertSame([null, 0, null], self::arrivalMark($old));

        // The backfill, with a limit: one at a time, in listing order, going on after the id it printed.
        self::refused(400, 'bad_request', fn () => $this->arrival->run(['apply' => true, 'backfill' => true]));
        $b = $this->arrival->run(['apply' => true, 'channel' => 'electrofag', 'backfill' => true, 'limit' => 1]);
        self::assertSame([1, 'limit', $old, 1], [$b['looked'], $b['stopped'], $b['per_store']['electrofag']['last_id'], $b['per_store']['electrofag']['match']]);
        self::assertSame($item['sku'], (int) self::arrivalOpen($old)['proposed_sku_id']);
        self::assertSame(['done', 0, 'arrival_barcode'], self::arrivalMark($old), 'handled in that run: never queued for the timer');
        self::assertSame((int) $this->shop->channelId, (int) self::$db->value('SELECT arrival_channel_id FROM listing_profile WHERE listing_id = ?', [$old]));
        self::assertSame('backfill', json_decode((string) self::$db->value('SELECT detail FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1', [ArrivalCheck::AUDIT]), true)['mode']);
        $b = $this->arrival->run(['apply' => true, 'channel' => 'electrofag', 'backfill' => true, 'after_id' => $old]);
        self::assertSame([1, null, ['no_clue' => 1]], [$b['looked'], $b['stopped'], $b['per_store']['electrofag']['left']]);
        self::assertSame(['done', 0, 'no_clue'], self::arrivalMark($oldNoClue));
        // Again: nothing is left without a mark.
        self::assertSame(0, $this->arrival->run(['apply' => true, 'channel' => 'electrofag', 'backfill' => true])['looked']);
        // A backfill takes only unmatched variants: a matched one of before the check keeps no mark.
        self::assertSame([null, 0, null], self::arrivalMark($matchedOld));
    }

    public function testTheTimerLeavesAStoreWhoseQueueIsAboveTheAlarmToAHandRun(): void
    {
        $this->arrivalSetting('mapping.arrival_queue_alarm', '2');
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->arrives($this->shop, "Variant {$i}", 'Red');
        }
        $before = self::written();
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame(['above_alarm', 3, 0], [$r['per_store']['electrofag']['skipped'], $r['per_store']['electrofag']['waiting'], $r['looked']]);
        self::assertSame([$before, 0], [self::written(), self::runRecords()]);
        self::assertSame(['queued', 1, null], self::arrivalMark($ids[0]));
        // By hand, naming the store: its queue whatever its size, within the run's number and time.
        $this->arrivalSetting('mapping.arrival_max_per_run', '2');
        $r = $this->arrival->run(['apply' => true, 'channel' => 'electrofag']);
        self::assertSame([2, 'limit', $ids[1]], [$r['looked'], $r['stopped'], $r['per_store']['electrofag']['last_id']]);
        self::assertSame('by_hand', json_decode((string) self::$db->value('SELECT detail FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1', [ArrivalCheck::AUDIT]), true)['mode']);
        // At the alarm (not above it) the timer takes it.
        $this->arrivalSetting('mapping.arrival_queue_alarm', '1');
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame([1, null], [$r['looked'], $r['stopped']]);
        // A run out of time stops before the next variant and says so.
        $this->arrives($this->shop, 'Variant 4', 'Red');
        $r = $this->arrival->run(['apply' => true, 'max_seconds' => 1, 'limit' => 5]);
        self::assertSame(1, $r['looked']);
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Its own suggestion

    public function testItsOwnUntouchedSuggestionIsKeptWhenTheAnswerIsTheSameAndReplacedWhenItIsNot(): void
    {
        [$item, $v] = $this->clean(1);
        $this->arrival->run(['apply' => true]);
        $first = self::arrivalOpen($v);
        self::assertSame('Check', $first['band']);

        // Marked again with nothing the rules read changed: the same answer is not written twice.
        self::$db->exec("UPDATE listing_profile SET arrival_state = 'queued', arrival_seq = arrival_seq + 1 WHERE listing_id = ?", [$v]);
        $before = self::written();
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame([1, 1, 0, null], [$r['looked'], $r['per_store']['electrofag']['kept'], $r['per_store']['electrofag']['match'], $r['run_id']]);
        self::assertSame($before, self::written(), 'same answer: no new run, no new suggestion');
        self::assertSame([(int) $first['id'], ['done', 2, 'arrival_barcode']], [(int) self::arrivalOpen($v)['id'], self::arrivalMark($v)]);

        // The barcode turns up on a second item: a different answer replaces its own suggestion.
        $this->mainItem(self::TITLE, '10mg', [self::gtin(1)]);
        self::$db->exec("UPDATE listing_profile SET arrival_state = 'queued', arrival_seq = arrival_seq + 1 WHERE listing_id = ?", [$v]);
        $decisions = $this->decisions();
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame(1, $r['per_store']['electrofag']['conflict']);
        $second = self::arrivalOpen($v);
        self::assertSame(['Conflict', 'arrival_barcode_two_items', ArrivalCheck::SOURCE_WARNED], [$second['band'], $second['evidence']['band_reasons'][0], $second['source']]);
        self::assertSame('superseded', self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [(int) $first['id']]));
        self::assertSame(['suggested', $decisions], [$this->link($v)['status'], $this->decisions()], 'it stays suggested: no second suggest');
        self::assertNotSame($first['run_name'], $second['run_name'], 'a new run row: one run holds one suggestion per variant');
    }

    public function testASuggestionWhoseClueWentAwayIsReplacedByANotSureThatSaysSoAndNeverLeftOpen(): void
    {
        [$item, $v] = $this->clean(1);
        $this->arrival->run(['apply' => true]);
        $first = self::arrivalOpen($v);
        // The site takes the barcode off the variant (the intake marks it again: its identity changed).
        $this->arrivesAgain($v, ['barcodes' => []]);
        self::assertSame(['queued', 2], array_slice(self::arrivalMark($v), 0, 2));
        $r = $this->arrival->run(['apply' => true]);
        self::assertSame([1, 0], [$r['per_store']['electrofag']['not_sure'], $r['per_store']['electrofag']['match']]);
        $p = self::arrivalOpen($v);
        self::assertSame(["Can't tell", null, null, 'barcode', ['arrival_no_longer'], ['arrival_warned', 'rules_only'], ArrivalCheck::SOURCE_WARNED],
            [$p['band'], $p['proposed_sku_id'], $p['closest_sku_id'], $p['lane'], $p['evidence']['band_reasons'], $p['flags'], $p['source']]);
        self::assertSame(['superseded', 'suggested'], [self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [(int) $first['id']]), $this->link($v)['status']]);
        self::assertSame(['done', 2, 'arrival_no_longer'], self::arrivalMark($v));
        // Still no clue at the next look: that "not sure" is kept, not written again every run.
        self::$db->exec("UPDATE listing_profile SET arrival_state = 'queued', arrival_seq = arrival_seq + 1 WHERE listing_id = ?", [$v]);
        $before = self::written();
        self::assertSame(1, $this->arrival->run(['apply' => true])['per_store']['electrofag']['kept']);
        self::assertSame([$before, (int) $p['id']], [self::written(), (int) self::arrivalOpen($v)['id']]);
        // The barcode comes back: a match again.
        $this->arrivesAgain($v, ['barcodes' => [self::gtin(1)]]);
        self::assertSame(1, $this->arrival->run(['apply' => true])['per_store']['electrofag']['match']);
        self::assertSame($item['sku'], (int) self::arrivalOpen($v)['proposed_sku_id']);
    }

    public function testItsOwnSuggestionAPersonHasTouchedIsNeverReplaced(): void
    {
        [$item, $v] = $this->clean(1);
        $this->arrival->run(['apply' => true]);
        $own = self::arrivalOpen($v);
        // "Not a match" on its page: the suggestion stays open for another choice, and it is the person's now.
        $this->decide($this->staffUser('mapper'), 'reject', $v, ['sku_id' => $item['sku'], 'proposal_id' => (int) $own['id']]);
        self::assertSame('open', self::arrivalOpen($v)['status']);
        $this->arrivesAgain($v, ['barcodes' => []]);
        $this->assertLeftAlone($v, 'has_history', function () use ($v, $own): void {
            self::assertSame((int) $own['id'], (int) self::arrivalOpen($v)['id'], 'not replaced, although its clue is gone');
        });
    }

    // ------------------------------------------------------------------------------------------------------------------
    // What must leave it alone

    public function testARebandAndTheKeyChecksLeaveARulesOnlySuggestionAlone(): void
    {
        [$item, $v] = $this->clean(1);
        $this->arrival->run(['apply' => true]);
        $p = self::arrivalOpen($v);
        $before = self::written();
        $report = (new Reband(self::$db, $this->proposals))->run(Caller::system('reband'), true);
        self::assertSame([1, [], []], [$report['open'], $report['moves'], $report['applied']], 'it is seen and not moved');
        self::assertSame(['unknown_band_version' => 1], $report['skipped'], 'its run names no band version: there is nothing to replay');
        self::assertSame([$before, (int) $p['id']], [self::written(), (int) self::arrivalOpen($v)['id']]);
        // The Key bulk confirm never takes it: it is not Strong, and its evidence does not replay to Strong.
        $c = (new KeyEligibility(self::$db))->check([(int) $p['id']])[(int) $p['id']];
        self::assertContains('not_key', $c['reasons']);
        // And its runs are not "a new matching run" for a failed spot check (KeyBulkTest shows the override refused).
        self::assertSame([], array_diff(ArrivalCheck::SOURCES, KeySample::NOT_A_MATCHING_RUN));
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The scoring

    public function testTheScoreComparesTheRulesWithAJudgedRunAndWithPeoplesMatchesAndWritesNothing(): void
    {
        $run = $this->proposals->run('run3-sold', 'first_match', null, 'n2.0/c1.0/v2.0/b2.0', null, ['band_version' => 'b2.0']);
        $judged = function (int $listing, string $band, ?string $lane, ?array $item, ?int $proposed) use ($run): void {
            $this->proposals->add(Caller::system('import_proposals'), $listing, $run, ['band' => $band, 'lane' => $lane, 'proposed_sku_id' => $proposed, 'ai_outcome' => 'match',
                'ai_confidence' => 95, 'evidence' => ['lane' => $lane, 'lane_target' => $item === null ? null : ['cw_id' => 'CWP-' . $item['variant'], 'sku_id' => $item['sku']],
                    'lane_flags' => [], 'ai' => ['outcome' => 'match', 'confidence' => 95]]]);
        };
        // The same clue, the same item.
        [$a, $va] = $this->clean(1);
        $judged($va, 'Key', 'barcode', $a, $a['sku']);
        // The run searched by name (no clue), the rules have none either.
        $vb = $this->arrives($this->shop, 'Unknown thing', 'Red');
        $judged($vb, "Can't tell", 'candidates', null, null);
        // The run's barcode pointed at another item than the rules' does now.
        [$c, $vc] = $this->clean(3);
        $other = $this->mainItem(self::TITLE, '10mg');
        $judged($vc, 'Key', 'barcode', $other, $other['sku']);
        // The run had a clue, the rules have none now (the barcode is gone from the variant).
        $d = $this->mainItem(self::TITLE, '10mg', [self::gtin(4)]);
        $vd = $this->arrives($this->shop, self::TITLE, '10mg');
        $judged($vd, 'Key', 'barcode', $d, $d['sku']);
        // A person matched this one since, to the item its barcode names: the rules agree with the person.
        $lead = $this->staffUser('mapping_lead');
        $this->decide($lead, 'link', $va, ['sku_id' => $a['sku'], 'units_per_item' => 1, 'proposal_id' => (int) self::arrivalOpen($va)['id']]);
        // ... and this one to another item than its barcode names.
        $this->decide($lead, 'link', $vc, ['sku_id' => $other['sku'], 'units_per_item' => 1, 'proposal_id' => (int) self::arrivalOpen($vc)['id']]);

        $before = [self::written(), self::runRecords(), self::arrivalMark($va)];
        $s = $this->arrival->score('electrofag');
        self::assertSame([$before[0], $before[1], $before[2]], [self::written(), self::runRecords(), self::arrivalMark($va)], 'read only');
        self::assertSame(['run3-sold', 'electrofag', 4], [$s['run_id'], $s['store'], $s['variants']]);
        self::assertSame(['same_clue_same_item' => 1, 'both_no_clue' => 1, 'same_item_other_lane' => 0, 'rule_has_no_item' => 0, 'run_has_no_item' => 0, 'other_item' => 1,
            'rule_clue_run_none' => 0, 'run_clue_rule_none' => 1], $s['t1']);
        self::assertCount(2, $s['t1_misses']);
        self::assertStringContainsString('other_item: listing ' . $vc, $s['t1_misses'][0]);
        self::assertStringContainsString($c['code'], $s['t1_misses'][0]);
        self::assertStringContainsString($other['code'], $s['t1_misses'][0]);
        self::assertStringContainsString('run_clue_rule_none: listing ' . $vd, $s['t1_misses'][1]);
        // Where the rules say "match": the history of the variant is ignored (two of them are matched by now).
        self::assertSame(['rule_says_match' => 2, 'same_item' => 1, 'other_item' => 1, 'run_proposed_none' => 0], $s['t2_run']);
        self::assertSame(['matched_by_a_person' => 2, 'rule_same_item' => 1, 'rule_other_item' => 1, 'rule_no_item' => 0], $s['t2_person']);
        self::assertStringContainsString('a person matched it to ' . $other['code'], $s['t2_person_misses'][0]);
        self::assertSame(['arrival_barcode' => 2, 'no_clue' => 2], $s['outcomes']);
        // Two variants of one store that the run suggested for one item: the rules say so of each ("the item is taken"), no match.
        $ve = $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin(4)]);
        $judged($ve, 'Key', 'barcode', $d, $d['sku']);
        $s2 = $this->arrival->score('electrofag');
        self::assertSame([5, 1], [$s2['variants'], $s2['outcomes']['arrival_item_taken']]);
        self::assertSame(2, $s2['t2_run']['rule_says_match'], 'a "not sure" that names the closest item is no match');
        self::assertSame(2, $s2['t1']['same_clue_same_item'], 'its clue and the item the clue names are the run\'s own all the same');
        // By name; a run that is not a judged one, or not there, is refused.
        self::assertSame(5, $this->arrival->score('electrofag', 'run3-sold')['variants']);
        self::refused(404, 'unknown_run', fn () => $this->arrival->score('electrofag', 'nope'));
        self::refused(404, 'unknown_run', fn () => $this->arrival->score('vapebig'));
        self::refused(404, 'unknown_store', fn () => $this->arrival->score('nowhere'));
    }

    /**
     * What a person decided about a barcode binds the check too (M65; the barcode sync and the seeder obey the same rows:
     * BarcodeReviews::KEEPS_AWAY). A barcode a person took off an item is still on the profile of that item's variant ("a
     * listing that still carries it"): that variant no longer names its item through it, and counts as a variant that carries
     * the barcode and has no item for it. So a removed barcode is Not sure with no item, and once a barcode review has said
     * which of two items a barcode belongs to, the other item's variant no longer makes it "on more than one item". A review
     * that is still open settles nothing, and a barcode put back on the item by hand is the item's again (its record wins).
     */
    public function testABarcodeAPersonTookOffAnItemOrRuledOnInAReviewDoesNotNameThatItem(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $key = static fn (int $n): string => ltrim(self::gtin($n), '0');
        $decided = 'INSERT INTO barcode_review (barcode, reason, claimant_sku_id, holder_sku_id, status, decision, decided_by, decided_actor, decided_at, opened_by, opened_actor) '
            . "VALUES (?, ?, ?, ?, 'decided', ?, ?, ?, UTC_TIMESTAMP(6), ?, ?)";
        $record = "INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source) VALUES (?, ?, 1, 1, 'test')";
        // Removed from the item by a person; the item's own variant still carries it.
        $x = $this->mainItem(self::TITLE, '10mg', [self::gtin(1)]);
        self::$db->exec($decided, [$key(1), 'removed', $x['sku'], $x['sku'], 'removed', $lead->staffUserId, $lead->actor, $lead->staffUserId, $lead->actor]);
        $removed = $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin(1)]);
        // A review ruled it the holder's; a third store's variant of the other item still carries it.
        $h = $this->mainItem(self::TITLE, '20mg', [self::gtin(2)]);
        self::$db->exec($record, [$key(2), $h['sku']]);
        $s = $this->mainItem('Another Juice 3000 Cola Ice 10ml Nic Salt E-Liquid', '20mg');
        $wrong = $this->arrives($this->third, 'Another Juice 3000 Cola Ice 10ml Nic Salt E-Liquid', '20mg', [self::gtin(2)]);
        $this->decide($lead, 'link', $wrong, ['sku_id' => $s['sku'], 'units_per_item' => 1]);
        self::$db->exec($decided, [$key(2), 'on_another_item', $s['sku'], $h['sku'], 'keep_holder', $lead->staffUserId, $lead->actor, null, 'system:sync_barcodes']);
        $ruled = $this->arrives($this->shop, self::TITLE, '20mg', [self::gtin(2)]);
        // A pair whose review is still open is not settled: both items count, as before.
        $a = $this->mainItem(self::TITLE, '10mg', [self::gtin(3)]);
        $b = $this->mainItem(self::TITLE, '10mg', [self::gtin(3)]);
        self::$db->exec("INSERT INTO barcode_review (barcode, reason, claimant_sku_id, holder_sku_id, status, opened_actor) VALUES (?, 'on_another_item', ?, ?, 'open', 'system:sync_barcodes')",
            [$key(3), $b['sku'], $a['sku']]);
        $open = $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin(3)]);
        // Removed once, then put back on the item by hand: the item's own record wins over the older ruling.
        $z = $this->mainItem(self::TITLE, '10mg', [self::gtin(4)]);
        self::$db->exec($decided, [$key(4), 'removed', $z['sku'], $z['sku'], 'removed', $lead->staffUserId, $lead->actor, $lead->staffUserId, $lead->actor]);
        self::$db->exec($record, [$key(4), $z['sku']]);
        $back = $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin(4)]);

        $this->arrival->run(['apply' => true]);
        $says = static function (int $v): array {
            $p = self::arrivalOpen($v);
            return [$p['band'], $p['proposed_sku_id'] === null ? null : (int) $p['proposed_sku_id'], $p['evidence']['band_reasons'], $p['flags'], $p['source']];
        };
        self::assertSame(["Can't tell", null, ['arrival_barcode_no_item'], ['arrival_warned', 'gtin_target_not_in_seed', 'rules_only'], ArrivalCheck::SOURCE_WARNED], $says($removed),
            'the barcode was taken off the item: its variant does not name it');
        self::assertSame(['Check', $h['sku'], ['arrival_barcode'], ['rules_only'], ArrivalCheck::SOURCE], $says($ruled), 'the review said whose it is');
        self::assertSame(['Conflict', 'arrival_barcode_two_items'], [$says($open)[0], $says($open)[2][0]], 'not settled yet');
        self::assertSame(['Check', $z['sku'], ['arrival_barcode'], ['rules_only'], ArrivalCheck::SOURCE], $says($back), 'put back by hand: the item\'s own record');
    }

    /**
     * A clue that goes through a variant matched as a pack (units per item other than 1) is no one-unit match (M65, the table's
     * "pack" line): the suggestion carries no units, so a confirm would link one unit a sale. The barcode sync asks a person
     * about such a barcode ("the pack's or the single unit's?"); the check says Not sure and names the item as the closest.
     */
    public function testAClueThroughAVariantMatchedAsAPackIsNotSureAndNamesTheItemAsTheClosest(): void
    {
        $lead = $this->staffUser('mapping_lead');
        // The barcode is on a third store's "box of ten", matched to the single item with ten units a sale.
        $x = $this->mainItem(self::TITLE, '10mg');
        $box = $this->arrives($this->third, self::TITLE, '10mg', [self::gtin(1)]);
        $this->decide($lead, 'link', $box, ['sku_id' => $x['sku'], 'units_per_item' => 1]);
        self::$db->exec('UPDATE channel_listing SET units_per_item = 10 WHERE id = ?', [$box]);
        $byBarcode = $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin(1)]);
        // The address is the main store's page of a pack: its variant is matched to the item with ten units a sale.
        $y = $this->mainItem(self::TITLE, '20mg', [], 'pack/of-ten');
        self::$db->exec('UPDATE channel_listing SET units_per_item = 10 WHERE id = ?', [$y['listing']]);
        $byAddress = $this->arrives($this->shop, self::TITLE, '20mg', [], 'pack/of-ten/');

        // The item's own barcode record says one unit: it stays the authority, whatever a pack page that carries the code says.
        $w = $this->mainItem(self::TITLE, '10mg');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, is_usable, units_per_scan, source) VALUES (?, ?, 1, 1, 'test')", [ltrim(self::gtin(2), '0'), $w['sku']]);
        $box2 = $this->arrives($this->third, self::TITLE, '10mg', [self::gtin(2)]);
        $this->decide($lead, 'link', $box2, ['sku_id' => $w['sku'], 'units_per_item' => 1]);
        self::$db->exec('UPDATE channel_listing SET units_per_item = 10 WHERE id = ?', [$box2]);
        $recorded = $this->arrives($this->shop, self::TITLE, '10mg', [self::gtin(2)]);

        $this->arrival->run(['apply' => true]);
        foreach ([[$byBarcode, 'barcode', $x['sku'], 'arrival_barcode_pack'], [$byAddress, 'transfer', $y['sku'], 'arrival_copy_pack']] as [$v, $lane, $item, $reason]) {
            $p = self::arrivalOpen($v);
            self::assertSame(["Can't tell", $lane, null, $item, $reason, ArrivalCheck::SOURCE_WARNED],
                [$p['band'], $p['lane'], $p['proposed_sku_id'], (int) $p['closest_sku_id'], $p['evidence']['band_reasons'][0], $p['source']], $reason);
        }
        $p = self::arrivalOpen($recorded);
        self::assertSame(['Check', $w['sku'], ['arrival_barcode'], ArrivalCheck::SOURCE], [$p['band'], (int) $p['proposed_sku_id'], $p['evidence']['band_reasons'], $p['source']]);
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The tool, as cron and a person run it

    public function testTheToolIsADryRunUnlessToldToApplyAndSaysWhenItIsSwitchedOff(): void
    {
        [$item, $v] = $this->clean(1);
        $before = [self::written(), self::runRecords(), self::arrivalMark($v)];
        // The dry run: what it would do, and nothing written.
        $dry = self::tool('--list');
        self::assertSame([0, ''], [$dry['code'], $dry['err']], $dry['out']);
        self::assertStringContainsString('DRY RUN (nothing written) store=electrofag waiting=1 looked=1 would_match=1', $dry['out']);
        self::assertStringContainsString("listing={$v} store=electrofag", $dry['out']);
        self::assertStringContainsString('item=' . $item['code'], $dry['out']);
        self::assertSame($before, [self::written(), self::runRecords(), self::arrivalMark($v)]);
        // One variant explained.
        $one = self::tool('--listing=' . $v);
        self::assertSame(0, $one['code'], $one['err']);
        self::assertStringContainsString('"outcome": "arrival_barcode"', $one['out']);
        self::assertSame($before, [self::written(), self::runRecords(), self::arrivalMark($v)]);
        // The score (read only), and what it needs.
        self::assertSame(2, self::tool('--score')['code']);
        self::assertSame(2, self::tool('--score', '--channel=electrofag')['code'], 'no judged run for the store: said, not guessed');
        // Usage: an apply for a store that is not switched on, a backfill without its store, an unknown store.
        foreach ([['--apply', '--channel=vapebig'], ['--backfill'], ['--channel=nowhere'], ['--limit=0'], ['--listing=' . $v, '--apply'], ['--run=x']] as $args) {
            $r = self::tool(...$args);
            self::assertSame([2, ''], [$r['code'], $r['out']], implode(' ', $args) . ': ' . $r['err']);
        }
        self::assertSame($before, [self::written(), self::runRecords(), self::arrivalMark($v)]);
        // A command line it cannot read whole is refused before anything is read. getopt() alone drops the word and goes on:
        // `--apply=false` is `--apply` to it, a misspelt or empty --channel leaves the timer's form (every store of the setting),
        // `--backfill=0` is a backfill, and it stops reading at a stray word. (The same for --db is in Unit\CliOptionsTest: a
        // line with a broken --db is never run from a test, which must not be able to reach another schema.)
        foreach ([['--apply=false'], ['--apply=0', '--channel=electrofag'], ['--apply', '--chanel=electrofag'], ['--apply', '--channel='],
            ['--apply', 'x', '--channel=electrofag'], ['--apply', '--channel=electrofag', '--backfill=0'], ['--apply', '--limit='], ['--apply', '--limit', '5'],
            ['--apply', '--apply']] as $args) {
            $r = self::tool(...$args);
            self::assertSame([2, ''], [$r['code'], $r['out']], implode(' ', $args) . ': ' . $r['err']);
            self::assertStringContainsString('usage: php bin/check_arrivals.php', $r['err'], implode(' ', $args));
            self::assertSame($before, [self::written(), self::runRecords(), self::arrivalMark($v)], implode(' ', $args) . ': written');
        }

        // As cron runs it: it writes.
        $apply = self::tool('--apply');
        self::assertSame([0, ''], [$apply['code'], $apply['err']], $apply['out']);
        self::assertStringContainsString('store=electrofag waiting=1 looked=1 match=1 conflict=0 not_sure=0', $apply['out']);
        self::assertStringNotContainsString('DRY RUN', $apply['out']);
        self::assertSame([$item['sku'], 'system:check_arrivals'], [(int) self::arrivalOpen($v)['proposed_sku_id'],
            self::$db->value('SELECT actor FROM audit_log WHERE action = ?', [ArrivalCheck::AUDIT])]);
        // Switched off: it says so and exits 0, with and without --apply.
        $this->arrivalSetting('mapping.arrival_stores', '""');
        foreach ([[], ['--apply']] as $args) {
            $off = self::tool(...$args);
            self::assertSame([0, ''], [$off['code'], $off['err']]);
            self::assertStringContainsString('switched off', $off['out']);
        }
        self::assertSame(1, self::runRecords());
    }

    // ------------------------------------------------------------------------------------------------------------------
    // The locks

    /**
     * The check's transaction against a listing push of the same variant, forced to overlap:
     *   D  holds the feed clock (what any stock write in flight does);
     *   W1 the check: locks the variant's listing row, writes the suggestion, parks on the feed clock. So the feed clock is the
     *      last lock it asks for, and while it waits it holds no lock on any profile row;
     *   W2 a push that renames the same variant: locks its profile (the intake's order: profile, then listing), parks on W1's
     *      listing lock;
     *   D  commits: W1 finishes, then W2. No deadlock. W1's suggestion stands (made on the identity it read), and the variant is
     *      marked to check again by the push, so the next run looks at it as it is now.
     */
    public function testTheCheckAndAListingPushOfTheSameVariantDoNotDeadlockAndTheFeedLockComesLast(): void
    {
        [$item, $v] = $this->clean(1);
        $variant = (string) self::$db->value('SELECT external_variant_id FROM channel_listing WHERE id = ?', [$v]);
        $d = self::session();
        $d->exec('INSERT INTO feed_clock (id, ticks) VALUES (1, 1) ON DUPLICATE KEY UPDATE ticks = ticks + 1');

        $w1 = $this->spawn([['op' => 'check_arrivals', 'opts' => ['apply' => true]]]);
        $this->waitForLock('feed_clock', 'WAITING');
        self::assertGreaterThan(0, $this->locks('channel_listing', 'GRANTED'), 'it holds the variant\'s row');
        self::assertSame([0, 0], [$this->locks('listing_profile', 'GRANTED'), $this->locks('listing_profile', 'WAITING')], 'and no profile row');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(DISTINCT ENGINE_TRANSACTION_ID) FROM performance_schema.data_locks WHERE OBJECT_SCHEMA = DATABASE() "
            . "AND OBJECT_NAME = 'feed_clock' AND LOCK_STATUS = 'WAITING'"));

        // A push that changes only the address writes the profile and never the listing row: the check does not hold it up.
        $w2 = $this->spawn([self::op($this->shop, 'listings', ['listings' => [['variant_id' => $variant, 'product_title' => self::TITLE, 'variant_title' => '10mg',
            'brand' => 'Bar Juice 5000', 'barcodes' => [self::gtin(1)], 'price' => 4.99, 'units_365d' => 7, 'perma_link' => 'renamed/page']]])]);
        $r2 = $this->collect($w2, 30);
        self::assertSame(200, $r2['results'][0]['status'], json_encode($r2));
        self::assertTrue($this->running($w1), 'the check is still parked on the feed clock');
        // A push that changes the identity (another strength) locks the profile, then wants the listing row the check holds.
        $w3 = $this->spawn([self::op($this->shop, 'listings', ['listings' => [['variant_id' => $variant, 'product_title' => self::TITLE, 'variant_title' => '20mg',
            'brand' => 'Bar Juice 5000', 'barcodes' => [self::gtin(1)], 'price' => 4.99, 'units_365d' => 7, 'perma_link' => 'renamed/page']]])]);
        $this->waitForLock('channel_listing', 'WAITING');
        self::assertGreaterThan(0, $this->locks('listing_profile', 'GRANTED'), 'the push holds the profile; the check never asks for it inside its transaction');
        self::assertTrue($this->running($w1) && $this->running($w3));

        $d->pdo()->commit();
        $r1 = $this->collect($w1, 60);
        $r3 = $this->collect($w3, 60);
        self::assertSame([200, 200], [$r1['results'][0]['status'], $r3['results'][0]['status']], json_encode([$r1, $r3]));
        self::assertSame(0, $r1['deadlocks'] + $r2['deadlocks'] + $r3['deadlocks'], $this->latestDeadlock());
        // The check's suggestion stands: it was made on the identity it read, which the basis records.
        $s = $r1['results'][0]['body']['per_store']['electrofag'];
        self::assertSame([1, 1, 0, 0], [$s['looked'], $s['match'], $s['moved'], $s['failed']], json_encode($s));
        $p = self::arrivalOpen($v);
        self::assertSame(['Check', $item['sku']], [$p['band'], (int) $p['proposed_sku_id']]);
        self::assertNotSame((string) self::$db->value('SELECT identity_hash FROM listing_profile WHERE listing_id = ?', [$v]),
            self::$db->value('SELECT identity_hash FROM match_proposal_basis WHERE proposal_id = ?', [(int) $p['id']]), 'the variant has changed since');
        // And nothing is lost: the pushes marked the variant again, and the check's own mark did not undo that.
        self::assertSame(['queued', 3], array_slice(self::arrivalMark($v), 0, 2));
        // The next run looks at it as it is now: its own suggestion is replaced by what the new strength says.
        $this->arrival->run(['apply' => true]);
        $now = self::arrivalOpen($v);
        self::assertSame(['Conflict', 'veto_on_key', 'superseded'], [$now['band'], $now['evidence']['band_reasons'][0], self::$db->value('SELECT status FROM match_proposal WHERE id = ?', [(int) $p['id']])]);
        self::assertSame((string) self::$db->value('SELECT identity_hash FROM listing_profile WHERE listing_id = ?', [$v]),
            self::$db->value('SELECT identity_hash FROM match_proposal_basis WHERE proposal_id = ?', [(int) $now['id']]));
        self::assertSame(['done', 3], array_slice(self::arrivalMark($v), 0, 2));
    }

    /**
     * A ticked batch and the switch (M64). The batch's pre-checks read `mapping.arrival_bulk_confirm` once, before the first row.
     * Switched off while the batch runs, a row still to do is left: under the row's lock the batch asks again whether the
     * suggestion the row named is one of the check's that are decided one at a time NOW, whatever the decision has just made of
     * it (a confirm closes the suggestion, so asking for "its open suggestion" finds nothing). Forced to overlap:
     *   D  holds the variant's row;
     *   W  the batch: its pre-checks pass (the setting is on), its decision parks on the row;
     *   the setting is switched off, D lets go: the row is skipped as found on arrival, and nothing is decided.
     */
    public function testATickedBatchLeavesAMatchOfTheCheckOnceTheSettingIsSwitchedOffWhileItRuns(): void
    {
        $this->arrivalSetting('mapping.arrival_bulk_confirm', 'true');
        [, $v] = $this->clean(1);
        $this->arrival->run(['apply' => true]);
        $open = self::arrivalOpen($v);
        $lead = $this->staffUser('mapping_lead');
        $row = ['listing_id' => $v, 'proposal_id' => (int) $open['id'], 'map_version' => (int) self::$db->value('SELECT map_version FROM channel_listing WHERE id = ?', [$v])];
        $context = ['source' => 'review', 'band' => 'Check'];
        // With the setting on it is an ordinary row: a ticked confirm would take it.
        self::assertSame(['done', null], array_slice(array_values((new BulkDecisions(self::$db, $this->ds))->preview($lead, 'link', [$row], $context)['rows'][$v]), 0, 2));
        $decisions = (int) self::$db->value('SELECT COUNT(*) FROM match_decision');

        $d = self::session();
        $d->one('SELECT id FROM channel_listing WHERE id = ? FOR UPDATE', [$v]);
        $w = $this->spawn([['op' => 'bulk', 'staff_id' => $lead->staffUserId, 'action' => 'link', 'rows' => [$row], 'context' => $context]]);
        $this->waitForLock('channel_listing', 'WAITING');
        $this->arrivalSetting('mapping.arrival_bulk_confirm', 'false');
        $d->pdo()->commit();

        $r = $this->collect($w, 60);
        self::assertSame(200, $r['results'][0]['status'], json_encode($r));
        $body = $r['results'][0]['body'];
        self::assertSame([0, 0, 1], [$body['done'], $body['pending'], $body['skipped']], json_encode($body));
        self::assertSame(['outcome' => 'skipped', 'skip_code' => 'on_arrival'], self::$db->one('SELECT outcome, skip_code FROM mapping_batch_row WHERE batch_id = ?', [(int) $body['batch_id']]));
        self::assertSame(['suggested', (int) $open['id'], $decisions], [self::$db->value('SELECT status FROM channel_listing WHERE id = ?', [$v]), (int) (self::arrivalOpen($v)['id'] ?? 0),
            (int) self::$db->value('SELECT COUNT(*) FROM match_decision')], 'nothing was decided: its suggestion is still open');
    }

    // ------------------------------------------------------------------------------------------------------------------

    /**
     * A spot check with one member of its population: a Strong suggestion of another variant. The tests then point a hold, or the
     * member row itself, at the variant under test, so that the hold or the membership is the ONLY thing the variant has.
     *
     * @return array{0: int, 1: int} the sample id, the member's proposal id
     */
    private function spotCheckWithOneMember(string $name = 'sc'): array
    {
        $item = $this->mainItem('Spot check item ' . $name, '10mg');
        $other = $this->listing($this->third, 'SC-' . $name, null);
        $pid = $this->propose($other, 'Key', $item['sku'], ['lane' => 'barcode', 'ai_outcome' => 'match', 'ai_confidence' => 96], true, 'run-' . $name);
        $lead = (int) $this->staffUser('mapping_lead')->staffUserId;
        $sample = self::$db->insert("INSERT INTO key_sample (name, seed, method, band_version, sample_size, required_size, population, strata, created_by, actor) "
            . "VALUES (?, 1, 'm', 'b2.1', 20, 20, 100, JSON_ARRAY(), ?, 'staff:1')", [$name, $lead]);
        self::$db->exec("INSERT INTO key_sample_member (sample_id, proposal_id, listing_id, stratum, ai_confidence, position) VALUES (?, ?, ?, 'a', 96, NULL)", [$sample, $pid, $other]);
        return [$sample, $pid];
    }

    /** @return array{code: int, out: string, err: string} */
    private static function tool(string ...$args): array
    {
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/check_arrivals.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
