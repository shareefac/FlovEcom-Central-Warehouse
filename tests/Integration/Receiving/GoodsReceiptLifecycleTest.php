<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Receiving;

use CW\Receiving\ReceivingInvariants;

/**
 * The receipt's life (IM6; I125-I147): keyed by the desk (a PO copied down, a scan of an outer case, lines in packs x units per
 * pack), checked at the bench, posted (stock into MAIN / VERIFY / UNSTAMPED at the line's cost, the PO's receipts and state, an
 * incident per exception, the selling modes, the anchor, the review), reversed (everything taken back), cancelled; the "box of 5".
 */
final class GoodsReceiptLifecycleTest extends ReceivingTestCase
{
    /**
     * The owner's "box of 5" (inventory plan §7, I-3 test): a PO of 3 boxes of 5 received from a copy-down, and a scan of the box's
     * own barcode adding a box: units are always packs x units per pack (ERPNext sent 3, not 15: the boxes-for-units bug).
     */
    public function testTheBoxOfFiveFromAPoCopyDownAndAScan(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Box Of Five Ltd');
        $sku = $this->itemWithBarcode('Box liquid 10ml', '5012345678900', '5012345678917', 5);
        $this->liquid($sku);
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, 5, '10.0000', ['supplier_code' => 'BOX5']);
        $po = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 3]]);
        $po = $this->pos->approve($buyer, $po->id, $po->version);
        $po = $this->pos->markSent($buyer, $po->id, $po->version, 'email', null, true);

        $d = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'BX-1001', 'invoice_date' => self::day('-1 day')], $po->id, true);
        $lines = $this->grns->lines($d->id);
        self::assertCount(1, $lines, 'receive all as ordered');
        self::assertSame([(int) $si['id'], 5, 3, '10.0000', 1, 'po'], [$lines[0]['supplier_item_id'], $lines[0]['units_per_pack'], $lines[0]['packs'],
            $lines[0]['pack_price'], $lines[0]['po_line_no'], $lines[0]['entry']]);
        self::assertSame(15, (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ? AND line_no = 1', [$d->id]), '3 packs x 5 = 15 units');
        $this->refusedCode('nothing_outstanding', fn () => $this->grns->copyFromPo($desk, $d->id, $this->docs->get($d->id)->version));

        // A scan of the box's own barcode (units per scan 5) adds a box to the line of that supplier item: 4 boxes = 20 units.
        $r = $this->grns->addLine($desk, $d->id, $this->docs->get($d->id)->version, '5012345678917');
        self::assertSame(['incremented', 1], [$r['status'], $r['line_no']]);
        self::assertSame(4, $this->grns->lines($d->id)[0]['packs']);
        // ... and back to what arrived (3 boxes).
        $l = $this->grns->lines($d->id);
        $l[0]['packs'] = 3;
        $this->grns->saveDraft($desk, $d->id, $this->docs->get($d->id)->version, [], $l);

        $plan = $this->grns->plan($d->id);
        self::assertSame(['invoice_file_required', 'bench_check_required'], array_column($plan['problems'], 'code'), 'one "waiting for the bench" problem, not one a line');
        $this->refusedCode('invoice_file_required', fn () => $this->post($desk, $d->id));
        $this->ready($desk, $d->id);
        $p = $this->post($desk, $d->id);
        self::assertSame(['posted', 'GRN-000001', 'pending'], [$p->status, $p->number, $p->reviewState]);
        self::assertSame(15, $this->onHand($sku), '15 units, not 3');
        $row = self::$db->one("SELECT qty_delta, unit_cost, cost_source, doc_ref, document_line, movement_type FROM stock_ledger WHERE document_id = ? AND bucket = 'on_hand'", [$d->id]);
        self::assertSame([15, '2.000000', 'document', 'GRN-000001', 1, 'goods_in'], [(int) $row['qty_delta'], $row['unit_cost'], $row['cost_source'], $row['doc_ref'],
            (int) $row['document_line'], $row['movement_type']]);
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id WHERE l.document_id = ?', [$d->id]),
            'the value seq (C0)');
        self::assertSame('received', $this->poRow($po->id)['state']);
        self::assertSame(15, (int) self::$db->value('SELECT received_units FROM po_line WHERE document_id = ? AND line_no = 1', [$po->id]));
        $g = self::$db->one('SELECT accepted_units, verify_units, quarantine_units, refused_units, po_units, selling_mode, mode_source, stamp_required, duty_ml, expected_duty '
            . 'FROM grn_line WHERE document_id = ?', [$d->id]);
        self::assertSame([15, 0, 0, 0, 15, 'From-Warehouse', 'fallback', 1, '10.0', '33.00'], [(int) $g['accepted_units'], (int) $g['verify_units'],
            (int) $g['quarantine_units'], (int) $g['refused_units'], (int) $g['po_units'], $g['selling_mode'], $g['mode_source'], (int) $g['stamp_required'], $g['duty_ml'],
            $g['expected_duty']], '15 x 10 ml x 22p = £33.00 expected duty, for information');
        self::assertSame(['From-Warehouse', null, 1], array_values((array) self::$db->one('SELECT mode, previous_mode, version FROM item_selling_mode WHERE sku_id = ?', [$sku])));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND state = 'open' AND kind = 'review'", [$d->id]));
        self::assertSame([], ReceivingInvariants::check(self::$db));
        self::assertContains('grn.post', $this->audits($d->id));
    }

    /**
     * Exceptions: short (not booked), over / damaged / wrong item (VERIFY), unstamped quarantined (UNSTAMPED): an incident each. With
     * some units unstamped and quarantined, the line's damaged and over units are quarantined with them (I167); wrong items stay in VERIFY.
     */
    public function testExceptionsGoToVerifyAndUnstampedWithAnIncidentEach(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        $sku = self::makeSku('Exceptions liquid');
        $this->liquid($sku);
        $coil = self::makeSku('Exceptions coil');
        $this->dry($coil);
        $d = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 2, 'units_per_pack' => 10, 'pack_price' => '25.00'],
            ['sku_id' => $coil, 'packs' => 2, 'units_per_pack' => 10, 'pack_price' => '5.00']]);
        $this->invoice($desk, $d->id);
        $this->bench($this->staffUser('goods_in'), $d->id, [1 => ['short_units' => '1', 'over_units' => '2', 'damaged_units' => '3', 'wrong_item_units' => '4',
            'unstamped_units' => '5', 'unstamped_action' => 'quarantine'], 2 => ['short_units' => '1', 'over_units' => '2', 'damaged_units' => '3', 'wrong_item_units' => '4']]);
        $plan = $this->grns->plan($d->id);
        self::assertSame([], $plan['problems']);
        self::assertSame(['accepted' => 7, 'verify' => 4, 'quarantine' => 10, 'refused' => 0, 'short' => 1], array_intersect_key($plan['lines'][1]['split'],
            array_flip(['accepted', 'verify', 'quarantine', 'refused', 'short'])));
        self::assertSame(['accepted' => 12, 'verify' => 9, 'quarantine' => 0, 'refused' => 0, 'short' => 1], array_intersect_key($plan['lines'][2]['split'],
            array_flip(['accepted', 'verify', 'quarantine', 'refused', 'short'])), 'a dry line: damaged and over to VERIFY');
        self::assertStringContainsString('its 3 damaged and 2 over units arrived unstamped too', implode(' ', $plan['warnings']));
        $this->post($desk, $d->id);
        self::assertSame([7, 4, 10], [$this->onHand($sku), $this->onHand($sku, 'VERIFY'), $this->onHand($sku, 'UNSTAMPED')]);
        self::assertSame([12, 9, 0], [$this->onHand($coil), $this->onHand($coil, 'VERIFY'), $this->onHand($coil, 'UNSTAMPED')]);
        self::assertSame([
            ['line' => 1, 'kind' => 'short', 'disposition' => 'not_received', 'units' => 1],
            ['line' => 1, 'kind' => 'over', 'disposition' => 'quarantine', 'units' => 2],
            ['line' => 1, 'kind' => 'damaged', 'disposition' => 'quarantine', 'units' => 3],
            ['line' => 1, 'kind' => 'wrong_item', 'disposition' => 'verify', 'units' => 4],
            ['line' => 1, 'kind' => 'unstamped', 'disposition' => 'quarantine', 'units' => 5],
            ['line' => 2, 'kind' => 'short', 'disposition' => 'not_received', 'units' => 1],
            ['line' => 2, 'kind' => 'over', 'disposition' => 'verify', 'units' => 2],
            ['line' => 2, 'kind' => 'damaged', 'disposition' => 'verify', 'units' => 3],
            ['line' => 2, 'kind' => 'wrong_item', 'disposition' => 'verify', 'units' => 4],
        ], array_map(static fn (array $r): array => ['line' => (int) $r['line_no'], 'kind' => $r['kind'], 'disposition' => $r['disposition'], 'units' => (int) $r['units']],
            self::$db->all('SELECT line_no, kind, disposition, units FROM incident WHERE document_id = ? ORDER BY line_no, id', [$d->id])));
        self::assertTrue((bool) json_decode((string) self::$db->value("SELECT detail FROM incident WHERE document_id = ? AND line_no = 1 AND kind = 'damaged'", [$d->id]), true)['unstamped']);
        self::assertSame(['2.500000', '0.500000'], array_values(array_unique(array_map('strval', self::$db->column('SELECT unit_cost FROM stock_ledger WHERE document_id = ? ORDER BY document_line',
            [$d->id])))));
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    /**
     * A barcode counts the units it stands for, never the supplier's pack instead (I173: the boxes-for-units bug on the scan route,
     * review 7 Oct): a case of 5 scanned for a supplier who sells singles is 5 units, for one who sells boxes of 10 a line in cases
     * of 5; one bottle scanned for a supplier of boxes asks once. The supplier's sheet says "pack differs" when a case barcode and
     * a supplier code of another pack name the same row.
     */
    public function testABarcodeCountsItsOwnUnitsWhateverTheSuppliersPack(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Scan Units Ltd');
        $sku = $this->itemWithBarcode('Scan liquid 10ml', '5012345678924', '5012345678931', 5);
        $this->liquid($sku);
        $single = $this->supplierItem($buyer, (int) $s['id'], $sku, 1, '2.0000', ['supplier_code' => 'SGL']);
        $d = $this->receipt($desk, (int) $s['id'], []);
        $r = $this->grns->addLine($desk, $d->id, $d->version, '5012345678931');
        self::assertSame(['added', 1, 5, null], [$r['status'], $r['line_no'], $r['units_added'], $r['note']]);
        self::assertSame([(int) $single['id'], 1, 5], [$this->grns->lines($d->id)[0]['supplier_item_id'], $this->grns->lines($d->id)[0]['units_per_pack'],
            $this->grns->lines($d->id)[0]['packs']], 'a case of 5 = 5 packs of the single');
        $r = $this->grns->addLine($desk, $d->id, $this->docs->get($d->id)->version, '5012345678931', 2);
        self::assertSame(['incremented', 10], [$r['status'], $r['units_added']]);
        self::assertSame(15, (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ?', [$d->id]));

        // A supplier of boxes of 10: the case of 5 is a line of its own in cases of 5 (no supplier item), and the desk is told.
        $other = $this->activeSupplier($buyer, ['name' => 'Boxes Of Ten Ltd']);
        $ten = $this->supplierItem($buyer, (int) $other['id'], $sku, 10, '18.0000', ['supplier_code' => 'TEN']);
        $d2 = $this->receipt($desk, (int) $other['id'], []);
        $r = $this->grns->addLine($desk, $d2->id, $d2->version, '5012345678931');
        self::assertSame(['added', 5], [$r['status'], $r['units_added']]);
        self::assertStringContainsString("a case of 5, not this supplier's packs of 10", (string) $r['note']);
        self::assertSame([null, 5, 1], [$this->grns->lines($d2->id)[0]['supplier_item_id'], $this->grns->lines($d2->id)[0]['units_per_pack'], $this->grns->lines($d2->id)[0]['packs']]);
        // One bottle (its own barcode) from the supplier of boxes: asked once, then the receipt's own line of the item is used.
        $d3 = $this->receipt($desk, (int) $other['id'], []);
        $c = $this->grns->addLine($desk, $d3->id, $d3->version, '5012345678924');
        self::assertSame('choices', $c['status']);
        self::assertSame([(int) $ten['id'], null], array_column($c['choices'], 'supplier_item_id'), 'one of its boxes, or single units');
        self::assertStringContainsString('That barcode is one unit', $c['choice_note']);
        $this->grns->addChoice($desk, $d3->id, $this->docs->get($d3->id)->version, (int) $ten['id'], null);
        $r = $this->grns->addLine($desk, $d3->id, $this->docs->get($d3->id)->version, '5012345678924');
        self::assertSame(['incremented', 10], [$r['status'], $r['units_added']], 'the line of the item it has');

        // The sheet: the case barcode alone counts its units; with a supplier code of another pack it is a contradiction.
        $d4 = $this->receipt($desk, (int) $s['id'], []);
        $ok = $this->grns->importLines($desk, $d4->id, $d4->version, $this->file("EAN,Qty
5012345678931,3
", 'a.csv'), 'a.csv', 'append');
        self::assertSame([], $ok['errors']);
        self::assertSame(15, (int) self::$db->value('SELECT qty FROM document_line WHERE document_id = ?', [$d4->id]), '3 cases of 5');
        $bad = $this->grns->importLines($desk, $d4->id, $this->docs->get($d4->id)->version, $this->file("Code,EAN,Qty
SGL,5012345678931,3
", 'b.csv'), 'b.csv', 'append');
        self::assertSame(['row 2, barcode: pack differs: the barcode is a case of 5, but the supplier code SGL is a pack of 1'], $bad['errors']);
    }

    /** Reversal: the stock negated, the PO's receipts taken back, the open incidents dismissed, the invoice number free again. */
    public function testAReversalTakesEverythingBack(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people();
        ['doc' => $po, 'sku' => $sku] = $this->approvedPoFor($buyer, (int) $s['id'], 4, 6);
        $this->dry($sku);
        $d = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'rev 77'], $po->id, true);
        $this->invoice($desk, $d->id);
        $this->bench($this->staffUser('goods_in'), $d->id, [1 => ['damaged_units' => '2']]);
        $this->post($desk, $d->id);
        self::assertSame([22, 2, 'part_received'], [$this->onHand($sku), $this->onHand($sku, 'VERIFY'), $this->poRow($po->id)['state']]);
        $rev = $this->docs->reverse($desk, $d->id, 'entered_in_error', null);
        self::assertSame('posted', $rev->status);
        self::assertSame('reversed', $this->docs->get($d->id)->status);
        self::assertSame([0, 0, 'approved'], [$this->onHand($sku), $this->onHand($sku, 'VERIFY'), $this->poRow($po->id)['state']]);
        self::assertSame(0, (int) self::$db->value('SELECT received_units FROM po_line WHERE document_id = ?', [$po->id]));
        self::assertSame(['dismissed'], array_column($this->incidents($d->id), 'status'));
        self::assertNull(self::$db->value('SELECT invoice_key FROM goods_receipt WHERE document_id = ?', [$d->id]));
        // The same invoice can be received again (written differently: the key is upper case without spaces).
        $again = $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => 'REV77']);
        self::assertSame('REV77', self::$db->value('SELECT invoice_key FROM goods_receipt WHERE document_id = ?', [$again->id]));
    }
}
