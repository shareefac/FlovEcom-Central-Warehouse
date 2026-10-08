<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\Audit;
use CW\Auth\Login;
use CW\Auth\LoginLimiter;
use CW\Auth\Sessions;
use CW\Caller;
use CW\Db;
use CW\Settings;

/**
 * A person sets up their own sign-in (/ui/enrol and /ui/new-code; docs/decisions.md Y20-Y24 as amended by Y40-Y43, review finding
 * B1). Whatever an admin saw on a sheet works once, and never gives a session by itself:
 *
 *  1. start() (/ui/enrol): someone the admin added (a sign-up sheet: QR code and set-up code), or told to choose a new password
 *     (a sheet with a set-up code only), types their e-mail, the one-time set-up code and the 6 numbers of their code app. While
 *     the set-up window is open (setup_until), and never for a "new sign-in code" (totp_state `reset`: that one works only at the
 *     sign-in, with the current password), this proves who they are. The set-up code is used up, the window closes, and a sign-up
 *     sheet's secret dies (an admin saw it). A FRESH secret is made and waits for this browser only (totp_next_*, its step token
 *     in a cookie, NEXT_MINUTES).
 *  2. Login::attempt starts the same step for a "new sign-in code": the CURRENT password and the 6 numbers of that sheet (begin()).
 *  3. pending() shows the fresh secret on the person's own page (QR code and key; every page is no-store); confirm() takes one code
 *     of it (and, after /ui/enrol, the new password). Only then does the account use it (totp_state `own`) and a session start:
 *     every earlier session of the person ends.
 *
 * The same protection as the sign-in (Login): the throttle per account and per address under the limiter's locks (a wrong answer
 * counts as a failed sign-in), a code accepted once (totp_last_step), one answer for every failure (an unknown e-mail, an account
 * switched off or not waiting to set up, an expired window, a wrong set-up code, a wrong code). On top of it each set-up window and
 * each fresh secret takes at most staff.setup_max_fails wrong tries (review finding I5): then it closes (setup_closed_at, audit
 * staff.setup_closed) and Home tells the admins and reviewers; the admin makes a new sheet. Audited staff.setup_start,
 * staff.setup / staff.new_code (with the address), staff.setup_fail.
 */
final class Enrolment
{
    /** How long the fresh secret waits on the person's page for its first code. */
    public const NEXT_MINUTES = 15;
    /** The default of staff.setup_max_fails. */
    public const MAX_FAILS = 5;

    public function __construct(private readonly Db $db, private readonly LoginLimiter $limiter, private readonly SecretBox $box)
    {
    }

    /**
     * Step 1 at /ui/enrol. `next`: the fresh secret waits; `token` is the browser's step token (its cookie).
     *
     * @return array{status: 'next'|'invalid'|'locked', token: ?string}
     */
    public function start(string $email, #[\SensitiveParameter] string $setupCode, #[\SensitiveParameter] string $code, string $ip, ?string $userAgent): array
    {
        $login = Login::normalise($email);
        $locked = ['status' => 'locked', 'token' => null];
        return $this->limiter->exclusive($login, $ip, function () use ($login, $setupCode, $code, $ip): array {
            if ($this->limiter->lockedBy($login, $ip) !== null) {
                return ['status' => 'locked', 'token' => null];
            }
            $u = $login === null ? null : $this->db->one(
                'SELECT id, is_active, totp_secret_enc, totp_last_step, totp_state, setup_code_hash, (setup_until IS NOT NULL AND setup_until > NOW(6)) AS open '
                . 'FROM staff_user WHERE email = ?',
                [$login],
            );
            $typed = SetupCode::hash($setupCode);
            if ($u === null || (int) $u['is_active'] !== 1 || (int) $u['open'] !== 1 || $u['totp_state'] === 'reset' || $u['totp_secret_enc'] === null
                || $u['setup_code_hash'] === null || $typed === null || !hash_equals((string) $u['setup_code_hash'], $typed)) {
                return $this->failStart($login, $u === null ? null : (int) $u['id'], $ip);
            }
            $id = (int) $u['id'];
            $step = Totp::verify($this->box->decrypt((string) $u['totp_secret_enc']), $code, $u['totp_last_step'] === null ? null : (int) $u['totp_last_step']);
            if ($step === null) {
                return $this->failStart($login, $id, $ip);
            }
            // The set-up code is used up and the window closes; a sign-up sheet's secret dies (an admin saw it). The person's own
            // secret (a forgotten password) stays theirs until the fresh one is confirmed.
            $token = $this->begin($id, 'enrol',
                "setup_until > NOW(6) AND setup_code_hash = ? AND totp_state <> 'reset'", [$typed], $step, Caller::staff($id, $ip),
                ', setup_until = NULL, setup_code_hash = NULL, setup_fails = 0, totp_secret_enc = IF(totp_state = \'signup\', NULL, totp_secret_enc)');
            return $token === null ? $this->failStart($login, $id, $ip) : ['status' => 'next', 'token' => $token];
        }) ?? $locked;
    }

    /**
     * Starts the rotate-and-confirm step for a person who signed in with a "new sign-in code" and their current password (Login, in
     * the limiter's locks): that code's secret dies now. Returns the browser's step token, or null when the code was used meanwhile.
     */
    public function beginFromLogin(int $staffUserId, int $step, string $ip): ?string
    {
        return $this->begin($staffUserId, 'login', "totp_state = 'reset'", [], $step, Caller::staff($staffUserId, $ip), ', totp_secret_enc = NULL');
    }

    /**
     * The fresh secret waiting for this browser's step token (the person's own page): who, which route, the secret and its
     * otpauth:// URI, until when. Null when there is none (another browser's token, an expired or finished step).
     *
     * @return array{id: int, email: string, route: string, secret: string, otpauth: string, until: string, must_change: bool}|null
     */
    public function pending(?string $token): ?array
    {
        $r = $this->row($token);
        if ($r === null) {
            return null;
        }
        $secret = $this->box->decrypt((string) $r['totp_next_enc']);
        return ['id' => (int) $r['id'], 'email' => (string) $r['email'], 'route' => (string) $r['totp_next_route'], 'secret' => $secret,
            'otpauth' => Totp::uri($secret, (string) $r['email']), 'until' => (string) $r['totp_next_until'], 'must_change' => (int) $r['password_must_change'] === 1];
    }

    /**
     * Step 3: one code of the fresh secret (and, after /ui/enrol, the new password: the screen checked its rules) confirms it. `ok`
     * with a new session token; `gone` when the step does not exist (any more); `invalid` for a wrong code (staff.setup_max_fails of
     * them end the step: `gone` from then on); `locked` by the throttle.
     *
     * @return array{status: 'ok'|'invalid'|'locked'|'gone', token: ?string, must_change: bool}
     */
    public function confirm(?string $stepToken, #[\SensitiveParameter] string $code, #[\SensitiveParameter] ?string $newPassword, string $ip, ?string $userAgent,
        ?string $oldToken): array
    {
        $gone = ['status' => 'gone', 'token' => null, 'must_change' => false];
        $first = $this->row($stepToken);
        if ($first === null) {
            return $gone;
        }
        $login = Login::normalise((string) $first['email']);
        return $this->limiter->exclusive($login, $ip, function () use ($login, $stepToken, $code, $newPassword, $ip, $userAgent, $oldToken, $gone): array {
            if ($this->limiter->lockedBy($login, $ip) !== null) {
                return ['status' => 'locked', 'token' => null, 'must_change' => false];
            }
            $r = $this->row($stepToken);
            if ($r === null) {
                return $gone;
            }
            $id = (int) $r['id'];
            $route = (string) $r['totp_next_route'];
            if ($route === 'enrol' && ($newPassword === null || $newPassword === '')) {
                throw new \LogicException('the set-up route needs the new password (the screen checks it first)');
            }
            $step = Totp::verify($this->box->decrypt((string) $r['totp_next_enc']), $code, null);
            if ($step === null) {
                return $this->failNext($login, $id, $ip);
            }
            $hash = $route === 'enrol' ? password_hash((string) $newPassword, PASSWORD_ARGON2ID) : null;
            $tokenHash = (string) $r['totp_next_token'];
            $session = $this->db->transaction(function (Db $db) use ($id, $route, $step, $hash, $tokenHash, $ip, $userAgent, $oldToken): ?array {
                $claimed = $db->exec(
                    "UPDATE staff_user SET totp_secret_enc = totp_next_enc, totp_state = 'own', totp_last_step = ?, last_login_at = NOW(6), "
                    . ($hash === null ? '' : 'password_hash = ?, password_must_change = 0, ')
                    . StaffAdmin::CLOSE_WINDOW . ', setup_closed_at = NULL, ' . StaffAdmin::CLEAR_NEXT
                    . ' WHERE id = ? AND is_active = 1 AND totp_next_token = ? AND totp_next_until > NOW(6)',
                    [$step, ...($hash === null ? [] : [$hash]), $id, $tokenHash],
                );
                if ($claimed !== 1) {
                    return null; // the step ended meanwhile (switched off, a new sheet, its time ran out)
                }
                $sessions = new Sessions($db);
                $sessions->revokeToken($oldToken);
                $ended = $sessions->revokeAll($id);
                $s = $sessions->create($id, $ip, $userAgent);
                Audit::write($db, Caller::staff($id, $ip), $route === 'enrol' ? 'staff.setup' : 'staff.new_code', 'staff_user', (string) $id, null,
                    ['route' => $route, 'sessions_ended' => $ended, 'session' => substr($s['id'], 0, 8)]);
                return $s;
            });
            if ($session === null) {
                return $gone;
            }
            $this->limiter->record($login, $id, $ip, 'totp', true);
            return ['status' => 'ok', 'token' => $session['token'],
                'must_change' => (int) $this->db->value('SELECT password_must_change FROM staff_user WHERE id = ?', [$id]) === 1];
        }) ?? ['status' => 'locked', 'token' => null, 'must_change' => false];
    }

    /** The cap on wrong tries (staff.setup_max_fails, the Settings page). */
    public static function maxFails(Db $db): int
    {
        return max(1, Settings::number($db, 'staff.setup_max_fails', self::MAX_FAILS));
    }

    /**
     * Makes the fresh secret and starts the step: one conditional UPDATE ($where on top of "this person, active, the code's step
     * not used yet"), so two browsers with the same answer cannot both win. Revokes nothing yet. Returns the step token or null.
     *
     * @param list<mixed> $whereArgs
     */
    private function begin(int $id, string $route, string $where, array $whereArgs, int $step, Caller $caller, string $alsoSet): ?string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $enc = $this->box->encrypt(Totp::newSecret());
        return $this->db->transaction(function (Db $db) use ($id, $route, $where, $whereArgs, $step, $caller, $alsoSet, $token, $enc): ?string {
            $claimed = $db->exec(
                'UPDATE staff_user SET totp_last_step = ?, totp_next_enc = ?, totp_next_token = ?, totp_next_until = NOW(6) + INTERVAL ' . self::NEXT_MINUTES . ' MINUTE, '
                . 'totp_next_route = ?, totp_next_fails = 0' . $alsoSet
                . ' WHERE id = ? AND is_active = 1 AND (totp_last_step IS NULL OR totp_last_step < ?) AND ' . $where,
                [$step, $enc, hash('sha256', $token), $route, $id, $step, ...$whereArgs],
            );
            if ($claimed !== 1) {
                return null;
            }
            Audit::write($db, $caller, 'staff.setup_start', 'staff_user', (string) $id, null, ['route' => $route]);
            return $token;
        });
    }

    /** @return array<string, mixed>|null the person whose fresh secret waits for this step token */
    private function row(?string $token): ?array
    {
        if ($token === null || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return null;
        }
        return $this->db->one('SELECT id, email, password_must_change, totp_next_enc, totp_next_token, totp_next_until, totp_next_route FROM staff_user '
            . 'WHERE totp_next_token = ? AND totp_next_until > NOW(6) AND is_active = 1', [hash('sha256', $token)]);
    }

    /**
     * A failed step 1: counted by the limiter, audited, and, for an account whose window is open, one more wrong try of the window
     * (at staff.setup_max_fails the window closes).
     *
     * @return array{status: 'invalid', token: null}
     */
    private function failStart(?string $login, ?int $staffUserId, string $ip): array
    {
        $this->limiter->record($login, $staffUserId, $ip, 'totp', false);
        $caller = $staffUserId !== null ? Caller::staff($staffUserId, $ip) : Caller::system('ui_enrol', $ip);
        Audit::write($this->db, $caller, 'staff.setup_fail', 'staff_user', $staffUserId === null ? null : (string) $staffUserId, null,
            ['login' => $login, 'known' => $staffUserId !== null, 'step' => 'enrol']);
        if ($staffUserId !== null && $this->db->exec('UPDATE staff_user SET setup_fails = setup_fails + 1 WHERE id = ? AND setup_until > NOW(6)', [$staffUserId]) === 1) {
            $fails = (int) $this->db->value('SELECT setup_fails FROM staff_user WHERE id = ?', [$staffUserId]);
            $cap = self::maxFails($this->db);
            if ($fails >= $cap && $this->db->exec('UPDATE staff_user SET setup_until = NULL, setup_code_hash = NULL, setup_closed_at = NOW(6) '
                . 'WHERE id = ? AND setup_until IS NOT NULL', [$staffUserId]) === 1) {
                Audit::write($this->db, Caller::system('ui_enrol', $ip), 'staff.setup_closed', 'staff_user', (string) $staffUserId, null,
                    ['fails' => $fails, 'cap' => $cap, 'step' => 'enrol']);
            }
        }
        return ['status' => 'invalid', 'token' => null];
    }

    /**
     * A wrong code for the fresh secret: counted by the limiter, audited; at staff.setup_max_fails the step ends (`gone`), recorded
     * as a closed set-up (Home's card).
     *
     * @return array{status: 'invalid'|'gone', token: null, must_change: false}
     */
    private function failNext(?string $login, int $staffUserId, string $ip): array
    {
        $this->limiter->record($login, $staffUserId, $ip, 'totp', false);
        Audit::write($this->db, Caller::staff($staffUserId, $ip), 'staff.setup_fail', 'staff_user', (string) $staffUserId, null,
            ['login' => $login, 'known' => true, 'step' => 'new_code']);
        $this->db->exec('UPDATE staff_user SET totp_next_fails = totp_next_fails + 1 WHERE id = ? AND totp_next_token IS NOT NULL', [$staffUserId]);
        $fails = (int) $this->db->value('SELECT totp_next_fails FROM staff_user WHERE id = ?', [$staffUserId]);
        $cap = self::maxFails($this->db);
        if ($fails >= $cap && $this->db->exec('UPDATE staff_user SET ' . StaffAdmin::CLEAR_NEXT . ', setup_closed_at = NOW(6) WHERE id = ? AND totp_next_token IS NOT NULL',
            [$staffUserId]) === 1) {
            Audit::write($this->db, Caller::system('ui_enrol', $ip), 'staff.setup_closed', 'staff_user', (string) $staffUserId, null,
                ['fails' => $fails, 'cap' => $cap, 'step' => 'new_code']);
            return ['status' => 'gone', 'token' => null, 'must_change' => false];
        }
        return ['status' => 'invalid', 'token' => null, 'must_change' => false];
    }
}
