<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Auth\Sessions;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Settings;

/**
 * Staff accounts for the /ui screens (plan §11, design A.9): several roles each (staff_role, 0007, I10), an
 * argon2id password hash and a TOTP secret encrypted with app.env `ui_secret_key` (SecretBox). create() returns
 * the one-time password and the otpauth:// URI ONCE to the tool that prints them; neither is stored in clear or
 * written to the audit log. Accounts are created on the server only (bin/create_staff.php): a secret is never
 * shown in a browser (I13).
 *
 * setRoles() / setActive() are what the People and roles screen does (and bin/reset_staff.php --roles): only an
 * admin (re-read inside the transaction) or a CLI tool (system caller), never on one's own account; a role set
 * always passes Permissions::checkRoleSet (admin never posts, reviews or decides, I12). A grant is revoked, never
 * deleted, so the history of who held what stays (staff_role.revoked_at / revoked_by). Both lock the caller's and
 * the person's staff_user rows in id order first, so two admins changing each other at the same moment queue, and
 * the second sees what the first did (it may no longer be an admin).
 *
 * reset() is the recovery for an existing account (a leaked or lost TOTP seed, a forgotten password,
 * someone leaving): accounts are never deleted or re-created, because decisions and audit rows name
 * them. It issues a new one-time password (to be changed at the next sign-in) and/or a new TOTP seed,
 * and/or switches the account off or on; every reset signs the person out everywhere. It follows the same
 * caller rules as setRoles() (I35: today only bin/reset_staff.php calls it, as a system caller; a future screen
 * calling it with a staff caller gets the admin and own-account checks, not a silent bypass).
 *
 * Placeholder accounts (an e-mail under `.invalid`, such as the mapping_lead the first load ran as) are never
 * switched on or given a role by a staff caller (409 placeholder_account, I35): an active placeholder is a working
 * second identity that defeats the two-person rule (U23), and enable_https.sh checks for it only once. The CLI
 * (a system caller, root on the server) remains the break-glass.
 */
final class StaffAdmin
{
    public const PASSWORD_LENGTH = 20;
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param string|list<string> $roles one role or a list (Permissions::checkRoleSet)
     * @return array{id: int, email: string, roles: list<string>, password: string, otpauth: string}
     */
    public function create(Caller $caller, string $email, string|array $roles, SecretBox $box, ?string $name = null): array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
            throw new CwException('bad_email', 'a valid e-mail address is required', 400);
        }
        $roles = Permissions::checkRoleSet(is_string($roles) ? [$roles] : $roles);
        $name = trim($name ?? '');
        $display = $name !== '' ? mb_substr($name, 0, 128) : mb_substr(strstr($email, '@', true) ?: $email, 0, 128);
        $username = mb_substr($email, 0, 64);
        $password = self::password();
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $secret = Totp::newSecret();
        $enc = $box->encrypt($secret);

        $id = $this->db->transaction(function (Db $db) use ($caller, $email, $roles, $display, $username, $hash, $enc): int {
            $this->authorise($db, $caller, null);
            self::refusePlaceholder($caller, $email, 'given a role');
            if ($db->value('SELECT id FROM staff_user WHERE email = ? OR username = ?', [$email, $username]) !== null) {
                throw new CwException('staff_exists', "a staff user {$email} already exists", 409);
            }
            $id = $db->insert(
                'INSERT INTO staff_user (username, display_name, email, password_hash, password_must_change, totp_secret_enc, is_active) '
                . 'VALUES (?, ?, ?, ?, 1, ?, 1)',
                [$username, $display, $email, $hash, $enc],
            );
            foreach ($roles as $role) {
                $db->exec('INSERT INTO staff_role (staff_user_id, role, granted_by) VALUES (?, ?, ?)', [$id, $role, $caller->staffUserId]);
            }
            Audit::write($db, $caller, 'staff.create', 'staff_user', (string) $id, null, ['email' => $email, 'roles' => $roles]);
            return $id;
        });
        return ['id' => $id, 'email' => $email, 'roles' => $roles, 'password' => $password, 'otpauth' => Totp::uri($secret, $email)];
    }

    /**
     * Replaces a person's role set (the People and roles form, bin/reset_staff.php --roles). One transaction:
     * the caller must hold admin (a system caller, i.e. a CLI tool, may too), never on their own account; the
     * person's row is locked; when $rolesSeen is given (the form's hidden list) and the live roles differ, 409
     * roles_changed (someone else changed them since the page was drawn); the new set passes checkRoleSet; removed
     * roles are revoked (revoked_at, revoked_by), added ones granted. Nothing changed: result `unchanged`, no audit.
     *
     * @param array<mixed> $roles
     * @param array<mixed>|null $rolesSeen
     * While the owner has the staff-grant rule on (approvals.staff_grant, Y25) a person at a screen adding Admin or Reviewer gets
     * result `requested` (with `request`): nothing is applied until a reviewer says OK (RoleRequests).
     *
     * @return array{before: list<string>, after: list<string>, added: list<string>, removed: list<string>, result: string, request?: int}
     */
    public function setRoles(Caller $caller, int $staffUserId, array $roles, ?array $rolesSeen): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $staffUserId, $roles, $rolesSeen): array {
            $u = $this->authorise($db, $caller, $staffUserId);
            $live = $db->all('SELECT id, CAST(role AS CHAR) AS role FROM staff_role WHERE staff_user_id = ? AND revoked_at IS NULL', [$staffUserId]);
            $before = array_map(static fn (array $r): string => (string) $r['role'], $live);
            sort($before);
            if ($rolesSeen !== null && self::normalised($rolesSeen) !== $before) {
                throw new CwException('roles_changed', 'the roles of this person were changed by someone else since the page was shown; reload it', 409,
                    ['current' => $before]);
            }
            $after = Permissions::checkRoleSet($roles);
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added === [] && $removed === []) {
                return ['before' => $before, 'after' => $after, 'added' => [], 'removed' => [], 'result' => 'unchanged'];
            }
            if ($added !== []) {
                self::refusePlaceholder($caller, $u['email'], 'given a role');
            }
            if (RoleRequests::applies($db, $caller, $added)) {
                // Admin or Reviewer waits for a reviewer's OK while the owner has that rule on (approvals.staff_grant, Y25).
                $request = RoleRequests::open($db, $caller, $staffUserId, $u['email'] === null ? null : (string) $u['email'], $before, $after);
                return ['before' => $before, 'after' => $after, 'added' => $added, 'removed' => $removed, 'result' => 'requested', 'request' => $request];
            }
            foreach ($live as $r) {
                if (in_array((string) $r['role'], $removed, true)) {
                    $n = $db->exec('UPDATE staff_role SET revoked_at = NOW(6), revoked_by = ? WHERE id = ? AND revoked_at IS NULL',
                        [$caller->staffUserId, (int) $r['id']]);
                    if ($n !== 1) {
                        throw new \LogicException('a live grant changed under the person\'s row lock');
                    }
                }
            }
            foreach ($added as $role) {
                $db->exec('INSERT INTO staff_role (staff_user_id, role, granted_by) VALUES (?, ?, ?)', [$staffUserId, $role, $caller->staffUserId]);
            }
            Audit::write($db, $caller, 'staff.roles', 'staff_user', (string) $staffUserId, null,
                ['email' => $u['email'], 'before' => $before, 'after' => $after, 'added' => $added, 'removed' => $removed]);
            return ['before' => $before, 'after' => $after, 'added' => $added, 'removed' => $removed, 'result' => 'changed'];
        });
    }

    /**
     * Switches a person's account on or off (the People and roles screen); the same admin and own-account rules as
     * setRoles(). Switching off ends every session of the person at once (their next request goes to the sign-in).
     * Nothing changed (and no session ended): result `unchanged`, no audit.
     *
     * @return array{id: int, email: ?string, active: bool, sessions_ended: int, result: string}
     */
    public function setActive(Caller $caller, int $staffUserId, bool $active): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $staffUserId, $active): array {
            $u = $this->authorise($db, $caller, $staffUserId);
            $was = (int) $u['is_active'] === 1;
            if ($active && !$was) {
                self::refusePlaceholder($caller, $u['email'], 'switched on');
            }
            if ($was !== $active) {
                $db->exec('UPDATE staff_user SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $staffUserId]);
            }
            $ended = $active ? 0 : (new Sessions($db))->revokeAll($staffUserId);
            $email = $u['email'] === null ? null : (string) $u['email'];
            if ($was === $active && $ended === 0) {
                return ['id' => $staffUserId, 'email' => $email, 'active' => $active, 'sessions_ended' => 0, 'result' => 'unchanged'];
            }
            Audit::write($db, $caller, $active ? 'staff.activate' : 'staff.deactivate', 'staff_user', (string) $staffUserId, null,
                ['email' => $email, 'active' => $active, 'sessions_ended' => $ended]);
            return ['id' => $staffUserId, 'email' => $email, 'active' => $active, 'sessions_ended' => $ended, 'result' => 'changed'];
        });
    }

    /**
     * @param bool|null $active false: switch the account off; true: on; null: leave it
     * @return array{id: int, email: string, roles: list<string>, active: bool, password: ?string, otpauth: ?string, sessions_ended: int}
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
            $found = $db->value('SELECT id FROM staff_user WHERE email = ?', [$email]);
            if ($found === null) {
                throw new CwException('unknown_staff', "no staff user {$email}", 404);
            }
            // The same caller rules as setRoles(), and the rows locked in id order (authorise re-reads the person locked).
            $u = $this->authorise($db, $caller, (int) $found);
            $id = (int) $u['id'];
            if ($active === true && (int) $u['is_active'] !== 1) {
                self::refusePlaceholder($caller, $u['email'], 'switched on');
            }
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
            return ['id' => $id, 'roles' => StaffRoles::of($db, $id), 'active' => $active ?? ((int) $u['is_active'] === 1), 'sessions_ended' => $ended];
        });
        return ['id' => $done['id'], 'email' => $email, 'roles' => $done['roles'], 'active' => $done['active'], 'password' => $password,
            'otpauth' => $secret === null ? null : Totp::uri($secret, $email), 'sessions_ended' => $done['sessions_ended']];
    }

    /**
     * Sets a person up on the Staff and access page (G06, docs/decisions.md Y20): the account, its jobs and a NEW sign-in secret,
     * returned ONCE (the screen draws it as a QR code and the setup key, and stores neither); no password exists that anybody
     * knows. Until setup_until (now + staff.setup_hours) the person opens /ui/enrol on their own device, types their e-mail and the
     * 6 numbers of their code app, and chooses their own password (Enrolment). The same rules as create(): an admin (staff.manage),
     * never a placeholder address, a valid job set (Admin only with Look only, Accountant, Auditor). While the staff-grant rule is on,
     * Admin and Reviewer wait for a reviewer's OK: the account gets its other jobs now (maybe none) and `request` names the request.
     *
     * @param list<string> $roles
     * @return array{id: int, email: string, roles: list<string>, request: ?int, otpauth: string, secret: string, setup_until: string}
     */
    public function enrol(Caller $caller, string $email, array $roles, SecretBox $box, ?string $name): array
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
            throw new CwException('bad_email', 'a valid e-mail address is required', 400, ['field' => 'email']);
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $name ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 128) {
            throw new CwException('bad_name', 'a name of 2 to 128 characters is required', 400, ['field' => 'name']);
        }
        $roles = Permissions::checkRoleSet($roles);
        $hash = password_hash(self::password() . bin2hex(random_bytes(16)), PASSWORD_ARGON2ID); // nobody knows it: the person sets their own
        $secret = Totp::newSecret();
        $enc = $box->encrypt($secret);
        $done = $this->db->transaction(function (Db $db) use ($caller, $email, $roles, $name, $hash, $enc): array {
            $this->authorise($db, $caller, null);
            self::refusePlaceholder($caller, $email, 'given a role');
            if ($db->value('SELECT id FROM staff_user WHERE email = ? OR username = ?', [$email, mb_substr($email, 0, 64)]) !== null) {
                throw new CwException('staff_exists', "a staff user {$email} already exists", 409, ['field' => 'email']);
            }
            $hours = Settings::number($db, 'staff.setup_hours', 48);
            $id = $db->insert(
                'INSERT INTO staff_user (username, display_name, email, password_hash, password_must_change, setup_until, totp_secret_enc, is_active) '
                . 'VALUES (?, ?, ?, ?, 0, NOW(6) + INTERVAL ? HOUR, ?, 1)',
                [mb_substr($email, 0, 64), $name, $email, $hash, $hours, $enc],
            );
            $now = RoleRequests::applies($db, $caller, $roles) ? array_values(array_diff($roles, \CW\Admin\ApprovalRules::GUARDED_ROLES)) : $roles;
            foreach ($now as $role) {
                $db->exec('INSERT INTO staff_role (staff_user_id, role, granted_by) VALUES (?, ?, ?)', [$id, $role, $caller->staffUserId]);
            }
            $until = (string) $db->value('SELECT setup_until FROM staff_user WHERE id = ?', [$id]);
            Audit::write($db, $caller, 'staff.create', 'staff_user', (string) $id, null, ['email' => $email, 'roles' => $now, 'via' => 'screen', 'setup_until' => $until]);
            $request = $now === $roles ? null : RoleRequests::open($db, $caller, $id, $email, $now, $roles);
            return ['id' => $id, 'roles' => $now, 'request' => $request, 'setup_until' => $until];
        });
        return $done + ['email' => $email, 'otpauth' => Totp::uri($secret, $email), 'secret' => $secret];
    }

    /**
     * A new sign-in secret for a person whose phone is lost or new (G06, Y22): returned ONCE (QR code and setup key); the old codes
     * stop at once and the person is signed out everywhere. They sign in with their password and the new code (a person who never
     * finished setting up gets a fresh set-up window). The caller rules of setRoles() (an admin, never their own account); never a
     * placeholder account (409 placeholder_account).
     *
     * @return array{id: int, email: string, otpauth: string, secret: string, sessions_ended: int}
     */
    public function resetAuthenticator(Caller $caller, int $staffUserId, SecretBox $box): array
    {
        $secret = Totp::newSecret();
        $enc = $box->encrypt($secret);
        $done = $this->db->transaction(function (Db $db) use ($caller, $staffUserId, $enc): array {
            $u = $this->authorise($db, $caller, $staffUserId);
            self::refusePlaceholder($caller, $u['email'], 'given a sign-in code');
            $hours = Settings::number($db, 'staff.setup_hours', 48);
            $db->exec('UPDATE staff_user SET totp_secret_enc = ?, totp_last_step = NULL, '
                . 'setup_until = IF(setup_until IS NULL, NULL, NOW(6) + INTERVAL ? HOUR) WHERE id = ?', [$enc, $hours, $staffUserId]);
            $ended = (new Sessions($db))->revokeAll($staffUserId);
            Audit::write($db, $caller, 'staff.reset', 'staff_user', (string) $staffUserId, null,
                ['email' => $u['email'], 'new_password' => false, 'new_totp' => true, 'via' => 'screen', 'sessions_ended' => $ended]);
            return ['email' => (string) $u['email'], 'sessions_ended' => $ended];
        });
        return ['id' => $staffUserId, 'email' => $done['email'], 'otpauth' => Totp::uri($secret, $done['email']), 'secret' => $secret,
            'sessions_ended' => $done['sessions_ended']];
    }

    /**
     * Lets a person choose a new password (G06, Y22): their old password stops working at once (nobody knows the new hash) and they
     * are signed out everywhere; until setup_until (now + staff.setup_hours) they set a new one at /ui/enrol with their e-mail and
     * the code of their phone. No password is ever shown in a browser. The caller rules of setRoles(); never a placeholder.
     *
     * @return array{id: int, email: string, setup_until: string, sessions_ended: int}
     */
    public function resetPassword(Caller $caller, int $staffUserId): array
    {
        $hash = password_hash(self::password() . bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);
        return $this->db->transaction(function (Db $db) use ($caller, $staffUserId, $hash): array {
            $u = $this->authorise($db, $caller, $staffUserId);
            self::refusePlaceholder($caller, $u['email'], 'given a new password');
            $hours = Settings::number($db, 'staff.setup_hours', 48);
            $db->exec('UPDATE staff_user SET password_hash = ?, password_must_change = 0, setup_until = NOW(6) + INTERVAL ? HOUR WHERE id = ?',
                [$hash, $hours, $staffUserId]);
            $ended = (new Sessions($db))->revokeAll($staffUserId);
            $until = (string) $db->value('SELECT setup_until FROM staff_user WHERE id = ?', [$staffUserId]);
            Audit::write($db, $caller, 'staff.reset', 'staff_user', (string) $staffUserId, null,
                ['email' => $u['email'], 'new_password' => 'chosen_by_the_person', 'new_totp' => false, 'via' => 'screen', 'setup_until' => $until,
                    'sessions_ended' => $ended]);
            return ['id' => $staffUserId, 'email' => (string) $u['email'], 'setup_until' => $until, 'sessions_ended' => $ended];
        });
    }

    /**
     * Signs a person out of one device ($handle: StaffSessions::HANDLE hex characters of the session's id) or of every device
     * (null), from the Staff and access pages (G06, Y23). The caller rules of setRoles() (an admin, never their own account here:
     * they sign out with the button of their own account panel). Returns how many sessions ended (0: it had ended already).
     */
    public function signOut(Caller $caller, int $staffUserId, ?string $handle): int
    {
        if ($handle !== null && !StaffSessions::isHandle($handle)) {
            throw new CwException('bad_session', 'that does not name a signed-in device', 400);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $staffUserId, $handle): int {
            $u = $this->authorise($db, $caller, $staffUserId);
            $ended = $handle === null ? (new Sessions($db))->revokeAll($staffUserId)
                : $db->exec("UPDATE staff_session SET revoked = 1, revoked_at = NOW(6) WHERE staff_user_id = ? AND revoked = 0 AND id LIKE CONCAT(?, '%')",
                    [$staffUserId, $handle]);
            if ($ended > 0) {
                Audit::write($db, $caller, 'staff.sign_out', 'staff_user', (string) $staffUserId, null,
                    ['email' => $u['email'], 'which' => $handle ?? 'all', 'sessions_ended' => $ended]);
            }
            return $ended;
        });
    }

    /**
     * Who may change staff accounts: a CLI tool (system caller), or a staff caller who holds admin now (re-read
     * after the lock), never on their own account. Locks the caller's and the person's staff_user rows in id order.
     *
     * @return array{id: int, email: mixed, is_active: mixed}|array{} the person's row ([] when $staffUserId is null)
     */
    private function authorise(Db $db, Caller $caller, ?int $staffUserId): array
    {
        if ($caller->isChannel()) {
            throw new CwException('staff_required', 'staff accounts are managed by an admin or on the server', 403);
        }
        $ids = array_values(array_unique(array_filter([$caller->staffUserId, $staffUserId], static fn (?int $i): bool => $i !== null)));
        sort($ids);
        $rows = [];
        if ($ids !== []) {
            foreach ($db->all('SELECT id, email, is_active FROM staff_user WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE', $ids) as $r) {
                $rows[(int) $r['id']] = $r;
            }
        }
        if ($caller->staffUserId !== null && !Permissions::can(StaffRoles::active($db, $caller->staffUserId), 'staff.manage')) {
            throw new CwException('role_not_allowed', 'only an admin manages people and roles', 403);
        }
        if ($staffUserId === null) {
            return [];
        }
        if ($caller->staffUserId !== null && $caller->staffUserId === $staffUserId) {
            throw new CwException('own_account', 'nobody changes their own roles or switches their own account off: ask another admin', 403);
        }
        return $rows[$staffUserId] ?? throw new CwException('unknown_staff', "no staff user {$staffUserId}", 404);
    }

    /** A placeholder account: an e-mail under `.invalid` (U23; enable_https.sh refuses while one is active). */
    public static function isPlaceholder(mixed $email): bool
    {
        return is_string($email) && str_ends_with(strtolower(trim($email)), '.invalid');
    }

    /** 409 placeholder_account when a staff caller (a screen) would switch a placeholder on or give it a role (I35). */
    private static function refusePlaceholder(Caller $caller, mixed $email, string $what): void
    {
        if ($caller->staffUserId !== null && self::isPlaceholder($email)) {
            throw new CwException('placeholder_account', "a placeholder account (an e-mail under .invalid) is never {$what} from a screen: "
                . 'it would be a working second identity that defeats the two-person rule (U23). Create a real account with '
                . 'bin/create_staff.php instead', 409);
        }
    }

    /** @param array<mixed> $roles @return list<string> the strings of $roles, unique and sorted */
    private static function normalised(array $roles): array
    {
        $out = array_values(array_unique(array_filter($roles, static fn (mixed $r): bool => is_string($r) && $r !== '')));
        sort($out);
        return $out;
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
