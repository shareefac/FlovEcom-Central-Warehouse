<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Documents\Document;
use CW\Profit\CostEngine;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;
use CW\Tests\Integration\Invoices\SupplierInvoicesTest;

/**
 * The second final review of 3c31d94 (build/A/codex_final2_astra_{a,b,c}.txt; final2/FIX_BRIEF.md), the buying price, each shown failing
 * first with the reviewers' numbers: split invoices of one delivery line reprice its units once (F1, a-HIGH2 = c-HIGH1); a delivery taken
 * back leaves at the price its invoice and credits gave it, exactly once (F2, a-HIGH3 = c-HIGH2, b-M8); an item made after the start never
 * starts at a supplier price of a later day (c-M5).
 */
final class FinalReview2CostTest extends ArrivalsTestCase
{
    private function engine(int $ahead = 0): CostEngine
    {
        return new CostEngine(self::$db, static fn (): \DateTimeImmutable => Clock::now()->modify('+' . (CostEngine::LAG_SECONDS + 60 + $ahead) . ' seconds'));
    }

    private static function cost(int $sku): ?int
    {
        $v = self::$db->value('SELECT unit_cost_e4 FROM item_cost WHERE sku_id = ?', [$sku]);
        return $v === null ? null : (int) $v;
    }

    private const EMPTY_BIN = "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n";

    private function start(?string $at = null): void
    {
        $at ??= Clock::db(Clock::now()->modify('-1 hour'));
        $plan = $this->engine()->plan(self::EMPTY_BIN, 'vapeandgo', $at);
        $this->engine()->seed($plan['prices'], $at);
    }

    private function deliver(array $who, array $o, int $packs, string $price): Document
    {
        return $this->delivered($who['desk'], (int) $who['supplier']['id'], [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => $packs,
            'units_per_pack' => 1, 'pack_price' => $price, 'po_line_no' => 1]], $o['doc']->id);
    }

    /** An invoice of the receipt's line 1 with $change (packs, pack_price). */
    private function invoiceOf(array $who, int $grnId, array $change): Document
    {
        $d = $this->invoices->createFromReceipt($who['desk'], $grnId)['document'];
        $l = $this->invoices->lines($d->id);
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [SupplierInvoicesTest::lineOf($l[0], $change)]);
        return $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'F2-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    private function credit(array $who, Document $invoice, int $line, int $units, string $per): Document
    {
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $invoice->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, $line, $units, $per);
        return $this->postWithTotals($who['desk'], $cn, number_format($units * (float) $per, 2, '.', ''), '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
    }

    /** Store $site sells $qty units of variant VA (relayed), as the shops' sales come in. */
    private function sell(\CW\Caller $site, int $qty): void
    {
        $this->ok($this->moves->record($site, ['type' => 'erp_sale', 'doc_ref' => 'SINV-' . bin2hex(random_bytes(3)), 'lines' => [['variant_id' => 'VA', 'qty' => $qty,
            'line_index' => 0]]], 'sale:' . bin2hex(random_bytes(4))));
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: Document} [who, order, the delivery of 100 at £2 with 80 of them sold] */
    private function hundredInEightySold(string $supplier): array
    {
        $site = $this->site('vapeandgo', 'live');
        $this->grant($site, 'erp_sale');
        $who = $this->people($supplier);
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 100, 1, '2.0000');
        $this->listing($site, 'VA', $o['sku']);
        $this->start();
        $g = $this->deliver($who, $o, 100, '2.00');
        $this->engine()->run();
        self::assertSame(20000, self::cost($o['sku']));
        $this->sell($site, 80);
        return [$who, $o, $g];
    }

    /** F1: 100 in at £2, 80 sold, two invoices of 50 at £3 each: the 20 left are £3 (it said £4: each invoice moved the same 20 from £2). */
    public function testSplitInvoicesRepriceTheUnitsLeftOnce(): void
    {
        [$who, $o, $g] = $this->hundredInEightySold('Split Once Ltd');
        $this->invoiceOf($who, $g->id, ['packs' => 50, 'pack_price' => '3.0000']);
        $this->engine(60)->run();
        self::assertSame(30000, self::cost($o['sku']), 'the first 50 at £3: the 20 left move from £2 to £3');
        $this->invoiceOf($who, $g->id, ['packs' => 50, 'pack_price' => '3.0000']);
        $this->engine(120)->run();
        self::assertSame(30000, self::cost($o['sku']), 'the other 50 at £3: the 20 left took their invoice price once (never £4)');
        self::assertSame([20, 0], array_map('intval', self::$db->column("SELECT units FROM item_cost_event WHERE sku_id = ? AND kind = 'invoice' ORDER BY source_at, id",
            [$o['sku']])), 'the units left are claimed by the invoice lines in the order they were posted');
        self::assertSame(30000, $this->engine(180)->rebuild($o['sku']), 'the item\'s history written again: the same');
    }

    /**
     * F1, two prices: the units left go to the invoice lines in the order they were posted, each at most its own units (the rule chosen,
     * docs/decisions.md PR130): 50 at £3, then 50 at £3.50: the 20 left are the first invoice's (£3). A credit of the second invoice reaches
     * none of them (they took the first one's price, not its).
     */
    public function testSplitInvoicesAtTwoPricesGiveTheUnitsLeftToTheFirstPosted(): void
    {
        [$who, $o, $g] = $this->hundredInEightySold('Split Two Prices Ltd');
        $this->invoiceOf($who, $g->id, ['packs' => 50, 'pack_price' => '3.0000']);
        $this->engine(60)->run();
        $second = $this->invoiceOf($who, $g->id, ['packs' => 50, 'pack_price' => '3.5000']);
        $this->engine(120)->run();
        self::assertSame(30000, self::cost($o['sku']), 'the 20 left are the first invoice\'s, at £3 (never £4.50)');
        $this->credit($who, $second, 1, 50, '0.5000');
        $this->engine(180)->run();
        self::assertSame(30000, self::cost($o['sku']), '50p off the second invoice\'s units: none of them is left (never £2.50)');
        self::assertSame(30000, $this->engine(240)->rebuild($o['sku']), 'the item\'s history written again: the same');
    }

    /**
     * F2: ten at £4, ten more at £2 invoiced at £3 (£70 for 20); the second ten taken back leave at £3, the price their invoice gave them: the
     * first ten stay £40 (it said £50). The invoice cancelled afterwards takes nothing off again (exactly once).
     */
    public function testADeliveryTakenBackLeavesAtThePriceItsInvoiceGaveIt(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Taken Back Invoiced Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->start();
        $this->deliver($who, $o, 10, '4.00');
        $second = $this->deliver($who, $o, 10, '2.00');
        $inv = $this->invoiceOf($who, $second->id, ['pack_price' => '3.0000']);
        $this->engine()->run();
        self::assertSame(35000, self::cost($o['sku']), 'ten at £4 and ten invoiced at £3: £70 for 20');
        $this->docs->reverse($who['desk'], $second->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(40000, self::cost($o['sku']), 'the second ten leave at their invoiced £3: the first ten stay £40 (never £50)');
        $this->invoices->reverse($who['desk'], $inv->id, 'entered_in_error', null);
        $this->engine(120)->run();
        self::assertSame(40000, self::cost($o['sku']), 'the invoice cancelled after its delivery was taken back takes nothing off again');
        self::assertSame(40000, $this->engine(180)->rebuild($o['sku']), 'the item\'s history written again: the same');
    }

    /**
     * F2 / b-M8: ten at £4; ten more that were on their way at an invoice's £4 with £1 credited meanwhile come in at £3; the credit cancelled
     * (they are worth £4 again: £80 for 20); then the ten are taken back: they leave at £4, their price without the cancelled credit, and the
     * first ten stay £40 (it said £50: they left at the £3 they came in at).
     */
    public function testACancelledCreditOfADeliveryTakenBackLeavesAtItsPriceWithoutIt(): void
    {
        $this->site('vapeandgo', 'live');
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cancelled Credit Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10, 1, '4.0000');
        $this->book('goods_in', $x['sku'], 10, 'MAIN', null, '4.00'); // ten at £4 on hand before the start
        usleep(2000);
        $this->start(Clock::db(Clock::now()));
        usleep(2000);
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 10, '1.0000');
        $cn = $this->postWithTotals($desk, $cn, '10.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $this->engine()->run();
        $g = $this->arrivals->receive($desk, $p->id);
        $this->invoice($desk, $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        $g = $this->post($desk, $g->id);
        $this->engine(60)->run();
        self::assertSame(35000, self::cost($x['sku']), 'ten at £4 and ten at £3 (the invoice\'s £4 less the £1 credited while they were on their way)');
        $this->invoices->reverse($desk, $cn->id, 'entered_in_error', null);
        $this->engine(120)->run();
        self::assertSame(40000, self::cost($x['sku']), 'the credit cancelled: the ten that came are worth £4 again');
        $this->docs->reverse($desk, $g->id, 'entered_in_error', null);
        $this->engine(180)->run();
        self::assertSame(40000, self::cost($x['sku']), 'the ten taken back leave at £4, their price without the cancelled credit: the first ten stay £40 (never £50)');
    }

    /**
     * c-M5 (a known limit made a fix: a historical price is cheap to read): an item made after the start, ten of it on hand with no price, ten
     * delivered at £2; the supplier's price rises to £4 two days later, before the job first sees the item. Its start is the supplier price
     * known on the day of its first delivery (none here), never the later £4: £2 (it said £3).
     */
    public function testAnItemMadeAfterTheStartNeverStartsAtALaterSupplierPrice(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Later Price Ltd');
        $this->start(Clock::db(Clock::now()->modify('-4 days')));
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '2.0000');
        $this->book('goods_in', $o['sku'], 10); // counted in with no price
        $g = $this->deliver($who, $o, 10, '2.00');
        // Both three days ago (the delivery came then); the supplier's price rises to £4 today, before the job first sees the item.
        $then = Clock::now()->modify('-3 days');
        self::$db->exec('UPDATE stock_ledger l JOIN stock_value_seq s ON s.stock_ledger_id = l.id SET l.created_at = ?, s.created_at = ? WHERE l.sku_id = ? '
            . 'AND (l.document_id IS NULL OR l.document_id <> ?)', [Clock::db($then->modify('-1 minute')), Clock::db($then->modify('-1 minute')), $o['sku'], $g->id]);
        self::$db->exec('UPDATE stock_ledger l JOIN stock_value_seq s ON s.stock_ledger_id = l.id SET l.created_at = ?, s.created_at = ? WHERE l.document_id = ?',
            [Clock::db($then), Clock::db($then), $g->id]);
        $this->items->recordPrice($who['buyer'], (int) $o['si']['id'], '4.0000', null, 'the supplier\'s new price');
        $this->engine()->run();
        self::assertSame(20000, self::cost($o['sku']), 'ten with no price and ten at £2: £2, as the job would have said on the day (never £3 from today\'s £4)');
    }
}
