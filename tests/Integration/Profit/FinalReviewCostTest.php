<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Documents\Document;
use CW\Profit\CostEngine;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;
use CW\Tests\Integration\Invoices\SupplierInvoicesTest;

/**
 * The final review of 6d59eda (build/A/codex_final_astra_{a,b,c}.txt), the buying price, each shown failing first with the reviewers'
 * numbers: the opening price never holds a delivery the replay adds again (c-HIGH1, a-M5); a broken Bin export is refused row by row
 * (b-HIGH3); an invoice or a price credit of a delivery that sold out never reprices the stock that replaced it (c-HIGH2, a-M6).
 */
final class FinalReviewCostTest extends ArrivalsTestCase
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

    private const EMPTY_BIN = "item_code\twarehouse\tactual_qty\tvaluation_rate\tstock_value\n";

    private function start(string $ago = '-1 hour'): void
    {
        $at = Clock::db(Clock::now()->modify($ago));
        $plan = $this->engine()->plan(self::EMPTY_BIN, 'vapeandgo', $at);
        $this->engine()->seed($plan['prices'], $at);
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
        return $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'FR-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
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

    public function testTheOpeningPriceNeverHoldsADeliveryTheReplayAddsAgain(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Opening Replay Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        // 10 at £1 on hand at the start (no Bin price for the item); 10 more at £3 after it, before the seed runs.
        $this->deliver($who, $o, 10, '1.00');
        usleep(2000);
        $startAt = Clock::db(Clock::now());
        usleep(2000);
        $this->deliver($who, $o, 10, '3.00');
        // The reports' average today holds both deliveries: £2.
        self::$db->exec('INSERT INTO report_item (sku_id, in_units, in_value) VALUES (?, 20, 40.000000) AS n ON DUPLICATE KEY UPDATE in_units = n.in_units, in_value = n.in_value',
            [$o['sku']]);
        $engine = $this->engine();
        $plan = $engine->plan(self::EMPTY_BIN, 'vapeandgo', $startAt);
        self::assertSame(['e4' => 10000, 'source' => 'cw_average'], $plan['prices'][$o['sku']], 'the opening is what came in before the start: £1, never today\'s £2');
        $engine->seed($plan['prices'], $startAt);
        $this->engine(60)->run();
        self::assertSame(20000, self::cost($o['sku']), '10 at £1 and the replayed 10 at £3: £2.00, never £2.50');
        // Run again with the same inputs (a seed stopped half way): the same opening.
        self::assertSame($plan['prices'][$o['sku']], $this->engine(120)->plan(self::EMPTY_BIN, 'vapeandgo', $startAt)['prices'][$o['sku']]);
    }

    public function testABrokenBinExportIsRefusedRowByRow(): void
    {
        $bin = self::EMPTY_BIN
            . "P500\tStores - VPG\t\t1.200000000\t0\n"          // row 2: a blank quantity
            . "P501\tStores - VPG\t3.000000000\tabc\t0\n"       // row 3: a valuation that is not a number
            . "P502\tStores - VPG\t5.000000000\n"               // row 4: cut short (its valuation lost)
            . "P503\tStores - VPG\t5.000000000\t\t0\n";         // row 5: a blank valuation
        $plan = $this->engine()->plan($bin, 'vapeandgo');
        self::assertSame([[2, 'P500'], [3, 'P501'], [4, 'P502'], [5, 'P503']], array_map(static fn (array $b): array => [$b['row'], $b['item_code']], $plan['broken']),
            'every broken row named: never skipped without a word, never read as a number it is not');
        $plan = $this->engine()->plan($bin, 'vapeandgo', null, ['P500', 'row:4', 'P503']);
        self::assertSame([[3, 'P501']], array_map(static fn (array $b): array => [$b['row'], $b['item_code']], $plan['broken']));
        self::assertSame([2, 4, 5], array_column($plan['skipped'], 'row'), 'the rows named may be left out, and are listed');
    }

    public function testAnInvoiceOfADeliveryThatSoldOutNeverRepricesItsReplacement(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $this->grant($site, 'erp_sale');
        $who = $this->people('Sold Out Invoice Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->listing($site, 'VA', $o['sku']);
        $this->start();
        $first = $this->deliver($who, $o, 10, '1.00');
        $this->engine()->run();
        self::assertSame(10000, self::cost($o['sku']));
        $this->sell($site, 10);
        $this->deliver($who, $o, 10, '3.00');
        $this->engine(60)->run();
        self::assertSame(30000, self::cost($o['sku']), 'the first ten sold out; ten more at £3');
        $this->invoiceAt($who, $first, '2.0000');
        $this->engine(120)->run();
        self::assertSame(30000, self::cost($o['sku']), 'the invoice corrects the first ten, all sold: the replacement stays at £3 (never £4)');
        self::assertSame(0, (int) self::$db->value("SELECT units FROM item_cost_event WHERE sku_id = ? AND kind = 'invoice'", [$o['sku']]));
    }

    public function testAPriceCreditOfADeliveryThatSoldOutNeverRepricesItsReplacement(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $this->grant($site, 'erp_sale');
        $who = $this->people('Sold Out Credit Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->listing($site, 'VA', $o['sku']);
        $this->start();
        $first = $this->deliver($who, $o, 10, '1.00');
        $inv = $this->invoiceAt($who, $first, '1.0000');
        $this->engine()->run();
        self::assertSame(10000, self::cost($o['sku']));
        $this->sell($site, 10);
        $this->deliver($who, $o, 10, '4.00');
        $this->engine(60)->run();
        self::assertSame(40000, self::cost($o['sku']));
        $this->credit($who, $inv, 1, 10, '0.5000');
        $this->engine(120)->run();
        self::assertSame(40000, self::cost($o['sku']), '50p off each of the first ten, all sold: the replacement stays at £4 (never £3.50)');
    }

    public function testAnInvoiceReachesWhatIsLeftOfItsDelivery(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $this->grant($site, 'erp_sale');
        $who = $this->people('Part Sold Invoice Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->listing($site, 'VA', $o['sku']);
        $this->start();
        $first = $this->deliver($who, $o, 10, '1.00');
        $this->sell($site, 6);
        $this->deliver($who, $o, 10, '3.00');
        $this->engine()->run();
        // 4 of the first ten left at £1, then 10 at £3: (4 + 30) / 14.
        self::assertSame(24286, self::cost($o['sku']));
        $this->invoiceAt($who, $first, '2.0000');
        $this->engine(60)->run();
        // The four still here move from £1 to £2: (34 + 4) / 14.
        self::assertSame(27143, self::cost($o['sku']), 'only the four of its ten that can still be here, never all ten on hand');
    }

    /** The Codex check of c48683e: a credit of a delivery taken back reaches none of the stock that stays. */
    public function testAPriceCreditOfADeliveryTakenBackNeverRepricesTheStockThatStays(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Taken Back Credit Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->start();
        $this->deliver($who, $o, 10, '4.00');
        $second = $this->deliver($who, $o, 10, '2.00');
        $inv = $this->invoiceAt($who, $second, '2.0000');
        $this->engine()->run();
        self::assertSame(30000, self::cost($o['sku']));
        $this->docs->reverse($who['desk'], $second->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(40000, self::cost($o['sku']), 'the second ten taken back at £2: the first ten at £4');
        $this->credit($who, $inv, 1, 10, '0.5000');
        $this->engine(120)->run();
        self::assertSame(40000, self::cost($o['sku']), '50p off the ten taken back: the ten that stay keep £4 (never £3.50)');
    }

    /**
     * The Codex check of c48683e (MEDIUM 3, 4): the opening takes what was BOOKED before the start, by the replay's clock (a delivery booked
     * just before midnight and made final just after it is in the opening, never in neither); an item made after the start is not in it.
     */
    public function testTheOpeningUsesTheReplaysClockAndLeavesItemsMadeAfterTheStart(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Opening Clock Ltd');
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $this->deliver($who, $o, 10, '1.00');
        $second = $this->deliver($who, $o, 10, '3.00');
        usleep(20000);
        $startAt = Clock::db(Clock::now());
        usleep(20000);
        // The second delivery booked before the start, its value sequence written just after it.
        self::$db->exec('UPDATE stock_value_seq s JOIN stock_ledger l ON l.id = s.stock_ledger_id SET s.created_at = ? WHERE l.document_id = ?',
            [Clock::db(Clock::now()), $second->id]);
        $late = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 20, 1, '1.0000');
        $plan = $this->engine()->plan(self::EMPTY_BIN, 'vapeandgo', $startAt);
        self::assertSame(['e4' => 20000, 'source' => 'cw_average'], $plan['prices'][$o['sku']] ?? null, 'both deliveries booked before the start: £2, never £1');
        self::assertArrayNotHasKey($late['sku'], $plan['prices'], 'an item made after the start is started by the job before its first delivery');
        $this->engine()->seed($plan['prices'], $startAt);
        $this->engine(60)->run();
        self::assertSame(20000, self::cost($o['sku']), 'never the second delivery in neither the opening nor the replay');
    }

    /** The Codex check of 2b718e7 (MEDIUM 2): a CW item made after the start that matches an ERPNext Bin row keeps that price in the start. */
    public function testAnItemMadeAfterTheStartKeepsItsMatchedBinPrice(): void
    {
        $site = $this->site('vapeandgo', 'live');
        $startAt = Clock::db(Clock::now()->modify('-1 hour'));
        $mapped = self::makeSku('Mapped after the start');
        $this->listing($site, '600', $mapped);
        $plain = self::makeSku('Made after the start, no Bin row');
        $plan = $this->engine()->plan(self::EMPTY_BIN . "P600\tStores - VPG\t5.000000000\t4.000000000\t20\n", 'vapeandgo', $startAt);
        self::assertSame(['e4' => 40000, 'source' => 'erp_bin'], $plan['prices'][$mapped] ?? null, 'ERPNext held it at £4 at the start: never an unknown cost');
        self::assertArrayNotHasKey($plain, $plan['prices'], 'no Bin row: started by the job before its first delivery');
    }

    public function testABinRowWithNoItemCodeOrAFileCutShortIsRefused(): void
    {
        $bin = self::EMPTY_BIN . "\tStores - VPG\t5.000000000\t1.000000000\t5\n" . "P501\tStores - VPG\t5.000000000\t1.000000000\t5\n";
        $plan = $this->engine()->plan($bin, 'vapeandgo');
        self::assertSame([[2, '', 'has no item_code']], array_map(static fn (array $b): array => [$b['row'], $b['item_code'], $b['why']], $plan['broken']));
        $plan = $this->engine()->plan($bin, 'vapeandgo', null, ['row:2'], 3);
        self::assertSame([0], array_column($plan['broken'], 'row'), 'two rows where the export counted three: cut short');
        self::assertSame([], $this->engine()->plan($bin, 'vapeandgo', null, ['row:2'], 2)['broken']);
    }
}
