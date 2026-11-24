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
 * 0077 (delivery cost in profit, step 2 part 1; docs/decisions.md DL1, DL2, DL11, DL15), applied to a scratch schema at 0076 that already
 * holds a day summary and a role grant: it only ADDS (two new, empty tables; two columns of profit_day at 0 on its existing row; the new
 * job at the end of the list; the two permission rows with their baselines); no existing row changes; its CHECKs; the app login's rights
 * (a bill and its lines never deleted, only their state and match written); running every statement again changes nothing.
 */
final class Migration0077Test extends IntegrationTestCase
{
    private const FILE = '0077_delivery_bills.sql';
    private const CHECK_VIOLATED = 3819;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m77', $this->dir);
        }
    }

    public function testItOnlyAddsAndRunsAgainCleanly(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/' . self::FILE);
        $statements = SqlSplitter::split($sql);
        self::assertDoesNotMatchRegularExpression('/\bDELETE\s+FROM\b|\bUPDATE\s+\w+\s+SET\b|\bDROP\b/i', $sql, 'nothing is deleted, changed or dropped');
        foreach ($statements as $st) {
            self::assertMatchesRegularExpression('/^(CREATE TABLE IF NOT EXISTS carrier_invoice(_line)?|INSERT INTO (config_change|role_permission)|ALTER TABLE staff_role '
                . 'MODIFY COLUMN role|SET @cw_0077_profit_day = |(PREPARE|EXECUTE|DEALLOCATE PREPARE) cw_0077_profit_day)\b/', $st,
                'the two tables, the two permissions\' rows, the job list and the two columns of profit_day while they are not there');
        }
        self::assertStringContainsString('ALGORITHM = INSTANT', $sql, 'profit_day is not copied');
        self::assertSame(['Select', 'Insert'], Grants::desired('carrier_invoice'), 'a bill is never deleted');
        // + supersedes_id since 0078 round 12 (DL90 F10): a pooled correction names the bill it replaces only when it is made live
        self::assertSame(['status', 'ended_by', 'ended_actor', 'ended_at', 'supersedes_id'], Grants::UPDATE_COLUMNS['carrier_invoice'],
            'only whether it counts, who ended it, and (once, when made live) the bill it replaced');
        self::assertSame(['Select', 'Insert'], Grants::desired('carrier_invoice_line'));
        self::assertSame(['match_state', 'channel_id', 'order_ref', 'parcel_ref', 'candidates', 'matched_at'], Grants::UPDATE_COLUMNS['carrier_invoice_line'],
            'a line\'s content is frozen: only its match');
        self::assertSame(Grants::FULL, Grants::desired('profit_day'), 'a batch rebuilds a day (as before)');

        $files = array_map('basename', glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        $at = array_search(self::FILE, $files, true);
        self::assertIsInt($at);
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m77', $files[$at - 1]);
        $tables = $db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $ch = $db->insert("INSERT INTO channel (code, name) VALUES ('vapeandgo', 'Vape and Go')");
        $db->exec("INSERT INTO profit_day (channel_id, day, orders, sale_ex, cost) VALUES (?, '2026-11-19', 3, 1000, 600)", [$ch]);
        $staff = $db->insert("INSERT INTO staff_user (username, email, display_name, password_hash) VALUES ('m77', 'm77@example.com', 'M77', 'x')");
        $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'finance'), (?, 'auditor')", [$staff, $staff]);
        $roles = $db->all('SELECT id, role, CAST(role AS UNSIGNED) AS n FROM staff_role ORDER BY id');
        $perms = $db->all('SELECT permission, roles, updated_actor FROM role_permission ORDER BY permission');
        $day = $db->one('SELECT * FROM profit_day');

        self::assertSame([self::FILE], MigrationFixture::migrateRest($db, $this->dir, self::FILE));
        $new = array_values(array_diff($db->column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), $tables));
        sort($new);
        self::assertSame(['carrier_invoice', 'carrier_invoice_line'], $new);
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM carrier_invoice') + (int) $db->value('SELECT COUNT(*) FROM carrier_invoice_line'));
        self::assertSame(array_map('strval', (array) $day) + ['delivery_cost' => '0', 'delivery_other' => '0'], array_map('strval', (array) $db->one('SELECT * FROM profit_day')),
            'the existing day keeps its figures, the two new ones at 0');
        self::assertSame($roles, $db->all('SELECT id, role, CAST(role AS UNSIGNED) AS n FROM staff_role ORDER BY id'), 'every grant keeps its number');
        $db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'delivery_bills')", [$staff]);
        self::assertSame(17, (int) $db->value("SELECT CAST(role AS UNSIGNED) FROM staff_role WHERE role = 'delivery_bills'"), 'the new job is number 17');
        self::assertSame('delivery_bills', Permissions::ROLES[16]);
        $want = [...$perms, ['permission' => 'delivery.upload', 'roles' => 'delivery_bills', 'updated_actor' => 'system:migrate'],
            ['permission' => 'delivery.view', 'roles' => 'finance,delivery_bills', 'updated_actor' => 'system:migrate']];
        $got = $db->all("SELECT permission, roles, updated_actor FROM role_permission ORDER BY permission LIKE 'delivery.%', permission");
        self::assertSame($want, $got);
        self::assertSame(Permissions::MAP['delivery.view'], explode(',', (string) $db->value("SELECT roles FROM role_permission WHERE permission = 'delivery.view'")));
        self::assertSame(Permissions::MAP['delivery.upload'], explode(',', (string) $db->value("SELECT roles FROM role_permission WHERE permission = 'delivery.upload'")));
        self::assertSame(2, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'permission' AND subject_key LIKE 'delivery.%' AND version = 1 "
            . "AND action = 'baseline'"));

        // The CHECKs: a bill adds up gross = net + VAT, its fingerprint is one, its status ended says who; a line's state and what it points at.
        $bill = static fn (array $over = []): int => $db->insert('INSERT INTO carrier_invoice (carrier, account, invoice_no, period_from, period_to, channel_id, net_pence, '
            . 'vat_pence, gross_pence, line_count, file_sha256, status, uploaded_actor, uploaded_at, ended_actor, ended_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values(array_replace(['carrier' => 'dpd', 'account' => '3012002', 'invoice_no' => '62368793', 'from' => '2026-11-05', 'to' => '2026-11-14', 'ch' => $ch,
                'net' => 420, 'vat' => 84, 'gross' => 504, 'n' => 1, 'sha' => str_repeat('a', 64), 'status' => 'live', 'actor' => 'system:x', 'at' => '2026-11-20 10:00:00',
                'ended_actor' => null, 'ended_at' => null], $over)));
        $id = $bill();
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $bill(['invoice_no' => '2', 'gross' => 505])), 'gross = net + VAT');
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $bill(['invoice_no' => '3', 'carrier' => 'evri'])));
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $bill(['invoice_no' => '4', 'sha' => 'X'])));
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $bill(['invoice_no' => '5', 'status' => 'undone'])), 'ended says when');
        self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $bill(['invoice_no' => 'Test Person'])), 'a number, not a name');
        self::assertSame(1062, self::err(static fn () => $bill(['sha' => str_repeat('b', 64)])), 'one live bill per key');
        $bill(['sha' => str_repeat('c', 64), 'status' => 'undone', 'ended_actor' => 'system:x', 'ended_at' => '2026-11-20 11:00:00']);
        $line = static fn (array $over = []): int => $db->exec('INSERT INTO carrier_invoice_line (invoice_id, line_no, line_date, consignment, senders_ref, vat_code, marks, '
            . 'money, cost, match_state, channel_id, order_ref, parcel_ref, candidates, matched_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_values(array_replace(['id' => $id, 'no' => 1, 'date' => '2026-11-05', 'cons' => '6599195040', 'ref' => '1356063', 'vat' => 'S', 'marks' => '',
                'money' => '{"Revenue": 420}', 'cost' => 420, 'state' => 'not_matched', 'ch' => null, 'order' => null, 'parcel' => null, 'n' => 0, 'at' => null], $over)));
        $line();
        foreach ([['no' => 2, 'state' => 'matched'], ['no' => 3, 'state' => 'ambiguous', 'n' => 1], ['no' => 4, 'state' => 'not_matched', 'n' => 2],
            ['no' => 5, 'state' => 'before_start', 'ch' => $ch], ['no' => 6, 'ref' => 'man return'], ['no' => 7, 'cons' => '6599195040/0'], ['no' => 8, 'marks' => '##'],
            ['no' => 9, 'money' => '[420]'], ['no' => 10, 'vat' => 's']] as $bad) {
            self::assertSame(self::CHECK_VIOLATED, self::err(static fn () => $line($bad)), json_encode($bad, JSON_THROW_ON_ERROR));
        }
        self::assertSame(1452, self::err(static fn () => $line(['no' => 11, 'state' => 'collection', 'ch' => $ch, 'order' => '1000', 'at' => '2026-11-20 10:00:00'])),
            'a collection names an order CW holds');

        // Every statement again: nothing changes (a holder given on the roles screen meanwhile stays).
        $db->exec("UPDATE role_permission SET roles = 'finance' WHERE permission = 'delivery.view'");
        foreach ($statements as $st) {
            $db->pdo()->exec($st);
        }
        self::assertSame('finance', (string) $db->value("SELECT roles FROM role_permission WHERE permission = 'delivery.view'"));
        self::assertSame(2, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'permission' AND subject_key LIKE 'delivery.%'"));
        self::assertSame(2, (int) $db->value('SELECT COUNT(*) FROM carrier_invoice'));
        self::assertSame(['delivery_cost', 'delivery_other'], $db->column("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
            . "AND TABLE_NAME = 'profit_day' AND COLUMN_NAME LIKE 'delivery\\_%' AND COLUMN_NAME <> 'delivery_ex' ORDER BY ORDINAL_POSITION"));
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
