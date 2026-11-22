<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Invariants;
use CW\Invoices\InvoiceMatch;

/**
 * CW-1155: the Codex check of 3ae3345 (cw_work/receive_rest_20261122/codex_r2.txt; docs/decisions.md RR22), each shown failing first on
 * 3ae3345: a line taken off the draft of the rest never moves the next line's units to another price (H2); "Not invoiced yet" gives the
 * billed units to accepted stock, not units set aside (H3), and moves them between the parts when one is taken back (M); a receipt line
 * shared by two invoice lines leaves the second its own rest (M); a credit answering a claim is counted once, at most the claim's units (M);
 * a claim whose delivery was changed by hand reserves nothing (M); an acceptance waives the lines the person saw (M); an earlier part's
 * fully short line is resolved by the delivery that brings it (M); J5 finds a fact naming a fully short later receipt as the first (LOW).
 */
final class ReceiveRestReviewR2Test extends ArrivalsTestCase
{
    use RestFixtures;

    public function testALineTakenOffTheDraftNeverMovesTheNextOnesUnitsToAnotherPrice(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Line Off Ltd');
        $sku = self::makeSku('Line off');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 4, 1, '2.0000'], [$sku, 3, 1, '5.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '4'], 2 => ['short_units' => '3']]);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $lines = $this->grns->lines($r->id);
        // Only the £5 line came: taking the £2 line off, keying them in another order or swapping their prices is refused at the save (RR29) ...
        $v = $r->version;
        $this->refusedCode('rest_lines_fixed', fn () => $this->grns->saveDraft($desk, $r->id, $v, [], [$lines[1]]));
        $this->refusedCode('rest_lines_fixed', fn () => $this->grns->saveDraft($desk, $r->id, $v, [], [$lines[1], $lines[0]]));
        $this->refusedCode('rest_lines_fixed', fn () => $this->grns->saveDraft($desk, $r->id, $v, [], [['pack_price' => '5.0000'] + $lines[0], ['pack_price' => '2.0000'] + $lines[1]]));
        $this->refusedCode('rest_lines_fixed', fn () => $this->grns->saveDraft($desk, $r->id, $v, [], [['line_no' => 2] + $lines[0], ['line_no' => 1] + $lines[1]]),
            'a line naming the other line (its bench findings would move)');
        $this->grns->saveDraft($desk, $r->id, $v, [], $lines); // ... the same lines saved again are fine; the £2 line is counted short at the bench
        $this->bench($this->staffUser('goods_in'), $r->id, [1 => ['short_units' => '4']]);
        $r = $this->post($desk, $r->id);
        self::assertSame([[2, 2, 3]], array_map(static fn (array $x): array => array_map('intval', array_values($x)),
            self::$db->all("SELECT line_no, document_line_no, units FROM invoice_arrival_rest WHERE kind = 'rest' AND document_id = ?", [$r->id])), 'the £5 line\'s, at £5');
        self::assertSame([['line' => 1, 'units' => 4, 'amount' => '8.00']], $this->owed($p->id));
    }

    public function testNotInvoicedYetGivesTheBilledUnitsToAcceptedStock(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Accepted Stock Ltd');
        $sku = self::makeSku('Accepted stock');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 10, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '2', 'damaged_units' => '3']]);
        self::assertSame([5, 3], [$this->onHand($sku), $this->onHand($sku, 'VERIFY')]);
        $r = $this->restReceipt($desk, $p->id);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 3, '1.0000');
        $this->postWithTotals($desk, $cn, '3.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        self::assertSame([0, 0], [$this->left($g->id), $this->left($r->id)], 'seven accepted, seven billed (it said 2 left on the rest)');
    }

    /** RR23: a part is billed for what it settled: taking one back moves nothing to another (and V7 holds). */
    public function testAPartTakenBackMovesNothingToTheOtherParts(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Parts Move Ltd');
        $sku = self::makeSku('Parts move');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 10, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '4']]);
        $r1 = $this->restReceipt($desk, $p->id, [1 => ['short_units' => '2']]);
        $r2 = $this->restReceipt($desk, $p->id);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 2, '1.0000');
        $this->postWithTotals($desk, $cn, '2.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        self::assertSame([0, 0], [$this->left($r1->id), $this->left($r2->id)], 'each part billed for what it settled');
        $this->docs->reverse($desk, $r1->id, 'entered_in_error', null);
        self::assertSame(0, $this->left($r2->id), 'nothing moved');
        self::assertSame([], Invariants::check(self::$db), 'V7: stored as worked out');
    }

    public function testAReceiptLineSharedByTwoInvoiceLinesLeavesTheSecondItsOwnRest(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Shared Line Ltd');
        $sku = self::makeSku('Shared line');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000'], [$sku, 5, 1, '1.0000']]);
        $g = $this->arrivals->receive($desk, $p->id);
        $l = $this->grns->lines($g->id)[0];
        $this->grns->saveDraft($desk, $g->id, $g->version, [], [['sku_id' => $sku, 'packs' => 10, 'pack_price' => '1.0000', 'po_line_no' => 0]]);
        $this->bench($this->staffUser('goods_in'), $g->id, [1 => ['short_units' => '8']]);
        $this->post($desk, $g->id);
        unset($l);
        self::assertSame([['line' => 1, 'units' => 3, 'amount' => '3.00'], ['line' => 2, 'units' => 5, 'amount' => '5.00']], $this->owed($p->id));
        $this->restReceipt($desk, $p->id);
        self::assertSame(['matched', []], [$this->stored($p->id)['match_state'], $this->owed($p->id)], 'ten came (it said the second line had two)');
    }

    public function testACreditAnsweringAClaimCountsAtMostTheClaimsUnits(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Part Overlap Ltd');
        $sku = self::makeSku('Part overlap');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $r = $this->restReceipt($desk, $p->id, [1 => ['short_units' => '2']]);
        $dn = $this->arrivals->claimMissing($desk, $g->id);
        $dn = $this->returns->post($desk, $dn->id, $this->docs->get($dn->id)->version);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id, 'debit_note_id' => $dn->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 3, '1.0000');
        $this->postWithTotals($desk, $cn, '3.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $this->docs->reverse($desk, $r->id, 'entered_in_error', null);
        self::assertSame([['line' => 1, 'units' => 2, 'amount' => '2.00']], $this->owed($p->id), 'three credited (two of them the claim\'s): two owed (it said three)');
    }

    /** The Codex checks of 3ae3345 and 6c78108 (RR23): a claim keeps its delivery and invoice (cancel it and claim again). */
    public function testAClaimKeepsItsDeliveryAndInvoice(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Claim Moved Ltd');
        $sku = self::makeSku('Claim moved');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $dn = $this->arrivals->claimMissing($desk, $g->id);
        self::assertSame([], $this->owed($p->id));
        $this->refusedCode('claim_fixed', fn () => $this->returns->saveHeader($desk, $dn->id, $this->docs->get($dn->id)->version, ['grn_id' => null]));
        $this->refusedCode('claim_fixed', fn () => $this->returns->saveHeader($desk, $dn->id, $this->docs->get($dn->id)->version, ['invoice_id' => null]));
        $this->returns->saveHeader($desk, $dn->id, $this->docs->get($dn->id)->version, ['note' => 'Asked by phone']);
        self::assertSame([], $this->owed($p->id), 'still claimed');
    }

    /** The Codex check of 6c78108 (M): an earlier part's line that brought some of its own line is about that line only. */
    public function testAPartsIncidentIsAboutItsOwnLineOnly(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Own Line Incident Ltd');
        $sku = self::makeSku('Own line incident');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 4, 1, '2.0000'], [$sku, 3, 1, '5.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '4'], 2 => ['short_units' => '3']]);
        $r1 = $this->restReceipt($desk, $p->id, [1 => ['short_units' => '2'], 2 => ['short_units' => '3']]);
        $r2 = $this->restReceipt($desk, $p->id, [2 => ['short_units' => '3']]);
        self::assertSame([['line' => 2, 'units' => 3, 'amount' => '15.00']], $this->owed($p->id));
        self::assertSame(['resolved', "The rest came on {$r2->number}."], $this->shortIncident($r1->id, 1), 'the £2 line came: its incident is done');
        self::assertSame(['open', null], $this->shortIncident($r1->id, 2), 'the £5 line still waited for');
    }

    /** The Codex check of 6c78108 (LOW): J5 finds a fact naming a receipt of the rest taken back as the first receipt. */
    public function testJ5FindsAFactNamingAReversedPartAsTheFirst(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('J5 Reversed Part Ltd');
        $sku = self::makeSku('J5 reversed part');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $r = $this->restReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $this->docs->reverse($desk, $r->id, 'entered_in_error', null);
        self::$db->exec("INSERT INTO invoice_arrival_rest (sinv_document_id, line_no, first_grn_document_id, first_grn_line_no, kind, units, created_actor, created_at) "
            . "VALUES (?, 1, ?, 1, 'waive', 1, 'test', NOW(6))", [$p->id, $r->id]);
        $v = implode("\n", Invariants::check(self::$db));
        self::$db->exec("DELETE FROM invoice_arrival_rest WHERE created_actor = 'test'");
        self::assertStringContainsString("J5 invoice {$p->id} line 1: its waive row", $v);
    }

    public function testAnAcceptanceWaivesTheLinesThePersonSaw(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Lines Seen Ltd');
        $a = self::makeSku('Seen A');
        $b = self::makeSku('Seen B');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$a, 2, 1, '1.0000'], [$b, 2, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '2']]);
        $fingerprint = InvoiceMatch::fingerprint($this->invoices->matchOf($p->id));
        $this->refusedCode('match_changed', fn () => $this->invoices->accept($desk, $p->id, 'Not coming', $fingerprint, '2:2'), 'the same total, another line');
        $this->invoices->accept($desk, $p->id, 'Not coming', $fingerprint, '1:2');
        self::assertSame([], $this->owed($p->id));
    }

    public function testAnEarlierPartsFullyShortLineIsResolvedByTheDeliveryThatBringsIt(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Short Part Ltd');
        $a = self::makeSku('Part A');
        $b = self::makeSku('Part B');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$a, 2, 1, '1.0000'], [$b, 2, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '2'], 2 => ['short_units' => '2']]);
        $r1 = $this->restReceipt($desk, $p->id, [2 => ['short_units' => '2']]);
        self::assertSame(['open', null], $this->shortIncident($r1->id, 2));
        $r2 = $this->restReceipt($desk, $p->id);
        self::assertSame(['resolved', "The rest came on {$r2->number}."], $this->shortIncident($r1->id, 2), 'it said open for ever');
        self::assertSame('resolved', $this->shortIncident($g->id, 2)[0]);
    }

    public function testJ5FindsAFactNamingAFullyShortLaterReceiptAsTheFirst(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('J5 Short Ltd');
        $sku = self::makeSku('J5 short');
        $other = self::makeSku('J5 other');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000'], [$other, 1, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $r = $this->restReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        self::$db->exec("INSERT INTO invoice_arrival_rest (sinv_document_id, line_no, first_grn_document_id, first_grn_line_no, kind, units, created_actor, created_at) "
            . "VALUES (?, 1, ?, 1, 'waive', 1, 'test', NOW(6))", [$p->id, $r->id]);
        $v = implode("\n", Invariants::check(self::$db));
        self::$db->exec("DELETE FROM invoice_arrival_rest WHERE created_actor = 'test'");
        self::assertStringContainsString("J5 invoice {$p->id} line 1: its waive row", $v);
    }
}
