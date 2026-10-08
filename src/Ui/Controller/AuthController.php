<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Auth\Login;
use CW\Auth\LoginLimiter;
use CW\Auth\Sessions;
use CW\Staff\Enrolment;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Kernel;
use CW\Ui\Words;

/**
 * /ui/login, /ui/logout, /ui/password.
 *
 * Behaviour items 1-3 of plan §8.6 (provisional, docs/decisions.md): the sign-in page carries the page to return to (`back`, a
 * safe local page path only: safeBack) and leads there after the sign-in; it says when a form sent while signed out was lost
 * (`why=lost`); GET /ui/logout (the address opened from the history) leads to the sign-in page and signs nobody out; a sign-in
 * form older than its cookie is shown again with a plain word (expired()), not an error page.
 */
final class AuthController
{
    private const MAX_TYPED_PASSWORD = 1024;
    /** The longest page path carried through the sign-in. */
    private const MAX_BACK = 512;
    /** Addresses never returned to: the sign-in pages themselves, and downloads (a file, not a page to land on). */
    private const NO_BACK = '#^/ui/(login|logout|password|enrol)(/|\?|$)|^/ui/(files|assets)/|(\.(csv|pdf|xlsx|css|js)|/pdf)(\?|$)#';

    public function loginForm(Context $ctx): HtmlResponse
    {
        $why = $ctx->req->param('why');
        return $this->form($ctx, '', null, 200, $why === 'signed_out' || $why === 'lost' ? $why : null, self::safeBack($ctx->req->param('back')));
    }

    /** GET /ui/logout signed out (signed in, the kernel sends the person Home): the sign-in page (F043). Nobody is signed out by a GET. */
    public function signedOut(Context $ctx): HtmlResponse
    {
        return HtmlResponse::redirect('/ui/login');
    }

    /**
     * The sign-in form sent after its cookie expired (or without it): the form again with "This page was open too long" and the
     * e-mail kept (F059). 403 like any refused token, and no sign-in attempt is made or counted.
     */
    public function expired(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        return $this->form($ctx, $req->field('email') ?? '', Words::SIGN_IN['expired'], 403, null, self::safeBack($req->field('back')));
    }

    /**
     * $path when it is a page of these screens a person may be sent back to after signing in (behaviour item 1): a local
     * `/ui/...` path with an optional query, of plain URL characters only (no `//`, no backslash, no scheme, no line breaks),
     * at most 512 characters; not Home (the default anyway), not the sign-in, sign-out or password pages, not a download.
     * Anything else is null: the person lands on Home.
     */
    public static function safeBack(?string $path): ?string
    {
        if ($path === null || $path === '' || strlen($path) > self::MAX_BACK) {
            return null;
        }
        if (preg_match('#^/ui/[A-Za-z0-9._~/-]*(\?[A-Za-z0-9._~%&=+-]*)?$#D', $path) !== 1 || str_contains($path, '//') || str_contains($path, '/.')) {
            return null;
        }
        if (preg_match('#^/ui/?(\?|$)#', $path) === 1 || preg_match(self::NO_BACK, $path) === 1) {
            return null;
        }
        return $path;
    }

    public function login(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $email = $req->field('email') ?? '';
        $password = $req->field('password') ?? '';
        $code = preg_replace('/\s+/', '', $req->field('code') ?? '') ?? '';
        if (strlen($password) > self::MAX_TYPED_PASSWORD || strlen($email) > 320 || strlen($code) > 16) {
            $password = ''; // cannot be a real answer: counted as a failed attempt below
        }
        $result = $this->service($ctx)->attempt($email, $password, $code, $req->ip, $req->header('user-agent'), $req->cookie(Kernel::SESSION_COOKIE));
        $back = self::safeBack($req->field('back'));
        if ($result['status'] === 'ok' && $result['token'] !== null) {
            // Back to the page that was asked for (behaviour item 1); a forced password change comes first.
            return HtmlResponse::redirect($result['must_change'] ? '/ui/password' : ($back ?? '/ui/'))
                ->withCookie(Kernel::SESSION_COOKIE, $result['token'], $req->secure)
                ->withoutCookie(Kernel::PRE_COOKIE, $req->secure);
        }
        if ($result['status'] === 'locked') {
            return $this->form($ctx, $email, Words::SIGN_IN['locked'], 429, null, $back)->withHeader('Retry-After', (string) LoginLimiter::WINDOW_SECONDS);
        }
        return $this->form($ctx, $email, Words::SIGN_IN['failed'], 401, null, $back);
    }

    public function logout(Context $ctx): HtmlResponse
    {
        $this->service($ctx)->logout($ctx->me(), $ctx->req->ip);
        return HtmlResponse::redirect('/ui/login')->withoutCookie(Kernel::SESSION_COOKIE, $ctx->req->secure);
    }

    public function passwordForm(Context $ctx): HtmlResponse
    {
        return $ctx->page('password', ['error' => null], 200, ['title' => Words::title('password'), 'active' => 'password']);
    }

    public function password(Context $ctx): HtmlResponse
    {
        $me = $ctx->me();
        $req = $ctx->req;
        $current = $req->field('current') ?? '';
        $new = $req->field('new') ?? '';
        $again = $req->field('again') ?? '';
        $error = null;
        if (strlen($current) > self::MAX_TYPED_PASSWORD) {
            $error = Words::SIGN_IN['wrong_current'];
        } elseif (mb_strlen($new) < Login::MIN_PASSWORD) {
            $error = sprintf(Words::SIGN_IN['too_short'], Login::MIN_PASSWORD);
        } elseif (mb_strlen($new) > Login::MAX_PASSWORD) {
            $error = sprintf(Words::SIGN_IN['too_long'], Login::MAX_PASSWORD);
        } elseif ($new !== $again) {
            $error = Words::SIGN_IN['mismatch'];
        } elseif ($new === $current) {
            $error = Words::SIGN_IN['unchanged'];
        } elseif (strcasecmp($new, $me->email) === 0) {
            $error = Words::SIGN_IN['email'];
        }
        if ($error === null) {
            $outcome = $this->service($ctx)->changePassword($me->id, $me->sessionId, $req->ip, $req->header('user-agent'), $current, $new);
            if ($outcome['status'] === 'ok' && $outcome['token'] !== null) {
                // Every session of the person ended, this one too: this browser continues on a new one.
                return HtmlResponse::redirect('/ui/?notice=password_changed')
                    ->withCookie(Kernel::SESSION_COOKIE, $outcome['token'], $req->secure);
            }
            if ($outcome['status'] === 'locked') {
                return $ctx->page('password', ['error' => Words::SIGN_IN['locked']], 429,
                    ['title' => Words::title('password'), 'active' => 'password'])->withHeader('Retry-After', (string) LoginLimiter::WINDOW_SECONDS);
            }
            $error = Words::SIGN_IN['wrong_current'];
        }
        return $ctx->page('password', ['error' => $error], 422, ['title' => Words::title('password'), 'active' => 'password']);
    }

    /** /ui/enrol (G06, docs/decisions.md Y20, Y22): a person sets their own password with their e-mail and their phone's code. */
    public function enrolForm(Context $ctx): HtmlResponse
    {
        return $this->enrolPage($ctx, '', null, 200);
    }

    /**
     * Sets the password and signs the person in (Staff\Enrolment: the throttle and the code as at the sign-in; one answer for every
     * failure). The new password's own rules are checked first (no attempt is counted for a typo of the password).
     */
    public function enrol(Context $ctx): HtmlResponse
    {
        $req = $ctx->req;
        $email = mb_strcut((string) ($req->field('email') ?? ''), 0, 320, 'UTF-8');
        $code = preg_replace('/\s+/', '', $req->field('code') ?? '') ?? '';
        $new = $req->field('new') ?? '';
        $again = $req->field('again') ?? '';
        $error = null;
        if (mb_strlen($new) < Login::MIN_PASSWORD) {
            $error = sprintf(Words::SIGN_IN['too_short'], Login::MIN_PASSWORD);
        } elseif (mb_strlen($new) > Login::MAX_PASSWORD) {
            $error = sprintf(Words::SIGN_IN['too_long'], Login::MAX_PASSWORD);
        } elseif ($new !== $again) {
            $error = Words::SIGN_IN['mismatch'];
        } elseif (strcasecmp($new, trim($email)) === 0) {
            $error = Words::SIGN_IN['email'];
        }
        if ($error !== null) {
            return $this->enrolPage($ctx, $email, $error, 422);
        }
        if (strlen($code) > 16) {
            $code = '';
        }
        $result = (new Enrolment($ctx->db, new LoginLimiter($ctx->db), $ctx->secretBox()))
            ->complete($email, $code, $new, $req->ip, $req->header('user-agent'), $req->cookie(Kernel::SESSION_COOKIE));
        if ($result['status'] === 'ok' && $result['token'] !== null) {
            return HtmlResponse::redirect('/ui/')->withCookie(Kernel::SESSION_COOKIE, $result['token'], $req->secure)->withoutCookie(Kernel::PRE_COOKIE, $req->secure);
        }
        if ($result['status'] === 'locked') {
            return $this->enrolPage($ctx, $email, Words::SIGN_IN['locked'], 429)->withHeader('Retry-After', (string) LoginLimiter::WINDOW_SECONDS);
        }
        return $this->enrolPage($ctx, $email, Words::ENROL['failed'], 401);
    }

    private function enrolPage(Context $ctx, string $email, ?string $error, int $status): HtmlResponse
    {
        $pre = $ctx->pre;
        $fresh = $pre === null;
        $pre ??= rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $res = $ctx->page('enrol', ['error' => $error, 'email' => mb_strcut($email, 0, 191, 'UTF-8'), 'csrf' => $ctx->csrf->forPre($pre), 'min' => Login::MIN_PASSWORD],
            $status, ['title' => Words::title('enrol')]);
        return $fresh ? $res->withCookie(Kernel::PRE_COOKIE, $pre, $ctx->req->secure, 3600) : $res;
    }

    private function service(Context $ctx): Login
    {
        return new Login($ctx->db, new Sessions($ctx->db), new LoginLimiter($ctx->db), $ctx->secretBox());
    }

    /**
     * The sign-in page. $why: 'signed_out' (a session ended: say why) or 'lost' (a form sent while signed out was not saved);
     * $back: the safe page to return to after the sign-in (a hidden field of the form).
     */
    private function form(Context $ctx, string $email, ?string $error, int $status, ?string $why = null, ?string $back = null): HtmlResponse
    {
        $req = $ctx->req;
        $pre = $ctx->pre;
        $fresh = $pre === null;
        $pre ??= rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $email = mb_strcut($email, 0, 191, 'UTF-8');
        $res = $ctx->page('login', ['error' => $error, 'email' => $email, 'csrf' => $ctx->csrf->forPre($pre), 'signedOut' => $why === 'signed_out',
            'lost' => $why === 'lost', 'back' => $back, 'expired' => $error === Words::SIGN_IN['expired']],
            $status, ['title' => Words::title('login')]);
        return $fresh ? $res->withCookie(Kernel::PRE_COOKIE, $pre, $req->secure, 3600) : $res;
    }
}
