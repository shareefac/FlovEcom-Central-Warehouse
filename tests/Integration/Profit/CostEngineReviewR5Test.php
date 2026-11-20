<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Profit;

use CW\Clock;
use CW\Documents\Document;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Profit\CostEngine;
use CW\StockOps\StockOps;
use CW\Tests\Integration\Arrivals\ArrivalsTestCase;

/**
 * The Codex re-check of e5d2e5a (build/A/codex_r5a.txt), the buying price, each shown failing first: a new item's first delivery whose
 * booking committed after the item's start is folded on top of the start (1); a credit given before the goods came but read after their
 * receipt still comes off them (2); a cancelled credit counts only the receipts still standing (3); units beyond the invoice come in at the
 * receipt's own price, and the credit is spread over the units that came (4); a moment never cuts a stock operation in two (5); a cancelled
 * credit undoes what its receipt really took off, exactly, at any size (6, 7).
 */
final class CostEngineReviewR5Test extends ArrivalsTestCase
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
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [\CW\Tests\Integration\Invoices\SupplierInvoicesTest::lineOf($this->invoices->lines($d->id)[0],
            ['pack_price' => $price])]);
        return $this->postWithTotals($who['desk'], $d, null, '0.00', ['invoice_number' => 'R5-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    /** An invoice of lines of one item ($units each at $prices), no delivery named, made final: on their way. */
    private function onTheirWay(array $who, int $sku, int $units, string ...$prices): Document
    {
        $lines = array_map(static fn (string $p): array => ['kind' => 'goods', 'sku_id' => $sku, 'packs' => $units, 'units_per_pack' => 1, 'pack_price' => $p,
            'supplier_code' => null, 'purchase_unit' => 'each', 'entry' => 'manual', 'description' => null], $prices);
        $d = $this->invoices->createDraft($who['desk'], 'invoice', (int) $who['supplier']['id'], ['invoice_number' => 'WAY-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, $lines);
        $net = (string) self::$db->value('SELECT CAST(COALESCE(SUM(amount), 0) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$d->id]);
        $d = $this->invoices->saveHeader($who['desk'], $d->id, $d->version, ['net_total' => $net, 'vat_total' => '0.00']);
        return $this->invoices->submit($who['desk'], $d->id, $d->version);
    }

    /** The invoice's goods received (its lines as one receipt line of $units at $price when $units is given) and posted. */
    private function receive(array $who, Document $invoice, int $sku, ?int $units = null, string $price = '9.0000'): Document
    {
        $g = $this->arrivals->receive($who['desk'], $invoice->id);
        if ($units !== null) {
            $this->grns->saveDraft($who['desk'], $g->id, $this->docs->get($g->id)->version, [], [['sku_id' => $sku, 'supplier_code' => null, 'purchase_unit' => 'each',
                'units_per_pack' => 1, 'packs' => $units, 'pack_price' => $price, 'po_line_no' => null, 'entry' => 'manual']]);
        }
        $this->invoice($who['desk'], $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id);
        return $this->post($who['desk'], $g->id);
    }

    private function credit(array $who, Document $invoice, int $line, int $units, string $per, string $net): Document
    {
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $invoice->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, $line, $units, $per);
        return $this->postWithTotals($who['desk'], $cn, $net, '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    public function testALateFirstDeliveryOfANewItemIsFoldedOnTopOfItsStart(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Late First Ltd');
        $this->start();
        $o = $this->approvedPoFor($who['buyer'], (int) $who['supplier']['id'], 10, 1, '3.0000');
        $this->engine()->run(); // nothing delivered yet: it starts at the run's time (a minute ahead), at the supplier's £3.00
        self::assertSame(30000, self::cost($o['sku']));
        // Its first delivery, ten at £1.00, booked with a time BEFORE that start (its booking committed after the run had passed it).
        $g = $this->deliver($who, $o, 10, '1.00');
        self::assertLessThan((string) self::$db->value("SELECT source_at FROM item_cost_event WHERE sku_id = ? AND kind = 'start'", [$o['sku']]),
            (string) self::$db->value("SELECT created_at FROM stock_ledger WHERE document_id = ? AND movement_type = 'goods_in'", [$g->id]));
        $this->engine(60)->run();
        self::assertSame(10000, self::cost($o['sku']), 'ten at £1.00 on nothing on hand: £1.00, the start never resets it to £3.00');
        $this->invoiceAt($who, $g, '2.0000');
        $this->engine(120)->run();
        self::assertSame(20000, self::cost($o['sku']), 'the invoice corrects the ten to £2.00: £20.00, not £40.00');
    }

    public function testACreditGivenBeforeTheGoodsCameButReadAfterTheirReceiptStillComesOffThem(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Late Credit Ltd');
        $sku = self::makeSku('Credited before arrival, read after');
        $this->start();
        $p = $this->onTheirWay($who, $sku, 10, '4.0000');
        $this->engine()->run();
        $cn = $this->credit($who, $p, 1, 10, '1.0000', '10.00');
        usleep(2000);
        $this->receive($who, $p, $sku);
        $creditAt = (string) self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$cn->id]);
        self::assertLessThan((string) self::$db->value("SELECT MIN(created_at) FROM stock_ledger WHERE sku_id = ? AND movement_type = 'goods_in'", [$sku]), $creditAt);
        // The credit's booking committed late: the job's window starts just after it, so a run reads the receipt first (window) and the
        // credit after it (its look back).
        $mark = Clock::fromDb($creditAt)->modify('+1 usec +' . CostEngine::OVERLAP_SECONDS . ' seconds');
        self::$db->exec('UPDATE profit_job SET mark_at = ? WHERE job = ?', [Clock::db($mark), CostEngine::JOB]);
        $at = $mark->modify('+' . (CostEngine::LAG_SECONDS + 60) . ' seconds');
        $this->engine(0, static fn (): \DateTimeImmutable => $at)->run();
        self::assertSame(30000, self::cost($sku), 'the ten came after the credit: (10 x £4.00 - £10.00) / 10 = £3.00, whatever was read first');
    }

    public function testACancelledCreditCountsOnlyTheReceiptsStillStanding(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Received Twice Ltd');
        $sku = self::makeSku('Received, taken back, received again');
        $this->supplierItem($who['buyer'], (int) $who['supplier']['id'], $sku, 1, '4.0000');
        $this->book('adjustment', $sku, 10); // ten on the shelf at the start, at the supplier's £4.00
        $this->start();
        self::assertSame(40000, self::cost($sku));
        $p = $this->onTheirWay($who, $sku, 10, '4.0000');
        $cn = $this->credit($who, $p, 1, 10, '1.0000', '10.00');
        $this->engine()->run();
        $g = $this->receive($who, $p, $sku);
        $this->engine()->run();
        self::assertSame(35000, self::cost($sku), '10 at £4.00 and 10 at £3.00');
        $this->docs->reverse($who['desk'], $g->id, 'entered_in_error', null);
        $this->engine(30)->run();
        self::assertSame(40000, self::cost($sku), 'the receipt taken back: the ten at £3.00 leave');
        $this->receive($who, $p, $sku);
        $this->engine(60)->run();
        self::assertSame(35000, self::cost($sku), 'received again');
        $this->invoices->reverse($who['desk'], $cn->id, 'entered_in_error', null);
        $this->engine(90)->run();
        self::assertSame(40000, self::cost($sku), 'the credit cancelled once, on the receipt that stands: £80.00 / 20, not £90.00');
    }

    public function testUnitsBeyondTheInvoiceComeInAtTheReceiptsOwnPrice(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Over Delivery Ltd');
        $sku = self::makeSku('Thirty came of twenty billed');
        $this->start();
        $p = $this->onTheirWay($who, $sku, 10, '4.0000', '4.0000');
        $this->credit($who, $p, 2, 10, '1.0000', '10.00');
        $this->engine()->run();
        $this->receive($who, $p, $sku, 30, '4.0000');
        $this->engine()->run();
        self::assertSame(36667, self::cost($sku), '(20 x £4.00 - £10.00 + 10 x £4.00 beyond the invoice) / 30 = £110.00 / 30, not £3.50 on all 30 (£105.00)');
    }

    public function testAMomentNeverCutsAStockOperationInTwo(): void
    {
        $this->site('vapeandgo', 'live');
        $sku = self::makeSku('Moved between our rooms');
        $this->book('adjustment', $sku, 20);
        $shop = (int) self::$db->insert("INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, owner_entity, sort_order) VALUES ('SHOPR5', 'Shop room', 0, 1, 'own', NULL, 50)");
        $dir = sys_get_temp_dir() . '/cw_r5trf_' . bin2hex(random_bytes(5));
        mkdir($dir, 0700, true);
        try {
            $store = new FileStore(self::$db, new LocalFileStorage($dir));
            $ops = new StockOps(self::$db, new Documents(self::$db, DocumentHandlers::all(self::$db)), static fn (): FileStore => $store);
            $sc = $this->staffUser('stock_controller');
            $t = $ops->createDraft($sc, 'transfer', ['warehouse' => self::warehouseId('MAIN'), 'to_warehouse' => $shop]);
            $t = $ops->saveLines($sc, $t->id, $t->version, [['sku_id' => $sku, 'qty' => 10]]);
            self::assertSame('posted', $ops->post($sc, $t->id, $t->version)->status);
        } finally {
            exec('chmod -R u+w ' . escapeshellarg($dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dir));
        }
        $rows = self::$db->all("SELECT id, qty_delta, created_at FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand' AND document_id = ? ORDER BY id", [$sku, $t->id]);
        self::assertSame([-10, 10], array_map('intval', array_column($rows, 'qty_delta')));
        $out = (string) $rows[0]['created_at'];
        self::assertLessThan((string) $rows[1]['created_at'], $out, 'the transfer\'s two rows have their own times');
        // A moment between its two rows: the transfer is whole there (all twenty are ours), never ten.
        $engine = $this->engine();
        self::assertSame(20, $engine->onHandAt($sku, $out));
        $lowest = new \ReflectionMethod($engine, 'lowestOnHandSince');
        self::assertSame(20, $lowest->invoke($engine, $sku, Clock::db(Clock::fromDb($out)->modify('-1 usec')), $out), 'never ten between its rows');
        self::assertSame(20, $lowest->invoke($engine, $sku, $out, Clock::db(Clock::fromDb($out)->modify('+1 second'))), 'nor from between them on');
    }

    public function testACancelledCreditUndoesWhatItsReceiptReallyTookOff(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Penny Credit Ltd');
        $sku = self::makeSku('Two hundred, a penny off');
        $this->start();
        $p = $this->onTheirWay($who, $sku, 200, '1.0000');
        $cn = $this->credit($who, $p, 1, 1, '0.0100', '0.01');
        $this->engine()->run();
        $this->receive($who, $p, $sku);
        $this->engine()->run();
        self::assertSame(10000, self::cost($sku), '(200 x £1.00 - 1p) / 200 = £0.99995: £1.0000 (half away from zero)');
        $this->invoices->reverse($who['desk'], $cn->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(10000, self::cost($sku), 'the penny the receipt never took off is not added back: £200.00, not £200.02');
    }

    public function testAHugeCreditIsUndoneExactly(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Big Credit Ltd');
        $sku = self::makeSku('A hundred thousand');
        $this->start();
        $p = $this->onTheirWay($who, $sku, 100000, '4.0000');
        $cn = $this->credit($who, $p, 1, 100000, '1.0000', '100000.00');
        $this->engine()->run();
        $this->receive($who, $p, $sku);
        $this->engine()->run();
        self::assertSame(30000, self::cost($sku), '£400,000.00 less £100,000.00 over 100,000 units');
        $this->invoices->reverse($who['desk'], $cn->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(40000, self::cost($sku), 'back to £400,000.00: the undo is worked out exactly, never past an integer');
    }
}
