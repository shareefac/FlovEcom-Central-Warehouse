<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Staff;

use CW\Admin\ApprovalRules;
use CW\Auth\Permissions;
use CW\Caller;
use CW\Documents\Documents;
use CW\Settings;
use CW\Staff\RoleRequests;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffRoles;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\TestDb;

/**
 * The People manager (0069; docs/decisions.md PM1-PM7; the owner, 16 Nov 2026: "i want you to build the option to create users, admin roles
 * etc for my cw account. so i can create users there"; 18 Nov 2026: "build it in so Fazil can create [staff] himself"): a job that adds
 * people, gives and takes their jobs (Admin and People manager too), switches them on and off and resets their sign-in, held together
 * with the owner's working jobs, so he keeps posting and reviewing. It is guarded like Admin and Reviewer (the staff-grant and
 * staff-reset rules), never changes its holder's own access, and bin/grant_role.php gives it to an existing account on the server: a dry
 * run unless --apply, audited, never taking the power to manage people from the last person who has it.
 */
final class PeopleManagerTest extends KernelUiTestCase
{
    /** The owner's jobs on production (16 Nov 2026), and People manager. */
    private const OWNER = ['accountant', 'mapping_lead', 'people_manager', 'purchasing_manager', 'reviewer', 'stock_controller'];

    /** @var list<array<string, mixed>> */
    private array $settings = [];
    private ?string $dir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting');
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['setting_key']]);
        }
        if ($this->dir !== null && is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testAPeopleManagerAddsPeopleGivesAnyJobSwitchesThemOffAndResetsTheirSignIn(): void
    {
        $pm = $this->uiUser(self::OWNER);
        $svc = new StaffAdmin(self::$appDb);
        $as = Caller::staff($pm['id'], '198.51.100.71');

        // A new person, with the sign-up sheet; Admin and People manager can be given too.
        $desk = $svc->enrol($as, 'new-desk@test.example', ['purchasing_desk'], self::$box, 'New Desk');
        self::assertNull($desk['request']);
        self::assertSame(['purchasing_desk'], StaffRoles::of(self::$db, $desk['id']));
        self::assertSame($pm['id'], (int) self::$db->value('SELECT granted_by FROM staff_role WHERE staff_user_id = ?', [$desk['id']]));
        $adm = $svc->enrol($as, 'new-admin@test.example', ['admin', 'auditor'], self::$box, 'New Admin');
        self::assertSame(['admin', 'auditor'], StaffRoles::of(self::$db, $adm['id']));
        $other = $svc->enrol($as, 'new-pm@test.example', ['people_manager', 'buyer'], self::$box, 'New Manager');
        self::assertSame(['buyer', 'people_manager'], StaffRoles::of(self::$db, $other['id']));
        self::assertSame("staff:{$pm['id']}", self::$db->value("SELECT actor FROM audit_log WHERE action = 'staff.create' AND entity_id = ?", [(string) $other['id']]));

        // Jobs given and taken, with the history and the audit naming the People manager.
        $p = $this->uiUser('buyer');
        $r = $svc->setRoles($as, $p['id'], ['buyer', 'reviewer', 'people_manager'], ['buyer']);
        self::assertSame(['changed', ['people_manager', 'reviewer']], [$r['result'], $r['added']]);
        self::assertSame("staff:{$pm['id']}", self::$db->value("SELECT actor FROM audit_log WHERE action = 'staff.roles' AND entity_id = ?", [(string) $p['id']]));
        self::assertSame('changed', $svc->setRoles($as, $p['id'], ['buyer'], ['buyer', 'people_manager', 'reviewer'])['result']);
        self::refused(422, 'role_conflict', fn () => $svc->setRoles($as, $p['id'], ['admin', 'reviewer'], null));
        self::refused(422, 'role_conflict', fn () => $svc->setRoles($as, $p['id'], ['admin', 'people_manager'], null));

        // Switched off and on, a new password, a new sign-up sheet, signed out: the Admin's screens, all of them.
        self::assertSame('changed', $svc->setActive($as, $p['id'], false)['result']);
        self::assertSame('changed', $svc->setActive($as, $p['id'], true)['result']);
        self::assertSame('done', $svc->resetPassword($as, $p['id'])['result']);
        self::assertSame('done', $svc->newSheet($as, $desk['id'], self::$box)['result']);
        self::assertSame(0, $svc->signOut($as, $p['id'], null));

        // Never their own access; the owner's work goes on.
        $own = self::refused(403, 'own_account', fn () => $svc->setRoles($as, $pm['id'], ['people_manager'], null));
        self::assertStringContainsString('ask another admin or people manager', $own->getMessage());
        self::refused(403, 'own_account', fn () => $svc->setActive($as, $pm['id'], false));
        self::refused(403, 'own_account', fn () => $svc->resetPassword($as, $pm['id']));
        $roles = StaffRoles::active(self::$db, $pm['id']);
        self::assertSame(self::OWNER, $roles);
        foreach (['ADJ', 'PO', 'GRN', 'CNT'] as $type) {
            self::assertTrue(Documents::mayPost($roles, $type), "the owner still posts {$type}");
        }
        self::assertTrue(Permissions::can($roles, 'documents.review'));
        self::assertTrue(Permissions::can($roles, 'staff.approve'));
    }

    /**
     * PM4 (Y40-Y44 unchanged): a People manager never holds both sign-in factors of anybody, also not of another People manager, an Admin
     * or a Reviewer. A new password gives them a set-up code: a new sign-in code waits until that window is over. A new sign-in code
     * gives them the code app's secret: a new password waits until the person has used it with their own password. Someone who has
     * set up their sign-in never gets a new sign-up sheet.
     */
    public function testAPeopleManagerNeverHoldsBothSignInFactorsOfAnybody(): void
    {
        $as = Caller::staff($this->uiUser(['people_manager', 'buyer'])['id']);
        $svc = new StaffAdmin(self::$appDb);
        foreach (['people_manager', 'admin', 'reviewer'] as $job) {
            $a = $this->uiUser($job);
            $pw = $svc->resetPassword($as, $a['id']);
            self::assertSame('done', $pw['result'], $job);
            self::assertNotNull($pw['setup_code']);
            self::refused(409, 'setup_open', fn () => $svc->resetAuthenticator($as, $a['id'], self::$box));
            self::refused(409, 'already_set_up', fn () => $svc->newSheet($as, $a['id'], self::$box));

            $b = $this->uiUser($job);
            $code = $svc->resetAuthenticator($as, $b['id'], self::$box);
            self::assertSame('done', $code['result'], $job);
            self::assertNotNull($code['secret']);
            self::refused(409, 'code_reset_open', fn () => $svc->resetPassword($as, $b['id']));
            self::refused(409, 'already_set_up', fn () => $svc->newSheet($as, $b['id'], self::$box));
        }
    }

    public function testOnlyAnAdminOrAPeopleManagerManagesPeople(): void
    {
        $svc = new StaffAdmin(self::$appDb);
        $p = $this->uiUser('buyer');
        foreach (['auditor', 'reviewer', 'buyer', ['reviewer', 'stock_controller', 'accountant']] as $roles) {
            $u = $this->uiUser($roles);
            $label = implode('+', (array) $roles);
            self::refused(403, 'role_not_allowed', fn () => $svc->setRoles(Caller::staff($u['id']), $p['id'], ['buyer', 'reviewer'], null));
            self::refused(403, 'role_not_allowed', fn () => $svc->enrol(Caller::staff($u['id']), "x-{$u['id']}@test.example", ['viewer'], self::$box, 'Nobody'));
            self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_user WHERE email = ?', ["x-{$u['id']}@test.example"]), $label);
        }
        $a = $this->uiUser('people_manager');
        $b = $this->uiUser(['people_manager', 'buyer']);
        // One People manager takes the job from another; the other can no longer act (re-read inside the transaction).
        self::assertSame('changed', $svc->setRoles(Caller::staff($a['id']), $b['id'], ['buyer'], ['buyer', 'people_manager'])['result']);
        self::refused(403, 'role_not_allowed', fn () => $svc->setRoles(Caller::staff($b['id']), $p['id'], ['buyer', 'viewer'], null));
        $denied = self::refused(403, 'role_not_allowed', fn () => $svc->setActive(Caller::staff($b['id']), $p['id'], false));
        self::assertStringContainsString('only an admin or a people manager', $denied->getMessage());
        self::assertSame(['buyer'], StaffRoles::of(self::$db, $p['id']));
    }

    public function testGivingOrResettingAPeopleManagerWaitsForAReviewerWhileTheRulesAreOn(): void
    {
        self::assertSame(['admin', 'reviewer', 'people_manager'], ApprovalRules::guardedRoles(self::$db));
        $admin = $this->uiUser('admin');
        $rev = $this->uiUser('reviewer');
        $p = $this->uiUser('buyer');
        $svc = new StaffAdmin(self::$appDb);
        (new Settings(self::$db))->change(Caller::staff($admin['id']), 'approvals.staff_grant', 'true', 'the owner wants a second person');

        $r = $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['buyer', 'people_manager'], ['buyer']);
        self::assertSame('requested', $r['result'], 'People manager is given only after a reviewer\'s OK');
        self::assertSame(['buyer'], StaffRoles::of(self::$db, $p['id']));
        self::assertSame(['people_manager'], json_decode((string) self::$db->value('SELECT guarded FROM staff_role_request WHERE id = ?', [$r['request']]), true));
        $made = $svc->enrol(Caller::staff($admin['id']), 'pm-new@test.example', ['people_manager', 'goods_in'], self::$box, 'Pm New');
        self::assertSame(['goods_in'], StaffRoles::of(self::$db, $made['id']), 'set up with the other jobs; People manager waits');
        self::assertNotNull($made['request']);
        self::assertSame('approved', (new RoleRequests(self::$appDb))->decide(Caller::staff($rev['id']), (int) $r['request'], true, null)['result']);
        self::assertSame(['buyer', 'people_manager'], StaffRoles::of(self::$db, $p['id']));

        // The owner (People manager and Reviewer) never gives the OK to what he asked for himself.
        $owner = $this->uiUser(self::OWNER);
        $q = $this->uiUser('buyer');
        $asked = $svc->setRoles(Caller::staff($owner['id']), $q['id'], ['buyer', 'reviewer'], ['buyer']);
        self::assertSame('requested', $asked['result']);
        self::refused(403, 'own_request', fn () => (new RoleRequests(self::$appDb))->decide(Caller::staff($owner['id']), (int) $asked['request'], true, null));

        // Resetting the sign-in of a People manager waits the same way while the reset rule is on.
        (new Settings(self::$db))->change(Caller::staff($admin['id']), 'approvals.staff_reset', 'true', 'the owner wants a second person');
        self::assertSame('requested', $svc->resetPassword(Caller::staff($admin['id']), $p['id'])['result']);
        self::assertSame('done', $svc->resetPassword(Caller::staff($admin['id']), $this->uiUser('buyer')['id'])['result'], 'a buyer is not guarded');
    }

    public function testTheGrantToolIsADryRunUntilApplyAndIsAudited(): void
    {
        $this->uiUser('admin'); // someone who can manage people already (production: nobody; see the last test)
        $owner = $this->uiUser(['accountant', 'mapping_lead', 'purchasing_manager', 'reviewer', 'stock_controller']);
        $before = ['accountant', 'mapping_lead', 'purchasing_manager', 'reviewer', 'stock_controller'];

        $dry = self::tool('grant_role', '--email=' . strtoupper($owner['email']), '--role=people_manager');
        self::assertSame(0, $dry['code'], $dry['err']);
        self::assertStringContainsString('DRY RUN', $dry['out']);
        self::assertStringContainsString('roles accountant,mapping_lead,purchasing_manager,reviewer,stock_controller -> '
            . 'accountant,mapping_lead,people_manager,purchasing_manager,reviewer,stock_controller', $dry['out']);
        self::assertStringContainsString('--apply', $dry['out']);
        self::assertSame($before, StaffRoles::of(self::$db, $owner['id']), 'a dry run writes nothing');
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.roles'"));

        $apply = self::tool('grant_role', '--email=' . $owner['email'], '--role=people_manager', '--apply');
        self::assertSame(0, $apply['code'], $apply['err']);
        self::assertStringContainsString('changed', $apply['out']);
        self::assertSame(self::OWNER, StaffRoles::of(self::$db, $owner['id']));
        self::assertNull(self::$db->value("SELECT granted_by FROM staff_role WHERE staff_user_id = ? AND role = 'people_manager'", [$owner['id']]), 'given on the server');
        $audit = self::$db->one("SELECT actor, entity_id, detail FROM audit_log WHERE action = 'staff.roles'");
        self::assertSame(['system:grant_role', (string) $owner['id']], [$audit['actor'], $audit['entity_id']]);
        self::assertEquals(['email' => $owner['email'], 'before' => $before, 'after' => self::OWNER, 'added' => ['people_manager'], 'removed' => []],
            json_decode((string) $audit['detail'], true));
        self::assertTrue(Permissions::can(StaffRoles::active(self::$db, $owner['id']), 'staff.manage'));

        $again = self::tool('grant_role', '--email=' . $owner['email'], '--role=people_manager', '--apply');
        self::assertSame(0, $again['code'], $again['err']);
        self::assertStringContainsString('unchanged', $again['out']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.roles'"));

        // Refused, writing nothing: an unknown address or job, Admin with working jobs (I12), the words the tool cannot read.
        $refusals = [
            [1, ['--email=nobody@test.example', '--role=people_manager', '--apply'], 'no staff user'],
            [1, ['--email=' . $owner['email'], '--role=boss', '--apply'], 'a role must be one of'],
            [1, ['--email=' . $owner['email'], '--role=admin', '--apply'], 'admin cannot be combined with'],
            [2, ['--email=' . $owner['email']], '--role'],
            [2, ['--role=people_manager'], '--email'],
            [2, ['--email=' . $owner['email'], '--role=people_manager', '--apply=yes'], 'takes no value'],
            [2, ['--email=' . $owner['email'], '--rol=people_manager'], 'unknown option'],
            [2, ['--email=' . $owner['email'], '--role=people_manager', 'apply'], 'not an option'],
        ];
        foreach ($refusals as [$code, $args, $says]) {
            $r = self::tool('grant_role', ...$args);
            self::assertSame($code, $r['code'], implode(' ', $args) . ': ' . $r['err']);
            self::assertStringContainsString($says, $r['err'], implode(' ', $args));
        }
        self::assertSame(self::OWNER, StaffRoles::of(self::$db, $owner['id']));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.roles'"));

        // --revoke takes one job away the same way.
        $off = self::tool('grant_role', '--email=' . $owner['email'], '--role=people_manager', '--revoke', '--apply');
        self::assertSame(0, $off['code'], $off['err']);
        self::assertSame($before, StaffRoles::of(self::$db, $owner['id']));
        self::assertSame(['people_manager'], json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'staff.roles' ORDER BY id DESC LIMIT 1"), true)['removed']);
        $none = self::tool('grant_role', '--email=' . $owner['email'], '--role=people_manager', '--revoke', '--apply');
        self::assertSame(0, $none['code'], $none['err']);
        self::assertStringContainsString('unchanged', $none['out']);
        $last = $this->uiUser('viewer');
        self::assertSame(1, self::tool('grant_role', '--email=' . $last['email'], '--role=viewer', '--revoke', '--apply')['code'], 'no jobs left: refused (switch the account off instead)');
        // A person with no job yet (set up while their only job waits for a reviewer's OK): taking one they lack is `unchanged`, not a refusal.
        self::$db->exec('UPDATE staff_role SET revoked_at = NOW(6) WHERE staff_user_id = ? AND revoked_at IS NULL', [$last['id']]);
        $lack = self::tool('grant_role', '--email=' . $last['email'], '--role=people_manager', '--revoke', '--apply');
        self::assertSame(0, $lack['code'], $lack['err']);
        self::assertStringContainsString('roles (none): unchanged', $lack['out']);
        $give = self::tool('grant_role', '--email=' . $last['email'], '--role=buyer');
        self::assertSame(0, $give['code'], $give['err']);
        self::assertStringContainsString('roles (none) -> buyer', $give['out']);
        self::assertSame([], StaffRoles::of(self::$db, $last['id']), 'a dry run');
    }

    /** PM5: someone must always be able to manage people. The tool never takes that from the last active person who can. */
    public function testTheLastPersonWhoCanManagePeopleKeepsIt(): void
    {
        $owner = $this->uiUser(['reviewer', 'people_manager']);
        $off = $this->uiUser('people_manager');
        (new StaffAdmin(self::$db))->setActive(Caller::system('pm_test'), $off['id'], false);
        $this->uiUser(['admin', 'auditor']);
        self::$db->exec("UPDATE staff_user SET is_active = 0 WHERE email LIKE 'k-admin-%'");
        self::assertSame([$owner['id']], StaffRoles::managers(self::$db), 'a switched-off account does not count');

        foreach ([[], ['--apply']] as $more) {
            $r = self::tool('grant_role', '--email=' . $owner['email'], '--role=people_manager', '--revoke', ...$more);
            self::assertSame(1, $r['code'], $r['err']);
            self::assertStringContainsString('nobody else can manage people', $r['err']);
        }
        self::assertSame(['people_manager', 'reviewer'], StaffRoles::of(self::$db, $owner['id']));

        // A second active person who can: then it may go.
        self::$db->exec("UPDATE staff_user SET is_active = 1 WHERE email LIKE 'k-admin-%'");
        self::assertCount(2, StaffRoles::managers(self::$db));
        $r = self::tool('grant_role', '--email=' . $owner['email'], '--role=people_manager', '--revoke', '--apply');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(['reviewer'], StaffRoles::of(self::$db, $owner['id']));
        // A switched-off person's job can always be taken (they manage nobody).
        self::assertSame(0, self::tool('grant_role', '--email=' . $off['email'], '--role=viewer', '--apply')['code']);
        self::assertSame(0, self::tool('grant_role', '--email=' . $off['email'], '--role=people_manager', '--revoke', '--apply')['code']);
    }

    /**
     * PM5 on the one screen path that can reach it: a reviewer's OK applies a change of jobs asked for earlier; when the person who asked
     * has lost the power since, the OK must not take it from the last manager. Refused 409 last_manager, nothing changed, the request
     * still open; and the server's tool never waits for the staff-grant rule.
     */
    public function testAReviewersOkNeverTakesThePowerFromTheLastManager(): void
    {
        $admin = $this->uiUser('admin');
        $a = $this->uiUser(['people_manager', 'buyer']);
        $b = $this->uiUser(['people_manager', 'buyer']);
        $rev = $this->uiUser('reviewer');
        $svc = new StaffAdmin(self::$appDb);
        (new Settings(self::$db))->change(Caller::staff($admin['id']), 'approvals.staff_grant', 'true', 'the owner wants a second person');
        $asked = $svc->setRoles(Caller::staff($a['id']), $b['id'], ['reviewer'], ['buyer', 'people_manager']);
        self::assertSame('requested', $asked['result']);
        self::assertSame('changed', $svc->setActive(Caller::staff($b['id']), $a['id'], false)['result'], 'B switches A off: B still manages people');
        self::assertSame('changed', $svc->setActive(Caller::system('pm_test'), $admin['id'], false)['result']);
        self::assertSame([$b['id']], StaffRoles::managers(self::$db));

        $e = self::refused(409, 'last_manager', fn () => (new RoleRequests(self::$appDb))->decide(Caller::staff($rev['id']), (int) $asked['request'], true, null));
        self::assertStringContainsString('nobody else can manage people', $e->getMessage());
        self::assertSame(['buyer', 'people_manager'], StaffRoles::of(self::$db, $b['id']));
        self::assertSame('open', self::$db->value('SELECT state FROM staff_role_request WHERE id = ?', [$asked['request']]));

        // A second person who can manage people: the OK goes through.
        $svc->setActive(Caller::system('pm_test'), $admin['id'], true);
        self::assertSame('approved', (new RoleRequests(self::$appDb))->decide(Caller::staff($rev['id']), (int) $asked['request'], true, null)['result']);
        self::assertSame(['reviewer'], StaffRoles::of(self::$db, $b['id']));

        // The server's tool is root: it never waits for the staff-grant rule.
        $c = $this->uiUser('buyer');
        $r = self::tool('grant_role', '--email=' . $c['email'], '--role=people_manager', '--apply');
        self::assertSame(0, $r['code'], $r['err']);
        self::assertSame(['buyer', 'people_manager'], StaffRoles::of(self::$db, $c['id']));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM staff_role_request WHERE staff_user_id = ?", [$c['id']]));
    }

    public function testCreateStaffTakesThePeopleManagerJob(): void
    {
        $env = $this->appEnv();
        $r = self::tool('create_staff', '--email=pm-cli@test.example', '--roles=people_manager,reviewer', '--name=Pm Cli', ['CW_APP_ENV' => $env]);
        self::assertSame(0, $r['code'], $r['err']);
        self::assertStringContainsString('roles people_manager,reviewer)', $r['err']);
        self::assertSame(['people_manager', 'reviewer'], StaffRoles::of(self::$db, (int) self::$db->value("SELECT id FROM staff_user WHERE email = 'pm-cli@test.example'")));
        $both = self::tool('create_staff', '--email=pm-admin@test.example', '--roles=admin,people_manager', ['CW_APP_ENV' => $env]);
        self::assertSame(1, $both['code']);
        self::assertStringContainsString('admin cannot be combined with people_manager', $both['err']);
    }

    /** A throwaway app.env (create_staff adds its own ui_secret_key there): never the server's /etc/cw/app.env. */
    private function appEnv(): string
    {
        $this->dir = sys_get_temp_dir() . '/cw-pm-' . bin2hex(random_bytes(4));
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
