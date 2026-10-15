<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigInvariants;
use CW\Schema\Grants;
use CW\Settings;
use CW\Tests\Support\IntegrationTestCase;

/**
 * 0041 (pack J, the owner's day-one purchasing workflow; docs/decisions.md PJ1-PJ16) on the migrated test schema: the fourth bucket
 * (stock_balance.incoming, never negative; 'incoming' in the ledger's buckets, the ledger's checks kept), the invoice's way of
 * coming in and its time, the stamp answer on a line, the three new tables with their checks and grants, the three settings with
 * their baselines and rules; the configuration checks K1-K4 hold.
 */
final class Migration0041Test extends IntegrationTestCase
{
    private const CHECK = 3819;

    private static function columnType(string $table, string $column): string
    {
        return (string) self::$db->value('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]);
    }

    /** @return list<string> */
    private static function checks(string $table): array
    {
        return array_map('strval', self::$db->column('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            . "AND CONSTRAINT_TYPE = 'CHECK' ORDER BY CONSTRAINT_NAME", [$table]));
    }

    /** @param list<mixed> $params */
    private static function err(string $sql, array $params = []): ?int
    {
        try {
            self::$db->exec($sql, $params);
        } catch (\PDOException $e) {
            return \CW\Db::driverCode($e);
        }
        return null;
    }

    public function testTheFourthBucketAndTheLedger(): void
    {
        self::assertSame('int', self::columnType('stock_balance', 'incoming'));
        self::assertSame('0', (string) self::$db->value("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_balance' "
            . "AND COLUMN_NAME = 'incoming'"));
        self::assertContains('ck_stock_balance_incoming', self::checks('stock_balance'));
        self::assertSame("enum('on_hand','allocated','held','incoming')", self::columnType('stock_ledger', 'bucket'), "'incoming' at the end: the old rows keep their values");
        self::assertContains('ck_stock_ledger_nonneg', self::checks('stock_ledger'), 'the ledger\'s checks survive the copy');
        self::assertContains('ck_stock_ledger_cost', self::checks('stock_ledger'));
        $wh = self::warehouseId('MAIN');
        $sku = self::makeSku('Migration 41 item');
        self::assertSame(self::CHECK, self::err('INSERT INTO stock_balance (warehouse_id, sku_id, on_hand, allocated, held, incoming) VALUES (?, ?, 0, 0, 0, -1)', [$wh, $sku]),
            'never below zero');
        self::assertNull(self::err('INSERT INTO stock_balance (warehouse_id, sku_id, on_hand, allocated, held, incoming) VALUES (?, ?, 0, 0, 0, 3)', [$wh, $sku]));
        self::$db->exec('DELETE FROM stock_balance WHERE warehouse_id = ? AND sku_id = ?', [$wh, $sku]);
    }

    public function testTheInvoiceItsTimeAndItsLines(): void
    {
        self::assertSame("enum('count','received')", self::columnType('supplier_invoice', 'receive_mode'));
        self::assertSame("enum('digital','transitional')", self::columnType('sinv_line', 'stamp_answer'));
        foreach (['submit_at' => 'datetime(6)', 'submit_by' => 'int unsigned', 'submit_failed_at' => 'datetime(6)', 'submit_error' => 'varchar(500)',
            'submit_receipt_id' => 'bigint unsigned'] as $c => $type) {
            self::assertSame($type, self::columnType('supplier_invoice', $c), $c);
        }
        self::assertContains('ck_supplier_invoice_submit', self::checks('supplier_invoice'));
        $sup = self::$db->insert("INSERT INTO supplier (code, name, created_actor, updated_actor) VALUES ('M41', 'Migration 41 Ltd', 'system:test', 'system:test')");
        $staff = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m41', 'M41', 'm41@test.example', 'x')");
        $doc = self::$db->insert("INSERT INTO document (doc_type, created_actor) VALUES ('SINV', 'test')");
        self::$db->exec("INSERT INTO supplier_invoice (document_id, kind, supplier_id, invoice_key, receive_mode) VALUES (?, 'invoice', ?, 'M41-1', 'count')", [$doc, $sup]);
        $set = 'UPDATE supplier_invoice SET submit_at = ?, submit_by = ?, submit_actor = ?, submit_failed_at = ?, submit_error = ? WHERE document_id = ?';
        self::assertSame(self::CHECK, self::err($set, ['2026-10-16 07:00:00', null, null, null, null, $doc]), 'a time names whose posting it is');
        self::assertSame(self::CHECK, self::err($set, [null, null, null, '2026-10-16 07:00:00', 'no', $doc]), 'a failure belongs to a time');
        self::assertSame(self::CHECK, self::err($set, ['2026-10-16 07:00:00', $staff, 'staff:x', '2026-10-16 07:00:00', null, $doc]), 'a failure keeps its reason');
        self::assertNull(self::err($set, ['2026-10-16 07:00:00', $staff, 'staff:x', null, null, $doc]));
        self::assertSame(self::CHECK, self::err('UPDATE supplier_invoice SET submit_receipt_id = ? WHERE document_id = ?', [$doc, $doc]),
            'a receipt booked at once only for "book as received"');
        $cn = self::$db->insert("INSERT INTO document (doc_type, created_actor) VALUES ('SCN', 'test')");
        self::assertSame(self::CHECK, self::err("INSERT INTO supplier_invoice (document_id, kind, supplier_id, invoice_key, receive_mode) VALUES (?, 'credit_note', ?, 'M41-C', 'count')",
            [$cn, $sup]), 'a credit note brings no goods');
    }

    public function testTheTablesTheirChecksAndGrants(): void
    {
        self::assertSame(['Select', 'Insert', 'Update'], Grants::desired('invoice_arrival'), 'never deleted: a reversal cancels or reopens');
        self::assertSame(['Select', 'Insert', 'Update'], Grants::desired('supplier_arrival_pref'), 'changed with audit, never deleted');
        self::assertSame(['Select', 'Insert'], Grants::desired('goods_receipt_invoice'), 'append-only');
        self::assertContains('ck_invoice_arrival_state', self::checks('invoice_arrival'));
        self::assertContains('ck_goods_receipt_invoice_auto', self::checks('goods_receipt_invoice'));
        self::assertContains('ck_supplier_arrival_pref_flag', self::checks('supplier_arrival_pref'));
        self::assertSame("enum('open','received','cancelled')", self::columnType('invoice_arrival', 'state'));
        self::assertSame("enum('count','received')", self::columnType('supplier_arrival_pref', 'default_mode'));
    }

    public function testTheSettings(): void
    {
        $s = [];
        foreach (self::$db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting WHERE setting_key LIKE 'arrivals.%' "
            . 'ORDER BY setting_key') as $r) {
            $s[(string) $r['setting_key']] = [$r['value_type'], $r['v'], (int) $r['provisional'], $r['decision']];
        }
        self::assertSame([
            'arrivals.default_mode' => ['string', '"count"', 1, 'PJ6'],
            'arrivals.late_hours' => ['int', '24', 1, 'PJ12'],
            'arrivals.sell_before_default' => ['bool', 'true', 1, 'PJ5'],
        ], $s, 'count on arrival, sold before it arrives, late after a day; every one provisional (a build decision put to the owner)');
        $settings = new Settings(self::$db);
        self::assertSame(['count', 24, true], [$settings->get('arrivals.default_mode'), $settings->get('arrivals.late_hours'), $settings->get('arrivals.sell_before_default')]);
        self::assertSame(['in' => ['count', 'received']], Settings::RULES['arrivals.default_mode']);
        self::assertSame(['min' => 1, 'max' => 720], Settings::RULES['arrivals.late_hours']);
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND action = 'baseline' AND version = 1 "
            . "AND actor = 'system:migrate' AND subject_key LIKE 'arrivals.%'"));
        self::assertSame([], ConfigInvariants::check(self::$db), 'K1-K4: the new rows have their history');
    }
}
