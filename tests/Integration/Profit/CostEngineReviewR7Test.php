<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Documents\Document;
use CW\Profit\CostEngine;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;
use CW\Tests\Integration\Invoices\SupplierInvoicesTest;

/**
 * The Codex check of 3f1b54e (build/A/codex_r7.txt), the buying price, each shown failing first with Codex's numbers: a delivery is in the
 * history at the moment its units are on hand (its place in the item's commit order), so a credit between its booking and its being made
 * final sees what was on hand (M1); a moment long ago is read without reading the history after it (M2); one credit note of two lines
 * cancelled gives back what the two together took off (r5a 7 LOW).
 */
final class CostEngineReviewR7Test extends ArrivalsTestCase
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

    private function start(): void
    {
        $plan = $this->engine()->plan("item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n", 'vapeandgo');
        $this->engine()->seed($plan['prices'], Clock::db(Clock::now()->modify('-1 hour')));
    }

    private function deliver(array $who, array $o, int $packs, string $price): Document
    {
        return $this->delivered($who['desk'], (int) $who['supplier']['id'], [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => $packs,
            'units_per_pack' => 1, 'pack_price' => $price, 'po_line_no' => 1]], $o['doc']->id);
    }

    private function invoiceAt(array $who, Document $grn, string $price): Document
    {
        $d = $this->invoices->createFromReceipt($who['desk'], $grn->id)['document'];
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [SupplierInvoicesTest::lineOf($this->invoices->lines($d->id)[0], ['pack_price' => $price])]);
        return $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'R7-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    /** Rows MySQL read for this connection so far (every Handler_read_* counter). */
    private static function rowsRead(): int
    {
        $n = 0;
        foreach (self::$db->all("SHOW SESSION STATUS LIKE 'Handler_read%'") as $r) {
            $n += (int) $r['Value'];
        }
        return $n;
    }

    public function testADeliveryIsInTheHistoryWhenItsUnitsAreOnHand(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Two Clocks Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->start();
        $d0 = $this->deliver($who, $o, 10, '1.00');
        $i0 = $this->invoiceAt($who, $d0, '1.0000');
        $this->engine()->run();
        self::assertSame(10000, self::cost($o['sku']), 'ten at £1.00');
        // Ten more at £3.00 booked now and made final two minutes later (its transaction took that long) ...
        $r = $this->deliver($who, $o, 10, '3.00');
        $row = (int) self::$db->value("SELECT id FROM stock_ledger WHERE document_id = ? AND movement_type = 'goods_in' AND bucket = 'on_hand'", [$r->id]);
        $final = Clock::db(Clock::now()->modify('+2 minutes'));
        self::$db->exec('UPDATE stock_value_seq SET created_at = ? WHERE stock_ledger_id = ?', [$final, $row]);
        // ... and between the two, a credit of £10.00 for the first ten (£1.00 each), when only those ten were on hand.
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $i0->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, 1, 10, '1.0000');
        $this->postWithTotals($who['desk'], $cn, '10.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
        $this->engine(300)->run();
        self::assertSame(15000, self::cost($o['sku']), 'the ten at £0 after the credit, then ten at £3.00: £30.00 for twenty, £1.50 each (never £20.00)');
    }

    public function testAMomentLongAgoIsReadWithoutTheHistoryAfterIt(): void
    {
        $this->site('vapeandgo', 'live');
        $sku = self::makeSku('A long history');
        for ($i = 0; $i < 300; $i++) {
            $this->book('adjustment', $sku, 1);
        }
        // A history of 300 days, not 300 bookings in one second: each booking (and its being made final) a day after the one before.
        self::$db->exec("UPDATE stock_ledger SET created_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL (400 - (SELECT n FROM (SELECT l2.id, ROW_NUMBER() OVER (ORDER BY l2.id) AS n "
            . "FROM stock_ledger l2 WHERE l2.sku_id = ?) t WHERE t.id = stock_ledger.id)) DAY) WHERE sku_id = ?", [$sku, $sku]);
        self::$db->exec('UPDATE stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id SET s.created_at = l.created_at WHERE s.sku_id = ?', [$sku]);
        $early = (string) self::$db->value('SELECT created_at FROM stock_value_seq WHERE sku_id = ? AND seq = 10', [$sku]);
        $engine = $this->engine();
        $engine->onHandAt($sku, $early); // warm
        $overhead = -self::rowsRead() + self::rowsRead();
        $before = self::rowsRead();
        self::assertSame(10, $engine->onHandAt((int) $sku, $early));
        self::assertLessThan(150, self::rowsRead() - $before - $overhead, 'a few indexed reads, not the 290 rows after the moment');
    }

    public function testOneCreditNoteOfTwoLinesCancelledGivesBackWhatTheyTookOffTogether(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Two Line Note Ltd');
        $sku = self::makeSku('Four hundred, 1p and 2p off on one note');
        $this->start();
        $d = $this->invoices->createDraft($who['desk'], 'invoice', (int) $who['supplier']['id'], ['invoice_number' => 'R7W-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [['kind' => 'goods', 'sku_id' => $sku, 'packs' => 400, 'units_per_pack' => 1,
            'pack_price' => '1.0000', 'supplier_code' => null, 'purchase_unit' => 'each', 'entry' => 'manual', 'description' => null]]);
        $d = $this->invoices->saveHeader($who['desk'], $d->id, $d->version, ['net_total' => '400.00', 'vat_total' => '0.00']);
        $p = $this->invoices->submit($who['desk'], $d->id, $d->version);
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, 1, 1, '0.0100');
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, 1, 1, '0.0200');
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM sinv_line WHERE document_id = ? AND kind = 'goods'", [$cn->id]), 'two lines on one note');
        $cn = $this->postWithTotals($who['desk'], $cn, '0.03', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
        $this->engine()->run();
        $g = $this->arrivals->receive($who['desk'], $p->id);
        $this->invoice($who['desk'], $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        $this->post($who['desk'], $g->id);
        $this->engine()->run();
        self::assertSame(9999, self::cost($sku), '(400 x £1.00 - 3p) / 400 = £0.999925: £0.9999');
        $this->invoices->reverse($who['desk'], $cn->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(10000, self::cost($sku), 'the note cancelled: £400.00, not £399.96');
    }
}
