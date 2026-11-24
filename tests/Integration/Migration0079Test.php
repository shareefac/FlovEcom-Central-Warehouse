<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Auth\Permissions;
use CW\Db;
use CW\Schema\Grants;
use CW\Schema\SqlSplitter;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0079 (CW-1150 expenses and regular costs; docs/decisions.md EX1-), applied to a scratch schema at 0077 (the live release's last) that
 * already holds a role grant: it only ADDS (five new tables, the start lists of the types with their history, the new job at the end of the
 * list, the two permission rows with their baselines); no existing row changes; its CHECKs (money in whole pence, total = net + VAT, a
 * delete says who and why, a regular cost's last day after its first); the app login's rights (money rows never removed, the history
 * append-only); running every statement again changes nothing.
 */
final class Migration0079Test extends IntegrationTestCase
{
    private const FILE = '0079_expenses.sql';
    private const CHECK_VIOLATED = 3819;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m79', $this->dir);
        }
    }

    public function testItOnlyAddsAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        $statements = SqlSplitter::split($sql);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\b/i', $sql, 'nothing is deleted, changed or dropped');
        foreach ($statements as $st) {
            self::assertMatchesRegularExpression('/^(CREATE TABLE IF NOT EXISTS (expense_type|expense_supplier|expense|regular_cost|expense_history) |INSERT INTO '
                . '(expense_type|expense_history|config_change|role_permission) |ALTER TABLE staff_role MODIFY COLUMN role )/', $st);
        }
        self::assertSame(['Select', 'Insert'], Grants::desired('expense'), 'an expense is never removed: deleted by a mark');
        self::assertSame(['Select', 'Insert'], Grants::desired('regular_cost'));
        self::assertSame(['Select', 'Insert'], Grants::desired('expense_supplier'));
        self::assertSame(['Select', 'Insert'], Grants::desired('expense_type'));
        self::assertSame(['Select', 'Insert'], Grants::desired('expense_history'));
        self::assertSame([], Grants::desiredColumns('expense_history'), 'the history is append-only');
        foreach (['id', 'created_by', 'created_actor', 'created_at'] as $frozen) {
            self::assertArrayNotHasKey($frozen, Grants::desiredColumns('expense'), $frozen);
            self::assertArrayNotHasKey($frozen, Grants::desiredColumns('regular_cost'), $frozen);
        }
        foreach (['channel_id', 'every', 'amount_pence', 'from_day', 'replaces_id'] as $frozen) {
            self::assertArrayNotHasKey($frozen, Grants::desiredColumns('regular_cost'), "a regular cost's {$frozen} never changes: a new line");
        }

        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m79', $files[$at - 1]);
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $ch = $db->insert("INSERT INTO channel (code, name) VALUES ('vapeandgo', 'Vape and Go')");
        $staff = $db->insert("INSERT INTO staff_user (username, email, display_name, password_hash) VALUES ('m79', 'm79@example.com', 'M79', 'x')");
        $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'finance'), (?, 'delivery_bills')", [$staff, $staff]);
        $roles = $db->all('SELECT id, role, CAST(role AS UNSIGNED) AS n FROM staff_role ORDER BY id');
        $perms = $db->all('SELECT permission, roles, updated_actor FROM role_permission ORDER BY permission');

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $new = array_values(array_diff($db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), $tables));
        sort($new);
        self::assertSame(['expense', 'expense_history', 'expense_supplier', 'expense_type', 'regular_cost'], $new);
        self::assertSame([['expense', 'Stationery and packaging'], ['expense', 'Software and AI'], ['expense', 'Developer'], ['expense', 'Fees'], ['expense', 'Other'],
            ['regular', 'Rent'], ['regular', 'Running the business'], ['regular', 'Marketing, website and people'], ['regular', 'Other']],
            array_map(static fn (array $r): array => [$r['kind'], $r['name']], $db->all('SELECT kind, name FROM expense_type ORDER BY kind, sort_order')));
        self::assertSame(9, (int) $db->value("SELECT COUNT(*) FROM expense_history WHERE subject = 'type' AND version = 1 AND action = 'add' AND actor = 'system:migrate'"));
        self::assertSame($roles, $db->all('SELECT id, role, CAST(role AS UNSIGNED) AS n FROM staff_role ORDER BY id'), 'every grant keeps its number');
        $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'expenses')", [$staff]);
        self::assertSame(18, (int) $db->value("SELECT CAST(role AS UNSIGNED) FROM staff_role WHERE role = 'expenses'"), 'the new job is number 18');
        self::assertSame('expenses', Permissions::ROLES[17]);
        $want = [...$perms, ['permission' => 'expenses.edit', 'roles' => 'expenses', 'updated_actor' => 'system:migrate'],
            ['permission' => 'expenses.view', 'roles' => 'finance,expenses', 'updated_actor' => 'system:migrate']];
        self::assertSame($want, $db->all("SELECT permission, roles, updated_actor FROM role_permission ORDER BY permission LIKE 'expenses.%', permission"));
        self::assertSame(Permissions::MAP['expenses.view'], explode(',', (string) $db->value("SELECT roles FROM role_permission WHERE permission = 'expenses.view'")));
        self::assertSame(Permissions::MAP['expenses.edit'], explode(',', (string) $db->value("SELECT roles FROM role_permission WHERE permission = 'expenses.edit'")));
        self::assertSame(2, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'permission' AND subject_key LIKE 'expenses.%' AND version = 1 "
            . "AND action = 'baseline'"));

        // The CHECKs.
        $sup = $db->insert("INSERT INTO expense_supplier (name, created_actor, created_at) VALUES ('Viking Direct', 'system:x', NOW(6))");
        $type = (int) $db->value("SELECT id FROM expense_type WHERE kind = 'expense' AND name = 'Fees'");
        $expense = static fn (array $over = []): int => $db->insert('INSERT INTO expense (channel_id, supplier_id, type_id, invoice_date, invoice_no, net_pence, vat_pence, '
            . 'total_pence, created_actor, created_at, updated_actor, updated_at, deleted_at, deleted_actor, deleted_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(6), ?, NOW(6), ?, ?, ?)',
            array_values(array_replace(['ch' => $ch, 'sup' => $sup, 'type' => $type, 'day' => '2026-11-12', 'no' => '1', 'net' => 6420, 'vat' => 1284, 'total' => 7704,
                'actor' => 'system:x', 'up' => 'system:x', 'del_at' => null, 'del_actor' => null, 'del_why' => null], $over)));
        $expense();
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $expense(['total' => 7705])), 'total = net + VAT');
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $expense(['net' => 0, 'total' => 1284])), 'an expense of nothing');
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $expense(['vat' => -1, 'total' => 6419])));
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $expense(['del_at' => '2026-11-20 10:00:00', 'del_actor' => 'system:x'])), 'a delete says why');
        $expense(['del_at' => '2026-11-20 10:00:00', 'del_actor' => 'system:x', 'del_why' => 'twice']);
        $cost = static fn (array $over = []): int => $db->insert('INSERT INTO regular_cost (name, channel_id, type_id, every, amount_pence, from_day, until_day, created_actor, '
            . 'created_at, updated_actor, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(6), ?, NOW(6))',
            array_values(array_replace(['name' => 'Unit 1', 'ch' => $ch, 'type' => $type, 'every' => 'week', 'amount' => 20769, 'from' => '2026-11-01', 'until' => null,
                'actor' => 'system:x', 'up' => 'system:x'], $over)));
        $cost();
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $cost(['until' => '2026-10-31'])), 'its last day after its first');
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $cost(['amount' => 0])));
        self::assertSame(1265, self::err(static fn () => $cost(['every' => 'day'])), 'week, month or year only');
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $db->exec("INSERT INTO expense_history (subject, subject_id, version, action, state, actor) "
            . "VALUES ('expense', 1, 2, 'add', '{}', 'system:x')")), 'version 1 is the add');

        // Every statement again: nothing changes (a holder given on the roles screen meanwhile stays; a renamed start type is not added again).
        $db->exec("UPDATE role_permission SET roles = 'finance' WHERE permission = 'expenses.view'");
        $db->exec("UPDATE expense_type SET name = 'Bank and card fees' WHERE kind = 'expense' AND name = 'Fees'");
        foreach ($statements as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame('finance', (string) $db->value("SELECT roles FROM role_permission WHERE permission = 'expenses.view'"));
        self::assertSame(2, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'permission' AND subject_key LIKE 'expenses.%'"));
        self::assertSame(9, (int) $db->value('SELECT COUNT(*) FROM expense_type'));
        self::assertSame(9, (int) $db->value("SELECT COUNT(*) FROM expense_history WHERE subject = 'type'"));
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM expense'));
    }

    private static function err(callable $fn): int
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            return (int) Db::driverCode($e);
        }
        self::fail('expected a MySQL error');
    }
}
