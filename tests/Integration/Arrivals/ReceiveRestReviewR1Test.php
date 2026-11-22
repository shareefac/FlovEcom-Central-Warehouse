<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

use CW\Invariants;
use CW\Invoices\InvoiceMatch;

/**
 * CW-1155: the Codex check of a0d33a2 (cw_work/receive_rest_20261122/codex_r1.txt; docs/decisions.md RR21), each shown failing first on
 * 83b03f0: a receipt of the rest keeps its invoice's number and no other part's delivery note (H2); one item on two owed lines, the first
 * counted short on the rest, gives the units to the second (H3); "Not invoiced yet" spends an invoice line's billed units once over the first
 * receipt and the parts (M1); a credit note answering a claim is not counted twice (M2); an acceptance waives only the units the person saw
 * (M4); the rest of one line never resolves another line's incident (M5); J5 finds a fact naming a receipt of the rest as the first (LOW).
 */
final class ReceiveRestReviewR1Test extends ArrivalsTestCase
{
    use RestFixtures;

    public function testTheRestKeepsItsInvoiceNumberAndNoOtherPartsDeliveryNote(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Keeps Number Ltd');
        $sku = self::makeSku('Number kept');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $g = $this->arrivals->receive($desk, $p->id);
        $g = $this->grns->setInvoice($desk, $g->id, $g->version, ['delivery_note' => 'DN-77']);
        $this->bench($this->staffUser('goods_in'), $g->id, [1 => ['short_units' => '5']]);
        $this->post($desk, $g->id);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $r = $this->grns->setInvoice($desk, $r->id, $r->version, ['invoice_number' => 'OTHER-' . bin2hex(random_bytes(3))]);
        $this->bench($this->staffUser('goods_in'), $r->id);
        self::assertContains('rest_invoice_changed', array_column($this->grns->plan($r->id)['problems'], 'code'));
        $this->refusedCode('rest_invoice_changed', fn () => $this->post($desk, $r->id), 'another invoice\'s delivery never settles this one');
        $r = $this->grns->setInvoice($desk, $r->id, $this->docs->get($r->id)->version, ['invoice_number' => (string) $p->externalRef, 'delivery_note' => ' dn-77']);
        $this->bench($this->staffUser('goods_in'), $r->id);
        $this->refusedCode('rest_same_delivery', fn () => $this->post($desk, $r->id), 'the first part\'s delivery note: the same delivery');
        $this->grns->setInvoice($desk, $r->id, $this->docs->get($r->id)->version, ['delivery_note' => 'DN-78']);
        $this->bench($this->staffUser('goods_in'), $r->id);
        $this->post($desk, $r->id);
        self::assertSame([5, 'matched'], [$this->onHand($sku), $this->stored($p->id)['match_state']]);
    }

    public function testOneItemOnTwoOwedLinesTheFirstShortOnTheRestGivesTheUnitsToTheSecond(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Two Owed Lines Ltd');
        $sku = self::makeSku('Two owed lines');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 4, 1, '2.0000'], [$sku, 3, 1, '5.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '4'], 2 => ['short_units' => '3']]);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        self::assertSame([[4, '2.0000'], [3, '5.0000']], array_map(static fn (array $l): array => [(int) $l['packs'], (string) $l['pack_price']], $this->grns->lines($r->id)));
        $this->bench($this->staffUser('goods_in'), $r->id, [1 => ['short_units' => '4']]);
        $r = $this->post($desk, $r->id);
        self::assertSame([[2, 2, 3]], array_map(static fn (array $x): array => array_map('intval', array_values($x)),
            self::$db->all("SELECT line_no, document_line_no, units FROM invoice_arrival_rest WHERE kind = 'rest' AND document_id = ?", [$r->id])), 'the £5 line\'s three');
        self::assertSame([['line' => 1, 'units' => 4, 'amount' => '8.00']], $this->owed($p->id));
    }

    public function testNotInvoicedYetSpendsTheBilledUnitsOnceOverTheParts(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Billed Once Ltd');
        $sku = self::makeSku('Billed once');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 10, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '4']]);
        $r = $this->restReceipt($desk, $p->id);
        self::assertSame([0, 0], [$this->left($g->id), $this->left($r->id)]);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 5, '1.0000');
        $this->postWithTotals($desk, $cn, '5.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        self::assertSame(5, (int) $this->left($g->id) + (int) $this->left($r->id), 'ten came, five are billed: five left to invoice (it said 1)');
    }

    public function testACreditNoteAnsweringAClaimIsCountedOnce(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Claim Credit Once Ltd');
        $sku = self::makeSku('Claimed and credited');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $r = $this->restReceipt($desk, $p->id, [1 => ['short_units' => '2']]);
        $dn = $this->arrivals->claimMissing($desk, $g->id);
        $dn = $this->returns->post($desk, $dn->id, $this->docs->get($dn->id)->version);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id, 'debit_note_id' => $dn->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 2, '1.0000');
        $this->postWithTotals($desk, $cn, '2.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        self::assertSame([], $this->owed($p->id));
        $this->docs->reverse($desk, $r->id, 'entered_in_error', null);
        self::assertSame([['line' => 1, 'units' => 3, 'amount' => '3.00']], $this->owed($p->id), 'the three taken back are owed; the two claimed and credited once');
    }

    public function testAnAcceptanceWaivesOnlyTheUnitsThePersonSaw(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Waive Seen Ltd');
        $sku = self::makeSku('Waived as seen');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $dn = $this->arrivals->claimMissing($desk, $g->id);
        $fingerprint = InvoiceMatch::fingerprint($this->invoices->matchOf($p->id));
        self::assertSame([], $this->owed($p->id), 'the form shows nothing waited for');
        $this->returns->cancel($desk, $dn->id, $this->docs->get($dn->id)->version, 'Claimed by mistake');
        $this->refusedCode('match_changed', fn () => $this->invoices->accept($desk, $p->id, 'Not coming', $fingerprint, 0), 'five released meanwhile');
        $this->invoices->accept($desk, $p->id, 'Not coming', $fingerprint, 5);
        self::assertSame([], $this->owed($p->id));
    }

    public function testTheRestOfOneLineNeverResolvesAnotherLinesIncident(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('One Line Only Ltd');
        $a = self::makeSku('Comes later');
        $b = self::makeSku('Credited instead');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$a, 2, 1, '1.0000'], [$b, 3, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '2'], 2 => ['short_units' => '3']]);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 2, 3, '1.0000');
        $this->postWithTotals($desk, $cn, '3.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $r = $this->restReceipt($desk, $p->id);
        self::assertSame(['resolved', "The rest came on {$r->number}."], $this->shortIncident($g->id, 1));
        self::assertSame(['open', null], $this->shortIncident($g->id, 2), 'B was credited, never "came"');
    }

    public function testJ5FindsAFactNamingAReceiptOfTheRestAsTheFirst(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('J5 First Ltd');
        $sku = self::makeSku('J5 first');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '5']]);
        $r = $this->restReceipt($desk, $p->id, [1 => ['short_units' => '2']]);
        self::$db->exec("INSERT INTO invoice_arrival_rest (sinv_document_id, line_no, first_grn_document_id, first_grn_line_no, kind, units, created_actor, created_at) "
            . "VALUES (?, 1, ?, 1, 'waive', 1, 'test', NOW(6))", [$p->id, $r->id]);
        $v = implode("\n", Invariants::check(self::$db));
        self::$db->exec("DELETE FROM invoice_arrival_rest WHERE created_actor = 'test'");
        self::assertStringContainsString("J5 invoice {$p->id} line 1: its waive row", $v);
    }
}
