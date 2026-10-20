<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Auth\LoginLimiter;
use CW\Auth\SecondStep;
use CW\Caller;
use CW\Clock;
use CW\CwException;
use CW\Db;
use CW\Settings;
use CW\Auth\Sessions;
use CW\Staff\Enrolment;
use CW\Staff\StaffAdmin;
use CW\Staff\Totp;
use CW\Tests\Support\HookedStatement;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\TestDb;
use CW\Ui\Html;
use CW\Ui\Words;

/**
 * 2-step verification switched off for a while (the owner's decision of 20 Oct 2026, against advice: "turn 2-step verification off
 * temporarily, I will tell you when to turn it back on"; docs/decisions.md TF1-TF6). The setting auth.totp_off_until (0048) holds a UK
 * time: while it is ahead, the sign-in asks for the e-mail and the password only, and records that it did; at that time the code is asked
 * for again by itself. Empty, past, unreadable or more than 90 days ahead: the code is asked for, exactly as before. Everything else stays:
 * the throttle and lock-out, the one-time password, sign-up accounts, the sessions and their limits. Admins and reviewers see a warning
 * on the Dashboard and on Settings › System while it is on.
 */
final class TotpOffTest extends KernelUiTestCase
{
    private const KEY = SecondStep::SETTING;
    /** The start of every read of the switch (SecondStep::offUntil; the one under the locks adds FOR SHARE). */
    private const SWITCH_READ = "SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'string'";

    /** @var array{v: string, provisional: int, actor: string}|null the row as it was */
    private ?array $saved = null;

    protected function setUp(): void
    {
        parent::setUp();
        $r = self::$db->one('SELECT CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting WHERE setting_key = ?', [self::KEY]);
        self::assertNotNull($r, '0048 adds the setting');
        $this->saved = ['v' => (string) $r['v'], 'provisional' => (int) $r['provisional'], 'actor' => (string) $r['updated_actor']];
    }

    protected function tearDown(): void
    {
        if ($this->saved !== null) {
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$this->saved['v'], $this->saved['provisional'], $this->saved['actor'], self::KEY]);
        }
        HookedStatement::$before = [];
        parent::tearDown();
    }

    /** The sign-in service on an app-login connection of its own whose statements a test can step in before (HookedStatement). */
    private function hookedLogin(): \CW\Auth\Login
    {
        $db = Db::connect(TestDb::config()->dbApp()->withDatabase(TestDb::name()));
        $db->pdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [HookedStatement::class, []]);
        return $this->loginService($db);
    }

    /** Live sessions of a person. */
    private static function live(int $id): int
    {
        return (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id = ? AND revoked = 0', [$id]);
    }

    /** A UK time ("Y-m-d H:i", as typed on the Settings page) at $modify from now. */
    private static function uk(string $modify): string
    {
        return Clock::now()->modify($modify)->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d H:i');
    }

    /** Stores the setting's text as only the admin login could (no rule checked). */
    private function store(string $text): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [json_encode($text, JSON_THROW_ON_ERROR), self::KEY]);
    }

    /** What the sign-in page and the warnings say the time is: the stored UK time as the screens print a moment. */
    private static function shown(string $uk): string
    {
        $at = SecondStep::moment($uk);
        self::assertNotNull($at, $uk);
        return Html::when(Clock::db($at));
    }

    /** @return array{0: \CW\Tests\Support\KernelBrowser, 1: \CW\Tests\Support\UiResponse} the page and its browser */
    private function loginPage(): array
    {
        $web = $this->browser();
        $page = $web->get('/ui/login');
        self::assertSame(200, $page->status, $page->describe());
        return [$web, $page];
    }

    public function testOffByDefaultTheCodeIsAskedForAsBefore(): void
    {
        self::assertSame('""', $this->saved['v'] ?? null, 'the baseline of 0048: empty');
        self::assertNull(SecondStep::offUntil(self::$appDb));
        $u = $this->uiUser('mapper');
        [$web, $page] = $this->loginPage();
        self::assertSame(['csrf', 'email', 'password', 'code'], array_keys($page->form('/ui/login')), 'the code field as always');
        self::assertStringNotContainsString(Words::say('SIGN_IN', 'code_off', 'x'), $page->text());
        $r = $web->post('/ui/login', ['csrf' => $page->form('/ui/login')['csrf'], 'email' => $u['email'], 'password' => $u['password'], 'code' => '']);
        self::assertSame(401, $r->status, 'the password alone does not sign in');
        self::assertStringContainsString(Words::SIGN_IN['failed'], $r->text());
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id = ?', [$u['id']]));
        self::assertSame('totp', (string) self::$db->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(detail, '$.step')) FROM audit_log WHERE action = 'login.fail' AND staff_user_id = ?", [$u['id']]));
        $this->signIn($u); // with the code: as before
    }

    public function testAPastOrUnreadableTimeAsksForTheCode(): void
    {
        $u = $this->uiUser('mapper');
        foreach ([self::uk('-1 minute'), self::uk('-30 days'), 'tomorrow', '2026-02-30 10:00', self::uk('+91 days'), '2026-10-25 01:30', '27/10/2026 18:00'] as $text) {
            $this->store($text);
            self::assertNull(SecondStep::offUntil(self::$appDb), "{$text}: the code is asked for (fail closed)");
            [$web, $page] = $this->loginPage();
            self::assertArrayHasKey('code', $page->form('/ui/login'), $text);
            self::assertSame('invalid', $this->loginService()->attempt($u['email'], $u['password'], '', '198.51.100.' . random_int(1, 250), null, null)['status'], $text);
        }
        self::$db->exec("UPDATE app_setting SET value_type = 'int', value_json = CAST('5' AS JSON) WHERE setting_key = ?", [self::KEY]);
        self::assertNull(SecondStep::offUntil(self::$appDb), 'not a text setting: the code is asked for');
        self::$db->exec("UPDATE app_setting SET value_type = 'string' WHERE setting_key = ?", [self::KEY]);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id = ?', [$u['id']]));
    }

    public function testWhileOnTheSignInNeedsThePasswordOnlyAndSaysSo(): void
    {
        $until = self::uk('+2 hours');
        $this->store($until);
        $at = SecondStep::offUntil(self::$appDb);
        self::assertNotNull($at);
        self::assertSame($until, $at->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d H:i'));

        $u = $this->uiUser('buyer');
        [$web, $page] = $this->loginPage();
        self::assertSame(['csrf', 'email', 'password'], array_keys($page->form('/ui/login')), 'no code field');
        self::assertStringContainsString(Words::say('SIGN_IN', 'code_off', self::shown($until)), $page->text(), 'one plain line, with the time it ends');

        // A wrong password still fails, is counted and says so without asking for a code.
        $bad = $web->post('/ui/login', ['csrf' => $page->form('/ui/login')['csrf'], 'email' => $u['email'], 'password' => 'not the password at all']);
        self::assertSame(401, $bad->status);
        self::assertStringContainsString(Words::SIGN_IN['failed_no_code'], $bad->text());
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM login_attempt WHERE login = ? AND success = 0 AND step = 'password'", [$u['email']]));

        // The right password: a session (second step marked done, as Sessions requires), live, audited as signed in without the code.
        $ok = $web->post('/ui/login', ['csrf' => $page->form('/ui/login')['csrf'], 'email' => $u['email'], 'password' => $u['password']]);
        self::assertSame([303, '/ui/'], [$ok->status, $ok->location()], $ok->describe());
        $row = self::$db->one('SELECT mfa_at, revoked FROM staff_session WHERE id = ?', [hash('sha256', $web->cookies['cw_session'])]);
        self::assertNotNull($row['mfa_at'] ?? null);
        self::assertSame(0, (int) $row['revoked']);
        self::assertSame(200, $web->get('/ui/')->status, 'the session is live');
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'login.ok' AND staff_user_id = ?", [$u['id']]), true);
        self::assertTrue($audit['without_code'] ?? null);
        self::assertSame($until, $audit['code_off_until'] ?? null);
        self::assertStringContainsString('signed in without the 2-step code: switched off until ' . $until, (string) ($audit['note'] ?? ''));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM login_attempt WHERE login = ? AND success = 1 AND step = 'password'", [$u['email']]));
        self::assertNotNull(self::$db->value('SELECT last_login_at FROM staff_user WHERE id = ?', [$u['id']]));

        // A code sent anyway is ignored; signing out ends the session as always.
        $out = $web->post('/ui/logout', ['csrf' => $this->token($web)]);
        self::assertSame([303, '/ui/login'], [$out->status, $out->location()]);
        self::assertStringContainsString('Max-Age=0', (string) $out->setCookie('cw_session'));
        [$web, $page] = $this->loginPage();
        $again = $web->post('/ui/login', ['csrf' => $page->form('/ui/login')['csrf'], 'email' => $u['email'], 'password' => $u['password'], 'code' => '000000']);
        self::assertSame(303, $again->status, $again->describe());
    }

    public function testEverythingElseStaysWhileItIsOn(): void
    {
        $this->store(self::uk('+1 day'));
        $login = $this->loginService();
        $ip = '198.51.100.77';

        // The throttle: ten wrong passwords lock the account, the right one is then refused too.
        $u = $this->uiUser('mapper');
        for ($i = 1; $i <= LoginLimiter::ACCOUNT_MAX; $i++) {
            self::assertSame('invalid', $login->attempt($u['email'], 'wrong password ' . $i, '', $ip, null, null)['status'], "failure {$i}");
        }
        self::assertSame('locked', $login->attempt($u['email'], $u['password'], '', $ip, null, null)['status']);
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id = ?', [$u['id']]));

        // A one-time password still leads to choosing a new one first.
        $first = $this->uiUser('viewer', true);
        [$web, $page] = $this->loginPage();
        $r = $web->post('/ui/login', ['csrf' => $page->form('/ui/login')['csrf'], 'email' => $first['email'], 'password' => $first['password']]);
        self::assertSame([303, '/ui/password'], [$r->status, $r->location()]);
        self::assertSame('/ui/password', $web->get('/ui/')->location());

        // An account switched off, or one whose sign-up sheet was never used (whatever its password), never signs in.
        $off = $this->uiUser('mapper');
        self::$db->exec('UPDATE staff_user SET is_active = 0 WHERE id = ?', [$off['id']]);
        self::assertSame('invalid', $login->attempt($off['email'], $off['password'], '', '198.51.100.78', null, null)['status']);
        $admin = $this->uiUser('admin');
        $sheet = (new StaffAdmin(self::$appDb))->enrol(Caller::staff($admin['id']), 'sheet@test.example', ['buyer'], self::$box, 'Sheet Person');
        self::$db->exec('UPDATE staff_user SET password_hash = ? WHERE id = ?', [password_hash('a known password 2026', PASSWORD_ARGON2ID), $sheet['id']]);
        self::assertSame('invalid', $login->attempt('sheet@test.example', 'a known password 2026', '', '198.51.100.79', null, null)['status'], 'a sign-up account sets itself up first');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id IN (?, ?)', [$off['id'], $sheet['id']]));

        // A "new sign-in code" waiting (its set-up stays offered) does not block the sign-in; the code waits for when 2-step is back.
        $lost = $this->uiUser('buyer');
        (new StaffAdmin(self::$appDb))->resetAuthenticator(Caller::staff($admin['id']), $lost['id'], self::$box);
        self::assertSame('reset', (string) self::$db->value('SELECT totp_state FROM staff_user WHERE id = ?', [$lost['id']]));
        self::assertSame('ok', $login->attempt($lost['email'], $lost['password'], '', '198.51.100.80', null, null)['status']);
        self::assertSame('reset', (string) self::$db->value('SELECT totp_state FROM staff_user WHERE id = ?', [$lost['id']]));

        // The session limits still hold.
        $v = $this->uiUser('viewer');
        [$web, $page] = $this->loginPage();
        $web->post('/ui/login', ['csrf' => $page->form('/ui/login')['csrf'], 'email' => $v['email'], 'password' => $v['password']]);
        self::$db->exec('UPDATE staff_session SET last_seen_at = NOW(6) - INTERVAL 31 MINUTE WHERE id = ?', [hash('sha256', $web->cookies['cw_session'])]);
        self::assertSame('/ui/login?why=signed_out', $web->get('/ui/')->location());
    }

    /**
     * Codex review of part 2, finding 1 (HIGH): the password is checked on a read without locks; a reset of the password, or the account
     * switched off, between that check and the new session must win. Both ways of signing in hold the account's row lock from the check of
     * what they verified (the password, the secret, active) to the session and its audit row, as the resets take it.
     */
    public function testAResetOrSwitchOffBetweenThePasswordCheckAndTheSessionWins(): void
    {
        [$reset, $switchOff] = $this->steppingIn();

        // With the code switched off: the step in comes after the password check, at the first look at the switch.
        $this->store(self::uk('+1 day'));
        foreach (['reset' => $reset, 'off' => $switchOff] as $what => $make) {
            $u = $this->uiUser('buyer');
            HookedStatement::$before["SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'string'"] = $make($u);
            $r = $this->hookedLogin()->attempt($u['email'], $u['password'], '', '198.51.100.90', null, null);
            self::assertSame('invalid', $r['status'], "without the code, {$what}: the old password signs nobody in");
            self::assertSame(0, self::live($u['id']), $what);
            self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'login.ok' AND staff_user_id = ?", [$u['id']]), $what);
        }
    }

    /** The same race on the ordinary sign-in, with the code (older than the switch: main had it too). */
    public function testAResetOrSwitchOffBetweenTheCodeCheckAndTheSessionWins(): void
    {
        [$reset, $switchOff] = $this->steppingIn();
        foreach (['reset' => $reset, 'off' => $switchOff] as $what => $make) {
            $u = $this->uiUser('buyer');
            HookedStatement::$before['SELECT totp_last_step FROM staff_user'] = $make($u);
            self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$u['id']]);
            $r = $this->hookedLogin()->attempt($u['email'], $u['password'], self::code($u['secret']), '198.51.100.91', null, null);
            self::assertSame('invalid', $r['status'], "with the code, {$what}");
            self::assertSame(0, self::live($u['id']), $what);
        }
    }

    /** @return array{0: \Closure(array): \Closure, 1: \Closure(array): \Closure} what steps in: an admin's password reset, the account switched off */
    private function steppingIn(): array
    {
        $admin = $this->uiUser('admin');
        $reset = function (array $u) use ($admin): \Closure {
            return function () use ($u, $admin): void {
                self::assertSame('done', (new StaffAdmin(self::$appDb))->resetPassword(Caller::staff($admin['id']), $u['id'])['result']);
            };
        };
        $switchOff = static fn (array $u): \Closure => static function () use ($u): void {
            self::$db->exec('UPDATE staff_user SET is_active = 0 WHERE id = ?', [$u['id']]);
        };
        return [$reset, $switchOff];
    }

    /**
     * Codex review of part 2, finding 2 (MEDIUM): a sign-in that read "switched off" and then waited must not create its session after
     * the switch ended or was emptied meanwhile. The switch is read again, sharing its row's lock with a change, just before the session.
     */
    public function testTheSwitchEndingWhileASignInIsUnderWayAsksForTheCode(): void
    {
        foreach (['emptied' => '', 'its time passed' => self::uk('-1 minute')] as $what => $then) {
            $this->store(self::uk('+1 hour'));
            $u = $this->uiUser('buyer');
            $this->atSecondRead(function () use ($then): void {
                $this->store($then);
            });
            $r = $this->hookedLogin()->attempt($u['email'], $u['password'], '', '198.51.100.92', null, null);
            self::assertSame('invalid', $r['status'], $what);
            self::assertSame(0, self::live($u['id']), $what);
            self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'login.ok' AND staff_user_id = ?", [$u['id']]), $what);
        }
    }

    /** Runs $fn just before the second read of the switch in this process (the one under the locks; the first is the look before). */
    private function atSecondRead(\Closure $fn): void
    {
        $first = true;
        $step = function () use (&$first, &$step, $fn): void {
            if ($first) {
                $first = false;
                HookedStatement::$before[self::SWITCH_READ] = $step;
                return;
            }
            $fn();
        };
        HookedStatement::$before[self::SWITCH_READ] = $step;
    }

    /**
     * The second Codex review of the switch, finding 1 (HIGH): with the switch on, an account with a "new sign-in code" waiting can have a
     * session. Someone who knew the old password and the sheet's code may have started the code's step meanwhile; the person's password
     * change must end that step, or it would still hand out a session without the new password.
     */
    public function testAPasswordChangeEndsAWaitingNewSignInCodeStep(): void
    {
        $admin = $this->uiUser('admin');
        $victim = $this->uiUser('buyer');
        $sheet = (new StaffAdmin(self::$appDb))->resetAuthenticator(Caller::staff($admin['id']), $victim['id'], self::$box);
        self::assertSame('done', $sheet['result']);
        $login = $this->loginService();
        // The switch on: the person signs in with the password alone (the new sign-in code waits).
        $this->store(self::uk('+1 day'));
        $ok = $login->attempt($victim['email'], $victim['password'], '', '198.51.100.41', null, null);
        self::assertSame('ok', $ok['status']);
        // The switch off again: someone with the old password and the sheet's code starts the step (the rotate-and-confirm page).
        $this->store('');
        $next = $login->attempt($victim['email'], $victim['password'], Totp::code((string) $sheet['secret']), '203.0.113.40', null, null);
        self::assertSame('new_code', $next['status']);
        $en = new Enrolment(self::$appDb, new LoginLimiter(self::$appDb), self::$box);
        $fresh = (string) ($en->pending($next['token'])['secret'] ?? '');
        self::assertNotSame('', $fresh);
        // The person, still signed in, changes the password.
        $changed = $login->changePassword($victim['id'], hash('sha256', (string) $ok['token']), '198.51.100.41', null, $victim['password'], 'a brand new passphrase 2026');
        self::assertSame('ok', $changed['status']);
        self::assertNull($en->pending($next['token']), 'the waiting step ended with the password');
        self::assertNull(self::$db->value('SELECT totp_next_token FROM staff_user WHERE id = ?', [$victim['id']]));
        self::assertSame('gone', $en->confirm($next['token'], Totp::code($fresh), null, '203.0.113.40', null, null)['status']);
        self::assertSame(1, self::live($victim['id']), 'only the session of the password change');
    }

    /**
     * The second Codex review of the switch, finding 2 (HIGH, older than the switch): the hand-off to a "new sign-in code" step was made
     * without the account's lock, so a password reset or a new sheet made on the server between the checks and the hand-off still
     * started a step (and then a session) with what was replaced.
     */
    public function testANewSignInCodeHandOffAfterTheAccountChangedIsRefused(): void
    {
        $admin = $this->uiUser('admin');
        $changes = [
            'password' => static fn (int $id): \Closure => static function () use ($id): void {
                self::$db->exec('UPDATE staff_user SET password_hash = ? WHERE id = ?', [password_hash('set on the server 2026', PASSWORD_ARGON2ID), $id]);
            },
            'secret' => static fn (int $id): \Closure => static function () use ($id): void {
                self::$db->exec('UPDATE staff_user SET totp_secret_enc = ? WHERE id = ?', [self::$box->encrypt(Totp::newSecret()), $id]);
            },
        ];
        foreach ($changes as $what => $make) {
            $u = $this->uiUser('buyer');
            $sheet = (new StaffAdmin(self::$appDb))->resetAuthenticator(Caller::staff($admin['id']), $u['id'], self::$box);
            HookedStatement::$before['SELECT totp_last_step FROM staff_user'] = $make($u['id']);
            $r = $this->hookedLogin()->attempt($u['email'], $u['password'], Totp::code((string) $sheet['secret']), '198.51.100.93', null, null);
            self::assertSame('invalid', $r['status'], $what);
            self::assertNull(self::$db->value('SELECT totp_next_token FROM staff_user WHERE id = ?', [$u['id']]), "{$what}: no step started");
        }
    }

    /**
     * The second Codex review of the switch, findings 3 and 4: the session is made only while the database's clock is still before the
     * switch's end (time passes while a sign-in waits for a lock, and the setting's share lock does not stop it), and the switch's row is
     * locked before the account's, in the order of a settings change (its row, then the person through the history's foreign key).
     */
    public function testASessionWithoutTheCodeIsMadeOnlyBeforeTheEndByTheDatabaseClockAndTheLocksKeepTheirOrder(): void
    {
        $u = $this->uiUser('buyer');
        $sessions = new Sessions(self::$appDb);
        self::assertNull($sessions->createBefore($u['id'], '198.51.100.94', null, Clock::now()->modify('-1 second')), 'past its end: no session');
        self::assertSame(0, self::live($u['id']));
        $made = $sessions->createBefore($u['id'], '198.51.100.94', null, Clock::now()->modify('+1 minute'));
        self::assertNotNull($made);
        self::assertSame(1, self::live($u['id']));

        $this->store(self::uk('+1 day'));
        $order = [];
        $this->atSecondRead(function () use (&$order): void {
            $order[] = 'switch';
        });
        HookedStatement::$before['SELECT password_hash, totp_secret_enc, totp_state, is_active FROM staff_user WHERE id = ? FOR UPDATE'] = function () use (&$order): void {
            $order[] = 'account';
        };
        $v = $this->uiUser('viewer');
        self::assertSame('ok', $this->hookedLogin()->attempt($v['email'], $v['password'], '', '198.51.100.95', null, null)['status']);
        self::assertSame(['switch', 'account'], $order);
    }

    /**
     * The third Codex review of the switch (HIGH, older than the switch; a sign-in without the code makes the first session easy): a
     * password change that was verified and then waited must not undo a reset, or a sign-out of the session it came from, made meanwhile.
     */
    public function testAPasswordChangeThatWaitedDoesNotUndoWhatHappenedMeanwhile(): void
    {
        $admin = $this->uiUser('admin');
        $meanwhile = [
            'a reset' => static fn (array $u): \Closure => static function () use ($u, $admin): void {
                self::assertSame('done', (new StaffAdmin(self::$appDb))->resetPassword(Caller::staff($admin['id']), $u['id'])['result']);
            },
            'a sign-out everywhere' => static fn (array $u): \Closure => static function () use ($u, $admin): void {
                self::assertSame(1, (new StaffAdmin(self::$appDb))->signOut(Caller::staff($admin['id']), $u['id'], null));
            },
        ];
        foreach ($meanwhile as $what => $make) {
            $u = $this->uiUser('buyer');
            $login = $this->hookedLogin();
            $ok = $login->attempt($u['email'], $u['password'], self::code($u['secret']), '198.51.100.96', null, null);
            self::assertSame('ok', $ok['status']);
            $before = (string) self::$db->value('SELECT password_hash FROM staff_user WHERE id = ?', [$u['id']]);
            // After the current password was checked (the limiter's locks are let go), before anything is written.
            HookedStatement::$before['SELECT RELEASE_LOCK('] = $make($u);
            $r = $login->changePassword($u['id'], hash('sha256', (string) $ok['token']), '198.51.100.96', null, $u['password'], 'a waiting passphrase 2026');
            self::assertSame('wrong_current', $r['status'], $what);
            $now = (string) self::$db->value('SELECT password_hash FROM staff_user WHERE id = ?', [$u['id']]);
            self::assertFalse(password_verify('a waiting passphrase 2026', $now), "{$what}: the waiting change wrote nothing");
            self::assertSame($what === 'a reset', $now !== $before, $what);
            self::assertSame(0, self::live($u['id']), "{$what}: no new session");
        }
    }

    public function testItEndsByItselfAtItsTime(): void
    {
        $until = self::uk('+2 minutes');
        $this->store($until);
        $at = SecondStep::offUntil(self::$appDb);
        self::assertNotNull($at);
        self::assertNull(SecondStep::offUntil(self::$appDb, Clock::now()->modify('+3 minutes')), 'after its time, the code is asked for again');
        self::assertNotNull(SecondStep::offUntil(self::$appDb, Clock::now()->modify('+1 minute')));

        // A session made while it was on carries on to its own limits; a new sign-in needs the code again.
        $u = $this->uiUser('mapper');
        [$web, $page] = $this->loginPage();
        self::assertSame(303, $web->post('/ui/login', ['csrf' => $page->form('/ui/login')['csrf'], 'email' => $u['email'], 'password' => $u['password']])->status);
        $this->store(self::uk('-1 minute'));
        self::assertSame(200, $web->get('/ui/')->status);
        self::assertSame('invalid', $this->loginService()->attempt($u['email'], $u['password'], '', '198.51.100.81', null, null)['status']);
    }

    public function testTheSettingIsBoundedAndSaysItIsARisk(): void
    {
        self::assertStringContainsString('security risk', strtolower(Words::SETTING_HELP[self::KEY]));
        self::assertStringContainsString('by itself', Words::SETTING_HELP[self::KEY], 'and when it ends');
        self::assertSame('Staff', Words::settingTopic(self::KEY));
        $settings = new Settings(self::$db);
        foreach (['', self::uk('+1 hour'), self::uk('+89 days'), self::uk('-2 days')] as $ok) {
            $settings->checkRule(self::KEY, $ok);
        }
        foreach ([self::uk('+91 days'), '2027-12-31 10:00', 'tomorrow', '2026-10-27', '2026-10-27T18:00', '27/10/2026 18:00', '2026-02-30 10:00',
            '2026-10-25 01:30', '2026-10-27 24:00'] as $bad) {
            try {
                $settings->checkRule(self::KEY, $bad);
                self::fail("{$bad} is refused");
            } catch (CwException $e) {
                self::assertSame('bad_value', $e->errorCode, $bad);
            }
        }

        // On the Settings page: settings.manage and a reason, the bound refused, a change takes effect at once both ways.
        $owner = $this->signIn($this->uiUser('reviewer'));
        $form = $owner->get('/ui/reference/settings/setting', ['key' => self::KEY])->form('/ui/reference/settings/setting');
        self::assertNotSame([], $form);
        $refused = $owner->post('/ui/reference/settings/setting', ['value' => self::uk('+100 days'), 'reason' => 'too long on purpose'] + $form);
        self::assertSame(400, $refused->status, $refused->describe());
        self::assertSame('""', (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [self::KEY]), 'nothing saved');
        $noReason = $owner->post('/ui/reference/settings/setting', ['value' => self::uk('+1 day'), 'reason' => ''] + $form);
        self::assertNotSame(303, $noReason->status, 'a reason is needed');
        $until = self::uk('+3 days');
        $saved = $owner->post('/ui/reference/settings/setting', ['value' => $until, 'reason' => 'the owner: off until the phones are sorted'] + $form);
        self::assertSame(303, $saved->status, $saved->describe());
        self::assertNotNull(SecondStep::offUntil(self::$appDb));
        self::assertSame(1, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'setting.change' AND entity_id = ?", [self::KEY]));
        $form = $owner->get('/ui/reference/settings/setting', ['key' => self::KEY])->form('/ui/reference/settings/setting');
        self::assertSame(303, $owner->post('/ui/reference/settings/setting', ['value' => '', 'reason' => 'the phones are sorted: back on'] + $form)->status);
        self::assertNull(SecondStep::offUntil(self::$appDb), 'emptied: the code is asked for again at once');

        // A buyer cannot change it.
        $buyer = $this->signIn($this->uiUser('buyer'));
        self::assertSame(403, $buyer->post('/ui/reference/settings/setting', ['csrf' => $this->token($buyer), 'key' => self::KEY, 'seen' => '1',
            'value' => self::uk('+1 day'), 'reason' => 'not mine to change'])->status);
    }

    public function testAdminsAndReviewersSeeAWarningOnTheDashboardAndSettingsSystem(): void
    {
        $reviewer = $this->signIn($this->uiUser('reviewer'));
        $admin = $this->signIn($this->uiUser('admin'));
        $buyer = $this->signIn($this->uiUser('buyer'));
        $warning = static fn (string $until): string => sprintf(Words::TASK['code_off']['title'], self::shown($until));
        foreach ([$reviewer, $admin] as $web) {
            self::assertStringNotContainsString('2-step verification is OFF', $web->get('/ui/')->text(), 'off: no warning');
            self::assertStringNotContainsString('2-step verification is OFF', $web->get('/ui/reference/settings')->text());
        }

        $until = self::uk('+5 hours');
        $this->store($until);
        foreach (['reviewer' => $reviewer, 'admin' => $admin] as $who => $web) {
            $home = $web->get('/ui/');
            self::assertSame(200, $home->status, $home->describe());
            self::assertStringContainsString($warning($until), $home->text(), "{$who}: the Dashboard says so");
            self::assertContains('/ui/reference/settings/setting?key=' . self::KEY, $home->hrefs(), "{$who}: and leads to the setting");
            $system = $web->get('/ui/reference/settings');
            self::assertSame(200, $system->status, $system->describe());
            self::assertStringContainsString(Words::say('SETTINGS_PAGE', 'code_off', self::shown($until)), $system->text(), "{$who}: Settings › System says so");
        }
        self::assertStringStartsWith('2-step verification is OFF until', $warning($until));
        // The fourth Codex review (low): what the Users pages say about signing in again promises no code while it may be off (TF15).
        foreach (['sign_out_all_note', 'allow_note'] as $key) {
            self::assertStringNotContainsString('code', Words::STAFF[$key], $key);
        }
        $p = $this->uiUser('buyer');
        $person = $admin->get('/ui/people/' . $p['id']);
        self::assertSame(200, $person->status, $person->describe());
        self::assertStringNotContainsString('password and code', $person->text());
        self::assertStringNotContainsString('2-step verification is OFF', $buyer->get('/ui/')->text(), 'a buyer can do nothing about it');
        $this->store(self::uk('-1 minute'));
        self::assertStringNotContainsString('2-step verification is OFF', $reviewer->get('/ui/')->text(), 'past: gone by itself');
    }
}
