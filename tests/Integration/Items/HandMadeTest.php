<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Items;

use CW\Items\Families;
use CW\Items\HandMade;
use CW\Tests\Support\MappingTestCase;

/**
 * Items made by hand (pack B; owner answer Q10; docs/decisions.md PB1-PB3) through the service: the item, its code from the item number
 * series, its identity, its item card, its barcode and its family in one transaction, audited; a barcode already on another item (or on
 * a variant matched to another item) refused with nothing made; the same name and identity warned about with the other item named,
 * unless the person says it is a different one; the check afterwards (off by default; a second person when on); who may.
 */
final class HandMadeTest extends MappingTestCase
{
    private HandMade $hand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hand = new HandMade(self::$db, $this->ds);
    }

    public function testAnItemIsMadeByHandWithItsCodeIdentityCardBarcodeAndFamily(): void
    {
        $sc = $this->staffUser('stock_controller');
        $fam = (new Families(self::$db))->create($sc, 'Trade liquids')['id'];
        $r = $this->hand->create($sc, [
            'card' => ['name' => 'Trade Mango 10ml', 'brand' => 'Acme', 'strength_mg' => '20', 'volume_ml' => '10', 'flavour' => 'mango'],
            'item_card' => ['product_type' => 'e_liquid', 'liquid_ml' => '10', 'nicotine_mg' => '20', 'duty_liable' => 'yes', 'manufacturer' => 'Acme Ltd',
                'discontinued' => 'no'],
            'barcode' => '5012345678900', 'barcode_units' => 1, 'family_id' => $fam,
        ]);
        $id = $r['sku_id'];
        self::assertSame([sprintf('CW-%06d', $id), 'not_asked', []], [$r['code'], $r['check_state'], $r['same']], 'the code is the item number series\'');
        $sku = self::$db->one('SELECT code, name, brand, strength_mg, volume_ml, flavour, origin, origin_listing_id, sell_policy FROM sku WHERE id = ?', [$id]);
        self::assertSame([sprintf('CW-%06d', $id), 'Trade Mango 10ml', 'Acme', '20.00', '10.00', 'mango', 'manual', null, 'legacy'],
            [$sku['code'], $sku['name'], $sku['brand'], (string) $sku['strength_mg'], (string) $sku['volume_ml'], $sku['flavour'], $sku['origin'],
                $sku['origin_listing_id'], $sku['sell_policy']]);
        $card = self::$db->one('SELECT product_type, liquid_ml, nicotine_mg, duty_liable, manufacturer, version FROM item_card WHERE sku_id = ?', [$id]);
        self::assertSame(['e_liquid', '10.0', '20.00', 1, 'Acme Ltd', 1],
            [$card['product_type'], (string) $card['liquid_ml'], (string) $card['nicotine_mg'], (int) $card['duty_liable'], $card['manufacturer'], (int) $card['version']]);
        self::assertSame([['5012345678900', 1, 1, 'manual']], array_map(static fn (array $b): array => [$b['barcode'], (int) $b['units_per_scan'], (int) $b['is_usable'], $b['source']],
            self::$db->all('SELECT barcode, units_per_scan, is_usable, source FROM sku_barcode WHERE sku_id = ?', [$id])));
        self::assertSame($fam, Families::ofItems(self::$db, [$id])[$id]['id']);
        self::assertSame(['not_asked', $sc->staffUserId], array_values(self::$db->one('SELECT check_state, created_by FROM item_by_hand WHERE sku_id = ?', [$id])));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'item.create' AND entity_id = ?", [(string) $id]), true);
        self::assertSame(['5012345678900', $fam, false], [$audit['barcode'], $audit['family_id'], $audit['anyway']]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM channel_listing'), 'no variant, no link');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ?', [$id]), 'no stock');

        // The name is the one field needed; a bad card value is refused with nothing made.
        $bare = $this->hand->create($sc, ['card' => ['name' => 'Just a name']]);
        self::assertNull(self::$db->value('SELECT sku_id FROM item_card WHERE sku_id = ?', [$bare['sku_id']]), 'no card until something is entered');
        $count = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        self::refused(422, 'name_required', fn () => $this->hand->create($sc, ['card' => ['name' => '  ']]));
        self::refused(422, 'card_invalid', fn () => $this->hand->create($sc, ['card' => ['name' => 'Bad card'], 'item_card' => ['liquid_ml' => 'lots']]));
        self::refused(422, 'bad_barcode', fn () => $this->hand->create($sc, ['card' => ['name' => 'Bad code'], 'barcode' => '5012345678901']));
        self::refused(422, 'family_off', function () use ($sc, $fam): void {
            (new Families(self::$db))->setActive($sc, $fam, 1, false);
            $this->hand->create($sc, ['card' => ['name' => 'Off family'], 'family_id' => $fam]);
        });
        self::assertSame($count, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'a refusal makes nothing');
    }

    public function testABarcodeAlreadyOnAnotherItemIsRefusedAndNothingIsMade(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $owner = $this->item('legacy', 0, 'Has the barcode');
        self::$db->exec("INSERT INTO sku_barcode (barcode, sku_id, source) VALUES ('5012345678900', ?, 'manual')", [$owner]);
        $count = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $e = self::refused(409, 'barcode_on_other_item', fn () => $this->hand->create($lead, ['card' => ['name' => 'New thing'], 'barcode' => '05012345678900']));
        self::assertSame([$owner, sprintf('CW-%06d', $owner)], [$e->detail['sku_id'], $e->detail['code']], 'the other item is named (any form of the code)');

        // A variant matched to another item carries it (that item's barcodes not copied to it yet): refused too.
        $site = $this->site('vapeandgo', 'off');
        $linked = $this->item('legacy', 0, 'Variant item');
        $l = $this->listing($site, 'V1', $linked);
        self::$db->exec("INSERT INTO listing_profile (listing_id, product_title, barcodes, identity_hash) VALUES (?, 'Thing', JSON_ARRAY('4006381333931'), ?)",
            [$l, str_repeat('a', 64)]);
        $e = self::refused(409, 'barcode_on_other_item', fn () => $this->hand->create($lead, ['card' => ['name' => 'New thing'], 'barcode' => '4006381333931']));
        self::assertSame($linked, $e->detail['sku_id']);
        // An unmatched variant's barcode is no item's: allowed.
        $free = $this->listing($site, 'V2', null);
        self::$db->exec("INSERT INTO listing_profile (listing_id, product_title, barcodes, identity_hash) VALUES (?, 'Free', JSON_ARRAY('9780201379624'), ?)",
            [$free, str_repeat('b', 64)]);
        self::assertSame($count + 1, (int) self::$db->value('SELECT COUNT(*) FROM sku'), 'nothing made by the refusals (one item more: the variant\'s own)');
        $ok = $this->hand->create($lead, ['card' => ['name' => 'New thing'], 'barcode' => '9780201379624']);
        self::assertSame($ok['sku_id'], (int) self::$db->value("SELECT sku_id FROM sku_barcode WHERE barcode = '9780201379624'"));
    }

    public function testTheSameNameAndIdentityIsWarnedAboutUnlessThePersonSaysItIsDifferent(): void
    {
        $pm = $this->staffUser('purchasing_manager');
        $first = $this->hand->create($pm, ['card' => ['name' => 'Coil 0.8 ohm', 'brand' => 'Vapo', 'pack_units' => '5']]);
        $e = self::refused(409, 'same_item_exists', fn () => $this->hand->create($pm, ['card' => ['name' => 'coil 0.8 OHM', 'brand' => ' vapo ', 'pack_units' => 5]]));
        self::assertSame([['id' => $first['sku_id'], 'code' => $first['code'], 'name' => 'Coil 0.8 ohm']], $e->detail['items']);
        // Another identity is another item: no warning.
        self::assertSame([], $this->hand->create($pm, ['card' => ['name' => 'Coil 0.8 ohm', 'brand' => 'Vapo', 'pack_units' => '10']])['same']);
        // The person saw it and says it is a different item: made, and the audit says so.
        $again = $this->hand->create($pm, ['card' => ['name' => 'Coil 0.8 ohm', 'brand' => 'Vapo', 'pack_units' => '5'], 'anyway' => true]);
        self::assertSame([$first['code']], array_column($again['same'], 'code'));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'item.create' AND entity_id = ?", [(string) $again['sku_id']]), true);
        self::assertSame([true, [$first['code']]], [$audit['anyway'], $audit['same']]);
        // A joined item does not count.
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$again['sku_id'], $first['sku_id']]);
        self::assertSame([$again['sku_id']], array_column($this->hand->sameItems(['name' => 'Coil 0.8 ohm', 'brand' => 'Vapo', 'pack_units' => 5]), 'id'));
    }

    /** The review of b62c69f, finding 2: every item of the name is compared, not the first 50 of them. */
    public function testTheSameItemIsFoundAmongManyOfTheSameName(): void
    {
        $lead = $this->staffUser('mapping_lead');
        $ids = [];
        for ($i = 1; $i <= 60; $i++) {
            $ids[] = $id = self::makeSku('Coil pack');
            self::$db->exec('UPDATE sku SET brand = ?, puffs = ? WHERE id = ?', ['Vapo', $i, $id]);
        }
        self::assertSame([$ids[59]], array_column($this->hand->sameItems(['name' => 'Coil pack', 'brand' => 'Vapo', 'puffs' => 60]), 'id'), 'the 60th of the name');
        $e = self::refused(409, 'same_item_exists', fn () => $this->hand->create($lead, ['card' => ['name' => 'coil pack', 'brand' => 'Vapo', 'puffs' => '55']]));
        self::assertSame([$ids[54]], array_column($e->detail['items'], 'id'));
        self::assertSame([], $this->hand->sameItems(['name' => 'Coil pack', 'brand' => 'Vapo', 'puffs' => 61]));
    }

    public function testTheCheckAfterwardsIsOffByDefaultAndASecondPersonChecksWhenItIsOn(): void
    {
        $maker = $this->staffUser('stock_controller');
        $checker = $this->staffUser('mapping_lead');
        $off = $this->hand->create($maker, ['card' => ['name' => 'Made while off']]);
        self::assertSame('not_asked', $off['check_state']);
        self::refused(409, 'not_waiting', fn () => $this->hand->check($checker, $off['sku_id'], null));
        self::assertSame('false', self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'approvals.item_by_hand_check'"),
            'extra approvals start off');
        self::$db->exec("UPDATE app_setting SET value_json = 'true' WHERE setting_key = 'approvals.item_by_hand_check'");
        try {
            $on = $this->hand->create($maker, ['card' => ['name' => 'Made while on']]);
            self::assertSame('waiting', $on['check_state']);
            self::assertSame(1, $this->hand->waitingCount());
            self::assertSame([$on['sku_id']], array_map(static fn (array $r): int => (int) $r['sku_id'], $this->hand->recent(true, 1)['rows']));
            self::refused(403, 'own_item', fn () => $this->hand->check($maker, $on['sku_id'], null));
            self::refused(403, 'role_not_allowed', fn () => $this->hand->check($this->staffUser('buyer'), $on['sku_id'], null));
            self::assertSame('checked', $this->hand->check($checker, $on['sku_id'], 'name and barcode right')['check_state']);
            self::refused(409, 'not_waiting', fn () => $this->hand->check($this->staffUser('mapping_lead'), $on['sku_id'], null));
            self::assertSame(['checked', $checker->staffUserId, 'name and barcode right'],
                array_values(self::$db->one('SELECT check_state, checked_by, check_note FROM item_by_hand WHERE sku_id = ?', [$on['sku_id']])));
            self::assertSame(0, $this->hand->waitingCount());
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = 'false' WHERE setting_key = 'approvals.item_by_hand_check'");
        }
        self::assertSame(2, $this->hand->recent(false, 1)['total']);
    }

    public function testWhoMayMakeAnItemByHand(): void
    {
        foreach (['mapping_lead', 'stock_controller', 'purchasing_manager'] as $role) {
            self::assertSame('manual', self::$db->value('SELECT origin FROM sku WHERE id = ?',
                [$this->hand->create($this->staffUser($role), ['card' => ['name' => "Item by {$role}"]])['sku_id']]), $role);
        }
        foreach (['viewer', 'mapper', 'buyer', 'goods_in', 'purchasing_desk', 'warehouse', 'reviewer', 'auditor'] as $role) {
            self::refused(403, 'role_not_allowed', fn () => $this->hand->create($this->staffUser($role), ['card' => ['name' => "Item by {$role}"]]));
        }
        self::refused(403, 'admin_cannot_edit', fn () => $this->hand->create($this->staffUser('admin'), ['card' => ['name' => 'Item by admin']]));
        self::refused(403, 'staff_required', fn () => $this->hand->create(\CW\Caller::system('test'), ['card' => ['name' => 'Item by a job']]));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name LIKE 'Item by %' AND name NOT IN ('Item by mapping_lead', 'Item by stock_controller', "
            . "'Item by purchasing_manager')"));
    }
}
