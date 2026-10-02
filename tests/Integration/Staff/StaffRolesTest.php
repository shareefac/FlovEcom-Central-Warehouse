<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Staff;

use CW\Caller;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffRoles;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\TestDb;

/**
 * Several roles per person (0007, I10-I13, I15): StaffAdmin::create with a role list, setRoles (admin only, never on
 * oneself, optimistic roles_seen, revoke + grant with history, audited), setActive (ends sessions), the CLI tools
 * (`create_staff --roles`, the `--role` alias, `reset_staff --roles`) and what the table itself refuses.
 */
final class StaffRolesTest extends KernelUiTestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null && is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testCreateGivesEveryRoleAGrantAndAuditsTheList(): void
    {
        $admin = new StaffAdmin(self::$db);
        $made = $admin->create(Caller::system('roles_test'), 'Multi@Test.invalid', ['reviewer', 'buyer', 'reviewer'], self::$box, 'Multi');
        self::assertSame(['buyer', 'reviewer'], $made['roles'], 'deduped and sorted');
        self::assertArrayNotHasKey('role', $made);
        $rows = self::$db->all('SELECT CAST(role AS CHAR) AS role, granted_by, revoked_at FROM staff_role WHERE staff_user_id = ? ORDER BY role', [$made['id']]);
        self::assertSame([['role' => 'buyer', 'granted_by' => null, 'revoked_at' => null], ['role' => 'reviewer', 'granted_by' => null, 'revoked_at' => null]], $rows);
        $audit = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'staff.create'");
        self::assertSame('system:roles_test', $audit['actor']);
        self::assertEquals(['email' => 'multi@test.invalid', 'roles' => ['buyer', 'reviewer']], json_decode((string) $audit['detail'], true));
        self::assertSame(['buyer', 'reviewer'], StaffRoles::active(self::$db, $made['id']));

        // A set the rules refuse creates nobody.
        self::refused(422, 'role_conflict', fn () => $admin->create(Caller::system('roles_test'), 'x1@test.invalid', ['admin', 'reviewer'], self::$box));
        self::refused(422, 'no_roles', fn () => $admin->create(Caller::system('roles_test'), 'x2@test.invalid', [], self::$box));
        self::refused(400, 'bad_role', fn () => $admin->create(Caller::system('roles_test'), 'x3@test.invalid', 'boss', self::$box));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM staff_user WHERE email LIKE 'x_@test.invalid'"));

        // A staff caller creating people must be an admin; the admin's id is the grantor.
        $adm = $this->uiUser(['admin', 'auditor']);
        $byAdmin = $admin->create(Caller::staff($adm['id']), 'made-by-admin@test.example', 'stock_controller', self::$box);
        self::assertSame($adm['id'], (int) self::$db->value('SELECT granted_by FROM staff_role WHERE staff_user_id = ?', [$byAdmin['id']]));
        $buyer = $this->uiUser('buyer');
        self::refused(403, 'role_not_allowed', fn () => $admin->create(Caller::staff($buyer['id']), 'nope@test.invalid', 'viewer', self::$box));
    }

    public function testSetRolesRevokesAndGrantsKeepingTheHistory(): void
    {
        $adm = $this->uiUser('admin');
        $p = $this->uiUser(['buyer', 'viewer']);
        $staff = new StaffAdmin(self::$db);
        $as = Caller::staff($adm['id'], '198.51.100.70');

        $r = $staff->setRoles($as, $p['id'], ['reviewer', 'buyer'], ['viewer', 'buyer']);
        self::assertSame(['before' => ['buyer', 'viewer'], 'after' => ['buyer', 'reviewer'], 'added' => ['reviewer'], 'removed' => ['viewer'], 'result' => 'changed'], $r);
        $rows = self::$db->all('SELECT CAST(role AS CHAR) AS role, granted_by, revoked_by, revoked_at IS NOT NULL AS revoked FROM staff_role WHERE staff_user_id = ? ORDER BY id', [$p['id']]);
        self::assertSame([
            ['role' => 'buyer', 'granted_by' => null, 'revoked_by' => null, 'revoked' => 0],
            ['role' => 'viewer', 'granted_by' => null, 'revoked_by' => $adm['id'], 'revoked' => 1],
            ['role' => 'reviewer', 'granted_by' => $adm['id'], 'revoked_by' => null, 'revoked' => 0],
        ], $rows, 'the viewer grant is revoked (kept), reviewer granted by the admin');
        $audit = self::$db->one("SELECT actor, ip, entity_id, detail FROM audit_log WHERE action = 'staff.roles'");
        self::assertSame(["staff:{$adm['id']}", '198.51.100.70', (string) $p['id']], [$audit['actor'], $audit['ip'], $audit['entity_id']]);
        self::assertEquals(['email' => $p['email'], 'before' => ['buyer', 'viewer'], 'after' => ['buyer', 'reviewer'], 'added' => ['reviewer'], 'removed' => ['viewer']],
            json_decode((string) $audit['detail'], true));
        self::assertCount(3, StaffRoles::history(self::$db, $p['id']));

        // The same set again: unchanged, no audit row. Giving back a revoked role is a new grant.
        self::assertSame('unchanged', $staff->setRoles($as, $p['id'], ['buyer', 'reviewer'], null)['result']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.roles'"));
        $staff->setRoles($as, $p['id'], ['buyer', 'reviewer', 'viewer'], ['buyer', 'reviewer']);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM staff_role WHERE staff_user_id = ? AND role = 'viewer'", [$p['id']]));

        // Refused, writing nothing: admin with a role it may not hold, an empty set, a stale form.
        $before = (int) self::$db->value('SELECT COUNT(*) FROM staff_role');
        $e = self::refused(422, 'role_conflict', fn () => $staff->setRoles($as, $p['id'], ['admin', 'reviewer'], null));
        self::assertStringContainsString('admin cannot be combined with reviewer', $e->getMessage());
        self::refused(422, 'no_roles', fn () => $staff->setRoles($as, $p['id'], [], null));
        $stale = self::refused(409, 'roles_changed', fn () => $staff->setRoles($as, $p['id'], ['buyer'], ['buyer', 'reviewer']));
        self::assertSame(['current' => ['buyer', 'reviewer', 'viewer']], $stale->detail);
        self::assertSame($before, (int) self::$db->value('SELECT COUNT(*) FROM staff_role'));
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.roles'"));
        // roles_seen is compared as a set: order and repeats do not matter.
        self::assertSame('changed', $staff->setRoles($as, $p['id'], ['buyer'], ['viewer', 'reviewer', 'buyer', 'buyer'])['result']);
    }

    public function testOnlyAnAdminChangesRolesAndNeverTheirOwn(): void
    {
        $adm = $this->uiUser(['admin', 'viewer']);
        $other = $this->uiUser('admin');
        $lead = $this->uiUser('mapping_lead');
        $p = $this->uiUser('buyer');
        $staff = new StaffAdmin(self::$db);

        $own = self::refused(403, 'own_account', fn () => $staff->setRoles(Caller::staff($adm['id']), $adm['id'], ['admin'], null));
        self::assertStringContainsString('ask another admin', $own->getMessage());
        self::refused(403, 'own_account', fn () => $staff->setActive(Caller::staff($adm['id']), $adm['id'], false));
        self::refused(403, 'role_not_allowed', fn () => $staff->setRoles(Caller::staff($lead['id']), $p['id'], ['reviewer'], null));
        self::refused(403, 'role_not_allowed', fn () => $staff->setActive(Caller::staff($lead['id']), $p['id'], false));
        self::refused(403, 'role_not_allowed', fn () => $staff->setRoles(Caller::staff($lead['id']), $lead['id'], ['mapper'], null)); // not an admin: before own_account
        self::refused(403, 'staff_required', fn () => $staff->setRoles(Caller::channel(1, 'vpg'), $p['id'], ['reviewer'], null));
        self::refused(404, 'unknown_staff', fn () => $staff->setRoles(Caller::staff($adm['id']), 999_999, ['reviewer'], null));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action IN ('staff.roles', 'staff.deactivate')"));

        // Another admin may change this admin; and an admin whose admin role was just taken away can no longer act (re-read in the transaction).
        self::assertSame('changed', $staff->setRoles(Caller::staff($other['id']), $adm['id'], ['viewer'], ['admin', 'viewer'])['result']);
        self::refused(403, 'role_not_allowed', fn () => $staff->setRoles(Caller::staff($adm['id']), $p['id'], ['reviewer'], null));
        // A deactivated admin cannot act either.
        $staff->setActive(Caller::system('roles_test'), $other['id'], false);
        self::refused(403, 'staff_not_allowed', fn () => $staff->setRoles(Caller::staff($other['id']), $p['id'], ['reviewer'], null));
        // The CLI (system caller) is the break-glass: it may change anyone.
        self::assertSame('changed', $staff->setRoles(Caller::system('roles_test'), $adm['id'], ['admin'], null)['result']);
        self::assertNull(self::$db->value("SELECT granted_by FROM staff_role WHERE staff_user_id = ? AND role = 'admin' AND revoked_at IS NULL", [$adm['id']]));
    }

    /**
     * I35 (review findings): reset() follows the same caller rules as setRoles() (admin only, never one's own account,
     * staff only), and a placeholder account (an e-mail under .invalid, U23) is never switched on or given a role by a
     * staff caller (409 placeholder_account); taking a role away is allowed, and the CLI (a system caller) stays the
     * break-glass.
     */
    public function testResetChecksItsCallerAndPlaceholdersStayOffForScreens(): void
    {
        $adm = $this->uiUser('admin');
        $buyer = $this->uiUser('buyer');
        $staff = new StaffAdmin(self::$db);
        self::refused(403, 'role_not_allowed', fn () => $staff->reset(Caller::staff($buyer['id']), $adm['email'], null, false, false, false));
        self::refused(403, 'role_not_allowed', fn () => $staff->reset(Caller::staff($buyer['id']), $buyer['email'], null, true, false, null),
            'nobody issues themselves a new password through the service');
        self::refused(403, 'own_account', fn () => $staff->reset(Caller::staff($adm['id']), $adm['email'], null, true, false, null));
        self::refused(403, 'staff_required', fn () => $staff->reset(Caller::channel(1, 'vpg'), $buyer['email'], null, false, false, false));
        self::assertSame(1, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$adm['id']]));
        $off = $staff->reset(Caller::staff($adm['id']), $buyer['email'], null, false, false, false);
        self::assertFalse($off['active']);
        self::assertSame("staff:{$adm['id']}", self::$db->value("SELECT actor FROM audit_log WHERE action = 'staff.deactivate'"));

        $made = $staff->create(Caller::system('roles_test'), 'mapping-lead-placeholder@cw-staging.invalid', ['mapping_lead', 'viewer'], self::$box);
        $ph = (int) $made['id'];
        $staff->setActive(Caller::system('roles_test'), $ph, false);
        $audits = (int) self::$db->value('SELECT COUNT(*) FROM audit_log');
        $e = self::refused(409, 'placeholder_account', fn () => $staff->setActive(Caller::staff($adm['id']), $ph, true));
        self::assertStringContainsString('two-person rule', $e->getMessage());
        self::refused(409, 'placeholder_account', fn () => $staff->reset(Caller::staff($adm['id']), $made['email'], null, false, false, true));
        self::refused(409, 'placeholder_account', fn () => $staff->setRoles(Caller::staff($adm['id']), $ph, ['mapping_lead', 'reviewer', 'viewer'], null));
        self::refused(409, 'placeholder_account', fn () => $staff->create(Caller::staff($adm['id']), 'second-placeholder@cw-staging.invalid', 'viewer', self::$box));
        self::assertSame(0, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$ph]));
        self::assertSame(['mapping_lead', 'viewer'], StaffRoles::of(self::$db, $ph));
        self::assertSame($audits, (int) self::$db->value('SELECT COUNT(*) FROM audit_log'), 'nothing written');
        // Taking a role away is fine; the server tool may still switch it on (break-glass), and off again.
        self::assertSame(['viewer'], $staff->setRoles(Caller::staff($adm['id']), $ph, ['mapping_lead'], null)['removed']);
        self::assertTrue($staff->setActive(Caller::system('roles_test'), $ph, true)['active']);
        $staff->setActive(Caller::staff($adm['id']), $ph, false);
        self::assertTrue(StaffAdmin::isPlaceholder(' Someone@Example.INVALID '));
        self::assertFalse(StaffAdmin::isPlaceholder('someone@invalid.example'));
    }

    public function testSetActiveEndsSessionsAndAudits(): void
    {
        $adm = $this->uiUser('admin');
        $p = $this->uiUser('reviewer');
        $web = $this->signIn($p);
        $staff = new StaffAdmin(self::$db);
        $off = $staff->setActive(Caller::staff($adm['id']), $p['id'], false);
        self::assertSame(['active' => false, 'sessions_ended' => 1, 'result' => 'changed'], array_intersect_key($off, ['active' => 1, 'sessions_ended' => 1, 'result' => 1]));
        self::assertSame(0, (int) self::$db->value('SELECT is_active FROM staff_user WHERE id = ?', [$p['id']]));
        self::assertSame('/ui/login', $web->get('/ui/')->location(), 'the session ended');
        self::refused(403, 'staff_not_allowed', fn () => StaffRoles::active(self::$db, $p['id']));
        self::assertSame(['reviewer'], StaffRoles::of(self::$db, $p['id']), 'the roles stay; only the account is off');
        self::assertSame(0, StaffRoles::activeCount(self::$db, 'reviewer'));
        self::assertSame('unchanged', $staff->setActive(Caller::staff($adm['id']), $p['id'], false)['result']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.deactivate'"));
        $on = $staff->setActive(Caller::staff($adm['id']), $p['id'], true);
        self::assertSame([true, 'changed'], [$on['active'], $on['result']]);
        self::assertSame(1, StaffRoles::activeCount(self::$db, 'reviewer'));
        $audit = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'staff.activate'");
        self::assertSame("staff:{$adm['id']}", $audit['actor']);
        self::assertEquals(['email' => $p['email'], 'active' => true, 'sessions_ended' => 0], json_decode((string) $audit['detail'], true));
    }

    public function testTheTableAllowsOneLiveGrantPerRoleAndNoSelfGrant(): void
    {
        $p = $this->uiUser('buyer');
        $adm = $this->uiUser('admin');
        // The generated active_staff_user_id + UNIQUE: a second live grant of the same role is refused even by admin SQL.
        self::assertSame(1062, self::mysqlError(fn () => self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'buyer')", [$p['id']])));
        self::$db->exec("UPDATE staff_role SET revoked_at = NOW(6), revoked_by = ? WHERE staff_user_id = ? AND role = 'buyer'", [$adm['id'], $p['id']]);
        self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'buyer')", [$p['id']]);
        self::assertSame(1062, self::mysqlError(fn () => self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'buyer')", [$p['id']])));
        self::$db->exec("UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ? AND role = 'buyer' AND revoked_at IS NULL", [$p['id']]);
        self::assertSame(2, (int) self::$db->value("SELECT COUNT(*) FROM staff_role WHERE staff_user_id = ? AND role = 'buyer' AND revoked_at IS NOT NULL", [$p['id']]),
            'any number of revoked grants');
        // Nobody grants or revokes their own role; a revoker needs a revocation time.
        self::assertSame(3819, self::mysqlError(fn () => self::$db->exec("INSERT INTO staff_role (staff_user_id, role, granted_by) VALUES (?, 'reviewer', ?)", [$adm['id'], $adm['id']])));
        self::assertSame(3819, self::mysqlError(fn () => self::$db->exec("UPDATE staff_role SET revoked_at = NOW(6), revoked_by = staff_user_id WHERE staff_user_id = ?", [$adm['id']])));
        self::assertSame(3819, self::mysqlError(fn () => self::$db->exec("INSERT INTO staff_role (staff_user_id, role, revoked_by) VALUES (?, 'viewer', ?)", [$p['id'], $adm['id']])));
        // A grant is never revoked before it was given (ck_staff_role_order, I35).
        self::assertSame(3819, self::mysqlError(fn () => self::$db->exec(
            "UPDATE staff_role SET revoked_at = granted_at - INTERVAL 1 SECOND WHERE staff_user_id = ? AND role = 'admin'", [$adm['id']])));
        self::assertSame(1452, self::mysqlError(fn () => self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (999999, 'viewer')")));
        self::assertSame(1265, self::mysqlError(fn () => self::$db->exec("INSERT INTO staff_role (staff_user_id, role) VALUES (?, 'boss')", [$p['id']])));
    }

    public function testCreateStaffTakesARoleListAndKeepsRoleAsAnAlias(): void
    {
        $env = $this->appEnv();
        $r = self::tool('create_staff', '--email=desk@test.invalid', '--roles=buyer,reviewer', '--name=Desk Person', ['CW_APP_ENV' => $env]);
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(1, preg_match('/^password=[A-Za-z0-9]{20}\notpauth=otpauth:\/\/totp\/\S+\n$/D', $r['out']), $r['out']);
        self::assertStringContainsString('roles buyer,reviewer)', $r['err']);
        $id = (int) self::$db->value("SELECT id FROM staff_user WHERE email = 'desk@test.invalid'");
        self::assertSame(['buyer', 'reviewer'], StaffRoles::of(self::$db, $id));
        self::assertEquals(['email' => 'desk@test.invalid', 'roles' => ['buyer', 'reviewer']],
            json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'staff.create' AND entity_id = ?", [(string) $id]), true));

        $alias = self::tool('create_staff', '--email=one@test.invalid', '--role=mapper', ['CW_APP_ENV' => $env]);
        self::assertSame(0, $alias['code'], $alias['err']);
        self::assertSame(['mapper'], StaffRoles::of(self::$db, (int) self::$db->value("SELECT id FROM staff_user WHERE email = 'one@test.invalid'")));

        foreach ([
            ['--email=both@test.invalid', '--roles=buyer', '--role=buyer'],
            ['--email=none@test.invalid'],
        ] as $args) {
            $u = self::tool('create_staff', ...[...$args, ['CW_APP_ENV' => $env]]);
            self::assertSame([2, ''], [$u['code'], $u['out']], implode(' ', $args) . ': ' . $u['err']);
        }
        $conflict = self::tool('create_staff', '--email=boss@test.invalid', '--roles=admin,reviewer', ['CW_APP_ENV' => $env]);
        self::assertSame([1, ''], [$conflict['code'], $conflict['out']]);
        self::assertStringContainsString('admin cannot be combined with reviewer', $conflict['err']);
        self::assertSame(2, self::tool('create_staff', '--email=empty@test.invalid', '--roles=', ['CW_APP_ENV' => $env])['code'], 'an empty option: usage');
        self::assertSame(1, self::tool('create_staff', '--email=empty@test.invalid', '--roles=,', ['CW_APP_ENV' => $env])['code'], 'no roles: refused (no_roles)');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM staff_user WHERE email IN ('both@test.invalid', 'none@test.invalid', 'boss@test.invalid', 'empty@test.invalid')"));
    }

    public function testResetStaffReplacesTheRolesAndSaysWhatChanged(): void
    {
        $p = $this->uiUser(['buyer', 'reviewer']);
        $web = $this->signIn($p);
        $r = self::tool('reset_staff', '--email=' . strtoupper($p['email']), '--roles=stock_controller');
        self::assertSame([0, ''], [$r['code'], $r['out']], $r['err']);
        self::assertStringContainsString('roles: buyer,reviewer -> stock_controller', $r['err']);
        self::assertSame(['stock_controller'], StaffRoles::of(self::$db, $p['id']));
        $audit = self::$db->one("SELECT actor, detail FROM audit_log WHERE action = 'staff.roles'");
        self::assertSame('system:reset_staff', $audit['actor']);
        self::assertSame(['buyer', 'reviewer'], json_decode((string) $audit['detail'], true)['removed']);
        self::assertSame(200, $web->get('/ui/')->status, 'a role change alone keeps the session; the new roles apply on this request');

        $again = self::tool('reset_staff', '--email=' . $p['email'], '--roles=stock_controller', '--deactivate');
        self::assertSame(0, $again['code'], $again['err']);
        self::assertStringContainsString('(unchanged)', $again['err']);
        self::assertStringContainsString('roles stock_controller)', $again['err']);
        self::assertStringContainsString('active=no', $again['err']);
        self::assertSame('/ui/login', $web->get('/ui/')->location());
        self::assertSame(1, self::tool('reset_staff', '--email=' . $p['email'], '--roles=admin,buyer')['code'], 'refused by the rules');
        self::assertSame(1, self::tool('reset_staff', '--email=nobody@test.invalid', '--roles=viewer')['code']);
        self::assertSame(['stock_controller'], StaffRoles::of(self::$db, $p['id']));
    }

    /** A throwaway app.env (the tool adds its own ui_secret_key there): never the server's /etc/cw/app.env. */
    private function appEnv(): string
    {
        $this->dir = sys_get_temp_dir() . '/cw-roles-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $env = "{$this->dir}/app.env";
        file_put_contents($env, "# test app.env\n");
        chmod($env, 0640);
        return $env;
    }

    /** @return array{code: int, out: string, err: string} */
    private static function tool(string $name, string|array ...$args): array
    {
        $env = null;
        if ($args !== [] && is_array(end($args))) {
            $env = array_pop($args) + getenv();
        }
        $root = dirname(__DIR__, 3);
        $p = proc_open([PHP_BINARY, "{$root}/bin/{$name}.php", '--db=' . TestDb::name(), '--admin', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        self::assertIsResource($p);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }
}
