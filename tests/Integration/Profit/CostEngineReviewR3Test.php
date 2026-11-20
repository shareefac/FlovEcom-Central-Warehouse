<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Profit\CostEngine;
use CW\StockOps\StockOps;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;
use CW\Tests\Integration\Invoices\SupplierInvoicesTest;

/**
 * The Codex re-check of 37a2cf0 (build/A/codex_r3.txt), the buying price, each shown failing first: a document partly read when a run
 * stopped is read whole later (R1); a document that moves no price is read once and never fills the run (R2); a transfer between two of our
 * warehouses is not a sell-out (R3); a cancelled credit comes off every portion of its receipt (R4); an item made after the start with stock
 * booked in at a price starts at that price (R5); a late event keeps the price at its own place in the history (R6).
 */
final class CostEngineReviewR3Test extends ArrivalsTestCase
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

    /** The job's window moved two hours past now (as if what is booked now committed after the job had read that time). */
    private function windowPast(): void
    {
        self::$db->exec('UPDATE profit_job SET mark_at = ? WHERE job = ?', [Clock::db(Clock::now()->modify('+2 hours')), CostEngine::JOB]);
    }

    /** A delivery of $packs units of $o's item at $price, against line 1 of its order. */
    private function deliver(array $who, array $o, int $packs, string $price): \CW\Documents\Document
    {
        return $this->delivered($who['desk'], (int) $who['supplier']['id'], [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => $packs,
            'units_per_pack' => 1, 'pack_price' => $price, 'po_line_no' => 1]], $o['doc']->id);
    }

    public function testADocumentPartlyReadWhenARunStoppedIsReadWholeLater(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Stopped Run Ltd');
        $sid = (int) $who['supplier']['id'];
        $a = $this->approvedPoFor($who['buyer'], $sid, 10, 1, '1.0000');
        $b = $this->approvedPoFor($who['buyer'], $sid, 10, 1, '1.0000');
        $this->start();
        $g = $this->delivered($who['desk'], $sid, [
            ['sku_id' => $a['sku'], 'supplier_item_id' => (int) $a['si']['id'], 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '1.00'],
            ['sku_id' => $b['sku'], 'supplier_item_id' => (int) $b['si']['id'], 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '3.00']]);
        $this->windowPast();
        // A run read the delivery late, wrote its first line's event and stopped there.
        $first = (array) self::$db->one("SELECT id, created_at FROM stock_ledger WHERE document_id = ? AND sku_id = ? AND movement_type = 'goods_in'", [$g->id, $a['sku']]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, document_id, document_line, units, on_hand, price_e4, old_cost_e4, new_cost_e4) "
            . "VALUES (?, ?, ?, 'receipt', ?, ?, 1, 10, 0, 10000, 10000, 10000)", [$a['sku'], $first['created_at'], $first['created_at'], 'ledger:' . $first['id'], $g->id]);
        $this->engine(3 * 3600)->run();
        self::assertSame([10000, 30000], [self::cost($a['sku']), self::cost($b['sku'])], 'its second line is read too: 10 at £3');
    }

    public function testADocumentThatMovesNoPriceIsReadOnceAndNeverFillsTheRun(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Carriage Only Ltd');
        $sid = (int) $who['supplier']['id'];
        $o = $this->approvedPoFor($who['buyer'], $sid, 20, 1, '1.0000');
        $this->start();
        $this->deliver($who, $o, 10, '1.00');
        $this->engine()->run();
        // A carriage-only invoice (it moves no price), then a delivery of 10 at £3: both left behind by the job's window.
        $c = $this->invoices->createDraft($who['desk'], 'invoice', $sid, ['invoice_number' => 'CAR-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $c = $this->invoices->addCharge($who['desk'], $c->id, $c->version, 'freight', '5.00', 'Carriage');
        $c = $this->postWithTotals($who['desk'], $c, '5.00');
        $this->deliver($who, $o, 10, '3.00');
        $this->windowPast();
        // One document a run: the first delivery (already read), the invoice, the second delivery.
        foreach ([0, 60, 120] as $i => $s) {
            $this->engine(3 * 3600 + $s)->run(CostEngine::MAX_EVENTS, 1);
        }
        self::assertNotNull(self::$db->value('SELECT 1 FROM item_cost_document WHERE document_id = ?', [$c->id]), 'the invoice is read, though it moved nothing');
        self::assertSame(20000, self::cost($o['sku']), 'the runs go past it to the delivery: (10 x £1 + 10 x £3) / 20');
    }

    public function testATransferBetweenOurWarehousesIsNotASellOut(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Transfer Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '1.0000');
        $this->start();
        $g = $this->deliver($who, $o, 10, '1.00');
        $this->engine()->run();
        $d = $this->invoices->createFromReceipt($who['desk'], $g->id)['document'];
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [SupplierInvoicesTest::lineOf($this->invoices->lines($d->id)[0], ['pack_price' => '2.0000'])]);
        $inv = $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'TRF-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
        $this->engine()->run();
        self::assertSame(20000, self::cost($o['sku']));
        // All ten moved to another warehouse of ours: one record, out of MAIN and into the other at once.
        $dir = sys_get_temp_dir() . '/cw_r3trf_' . bin2hex(random_bytes(5));
        mkdir($dir, 0700, true);
        try {
            $store = new FileStore(self::$db, new LocalFileStorage($dir));
            $ops = new StockOps(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)), static fn (): FileStore => $store);
            $shop = (int) self::$db->insert("INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, owner_entity, sort_order) VALUES ('SHOPR3', 'Shop room', 0, 1, 'own', NULL, 50)");
            $sc = $this->staffUser('stock_controller');
            $t = $ops->createDraft($sc, 'transfer', ['warehouse' => self::warehouseId('MAIN'), 'to_warehouse' => $shop]);
            $t = $ops->saveLines($sc, $t->id, $t->version, [['sku_id' => $o['sku'], 'qty' => 10]]);
            self::assertSame('posted', $ops->post($sc, $t->id, $t->version)->status);
        } finally {
            exec('chmod -R u+w ' . escapeshellarg($dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dir));
        }
        $this->invoices->reverse($who['desk'], $inv->id, 'entered_in_error', null);
        $this->engine()->run();
        self::assertSame(10000, self::cost($o['sku']), 'the ten are still ours: the cancelled invoice comes off them, back to £1');
    }

    public function testACancelledCreditComesOffEveryPortionOfItsReceipt(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Two Rooms Ltd');
        $x = $this->invoiceOfOrder($who['desk'], $who['buyer'], (int) $who['supplier']['id'], 100, 1, '4.0000');
        $this->start();
        $p = $this->invoices->submit($who['desk'], $x['doc']->id, $x['doc']->version);
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, 1, 10, '1.0000');
        $cn = $this->postWithTotals($who['desk'], $cn, '10.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $this->engine()->run();
        // The hundred come in two portions: 60 into MAIN, 40 found damaged into VERIFY (both ours).
        $g = $this->arrivals->receive($who['desk'], $p->id);
        $this->invoice($who['desk'], $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id, [1 => ['damaged_units' => '40']]);
        $this->post($who['desk'], $g->id);
        $this->engine()->run();
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM item_cost_event WHERE sku_id = ? AND kind = 'receipt'", [$x['sku']]));
        self::assertSame(39000, self::cost($x['sku']), '£10 off 100 units: £3.90 each');
        $this->invoices->reverse($who['desk'], $cn->id, 'entered_in_error', null);
        $this->engine()->run();
        self::assertSame(40000, self::cost($x['sku']), 'the credit cancelled on all hundred: back to £4.00');
    }

    public function testAnItemMadeAfterTheStartWithStockBookedInAtAPriceStartsAtThatPrice(): void
    {
        $this->site('vapeandgo', 'live');
        $this->start();
        $new = self::makeSku('Made after the start, booked in by hand');
        $this->book('adjustment', $new, 10, 'MAIN', null, '1.00');
        $this->engine()->run();
        self::assertSame([10000, 'cw_average'], [self::cost($new), (string) self::$db->value('SELECT start_source FROM item_cost WHERE sku_id = ?', [$new])],
            'CW\'s average of what came in with a price');
        // A delivery of it read afterwards, booked before the start: in the start already (never counted twice).
        $who = $this->people('Made After Ltd');
        $other = self::makeSku('Made after the start, delivered');
        $si2 = $this->supplierItem($who['buyer'], (int) $who['supplier']['id'], $other, 1, '3.0000');
        $this->delivered($who['desk'], (int) $who['supplier']['id'], [['sku_id' => $other, 'supplier_item_id' => (int) $si2['id'], 'packs' => 10, 'units_per_pack' => 1,
            'pack_price' => '2.00']]);
        $this->engine(60)->run();
        self::assertSame([20000, 'cw_average'], [self::cost($other), (string) self::$db->value('SELECT start_source FROM item_cost WHERE sku_id = ?', [$other])],
            'its delivery is in its start, and read once: £2, not more');
    }

    public function testALateEventKeepsThePriceAtItsOwnPlace(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Late Place Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->book('adjustment', $o['sku'], 10); // ten on the shelf at the start (no price of their own: the supplier's £1)
        $this->start();
        self::assertSame(10000, self::cost($o['sku']));
        $d1 = $this->deliver($who, $o, 10, '2.00');
        $d2 = $this->deliver($who, $o, 10, '4.00');
        // The job read the second delivery first (the first one's booking committed late): its event as the job writes it.
        $r2 = (array) self::$db->one("SELECT id, created_at FROM stock_ledger WHERE document_id = ? AND movement_type = 'goods_in'", [$d2->id]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, document_id, document_line, units, on_hand, price_e4, old_cost_e4, new_cost_e4) "
            . "VALUES (?, ?, ?, 'receipt', ?, ?, 1, 10, 20, 40000, 10000, 20000)", [$o['sku'], $r2['created_at'], $r2['created_at'], 'ledger:' . $r2['id'], $d2->id]);
        self::$db->exec('UPDATE item_cost SET unit_cost_e4 = 20000 WHERE sku_id = ?', [$o['sku']]);
        $this->engine()->run();
        $r1 = (string) self::$db->value("SELECT id FROM stock_ledger WHERE document_id = ? AND movement_type = 'goods_in'", [$d1->id]);
        self::assertSame(15000, (int) self::$db->value('SELECT new_cost_e4 FROM item_cost_event WHERE source_key = ?', ['ledger:' . $r1]),
            'the first delivery\'s price at its place: (10 x £1 + 10 x £2) / 20, not worked out from the price after the second');
        self::assertSame(23333, self::cost($o['sku']), 'now: (20 x £1.50 + 10 x £4) / 30');
    }
}
