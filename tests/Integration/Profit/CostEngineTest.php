<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Profit\CostEngine;
use CW\Profit\ItemCosts;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;

/**
 * The buying price that follows the invoices (profit step 1; docs/decisions.md PR12-PR19), with the real receipts, invoices and credit
 * notes of CW: the start (ERPNext's Bin weighted by quantity, CW's average, the supplier's price, none), a delivery moving the average, an
 * invoice's landed price (its carriage spread by value) on the units still on hand, a price credit, its reversal, a delivery taken back, a
 * receipt of goods that were on their way at the invoice's landed price; the job run again changes nothing, run from an older mark it
 * skips what is done; the cost at a moment is read from the history; report_item and the value ledger are never touched.
 */
final class CostEngineTest extends ArrivalsTestCase
{
    private function engine(): CostEngine
    {
        // Three minutes ahead: what was just booked is past the job's lag, and the mark stays behind what the test books next.
        return new CostEngine(self::$db, static fn (): \DateTimeImmutable => Clock::now()->modify('+' . (CostEngine::LAG_SECONDS + 60) . ' seconds'));
    }

    private static function cost(int $sku): ?int
    {
        $v = self::$db->value('SELECT unit_cost_e4 FROM item_cost WHERE sku_id = ?', [$sku]);
        return $v === null ? null : (int) $v;
    }

    /** @return list<array{0: string, 1: ?int, 2: ?int}> the item's events: kind, on hand before, new price */
    private static function events(int $sku): array
    {
        return array_map(static fn (array $e): array => [(string) $e['kind'], $e['on_hand'] === null ? null : (int) $e['on_hand'],
            $e['new_cost_e4'] === null ? null : (int) $e['new_cost_e4']], self::$db->all('SELECT kind, on_hand, new_cost_e4 FROM item_cost_event WHERE sku_id = ? ORDER BY id', [$sku]));
    }

    /** The start an hour ago, from an empty Bin (every item that is there now, priced from CW's own figures). */
    private function start(): void
    {
        $plan = $this->engine()->plan("item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n", 'vapeandgo');
        $this->engine()->seed($plan['prices'], Clock::db(Clock::now()->modify('-1 hour')));
    }

    public function testTheStartPricesEveryItemFromTheBestSource(): void
    {
        $site = $this->site('vapeandgo', 'live');
        ['buyer' => $buyer, 'supplier' => $s] = $this->people('Start Ltd');
        $erp = self::makeSku('From ERPNext');
        $pack = self::makeSku('From ERPNext, a pack of 2');
        $avg = self::makeSku('CW average');
        $sup = self::makeSku('Supplier price');
        $none = self::makeSku('No price');
        $this->listing($site, '500', $erp);
        $this->listing($site, '501', $pack, 2);
        $this->listing($site, '502', $none);
        self::$db->exec('INSERT INTO report_item (sku_id, in_units, in_value) VALUES (?, 10, 15.000000)', [$avg]);
        $this->supplierItem($buyer, (int) $s['id'], $sup, 10, '5.0000');
        $bin = "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n"
            . "P500\tStores - VPG\t30.000000000\t1.200000000\t36.0\n"
            . "P500\tVPG 2 Main warehouse - VPG2\t10.000000000\t1.600000000\t16.0\n"
            . "P501\tStores - VPG\t0.000000000\t3.000000000\t0\n"
            . "P502\tStores - VPG\t5.000000000\t1.000000000\t5\n" // a placeholder rate (erp_opening.placeholder_costs): no price
            . "P999\tStores - VPG\t5.000000000\t2.000000000\t10\n"; // no CW item
        $plan = $this->engine()->plan($bin, 'vapeandgo');
        self::assertSame(['e4' => 13000, 'source' => 'erp_bin'], $plan['prices'][$erp], '30 at £1.20 and 10 at £1.60: £1.30');
        self::assertSame(['e4' => 15000, 'source' => 'erp_bin'], $plan['prices'][$pack], '£3.00 for a pack of 2, none in stock: £1.50 a unit');
        self::assertSame(['e4' => 15000, 'source' => 'cw_average'], $plan['prices'][$avg]);
        self::assertSame(['e4' => 5000, 'source' => 'supplier_price'], $plan['prices'][$sup], '£5.00 for 10');
        self::assertSame(['e4' => null, 'source' => 'none'], $plan['prices'][$none]);
        self::assertSame([5, 1], [$plan['bin_rows'], $plan['bin_unmatched']]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_cost'), 'a plan writes nothing');
        $start = '2026-11-16 00:00:00.000000';
        self::assertSame(count($plan['prices']), $this->engine()->seed($plan['prices'], $start));
        self::assertSame([['start', null, 13000]], self::events($erp));
        self::assertSame($start, (string) self::$db->value('SELECT mark_at FROM profit_job WHERE job = ?', [CostEngine::JOB]));
        $this->expectException(\RuntimeException::class);
        $this->engine()->seed($plan['prices'], $start);
    }

    public function testDeliveriesInvoicesAndCreditsMoveTheAverage(): void
    {
        $this->site('vapeandgo', 'live');
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Average Ltd');
        $sid = (int) $s['id'];
        $o = $this->approvedPoFor($buyer, $sid, 30, 1, '2.0000');
        $x = $o['sku'];
        $this->start();
        self::$db->exec('UPDATE item_cost SET unit_cost_e4 = 10000, start_source = \'erp_bin\' WHERE sku_id = ?', [$x]);
        self::$db->exec("UPDATE item_cost_event SET new_cost_e4 = 10000 WHERE sku_id = ? AND kind = 'start'", [$x]);
        $this->book('goods_in', $x, 10); // the stock on hand at the start, no cost of its own
        // A delivery of 10 at £2.00: (10 x 1.00 + 10 x 2.00) / 20 = £1.50.
        $g = $this->delivered($desk, $sid, [['sku_id' => $x, 'supplier_item_id' => (int) $o['si']['id'], 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '2.00',
            'po_line_no' => 1]], $o['doc']->id);
        $r = $this->engine()->run();
        self::assertSame(['receipt' => 1], $r['kinds']);
        self::assertSame(15000, self::cost($x));
        // Its invoice at £2.50 a unit plus £5.00 carriage (all on this line): landed £3.00 on the 10 units still here, booked at £2.00:
        // (1.50 x 20 + 1.00 x 10) / 20 = £2.00.
        $d = $this->invoices->createFromReceipt($desk, $g->id)['document'];
        $l = $this->invoices->lines($d->id);
        $d = $this->invoices->saveLines($desk, $d->id, $d->version, [\CW\Tests\Integration\Invoices\SupplierInvoicesTest::lineOf($l[0], ['pack_price' => '2.5000']),
            ['kind' => 'freight', 'amount' => '5.00', 'description' => 'Carriage']]);
        $inv = $this->postWithTotals($desk, $d, '30.00', '6.00');
        $this->engine()->run();
        self::assertSame(20000, self::cost($x));
        self::assertSame(['invoice', 20, 20000], self::events($x)[2]);
        // A price credit of 50p on 4 units: (2.00 x 20 - 0.50 x 4) / 20 = £1.90.
        $cn = $this->invoices->createDraft($desk, 'credit_note', $sid, ['credits_id' => $inv->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 4, '0.5000');
        $cn = $this->postWithTotals($desk, $cn, '2.00', '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(3)), 'invoice_date' => self::day('-1 day')]);
        $this->engine()->run();
        self::assertSame(19000, self::cost($x));
        $events = count(self::events($x));
        // Run again: nothing new. From an older mark: everything found again, nothing written twice.
        self::assertSame(0, $this->engine()->run()['events']);
        self::$db->exec('UPDATE profit_job SET mark_at = ? WHERE job = ?', ['2026-01-01 00:00:00', CostEngine::JOB]);
        self::assertSame(0, $this->engine()->run()['events'], 'restartable: what is done is skipped');
        self::assertCount($events, self::events($x));
        self::assertSame(19000, self::cost($x));
        // The credit note cancelled: back to £2.00.
        $this->invoices->reverse($desk, $cn->id, 'entered_in_error', null);
        $this->engine()->run();
        self::assertSame(20000, self::cost($x));
        // A second delivery of 10 at £4.00, then taken back: (2.00 x 20 + 4.00 x 10) / 30 = £2.6667, then the 10 out at £4.00: £2.00.
        $g2 = $this->delivered($desk, $sid, [['sku_id' => $x, 'supplier_item_id' => (int) $o['si']['id'], 'packs' => 10, 'units_per_pack' => 1, 'pack_price' => '4.00',
            'po_line_no' => 1]], $o['doc']->id);
        $this->engine()->run();
        self::assertSame(26667, self::cost($x));
        $this->docs->reverse($desk, $g2->id, 'entered_in_error', null);
        $this->engine()->run();
        self::assertSame(20001, self::cost($x), 'the 10 out at the £4.00 they came in at (the half penny of the average rounds)');
        self::assertSame(['start', 'receipt', 'invoice', 'credit_note', 'credit_note_reversal', 'receipt', 'receipt_reversal'], array_column(self::events($x), 0));
        // The price at a moment is read from the history (cw_cost from the day CW's own price starts).
        $at = (string) self::$db->value("SELECT at FROM item_cost_event WHERE sku_id = ? AND kind = 'invoice'", [$x]);
        self::assertSame([$x => ['e4' => 20000, 'source' => 'cw_cost']], (new ItemCosts(self::$db))->at([$x], $at));
        // Nothing of the stock side moved.
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM report_item WHERE sku_id = ?', [$x]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM stock_value_ledger'));
    }

    public function testGoodsThatWereOnTheirWayComeInAtTheInvoicesLandedPrice(): void
    {
        $site = $this->site('vapeandgo', 'live');
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('On The Way Ltd');
        $x = $this->invoiceOfOrder($desk, $buyer, (int) $s['id'], 10, 1, '4.0000');
        $this->listing($site, 'V1', $x['sku']);
        $d = $this->invoices->addCharge($desk, $x['doc']->id, $x['doc']->version, 'freight', '2.00', 'Carriage');
        $d = $this->invoices->saveHeader($desk, $d->id, $d->version, ['net_total' => '42.00', 'vat_total' => '0.00']);
        $this->start();
        $p = $this->invoices->submit($desk, $d->id, $d->version);
        $this->engine()->run();
        self::assertSame(['start', 'invoice'], array_column(self::events($x['sku']), 0), 'invoiced before its goods arrived: nothing on hand moves');
        self::assertSame(40000, self::cost($x['sku']), 'its start: the supplier\'s price');
        $g = $this->arrivals->receive($desk, $p->id);
        $this->invoice($desk, $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        $this->post($desk, $g->id);
        $this->engine()->run();
        self::assertSame(42000, self::cost($x['sku']), '£4.00 + 20p carriage a unit');
        self::assertSame($p->id, (int) self::$db->value("SELECT ref_document_id FROM item_cost_event WHERE sku_id = ? AND kind = 'receipt'", [$x['sku']]));
    }
}
