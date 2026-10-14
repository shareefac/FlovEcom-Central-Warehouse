<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Invoices\InvoiceInvariants;

/**
 * Returns to the supplier and credit notes (pack C, plan IM7; docs/decisions.md PC13-PC16): a return takes stock out once, through the
 * stock writer (`supplier_return`), and is our debit note; a return before the invoice lowers what waits to be invoiced; the supplier's
 * credit note answers it, reduces what is owed on the invoice and brings an over-invoiced line back to matched; reversing each.
 */
final class SupplierReturnsTest extends InvoicesTestCase
{
    public function testAReturnTakesStockOutOnceAndIsTheDebitNote(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Returns Ltd');
        $sku = self::makeSku('Return me');
        $grn = $this->delivered($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '2.00']]);
        self::assertSame(10, $this->onHand($sku));

        $d = $this->returns->createDraft($desk, (int) $s['id'], ['reason_code' => 'faulty', 'return_ref' => 'RMA-9']);
        $r = $this->returns->addItem($desk, $d->id, $d->version, 'CW-' . $sku, 3, null, '2.00');
        self::assertSame('added', $r['status']);
        $lines = $this->returns->lines($d->id);
        $d = $this->returns->saveLines($desk, $d->id, $this->docs->get($d->id)->version, [
            ['sku_id' => $sku, 'qty' => 3, 'unit_cost' => '2.00', 'duty_amount' => '0.66', 'grn_document_id' => $grn->id, 'grn_line_no' => 1],
            ['amount' => '5.00', 'description' => 'Short on the last delivery'],
        ]);
        self::assertSame(['6.660000', '5.000000'], array_column($this->returns->lines($d->id), 'amount'), '3 x £2.00 + £0.66 duty; a claim without goods');
        self::assertSame([self::warehouseId('MAIN')], array_column($lines, 'warehouse_id'), 'the return\'s warehouse by default');
        $p = $this->returns->post($desk, $d->id, $d->version);
        self::assertSame(['posted', 'DN-000001'], [$p->status, $p->number]);
        self::assertSame(7, $this->onHand($sku));
        self::assertSame([['supplier_return', -3, '2.000000', 'document', 'DN-000001']], array_map(static fn (array $r): array => [$r['movement_type'], (int) $r['qty_delta'],
            $r['unit_cost'], $r['cost_source'], $r['doc_ref']], self::$db->all("SELECT movement_type, qty_delta, unit_cost, cost_source, doc_ref FROM stock_ledger WHERE document_id = ? "
            . "AND bucket = 'on_hand'", [$p->id])));
        self::assertSame('11.66', (string) self::$db->value('SELECT claim_total FROM supplier_return WHERE document_id = ?', [$p->id]), 'the debit note claims £11.66');
        self::assertSame('open', $this->returns->record($p->id)['credit_state']);

        // Once: posting it again moves nothing.
        $this->refusedCode('not_draft', fn () => $this->returns->post($desk, $p->id, $p->version));
        self::assertSame(7, $this->onHand($sku));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE document_id = ? AND bucket = 'on_hand'", [$p->id]));

        // More back than the delivery line booked in is refused.
        $more = $this->returns->createDraft($desk, (int) $s['id'], ['reason_code' => 'faulty']);
        $more = $this->returns->saveLines($desk, $more->id, $more->version, [['sku_id' => $sku, 'qty' => 8, 'unit_cost' => '2.00', 'grn_document_id' => $grn->id, 'grn_line_no' => 1]]);
        $this->refusedCode('return_more_than_received', fn () => $this->returns->post($desk, $more->id, $more->version), '3 went back already: 7 more at most');

        // Cancelled by its reversal: the 3 units come back.
        $rev = $this->returns->reverse($desk, $p->id, 'entered_in_error', null);
        self::assertSame(['posted', 10], [$rev->status, $this->onHand($sku)]);
        self::assertSame('reversed', $this->docs->get($p->id)->status);
        self::assertSame([], InvoiceInvariants::check(self::$db));
    }

    public function testAProtectedProductNeverGoesBelowZero(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Below Zero Ltd');
        $sku = $this->item('strict', 2);
        $d = $this->returns->createDraft($desk, (int) $s['id'], ['reason_code' => 'damaged']);
        $d = $this->returns->saveLines($desk, $d->id, $d->version, [['sku_id' => $sku, 'qty' => 3, 'unit_cost' => '1.00']]);
        $e = $this->refusedCode('below_zero', fn () => $this->returns->post($desk, $d->id, $d->version));
        self::assertSame(2, $e->detail['have']);
        self::assertSame(2, $this->onHand($sku), 'nothing booked');
    }

    public function testAReturnBeforeTheInvoiceLowersWhatWaitsToBeInvoiced(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Before Invoice Ltd');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 10, 1, '4.0000', 10);
        $d = $this->returns->createDraft($desk, (int) $s['id'], ['reason_code' => 'faulty', 'grn_id' => $x['grn']->id]);
        $d = $this->returns->saveLines($desk, $d->id, $d->version, [['sku_id' => $x['sku'], 'qty' => 2, 'unit_cost' => '4.00', 'grn_document_id' => $x['grn']->id,
            'grn_line_no' => 1]]);
        $this->returns->post($desk, $d->id, $d->version);
        self::assertSame(8, (int) $this->invoices->notInvoiced((int) $s['id'])[0]['units_left'], 'the 2 returned are not to be invoiced (stock is not reduced twice)');
        $inv = $this->invoices->createFromReceipt($desk, $x['grn']->id)['document'];
        self::assertSame(8, $this->invoices->lines($inv->id)[0]['qty']);
        $inv = $this->postWithTotals($desk, $inv);
        self::assertSame('matched', $this->stored($inv->id)['match_state'], 'the supplier invoiced what was kept');
    }

    /**
     * Review finding 2: a return about a delivery takes its goods from that delivery's lines, also when they are added by hand: the
     * delivery is locked, what it booked in limits what goes back, and what waits to be invoiced goes down.
     */
    public function testAReturnByHandAgainstADeliveryTakesItsLine(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('By Hand Ltd');
        $sid = (int) $s['id'];
        $sku = self::makeSku('By hand item');
        $g1 = $this->delivered($desk, $sid, [['sku_id' => $sku, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '2.00']]);
        $this->delivered($desk, $sid, [['sku_id' => $sku, 'packs' => 5, 'units_per_pack' => 1, 'pack_price' => '2.00']]);
        $elsewhere = self::makeSku('Not on that delivery');
        $this->delivered($desk, $sid, [['sku_id' => $elsewhere, 'packs' => 2, 'units_per_pack' => 1, 'pack_price' => '1.00']]);
        $left = fn (): int => (int) array_column($this->invoices->notInvoiced($sid), 'units_left', 'id')[$g1->id];

        $d = $this->returns->createDraft($desk, $sid, ['reason_code' => 'faulty', 'grn_id' => $g1->id]);
        $this->returns->addItem($desk, $d->id, $d->version, '', 3, null, '2.00', $sku);
        self::assertSame([[$g1->id, 1]], array_map(static fn (array $l): array => [$l['grn_document_id'], $l['grn_line_no']], $this->returns->lines($d->id)),
            'the item added by hand is the delivery\'s line');
        $this->returns->post($desk, $d->id, $this->docs->get($d->id)->version);
        self::assertSame([12, 7], [$this->onHand($sku), $left()], 'three went back: seven of that delivery wait to be invoiced');

        // Eight more against the same delivery: more than it booked in, although the stock is there (another delivery's five).
        $more = $this->returns->createDraft($desk, $sid, ['reason_code' => 'faulty', 'grn_id' => $g1->id]);
        $this->returns->addItem($desk, $more->id, $more->version, '', 8, null, '2.00', $sku);
        $this->refusedCode('return_more_than_received', fn () => $this->returns->post($desk, $more->id, $this->docs->get($more->id)->version));

        // An item that is not on the delivery the return is about.
        $not = $this->returns->createDraft($desk, $sid, ['reason_code' => 'faulty', 'grn_id' => $g1->id]);
        $this->returns->addItem($desk, $not->id, $not->version, '', 1, null, '1.00', $elsewhere);
        $this->refusedCode('line_not_on_delivery', fn () => $this->returns->post($desk, $not->id, $this->docs->get($not->id)->version));
        self::assertSame([12, 2, 7], [$this->onHand($sku), $this->onHand($elsewhere), $left()]);
    }

    /**
     * Review finding 3 (PC14 revisited): a return counts against what the delivery line put where the goods leave from. Eight accepted
     * into MAIN, two damaged into VERIFY: two going back from MAIN leave six to invoice (an invoice for eight is off); the two damaged
     * may still go back from VERIFY, and nothing more from VERIFY.
     */
    public function testAReturnCountsAgainstWhatTheDeliveryPutWhereItLeavesFrom(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Where From Ltd');
        $sid = (int) $s['id'];
        $x = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '3.0000', 10, [1 => ['damaged_units' => '2']]);
        self::assertSame([8, 2], [$this->onHand($x['sku']), $this->onHand($x['sku'], 'VERIFY')]);
        $main = $this->returns->createDraft($desk, $sid, ['reason_code' => 'faulty', 'grn_id' => $x['grn']->id]);
        $main = $this->returns->saveLines($desk, $main->id, $main->version, [['sku_id' => $x['sku'], 'qty' => 2, 'unit_cost' => '3.00',
            'warehouse_id' => self::warehouseId('MAIN'), 'grn_document_id' => $x['grn']->id, 'grn_line_no' => 1]]);
        $this->returns->post($desk, $main->id, $main->version);
        self::assertSame(6, (int) $this->invoices->notInvoiced($sid)[0]['units_left'], 'two accepted units went back: six to invoice');

        $quick = $this->returns->createFromReceipt($desk, $x['grn']->id);
        self::assertSame([[2, self::warehouseId('VERIFY')]], array_map(static fn (array $l): array => [$l['qty'], $l['warehouse_id']], $this->returns->lines($quick->id)),
            'the two damaged are still to go back: the accepted units that went back are not theirs');
        $quick = $this->returns->saveHeader($desk, $quick->id, $quick->version, ['reason_code' => 'damaged']);
        $this->returns->post($desk, $quick->id, $quick->version);
        self::assertSame(6, (int) $this->invoices->notInvoiced($sid)[0]['units_left']);
        $third = $this->returns->createDraft($desk, $sid, ['reason_code' => 'damaged', 'grn_id' => $x['grn']->id]);
        $third = $this->returns->saveLines($desk, $third->id, $third->version, [['sku_id' => $x['sku'], 'qty' => 1, 'unit_cost' => '3.00',
            'warehouse_id' => self::warehouseId('VERIFY'), 'grn_document_id' => $x['grn']->id, 'grn_line_no' => 1]]);
        $this->refusedCode('return_more_than_received', fn () => $this->returns->post($desk, $third->id, $third->version), 'the delivery put two in VERIFY');

        $inv = $this->invoices->createFromReceipt($desk, $x['grn']->id)['document'];
        self::assertSame(6, $this->invoices->lines($inv->id)[0]['qty']);
        $inv = $this->invoices->saveLines($desk, $inv->id, $inv->version, [SupplierInvoicesTest::lineOf($this->invoices->lines($inv->id)[0], ['packs' => 8])]);
        $inv = $this->postWithTotals($desk, $inv);
        self::assertSame('needs_review', $this->stored($inv->id)['match_state'], 'eight billed, six there');
        self::assertSame([], InvoiceInvariants::check(self::$db));
    }

    public function testACreditNoteAnswersTheReturnAndBringsTheInvoiceBack(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Credit Note Ltd');
        // Ten on the paperwork, two damaged at the bench (to VERIFY): eight accepted. The supplier invoices all ten.
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 10, 1, '3.0000', 10, [1 => ['damaged_units' => '2']]);
        self::assertSame([8, 2], [$this->onHand($x['sku']), $this->onHand($x['sku'], 'VERIFY')]);
        $inv = $this->invoices->createFromReceipt($desk, $x['grn']->id)['document'];
        $inv = $this->invoices->saveLines($desk, $inv->id, $inv->version, [SupplierInvoicesTest::lineOf($this->invoices->lines($inv->id)[0], ['packs' => 10])]);
        $inv = $this->postWithTotals($desk, $inv, '30.00', '6.00');
        self::assertSame(['needs_review', '1', '6.00'], array_values(array_map('strval', $this->stored($inv->id))), 'two billed that were not accepted');

        // The two damaged go back from VERIFY: our debit note. The invoice still needs review: it bills them until the supplier credits.
        $dn = $this->returns->createFromReceipt($desk, $x['grn']->id);
        $l = $this->returns->lines($dn->id);
        self::assertSame([[$x['sku'], 2, self::warehouseId('VERIFY'), '3.000000', $x['grn']->id, 1]], array_map(static fn (array $r): array => [$r['sku_id'], $r['qty'],
            $r['warehouse_id'], $r['unit_cost'], $r['grn_document_id'], $r['grn_line_no']], $l), 'from where the damaged units are, at the delivery\'s price');
        self::assertSame($inv->id, (int) self::$db->value('SELECT invoice_document_id FROM supplier_return WHERE document_id = ?', [$dn->id]));
        $dn = $this->returns->saveHeader($desk, $dn->id, $dn->version, ['reason_code' => 'damaged']);
        $dn = $this->returns->post($desk, $dn->id, $dn->version);
        self::assertSame(0, $this->onHand($x['sku'], 'VERIFY'));
        self::assertSame('needs_review', $this->stored($inv->id)['match_state'], 'units from VERIFY were never accepted: nothing changes until the credit');

        // The supplier's credit note for the return: it credits the two on the invoice's line.
        $cn = $this->invoices->createFromReturn($desk, $dn->id);
        $cl = $this->invoices->lines($cn->id);
        self::assertSame([['goods', 2, 1, '3.0000', $x['grn']->id]], array_map(static fn (array $r): array => [$r['kind'], $r['qty'], $r['credit_of_line_no'], $r['pack_price'],
            $r['grn_document_id']], $cl));
        $cn = $this->postWithTotals($desk, $cn, '6.00', '1.20', ['invoice_number' => 'CN-5', 'invoice_date' => self::day('-1 day')]);
        self::assertSame(['posted', 'SCN-000001'], [$cn->status, $cn->number]);
        self::assertSame('matched', $this->stored($inv->id)['match_state'], 'billed 10 - 2 credited = the 8 accepted');
        $rec = $this->invoices->record($inv->id);
        self::assertSame([3600, 720, 2880], [\CW\Invoices\InvoiceMath::e2((string) $rec['gross_total']), $rec['credited_e2'], $rec['owed_e2']], 'owed: £36.00 - £7.20');
        self::assertSame('credited', $this->returns->record($dn->id)['credit_state']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM invoice_value_event WHERE document_id = ? AND kind = 'credit' AND amount = '-6.00'", [$cn->id]));

        // More credit than the line billed is refused; the invoice is not reversed while its credit note stands.
        $more = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $inv->id]);
        $this->refusedCode('credit_more_than_invoiced', fn () => $this->invoices->addCredit($desk, $more->id, $more->version, 1, 9));
        $this->refusedCode('has_credit_notes', fn () => $this->invoices->reverse($desk, $inv->id, 'entered_in_error', null));
        $this->invoices->reverse($desk, $cn->id, 'entered_in_error', null);
        self::assertSame(['needs_review', 'open'], [$this->stored($inv->id)['match_state'], $this->returns->record($dn->id)['credit_state']]);
        $this->invoices->reverse($desk, $inv->id, 'entered_in_error', null);
        self::assertSame([null, 'reversed'], [$this->stored($inv->id)['match_state'], $this->docs->get($inv->id)->status]);
        self::assertSame([], InvoiceInvariants::check(self::$db));
    }

    public function testWhoMayKeepReturns(): void
    {
        ['buyer' => $buyer, 'supplier' => $s] = $this->people('Who Returns Ltd');
        $this->refusedCode('role_not_allowed', fn () => $this->returns->createDraft($buyer, (int) $s['id']));
        $this->refusedCode('role_not_allowed', fn () => $this->returns->createDraft($this->staffUser('goods_in'), (int) $s['id']));
        $this->refusedCode('admin_cannot_post', fn () => $this->returns->createDraft($this->staffUser('admin'), (int) $s['id']));
        self::assertSame('draft', $this->returns->createDraft($this->staffUser('purchasing_manager'), (int) $s['id'])->status);
    }
}
