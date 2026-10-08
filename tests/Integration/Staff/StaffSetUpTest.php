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
 * Staff set up and looked after on the screens (G06; docs/decisions.md Y20-Y25 as amended by Y40-Y44, the review finding B1), as the
 * app login. The rule every test here holds: an admin NEVER holds both sign-in factors of anybody, so whatever order they reset
 * things in, with or without a new account, they can never finish /ui/enrol or /ui/login as that person:
 *
 *  - a sign-up sheet (a new person: QR code + set-up code) works once, at /ui/enrol; the person's OWN page then shows a fresh secret
 *    the admin never sees, confirmed with one code before any session (Staff\Enrolment);
 *  - a "new sign-in code" (a lost phone) works once, only at /ui/login with the person's CURRENT password, then the same step;
 *  - a new password opens a set-up window with a set-up code only (the person's own phone does the rest);
 *  - the two resets refuse each other while the other is open; switching someone off closes their set-up (M3);
 *  - staff.setup_max_fails wrong tries close a set-up (I5); a reset of an Admin or a Reviewer waits for a reviewer while
 *    approvals.staff_reset is on (Y44).
 */
final class StaffSetUpTest extends KernelUiTestCase
{
    /** @var list<array<string, mixed>> */
    private array $saved = [];
    private int $ip = 0;

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

    /** A fresh address per attempt: the per-address throttle (30) never decides these tests. */
    private function ip(): string
    {
        return '198.51.' . intdiv(++$this->ip, 250) . '.' . ($this->ip % 250 + 1);
    }

    /**
     * The current code of $secret, as if the next 30 seconds had come (the replay guard totp_last_step is cleared first, as
     * signIn() does): a step of the test never fails because the one before used the same 30 seconds.
     */
    private static function next(string $secret, int $id): string
    {
        self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$id]);
        return Totp::code($secret);
    }

    /** Steps 1-3 for the person, as the screens do them: returns the fresh secret they now hold. */
    private function finishEnrol(string $email, string $setupCode, string $sheetSecret, int $id, string $password): string
    {
        $en = $this->enrolment();
        $start = $en->start($email, $setupCode, self::next($sheetSecret, $id), $this->ip(), 'Phone');
        self::assertSame('next', $start['status']);
        $p = $en->pending($start['token']);
        self::assertNotNull($p);
        self::assertSame('enrol', $p['route']);
        self::assertNotSame($sheetSecret, $p['secret'], 'a FRESH secret, not the sheet\'s');
        self::assertSame('ok', $en->confirm($start['token'], Totp::code($p['secret']), $password, $this->ip(), 'Phone', null)['status']);
        return $p['secret'];
    }

    public function testAPersonAddedOnTheScreenFinishesOnTheirOwnPageWithASecretOnlyTheySee(): void
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
        self::assertMatchesRegularExpression('/^[0-9A-Z]{5}-[0-9A-Z]{5}-[0-9A-Z]{5}$/', $made['setup_code']);
        $row = self::$db->one('SELECT display_name, password_must_change, totp_state, setup_code_hash, is_active, TIMESTAMPDIFF(HOUR, NOW(6), setup_until) AS h '
            . 'FROM staff_user WHERE id = ?', [$made['id']]);
        self::assertSame(['New Person', 0, 'signup', 1], [$row['display_name'], (int) $row['password_must_change'], $row['totp_state'], (int) $row['is_active']]);
        self::assertContains((int) $row['h'], [47, 48], 'staff.setup_hours: 48 by default');
        self::assertSame(hash('sha256', str_replace('-', '', $made['setup_code'])), $row['setup_code_hash'], 'only the hash of the set-up code is kept');
        $audit = (string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'staff.create' AND entity_id = ?", [(string) $made['id']]);
        self::assertStringNotContainsString($made['secret'], $audit, 'no secret in the audit');
        self::assertStringNotContainsString($made['setup_code'], $audit);
        self::refused(409, 'staff_exists', fn () => $svc->enrol(Caller::staff($admin['id']), 'new@test.example', ['buyer'], self::$box, 'Again'));

        // The sheet's secret never signs in at /ui/login, whatever password is typed (nobody knows the account's password).
        self::assertSame('invalid', $this->loginService()->attempt('new@test.example', 'anything-at-all-123', Totp::code($made['secret']), $this->ip(), null, null)['status']);

        // One answer for every failure: a wrong set-up code, a wrong 6-digit code, an unknown e-mail, an account not waiting.
        $en = $this->enrolment();
        self::assertSame('invalid', $en->start('new@test.example', 'AAAAA-AAAAA-AAAAA', Totp::code($made['secret']), $this->ip(), null)['status']);
        self::assertSame('invalid', $en->start('new@test.example', $made['setup_code'], '000000', $this->ip(), null)['status']);
        self::assertSame('invalid', $en->start('nobody@test.example', $made['setup_code'], Totp::code($made['secret']), $this->ip(), null)['status']);
        self::assertSame('invalid', $en->start($buyer['email'], $made['setup_code'], self::code($buyer['secret']), $this->ip(), null)['status'], 'not waiting to set up');
        self::assertSame(4, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.setup_fail'"));
        self::assertSame(2, (int) self::$db->value('SELECT setup_fails FROM staff_user WHERE id = ?', [$made['id']]), 'two wrong tries of this window (the set-up code, the code)');

        // Step 1: the sheet's secret and code die; no session yet; the fresh secret waits for this browser only.
        $start = $en->start('NEW@test.example', strtolower($made['setup_code']), Totp::code($made['secret']), '203.0.113.7', 'Phone');
        self::assertSame('next', $start['status']);
        self::assertSame([], StaffSessions::live(self::$db, $made['id']), 'no session before the fresh secret is confirmed');
        $row = self::$db->one('SELECT totp_secret_enc, setup_until, setup_code_hash, totp_state FROM staff_user WHERE id = ?', [$made['id']]);
        self::assertSame([null, null, null, 'signup'], [$row['totp_secret_enc'], $row['setup_until'], $row['setup_code_hash'], $row['totp_state']]);
        self::assertSame('invalid', $en->start('new@test.example', $made['setup_code'], Totp::code($made['secret'], intdiv(time(), 30) + 1), $this->ip(), null)['status'],
            'the sheet works once');
        self::assertNull($en->pending(null));
        self::assertNull($en->pending(str_repeat('x', 43)), 'another browser has no page');
        $p = $en->pending($start['token']);
        self::assertNotSame($made['secret'], $p['secret']);
        self::assertSame('new@test.example', $p['email']);
        // A wrong code of the fresh secret (the sheet's, say) does nothing; the right one with a password signs them in.
        self::assertSame('invalid', $en->confirm($start['token'], Totp::code($made['secret']), 'my-own-password-1', $this->ip(), null, null)['status']);
        $ok = $en->confirm($start['token'], Totp::code($p['secret']), 'my-own-password-1', '203.0.113.7', 'Phone', null);
        self::assertSame(['ok', false], [$ok['status'], $ok['must_change']]);
        self::assertSame('gone', $en->confirm($start['token'], Totp::code($p['secret'], intdiv(time(), 30) + 1), 'x-another-password', $this->ip(), null, null)['status']);
        $row = self::$db->one('SELECT totp_state, totp_next_token, setup_until FROM staff_user WHERE id = ?', [$made['id']]);
        self::assertSame(['own', null, null], [$row['totp_state'], $row['totp_next_token'], $row['setup_until']]);
        self::assertSame($p['secret'], self::$box->decrypt((string) self::$db->value('SELECT totp_secret_enc FROM staff_user WHERE id = ?', [$made['id']])));
        $setup = StaffAdmin::setupInfo(self::$db, $made['id']);
        self::assertSame(['own', '203.0.113.7', 'enrol'], [$setup['state'], $setup['finished']['ip'], $setup['finished']['how']], 'when and from where they finished');
        foreach (self::$db->column("SELECT detail FROM audit_log WHERE entity_id = ? AND action LIKE 'staff.%'", [(string) $made['id']]) as $d) {
            self::assertStringNotContainsString($p['secret'], (string) $d, 'the fresh secret is in no audit row');
        }
        // Their password and THEIR code sign them in; the sheet's code never again.
        $login = $this->loginService();
        self::assertSame('invalid', $login->attempt('new@test.example', 'my-own-password-1', Totp::code($made['secret'], intdiv(time(), 30) + 1), $this->ip(), null, null)['status']);
        self::assertSame('ok', $login->attempt('new@test.example', 'my-own-password-1', Totp::code($p['secret'], intdiv(time(), 30) + 1), $this->ip(), null, null)['status']);

        // A window that ran out refuses the right answer.
        $late = $svc->enrol(Caller::staff($admin['id']), 'late@test.example', ['buyer'], self::$box, 'Late Person');
        self::$db->exec('UPDATE staff_user SET setup_until = NOW(6) - INTERVAL 1 MINUTE WHERE id = ?', [$late['id']]);
        self::assertSame('invalid', $en->start('late@test.example', $late['setup_code'], Totp::code($late['secret']), $this->ip(), null)['status']);
        // A new sign-up sheet for someone who never finished: the old sheet stops, the new one works.
        self::refused(409, 'not_set_up', fn () => $svc->resetAuthenticator(Caller::staff($admin['id']), $late['id'], self::$box));
        self::refused(409, 'not_set_up', fn () => $svc->resetPassword(Caller::staff($admin['id']), $late['id']));
        self::refused(409, 'already_set_up', fn () => $svc->newSheet(Caller::staff($admin['id']), $made['id'], self::$box));
        $sheet = $svc->newSheet(Caller::staff($admin['id']), $late['id'], self::$box);
        self::assertSame('done', $sheet['result']);
        self::assertSame('invalid', $en->start('late@test.example', $late['setup_code'], Totp::code($late['secret']), $this->ip(), null)['status'], 'the old sheet stopped');
        $this->finishEnrol('late@test.example', (string) $sheet['setup_code'], (string) $sheet['secret'], $late['id'], 'late-but-done-123');
    }

    public function testALostPhoneAndAForgottenPasswordWorkForThePersonOnly(): void
    {
        $admin = $this->uiUser('admin');
        $p = $this->uiUser('buyer');
        $svc = new StaffAdmin(self::$appDb);
        $me = Caller::staff($admin['id']);
        $this->signIn($p);
        $login = $this->loginService();

        // A lost phone: a "new sign-in code". The old code stops at once; the sheet's code with the CURRENT password leads to the
        // person's own page (no session); its fresh secret, confirmed, is theirs.
        $r = $svc->resetAuthenticator($me, $p['id'], self::$box);
        self::assertSame(['done', 1], [$r['result'], $r['sessions_ended']]);
        self::refused(403, 'own_account', fn () => $svc->resetAuthenticator($me, $admin['id'], self::$box));
        self::assertSame('invalid', $login->attempt($p['email'], $p['password'], self::code($p['secret']), $this->ip(), null, null)['status']);
        self::assertSame('invalid', $login->attempt($p['email'], 'not-the-password-1', Totp::code((string) $r['secret']), $this->ip(), null, null)['status'],
            'the sheet alone is not enough');
        self::assertSame('invalid', $this->enrolment()->start($p['email'], 'AAAAA-AAAAA-AAAAA', Totp::code((string) $r['secret']), $this->ip(), null)['status'],
            'a new sign-in code never works at /ui/enrol');
        $step = $login->attempt($p['email'], $p['password'], self::next((string) $r['secret'], $p['id']), $this->ip(), null, null);
        self::assertSame('new_code', $step['status']);
        self::assertSame([], StaffSessions::live(self::$db, $p['id']), 'no session yet');
        self::assertNull(self::$db->value('SELECT totp_secret_enc FROM staff_user WHERE id = ?', [$p['id']]), 'the sheet\'s code died at its first use');
        $pend = $this->enrolment()->pending($step['token']);
        self::assertSame('login', $pend['route']);
        $ok = $this->enrolment()->confirm($step['token'], Totp::code($pend['secret']), null, $this->ip(), null, null);
        self::assertSame('ok', $ok['status']);
        self::assertSame('own', self::$db->value('SELECT totp_state FROM staff_user WHERE id = ?', [$p['id']]));
        self::assertSame('ok', $login->attempt($p['email'], $p['password'], Totp::code($pend['secret'], intdiv(time(), 30) + 1), $this->ip(), null, null)['status']);
        self::assertSame('new_code', StaffAdmin::setupInfo(self::$db, $p['id'])['finished']['how']);

        // A forgotten password: a set-up code only; the person uses it with THEIR phone, then rotates and chooses a password.
        $pw = $svc->resetPassword($me, $p['id']);
        self::assertSame('done', $pw['result']);
        self::assertSame(2, $pw['sessions_ended'], 'the session of the new code\'s step and the sign-in after it');
        self::assertSame('invalid', $login->attempt($p['email'], $p['password'], Totp::code($pend['secret'], intdiv(time(), 30) + 1), $this->ip(), null, null)['status'],
            'the old password stops at once');
        $fresh = $this->finishEnrol($p['email'], (string) $pw['setup_code'], $pend['secret'], $p['id'], 'a-brand-new-password');
        self::assertSame('ok', $login->attempt($p['email'], 'a-brand-new-password', self::next($fresh, $p['id']), $this->ip(), null, null)['status']);
        self::assertSame(['staff.reset', 'staff.reset'], array_map('strval', self::$db->column("SELECT action FROM audit_log WHERE action = 'staff.reset' AND entity_id = ?",
            [(string) $p['id']])));
        foreach (self::$db->column("SELECT detail FROM audit_log WHERE entity_id = ? AND action LIKE 'staff.%'", [(string) $p['id']]) as $d) {
            self::assertStringNotContainsString((string) $r['secret'], (string) $d);
            self::assertStringNotContainsString((string) $pw['setup_code'], (string) $d);
        }
        $placeholder = $this->uiUser('buyer');
        self::$db->exec("UPDATE staff_user SET email = 'gone@test.invalid' WHERE id = ?", [$placeholder['id']]);
        self::refused(409, 'placeholder_account', fn () => $svc->resetPassword($me, $placeholder['id']));
        self::refused(409, 'placeholder_account', fn () => $svc->resetAuthenticator($me, $placeholder['id'], self::$box));
    }

    /**
     * Review finding B1: whichever order the admin resets things in, with or without an account they added themselves, they never
     * hold both factors, so they never finish /ui/enrol or /ui/login as the person (here: as the owner's Reviewer account).
     */
    public function testTheAdminCanNeverSignInAsSomeoneElseWhateverTheyReset(): void
    {
        $admin = $this->uiUser('admin');
        $me = Caller::staff($admin['id']);
        $svc = new StaffAdmin(self::$appDb);
        $login = $this->loginService();
        $en = $this->enrolment();
        // What an admin can try with what they hold: every password they might guess, every sheet code they saw, at both doors.
        $tryAll = function (string $email, int $id, array $secrets, array $setupCodes) use ($login, $en): void {
            $sessions = (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id = ?', [$id]);
            foreach ($secrets as $s) {
                foreach (['a-guessed-password-1', ''] as $pw) {
                    $r = $login->attempt($email, $pw, self::next($s, $id), $this->ip(), null, null);
                    self::assertSame('invalid', $r['status'], 'never at /ui/login');
                }
                foreach ([...$setupCodes, 'AAAAA-AAAAA-AAAAA'] as $c) {
                    self::assertSame('invalid', $en->start($email, $c, self::next($s, $id), $this->ip(), null)['status'], 'never at /ui/enrol');
                }
                self::$db->exec('UPDATE login_attempt SET created_at = NOW(6) - INTERVAL 1 DAY WHERE staff_user_id = ?', [$id]); // the throttle never decides it
            }
            self::assertSame($sessions, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id = ?', [$id]), 'no session for the admin');
            self::assertNull(self::$db->value('SELECT totp_next_token FROM staff_user WHERE id = ?', [$id]), 'no fresh code waits for the admin either');
        };

        // Order A on the owner (an existing Reviewer): a new sign-in code first, then a new password.
        $owner = $this->uiUser(['reviewer', 'mapping_lead']);
        $code = $svc->resetAuthenticator($me, $owner['id'], self::$box);
        self::refused(409, 'code_reset_open', fn () => $svc->resetPassword($me, $owner['id']), 'the admin would hold the sign-in code and a set-up code');
        $tryAll($owner['email'], $owner['id'], [(string) $code['secret']], []);
        // The owner, with their password and that sheet, still gets in (and only they hold the fresh secret).
        $step = $login->attempt($owner['email'], $owner['password'], self::next((string) $code['secret'], $owner['id']), $this->ip(), null, null);
        self::assertSame('new_code', $step['status']);
        $mine = $en->pending($step['token']);
        self::assertSame('ok', $en->confirm($step['token'], Totp::code($mine['secret']), null, $this->ip(), null, null)['status']);
        $tryAll($owner['email'], $owner['id'], [(string) $code['secret']], []);

        // Order B on the owner: a new password first, then a new sign-in code.
        $pw = $svc->resetPassword($me, $owner['id']);
        self::refused(409, 'setup_open', fn () => $svc->resetAuthenticator($me, $owner['id'], self::$box), 'the admin would hold a set-up code and a sign-in code');
        $tryAll($owner['email'], $owner['id'], [(string) $code['secret']], [(string) $pw['setup_code']]);
        // ... and after that window ran out: the sign-in code is allowed again, but the admin knows no password.
        self::$db->exec('UPDATE staff_user SET setup_until = NOW(6) - INTERVAL 1 MINUTE WHERE id = ?', [$owner['id']]);
        $code2 = $svc->resetAuthenticator($me, $owner['id'], self::$box);
        self::assertSame([null, 'reset'], array_values((array) self::$db->one('SELECT setup_until, totp_state FROM staff_user WHERE id = ?', [$owner['id']])));
        self::refused(409, 'code_reset_open', fn () => $svc->resetPassword($me, $owner['id']));
        $tryAll($owner['email'], $owner['id'], [(string) $code2['secret'], (string) $code['secret']], [(string) $pw['setup_code']]);

        // An account the admin added on the screen: once the person finished, the QR code the admin saw is dead, and neither reset gives
        // the admin both factors (the one-step variant of the review: a new password alone used to be enough).
        $made = $svc->enrol($me, 'sam@test.example', ['buyer'], self::$box, 'Sam');
        $fresh = $this->finishEnrol('sam@test.example', $made['setup_code'], $made['secret'], $made['id'], 'sams-own-password');
        $tryAll('sam@test.example', $made['id'], [$made['secret']], [$made['setup_code']]);
        $pw = $svc->resetPassword($me, $made['id']);
        $tryAll('sam@test.example', $made['id'], [$made['secret']], [(string) $pw['setup_code'], $made['setup_code']]);
        self::refused(409, 'setup_open', fn () => $svc->resetAuthenticator($me, $made['id'], self::$box));
        // Sam finishes with their own phone; the admin's sheet code and secret still do nothing.
        $fresh = $this->finishEnrol('sam@test.example', (string) $pw['setup_code'], $fresh, $made['id'], 'sams-next-password');
        $code = $svc->resetAuthenticator($me, $made['id'], self::$box);
        $tryAll('sam@test.example', $made['id'], [(string) $code['secret'], $made['secret']], [(string) $pw['setup_code'], $made['setup_code']]);
        self::refused(409, 'code_reset_open', fn () => $svc->resetPassword($me, $made['id']));
        self::assertSame(3819, self::mysqlError(static fn () => self::$db->exec('UPDATE staff_user SET setup_until = NOW(6) + INTERVAL 1 HOUR WHERE id = ?', [$made['id']])),
            'and the database refuses a new sign-in code with a set-up window (ck_staff_user_one_factor)');
    }

    public function testWrongTriesCloseASetUpAndHomeSaysSo(): void
    {
        $admin = $this->uiUser('admin');
        $rev = $this->uiUser('reviewer');
        $svc = new StaffAdmin(self::$appDb);
        $en = $this->enrolment();
        self::assertSame(5, Enrolment::maxFails(self::$db), 'staff.setup_max_fails: 5 by default');
        (new Settings(self::$db))->change(Caller::staff($admin['id']), 'staff.setup_max_fails', '3', 'tighter for the test');
        $made = $svc->enrol(Caller::staff($admin['id']), 'guessed@test.example', ['buyer'], self::$box, 'Guessed');
        foreach (['AAAAA-AAAAA-AAAAA', 'BBBBB-BBBBB-BBBBB', 'CCCCC-CCCCC-CCCCC'] as $guess) {
            self::assertSame('invalid', $en->start('guessed@test.example', $guess, Totp::code($made['secret']), $this->ip(), null)['status']);
        }
        $row = self::$db->one('SELECT setup_until, setup_code_hash, setup_closed_at FROM staff_user WHERE id = ?', [$made['id']]);
        self::assertSame([null, null], [$row['setup_until'], $row['setup_code_hash']], 'the window closed');
        self::assertNotNull($row['setup_closed_at']);
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'staff.setup_closed' AND entity_id = ?", [(string) $made['id']]));
        self::assertSame('invalid', $en->start('guessed@test.example', $made['setup_code'], Totp::code($made['secret']), $this->ip(), null)['status'], 'even the right answer');
        // Home tells the admins and the reviewers.
        foreach ([$admin, $rev] as $u) {
            $home = $this->signIn($u)->get('/ui/');
            self::assertSame(200, $home->status);
            self::assertStringContainsString(\CW\Ui\Words::TASK['setup_closed']['title'], $home->text(), 'Home card for ' . implode(',', $u['roles']));
        }
        // A new sheet opens a new window and the card goes.
        $sheet = $svc->newSheet(Caller::staff($admin['id']), $made['id'], self::$box);
        self::assertNull(self::$db->value('SELECT setup_closed_at FROM staff_user WHERE id = ?', [$made['id']]));
        // The fresh secret's step has its own cap too: then it is gone.
        $start = $en->start('guessed@test.example', (string) $sheet['setup_code'], Totp::code((string) $sheet['secret']), $this->ip(), null);
        self::assertSame('next', $start['status']);
        self::assertSame('invalid', $en->confirm($start['token'], '000000', 'a-good-long-password', $this->ip(), null, null)['status']);
        self::assertSame('invalid', $en->confirm($start['token'], '000001', 'a-good-long-password', $this->ip(), null, null)['status']);
        self::assertSame('gone', $en->confirm($start['token'], '000002', 'a-good-long-password', $this->ip(), null, null)['status']);
        self::assertNull($en->pending($start['token']));
        self::assertNotNull(self::$db->value('SELECT setup_closed_at FROM staff_user WHERE id = ?', [$made['id']]));
    }

    /** M3: switching someone off closes their set-up window and any half-finished set-up (the screen and the server's tool). */
    public function testSwitchingSomeoneOffClosesTheirSetUp(): void
    {
        $admin = $this->uiUser('admin');
        $svc = new StaffAdmin(self::$appDb);
        $en = $this->enrolment();
        $made = $svc->enrol(Caller::staff($admin['id']), 'leaver@test.example', ['buyer'], self::$box, 'Leaver');
        $start = $en->start('leaver@test.example', $made['setup_code'], Totp::code($made['secret']), $this->ip(), null);
        self::assertSame('next', $start['status']);
        self::assertSame('changed', $svc->setActive(Caller::staff($admin['id']), $made['id'], false)['result']);
        self::assertSame([null, null], array_values((array) self::$db->one('SELECT setup_until, totp_next_token FROM staff_user WHERE id = ?', [$made['id']])));
        self::assertTrue(json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'staff.deactivate' AND entity_id = ?", [(string) $made['id']]), true)['setup_closed']);
        $svc->setActive(Caller::staff($admin['id']), $made['id'], true);
        self::assertNull($en->pending($start['token']), 'switched on again: the half-finished set-up stays gone');
        $sheet = $svc->newSheet(Caller::staff($admin['id']), $made['id'], self::$box);
        self::assertSame('next', $en->start('leaver@test.example', (string) $sheet['setup_code'], Totp::code((string) $sheet['secret']), $this->ip(), null)['status']);
        // The server's tool (bin/reset_staff.php --deactivate) does the same.
        $svc->reset(Caller::system('reset_staff'), 'leaver@test.example', null, false, false, false);
        self::assertSame([null, null], array_values((array) self::$db->one('SELECT setup_until, totp_next_token FROM staff_user WHERE id = ?', [$made['id']])));
        // And a new password from the server for someone who never finished needs a new secret too (the sheet's is not theirs).
        self::refused(400, 'needs_new_totp', fn () => $svc->reset(Caller::system('reset_staff'), 'leaver@test.example', self::$box, true, false, null));
        $r = $svc->reset(Caller::system('reset_staff'), 'leaver@test.example', self::$box, true, true, true);
        self::assertSame('own', self::$db->value('SELECT totp_state FROM staff_user WHERE id = ?', [$made['id']]));
        self::assertNotNull($r['password']);
    }

    /** Y44: while approvals.staff_reset is on, a reset of an Admin's or a Reviewer's sign-in waits for another reviewer's OK, used once. */
    public function testResetsOfAdminsAndReviewersWaitForAReviewerWhileTheRuleIsOn(): void
    {
        $admin = $this->uiUser('admin');
        $admin2 = $this->uiUser('admin');
        $owner = $this->uiUser('reviewer');
        $rev = $this->uiUser('reviewer');
        $buyer = $this->uiUser('buyer');
        $svc = new StaffAdmin(self::$appDb);
        $requests = new RoleRequests(self::$appDb);
        $me = Caller::staff($admin['id']);
        // Off by default: the admin resets at once.
        self::assertSame('done', $svc->resetPassword($me, $admin2['id'])['result']);
        (new Settings(self::$db))->change($me, 'approvals.staff_reset', 'true', 'the owner wants a second person'); // stricter: the admin may
        $r = $svc->resetAuthenticator($me, $owner['id'], self::$box);
        self::assertSame(['requested', null], [$r['result'], $r['secret']]);
        self::assertSame('own', self::$db->value('SELECT totp_state FROM staff_user WHERE id = ?', [$owner['id']]), 'nothing changed yet');
        self::assertSame('ok', $this->loginService()->attempt($owner['email'], $owner['password'], self::code($owner['secret']), $this->ip(), null, null)['status'],
            'the owner still signs in');
        self::refused(409, 'request_open', fn () => $svc->resetPassword($me, $owner['id']));
        self::assertSame('done', $svc->resetPassword($me, $buyer['id'])['result'], 'a buyer\'s reset never waits');
        self::refused(403, 'own_account', fn () => $requests->decide(Caller::staff($owner['id']), (int) $r['request'], true, null));
        self::assertSame('reset_approved', $requests->decide(Caller::staff($rev['id']), (int) $r['request'], true, null)['result']);
        self::assertSame('reset_code', RoleRequests::approvedReset(self::$db, $owner['id'])['kind']);
        self::assertSame('requested', $svc->resetPassword($me, $owner['id'])['result'], 'the OK was for a new sign-in code only');
        $requests->withdraw($me, (int) self::$db->value("SELECT id FROM staff_role_request WHERE state = 'open'"));
        $done = $svc->resetAuthenticator(Caller::staff($admin2['id']), $owner['id'], self::$box);
        self::assertSame('done', $done['result'], 'any admin carries the OK out');
        self::assertNotNull(self::$db->value("SELECT used_at FROM staff_role_request WHERE id = ?", [$r['request']]));
        self::assertNull(RoleRequests::approvedReset(self::$db, $owner['id']));
        self::assertSame('reset', self::$db->value('SELECT totp_state FROM staff_user WHERE id = ?', [$owner['id']]));
        // The OK worked once: the next reset waits again; a Not OK changes nothing.
        $again = $svc->resetAuthenticator(Caller::staff($admin2['id']), $owner['id'], self::$box);
        self::assertSame('requested', $again['result']);
        self::assertSame('reset_rejected', $requests->decide(Caller::staff($rev['id']), (int) $again['request'], false, 'not again so soon')['result']);
        self::assertNull(RoleRequests::approvedReset(self::$db, $owner['id']));
        // The server's tool never waits.
        self::assertNotNull($svc->reset(Caller::system('reset_staff'), $owner['email'], self::$box, false, true, null)['otpauth']);
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
        $pending = $requests->pending($rev['id']);
        self::assertSame(['deputy@test.example', 'roles'], [$pending[0]['email'], $pending[0]['kind']]);
        self::assertNotNull($pending[0]['person_created_at'], 'the reviewer sees when the account was made');
        $requests->decide(Caller::staff($rev['id']), (int) $made['request'], true, null);
        self::assertSame(['buyer', 'reviewer'], StaffRoles::of(self::$db, $made['id']));
        // The CLI stays the break-glass: it never waits.
        self::assertSame('changed', $svc->setRoles(Caller::system('reset_staff'), $p['id'], ['admin', 'accountant'], null)['result']);
        self::assertSame(['accountant', 'admin'], StaffRoles::of(self::$db, $p['id']));
    }

    public function testSigningOutIsTheAdminsAndNeverTheirOwnAndCountsOnlyLiveSessions(): void
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
        // A session idle for longer than the idle limit is closed too, but not counted as signed out (review nit).
        self::$db->exec("INSERT INTO staff_session (id, staff_user_id, mfa_at, last_seen_at) VALUES (REPEAT('c', 64), ?, NOW(6), NOW(6) - INTERVAL 2 HOUR)", [$p['id']]);
        self::assertSame(1, $svc->signOut($me, $p['id'], null));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id = ? AND revoked = 0', [$p['id']]));
        self::assertSame([], StaffSessions::live(self::$db, $p['id']));
        self::assertSame('/ui/login?why=signed_out', $a->get('/ui/')->location());
        self::assertSame('/ui/login?why=signed_out', $b->get('/ui/')->location());
    }

    private static function secretOf(string $otpauth): string
    {
        parse_str((string) parse_url($otpauth, PHP_URL_QUERY), $q);
        return (string) $q['secret'];
    }
}
