<?php

declare(strict_types=1);

namespace CW\Tests\Integration\OwnItems;

use CW\Caller;
use CW\Items\ItemOwners;
use CW\Profit\OrderSnapshots;
use CW\Profit\ProfitViews;
use CW\Profit\RecostSales;
use CW\Tests\Integration\Invoices\InvoicesTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1153, the last Codex check of a46cc3d (astra), the code findings, each red on a46cc3d first:
 *  H4  before the go-live kit changes the items' price history it counts, under the items' rows, every way another shop's sale could read it:
 *      a sale line paid since the mark's start, a line with no cost fixed yet (any time), a snapshot waiting in the queue that names one of
 *      them (by its listing or by a reserved unit), a reserved unit with no cost fixed yet, a link on that shop (ItemOwners::otherShopUse)
 *  H5  a re-cost that has nothing left to write on an order still says the order when a newer snapshot of it waits in the queue (deferred),
 *      read under the order's guard: --fail-deferred never says "done" while a correction waits
 *  M12 "lowest margin first" uses the margin of ours each row shows, for which orders it lists, their count, their order and the pages
 */
final class OwnItemsLastCheckTest extends InvoicesTestCase
{
    private const DAY = '2026-11-19';
    private const FROM = '2026-11-16 00:00:00';
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
        $f = (string) tempnam(sys_get_temp_dir(), 'vbp-lc-codes-');
        file_put_contents($f, implode("\n", array_map(static fn (int $s): string => (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$s]), $skus)) . "\n");
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

    private static function price(int $sku, ?int $e4, string $mark = '2030-01-01'): void
    {
        $at = '2026-11-01 00:00:00';
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, 'start', ?, ?)",
            [$sku, $at, $at, 'start:' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', ?) AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at", [$mark]);
    }

    private static function sl(string $id, string $variant, int $price): array
    {
        return ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false];
    }

    /** @return array<string, mixed> */
    private static function raw(string $ref, array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'LC' . $ref, 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z',
            'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '1', 'method' => 'Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []];
    }

    private function priced(string $name, ?int $e4, string $variant): int
    {
        $sku = $this->item('legacy', 0, $name);
        self::price($sku, $e4);
        $this->listing($this->vbs, $variant, $sku);
        return $sku;
    }

    private static function markOwn(int $sku): void
    {
        (new ItemOwners(self::$db))->add(Caller::system('own_items_kit'), (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$sku]), 'Vape Big\'s own goods', self::FROM);
    }

    /** @return array<string, int> */
    private function use(int $sku): array
    {
        return ItemOwners::otherShopUse(self::$db, [$sku], (int) $this->vbs->channelId, self::FROM);
    }

    public function testH4EveryWayAnotherShopsSaleCouldReadTheItemsPriceHistoryIsCounted(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $h = $this->item('legacy', 0, 'Hayati 4000 LC');
        self::price($h, null);
        $zero = ['lines' => 0, 'unpriced' => 0, 'queued' => 0, 'reservations' => 0, 'links' => 0];
        self::assertSame($zero, $this->use($h));
        $this->listing($vpg, '50501', $h);
        self::assertSame(['links' => 1] + $zero, $this->use($h), 'linked on another shop');
        $this->ok($this->commit($vpg, '9811', [self::line('50501', '8811')]));
        self::assertSame(['links' => 1, 'reservations' => 1] + $zero, $this->use($h), 'a unit reserved there with no cost fixed yet');
        $snaps = new OrderSnapshots(self::$db);
        $snaps->accept((int) $vpg->channelId, '9811', self::raw('9811', [self::sl('8811', '50501', 599)]), 'api');
        self::assertSame(['links' => 1, 'reservations' => 1, 'queued' => 1] + $zero, $this->use($h), 'its first paid snapshot waits in the queue');
        $snaps->applyQueued();
        self::assertSame(['links' => 1, 'lines' => 1] + $zero, $this->use($h), 'worked out: a sale line paid since the start, its cost fixed (no price: none)');
        // a sale there paid BEFORE the start whose cost the cost job has not fixed yet: it would read the history being changed
        self::$db->exec("UPDATE profit_job SET mark_at = '2026-11-05 00:00:00' WHERE job = 'item_cost'");
        $this->ok($this->commit($vpg, '9812', [self::line('50501', '8812')]));
        $snaps->store((int) $vpg->channelId, '9812', self::raw('9812', [self::sl('8812', '50501', 599)], ['placed_at' => '2026-11-10T09:59:00Z',
            'paid_at' => '2026-11-10T10:00:00Z']), 'api');
        self::assertNull(self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '8812'"));
        self::assertSame(['links' => 1, 'lines' => 1, 'unpriced' => 1, 'reservations' => 1] + $zero, $this->use($h));
        self::assertSame($zero, ItemOwners::otherShopUse(self::$db, [$h], (int) $vpg->channelId, self::FROM), 'the shop itself is not another shop');
    }

    public function testH5TheReCostSaysAnOrderWaitingInTheQueueWhenItHasNothingLeftToWrite(): void
    {
        $h = $this->priced('Hayati 4000 LC re-cost', null, '40501');
        $snaps = new OrderSnapshots(self::$db);
        $snaps->store((int) $this->vbs->channelId, '9821', self::raw('9821', [self::sl('8821', '40501', 599)]), 'api');
        self::assertSame('none', self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '8821'"));
        self::price($h, 37500);
        $first = (new RecostSales(self::$db))->run('2026-11-01', self::DAY, ['none', 'cw_cost'], 'vapebig', true, [$h]);
        self::assertSame([1, 0], [$first['orders'], $first['deferred']]);
        // a correction of the order arrives and waits for the money job (it could move the payment across a receipt)
        $snaps->accept((int) $this->vbs->channelId, '9821', self::raw('9821', [self::sl('8821', '40501', 649)], ['version' => 1001]), 'api');
        $again = (new RecostSales(self::$db))->run('2026-11-01', self::DAY, ['none', 'cw_cost'], 'vapebig', true, [$h]);
        self::assertSame(1, $again['deferred'], 'the order is said while its correction waits, though its line is re-costed already');
        $cli = self::script('recost_sales', '--from=2026-11-01', '--to=' . self::DAY, '--source=none,cw_cost', '--items=' . $this->codesFile($h), '--apply', '--fail-deferred');
        self::assertSame(1, $cli['code'], $cli['out']);
        self::assertStringContainsString('deferred=1', $cli['out']);
        $snaps->applyQueued();
        $done = self::script('recost_sales', '--from=2026-11-01', '--to=' . self::DAY, '--source=none,cw_cost', '--items=' . $this->codesFile($h), '--apply', '--fail-deferred');
        self::assertSame(0, $done['code'], $done['out']);
    }

    public function testM12LowestMarginFirstUsesTheMarginOfOursEachRowShows(): void
    {
        $o3 = $this->priced('Our liquid LC 3.00', 30000, '40511');
        $o2 = $this->priced('Our liquid LC 2.00', 20000, '40512');
        $o15 = $this->priced('Our liquid LC 1.50', 15000, '40513');
        $h = $this->priced('Hayati 4000 LC margin', 37500, '40514');
        $n = $this->priced('Elux Legend LC no price', null, '40515');
        self::markOwn($h);
        self::markOwn($n);
        $snaps = new OrderSnapshots(self::$db);
        $ch = (int) $this->vbs->channelId;
        $snaps->store($ch, '9831', self::raw('9831', [self::sl('8831', '40511', 1200)]), 'api');                               // ours £10 at £3: 70%
        $snaps->store($ch, '9832', self::raw('9832', [self::sl('8832', '40512', 1200), self::sl('8833', '40514', 599)]), 'api'); // ours 80%; whole 61.64%
        $snaps->store($ch, '9833', self::raw('9833', [self::sl('8834', '40513', 1200), self::sl('8835', '40515', 599)]), 'api'); // ours 85%; whole unknown
        $snaps->store($ch, '9834', self::raw('9834', [self::sl('8836', '40514', 599)]), 'api');                                // the shop's own only
        $views = new ProfitViews(self::$db);
        $plain = array_column($views->orders(self::DAY, self::DAY, $ch, '', false)['rows'], 'margin_bp', 'ref');
        self::assertSame([7000, 8000, 8500], [$plain['9831'], $plain['9832'], $plain['9833']], 'the margins of ours the rows show');
        $low = $views->orders(self::DAY, self::DAY, $ch, '', true);
        self::assertSame(['9831', '9832', '9833'], array_column($low['rows'], 'ref'), 'listed and ordered by the margin of ours shown');
        self::assertSame([7000, 8000, 8500], array_column($low['rows'], 'margin_bp'));
        self::assertSame(3, $low['total']);
        $next = $views->orders(self::DAY, self::DAY, $ch, '', true, ['8000', $ch, '9832']);
        self::assertSame(['9833'], array_column($next['rows'], 'ref'), 'the page after a row goes on from its margin of ours');
        self::assertNull($next['total']);
    }
}
