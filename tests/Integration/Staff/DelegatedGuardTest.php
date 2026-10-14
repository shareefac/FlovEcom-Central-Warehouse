<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Staff;

use CW\Admin\ApprovalRules;
use CW\Admin\RolePermissions;
use CW\Admin\ConfigHistory;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Settings;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffRoles;
use CW\Tests\Support\KernelUiTestCase;

/**
 * Pack E review finding 1 (docs/decisions.md PE16): the staff-grant and staff-reset rules (approvals.staff_grant, approvals.staff_reset)
 * guard the jobs that hold the powers the code keeps for Admin and Reviewer, read from the map in force, not only the two job names.
 * A Reviewer who gives such a power to another job (Roles and permissions) makes that job guarded too: giving it to a person, setting
 * a person up with it, and resetting the sign-in of a person who holds it wait for a reviewer's OK while the rule is on.
 */
final class DelegatedGuardTest extends KernelUiTestCase
{
    /** @var list<array<string, mixed>> */
    private array $settings = [];
    /** @var array<string, string> */
    private array $map = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting');
        $this->map = [];
        foreach (self::$db->all('SELECT permission, roles FROM role_permission') as $r) {
            $this->map[(string) $r['permission']] = (string) $r['roles'];
        }
        Permissions::useStored(null);
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['setting_key']]);
        }
        foreach ($this->map as $p => $roles) {
            self::$db->exec('UPDATE role_permission SET roles = ? WHERE permission = ?', [$roles, $p]);
        }
        Permissions::useStored(null);
        parent::tearDown();
    }

    private function delegate(array $reviewer, string $perm, string $job): void
    {
        RolePermissions::change(self::$db, Caller::staff($reviewer['id']), $perm, [...Permissions::MAP[$perm], $job], 'testing the delegated guard',
            ConfigHistory::version(self::$db, 'permission', $perm));
    }

    public function testTheGuardedJobsAreReadFromTheMapInForce(): void
    {
        self::assertSame(['admin', 'reviewer'], ApprovalRules::guardedRoles(self::$db), 'the map as delivered: as before');
        $rev = $this->uiUser('reviewer');
        $this->delegate($rev, 'reorder.view', 'warehouse');
        self::assertSame(['admin', 'reviewer'], ApprovalRules::guardedRoles(self::$db), 'a look-only power is not one of theirs');
        $this->delegate($rev, 'documents.approve', 'warehouse');
        self::assertSame(['warehouse', 'admin', 'reviewer'], ApprovalRules::guardedRoles(self::$db));
        $this->delegate($rev, 'suppliers.approve', 'purchasing_manager');
        self::assertSame(['warehouse', 'admin', 'purchasing_manager', 'reviewer'], ApprovalRules::guardedRoles(self::$db));
        foreach (Permissions::guardedPowers() as $perm) {
            self::assertSame([], array_values(array_diff(Permissions::MAP[$perm], ApprovalRules::GUARDED_ROLES)), "{$perm}: a power the code keeps for Admin and Reviewer");
        }
        self::assertContains('documents.approve', Permissions::guardedPowers());
        self::assertContains('staff.manage', Permissions::guardedPowers());
        self::assertNotContains('staff.view', Permissions::guardedPowers(), 'auditors look at staff too');
    }

    /** Codex's case: a reviewer gives document approval to Warehouse staff; an admin then gives Warehouse to someone. */
    public function testGivingAJobThatHoldsAReviewersPowerWaitsForAReviewer(): void
    {
        $admin = $this->uiUser('admin');
        $rev = $this->uiUser('reviewer');
        $p = $this->uiUser('buyer');
        $svc = new StaffAdmin(self::$appDb);
        (new Settings(self::$db))->change(Caller::staff($admin['id']), 'approvals.staff_grant', 'true', 'the owner wants a second person');
        self::assertSame('changed', $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['buyer', 'warehouse'], ['buyer'])['result'], 'not guarded yet');
        $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['buyer'], ['buyer', 'warehouse']);

        $this->delegate($rev, 'documents.approve', 'warehouse');
        $r = $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['buyer', 'warehouse'], ['buyer']);
        self::assertSame('requested', $r['result'], 'Warehouse now gives document approval: it waits for a reviewer');
        self::assertSame(['buyer'], StaffRoles::of(self::$db, $p['id']), 'nothing applied yet');
        self::assertSame(['warehouse'], json_decode((string) self::$db->value('SELECT guarded FROM staff_role_request WHERE id = ?', [$r['request']]), true));
        // Setting a new person up with it waits the same way; a job without such a power does not.
        $made = $svc->enrol(Caller::staff($admin['id']), 'new-warehouse@test.example', ['warehouse', 'goods_in'], self::$box, 'New Warehouse');
        self::assertSame(['goods_in'], StaffRoles::of(self::$db, $made['id']), 'the guarded job waits; the other is given at once');
        self::assertNotNull($made['request']);
        $q = $this->uiUser('buyer');
        self::assertSame('changed', $svc->setRoles(Caller::staff($admin['id']), $q['id'], ['buyer', 'goods_in'], ['buyer'])['result']);
    }

    public function testResettingThePersonWhoHoldsSuchAJobWaitsForAReviewer(): void
    {
        $admin = $this->uiUser('admin');
        $rev = $this->uiUser('reviewer');
        $w = $this->uiUser('warehouse');
        $svc = new StaffAdmin(self::$appDb);
        (new Settings(self::$db))->change(Caller::staff($admin['id']), 'approvals.staff_reset', 'true', 'the owner wants a second person');
        self::assertSame('done', $svc->resetPassword(Caller::staff($admin['id']), $w['id'])['result'], 'not guarded yet');
        $this->delegate($rev, 'documents.review', 'warehouse');
        self::assertSame('requested', $svc->resetPassword(Caller::staff($admin['id']), $w['id'])['result']);
    }
}
