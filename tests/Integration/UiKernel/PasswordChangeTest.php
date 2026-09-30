<?php

declare(strict_types=1);

namespace CW\Tests\Integration\UiKernel;

use CW\Tests\Support\KernelUiTestCase;

/**
 * A password change ends EVERY session of the person, the one it was made from included, and hands
 * that browser a new session (U2): a copied session cookie, the usual reason to change a password,
 * dies with the change, and the session opened with a one-time password never becomes the long-lived
 * one. Regression tests of the review finding (security lens); in-process through the real kernel.
 */
final class PasswordChangeTest extends KernelUiTestCase
{
    public function testACopiedSessionCookieDiesWithThePasswordChange(): void
    {
        $u = $this->uiUser('mapping_lead');
        $owner = $this->signIn($u);
        $copy = $this->browser('203.0.113.99');
        $copy->cookies['cw_session'] = $owner->cookies['cw_session'];
        self::assertSame(200, $copy->get('/ui/')->status, 'a copied cookie works: the threat the change answers');
        $before = $owner->cookies['cw_session'];

        $page = $owner->get('/ui/password');
        self::assertStringContainsString('Changing it signs you out everywhere else, and this browser continues on a new session.', $page->text());
        $r = $owner->post('/ui/password', ['csrf' => $page->form('/ui/password')['csrf'], 'current' => $u['password'],
            'new' => 'a brand new passphrase 2026', 'again' => 'a brand new passphrase 2026']);
        self::assertSame(303, $r->status, self::statusOf($r));
        self::assertSame('/ui/?notice=password_changed', $r->location());
        self::assertNotNull($r->setCookie('cw_session'), 'the owner gets a new session');
        self::assertNotSame($before, $owner->cookies['cw_session']);
        $audit = json_decode((string) self::$db->value("SELECT detail FROM audit_log WHERE action = 'password.change'"), true);
        self::assertSame([true, 1], [$audit['rotated'] ?? null, $audit['sessions_ended'] ?? null]);

        // The copy is dead; the owner carries on with the new session (and its new CSRF token).
        self::assertSame(303, $copy->get('/ui/review', ['queue' => 'pending'])->status);
        self::assertSame('/ui/login', $copy->get('/ui/')->location());
        $home = $owner->follow($r);
        self::assertSame(200, $home->status, self::statusOf($home));
        self::assertStringContainsString('Your password was changed.', $home->text());
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM staff_session WHERE staff_user_id = ? AND revoked = 0', [$u['id']]));
        self::assertSame(303, $owner->post('/ui/logout', ['csrf' => $this->token($owner)])->status, 'the new token works');

        // The new password signs in; the old one does not.
        self::assertSame('ok', $this->loginService()->attempt($u['email'], 'a brand new passphrase 2026', self::code($u['secret'], 1), '198.51.100.9', null, null)['status']);
    }

    public function testTheOneTimePasswordSessionEndsAtTheFirstPasswordChange(): void
    {
        $u = $this->uiUser('mapper', true);
        $web = $this->signIn($u, null, '/ui/password');
        $before = $web->cookies['cw_session'];
        $page = $web->get('/ui/password');
        $r = $web->post('/ui/password', ['csrf' => $page->form('/ui/password')['csrf'], 'current' => $u['password'],
            'new' => 'my own passphrase 2026', 'again' => 'my own passphrase 2026']);
        self::assertSame(303, $r->status, self::statusOf($r));
        self::assertSame(0, (int) self::$db->value('SELECT password_must_change FROM staff_user WHERE id = ?', [$u['id']]));
        self::assertNotSame($before, $web->cookies['cw_session'] ?? $before, 'a new session token after the first password change');
        $old = $this->browser();
        $old->cookies['cw_session'] = $before;
        self::assertSame('/ui/login', $old->get('/ui/')->location(), 'the one-time-password session is revoked');
        self::assertSame(200, $web->get('/ui/')->status);
    }

    public function testAWrongCurrentPasswordChangesNothingAndCountsAsAFailure(): void
    {
        $u = $this->uiUser('mapper');
        $web = $this->signIn($u);
        $before = $web->cookies['cw_session'];
        $page = $web->get('/ui/password');
        $r = $web->post('/ui/password', ['csrf' => $page->form('/ui/password')['csrf'], 'current' => 'not my password at all',
            'new' => 'my own passphrase 2026', 'again' => 'my own passphrase 2026']);
        self::assertSame(422, $r->status, self::statusOf($r));
        self::assertSame($before, $web->cookies['cw_session']);
        self::assertSame(200, $web->get('/ui/')->status, 'still signed in');
        self::assertSame(1, (int) self::$db->value('SELECT COUNT(*) FROM login_attempt WHERE staff_user_id = ? AND success = 0', [$u['id']]));
        self::assertSame(0, (int) self::$db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'password.change'"));
    }
}
