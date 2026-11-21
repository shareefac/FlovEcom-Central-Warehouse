<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Caller;
use CW\Clock;
use CW\Documents\Document;
use CW\Profit\CostEngine;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;
use CW\Tests\Integration\Invoices\SupplierInvoicesTest;

/**
 * The third final review of bc7e248 (build/A/codex_final3_astra_{a,b,c}.txt; final2/FIX_BRIEF_A4.md H1, H2; docs/decisions.md PR154), the
 * buying price, each shown failing first on bc7e248: one unit on the shelf takes a later invoice or credit price at most once ACROSS all the
 * deliveries of its item, the oldest sold first (a-HIGH1: two deliveries of 50 at £1, 50 sold, each invoiced at £2: £2, never £3; the
 * owner's rule of question 6: 100 at £2, 40 sold, invoiced at £2.20: only the 60 on the shelf move, in one delivery or two); a named delivery
 * line that booked nothing (all short) moves none, by its invoice, its credit or a history written again (c-M1).
 */
final class FinalReview3CostTest extends ArrivalsTestCase
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

    private function start(): void
    {
        $at = Clock::db(Clock::now()->modify('-1 hour'));
        $plan = $this->engine()->plan(self::EMPTY_BIN, 'vapeandgo', $at);
        $this->engine()->seed($plan['prices'], $at);
    }

    /** @param array<string, mixed> $who */
    private function deliver(array $who, array $o, int $packs, string $price, array $bench = []): Document
    {
        return $this->delivered($who['desk'], (int) $who['supplier']['id'], [['sku_id' => $o['sku'], 'supplier_item_id' => (int) $o['si']['id'], 'packs' => $packs,
            'units_per_pack' => 1, 'pack_price' => $price, 'po_line_no' => 1]], $o['doc']->id, $bench);
    }

    /** @param array<string, mixed> $who */
    private function invoiceOf(array $who, int $grnId, array $change): Document
    {
        $d = $this->invoices->createFromReceipt($who['desk'], $grnId)['document'];
        $l = $this->invoices->lines($d->id);
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [SupplierInvoicesTest::lineOf($l[0], $change)]);
        return $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'F3-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    /** @param array<string, mixed> $who */
    private function credit(array $who, Document $invoice, int $line, int $units, string $per): Document
    {
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $invoice->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, $line, $units, $per);
        return $this->postWithTotals($who['desk'], $cn, number_format($units * (float) $per, 2, '.', ''), '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
    }

    private function sell(Caller $site, string $variant, int $qty): void
    {
        $this->ok($this->moves->record($site, ['type' => 'erp_sale', 'doc_ref' => 'SINV-' . bin2hex(random_bytes(3)), 'lines' => [['variant_id' => $variant, 'qty' => $qty,
            'line_index' => 0]]], 'sale:' . bin2hex(random_bytes(4))));
    }

    /**
     * a-HIGH1: 50 in at £1, 50 more at £1, 50 sold (the first delivery's, the oldest), each delivery invoiced at £2 on its own: the 50 left
     * are the second delivery's: £2 (it said £3: each delivery found 50 of its own still here, the same 50 moved twice). The same in a history
     * written again, and in the other order of the invoices.
     */
    public function testTwoDeliveriesOfAnItemInvoicedOneByOneMoveEachUnitOnce(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $this->grant($site, 'erp_sale');
        $who = $this->people('Two Deliveries Ltd');
        foreach (['first invoice first' => false, 'second invoice first' => true] as $what => $reverse) {
            $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 100, 1, '1.0000');
            $variant = (string) random_int(91_000_000, 91_999_999);
            $this->listing($site, $variant, $o['sku']);
            if (!$reverse) {
                $this->start();
            }
            $a = $this->deliver($who, $o, 50, '1.00');
            $b = $this->deliver($who, $o, 50, '1.00');
            $this->sell($site, $variant, 50);
            foreach ($reverse ? [$b, $a] : [$a, $b] as $g) {
                $this->invoiceOf($who, $g->id, ['pack_price' => '2.0000']);
            }
            $this->engine()->run();
            self::assertSame(20000, self::cost($o['sku']), "{$what}: the 50 left are the second delivery's, now £2 (never £3)");
            self::assertSame(20000, $this->engine(60)->rebuild($o['sku']), "{$what}: the item's history written again: the same");
        }
    }

    /**
     * The owner's rule (question 6, OWNER_DECISIONS.md: "100 at £2, 40 sold, invoice £2.20 -> the 60 on the shelf move, the 40 sold keep
     * £2.00"): in one delivery of 100 (as it was), and in two deliveries of 50 each invoiced at £2.20: only the 60 move, £2.20 (it said £2.33:
     * the first delivery's 10 left and the second's 50 counted as 50 + 50).
     */
    public function testTheOwnersRuleOnlyTheUnitsOnTheShelfMoveInOneDeliveryOrTwo(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $this->grant($site, 'erp_sale');
        $who = $this->people('Owners Rule Ltd');
        $one = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 100, 1, '2.0000');
        $two = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 100, 1, '2.0000');
        $this->listing($site, '92000001', $one['sku']);
        $this->listing($site, '92000002', $two['sku']);
        $this->start();
        $g = $this->deliver($who, $one, 100, '2.00');
        $a = $this->deliver($who, $two, 50, '2.00');
        $b = $this->deliver($who, $two, 50, '2.00');
        $this->sell($site, '92000001', 40);
        $this->sell($site, '92000002', 40);
        $this->invoiceOf($who, $g->id, ['pack_price' => '2.2000']);
        $this->invoiceOf($who, $a->id, ['pack_price' => '2.2000']);
        $this->invoiceOf($who, $b->id, ['pack_price' => '2.2000']);
        $this->engine()->run();
        self::assertSame(22000, self::cost($one['sku']), 'one delivery: the 60 on the shelf at £2.20');
        self::assertSame(22000, self::cost($two['sku']), 'two deliveries: the first one\'s 10 and the second one\'s 50 at £2.20 (never £2.33)');
    }

    /**
     * a-HIGH1, the credit path: two deliveries of 50 at £2, each invoiced at £2, 50 sold; a 50p credit of each invoice's 50: only the 50 left
     * (the second delivery's) are credited: £1.50 (it said £1.00: the first credit reached 50 that were already sold).
     */
    public function testCreditsOfTwoDeliveriesReachEachUnitOnce(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $this->grant($site, 'erp_sale');
        $who = $this->people('Two Credits Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 100, 1, '2.0000');
        $this->listing($site, '93000001', $o['sku']);
        $this->start();
        $a = $this->deliver($who, $o, 50, '2.00');
        $b = $this->deliver($who, $o, 50, '2.00');
        $ia = $this->invoiceOf($who, $a->id, ['pack_price' => '2.0000']);
        $ib = $this->invoiceOf($who, $b->id, ['pack_price' => '2.0000']);
        $this->sell($site, '93000001', 50);
        $this->engine()->run();
        self::assertSame(20000, self::cost($o['sku']));
        $this->credit($who, $ia, 1, 50, '0.5000');
        $this->credit($who, $ib, 1, 50, '0.5000');
        $this->engine(60)->run();
        self::assertSame(15000, self::cost($o['sku']), 'only the second delivery\'s 50 are here: 50p off them (never £1 off)');
        self::assertSame(15000, $this->engine(120)->rebuild($o['sku']), 'the same in a history written again');
    }

    /**
     * c-M1: ten at £4 stay; a delivery line of ten at £2 comes all short (it books nothing); an invoice of that line at £3 moves none of the
     * ten that are here (it moved them: £5); a credit of it reaches none either, nor a history written again.
     */
    public function testADeliveryLineThatBookedNothingMovesNoStock(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('All Short Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '4.0000');
        $this->start();
        $this->deliver($who, $o, 10, '4.00');
        $short = $this->deliver($who, $o, 10, '2.00', [1 => ['short_units' => '10']]);
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE document_id = ? AND movement_type = 'goods_in'", [$short->id]), 'all short: nothing booked');
        $inv = $this->invoices->createDraft($who['desk'], 'invoice', (int) $who['supplier']['id'], ['invoice_number' => 'F3S-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
        $inv = $this->invoices->saveLines($who['desk'], $inv->id, $inv->version, [['kind' => 'goods', 'sku_id' => $o['sku'], 'packs' => 10, 'units_per_pack' => 1,
            'pack_price' => '3.0000', 'grn_document_id' => $short->id, 'grn_line_no' => 1, 'po_document_id' => $o['doc']->id, 'po_line_no' => 1, 'entry' => 'manual']]);
        $inv = $this->postWithTotals($who['desk'], $inv);
        $this->engine()->run();
        self::assertSame(40000, self::cost($o['sku']), 'the ten here stay £4: the invoiced line brought none of them');
        $this->credit($who, $inv, 1, 10, '0.5000');
        $this->engine(60)->run();
        self::assertSame(40000, self::cost($o['sku']), 'its credit reaches none either');
        self::assertSame(40000, $this->engine(120)->rebuild($o['sku']), 'nor the history written again');
    }
}
