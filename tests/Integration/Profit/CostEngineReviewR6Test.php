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
 * The Codex check of 51417a3 (build/A/codex_r6.txt), the buying price, each shown failing first with Codex's own numbers: what the job
 * reads late gives exactly what reading it in time gives (a credit and its cancellation before the goods came (M1); a credit whose receipt
 * is taken back and made again (M2); a receipt in two portions (M3); the rounding of a late credit (L5)); several cancelled credits give
 * back the rounding once (L4); an operation longer than a few minutes is never cut in two by a moment (r5a 5).
 */
final class CostEngineReviewR6Test extends ArrivalsTestCase
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

    /** An invoice of $units units of an item at $price, no delivery named, made final: on their way. */
    private function onTheirWay(array $who, int $sku, int $units, string $price): Document
    {
        $d = $this->invoices->createDraft($who['desk'], 'invoice', (int) $who['supplier']['id'], ['invoice_number' => 'R6-' . bin2hex(random_bytes(4)),
            'invoice_date' => self::day('-1 day')]);
        $d = $this->invoices->saveLines($who['desk'], $d->id, $d->version, [['kind' => 'goods', 'sku_id' => $sku, 'packs' => $units, 'units_per_pack' => 1,
            'pack_price' => $price, 'supplier_code' => null, 'purchase_unit' => 'each', 'entry' => 'manual', 'description' => null]]);
        $net = (string) self::$db->value('SELECT CAST(COALESCE(SUM(amount), 0) AS DECIMAL(14,2)) FROM document_line WHERE document_id = ?', [$d->id]);
        $d = $this->invoices->saveHeader($who['desk'], $d->id, $d->version, ['net_total' => $net, 'vat_total' => '0.00']);
        return $this->invoices->submit($who['desk'], $d->id, $d->version);
    }

    /** The invoice's goods received and posted ($damaged of them set aside into VERIFY: a second portion). */
    private function receive(array $who, Document $invoice, int $damaged = 0): Document
    {
        $g = $this->arrivals->receive($who['desk'], $invoice->id);
        $this->invoice($who['desk'], $g->id);
        $this->bench($this->staffUser('goods_in'), $g->id, $damaged > 0 ? [1 => ['damaged_units' => (string) $damaged]] : []);
        return $this->post($who['desk'], $g->id);
    }

    private function credit(array $who, Document $invoice, int $units, string $per, string $net): Document
    {
        $cn = $this->invoices->createDraft($who['desk'], 'credit_note', (int) $who['supplier']['id'], ['credits_id' => $invoice->id]);
        $cn = $this->invoices->addCredit($who['desk'], $cn->id, $cn->version, 1, $units, $per);
        return $this->postWithTotals($who['desk'], $cn, $net, '0.00', ['invoice_number' => 'CN-' . bin2hex(random_bytes(4)), 'invoice_date' => self::day('-1 day')]);
    }

    private static function posted(Document $d): string
    {
        return (string) self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$d->id]);
    }

    /**
     * What was booked up to $t committed late: the job's window starts just after $t, so a run reads what came after it first (its window)
     * and what came before it afterwards (its look back). Returns the clock of that run.
     */
    private function readLate(string $t, int $ahead = 0): \DateTimeImmutable
    {
        $mark = Clock::fromDb($t)->modify('+1 usec +' . CostEngine::OVERLAP_SECONDS . ' seconds');
        self::$db->exec('UPDATE profit_job SET mark_at = ? WHERE job = ?', [Clock::db($mark), CostEngine::JOB]);
        $at = $mark->modify('+' . (CostEngine::LAG_SECONDS + 60 + $ahead) . ' seconds');
        $this->engine(0, static fn (): \DateTimeImmutable => $at)->run();
        return $at;
    }

    public function testACreditAndItsCancellationBeforeTheGoodsCameReadAfterTheirReceiptMoveNothing(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Cancelled Before Ltd');
        $sku = self::makeSku('Credited and cancelled before arrival');
        $this->start();
        $p = $this->onTheirWay($who, $sku, 10, '4.0000');
        $this->engine()->run();
        $cn = $this->credit($who, $p, 10, '1.0000', '10.00');
        usleep(2000);
        $rev = $this->invoices->reverse($who['desk'], $cn->id, 'entered_in_error', null);
        usleep(2000);
        $this->receive($who, $p);
        $this->readLate(self::posted($rev));
        self::assertSame(40000, self::cost($sku), 'the credit was cancelled before the ten came: £40.00, not £30.00');
    }

    public function testARecoveredCreditStaysWithItsGoodsWhenTheirReceiptIsTakenBackAndMadeAgain(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Made Again Ltd');
        $sku = self::makeSku('Credited, received, taken back, received again');
        $this->start();
        $p = $this->onTheirWay($who, $sku, 10, '4.0000');
        $this->engine()->run();
        $cn = $this->credit($who, $p, 10, '1.0000', '10.00');
        usleep(2000);
        $g = $this->receive($who, $p);
        $at = $this->readLate(self::posted($cn));
        self::assertSame(30000, self::cost($sku), '(10 x £4.00 - £10.00) / 10');
        $this->docs->reverse($who['desk'], $g->id, 'entered_in_error', null);
        $this->engine(0, static fn (): \DateTimeImmutable => $at->modify('+1 minute'))->run();
        $this->receive($who, $p);
        $this->engine(0, static fn (): \DateTimeImmutable => $at->modify('+2 minutes'))->run();
        self::assertSame(30000, self::cost($sku), 'received again while the credit stands: £30.00, not £40.00');
    }

    public function testACreditReadLateOnAReceiptInTwoPortionsGivesWhatReadingItInTimeGives(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Two Portions Ltd');
        $sku = self::makeSku('One on the shelf, two received in two portions');
        $this->supplierItem($who['buyer'], (int) $who['supplier']['id'], $sku, 1, '1.0001');
        $this->book('adjustment', $sku, 1); // one on the shelf at the start, at the supplier's £1.0001
        $this->start();
        self::assertSame(10001, self::cost($sku));
        $p = $this->onTheirWay($who, $sku, 2, '1.0000');
        $this->engine()->run();
        $cn = $this->credit($who, $p, 1, '0.0100', '0.01');
        usleep(2000);
        $this->receive($who, $p, 1);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM stock_ledger WHERE sku_id = ? AND movement_type = 'goods_in' AND bucket = 'on_hand'", [$sku]));
        $this->readLate(self::posted($cn));
        // In time: the receipt at (2 x £1.00 - 1p) / 2 = £0.9950; one in: (£1.0001 + £0.9950) / 2 = £0.99755 -> £0.9976; the second:
        // (2 x £0.9976 + £0.9950) / 3 = £0.99673 -> £0.9967.
        self::assertSame(9967, self::cost($sku), 'portion by portion, as reading it in time: £0.9967, not £0.9968');
    }

    public function testSeveralCancelledCreditsGiveBackTheRoundingOnce(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Two Pennies Ltd');
        $sku = self::makeSku('Four hundred, 2p and 1p off');
        $this->start();
        $p = $this->onTheirWay($who, $sku, 400, '1.0000');
        $two = $this->credit($who, $p, 1, '0.0200', '0.02');
        $one = $this->credit($who, $p, 1, '0.0100', '0.01');
        $this->engine()->run();
        $this->receive($who, $p);
        $this->engine()->run();
        self::assertSame(9999, self::cost($sku), '(400 x £1.00 - 3p) / 400 = £0.999925: £0.9999');
        $this->invoices->reverse($who['desk'], $two->id, 'entered_in_error', null);
        $this->engine(60)->run();
        self::assertSame(10000, self::cost($sku), 'the 2p cancelled: (400 x £1.00 - 1p) / 400 = £0.999975: £1.0000');
        $this->invoices->reverse($who['desk'], $one->id, 'entered_in_error', null);
        $this->engine(120)->run();
        self::assertSame(10000, self::cost($sku), 'the 1p cancelled too: £400.00, not £400.04');
    }

    public function testACreditReadLateIsRoundedAsReadingItInTime(): void
    {
        $this->site('vapeandgo', 'live');
        $who = $this->people('Late Penny Ltd');
        $sku = self::makeSku('A hundred at £0.9999, a hundred more');
        $this->supplierItem($who['buyer'], (int) $who['supplier']['id'], $sku, 1, '0.9999');
        $this->book('adjustment', $sku, 100);
        $this->start();
        self::assertSame(9999, self::cost($sku));
        $p = $this->onTheirWay($who, $sku, 100, '1.0000');
        $this->engine()->run();
        $cn = $this->credit($who, $p, 1, '0.0100', '0.01');
        usleep(2000);
        $this->receive($who, $p);
        $this->readLate(self::posted($cn));
        self::assertSame(9999, self::cost($sku), 'the receipt at (100 x £1.00 - 1p) / 100 = £0.9999, the 200 at £0.9999: £199.98, not £200.00');
    }

    public function testAnOperationLongerThanAFewMinutesIsNeverCutInTwo(): void
    {
        $this->site('vapeandgo', 'live');
        $sku = self::makeSku('Moved between our rooms, slowly');
        $this->book('adjustment', $sku, 20);
        $shop = (int) self::$db->insert("INSERT INTO warehouse (code, name, is_sellable, is_active, stock_owner, owner_entity, sort_order) VALUES ('SHOPR6', 'Shop room', 0, 1, 'own', NULL, 50)");
        $dir = sys_get_temp_dir() . '/cw_r6trf_' . bin2hex(random_bytes(5));
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
        $rows = self::$db->all("SELECT id, created_at FROM stock_ledger WHERE sku_id = ? AND bucket = 'on_hand' AND document_id = ? ORDER BY id", [$sku, $t->id]);
        // As if the transfer's transaction took six minutes: out at its time, in six minutes later, both made final (committed) at the end.
        $out = (string) $rows[0]['created_at'];
        $in = Clock::db(Clock::fromDb($out)->modify('+6 minutes'));
        self::$db->exec('UPDATE stock_ledger SET created_at = ? WHERE id = ?', [$in, (int) $rows[1]['id']]);
        self::$db->exec('UPDATE stock_value_seq SET created_at = ? WHERE stock_ledger_id IN (?, ?)', [$in, (int) $rows[0]['id'], (int) $rows[1]['id']]);
        $between = Clock::db(Clock::fromDb($out)->modify('+30 seconds'));
        $engine = $this->engine();
        self::assertSame(20, $engine->onHandAt($sku, $between), 'between its two rows the transfer is whole: all twenty are ours');
        $lowest = new \ReflectionMethod($engine, 'lowestOnHandSince');
        self::assertSame(20, $lowest->invoke($engine, $sku, Clock::db(Clock::fromDb($out)->modify('-1 usec')), $between), 'never ten');
        self::assertSame(20, $lowest->invoke($engine, $sku, $between, Clock::db(Clock::fromDb($in)->modify('+1 minute'))), 'nor from between them on');
    }
}
