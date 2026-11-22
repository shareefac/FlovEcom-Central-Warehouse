<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Arrivals;

/**
 * CW-1155: the Codex check of 55a4a42 (cw_work/receive_rest_20261122/codex_r4b.txt; docs/decisions.md RR24), each shown failing first on
 * 55a4a42: a part's two lines of one invoice line are billed once for what the line still bills (M1); a part's own shortfall is never said
 * to have come on it (M2); To match's candidates leave out a line settled by a credit note (M4).
 */
final class ReceiveRestReviewR4Test extends ArrivalsTestCase
{
    use RestFixtures;

    public function testAPartsTwoLinesOfOneInvoiceLineAreBilledOnce(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Billed Once Per Part Ltd');
        $sku = self::makeSku('Two lines one part');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 10, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '10']]);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $this->grns->saveDraft($desk, $r->id, $r->version, [], [['sku_id' => $sku, 'packs' => 5, 'pack_price' => '1.0000', 'po_line_no' => 0],
            ['sku_id' => $sku, 'packs' => 5, 'pack_price' => '1.0000', 'po_line_no' => 0]]);
        $this->bench($this->staffUser('goods_in'), $r->id);
        $r = $this->post($desk, $r->id);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 7, '1.0000');
        $this->postWithTotals($desk, $cn, '7.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        self::assertSame(7, $this->left($r->id), 'ten came, three billed: seven left (it said four: the three counted on each line)');
    }

    public function testAPartsOwnShortfallIsNeverSaidToHaveComeOnIt(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Own Shortfall Ltd');
        $sku = self::makeSku('Own shortfall');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 2, 1, '1.0000'], [$sku, 4, 1, '1.0000']]);
        $g = $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '2'], 2 => ['short_units' => '4']]);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $this->grns->saveDraft($desk, $r->id, $r->version, [], [['sku_id' => $sku, 'packs' => 6, 'pack_price' => '1.0000', 'po_line_no' => 0]]);
        $this->bench($this->staffUser('goods_in'), $r->id, [1 => ['short_units' => '4']]);
        $r = $this->post($desk, $r->id);
        self::assertSame([['line' => 2, 'units' => 4, 'amount' => '4.00']], $this->owed($p->id));
        self::assertSame(['open', null], $this->shortIncident($r->id, 1), 'its four short did not come on it');
        self::assertSame(['resolved', "The rest came on {$r->number}."], $this->shortIncident($g->id, 1));
        self::assertSame(['open', null], $this->shortIncident($g->id, 2));
    }

    public function testToMatchsCandidatesLeaveOutALineSettledByACredit(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Candidates Ltd');
        $a = self::makeSku('Credited away');
        $b = self::makeSku('Still waited for');
        $pa = $this->finalInvoice($desk, (int) $s['id'], [[$a, 2, 1, '1.0000']]);
        $pb = $this->finalInvoice($desk, (int) $s['id'], [[$b, 2, 1, '1.0000']]);
        $this->firstReceipt($desk, $pa->id, [1 => ['short_units' => '2']]);
        $this->firstReceipt($desk, $pb->id, [1 => ['short_units' => '2']]);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $pa->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 2, '1.0000');
        $this->postWithTotals($desk, $cn, '2.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $c = $this->invoices->mayWaitForTheRest();
        self::assertArrayNotHasKey($pa->id, $c, 'settled by its credit: no slot taken');
        self::assertArrayHasKey($pb->id, $c);
    }

    /** The Codex check of 66e16fa (HIGH): a damaged line of a part, set aside, takes none of the billing of the good line beside it. */
    public function testADamagedLineSetAsideTakesNoBillingOfTheGoodLineBesideIt(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Damaged Beside Ltd');
        $sku = self::makeSku('Damaged beside');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 10, 1, '1.0000']]);
        $this->firstReceipt($desk, $p->id, [1 => ['short_units' => '10']]);
        $r = $this->arrivals->receiveRest($desk, $p->id);
        $this->grns->saveDraft($desk, $r->id, $r->version, [], [['sku_id' => $sku, 'packs' => 5, 'pack_price' => '1.0000', 'po_line_no' => 0],
            ['sku_id' => $sku, 'packs' => 5, 'pack_price' => '1.0000', 'po_line_no' => 0]]);
        $this->bench($this->staffUser('goods_in'), $r->id, [1 => ['damaged_units' => '5']]);
        $r = $this->post($desk, $r->id);
        self::assertSame([5, 5], [$this->onHand($sku), $this->onHand($sku, 'VERIFY')]);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 5, '1.0000');
        $this->postWithTotals($desk, $cn, '5.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        self::assertSame(0, $this->left($r->id), 'five good on the shelf, five billed: nothing left (it said five)');
    }

    /** The Codex check of 66e16fa (M): a credit of one of two invoice lines sharing a receipt line never hides the other's shortfall. */
    public function testACreditOfOneOfTwoLinesSharingAReceiptLineKeepsTheOtherACandidate(): void
    {
        ['desk' => $desk, 'supplier' => $s] = $this->people('Shared Credit Ltd');
        $sku = self::makeSku('Shared credit');
        $p = $this->finalInvoice($desk, (int) $s['id'], [[$sku, 5, 1, '1.0000'], [$sku, 5, 1, '1.0000']]);
        $g = $this->arrivals->receive($desk, $p->id);
        $this->grns->saveDraft($desk, $g->id, $g->version, [], [['sku_id' => $sku, 'packs' => 10, 'pack_price' => '1.0000', 'po_line_no' => 0]]);
        $this->bench($this->staffUser('goods_in'), $g->id, [1 => ['short_units' => '5']]);
        $this->post($desk, $g->id);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 5, '1.0000');
        $this->postWithTotals($desk, $cn, '5.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        self::assertSame([['line' => 2, 'units' => 5, 'amount' => '5.00']], $this->owed($p->id));
        self::assertArrayHasKey($p->id, $this->invoices->mayWaitForTheRest(), 'still waiting: a candidate');
    }
}
