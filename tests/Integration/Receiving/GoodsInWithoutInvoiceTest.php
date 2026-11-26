<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Receiving;

use CW\Caller;
use CW\Clock;
use CW\Duty\DutyRegister;
use CW\Receiving\ReceiptPlan;
use CW\Receiving\ReceivingInvariants;
use CW\Settings;
use CW\Tests\Integration\Invoices\InvoicesTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1152 (docs/decisions.md NI1-NI6; the owner, CW chat 26 Nov 2026 ~21:25 UK, option "A) Per supplier"): a supplier ticked "Can book goods
 * in without the invoice" has its deliveries booked in without the supplier invoice number and without its copy (a note "Invoice still to
 * come", never a refusal); every other check stays (a typed number booked once, the same copy on another delivery, the file type, the bench,
 * the duty stamp). Off (the default) nothing changes. The tick is read under the posting's locks; ticked off again, new postings are refused
 * again. "Book its invoice" works with no number on the delivery, and the duty register prints the number of the supplier invoice that billed
 * it. The cost is the delivery line's, as for any delivery.
 */
final class GoodsInWithoutInvoiceTest extends InvoicesTestCase
{
    /** Ticks (or unticks) a supplier as its buyer, through the supplier service. @return array<string, mixed> the row */
    private function tick(Caller $buyer, int $supplierId, bool $on): array
    {
        $s = $this->sup->get($supplierId);
        return $this->sup->update($buyer, $supplierId, (int) $s['version'], ['goods_in_without_invoice' => $on ? '1' : '0']);
    }

    /**
     * A draft with no copy, one line of $packs x 1 of $sku at £3.00 a unit; no invoice number unless $header gives one, and a delivery note
     * (the carbon-book page: compulsory without the number, H1) unless $header says otherwise.
     */
    private function bareDraft(Caller $desk, int $supplierId, int $sku, int $packs = 5, array $header = []): \CW\Documents\Document
    {
        if (!array_key_exists('delivery_note', $header)) {
            $header['delivery_note'] = 'PAGE-' . bin2hex(random_bytes(3));
        }
        $d = $this->grns->createDraft($desk, $supplierId, $header);
        return $this->grns->saveDraft($desk, $d->id, $d->version, [], [['sku_id' => $sku, 'packs' => $packs, 'units_per_pack' => 1, 'pack_price' => '3.00']]);
    }

    public function testOffTheDefaultNothingChanges(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Invoice Always Ltd');
        self::assertSame(0, (int) $s['goods_in_without_invoice'], 'a supplier is off until a person ticks it');
        $sku = self::makeSku('Off item');
        $this->dry($sku);
        $d = $this->bareDraft($desk, (int) $s['id'], $sku);
        $this->bench($this->staffUser('goods_in'), $d->id);
        $e = $this->refusedCode('invoice_number_required', fn () => $this->post($desk, $d->id));
        self::assertSame(['invoice_number_required', 'invoice_file_required'], array_column($e->detail['problems'], 'code'));
        $plan = $this->grns->plan($d->id);
        self::assertSame([], $plan['header']['invoice_to_come']);
        self::assertSame([], array_values(array_filter($plan['warnings'], static fn (string $w): bool => str_starts_with($w, 'Invoice still to come'))));
    }

    public function testATickedSupplierBooksGoodsInWithoutTheInvoiceAndEveryOtherCheckStays(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Manchester Carbon Books Ltd');
        $sid = (int) $s['id'];
        $s = $this->tick($buyer, $sid, true);
        self::assertSame(1, (int) $s['goods_in_without_invoice']);
        $audit = (array) json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE entity_type = 'supplier' AND entity_id = ? AND action = 'supplier.update' "
            . 'ORDER BY id DESC LIMIT 1', [(string) $sid]), true);
        self::assertSame(['goods_in_without_invoice'], array_keys($audit['changed']), 'the change is audited like any supplier field');
        self::assertSame(1, $audit['changed']['goods_in_without_invoice'][1]);

        $sku = self::makeSku('Carbon book liquid 10ml');
        $this->liquid($sku);
        $d = $this->bareDraft($desk, $sid, $sku);
        // Before the bench: the bench still stops it; the invoice is a note.
        $plan = $this->grns->plan($d->id);
        $codes = array_column($plan['problems'], 'code');
        self::assertNotContains('invoice_number_required', $codes);
        self::assertNotContains('invoice_file_required', $codes);
        self::assertContains('bench_check_required', $codes);
        self::assertSame(['number', 'copy'], $plan['header']['invoice_to_come']);
        self::assertContains("Invoice still to come: no supplier invoice number or copy yet. Supplier {$s['code']} books goods in without the invoice: book its invoice when it comes.",
            $plan['warnings']);
        $this->refusedCode('bench_check_required', fn () => $this->post($desk, $d->id), 'the bench check stays');
        // The duty stamp check stays: a liquid whose stamp the bench did not record is refused.
        $this->bench($this->staffUser('goods_in'), $d->id, [1 => ['stamp_on_pack' => '', 'stamp_type' => '']]);
        $this->refusedCode('stamp_check_required', fn () => $this->post($desk, $d->id), 'the duty stamp check stays');
        $this->bench($this->staffUser('goods_in'), $d->id);
        $p = $this->post($desk, $d->id);
        self::assertSame(['posted', null], [$p->status, $p->externalRef]);
        self::assertNull(self::$db->value('SELECT invoice_key FROM goods_receipt WHERE document_id = ?', [$p->id]));
        $post = (array) json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE entity_type = 'document' AND entity_id = ? AND action = 'grn.post'",
            [(string) $p->id]), true);
        self::assertSame(['number', 'copy'], $post['invoice_to_come'], 'the posting says it was booked without the invoice');
        // The cost is the delivery line's (as for any delivery): 5 units into stock at £3.00.
        self::assertSame([[5, '3.000000']], array_map(static fn (array $r): array => [(int) $r['qty_delta'], (string) $r['unit_cost']], self::$db->all(
            "SELECT qty_delta, unit_cost FROM stock_ledger WHERE document_id = ? AND movement_type = 'goods_in' AND bucket = 'on_hand'", [$p->id])));
        self::assertSame(5, $this->onHand($sku));

        // A typed number is still booked once; the copy alone may come later.
        $n = $this->bareDraft($desk, $sid, $sku, 2, ['invoice_number' => 'MAN-0001']);
        $this->bench($this->staffUser('goods_in'), $n->id);
        $plan = $this->grns->plan($n->id);
        self::assertSame(['copy'], $plan['header']['invoice_to_come']);
        self::assertContains("Invoice still to come: the supplier's invoice is not attached yet. Supplier {$s['code']} books goods in without the invoice: book its invoice when it comes.",
            $plan['warnings']);
        $this->post($desk, $n->id);
        $this->refusedCode('duplicate_invoice', fn () => $this->grns->createDraft($desk, $sid, ['invoice_number' => ' man-0001 ']));
        // The invoice copy rules stay: its type, and one copy on one delivery.
        $csv = $this->file("code,qty\r\nA,1\r\n", 'invoice.csv');
        $a = $this->bareDraft($desk, $sid, $sku, 1, ['invoice_number' => 'MAN-0002']);
        $this->refusedCode('type_not_allowed', fn () => $this->grns->attach($desk, $a->id, $csv, 'invoice.csv', 'supplier_invoice'));
        $pdf = $this->pdf();
        $this->grns->attach($desk, $a->id, $pdf, 'invoice.pdf', 'supplier_invoice');
        $b = $this->bareDraft($desk, $sid, $sku, 1, ['invoice_number' => 'MAN-0003']);
        $this->refusedCode('invoice_copy_elsewhere', fn () => $this->grns->attach($desk, $b->id, $pdf, 'invoice.pdf', 'supplier_invoice'));
        $this->bench($this->staffUser('goods_in'), $a->id);
        self::assertSame([], $this->grns->plan($a->id)['header']['invoice_to_come'], 'number and copy there: nothing to come');
        self::assertSame('posted', $this->post($desk, $a->id)->status);

        // Ticked off again: the next posting needs the invoice again; what was booked stays booked.
        $late = $this->bareDraft($desk, $sid, $sku, 1);
        $this->bench($this->staffUser('goods_in'), $late->id);
        $this->tick($buyer, $sid, false);
        $this->services();
        $e = $this->refusedCode('invoice_number_required', fn () => $this->post($desk, $late->id));
        self::assertSame(['invoice_number_required', 'invoice_file_required'], array_slice(array_column($e->detail['problems'], 'code'), 0, 2));
        self::assertSame('posted', $this->docs->get($p->id)->status);
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    public function testTheTickIsReadUnderThePostingsLock(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Locked Tick Ltd');
        $sid = (int) $s['id'];
        $this->tick($buyer, $sid, true);
        $sku = self::makeSku('Locked tick item');
        $this->dry($sku);
        $d = $this->bareDraft($desk, $sid, $sku);
        $other = TestDb::connect();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        // The screens' plan (no locks) holds nothing.
        self::$db->transaction(function () use ($d, $other, $sid): void {
            ReceiptPlan::build(self::$db, $d->id, Clock::now(), false, false, new Settings(self::$db));
            self::assertSame(1, $other->exec('UPDATE supplier SET goods_in_without_invoice = 0 WHERE id = ?', [$sid]));
            $other->exec('UPDATE supplier SET goods_in_without_invoice = 1 WHERE id = ?', [$sid]);
        });
        // The posting's plan (validate and post) holds the supplier row until the posting ends: the tick cannot be taken off meanwhile.
        self::$db->transaction(function () use ($d, $other, $sid): void {
            $plan = ReceiptPlan::build(self::$db, $d->id, Clock::now(), true, false, new Settings(self::$db));
            self::assertSame(['number', 'copy'], $plan['header']['invoice_to_come']);
            self::assertSame(1205, self::mysqlError(fn () => $other->exec('UPDATE supplier SET goods_in_without_invoice = 0 WHERE id = ?', [$sid])),
                'a lock wait: the tick is held for the posting');
        });
        self::assertSame(1, (int) self::$db->value('SELECT goods_in_without_invoice FROM supplier WHERE id = ?', [$sid]));
    }

    public function testBookItsInvoiceWithNoNumberOnTheDeliveryAndTheDutyRegisterPrintsItsNumber(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cloud Tech Ltd');
        $sid = (int) $s['id'];
        $this->tick($buyer, $sid, true);
        $sku = self::makeSku('Cloud tech liquid 10ml');
        $this->liquid($sku);
        $d = $this->bareDraft($desk, $sid, $sku);
        $this->bench($this->staffUser('goods_in'), $d->id);
        $grn = $this->post($desk, $d->id);
        $register = new DutyRegister(self::$db, new Settings(self::$db));
        $f = DutyRegister::filters(['supplier' => (string) $sid]);
        self::assertSame([[$grn->id, null]], array_map(static fn (array $r): array => [$r['document_id'], $r['supplier_invoice']], $register->rows($f, 10)),
            'no number yet: the register line is blank');
        $left = $this->invoices->notInvoiced($sid);
        self::assertSame([[$grn->id, null, 5]], array_map(static fn (array $r): array => [$r['id'], $r['external_ref'], $r['units_left']], $left));

        // "Book its invoice": a draft with no number (the desk types it from the paper), the delivery's day and its lines.
        $r = $this->invoices->createFromReceipt($desk, $grn->id);
        $inv = $r['document'];
        self::assertSame([null, null, $grn->docDate], [$r['number_taken'], $inv->externalRef, $inv->docDate]);
        self::assertSame(1, $r['lines']);
        self::assertContains('invoice_number_required', array_column($this->invoices->problems($inv->id), 'code'), 'the invoice itself needs its number');
        $posted = $this->postWithTotals($desk, $inv, null, '0.00', ['invoice_number' => 'CT-SINV-000627', 'invoice_date' => $grn->docDate]);
        self::assertSame(['posted', 'CT-SINV-000627'], [$posted->status, $posted->externalRef]);
        self::assertSame([], $this->invoices->notInvoiced($sid), 'billed: off the list');
        self::assertSame([[$grn->id, 'CT-SINV-000627']], array_map(static fn (array $r): array => [$r['document_id'], $r['supplier_invoice']], $register->rows($f, 10)),
            'the register prints the number of the supplier invoice that billed the delivery');
        self::assertSame(1, $register->count(DutyRegister::filters(['q' => 'CT-SINV-000627'])), 'and finds it by that number');
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    public function testTheSupplierFieldIsChangedLikeTheOtherSupplierFields(): void
    {
        $buyer = $this->staffUser('buyer');
        $s = $this->activeSupplier($buyer, ['name' => 'Field Rules Ltd']);
        $sid = (int) $s['id'];
        $e = $this->refusedCode('bad_field', fn () => $this->sup->update($buyer, $sid, (int) $s['version'], ['goods_in_without_invoice' => 'maybe']));
        self::assertSame(['goods_in_without_invoice', 422], [$e->detail['field'], $e->httpStatus]);
        $this->refusedCode('role_not_allowed', fn () => $this->sup->update($this->staffUser('goods_in'), $sid, (int) $s['version'], ['goods_in_without_invoice' => '1']),
            'who may change it = who may change supplier details');
        $this->refusedCode('version_conflict', fn () => $this->sup->update($buyer, $sid, (int) $s['version'] - 1, ['goods_in_without_invoice' => '1']));
        $after = $this->sup->update($buyer, $sid, (int) $s['version'], ['goods_in_without_invoice' => 'yes']);
        self::assertSame([1, (int) $s['version'] + 1], [(int) $after['goods_in_without_invoice'], (int) $after['version']]);
        // A field of the terms (as payment terms): audited, no reviewer task; an empty value is off.
        self::assertSame(0, $this->openTask($sid, 'review'));
        $off = $this->sup->update($buyer, $sid, (int) $after['version'], ['goods_in_without_invoice' => '']);
        self::assertSame(0, (int) $off['goods_in_without_invoice']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'supplier' AND entity_id = ? AND action = 'supplier.update'", [(string) $sid]));
    }

    /** H1 (the lead, 26 Nov): without the invoice number the delivery note (or carbon-book page) is compulsory, and booked once per supplier. */
    public function testWithoutTheNumberTheDeliveryReferenceIsCompulsoryAndBookedOnce(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Carbon Pages Ltd');
        $sid = (int) $s['id'];
        $this->tick($buyer, $sid, true);
        $sku = self::makeSku('Carbon page item');
        $this->dry($sku);
        $bench = $this->staffUser('goods_in');
        $none = $this->bareDraft($desk, $sid, $sku, 2, ['delivery_note' => null]);
        $this->bench($bench, $none->id);
        $e = $this->refusedCode('delivery_ref_required', fn () => $this->post($desk, $none->id));
        self::assertSame('Type the delivery note or page number: without the invoice it is how CW books a delivery once.', $e->detail['problems'][0]['message']);
        $this->grns->cancel($desk, $none->id, $this->docs->get($none->id)->version, 'keyed without its page');
        $first = $this->bareDraft($desk, $sid, $sku, 2, ['delivery_note' => 'Page 0042']);
        $this->bench($bench, $first->id);
        $first = $this->post($desk, $first->id);
        // The same page again (written another way), with or without an invoice number: refused at posting, naming the other delivery.
        $again = $this->bareDraft($desk, $sid, $sku, 2, ['delivery_note' => ' page0042 ']);
        $this->bench($bench, $again->id);
        $e = $this->refusedCode('delivery_ref_taken', fn () => $this->post($desk, $again->id));
        self::assertStringContainsString((string) $first->number, $e->getMessage());
        $numbered = $this->bareDraft($desk, $sid, $sku, 2, ['invoice_number' => 'CB-77', 'delivery_note' => 'PAGE 0042']);
        $this->invoice($desk, $numbered->id);
        $this->bench($bench, $numbered->id);
        $this->refusedCode('delivery_ref_taken', fn () => $this->post($desk, $numbered->id), 'the same delivery keyed again with its invoice');
        // Another page posts; a cancelled or reversed delivery frees its page.
        $other = $this->bareDraft($desk, $sid, $sku, 2, ['delivery_note' => 'PAGE 0043']);
        $this->bench($bench, $other->id);
        self::assertSame('posted', $this->post($desk, $other->id)->status);
        $this->docs->reverse($desk, $first->id, 'entered_in_error', null);
        self::assertSame('posted', $this->post($desk, $again->id)->status, 'the reversed delivery no longer holds its page');
        self::assertSame([], ReceivingInvariants::check(self::$db));
    }

    /** H3: an invoice number billed already (Book its invoice of a delivery booked without it) is not booked again on a fresh delivery. */
    public function testANumberBilledOnADeliveryBookedWithoutItIsNotBookedAgain(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Billed Once Ltd');
        $sid = (int) $s['id'];
        $this->tick($buyer, $sid, true);
        $sku = self::makeSku('Billed once item');
        $this->dry($sku);
        $d = $this->bareDraft($desk, $sid, $sku);
        $this->bench($this->staffUser('goods_in'), $d->id);
        $grn = $this->post($desk, $d->id);
        $inv = $this->invoices->createFromReceipt($desk, $grn->id)['document'];
        $this->postWithTotals($desk, $inv, null, '0.00', ['invoice_number' => 'INV-42']);
        $fresh = $this->bareDraft($desk, $sid, $sku, 5, ['invoice_number' => 'inv-42']);
        $this->invoice($desk, $fresh->id);
        $this->bench($this->staffUser('goods_in'), $fresh->id);
        $e = $this->refusedCode('invoice_billed_elsewhere', fn () => $this->post($desk, $fresh->id));
        self::assertStringContainsString((string) $grn->number, $e->getMessage());
        self::assertSame(5, $this->onHand($sku), 'booked once');
    }

    /** M5, L8: the register's later invoice number is the one that billed THAT line (a delivery of a coil and a liquid billed for the coil only). */
    public function testTheRegisterPrintsTheInvoiceOfItsOwnLine(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Line Billed Ltd');
        $sid = (int) $s['id'];
        $this->tick($buyer, $sid, true);
        $coil = self::makeSku('Line billed coil');
        $this->dry($coil);
        $liquid = self::makeSku('Line billed liquid 10ml');
        $this->liquid($liquid);
        $d = $this->grns->createDraft($desk, $sid, ['delivery_note' => 'PAGE-LB1']);
        $d = $this->grns->saveDraft($desk, $d->id, $d->version, [], [['sku_id' => $coil, 'packs' => 2, 'units_per_pack' => 1, 'pack_price' => '1.00'],
            ['sku_id' => $liquid, 'packs' => 3, 'units_per_pack' => 1, 'pack_price' => '2.00']]);
        $this->bench($this->staffUser('goods_in'), $d->id, [1 => ['stamp_on_pack' => '', 'stamp_type' => '']]);
        $grn = $this->post($desk, $d->id);
        $inv = $this->invoices->createFromReceipt($desk, $grn->id)['document'];
        $lines = array_values(array_filter($this->invoices->lines($inv->id), static fn (array $l): bool => (int) $l['grn_line_no'] === 1));
        $inv = $this->invoices->saveLines($desk, $inv->id, $this->docs->get($inv->id)->version, array_map(static fn (array $l): array => ['kind' => 'goods',
            'sku_id' => (int) $l['sku_id'], 'packs' => (int) $l['packs'], 'units_per_pack' => (int) $l['units_per_pack'], 'pack_price' => (string) $l['pack_price'],
            'grn_document_id' => (int) $l['grn_document_id'], 'grn_line_no' => (int) $l['grn_line_no'], 'entry' => 'receipt'], $lines));
        $this->postWithTotals($desk, $inv, null, '0.00', ['invoice_number' => 'COILS-ONLY-1']);
        $register = new DutyRegister(self::$db, new Settings(self::$db));
        $rows = $register->rows(DutyRegister::filters(['supplier' => (string) $sid]), 10);
        self::assertSame([[$grn->id, 2, null]], array_map(static fn (array $r): array => [$r['document_id'], $r['line_no'], $r['supplier_invoice']], $rows),
            'the liquid line was not billed: still blank');
        self::assertSame(0, $register->count(DutyRegister::filters(['q' => 'COILS-ONLY-1'])), 'and not found by the coils\' invoice');
    }

    /** M6: a copy of a type a delivery never takes (copied from an invoice) stays a refusal; the note is for a copy not there at all. */
    public function testACopyOfTheWrongTypeStaysARefusal(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Wrong Copy Ltd');
        $sid = (int) $s['id'];
        $this->tick($buyer, $sid, true);
        $sku = self::makeSku('Wrong copy item');
        $this->dry($sku);
        $d = $this->bareDraft($desk, $sid, $sku, 1, ['invoice_number' => 'WC-1']);
        $txt = $this->file("invoice as text\n", 'invoice.txt');
        $stored = $this->store->store($desk, $txt, 'invoice.txt', 'supplier_invoice', null);
        $this->store->attach($desk, $d->id, (int) $stored['id'], 'supplier_invoice');
        $this->bench($this->staffUser('goods_in'), $d->id);
        self::assertSame([], $this->grns->plan($d->id)['header']['invoice_to_come'], 'a copy is there: nothing "to come"');
        $this->refusedCode('invoice_file_required', fn () => $this->post($desk, $d->id));
    }

    /** M7: a number of only spaces (a non-breaking one too) is no number: kept empty, "to come", as the booked-once key reads it. */
    public function testANumberOfOnlySpacesIsNoNumber(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Blank Number Ltd');
        $sid = (int) $s['id'];
        $this->tick($buyer, $sid, true);
        $sku = self::makeSku('Blank number item');
        $this->dry($sku);
        $d = $this->bareDraft($desk, $sid, $sku, 1, ['invoice_number' => " \u{00A0}\u{2003} "]);
        self::assertNull($this->docs->get($d->id)->externalRef, 'kept as no number');
        $this->invoice($desk, $d->id);
        $this->bench($this->staffUser('goods_in'), $d->id);
        self::assertSame(['number'], $this->grns->plan($d->id)['header']['invoice_to_come']);
        $old = $this->bareDraft($desk, $sid, $sku, 1, ['invoice_number' => 'X']);
        self::$db->exec("UPDATE document SET external_ref = ? WHERE id = ?", ["\u{00A0}", $old->id]); // a value from before (the column itself)
        self::assertSame(['number', 'copy'], $this->grns->plan($old->id)['header']['invoice_to_come'], 'an old value of only spaces reads as no number');
    }

    /** H2: an invoice whose goods would come in again while deliveries of the same products wait for their invoice. */
    public function testAnInvoiceThatWouldBringInGoodsBookedWithoutTheirInvoiceIsStopped(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Twice In Ltd');
        $sid = (int) $s['id'];
        $this->tick($buyer, $sid, true);
        $sku = self::makeSku('Twice in item');
        $this->dry($sku);
        $d = $this->bareDraft($desk, $sid, $sku);
        $this->bench($this->staffUser('goods_in'), $d->id);
        $grn = $this->post($desk, $d->id);
        // "New invoice" with a line of the same product, no delivery named: it would bring the goods in again.
        $inv = $this->invoices->createDraft($desk, 'invoice', $sid, ['invoice_number' => 'TW-1', 'invoice_date' => self::day('-1 day')]);
        $inv = $this->invoices->saveLines($desk, $inv->id, $inv->version, [['kind' => 'goods', 'sku_id' => $sku, 'packs' => 5, 'units_per_pack' => 1, 'pack_price' => '3.0000']]);
        $p = array_values(array_filter($this->invoices->problems($inv->id), static fn (array $p): bool => $p['code'] === 'receipts_to_come'));
        self::assertCount(1, $p);
        self::assertStringContainsString((string) $grn->number, $p[0]['message'], 'the deliveries waiting are named');
        $this->refusedCode('bad_reason', fn () => $this->invoices->setSeparateDelivery($desk, $inv->id, $this->docs->get($inv->id)->version, 'x'));
        $inv = $this->invoices->setSeparateDelivery($desk, $inv->id, $this->docs->get($inv->id)->version, 'A second delivery came on Friday');
        self::assertNotContains('receipts_to_come', array_column($this->invoices->problems($inv->id), 'code'), 'confirmed as a separate delivery');
        self::assertSame('A second delivery came on Friday', self::$db->value('SELECT separate_delivery FROM supplier_invoice WHERE document_id = ?', [$inv->id]));
        self::assertContains('invoice.separate', $this->audits($inv->id));
        // Billing the delivery instead: its own invoice names it, nothing to confirm.
        $bill = $this->invoices->createFromReceipt($desk, $grn->id)['document'];
        self::assertNotContains('receipts_to_come', array_column($this->invoices->problems($bill->id), 'code'));
    }
}
