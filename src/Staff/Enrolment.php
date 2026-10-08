<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\Audit;
use CW\Auth\Login;
use CW\Auth\LoginLimiter;
use CW\Auth\Sessions;
use CW\Caller;
use CW\Db;

/**
 * A person sets their own password (/ui/enrol, docs/decisions.md Y20, Y22): someone the admin set up on the screen (they scanned
 * the QR code into their code app), or someone the admin told to choose a new password. While their account's setup_until is in
 * the future, their e-mail and the current 6-digit code of their code app prove who they are; they choose a password (the screen
 * checks its length and that it is typed twice) and are signed in. Nobody else ever sees or types the password.
 *
 * The same protection as the sign-in (Login): the throttle per account and per address under the limiter's locks (a wrong code
 * counts as a failed sign-in), a code accepted once (totp_last_step), one answer for every failure (an unknown e-mail, an account
 * switched off or not waiting to set up, an expired window, a wrong code), audited staff.setup / staff.setup_fail. Every earlier
 * session of the person ends; the new one is issued here.
 */
final class Enrolment
{
    public function __construct(private readonly Db $db, private readonly LoginLimiter $limiter, private readonly SecretBox $box)
    {
    }

    /**
     * @return array{status: 'ok'|'invalid'|'locked', token: ?string}
     */
    public function complete(string $email, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $newPassword, string $ip, ?string $userAgent,
        ?string $oldToken): array
    {
        $login = Login::normalise($email);
        $locked = ['status' => 'locked', 'token' => null];
        return $this->limiter->exclusive($login, $ip, function () use ($login, $code, $newPassword, $ip, $userAgent, $oldToken): array {
            if ($this->limiter->lockedBy($login, $ip) !== null) {
                return ['status' => 'locked', 'token' => null];
            }
            $u = $login === null ? null : $this->db->one(
                'SELECT id, is_active, totp_secret_enc, totp_last_step, (setup_until IS NOT NULL AND setup_until > NOW(6)) AS open FROM staff_user WHERE email = ?',
                [$login],
            );
            if ($u === null || (int) $u['is_active'] !== 1 || (int) $u['open'] !== 1 || $u['totp_secret_enc'] === null) {
                return $this->fail($login, $u === null ? null : (int) $u['id'], $ip);
            }
            $id = (int) $u['id'];
            $step = Totp::verify($this->box->decrypt((string) $u['totp_secret_enc']), $code, $u['totp_last_step'] === null ? null : (int) $u['totp_last_step']);
            if ($step === null) {
                return $this->fail($login, $id, $ip);
            }
            $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
            $session = $this->db->transaction(function (Db $db) use ($id, $step, $hash, $ip, $userAgent, $oldToken): ?array {
                $claimed = $db->exec(
                    'UPDATE staff_user SET totp_last_step = ?, last_login_at = NOW(6), password_hash = ?, password_must_change = 0, setup_until = NULL '
                    . 'WHERE id = ? AND is_active = 1 AND setup_until > NOW(6) AND (totp_last_step IS NULL OR totp_last_step < ?)',
                    [$step, $hash, $id, $step],
                );
                if ($claimed !== 1) {
                    return null; // the same code a moment ago, or the window closed meanwhile
                }
                $sessions = new Sessions($db);
                $sessions->revokeToken($oldToken);
                $ended = $sessions->revokeAll($id);
                $s = $sessions->create($id, $ip, $userAgent);
                Audit::write($db, Caller::staff($id, $ip), 'staff.setup', 'staff_user', (string) $id, null, ['sessions_ended' => $ended, 'session' => substr($s['id'], 0, 8)]);
                return $s;
            });
            if ($session === null) {
                return $this->fail($login, $id, $ip);
            }
            $this->limiter->record($login, $id, $ip, 'totp', true);
            return ['status' => 'ok', 'token' => $session['token']];
        }) ?? $locked;
    }

    /** @return array{status: 'invalid', token: null} */
    private function fail(?string $login, ?int $staffUserId, string $ip): array
    {
        $this->limiter->record($login, $staffUserId, $ip, 'totp', false);
        $caller = $staffUserId !== null ? Caller::staff($staffUserId, $ip) : Caller::system('ui_enrol', $ip);
        Audit::write($this->db, $caller, 'staff.setup_fail', 'staff_user', $staffUserId === null ? null : (string) $staffUserId, null,
            ['login' => $login, 'known' => $staffUserId !== null]);
        return ['status' => 'invalid', 'token' => null];
    }
}
