<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Mapping;

use CW\Caller;
use CW\Mapping\BulkDecisions;
use CW\Mapping\KeyHold;
use CW\Mapping\KeySample;
use CW\Tests\Support\ArrivalFixtures;
use CW\Tests\Support\KeyFixtures;
use CW\Tests\Support\MappingTestCase;

/**
 * BulkDecisions' list context `store_new` (Store Products' "Create new warehouse products for these", docs/decisions.md NF1-NF8): the
 * rules the screen test does not reach. Held back, in a spot check and found on arrival are skipped first, whatever else is true; a
 * matching lead only (a matcher is refused whole); a row the preview skipped is never made, and one whose details changed after the
 * preview neither; a new product of the same name waiting for a second OK is no second one; preview() and run() say the same; the
 * form key makes one batch however often it is sent.
 */
final class StoreNewItemsTest extends MappingTestCase
{
    use KeyFixtures;
    use ArrivalFixtures;

    /** @var array<string, string> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $json) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
        }
        $this->saved = [];
        parent::tearDown();
    }

    private function setting(string $key, string $json): void
    {
        $this->saved[$key] ??= (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [$key]);
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    private function bulk(): BulkDecisions
    {
        return new BulkDecisions(self::$db, $this->ds);
    }

    /** @return array{listing_id: int, proposal_id: ?int, map_version: int} */
    private function seen(int $listingId): array
    {
        $p = self::$db->value('SELECT id FROM match_proposal WHERE open_listing_id = ?', [$listingId]);
        return ['listing_id' => $listingId, 'proposal_id' => $p === null ? null : (int) $p, 'map_version' => $this->version($listingId)];
    }

    private function fresh(string $variant, string $name): int
    {
        $id = $this->listing($this->altSite, $variant, null);
        self::$db->exec("INSERT INTO listing_profile (listing_id, product_title, variant_title, brand, barcodes, features) VALUES (?, 'Family', ?, 'Hayati', JSON_ARRAY(), JSON_OBJECT())",
            [$id, $name]);
        return $id;
    }

    /** @param array{listing_id: int, proposal_id: ?int, map_version: int} $row @return array<string, mixed> */
    private function shown(array $row, array $preview): array
    {
        $p = $preview['rows'][$row['listing_id']];
        return $row + ['preview_skipped' => $p['outcome'] === 'skipped', 'card_hash' => BulkDecisions::cardHash($p['card'])];
    }

    public function testHeldSpotCheckAndArrivalRowsAreSkippedAndOnlyAMatchingLeadMayCreate(): void
    {
        $this->keySetup();
        $this->setting('approvals.spot_check_size', '5');
        $lead = $this->staffUser('mapping_lead');
        $pop = [];
        for ($i = 0; $i < 7; $i++) {
            $pop[] = $this->firstMatch($this->vpgItem(), 95, [], [], null, [], 100 + $i);
        }
        $sample = (new KeySample(self::$db))->create($lead, 'sn1', 5, true);
        $spot = (int) $sample['members'][0]['listing_id'];
        $others = array_values(array_filter($pop, static fn (array $m): bool => !in_array($m['listing'], array_column($sample['members'], 'listing_id'), true)));
        (new KeyHold(self::$db))->run($lead, 'sn1', KeyHold::parse("proposal_id,listing_id,reason\n{$others[0]['proposal']},{$others[0]['listing']},Pack size differs\n"), false, true);
        $held = $others[0]['listing'];
        // Found by the check on arrival (a barcode clue): decided one at a time.
        foreach (['mapping.arrival_stores' => '"alt"', 'mapping.arrival_main_store' => '"vpg"', 'mapping.arrival_bulk_confirm' => 'false'] as $key => $json) {
            $this->setting($key, $json);
        }
        $this->arrivalMain = $this->vpgSite;
        $this->arrival = new \CW\Mapping\ArrivalCheck(self::$db, $this->ds);
        $title = 'Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid';
        $this->mainItem($title, '10mg', [self::gtin(1)]);
        $arrived = $this->arrives($this->altSite, $title, '10mg', [self::gtin(1)]);
        $this->arrival->run(['apply' => true]);
        $free = $this->fresh('F1', 'Hayati Pro Max 4000 Kiwi');

        $rows = array_map(fn (int $id): array => $this->seen($id), [$held, $spot, $arrived, $free]);
        $context = ['source' => BulkDecisions::STORE_NEW, 'channel_id' => (int) $this->altSite->channelId];
        $p = $this->bulk()->preview($lead, 'new_item', $rows, $context);
        $expect = [$held => 'held', $spot => 'in_spot_check', $arrived => 'on_arrival', $free => null];
        ksort($expect);
        self::assertSame($expect, array_map(static fn (array $r): ?string => $r['code'], $p['rows']), 'in listing id order, the order run() does them in');
        self::assertSame([1, 0, 3], [$p['done'], $p['pending'], $p['skipped']]);

        // A matcher may make one new product on its own page, but not create them for ticked rows: refused whole, nothing written.
        $decisions = $this->decisions();
        $batches = (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch');
        foreach (['mapper' => 'lead_required', 'viewer' => 'role_not_allowed'] as $role => $code) {
            self::refused(403, $code, fn () => $this->bulk()->run($this->staffUser($role), 'new_item', $rows, $context));
            self::refused(403, $code, fn () => $this->bulk()->preview($this->staffUser($role), 'new_item', $rows, $context));
        }
        self::assertSame([$decisions, $batches], [$this->decisions(), (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch')]);
        // A store list names its store; and Store Products' own bulk route has no such action.
        self::refused(400, 'bad_request', fn () => $this->bulk()->preview($lead, 'new_item', $rows, ['source' => BulkDecisions::STORE_NEW]));
        self::refused(400, 'bad_action', fn () => $this->bulk()->run($lead, 'new_item', $rows, ['source' => 'store', 'channel_id' => (int) $this->altSite->channelId]));

        $r = $this->bulk()->run($lead, 'new_item', array_map(fn (array $row): array => $this->shown($row, $p), $rows), $context);
        self::assertSame([1, 0, 3], [$r['done'], $r['pending'], $r['skipped']]);
        self::assertSame(['mapped', 1], [$this->link($free)['status'], $this->link($free)['units_per_item']]);
        foreach ([$held, $spot, $arrived] as $lid) {
            self::assertNotSame('mapped', $this->link($lid)['status']);
        }
        self::assertSame('waiting', (new KeySample(self::$db))->status('sn1')['verdict'], 'the spot check is untouched');
    }

    public function testTheApplyNeverMakesARowThePreviewSkippedOrOneWhoseDetailsChangedAndSendingTheKeyAgainMakesOneBatch(): void
    {
        $this->keySetup();
        $lead = $this->staffUser('mapping_lead');
        $a = $this->fresh('A1', 'Hayati Pro Max 4000 Apple');
        $b = $this->fresh('B1', 'Hayati Pro Max 4000 Banana');
        $c = $this->fresh('C1', 'Hayati Pro Max 4000 Cherry');
        $taken = $this->item('legacy', 0, 'Hayati Pro Max 4000 Cherry');
        $context = ['source' => BulkDecisions::STORE_NEW, 'channel_id' => (int) $this->altSite->channelId];
        $rows = array_map(fn (int $id): array => $this->seen($id), [$a, $b, $c]);
        $p = $this->bulk()->preview($lead, 'new_item', $rows, $context);
        self::assertSame([null, null, 'name_elsewhere'], array_values(array_map(static fn (array $r): ?string => $r['code'], $p['rows'])));
        $shown = array_map(fn (array $row): array => $this->shown($row, $p), $rows);
        // After the preview: the item of C's name is joined into another (C could now be made), B's details change (a matching run
        // rewrote its features: the version does not move).
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$this->item('legacy', 0, 'Kept item'), $taken]);
        self::$db->exec("UPDATE listing_profile SET features = JSON_OBJECT('strength_mg', 20) WHERE listing_id = ?", [$b]);

        $key = str_repeat('ab', 16);
        $r = $this->bulk()->run($lead, 'new_item', $shown, $context + ['form_key' => $key]);
        $codes = [];
        foreach (self::$db->all('SELECT listing_id, outcome, skip_code FROM mapping_batch_row WHERE batch_id = ? ORDER BY seq', [$r['batch_id']]) as $row) {
            $codes[(int) $row['listing_id']] = $row['skip_code'] ?? $row['outcome'];
        }
        self::assertSame([$a => 'done', $b => 'card_changed', $c => 'changed'], $codes, 'the preview skipped C: never made, even when it could be now');
        self::assertSame(['mapped', 'unmapped', 'unmapped'], [$this->link($a)['status'], $this->link($b)['status'], $this->link($c)['status']]);

        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $again = $this->bulk()->run($lead, 'new_item', $shown, $context + ['form_key' => $key]);
        self::assertSame([$r['batch_id'], 1, 0, 2, true], [$again['batch_id'], $again['done'], $again['pending'], $again['skipped'], $again['replayed']]);
        self::assertSame($skus, (int) self::$db->value('SELECT COUNT(*) FROM sku'));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM mapping_batch'));
        self::refused(400, 'bad_form_key', fn () => $this->bulk()->run($lead, 'new_item', $shown, $context + ['form_key' => 'not-a-key']));
    }

    public function testANewProductOfTheSameNameWaitingForASecondOkIsNoSecondOneAndThePreviewSaysWhatRunDoes(): void
    {
        $this->keySetup();
        $this->setting('approvals.mapping_bulk_second_ok', 'true');
        $lead = $this->staffUser('mapping_lead');
        $first = $this->fresh('A1', 'Hayati Pro Max 4000 Apple');
        $context = ['source' => BulkDecisions::STORE_NEW, 'channel_id' => (int) $this->altSite->channelId];
        $r = $this->bulk()->run($lead, 'new_item', [$this->seen($first)], $context);
        self::assertSame([0, 1, 0], [$r['done'], $r['pending'], $r['skipped']]);
        // The same product listed again (words in another order): its name waits for a second OK already.
        $second = $this->fresh('A2', 'Apple Hayati Pro Max 4000');
        $other = $this->fresh('B2', 'Hayati Pro Max 4000 Banana');
        $rows = [$this->seen($second), $this->seen($other)];
        $p = $this->bulk()->preview($lead, 'new_item', $rows, $context);
        self::assertSame([$second => ['skipped', 'name_waiting', []], $other => ['pending_second', null, ['bulk']]],
            array_map(static fn (array $x): array => [$x['outcome'], $x['code'], $x['needs']], $p['rows']));
        $run = $this->bulk()->run($lead, 'new_item', array_map(fn (array $row): array => $this->shown($row, $p), $rows), $context);
        self::assertSame([$p['done'], $p['pending'], $p['skipped']], [$run['done'], $run['pending'], $run['skipped']], 'the preview says what run() does');
        self::assertSame('name_waiting', self::$db->value('SELECT skip_code FROM mapping_batch_row WHERE batch_id = ? AND listing_id = ?', [$run['batch_id'], $second]));
        self::assertSame('4000 apple hayati max pro', BulkDecisions::nameWords('Hayati Pro Max 4000 Apple'), 'the words of a name, as a set');
        self::assertSame(BulkDecisions::nameWords('Hayati Pro Max 4000 apple'), BulkDecisions::nameWords('APPLE - hayati, Pro Max 4000 (Apple)'));
        self::assertNotSame(BulkDecisions::nameWords('Hayati Pro Max 4000 Apple'), BulkDecisions::nameWords('Hayati Pro Max 4000 Apple Ice'), 'a word more is another name');
        self::assertSame(BulkDecisions::nameWords('Crème Brûlée'), BulkDecisions::nameWords('creme brulee'), 'case and accents do not tell two names apart');
        $caller = Caller::system('x');
        self::refused(403, 'staff_required', fn () => $this->bulk()->preview($caller, 'new_item', $rows, $context));
    }
}
