<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0074 (profit step 1; docs/decisions.md PR1-PR30), applied to a scratch schema that already holds a role grant: it only ADDS (nine new,
 * empty tables, five setting rows with their baselines, `finance` at the end of the job list); no existing row changes; its CHECKs; the
 * app login's rights on each new table; running every statement again changes nothing.
 */
final class Migration0074Test extends IntegrationTestCase
{
    private const FILE = '0074_profit.sql';
    private const TABLES = ['item_cost', 'item_cost_event', 'order_snapshot', 'profit_day', 'profit_item_day', 'profit_job', 'sale_line', 'sale_order', 'sale_parcel'];
    private const CHECK_VIOLATED = 3819;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m74', $this->dir);
        }
    }

    public function testItOnlyAddsAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        $statements = SqlSplitter::split($sql);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\b/i', $sql, 'nothing is deleted, changed or dropped');
        foreach ($statements as $st) {
            self::assertMatchesRegularExpression('/^(CREATE TABLE IF NOT EXISTS|INSERT INTO (app_setting|config_change)|ALTER TABLE staff_role MODIFY COLUMN role)\b/', $st,
                'only new tables, the settings and the job list');
        }
        self::assertSame(['Select', 'Insert'], Grants::desired('item_cost_event'), 'the price history is never rewritten');
        foreach (['order_snapshot', 'sale_order', 'sale_line', 'sale_parcel', 'item_cost', 'profit_job'] as $t) {
            self::assertSame(['Select', 'Insert', 'Update'], Grants::desired($t), "{$t}: never deleted by the app login");
        }
        self::assertSame(Grants::FULL, Grants::desired('profit_day'), 'a batch rebuilds a day');

        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m74', $files[$at - 1]);
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $staff = $db->insert("INSERT INTO staff_user (username, email, display_name, password_hash) VALUES ('m74', 'm74@example.com', 'M74', 'x')");
        $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'people_manager'), (?, 'auditor')", [$staff, $staff]);
        $roles = $db->all('SELECT id, role, CAST(role AS UNSIGNED) AS n FROM staff_role ORDER BY id');
        $settings = (int) $db->value('SELECT COUNT(*) FROM app_setting');

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $new = array_values(array_diff($db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), $tables));
        sort($new);
        self::assertSame(self::TABLES, $new);
        foreach (self::TABLES as $t) {
            self::assertSame(0, (int) $db->value("SELECT COUNT(*) FROM {$t}"), "{$t} is empty");
        }
        self::assertSame($roles, $db->all('SELECT id, role, CAST(role AS UNSIGNED) AS n FROM staff_role ORDER BY id'), 'every grant keeps its number');
        $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'finance')", [$staff]);
        self::assertSame(16, (int) $db->value("SELECT CAST(role AS UNSIGNED) FROM staff_role WHERE role = 'finance'"), 'the new job is number 16');
        self::assertSame($settings + 5, (int) $db->value('SELECT COUNT(*) FROM app_setting'));
        self::assertSame(['profit.before_history' => '"estimate"', 'profit.cost_refresh_on' => 'true', 'profit.cost_start' => '"2026-11-16"',
            'profit.erp_history_from' => '"2025-12-15"', 'profit.vat_rate_pct' => '"20"'],
            array_column($db->all("SELECT setting_key, CAST(value_json AS CHAR) AS v FROM app_setting WHERE setting_key LIKE 'profit.%' ORDER BY setting_key"), 'v', 'setting_key'));
        self::assertSame(5, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key LIKE 'profit.%' AND version = 1"));

        // The CHECKs: a paid order has its day; a line's cost is fixed whole; flags are 0 or 1.
        $ch = $db->insert("INSERT INTO channel (code, name) VALUES ('vapeandgo', 'Vape and Go')");
        $order = static fn (string $ref, ?string $paid, ?string $day): int => $db->exec('INSERT INTO sale_order (channel_id, order_ref, paid_at, day, vat_bp, '
            . 'snapshot_version, first_at, updated_at) VALUES (?, ?, ?, ?, 2000, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))', [$ch, $ref, $paid, $day]);
        $order('1', '2026-11-19 10:00:00', '2026-11-19');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $order('2', '2026-11-19 10:00:00', null)));
        $line = static fn (string $id, ?string $source, ?string $by, ?string $at, ?int $e4): int => $db->exec('INSERT INTO sale_line (channel_id, line_id, order_ref, '
            . 'variant_id, qty, price_inc, cost_source, costed_by, cost_at, unit_cost_e4, updated_at) VALUES (?, ?, \'1\', \'9\', 1, 100, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
            [$ch, $id, $source, $by, $at, $e4]);
        $line('a', 'cw_cost', 'sale', '2026-11-19 10:00:00', 10500);
        $line('b', 'none', 'sale', '2026-11-19 10:00:00', null);
        $line('c', null, null, null, null);
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $line('d', 'cw_cost', 'sale', '2026-11-19 10:00:00', null)), 'a price is named');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $line('e', 'cw_cost', null, '2026-11-19 10:00:00', 1)), 'who fixed it');
        self::assertSame(1452, self::mysqlError(static fn () => $db->exec("INSERT INTO sale_line (channel_id, line_id, order_ref, variant_id, qty, price_inc, updated_at) "
            . "VALUES (?, 'f', 'no such order', '9', 1, 1, UTC_TIMESTAMP(6))", [$ch])), 'a line belongs to a stored order');

        // Every statement again: nothing changes.
        foreach ($statements as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame($settings + 5, (int) $db->value('SELECT COUNT(*) FROM app_setting'));
        self::assertSame(5, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND subject_key LIKE 'profit.%'"));
        self::assertSame(3, (int) $db->value('SELECT COUNT(*) FROM sale_line'));
    }
}
