<?php

declare(strict_types=1);

namespace CW\Ui\Controller;

use CW\Auth\Login;
use CW\Auth\LoginLimiter;
use CW\Auth\Sessions;
use CW\Ui\Context;
use CW\Ui\HtmlResponse;
use CW\Ui\Kernel;

/** /ui/login, /ui/logout, /ui/password. */
final class AuthController
{
    private const MAX_TYPED_PASSWORD = 1024;

    public function loginForm(Context $ctx): HtmlResponse
    {
        return $this->form($ctx, '', null, 200);
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
        if ($result['status'] === 'ok' && $result['token'] !== null) {
            return HtmlResponse::redirect($result['must_change'] ? '/ui/password' : '/ui/')
                ->withCookie(Kernel::SESSION_COOKIE, $result['token'], $req->secure)
                ->withoutCookie(Kernel::PRE_COOKIE, $req->secure);
        }
        if ($result['status'] === 'locked') {
            return $this->form($ctx, $email, 'Too many failed attempts. Wait 15 minutes, then try again.', 429)->withHeader('Retry-After', (string) LoginLimiter::WINDOW_SECONDS);
        }
        return $this->form($ctx, $email, 'That sign-in did not work. Check the e-mail address, the password and the current 6-digit code.', 401);
    }

    public function logout(Context $ctx): HtmlResponse
    {
        $this->service($ctx)->logout($ctx->me(), $ctx->req->ip);
        return HtmlResponse::redirect('/ui/login')->withoutCookie(Kernel::SESSION_COOKIE, $ctx->req->secure);
    }

    public function passwordForm(Context $ctx): HtmlResponse
    {
        return $ctx->page('password', ['error' => null], 200, ['title' => 'Change password', 'active' => 'password']);
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
            $error = 'The current password is not right.';
        } elseif (mb_strlen($new) < Login::MIN_PASSWORD || mb_strlen($new) > Login::MAX_PASSWORD) {
            $error = 'The new password must be ' . Login::MIN_PASSWORD . ' to ' . Login::MAX_PASSWORD . ' characters long.';
        } elseif ($new !== $again) {
            $error = 'The two new passwords are not the same.';
        } elseif ($new === $current) {
            $error = 'The new password must differ from the current one.';
        } elseif (strcasecmp($new, $me->email) === 0) {
            $error = 'The password cannot be your e-mail address.';
        }
        if ($error === null) {
            $outcome = $this->service($ctx)->changePassword($me->id, $me->sessionId, $req->ip, $req->header('user-agent'), $current, $new);
            if ($outcome['status'] === 'ok' && $outcome['token'] !== null) {
                // Every session of the person ended, this one too: this browser continues on a new one.
                return HtmlResponse::redirect('/ui/?notice=password_changed')
                    ->withCookie(Kernel::SESSION_COOKIE, $outcome['token'], $req->secure);
            }
            if ($outcome['status'] === 'locked') {
                return $ctx->page('password', ['error' => 'Too many failed attempts. Wait 15 minutes, then try again.'], 429,
                    ['title' => 'Change password', 'active' => 'password'])->withHeader('Retry-After', (string) LoginLimiter::WINDOW_SECONDS);
            }
            $error = 'The current password is not right.';
        }
        return $ctx->page('password', ['error' => $error], 422, ['title' => 'Change password', 'active' => 'password']);
    }

    private function service(Context $ctx): Login
    {
        return new Login($ctx->db, new Sessions($ctx->db), new LoginLimiter($ctx->db), $ctx->secretBox());
    }

    private function form(Context $ctx, string $email, ?string $error, int $status): HtmlResponse
    {
        $req = $ctx->req;
        $pre = $ctx->pre;
        $fresh = $pre === null;
        $pre ??= rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $res = $ctx->page('login', ['error' => $error, 'email' => substr($email, 0, 191), 'csrf' => $ctx->csrf->forPre($pre)], $status, ['title' => 'Sign in']);
        return $fresh ? $res->withCookie(Kernel::PRE_COOKIE, $pre, $req->secure, 3600) : $res;
    }
}
