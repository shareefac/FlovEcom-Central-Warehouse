<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Admin\ConfigHistory;
use CW\Admin\ConfigInvariants;
use CW\Db;
use CW\Tests\Support\IntegrationTestCase;
use CW\Tests\Support\MigrationFixture;

/**
 * 0019 (the set-it-yourself pack; docs/decisions.md Y2, Y5, Y14, Y20, Y25, Y33, and the review fixes Y40-Y53): a baseline version of
 * every configuration row that exists (settings, reasons, document rules, warehouses: what the schema held before 0019, even a value
 * someone changed with the CLI), the purchase-order reasons' uses as their version 2 (docs/dev.md rule 5), the spot checks already
 * drawn judged by their own size (required_size), the new settings, the three system warehouses, and the CHECKs of the new columns
 * and tables.
 */
final class Migration0019Test extends IntegrationTestCase
{
    private const CHECK_VIOLATED = 3819;
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            MigrationFixture::drop('m19', $this->dir);
        }
    }

    public function testTheBaselinesTakeWhatTheSchemaHeldBeforeAndCheckClean(): void
    {
        /** @var Db $db */
        [$db, $this->dir] = MigrationFixture::upTo('m19', '0018_site_writer.sql');
        $db->exec("UPDATE app_setting SET value_json = CAST('9' AS JSON), updated_actor = 'system:settings' WHERE setting_key = 'reorder.default_safety_days'");
        $db->exec("UPDATE document_type SET approval_limit_units = 25000 WHERE code = 'PO'");
        $db->exec("INSERT INTO warehouse (code, name, is_sellable) VALUES ('MAIN2', 'Second building', 1)");
        // A spot check drawn before 0019 (the staging owner-1: 20 of 20, judged against the setting of today until Y47).
        $lead = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m19lead@test.example', 'Lead', 'm19lead@test.example', 'x')");
        $db->exec("INSERT INTO key_sample (name, seed, method, band_version, sample_size, population, strata, created_by, actor) "
            . "VALUES ('owner-1', 1, 'm', 'b2.1', 20, 1371, JSON_ARRAY(), ?, 'staff:1')", [$lead]);
        MigrationFixture::migrateRest($db, $this->dir, '0019_set_it_yourself.sql');
        self::assertSame(20, (int) $db->value("SELECT required_size FROM key_sample WHERE name = 'owner-1'"), 'a sample keeps the size it was drawn with');

        $settings = (int) $db->value('SELECT COUNT(*) FROM app_setting');
        self::assertSame($settings, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'setting' AND action = 'baseline' AND version = 1"));
        self::assertSame((int) $db->value('SELECT COUNT(*) FROM reason_code'), (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'reason' AND version = 1"));
        // The order screens' reasons, seeded with their uses as version 2 by the migration (I6, M8): what the code listed before.
        $uses = [];
        foreach ($db->all("SELECT subject_key, CAST(state AS CHAR) AS s, CAST(before_state AS CHAR) AS b, reason, actor FROM config_change "
            . "WHERE subject_type = 'reason' AND version = 2 ORDER BY subject_key") as $r) {
            self::assertSame(['change', 'system:migrate'], [(string) $db->value("SELECT action FROM config_change WHERE subject_type = 'reason' AND subject_key = ? AND version = 2",
                [$r['subject_key']]), (string) $r['actor']]);
            self::assertNotSame('', (string) $r['reason']);
            $uses[(string) $r['subject_key']] = [json_decode((string) $r['b'], true)['applies_to'], json_decode((string) $r['s'], true)['applies_to']];
        }
        self::assertSame(['duplicate' => ['reversal', 'reversal,po_cancel,po_draft_cancel'],
            'entered_in_error' => ['reversal', 'reversal,po_cancel,po_draft_cancel,po_amend'],
            'not_needed' => ['reversal', 'reversal,po_cancel,po_draft_cancel'],
            'other' => ['adjustment,write_off,count,return,supplier_return,reversal', 'adjustment,write_off,count,return,supplier_return,reversal,po_cancel,po_draft_cancel,po_amend'],
            'po_amended' => ['reversal', 'reversal,po_amend'],
            'supplier_cannot_supply' => ['reversal', 'reversal,po_cancel,po_draft_cancel,po_amend']], $uses);
        self::assertSame(8, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'document_rule'"));
        self::assertSame(4, (int) $db->value("SELECT COUNT(*) FROM config_change WHERE subject_type = 'warehouse'"));
        self::assertSame(['value' => '9', 'provisional' => 1], ConfigHistory::latest($db, 'setting', 'reorder.default_safety_days')['state']);
        self::assertSame(25000, ConfigHistory::latest($db, 'document_rule', 'PO')['state']['approval_limit_units']);
        self::assertEquals(['code' => 'MAIN2', 'name' => 'Second building', 'is_sellable' => 1, 'is_active' => 1, 'stock_owner' => 'own', 'owner_entity' => null,
            'is_system' => 0, 'note' => null], ConfigHistory::latest($db, 'warehouse', 'MAIN2')['state'], 'MySQL keeps JSON keys in its own order');
        self::assertSame([], ConfigInvariants::check($db), 'every row equals its baseline');
        self::assertSame(['MAIN', 'UNSTAMPED', 'VERIFY'], array_map('strval', $db->column('SELECT code FROM warehouse WHERE is_system = 1 ORDER BY code')));
        self::assertSame(0, (int) $db->value('SELECT COUNT(*) FROM warehouse WHERE is_active = 0'));
        foreach (['approvals.supplier_activation' => 'true', 'approvals.match_multiple' => 'true', 'approvals.match_counted' => 'true',
            'approvals.company_own_change' => 'true', 'approvals.staff_grant' => 'false', 'approvals.staff_reset' => 'false', 'approvals.spot_check_size' => '20',
            'staff.setup_hours' => '48', 'staff.setup_max_fails' => '5', 'staff.sign_in_address' => '""', 'staff.min_reviewers' => '2'] as $key => $value) {
            self::assertSame([$value, 1], array_values((array) $db->one('SELECT CAST(value_json AS CHAR), provisional FROM app_setting WHERE setting_key = ?', [$key])), $key);
        }
    }

    public function testTheNewChecks(): void
    {
        $db = self::$db;
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("INSERT INTO warehouse (code, name, is_sellable, stock_owner, owner_entity) "
            . "VALUES ('VPGX', 'VPG 2 room', 1, 'other', 'VPG 2')")), 'another account\'s stock is never sellable');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("INSERT INTO warehouse (code, name, stock_owner) VALUES ('VPGX', 'VPG 2 room', 'other')")),
            'another account names its owner');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("UPDATE warehouse SET is_active = 0 WHERE code = 'MAIN'")),
            'a system warehouse is never switched off');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("INSERT INTO warehouse_location (warehouse_id, code, name) VALUES (1, 'a 1', 'x')")));
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("INSERT INTO config_change (subject_type, subject_key, version, action, state, actor) "
            . "VALUES ('setting', 'x.y', 2, 'change', JSON_OBJECT(), 'system:t')")), 'a change has its reason and the row before it');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("INSERT INTO config_change (subject_type, subject_key, version, action, state, reason, actor) "
            . "VALUES ('setting', 'x.y', 1, 'change', JSON_OBJECT(), 'because', 'system:t')")), 'version 1 is a baseline or an add');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec('INSERT INTO integrity_run (started_at, finished_at, ok, problems, details, run_by) '
            . "VALUES (NOW(6), NOW(6), 1, 2, JSON_ARRAY(), 'x')")), 'ok means no problem');
        $sid = $db->insert("INSERT INTO staff_user (username, display_name, email, password_hash) VALUES ('m19@test.example', 'M', 'm19@test.example', 'x')");
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec('INSERT INTO staff_role_request (staff_user_id, roles_before, roles_after, guarded, '
            . "requested_by, requested_actor, state, decided_by, decided_at) VALUES (?, '[]', '[\"reviewer\"]', '[\"reviewer\"]', ?, 'staff:1', 'approved', ?, NOW(6))",
            [$sid, $sid, $sid])), 'nobody decides their own access');
        $db->exec('INSERT INTO staff_role_request (staff_user_id, roles_before, roles_after, guarded, requested_actor) VALUES (?, \'[]\', \'["reviewer"]\', \'["reviewer"]\', \'staff:9\')', [$sid]);
        self::assertSame(1062, self::mysqlError(static fn () => $db->exec('INSERT INTO staff_role_request (staff_user_id, roles_before, roles_after, guarded, requested_actor) '
            . 'VALUES (?, \'[]\', \'["admin"]\', \'["admin"]\', \'staff:9\')', [$sid])), 'one open request per person');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("INSERT INTO supplier (code, name, created_actor, updated_actor, approved_alone) VALUES ('M19', 'M', 'x', 'x', 1)")),
            'an approval alone names the version of the switch');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("INSERT INTO supplier (code, name, created_actor, updated_actor, alone_checked_by, alone_checked_at) "
            . "VALUES ('M19', 'M', 'x', 'x', ?, NOW(6))", [$sid])), 'a later OK only of a supplier approved alone');
        $db->exec('DELETE FROM staff_role_request');
        // The review fixes (Y40-Y53): an admin never holds both factors (a new sign-in code never with a set-up window), a half-finished
        // set-up is all or nothing, a reset's OK is used once, Not OK on a PO only records it, a spot check of 5 or more.
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("UPDATE staff_user SET totp_state = 'reset', setup_until = NOW(6) + INTERVAL 1 HOUR "
            . 'WHERE id = ?', [$sid])), 'a new sign-in code never with an open set-up window');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("UPDATE staff_user SET setup_code_hash = REPEAT('a', 64) WHERE id = ?", [$sid])),
            'a set-up code only with its window');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("UPDATE staff_user SET totp_next_token = REPEAT('b', 64) WHERE id = ?", [$sid])),
            'a fresh code waits with all its parts');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec('INSERT INTO staff_role_request (staff_user_id, roles_before, roles_after, guarded, '
            . "requested_actor, used_at) VALUES (?, '[]', '[]', '[]', 'staff:9', NOW(6))", [$sid])), 'only an approved reset is used');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("UPDATE document_type SET reject_action = 'reverse' WHERE code = 'PO'")),
            'Not OK on a PO only records it');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("INSERT INTO key_sample (name, seed, method, band_version, sample_size, required_size, "
            . "population, strata, created_by, actor) VALUES ('tiny', 1, 'm', 'b', 4, 4, 100, JSON_ARRAY(), ?, 'x')", [$sid])), 'a spot check of 5 or more');
        self::assertSame(self::CHECK_VIOLATED, self::mysqlError(static fn () => $db->exec("INSERT INTO key_sample (name, seed, method, band_version, sample_size, required_size, "
            . "population, strata, created_by, actor) VALUES ('short', 1, 'm', 'b', 10, 20, 100, JSON_ARRAY(), ?, 'x')", [$sid])), 'never smaller than the size in force');
    }
}
