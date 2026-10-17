<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Items;

use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\TestDb;
use CW\Items\Packs;
use CW\Items\RepackHandler;
use CW\Items\Repacks;
use CW\StockOps\CostHints;
use CW\Tests\Integration\StockOps\StockOpsTestCase;

/**
 * Repack (RPK, pack B; owner answer Q3; docs/decisions.md PB9-PB13) against the real document base: breaking packs into singles and
 * making packs from singles move on hand by exactly the lines on each item (the invariants, B1-B2 included, after every test), carry
 * the cost of what is taken apart (a setting), happen once however often they are sent, are cancelled by the exact negation, never take
 * a protected item below zero, need a definition switched on, wait for a reviewer only when the OK first for a big record is switched on,
 * and are kept by the people who move stock.
 */
final class RepackTest extends StockOpsTestCase
{
    private Packs $packs;
    private Repacks $repacks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packs = new Packs(self::$db);
        $this->repacks = new Repacks(self::$db, $this->docs);
    }

    /** A pack item of $units singles, defined by a mapping lead. @return array{0: int, 1: int, 2: int} [definition, pack item, single item] */
    private function pack(int $units, string $policy = 'legacy', int $packsOnHand = 0, int $singlesOnHand = 0): array
    {
        $box = $this->item($policy, $packsOnHand, "Box of {$units}");
        $single = $this->item($policy, $singlesOnHand, 'Single');
        return [$this->packs->create($this->staffUser('mapping_lead'), $box, $single, $units)['id'], $box, $single];
    }

    public function testBreakingAndMakingPacksBalanceOnHandPerItemAndCarryTheCost(): void
    {
        [$def, $box, $shot] = $this->pack(10);
        $this->book('goods_in', $box, 5, 'MAIN', null, '12.00'); // 5 boxes at £12 each
        $sc = $this->staffUser('stock_controller');

        $break = $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 2, 'note' => 'for singles']);
        self::assertSame(['posted', 'RPK-000001', 'not_required'], [$break->status, $break->number, $break->reviewState], 'no reviewer check by default');
        self::assertSame([['MAIN', 'adjustment', -2, null], ['MAIN', 'adjustment', 20, '1.200000']], $this->ledgerOf($break->id),
            'the singles carry the boxes\' cost: £24 over 20');
        self::assertSame([3, 20], [$this->onHand($box), $this->onHand($shot)]);
        self::assertSame(['break', 2, 10, $box, $shot], array_values(self::$db->one('SELECT direction, packs, units_per_pack, pack_sku_id, single_sku_id FROM repack WHERE document_id = ?',
            [$break->id])));
        self::assertSame([[1, $box, -2], [2, $shot, 20]], array_map(static fn (array $l): array => [(int) $l['line_no'], (int) $l['sku_id'], (int) $l['qty']],
            $this->docs->lines($break->id)));

        // Make one box back from ten singles: the box carries the singles' average cost x 10.
        $make = $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'make', 'packs' => 1]);
        self::assertSame(['posted', 'RPK-000002'], [$make->status, $make->number]);
        self::assertSame([['MAIN', 'adjustment', -10, null], ['MAIN', 'adjustment', 1, '12.000000']], $this->ledgerOf($make->id));
        self::assertSame([4, 10], [$this->onHand($box), $this->onHand($shot)]);
        self::assertSame('12.000000', (new CostHints(self::$db))->average([$box])[$box]);
        // Each item moved by exactly its lines, both ways: the boxes -2 then +1, the singles +20 then -10.
        self::assertSame([[$box, -1], [$shot, 10]], array_map(static fn (array $r): array => [(int) $r['sku_id'], (int) $r['n']],
            self::$db->all("SELECT l.sku_id, SUM(l.qty_delta) AS n FROM stock_ledger l JOIN document d ON d.id = l.document_id WHERE d.doc_type = 'RPK' "
                . "AND l.bucket = 'on_hand' GROUP BY l.sku_id ORDER BY l.sku_id")));
        self::assertSame(['repack.book', 'repack.book'], self::$db->column("SELECT action FROM audit_log WHERE action LIKE 'repack.%' ORDER BY id"));
        self::assertSame([$make->id, $break->id], array_map(static fn (array $r): int => (int) $r['id'], $this->repacks->list([])));
        self::assertSame([$break->id], array_map(static fn (array $r): int => (int) $r['id'], $this->repacks->list(['q' => 'RPK-000001'])));
    }

    public function testARepackIsBookedOnceHoweverOftenItIsPosted(): void
    {
        [$def, $box, $shot] = $this->pack(6, 'legacy', 3);
        $sc = $this->staffUser('stock_controller');
        $doc = $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 1]);
        $rows = $this->ledgerCount();
        self::refusedWith('not_draft', fn () => $this->docs->post($sc, $doc->id, $doc->version - 1));
        self::refusedWith('not_draft', fn () => $this->docs->post($sc, $doc->id, $doc->version));
        self::refusedWith('not_draft', fn () => $this->repacks->post($sc, $doc->id, $doc->version));
        self::assertSame($rows, $this->ledgerCount(), 'nothing booked twice');
        self::assertSame([2, 6], [$this->onHand($box), $this->onHand($shot)]);
        self::assertSame(1, (int) self::$db->value("SELECT last_no FROM number_series WHERE prefix = 'RPK'"));
    }

    public function testARepackIsCancelledByTheExactNegation(): void
    {
        [$def, $box, $shot] = $this->pack(4, 'legacy', 0, 8);
        $sc = $this->staffUser('stock_controller');
        $make = $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'make', 'packs' => 2]);
        self::assertSame([2, 0], [$this->onHand($box), $this->onHand($shot)]);
        $rev = $this->docs->reverse($sc, $make->id, 'entered_in_error', null);
        self::assertSame(['posted', 'RPK-000002', $make->id], [$rev->status, $rev->number, $rev->reversesId]);
        self::assertSame('reversed', $this->docs->get($make->id)->status);
        self::assertSame([['MAIN', 'adjustment', 8, null], ['MAIN', 'adjustment', -2, null]], $this->ledgerOf($rev->id));
        self::assertSame([0, 8], [$this->onHand($box), $this->onHand($shot)], 'back as it was');
        self::assertSame(['make', 2, $box, $shot], array_values(self::$db->one('SELECT direction, packs, pack_sku_id, single_sku_id FROM repack WHERE document_id = ?', [$rev->id])),
            'the cancellation says the same header');
        self::refusedWith('not_reversible', fn () => $this->docs->reverse($sc, $make->id, 'entered_in_error', null));
        self::assertSame([$rev->id, $make->id], array_map(static fn (array $r): int => (int) $r['id'], $this->repacks->list([])));
    }

    /**
     * The review of b62c69f, finding 4: a cancellation that waits for a reviewer's OK (it puts more back on hand than the "stock put back"
     * limit, rule I32) has no header of its own until it is posted; the list shows it all the same, and after it is taken back.
     */
    public function testACancellationWaitingForAnOkIsOnTheListAndStaysThereWhenTakenBack(): void
    {
        [$def, $box, $shot] = $this->pack(10, 'legacy', 0, 20);
        $sc = $this->staffUser('stock_controller');
        $make = $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'make', 'packs' => 2]);
        $limit = (int) self::$db->value("SELECT MIN(approval_limit_units) FROM document_type WHERE approval_rule = 'positive_without_supplier_doc'");
        self::assertSame(10, $limit, 'ADJ\'s "stock put back" rule is on by default');
        $rev = $this->docs->reverse($sc, $make->id, 'entered_in_error', null);
        self::assertSame('awaiting_approval', $rev->status, '20 singles back on hand: over the limit');
        $row = array_column($this->repacks->list([]), null, 'id')[$rev->id] ?? null;
        self::assertNotNull($row, 'the waiting cancellation is on the list');
        self::assertSame(['awaiting_approval', 'make', 2, 10, $box, $shot, $make->number],
            [$row['status'], $row['direction'], (int) $row['packs'], (int) $row['units_per_pack'], (int) $row['pack_sku_id'], (int) $row['single_sku_id'], $row['reverses_number']]);
        self::assertSame([$rev->id], array_map(static fn (array $r): int => (int) $r['id'], $this->repacks->list(['state' => 'awaiting_approval'])));
        self::assertContains($rev->id, array_map(static fn (array $r): int => (int) $r['id'], $this->repacks->list(['q' => sprintf('CW-%06d', $box)])));
        $back = $this->repacks->withdraw($sc, $rev->id);
        self::assertSame('cancelled', $back->status, 'a cancellation taken back is cancelled, never a draft');
        self::assertSame(['cancelled'], array_column(array_filter($this->repacks->list([]), static fn (array $r): bool => (int) $r['id'] === $rev->id), 'status'));
        self::assertSame([2, 0], [$this->onHand($box), $this->onHand($shot)], 'nothing was put back');
    }

    public function testAProtectedItemNeverGoesBelowZeroAndTheDefinitionMustBeSwitchedOn(): void
    {
        [$def, $box, $shot] = $this->pack(10, 'strict', 1, 5);
        $sc = $this->staffUser('stock_controller');
        $e = self::refusedWith('below_zero', fn () => $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 2]));
        self::assertSame([1, 1, 2], [$e->detail['line'], $e->detail['have'], $e->detail['wanted']]);
        self::refusedWith('below_zero', fn () => $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'make', 'packs' => 1]));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM document WHERE doc_type = 'RPK'"), 'a refusal leaves no record behind');
        // An unprotected item may go below zero, as on every stock record.
        [$legacyDef, $legacyBox] = $this->pack(3);
        $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $legacyDef, 'direction' => 'break', 'packs' => 1]);
        self::assertSame(-1, $this->onHand($legacyBox));
        // A switched-off definition is refused; so are a bad way and a bad number.
        $this->packs->setActive($this->staffUser('mapping_lead'), $def, false);
        self::refusedWith('pack_off', fn () => $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 1]));
        self::refusedWith('bad_direction', fn () => $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $legacyDef, 'direction' => 'both', 'packs' => 1]));
        self::refusedWith('bad_packs', fn () => $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $legacyDef, 'direction' => 'break', 'packs' => 0]));
        self::refusedWith('unknown_pack', fn () => $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => 999999, 'direction' => 'break', 'packs' => 1]));
        // Lines that are not the repack's own are refused when it is made final.
        $d = $this->docs->createDraft($sc, 'RPK', ['warehouse' => 'MAIN']);
        self::$db->exec("INSERT INTO repack (document_id, item_pack_id, direction, packs, units_per_pack, pack_sku_id, single_sku_id) VALUES (?, ?, 'break', 1, 3, ?, ?)",
            [$d->id, $legacyDef, $legacyBox, (int) self::$db->value('SELECT single_sku_id FROM item_pack WHERE id = ?', [$legacyDef])]);
        $d = $this->docs->setLines($sc, $d->id, $d->version, [['sku_id' => $legacyBox, 'qty' => -1], ['sku_id' => $box, 'qty' => 3]]);
        self::refusedWith('repack_lines', fn () => $this->docs->post($sc, $d->id, $d->version));
        $this->repacks->cancel($sc, $d->id, $d->version, 'drawn wrong');
        self::assertSame('cancelled', $this->docs->get($d->id)->status);
    }

    public function testTheCostIsNotCarriedWhenTheSettingIsOffOrNoCostIsKnown(): void
    {
        [$def, $box, $shot] = $this->pack(2, 'legacy', 4);
        $sc = $this->staffUser('stock_controller');
        $doc = $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 1]);
        self::assertSame([['MAIN', 'adjustment', -1, null], ['MAIN', 'adjustment', 2, null]], $this->ledgerOf($doc->id), 'no average known: no cost');
        $this->book('goods_in', $box, 1, 'MAIN', null, '5.00');
        self::$db->exec("UPDATE app_setting SET value_json = 'false' WHERE setting_key = 'items.repack_carry_cost'");
        try {
            $doc = $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 1]);
            self::assertSame([['MAIN', 'adjustment', -1, null], ['MAIN', 'adjustment', 2, null]], $this->ledgerOf($doc->id), 'the setting off: no cost');
        } finally {
            self::$db->exec("UPDATE app_setting SET value_json = 'true' WHERE setting_key = 'items.repack_carry_cost'");
        }
        $doc = $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 1]);
        self::assertSame([['MAIN', 'adjustment', -1, null], ['MAIN', 'adjustment', 2, '2.500000']], $this->ledgerOf($doc->id));
        self::assertSame('2.500000', (new RepackHandler(self::$db))->carriedCost(self::$db, [['qty' => -1, 'sku_id' => $box], ['qty' => 2, 'sku_id' => $shot]]));
    }

    public function testABigRepackWaitsForAReviewerOnlyWhenTheOkFirstIsSwitchedOn(): void
    {
        [$def, $box] = $this->pack(10, 'legacy', 100);
        $sc = $this->staffUser('stock_controller');
        $t = self::$db->one("SELECT review_rule, approval_rule, size_approval, size_units FROM document_type WHERE code = 'RPK'");
        self::assertSame(['none', 'none', 0, 500], [$t['review_rule'], $t['approval_rule'], (int) $t['size_approval'], (int) $t['size_units']], 'extra approvals start off');
        $this->rule('RPK', ['size_approval' => 1, 'size_units' => 100]);
        $wait = $this->repacks->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 20]);
        self::assertSame(['awaiting_approval', null], [$wait->status, $wait->number], '20 boxes and 200 singles: over 100 units');
        self::assertSame(100, $this->onHand($box), 'nothing booked until the OK');
        $task = $this->openTask($wait->id);
        self::assertGreaterThan(0, $task);
        $back = $this->repacks->withdraw($sc, $wait->id);
        self::assertSame('draft', $back->status);
        $this->rule('RPK', ['size_approval' => 0]);
        $posted = $this->repacks->post($sc, $back->id, $back->version);
        self::assertSame(['posted', 'RPK-000001'], [$posted->status, $posted->number]);
        self::assertSame(80, $this->onHand($box));
    }

    public function testRepacksAreKeptByThePeopleWhoMoveStock(): void
    {
        [$def, $box] = $this->pack(2, 'legacy', 10);
        foreach (['stock_controller', 'warehouse'] as $role) {
            self::assertSame('posted', $this->repacks->book($this->staffUser($role), ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break',
                'packs' => 1])->status, $role);
        }
        foreach (['buyer', 'mapping_lead', 'goods_in', 'purchasing_desk', 'viewer', 'reviewer'] as $role) {
            self::refusedWith('role_not_allowed', fn () => $this->repacks->book($this->staffUser($role), ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def,
                'direction' => 'break', 'packs' => 1]));
        }
        self::refusedWith('admin_cannot_post', fn () => $this->repacks->book($this->staffUser('admin'), ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def,
            'direction' => 'break', 'packs' => 1]));
        self::assertSame(8, $this->onHand($box));
    }

    public function testCarriedCostIsReadAfterTheStockLocks(): void
    {
        [$def, $box] = $this->pack(10);
        $this->book('goods_in', $box, 1, 'MAIN', null, '10.00');
        $sc = $this->staffUser('stock_controller');
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        $docs = new Documents($conn, DocumentHandlers::all($conn));
        $ran = false;
        HookedStatement::$before = ['SELECT warehouse_id, sku_id, on_hand' => function () use ($box, &$ran): void {
            $ran = true;
            // A receipt commits while the repack is about to acquire its balance locks. The average is global across warehouses.
            $this->book('goods_in', $box, 1, 'VERIFY', null, '30.00');
        }];
        try {
            $doc = (new Repacks($conn, $docs))->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 1]);
        } finally {
            HookedStatement::$before = [];
        }
        self::assertTrue($ran);
        self::assertSame([['MAIN', 'adjustment', -1, null], ['MAIN', 'adjustment', 10, '2.000000']], $this->ledgerOf($doc->id));
    }

    public function testCarriedCostHoldsEveryPostingValueClockBeforeWritingTheLedger(): void
    {
        [$def, $box, $single] = $this->pack(10, 'legacy', 0, 1);
        $this->book('goods_in', $box, 1, 'MAIN', null, '10.00');
        $sc = $this->staffUser('stock_controller');
        $conn = TestDb::connect();
        $conn->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        $docs = new Documents($conn, DocumentHandlers::all($conn));
        $ran = false;
        HookedStatement::$before = ['INSERT INTO stock_ledger (' => function () use ($box, $single, &$ran): void {
            $ran = true;
            foreach ([$box, $single] as $sku) {
                self::$db->pdo()->beginTransaction();
                try {
                    self::assertSame(3572, self::mysqlError(fn () => self::$db->one(
                        'SELECT sku_id FROM stock_value_clock WHERE sku_id = ? FOR UPDATE NOWAIT', [$sku])), 'each value stream is frozen');
                } finally {
                    self::$db->pdo()->rollBack();
                }
            }
        }];
        try {
            (new Repacks($conn, $docs))->book($sc, ['warehouse' => self::warehouseId('MAIN'), 'pack' => $def, 'direction' => 'break', 'packs' => 1]);
        } finally {
            HookedStatement::$before = [];
        }
        self::assertTrue($ran);
    }
}
