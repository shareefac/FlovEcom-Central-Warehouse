<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\Audit;
use CW\Auth\Sessions;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Mapping\DecisionService;

/**
 * Staff accounts for the /ui screens (plan §11, design A.9): one role each, an argon2id password
 * hash and a TOTP secret encrypted with app.env `ui_secret_key` (SecretBox). create() returns the
 * one-time password and the otpauth:// URI ONCE to the tool that prints them; neither is stored
 * in clear or written to the audit log.
 *
 * reset() is the recovery for an existing account (a leaked or lost TOTP seed, a forgotten password,
 * someone leaving): accounts are never deleted or re-created, because decisions and audit rows name
 * them. It issues a new one-time password (to be changed at the next sign-in) and/or a new TOTP seed,
 * and/or switches the account off or on; every reset signs the person out everywhere.
 */
final class StaffAdmin
{
    public const PASSWORD_LENGTH = 20;
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array{id: int, email: string, role: string, password: string, otpauth: string}
     */
    public function create(Caller $caller, string $email, string $role, SecretBox $box, ?string $name = null): array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
            throw new CwException('bad_email', 'a valid e-mail address is required', 400);
        }
        if (!in_array($role, DecisionService::ROLES, true)) {
            throw new CwException('bad_role', 'role must be one of ' . implode(', ', DecisionService::ROLES), 400);
        }
        $name = trim($name ?? '');
        $display = $name !== '' ? mb_substr($name, 0, 128) : mb_substr(strstr($email, '@', true) ?: $email, 0, 128);
        $username = mb_substr($email, 0, 64);
        $password = self::password();
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $secret = Totp::newSecret();
        $enc = $box->encrypt($secret);

        $id = $this->db->transaction(function (Db $db) use ($caller, $email, $role, $display, $username, $hash, $enc): int {
            if ($db->value('SELECT id FROM staff_user WHERE email = ? OR username = ?', [$email, $username]) !== null) {
                throw new CwException('staff_exists', "a staff user {$email} already exists", 409);
            }
            $id = $db->insert(
                'INSERT INTO staff_user (username, display_name, email, role, password_hash, password_must_change, totp_secret_enc, is_active) '
                . 'VALUES (?, ?, ?, ?, ?, 1, ?, 1)',
                [$username, $display, $email, $role, $hash, $enc],
            );
            Audit::write($db, $caller, 'staff.create', 'staff_user', (string) $id, null, ['email' => $email, 'role' => $role]);
            return $id;
        });
        return ['id' => $id, 'email' => $email, 'role' => $role, 'password' => $password, 'otpauth' => Totp::uri($secret, $email)];
    }

    /**
     * @param bool|null $active false: switch the account off; true: on; null: leave it
     * @return array{id: int, email: string, role: string, active: bool, password: ?string, otpauth: ?string, sessions_ended: int}
     */
    public function reset(Caller $caller, string $email, ?SecretBox $box, bool $newPassword, bool $newTotp, ?bool $active): array
    {
        $email = strtolower(trim($email));
        if (!$newPassword && !$newTotp && $active === null) {
            throw new CwException('nothing_to_do', 'say what to reset: a new password, a new TOTP secret, or (de)activation', 400);
        }
        if ($newTotp && $box === null) {
            throw new CwException('no_key', 'a new TOTP secret needs ui_secret_key', 500);
        }
        $password = $newPassword ? self::password() : null;
        $hash = $password === null ? null : password_hash($password, PASSWORD_ARGON2ID);
        $secret = $newTotp ? Totp::newSecret() : null;
        $enc = $secret === null || $box === null ? null : $box->encrypt($secret);

        $done = $this->db->transaction(function (Db $db) use ($caller, $email, $hash, $enc, $active): array {
            $u = $db->one('SELECT id, role, is_active FROM staff_user WHERE email = ? FOR UPDATE', [$email]);
            if ($u === null) {
                throw new CwException('unknown_staff', "no staff user {$email}", 404);
            }
            $id = (int) $u['id'];
            $set = [];
            $args = [];
            if ($hash !== null) {
                $set[] = 'password_hash = ?, password_must_change = 1';
                $args[] = $hash;
            }
            if ($enc !== null) {
                $set[] = 'totp_secret_enc = ?, totp_last_step = NULL';
                $args[] = $enc;
            }
            if ($active !== null) {
                $set[] = 'is_active = ?';
                $args[] = $active ? 1 : 0;
            }
            $db->exec('UPDATE staff_user SET ' . implode(', ', $set) . ' WHERE id = ?', [...$args, $id]);
            $ended = (new Sessions($db))->revokeAll($id);
            $action = $active === false ? 'staff.deactivate' : ($active === true && $hash === null && $enc === null ? 'staff.activate' : 'staff.reset');
            Audit::write($db, $caller, $action, 'staff_user', (string) $id, null, [
                'email' => $email, 'new_password' => $hash !== null, 'new_totp' => $enc !== null, 'active' => $active, 'sessions_ended' => $ended,
            ]);
            return ['id' => $id, 'role' => (string) $u['role'], 'active' => $active ?? ((int) $u['is_active'] === 1), 'sessions_ended' => $ended];
        });
        return ['id' => $done['id'], 'email' => $email, 'role' => $done['role'], 'active' => $done['active'], 'password' => $password,
            'otpauth' => $secret === null ? null : Totp::uri($secret, $email), 'sessions_ended' => $done['sessions_ended']];
    }

    private static function password(): string
    {
        $out = '';
        $max = strlen(self::PASSWORD_ALPHABET) - 1;
        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $out .= self::PASSWORD_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
