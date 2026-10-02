<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Caller;
use CW\Db;
use CW\Documents\DocumentHandlers;
use CW\Documents\Documents;
use CW\Files\FileStore;
use CW\Files\LocalFileStorage;
use CW\Invariants;
use CW\Mapping\DecisionService;
use CW\Mapping\ListingIngestService;
use CW\Mapping\Proposals;
use CW\Movements;
use CW\PurchaseOrders\PurchaseOrders;
use CW\Reorder\DemandBuilder;
use CW\Reorder\DraftPos;
use CW\Reorder\ReorderList;
use CW\Reorder\ReorderSettings;
use CW\Reorder\SalesHistoryImport;
use CW\Reservations;
use CW\Schema\Grants;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffRoles;
use CW\Suppliers\SupplierItems;
use CW\Suppliers\Suppliers;
use CW\Tests\Support\Documents\FixtureAdjustmentHandler;
use CW\Tests\Support\Documents\FixtureDocuments;
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
        // C0 (I3, I5): the value sequence and the value journal are history; an item's clock moves only its last_seq.
        foreach (['stock_value_seq', 'stock_value_ledger', 'stock_value_clock'] as $t) {
            self::assertSame(['Select', 'Insert'], Grants::desired($t), $t);
        }
        self::assertSame([], Grants::desiredColumns('stock_value_seq'));
        self::assertSame([], Grants::desiredColumns('stock_value_ledger'));
        self::assertSame(['last_seq' => ['Update']], Grants::desiredColumns('stock_value_clock'));
        // I10: a role grant is revoked (who and when), never rewritten or deleted.
        self::assertSame(['Select', 'Insert'], Grants::desired('staff_role'));
        self::assertSame(['revoked_at' => ['Update'], 'revoked_by' => ['Update']], Grants::desiredColumns('staff_role'));
        // 0008 (I17-I23): seeded reference lists are read-only; a series moves only its last number; a document's identity
        // is frozen; a review task changes only its decision; stored files and their attachments are history; draft lines are free.
        foreach (['reason_code', 'document_type'] as $t) {
            self::assertSame(['Select'], Grants::desired($t), $t);
            self::assertSame([], Grants::desiredColumns($t), $t);
        }
        foreach (['number_series', 'document', 'review_task', 'stored_file', 'document_file', 'document_posting'] as $t) {
            self::assertSame(['Select', 'Insert'], Grants::desired($t), $t);
        }
        self::assertSame([], Grants::desiredColumns('document_posting'), 'the posting record is write-once (I33)');
        self::assertSame(['last_no' => ['Update']], Grants::desiredColumns('number_series'));
        self::assertSame(['state', 'decided_by', 'decided_at', 'decision_note'], array_keys(Grants::desiredColumns('review_task')));
        $doc = array_keys(Grants::desiredColumns('document'));
        foreach (['id', 'doc_type', 'created_by', 'created_actor', 'created_at', 'reverses_id'] as $frozen) {
            self::assertNotContains($frozen, $doc, "document.{$frozen} is never updated");
        }
        $columns = array_map('strval', self::$db->column(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document' ORDER BY ORDINAL_POSITION"));
        self::assertSame(array_values(array_diff($columns, ['id', 'doc_type', 'created_by', 'created_actor', 'created_at', 'reverses_id', 'live_reverses_id'])), $doc,
            'every other column of document is a state column (live_reverses_id is generated)');
        self::assertSame([], Grants::desiredColumns('stored_file'));
        self::assertSame([], Grants::desiredColumns('document_file'));
        self::assertSame(Grants::FULL, Grants::desired('document_line'));
        // 0009 (I38-I47): settings and VAT codes are read-only; suppliers, supplier items and import runs are never deleted; the
        // price history is append-only.
        foreach (['app_setting', 'vat_code'] as $t) {
            self::assertSame(['Select'], Grants::desired($t), $t);
            self::assertContains($t, Grants::READ_ONLY);
        }
        foreach (['supplier', 'supplier_item', 'import_run'] as $t) {
            self::assertSame(['Select', 'Insert', 'Update'], Grants::desired($t), $t);
            self::assertContains($t, Grants::NO_DELETE);
        }
        self::assertSame(['Select', 'Insert'], Grants::desired('supplier_item_price'));
        self::assertSame([], Grants::desiredColumns('supplier_item_price'));
        self::assertContains('supplier_item_price', Grants::APPEND_ONLY);
        // 0010 (I48-I59): a PO header is never deleted (cancelled instead), the posting anchor is write-once, a draft's
        // po_line rows are replaced with their document lines (FULL).
        self::assertSame(['Select', 'Insert', 'Update'], Grants::desired('purchase_order'));
        self::assertContains('purchase_order', Grants::NO_DELETE);
        self::assertSame(['Select', 'Insert'], Grants::desired('po_posting'));
        self::assertSame([], Grants::desiredColumns('po_posting'));
        self::assertContains('po_posting', Grants::APPEND_ONLY);
        self::assertSame(Grants::FULL, Grants::desired('po_line'));
        // 0011 (I60-I71): an import batch and an anomaly window are never deleted (a later batch replaces the days, a window is
        // ended); the history, the snapshot and stock days, the site's latest stock, the reorder settings and the demand are
        // replaced (FULL: an import deletes its days before inserting them, the demand is rebuilt).
        foreach (['sales_import_batch', 'demand_anomaly'] as $t) {
            self::assertSame(['Select', 'Insert', 'Update'], Grants::desired($t), $t);
            self::assertContains($t, Grants::NO_DELETE);
            self::assertSame([], Grants::desiredColumns($t), $t);
        }
        foreach (['sales_history_day', 'channel_snapshot_day', 'listing_stock_day', 'listing_stock_latest', 'item_reorder', 'reorder_brand', 'reorder_demand'] as $t) {
            self::assertSame(Grants::FULL, Grants::desired($t), $t);
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
            $uid = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES (?, ?, ?, 'x')",
                ["u{$i}", "u{$i}", "u{$i}@test.invalid"]);
            self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$uid, $role]);
            $staff[] = Caller::staff($uid);
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

    /**
     * C0 as the app login: Stock writes seq rows and moves item clocks (INSERT ... ON DUPLICATE KEY UPDATE of
     * last_seq) and nothing else; a document posting and its reversal book with the same rights.
     */
    public function testTheValueTablesAsTheAppLogin(): void
    {
        Grants::apply(self::$db, TestDb::name(), self::$user);
        $app = $this->appSession();
        $sku = self::makeSku();
        $other = self::makeSku('Other');

        $app->exec('INSERT INTO stock_value_seq (sku_id, seq, stock_ledger_id) VALUES (?, 1, 987654321)', [$other]);
        $odku = 'INSERT INTO stock_value_clock (sku_id, last_seq) VALUES (?, 1) AS new ON DUPLICATE KEY UPDATE last_seq = stock_value_clock.last_seq + new.last_seq';
        $app->exec($odku, [$other]);
        $app->exec($odku, [$other]);
        self::assertSame(2, (int) self::$db->value('SELECT last_seq FROM stock_value_clock WHERE sku_id = ?', [$other]));
        $app->exec('UPDATE stock_value_clock SET last_seq = last_seq');
        $app->insert("INSERT INTO stock_value_ledger (sku_id, kind, value_delta, qty_after, value_after, cost_source, effective_at, actor) "
            . "VALUES (?, 'opening', 0, 0, 0, 'estimate', UTC_TIMESTAMP(6), 'system:test')", [$other]);
        self::assertSame(self::COLUMN_DENIED, self::mysqlError(fn () => $app->exec('UPDATE stock_value_clock SET sku_id = 1')));
        foreach ([
            'DELETE FROM stock_value_clock', 'UPDATE stock_value_seq SET seq = seq', 'DELETE FROM stock_value_seq',
            "UPDATE stock_value_ledger SET note = 'x'", 'DELETE FROM stock_value_ledger',
        ] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        self::$db->exec('DELETE FROM stock_value_seq');
        self::$db->exec('DELETE FROM stock_value_clock');

        // The stock core with exactly these rights: a costed staff goods_in, a document posting and its reversal (real
        // document rows, so DocumentInvariants holds: the docs task, 0008).
        $moves = new Movements($app);
        $r = $moves->record(Caller::staff(1), ['type' => 'goods_in', 'doc_ref' => 'PINV-APP', 'lines' => [['sku_id' => $sku, 'qty' => 4, 'unit_cost' => '1.5']]], 'app-gi');
        self::assertSame(200, $r->status, json_encode($r->body));
        $posted = FixtureDocuments::posted(self::$db, 77, 'ADJ', 1);
        $app->transaction(function () use ($moves, $sku, $posted): void {
            $moves->bookForDocument(Caller::staff(1), ['document_id' => 77, 'doc_ref' => $posted], 'doc:77:post',
                [['type' => 'adjustment', 'lines' => [['document_line' => 1, 'sku_id' => $sku, 'qty' => -1, 'unit_cost' => '1.5']]]]);
        });
        $reversal = FixtureDocuments::posted(self::$db, 78, 'ADJ', 2, 77);
        $app->transaction(function () use ($moves, $reversal): void {
            self::assertSame(1, $moves->reverseDocument(Caller::staff(1), 77, ['document_id' => 78, 'doc_ref' => $reversal], 'doc:78:reverse'));
        });
        self::assertSame(4, (int) self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ?', [$sku]));
        self::assertSame([1, 2, 3], array_map('intval', self::$db->column('SELECT seq FROM stock_value_seq WHERE sku_id = ? ORDER BY seq', [$sku])));
        self::assertSame(3, (int) self::$db->value('SELECT last_seq FROM stock_value_clock WHERE sku_id = ?', [$sku]));
        self::assertSame([], Invariants::check(self::$db));
    }

    /**
     * I10 as the app login: role grants are inserted and revoked (revoked_at, revoked_by; the generated
     * active_staff_user_id follows), never rewritten or deleted; StaffAdmin works with exactly these rights.
     */
    public function testStaffRolesAsTheAppLogin(): void
    {
        Grants::apply(self::$db, TestDb::name(), self::$user);
        $app = $this->appSession();
        $ids = [];
        foreach (['admin', 'buyer'] as $role) {
            $uid = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES (?, ?, ?, 'x')",
                ["g-{$role}", "g {$role}", "g-{$role}@test.example"]);
            self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$uid, $role]);
            $ids[$role] = $uid;
        }
        $grant = $app->insert('INSERT INTO staff_role (staff_user_id, role, granted_by) VALUES (?, ?, ?)', [$ids['buyer'], 'reviewer', $ids['admin']]);
        self::assertSame(1, $app->exec('UPDATE staff_role SET revoked_at = NOW(6), revoked_by = ? WHERE id = ?', [$ids['admin'], $grant]));
        self::assertNull(self::$db->value('SELECT active_staff_user_id FROM staff_role WHERE id = ?', [$grant]), 'the generated column follows the revocation');
        foreach ([
            "UPDATE staff_role SET role = 'admin'" => self::COLUMN_DENIED,
            'UPDATE staff_role SET staff_user_id = 1' => self::COLUMN_DENIED,
            'UPDATE staff_role SET granted_by = NULL' => self::COLUMN_DENIED,
            'UPDATE staff_role SET granted_at = NOW(6)' => self::COLUMN_DENIED,
            'DELETE FROM staff_role' => self::DENIED,
        ] as $sql => $code) {
            self::assertSame($code, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }

        // The service with these rights: an admin replaces the buyer's roles; the revoked grant stays.
        $r = (new StaffAdmin($app))->setRoles(Caller::staff($ids['admin']), $ids['buyer'], ['reviewer', 'stock_controller'], ['buyer']);
        self::assertSame(['changed', ['reviewer', 'stock_controller'], ['buyer']], [$r['result'], $r['added'], $r['removed']]);
        self::assertSame(['reviewer', 'stock_controller'], StaffRoles::of($app, $ids['buyer']));
        self::assertSame(4, (int) self::$db->value('SELECT COUNT(*) FROM staff_role WHERE staff_user_id = ?', [$ids['buyer']]), 'buyer and the first reviewer revoked, two live');
        self::assertSame('changed', (new StaffAdmin($app))->setActive(Caller::staff($ids['admin']), $ids['buyer'], false)['result']);
    }

    /**
     * 0008 as the app login (I17-I23): the reference lists are read only; a number series moves only last_no; a document's
     * identity columns and a review task's subject are frozen and neither is ever deleted; stored files and attachments are
     * append-only; draft lines are free. The document base (draft, lines, post, review, reversal) and the file store run
     * with exactly these rights, locking reads included.
     */
    public function testTheDocumentTablesAsTheAppLogin(): void
    {
        Grants::apply(self::$db, TestDb::name(), self::$user);
        $app = $this->appSession();
        self::assertSame((int) self::$db->value('SELECT COUNT(*) FROM reason_code'), (int) $app->value('SELECT COUNT(*) FROM reason_code'), 'the app login reads them all');
        self::assertSame(8, (int) $app->value('SELECT COUNT(*) FROM document_type'));
        foreach ([
            "INSERT INTO reason_code (code, label, applies_to) VALUES ('xx', 'x', 'adjustment')", "UPDATE reason_code SET label = 'x'", 'DELETE FROM reason_code',
            "INSERT INTO document_type (code, prefix, name, phase) VALUES ('XX', 'XX', 'x', 'I-9')", "UPDATE document_type SET review_rule = 'none'",
            'DELETE FROM document_type', 'DELETE FROM number_series', 'DELETE FROM document', 'DELETE FROM review_task', 'DELETE FROM stored_file',
            'DELETE FROM document_file', 'UPDATE stored_file SET note = NULL', 'UPDATE document_file SET role = role',
            "UPDATE document_posting SET posted_hash = REPEAT('0', 64)", 'UPDATE document_posting SET posted_at = NOW(6)', 'DELETE FROM document_posting',
            'UPDATE document_file SET retain_until = CURRENT_DATE()',
        ] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        foreach ([
            'UPDATE number_series SET pad = 7', "UPDATE number_series SET prefix = 'ZZ'", "UPDATE document SET doc_type = 'PO'",
            'UPDATE document SET reverses_id = NULL', 'UPDATE document SET created_by = NULL', "UPDATE document SET created_actor = 'x'",
            'UPDATE document SET created_at = NOW(6)', "UPDATE review_task SET kind = 'approval'", 'UPDATE review_task SET opened_by = NULL',
            'UPDATE review_task SET subject_id = 1', 'UPDATE review_task SET units = 0',
        ] as $sql) {
            self::assertSame(self::COLUMN_DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        $app->pdo()->beginTransaction();
        self::assertSame(1, $app->exec("UPDATE number_series SET last_no = LAST_INSERT_ID(last_no + 1) WHERE prefix = 'PO'"));
        // Locking reads of the column-granted tables (the document base locks document and review_task rows FOR UPDATE).
        self::assertNull($app->one('SELECT id FROM review_task WHERE id = 0 FOR UPDATE'));
        self::assertNull($app->one('SELECT id FROM document WHERE id = 0 FOR UPDATE'));
        self::assertNull($app->one('SELECT id FROM document WHERE id = 0 FOR SHARE'));
        $app->pdo()->rollBack();

        // The document base with exactly these rights: draft, lines (replaced), post, review, approval request, reversal.
        $poster = $this->staffWith('stock_controller');
        $reviewer = $this->staffWith('reviewer');
        $sku = self::makeSku('Grants doc item');
        $docs = new Documents($app, ['ADJ' => new FixtureAdjustmentHandler($app)]);
        $p = Caller::staff($poster);
        $d = $docs->createDraft($p, 'ADJ', ['external_ref' => 'SUP-1', 'warehouse' => 'MAIN', 'reason_code' => 'found']);
        $d = $docs->setLines($p, $d->id, $d->version, [['sku_id' => $sku, 'qty' => 3]]);
        $d = $docs->setLines($p, $d->id, $d->version, [['sku_id' => $sku, 'qty' => 5, 'unit_cost' => '2'], ['sku_id' => $sku, 'qty' => -1]]);
        $d = $docs->post($p, $d->id, $d->version);
        self::assertSame(['posted', 'ADJ-000001', 'pending'], [$d->status, $d->number, $d->reviewState]);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_id = ? AND state = 'open'", [$d->id]);
        self::assertSame('approved', $docs->approve(Caller::staff($reviewer), $task, 'checked')->reviewState);
        self::assertSame('ADJ-000002', $docs->reverse($p, $d->id, 'entered_in_error', null)->number);
        $x = $docs->createDraft($p, 'ADJ', []);
        $x = $docs->setLines($p, $x->id, $x->version, [['sku_id' => $sku, 'qty' => 11]]);
        self::assertSame('awaiting_approval', $docs->post($p, $x->id, $x->version)->status);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_id = ? AND state = 'open'", [$x->id]);
        self::assertSame('cancelled', $docs->reject(Caller::staff($reviewer), $task, 'no supplier document')->status);
        self::assertSame(0, (int) self::$db->value('SELECT on_hand FROM stock_balance WHERE sku_id = ?', [$sku]), 'the reversal booked it all back');

        // The file store with these rights.
        $dir = sys_get_temp_dir() . '/cw_grants_fs_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $file = $dir . '/in.csv';
        file_put_contents($file, "code,qty\nCW-000001,4\n");
        try {
            $store = new FileStore($app, new LocalFileStorage($dir), static function (): void {
            });
            $f = $store->store($p, $file, 'stock.csv', 'other', null);
            self::assertFalse($f['deduped']);
            self::assertTrue($store->attach($p, $d->id, $f['id'], 'evidence'));
            self::assertFalse($store->attach($p, $d->id, $f['id'], 'evidence'), 'attached once');
            self::assertSame("code,qty\nCW-000001,4\n", $store->read($f['id'])['bytes']);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        self::assertSame([], Invariants::check(self::$db));
    }

    /**
     * 0009 as the app login (I38-I47): the supplier flow (create, complete, request, a second person approves, an item, a
     * manual price, the preferred supply, an import-route change and its approval, deactivation) runs with exactly these
     * rights, locking reads included; settings and VAT codes cannot be written; suppliers, items, prices and import runs are
     * never deleted and a price is never rewritten.
     */
    public function testTheSupplierTablesAsTheAppLogin(): void
    {
        Grants::apply(self::$db, TestDb::name(), self::$user);
        $app = $this->appSession();
        $buyer = Caller::staff($this->staffWith('buyer'));
        $reviewer = Caller::staff($this->staffWith('reviewer'));
        $sup = new Suppliers($app);
        $s = $sup->create($buyer, ['name' => 'App Login Supplier', 'address_line1' => '1 Road', 'postcode' => 'LS1 1AA', 'email' => 'o@app.example',
            'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 5 * 86400), 'dd_checked_by' => (string) $buyer->staffUserId,
            'dd_next_review_on' => gmdate('Y-m-d', time() + 300 * 86400), 'is_overseas' => '1', 'import_route' => 'Stamped in Kent']);
        $s = $sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $task = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND state = 'open'");
        $s = $sup->approve($reviewer, $task, 'checked');
        self::assertSame(['active', $reviewer->staffUserId, $reviewer->staffUserId], [$s['status'], (int) $s['approved_by'], (int) $s['import_route_approved_by']]);
        $items = new SupplierItems($app);
        $i = $items->create($buyer, (int) $s['id'], self::makeSku('App item'), ['units_per_pack' => '24', 'is_preferred' => '1'], ['pack_price' => '12.00']);
        $i = $items->recordPrice($buyer, (int) $i['id'], '11.50', null, 'new list');
        $i = $items->update($buyer, (int) $i['id'], (int) $i['version'], ['moq_packs' => '2']);
        $items->setPreferred($buyer, (int) $i['id'], false);
        $s = $sup->update($buyer, (int) $s['id'], (int) $s['version'], ['import_route' => 'Stamped in Essex']);
        $route = (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND state = 'open' AND reason = 'import_route'");
        $s = $sup->approve($reviewer, $route, null);
        $s = $sup->deactivate($buyer, (int) $s['id'], (int) $s['version'], 'test over');
        self::assertSame('inactive', $s['status']);
        self::assertSame(['11.5000', 'manual'], [$i['last_pack_price'], $i['last_price_source']]);
        foreach ([
            'DELETE FROM supplier', 'DELETE FROM supplier_item', 'DELETE FROM import_run', 'DELETE FROM supplier_item_price',
            'UPDATE supplier_item_price SET pack_price = 0', 'UPDATE supplier_item_price SET source = \'po\'',
            "UPDATE app_setting SET description = 'x'", 'DELETE FROM app_setting', "UPDATE vat_code SET label = 'x'", 'DELETE FROM vat_code',
        ] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        $app->pdo()->beginTransaction();
        self::assertNull($app->one('SELECT id FROM supplier WHERE id = 0 FOR UPDATE'));
        self::assertNull($app->one('SELECT id FROM supplier_item WHERE id = 0 FOR UPDATE'));
        $app->pdo()->rollBack();
        self::assertSame([], Invariants::check(self::$db));
    }

    /**
     * 0010 as the app login (I48-I59): a PO drafted (with save_item), approved (the po_posting anchor, the po price history,
     * last_po_*), sent, cancelled, amended and its review rejected (recorded) with exactly these rights, locking reads
     * included; purchase_order rows are never deleted and an anchor is never rewritten.
     */
    public function testThePurchaseOrderFlowAsTheAppLogin(): void
    {
        Grants::apply(self::$db, TestDb::name(), self::$user);
        $app = $this->appSession();
        $buyer = Caller::staff($this->staffWith('buyer'));
        $reviewer = Caller::staff($this->staffWith('reviewer'));
        $sup = new Suppliers($app);
        $s = $sup->create($buyer, ['name' => 'PO App Supplier', 'address_line1' => '1 Road', 'postcode' => 'LS1 1AA', 'email' => 'o@po.example',
            'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 5 * 86400), 'dd_checked_by' => (string) $buyer->staffUserId,
            'dd_next_review_on' => gmdate('Y-m-d', time() + 300 * 86400)]);
        $s = $sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $sup->approve($reviewer, (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND state = 'open'"), null);
        $docs = new Documents($app, DocumentHandlers::all($app));
        $pos = new PurchaseOrders($app, $docs);
        $sku = self::makeSku('PO app item');
        $d = $pos->createDraft($buyer, (int) $s['id'], ['external_ref' => 'Q-1']);
        $d = $pos->saveDraft($buyer, $d->id, $d->version, [], [['sku_id' => $sku, 'units_per_pack' => 12, 'packs' => 2, 'pack_price' => '24.00', 'supplier_code' => 'APP-12',
            'save_item' => true], ['kind' => 'charge', 'description' => 'Delivery', 'pack_price' => '5.00']]);
        $r = $pos->addLine($buyer, $d->id, $d->version, 'app-12');
        self::assertSame('incremented', $r['status']);
        $p = $pos->approve($buyer, $d->id, $r['document']->version);
        self::assertSame(['posted', 'PO-000001'], [$p->status, $p->number]);
        $p = $pos->markSent($buyer, $p->id, $p->version, 'email', 'o@po.example', true);
        self::assertSame('sent', self::$db->value('SELECT state FROM purchase_order WHERE document_id = ?', [$p->id]));
        self::assertSame('po', self::$db->value("SELECT source FROM supplier_item_price WHERE document_id = ?", [$p->id]));
        $docs->reject($reviewer, (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'document' AND subject_id = ? AND state = 'open'", [$p->id]),
            'price check');
        $new = $pos->amend($buyer, $p->id, 'po_amended', null);
        self::assertSame('draft', $new->status);
        self::assertSame([], $pos->openLines($p->id), 'a cancelled order expects nothing');
        foreach (['DELETE FROM purchase_order', 'DELETE FROM po_posting', 'UPDATE po_posting SET content_hash = REPEAT(\'0\', 64)', "UPDATE po_posting SET content = '{}'"] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        $app->pdo()->beginTransaction();
        self::assertNull($app->one('SELECT document_id FROM purchase_order WHERE document_id = 0 FOR UPDATE'));
        $app->pdo()->rollBack();
        self::assertSame([], Invariants::check(self::$db));
    }

    /**
     * 0011 as the app login (I60-I71): a sales-history import and its overlapping successor (the days replaced), the demand
     * build (its named lock, DELETE + INSERT), item and brand settings, an anomaly window added and ended, the reorder list and
     * "create draft PO", with exactly these rights, locking reads included; import batches and windows are never deleted.
     */
    public function testTheReorderFlowAsTheAppLogin(): void
    {
        Grants::apply(self::$db, TestDb::name(), self::$user);
        $app = $this->appSession();
        $buyer = Caller::staff($this->staffWith('buyer'));
        $reviewer = Caller::staff($this->staffWith('reviewer'));
        $channel = self::makeChannel('vapeandgo');
        $sku = self::makeSku('App reorder item');
        self::$db->exec("UPDATE sku SET brand = 'Elux' WHERE id = ?", [$sku]);
        self::$db->exec("INSERT INTO channel_listing (channel_id, external_variant_id, sku_id, units_per_item, status) VALUES (?, '701', ?, 1, 'mapped')", [$channel, $sku]);
        $sup = new Suppliers($app);
        $s = $sup->create($buyer, ['name' => 'Reorder App Supplier', 'address_line1' => '1 Road', 'postcode' => 'LS1 1AA', 'email' => 'o@ra.example',
            'payment_terms' => '30 days', 'dd_checked_on' => gmdate('Y-m-d', time() - 5 * 86400), 'dd_checked_by' => (string) $buyer->staffUserId,
            'dd_next_review_on' => gmdate('Y-m-d', time() + 300 * 86400)]);
        $s = $sup->requestActivation($buyer, (int) $s['id'], (int) $s['version']);
        $sup->approve($reviewer, (int) self::$db->value("SELECT id FROM review_task WHERE subject_type = 'supplier' AND state = 'open'"), null);
        (new SupplierItems($app))->create($buyer, (int) $s['id'], $sku, ['units_per_pack' => '10', 'is_preferred' => '1'], ['pack_price' => '15.00']);

        $dir = sys_get_temp_dir() . '/cw_grants_sales_' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            $import = new SalesHistoryImport($app);
            $r = $import->import(Caller::system('test'), 'vapeandgo', self::salesExport($dir . '/a', '2026-09-01', '2026-09-30', 4));
            self::assertSame('loaded', $r['status']);
            $r = $import->import(Caller::system('test'), 'vapeandgo', self::salesExport($dir . '/b', '2026-09-15', '2026-10-01', 6));
            self::assertSame(['loaded', 17], [$r['status'], $r['counts']['rows_read']]);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM listing_stock_latest'));
        $b = (new DemandBuilder($app))->rebuild();
        self::assertSame(1, $b['items']);
        $set = new ReorderSettings($app);
        $set->saveItem($buyer, $sku, 0, ['safety_days' => '3']);
        $set->saveBrand($buyer, 'Elux', 0, ['demand_factor' => '0.90']);
        $a = $set->addAnomaly($buyer, ['date_from' => '2026-09-20', 'date_to' => '2026-09-21', 'label' => 'app window']);
        $set->endAnomaly($buyer, (int) $a['id']);
        (new DemandBuilder($app))->rebuild();
        $docs = new Documents($app, DocumentHandlers::all($app));
        $pos = new PurchaseOrders($app, $docs);
        $list = new ReorderList($app, $pos);
        $line = $list->lines(ReorderList::filters([]))[0] ?? null;
        self::assertNotNull($line);
        self::assertSame($sku, $line['sku_id']);
        $d = (new DraftPos($app, $pos, $list))->create($buyer, [['sku_id' => $sku, 'packs' => (int) $line['packs']]]);
        self::assertCount(1, $d['drafts']);
        foreach (['DELETE FROM sales_import_batch', 'DELETE FROM demand_anomaly'] as $sql) {
            self::assertSame(self::DENIED, self::mysqlError(fn () => $app->exec($sql)), $sql);
        }
        $app->pdo()->beginTransaction();
        foreach (['item_reorder WHERE sku_id = 0', 'reorder_brand WHERE brand = \'x\'', 'demand_anomaly WHERE id = 0', 'sales_import_batch WHERE id = 0',
            'reorder_demand WHERE sku_id = 0'] as $t) {
            self::assertSame([], $app->all("SELECT 1 FROM {$t} FOR UPDATE"));
        }
        $app->pdo()->rollBack();
        self::assertSame([], Invariants::check(self::$db));
    }

    /** A one-variant export (variant 701, $perDay a day) in $dir, as tools/sales_history/export.php writes it; returns the manifest. */
    private static function salesExport(string $dir, string $from, string $to, int $perDay): string
    {
        mkdir($dir, 0700);
        $name = "vapeandgo_sales_{$from}_{$to}_20261002T050000Z.csv.gz";
        $csv = "site,variant_id,sale_date,units_online,orders_online,net_online,gross_online,units_office,orders_office\n";
        for ($t = strtotime($from . ' 00:00:00 UTC'); $t <= strtotime($to . ' 00:00:00 UTC'); $t += 86400) {
            $csv .= 'vapeandgo,701,' . gmdate('Y-m-d', $t) . ",{$perDay},1,{$perDay}.00,{$perDay}.00,0,0\n";
        }
        file_put_contents("{$dir}/{$name}", gzencode($csv));
        $latest = "vapeandgo_stocklatest_{$to}_20261002T050000Z.csv.gz";
        file_put_contents("{$dir}/{$latest}", gzencode("site,variant_id,snapshot_date,stock,stock_mode,sellable\nvapeandgo,701,{$to},5,In-Stock,1\n"));
        $manifest = "{$dir}/vapeandgo_sales_{$from}_{$to}_20261002T050000Z.manifest.json";
        file_put_contents($manifest, json_encode(['site' => 'vapeandgo', 'source' => 'cps', 'from' => $from, 'to' => $to, 'exported_at' => '2026-10-02T05:00:00Z',
            'snapshot_days' => [['date' => $to, 'variants' => 1, 'unsellable' => 0]], 'files' => [['name' => $name, 'kind' => 'sales', 'sha256' => hash_file('sha256', "{$dir}/{$name}")],
                ['name' => $latest, 'kind' => 'latest', 'sha256' => hash_file('sha256', "{$dir}/{$latest}")]]], JSON_THROW_ON_ERROR));
        return $manifest;
    }

    private function staffWith(string $role): int
    {
        $n = bin2hex(random_bytes(3));
        $uid = self::$db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES (?, ?, ?, 'x')",
            ["g-{$role}-{$n}", "g {$role}", "g-{$role}-{$n}@test.invalid"]);
        self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$uid, $role]);
        return $uid;
    }

    private function appSession(): Db
    {
        $s = TestDb::config()->dbAdmin()->withCredentials(self::$user, self::$password)->withDatabase(TestDb::name());
        return Db::connect($s);
    }
}
