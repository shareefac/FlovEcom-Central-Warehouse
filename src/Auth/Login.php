<?php

declare(strict_types=1);

namespace CW\Auth;

use CW\Audit;
use CW\Caller;
use CW\Db;
use CW\Staff\SecretBox;
use CW\Staff\Totp;

/**
 * The staff sign-in (plan §11): e-mail + password + a 6-digit TOTP code, all three in one step, so a
 * wrong answer never says which factor failed (nor whether the account exists).
 *
 *   1. throttle: refused while the account has 10 (or the address 30) failures in 15 minutes; steps 1-3
 *      and the record of the outcome run under the account's and the address's named locks
 *      (LoginLimiter::exclusive), so parallel attempts cannot all pass the count before any is charged
 *   2. password: argon2id verify; an unknown account is verified against a throw-away hash so the
 *      answer takes as long
 *   3. TOTP: RFC 6238, +-1 step, constant-time compare, and a code is accepted once (the step is
 *      stored in staff_user.totp_last_step with a conditional UPDATE, so two parallel logins with the
 *      same code cannot both win)
 *   4. a NEW session (the old cookie's session is revoked: rotation), audit_log `login.ok`
 * Every failure is a login_attempt row (feeds the throttle) and an audit_log `login.fail` row.
 */
final class Login
{
    public const MIN_PASSWORD = 12;
    public const MAX_PASSWORD = 200;

    public function __construct(
        private readonly Db $db,
        private readonly Sessions $sessions,
        private readonly LoginLimiter $limiter,
        private readonly SecretBox $box,
    ) {
    }

    /**
     * @return array{status: 'ok'|'invalid'|'locked', token: ?string, must_change: bool}
     */
    public function attempt(
        string $email,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $code,
        string $ip,
        ?string $userAgent,
        ?string $oldToken,
    ): array {
        $login = self::normalise($email);
        $locked = ['status' => 'locked', 'token' => null, 'must_change' => false];
        return $this->limiter->exclusive($login, $ip, fn (): array => $this->attemptLocked($login, $password, $code, $ip, $userAgent, $oldToken)) ?? $locked;
    }

    /**
     * attempt() under the limiter's locks.
     *
     * @return array{status: 'ok'|'invalid'|'locked', token: ?string, must_change: bool}
     */
    private function attemptLocked(
        ?string $login,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $code,
        string $ip,
        ?string $userAgent,
        ?string $oldToken,
    ): array {
        if ($this->limiter->lockedBy($login, $ip) !== null) {
            return ['status' => 'locked', 'token' => null, 'must_change' => false];
        }
        $user = $login === null ? null : $this->db->one(
            'SELECT id, password_hash, password_must_change, totp_secret_enc, is_active FROM staff_user WHERE email = ?',
            [$login],
        );
        if ($user === null) {
            self::burn($password); // an unknown account costs the same time as a wrong password
            return $this->fail($login, null, $ip, 'password');
        }
        $id = (int) $user['id'];
        $passwordOk = password_verify($password, (string) $user['password_hash']);
        if (!$passwordOk || (int) $user['is_active'] !== 1 || $user['totp_secret_enc'] === null) {
            return $this->fail($login, $id, $ip, 'password');
        }
        $secret = $this->box->decrypt((string) $user['totp_secret_enc']);
        $last = $this->db->value('SELECT totp_last_step FROM staff_user WHERE id = ?', [$id]);
        $step = Totp::verify($secret, $code, $last === null ? null : (int) $last);
        if ($step === null) {
            return $this->fail($login, $id, $ip, 'totp');
        }
        $claimed = $this->db->exec(
            'UPDATE staff_user SET totp_last_step = ?, last_login_at = NOW(6) WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
            [$step, $id, $step],
        );
        if ($claimed !== 1) {
            return $this->fail($login, $id, $ip, 'totp'); // the same code, used a moment ago
        }
        $this->sessions->revokeToken($oldToken);
        $session = $this->sessions->create($id, $ip, $userAgent);
        $this->limiter->record($login, $id, $ip, 'totp', true);
        Audit::write($this->db, Caller::staff($id, $ip), 'login.ok', 'staff_user', (string) $id, null, ['session' => substr($session['id'], 0, 8)]);
        return ['status' => 'ok', 'token' => $session['token'], 'must_change' => (int) $user['password_must_change'] === 1];
    }

    /**
     * Replaces the password of a signed-in person (the current one is asked again). EVERY session of the
     * person ends, the one the change was made from included, and a new session is issued for it: a
     * copied session cookie (the usual reason to change a password) and the session opened with a
     * one-time password both die here. Failures count towards the same throttle as sign-in (and run under
     * its locks).
     *
     * @return array{status: 'ok'|'wrong_current'|'locked', token: ?string}
     */
    public function changePassword(
        int $staffUserId,
        string $currentSessionId,
        string $ip,
        ?string $userAgent,
        #[\SensitiveParameter] string $current,
        #[\SensitiveParameter] string $new,
    ): array {
        $u = $this->db->one('SELECT email, password_hash FROM staff_user WHERE id = ? AND is_active = 1', [$staffUserId]);
        if ($u === null) {
            return ['status' => 'wrong_current', 'token' => null];
        }
        $login = self::normalise((string) $u['email']);
        $checked = $this->limiter->exclusive($login, $ip, function () use ($login, $staffUserId, $ip, $current, $u): string {
            if ($this->limiter->lockedBy($login, $ip) !== null) {
                return 'locked';
            }
            if (!password_verify($current, (string) $u['password_hash'])) {
                $this->limiter->record($login, $staffUserId, $ip, 'password', false);
                Audit::write($this->db, Caller::staff($staffUserId, $ip), 'password.fail', 'staff_user', (string) $staffUserId, null, []);
                return 'wrong_current';
            }
            return 'ok';
        }) ?? 'locked';
        if ($checked !== 'ok') {
            return ['status' => $checked, 'token' => null];
        }
        $hash = password_hash($new, PASSWORD_ARGON2ID);
        $session = $this->db->transaction(function (Db $db) use ($staffUserId, $currentSessionId, $ip, $userAgent, $hash): array {
            $db->exec('UPDATE staff_user SET password_hash = ?, password_must_change = 0 WHERE id = ?', [$hash, $staffUserId]);
            $ended = $this->sessions->revokeAll($staffUserId);
            $session = $this->sessions->create($staffUserId, $ip, $userAgent);
            Audit::write($db, Caller::staff($staffUserId, $ip), 'password.change', 'staff_user', (string) $staffUserId, null, [
                'sessions_ended' => $ended, 'rotated' => true, 'from_session' => substr($currentSessionId, 0, 8), 'session' => substr($session['id'], 0, 8),
            ]);
            return $session;
        });
        return ['status' => 'ok', 'token' => $session['token']];
    }

    /** Signs the session out (audit `logout`). */
    public function logout(StaffIdentity $who, string $ip): void
    {
        $this->sessions->revoke($who->sessionId);
        Audit::write($this->db, Caller::staff($who->id, $ip), 'logout', 'staff_user', (string) $who->id, null, []);
    }

    /** The typed login as stored in login_attempt: a lower-case e-mail, or null (never free text: it might be a password). */
    public static function normalise(string $email): ?string
    {
        $e = strtolower(trim($email));
        return $e !== '' && strlen($e) <= 191 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false ? $e : null;
    }

    /** @return array{status: 'invalid', token: null, must_change: false} */
    private function fail(?string $login, ?int $staffUserId, string $ip, string $step): array
    {
        $this->limiter->record($login, $staffUserId, $ip, $step, false);
        $caller = $staffUserId !== null ? Caller::staff($staffUserId, $ip) : Caller::system('ui_login', $ip);
        Audit::write($this->db, $caller, 'login.fail', 'staff_user', $staffUserId === null ? null : (string) $staffUserId, null,
            ['step' => $step, 'login' => $login, 'known' => $staffUserId !== null]);
        return ['status' => 'invalid', 'token' => null, 'must_change' => false];
    }

    /** One argon2id hash: the time an unknown account must not save. */
    private static function burn(#[\SensitiveParameter] string $password): void
    {
        password_hash($password, PASSWORD_ARGON2ID);
    }
}
