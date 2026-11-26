<?php

declare(strict_types=1);

namespace CW\Tests\Integration\OwnItems;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Expenses\ExpenseViews;
use CW\Invoices\SupplierInvoiceHandler;
use CW\Items\ItemOwnerRefresh;
use CW\Items\ItemOwners;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;
use CW\Profit\RecostSales;
use CW\Reorder\ReorderLines;
use CW\Reorder\ReorderList;
use CW\Reports\ReportViews;
use CW\Tests\Integration\Invoices\InvoicesTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1153, the Codex review of bd9104d (round 1), the code findings, each red on bd9104d first:
 *  H1  a refund written by the release before (own_refund_ex stale) is put right by the refresh
 *  H4  recost --items never changes an unlisted line of a mixed order (its stored cost stays, also a pending one)
 *  M6  the refresh changes no money: it never replays the snapshot (a unit restocked since keeps its fixed cost)
 *  M7  one order's page: ours and the shop's own apart (sales, refund share, cost, profit)
 *  M8  a re-send of the shop's own goods costs the shop, never our "with re-sends"
 *  M9  a bill's owner is read at its posting time
 *  M10 a credit of a line of the shop's own bill is the shop's even after the mark ended; the kit's bill step takes its linked credits along
 *  M11 a snapshot reads the marks under the items' rows; a mark takes its time under the item's row
 *  M12 a purchase order's approval reads the marks under the items' rows
 *  M13 the order refund split never gives an owner a negative share; a cancelled line is no owner
 *  M16 the kit's bill step checks the exact goods lines against the pinned list
 *  M17 a past day's own stock is valued at the price in force then
 *  M18 the stored reorder lists never offer an item that is a shop's own now
 *  M20 the shop's own items with no price are listed apart and its own profit is not complete while one has no price
 */
final class OwnItemsReviewR1Test extends InvoicesTestCase
{
    private const DAY = '2026-11-19';
    private string $realFrom = '';
    private const PAID = '2026-11-19T10:00:00Z';
    private Caller $vbs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vbs = $this->site('vapebig', 'live');
        // Vape Big's first real profit day moved for these tests, put back exactly as it was after each (the K1-K4 history checks of later
        // classes read it: 0079 seeds "2026-12-01")
        $this->realFrom = (string) self::$db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = 'profit.real_from_vapebig'");
        self::$db->exec("UPDATE app_setting SET value_json = JSON_QUOTE('2026-11-01') WHERE setting_key = 'profit.real_from_vapebig'");
    }

    protected function tearDown(): void
    {
        self::$db->exec("UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = 'profit.real_from_vapebig'", [$this->realFrom]);
        parent::tearDown();
    }

    private static function price(int $sku, ?int $e4, string $at = '2026-11-16 00:00:00', string $mark = '2030-01-01'): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, 'start', ?, ?)",
            [$sku, $at, $at, 'start:' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', ?) AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at", [$mark]);
    }

    private static function code(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }

    private static function markOwn(int $sku, ?string $from = '2026-11-16 00:00:00'): void
    {
        (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), self::code($sku), 'Vape Big\'s own goods', $from);
    }

    /** @return array<string, mixed> */
    private static function sl(string $id, string $variant, int $price, array $over = []): array
    {
        return $over + ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    private function store(string $ref, array $lines, array $over = []): void
    {
        (new OrderSnapshots(self::$db))->store((int) $this->vbs->channelId, $ref, $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VB' . $ref, 'status' => 'completed',
            'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => self::PAID, 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '1', 'method' => 'Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []], 'api');
    }

    /** @return array{0: int, 1: int} Hayati (3.75) on Vape Big, ours (2.00) on Vape Big */
    private function two(): array
    {
        $h = $this->item('legacy', 0, 'Hayati 4000 R1');
        $o = $this->item('legacy', 0, 'Our liquid R1');
        self::price($h, 37500);
        self::price($o, 20000);
        $this->listing($this->vbs, '40101', $h);
        $this->listing($this->vbs, '40103', $o);
        return [$h, $o];
    }

    // ---- money ---------------------------------------------------------------------------------------------------------------------

    public function testH1ARefundWrittenByTheReleaseBeforeIsPutRightByTheRefresh(): void
    {
        [$h, $o] = $this->two();
        self::markOwn($h);
        $this->store('9501', [self::sl('8511', '40101', 599), self::sl('8512', '40103', 1200)]);
        // the release before records an order-level refund: the whole refund moves, own_refund_ex stays as it was (0)
        self::$db->exec("UPDATE sale_order SET order_refund_inc = 300, order_refund_ex = 250, sale_ex = sale_ex - 250 WHERE order_ref = '9501'");
        ProfitDays::rebuild(self::$db, (int) $this->vbs->channelId, self::DAY);
        $r = (new ItemOwnerRefresh(self::$db))->run([$h], '2026-11-01', self::DAY, null, true);
        self::assertSame(1, $r['refreshed'], 'a stale own refund share is a difference the refresh puts right');
        // own share: 250 x 599 / 1799 = 83.2 -> 83
        self::assertSame(83, (int) self::$db->value("SELECT own_refund_ex FROM sale_order WHERE order_ref = '9501'"));
        self::assertSame([], ProfitDays::differences(self::$db, (int) $this->vbs->channelId, self::DAY));
    }

    public function testH4ReCostingTheListedItemsNeverChangesAnUnlistedLine(): void
    {
        [$h, $o] = $this->two();
        self::$db->exec("UPDATE profit_job SET mark_at = '2026-11-19 09:00:00' WHERE job = 'item_cost'"); // the cost job has not reached the sale yet
        $this->store('9502', [self::sl('8521', '40101', 599), self::sl('8522', '40103', 1200)]);
        // the listed line's cost fixed (as the cost job fixes it: through the money service, its order and day following); the other line still waits
        (new OrderSnapshots(self::$db))->recompute((int) $this->vbs->channelId, '9502', ['8521' => ['e4' => 36250, 'source' => 'cw_cost', 'by' => 'sale']], true);
        self::assertSame(363, (int) self::$db->value("SELECT cost FROM sale_line WHERE line_id = '8521'"));
        self::$db->exec("UPDATE profit_job SET mark_at = '2030-01-01' WHERE job = 'item_cost'"); // ... and now it has: the unlisted line could be fixed
        $before = self::$db->one("SELECT cost_source, unit_cost_e4, cost, costed_by, cost_at FROM sale_line WHERE line_id = '8522'");
        self::assertNull($before['cost_source']);
        $r = (new RecostSales(self::$db))->run('2026-11-01', self::DAY, ['none', 'cw_cost'], 'vapebig', true, [$h]);
        self::assertSame(1, $r['lines']);
        self::assertSame($before, self::$db->one("SELECT cost_source, unit_cost_e4, cost, costed_by, cost_at FROM sale_line WHERE line_id = '8522'"),
            'the unlisted pending line keeps exactly what was stored');
        self::assertSame(375, (int) self::$db->value("SELECT cost FROM sale_line WHERE line_id = '8521'"));
        self::assertSame([], ProfitDays::differences(self::$db, (int) $this->vbs->channelId, self::DAY));
    }

    public function testM6TheRefreshNeverReplaysTheSnapshotSoARestockedUnitKeepsItsCost(): void
    {
        [$h] = $this->two();
        $this->ok($this->commit($this->vbs, '9503', [self::line('40101', '8531')]));
        $this->ok($this->ship($this->vbs, '9503', ['8531']));
        $this->store('9503', [self::sl('8531', '40101', 599)]);
        self::assertSame(375, (int) self::$db->value("SELECT cost FROM sale_line WHERE line_id = '8531'"));
        $this->ok($this->res->returnUnits($this->vbs, '9503', ['8531'], $this->key('ret'))); // a replay would now see it restocked (cost 0)
        self::markOwn($h);
        $r = (new ItemOwnerRefresh(self::$db))->run([$h], '2026-11-01', self::DAY, null, true);
        self::assertSame(1, $r['refreshed']);
        $l = self::$db->one("SELECT item_owner, cost, restocked FROM sale_line WHERE line_id = '8531'");
        self::assertSame([(int) $this->vbs->channelId, 375, 0], [(int) $l['item_owner'], (int) $l['cost'], (int) $l['restocked']], 'only the owner moved');
        self::assertSame(375, (int) self::$db->value("SELECT own_cost FROM sale_order WHERE order_ref = '9503'"));
    }

    public function testM13TheRefundSplitNeverGivesAnOwnerANegativeShareAndACancelledLineIsNoOwner(): void
    {
        [$h, $o] = $this->two();
        self::markOwn($h);
        $this->store('9504', [self::sl('8541', '40101', 120), self::sl('8542', '40103', -12)], ['order_refund_inc' => 60]);
        $r = self::$db->one("SELECT order_refund_ex, own_refund_ex FROM sale_order WHERE order_ref = '9504'");
        self::assertSame((int) $r['order_refund_ex'], (int) $r['own_refund_ex'], 'the only positive line takes the whole refund; ours 0, never below');
        $this->store('9505', [self::sl('8551', '40101', 599), self::sl('8552', '40103', 1200, ['cancelled' => true])], ['order_refund_inc' => 599]);
        $r = self::$db->one("SELECT order_refund_ex, own_refund_ex FROM sale_order WHERE order_ref = '9505'");
        self::assertSame((int) $r['order_refund_ex'], (int) $r['own_refund_ex'], 'a cancelled line of ours is no owner: one owner\'s order');
    }

    public function testM7AndM8OneOrderShowsOursAndTheShopsOwnApartAndAnOwnReSendNeverCostsUs(): void
    {
        [$h, $o] = $this->two();
        self::markOwn($h);
        $this->store('9506', [self::sl('8561', '40101', 599)]);
        $d = (new ProfitViews(self::$db))->order((int) $this->vbs->channelId, '9506');
        self::assertSame([0, 0, 499, 375, 124], [$d['split']['ours']['goods'], $d['split']['ours']['profit'], $d['split']['own']['goods'], $d['split']['own']['cost'],
            $d['split']['own']['profit']]);
        // an order of ours, re-sent with the shop's own goods: our "with re-sends" never pays for them
        $this->store('9507', [self::sl('8571', '40103', 1200)]);
        $this->store('9508', [self::sl('8581', '40101', 599)], ['parent_ref' => '9507', 'order_no' => 'VB9508R']);
        $rows = array_column((new ProfitViews(self::$db))->orders(self::DAY, self::DAY, null, '', false)['rows'], null, 'ref');
        self::assertSame(800, $rows['9507']['with_resends'], 'ours: 1000 - 200, the re-sent own goods are the shop\'s');
        self::assertSame(375, $rows['9507']['shop_own_resend_cost']);
        $d = (new ProfitViews(self::$db))->order((int) $this->vbs->channelId, '9507');
        self::assertSame([800, 375], [$d['split']['ours']['with_resends'], $d['split']['own']['resend_cost']]);
    }

    public function testM20TheOwnItemsWithNoPriceAreApartAndTheOwnProfitIsNotCompleteWhileOneHasNoPrice(): void
    {
        [$h, $o] = $this->two();
        $n = $this->item('legacy', 0, 'Hayati 4000 no price R1');
        $this->listing($this->vbs, '40104', $n);
        self::markOwn($h);
        self::markOwn($n);
        // 200 priced units and ONE with no price: inside ours' 0.5% rule, yet the shop's own profit is not known (the lead: any missing cost)
        $lines = array_map(static fn (int $i): array => self::sl((string) (86000 + $i), '40101', 599), range(0, 199));
        $this->store('9509', [...$lines, self::sl('8592', '40104', 599)]);
        $real = (new ExpenseViews(self::$db))->realProfit(self::DAY, self::DAY, (int) $this->vbs->channelId, false);
        self::assertTrue($real[0]['own']['incomplete'], 'one own unit of 201 has no price: the own profit is not known');
        self::assertSame([$n], array_column((new ProfitViews(self::$db))->noCost(self::DAY, self::DAY, null, true), 'sku_id'));
    }

    public function testM17APastDaysOwnStockIsValuedAtThePriceInForceThen(): void
    {
        [$h] = $this->two();
        $this->now = new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC'));
        $this->book('goods_in', $h, 10);
        self::markOwn($h);
        self::price($h, 36250, '2026-11-27 00:00:00'); // a later receipt lowers the price
        $r = new ReportViews(self::$db);
        $at = $r->at('2026-11-25 23:59:59.999999');
        $own = $at->bounded(static fn (): array => $at->valueFigures())['own'];
        self::assertSame('37.50', $own[0]['value'], '10 units at the 3.75 in force on 25 Nov, not today\'s 3.625');
    }

    public function testM18TheStoredReorderListsNeverOfferAnItemThatIsAShopsOwnNow(): void
    {
        [$h, $o] = $this->two();
        foreach ([$h, $o] as $sku) {
            self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 50, 'system:test')", [$sku]);
        }
        (new \CW\Reorder\ReorderRefresh(self::$db, $this->pos))->items([$h, $o]);
        self::assertSame(1, (int) self::$db->value('SELECT need FROM reorder_line WHERE sku_id = ?', [$h]), 'stored "to buy" before the mark');
        // marked inside a transaction (the post-commit refresh is skipped, as when it times out): the stored line still says "to buy"
        self::$db->transaction(fn () => self::markOwn($h, null));
        $lines = new ReorderLines(self::$db);
        self::assertNotContains($h, $lines->ids(ReorderList::filters(['show' => 'need'])));
        self::assertNotContains($h, $lines->ids(ReorderList::filters(['show' => 'need', 'buy_for' => '14'])));
        self::assertContains($o, $lines->ids(ReorderList::filters(['show' => 'need'])));
    }

    // ---- locks ---------------------------------------------------------------------------------------------------------------------

    /** Runs $fn while a second connection holds $sku's row FOR UPDATE; returns the MySQL error $fn met (1205 = it waited for the row). */
    private static function underItemLock(int $sku, callable $fn): ?int
    {
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one('SELECT id FROM sku WHERE id = ? FOR UPDATE', [$sku]);
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $fn();
            return null;
        } catch (\PDOException $e) {
            return Db::driverCode($e);
        } catch (CwException $e) {
            return ($e->getPrevious() instanceof \PDOException) ? Db::driverCode($e->getPrevious()) : -1;
        } finally {
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
            $other->pdo()->rollBack();
        }
    }

    public function testM11ASnapshotReadsTheMarksUnderTheItemsRowsAndAMarkTakesItsTimeUnderTheRow(): void
    {
        [$h] = $this->two();
        self::assertSame(1205, self::underItemLock($h, fn () => $this->store('9510', [self::sl('8601', '40101', 599)])),
            'a snapshot waits for a mark being written on its item');
        $inside = null;
        $owners = new ItemOwners(self::$db, static function () use (&$inside): \DateTimeImmutable {
            $inside ??= self::$db->inTransaction();
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        });
        $owners->add(Caller::system('own_items_kit'), self::code($h), 'from now');
        self::assertTrue($inside, 'the mark\'s time is read inside its transaction, under the item\'s row');
    }

    public function testM12APurchaseOrdersApprovalReadsTheMarksUnderTheItemsRows(): void
    {
        ['buyer' => $buyer, 'supplier' => $s] = $this->people('R1 Orders');
        $sku = self::makeSku('R1 order item');
        $si = $this->supplierItem($buyer, (int) $s['id'], $sku, 10, '30.0000', ['supplier_code' => 'R1-1']);
        $po = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $si['id'], 'packs' => 1]]);
        self::assertSame(1205, self::underItemLock($sku, fn () => $this->pos->approve($buyer, $po->id, $po->version)));
    }

    // ---- bills ---------------------------------------------------------------------------------------------------------------------

    public function testM9ABillsOwnerIsReadAtItsPostingTime(): void
    {
        $sku = self::makeSku('R1 bill item');
        self::markOwn($sku, '2026-11-20 00:00:00');
        $skus = [['sku_id' => $sku]];
        self::assertNull(SupplierInvoiceHandler::ownerAt(self::$db, $skus, '2026-11-19 12:00:00.000000'), 'before its period: ours');
        self::assertSame((int) $this->vbs->channelId, SupplierInvoiceHandler::ownerAt(self::$db, $skus, '2026-11-21 12:00:00.000000'));
    }

    public function testM10ACreditOfTheShopsOwnBillStaysTheShopsAndTheKitsBillStepTakesItsCreditsAlong(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('R1 Credits');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 2, 6, '12.0000', 2);
        self::markOwn($x['sku'], null);
        $own = $this->invoiceDelivery($desk, $x['grn']->id, '24.00', '4.80');
        sleep(1);
        (new ItemOwners(self::$db))->remove(Caller::system('own_items_kit'), self::code($x['sku']), 'ours again');
        $cn = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['invoice_number' => 'R1-CN-1', 'invoice_date' => gmdate('Y-m-d', time() - 86400),
            'credits_id' => $own->id]);
        $cn = $this->invoices->addCredit($desk, $cn->id, $cn->version, 1, 6);
        $cn = $this->postWithTotals($desk, $cn, '12.00', '2.40');
        self::assertSame((int) $this->vbs->channelId, (int) self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$cn->id]),
            'a credit of a line of the shop\'s own bill is the shop\'s');
        // a bill made final before the marks, credited, then marked by the kit: its credit goes with it
        $y = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 2, 6, '12.0000', 2);
        $bill = $this->invoiceDelivery($desk, $y['grn']->id, '24.00', '4.80');
        $cn2 = $this->invoices->createDraft($desk, 'credit_note', (int) $s['id'], ['invoice_number' => 'R1-CN-2', 'invoice_date' => gmdate('Y-m-d', time() - 86400),
            'credits_id' => $bill->id]);
        $cn2 = $this->postWithTotals($desk, $this->invoices->addCredit($desk, $cn2->id, $cn2->version, 1, 6), '12.00', '2.40');
        self::markOwn($y['sku'], (string) self::$db->value("SELECT DATE_FORMAT(posted_at - INTERVAL 1 DAY, '%Y-%m-%d %H:%i:%s') FROM document WHERE id = ?", [$bill->id]));
        (new ItemOwners(self::$db))->markBill(Caller::system('own_items_kit'), (string) $bill->number, true);
        self::assertSame((int) $this->vbs->channelId, (int) self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$cn2->id]));
    }

    public function testM16TheKitsBillStepChecksTheExactGoodsLinesAgainstThePinnedList(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('R1 Exact');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 1, 10, '36.2500', 1);
        $bill = $this->invoiceDelivery($desk, $x['grn']->id, '36.25', '7.25');
        self::markOwn($x['sku'], (string) self::$db->value("SELECT DATE_FORMAT(posted_at - INTERVAL 1 DAY, '%Y-%m-%d %H:%i:%s') FROM document WHERE id = ?", [$bill->id]));
        $owners = new ItemOwners(self::$db);
        foreach ([[2, [$x['sku']]], [1, [$x['sku'] + 1000]]] as [$lines, $allowed]) {
            try {
                $owners->markBill(Caller::system('own_items_kit'), (string) $bill->number, true, $allowed, $lines);
                self::fail('refused: not the pinned lines');
            } catch (CwException $e) {
                self::assertSame('bill_not_pinned', $e->errorCode);
            }
        }
        self::assertNull(self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$bill->id]));
        $owners->markBill(Caller::system('own_items_kit'), (string) $bill->number, true, [$x['sku']], 1);
        self::assertNotNull(self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$bill->id]));
    }
}
