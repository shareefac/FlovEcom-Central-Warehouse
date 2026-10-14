<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Admin;

use CW\Admin\ConfigHistory;
use CW\Admin\ConfigInvariants;
use CW\Admin\RolePermissions;
use CW\Auth\Permissions;
use CW\Auth\RoleMap;
use CW\Caller;
use CW\Staff\StaffRoles;
use CW\Tests\Support\MappingTestCase;

/**
 * Pack E (docs/decisions.md PE2-PE8): who may do what, changed on the screen (role_permission, Admin\RolePermissions). This is
 * security work: one test per guard, and "a person cannot grant themselves more rights". Every test puts role_permission back as it
 * found it (a seed table, like app_setting) and the map in force back to the code's, and the nightly configuration checks
 * (ConfigInvariants K1-K4) agree with whatever was changed.
 */
final class RolePermissionsTest extends MappingTestCase
{
    /** @var array<string, string> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = [];
        foreach (self::$db->all('SELECT permission, roles FROM role_permission') as $r) {
            $this->saved[(string) $r['permission']] = (string) $r['roles'];
        }
        Permissions::useStored(null);
    }

    protected function tearDown(): void
    {
        foreach (self::$db->column('SELECT permission FROM role_permission') as $p) {
            if (!isset($this->saved[(string) $p])) {
                self::$db->exec('DELETE FROM role_permission WHERE permission = ?', [$p]);
            }
        }
        foreach ($this->saved as $p => $roles) {
            self::$db->exec("INSERT INTO role_permission (permission, roles, updated_actor) VALUES (?, ?, 'system:migrate') AS n "
                . 'ON DUPLICATE KEY UPDATE roles = n.roles, updated_actor = n.updated_actor', [$p, $roles]);
        }
        RoleMap::runWithoutTable(false);
        Permissions::useStored(null);
        parent::tearDown();
    }

    /** @param list<string> $holders */
    private function change(Caller $by, string $perm, array $holders, ?int $seen = null, string $reason = 'testing the roles screen'): array
    {
        return RolePermissions::change(self::$db, $by, $perm, $holders, $reason, $seen ?? ConfigHistory::version(self::$db, 'permission', $perm));
    }

    private function row(string $perm): ?string
    {
        $v = self::$db->value('SELECT roles FROM role_permission WHERE permission = ?', [$perm]);
        return $v === null ? null : (string) $v;
    }

    /** @return list<array<string, mixed>> */
    private function audits(): array
    {
        return self::$db->all("SELECT actor, entity_id, CAST(detail AS CHAR) AS detail FROM audit_log WHERE action = 'permission.change' ORDER BY id");
    }

    /** Nothing changes on deploy: the table is the code's map, each row with its baseline, and the nightly checks are clean. */
    public function testTheSeededTableIsTheCodeMapWithItsHistory(): void
    {
        self::assertSame(array_keys(Permissions::MAP), array_values(array_intersect(array_keys(Permissions::MAP), array_keys($this->saved))));
        foreach (Permissions::MAP as $perm => $holders) {
            self::assertSame(implode(',', Permissions::ordered($holders)), $this->saved[$perm], $perm);
            $v1 = ConfigHistory::latest(self::$db, 'permission', $perm);
            self::assertSame([1, 'baseline', 'system:migrate', ['roles' => $this->saved[$perm]]], [$v1['version'], $v1['action'], $v1['actor'], $v1['state']], $perm);
        }
        RoleMap::load(self::$db);
        self::assertSame(Permissions::defaults(), Permissions::map());
        self::assertSame([], ConfigInvariants::check(self::$db));
    }

    /** Giving a job a permission is loosening: a Reviewer does it, it works on the next check, and it is in the history and the audit log. */
    public function testAReviewerGivesAJobAPermissionAndItWorksAtOnce(): void
    {
        $reviewer = $this->staffUser('reviewer');
        $buyer = $this->staffUser('buyer');
        self::assertFalse(Permissions::can(StaffRoles::active(self::$db, (int) $buyer->staffUserId), 'incidents.view'));
        $r = $this->change($reviewer, 'incidents.view', [...Permissions::MAP['incidents.view'], 'buyer']);
        self::assertSame([true, ['buyer'], [], 2], [$r['changed'], $r['added'], $r['removed'], $r['version']]);
        self::assertStringContainsString('buyer', (string) $this->row('incidents.view'));
        self::assertTrue(Permissions::can(StaffRoles::active(self::$db, (int) $buyer->staffUserId), 'incidents.view'), 'in force at once');
        $v = ConfigHistory::latest(self::$db, 'permission', 'incidents.view');
        self::assertSame([2, 'change', $reviewer->actor], [$v['version'], $v['action'], $v['actor']]);
        self::assertSame('testing the roles screen', ConfigHistory::history(self::$db, 'permission', 'incidents.view')[0]['reason']);
        $a = $this->audits();
        self::assertCount(1, $a);
        $detail = json_decode((string) $a[0]['detail'], true);
        self::assertSame(['incidents.view', ['buyer'], [], true, 2], [$detail['permission'], $detail['added'], $detail['removed'], $detail['loosened'], $detail['version']]);
        self::assertSame('testing the roles screen', $detail['reason']);
        self::assertSame([], ConfigInvariants::check(self::$db));

        // The same set again changes nothing and writes nothing.
        $again = $this->change($reviewer, 'incidents.view', [...Permissions::MAP['incidents.view'], 'buyer']);
        self::assertFalse($again['changed']);
        self::assertCount(1, $this->audits());
    }

    /** Only a Reviewer loosens (I1, Y45): an admin's "give" is refused and nothing is written; an admin may take a permission away. */
    public function testAnAdminMayTakeAPermissionAwayButNeverGiveOne(): void
    {
        $admin = $this->staffUser('admin');
        $before = $this->row('reorder.view');
        self::refused(403, 'loosen_needs_reviewer', fn () => $this->change($admin, 'reorder.view', [...Permissions::MAP['reorder.view'], 'goods_in']));
        // A change that gives one job and takes another is still a give.
        self::refused(403, 'loosen_needs_reviewer', fn () => $this->change($admin, 'reorder.view', ['buyer', 'goods_in']));
        self::assertSame($before, $this->row('reorder.view'));
        self::assertSame(1, ConfigHistory::version(self::$db, 'permission', 'reorder.view'));
        self::assertSame([], $this->audits());

        $r = $this->change($admin, 'reorder.view', ['buyer', 'purchasing_manager', 'reviewer']);
        self::assertSame([true, [], ['manager', 'auditor']], [$r['changed'], $r['added'], $r['removed']]);
        self::assertFalse(Permissions::can(['manager'], 'reorder.view'));
        self::assertArrayNotHasKey('loosened', json_decode((string) $this->audits()[0]['detail'], true));
        // The owner's staging account (Admin + Reviewer + Matching lead): its Reviewer job is off while it holds Admin, so it cannot give.
        $owner = $this->staffUser(['admin', 'mapping_lead', 'reviewer']);
        self::refused(403, 'loosen_needs_reviewer', fn () => $this->change($owner, 'reorder.view', ['buyer', 'purchasing_manager', 'reviewer', 'manager']));
        self::assertSame([], ConfigInvariants::check(self::$db));
    }

    /** A person never grants themselves more rights: not to a job they hold, whichever of their jobs it is, re-read in the transaction. */
    public function testNobodyGivesAPermissionToAJobTheyHold(): void
    {
        $reviewer = $this->staffUser('reviewer');
        $e = self::refused(403, 'own_job', fn () => $this->change($reviewer, 'reorder.manage', [...Permissions::MAP['reorder.manage'], 'reviewer']));
        self::assertSame(['reviewer'], $e->detail['roles']);
        $both = $this->staffUser(['reviewer', 'buyer']);
        self::refused(403, 'own_job', fn () => $this->change($both, 'incidents.resolve', [...Permissions::MAP['incidents.resolve'], 'buyer']));
        self::refused(403, 'own_job', fn () => $this->change($both, 'incidents.resolve', [...Permissions::MAP['incidents.resolve'], 'buyer', 'goods_in']),
            'one of the jobs given is theirs: the whole change is refused');
        self::assertSame($this->saved['incidents.resolve'], $this->row('incidents.resolve'));
        self::assertSame($this->saved['reorder.manage'], $this->row('reorder.manage'));
        // A job they do not hold: allowed (and audited).
        $r = $this->change($both, 'incidents.resolve', [...Permissions::MAP['incidents.resolve'], 'goods_in']);
        self::assertSame(['goods_in'], $r['added']);
        // Their jobs are read inside the transaction: a job given to them a moment ago counts.
        self::$db->exec('INSERT INTO staff_role (staff_user_id, role) VALUES (?, ?)', [$reviewer->staffUserId, 'warehouse']);
        self::refused(403, 'own_job', fn () => $this->change($reviewer, 'reorder.view', [...Permissions::MAP['reorder.view'], 'warehouse']));
        // Taking a permission away from their own job is allowed (fewer rights).
        $r = $this->change($reviewer, 'audit.view', ['admin', 'accountant', 'auditor']);
        self::assertSame(['reviewer'], $r['removed']);
        self::assertFalse(Permissions::can(['reviewer'], 'audit.view'));
        self::assertSame([], ConfigInvariants::check(self::$db));
    }

    /** I12: admin, and the look-only jobs admin may be combined with, never get a permission that changes anything; they may look. */
    public function testAdminAndTheLookOnlyJobsNeverGetAPermissionThatActs(): void
    {
        $reviewer = $this->staffUser('reviewer');
        foreach (['admin', 'viewer', 'accountant', 'auditor'] as $job) {
            self::refused(422, 'acting_job', fn () => $this->change($reviewer, 'doc.PO.post', [...Permissions::MAP['doc.PO.post'], $job]));
            self::refused(422, 'acting_job', fn () => $this->change($reviewer, 'accounts.pay', [...Permissions::MAP['accounts.pay'], $job]));
            self::refused(422, 'acting_job', fn () => $this->change(Caller::system('roles_test'), 'reorder.manage', [...Permissions::MAP['reorder.manage'], $job]),
                'not even a server tool');
        }
        self::assertSame($this->saved['doc.PO.post'], $this->row('doc.PO.post'));
        // Looking is fine: a reviewer lets the admins look at the records.
        $this->change($reviewer, 'documents.view', [...Permissions::MAP['documents.view'], 'admin']);
        self::assertTrue(Permissions::can(['admin'], 'documents.view'));
        // A row written around the screen (admin SQL) is read fail-closed: the acting permission does not reach admin or a look-only job.
        self::$db->exec("UPDATE role_permission SET roles = 'admin,stock_controller,auditor' WHERE permission = 'doc.ADJ.post'");
        RoleMap::load(self::$db);
        self::assertFalse(Permissions::can(['admin'], 'doc.ADJ.post'));
        self::assertFalse(Permissions::can(['auditor'], 'doc.ADJ.post'));
        self::assertFalse(Permissions::can(['admin', 'auditor'], 'doc.ADJ.post'));
        self::assertTrue(Permissions::can(['stock_controller'], 'doc.ADJ.post'));
        // ... and the person who manages people still cannot hold a working job (checkRoleSet is untouched).
        self::refused(422, 'role_conflict', static fn () => Permissions::checkRoleSet(['admin', 'stock_controller']));
    }

    /** The locked permissions never change on the screen, not even by a server tool; a row written around it does not count. */
    public function testTheLockedPermissionsNeverChange(): void
    {
        $reviewer = $this->staffUser('reviewer');
        $cases = [
            'settings.manage' => ['admin'], // stays admin plus reviewer
            'staff.manage' => ['admin', 'buyer'],
            'staff.approve' => ['reviewer', 'buyer'],
            'catalogue.view' => ['buyer'],
            'reference.view' => ['reviewer'],
            'mapping.decide' => ['mapper'],
            'mapping.approve' => ['mapping_lead', 'buyer'],
            'company.edit' => ['reviewer', 'buyer'],
            'company.confirm' => ['buyer'],
        ];
        foreach ($cases as $perm => $holders) {
            self::refused(409, 'permission_locked', fn () => $this->change($reviewer, $perm, $holders));
            self::refused(409, 'permission_locked', fn () => $this->change(Caller::system('roles_test'), $perm, $holders));
            self::assertSame($this->saved[$perm], $this->row($perm), $perm);
        }
        self::$db->exec("UPDATE role_permission SET roles = 'buyer' WHERE permission = 'settings.manage'");
        self::$db->exec("UPDATE role_permission SET roles = 'admin,buyer' WHERE permission = 'staff.manage'");
        self::$db->exec("UPDATE role_permission SET roles = 'reviewer,buyer' WHERE permission = 'staff.approve'");
        RoleMap::load(self::$db);
        self::assertSame(['admin', 'reviewer'], Permissions::holders('settings.manage'), 'settings.manage stays admin plus reviewer');
        self::assertFalse(Permissions::can(['buyer'], 'settings.manage'));
        self::assertFalse(Permissions::can(['buyer'], 'staff.manage'));
        self::assertFalse(Permissions::can(['buyer'], 'staff.approve'), 'only a Reviewer loosens a rule');
        self::assertSame([], $this->audits());
    }

    /** The guarded jobs keep their own permissions: an admin sees the people; a reviewer gives the checks and the OKs. */
    public function testTheGuardedJobsCannotBeEditedIntoAnUnsafeState(): void
    {
        $admin = $this->staffUser('admin');
        $reviewer = $this->staffUser('reviewer');
        self::refused(422, 'required_job', fn () => $this->change($admin, 'staff.view', ['auditor']));
        foreach (['documents.review', 'documents.approve', 'suppliers.approve'] as $perm) {
            $e = self::refused(422, 'required_job', fn () => $this->change($reviewer, $perm, ['purchasing_manager']));
            self::assertSame(['reviewer'], $e->detail['required']);
        }
        self::refused(422, 'no_holders', fn () => $this->change($admin, 'doc.WO.post', []));
        // A row written around the screen cannot take them away either.
        self::$db->exec("UPDATE role_permission SET roles = '' WHERE permission = 'documents.review'");
        self::$db->exec("UPDATE role_permission SET roles = 'auditor' WHERE permission = 'staff.view'");
        RoleMap::load(self::$db);
        self::assertTrue(Permissions::can(['reviewer'], 'documents.review'));
        self::assertTrue(Permissions::can(['admin'], 'staff.view'));
        // What may change around them does: an admin takes the auditors off the staff list (tightening, settings.manage).
        self::$db->exec('UPDATE role_permission SET roles = ? WHERE permission = ?', [$this->saved['staff.view'], 'staff.view']);
        self::$db->exec('UPDATE role_permission SET roles = ? WHERE permission = ?', [$this->saved['documents.review'], 'documents.review']);
        $r = $this->change($admin, 'staff.view', ['admin']);
        self::assertSame(['auditor'], $r['removed']);
        self::assertSame([], ConfigInvariants::check(self::$db));
    }

    /** Who may change the map at all: an admin or a reviewer (settings.manage, re-read in the transaction); a store never. */
    public function testOnlyAnAdminOrAReviewerChangesTheMap(): void
    {
        foreach (['buyer', 'auditor', 'mapping_lead', 'stock_controller', 'purchasing_manager'] as $job) {
            self::refused(403, 'role_not_allowed', fn () => $this->change($this->staffUser($job), 'reorder.view', ['buyer']));
        }
        self::refused(403, 'staff_not_allowed', fn () => $this->change($this->staffUser('reviewer', false), 'reorder.view', ['buyer']));
        $site = self::makeChannel('roles_site');
        self::refused(403, 'staff_required', fn () => $this->change(Caller::channel($site, 'roles_site'), 'reorder.view', ['buyer']));
        // A reviewer whose job was taken away a moment ago is refused (read inside the transaction).
        $gone = $this->staffUser('reviewer');
        self::$db->exec('UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ?', [$gone->staffUserId]);
        self::refused(403, 'role_not_allowed', fn () => $this->change($gone, 'reorder.view', ['buyer']));
        self::assertSame($this->saved['reorder.view'], $this->row('reorder.view'));
        self::assertSame([], $this->audits());
    }

    /** The form's rules: the version it was drawn with, a known permission and jobs, a reason. */
    public function testAStaleOrBadFormIsRefused(): void
    {
        $reviewer = $this->staffUser('reviewer');
        $this->change($reviewer, 'reorder.view', ['buyer', 'purchasing_manager']);
        self::refused(409, 'changed_meanwhile', fn () => $this->change($reviewer, 'reorder.view', ['buyer'], 1));
        self::refused(400, 'unknown_permission', fn () => $this->change($reviewer, 'staff.mange', ['buyer']));
        self::refused(400, 'bad_role', fn () => $this->change($reviewer, 'reorder.view', ['buyer', 'boss']));
        self::refused(400, 'bad_reason', fn () => $this->change($reviewer, 'reorder.view', ['buyer'], null, ' x '));
        self::assertSame('buyer,purchasing_manager', $this->row('reorder.view'), 'nothing of the refused forms was written');
        self::assertSame(2, ConfigHistory::version(self::$db, 'permission', 'reorder.view'));
    }

    /**
     * A permission the code adds after 0034 (another pack's) has no row: it reads as the code says at once, and its first change on
     * the screen writes its row, with a baseline of the code's holders first, so the nightly checks hold.
     */
    public function testAPermissionWithoutARowReadsAsTheCodeAndGetsItsRowOnItsFirstChange(): void
    {
        $saved = self::$db->one("SELECT id, CAST(state AS CHAR) AS state FROM config_change WHERE subject_type = 'permission' AND subject_key = 'incidents.resolve'");
        self::$db->exec("DELETE FROM config_change WHERE subject_type = 'permission' AND subject_key = 'incidents.resolve'");
        self::$db->exec("DELETE FROM role_permission WHERE permission = 'incidents.resolve'");
        try {
            RoleMap::load(self::$db);
            self::assertSame(Permissions::ordered(Permissions::MAP['incidents.resolve']), Permissions::holders('incidents.resolve'));
            self::assertTrue(Permissions::can(['purchasing_desk'], 'incidents.resolve'));
            $all = array_column(RolePermissions::all(self::$db), null, 'perm');
            self::assertSame([true, 0], [$all['incidents.resolve']['from_code'], $all['incidents.resolve']['version']]);
            self::assertFalse($all['reorder.view']['from_code']);

            $admin = $this->staffUser('admin');
            $r = RolePermissions::change(self::$db, $admin, 'incidents.resolve', ['purchasing_manager', 'stock_controller'], 'the desk stops closing issues', 0);
            self::assertSame([true, ['purchasing_desk'], 2], [$r['changed'], $r['removed'], $r['version']]);
            self::assertSame('purchasing_manager,stock_controller', $this->row('incidents.resolve'));
            $h = ConfigHistory::history(self::$db, 'permission', 'incidents.resolve');
            self::assertSame(['change', 'baseline'], array_column($h, 'action'));
            self::assertSame(['roles' => implode(',', Permissions::defaults()['incidents.resolve'])], $h[1]['state']);
            self::assertFalse(Permissions::can(['purchasing_desk'], 'incidents.resolve'));
            self::assertSame([], ConfigInvariants::check(self::$db));
        } finally {
            self::$db->exec("DELETE FROM config_change WHERE subject_type = 'permission' AND subject_key = 'incidents.resolve'");
            self::$db->exec("INSERT INTO config_change (subject_type, subject_key, version, action, state, actor) VALUES ('permission', 'incidents.resolve', 1, 'baseline', CAST(? AS JSON), 'system:migrate')",
                [(string) $saved['state']]);
        }
    }

    /**
     * Pack E review finding 2 (PE17): the map is never guessed. Without the table (a restore or a deploy half done) the read fails
     * and nothing is allowed, so a permission taken away on the screen never comes back from the code's defaults.
     */
    public function testAMissingTableFailsClosedAndNeverRestoresARemovedPermission(): void
    {
        $this->change($this->staffUser('reviewer'), 'doc.WO.post', ['stock_controller', 'purchasing_manager']);
        $this->change($this->staffUser('admin'), 'doc.WO.post', ['purchasing_manager']);
        $controller = $this->staffUser('stock_controller');
        RoleMap::load(self::$db);
        self::assertFalse(Permissions::can(['stock_controller'], 'doc.WO.post'), 'taken away on the screen');
        self::$db->exec('RENAME TABLE role_permission TO role_permission_gone');
        try {
            self::assertSame(1146, self::mysqlError(static fn () => RoleMap::load(self::$db)));
            self::assertFalse(Permissions::can(['stock_controller'], 'doc.WO.post'), 'the code default does not come back');
            self::assertFalse(Permissions::can(['purchasing_manager'], 'doc.PO.post'), 'nothing is allowed after a failed read');
            self::assertSame(1146, self::mysqlError(static fn () => StaffRoles::active(self::$db, (int) $controller->staffUserId)), 'a service\'s check fails too');
            // Running without the table is an explicit choice (the migration tests' older schemas), never the answer to an error.
            RoleMap::runWithoutTable(true);
            RoleMap::load(self::$db);
            self::assertTrue(Permissions::can(['purchasing_manager'], 'doc.PO.post'), 'the code\'s map, because the caller said so');
            RoleMap::runWithoutTable(false);
            self::assertSame(1146, self::mysqlError(static fn () => RoleMap::load(self::$db)), 'and off again: the read fails closed');
        } finally {
            self::$db->exec('RENAME TABLE role_permission_gone TO role_permission');
        }
        RoleMap::load(self::$db);
        self::assertFalse(Permissions::can(['stock_controller'], 'doc.WO.post'));
        self::assertTrue(Permissions::can(['purchasing_manager'], 'doc.PO.post'));
    }

    /** A server tool (the break-glass) may give and take, but the rules still hold for it. */
    public function testAServerToolMayGiveButKeepsTheRules(): void
    {
        $r = $this->change(Caller::system('roles_test'), 'documents.approve', ['reviewer', 'purchasing_manager']);
        self::assertSame(['purchasing_manager'], $r['added']);
        self::assertTrue(Permissions::can(['purchasing_manager'], 'documents.approve'));
        self::refused(422, 'required_job', fn () => $this->change(Caller::system('roles_test'), 'documents.approve', ['purchasing_manager']));
        self::assertSame([], ConfigInvariants::check(self::$db));
    }
}
