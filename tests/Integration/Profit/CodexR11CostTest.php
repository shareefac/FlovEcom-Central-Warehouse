<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Documents\Document;
use CW\Profit\CostEngine;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;
use CW\Tests\Integration\Invoices\SupplierInvoicesTest;

/**
 * The Codex check of the fix round (r11, build/A/codex_r11_astra.txt, gpt-6.2-astra), the buying price, each shown failing first on 973c8ff:
 * two invoices of one delivery line cancelled after the sale undo their move once (F1 PARTLY); a delivery of before the start invoiced after
 * it leaves at its invoiced price (F2 PARTLY); a credit cancelled after its delivery was taken back adds nothing (new HIGH 1); a credit the
 * price could not absorb leaves with what it took (new HIGH 2); a first delivery at UK midnight takes that day's supplier price (LOW 2).
 */
final class CodexR11CostTest extends ArrivalsTestCase
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

    private function invoiceOf(array $who, int $grnId, array $change): Document
    {
        $d = $this->invoices->createFromReceipt($who['desk'], $grnId)['document'];
        $l = $this->invoices->lines($d->id);
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [SupplierInvoicesTest::lineOf($l[0], $change)]);
        return $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'R11-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    private function credit(array $who, Document $invoice, int $line, int $units, string $per): Document
    {
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $invoice->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, $line, $units, $per);
        return $this->postWithTotals($who['desk'], $cn, number_format($units * (float) $per, 2, '.', ''), '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
    }

    private function sell(\CW\Caller $site, int $qty): void
    {
        $this->ok($this->moves->record($site, ['type' => 'erp_sale', 'doc_ref' => 'SINV-' . bin2hex(random_bytes(3)), 'lines' => [['variant_id' => 'VA', 'qty' => $qty,
            'line_index' => 0]]], 'sale:' . bin2hex(random_bytes(4))));
    }

    /**
     * F1 PARTLY: 100 in at £2, two invoices of 50 at £3 before any sale (£3), 80 sold; the first invoice cancelled (the 20 left are its
     * own: back to £2), then the second (none of the 20 is its): £2 (it said £1: the second took over the first one's units and undid them again).
     */
    public function testTwoInvoicesOfADeliveryCancelledAfterTheSaleUndoTheirMoveOnce(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $this->grant($site, 'erp_sale');
        $who = $this->people('Cancel Twice Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 100, 1, '2.0000');
        $this->listing($site, 'VA', $o['sku']);
        $this->start();
        $g = $this->deliver($who, $o, 100, '2.00');
        $a = $this->invoiceOf($who, $g->id, ['packs' => 50, 'pack_price' => '3.0000']);
        $b = $this->invoiceOf($who, $g->id, ['packs' => 50, 'pack_price' => '3.0000']);
        $this->engine()->run();
        self::assertSame(30000, self::cost($o['sku']));
        $this->sell($site, 80);
        $this->invoices->reverse($who['desk'], $a->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(20000, self::cost($o['sku']), 'the 20 left were the first invoice\'s: back to £2');
        $this->invoices->reverse($who['desk'], $b->id, 'entered_in_error', null);
        $this->engine(120)->run();
        self::assertSame(20000, self::cost($o['sku']), 'the second invoice has none of them: £2 (never £1)');
        self::assertSame(20000, $this->engine(180)->rebuild($o['sku']), 'the item\'s history written again: the same');
        // The corrected invoice posted again claims the units the cancelled ones gave back.
        $this->invoiceOf($who, $g->id, ['packs' => 50, 'pack_price' => '3.0000']);
        $this->engine(240)->run();
        self::assertSame(30000, self::cost($o['sku']), 're-posted: the 20 left at £3');
    }

    /**
     * F2 PARTLY: ten at £4 and ten at £2 delivered before the start (£3 at the start), the second ten invoiced at £3 after it (£70), then
     * taken back: they leave at £3, the first ten stay £40 (it said £50: a delivery of before the start has no receipt event).
     */
    public function testADeliveryOfBeforeTheStartInvoicedAfterItLeavesAtItsInvoicedPrice(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Opening Invoiced Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->deliver($who, $o, 10, '4.00');
        $second = $this->deliver($who, $o, 10, '2.00');
        usleep(2000);
        $this->start(Clock::db(Clock::now()));
        usleep(2000);
        $this->invoiceOf($who, $second->id, ['pack_price' => '3.0000']);
        $this->engine()->run();
        self::assertSame(35000, self::cost($o['sku']), '£60 at the start, £10 more: £70 for 20');
        $this->docs->reverse($who['desk'], $second->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(40000, self::cost($o['sku']), 'the second ten leave at their invoiced £3: the first ten stay £40 (never £50)');
    }

    /**
     * New HIGH 1: ten at £4; ten that were on their way at £4 come in, then £1 credited on them (£70); they are taken back (£40 for the ten
     * that stay); the credit cancelled afterwards adds nothing (it added £10 to the ten that stay: £50).
     */
    public function testACreditCancelledAfterItsDeliveryWasTakenBackAddsNothing(): void
    {
        $this->site('vapeandgo', 'live');
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Credit After Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10, 1, '4.0000');
        $this->book('goods_in', $x['sku'], 10, 'MAIN', null, '4.00');
        usleep(2000);
        $this->start(Clock::db(Clock::now()));
        usleep(2000);
        $p = $this->invoices->submit($desk, $x['doc']->id, $x['doc']->version);
        $g = $this->arrivals->receive($desk, $p->id);
        $this->invoice($desk, $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        $g = $this->post($desk, $g->id);
        $this->engine()->run();
        self::assertSame(40000, self::cost($x['sku']));
        $cn = $this->credit(['desk' => $desk, 'supplier' => $s], $p, 1, 10, '1.0000');
        $this->engine(60)->run();
        self::assertSame(35000, self::cost($x['sku']), '£1 off the ten that came: £70 for 20');
        $this->docs->reverse($desk, $g->id, 'entered_in_error', null);
        $this->engine(120)->run();
        self::assertSame(40000, self::cost($x['sku']), 'they leave at the £3 they carry: £40 for the ten that stay');
        $this->invoices->reverse($desk, $cn->id, 'entered_in_error', null);
        $this->engine(180)->run();
        self::assertSame(40000, self::cost($x['sku']), 'the credit cancelled after they left: nothing added to the ten that stay (never £50)');
    }

    /**
     * New HIGH 2: ten at £4 and ten received and invoiced at £2 (£60); a credit of £8 a unit on the second ten takes the price to £0 (the
     * £20 the floor could not take is lost); the second ten taken back leave with the £60 the credit really took: the first ten stay £40
     * (it said £60: they left at £2 less the credit's full £8, -£6 each).
     */
    public function testACreditThePriceCouldNotAbsorbLeavesWithWhatItTook(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Big Credit Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->start();
        $this->deliver($who, $o, 10, '4.00');
        $second = $this->deliver($who, $o, 10, '2.00');
        $inv = $this->invoiceOf($who, $second->id, ['pack_price' => '2.0000']);
        $this->engine()->run();
        self::assertSame(30000, self::cost($o['sku']));
        $this->credit($who, $inv, 1, 10, '8.0000');
        $this->engine(60)->run();
        self::assertSame(0, self::cost($o['sku']), 'never below 0');
        $this->docs->reverse($who['desk'], $second->id, 'entered_in_error', null);
        $this->engine(120)->run();
        self::assertSame(40000, self::cost($o['sku']), 'the second ten leave with the £60 the credit took: the first ten stay £40');
    }

    /**
     * LOW 2: an item made after the start whose first delivery came at 00:00:00 UK takes the supplier price known on that day (it looked one
     * microsecond earlier, the day before): ten unpriced and ten at £2 with today's £4: £3.
     */
    public function testAFirstDeliveryAtUkMidnightTakesThatDaysSupplierPrice(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Midnight Ltd');
        $this->start(Clock::db(Clock::now()->modify('-2 days')));
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '2.0000');
        $this->items->recordPrice($who['buyer'], (int) $o['si']['id'], '4.0000', null, 'today\'s price');
        $this->book('goods_in', $o['sku'], 10); // counted in with no price
        $g = $this->deliver($who, $o, 10, '2.00');
        $midnight = new \DateTimeImmutable('today', new \DateTimeZone('Europe/London'));
        $utc = static fn (\DateTimeImmutable $t): string => Clock::db($t->setTimezone(new \DateTimeZone('UTC')));
        self::$db->exec('UPDATE stock_ledger l JOIN stock_value_seq s ON s.stock_ledger_id = l.id SET l.created_at = ?, s.created_at = ? WHERE l.sku_id = ? '
            . 'AND (l.document_id IS NULL OR l.document_id <> ?)', [$utc($midnight->modify('-1 minute')), $utc($midnight->modify('-1 minute')), $o['sku'], $g->id]);
        self::$db->exec('UPDATE stock_ledger l JOIN stock_value_seq s ON s.stock_ledger_id = l.id SET l.created_at = ?, s.created_at = ? WHERE l.document_id = ?',
            [$utc($midnight), $utc($midnight), $g->id]);
        self::$db->exec("UPDATE supplier_item SET last_po_pack_price = NULL, last_po_on = NULL WHERE sku_id = ?", [$o['sku']]);
        $this->engine()->run();
        self::assertSame(30000, self::cost($o['sku']), 'the day\'s £4 for the ten with no price, ten at £2: £3');
    }
}
