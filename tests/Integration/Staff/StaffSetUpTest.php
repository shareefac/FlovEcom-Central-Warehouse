<?php

declare(strict_types=1);

namespace CW\Tests\Integration\Staff;

use CW\Auth\LoginLimiter;
use CW\Caller;
use CW\Settings;
use CW\Staff\Enrolment;
use CW\Staff\RoleRequests;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffRoles;
use CW\Staff\StaffSessions;
use CW\Staff\Totp;
use CW\Tests\Support\KernelUiTestCase;

/**
 * Staff set up and looked after on the screens (G06; docs/decisions.md Y20-Y25), as the app login: a person added by an admin gets a
 * sign-in secret returned once and no password anybody knows; they set their own password at /ui/enrol with their phone's code
 * within staff.setup_hours (Staff\Enrolment: one answer for every failure, the sign-in's throttle, a code used once); a new sign-in
 * code and a new password chosen by the person; signing out one device or all; and, while the owner has that rule on, Admin or
 * Reviewer waiting for a reviewer's OK (RoleRequests).
 */
final class StaffSetUpTest extends KernelUiTestCase
{
    /** @var list<array<string, mixed>> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = self::$db->all('SELECT setting_key, CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting');
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $r) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], (int) $r['provisional'], $r['updated_actor'], $r['setting_key']]);
        }
        parent::tearDown();
    }

    private function enrolment(): Enrolment
    {
        return new Enrolment(self::$appDb, new LoginLimiter(self::$appDb), self::$box);
    }

    private static function secretOf(string $otpauth): string
    {
        parse_str((string) parse_url($otpauth, PHP_URL_QUERY), $q);
        return (string) $q['secret'];
    }

    public function testAPersonAddedOnTheScreenSetsTheirOwnPasswordWithTheirPhonesCode(): void
    {
        $admin = $this->uiUser('admin');
        $buyer = $this->uiUser('buyer');
        $svc = new StaffAdmin(self::$appDb);
        self::refused(403, 'role_not_allowed', fn () => $svc->enrol(Caller::staff($buyer['id']), 'new@test.example', ['buyer'], self::$box, 'New Person'));
        self::refused(400, 'bad_email', fn () => $svc->enrol(Caller::staff($admin['id']), 'not an address', ['buyer'], self::$box, 'New Person'));
        self::refused(400, 'bad_name', fn () => $svc->enrol(Caller::staff($admin['id']), 'new@test.example', ['buyer'], self::$box, 'N'));
        self::refused(422, 'role_conflict', fn () => $svc->enrol(Caller::staff($admin['id']), 'new@test.example', ['admin', 'buyer'], self::$box, 'New Person'));
        self::refused(409, 'placeholder_account', fn () => $svc->enrol(Caller::staff($admin['id']), 'x@test.invalid', ['buyer'], self::$box, 'Test'));

        $made = $svc->enrol(Caller::staff($admin['id']), ' New@Test.Example ', ['buyer', 'goods_in'], self::$box, 'New  Person');
        self::assertSame(['new@test.example', ['buyer', 'goods_in'], null], [$made['email'], $made['roles'], $made['request']]);
        self::assertSame($made['secret'], self::secretOf($made['otpauth']));
        $row = self::$db->one('SELECT display_name, password_must_change, setup_until, totp_secret_enc, is_active, TIMESTAMPDIFF(HOUR, NOW(6), setup_until) AS h '
            . 'FROM staff_user WHERE id = ?', [$made['id']]);
        self::assertSame(['New Person', 0, 1], [$row['display_name'], (int) $row['password_must_change'], (int) $row['is_active']]);
        self::assertContains((int) $row['h'], [47, 48], 'staff.setup_hours: 48 by default');
        self::assertSame($made['secret'], self::$box->decrypt((string) $row['totp_secret_enc']));
        $audit = (string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'staff.create' AND entity_id = ?", [(string) $made['id']]);
        self::assertStringNotContainsString($made['secret'], $audit, 'no secret in the audit');
        self::assertSame(['screen', ['buyer', 'goods_in']], [json_decode($audit, true)['via'], json_decode($audit, true)['roles']]);
        self::refused(409, 'staff_exists', fn () => $svc->enrol(Caller::staff($admin['id']), 'new@test.example', ['buyer'], self::$box, 'Again'));

        // No password works before the person sets one: the sign-in refuses whatever is typed.
        self::assertSame('invalid', $this->loginService()->attempt('new@test.example', 'anything-at-all-123', Totp::code($made['secret']), '198.51.100.61', null, null)['status']);
        self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$made['id']]);

        // One answer for every failure: wrong code, unknown e-mail, the window over.
        $en = $this->enrolment();
        self::assertSame('invalid', $en->complete('new@test.example', '000000', 'my-own-password-1', '198.51.100.62', null, null)['status']);
        self::assertSame('invalid', $en->complete('nobody@test.example', Totp::code($made['secret']), 'my-own-password-1', '198.51.100.62', null, null)['status']);
        self::assertSame('invalid', $en->complete($buyer['email'], self::code($buyer['secret']), 'my-own-password-1', '198.51.100.62', null, null)['status'],
            'an account not waiting to set up');
        self::assertSame(3, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.setup_fail'"));
        $ok = $en->complete('NEW@test.example', Totp::code($made['secret']), 'my-own-password-1', '198.51.100.62', 'Phone', null);
        self::assertSame('ok', $ok['status']);
        self::assertNotNull($ok['token']);
        $row = self::$db->one('SELECT setup_until, totp_last_step, last_login_at FROM staff_user WHERE id = ?', [$made['id']]);
        self::assertNull($row['setup_until']);
        self::assertNotNull($row['totp_last_step']);
        self::assertSame('invalid', $en->complete('new@test.example', Totp::code($made['secret'], intdiv(time(), 30) + 1), 'another-password-2', '198.51.100.62', null, null)['status'],
            'done once: the window is closed');
        // The password they chose signs them in, with the next code.
        self::assertSame('ok', $this->loginService()->attempt('new@test.example', 'my-own-password-1', Totp::code($made['secret'], intdiv(time(), 30) + 1), '198.51.100.63', null, null)['status']);
        self::assertCount(2, StaffSessions::live(self::$db, $made['id']));

        // A window that ran out (staff.setup_hours) refuses the right code.
        $late = $svc->enrol(Caller::staff($admin['id']), 'late@test.example', ['buyer'], self::$box, 'Late Person');
        self::$db->exec('UPDATE staff_user SET setup_until = NOW(6) - INTERVAL 1 MINUTE WHERE id = ?', [$late['id']]);
        self::assertSame('invalid', $en->complete('late@test.example', Totp::code($late['secret']), 'my-own-password-1', '198.51.100.64', null, null)['status']);
    }

    public function testANewCodeANewPasswordAndSigningOutAreTheAdminsAndNeverTheirOwn(): void
    {
        $admin = $this->uiUser('admin');
        $p = $this->uiUser('buyer');
        $svc = new StaffAdmin(self::$appDb);
        $me = Caller::staff($admin['id']);
        $a = $this->signIn($p);
        $b = $this->signIn($p, $this->browser('198.51.100.70'));
        $live = StaffSessions::live(self::$db, $p['id']);
        self::assertCount(2, $live);
        self::assertTrue(StaffSessions::isHandle($live[0]['handle']));
        self::refused(403, 'own_account', fn () => $svc->signOut($me, $admin['id'], null));
        self::refused(400, 'bad_session', fn () => $svc->signOut($me, $p['id'], 'nope'));
        self::assertSame(1, $svc->signOut($me, $p['id'], $live[0]['handle']));
        self::assertCount(1, StaffSessions::live(self::$db, $p['id']));
        self::assertSame(0, $svc->signOut($me, $p['id'], $live[0]['handle']), 'ended already');
        self::assertSame(1, $svc->signOut($me, $p['id'], null));
        self::assertSame([], StaffSessions::live(self::$db, $p['id']));
        self::assertSame('/ui/login?why=signed_out', $a->get('/ui/')->location());
        self::assertSame('/ui/login?why=signed_out', $b->get('/ui/')->location());

        // A new sign-in code: the old code stops, the password stays.
        $r = $svc->resetAuthenticator($me, $p['id'], self::$box);
        self::assertNotSame($p['secret'], $r['secret']);
        self::refused(403, 'own_account', fn () => $svc->resetAuthenticator($me, $admin['id'], self::$box));
        $login = $this->loginService();
        self::assertSame('invalid', $login->attempt($p['email'], $p['password'], self::code($p['secret']), '198.51.100.71', null, null)['status']);
        self::assertSame('ok', $login->attempt($p['email'], $p['password'], Totp::code($r['secret']), '198.51.100.71', null, null)['status']);

        // A new password chosen by the person: the old one stops at once; they set the new one with their code.
        $pw = $svc->resetPassword($me, $p['id']);
        self::assertSame(1, $pw['sessions_ended']);
        self::assertSame('invalid', $login->attempt($p['email'], $p['password'], Totp::code($r['secret'], intdiv(time(), 30) + 1), '198.51.100.72', null, null)['status']);
        self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$p['id']]);
        self::assertSame('ok', $this->enrolment()->complete($p['email'], Totp::code($r['secret']), 'a-brand-new-password', '198.51.100.73', null, null)['status']);
        self::assertSame(['staff.reset', 'staff.reset'], array_map('strval', self::$db->column("SELECT action FROM audit_log WHERE action = 'staff.reset' AND entity_id = ?",
            [(string) $p['id']])));
        foreach (self::$db->column("SELECT detail FROM audit_log WHERE entity_id = ? AND action LIKE 'staff.%'", [(string) $p['id']]) as $d) {
            self::assertStringNotContainsString($r['secret'], (string) $d);
        }
        $placeholder = $this->uiUser('buyer');
        self::$db->exec("UPDATE staff_user SET email = 'gone@test.invalid' WHERE id = ?", [$placeholder['id']]);
        self::refused(409, 'placeholder_account', fn () => $svc->resetPassword($me, $placeholder['id']));
        self::refused(409, 'placeholder_account', fn () => $svc->resetAuthenticator($me, $placeholder['id'], self::$box));
    }

    public function testGivingAdminOrReviewerWaitsForAReviewerWhileTheRuleIsOn(): void
    {
        $admin = $this->uiUser('admin');
        $admin2 = $this->uiUser('admin');
        $rev = $this->uiUser('reviewer');
        $p = $this->uiUser('buyer');
        $svc = new StaffAdmin(self::$appDb);
        $requests = new RoleRequests(self::$appDb);
        // Off by default: the change works at once.
        self::assertSame('changed', $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['buyer', 'reviewer'], ['buyer'])['result']);
        $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['buyer'], ['buyer', 'reviewer']);
        (new Settings(self::$db))->change(Caller::staff($admin['id']), 'approvals.staff_grant', 'true', 'the owner wants a second person');

        $r = $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['buyer', 'reviewer'], ['buyer']);
        self::assertSame('requested', $r['result']);
        self::assertSame(['buyer'], StaffRoles::of(self::$db, $p['id']), 'nothing applied yet');
        self::refused(409, 'request_open', fn () => $svc->setRoles(Caller::staff($admin2['id']), $p['id'], ['buyer', 'reviewer', 'stock_controller'], ['buyer']));
        self::assertSame('changed', $svc->setRoles(Caller::staff($admin['id']), $rev['id'], ['reviewer', 'stock_controller'], ['reviewer'])['result'],
            'other jobs are not held up');
        self::assertSame(1, $requests->decidableCount($rev['id'], ['reviewer', 'stock_controller']));
        self::assertSame(0, $requests->decidableCount($admin['id'], ['admin']), 'admin never gives the OK');
        self::refused(403, 'role_not_allowed', fn () => $requests->decide(Caller::staff($admin2['id']), (int) $r['request'], true, null));
        self::refused(403, 'role_not_allowed', fn () => $requests->decide(Caller::staff($p['id']), (int) $r['request'], true, null));
        self::refused(400, 'note_required', fn () => $requests->decide(Caller::staff($rev['id']), (int) $r['request'], false, 'no'));
        $d = $requests->decide(Caller::staff($rev['id']), (int) $r['request'], true, null);
        self::assertSame('approved', $d['result']);
        self::assertSame(['buyer', 'reviewer'], StaffRoles::of(self::$db, $p['id']));
        $grant = self::$db->one("SELECT granted_by FROM staff_role WHERE staff_user_id = ? AND role = 'reviewer' AND revoked_at IS NULL", [$p['id']]);
        self::assertSame($admin['id'], (int) $grant['granted_by'], 'given by the admin who asked');
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'staff.roles' AND entity_id = ? ORDER BY id DESC LIMIT 1",
            [(string) $p['id']]), true);
        self::assertSame([$r['request'], $admin['id'], $rev['id']], [$audit['request'], $audit['requested_by'], $audit['approved_by']]);
        self::refused(409, 'request_closed', fn () => $requests->decide(Caller::staff($rev['id']), (int) $r['request'], true, null));

        // Not OK keeps the jobs; a request whose person's jobs changed meanwhile is withdrawn, not applied; an admin withdraws one.
        $r2 = $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['admin'], ['buyer', 'reviewer']);
        self::assertSame('requested', $r2['result']);
        self::assertSame('rejected', $requests->decide(Caller::staff($rev['id']), (int) $r2['request'], false, 'not now')['result']);
        self::assertSame(['buyer', 'reviewer'], StaffRoles::of(self::$db, $p['id']));
        $r3 = $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['admin', 'accountant'], ['buyer', 'reviewer']);
        self::assertSame('requested', $r3['result']);
        self::$db->exec("UPDATE staff_role SET revoked_at = NOW(6), revoked_by = ? WHERE staff_user_id = ? AND role = 'buyer' AND revoked_at IS NULL", [$admin2['id'], $p['id']]);
        self::assertSame('stale', $requests->decide(Caller::staff($rev['id']), (int) $r3['request'], true, null)['result']);
        self::assertSame(['reviewer'], StaffRoles::of(self::$db, $p['id']));
        $r4 = $svc->setRoles(Caller::staff($admin['id']), $p['id'], ['admin'], ['reviewer']);
        $requests->withdraw(Caller::staff($admin2['id']), (int) $r4['request']);
        self::assertSame([], $requests->pending());
        self::assertSame(['approved', 'rejected', 'withdrawn', 'withdrawn'], array_map('strval', self::$db->column('SELECT state FROM staff_role_request ORDER BY id')));

        // A person added with Admin or Reviewer while the rule is on: the other jobs now, the rest after the OK.
        $made = $svc->enrol(Caller::staff($admin['id']), 'deputy@test.example', ['reviewer', 'buyer'], self::$box, 'Deputy');
        self::assertSame(['buyer'], $made['roles']);
        self::assertNotNull($made['request']);
        $requests->decide(Caller::staff($rev['id']), (int) $made['request'], true, null);
        self::assertSame(['buyer', 'reviewer'], StaffRoles::of(self::$db, $made['id']));
        // The CLI stays the break-glass: it never waits.
        self::assertSame('changed', $svc->setRoles(Caller::system('reset_staff'), $p['id'], ['admin', 'accountant'], null)['result']);
        self::assertSame(['accountant', 'admin'], StaffRoles::of(self::$db, $p['id']));
    }
}
