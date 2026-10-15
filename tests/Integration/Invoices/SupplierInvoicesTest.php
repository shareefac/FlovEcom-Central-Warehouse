<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Invoices;

use CW\Caller;
use CW\Db;
use CW\Documents\Document;
use CW\Documents\DocumentHandler;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Documents\SizeApproval;
use CW\Invoices\InvoiceInvariants;
use CW\Invoices\SupplierInvoiceHandler;
use CW\Invoices\SupplierInvoices;
use CW\Tests\Support\TestDb;

/**
 * Supplier invoices (pack C, plan IM7; docs/decisions.md PC1-PC12): the quick way with the delivery, the duplicate refused, the 3-way
 * match inside and outside the tolerances (the "To match" list, a person's acceptance, the second-person switch), a partial delivery
 * with a split invoice and several deliveries on one invoice, the total check, freight and duty recorded for the value engine, a
 * receipt's reversal, the OK first for differences, reversing.
 */
final class SupplierInvoicesTest extends InvoicesTestCase
{
    public function testTheQuickWayAnInvoiceBookedWithItsDeliveryIsMatched(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Quick Way Ltd');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 2, 6, '12.0000', 2);
        $grnNumber = (string) $this->docs->get($x['grn']->id)->externalRef;
        $left = $this->invoices->notInvoiced((int) $s['id']);
        self::assertSame([[$x['grn']->id, 12, '24.00']], array_map(static fn (array $r): array => [(int) $r['id'], (int) $r['units_left'], (string) $r['value_left']], $left),
            'the delivery waits for its invoice: 12 units, £24.00 at the delivery\'s cost');

        $r = $this->invoices->createFromReceipt($desk, $x['grn']->id);
        $d = $r['document'];
        self::assertNull($r['number_taken']);
        self::assertSame(['draft', $grnNumber], [$d->status, $d->externalRef], 'the delivery\'s supplier invoice number');
        $lines = $this->invoices->lines($d->id);
        self::assertCount(1, $lines);
        self::assertSame(['goods', $x['sku'], 12, 2, 6, '12.0000', $x['grn']->id, 1, $x['po']->id, 1, '24.000000', 'receipt'], [$lines[0]['kind'], $lines[0]['sku_id'],
            $lines[0]['qty'], $lines[0]['packs'], $lines[0]['units_per_pack'], $lines[0]['pack_price'], $lines[0]['grn_document_id'], $lines[0]['grn_line_no'],
            $lines[0]['po_document_id'], $lines[0]['po_line_no'], $lines[0]['amount'], $lines[0]['entry']]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$d->id]), 'the delivery\'s invoice copy');
        self::assertSame(['totals_required'], array_column($this->invoices->problems($d->id), 'code'), 'the desk types the total and the VAT from the paper');
        $this->refusedCode('totals_required', fn () => $this->invoices->post($desk, $d->id, $d->version));

        $this->refusedCode('gross_mismatch', fn () => $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => '24.00', 'vat_total' => '4.80',
            'gross_total' => '29.80']), 'net + VAT must be the total to pay');
        $p = $this->postWithTotals($desk, $d, '24.00', '4.80', ['gross_total' => '28.80']);
        self::assertSame(['posted', 'SINV-000001', 'not_required'], [$p->status, $p->number, $p->reviewState], 'no reviewer check by default');
        self::assertSame(['matched', '0', '0.00'], array_values(array_map('strval', $this->stored($p->id))));
        self::assertSame(['24.00', '4.80', '28.80'], array_values((array) self::$db->one('SELECT net_total, vat_total, gross_total FROM supplier_invoice WHERE document_id = ?', [$p->id])));
        self::assertSame([], $this->invoices->notInvoiced((int) $s['id']), 'nothing waits to be invoiced');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM invoice_value_event WHERE document_id = ?', [$p->id]), 'the price is the delivery\'s: no value change');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger WHERE document_id = ?', [$p->id]), 'an invoice moves no stock');
        self::assertSame(0, $this->openDocTask($p->id, 'review'));
        self::assertContains('invoice.post', $this->audits($p->id));
        self::assertSame([], InvoiceInvariants::check(self::$db));

        // The delivery's number is on an invoice now: another start from the same delivery leaves the number to type.
        $this->refusedCode('nothing_to_invoice', fn () => $this->invoices->createFromReceipt($desk, $x['grn']->id));
    }

    public function testADuplicateInvoiceIsRefusedNamingTheEarlierOne(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Duplicates Ltd');
        $other = $this->activeSupplier($buyer, ['name' => 'Other Duplicates Ltd']);
        $first = $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'INV-77']);
        $e = $this->refusedCode('duplicate_invoice', fn () => $this->invoices->createDraft($this->staffUser('purchasing_desk'), 'invoice', (int) $s['id'],
            ['invoice_number' => ' inv-77 ']), 'the same number typed differently');
        self::assertStringContainsString("draft invoice #{$first->id}", $e->getMessage(), 'names the earlier one and who keyed it');
        self::assertSame($first->id, $e->detail['other']);
        // Another supplier's invoice of the same number, and a credit note of the same number, are other papers.
        $this->invoices->createDraft($desk, 'invoice', (int) $other['id'], ['invoice_number' => 'INV-77']);
        $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['invoice_number' => 'INV-77']);

        // Posted, the duplicate names its number.
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 1, 1, '5.0000', 1);
        $first = $this->invoices->addReceipt($desk, $first->id, $first->version, $x['grn']->id);
        $first = $this->postWithTotals($desk, $first, null, '1.00', ['invoice_date' => self::day('-1 day')]);
        self::assertSame('posted', $first->status);
        $e = $this->refusedCode('duplicate_invoice', fn () => $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'inv-77']));
        self::assertStringContainsString('SINV-000001', $e->getMessage());
        // A draft that takes the number later is refused the same way.
        $later = $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'INV-78']);
        $this->refusedCode('duplicate_invoice', fn () => $this->invoices->saveHeader($desk, $later->id, $later->version, ['invoice_number' => 'INV-77']));

        // Reversed, the number is free again.
        $this->invoices->reverse($desk, $first->id, 'entered_in_error', null);
        self::assertNull(self::$db->value('SELECT invoice_key FROM supplier_invoice WHERE document_id = ?', [$first->id]));
        self::assertSame('draft', $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => 'INV-77'])->status);
    }

    public function testTheThreeWayMatchInsideAndOutsideTheTolerances(): void
    {
        $this->setting('invoices.check_price', 'true'); // PC19: with the price check on, the tolerances apply as before
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Matching Ltd');
        $sid = (int) $s['id'];
        // Within: the order says £10.00 a pack, the invoice £10.15 (1.5 %, £1.50 on ten packs): within 2 % or £5.
        $a = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '10.0000', 10);
        $d = $this->invoices->createFromReceipt($desk, $a['grn']->id)['document'];
        $l = $this->invoices->lines($d->id);
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($l[0], ['pack_price' => '10.1500'])]);
        $within = $this->postWithTotals($desk, $d);
        self::assertSame('within_tolerance', $this->stored($within->id)['match_state']);
        $m = $this->invoices->matchOf($within->id);
        self::assertSame(['within', 150, ['price_over'], 'order'], [$m['lines'][1]['state'], $m['lines'][1]['price_e2'], $m['lines'][1]['why'], $m['lines'][1]['ref_from']]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM invoice_value_event WHERE document_id = ? AND kind = 'price_difference' AND amount = '1.50'", [$within->id]),
            'the £1.50 above the delivery\'s cost is recorded for the value engine');

        // Outside: £11.00 a pack (10 %, £10): needs review, on the "To match" list.
        $b = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '10.0000', 10);
        $d = $this->invoices->createFromReceipt($desk, $b['grn']->id)['document'];
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($this->invoices->lines($d->id)[0], ['pack_price' => '11.0000'])]);
        $off = $this->postWithTotals($desk, $d);
        self::assertSame(['needs_review', '1', '10.00'], array_values(array_map('strval', $this->stored($off->id))));
        self::assertSame([$off->id], array_map(static fn (array $r): int => (int) $r['id'], $this->invoices->toMatch()));
        self::assertSame(1, $this->invoices->toMatchCount());

        // Quantity: twelve packs billed of the ten received: off (exact by default) ...
        $c = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '10.0000', 10);
        $d = $this->invoices->createFromReceipt($desk, $c['grn']->id)['document'];
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($this->invoices->lines($d->id)[0], ['packs' => 12])]);
        $qty = $this->postWithTotals($desk, $d);
        $m = $this->invoices->matchOf($qty->id);
        self::assertSame(['off', 2, ['qty_over'], 2000], [$m['lines'][1]['state'], $m['lines'][1]['excess'], $m['lines'][1]['why'], $m['lines'][1]['diff_e2']]);
        self::assertSame('needs_review', $this->stored($qty->id)['match_state']);

        // ... within a quantity tolerance of 20 %: "Check again" works the stored matches out with the tolerances now.
        $this->setting('invoices.qty_tolerance_pct', '"20"');
        self::assertSame(1, $this->invoices->recheck($desk, [$qty->id, $off->id]));
        self::assertSame(['within_tolerance', 'needs_review'], [$this->stored($qty->id)['match_state'], $this->stored($off->id)['match_state']]);

        // A person accepts the differences (the poster may, while the second-person switch is off) ...
        $this->refusedCode('note_required', fn () => $this->invoices->accept($desk, $off->id, 'ok'));
        $this->invoices->accept($desk, $off->id, 'Price rise agreed on the phone');
        self::assertSame('accepted', $this->stored($off->id)['match_state']);
        self::assertSame([], $this->invoices->toMatch());
        self::assertSame([$off->id], array_map(static fn (array $r): int => (int) $r['id'], $this->invoices->toMatch('accepted')));
        self::assertContains('invoice.accept', $this->audits($off->id));
        $this->refusedCode('not_needs_review', fn () => $this->invoices->accept($desk, $within->id, 'Nothing to accept here'));
        $this->refusedCode('role_not_allowed', fn () => $this->invoices->accept($buyer, $off->id, 'A buyer cannot accept'));

        // ... and, with the switch on, someone other than the person who posted it.
        $this->setting('approvals.invoice_accept_other', 'true');
        $e2 = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '10.0000', 10);
        $d = $this->invoices->createFromReceipt($desk, $e2['grn']->id)['document'];
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($this->invoices->lines($d->id)[0], ['pack_price' => '12.0000'])]);
        $second = $this->postWithTotals($desk, $d);
        $this->refusedCode('own_invoice', fn () => $this->invoices->accept($desk, $second->id, 'I posted it myself'));
        $this->invoices->accept($this->staffUser('reviewer'), $second->id, 'Checked with the buyer');
        self::assertSame('accepted', $this->stored($second->id)['match_state']);
    }

    public function testAPartialDeliveryWithASplitInvoiceAndTwoDeliveriesOnOneInvoice(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Split Ltd');
        $sid = (int) $s['id'];
        $o = $this->approvedPoFor($buyer, $sid, 10, 1, '5.0000');
        $line = ['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'units_per_pack' => 1, 'pack_price' => '5.0000', 'po_line_no' => 1];
        $g1 = $this->delivered($desk, $sid, [$line + ['packs' => 6]], $o['doc']->id);
        $g2 = $this->delivered($desk, $sid, [$line + ['packs' => 4]], $o['doc']->id);
        self::assertSame('received', $this->poRow($o['doc']->id)['state'], 'a partial delivery, then the rest');

        // Invoice X bills 4 of the first delivery's 6: a partial invoice is matched (2 still wait to be invoiced).
        $x = $this->invoices->createFromReceipt($desk, $g1->id)['document'];
        $x = $this->invoices->saveLines($desk, $x->id, $x->version, [self::lineOf($this->invoices->lines($x->id)[0], ['packs' => 4])]);
        $x = $this->postWithTotals($desk, $x);
        self::assertSame('matched', $this->stored($x->id)['match_state']);
        self::assertSame([$g2->id => 4, $g1->id => 2], array_column(array_map(static fn (array $r): array => [(int) $r['id'], (int) $r['units_left']],
            $this->invoices->notInvoiced($sid)), 1, 0));
        self::assertSame([[$g1->id, 2, '10.00']], array_map(static fn (array $r): array => [(int) $r['grn_document_id'], (int) $r['units_left'], (string) $r['value_left']],
            self::$db->all('SELECT grn_document_id, units_left, value_left FROM grn_invoice_left WHERE grn_document_id IN (?, ?)', [$g1->id, $g2->id])),
            'what the list reads (PC18): stored for the delivery X named; none for the one no invoice has named: everything it accepted is left');

        // Invoice Y bills the rest of the first delivery and all of the second: two deliveries on one invoice.
        $y = $this->invoices->createDraft($desk, 'invoice', $sid, ['invoice_number' => 'Y-1', 'invoice_date' => self::day('-1 day')]);
        $y = $this->invoices->addReceipt($desk, $y->id, $y->version, $g1->id);
        $y = $this->invoices->addReceipt($desk, $y->id, $y->version, $g2->id);
        self::assertSame([[$g1->id, 2], [$g2->id, 4]], array_map(static fn (array $l): array => [$l['grn_document_id'], $l['qty']], $this->invoices->lines($y->id)));
        $this->refusedCode('nothing_to_invoice', fn () => $this->invoices->addReceipt($desk, $y->id, $this->docs->get($y->id)->version, $g2->id));
        $y = $this->postWithTotals($desk, $y);
        self::assertSame(['matched', 'matched'], [$this->stored($x->id)['match_state'], $this->stored($y->id)['match_state']]);
        self::assertSame([], $this->invoices->notInvoiced($sid));

        // Invoice Z bills the second delivery again: both invoices on it show; Z's cancellation clears Y again.
        $z = $this->invoices->createDraft($desk, 'invoice', $sid, ['invoice_number' => 'Z-1', 'invoice_date' => self::day('-1 day')]);
        $z = $this->invoices->saveLines($desk, $z->id, $z->version, [['kind' => 'goods', 'sku_id' => $o['sku'], 'packs' => 4, 'units_per_pack' => 1, 'pack_price' => '5.0000',
            'grn_document_id' => $g2->id, 'grn_line_no' => 1]]);
        $z = $this->postWithTotals($desk, $z);
        self::assertSame(['needs_review', 'needs_review'], [$this->stored($y->id)['match_state'], $this->stored($z->id)['match_state']], 'billed twice: both need a person');
        $this->invoices->reverse($desk, $z->id, 'duplicate', null);
        self::assertSame(['matched', null], [$this->stored($y->id)['match_state'], $this->stored($z->id)['match_state']]);

        // An invoice before its goods: the order's lines, linked to the order only, need review (no delivery).
        $o2 = $this->approvedPoFor($buyer, $sid, 3, 1, '5.0000');
        $early = $this->invoices->createDraft($desk, 'invoice', $sid, ['invoice_number' => 'EARLY-1', 'invoice_date' => self::day('-1 day')]);
        $early = $this->invoices->addOrder($desk, $early->id, $early->version, $o2['doc']->id);
        $early = $this->postWithTotals($desk, $early);
        $m = $this->invoices->matchOf($early->id);
        self::assertSame(['off', ['no_delivery'], 'order'], [$m['lines'][1]['state'], $m['lines'][1]['why'], $m['lines'][1]['ref_from']]);

        // V7 finds stored figures that are not what the delivery has left (a change that forgot InvoiceMatch::storeReceipts()).
        self::assertSame([], InvoiceInvariants::check(self::$db));
        self::$db->exec('UPDATE grn_invoice_left SET units_left = units_left + 1 WHERE grn_document_id = ?', [$g1->id]);
        self::assertSame(["V7 delivery {$g1->id}: stores 1 units (0.00) left to invoice, but it has 0 (0.00) now"], InvoiceInvariants::check(self::$db));
        self::$db->exec('UPDATE grn_invoice_left SET units_left = units_left - 1 WHERE grn_document_id = ?', [$g1->id]);
    }

    public function testTheLinesMustAddUpToTheStatedTotal(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Totals Ltd');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 3, 1, '3.3333', 3);
        $d = $this->invoices->createFromReceipt($desk, $x['grn']->id)['document'];
        $d = $this->invoices->addCharge($desk, $d->id, $d->version, 'freight', '4.50', 'Carriage');
        // The lines add up to £14.50 (3 x £3.3333 = £10.00, plus £4.50): two lines may be 2 x 5p off the stated total, not 11p.
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => '14.61', 'vat_total' => '2.92']);
        $e = $this->refusedCode('total_mismatch', fn () => $this->invoices->post($desk, $d->id, $d->version));
        self::assertStringContainsString('£14.50', $e->getMessage());
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => '14.53']);
        $p = $this->invoices->post($desk, $d->id, $d->version);
        self::assertSame(['posted', 'within_tolerance'], [$p->status, $this->stored($p->id)['match_state']], 'within the rounding: the total differs by 3p');
    }

    public function testFreightDutyAndPriceDifferencesAreRecordedForTheValueEngine(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Landed Cost Ltd');
        $liquid = self::makeSku('Landed liquid 10ml');
        $this->liquid($liquid, '10');
        $coil = self::makeSku('Landed coil');
        $this->dry($coil);
        $grn = $this->delivered($desk, (int) $s['id'], [['sku_id' => $liquid, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '2.00'],
            ['sku_id' => $coil, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '1.00']]);
        $d = $this->invoices->createFromReceipt($desk, $grn->id)['document'];
        $l = $this->invoices->lines($d->id);
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($l[0]), self::lineOf($l[1], ['pack_price' => '1.10']),
            ['kind' => 'freight', 'amount' => '3.00', 'description' => 'Carriage'], ['kind' => 'duty', 'amount' => '22.00', 'description' => 'Vaping duty']]);
        $p = $this->postWithTotals($desk, $d, '56.00', '11.20');
        self::assertSame([
            ['price_difference', $coil, '1.00', 'delivery_cost'],
            ['freight', $liquid, '1.94', 'value'],
            ['freight', $coil, '1.06', 'value'],
            ['duty', $liquid, '22.00', 'ml'],
        ], array_map(static fn (array $r): array => [$r['kind'], (int) $r['sku_id'], (string) $r['amount'], $r['basis']],
            self::$db->all('SELECT kind, sku_id, amount, basis FROM invoice_value_event WHERE document_id = ? ORDER BY id', [$p->id])),
            'freight by value (£20 : £11, the penny to the larger remainder), duty by ml (the coil holds none)');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_value_ledger'), 'the value engine is not built: nothing is valued yet');
        $rev = $this->invoices->reverse($desk, $p->id, 'entered_in_error', null);
        self::assertSame('0.00', (string) self::$db->value('SELECT CAST(SUM(amount) AS DECIMAL(14,2)) FROM invoice_value_event WHERE document_id IN (?, ?)', [$p->id, $rev->id]));
        self::assertSame([], InvoiceInvariants::check(self::$db));
    }

    public function testAReversedDeliveryPutsItsInvoiceBackOnTheList(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Reversed Delivery Ltd');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 4, 1, '2.5000', 4);
        $inv = $this->invoiceDelivery($desk, $x['grn']->id);
        self::assertSame('matched', $this->stored($inv->id)['match_state']);
        $this->docs->reverse($desk, $x['grn']->id, 'entered_in_error', null);
        self::assertSame('needs_review', $this->stored($inv->id)['match_state'], 'the receipt\'s reversal worked the invoice out again');
        self::assertSame(['delivery_reversed'], $this->invoices->matchOf($inv->id)['lines'][1]['why']);
    }

    public function testTheOkFirstForAnInvoiceOutsideTheTolerances(): void
    {
        $this->setting('invoices.check_price', 'true'); // PC19: with the price check on, the tolerances apply as before
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Ok First Ltd');
        $this->rule('SINV', ['approval_rule' => 'over_difference', 'approval_limit_units' => 5]);
        $a = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 10, 1, '10.0000', 10);
        $d = $this->invoices->createFromReceipt($desk, $a['grn']->id)['document'];
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($this->invoices->lines($d->id)[0], ['pack_price' => '11.0000'])]);
        $w = $this->postWithTotals($desk, $d);
        self::assertSame(['awaiting_approval', null], [$w->status, $w->number], '£10 over, more than the £5 limit: a reviewer first');
        self::assertSame('over_difference', self::$db->value("SELECT reason FROM review_task WHERE subject_id = ? AND state = 'open'", [$w->id]));
        $p = $this->docs->approve($this->staffUser('reviewer'), $this->openDocTask($w->id), null);
        self::assertSame(['posted', 'needs_review'], [$p->status, $this->stored($p->id)['match_state']]);
        // A matched invoice does not wait.
        $b = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 10, 1, '10.0000', 10);
        self::assertSame('posted', $this->invoiceDelivery($desk, $b['grn']->id)->status);
    }

    /**
     * Review finding 1: the OK first is decided with the deliveries locked. Two drafts bill the same ten units at £10 (each matched on
     * its own). While the first posting decides whether it needs the OK, another person posts the second on their own connection: it
     * must wait (it reads the same delivery), so it cannot slip through matched; posted after the first, it bills ten units already
     * invoiced and waits for a reviewer.
     */
    public function testTheOkFirstIsDecidedWithTheDeliveriesLocked(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Locked Ok Ltd');
        $this->rule('SINV', ['approval_rule' => 'over_difference', 'approval_limit_units' => 5]);
        $a = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 10, 1, '10.0000', 10);
        $drafts = [];
        foreach (['LOCK-1', 'LOCK-2'] as $no) {
            $d = $this->invoices->createDraft($desk, 'invoice', (int) $s['id'], ['invoice_number' => $no, 'invoice_date' => self::day('-1 day')]);
            $d = $this->invoices->addReceipt($desk, $d->id, $d->version, $a['grn']->id);
            $drafts[] = $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => '100.00', 'vat_total' => '0.00']);
        }
        [$x, $y] = $drafts;
        $other = null;
        $during = static function () use ($desk, $y, &$other): void {
            if ($other !== null) {
                return;
            }
            $db2 = TestDb::connect();
            $db2->exec('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $other = (new SupplierInvoices($db2, new Documents($db2, DocumentHandlers::all($db2))))->post($desk, $y->id, $y->version)->status;
            } catch (\Throwable $e) {
                $other = Db::driverCode($e) === 1205 ? 'lock_wait' : get_class($e) . ': ' . $e->getMessage();
            }
        };
        $probe = new class (SupplierInvoiceHandler::all(self::$db)['SINV'], $during) implements DocumentHandler, SizeApproval {
            public function __construct(private readonly SupplierInvoiceHandler $real, private readonly \Closure $during)
            {
            }

            public function type(): string
            {
                return 'SINV';
            }

            public function validate(Db $db, Document $doc, array $lines): void
            {
                $this->real->validate($db, $doc, $lines);
            }

            public function approvalUnits(Db $db, Document $doc, array $lines): int
            {
                $units = $this->real->approvalUnits($db, $doc, $lines);
                ($this->during)();
                return $units;
            }

            public function size(Db $db, Document $doc, array $lines): array
            {
                return $this->real->size($db, $doc, $lines);
            }

            public function post(Db $db, Document $doc, array $lines, Caller $caller, string $opKey): int
            {
                return $this->real->post($db, $doc, $lines, $caller, $opKey);
            }

            public function reverse(Db $db, Document $original, Document $reversal, array $lines, Caller $caller, string $opKey): void
            {
                $this->real->reverse($db, $original, $reversal, $lines, $caller, $opKey);
            }
        };
        $p = (new SupplierInvoices(self::$db, new Documents(self::$db, ['SINV' => $probe] + DocumentHandlers::all(self::$db))))->post($desk, $x->id, $x->version);
        self::assertSame('lock_wait', $other, 'the second posting waited: the first held the delivery while it decided');
        self::assertSame(['posted', 'matched'], [$p->status, $this->stored($p->id)['match_state']]);
        $w = $this->invoices->post($desk, $y->id, $this->docs->get($y->id)->version);
        self::assertSame(['awaiting_approval', 'over_difference'], [$w->status, self::$db->value("SELECT reason FROM review_task WHERE subject_id = ? AND state = 'open'", [$y->id])],
            'the second bills ten units already invoiced: £100 over, a reviewer first');
    }

    /**
     * Review finding 4: an acceptance covers exactly the lines and figures a person looked at. Line A bills one unit too many (£10) and
     * is accepted; the supplier credits that unit (matched: the acceptance is dropped); later a return makes line B £10 off: the same
     * count and money as before, but not what anyone accepted.
     */
    public function testAnAcceptanceCoversOnlyWhatWasAccepted(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Fingerprint Ltd');
        $sid = (int) $s['id'];
        $a = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '10.0000', 10);
        $b = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '10.0000', 10);
        $d = $this->invoices->createDraft($desk, 'invoice', $sid, ['invoice_number' => 'FP-1', 'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->addReceipt($desk, $d->id, $d->version, $a['grn']->id);
        $d = $this->invoices->addReceipt($desk, $d->id, $d->version, $b['grn']->id);
        $l = $this->invoices->lines($d->id);
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($l[0], ['packs' => 11]), self::lineOf($l[1])]);
        $inv = $this->postWithTotals($desk, $d);
        self::assertSame(['needs_review', '1', '10.00'], array_values(array_map('strval', $this->stored($inv->id))));
        $this->invoices->accept($desk, $inv->id, 'One extra unit agreed with the rep');
        self::assertSame('accepted', $this->stored($inv->id)['match_state']);

        $cn = $this->invoices->createDraft($desk, 'credit_note', $sid, ['credits_id' => $inv->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 1);
        $this->postWithTotals($desk, $cn, '10.00', '0.00', ['invoice_number' => 'FP-CN', 'invoice_date' => self::day('-1 day')]);
        self::assertSame('matched', $this->stored($inv->id)['match_state']);
        self::assertNull(self::$db->value('SELECT accepted_at FROM supplier_invoice WHERE document_id = ?', [$inv->id]), 'the match moved: the acceptance is dropped');
        self::assertContains('invoice.accept_dropped', $this->audits($inv->id));

        $r = $this->returns->createDraft($desk, $sid, ['reason_code' => 'faulty', 'grn_id' => $b['grn']->id]);
        $r = $this->returns->saveLines($desk, $r->id, $r->version, [['sku_id' => $b['sku'], 'qty' => 1, 'unit_cost' => '10.00', 'grn_document_id' => $b['grn']->id,
            'grn_line_no' => 1]]);
        $this->returns->post($desk, $r->id, $r->version);
        self::assertSame(['needs_review', '1', '10.00'], array_values(array_map('strval', $this->stored($inv->id))), 'line B now: a person looks again');
    }

    /** Review finding 5: an invoice whose OK first is refused is cancelled, and the supplier's number is free for the corrected one. */
    public function testARefusedOkFreesTheSuppliersNumber(): void
    {
        $this->setting('invoices.check_price', 'true'); // PC19: with the price check on, the tolerances apply as before
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Refused Ok Ltd');
        $this->rule('SINV', ['approval_rule' => 'over_difference', 'approval_limit_units' => 5]);
        $a = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 10, 1, '10.0000', 10);
        $d = $this->invoices->createFromReceipt($desk, $a['grn']->id)['document'];
        $number = (string) $d->externalRef;
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($this->invoices->lines($d->id)[0], ['pack_price' => '11.0000'])]);
        $w = $this->postWithTotals($desk, $d);
        self::assertSame('awaiting_approval', $w->status);
        $c = $this->docs->reject($this->staffUser('reviewer'), $this->openDocTask($w->id), 'The price was keyed wrong');
        self::assertSame('cancelled', $c->status);
        self::assertNull(self::$db->value('SELECT invoice_key FROM supplier_invoice WHERE document_id = ?', [$w->id]), 'a cancelled record holds no number');
        $again = $this->invoices->createFromReceipt($desk, $a['grn']->id);
        self::assertSame([null, $number], [$again['number_taken'], $again['document']->externalRef], 'the corrected invoice takes the same number');
        self::assertSame([], InvoiceInvariants::check(self::$db));
    }

    /**
     * PC19 (owner, 15 Oct 2026: "end of the day whatever comes in purchase invoice is the right price"): with invoices.check_price off,
     * the default, a price that differs from the order is matched whatever its size, the difference is recorded for the stock value and
     * shown as information; billing more units than arrived still needs a person; with the check on, the tolerances apply as before.
     */
    public function testThePriceOnTheInvoiceIsTheRightPrice(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Invoice Price Ltd');
        $sid = (int) $s['id'];
        self::assertFalse((new \CW\Settings(self::$db))->get('invoices.check_price'), 'off by default (0040)');
        // Ordered and delivered at £10.00 a pack; the invoice says £15.00 (£50 more on ten, 50 %): matched.
        $a = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '10.0000', 10);
        $d = $this->invoices->createFromReceipt($desk, $a['grn']->id)['document'];
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($this->invoices->lines($d->id)[0], ['pack_price' => '15.0000'])]);
        $p = $this->postWithTotals($desk, $d);
        self::assertSame(['matched', '0', '0.00'], array_values(array_map('strval', $this->stored($p->id))), 'the invoice price is the right price');
        $m = $this->invoices->matchOf($p->id);
        self::assertSame(['matched', [], 5000, 'price_over', 'order'], [$m['lines'][1]['state'], $m['lines'][1]['why'], $m['lines'][1]['price_e2'],
            $m['lines'][1]['price_note'], $m['lines'][1]['ref_from']]);
        self::assertSame([], $this->invoices->toMatch(), 'nothing on "To match"');
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM invoice_value_event WHERE document_id = ? AND kind = 'price_difference' AND amount = '50.00'",
            [$p->id]), 'the £50 above the delivery\'s cost is recorded: the invoice\'s price is the cost carried forward');

        // The units are checked as before: twelve billed of the ten received needs a person, the two extra at the invoice's price.
        $b = $this->orderedAndDelivered($desk, $buyer, $sid, 10, 1, '10.0000', 10);
        $d = $this->invoices->createFromReceipt($desk, $b['grn']->id)['document'];
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [self::lineOf($this->invoices->lines($d->id)[0], ['packs' => 12, 'pack_price' => '15.0000'])]);
        $q = $this->postWithTotals($desk, $d);
        self::assertSame(['needs_review', '1', '30.00'], array_values(array_map('strval', $this->stored($q->id))));
        self::assertSame(['qty_over'], $this->invoices->matchOf($q->id)['lines'][1]['why']);
        self::assertSame([$q->id], array_map(static fn (array $r): int => (int) $r['id'], $this->invoices->toMatch()));

        // With the check on, the tolerances apply as before ("Check these again" works the stored matches out with it).
        $this->setting('invoices.check_price', 'true');
        self::assertSame(1, $this->invoices->recheck($desk, [$p->id, $q->id]));
        self::assertSame(['needs_review', '1', '50.00'], array_values(array_map('strval', $this->stored($p->id))));
        self::assertSame(['price_over'], $this->invoices->matchOf($p->id)['lines'][1]['why']);
        self::assertSame(['needs_review', '1', '80.00'], array_values(array_map('strval', $this->stored($q->id))), 'twelve at £15 against ten at £10');
        self::assertSame([], InvoiceInvariants::check(self::$db));
    }

    public function testWhoMayKeepInvoices(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Who Ltd');
        $this->refusedCode('role_not_allowed', fn () => $this->invoices->createDraft($buyer, 'invoice', (int) $s['id']));
        $this->refusedCode('role_not_allowed', fn () => $this->invoices->createDraft($this->staffUser('goods_in'), 'invoice', (int) $s['id']));
        $this->refusedCode('admin_cannot_post', fn () => $this->invoices->createDraft($this->staffUser(['admin', 'auditor']), 'invoice', (int) $s['id']));
        $this->refusedCode('role_not_allowed', fn () => $this->invoices->createDraft($this->staffUser('reviewer'), 'credit_note', (int) $s['id']));
        $d = $this->invoices->createDraft($this->staffUser('purchasing_manager'), 'invoice', (int) $s['id']);
        $this->refusedCode('not_creator', fn () => $this->invoices->saveHeader($desk, $d->id, $d->version, ['invoice_number' => 'X-1']), 'a draft is its creator\'s');
    }

    /**
     * A line as Invoices::lines() reads it, in saveLines()'s shape, with changes.
     *
     * @param array<string, mixed> $l
     * @param array<string, mixed> $change
     * @return array<string, mixed>
     */
    public static function lineOf(array $l, array $change = []): array
    {
        $out = $l['kind'] === 'goods'
            ? ['kind' => 'goods', 'sku_id' => $l['sku_id'], 'packs' => $l['packs'], 'units_per_pack' => $l['units_per_pack'], 'pack_price' => $l['pack_price'],
                'grn_document_id' => $l['grn_document_id'], 'grn_line_no' => $l['grn_line_no'], 'po_document_id' => $l['po_document_id'], 'po_line_no' => $l['po_line_no'],
                'credit_of_line_no' => $l['credit_of_line_no'], 'supplier_code' => $l['supplier_code'], 'purchase_unit' => $l['purchase_unit'], 'entry' => $l['entry']]
            : ['kind' => $l['kind'], 'amount' => $l['amount'], 'description' => $l['description'], 'entry' => $l['entry']];
        return $change + $out;
    }
}
