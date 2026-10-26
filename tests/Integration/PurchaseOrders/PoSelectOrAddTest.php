<?php

declare(strict_types=1);

namespace CW\Tests\Integration\PurchaseOrders;

use CW\Items\HandMade;
use CW\Suppliers\Suppliers;

/**
 * The owner's "In purchase order view: select or add new option" (15 Oct 2026; pack PO, docs/decisions.md PO1-PO6), the services:
 * a new supplier made where the order is started (a DRAFT supplier, its approval rules unchanged: the order is saved, never confirmed
 * before the supplier is approved), a new supplier put on a draft with no products, and a new item made for a line with Pack B's rules,
 * its supplier item and the line in one transaction.
 */
final class PoSelectOrAddTest extends PurchaseOrderTestCase
{
    public function testANewSupplierIsMadeAsADraftAndTheOrderWaitsForItsApproval(): void
    {
        $buyer = $this->staffUser('buyer');
        $r = $this->pos->createWithNewSupplier($buyer, $this->sup, ['name' => 'Brand New Vapes Ltd', 'email' => 'sales@brandnew.example']);
        $s = $r['supplier'];
        $d = $r['document'];
        self::assertSame(['draft', 'Brand New Vapes Ltd', 'sales@brandnew.example'], [$s['status'], $s['name'], $s['email']]);
        self::assertSame((int) $s['id'], (int) $this->poRow($d->id)['supplier_id']);
        self::assertSame(['draft', 'manual'], [$d->status, $this->poRow($d->id)['source']]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'supplier.create' AND entity_id = ?", [(string) $s['id']]));
        self::assertSame(['document.create', 'po.create'], $this->audits($d->id));

        // The draft is saved with products; confirming it waits for the supplier (the existing rule, unchanged).
        $sku = self::makeSku('New supplier pod');
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, 1, '2.0000', ['supplier_code' => 'BN-1']);
        $d = $this->pos->saveDraft($buyer, $d->id, $d->version, [], [['supplier_item_id' => (int) $si['id'], 'packs' => 3]]);
        self::refused(422, 'supplier_not_active', fn () => $this->pos->approve($buyer, $d->id, $d->version));
        self::assertSame('draft', $this->docs->get($d->id)->status);
        // Approved by a second person: then the order is confirmed.
        $s = $this->sup->update($buyer, (int) $s['id'], (int) $this->sup->get((int) $s['id'])['version'], $this->complete($buyer, ['name' => 'Brand New Vapes Ltd']));
        $s = $this->sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $this->sup->approve($this->staffUser('reviewer'), $this->openTask((int) $s['id']), null);
        self::assertSame('posted', $this->pos->approve($buyer, $d->id, $this->docs->get($d->id)->version)->status);
    }

    public function testWhoMayAddASupplierAndNothingIsLeftHalfMade(): void
    {
        self::refused(403, 'role_not_allowed', fn () => $this->pos->createWithNewSupplier($this->staffUser('purchasing_desk'), $this->sup, ['name' => 'Desk Made Ltd']));
        self::refused(422, 'bad_field', fn () => $this->pos->createWithNewSupplier($this->staffUser('buyer'), $this->sup, ['name' => '  ']));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'PO'"));
    }

    public function testANewSupplierIsPutOnADraftWithoutProductsOnly(): void
    {
        $buyer = $this->staffUser('buyer');
        $old = $this->activeSupplier($buyer, ['name' => 'Old Supplier Ltd']);
        $d = $this->pos->createDraft($buyer, (int) $old['id'], []);
        $r = $this->pos->setNewSupplier($buyer, $this->sup, $d->id, $d->version, ['name' => 'Switched To Ltd']);
        self::assertSame([(int) $r['supplier']['id'], 'draft'], [(int) $this->poRow($d->id)['supplier_id'], $r['supplier']['status']]);
        self::refused(409, 'version_conflict', fn () => $this->pos->setNewSupplier($buyer, $this->sup, $d->id, $d->version, ['name' => 'Stale Ltd']));
        self::refused(403, 'not_creator', fn () => $this->pos->setNewSupplier($this->staffUser('buyer'), $this->sup, $d->id, $r['document']->version, ['name' => 'Other Ltd']));
        // With products: the supplier is fixed, and no supplier is left behind.
        $sku = self::makeSku('Fixed supplier pod');
        $doc = $this->pos->saveDraft($buyer, $d->id, $r['document']->version, [], [['sku_id' => $sku, 'packs' => 1]]);
        $before = (int) self::$db->value('SELECT COUNT(*) FROM supplier');
        self::refused(409, 'supplier_has_lines', fn () => $this->pos->setNewSupplier($buyer, $this->sup, $d->id, $doc->version, ['name' => 'Too Late Ltd']));
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));
    }

    /**
     * PO on one page (docs/decisions.md PX3): "+ Add new supplier" before the order exists makes the supplier alone (a draft supplier, its
     * rules unchanged), for a person who writes orders; and on a draft without products it keeps the order's details and charge typed
     * with it (one transaction: the draft locked first, then the supplier).
     */
    public function testANewSupplierAloneAndOnADraftWithWhatWasTyped(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->pos->newSupplier($buyer, $this->sup, ['name' => 'Alone Vapes Ltd', 'phone' => '0161 111 1111']);
        self::assertSame(['draft', 'Alone Vapes Ltd', '0161 111 1111'], [$s['status'], $s['name'], $s['phone']]);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'PO'"), 'no order with it');
        self::refused(403, 'role_not_allowed', fn () => $this->pos->newSupplier($this->staffUser('purchasing_desk'), $this->sup, ['name' => 'Desk Ltd']));
        self::refused(422, 'bad_field', fn () => $this->pos->newSupplier($buyer, $this->sup, ['name' => ' ']));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM supplier'));

        $old = $this->activeSupplier($buyer, ['name' => 'Before Ltd']);
        $d = $this->pos->createDraft($buyer, (int) $old['id'], []);
        $expected = gmdate('Y-m-d', time() + 9 * 86400);
        $r = $this->pos->setNewSupplier($buyer, $this->sup, $d->id, $d->version, ['name' => 'With Details Ltd'], ['expected_date' => $expected, 'external_ref' => 'Q-42'],
            [['kind' => 'charge', 'description' => 'Delivery', 'pack_price' => '6.00']]);
        self::assertSame([(int) $r['supplier']['id'], $expected], [(int) $this->poRow($d->id)['supplier_id'], (string) $this->poRow($d->id)['expected_date']]);
        self::assertSame('Q-42', $this->docs->get($d->id)->externalRef);
        self::assertSame([['charge', '6.0000']], array_map(static fn (array $l): array => [$l['kind'], $l['pack_price']],
            self::$db->all('SELECT kind, pack_price FROM po_line WHERE document_id = ?', [$d->id])));
    }

    public function testANewItemIsMadeWithItsSupplierItemAndLineInOneGo(): void
    {
        $pm = $this->staffUser('purchasing_manager');
        $s = $this->activeSupplier($pm, ['name' => 'New Item Supplies']);
        $d = $this->pos->createDraft($pm, (int) $s['id'], []);
        $hand = new HandMade(self::$db);
        $r = $this->pos->addNewItem($pm, $d->id, $d->version, $hand, ['card' => ['name' => 'Mystery Pod 2ml', 'brand' => 'Mystery'], 'barcode' => '5060000000016'],
            ['supplier_code' => 'MYS-6', 'units_per_pack' => 6, 'pack_price' => '9.00'], 2);
        self::assertSame(['added', 1], [$r['status'], $r['line_no']]);
        $sku = $r['sku_id'];
        self::assertSame(['Mystery Pod 2ml', 'Mystery', 'manual'], array_values((array) self::$db->one('SELECT name, brand, origin FROM sku WHERE id = ?', [$sku])));
        $si = $this->items->get((int) $r['supplier_item_id']);
        self::assertSame([(int) $s['id'], 'MYS-6', 6, '9.0000', 1], [(int) $si['supplier_id'], $si['supplier_code'], (int) $si['units_per_pack'], $si['last_pack_price'],
            (int) $si['is_preferred']], 'this supplier\'s supplier item, preferred (the item had no main supply)');
        $line = self::$db->one('SELECT pl.supplier_item_id, pl.packs, pl.units_per_pack, pl.pack_price, dl.qty FROM po_line pl JOIN document_line dl ON dl.document_id = pl.document_id '
            . 'AND dl.line_no = pl.line_no WHERE pl.document_id = ?', [$d->id]) ?? [];
        self::assertSame([(int) $si['id'], 2, 6, '9.0000', 12], [(int) $line['supplier_item_id'], (int) $line['packs'], (int) $line['units_per_pack'], $line['pack_price'],
            (int) $line['qty']]);
        self::assertContains('po.new_item', $this->audits($d->id));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'item.create' AND entity_id = ?", [(string) $sku]));
        $version = $this->docs->get($d->id)->version;

        // Pack B's rules: the barcode of another item is refused, and nothing is made.
        $skus = (int) self::$db->value('SELECT COUNT(*) FROM sku');
        $e = self::refused(409, 'barcode_on_other_item', fn () => $this->pos->addNewItem($pm, $d->id, $version, $hand, ['card' => ['name' => 'Another pod'],
            'barcode' => '5060000000016'], [], 1));
        self::assertSame($sku, $e->detail['sku_id']);
        self::assertSame($skus, (int) self::$db->value('SELECT COUNT(*) FROM sku'));
        // The same name (and identity) is answered with the item that is there; "it is a different item" makes it all the same.
        $e = self::refused(409, 'same_item_exists', fn () => $this->pos->addNewItem($pm, $d->id, $version, $hand, ['card' => ['name' => 'Mystery Pod 2ml', 'brand' => 'Mystery']],
            [], 1));
        self::assertSame([$sku], array_column($e->detail['items'], 'id'));
        $r2 = $this->pos->addNewItem($pm, $d->id, $version, $hand, ['card' => ['name' => 'Mystery Pod 2ml', 'brand' => 'Mystery'], 'anyway' => true], [], 4);
        self::assertNull($r2['supplier_item_id'], 'nothing about the supplier given: no supplier item');
        self::assertSame([[null, 4, 1, '0.0000']], array_map(static fn (array $l): array => [$l['supplier_item_id'], (int) $l['packs'], (int) $l['units_per_pack'], $l['pack_price']],
            self::$db->all('SELECT supplier_item_id, packs, units_per_pack, pack_price FROM po_line WHERE document_id = ? AND line_no = 2', [$d->id])));
        self::assertSame(2, count($this->pos->lines($d->id)));
    }

    /**
     * The coordinator's addition of 15 Oct ("make all flow easy"): a new line takes the supplier's own VAT code and the supplier item's
     * last price. Both were already so (PurchaseOrders::normaliseLines); held here for every way a line is added.
     */
    public function testANewLineTakesTheSuppliersVatCodeAndTheLastPrice(): void
    {
        $pm = $this->staffUser('purchasing_manager');
        $s = $this->activeSupplier($pm, ['name' => 'Reduced Rate Supplies', 'default_vat_code' => 'R']);
        $sku = $this->itemWithBarcode('Reduced rate patch', '5060000000030');
        $si = $this->supplierItem($pm, (int) $s['id'], $sku, 10, '4.2500', ['supplier_code' => 'RR-10']);
        $d = $this->pos->createDraft($pm, (int) $s['id'], []);
        $d = $this->pos->addLine($pm, $d->id, $d->version, '5060000000030', 2)['document'];
        $d = $this->pos->addNewItem($pm, $d->id, $d->version, new HandMade(self::$db), ['card' => ['name' => 'Reduced rate new item']], ['pack_price' => '1.10'], 1)['document'];
        $d = $this->pos->saveDraft($pm, $d->id, $d->version, [], [...$this->pos->lines($d->id), ['kind' => 'charge', 'description' => 'Delivery', 'pack_price' => '5.00']]);
        self::assertSame([['item', 'R', '4.2500', (int) $si['id']], ['item', 'R', '1.1000', -1], ['charge', 'R', '5.0000', null]], array_map(static fn (array $l): array
            => [$l['kind'], $l['vat_code'], $l['pack_price'], $l['supplier_item_id'] === null ? null : ((int) $l['supplier_item_id'] === (int) $si['id'] ? (int) $si['id'] : -1)],
            self::$db->all('SELECT kind, vat_code, pack_price, supplier_item_id FROM po_line WHERE document_id = ? ORDER BY line_no', [$d->id])),
            'the scanned line: the supplier\'s VAT code and the last price; the new item: its new supplier item\'s price; the charge: the supplier\'s VAT code');
    }

    public function testOnlyThoseWhoMayMakeItemsAddANewItem(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Buyer Only Supplies']);
        $d = $this->pos->createDraft($buyer, (int) $s['id'], []);
        self::refused(403, 'role_not_allowed', fn () => $this->pos->addNewItem($buyer, $d->id, $d->version, new HandMade(self::$db), ['card' => ['name' => 'Not allowed pod']], [], 1));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM sku WHERE name = 'Not allowed pod'"));
        self::assertSame([], $this->pos->lines($d->id));
    }
}
