<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Catalogue;

use CW\Caller;
use CW\Catalogue\CardProposals;
use CW\Catalogue\ItemCompliance;
use CW\Catalogue\ItemRules;
use CW\Invariants;

/**
 * The item card service (IM3; docs/decisions.md I100-I105, I113-I115, I119): a person fills in and confirms the legal fields; the
 * TRPR and single-use rules warn until a person confirms the card and then block until a person confirms it again (no edit lifts
 * a block or imposes one); who may write; versions; nothing changed writes nothing; the flavour's proposed / confirmed status (a
 * form that sends a proposed flavour back keeps it proposed); a kit's tank capacity; suggestions from the matcher's identity card
 * and the linked listings, accepted one at a time and never written by themselves; what the other modules ask (ItemCompliance);
 * the SQL form of the rules; the invariants that find a card changed outside CW.
 */
final class ItemCardsTest extends CatalogueTestCase
{
    public function testWarningsUntilAPersonConfirmsThenBlocks(): void
    {
        $who = $this->editor();
        $tank = $this->item('legacy', 0, 'Big Tank 5ml');
        $other = $this->item('legacy', 0, 'Coil');
        $compliance = new ItemCompliance(self::$db);

        $s = $this->cards->save($who, $tank, 0, ['product_type' => 'Tank', 'liquid_ml' => '5', 'duty_liable' => 'no']);
        self::assertSame(['saved', 1, ['product_type', 'liquid_ml', 'duty_liable'], false], [$s['result'], $s['version'], $s['changed'], $s['unconfirmed']]);
        self::assertSame(['warn', ['trpr_tank_ml']], [$compliance->statusOf([$tank])[$tank]['level'], $compliance->statusOf([$tank])[$tank]['breaches']]);
        self::assertSame([], $compliance->blocked([$tank, $other]), 'a warning blocks nothing');
        $compliance->assertAllowed([$tank], 'receive');

        // Confirming a card that breaks a rule needs the person to say so: it blocks the item.
        $e = self::refused(422, 'card_breaches', fn () => $this->cards->confirm($who, $tank, 1));
        self::assertSame(['trpr_tank_ml'], $e->detail['rules']);
        self::assertStringContainsString('Confirming them BLOCKS the item', $e->getMessage());
        $c = $this->cards->confirm($who, $tank, 1, true);
        self::assertSame(['confirmed', 2, ['trpr_tank_ml'], true], [$c['result'], $c['version'], $c['breaches'], $c['first']]);
        self::assertSame([$tank => ['trpr_tank_ml']], $compliance->blocked([$tank, $other]));
        $blocked = self::refused(422, 'item_blocked', fn () => $compliance->assertAllowed([$other, $tank], 'receive'));
        self::assertSame([sprintf('CW-%06d', $tank) => ['trpr_tank_ml']], $blocked->detail['items']);
        self::assertStringContainsString('cannot be received', $blocked->getMessage());
        self::refused(422, 'item_blocked', fn () => $compliance->assertAllowed([$tank], 'sell'));
        self::assertSame('already', $this->cards->confirm($who, $tank, 2, true)['result'], 'nothing written twice');
        $row = $this->row($tank);
        self::assertSame([(string) $who->staffUserId, '["trpr_tank_ml"]'], [(string) $row['confirmed_by'], (string) $row['confirmed_breaches']]);

        // Corrected to 2 ml: no longer confirmed, but STILL blocked: only a person's new confirmation lifts it (I113).
        $fix = $this->cards->save($who, $tank, 2, ['liquid_ml' => '2']);
        self::assertSame(['saved', 3, true], [$fix['result'], $fix['version'], $fix['unconfirmed']]);
        self::assertNull($this->row($tank)['confirmed_at']);
        self::assertNotNull($this->row($tank)['first_confirmed_at']);
        self::assertSame('["trpr_tank_ml"]', (string) $this->row($tank)['confirmed_breaches'], 'the last confirmation\'s rules are kept');
        self::assertSame(['block', ['trpr_tank_ml'], []], [$compliance->statusOf([$tank])[$tank]['level'], $compliance->statusOf([$tank])[$tank]['blocked'],
            $compliance->statusOf([$tank])[$tank]['warnings']]);
        self::refused(422, 'item_blocked', fn () => $compliance->assertAllowed([$tank], 'order'));
        $lift = $this->cards->confirm($who, $tank, 3);
        self::assertSame(['confirmed', [], false, ['trpr_tank_ml']], [$lift['result'], $lift['breaches'], $lift['first'], $lift['lifted']], 'no acknowledgement: nothing broken');
        self::assertNull($compliance->statusOf([$tank])[$tank]['level']);
        self::assertNull($this->row($tank)['confirmed_breaches']);
        // Set back to 5 ml: a warning until a person confirms it again (an edit never blocks without the acknowledgement).
        $this->cards->save($who, $tank, 4, ['liquid_ml' => '5.0']);
        self::assertSame(['warn', [], ['trpr_tank_ml']], [$compliance->statusOf([$tank])[$tank]['level'], $compliance->statusOf([$tank])[$tank]['blocked'],
            $compliance->statusOf([$tank])[$tank]['warnings']]);
        self::refused(422, 'card_breaches', fn () => $this->cards->confirm($who, $tank, 5));
        self::assertSame(['trpr_tank_ml'], $this->cards->confirm($who, $tank, 5, true)['breaches']);
        self::assertSame('block', $compliance->statusOf([$tank])[$tank]['level']);

        // History and audit: one row per write.
        $h = $this->cards->history($tank);
        self::assertSame([6, 5, 4, 3, 2, 1], array_column($h, 'version'));
        self::assertSame(['confirm', 'change', 'confirm', 'change', 'confirm', 'change'], array_column($h, 'kind'));
        self::assertSame(['2.0', '5.0'], [$h[1]['changes']['liquid_ml']['before'], $h[1]['changes']['liquid_ml']['after']]);
        self::assertEquals(['breaches' => [], 'first' => false, 'lifted' => ['trpr_tank_ml']], $h[2]['detail']);
        self::assertEquals(['breaches' => ['trpr_tank_ml'], 'first' => true], $h[4]['detail']);
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'item_card.change' AND entity_id = ?", [(string) $tank]));
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'item_card.confirm' AND entity_id = ?", [(string) $tank]));
    }

    /** The review probes (I113): emptying, retyping or "not known" never lifts a block; only a person's new confirmation does. */
    public function testNoEditLiftsABlock(): void
    {
        $who = $this->editor();
        $compliance = new ItemCompliance(self::$db);
        $cases = [
            'nicotine emptied' => [['product_type' => 'e_liquid', 'liquid_ml' => '12', 'nicotine_mg' => '20', 'duty_liable' => 'yes'], ['nicotine_mg' => ''], 'trpr_refill_ml'],
            'ml emptied' => [['product_type' => 'e_liquid', 'liquid_ml' => '12', 'nicotine_mg' => '20', 'duty_liable' => 'yes'], ['liquid_ml' => ''], 'trpr_refill_ml'],
            'retyped as an accessory' => [['product_type' => 'tank', 'liquid_ml' => '5', 'duty_liable' => 'no'], ['product_type' => 'accessory'], 'trpr_tank_ml'],
            'single-use "not known"' => [['product_type' => 'device_kit', 'liquid_ml' => '2', 'single_use' => 'yes', 'duty_liable' => 'no'], ['single_use' => ''], 'single_use'],
        ];
        foreach ($cases as $why => [$values, $edit, $rule]) {
            $sku = $this->item('legacy', 0, $why);
            $this->cards->save($who, $sku, 0, $values);
            $this->cards->confirm($who, $sku, 1, true);
            self::assertSame([$rule], $compliance->blocked([$sku])[$sku] ?? null, $why);
            $this->cards->save($who, $sku, 2, $edit);
            self::assertSame([$rule], $compliance->blocked([$sku])[$sku] ?? null, "{$why}: still blocked");
            self::refused(422, 'item_blocked', fn () => $compliance->assertAllowed([$sku], 'receive'));
            self::assertSame([$rule], $compliance->receiving([$sku])[$sku]['blocked'], $why);
            if ($edit !== ['product_type' => 'accessory']) {
                $e = self::refused(422, 'card_incomplete', fn () => $this->cards->confirm($who, $sku, 3, true));
                self::assertNotSame([], $e->detail['missing'], "{$why}: a confirmation needs the field again");
            }
        }
        // Corrected and confirmed by a person: lifted.
        $sku = (int) self::$db->value('SELECT id FROM sku WHERE name = ?', ['retyped as an accessory']);
        $this->cards->save($who, $sku, 3, ['product_type' => 'tank', 'liquid_ml' => '2']);
        self::assertSame(['trpr_tank_ml'], $this->cards->confirm($who, $sku, 4)['lifted']);
        self::assertSame([], $compliance->blocked([$sku]));
    }

    public function testAKitNeedsItsTankCapacityAndZeroMeansNone(): void
    {
        $who = $this->editor();
        $kit = $this->item('legacy', 0, 'Pod kit');
        $this->cards->save($who, $kit, 0, ['product_type' => 'device / kit', 'single_use' => 'no', 'duty_liable' => 'no']);
        $e = self::refused(422, 'card_incomplete', fn () => $this->cards->confirm($who, $kit, 1));
        self::assertSame(['liquid_ml'], $e->detail['missing'], 'the 2 ml rule needs the kit\'s capacity');
        $this->cards->save($who, $kit, 1, ['liquid_ml' => '3']);
        self::assertSame(['trpr_tank_ml'], self::refused(422, 'card_breaches', fn () => $this->cards->confirm($who, $kit, 2))->detail['rules']);
        $this->cards->save($who, $kit, 2, ['liquid_ml' => '0']);
        self::assertSame(['confirmed', []], array_values(array_intersect_key($this->cards->confirm($who, $kit, 3), ['result' => 1, 'breaches' => 1])),
            'a mod or battery sold without a tank: 0 ml');
        $tank = $this->item('legacy', 0, 'Tank');
        $e = self::refused(422, 'card_invalid', fn () => $this->cards->save($who, $tank, 0, ['product_type' => 'tank', 'liquid_ml' => '0']));
        self::assertArrayHasKey('liquid_ml', $e->detail['errors']);
        self::refused(422, 'card_invalid', fn () => $this->cards->save($who, $kit, 4, ['product_type' => 'accessory']), '0 ml stays a kit\'s answer');
    }

    public function testAFormSaveKeepsAProposedFlavourProposed(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'Elux 10ml');
        $this->cards->save($who, $sku, 0, ['flavour' => 'Blue Razz'], 'import', ['import_run' => 1]);
        // The card form posts every field: the proposed flavour comes back unchanged with a ticked box.
        $form = ['product_type' => '', 'liquid_ml' => '', 'nicotine_mg' => '', 'duty_liable' => '', 'single_use' => '', 'ecid' => '', 'manufacturer' => '', 'brand' => '',
            'flavour' => 'Blue Razz', 'discontinued' => 'yes'];
        $s = $this->cards->save($who, $sku, 1, $form);
        self::assertSame(['discontinued'], $s['changed']);
        self::assertSame(['Blue Razz', 'proposed'], [$this->row($sku)['flavour'], $this->row($sku)['flavour_status']], 'nobody confirmed it');
        $s = $this->cards->save($who, $sku, 2, ['flavour' => 'Blue Razz Ice'] + $form);
        self::assertSame(['flavour', 'flavour_status'], $s['changed'], 'a flavour typed is confirmed');
        self::assertSame('confirmed', $this->row($sku)['flavour_status']);
    }

    public function testConfirmNeedsTheFieldsAndAnEditor(): void
    {
        $who = $this->editor('mapping_lead');
        $sku = $this->item('legacy', 0, 'Elux 10ml');
        self::refused(422, 'card_incomplete', fn () => $this->cards->confirm($who, $sku, 0));
        $this->cards->save($who, $sku, 0, ['product_type' => 'e_liquid', 'duty_liable' => 'yes']);
        $e = self::refused(422, 'card_incomplete', fn () => $this->cards->confirm($who, $sku, 1));
        self::assertSame(['liquid_ml', 'nicotine_mg'], $e->detail['missing']);
        self::assertStringContainsString('liquid ml, nicotine mg/ml', $e->getMessage());
        $this->cards->save($this->editor('purchasing_manager'), $sku, 1, ['liquid_ml' => '10', 'nicotine_mg' => '20']);
        self::assertSame([], $this->cards->confirm($who, $sku, 2)['breaches']);
        self::assertNull((new ItemCompliance(self::$db))->statusOf([$sku])[$sku]['level']);

        $other = $this->item('legacy', 0, 'Other');
        foreach ([['buyer', 'role_not_allowed'], ['reviewer', 'role_not_allowed'], ['viewer', 'role_not_allowed']] as [$role, $code]) {
            self::refused(403, $code, fn () => $this->cards->save($this->staffUser($role), $other, 0, ['brand' => 'X']));
        }
        $e = self::refused(403, 'admin_cannot_edit', fn () => $this->cards->save($this->staffUser(['admin', 'auditor']), $other, 0, ['brand' => 'X']));
        self::assertStringContainsString('Admin manages people and roles only', $e->getMessage());
        self::refused(403, 'staff_required', fn () => $this->cards->save(Caller::system('test'), $other, 0, ['brand' => 'X']));
        self::refused(403, 'staff_not_allowed', fn () => $this->cards->save($this->staffUser('stock_controller', false), $other, 0, ['brand' => 'X']));
        self::refused(403, 'role_not_allowed', fn () => $this->cards->confirm($this->staffUser('buyer'), $sku, 3));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_card WHERE sku_id = ?', [$other]));
    }

    public function testVersionsNothingChangedAndRefusedValues(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'Pod');
        self::refused(409, 'card_changed', fn () => $this->cards->save($who, $sku, 3, ['brand' => 'X']));
        $this->cards->save($who, $sku, 0, ['brand' => 'Elux', 'product_type' => 'prefilled pod']);
        $e = self::refused(409, 'card_changed', fn () => $this->cards->save($who, $sku, 0, ['brand' => 'Other']));
        self::assertSame(1, $e->detail['version']);
        self::assertSame(['unchanged', 1], array_values(array_intersect_key($this->cards->save($who, $sku, 1, ['brand' => ' Elux ', 'product_type' => 'Prefilled Pod']),
            ['result' => 1, 'version' => 1])));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_card_change WHERE sku_id = ?', [$sku]), 'nothing changed writes nothing');
        $e = self::refused(422, 'card_invalid', fn () => $this->cards->save($who, $sku, 1, ['product_type' => 'single-use vape']));
        self::assertArrayHasKey('single_use', $e->detail['errors'], 'a single-use type needs a person to say single-use: yes');
        self::assertSame('saved', $this->cards->save($who, $sku, 1, ['product_type' => 'single-use vape', 'single_use' => 'yes'])['result']);
        self::refused(422, 'card_invalid', fn () => $this->cards->save($who, $sku, 2, ['single_use' => 'no']), 'the type and the answer agree');
        self::refused(404, 'unknown_item', fn () => $this->cards->save($who, $sku + 999, 0, ['brand' => 'X']));
        $merged = $this->item('legacy', 0, 'Merged');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$sku, $merged]);
        self::refused(409, 'merged_item', fn () => $this->cards->save($who, $merged, 0, ['brand' => 'X']));
    }

    public function testDiscontinuedAndTheFlavourStatus(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'Coil pack');
        $this->cards->save($who, $sku, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        $this->cards->confirm($who, $sku, 1);
        $d = $this->cards->save($who, $sku, 2, ['discontinued' => 'yes']);
        self::assertSame([['discontinued'], false], [$d['changed'], $d['unconfirmed']], 'a buying flag does not unconfirm the card');
        self::assertNotNull($this->row($sku)['confirmed_at']);
        self::assertTrue((new ItemCompliance(self::$db))->statusOf([$sku])[$sku]['discontinued']);

        // A flavour from a file is proposed (and unconfirms the card); accepting it confirms it; typing one confirms it.
        $i = $this->cards->save($who, $sku, 3, ['flavour' => 'Blue Razz'], 'import', ['import_run' => 1]);
        self::assertSame([['flavour', 'flavour_status'], true], [$i['changed'], $i['unconfirmed']]);
        self::assertSame(['Blue Razz', 'proposed'], [$this->row($sku)['flavour'], $this->row($sku)['flavour_status']]);
        self::assertSame('unchanged', $this->cards->save($who, $sku, 4, ['flavour' => 'Blue Razz'], 'import')['result'], 'the same flavour from a file again');
        $a = $this->cards->accept($who, $sku, 4, 'flavour', 'Blue Razz');
        self::assertSame(['flavour_status'], $a['changed']);
        self::assertSame('confirmed', $this->row($sku)['flavour_status']);
        self::refused(409, 'proposal_gone', fn () => $this->cards->accept($who, $sku, 5, 'flavour', 'Blue Razz'), 'nothing left to accept');
        $this->cards->save($who, $sku, 5, ['flavour' => 'Cola'], 'import');
        self::assertSame('proposed', $this->row($sku)['flavour_status']);
        $this->cards->confirm($who, $sku, 6);
        self::assertSame('confirmed', $this->row($sku)['flavour_status'], 'confirming the card confirms the flavour on it');
        $this->cards->save($who, $sku, 7, ['flavour' => 'Mint']);
        self::assertSame(['Mint', 'confirmed'], [$this->row($sku)['flavour'], $this->row($sku)['flavour_status']], 'typed on the screen: confirmed');
        $this->cards->save($who, $sku, 8, ['flavour' => '']);
        self::assertSame([null, null], [$this->row($sku)['flavour'], $this->row($sku)['flavour_status']]);
    }

    public function testSuggestionsFromTheMatcherAndTheLinkedListingsAreAcceptedOneByOne(): void
    {
        $who = $this->editor();
        $site = $this->site('vpg');
        $alt = $this->site('alt');
        $sku = $this->item('legacy', 0, 'Elux Legend Blue Razz 10ml 20mg');
        self::$db->exec("UPDATE sku SET brand = 'Elux', strength_mg = 20, volume_ml = 10, form = 'nic_salt', flavour = 'blue razz' WHERE id = ?", [$sku]);
        $this->linked($site, 'P1', $sku, [], 1, ['strength_mg' => 20.0, 'volume_ml' => 10.0, 'form' => 'nic_salt', 'flavour_tokens' => ['blue', 'razz'], 'brand_raw' => 'Elux']);
        $this->linked($alt, 'A1', $sku, [], 1, ['strength_mg' => 10.0, 'volume_ml' => 10.0, 'form' => 'e_liquid', 'flavour_tokens' => ['blue', 'razz', 'ice']]);
        $this->linked($alt, 'A2', null, [], 1, ['strength_mg' => 50.0, 'form' => 'tank']);
        $this->linked($alt, 'A3', $sku, [], 1, ['form' => 'disposable', 'strength_mg' => 20.0], 'Elux 600 disposable');
        $this->linked($alt, 'A4', $sku, [], 1, null, 'Elux Legend Nic Salt 10ml - 20mg Blue Razz');

        $p = (new CardProposals(self::$db))->of($sku);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_card'), 'suggestions are never written by themselves');
        self::assertSame(['20.00', '10.00'], array_column($p['fields']['nicotine_mg'], 'value'), 'the value most sources give first');
        self::assertContains("the item's identity card (filled by the matcher)", $p['fields']['nicotine_mg'][0]['sources']);
        self::assertContains('listing vpg P1 "Listing P1"', $p['fields']['nicotine_mg'][0]['sources']);
        self::assertSame(['10.0'], array_column($p['fields']['liquid_ml'], 'value'));
        self::assertSame(['e_liquid'], array_column($p['fields']['product_type'], 'value'), 'nic_salt and e_liquid are both an e-liquid; a disposable proposes no type');
        self::assertSame('Blue Razz', $p['fields']['flavour'][0]['value'], 'the matcher and a listing agree');
        self::assertContains('Blue Razz Ice', array_column($p['fields']['flavour'], 'value'), 'another listing says more: shown too, a person picks');
        self::assertSame(['Elux'], array_column($p['fields']['brand'], 'value'));
        self::assertArrayNotHasKey('duty_liable', $p['fields'], 'no product type on the card yet');
        self::assertContains('nicotine_mg', $p['disagree']);
        self::assertSame(['listing alt A3 "Elux 600 disposable"'], $p['disposable_sources']);
        self::assertArrayNotHasKey('single_use', $p['fields'], 'single-use is never proposed');
        foreach ($p['fields'] as $values) {
            foreach ($values as $v) {
                self::assertStringNotContainsString('A2', implode(' ', $v['sources']), 'an unlinked listing says nothing about the item');
            }
        }
        self::assertContains('listing alt A4 "Elux Legend Nic Salt 10ml - 20mg Blue Razz"', $p['fields']['nicotine_mg'][0]['sources'], 'no stored features: the normaliser reads the titles');

        $a = $this->cards->accept($who, $sku, 0, 'nicotine_mg', '20.00');
        self::assertSame(['saved', 1, ['nicotine_mg']], [$a['result'], $a['version'], $a['changed']]);
        $h = $this->cards->history($sku)[0];
        self::assertSame('accept', $h['kind']);
        self::assertSame('nicotine_mg', $h['detail']['field']);
        self::assertContains("the item's identity card (filled by the matcher)", $h['detail']['sources']);
        self::refused(409, 'proposal_gone', fn () => $this->cards->accept($who, $sku, 1, 'nicotine_mg', '35'));
        self::refused(400, 'bad_field', fn () => $this->cards->accept($who, $sku, 1, 'single_use', 'yes'));
        $this->cards->accept($who, $sku, 1, 'product_type', 'e_liquid');
        $p = (new CardProposals(self::$db))->of($sku);
        self::assertSame(['10.00'], array_column($p['fields']['nicotine_mg'], 'value'), 'a value on the card is not offered again');
        self::assertSame([1], array_column($p['fields']['duty_liable'], 'value'), 'an e-liquid: duty-liable yes is suggested');
        self::assertStringContainsString('Vaping Products Duty applies to all vaping liquids', $p['fields']['duty_liable'][0]['sources'][0]);
        $this->cards->accept($who, $sku, 2, 'duty_liable', '1');
        self::assertSame([1, '20.00', 'e_liquid'], [(int) $this->row($sku)['duty_liable'], $this->row($sku)['nicotine_mg'], $this->row($sku)['product_type']]);
    }

    public function testWhatReceivingAsks(): void
    {
        $who = $this->editor();
        [$none, $dry, $liquid, $warned] = [$this->item('legacy'), $this->item('legacy'), $this->item('legacy'), $this->item('legacy')];
        $this->cards->save($who, $dry, 0, ['product_type' => 'coil', 'duty_liable' => 'no']);
        $this->cards->save($who, $liquid, 0, ['product_type' => 'e_liquid', 'duty_liable' => 'yes', 'discontinued' => 'yes']);
        $this->cards->save($who, $warned, 0, ['nicotine_mg' => '25']);
        $r = (new ItemCompliance(self::$db))->receiving([$none, $dry, $liquid, $warned]);
        self::assertSame(['blocked' => [], 'warnings' => [], 'stamp_required' => true, 'duty_unknown' => true, 'discontinued' => false], $r[$none],
            'no card: treated as duty-liable (decision 8: unstamped deliveries are refused)');
        self::assertSame([false, false], [$r[$dry]['stamp_required'], $r[$dry]['duty_unknown']]);
        $coil = $this->item('legacy');
        $this->cards->save($who, $coil, 0, ['product_type' => 'coil']);
        self::assertSame([false, true], array_values(array_intersect_key((new ItemCompliance(self::$db))->receiving([$coil])[$coil], ['stamp_required' => 1,
            'duty_unknown' => 1])), 'a coil holds no liquid: no stamp asked for, the missing answer still flagged (I119)');
        self::assertSame([true, false, true], [$r[$liquid]['stamp_required'], $r[$liquid]['duty_unknown'], $r[$liquid]['discontinued']]);
        self::assertSame([[], ['trpr_nicotine']], [$r[$warned]['blocked'], $r[$warned]['warnings']]);
    }

    /** The SQL form of the rules (the list's "breaks a rule" filter) says what breaches() says, card by card. */
    public function testTheSqlRuleMatchesThePhpRule(): void
    {
        $who = $this->editor();
        $cases = [
            ['product_type' => 'e_liquid', 'liquid_ml' => '10', 'nicotine_mg' => '20'], ['product_type' => 'e_liquid', 'liquid_ml' => '10.1', 'nicotine_mg' => '3'],
            ['product_type' => 'shortfill', 'liquid_ml' => '100', 'nicotine_mg' => '0'], ['product_type' => 'shortfill', 'liquid_ml' => '60'],
            ['product_type' => 'nic_shot', 'liquid_ml' => '10', 'nicotine_mg' => '20.01'], ['product_type' => 'tank', 'liquid_ml' => '2'],
            ['product_type' => 'tank', 'liquid_ml' => '2.1'], ['product_type' => 'prefilled_pod', 'liquid_ml' => '2', 'nicotine_mg' => '20'],
            ['product_type' => 'device_kit', 'liquid_ml' => '3'], ['product_type' => 'single_use', 'single_use' => 'yes'], ['single_use' => 'yes'],
            ['single_use' => 'no', 'product_type' => 'device_kit'], ['nicotine_mg' => '50'], ['liquid_ml' => '50', 'nicotine_mg' => '18'], ['brand' => 'X'],
            ['product_type' => 'coil', 'liquid_ml' => '50', 'nicotine_mg' => '18'], ['product_type' => 'accessory'],
            ['product_type' => 'device_kit', 'liquid_ml' => '0', 'single_use' => 'no'],
        ];
        $want = [];
        foreach ($cases as $i => $c) {
            $sku = $this->item('legacy', 0, "Case {$i}");
            $this->cards->save($who, $sku, 0, $c);
            $want[$sku] = ItemRules::breaches($this->cards->card($sku)) === [] ? 0 : 1;
        }
        $got = [];
        foreach (self::$db->all('SELECT c.sku_id, ' . ItemRules::sqlBreach('c') . ' AS b FROM item_card c') as $r) {
            $got[(int) $r['sku_id']] = (int) $r['b'];
        }
        ksort($got);
        self::assertSame($want, $got);
        self::assertSame(7, array_sum($want), 'the cases cover both answers');

        // The block (and the "warned or blocked" filter) in SQL says what ItemRules::status says, confirmed and edited cards included.
        $skus = array_keys($want);
        foreach ([1, 0, 6] as $i) {
            $this->cards->save($who, $skus[$i], 1, ['duty_liable' => 'yes']);
        }
        $this->cards->confirm($who, $skus[1], 2, true);  // e-liquid 10.1 ml: blocked
        $this->cards->confirm($who, $skus[0], 2);        // compliant
        $this->cards->save($who, $skus[0], 3, ['nicotine_mg' => '25']); // then broken by an edit: a warning
        $this->cards->confirm($who, $skus[6], 2, true);  // tank 2.1 ml: blocked
        $this->cards->save($who, $skus[6], 3, ['liquid_ml' => '2']); // corrected, not confirmed again: still blocked
        $level = [];
        foreach ($skus as $sku) {
            $level[$sku] = ItemRules::level($this->cards->card($sku));
        }
        $sql = [];
        foreach (self::$db->all('SELECT c.sku_id, ' . ItemRules::sqlBlocked('c') . ' AS blocked, ' . ItemRules::sqlFlagged('c') . ' AS flagged FROM item_card c') as $r) {
            $sql[(int) $r['sku_id']] = [(int) $r['blocked'], (int) $r['flagged']];
        }
        foreach ($skus as $sku) {
            self::assertSame([$level[$sku] === 'block' ? 1 : 0, $level[$sku] !== null ? 1 : 0], $sql[$sku], "item {$sku}");
        }
        self::assertSame(['block', 'warn', 'block'], [$level[$skus[1]], $level[$skus[0]], $level[$skus[6]]]);
    }

    public function testTheInvariantsFindACardChangedOutsideCw(): void
    {
        $who = $this->editor();
        $sku = $this->item('legacy', 0, 'Watched');
        $this->cards->save($who, $sku, 0, ['product_type' => 'tank', 'liquid_ml' => '5', 'duty_liable' => 'no']);
        $this->cards->confirm($who, $sku, 1, true);
        self::assertSame([], Invariants::check(self::$db));
        self::$db->exec('UPDATE item_card SET liquid_ml = 2.0, first_confirmed_at = NULL, confirmed_at = NULL, confirmed_actor = NULL, confirmed_by = NULL, '
            . 'confirmed_breaches = NULL WHERE sku_id = ?', [$sku]);
        $v = Invariants::check(self::$db);
        self::assertCount(1, $v);
        self::assertStringContainsString("item card {$sku} version 2: liquid_ml, confirmed_by, confirmed_at, confirmed_breaches, first_confirmed_at differ from its history", $v[0]);
        self::$db->exec('UPDATE item_card SET version = 3 WHERE sku_id = ?', [$sku]);
        self::assertStringContainsString("item card {$sku}: version 3 but its history has 2 rows", implode("\n", Invariants::check(self::$db)));
        // Put it back as CW wrote it (the post-condition checks every invariant).
        $snap = json_decode((string) self::$db->value('SELECT card FROM item_card_change WHERE sku_id = ? AND version = 2', [$sku]), true);
        self::$db->exec('UPDATE item_card SET version = 2, liquid_ml = ?, confirmed_by = ?, confirmed_actor = ?, confirmed_at = ?, first_confirmed_at = ?, '
            . "confirmed_breaches = '[\"trpr_tank_ml\"]' WHERE sku_id = ?", [$snap['liquid_ml'], $snap['confirmed_by'], $who->actor, $snap['confirmed_at'],
                $snap['first_confirmed_at'], $sku]);
        self::assertSame([], Invariants::check(self::$db));
    }
}
