<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Profit\CostEngine;
use CW\Profit\ItemCosts;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;

/**
 * The Codex re-check of 57d98ad (build/A/codex_r2.txt), the buying price, each shown failing first: a credit of some units before the goods
 * come is spread over the invoice line (N1); the receipt of an invoice's goods on their way is priced by the line that was on its way, not
 * by another line of the same item (N4); an item made after the start never starts at CW's average, which already holds its receipts (N5);
 * the mark stops short of the first event not read (N6); a receipt whose booking committed after the job's window is still read (b2).
 */
final class CostEngineReviewR2Test extends ArrivalsTestCase
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

    private function start(): void
    {
        $plan = $this->engine()->plan("item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n", 'vapeandgo');
        $this->engine()->seed($plan['prices'], Clock::db(Clock::now()->modify('-1 hour')));
    }

    private function receiveAll(array $who, int $invoiceId): void
    {
        $g = $this->arrivals->receive($who['desk'], $invoiceId);
        $this->invoice($who['desk'], $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        $this->post($who['desk'], $g->id);
    }

    public function testACreditOfSomeUnitsBeforeTheyComeIsSpreadOverTheLine(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Spread Credit Ltd');
        $x = $this->invoiceOfOrder($who['desk'], $who['buyer'], (int) $who['supplier']['id'], 100, 1, '4.0000');
        $this->start();
        $p = $this->invoices->submit($who['desk'], $x['doc']->id, $x['doc']->version);
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, 1, 10, '1.0000');
        $cn = $this->postWithTotals($who['desk'], $cn, '10.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $this->engine()->run();
        $this->receiveAll($who, $p->id);
        $this->engine()->run();
        self::assertSame(39000, self::cost($x['sku']), '£10 off 100 units: £3.90 each, not £3.00');
        $this->invoices->reverse($who['desk'], $cn->id, 'entered_in_error', null);
        $this->engine()->run();
        self::assertSame(40000, self::cost($x['sku']), 'the credit cancelled: back to £4.00');
    }

    public function testAnItemMadeAfterTheStartNeverStartsAtCwsAverage(): void
    {
        $this->site('vapeandgo', 'live');
        ['buyer' => $buyer, 'supplier' => $s] = $this->people('Average After Ltd');
        $this->start();
        $new = self::makeSku('Made after the start, averaged already');
        self::$db->exec('INSERT INTO report_item (sku_id, in_units, in_value) VALUES (?, 20, 40.000000)', [$new]);
        $this->supplierItem($buyer, (int) $s['id'], $new, 1, '1.5000');
        $this->engine()->run();
        self::assertSame([15000, 'supplier_price'], [self::cost($new), (string) self::$db->value('SELECT start_source FROM item_cost WHERE sku_id = ?', [$new])],
            'the supplier\'s price: no unit of it came in with a price (the report\'s figures are never read; PR59 of round 3)');
    }

    public function testTheMarkStopsShortOfTheFirstEventNotRead(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Mark Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->start();
        foreach (['1.00', '3.00'] as $price) {
            $this->delivered($who['desk'], (int) $who['supplier']['id'], [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => 10,
                'units_per_pack' => 1, 'pack_price' => $price, 'po_line_no' => 1]], $o['doc']->id);
        }
        $second = (string) self::$db->value("SELECT MAX(created_at) FROM stock_ledger WHERE sku_id = ? AND movement_type = 'goods_in'", [$o['sku']]);
        $r = $this->engine()->run(1);
        self::assertSame(1, $r['events']);
        self::assertLessThan($second, (string) $r['mark_at'], 'the mark is before the delivery not read yet');
        self::assertFalse((new ItemCosts(self::$db))->reached($second), 'a sale at that moment waits');
        $this->engine()->run();
        self::assertSame(20000, self::cost($o['sku']));
    }

    public function testAReceiptBookedOutsideTheJobsWindowIsStillRead(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Window Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '2.0000');
        $this->start();
        $this->delivered($who['desk'], (int) $who['supplier']['id'], [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => 10,
            'units_per_pack' => 1, 'pack_price' => '2.00', 'po_line_no' => 1]], $o['doc']->id);
        // The job's window has moved on past the receipt (as if its booking committed after the job had read that time).
        self::$db->exec('UPDATE profit_job SET mark_at = ? WHERE job = ?', [Clock::db(Clock::now()->modify('+2 hours')), CostEngine::JOB]);
        $this->engine(3 * 3600)->run();
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM item_cost_event WHERE sku_id = ? AND kind = 'receipt'", [$o['sku']]));
        self::assertSame(20000, self::cost($o['sku']));
    }

    public function testGoodsOnTheirWayArePricedByTheirOwnLine(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Two Lines Ltd');
        $sid = (int) $who['supplier']['id'];
        $o = $this->approvedPoFor($who['buyer'], $sid, 20, 1, '4.0000');
        $this->start();
        // Ten came already at £2 (a delivery of the order); the invoice bills those ten (line 1) and the ten still on their way at £4 (line 2).
        $g = $this->delivered($who['desk'], $sid, [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => 10, 'units_per_pack' => 1,
            'pack_price' => '2.00', 'po_line_no' => 1]], $o['doc']->id);
        $d = $this->invoices->createDraft($who['desk'], 'invoice', $sid, ['invoice_number' => 'TWO-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->addReceipt($who['desk'], $d->id, $d->version, $g->id);
        $d = $this->invoices->addOrder($who['desk'], $d->id, $d->version, $o['doc']->id);
        $lines = $this->invoices->lines($d->id);
        self::assertCount(2, $lines, 'one line on the delivery, one on its way');
        $net = (string) self::$db->value('SELECT CAST(COALESCE(SUM(amount), 0) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$d->id]);
        $d = $this->invoices->saveHeader($who['desk'], $d->id, $d->version, ['net_total' => $net, 'vat_total' => '0.00']);
        $p = $this->invoices->submit($who['desk'], $d->id, $d->version);
        $this->engine()->run();
        $this->receiveAll($who, $p->id);
        $this->engine()->run();
        self::assertSame(40000, (int) self::$db->value("SELECT price_e4 FROM item_cost_event WHERE sku_id = ? AND kind = 'receipt' ORDER BY id DESC LIMIT 1", [$o['sku']]),
            'the ten that were on their way came in at their own line\'s £4.00');
    }
}
