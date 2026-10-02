<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Suppliers;

use CW\Suppliers\SupplierItems;

/**
 * Supplier items and prices (spec §5.4, I43-I44): create (any supplier status; the item exists and is not merged),
 * duplicate pack and supplier code refused, the manual price (history row, half-up unit price, the last-price rule),
 * the preferred supply of an item (one at a time), updates with the version, and who may change them.
 */
final class SupplierItemsTest extends SupplierTestCase
{
    public function testCreateAndTheRefusals(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->draft($buyer);
        $sku = self::makeSku('Elux Legend 3500 Blue Razz 20mg');
        $i = $this->items->create($buyer, (int) $s['id'], $sku, ['supplier_code' => ' ELX-BR-20 ', 'purchase_unit' => 'box', 'units_per_pack' => '10',
            'moq_packs' => '5', 'order_multiple_packs' => '5', 'lead_days' => '2']);
        self::assertSame(['ELX-BR-20', 'box', 10, 5, 5, 2, 0, 1, null, 1, $buyer->staffUserId], [$i['supplier_code'], $i['purchase_unit'], (int) $i['units_per_pack'],
            (int) $i['moq_packs'], (int) $i['order_multiple_packs'], (int) $i['lead_days'], (int) $i['is_preferred'], (int) $i['is_active'], $i['last_pack_price'],
            (int) $i['version'], (int) $i['created_by']]);
        self::assertSame('draft', $s['status'], 'a buyer prepares the items while the activation waits');
        $d = $this->items->create($buyer, (int) $s['id'], $sku, []);
        self::assertSame(['each', 1, 1, 1, null, null], [$d['purchase_unit'], (int) $d['units_per_pack'], (int) $d['moq_packs'], (int) $d['order_multiple_packs'],
            $d['lead_days'], $d['supplier_code']], 'the defaults');

        self::assertSame('this supplier already has this item in packs of 10',
            self::refused(409, 'duplicate_pack', fn () => $this->items->create($buyer, (int) $s['id'], $sku, ['units_per_pack' => '10']))->getMessage());
        self::refused(409, 'duplicate_supplier_code', fn () => $this->items->create($buyer, (int) $s['id'], self::makeSku('Other'), ['supplier_code' => 'elx-br-20']));
        $merged = self::makeSku('Old copy');
        self::$db->exec('UPDATE sku SET merged_into_sku_id = ? WHERE id = ?', [$sku, $merged]);
        $e = self::refused(422, 'merged_item', fn () => $this->items->create($buyer, (int) $s['id'], $merged, []));
        self::assertStringContainsString('was merged into', $e->getMessage());
        self::refused(422, 'unknown_sku', fn () => $this->items->create($buyer, (int) $s['id'], 999_999, []));
        self::refused(404, 'unknown_supplier', fn () => $this->items->create($buyer, 999_999, $sku, []));
        foreach ([['units_per_pack' => '0'], ['units_per_pack' => '100001'], ['moq_packs' => 'x'], ['lead_days' => '121'], ['is_preferred' => 'perhaps'],
            ['purchase_unit' => str_repeat('b', 33)]] as $bad) {
            self::refused(422, 'bad_field', fn () => $this->items->create($buyer, (int) $s['id'], $sku, $bad + ['units_per_pack' => '48']));
        }
        self::refused(400, 'bad_field', fn () => $this->items->create($buyer, (int) $s['id'], $sku, ['last_pack_price' => '1']));
        self::refused(403, 'role_not_allowed', fn () => $this->items->create($this->staffUser('reviewer'), (int) $s['id'], $sku, ['units_per_pack' => '48']));
        self::assertSame(['supplier_item.create'], array_map('strval', self::$db->column(
            "SELECT action FROM audit_log WHERE entity_type = 'supplier_item' AND entity_id = ?", [(string) $i['id']])));
    }

    public function testManualPricesAndTheLastPriceRule(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer);
        $i = $this->items->create($buyer, (int) $s['id'], self::makeSku('Item'), ['purchase_unit' => 'box', 'units_per_pack' => '24']);
        $i = $this->items->recordPrice($buyer, (int) $i['id'], '£1,000.5', self::day('-5 days'), 'price list Sep');
        self::assertSame(['1000.5000', self::day('-5 days'), 'manual'], [$i['last_pack_price'], $i['last_price_on'], $i['last_price_source']]);
        $h = self::$db->one('SELECT * FROM supplier_item_price WHERE supplier_item_id = ?', [(int) $i['id']]);
        self::assertSame(['1000.5000', 24, '41.687500', 'manual', null, 'price list Sep', $buyer->staffUserId, $buyer->actor],
            [$h['pack_price'], (int) $h['units_per_pack'], $h['unit_price'], $h['source'], $h['source_ref'], $h['note'], (int) $h['recorded_by'], $h['recorded_actor']]);

        $i = $this->items->recordPrice($buyer, (int) $i['id'], '990', self::day('-30 days'), null);
        self::assertSame(['1000.5000', self::day('-5 days')], [$i['last_pack_price'], $i['last_price_on']], 'an older price goes into the history only');
        $i = $this->items->recordPrice($buyer, (int) $i['id'], '0.0001', self::day('-5 days'), null);
        self::assertSame('0.0001', $i['last_pack_price'], 'the same date: the newer row wins');
        self::assertSame('0.000004', self::$db->value('SELECT unit_price FROM supplier_item_price WHERE supplier_item_id = ? ORDER BY id DESC LIMIT 1', [(int) $i['id']]),
            '0.0001 / 24 = 0.00000417, half-up to 6 decimals');
        $i = $this->items->recordPrice($buyer, (int) $i['id'], '995.00', null, null);
        self::assertSame(['995.0000', self::day('today')], [$i['last_pack_price'], $i['last_price_on']], 'no date: today (UK)');
        self::assertSame(4, (int) self::$db->value('SELECT COUNT(*) FROM supplier_item_price WHERE supplier_item_id = ?', [(int) $i['id']]));

        self::refused(422, 'bad_field', fn () => $this->items->recordPrice($buyer, (int) $i['id'], '1', self::day('+1 day'), null));
        foreach (['-1', '1.23456', 'abc', '', '12345678901'] as $bad) {
            self::refused(422, 'bad_price', fn () => $this->items->recordPrice($buyer, (int) $i['id'], $bad, null, null));
        }
        self::refused(403, 'role_not_allowed', fn () => $this->items->recordPrice($this->staffUser('reviewer'), (int) $i['id'], '1', null, null));
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'supplier_item.price' AND entity_id = ?", [(string) $i['id']]));
    }

    /**
     * Review finding (I74): a supplier item moved from packs of 1 at £1.00 to packs of 24 still said £1.00, so a draft PO
     * priced 48 units at £2. The last price is the price OF A PACK: a new pack size takes the newest price recorded for it.
     */
    public function testAChangedPackDropsTheOldPacksPrice(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->draft($buyer);
        $i = $this->items->create($buyer, (int) $s['id'], self::makeSku('Pack change'), ['units_per_pack' => '1'], ['pack_price' => '1.00']);
        self::assertSame('1.0000', $i['last_pack_price']);
        $i = $this->items->update($buyer, (int) $i['id'], (int) $i['version'], ['units_per_pack' => '24']);
        self::assertSame([24, null, null, null], [(int) $i['units_per_pack'], $i['last_pack_price'], $i['last_price_on'], $i['last_price_source']]);
        self::assertSame([], \CW\Suppliers\SupplierInvariants::check(self::$db));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'supplier_item.update' AND entity_id = ? ORDER BY id DESC LIMIT 1",
            [(string) $i['id']]), true);
        self::assertSame(['1.0000', null], $audit['changed']['last_pack_price']);
        $i = $this->items->recordPrice($buyer, (int) $i['id'], '21.60', null, null);
        self::assertSame('21.6000', $i['last_pack_price']);
        // Back to packs of 1: the price recorded for that size comes back.
        $i = $this->items->update($buyer, (int) $i['id'], (int) $i['version'], ['units_per_pack' => '1']);
        self::assertSame([1, '1.0000', 'manual'], [(int) $i['units_per_pack'], $i['last_pack_price'], $i['last_price_source']]);
        self::assertSame([], \CW\Suppliers\SupplierInvariants::check(self::$db));
        // S4 catches a last price of another pack size.
        self::$db->exec("UPDATE supplier_item SET units_per_pack = 24 WHERE id = ?", [(int) $i['id']]);
        self::assertStringContainsString('but its newest price is 21.6000', implode(' ', \CW\Suppliers\SupplierInvariants::check(self::$db)));
        self::$db->exec("UPDATE supplier_item SET units_per_pack = 1 WHERE id = ?", [(int) $i['id']]);
    }

    public function testUnitPriceIsHalfUpWithIntegers(): void
    {
        foreach ([['1.0000', 3, '0.333333'], ['2.0000', 3, '0.666667'], ['0.0001', 3, '0.000033'], ['10.0000', 24, '0.416667'], ['0.0005', 1000, '0.000001'],
            ['0.0004', 1000, '0.000000'], ['9999999999.9999', 1, '9999999999.999900'], ['12.5', 1, '12.500000'], ['0', 7, '0.000000'], ['1.0000', 8, '0.125000']] as [$p, $u, $want]) {
            self::assertSame($want, SupplierItems::unitPrice($p, $u), "{$p} / {$u}");
        }
        self::assertSame('1.5000', SupplierItems::packPrice('1.5'));
        self::assertSame('0.5000', SupplierItems::packPrice('.5'));
        self::assertSame('1234.0000', SupplierItems::packPrice('£1,234'));
    }

    public function testThePreferredSupplyOfAnItem(): void
    {
        $buyer = $this->staffUser('buyer');
        $a = $this->activeSupplier($buyer, ['name' => 'Supplier A']);
        $b = $this->activeSupplier($buyer, ['name' => 'Supplier B']);
        $sku = self::makeSku('Preferred item');
        $ia = $this->items->create($buyer, (int) $a['id'], $sku, ['is_preferred' => '1']);
        self::assertSame(1, (int) $ia['is_preferred']);
        $ib = $this->items->create($buyer, (int) $b['id'], $sku, ['is_preferred' => '1', 'units_per_pack' => '6']);
        $preferred = static fn (): array => array_map('intval', self::$db->column('SELECT id FROM supplier_item WHERE sku_id = ? AND preferred_sku_id IS NOT NULL', [$sku]));
        self::assertSame([(int) $ib['id']], $preferred(), 'the newer preferred row unsets the other');
        $ia = $this->items->setPreferred($buyer, (int) $ia['id']);
        self::assertSame([(int) $ia['id']], $preferred());
        self::assertSame($ia, $this->items->setPreferred($buyer, (int) $ia['id']), 'already preferred: nothing written');
        self::refused(409, 'version_conflict', fn () => $this->items->setPreferred($buyer, (int) $ib['id'], true, 99));
        // Through update(): the preferred checkbox; switched off: no longer preferred.
        $ib = $this->items->get((int) $ib['id']);
        $ib = $this->items->update($buyer, (int) $ib['id'], (int) $ib['version'], ['is_preferred' => '1', 'is_active' => '1']);
        self::assertSame([(int) $ib['id']], $preferred());
        $ib = $this->items->update($buyer, (int) $ib['id'], (int) $ib['version'], ['is_active' => '0', 'is_preferred' => '1']);
        self::assertSame([0, 0], [(int) $ib['is_active'], (int) $ib['is_preferred']]);
        self::assertSame([], $preferred());
        self::refused(422, 'supplier_item_inactive', fn () => $this->items->setPreferred($buyer, (int) $ib['id']));
        $ia = $this->items->setPreferred($buyer, (int) $ia['id']);
        $ia = $this->items->setPreferred($buyer, (int) $ia['id'], false);
        self::assertSame([], $preferred());
    }

    public function testUpdateWithTheVersion(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->draft($buyer);
        $i = $this->items->create($buyer, (int) $s['id'], self::makeSku('Item'), ['supplier_code' => 'A1']);
        $i2 = $this->items->create($buyer, (int) $s['id'], self::makeSku('Item 2'), ['supplier_code' => 'A2']);
        self::refused(409, 'version_conflict', fn () => $this->items->update($buyer, (int) $i['id'], 5, ['moq_packs' => '2']));
        $u = $this->items->update($buyer, (int) $i['id'], 1, ['moq_packs' => '2', 'units_per_pack' => '12', 'supplier_description' => 'Box of 12']);
        self::assertSame([2, 2, 12, 'Box of 12'], [(int) $u['version'], (int) $u['moq_packs'], (int) $u['units_per_pack'], $u['supplier_description']]);
        self::assertSame($u, $this->items->update($buyer, (int) $i['id'], 2, ['moq_packs' => '2']), 'nothing changed');
        self::refused(409, 'duplicate_supplier_code', fn () => $this->items->update($buyer, (int) $i2['id'], 1, ['supplier_code' => 'a1']));
        self::refused(403, 'role_not_allowed', fn () => $this->items->update($this->staffUser('purchasing_desk'), (int) $i['id'], 2, ['moq_packs' => '3']));
        self::refused(404, 'unknown_supplier_item', fn () => $this->items->update($buyer, 999_999, 1, []));
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'supplier_item.update' AND entity_id = ?", [(string) $i['id']]), true);
        self::assertEquals(['moq_packs' => [1, 2], 'units_per_pack' => [1, 12], 'supplier_description' => [null, 'Box of 12']], $audit['changed']);
    }
}
