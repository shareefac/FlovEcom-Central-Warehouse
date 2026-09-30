<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Caller;
use CW\Db;
use CW\Mapping\DecisionService;
use CW\Mapping\ListingIngestService;
use CW\Mapping\Proposals;
use CW\Reservations;
use CW\Schema\Grants;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\TestDb;

/**
 * The app-login grant model, checked with a throwaway login on the test schema:
 * full DML everywhere except the append-only tables (SELECT/INSERT, plus UPDATE of a few state
 * columns on the matching tables) and schema_migrations (SELECT); and the whole linking flow
 * (DecisionService, proposals, adoption) runs with exactly those rights.
 */
final class GrantsTest extends IntegrationTestCase
{
    private const DENIED = 1142;
    /** UPDATE of a column the login has no column-level grant on. */
    private const COLUMN_DENIED = 1143;

    private static string $user;
    private static string $password;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$user = substr('cw_t_' . preg_replace('/[^a-z0-9_]/', '', substr(TestDb::name(), strlen('cw_test_'))), 0, 32);
        self::$password = bin2hex(random_bytes(16)) . 'Aa1-';
        $server = TestDb::server();
        $account = Grants::account(self::$user);
        $server->pdo()->exec("DROP USER IF EXISTS {$account}");
        $server->pdo()->exec("CREATE USER {$account} IDENTIFIED BY " . $server->pdo()->quote(self::$password) . ' REQUIRE SSL');
    }

    public static function tearDownAfterClass(): void
    {
        TestDb::server()->pdo()->exec('DROP USER IF EXISTS ' . Grants::account(self::$user));
    }

    public function testDesiredPrivileges(): void
    {
        self::assertSame(['Select', 'Insert'], Grants::desired('stock_ledger'));
        self::assertSame(['Select', 'Insert'], Grants::desired('audit_log'));
        self::assertSame(['Select'], Grants::desired('schema_migrations'));
        self::assertSame(['Select', 'Insert', 'Update', 'Delete'], Grants::desired('stock_balance'));
        foreach (['match_run', 'match_reject', 'match_decision', 'match_proposal', 'listing_map_history'] as $t) {
            self::assertSame(['Select', 'Insert'], Grants::desired($t), $t);
        }
        self::assertSame(['applied_at' => ['Update'], 'second_by' => ['Update'], 'state' => ['Update']], Grants::desiredColumns('match_decision'));
        self::assertSame(['status' => ['Update']], Grants::desiredColumns('match_proposal'));
        self::assertSame(['closed_by_decision_id' => ['Update'], 'valid_to' => ['Update']], Grants::desiredColumns('listing_map_history'));
        self::assertSame([], Grants::desiredColumns('match_reject'));
        // Design A.1 I1: the app login never deletes a listing (its holding-ledger units would be orphaned:
        // reservation_unit.listing_id has no FK), an item, a profile or a staff account.
        foreach (['channel_listing', 'sku', 'listing_profile', 'staff_user'] as $t) {
            self::assertSame(['Select', 'Insert', 'Update'], Grants::desired($t), $t);
            self::assertContains($t, Grants::NO_DELETE);
        }
    }

    public function testApplyConvergesAndTheAppLoginIsLimited(): void
    {
        $name = TestDb::name();
        $changes = Grants::apply(self::$db, $name, self::$user);
        self::assertNotEmpty($changes);
        self::assertSame([], Grants::apply(self::$db, $name, self::$user), 'second apply changes nothing');

        $app = $this->appSession();
        $main = self::warehouseId('MAIN');
        $sku = self::makeSku();
        self::$db->exec('INSERT INTO stock_balance (warehouse_id, sku_id) VALUES (?, ?)', [$main, $sku]);

        // Allowed: ordinary DML, and INSERT into the append-only tables.
        $app->exec('UPDATE stock_balance SET on_hand = on_hand + 1 WHERE warehouse_id = ? AND sku_id = ?', [$main, $sku]);
        $ledgerId = $app->insert(
            "INSERT INTO stock_ledger (warehouse_id, sku_id, bucket, qty_delta, balance_after, movement_type, actor) VALUES (?, ?, 'on_hand', 1, 1, 'adjustment', 'system:test')",
            [$main, $sku],
        );
        $app->insert("INSERT INTO audit_log (actor, action) VALUES ('system:test', 'test.grant')");
        self::assertSame(1, $app->value('SELECT COUNT(*) FROM stock_ledger WHERE id = ?', [$ledgerId]));
        self::assertSame(1, $app->value('SELECT COUNT(*) FROM schema_migrations WHERE version = ?', ['0001_core.sql']));

        // Refused: rewriting history or the migration bookkeeping.
        foreach ([
            'UPDATE stock_ledger SET qty_delta = 99',
            'DELETE FROM stock_ledger',
            "UPDATE audit_log SET action = 'x'",
            'DELETE FROM audit_log',
            "INSERT INTO schema_migrations (version, checksum, applied_at, duration_ms) VALUES ('x', 'x', UTC_TIMESTAMP(), 0)",
            'DELETE FROM schema_migrations',
            'DROP TABLE stock_balance',
            'ALTER TABLE sku ADD COLUMN x INT',
        ] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        self::assertSame(1, self::$db->value('SELECT qty_delta FROM stock_ledger WHERE id = ?', [$ledgerId]));

        // Matching history: decisions, proposals and link periods change only in their state columns.
        foreach ([
            'DELETE FROM match_decision', 'DELETE FROM match_proposal', 'DELETE FROM listing_map_history', 'DELETE FROM match_reject',
            'DELETE FROM match_run', "UPDATE match_run SET run_id = 'x'", 'UPDATE match_reject SET sku_id = 1',
        ] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        foreach ([
            'UPDATE match_decision SET sku_id = 1', "UPDATE match_decision SET action = 'link'", 'UPDATE match_decision SET decided_by = 1',
            "UPDATE match_proposal SET band = 'Key'", 'UPDATE match_proposal SET proposed_sku_id = 1',
            'UPDATE listing_map_history SET sku_id = 1', 'UPDATE listing_map_history SET valid_from = UTC_TIMESTAMP(6)',
        ] as $sql) {
            self::assertSame(self::COLUMN_DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        // Listings, items, profiles and staff are updated, never deleted: a listing sold only while unlinked
        // (no profile, no decision, so no FK would stop it) cannot be removed from under its units.
        $orphan = self::makeChannel('orphan');
        $lid = self::$db->insert("INSERT INTO channel_listing (channel_id, external_variant_id) VALUES (?, 'SOLD-UNLINKED')", [$orphan]);
        foreach (['DELETE FROM channel_listing WHERE id = ' . $lid, 'DELETE FROM sku', 'DELETE FROM listing_profile', 'DELETE FROM staff_user'] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM channel_listing WHERE id = ?', [$lid]));
        self::assertSame(0, $app->exec('UPDATE channel_listing SET map_version = map_version WHERE 1 = 0'), 'UPDATE stays allowed');
        foreach ([
            "UPDATE match_decision SET state = 'applied', applied_at = UTC_TIMESTAMP(6), second_by = NULL WHERE 1 = 0",
            "UPDATE match_proposal SET status = 'decided' WHERE 1 = 0",
            'UPDATE listing_map_history SET valid_to = UTC_TIMESTAMP(6), closed_by_decision_id = NULL WHERE 1 = 0',
        ] as $sql) {
            self::assertSame(0, $app->exec($sql), $sql);
        }
    }

    public function testApplyRemovesDriftAndDatabaseLevelGrants(): void
    {
        $name = TestDb::name();
        $account = Grants::account(self::$user);
        Grants::apply(self::$db, $name, self::$user);
        self::$db->pdo()->exec('GRANT UPDATE, DELETE ON ' . Db::ident($name) . '.`stock_ledger` TO ' . $account);
        self::$db->pdo()->exec('GRANT ALL PRIVILEGES ON ' . Db::ident($name) . '.* TO ' . $account);

        $changes = Grants::apply(self::$db, $name, self::$user);
        self::assertContains('revoked UPDATE, DELETE on stock_ledger', $changes);
        self::assertContains("revoked database-level grant on {$name}.*", $changes);

        $app = $this->appSession();
        self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec('DELETE FROM stock_ledger')));
        self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec('CREATE TABLE x (id INT PRIMARY KEY)')));

        // Column-level drift: an extra column grant goes, a missing one comes back.
        $t = Db::ident($name) . '.`match_decision`';
        self::$db->pdo()->exec("GRANT UPDATE (`sku_id`) ON {$t} TO {$account}");
        self::$db->pdo()->exec("REVOKE UPDATE (`state`) ON {$t} FROM {$account}");
        $changes = Grants::apply(self::$db, $name, self::$user);
        self::assertSame(['granted UPDATE (state) on match_decision', 'revoked UPDATE (sku_id) on match_decision'], $changes);
        // A table-wide UPDATE goes; its REVOKE also drops the column grants, which are then granted again.
        self::$db->pdo()->exec("GRANT UPDATE ON {$t} TO {$account}");
        $changes = Grants::apply(self::$db, $name, self::$user);
        self::assertSame(['revoked UPDATE on match_decision', 'granted UPDATE (applied_at, second_by, state) on match_decision'], $changes);
        self::assertSame([], Grants::apply(self::$db, $name, self::$user));
        $app = $this->appSession();
        self::assertSame(self::COLUMN_DENIED, self::mysqlError(fn () => $app->exec('UPDATE match_decision SET sku_id = 1')));
        self::assertSame(0, $app->exec("UPDATE match_decision SET state = 'withdrawn' WHERE 1 = 0"));
    }

    /**
     * The linking flow with exactly the app login's rights: listing intake, a proposal and its
     * suggest, reject, a one-person link that adopts units sold while unlinked, a pending link and
     * its approval, a withdrawal, new_item, the seed mint and a merge.
     */
    public function testTheLinkingFlowRunsAsTheAppLogin(): void
    {
        Grants::apply(self::$db, TestDb::name(), self::$user);
        $app = $this->appSession();
        $alt = self::makeChannel('alt', 'live');
        self::$db->exec('INSERT INTO channel_warehouse (channel_id, warehouse_id, is_sellable) VALUES (?, ?, 1)', [$alt, self::warehouseId('MAIN')]);
        $site = Caller::channel($alt, 'alt');
        $staff = [];
        foreach (['mapper', 'mapping_lead', 'mapping_lead'] as $i => $role) {
            $staff[] = Caller::staff(self::$db->insert(
                "INSERT INTO staff_user (username, display_name, email, role, password_hash) VALUES (?, ?, ?, ?, 'x')",
                ["u{$i}", "u{$i}", "u{$i}@test.invalid", $role],
            ));
        }
        [$mapper, $lead, $lead2] = $staff;
        [$a, $b, $c] = [self::makeSku('A'), self::makeSku('B'), self::makeSku('C')];

        $pushed = (new ListingIngestService($app))->push($site, [
            ['variant_id' => 'E1', 'product_title' => 'One'], ['variant_id' => 'E2', 'product_title' => 'Two'],
            ['variant_id' => 'E3', 'product_title' => 'Three'], ['variant_id' => 'E4', 'product_title' => 'Four'],
        ]);
        $id = array_column($pushed['listings'], 'listing_id', 'variant_id');
        $res = new Reservations($app);
        self::assertSame(200, $res->commit($site, 'O-1', [['variant_id' => 'E1', 'qty' => 1, 'unit_ids' => ['u1']]], 'reserved', 'k1')->status);

        $ds = new DecisionService($app, null, $res);
        $proposals = new Proposals($app, $ds);
        $run = $proposals->run('grants-run', 'manual', str_repeat('a', 64), 'engine', ['m' => ['chunks' => 1]]);
        $pid = $proposals->add(Caller::system('test'), $id['E1'], $run, ['band' => 'Check', 'proposed_sku_id' => $a, 'flags' => ['x']])['proposal_id'];
        $v = static fn (string $k): int => (int) $app->value('SELECT map_version FROM channel_listing WHERE id = ?', [$id[$k]]);

        $ds->decide($mapper, ['action' => 'reject', 'listing_id' => $id['E1'], 'expected_map_version' => $v('E1'), 'sku_id' => $b, 'proposal_id' => $pid]);
        $r = $ds->decide($mapper, ['action' => 'link', 'listing_id' => $id['E1'], 'expected_map_version' => $v('E1'), 'sku_id' => $a, 'proposal_id' => $pid]);
        self::assertSame(['applied', 1], [$r['state'], $r['adopted']]);
        $p = $ds->decide($mapper, ['action' => 'link', 'listing_id' => $id['E2'], 'expected_map_version' => 0, 'sku_id' => $a, 'units_per_item' => 5]);
        self::assertSame('applied', $ds->approve($lead, $p['decision_id'])['state']);
        $w = $ds->decide($lead, ['action' => 'link', 'listing_id' => $id['E3'], 'expected_map_version' => 0, 'sku_id' => $b, 'units_per_item' => 2]);
        self::assertSame('withdrawn', $ds->withdraw($lead2, $w['decision_id'])['state']);
        $n = $ds->decide($mapper, ['action' => 'new_item', 'listing_id' => $id['E3'], 'expected_map_version' => 0]);
        self::assertSame('Three', $app->value('SELECT name FROM sku WHERE id = ?', [$n['sku_id']]));
        $m = $ds->mintAndLink($lead, $id['E4'], 0, DecisionService::cardFrom([], [], ['name' => 'Four']), 'bulk-1');
        self::assertSame('mapped', $m['status']);
        $ds->decide($mapper, ['action' => 'link', 'listing_id' => $id['E4'], 'expected_map_version' => 1, 'sku_id' => $c]);
        $merge = $ds->decide($mapper, ['action' => 'merge_skus', 'listing_id' => $id['E4'], 'expected_map_version' => 2, 'sku_id' => $a, 'merge_from_sku_id' => $c]);
        self::assertSame('applied', $ds->approve($lead, $merge['decision_id'])['state']);
        $ds->decide($mapper, ['action' => 'unlink', 'listing_id' => $id['E3'], 'expected_map_version' => 1]);
        $ds->decide($mapper, ['action' => 'ignore', 'listing_id' => $id['E3'], 'expected_map_version' => 2]);

        self::assertSame([$a, $a, null, $a], array_map(static fn (string $k): mixed => $app->value('SELECT sku_id FROM channel_listing WHERE id = ?', [$id[$k]]), ['E1', 'E2', 'E3', 'E4']));
        self::assertSame($a, $app->value('SELECT merged_into_sku_id FROM sku WHERE id = ?', [$c]));
        self::assertSame(1, (int) self::$db->value('SELECT allocated FROM stock_balance WHERE sku_id = ?', [$a]));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM match_reject'));
        self::assertSame(['applied' => 10, 'withdrawn' => 1],
            array_map('intval', array_column(self::$db->all('SELECT state, COUNT(*) AS n FROM match_decision GROUP BY state ORDER BY state'), 'n', 'state')));
    }

    private function appSession(): Db
    {
        $s = TestDb::config()->dbAdmin()->withCredentials(self::$user, self::$password)->withDatabase(TestDb::name());
        return Db::connect($s);
    }
}
