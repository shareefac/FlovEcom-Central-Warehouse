<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Arrivals\ArrivalRest;
use CW\Invariants;
use CW\Invoices\InvoiceMatch;

/**
 * CW-1155 "Receive the rest" on the services (docs/decisions.md RR1-RR20; the owner, 22 Nov 2026): a line booked short on the first receipt of
 * an invoice counted on arrival is waited for (the shops never sell it meanwhile: nothing goes back to Incoming); when the rest comes it is
 * received from the SAME invoice (a second receipt, the next part of its number), its posting puts what came on hand (the shops sell it
 * again), makes the invoice match by itself, leaves nothing "not invoiced yet" and resolves the short incident; part arrivals wait for the
 * rest of the rest; what never comes is claimed as today, or the difference accepted (we stop waiting). The invariants (J1-J6, G1-G8, V1-V8)
 * hold after every test (assertPostConditions).
 */
final class ReceiveRestTest extends ArrivalsTestCase
{
    use RestFixtures;

    /**
     * The real case, SINV-000632 (live, 22 Nov): 58 lines, line 19 = 5 x an item at £1.00 the shops sell before it arrives, 6 of it on the
     * shelf; the first receipt books line 19 0 of 5 (5 short): the shelf stays 6, Incoming 0, the match needs review (1 line, £5.00), the short
     * incident is open. With the code alone (no data fix): "waiting for the rest: 5 (£5.00)". Then 3 come, then 2: the shelf 9, then 11, the
     * websites sell them, matched, the incidents resolved, nothing left to invoice; no unit ever went back to Incoming.
     */
    public function testTheRealCaseShapeIsWaitedForAndReceivedInTwoParts(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Real Case Ltd');
        $site = $this->site();
        $lines = [];
        $skus = [];
        for ($i = 1; $i <= 58; $i++) {
            $skus[$i] = self::makeSku("Real case item {$i} 10ml 20mg");
            $lines[] = $i === 19 ? [$skus[$i], 5, 1, '1.0000'] : [$skus[$i], 2, 1, '19.0000'];
        }
        $x = $skus[19];
        $this->book('goods_in', $x, 6);
        $this->listing($site, 'RC19', $x);
        $p = $this->finalInvoice($desk, (int) $s['id'], $lines);
        self::assertSame([5, 6, 11], [$this->incoming($x), $this->onHand($x), $this->view($site, 'RC19')['available']], 'sold before it arrives');
        $g = $this->firstReceipt($desk, $p->id, [19 => ['short_units' => '5']]);
        self::assertSame([0, 6, 6], [$this->incoming($x), $this->onHand($x), $this->view($site, 'RC19')['available']], 'the shops do not sell the five');
        self::assertSame(['needs_review', '1', '5.00'], array_values(array_map('strval', $this->stored($p->id))));
        self::assertSame(['open', null], $this->shortIncident($g->id, 19));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM invoice_arrival_rest'), 'the first receipt writes nothing new: the same as before 0076');
        // The code alone shows the wait.
        self::assertSame([['line' => 19, 'units' => 5, 'amount' => '5.00']], $this->owed($p->id));
        $w = ArrivalRest::waiting(self::$db, $p->id, new \DateTimeImmutable('now'));
        self::assertSame([5, '5.00', 0, false, $g->id], [$w['units'] ?? null, $w['amount'] ?? null, $w['days'] ?? null, $w['late'] ?? null, $w['first_grn'] ?? null]);
        self::assertSame([['line_no' => 19, 'units' => 5, 'amount' => '5.00']], array_map(static fn (array $m): array => ['line_no' => $m['line_no'], 'units' => $m['units'],
            'amount' => $m['amount']], $this->arrivals->missingOf($g->id)), 'the claim would claim the same five');

        // Three come.
        $r1 = $this->arrivals->receiveRest($desk, $p->id);
        self::assertSame([$p->externalRef, 2, 0], [$r1->externalRef, (int) self::$db->value('SELECT invoice_part FROM goods_receipt WHERE document_id = ?', [$r1->id]),
            (int) self::$db->value('SELECT auto FROM goods_receipt_invoice WHERE grn_document_id = ?', [$r1->id])], 'the same invoice, its next part');
        self::assertSame([[$x, 5, 1]], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs'], (int) $l['units_per_pack']], $this->grns->lines($r1->id)));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$r1->id]), 'its copy');
        self::assertSame($r1->id, $this->arrivals->receiveRest($desk, $p->id)->id, 'pressed twice: the same draft');
        self::assertSame([], array_values(array_intersect(['duplicate_invoice', 'invoice_shared', 'invoice_copy_elsewhere', 'not_owed', 'claimed'],
            array_column($this->grns->plan($r1->id)['problems'], 'code'))), 'one invoice on two receipts made from it, its copy on both: allowed');
        $this->bench($this->staffUser('goods_in'), $r1->id, [1 => ['short_units' => '2']]);
        $r1 = $this->post($desk, $r1->id);
        self::assertSame([0, 9, 9], [$this->incoming($x), $this->onHand($x), $this->view($site, 'RC19')['available']], 'three on the shelf, on sale');
        self::assertSame(0, $this->arriveRows($r1->id), 'nothing came out of Incoming: it was never there');
        self::assertSame(['needs_review', '1', '2.00'], array_values(array_map('strval', $this->stored($p->id))), 'two still missing');
        self::assertSame([['line' => 19, 'units' => 2, 'amount' => '2.00']], $this->owed($p->id));
        self::assertSame(['open', null], $this->shortIncident($g->id, 19), 'part of it still waited for');
        self::assertSame(['open', null], $this->shortIncident($r1->id, 1));
        self::assertSame(0, $this->left($r1->id), 'invoiced by its invoice');
        self::assertSame([], Invariants::check(self::$db));

        // The last two.
        $r2 = $this->arrivals->receiveRest($desk, $p->id);
        self::assertSame([3, [[$x, 2]]], [(int) self::$db->value('SELECT invoice_part FROM goods_receipt WHERE document_id = ?', [$r2->id]),
            array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs']], $this->grns->lines($r2->id))]);
        $this->bench($this->staffUser('goods_in'), $r2->id);
        $r2 = $this->post($desk, $r2->id);
        self::assertSame([0, 11, 11], [$this->incoming($x), $this->onHand($x), $this->view($site, 'RC19')['available']]);
        self::assertSame('matched', $this->stored($p->id)['match_state'], 'matched by itself');
        self::assertNotContains($p->id, array_map(static fn (array $r): int => (int) $r['id'], $this->invoices->toMatch()), 'out of To match');
        self::assertSame(['resolved', "The rest came on {$r2->number}."], $this->shortIncident($g->id, 19));
        self::assertSame(['resolved', "The rest came on {$r2->number}."], $this->shortIncident($r1->id, 1));
        self::assertSame([0, 0], [$this->left($r1->id), $this->left($r2->id)]);
        self::assertSame([], array_values(array_filter($this->invoices->notInvoiced((int) $s['id']), static fn (array $r): bool => in_array((int) $r['id'],
            [$g->id, $r1->id, $r2->id], true))), 'nothing "not invoiced yet"');
        self::assertSame([], $this->owed($p->id));
        self::assertNull(ArrivalRest::waiting(self::$db, $p->id, new \DateTimeImmutable('now')));
        $this->refusedCode('nothing_owed', fn () => $this->arrivals->receiveRest($desk, $p->id));
        self::assertSame([[19, $g->id, $r1->id, 1, 3], [19, $g->id, $r2->id, 1, 2]], array_map(static fn (array $r): array => array_map('intval', array_values($r)),
            self::$db->all("SELECT line_no, first_grn_document_id, document_id, document_line_no, units FROM invoice_arrival_rest WHERE kind = 'rest' ORDER BY id")));
    }

    /** Everything comes at once; the receipt of the rest has no order and the first receipt's PO line is filled up when there is one. */
    public function testTheRestInOneGoAgainstItsOrder(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('One Go Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10, 1, '2.0000');
        $this->invoices->attach($desk, $x['doc']->id, $this->pdf(), 'invoice.pdf', 'supplier_invoice');
        $p = $this->invoices->submit($desk, $x['doc']->id, $this->docs->get($x['doc']->id)->version);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '4']]);
        self::assertSame('part_received', (string) self::$db->value('SELECT state FROM purchase_order WHERE document_id = ?', [$x['po']->id]));
        $r = $this->arrivals->receiveRest($desk, $p->id);
        self::assertSame([[$x['sku'], 4, 1]], array_map(static fn (array $l): array => [(int) $l['sku_id'], (int) $l['packs'], (int) $l['po_line_no']], $this->grns->lines($r->id)),
            'against the same order line');
        $this->bench($this->staffUser('goods_in'), $r->id);
        $r = $this->post($desk, $r->id);
        self::assertSame([10, 0], [$this->onHand($x['sku']), $this->incoming($x['sku'])]);
        self::assertSame('received', (string) self::$db->value('SELECT state FROM purchase_order WHERE document_id = ?', [$x['po']->id]), 'the order is complete');
        self::assertSame('matched', $this->stored($p->id)['match_state']);
        self::assertSame('resolved', $this->shortIncident($g->id, 1)[0]);
    }

    /** Loose units of a pack line: the rest of 3 single units of a box of 6 comes as 3 units at the unit price. */
    public function testLooseUnitsOfAPackLine(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Loose Units Ltd');
        $sku = self::makeSku('Boxed item');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 2, 6, '12.0000']]); // 12 units at £2.00
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '3']]);
        self::assertSame([['line' => 1, 'units' => 3, 'amount' => '6.00']], $this->owed($p->id));
        $r = $this->arrivals->receiveRest($desk, $p->id);
        self::assertSame([[3, 1, '2.0000']], array_map(static fn (array $l): array => [(int) $l['packs'], (int) $l['units_per_pack'], (string) $l['pack_price']],
            $this->grns->lines($r->id)));
        $this->bench($this->staffUser('goods_in'), $r->id);
        $this->post($desk, $r->id);
        self::assertSame([12, 'matched'], [$this->onHand($sku), $this->stored($p->id)['match_state']]);
        self::assertSame('resolved', $this->shortIncident($g->id, 1)[0]);
    }

    /** After a part arrival, "Claim what did not come" claims only what is still missing; a claim and the rest exclude each other. */
    public function testAClaimAfterAPartArrivalAndTheClaimAgainstTheRest(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Claim Part Ltd');
        $sku = self::makeSku('Claimed item');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        // A receipt of the rest being booked in: no claim meanwhile.
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $this->refusedCode('rest_being_received', fn () => $this->arrivals->claimMissing($desk, $g->id));
        $this->bench($this->staffUser('goods_in'), $r->id, [1 => ['short_units' => '2']]);
        $this->post($desk, $r->id);
        self::assertSame([['line_no' => 1, 'units' => 2, 'amount' => '2.00']], array_map(static fn (array $m): array => ['line_no' => $m['line_no'], 'units' => $m['units'],
            'amount' => $m['amount']], $this->arrivals->missingOf($g->id)), 'three came: two are missing');
        $dn = $this->arrivals->claimMissing($desk, $g->id);
        self::assertSame([[null, '2.000000']], array_map(static fn (array $l): array => [$l['sku_id'], $l['amount']], $this->returns->lines($dn->id)));
        self::assertSame([], $this->owed($p->id), 'claimed: not waited for');
        $this->refusedCode('claimed', fn () => $this->arrivals->receiveRest($desk, $p->id));
        // The claim cancelled (the goods came after all): waited for again.
        $this->returns->cancel($desk, $dn->id, $this->docs->get($dn->id)->version, 'The goods came after all');
        self::assertSame([['line' => 1, 'units' => 2, 'amount' => '2.00']], $this->owed($p->id));
        $r2 = $this->restReceipt($desk, $p->id);
        self::assertSame([5, 'matched'], [$this->onHand($sku), $this->stored($p->id)['match_state']]);
        self::assertSame(['resolved', "The rest came on {$r2->number}."], $this->shortIncident($g->id, 1));
        $this->refusedCode('nothing_missing', fn () => $this->arrivals->claimMissing($desk, $g->id), 'everything came');
    }

    /** A claim posted before the rest: the rest is refused until it is cancelled or taken back; posted and answered as today otherwise. */
    public function testAClaimOfEverythingAndALegacyClaim(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Claim All Ltd');
        $sku = self::makeSku('Never comes');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 4, 1, '3.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '4']]);
        $dn = $this->arrivals->claimMissing($desk, $g->id);
        $dn = $this->returns->post($desk, $dn->id, $this->docs->get($dn->id)->version);
        $this->refusedCode('claimed', fn () => $this->arrivals->receiveRest($desk, $p->id));
        self::assertSame([], $this->owed($p->id));
        // A claim made before 0076 (or by hand) has no rows: it claims everything all the same.
        self::$db->exec("DELETE FROM invoice_arrival_rest WHERE kind = 'claim' AND document_id = ?", [$dn->id]);
        self::assertSame([], $this->owed($p->id));
        $this->refusedCode('claimed', fn () => $this->arrivals->receiveRest($desk, $p->id));
        $this->returns->reverse($desk, $dn->id, 'entered_in_error', null);
        self::assertSame([['line' => 1, 'units' => 4, 'amount' => '12.00']], $this->owed($p->id), 'the claim taken back: waited for again');
    }

    /** The receipt of the rest taken back: the units off the shelf, owed again, the incident open again; then received again. */
    public function testTheRestTakenBackIsWaitedForAgain(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Rest Back Ltd');
        $site = $this->site();
        $sku = self::makeSku('Comes twice');
        $this->listing($site, 'RB1', $sku);
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 8, 1, '1.5000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $r = $this->restReceipt($desk, $p->id);
        self::assertSame([8, 'matched', 'resolved'], [$this->onHand($sku), $this->stored($p->id)['match_state'], $this->shortIncident($g->id, 1)[0]]);
        $this->refusedCode('rest_received', fn () => $this->docs->reverse($desk, $g->id, 'entered_in_error', null), 'the first receipt waits for the rest to go first');
        $this->docs->reverse($desk, $r->id, 'entered_in_error', null);
        self::assertSame([3, 0, 3], [$this->onHand($sku), $this->incoming($sku), $this->view($site, 'RB1')['available']], 'never back to Incoming');
        self::assertSame(['needs_review', '1', '7.50'], array_values(array_map('strval', $this->stored($p->id))));
        self::assertSame([['line' => 1, 'units' => 5, 'amount' => '7.50']], $this->owed($p->id));
        self::assertSame(['open', null], $this->shortIncident($g->id, 1), 'open again: the rest it said came was taken back');
        self::assertSame([], Invariants::check(self::$db));
        $r2 = $this->restReceipt($desk, $p->id);
        self::assertSame([8, 'matched', ['resolved', "The rest came on {$r2->number}."]], [$this->onHand($sku), $this->stored($p->id)['match_state'],
            $this->shortIncident($g->id, 1)]);
        // A person's own resolution is never reopened.
        $this->docs->reverse($desk, $r2->id, 'entered_in_error', null);
        $this->restReceipt($desk, $p->id, [1 => ['short_units' => '1']]);
        (new \CW\Receiving\Incidents(self::$db))->resolve($desk, (int) self::$db->value('SELECT id FROM incident WHERE dedupe_key = ?', ["grn:{$g->id}:1:short"]),
            'resolved', 'The supplier credits the last one');
        $last = (int) self::$db->value("SELECT MAX(document_id) FROM invoice_arrival_rest WHERE kind = 'rest'");
        $this->docs->reverse($desk, $last, 'entered_in_error', null);
        self::assertSame('resolved', $this->shortIncident($g->id, 1)[0], 'the person\'s resolution stays');
    }

    /** The first receipt is not taken back while a receipt of the rest stands (posted or a draft); once that is gone, as before. */
    public function testTheFirstReceiptWaitsForTheRestToGoFirst(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('First Back Ltd');
        $sku = self::makeSku('First back');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 6, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '2']]);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $this->refusedCode('rest_received', fn () => $this->docs->reverse($desk, $g->id, 'entered_in_error', null), 'a draft of the rest');
        $this->grns->cancel($desk, $r->id, $this->docs->get($r->id)->version, 'Keyed too early');
        $this->docs->reverse($desk, $g->id, 'entered_in_error', null);
        self::assertSame([6, 0], [$this->incoming($sku), $this->onHand($sku)], 'on its way again, as before');
        self::assertSame([['state' => 'open', 'units' => 6, 'sellable' => 1, 'grn' => null]], $this->arrivalRows($p->id));
        self::assertSame([], $this->owed($p->id));
        $this->refusedCode('nothing_owed', fn () => $this->arrivals->receiveRest($desk, $p->id), 'nothing received: Receive, not the rest');
    }

    /**
     * Accepting the difference while units are waited for: allowed, it stops waiting for exactly those (for good, whatever the match does
     * later); units waited for again afterwards (a part of the rest taken back) can still be received.
     */
    public function testAcceptingTheDifferenceStopsWaitingForThoseUnits(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Accept Owed Ltd');
        $sku = self::makeSku('Accepted short');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $this->refusedCode('rest_being_received', fn () => $this->invoices->accept($desk, $p->id, 'Supplier will not send them', InvoiceMatch::fingerprint(
            $this->invoices->matchOf($p->id))));
        $this->bench($this->staffUser('goods_in'), $r->id, [1 => ['short_units' => '2']]);
        $r = $this->post($desk, $r->id);
        $this->invoices->accept($desk, $p->id, 'Supplier will not send the last two', InvoiceMatch::fingerprint($this->invoices->matchOf($p->id)));
        self::assertSame('accepted', $this->stored($p->id)['match_state']);
        self::assertSame([], $this->owed($p->id), 'we stopped waiting');
        self::assertSame([[1, 2]], array_map(static fn (array $r): array => array_map('intval', array_values($r)),
            self::$db->all("SELECT line_no, units FROM invoice_arrival_rest WHERE kind = 'waive' AND sinv_document_id = ?", [$p->id])));
        $this->refusedCode('nothing_owed', fn () => $this->arrivals->receiveRest($desk, $p->id));
        // The three that came taken back: those three are waited for again (never the two given up).
        $this->docs->reverse($desk, $r->id, 'entered_in_error', null);
        self::assertSame([['line' => 1, 'units' => 3, 'amount' => '3.00']], $this->owed($p->id));
        self::assertSame('needs_review', $this->stored($p->id)['match_state'], 'another difference: the acceptance is dropped');
        $this->restReceipt($desk, $p->id);
        self::assertSame([3, []], [$this->onHand($sku), $this->owed($p->id)]);
    }

    /** A receipt of the rest takes in only what is waited for: another item, or more units, is refused at posting (and listed before). */
    public function testTheRestReceiptTakesInOnlyWhatIsOwed(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Only Owed Ltd');
        $a = self::makeSku('Owed one');
        $b = self::makeSku('Came in full');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$a, 4, 1, '1.0000'], [$b, 2, 1, '2.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '3']]);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $lines = $this->grns->lines($r->id);
        $this->grns->saveDraft($desk, $r->id, $this->docs->get($r->id)->version, [], [...$lines, ['sku_id' => $b, 'packs' => 1, 'pack_price' => '2.0000', 'po_line_no' => 0]]);
        $this->bench($this->staffUser('goods_in'), $r->id);
        self::assertContains('rest_lines_changed', array_column($this->grns->plan($r->id)['problems'], 'code'), 'said on the page before posting (RR27)');
        $this->refusedCode('rest_lines_changed', fn () => $this->post($desk, $r->id), 'one line per invoice line waited for');
        $this->grns->saveDraft($desk, $r->id, $this->docs->get($r->id)->version, [], [['sku_id' => $a, 'packs' => 4, 'pack_price' => '1.0000', 'po_line_no' => 0]]);
        $this->bench($this->staffUser('goods_in'), $r->id);
        $this->refusedCode('not_owed', fn () => $this->post($desk, $r->id), 'four on the paperwork, three owed');
        $this->grns->saveDraft($desk, $r->id, $this->docs->get($r->id)->version, [], [['sku_id' => $a, 'packs' => 3, 'pack_price' => '1.0000', 'po_line_no' => 0]]);
        $this->bench($this->staffUser('goods_in'), $r->id);
        $this->post($desk, $r->id);
        self::assertSame([4, 2, 'matched'], [$this->onHand($a), $this->onHand($b), $this->stored($p->id)['match_state']]);
    }

    /** What the supplier credited of a line is not waited for (2 of 5 credited: 3); the match bills the rest. */
    public function testACreditOfTheLineIsNotWaitedFor(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Credited Ltd');
        $sku = self::makeSku('Credited item');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 2, '1.0000');
        $this->postWithTotals($desk, $cn, '2.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        self::assertSame([['line' => 1, 'units' => 3, 'amount' => '3.00']], $this->owed($p->id));
        $this->restReceipt($desk, $p->id);
        self::assertSame([3, 'matched', []], [$this->onHand($sku), $this->stored($p->id)['match_state'], $this->owed($p->id)]);
    }

    /** The duplicate guards still hold for every other receipt: another receipt keyed with the invoice's number, or its copy, is refused. */
    public function testTheInvoiceNumberAndCopyStayGuardedForOtherReceipts(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Guarded Ltd');
        $sku = self::makeSku('Guarded item');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '1']]);
        $this->refusedCode('duplicate_invoice', fn () => $this->grns->createDraft($desk, (int) $s['id'], ['invoice_number' => (string) $p->externalRef]),
            'a delivery keyed by hand with the same number');
        $r = $this->arrivals->receiveRest($desk, $p->id);
        self::assertNotContains('invoice_copy_elsewhere', array_column($this->grns->plan($r->id)['problems'], 'code'), 'the copy shared by the receipts of one invoice');
        $other = $this->receipt($desk, (int) $s['id'], [['sku_id' => $sku, 'packs' => 1, 'pack_price' => '1.0000']]);
        $file = (int) self::$db->value("SELECT file_id FROM document_file WHERE document_id = ? AND role = 'supplier_invoice'", [$g->id]);
        $this->store->attach($desk, $other->id, $file, 'supplier_invoice');
        self::assertContains('invoice_copy_elsewhere', array_column($this->grns->plan($other->id)['problems'], 'code'), 'the copy on an unrelated receipt');
    }

    /** Who may: Goods In's own right (doc.GRN.post). */
    public function testWhoMayReceiveTheRest(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Who Rest Ltd');
        $sku = self::makeSku('Who item');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 2, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '1']]);
        $this->refusedCode('role_not_allowed', fn () => $this->arrivals->receiveRest($this->staffUser('reviewer'), $p->id));
        $r = $this->arrivals->receiveRest($this->staffUser('goods_in'), $p->id);
        self::assertSame('draft', $r->status);
    }

    /** The nightly checks: a row about another invoice's receipt (J5), or more settled than was missing (J6), is found. */
    public function testTheNightlyChecksOfTheRest(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Checks Rest Ltd');
        $sku = self::makeSku('Checked rest');
        $other = self::makeSku('Another item');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 3, 1, '1.0000'], [$other, 1, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '2']]);
        $r = $this->restReceipt($desk, $p->id, [1 => ['short_units' => '1']]);
        self::assertSame([], Invariants::check(self::$db));
        self::$db->exec("INSERT INTO invoice_arrival_rest (sinv_document_id, line_no, first_grn_document_id, first_grn_line_no, kind, units, created_actor, created_at) "
            . "VALUES (?, 1, ?, 1, 'waive', 5, 'test', NOW(6))", [$p->id, $g->id]);
        self::$db->exec("INSERT INTO invoice_arrival_rest (sinv_document_id, line_no, first_grn_document_id, first_grn_line_no, kind, document_id, document_line_no, units, "
            . "created_actor, created_at) VALUES (?, 2, ?, 2, 'rest', ?, 1, 1, 'test', NOW(6))", [$p->id, $g->id, $r->id]);
        $v = implode("\n", Invariants::check(self::$db));
        self::$db->exec("DELETE FROM invoice_arrival_rest WHERE created_actor = 'test'");
        self::assertStringContainsString("J6 invoice {$p->id} line 1: 1 came later, 0 claimed and 5 stopped waiting for, but its first receipt did not bring only 2", $v);
        self::assertStringContainsString("J5 invoice {$p->id} line 2: its rest row", $v, 'a rest row of another item');
    }
}
