<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Documents\Document;
use CW\Profit\CostEngine;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;
use CW\Tests\Integration\Invoices\SupplierInvoicesTest;

/**
 * The Codex re-check of 6b8a7ee (build/A/codex_r4.txt), the buying price, each shown failing first: an item made after the start keeps the
 * invoice correction of a delivery booked before its first run (F1); two invoice lines of one item received on one receipt line are priced
 * by what came of each, as the arrival matching gives it (F2), and a credit of one of them is taken in, cancelled and applied by its own
 * line (F3); the same Idempotency-Key of two stores is two operations, so a sell-out between them is seen (F4); a cancellation read before
 * its invoice reads the invoice first, so neither is lost (F5).
 */
final class CostEngineReviewR4Test extends ArrivalsTestCase
{
    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    private function engine(int $ahead = 0, ?\Closure $clock = null): CostEngine
    {
        return new CostEngine(self::$db, $clock ?? static fn (): \DateTimeImmutable => Clock::now()->modify('+' . (CostEngine::LAG_SECONDS + 60 + $ahead) . ' seconds'));
    }

    private static function cost(int $sku): ?int
    {
        $v = self::$db->value('SELECT unit_cost_e4 FROM item_cost WHERE sku_id = ?', [$sku]);
        return $v === null ? null : (int) $v;
    }

    private function start(string $ago = '-1 hour'): void
    {
        $plan = $this->engine()->plan("item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n", 'vapeandgo');
        $this->engine()->seed($plan['prices'], Clock::db(Clock::now()->modify($ago)));
    }

    /** A delivery of $packs units of $o's item at $price, against line 1 of its order. */
    private function deliver(array $who, array $o, int $packs, string $price): Document
    {
        return $this->delivered($who['desk'], (int) $who['supplier']['id'], [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => $packs,
            'units_per_pack' => 1, 'pack_price' => $price, 'po_line_no' => 1]], $o['doc']->id);
    }

    /** The invoice of a delivery at another pack price, made final. */
    private function invoiceAt(array $who, Document $grn, string $price): Document
    {
        $d = $this->invoices->createFromReceipt($who['desk'], $grn->id)['document'];
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [SupplierInvoicesTest::lineOf($this->invoices->lines($d->id)[0], ['pack_price' => $price])]);
        return $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'R4-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    /** An invoice of two lines of one item, ten units each (no delivery named), made final: both on their way. */
    private function twoLines(array $who, int $sku, string $first, string $second): Document
    {
        $line = static fn (string $price): array => ['kind' => 'goods', 'sku_id' => $sku, 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => $price, 'supplier_code' => null,
            'purchase_unit' => 'each', 'entry' => 'manual', 'description' => null];
        $d = $this->invoices->createDraft($who['desk'], 'invoice', (int) $who['supplier']['id'], ['invoice_number' => 'TWO-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [$line($first), $line($second)]);
        $net = (string) self::$db->value('SELECT CAST(COALESCE(SUM(amount), 0) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$d->id]);
        $d = $this->invoices->saveHeader($who['desk'], $d->id, $d->version, ['net_total' => $net, 'vat_total' => '0.00']);
        $p = $this->invoices->submit($who['desk'], $d->id, $d->version);
        self::assertSame([10, 10], array_column($this->arrivalRows($p->id), 'units'), 'both lines on their way');
        return $p;
    }

    /** The invoice's goods received with its two lines keyed as ONE receipt line of $units units (at a pack price of its own), and posted. */
    private function receiveAsOneLine(array $who, Document $invoice, int $sku, int $units): Document
    {
        $g = $this->arrivals->receive($who['desk'], $invoice->id);
        self::assertCount(2, $this->grns->lines($g->id), 'made line for line from the invoice');
        $this->grns->saveDraft($who['desk'], $g->id, $this->docs->get($g->id)->version, [], [['sku_id' => $sku, 'supplier_code' => null, 'purchase_unit' => 'each',
            'units_per_pack' => 1, 'packs' => $units, 'pack_price' => '9.0000', 'po_line_no' => null, 'entry' => 'manual']]);
        $this->invoice($who['desk'], $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        return $this->post($who['desk'], $g->id);
    }

    /** A credit note of $units units of the invoice's line $line at $per off each, made final. */
    private function credit(array $who, Document $invoice, int $line, int $units, string $per): Document
    {
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $invoice->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, $line, $units, $per);
        return $this->postWithTotals($who['desk'], $cn, number_format($units * (float) $per, 2, '.', ''), '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
    }

    public function testAnItemMadeAfterTheStartKeepsTheInvoiceCorrectionOfItsFirstDelivery(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('New Item Invoice Ltd');
        $this->start();
        // Made after the start: ten received at £1 and their invoice made final at £2, both before the job's next run.
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '1.0000');
        $g = $this->deliver($who, $o, 10, '1.00');
        $inv = $this->invoiceAt($who, $g, '2.0000');
        $this->engine()->run();
        self::assertSame(20000, self::cost($o['sku']), 'the invoice price wins: 10 x £2.00, not the delivery\'s £1.00');
        $this->invoices->reverse($who['desk'], $inv->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(10000, self::cost($o['sku']), 'the invoice cancelled: back to the delivery\'s £1.00, never below');
    }

    public function testAShortReceiptOfTwoInvoiceLinesKeyedAsOneIsPricedByWhatCame(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Short Merge Ltd');
        $sku = self::makeSku('Two lines, one receipt line, short');
        $this->start();
        $p = $this->twoLines($who, $sku, '2.0000', '4.0000');
        $this->engine()->run();
        $g = $this->receiveAsOneLine($who, $p, $sku, 10);
        self::assertSame([[2, 10]], array_map(static fn (array $m): array => [$m['line_no'], $m['units']], $this->arrivals->missingOf($g->id)),
            'the arrival matching: the ten that came are line 1\'s, line 2 is short');
        $this->engine()->run();
        self::assertSame([[20000, 10]], array_map(static fn (array $r): array => [(int) $r['price_e4'], (int) $r['units']],
            self::$db->all("SELECT price_e4, units FROM item_cost_event WHERE sku_id = ? AND kind = 'receipt'", [$sku])), 'line 1\'s £2.00: (10 x £2.00) / 10, not £3.00');
        self::assertSame(20000, self::cost($sku));
    }

    public function testACreditOfOneOfTwoLinesKeyedAsOneIsTakenInAndCancelledByItsOwnLine(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Merged Credit Ltd');
        $sku = self::makeSku('Two lines, one receipt line, credited');
        $this->start();
        $p = $this->twoLines($who, $sku, '4.0000', '4.0000');
        $cn = $this->credit($who, $p, 2, 10, '1.0000');
        $this->engine()->run();
        self::assertSame(1, (int) self::$db->value("SELECT deferred FROM item_cost_event WHERE sku_id = ? AND kind = 'credit_note'", [$sku]), 'its goods are on their way');
        $this->receiveAsOneLine($who, $p, $sku, 20);
        $this->engine()->run();
        self::assertSame(35000, self::cost($sku), '(20 x £4.00 - 10 x £1.00) / 20 = £3.50');
        $this->invoices->reverse($who['desk'], $cn->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(40000, self::cost($sku), 'the credit of line 2 cancelled on the receipt that took it in: £80.00 / 20 = £4.00');
    }

    public function testACreditAfterTheReceiptOfTwoLinesKeyedAsOneLowersThePrice(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Merged Late Credit Ltd');
        $sku = self::makeSku('Two lines, one receipt line, credited after');
        $this->start();
        $p = $this->twoLines($who, $sku, '4.0000', '4.0000');
        $this->engine()->run();
        $this->receiveAsOneLine($who, $p, $sku, 20);
        $this->engine()->run();
        self::assertSame(40000, self::cost($sku));
        $this->credit($who, $p, 2, 10, '1.0000');
        $this->engine(60)->run();
        self::assertSame(0, (int) self::$db->value("SELECT deferred FROM item_cost_event WHERE sku_id = ? AND kind = 'credit_note'", [$sku]),
            'line 2\'s goods came (on the receipt\'s one line): not waiting for a receipt that will never come');
        self::assertSame(35000, self::cost($sku), '£10.00 off the 20 on hand: £3.50');
    }

    public function testTheSameKeyOfTwoStoresIsTwoOperations(): void
    {
        $a = $this->site('vapeandgo', 'live');
        $b = $this->site('vapebig', 'live');
        $this->grant($a, 'erp_sale');
        $who = $this->people('Two Stores Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '1.0000');
        $this->listing($a, 'VA', $o['sku']);
        $this->start();
        $g = $this->deliver($who, $o, 10, '1.00');
        $inv = $this->invoiceAt($who, $g, '2.0000');
        $this->engine()->run();
        self::assertSame(20000, self::cost($o['sku']));
        // Store B relays its goods-in from now on (one route per item: after CW's own delivery).
        $this->grant($b, 'goods_in');
        $this->listing($b, 'VB', $o['sku']);
        // Store A relays the sale of all ten, then store B relays ten more that came in without a price: each with ITS OWN key "batch:1".
        $this->ok($this->moves->record($a, ['type' => 'erp_sale', 'doc_ref' => 'SINV-A-1', 'lines' => [['variant_id' => 'VA', 'qty' => 10, 'line_index' => 0]]], 'batch:1'));
        $this->ok($this->moves->record($b, ['type' => 'goods_in', 'doc_ref' => 'PINV-B-1', 'lines' => [['variant_id' => 'VB', 'qty' => 10, 'line_index' => 0]]], 'batch:1'));
        self::assertSame(['batch:1', 'batch:1'], self::$db->column("SELECT idem_key FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand' AND channel_id IS NOT NULL ORDER BY id",
            [$o['sku']]));
        self::assertSame(10, $this->onHand($o['sku']));
        $this->invoices->reverse($who['desk'], $inv->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(20000, self::cost($o['sku']), 'the ten it corrected sold out before the next ten came (at £2.00): nothing of it is left to undo');
    }

    public function testACancellationReadBeforeItsInvoiceReadsTheInvoiceFirst(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Late Original Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '1.0000');
        $this->start();
        $g = $this->deliver($who, $o, 10, '1.00');
        $this->engine()->run();
        self::assertSame(10000, self::cost($o['sku']));
        $inv = $this->invoiceAt($who, $g, '2.0000');
        usleep(2000);
        $rev = $this->invoices->reverse($who['desk'], $inv->id, 'entered_in_error', null);
        $posted = (string) self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$inv->id]);
        self::assertLessThan((string) self::$db->value('SELECT posted_at FROM document WHERE reverses_id = ?', [$inv->id]), $posted);
        // The invoice committed late (after the job's window and after the night's run had passed it); the job's window is now a day and an
        // hour past it, so a run's look back (the day before its window) reaches the cancellation but not the invoice.
        $from = Clock::fromDb($posted)->modify('+1 day +1 usec');
        $mark = $from->modify('+' . CostEngine::OVERLAP_SECONDS . ' seconds');
        self::$db->exec('UPDATE profit_job SET mark_at = ? WHERE job = ?', [Clock::db($mark), CostEngine::JOB]);
        $at = $mark->modify('+' . (CostEngine::LAG_SECONDS + 60) . ' seconds');
        $this->engine(0, static fn (): \DateTimeImmutable => $at)->run();
        $night = $at->modify('+1 hour');
        $this->engine(0, static fn (): \DateTimeImmutable => $night)->run(CostEngine::MAX_EVENTS, CostEngine::UNREAD_PER_RUN, true);
        self::assertSame(10000, self::cost($o['sku']), 'the invoice (to £2.00) and its cancellation (back to £1.00) both read: £1.00');
        self::assertSame(['invoice', 'invoice_reversal'], self::$db->column("SELECT kind FROM item_cost_event WHERE sku_id = ? AND kind LIKE 'invoice%' ORDER BY source_at, id",
            [$o['sku']]));
        self::assertNotNull(self::$db->value('SELECT 1 FROM item_cost_document WHERE document_id = ?', [$rev->id]));
    }
}
