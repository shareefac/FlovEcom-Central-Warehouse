<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Auth\LoginLimiter;
use CW\Auth\Sessions;
use CW\Caller;
use CW\CwException;
use CW\Settings;
use CW\Staff\Enrolment;
use CW\Staff\StaffAdmin;
use CW\Staff\StaffSessions;
use CW\Staff\Totp;
use CW\Tests\Support\KernelBrowser;
use CW\Tests\Support\KernelUiTestCase;
use CW\Tests\Support\UiResponse;
use CW\Ui\Words;

/**
 * How long a person stays signed in (the owner, 20 Oct 2026: "we face session time out easily, once logged in don't time out";
 * docs/decisions.md SL1-SL5). The settings session.idle_minutes and session.absolute_hours (0047) take the place of the fixed 30
 * minutes and 12 hours; they are read once per request, and a missing or unreadable one reads as the limit of before. The session
 * cookie lives as long as the absolute limit (Max-Age), so closing the browser does not sign the person out; signing out, a password
 * change and an ended session still end it and clear the cookie. In-process through the real kernel, in every slot (UiAuthTest
 * proves the same headers over HTTP in slot ui).
 */
final class SessionLimitsTest extends KernelUiTestCase
{
    private const IDLE = 'session.idle_minutes';
    private const ABSOLUTE = 'session.absolute_hours';
    private const MISSING = '.missing_in_test';

    /** @var array<string, array{v: string, provisional: int, actor: string}> setting key => the row as it was */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::IDLE, self::ABSOLUTE] as $key) {
            $r = self::$db->one('SELECT CAST(value_json AS CHAR) AS v, provisional, updated_actor FROM app_setting WHERE setting_key = ?', [$key]);
            self::assertNotNull($r, "{$key}: 0047 adds the setting");
            $this->saved[$key] = ['v' => (string) $r['v'], 'provisional' => (int) $r['provisional'], 'actor' => (string) $r['updated_actor']];
        }
    }

    protected function tearDown(): void
    {
        // app_setting is a seed table the tests keep: put each row back as it was (a renamed one under its own name first).
        foreach ($this->saved as $key => $r) {
            self::$db->exec('UPDATE app_setting SET setting_key = ? WHERE setting_key = ?', [$key, $key . self::MISSING]);
            self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON), provisional = ?, updated_actor = ? WHERE setting_key = ?',
                [$r['v'], $r['provisional'], $r['actor'], $key]);
        }
        $this->saved = [];
        parent::tearDown();
    }

    /** Stores a setting's JSON text as only the admin login could (a value the rule may refuse). */
    private function store(string $key, string $json): void
    {
        self::$db->exec('UPDATE app_setting SET value_json = CAST(? AS JSON) WHERE setting_key = ?', [$json, $key]);
    }

    /**
     * The browser's session as if it was signed in $ageMinutes ago and last opened a page $idleMinutes ago, then the next page.
     */
    private function after(KernelBrowser $web, int $idleMinutes, int $ageMinutes): UiResponse
    {
        $id = hash('sha256', $web->cookies['cw_session'] ?? self::fail('the browser holds no session'));
        self::$db->exec('UPDATE staff_session SET last_seen_at = NOW(6) - INTERVAL ? MINUTE, created_at = NOW(6) - INTERVAL ? MINUTE WHERE id = ?',
            [$idleMinutes, $ageMinutes, $id]);
        return $web->get('/ui/');
    }

    private static function revoked(string $token): int
    {
        return (int) self::$db->value('SELECT revoked FROM staff_session WHERE id = ?', [hash('sha256', $token)]);
    }

    /** Signs in through the real form and returns the sign-in's own answer (signIn() returns only the browser). */
    private function login(array $user, KernelBrowser $web): UiResponse
    {
        $csrf = $web->get('/ui/login')->form('/ui/login')['csrf'] ?? '';
        self::$db->exec('UPDATE staff_user SET totp_last_step = NULL WHERE id = ?', [$user['id']]);
        $r = $web->post('/ui/login', ['csrf' => $csrf, 'email' => $user['email'], 'password' => $user['password'], 'code' => self::code($user['secret'])]);
        self::assertSame(303, $r->status, 'sign-in: ' . $r->describe());
        return $r;
    }

    public function testTheDefaultsAreTheLimitsOfBefore(): void
    {
        self::assertSame([1800, 43200], [Sessions::IDLE_SECONDS, Sessions::ABSOLUTE_SECONDS], '30 minutes and 12 hours');
        self::assertSame(['30', '12'], [$this->saved[self::IDLE]['v'], $this->saved[self::ABSOLUTE]['v']], 'the baselines of 0047');
        self::assertSame(['idle' => Sessions::IDLE_SECONDS, 'absolute' => Sessions::ABSOLUTE_SECONDS], (new Sessions(self::$appDb))->limits());

        $u = $this->uiUser('mapper');
        $web = $this->signIn($u);
        self::assertSame(200, $this->after($web, 29, 29)->status, '29 idle minutes: still in');
        $token = $web->cookies['cw_session'];
        $r = $this->after($web, 31, 31);
        self::assertSame([303, '/ui/login?why=signed_out'], [$r->status, $r->location()], 'idle for 31 minutes');
        self::assertSame(1, self::revoked($token), 'an ended session can never come back');
        self::assertStringContainsString('cw_session=;', (string) $r->setCookie('cw_session'), 'the dead cookie is cleared');

        $web = $this->signIn($u);
        self::assertSame(200, $this->after($web, 1, 12 * 60 - 1)->status, '11 h 59 min old and active: still in');
        $r = $this->after($web, 1, 12 * 60 + 1);
        self::assertSame([303, '/ui/login?why=signed_out'], [$r->status, $r->location()], 'older than 12 hours, however active');
    }

    public function testARaisedIdleLimitKeepsAPersonSignedInPastThirtyMinutesUntilTheNewLimit(): void
    {
        $this->store(self::IDLE, '600'); // 10 hours
        self::assertSame(['idle' => 36000, 'absolute' => 43200], (new Sessions(self::$appDb))->limits());
        $u = $this->uiUser('buyer');
        $web = $this->signIn($u);
        self::assertSame(200, $this->after($web, 31, 31)->status, 'away for 31 minutes: still in (signed out before the setting)');
        self::assertSame(200, $this->after($web, 599, 600)->status, 'away for 9 h 59 min: still in');
        $token = $web->cookies['cw_session'];
        $r = $this->after($web, 601, 602);
        self::assertSame([303, '/ui/login?why=signed_out'], [$r->status, $r->location()], 'away for longer than the new limit');
        self::assertSame(1, self::revoked($token));
        self::assertStringContainsString(Words::UI['signed_out'], $web->follow($r)->text(), 'the sign-in page says why');

        // The absolute limit still holds, however long the idle limit is.
        $web = $this->signIn($u);
        $r = $this->after($web, 1, 12 * 60 + 1);
        self::assertSame([303, '/ui/login?why=signed_out'], [$r->status, $r->location()], 'twelve hours after signing in');

        // At its most (30 days away), with the absolute limit at its most (90 days).
        $this->store(self::IDLE, '43200');
        $this->store(self::ABSOLUTE, '2160');
        $web = $this->signIn($u);
        self::assertSame(200, $this->after($web, 43199, 43199)->status, 'away for a minute less than 30 days: still in');
        $r = $this->after($web, 43201, 43201);
        self::assertSame([303, '/ui/login?why=signed_out'], [$r->status, $r->location()], 'away for more than 30 days');
    }

    public function testARaisedAbsoluteLimitKeepsAPersonSignedInPastTwelveHoursUntilTheNewLimit(): void
    {
        $this->store(self::ABSOLUTE, '2160'); // 90 days
        self::assertSame(['idle' => 1800, 'absolute' => 7776000], (new Sessions(self::$appDb))->limits());
        $u = $this->uiUser('goods_in');
        $web = $this->signIn($u);
        self::assertSame(200, $this->after($web, 1, 12 * 60 + 1)->status, 'signed in 12 h 1 min ago and active: still in (out before the setting)');
        self::assertSame(200, $this->after($web, 1, 90 * 24 * 60 - 1)->status, 'a minute less than 90 days: still in');
        $token = $web->cookies['cw_session'];
        $r = $this->after($web, 1, 90 * 24 * 60 + 1);
        self::assertSame([303, '/ui/login?why=signed_out'], [$r->status, $r->location()], 'older than the new limit, however active');
        self::assertSame(1, self::revoked($token));

        // The idle limit still holds (its default here: 30 minutes).
        $web = $this->signIn($u);
        $r = $this->after($web, 31, 31);
        self::assertSame([303, '/ui/login?why=signed_out'], [$r->status, $r->location()]);
    }

    public function testAMissingOrUnreadableSettingReadsAsTheLimitOfBefore(): void
    {
        $limits = static fn (): array => (new Sessions(self::$appDb))->limits();
        $default = ['idle' => Sessions::IDLE_SECONDS, 'absolute' => Sessions::ABSOLUTE_SECONDS];
        foreach (['"x"', '""', '"45"', '45.5', '29', '43201', '-60', 'true', '1000000000000'] as $json) {
            $this->store(self::IDLE, $json);
            self::assertSame($default, $limits(), "idle {$json}: not a whole number the rule allows");
        }
        $this->store(self::IDLE, '45');
        foreach (['"x"', '""', '11', '2161', '0'] as $json) {
            $this->store(self::ABSOLUTE, $json);
            self::assertSame(['idle' => 2700, 'absolute' => Sessions::ABSOLUTE_SECONDS], $limits(), "absolute {$json}; each setting on its own");
        }

        // Read once per request: the instance the kernel makes for a request keeps what it read.
        $this->store(self::ABSOLUTE, '24');
        $sessions = new Sessions(self::$appDb);
        self::assertSame(86400, $sessions->limits()['absolute']);
        $this->store(self::ABSOLUTE, '48');
        self::assertSame(86400, $sessions->limits()['absolute'], 'one request sees one set');
        self::assertSame(172800, $limits()['absolute'], 'the next request sees the change');

        // A schema before 0047 (no rows): the limits of before, and signing in and the devices list work as they did.
        self::$db->exec("UPDATE app_setting SET setting_key = CONCAT(setting_key, ?) WHERE setting_key IN (?, ?)", [self::MISSING, self::IDLE, self::ABSOLUTE]);
        self::assertSame($default, $limits());
        $u = $this->uiUser('mapper');
        $web = $this->signIn($u);
        self::assertStringContainsString('Max-Age=' . Sessions::ABSOLUTE_SECONDS, (string) $this->login($u, $this->browser())->setCookie('cw_session'));
        self::assertSame(200, $this->after($web, 29, 29)->status);
        self::assertCount(2, StaffSessions::live(self::$db, $u['id']));
        self::assertSame(303, $this->after($web, 31, 31)->status);
    }

    public function testTheSessionCookieLivesAsLongAsTheAbsoluteLimit(): void
    {
        $u = $this->uiUser('mapping_lead');
        $web = $this->browser();
        $cookie = (string) $this->login($u, $web)->setCookie('cw_session');
        self::assertMatchesRegularExpression('/^cw_session=[A-Za-z0-9_-]{43}; Path=\/ui; HttpOnly; SameSite=Strict; Max-Age=43200$/D', $cookie,
            'closing the browser does not sign the person out before the absolute limit (plain HTTP here: no Secure)');

        $this->store(self::ABSOLUTE, '2160');
        $this->store(self::IDLE, '43200');
        $web = $this->browser();
        $cookie = (string) $this->login($u, $web)->setCookie('cw_session');
        self::assertStringContainsString('; Max-Age=7776000', $cookie, '90 days in seconds');
        self::assertStringContainsString('HttpOnly; SameSite=Strict', $cookie);

        // A password change ends every session (a copied cookie too) and hands this browser a new one with the same lifetime.
        $copy = $this->browser('203.0.113.99');
        $copy->cookies['cw_session'] = $old = $web->cookies['cw_session'];
        self::assertSame(200, $copy->get('/ui/')->status);
        $page = $web->get('/ui/password');
        $r = $web->post('/ui/password', ['csrf' => $page->form('/ui/password')['csrf'], 'current' => $u['password'],
            'new' => 'a long new passphrase 2026', 'again' => 'a long new passphrase 2026']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertMatchesRegularExpression('/^cw_session=[A-Za-z0-9_-]{43}; Path=\/ui; HttpOnly; SameSite=Strict; Max-Age=7776000$/D', (string) $r->setCookie('cw_session'));
        self::assertSame(1, self::revoked($old), 'the session the change was made from ended');
        $dead = $copy->get('/ui/');
        self::assertSame([303, '/ui/login?why=signed_out'], [$dead->status, $dead->location()], 'the copied cookie died with the change');
        self::assertStringContainsString('cw_session=;', (string) $dead->setCookie('cw_session'));
        self::assertStringContainsString('Max-Age=0', (string) $dead->setCookie('cw_session'), 'and is cleared');
        self::assertSame(200, $web->get('/ui/')->status);

        // Signing out ends the session and clears the cookie, whatever the limits.
        $kept = $web->cookies['cw_session'];
        $out = $web->post('/ui/logout', ['csrf' => $this->token($web)]);
        self::assertSame([303, '/ui/login'], [$out->status, $out->location()]);
        self::assertMatchesRegularExpression('/^cw_session=; Path=\/ui; HttpOnly; SameSite=Strict; Max-Age=0$/D', (string) $out->setCookie('cw_session'));
        self::assertArrayNotHasKey('cw_session', $web->cookies);
        self::assertSame(1, self::revoked($kept));
        $thief = $this->browser();
        $thief->cookies['cw_session'] = $kept;
        self::assertSame('/ui/login?why=signed_out', $thief->get('/ui/')->location(), 'the token, replayed, is dead');
    }

    public function testFinishingTheSignInSetUpGivesTheSameLongLivedCookie(): void
    {
        $this->store(self::ABSOLUTE, '720'); // 30 days
        $admin = $this->uiUser('admin');
        $made = (new StaffAdmin(self::$appDb))->enrol(Caller::staff($admin['id']), 'setup@test.example', ['buyer'], self::$box, 'Set Up');
        parse_str((string) parse_url($made['otpauth'], PHP_URL_QUERY), $q);
        $en = new Enrolment(self::$appDb, new LoginLimiter(self::$appDb), self::$box);
        $start = $en->start('setup@test.example', $made['setup_code'], Totp::code((string) $q['secret']), '198.51.100.31', 'Phone');
        self::assertSame('next', $start['status']);
        $p = $en->pending($start['token']);
        self::assertNotNull($p);

        $web = $this->browser('198.51.100.31');
        $web->cookies[\CW\Ui\Kernel::SETUP_COOKIE] = (string) $start['token'];
        $page = $web->get('/ui/new-code');
        self::assertSame(200, $page->status, $page->describe());
        $r = $web->post('/ui/new-code', ['csrf' => $page->form('/ui/new-code')['csrf'], 'code' => Totp::code($p['secret']),
            'new' => 'my own passphrase 2026', 'again' => 'my own passphrase 2026']);
        self::assertSame(303, $r->status, $r->describe());
        self::assertStringContainsString('; Max-Age=2592000', (string) $r->setCookie('cw_session'), '30 days in seconds');
        self::assertSame(200, $web->get('/ui/')->status);
    }

    public function testTheSignedInDevicesAndSigningOutEverywhereFollowTheLimits(): void
    {
        $admin = $this->uiUser('admin');
        $p = $this->uiUser('buyer');
        $web = $this->signIn($p);
        $id = hash('sha256', $web->cookies['cw_session']);
        self::$db->exec('UPDATE staff_session SET last_seen_at = NOW(6) - INTERVAL 31 MINUTE WHERE id = ?', [$id]);
        self::assertSame([], StaffSessions::live(self::$db, $p['id']), 'away for 31 minutes: not signed in (the default)');

        $this->store(self::IDLE, '60');
        self::assertCount(1, StaffSessions::live(self::$db, $p['id']), 'with an hour, still signed in');
        $people = $this->signIn($admin)->get('/ui/people/' . $p['id']);
        self::assertSame(200, $people->status, $people->describe());
        self::assertStringNotContainsString(Words::STAFF['person_devices_none'], $people->text(), 'their page lists the device');
        self::$db->exec('UPDATE staff_session SET created_at = NOW(6) - INTERVAL 13 HOUR WHERE id = ?', [$id]);
        self::assertSame([], StaffSessions::live(self::$db, $p['id']), 'older than the absolute limit');
        self::$db->exec('UPDATE staff_session SET created_at = NOW(6) - INTERVAL 31 MINUTE WHERE id = ?', [$id]);

        // Signing them out everywhere counts the session as signed out (it was live under the setting) and ends it.
        self::assertSame(1, (new StaffAdmin(self::$appDb))->signOut(Caller::staff($admin['id']), $p['id'], null));
        self::assertSame(1, (int) self::$db->value('SELECT revoked FROM staff_session WHERE id = ?', [$id]));
        self::assertSame('/ui/login?why=signed_out', $web->get('/ui/')->location());
    }

    public function testTheSettingsRefuseValuesOutOfRangeAndTheSettingsPageShowsThemInWords(): void
    {
        self::assertSame(['min' => 30, 'max' => 43200], Settings::RULES[self::IDLE], '30 minutes to 30 days');
        self::assertSame(['min' => 12, 'max' => 2160], Settings::RULES[self::ABSOLUTE], '12 hours to 90 days');
        self::assertSame([Sessions::IDLE_SECONDS, Sessions::ABSOLUTE_SECONDS], [Settings::RULES[self::IDLE]['min'] * 60, Settings::RULES[self::ABSOLUTE]['min'] * 3600],
            'the shortest each may be is the limit of before: never shorter');
        $settings = new Settings(self::$db);
        foreach ([[self::IDLE, 29], [self::IDLE, 43201], [self::IDLE, 0], [self::IDLE, -30], [self::ABSOLUTE, 11], [self::ABSOLUTE, 2161], [self::ABSOLUTE, 0]] as [$key, $value]) {
            try {
                $settings->checkRule($key, $value);
                self::fail("{$key} = {$value} is refused");
            } catch (CwException $e) {
                self::assertSame('bad_value', $e->errorCode, "{$key} = {$value}");
            }
        }
        foreach ([[self::IDLE, 30], [self::IDLE, 43200], [self::ABSOLUTE, 12], [self::ABSOLUTE, 2160]] as [$key, $value]) {
            $settings->checkRule($key, $value);
        }

        // The Settings page: both under Staff, with their names and what they do; a value out of range is refused and nothing saved.
        $owner = $this->signIn($this->uiUser('reviewer'));
        $list = $owner->get('/ui/reference/settings');
        self::assertSame(200, $list->status, $list->describe());
        foreach ([self::IDLE, self::ABSOLUTE] as $key) {
            self::assertSame('Staff', Words::settingTopic($key));
            self::assertStringContainsString(Words::settingName($key), $list->text(), $key);
            self::assertContains('/ui/reference/settings/setting?key=' . $key, $list->hrefs());
            $one = $owner->get('/ui/reference/settings/setting', ['key' => $key]);
            self::assertSame(200, $one->status, $one->describe());
            self::assertStringContainsString(Words::SETTING_HELP[$key], $one->text());
            self::assertDoesNotMatchRegularExpression('/\b(30|12)\b/', Words::SETTING[$key] . Words::SETTING_HELP[$key], "{$key}: the words never repeat the default");
        }
        $form = $owner->get('/ui/reference/settings/setting', ['key' => self::IDLE])->form('/ui/reference/settings/setting');
        $refused = $owner->post('/ui/reference/settings/setting', ['value' => '29', 'reason' => 'shorter than the default'] + $form);
        self::assertSame(400, $refused->status, $refused->describe());
        self::assertSame('30', (string) self::$db->value('SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ?', [self::IDLE]), 'nothing saved');
        $refused = $owner->post('/ui/reference/settings/setting', ['value' => '43201', 'reason' => 'longer than the most'] + $form);
        self::assertSame(400, $refused->status, $refused->describe());

        // Raised on the page with a reason, it applies to the next request of everyone signed in (this browser included).
        $saved = $owner->post('/ui/reference/settings/setting', ['value' => '43200', 'reason' => 'the owner: once signed in, stay signed in'] + $form);
        self::assertSame(303, $saved->status, $saved->describe());
        self::assertSame(2592000, (new Sessions(self::$appDb))->limits()['idle']);
        self::assertSame(2, (int) self::$db->value("SELECT MAX(version) FROM config_change WHERE subject_type = 'setting' AND subject_key = ?", [self::IDLE]));
        self::assertSame(200, $this->after($owner, 120, 120)->status, 'two hours away: still in');
    }

    public function testTheSignedOutWordsHoldForAnySetting(): void
    {
        self::assertDoesNotMatchRegularExpression('/\d/', Words::UI['signed_out'], 'no fixed number: the limits are settings');
        $this->store(self::IDLE, '90');
        $u = $this->uiUser('viewer');
        $web = $this->signIn($u);
        $r = $this->after($web, 91, 91);
        self::assertSame('/ui/login?why=signed_out', $r->location());
        self::assertStringContainsString(Words::UI['signed_out'], $web->follow($r)->text());
    }
}
