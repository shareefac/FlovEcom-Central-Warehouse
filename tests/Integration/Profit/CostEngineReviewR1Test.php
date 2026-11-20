<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Profit\CostEngine;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;
use CW\Tests\Integration\Invoices\SupplierInvoicesTest;

/**
 * The Codex review of 92efa82 (build/A/codex_r1_a.txt, codex_r1_b.txt), the buying price, each shown failing first: split invoices of one
 * delivery line (a2); a reversal of an invoice that moved nothing (a3); a price credit before its goods arrived (a5); an event that came late
 * is folded in its own order (a6); goods that were on their way, received and taken back before the job ran (a13); the job never reads before
 * the start (b3); a start stopped half way goes on (b4); an item made after the start gets its starting price (b5).
 */
final class CostEngineReviewR1Test extends ArrivalsTestCase
{
    private function engine(): CostEngine
    {
        // Past the job's lag: what was just booked is read, and the mark stays behind what the test books next.
        return new CostEngine(self::$db, static fn (): \DateTimeImmutable => Clock::now()->modify('+' . (CostEngine::LAG_SECONDS + 60) . ' seconds'));
    }

    private static function cost(int $sku): ?int
    {
        $v = self::$db->value('SELECT unit_cost_e4 FROM item_cost WHERE sku_id = ?', [$sku]);
        return $v === null ? null : (int) $v;
    }

    private function start(?string $at = null): void
    {
        $plan = $this->engine()->plan("item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n", 'vapeandgo');
        $this->engine()->seed($plan['prices'], $at ?? Clock::db(Clock::now()->modify('-1 hour')));
    }

    /** A delivery of $packs units at $price against line 1 of the order. */
    private function deliver(array $who, array $o, int $packs, string $price): \CW\Documents\Document
    {
        return $this->delivered($who['desk'], (int) $who['supplier']['id'], [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => $packs,
            'units_per_pack' => 1, 'pack_price' => $price, 'po_line_no' => 1]], $o['doc']->id);
    }

    private function invoiceOf(array $who, int $grnId, array $change): \CW\Documents\Document
    {
        $d = $this->invoices->createFromReceipt($who['desk'], $grnId)['document'];
        $l = $this->invoices->lines($d->id);
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [SupplierInvoicesTest::lineOf($l[0], $change)]);
        return $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'INV-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    public function testSplitInvoicesOfOneDeliveryLineEachMoveTheirOwnUnits(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Split Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 100, 1, '1.0000');
        $this->start();
        $g = $this->deliver($who, $o, 100, '1.00');
        $this->engine()->run();
        self::assertSame(10000, self::cost($o['sku']));
        $this->invoiceOf($who, $g->id, ['packs' => 50, 'pack_price' => '2.0000']);
        $this->engine()->run();
        self::assertSame(15000, self::cost($o['sku']), '50 of the 100 from £1 to £2');
        $this->invoiceOf($who, $g->id, ['packs' => 50, 'pack_price' => '3.0000']);
        $this->engine()->run();
        self::assertSame(25000, self::cost($o['sku']), 'the other 50 from £1 (their own price) to £3: (50 x 2 + 50 x 3) / 100');
    }

    public function testAReversalUndoesOnlyWhatItsInvoiceMoved(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $who = $this->people('Reversal Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->listing($site, 'R1', $o['sku']);
        $this->start();
        $g = $this->deliver($who, $o, 10, '1.00');
        // All 10 sold and sent before the invoice is made final: the invoice moves nothing.
        $units = array_map(static fn (int $i): string => 'r' . $i, range(1, 10));
        $this->ok($this->commit($site, 'S1', [self::line('R1', ...$units)]));
        $this->ok($this->ship($site, 'S1', $units));
        $inv = $this->invoiceOf($who, $g->id, ['pack_price' => '2.0000']);
        $this->engine()->run();
        self::assertSame(10000, self::cost($o['sku']), 'nothing on hand: unchanged');
        $this->deliver($who, $o, 10, '3.00');
        $this->engine()->run();
        self::assertSame(30000, self::cost($o['sku']));
        $this->invoices->reverse($who['desk'], $inv->id, 'entered_in_error', null);
        $this->engine()->run();
        self::assertSame(30000, self::cost($o['sku']), 'the reversal undoes nothing: its invoice moved nothing');
    }

    public function testACreditBeforeTheGoodsArriveComesInWithThem(): void
    {
        $this->site('vapeandgo', 'live');
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Credit Early Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10, 1, '4.0000');
        $d = $this->invoices->addCharge($desk, $x['doc']->id, $x['doc']->version, 'freight', '2.00', 'Carriage');
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => '42.00', 'vat_total' => '0.00']);
        $this->start();
        $p = $this->invoices->submit($desk, $d->id, $d->version);
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['credits_id' => $p->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 10, '0.5000');
        $this->postWithTotals($desk, $cn, '5.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $this->engine()->run();
        $g = $this->arrivals->receive($desk, $p->id);
        $this->invoice($desk, $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        $this->post($desk, $g->id);
        $this->engine()->run();
        self::assertSame(37000, self::cost($x['sku']), '£4.00 + 20p carriage - 50p credited');
    }

    public function testGoodsOnTheirWayReceivedAndTakenBackBeforeTheJobRanKeepTheInvoicesPrice(): void
    {
        $this->site('vapeandgo', 'live');
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Provenance Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10, 1, '2.0000');
        $d = $this->invoices->addCharge($desk, $x['doc']->id, $x['doc']->version, 'freight', '10.00', 'Carriage');
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => '30.00', 'vat_total' => '0.00']);
        $this->start();
        $p = $this->invoices->submit($desk, $d->id, $d->version);
        $g = $this->arrivals->receive($desk, $p->id);
        $this->invoice($desk, $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        $g = $this->post($desk, $g->id);
        $this->docs->reverse($desk, $g->id, 'entered_in_error', null);
        $this->engine()->run();
        self::assertSame(30000, (int) self::$db->value("SELECT price_e4 FROM item_cost_event WHERE sku_id = ? AND kind = 'receipt'", [$x['sku']]),
            'it came in at the invoice\'s £3.00 (its arrival row was opened again since)');
    }

    public function testTheJobNeverReadsBeforeTheStartAndAStartStoppedHalfWayGoesOn(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Floor Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '1.0000');
        $this->deliver($who, $o, 10, '5.00'); // in ERPNext's valuation already: before the start
        $other = self::makeSku('Second item');
        $plan = $this->engine()->plan("item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n", 'vapeandgo');
        // The first batch of a start, then the run stopped: one item written, no mark.
        $first = [array_key_first($plan['prices']) => $plan['prices'][array_key_first($plan['prices'])]];
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, ?, '2026-11-16', '2026-11-16')",
            [array_key_first($first), $first[array_key_first($first)]['e4'], $first[array_key_first($first)]['source']]);
        $at = Clock::db(Clock::now()->modify('+1 second'));
        self::assertSame(count($plan['prices']) - 1, $this->engine()->seed($plan['prices'], $at), 'it goes on with the items not written yet');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM item_cost WHERE sku_id = ?', [$other]));
        $this->engine()->run();
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM item_cost_event WHERE kind = 'receipt'"), 'a delivery before the start is in the start');
        $this->expectException(\RuntimeException::class);
        $this->engine()->seed($plan['prices'], $at);
    }

    public function testAnItemMadeAfterTheStartGetsItsStartingPrice(): void
    {
        $this->site('vapeandgo', 'live');
        ['buyer' => $buyer, 'supplier' => $s] = $this->people('Late Item Ltd');
        $this->start();
        $new = self::makeSku('Made after the start');
        $this->supplierItem($buyer, (int) $s['id'], $new, 10, '7.0000');
        $this->engine()->run();
        self::assertSame([7000, 'supplier_price'], [self::cost($new), (string) self::$db->value('SELECT start_source FROM item_cost WHERE sku_id = ?', [$new])]);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM item_cost_event WHERE sku_id = ? AND kind = 'start'", [$new]));
    }

    public function testAnEventThatCameLateIsFoldedInItsOwnOrder(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Late Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->start();
        $g1 = $this->deliver($who, $o, 10, '1.00');
        $this->engine()->run();
        $this->deliver($who, $o, 10, '3.00');
        $this->engine()->run();
        self::assertSame(20000, self::cost($o['sku']));
        // An invoice of the first delivery at £2 that the job reads only now, though it was made final before the second delivery (its
        // booking committed late): written as the job writes it, with its own time.
        $before = (string) self::$db->value("SELECT source_at FROM item_cost_event WHERE sku_id = ? AND kind = 'receipt' ORDER BY id LIMIT 1", [$o['sku']]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, document_id, document_line, units, on_hand, price_e4, booked_e4, old_cost_e4, "
            . "new_cost_e4) VALUES (?, UTC_TIMESTAMP(6), DATE_ADD(?, INTERVAL 1 MICROSECOND), 'invoice', 'doc:late:1', ?, 1, 10, 10, 20000, 10000, 20000, 30000)",
            [$o['sku'], $before, $g1->id]);
        self::$db->exec('UPDATE item_cost SET unit_cost_e4 = 30000 WHERE sku_id = ?', [$o['sku']]);
        $this->engine()->replayItem($o['sku']);
        self::assertSame(25000, self::cost($o['sku']), 'start, 10 at £1, the 10 to £2, 10 more at £3: £2.50');
        self::assertSame('replay', (string) self::$db->value('SELECT kind FROM item_cost_event WHERE sku_id = ? ORDER BY id DESC LIMIT 1', [$o['sku']]));
    }
}
