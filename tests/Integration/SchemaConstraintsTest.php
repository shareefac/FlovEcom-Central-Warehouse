<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\IntegrationTestCase;

/** The invariants 0001_core.sql enforces in the database itself (see docs/decisions.md). */
final class SchemaConstraintsTest extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;
    private const DUPLICATE = 1062;
    private const FK_CHILD = 1452;   // no parent row
    private const FK_PARENT = 1451;  // parent row still referenced

    public function testDayOneWarehousesAreSeeded(): void
    {
        self::assertSame(
            [['code' => 'MAIN', 'is_sellable' => 1], ['code' => 'VERIFY', 'is_sellable' => 0], ['code' => 'UNSTAMPED', 'is_sellable' => 0]],
            self::$db->all('SELECT code, is_sellable FROM warehouse ORDER BY id'),
        );
    }

    public function testAllocatedAndHeldCannotGoNegativeButOnHandCan(): void
    {
        $sku = self::makeSku();
        $main = self::warehouseId('MAIN');
        self::$db->exec('INSERT INTO stock_balance (warehouse_id, sku_id) VALUES (?, ?)', [$main, $sku]);

        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            'UPDATE stock_balance SET allocated = allocated - 1 WHERE warehouse_id = ? AND sku_id = ?', [$main, $sku])));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            'UPDATE stock_balance SET held = -1 WHERE warehouse_id = ? AND sku_id = ?', [$main, $sku])));

        self::$db->exec('UPDATE stock_balance SET on_hand = -3 WHERE warehouse_id = ? AND sku_id = ?', [$main, $sku]);
        self::assertSame(-3, self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ?', [$sku]));
    }

    public function testLedgerRowsNeedABalanceRowAndNonNegativeBucketBalances(): void
    {
        $sku = self::makeSku();
        $main = self::warehouseId('MAIN');
        $insert = fn (string $bucket, int $after) => self::$db->exec(
            "INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, actor) VALUES (?, ?, ?, 1, ?, 'test', 'system:test')",
            [$main, $sku, $bucket, $after],
        );
        self::assertSame(self::FK_CHILD, self::mysqlError(fn () => $insert('on_hand', 1)));

        self::$db->exec('INSERT INTO stock_balance (warehouse_id, sku_id) VALUES (?, ?)', [$main, $sku]);
        $insert('on_hand', -5);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $insert('allocated', -1)));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $insert('held', -1)));
    }

    public function testOneSellableWarehousePerChannel(): void
    {
        $channel = self::makeChannel();
        $main = self::warehouseId('MAIN');
        $verify = self::warehouseId('VERIFY');
        $second = self::$db->insert("INSERT INTO warehouse (code, name, is_sellable) VALUES ('SECOND', 'Second building', 1)");

        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$channel, $main]);
        // a second sellable warehouse for the same channel is refused
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => self::$db->exec(
            'INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$channel, $second])));
        // a non-sellable assignment is allowed alongside the sellable one
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 0)', [$channel, $verify]);
        // a warehouse cannot change sellability while a channel is assigned to it
        self::assertSame(self::FK_PARENT, self::mysqlError(fn () => self::$db->exec(
            'UPDATE warehouse SET is_sellable = 0 WHERE id = ?', [$main])));

        $other = self::makeChannel('alt');
        // the copied flag must match the warehouse: VERIFY cannot be claimed as sellable
        self::assertSame(self::FK_CHILD, self::mysqlError(fn () => self::$db->exec(
            'INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$other, $verify])));
        // another channel may use the same sellable warehouse
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$other, $main]);

        self::assertSame(2, self::$db->value('SELECT COUNT(*) FROM channel_warehouse WHERE is_sellable = 1'));
    }

    public function testListingStatusAndLinkAgree(): void
    {
        $channel = self::makeChannel();
        $sku = self::makeSku();
        $ins = fn (string $variant, string $status, ?int $skuId) => self::$db->insert(
            'INSERT INTO channel_listing (channel_id, external_variant_id, status, sku_id) VALUES (?, ?, ?, ?)',
            [$channel, $variant, $status, $skuId],
        );
        $ins('1', 'unmapped', null);
        $ins('2', 'mapped', $sku);
        $ins('3', 'quarantined', $sku);
        $ins('4', 'ignored', null);
        $ins('5', 'suggested', null);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $ins('6', 'mapped', null)));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $ins('7', 'unmapped', $sku)));
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => $ins('1', 'unmapped', null)));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO channel_listing (channel_id, external_variant_id, units_per_item) VALUES (?, 'x', 0)", [$channel])));
    }

    public function testSkuCodeFormatAndUniqueness(): void
    {
        $a = self::makeSku('A');
        self::assertMatchesRegularExpression('/^CW-\d{6}$/', (string) self::$db->value('SELECT code FROM sku WHERE id = ?', [$a]));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec("INSERT INTO sku (code, name) VALUES ('X-1', 'bad')")));
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => self::$db->exec(
            'INSERT INTO sku (code, name) VALUES (?, ?)', [sprintf('CW-%06d', $a), 'dup'])));
        self::$db->exec("INSERT INTO sku (code, name) VALUES ('CW-1234567', 'seven digits ok')");
    }

    public function testReservationRules(): void
    {
        $channel = self::makeChannel();
        self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, expires_at) VALUES (?, '1001', 'held', UTC_TIMESTAMP(6) + INTERVAL 40 MINUTE)", [$channel]);
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO reservation (channel_id, order_ref, status) VALUES (?, '1001', 'committed')", [$channel])));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO reservation (channel_id, order_ref, status) VALUES (?, '1002', 'held')", [$channel])));
        self::$db->exec("INSERT INTO reservation (channel_id, order_ref, status, is_tombstone) VALUES (?, '1003', 'released', 1)", [$channel]);
        $res = (int) self::$db->value("SELECT id FROM reservation WHERE order_ref = '1001'");

        $unit = fn (string $id, string $state, ?string $dispatched) => self::$db->exec(
            'INSERT INTO reservation_unit (channel_id, unit_id, reservation_id, listing_id, warehouse_id, state, dispatched_at) VALUES (?, ?, ?, 1, 1, ?, ?)',
            [$channel, $id, $res, $state, $dispatched],
        );
        $unit('5001', 'held', null);
        $unit('5002', 'shipped', '2026-09-26 10:00:00');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $unit('5003', 'shipped', null)));
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => $unit('5001', 'held', null)));
    }

    public function testIdempotencyKeysAreCaseSensitive(): void
    {
        $channel = self::makeChannel();
        $ins = fn (string $key) => self::$db->exec(
            "INSERT INTO idempotency (channel_id, idem_key, method, path, request_hash, response_status, response_body) VALUES (?, ?, 'POST', '/v1/reservations', ?, 201, JSON_OBJECT('ok', TRUE))",
            [$channel, $key, str_repeat('a', 64)],
        );
        $ins('commit:1001:aBc');
        $ins('commit:1001:ABC');
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => $ins('commit:1001:aBc')));
    }

    public function testChannelKeyIpsAndTtlChecks(): void
    {
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO channel (code, name, api_key_hash) VALUES ('a', 'A', 'not-a-sha256')")));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO channel (code, name, allowed_ips) VALUES ('b', 'B', JSON_OBJECT('ip', '1.2.3.4'))")));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO channel (code, name, reserve_ttl_sec) VALUES ('c', 'C', 5)")));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO channel (code, name) VALUES ('Bad Code', 'D')")));
        $id = self::makeChannel('ok');
        self::assertSame('off', self::$db->value('SELECT mode FROM channel WHERE id = ?', [$id]), 'a new channel starts off');
        self::assertNull(self::$db->value('SELECT api_key_hash FROM channel WHERE id = ?', [$id]), 'no key = fail closed');
    }

    /** C0 (0006, I1, I2): a cost belongs on an on_hand row of a cost-bearing movement, in GBP, with its source; a document line needs its document. */
    public function testLedgerCostAndDocumentChecks(): void
    {
        $sku = self::makeSku();
        $main = self::warehouseId('MAIN');
        self::$db->exec('INSERT INTO stock_balance (warehouse_id, sku_id) VALUES (?, ?)', [$main, $sku]);
        $row = fn (array $over): int => self::$db->insert(
            'INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, actor, unit_cost, cost_currency, cost_source, document_id, document_line) '
            . "VALUES (?, ?, ?, 1, 1, ?, 'system:test', ?, ?, ?, ?, ?)",
            [$main, $sku, ...array_values(array_merge(['bucket' => 'on_hand', 'movement_type' => 'goods_in', 'unit_cost' => '1.250000',
                'cost_currency' => 'GBP', 'cost_source' => 'manual', 'document_id' => null, 'document_line' => null], $over))],
        );
        $row([]);
        $row(['cost_source' => 'document', 'document_id' => 7, 'document_line' => 3]);
        $row(['unit_cost' => '0', 'movement_type' => 'count']);
        $row(['unit_cost' => null, 'cost_currency' => null, 'cost_source' => null, 'movement_type' => 'erp_sale', 'document_id' => 7]);
        foreach ([
            'a cost on a held row' => ['bucket' => 'held', 'movement_type' => 'reserve'],
            'a cost on erp_sale' => ['movement_type' => 'erp_sale'],
            'a cost on trade_sale' => ['movement_type' => 'trade_sale'],
            'a negative cost' => ['unit_cost' => '-0.01'],
            'a cost without currency' => ['cost_currency' => null],
            'a cost without source' => ['cost_source' => null],
            'a currency without cost' => ['unit_cost' => null, 'cost_source' => null],
            'currency EUR' => ['cost_currency' => 'EUR'],
            'cost_source document without document_id' => ['cost_source' => 'document'],
            'document_line without document_id' => ['document_line' => 1],
        ] as $label => $over) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $row($over)), $label);
        }
        self::assertSame(4, (int) self::$db->value('SELECT COUNT(*) FROM stock_ledger'));
    }

    /** C0 (0006, I3, I5): seqs start at 1; one value entry per ledger movement; only movements carry quantities. */
    public function testValueSequenceAndValueLedgerChecks(): void
    {
        $sku = self::makeSku();
        self::$db->exec('INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id) VALUES (?, 1, 10)', [$sku]);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec('INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id) VALUES (?, 0, 11)', [$sku])));
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => self::$db->exec('INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id) VALUES (?, 1, 12)', [$sku])));
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => self::$db->exec('INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id) VALUES (?, 2, 10)', [$sku])));

        $entry = fn (string $kind, ?int $ledgerId, ?int $valueSeq, int $qty, ?string $cost = '1.000000', ?int $docLine = null): int => self::$db->insert(
            'INSERT INTO stock_value_ledger (sku_id, kind, stock_ledger_id, value_seq, qty_delta, unit_cost, value_delta, qty_after, value_after, cost_source, '
            . "document_id, document_line, effective_at, actor) VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 'average', NULL, ?, UTC_TIMESTAMP(6), 'system:test')",
            [$sku, $kind, $ledgerId, $valueSeq, $qty, $cost, $docLine],
        );
        $entry('movement', 10, 1, 5);
        $entry('landed', 10, null, 0);           // a value-only entry may point at the movement it adds to
        $entry('trueup', null, null, 0, null);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $entry('movement', null, 1, 5)), 'a movement without a ledger id');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $entry('movement', 11, null, 5)), 'a movement without its seq');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $entry('cost_adjust', 10, null, 5)), 'a value-only kind with a quantity');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $entry('landed', null, null, 0, '-1')), 'a negative cost');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => $entry('landed', null, null, 0, null, 2)), 'a document line without its document');
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => $entry('movement', 10, 1, 5)), 'one movement entry per ledger row');
        self::assertSame(3, (int) self::$db->value('SELECT COUNT(*) FROM stock_value_ledger'));
        self::assertSame([10, null, null], array_map(static fn ($v): ?int => $v === null ? null : (int) $v,
            self::$db->column('SELECT movement_ledger_id FROM stock_value_ledger ORDER BY id')));
    }

    public function testQueueRowsNeedAResolutionTimeOnceClosed(): void
    {
        $sku = self::makeSku();
        $main = self::warehouseId('MAIN');
        self::$db->exec('INSERT INTO stock_balance (warehouse_id, sku_id) VALUES (?, ?)', [$main, $sku]);
        self::$db->exec("INSERT INTO oversell_event (warehouse_id, sku_id, kind, shortfall, available_after) VALUES (?, ?, 'commit_short', 1, -1)", [$main, $sku]);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO oversell_event (warehouse_id, sku_id, kind, shortfall, available_after) VALUES (?, ?, 'commit_short', 0, 0)", [$main, $sku])));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec("UPDATE oversell_event SET status = 'resolved'")));
        self::$db->exec("UPDATE oversell_event SET status = 'resolved', resolution = 'refunded', resolved_at = UTC_TIMESTAMP(6)");
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO policy_review (source, proposed_policy) VALUES ('erp_update_product', 'strict')")));
        self::$db->exec("INSERT INTO count_review (warehouse_id, sku_id, source, proposed_qty, dedupe_key) VALUES (?, ?, 'erp_reconciliation', 12, 'SR-0001:0')", [$main, $sku]);
        self::assertSame(self::DUPLICATE, self::mysqlError(fn () => self::$db->exec(
            "INSERT INTO count_review (warehouse_id, sku_id, source, proposed_qty, dedupe_key) VALUES (?, ?, 'erp_reconciliation', 12, 'SR-0001:0')", [$main, $sku])));
    }
}
