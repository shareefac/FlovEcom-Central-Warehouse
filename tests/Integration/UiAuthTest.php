<?php

declare(strict_types=1);

namespace CW\Tests\Integration;

use CW\Auth\LoginLimiter;
use CW\Auth\Sessions;
use CW\Tests\Support\UiClient;
use CW\Tests\Support\UiResponse;
use CW\Tests\Support\UiTestCase;

/**
 * Sign-in, sessions and sign-out of the staff screens, over HTTP against the real vhost (plan §11):
 * e-mail + password + TOTP in one step, one generic failure, hashed session ids, rotation, the
 * idle and absolute limits, POST-only sign-out and the failed-login lock.
 */
final class UiAuthTest extends UiTestCase
{
    public function testTheLoginPageIsSelfContainedAndSetsAHardenedPreLoginCookie(): void
    {
        $web = $this->browser();
        $r = $web->get('/ui/login');
        self::assertSame(200, $r->status);
        self::assertStringContainsString('text/html', (string) $r->header('content-type'));
        self::assertSame("default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'", $r->header('content-security-policy'));
        self::assertSame(['csrf', 'email', 'password', 'code'], array_keys($r->form('/ui/login')));

        $cookie = (string) $r->setCookie('cw_pre');
        self::assertNotSame('', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Path=/ui', $cookie);
        self::assertStringNotContainsString('Secure', $cookie, 'plain HTTP on the local test vhost');

        // The same browser keeps the same pre-login cookie: the token of the form stays valid for reloads.
        $again = $web->get('/ui/login');
        self::assertNull($again->setCookie('cw_pre'));
        self::assertSame($r->form('/ui/login')['csrf'], $again->form('/ui/login')['csrf']);
    }

    public function testSigningInGivesAHardenedSessionWhoseIdIsStoredHashed(): void
    {
        $user = $this->uiUser('mapper');
        $web = $this->browser();
        $login = $this->attemptLogin($web, $user['email'], $user['password'], self::code($user['secret']));
        self::assertSame(303, $login->status, $login->describe());
        self::assertSame('/ui/', $login->location());

        $cookie = (string) $login->setCookie('cw_session');
        self::assertMatchesRegularExpression('/^cw_session=[A-Za-z0-9_-]{43};/', $cookie, 'a 256-bit random token');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Path=/ui', $cookie);
        self::assertStringNotContainsString('Secure', $cookie, 'no Secure over plain HTTP (the HTTPS vhost sets it: see UiUnitTest)');
        self::assertStringContainsString('cw_pre=;', (string) $login->setCookie('cw_pre'), 'the pre-login cookie is dropped');

        $token = $web->cookies['cw_session'];
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE id = ?', [hash('sha256', $token)]));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE id = ?', [$token]), 'the cookie value is never stored');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM staff_session'));
        self::assertSame(64, (int) self::$db->value('SELECT CHAR_LENGTH(id) FROM staff_session'));

        $home = $web->follow($login);
        self::assertSame(200, $home->status);
        self::assertStringContainsString('Dashboard', $home->text());
        self::assertSame('no-store', $home->header('cache-control'));

        self::assertSame(1, self::auditCount('login.ok', $user['id']));
        self::assertSame(0, self::auditCount('login.fail'));
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM login_attempt WHERE success = 1'));
    }

    public function testEveryFailureLooksTheSameAndIsAudited(): void
    {
        $user = $this->uiUser('mapper');
        $good = self::code($user['secret']);
        $bad = $good === '000000' ? '111111' : '000000';

        $answers = [
            'unknown account' => $this->attemptLogin($this->browser(), 'nobody@test.invalid', $user['password'], $good),
            'wrong password' => $this->attemptLogin($this->browser(), $user['email'], $user['password'] . 'x', $good),
            'wrong code' => $this->attemptLogin($this->browser(), $user['email'], $user['password'], $bad),
            'not an e-mail address' => $this->attemptLogin($this->browser(), "x' OR 1=1 -- ", $user['password'], $good),
            'empty code' => $this->attemptLogin($this->browser(), $user['email'], $user['password'], ''),
        ];
        $texts = [];
        foreach ($answers as $what => $r) {
            self::assertSame(401, $r->status, $what);
            self::assertArrayNotHasKey('cw_session', $this->cookiesOf($r), $what);
            self::assertSame(1, preg_match('#<p class="error"[^>]*>(.*?)</p>#s', $r->body, $m), $what);
            $texts[$what] = $m[1];
        }
        self::assertCount(1, array_unique($texts), 'the message never says which factor failed: ' . json_encode($texts));
        self::assertStringContainsString('did not work', $answers['wrong code']->text());

        self::assertSame(5, self::auditCount('login.fail'));
        self::assertSame(0, self::auditCount('login.ok'));
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session'));
        // The typed e-mail is kept in the form, the password and code never are.
        $known = $answers['wrong password'];
        self::assertSame($user['email'], $known->form('/ui/login')['email']);
        self::assertSame('', $known->form('/ui/login')['password'] ?? '');
        self::assertStringNotContainsString($user['password'], $known->body);

        // The audit row of a wrong code names the step that failed, and never a secret.
        $detail = (string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'login.fail' AND staff_user_id = ? ORDER BY id DESC LIMIT 1", [$user['id']]);
        self::assertStringContainsString('"step"', $detail);
        self::assertStringNotContainsString($user['password'], $detail);
        self::assertStringNotContainsString($good, $detail);
    }

    public function testAnInactiveAccountCannotSignInAndALiveSessionEndsWhenItIsSwitchedOff(): void
    {
        $user = $this->uiUser('mapper');
        $web = $this->signIn($user);
        self::assertSame(200, $web->get('/ui/')->status);
        self::$db->exec('UPDATE staff_user SET is_active = 0 WHERE id = ?', [$user['id']]);
        $r = $web->get('/ui/');
        self::assertSame(303, $r->status);
        self::assertSame('/ui/login', $r->location());
        self::assertSame(1, (int) self::$db->value('SELECT revoked FROM staff_session'));

        $r = $this->attemptLogin($this->browser(), $user['email'], $user['password'], self::code($user['secret']));
        self::assertSame(401, $r->status);
    }

    public function testATotpCodeWorksOnceAndInsideThePlusMinusOneWindow(): void
    {
        $user = $this->uiUser('viewer');

        // The same code twice (however the clock moves in between): once, then refused as a replay.
        $code = self::code($user['secret']);
        $first = $this->attemptLogin($this->browser(), $user['email'], $user['password'], $code);
        self::assertSame(303, $first->status, $first->describe());
        $replay = $this->attemptLogin($this->browser(), $user['email'], $user['password'], $code, false);
        self::assertSame(401, $replay->status, 'a code is accepted once');
        $signedIn = 1;

        // The steps next to the current one are accepted (clock drift), those two away are not.
        $signedIn += $this->attemptOffset($user, -1, 303);
        $signedIn += $this->attemptOffset($user, 1, 303);
        $signedIn += $this->attemptOffset($user, -2, 401);
        $signedIn += $this->attemptOffset($user, 2, 401);
        self::assertSame($signedIn, self::auditCount('login.ok', $user['id']));
    }

    public function testASessionIsRotatedAtSignInAndTheOldOneIsRevoked(): void
    {
        $user = $this->uiUser('mapper');
        $first = $this->signIn($user);
        $old = $first->cookies['cw_session'];

        // A second sign-in that still carries the first cookie (a form opened before it was signed in).
        $second = $this->browser();
        $fields = $this->loginFields($second);
        $second->cookies['cw_session'] = $old;
        self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$user['id']]);
        $r = $second->post('/ui/login', ['csrf' => $fields['csrf'], 'email' => $user['email'], 'password' => $user['password'], 'code' => self::code($user['secret'])]);
        self::assertSame(303, $r->status, $r->describe());
        self::assertNotSame($old, $second->cookies['cw_session'], 'a new token at every sign-in');

        self::assertSame(1, (int) self::$db->value('SELECT revoked FROM staff_session WHERE id = ?', [hash('sha256', $old)]));
        self::assertSame(0, (int) self::$db->value('SELECT revoked FROM staff_session WHERE id = ?', [hash('sha256', $second->cookies['cw_session'])]));
        $stale = $first->get('/ui/');
        self::assertSame(303, $stale->status);
        self::assertSame('/ui/login', $stale->location());
        self::assertNotNull($stale->setCookie('cw_session'), 'the stale cookie is cleared');
        self::assertArrayNotHasKey('cw_session', $first->cookies);
        self::assertSame(200, $second->get('/ui/')->status);
    }

    public function testSigningOutIsPostOnlyNeedsTheTokenAndRevokesTheSession(): void
    {
        $user = $this->uiUser('mapper');
        $web = $this->signIn($user);
        $token = $this->token($web);
        $kept = $web->cookies['cw_session'];

        $get = $web->get('/ui/logout');
        self::assertSame(405, $get->status);
        self::assertSame('POST', $get->header('allow'));
        self::assertSame(200, $web->get('/ui/')->status, 'a GET never signs anybody out');

        $forged = $web->post('/ui/logout', ['csrf' => 'nope']);
        self::assertSame(403, $forged->status);
        self::assertSame(200, $web->get('/ui/')->status);
        self::assertSame(0, self::auditCount('logout'));

        $out = $web->post('/ui/logout', ['csrf' => $token]);
        self::assertSame(303, $out->status);
        self::assertSame('/ui/login', $out->location());
        self::assertStringContainsString('cw_session=;', (string) $out->setCookie('cw_session'));
        self::assertSame(1, self::auditCount('logout', $user['id']));
        self::assertSame(1, (int) self::$db->value('SELECT revoked FROM staff_session'));

        // The token, replayed by somebody who kept a copy, is dead.
        $thief = $this->browser();
        $thief->cookies['cw_session'] = $kept;
        self::assertSame(303, $thief->get('/ui/')->status);
    }

    public function testASessionEndsAfterThirtyIdleMinutesAndTwelveHoursInAll(): void
    {
        $user = $this->uiUser('mapper');

        $web = $this->signIn($user);
        self::$db->exec('UPDATE staff_session SET last_seen_at = NOW(6) - INTERVAL 29 MINUTE');
        self::assertSame(200, $web->get('/ui/')->status, '29 idle minutes: still in');
        $seen = (int) self::$db->value('SELECT TIMESTAMPDIFF(SECOND, last_seen_at, NOW(6)) FROM staff_session');
        self::assertLessThan(60, $seen, 'every request counts as activity');

        self::$db->exec('UPDATE staff_session SET last_seen_at = NOW(6) - INTERVAL 31 MINUTE');
        $r = $web->get('/ui/');
        self::assertSame(303, $r->status, 'idle for 31 minutes');
        self::assertSame('/ui/login', $r->location());
        self::assertSame(1, (int) self::$db->value('SELECT revoked FROM staff_session'), 'an expired session can never come back');

        $web = $this->signIn($user);
        self::$db->exec('UPDATE staff_session SET created_at = NOW(6) - INTERVAL 11 HOUR - INTERVAL 59 MINUTE WHERE revoked = 0');
        self::assertSame(200, $web->get('/ui/')->status, '11 h 59 min old and active: still in');
        self::$db->exec('UPDATE staff_session SET created_at = NOW(6) - INTERVAL 12 HOUR - INTERVAL 1 MINUTE WHERE revoked = 0');
        $r = $web->get('/ui/');
        self::assertSame(303, $r->status, 'older than 12 hours, however active');
        self::assertSame('/ui/login', $r->location());

        self::assertSame(Sessions::IDLE_SECONDS, 1800);
        self::assertSame(Sessions::ABSOLUTE_SECONDS, 43200);
    }

    public function testAnAccountIsLockedAfterTenFailuresForFifteenMinutes(): void
    {
        $user = $this->uiUser('mapper');
        $other = $this->uiUser('mapper');
        for ($i = 1; $i <= LoginLimiter::ACCOUNT_MAX; $i++) {
            $r = $this->attemptLogin($this->browser(), $user['email'], 'wrong password ' . $i, self::code($user['secret']));
            self::assertSame(401, $r->status, "failure {$i}");
        }
        $recorded = (int) self::$db->value('SELECT COUNT(*) FROM login_attempt WHERE login = ?', [$user['email']]);
        self::assertSame(10, $recorded);

        // Now even the right password and code are refused, with a Retry-After, and nothing more is recorded.
        $locked = $this->attemptLogin($this->browser(), $user['email'], $user['password'], self::code($user['secret']));
        self::assertSame(429, $locked->status);
        self::assertSame('900', $locked->header('retry-after'));
        self::assertStringContainsString('Too many failed attempts', $locked->text());
        self::assertSame(10, (int) self::$db->value('SELECT COUNT(*) FROM login_attempt WHERE login = ?', [$user['email']]), 'refused attempts do not extend the lock');
        self::assertSame(0, (int) self::$db->value('SELECT COUNT(*) FROM staff_session'));

        // Another account of the same office is not locked by it.
        $this->signIn($other);

        // 15 minutes later the failures fall out of the window.
        self::$db->exec("UPDATE login_attempt SET created_at = NOW(6) - INTERVAL 16 MINUTE WHERE login = ?", [$user['email']]);
        $this->signIn($user);
    }

    public function testASuccessfulSignInClearsTheAccountsFailureCount(): void
    {
        $user = $this->uiUser('mapper');
        for ($i = 0; $i < LoginLimiter::ACCOUNT_MAX - 1; $i++) {
            $this->attemptLogin($this->browser(), $user['email'], 'wrong password', self::code($user['secret']));
        }
        $this->signIn($user);
        // Nine failures before the success no longer count: nine more do not lock.
        for ($i = 0; $i < LoginLimiter::ACCOUNT_MAX - 1; $i++) {
            $r = $this->attemptLogin($this->browser(), $user['email'], 'wrong password', self::code($user['secret']));
            self::assertSame(401, $r->status);
        }
        self::assertSame(200, $this->signIn($user, null)->get('/ui/')->status);
    }

    public function testAnAddressIsLockedAfterThirtyFailuresWhateverTheAccounts(): void
    {
        $user = $this->uiUser('mapper');
        // 29 earlier failures against other (even unknown) accounts from this address, then one more over HTTP.
        for ($i = 0; $i < LoginLimiter::IP_MAX - 1; $i++) {
            self::$db->exec("INSERT INTO login_attempt (login, ip, step, success) VALUES (?, '127.0.0.1', 'password', 0)", ["other{$i}@test.invalid"]);
        }
        $r = $this->attemptLogin($this->browser(), 'nobody@test.invalid', 'x', '123456');
        self::assertSame(401, $r->status);

        $locked = $this->attemptLogin($this->browser(), $user['email'], $user['password'], self::code($user['secret']));
        self::assertSame(429, $locked->status);
        self::assertSame('900', $locked->header('retry-after'));

        self::$db->exec("UPDATE login_attempt SET created_at = NOW(6) - INTERVAL 16 MINUTE");
        $this->signIn($user);
    }

    public function testAForcedPasswordChangeBlocksEverythingElseUntilItIsDone(): void
    {
        $user = $this->uiUser('mapper', true);
        $web = $this->signIn($user, null, '/ui/password');
        $other = $this->signIn($user, $this->browser(), '/ui/password'); // a second session of the same person

        foreach (['/ui/', '/ui/review?queue=Key', '/ui/search', '/ui/items/1', '/ui/review/listing/1'] as $path) {
            $r = $web->get($path);
            self::assertSame(303, $r->status, $path);
            self::assertSame('/ui/password', $r->location(), $path);
        }
        $page = $web->get('/ui/password');
        self::assertSame(200, $page->status);
        self::assertStringContainsString('choose a new password', $page->text());
        $token = $page->form('/ui/password')['csrf'];

        $refusals = [
            'wrong current' => [['current' => 'not it', 'new' => 'A brand new password 1', 'again' => 'A brand new password 1'], 'not right'],
            'too short' => [['current' => $user['password'], 'new' => 'short', 'again' => 'short'], '12 to 200'],
            'mismatch' => [['current' => $user['password'], 'new' => 'A brand new password 1', 'again' => 'A brand new password 2'], 'not the same'],
            'unchanged' => [['current' => $user['password'], 'new' => $user['password'], 'again' => $user['password']], 'must differ'],
            'the e-mail address' => [['current' => $user['password'], 'new' => strtoupper($user['email']), 'again' => strtoupper($user['email'])], 'e-mail address'],
        ];
        foreach ($refusals as $what => [$fields, $text]) {
            $r = $web->post('/ui/password', ['csrf' => $token] + $fields);
            self::assertSame(422, $r->status, $what);
            self::assertStringContainsString($text, $r->text(), $what);
        }
        self::assertSame(1, (int) self::$db->value('SELECT password_must_change FROM staff_user WHERE id = ?', [$user['id']]));
        self::assertSame(1, self::auditCount('password.fail', $user['id']), 'only a wrong current password is a failed attempt');

        $new = 'A brand new password 1';
        $done = $web->post('/ui/password', ['csrf' => $token, 'current' => $user['password'], 'new' => $new, 'again' => $new]);
        self::assertSame(303, $done->status, $done->describe());
        self::assertSame('/ui/?notice=password_changed', $done->location());
        self::assertStringContainsString('Your password was changed.', $web->follow($done)->text());
        self::assertSame(0, (int) self::$db->value('SELECT password_must_change FROM staff_user WHERE id = ?', [$user['id']]));
        self::assertTrue(password_verify($new, (string) self::$db->value('SELECT password_hash FROM staff_user WHERE id = ?', [$user['id']])));
        self::assertSame(1, self::auditCount('password.change', $user['id']));
        self::assertSame(200, $web->get('/ui/search')->status, 'the screens are open now');

        // The other session of the person ended; the old password no longer signs in, the new one does.
        self::assertSame(303, $other->get('/ui/')->status);
        self::assertSame(401, $this->attemptLogin($this->browser(), $user['email'], $user['password'], self::code($user['secret']))->status);
        $user['password'] = $new;
        $this->signIn($user);
    }

    public function testSignedInPeopleAreSentPastTheLoginPageAndUnknownPagesAre404(): void
    {
        $web = $this->signIn($this->uiUser('viewer'));
        $r = $web->get('/ui/login');
        self::assertSame(303, $r->status);
        self::assertSame('/ui/', $r->location());

        $nothing = $web->get('/ui/no-such-page');
        self::assertSame(404, $nothing->status);
        self::assertStringContainsString('no such page', $nothing->text());
        self::assertSame(404, $web->get('/ui/review/listing/abc')->status);
        self::assertSame(404, $web->get('/ui/items/0')->status);
    }

    // ---- helpers -----------------------------------------------------------------------------

    /**
     * Signs in with a code $offset steps from now and checks the answer. A step boundary crossed
     * between computing the code and the server reading the clock could turn either answer, so
     * such an attempt is repeated.
     *
     * @param array{id: int, email: string, password: string, secret: string} $user
     * @return int how many sign-ins it made (a repeat after a boundary can add one)
     */
    private function attemptOffset(array $user, int $offset, int $expect): int
    {
        $signedIn = 0;
        for ($try = 0; $try < 4; $try++) {
            $step = intdiv(time(), 30);
            $r = $this->attemptLogin($this->browser(), $user['email'], $user['password'], self::code($user['secret'], $offset));
            $signedIn += $r->status === 303 ? 1 : 0;
            if (intdiv(time(), 30) === $step) {
                self::assertSame($expect, $r->status, "code {$offset} steps from now: " . $r->describe());
                return $signedIn;
            }
        }
        self::fail('the clock kept crossing a TOTP step boundary');
    }

    /** @return array<string, string> the cookies a response sets (name => value), expiries excluded */
    private function cookiesOf(UiResponse $r): array
    {
        $out = [];
        foreach ($r->headerValues('set-cookie') as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($value !== '') {
                $out[$name] = $value;
            }
        }
        return $out;
    }
}
