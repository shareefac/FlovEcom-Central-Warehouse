<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Documents\Document;
use CW\Profit\CostEngine;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;

/**
 * The Codex check of 1179112..e1f075c (build/A/codex_r8.txt), the buying price, each shown failing first with Codex's numbers: the job's
 * mark never passes what it has read (M1); a new item starts at what came in before its first delivery in commit order (M2); a receipt taken
 * back stops taking credits when its stock leaves (M3); the units on hand at a place are exact whatever the ledger's ids (M4) and whatever the
 * clock did (M5); cancelling one credit note leaves another's rounding (LOW 1); a rebuild is counted once (LOW 2).
 */
final class CostEngineReviewR8Test extends ArrivalsTestCase
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

    private function onTheirWay(array $who, int $sku, int $units, string $price): Document
    {
        $d = $this->invoices->createDraft($who['desk'], 'invoice', (int) $who['supplier']['id'], ['invoice_number' => 'R8-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [['kind' => 'goods', 'sku_id' => $sku, 'packs' => $units, 'units_per_pack' => 1,
            'pack_price' => $price, 'supplier_code' => null, 'purchase_unit' => 'each', 'entry' => 'manual', 'description' => null]]);
        $net = (string) self::$db->value('SELECT CAST(COALESCE(SUM(amount), 0) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$d->id]);
        $d = $this->invoices->saveHeader($who['desk'], $d->id, $d->version, ['net_total' => $net, 'vat_total' => '0.00']);
        return $this->invoices->submit($who['desk'], $d->id, $d->version);
    }

    private function receive(array $who, Document $invoice): Document
    {
        $g = $this->arrivals->receive($who['desk'], $invoice->id);
        $this->invoice($who['desk'], $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        return $this->post($who['desk'], $g->id);
    }

    /** A credit note of the invoice's line 1 with these lines (units => per unit), made final. */
    private function note(array $who, Document $invoice, array $lines, string $net): Document
    {
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $invoice->id]);
        foreach ($lines as [$units, $per]) {
            $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, 1, $units, $per);
        }
        return $this->postWithTotals($who['desk'], $cn, $net, '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    /** The on-hand ledger row of a document for an item, and its seq row made final at $at. */
    private static function finalAt(int $documentId, int $sku, string $at): int
    {
        $row = (int) self::$db->value("SELECT id FROM stock_ledger WHERE document_id = ? AND sku_id = ? AND bucket = 'on_hand' ORDER BY id LIMIT 1", [$documentId, $sku]);
        self::$db->exec('UPDATE stock_value_seq SET created_at = ? WHERE stock_ledger_id = ?', [$at, $row]);
        return $row;
    }

    public function testTheMarkNeverPassesWhatTheJobHasRead(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Mark Cap Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->start();
        $this->deliver($who, $o, 10, '1.00');
        $b = $this->deliver($who, $o, 10, '3.00');
        $now = Clock::now();
        $cutoff = $now->modify('+60 seconds');
        self::finalAt($b->id, $o['sku'], Clock::db($cutoff->modify('+2 minutes'))); // booked now, made final after the job's cutoff
        $r = $this->engine(0, static fn (): \DateTimeImmutable => $now->modify('+' . (CostEngine::LAG_SECONDS + 60) . ' seconds'))->run(1);
        self::assertSame(1, $r['events']);
        self::assertLessThanOrEqual(Clock::db($cutoff), (string) $r['mark_at'], 'never past the cutoff: an invoice after it is not read yet');
    }

    public function testTheMarkWaitsForEveryUnreadDocument(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Unread Backlog Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 30, 1, '1.0000');
        $this->start();
        foreach (['1.00', '2.00', '3.00'] as $price) {
            $this->deliver($who, $o, 10, $price);
        }
        // All three committed after the job's window had passed them (late bookings); the look back reads one document a run here.
        $held = Clock::db(Clock::now()->modify('+2 hours'));
        self::$db->exec('UPDATE profit_job SET mark_at = ? WHERE job = ?', [$held, CostEngine::JOB]);
        $r = $this->engine(3 * 3600)->run(CostEngine::MAX_EVENTS, 1);
        self::assertSame($held, (string) $r['mark_at'], 'two deliveries are still unread: the mark does not move on past them');
        $this->engine(3 * 3600 + 60)->run(CostEngine::MAX_EVENTS, 1);
        $r = $this->engine(3 * 3600 + 120)->run(CostEngine::MAX_EVENTS, 1);
        self::assertGreaterThan($held, (string) $r['mark_at'], 'all read: it moves on');
        self::assertSame(20000, self::cost($o['sku']));
    }

    public function testANewItemStartsAtWhatCameInBeforeItsFirstDeliveryInCommitOrder(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Commit Order Start Ltd');
        $this->start();
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '1.0000'); // the supplier's £1.00
        $g = $this->deliver($who, $o, 10, '3.00');
        $this->book('adjustment', $o['sku'], 10, 'VERIFY', null, '2.00');
        // The delivery was booked first but made final after the stock-in (its transaction took longer): swap their places in the commit order.
        $r = (int) self::$db->value("SELECT id FROM stock_ledger WHERE document_id = ? AND bucket = 'on_hand'", [$g->id]);
        $a = (int) self::$db->value("SELECT MAX(id) FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand'", [$o['sku']]);
        $ta = (string) self::$db->value('SELECT created_at FROM stock_value_seq WHERE stock_ledger_id = ?', [$a]);
        self::$db->exec('UPDATE stock_value_seq SET seq = 999 WHERE stock_ledger_id = ?', [$r]);
        self::$db->exec('UPDATE stock_value_seq SET seq = 1 WHERE stock_ledger_id = ?', [$a]);
        self::$db->exec('UPDATE stock_value_seq SET seq = 2, created_at = ? WHERE stock_ledger_id = ?', [Clock::db(Clock::fromDb($ta)->modify('+1 second')), $r]);
        $this->engine(60)->run();
        self::assertSame(25000, self::cost($o['sku']), 'ten at £2.00 were on hand before the delivery: (10 x £2.00 + 10 x £3.00) / 20 = £2.50, not £2.00');
    }

    public function testAReceiptTakenBackStopsTakingCreditsWhenItsStockLeaves(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Leaving Late Ltd');
        $sku = self::makeSku('Ten on the shelf, ten received then taken back');
        $this->supplierItem($who['buyer'], (int) $who['supplier']['id'], $sku, 1, '4.0000');
        $this->book('adjustment', $sku, 10);
        $this->start();
        $p = $this->onTheirWay($who, $sku, 10, '4.0000');
        $g = $this->receive($who, $p);
        $this->engine()->run();
        self::assertSame(40000, self::cost($sku));
        $rev = $this->docs->reverse($who['desk'], $g->id, 'entered_in_error', null);
        self::finalAt($rev->id, $sku, Clock::db(Clock::now()->modify('+2 minutes'))); // its stock leaves two minutes later
        $this->note($who, $p, [[10, '1.0000']], '10.00'); // between: the twenty are still on hand
        $this->engine(300)->run();
        self::assertSame(30000, self::cost($sku), '£10.00 off the twenty (£3.50), then the ten leave at £4.00: £30.00 for ten, not £40.00');
    }

    public function testTheUnitsOnHandAtAPlaceAreExactWhateverTheLedgersIds(): void
    {
        $this->site('vapeandgo', 'live');
        $other = self::makeSku('Booked just before');
        $sku = self::makeSku('Booked just after');
        $this->book('adjustment', $other, 1);
        $this->book('adjustment', $sku, 10);
        // The other item's row has the smaller id but the later booking time (its insert started later, its id came first).
        $mine = (string) self::$db->value("SELECT created_at FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand'", [$sku]);
        self::$db->exec("UPDATE stock_ledger SET created_at = ? WHERE sku_id = ? AND bucket = 'on_hand'", [Clock::db(Clock::fromDb($mine)->modify('+500 milliseconds')), $other]);
        $at = (string) self::$db->value('SELECT created_at FROM stock_value_seq WHERE sku_id = ? AND seq = 1', [$sku]);
        self::assertSame(10, $this->engine()->onHandAt($sku, $at), 'the ten booked are on hand, never 0');
    }

    public function testTheUnitsOnHandAtAMomentAreExactWhateverTheClockDid(): void
    {
        $this->site('vapeandgo', 'live');
        $sku = self::makeSku('Three bookings, the clock stepped back');
        for ($i = 0; $i < 3; $i++) {
            $this->book('adjustment', $sku, 10);
        }
        $t0 = Clock::now()->modify('-1 hour');
        foreach ([1 => 0, 2 => 4, 3 => 2] as $seq => $min) {
            self::$db->exec('UPDATE stock_value_seq SET created_at = ? WHERE sku_id = ? AND seq = ?', [Clock::db($t0->modify("+{$min} minutes")), $sku, $seq]);
        }
        self::assertSame(30, $this->engine()->onHandAt($sku, Clock::db($t0->modify('+3 minutes'))), 'the third (made final at 10:02 by the clock) is counted: 30, not 10');
    }

    public function testCancellingOneCreditNoteLeavesAnothersRounding(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Two Notes Ltd');
        $sku = self::makeSku('Four hundred, a note of three lines and a note of one');
        $this->start();
        $p = $this->onTheirWay($who, $sku, 400, '1.0000');
        $three = $this->note($who, $p, [[1, '0.0100'], [1, '0.0300'], [1, '0.0300']], '0.07');
        $this->note($who, $p, [[1, '0.0400']], '0.04');
        $this->engine()->run();
        $this->receive($who, $p);
        $this->engine()->run();
        self::assertSame(9997, self::cost($sku), '(400 x £1.00 - 11p) / 400 = £0.999725: £0.9997 (£399.88)');
        $this->invoices->reverse($who['desk'], $three->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(9999, self::cost($sku), 'the 4p note stands: (400 x £1.00 - 4p) / 400 = £0.9999 (£399.96), not £400.00');
    }

    public function testARebuildIsCountedOnce(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Count Once Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->start();
        $g1 = $this->deliver($who, $o, 10, '1.00');
        $this->engine()->run();
        $d = $this->invoices->createFromReceipt($who['desk'], $g1->id)['document'];
        $inv = $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'R8C-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
        usleep(2000);
        $this->deliver($who, $o, 10, '3.00');
        $mark = Clock::fromDb((string) self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$inv->id]))->modify('+1 usec +' . CostEngine::OVERLAP_SECONDS . ' seconds');
        self::$db->exec('UPDATE profit_job SET mark_at = ? WHERE job = ?', [Clock::db($mark), CostEngine::JOB]);
        $at = $mark->modify('+' . (CostEngine::LAG_SECONDS + 60) . ' seconds');
        $r = (new CostEngine(self::$db, static fn (): \DateTimeImmutable => $at))->run();
        self::assertSame(1, (int) self::$db->value('SELECT timeline FROM item_cost WHERE sku_id = ?', [$o['sku']]), 'the late invoice rebuilt the item');
        // The second delivery (1, in the window) and the rebuilt timeline (start, receipt, invoice, receipt: 4): 5 written, the late invoice not twice.
        self::assertSame(5, $r['events']);
    }
}
