<?php

declare(strict_types=1);

namespace CW\Tests\Integration\OwnItems;

use CW\Caller;
use CW\Db;
use CW\Items\ItemOwners;
use CW\Profit\OrderSnapshots;
use CW\Profit\RecostSales;
use CW\Tests\Integration\Invoices\InvoicesTestCase;
use CW\Tests\Support\TestDb;

/**
 * CW-1153, the last fix round: the Codex astra check of b2f42a4 and the lead's decisions, each red on b2f42a4 first:
 *  H1 H2 H8  the go-live kit's refusal is STRICT and set-based: any row of another shop that ever named one of the items refuses the mark
 *            (a sale line in any state, a listing linked now or ever by its link history, a reserved unit in any state, a snapshot waiting in
 *            the queue that names one by the unit of its own line or by its listing): ItemOwners::otherShopUse
 *  H3        a batch tool's work that has nothing left to write still checks, at its end under the order's intake lock, for a snapshot stored
 *            meanwhile: deferred (OrderSnapshots::recompute; the re-cost of every shop reads it)
 *  H9        an order whose guard or rows another money writer holds is left for later (deferred) and the re-cost goes on to the next order
 */
final class OwnItemsFinal3Test extends InvoicesTestCase
{
    private const DAY = '2026-11-19';
    private const FROM = '2026-11-16 00:00:00';
    private Caller $vbs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vbs = $this->site('vapebig', 'live');
    }

    private static function price(int $sku, ?int $e4): void
    {
        $at = '2026-11-01 00:00:00';
        self::$db->exec("INSERT INTO item_cost (sku_id, unit_cost_e4, start_source, started_at, updated_at) VALUES (?, ?, 'erp_bin', ?, ?) AS n "
            . 'ON DUPLICATE KEY UPDATE unit_cost_e4 = n.unit_cost_e4', [$sku, $e4, $at, $at]);
        self::$db->exec("INSERT INTO item_cost_event (sku_id, at, source_at, kind, source_key, new_cost_e4) VALUES (?, ?, ?, 'start', ?, ?)",
            [$sku, $at, $at, 'start:' . $sku . ':' . bin2hex(random_bytes(4)), $e4]);
        self::$db->exec("INSERT INTO profit_job (job, mark_at) VALUES ('item_cost', '2030-01-01') AS n ON DUPLICATE KEY UPDATE mark_at = n.mark_at");
    }

    private static function sl(string $id, string $variant, int $price): array
    {
        return ['line_id' => $id, 'variant_id' => $variant, 'qty' => 1, 'price_inc' => $price, 'discount_inc' => 0, 'cancelled' => false, 'refund_inc' => 0, 'free' => false];
    }

    /** @return array<string, mixed> */
    private static function raw(string $ref, array $lines, array $over = []): array
    {
        return $over + ['v' => 1, 'version' => 1000, 'order_no' => 'F3' . $ref, 'status' => 'completed', 'placed_at' => '2026-11-19T09:59:00Z',
            'paid_at' => '2026-11-19T10:00:00Z', 'gateway' => 'paypal', 'currency' => 'GBP', 'parent_ref' => null, 'lines' => $lines,
            'delivery' => ['method_id' => '1', 'method' => 'Tracked 48', 'charged_inc' => 0, 'refund_inc' => 0], 'order_refund_inc' => 0, 'grand_inc' => 0, 'parcels' => []];
    }

    /** Two Vape Big orders of an item with no price, then the item priced: both orders' lines are the re-cost's. @return int the item */
    private function twoToRecost(string $a, string $b): int
    {
        $h = $this->item('legacy', 0, 'Hayati 4000 F3 ' . $a);
        self::price($h, null);
        $this->listing($this->vbs, '4' . $a, $h);
        foreach ([$a, $b] as $ref) {
            (new OrderSnapshots(self::$db))->store((int) $this->vbs->channelId, $ref, self::raw($ref, [self::sl('8' . substr($ref, 1), '4' . $a, 599)]), 'api');
        }
        self::price($h, 37500);
        return $h;
    }

    public function testH1H2H8AnyRowOfAnotherShopThatEverNamedAnItemIsCounted(): void
    {
        $vpg = $this->site('vapeandgo', 'live');
        $h = $this->item('legacy', 0, 'Hayati 4000 F3 strict');
        self::price($h, null);
        $use = fn (): array => ItemOwners::otherShopUse(self::$db, [$h], (int) $this->vbs->channelId, self::FROM);
        $zero = ['lines' => 0, 'listings' => 0, 'units' => 0, 'queued' => 0];
        $is = static fn (array $over): array => array_merge($zero, $over);
        self::assertSame($zero, $use());
        $this->listing($vpg, '50601', $h);
        self::assertSame($is(['listings' => 1]), $use(), 'linked there now');
        $this->ok($this->commit($vpg, '9851', [self::line('50601', '8851')]));
        $this->ok($this->release($vpg, '9851'));
        self::assertSame('released', self::$db->value("SELECT state FROM reservation_unit WHERE unit_id = '8851'"));
        self::assertSame($is(['listings' => 1, 'units' => 1]), $use(), 'H1: a released unit keeps its item: counted in any state');
        // a listing linked to it once and unlinked since: its link history names it (H2: a worker that read the link before the unlink)
        $old = $this->listing($vpg, '50602', null);
        $d = self::$db->insert("INSERT INTO match_decision (listing_id, action, sku_id, units_per_item, actor, state, expected_map_version, applied_at) "
            . "VALUES (?, 'suggest', ?, 1, 'system:test', 'applied', 0, NOW(6))", [$old, $h]);
        self::$db->exec('INSERT INTO listing_map_history (listing_id, sku_id, units_per_item, valid_from, valid_to, decision_id, closed_by_decision_id) '
            . 'VALUES (?, ?, 1, NOW(6) - INTERVAL 2 DAY, NOW(6) - INTERVAL 1 DAY, ?, ?)', [$old, $h, $d, $d]);
        self::assertSame($is(['listings' => 2, 'units' => 1]), $use(), 'a link ever made');
        // H8: a snapshot waiting in the queue counts only by its OWN lines (the unit of its line, the listing of its line's variant)
        (new OrderSnapshots(self::$db))->accept((int) $vpg->channelId, '9853', self::raw('9853', [self::sl('8853', '50602', 599)]), 'api');
        self::assertSame($is(['listings' => 2, 'units' => 1, 'queued' => 1]), $use(), 'queued, naming it by its listing\'s history');
        $this->listing($vpg, '50603', null);
        (new OrderSnapshots(self::$db))->accept((int) $vpg->channelId, '9851', self::raw('9851', [self::sl('8859', '50603', 599)]), 'api');
        self::assertSame($is(['listings' => 2, 'units' => 1, 'queued' => 1]), $use(), 'an order whose queued lines name none of them is not counted');
        // a sale line there, in any state
        self::$db->exec("UPDATE profit_job SET mark_at = '2030-01-01' WHERE job = 'item_cost'");
        $this->ok($this->commit($vpg, '9852', [self::line('50601', '8852')]));
        (new OrderSnapshots(self::$db))->store((int) $vpg->channelId, '9852', self::raw('9852', [self::sl('8852', '50601', 599)], ['placed_at' => '2026-11-10T09:59:00Z',
            'paid_at' => '2026-11-10T10:00:00Z']), 'api');
        self::assertSame($is(['lines' => 1, 'listings' => 2, 'units' => 2, 'queued' => 1]), $use(), 'a sale line paid before the start counts too');
        self::assertSame($zero, ItemOwners::otherShopUse(self::$db, [$h], (int) $vpg->channelId, self::FROM), 'the shop itself is not another shop');
    }

    public function testH3WorkWithNothingLeftToWriteStillSaysACorrectionStoredMeanwhile(): void
    {
        $h = $this->twoToRecost('9861', '9862');
        (new RecostSales(self::$db))->run('2026-11-01', self::DAY, ['none', 'cw_cost'], 'vapebig', true, [$h]);
        self::assertSame('recost', self::$db->value("SELECT costed_by FROM sale_line WHERE line_id = '8861'"));
        $other = TestDb::connect();
        $ch = (int) $this->vbs->channelId;
        $service = new OrderSnapshots(self::$db);
        // the API stores a correction of the order while the batch tool works it out (under the order's guard and row), and nothing is left to write
        $wrote = $service->recompute($ch, '9861', static function (Db $db) use ($other, $ch): ?array {
            (new OrderSnapshots($other))->accept($ch, '9861', self::raw('9861', [self::sl('8861', '49861', 649)], ['version' => 1001]), 'api');
            return null;
        }, true);
        self::assertFalse($wrote);
        self::assertTrue($service->deferred, 'the correction stored meanwhile is said: the order is left for the money job and a run again');
    }

    public function testH9AnOrderWhoseRowsAnotherWriterHoldsIsDeferredAndTheBatchGoesOn(): void
    {
        $h = $this->twoToRecost('9871', '9872');
        $other = TestDb::connect();
        $other->pdo()->beginTransaction();
        $other->one('SELECT order_ref FROM order_snapshot WHERE channel_id = ? AND order_ref = ? FOR UPDATE', [(int) $this->vbs->channelId, '9871']);
        self::$db->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $r = (new RecostSales(self::$db))->run('2026-11-01', self::DAY, ['none', 'cw_cost'], 'vapebig', true, [$h]);
        } finally {
            self::$db->exec('SET SESSION innodb_lock_wait_timeout = 50');
            $other->pdo()->rollBack();
        }
        self::assertSame([1, 1], [$r['deferred'], $r['orders']], 'the held order left for later, the next one re-costed');
        self::assertSame(['none', 'cw_cost'], [self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '8871'"),
            self::$db->value("SELECT cost_source FROM sale_line WHERE line_id = '8872'")]);
    }

    public function testH9AnOrderWhoseGuardAnotherWriterHoldsIsDeferredAndTheBatchGoesOn(): void
    {
        $h = $this->twoToRecost('9881', '9882');
        $other = TestDb::connect();
        $name = OrderSnapshots::lockName((int) $this->vbs->channelId, '9881');
        self::assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [$name]));
        try {
            $r = (new RecostSales(self::$db))->run('2026-11-01', self::DAY, ['none', 'cw_cost'], 'vapebig', true, [$h]);
        } finally {
            $other->value('SELECT RELEASE_LOCK(?)', [$name]);
        }
        self::assertSame([1, 1], [$r['deferred'], $r['orders']], 'the busy order left for later, the next one re-costed');
    }
}
