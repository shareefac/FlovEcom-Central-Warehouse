<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Tests\Support\IntegrationTestCase;

/**
 * 0009 (I38-I47): the new tables, the seeds (12 settings, 6 VAT codes), the CHECKs that hold a supplier and a supplier
 * item to their rules even against admin SQL, and decision 25: no column that could hold bank details. 0009 has no
 * backfill, so it is tested on the slot's schema (TestDb::clean keeps app_setting and vat_code).
 */
final class Migration0009Test extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;
    private const DUPLICATE = 1062;
    private const FK = 1452;

    public function testTheTables(): void
    {
        $tables = array_map('strval', self::$db->column(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('app_setting', 'vat_code', 'supplier', "
            . "'supplier_item', 'supplier_item_price', 'import_run') AND ENGINE = 'InnoDB' AND TABLE_COLLATION = 'utf8mb4_0900_ai_ci' ORDER BY TABLE_NAME"));
        self::assertSame(['app_setting', 'import_run', 'supplier', 'supplier_item', 'supplier_item_price', 'vat_code'], $tables);
        foreach ($tables as $t) {
            self::assertNotNull(self::$db->value("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? "
                . "AND CONSTRAINT_TYPE = 'PRIMARY KEY'", [$t]), "{$t} has a primary key");
        }
        foreach (self::$db->all("SELECT TABLE_NAME, COLUMN_NAME, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME IN ('app_setting', 'supplier', 'supplier_item', 'supplier_item_price', 'import_run') AND DATA_TYPE = 'datetime'") as $c) {
            self::assertSame(6, (int) $c['DATETIME_PRECISION'], "{$c['TABLE_NAME']}.{$c['COLUMN_NAME']} is DATETIME(6)");
        }
        self::assertSame('preferred_sku_id', self::$db->value("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'supplier_item' AND EXTRA LIKE '%STORED GENERATED%'"));
    }

    /** Decision 25 (provisional, I39): no bank, IBAN, SWIFT/BIC, sort-code or account-number column anywhere near a supplier. */
    public function testNoBankDetailsAreKept(): void
    {
        $cols = array_map('strval', self::$db->column("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME IN ('supplier', 'supplier_item', 'supplier_item_price')"));
        self::assertGreaterThan(60, count($cols));
        foreach ($cols as $c) {
            self::assertDoesNotMatchRegularExpression('/bank|iban|swift|bic|sort_?code|account_?(no|number)/i', $c);
        }
    }

    public function testTheSeeds(): void
    {
        // The 12 keys 0009 seeds (later migrations add po.* (0010) and reorder.* (0011)); 0013 moves the nine company.* keys to
        // company_profile (Migration0013Test checks them on a schema migrated up to 0012, before the move).
        $settings = self::$db->all("SELECT setting_key, value_type, CAST(value_json AS CHAR) AS v, provisional, decision FROM app_setting "
            . "WHERE SUBSTRING_INDEX(setting_key, '.', 1) IN ('company', 'costs', 'suppliers') ORDER BY setting_key");
        self::assertCount(3, $settings);
        $by = array_column($settings, null, 'setting_key');
        self::assertSame(['costs.site_writeback', 'suppliers.approval_due_days', 'suppliers.change_review'], array_keys($by));
        foreach ($by as $k => $s) {
            self::assertSame(1, (int) $s['provisional'], "{$k}: provisional until the owner confirms it");
        }
        self::assertSame(['bool', 'false', '12'], [$by['costs.site_writeback']['value_type'], $by['costs.site_writeback']['v'], $by['costs.site_writeback']['decision']]);
        self::assertSame(['int', '3', '11'], [$by['suppliers.approval_due_days']['value_type'], $by['suppliers.approval_due_days']['v'], $by['suppliers.approval_due_days']['decision']]);
        self::assertSame(['bool', 'true'], [$by['suppliers.change_review']['value_type'], $by['suppliers.change_review']['v']]);
        self::assertSame(['system:migrate'], array_map('strval', self::$db->column('SELECT DISTINCT updated_actor FROM app_setting')));

        self::assertSame([['S', '20.00', 1], ['R', '5.00', 1], ['Z', '0.00', 1], ['E', '0.00', 1], ['RC', '0.00', 1], ['OS', '0.00', 1]],
            array_map(static fn (array $r): array => [(string) $r['code'], (string) $r['rate_percent'], (int) $r['is_active']],
                self::$db->all('SELECT code, rate_percent, is_active FROM vat_code ORDER BY sort_order')));
        foreach (['supplier', 'supplier_item', 'supplier_item_price', 'import_run'] as $t) {
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM ' . $t), $t);
        }
    }

    public function testTheChecksHoldEvenForAdminSql(): void
    {
        $staff = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m9', 'M9', 'm9@test.example', 'x')");
        $ins = static fn (array $over = []): int => self::$db->insert(
            'INSERT INTO supplier (code, name, status, country, currency, is_overseas, import_route, approved_by, approved_at, deactivated_at, '
            . 'import_route_approved_at, dd_checked_on, dd_next_review_on, created_actor, updated_actor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$over['code'] ?? 'ACME', 'Acme', $over['status'] ?? 'draft', $over['country'] ?? 'GB', $over['currency'] ?? 'GBP', $over['is_overseas'] ?? 0,
                $over['import_route'] ?? null, $over['approved_by'] ?? null, $over['approved_at'] ?? null, $over['deactivated_at'] ?? null,
                $over['import_route_approved_at'] ?? null, $over['dd_checked_on'] ?? null, $over['dd_next_review_on'] ?? null, 'system:test', 'system:test']);
        foreach ([
            ['code' => 'acme'], ['code' => 'A'], ['code' => '-ACME'], ['code' => 'ACME LTD'], ['code' => 'ACME!'], ['code' => "AC\u{00C9}"],
            ['country' => 'gb'], ['country' => 'G1'],
            ['currency' => 'EUR'],
            ['is_overseas' => 2],
            ['status' => 'active'],
            ['status' => 'inactive'],
            ['deactivated_at' => '2026-10-01 00:00:00'],
            ['import_route_approved_at' => '2026-10-01 00:00:00'],
            ['import_route_approved_at' => '2026-10-01 00:00:00', 'is_overseas' => 1],
            ['import_route_approved_at' => '2026-10-01 00:00:00', 'import_route' => 'stamped in Kent'],
            ['dd_checked_on' => '2026-10-01', 'dd_next_review_on' => '2026-09-30'],
        ] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $ins($bad)), json_encode($bad));
        }
        $ok = [
            ['code' => 'A1'], ['code' => 'ACME_LTD-2'], ['code' => str_repeat('B', 16)],
            ['code' => 'ACT', 'status' => 'active', 'approved_by' => $staff, 'approved_at' => '2026-10-01 00:00:00'],
            ['code' => 'OFF', 'status' => 'inactive', 'deactivated_at' => '2026-10-01 00:00:00'],
            ['code' => 'OVS', 'is_overseas' => 1, 'import_route' => 'stamped in Kent', 'import_route_approved_at' => '2026-10-01 00:00:00'],
            ['code' => 'DD', 'dd_checked_on' => '2026-10-01', 'dd_next_review_on' => '2026-10-01'],
        ];
        $ids = [];
        foreach ($ok as $row) {
            $ids[$row['code']] = $ins($row);
        }
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $ins(['code' => 'A1'])));
        self::assertSame(self::FK, self::mysqlError(static fn () => self::$db->exec("UPDATE supplier SET default_vat_code = 'XX' WHERE id = ?", [$ids['A1']])));
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => self::$db->exec("UPDATE supplier SET erp_name = 'Acme' WHERE id IN (?, ?)", [$ids['A1'], $ids['DD']])));

        // Supplier items: pack limits, flags, the last price triple, one active preferred row per item.
        $a = self::makeSku('Item A');
        $b = self::makeSku('Item B');
        $item = static fn (int $supplier, int $sku, array $over = []): int => self::$db->insert(
            'INSERT INTO supplier_item (supplier_id, sku_id, supplier_code, units_per_pack, moq_packs, order_multiple_packs, is_preferred, is_active, last_pack_price, '
            . 'last_price_on, last_price_source, created_actor, updated_actor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$supplier, $sku, $over['supplier_code'] ?? null, $over['units_per_pack'] ?? 1, $over['moq_packs'] ?? 1, $over['order_multiple_packs'] ?? 1,
                $over['is_preferred'] ?? 0, $over['is_active'] ?? 1, $over['last_pack_price'] ?? null, $over['last_price_on'] ?? null,
                $over['last_price_source'] ?? null, 'system:test', 'system:test']);
        foreach ([
            ['units_per_pack' => 0], ['units_per_pack' => 100_001], ['moq_packs' => 0], ['order_multiple_packs' => 10_001], ['is_preferred' => 2],
            ['last_pack_price' => '-1.0000', 'last_price_on' => '2026-10-01', 'last_price_source' => 'manual'],
            ['last_pack_price' => '1.0000'], ['last_pack_price' => '1.0000', 'last_price_on' => '2026-10-01'], ['last_price_source' => 'manual'],
        ] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $item($ids['A1'], $a, $bad)), json_encode($bad));
        }
        $p1 = $item($ids['A1'], $a, ['is_preferred' => 1, 'supplier_code' => 'X-1', 'last_pack_price' => '2.5000', 'last_price_on' => '2026-10-01',
            'last_price_source' => 'import']);
        self::assertSame($a, (int) self::$db->value('SELECT preferred_sku_id FROM supplier_item WHERE id = ?', [$p1]));
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $item($ids['ACT'], $a, ['is_preferred' => 1])), 'one preferred per item');
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $item($ids['A1'], $a)), 'one row per supplier, item and pack');
        self::assertSame(self::DUPLICATE, self::mysqlError(static fn () => $item($ids['A1'], $b, ['supplier_code' => 'x-1'])), 'a supplier code once per supplier');
        $off = $item($ids['ACT'], $a, ['is_preferred' => 1, 'is_active' => 0]);
        self::assertNull(self::$db->value('SELECT preferred_sku_id FROM supplier_item WHERE id = ?', [$off]), 'an inactive row is never the preferred supply');
        $item($ids['A1'], $a, ['units_per_pack' => 24]);
        $item($ids['A1'], $b);
        $item($ids['A1'], $b, ['units_per_pack' => 6, 'supplier_code' => null]);

        // Price history and import runs.
        $price = static fn (array $over = []): int => self::$db->insert(
            'INSERT INTO supplier_item_price (supplier_item_id, pack_price, units_per_pack, unit_price, source, effective_on, recorded_actor) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$over['item'] ?? $p1, $over['pack_price'] ?? '1.0000', $over['units_per_pack'] ?? 1, $over['unit_price'] ?? '1.000000', $over['source'] ?? 'manual',
                '2026-10-01', 'system:test']);
        $price();
        $price(['source' => 'po']);
        foreach ([['pack_price' => '-0.0001'], ['units_per_pack' => 0], ['unit_price' => '-1']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $price($bad)), json_encode($bad));
        }
        self::assertSame(self::FK, self::mysqlError(static fn () => $price(['item' => 999_999])));
        $run = static fn (string $sha, int $dry = 0): int => self::$db->insert(
            "INSERT INTO import_run (kind, file_name, file_sha256, dry_run, actor) VALUES ('erp_suppliers', 'f.csv', ?, ?, 'system:test')", [$sha, $dry]);
        $run(str_repeat('a', 64));
        foreach ([[str_repeat('A', 64), 0], [str_repeat('a', 63), 0], [str_repeat('a', 64), 2]] as [$sha, $dry]) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $run($sha, $dry)), $sha);
        }
        // Settings and VAT codes.
        foreach ([
            "INSERT INTO app_setting (setting_key, value_type, value_json, description) VALUES ('Company.x', 'string', '\"\"', 'x')",
            "INSERT INTO app_setting (setting_key, value_type, value_json, description) VALUES ('nodot', 'string', '\"\"', 'x')",
            "INSERT INTO app_setting (setting_key, value_type, value_json, provisional, description) VALUES ('a.b', 'string', '\"\"', 2, 'x')",
            "INSERT INTO vat_code (code, label, rate_percent) VALUES ('s2', 'x', 1)",
            "INSERT INTO vat_code (code, label, rate_percent) VALUES ('X', 'x', 100.01)",
        ] as $sql) {
            self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => self::$db->exec($sql)), $sql);
        }
        self::$db->exec("DELETE FROM supplier_item_price");
        self::$db->exec("DELETE FROM supplier_item");
        self::$db->exec("DELETE FROM supplier");
    }
}
