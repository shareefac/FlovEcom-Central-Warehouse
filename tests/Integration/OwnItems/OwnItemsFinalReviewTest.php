<?php

declare(strict_types=1);

namespace CW\Tests\Integration\OwnItems;

use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Items\ItemOwners;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitDays;
use CW\Profit\ProfitViews;
use CW\Profit\RecostSales;
use CW\Reorder\ReorderLines;
use CW\Reports\ReportViews;
use CW\Tests\Integration\Invoices\InvoicesTestCase;
use CW\Tests\Support\TestDb;
use CW\Ui\Words;

/**
 * CW-1153, the FINAL review of 2d13f32 (Codex astra + Fable), the code findings, each red on 2d13f32 first:
 *  H2  item_owner_set finds every final bill of the listed items made since the mark's start: one of only their items becomes the shop's own
 *      bill, a bill that mixes them with ours refuses the whole run (nothing written)
 *  H9  recost --items counts a listed line it leaves because the cost job has not reached its paid time (pending), and --fail-deferred says so
 *  H10 a scoped re-cost changes a listed line's COST only: its stored units, sale and restock state stay (the returns path moves those)
 *  M13 item_owner_set takes an item already marked as "already" only at the very start asked for; another start is refused whole
 *  M16 a mark started now puts right the sales already worked out whose paid time is at or after its start
 *  M17 a mark reads the setting's shop under that row (FOR SHARE): a change of the setting being written is waited for
 *  M18 an order's margin of ours is worked out from our goods when ours is known, whatever the shop's own line says
 *  M19 a past day's own stock is valued with the same exclusive cut-off for the price as for the units
 *  M20 the reorder list's every line (the All view, its export) says a shop's own item now and suggests nothing for it
 *  Fable L11b the own items' history names CW for the go-live tool, never its system name
 *  Fable L12 by product keeps a row of ours that holds no own goods, whatever its figures
 */
final class OwnItemsFinalReviewTest extends InvoicesTestCase
{
    private const DAY = '2026-11-19';
    private Caller $vbs;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->vbs = $this->site('vapebig', 'live');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function codesFile(int ...$skus): string
    {
        $f = (string) tempnam(sys_get_temp_dir(), 'vbp-fr-codes-');
        file_put_contents($f, implode("\n", array_map(static fn (int $s): string => self::code($s), $skus)) . "\n");
        $this->files[] = $f;
        return $f;
    }

    /** @return array{code: int, out: string} */
    private static function script(string $name, string ...$args): array
    {
        $p = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', dirname(__DIR__, 3) . "/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out];
    }

    private static function code(int $sku): string
    {
        return (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]);
    }

    private static function price(int $sku, ?int $e4, string $at = '2026-11-16 00:00:00', string $mark = '2030-01-01'): void
    {
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, 'start', ?, ?)",
            [$sku, $at, $at, 'start:' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', ?) AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at", [$mark]);
    }

    private static function sl(string $id, string $variant, int $price, array $over = []): array
    {
        return $over + ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0,
            'free' => false];
    }

    private function store(string $ref, array $lines, array $over = []): void
    {
        (new OrderSnapshots(self::$db))->store((int) $this->vbs->channelId, $ref, $over + ['v' => 1, 'version' => 1000, 'order_no' => 'VB' . $ref, 'status' => 'completed',
            'placed_at' => '2026-11-19T09:59:00Z', 'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '1', 'method' => 'Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []], 'api');
    }

    /** @return array{0: int, 1: int} Hayati (3.75) and ours (2.00), both listed on Vape Big */
    private function two(): array
    {
        $h = $this->item('legacy', 0, 'Hayati 4000 FR');
        $o = $this->item('legacy', 0, 'Our liquid FR');
        self::price($h, 37500);
        self::price($o, 20000);
        $this->listing($this->vbs, '40501', $h);
        $this->listing($this->vbs, '40503', $o);
        return [$h, $o];
    }

    private static function markOwn(int $sku, ?string $from = '2026-11-16 00:00:00'): void
    {
        (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), self::code($sku), 'Vape Big\'s own goods', $from);
    }

    // ---- the kit's tools ---------------------------------------------------------------------------------------------------------------

    public function testH2TheMarkingToolMakesEveryBillOfOnlyTheItemsTheShopsAndRefusesAMixedOne(): void
    {
        ['desk' => $desk, 'buyer' => $buyer, 'supplier' => $s] = $this->people('Cloud Tech FR');
        $x = $this->orderedAndDelivered($desk, $buyer, (int) $s['id'], 1, 10, '36.2500', 1);
        $only = $this->invoiceDelivery($desk, $x['grn']->id, '36.25', '7.25');
        $a = self::makeSku('Hayati 4000 FR mixed');
        $b = self::makeSku('Our liquid FR mixed');
        $sa = $this->supplierItem($buyer, (int) $s['id'], $a, 10, '36.2500', ['supplier_code' => 'FR-A']);
        $sb = $this->supplierItem($buyer, (int) $s['id'], $b, 10, '20.0000', ['supplier_code' => 'FR-B']);
        $po = $this->draftPo($buyer, (int) $s['id'], [['supplier_item_id' => (int) $sa['id'], 'packs' => 1], ['supplier_item_id' => (int) $sb['id'], 'packs' => 1]]);
        $po = $this->pos->approve($buyer, $po->id, $po->version);
        $grn = $this->delivered($desk, (int) $s['id'], [
            ['sku_id' => $a, 'supplier_item_id' => (int) $sa['id'], 'packs' => 1, 'units_per_pack' => 10, 'pack_price' => '36.2500', 'po_line_no' => 1],
            ['sku_id' => $b, 'supplier_item_id' => (int) $sb['id'], 'packs' => 1, 'units_per_pack' => 10, 'pack_price' => '20.0000', 'po_line_no' => 2],
        ], $po->id);
        $mixed = $this->invoices->createFromReceipt($desk, $grn->id)['document'];
        $mixed = $this->postWithTotals($desk, $mixed, '56.25', '0.00');
        $from = (string) self::$db->value("SELECT DATE_FORMAT(LEAST(?, ?) - INTERVAL 1 DAY, '%Y-%m-%d %H:%i:%s')", [
            self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$only->id]), self::$db->value('SELECT posted_at FROM document WHERE id = ?', [$mixed->id])]);
        $refused = self::script('item_owner_set', '--items=' . $this->codesFile($x['sku'], $a), "--from={$from}", '--reason=the count that brought them in', '--apply');
        self::assertSame(2, $refused['code'], $refused['out']);
        self::assertStringContainsString('bills_mixed', $refused['out']);
        self::assertStringContainsString((string) $mixed->number, $refused['out']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM item_owner'), 'refused whole: no mark');
        $done = self::script('item_owner_set', '--items=' . $this->codesFile($x['sku']), "--from={$from}", '--reason=the count that brought them in', '--apply');
        self::assertSame(0, $done['code'], $done['out']);
        self::assertSame((int) $this->vbs->channelId, (int) self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$only->id]),
            'a bill of only the marked item, made final after the start, is the shop\'s own bill');
        self::assertNull(self::$db->value('SELECT item_owner FROM supplier_invoice WHERE document_id = ?', [$mixed->id]));
    }

    public function testM13TheMarkingToolTakesAnItemAsAlreadyOnlyAtTheStartAsked(): void
    {
        $sku = self::makeSku('Elux Legend FR');
        self::markOwn($sku, '2026-11-10 00:00:00'); // marked by hand, from another start
        $other = self::script('item_owner_set', '--items=' . $this->codesFile($sku), '--from=2026-11-16 00:00:00', '--reason=the count that brought them in', '--apply');
        self::assertSame(2, $other['code'], $other['out']);
        self::assertStringContainsString('own_item_conflict', $other['out']);
        $same = self::script('item_owner_set', '--items=' . $this->codesFile($sku), '--from=2026-11-10 00:00:00', '--reason=the count that brought them in', '--apply');
        self::assertSame(0, $same['code'], $same['out']);
        self::assertStringContainsString('1 were already', $same['out']);
    }

    public function testH9TheReCostCountsAListedLineItLeavesForTheCostJob(): void
    {
        [$h] = $this->two();
        self::price($h, null);
        $this->store('9701', [self::sl('8701', '40501', 599)]);
        self::assertSame('none', self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '8701'"));
        self::price($h, 37500, '2026-11-16 00:00:00', '2026-11-19 09:00:00'); // the cost job's mark now BEFORE the sale's paid time
        $r = (new RecostSales(self::$db))->run('2026-11-01', self::DAY, ['none', 'cw_cost'], 'vapebig', true, [$h]);
        self::assertSame(1, $r['pending'] ?? null, 'the line it leaves is counted, never passed over silently');
        $cli = self::script('recost_sales', '--from=2026-11-01', '--to=' . self::DAY, '--source=none,cw_cost', '--items=' . $this->codesFile($h), '--apply', '--fail-deferred');
        self::assertSame(1, $cli['code'], $cli['out']);
        self::assertStringContainsString('pending=1', $cli['out']);
    }

    // ---- the money service -------------------------------------------------------------------------------------------------------------

    public function testH10AScopedReCostChangesTheCostOnlyNeverTheStoredSaleOrRestock(): void
    {
        [$h] = $this->two();
        $this->ok($this->commit($this->vbs, '9702', [self::line('40501', '8702')]));
        $this->ok($this->ship($this->vbs, '9702', ['8702']));
        // £1.00 refunded £1.12 on the line: -10p ex VAT, as stored
        $this->store('9702', [self::sl('8702', '40501', 100, ['refund_inc' => 112])]);
        $before = self::$db->one("SELECT units, sale_inc, sale_ex, restocked FROM sale_line WHERE line_id = '8702'");
        self::assertSame(-10, (int) $before['sale_ex']);
        $orderSale = (int) self::$db->value("SELECT sale_ex FROM sale_order WHERE order_ref = '9702'");
        $this->ok($this->res->returnUnits($this->vbs, '9702', ['8702'], $this->key('ret'))); // back in stock since: the returns path's to move
        self::price($h, 36250);
        (new RecostSales(self::$db))->run('2026-11-01', self::DAY, ['none', 'cw_cost'], 'vapebig', true, [$h]);
        self::assertSame($before, self::$db->one("SELECT units, sale_inc, sale_ex, restocked FROM sale_line WHERE line_id = '8702'"), 'units, sale and restock as stored');
        self::assertSame(363, (int) self::$db->value("SELECT cost FROM sale_line WHERE line_id = '8702'"), 'the cost at the new price');
        self::assertSame($orderSale, (int) self::$db->value("SELECT sale_ex FROM sale_order WHERE order_ref = '9702'"));
        self::assertSame([], ProfitDays::differences(self::$db, (int) $this->vbs->channelId, self::DAY));
    }

    public function testM16AMarkStartedNowPutsRightASaleWorkedOutBeforeItWithALaterPaidTime(): void
    {
        [$h] = $this->two();
        $t = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $paid = $t->modify('+60 seconds')->format('Y-m-d\TH:i:s\Z');
        $this->store('9703', [self::sl('8703', '40501', 599)], ['paid_at' => $paid, 'placed_at' => $paid]);
        self::assertNull(self::$db->value("SELECT item_owner FROM sale_line WHERE line_id = '8703'"), 'ours: worked out before the mark');
        (new ItemOwners(self::$db, static fn (): \DateTimeImmutable => $t))->add(Caller::system('own_items_kit'), self::code($h), 'from now');
        self::assertSame((int) $this->vbs->channelId, (int) self::$db->value("SELECT item_owner FROM sale_line WHERE line_id = '8703'"),
            'paid at or after the mark\'s start: the shop\'s own');
        self::assertSame(1, (int) self::$db->value("SELECT own_units FROM sale_order WHERE order_ref = '9703'"));
    }

    public function testM17AMarkReadsTheSettingsShopUnderItsRow(): void
    {
        $sku = self::makeSku('Elux FireRose FR');
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one("SELECT setting_key FROM app_setting WHERE setting_key = 'stock.own_items_shop' FOR UPDATE");
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $code = null;
        try {
            self::markOwn($sku, null);
        } catch (\PDOException $e) {
            $code = Db::driverCode($e);
        } catch (CwException $e) {
            $code = $e->getPrevious() instanceof \PDOException ? Db::driverCode($e->getPrevious()) : -1;
        } finally {
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
            $other->pdo()->rollBack();
        }
        self::assertSame(1205, $code, 'a change of the setting being written is waited for');
    }

    // ---- the readers -------------------------------------------------------------------------------------------------------------------

    public function testM18AnOrdersMarginOfOursComesFromOurGoods(): void
    {
        [, $o] = $this->two();
        $n = $this->item('legacy', 0, 'Elux Legend FR no price');
        $this->listing($this->vbs, '40504', $n);
        self::markOwn($n);
        $this->store('9704', [self::sl('8704', '40503', 1200), self::sl('8705', '40504', 599)]); // ours £10 at £2; the shop's own with no price
        $row = array_column((new ProfitViews(self::$db))->orders(self::DAY, self::DAY, null, '', false)['rows'], null, 'ref')['9704'];
        self::assertSame([800, 8000], [$row['profit'], $row['margin_bp']], 'our £8 on £10: 80%, whatever the shop\'s own line lacks');
    }

    public function testM19APastDaysOwnStockTakesThePriceBeforeItsExclusiveCutOff(): void
    {
        [$h] = $this->two();
        $this->now = new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC'));
        $this->book('goods_in', $h, 10);
        self::markOwn($h);
        self::price($h, 36250, '2026-11-26 00:00:00'); // a price from exactly the next midnight
        $at = (new ReportViews(self::$db))->at('2026-11-26 00:00:00'); // the day's end, exclusive (ReportsController::endOfDay)
        self::assertSame('37.50', $at->bounded(static fn (): array => $at->valueFigures())['own'][0]['value'], 'the price in force BEFORE the cut-off');
    }

    public function testM20EveryReorderLineSaysAShopsOwnItemNowAndSuggestsNothing(): void
    {
        [$h] = $this->two();
        self::$db->exec("INSERT INTO item_reorder (sku_id, min_stock, updated_actor) VALUES (?, 50, 'system:test')", [$h]);
        (new \CW\Reorder\ReorderRefresh(self::$db, $this->pos))->items([$h]);
        self::$db->transaction(fn () => self::markOwn($h, null)); // the post-commit refresh skipped, as when it fails
        $line = (new ReorderLines(self::$db))->lines([$h])[0];
        self::assertSame([0, false], [(int) $line['packs'], (bool) $line['urgent']]);
        self::assertContains('own_item', $line['flags']);
        self::assertNotNull($line['never']);
        $forDays = (new ReorderLines(self::$db))->lines([$h], 14)[0];
        self::assertSame(0, (int) $forDays['packs'], 'bought for 14 days: nothing either');
    }

    public function testFableL11bTheHistoryNamesCwForTheGoLiveTool(): void
    {
        $sku = self::makeSku('Hayati 4000 FR history');
        self::markOwn($sku);
        self::assertSame(Words::RECORD['by_cw'], (new ItemOwners(self::$db))->history()[0]['added_by']);
    }

    public function testFableL12ByProductKeepsARowOfOursWithNoOwnGoods(): void
    {
        [, $o] = $this->two();
        self::$db->exec('INSERT INTO profit_item_day (channel_id, day, sku_id, units, sale_ex, cost, units_no_cost) VALUES (?, ?, ?, 0, 0, 0, 0)',
            [(int) $this->vbs->channelId, self::DAY, $o]);
        $ids = array_column((new ProfitViews(self::$db))->breakdown(self::DAY, self::DAY, null, 'product', 'profit')['rows'], 'id');
        self::assertContains($o, $ids, 'no own goods on it: shown as before, figures or not');
    }
}
