<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Caller;
use CW\Invoices\InvoiceInvariants;
use CW\Items\ItemOwnerInvariants;
use CW\Items\ItemOwners;
use CW\Ui\Controller\InvoicesController;

/**
 * CW-1153 B1 (docs/decisions.md VB7, VB8): a supplier bill whose goods lines are ALL a shop's own items when it is made final is that shop's
 * own bill (supplier_invoice.item_owner, derived from the lines, never a header choice): never owed by us (no balance, never late). A bill
 * mixing a shop's own items with ours is refused in plain words; a credit note must be its bill's owner's. A purchase order of an own item
 * is refused with what to do instead. Marking a bill that was made final before the marks (the go-live kit, Cloud Tech's bill) keeps its
 * write-once posting check (V2) and the nightly check (O6) quiet.
 */
final class OwnBillsTest extends InvoicesTestCase
{
    private int $vb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vb = self::makeChannel('vapebig');
    }

    private function mark(int $sku, ?string $from = null): void
    {
        (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]),
            'Vape Big\'s own goods', $from);
    }

    /** A PO of two items approved and delivered (one receipt line each). @return array{grn: \CW\Documents\Document, a: int, b: int} */
    private function twoItemsDelivered(Caller $desk, Caller $buyer, int $supplierId): array
    {
        $a = self::makeSku('Hayati 4000 Cola');
        $b = self::makeSku('Our liquid 10ml');
        $sa = $this->supplierItem($buyer, $supplierId, $a, 10, '36.2500', ['supplier_code' => 'CT-A']);
        $sb = $this->supplierItem($buyer, $supplierId, $b, 10, '20.0000', ['supplier_code' => 'CT-B']);
        $po = $this->draftPo($buyer, $supplierId, [['supplier_item_id' => (int) $sa['id'], 'packs' => 1], ['supplier_item_id' => (int) $sb['id'], 'packs' => 1]]);
        $po = $this->pos->approve($buyer, $po->id, $po->version);
        $grn = $this->delivered($desk, $supplierId, [
            ['sku_id' => $a, 'supplier_item_id' => (int) $sa['id'], 'packs' => 1, 'units_per_pack' => 10, 'pack_price' => '36.2500', 'po_line_no' => 1],
            ['sku_id' => $b, 'supplier_item_id' => (int) $sb['id'], 'packs' => 1, 'units_per_pack' => 10, 'pack_price' => '20.0000', 'po_line_no' => 2],
        ], $po->id);
        return ['grn' => $grn, 'a' => $a, 'b' => $b];
    }

    public function testABillOfOnlyAShopsOwnItemsIsThatShopsOwnBillAndNeverOwedByUs(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cloud Tech Test');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 1, 10, '36.2500', 1);
        $this->mark($x['sku']);
        $inv = $this->invoiceDelivery($desk, $x['grn']->id, '36.25', '7.25');
        self::assertSame($this->vb, (int) self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$inv->id]));
        $rec = $this->invoices->record($inv->id);
        self::assertNull($rec['owed_e2'], 'not owed by us');
        $row = array_values(array_filter($this->invoices->list('invoice', []), static fn (array $r): bool => (int) $r['id'] === $inv->id))[0];
        $view = InvoicesController::listRow('invoice', $row, 0, null, '2099-01-01');
        self::assertNull($view['balance']);
        self::assertFalse($view['late']);
        self::assertSame('VAPEBIG test site\'s own bill: not ours to pay', $view['own']);
        // an ordinary bill stays ours
        $y = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 1, 10, '20.0000', 1);
        $ours = $this->invoiceDelivery($desk, $y['grn']->id, '20.00', '4.00');
        self::assertNull(self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$ours->id]));
        self::assertSame(2400, $this->invoices->record($ours->id)['owed_e2']);
        self::assertSame([], ItemOwnerInvariants::check(self::$db));
    }

    public function testABillMixingAShopsOwnItemsWithOursIsRefusedInPlainWords(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Mixed Supplies');
        $x = $this->twoItemsDelivered($desk, $buyer, (int) $s['id']);
        $this->mark($x['a']);
        $r = $this->invoices->createFromReceipt($desk, $x['grn']->id);
        $e = $this->refusedCode('own_items_mixed', fn () => $this->postWithTotals($desk, $r['document'], '56.25', '0.00'));
        self::assertStringContainsString('Make two bills', $e->getMessage());
        self::assertSame('draft', (string) self::$db->value('SELECT status FROM document WHERE id = ?', [$r['document']->id]));
    }

    public function testACreditNoteMustBeItsBillsOwners(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Credit Supplies');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 2, 6, '12.0000', 2);
        $inv = $this->invoiceDelivery($desk, $x['grn']->id, '24.00', '4.80'); // ours: the item was not marked when it was made final
        $this->mark($x['sku']);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['invoice_number' => 'CN-OWN-1', 'invoice_date' => gmdate('Y-m-d', time() - 86400),
            'credits_id' => $inv->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 6);
        $this->refusedCode('own_items_credit_mismatch', fn () => $this->postWithTotals($desk, $cn, '12.00', '2.40'));
        // a credit note of a shop's own bill is that shop's own
        $y = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 2, 6, '12.0000', 2);
        $this->mark($y['sku']);
        $own = $this->invoiceDelivery($desk, $y['grn']->id, '24.00', '4.80');
        $cn2 = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['invoice_number' => 'CN-OWN-2', 'invoice_date' => gmdate('Y-m-d', time() - 86400),
            'credits_id' => $own->id]);
        $cn2 = $this->invoices->addCredit($desk, $cn2->id, $cn2->version, 1, 6);
        $cn2 = $this->postWithTotals($desk, $cn2, '12.00', '2.40');
        self::assertSame($this->vb, (int) self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$cn2->id]));
    }

    public function testAPurchaseOrderOfAShopsOwnItemIsRefusedWithWhatToDoInstead(): void
    {
        ['buyer' => $buyer, 'supplier' => $s] = $this->people('Order Supplies');
        $sku = self::makeSku('Elux Legend 4000 Grape');
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, 10, '30.0000', ['supplier_code' => 'EL-1']);
        $this->mark($sku);
        $po = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 1]]);
        $words = 'own purchases are entered as a bill, not a purchase order';
        self::assertNotEmpty(array_filter($this->pos->warnings($po->id), static fn (string $w): bool => str_contains($w, $words)));
        $e = $this->refusedCode('own_item_on_order', fn () => $this->pos->approve($buyer, $po->id, $po->version));
        self::assertStringContainsString($words, $e->getMessage());
    }

    public function testMarkingABillMadeFinalBeforeTheMarksKeepsItsPostingCheckAndTheNightlyCheckQuiet(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cloud Tech Earlier');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 1, 10, '36.2500', 1);
        $inv = $this->invoiceDelivery($desk, $x['grn']->id, '36.25', '7.25');
        $posted = (string) self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$inv->id]);
        // the kit: the items marked from before the bill (the count that brought them in), then the bill marked, as its lines now say
        $this->mark($x['sku'], substr((string) self::$db->value('SELECT ? - INTERVAL 3 DAY', [$posted]), 0, 19));
        self::$db->exec('UPDATE supplier_invoice SET item_owner = ? WHERE document_id = ?', [$this->vb, $inv->id]);
        self::assertSame([], array_values(array_filter(InvoiceInvariants::check(self::$db), static fn (string $v): bool => str_contains($v, (string) $inv->number))));
        self::assertSame([], ItemOwnerInvariants::check(self::$db));
        self::assertNull($this->invoices->record($inv->id)['owed_e2']);
    }
}
