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
 * Staff accounts for the /ui screens (plan §11, design A.9): several roles each (staff_role, 0007, I10), an argon2id password hash
 * and a TOTP secret encrypted with app.env `ui_secret_key` (SecretBox). Accounts are never deleted or re-created: decisions and
 * audit rows name them.
 *
 * Who holds which sign-in factor (review finding B1; docs/decisions.md Y20-Y22 as amended by Y40-Y44, which replace I13's
 * "secrets only on the server" and I35's "resets only with bin/reset_staff.php"). An admin works on the Staff and access screen,
 * but NEVER holds both factors of anybody, so they can never sign in as someone else:
 *
 *  - enrol() adds a person: the sheet the admin hands over carries a sign-up secret (QR code) and a one-time set-up code; nobody
 *    knows the account's password. The person starts /ui/enrol with their e-mail, the set-up code and the 6 numbers of the sheet's
 *    secret (Enrolment::start); that secret and the code die at that first use, and the person's own page shows a FRESH secret
 *    (QR code and key, no-store) which they confirm with one code while choosing their password (Enrolment::confirm). Only then
 *    is a session issued. The admin never sees the fresh secret. totp_state says whose the current secret is: `signup` until the
 *    person has finished, then `own`.
 *  - newSheet() replaces the sheet of someone who never finished (lost, or the window ran out): an account nobody has finished
 *    setting up has no factor of a person to protect.
 *  - resetAuthenticator() (a lost phone, for someone who HAS finished) gives a "new sign-in code" sheet (totp_state `reset`): it
 *    works only at /ui/login together with the person's CURRENT password (Login), never at /ui/enrol, and leads straight to the
 *    same rotate-and-confirm step. Refused while a set-up window is open (409 setup_open: the admin would hold the set-up code and
 *    the code app's secret) and for someone who never finished (409 not_set_up: newSheet()).
 *  - resetPassword() (a forgotten password) opens a set-up window with a new one-time set-up code (the sheet shows that code only):
 *    the person starts /ui/enrol with it and the code app on THEIR phone, then rotates and chooses a password. Refused while the
 *    current secret is a sheet's (409 not_set_up / code_reset_open): the admin would hold that secret and the set-up code.
 *  - Someone who lost both the phone and the password is recovered on the server (bin/reset_staff.php, the break-glass).
 *  - While the owner has approvals.staff_reset on (off by default), a reset of someone holding Admin or Reviewer waits for a
 *    reviewer's OK first (RoleRequests, kind reset_code / reset_password): the admin then carries it out once (result
 *    `requested` until then).
 *
 * create() / reset() are the server's tools (bin/create_staff.php, bin/reset_staff.php; system callers): they print a one-time
 * password and/or an otpauth:// URI ONCE on the server's terminal, never to a browser; the account's secret is then the person's
 * own (totp_state `own`). Neither is stored in clear or written to the audit log.
 *
 * setRoles() / setActive() are what the People and roles screen does (and bin/reset_staff.php --roles): only an admin (re-read
 * inside the transaction) or a CLI tool (system caller), never on one's own account; a role set always passes
 * Permissions::checkRoleSet (admin never posts, reviews or decides, I12). A grant is revoked, never deleted. Every method locks the
 * caller's and the person's staff_user rows in id order first, so two admins changing each other at the same moment queue.
 * Switching someone off ends their sessions and closes any set-up window or half-finished set-up (review finding M3).
 *
 * Placeholder accounts (an e-mail under `.invalid`, such as the mapping_lead the first load ran as) are never switched on, given a
 * role or given a sign-in by a staff caller (409 placeholder_account, I35): an active placeholder is a working second identity
 * that defeats the two-person rule (U23). The CLI (a system caller, root on the server) remains the break-glass.
 */
final class StaffAdmin
{
    public const PASSWORD_LENGTH = 20;
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    /** Ends a half-finished set-up (the fresh secret waiting on someone's page). */
    public const CLEAR_NEXT = 'totp_next_enc = NULL, totp_next_token = NULL, totp_next_until = NULL, totp_next_route = NULL, totp_next_fails = 0';
    /** Closes a set-up window (and its one-time code). */
    public const CLOSE_WINDOW = 'setup_until = NULL, setup_code_hash = NULL, setup_fails = 0';

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
     * setRoles(). Switching off ends every session of the person at once (their next request goes to the sign-in), closes their
     * set-up window and any half-finished set-up (M3: a switched-off account has nothing open to guess at). Nothing changed (and no
     * session ended): result `unchanged`, no audit.
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
            $closed = 0;
            if ($was !== $active) {
                $db->exec('UPDATE staff_user SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $staffUserId]);
            }
            if (!$active) {
                $closed = $db->exec('UPDATE staff_user SET ' . self::CLOSE_WINDOW . ', ' . self::CLEAR_NEXT
                    . ' WHERE id = ? AND (setup_until IS NOT NULL OR totp_next_token IS NOT NULL)', [$staffUserId]);
            }
            $ended = $active ? 0 : (new Sessions($db))->revokeAll($staffUserId);
            $email = $u['email'] === null ? null : (string) $u['email'];
            if ($was === $active && $ended === 0 && $closed === 0) {
                return ['id' => $staffUserId, 'email' => $email, 'active' => $active, 'sessions_ended' => 0, 'result' => 'unchanged'];
            }
            Audit::write($db, $caller, $active ? 'staff.activate' : 'staff.deactivate', 'staff_user', (string) $staffUserId, null,
                ['email' => $email, 'active' => $active, 'sessions_ended' => $ended] + ($closed > 0 ? ['setup_closed' => true] : []));
            return ['id' => $staffUserId, 'email' => $email, 'active' => $active, 'sessions_ended' => $ended, 'result' => 'changed'];
        });
    }

    /**
     * The server's recovery of an existing account (bin/reset_staff.php, a system caller; the screens use the methods below): a new
     * one-time password (to be changed at the next sign-in) and/or a new TOTP secret, printed once on the server, and/or the account
     * switched off or on; every reset signs the person out everywhere. A new secret is the person's own from then on (totp_state
     * `own`), and any set-up window or half-finished set-up closes (a password exists now, or the account is off). An account that
     * never finished setting up needs a new secret with its new password (400 needs_new_totp: its secret is a sheet's). The caller
     * rules of setRoles() (I35: a staff caller gets the admin and own-account checks, not a silent bypass).
     *
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
            $state = (string) $db->value('SELECT totp_state FROM staff_user WHERE id = ?', [$id]);
            if ($hash !== null && $enc === null && $state === 'signup') {
                throw new CwException('needs_new_totp', "{$email} never finished setting up: its secret is a sign-up sheet's. Give a new TOTP secret too (--new-totp)", 400);
            }
            $set = [];
            $args = [];
            if ($hash !== null) {
                $set[] = 'password_hash = ?, password_must_change = 1';
                $args[] = $hash;
            }
            if ($enc !== null) {
                $set[] = "totp_secret_enc = ?, totp_last_step = NULL, totp_state = 'own'";
                $args[] = $enc;
            }
            if ($active !== null) {
                $set[] = 'is_active = ?';
                $args[] = $active ? 1 : 0;
            }
            if ($hash !== null || $enc !== null || $active === false) {
                $set[] = self::CLOSE_WINDOW . ', ' . self::CLEAR_NEXT;
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
     * Adds a person on the Staff and access page (G06; Y20 as amended by Y40-Y42): the account, its jobs, a sign-up secret and a
     * one-time set-up code, returned ONCE for the sign-up sheet (the screen draws the QR code; nothing secret is stored in clear or
     * shown again); no password exists that anybody knows (totp_state `signup`, setup_until = now + staff.setup_hours). The person
     * finishes at /ui/enrol (Enrolment): the sheet's secret and code die at their first use and the person's own page shows a fresh
     * secret the admin never sees. The same rules as create(): an admin (staff.manage), never a placeholder address, a valid job set
     * (Admin only with Look only, Accountant, Auditor). While the staff-grant rule is on, Admin and Reviewer wait for a reviewer's OK:
     * the account gets its other jobs now (maybe none) and `request` names the request.
     *
     * @param list<string> $roles
     * @return array{id: int, email: string, roles: list<string>, request: ?int, otpauth: string, secret: string, setup_code: string, setup_until: string}
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
        $hash = self::unknownPassword(); // nobody knows it: the person sets their own
        $secret = Totp::newSecret();
        $enc = $box->encrypt($secret);
        $code = SetupCode::new();
        $done = $this->db->transaction(function (Db $db) use ($caller, $email, $roles, $name, $hash, $enc, $code): array {
            $this->authorise($db, $caller, null);
            self::refusePlaceholder($caller, $email, 'given a role');
            if ($db->value('SELECT id FROM staff_user WHERE email = ? OR username = ?', [$email, mb_substr($email, 0, 64)]) !== null) {
                throw new CwException('staff_exists', "a staff user {$email} already exists", 409, ['field' => 'email']);
            }
            $hours = Settings::number($db, 'staff.setup_hours', 48);
            $id = $db->insert(
                'INSERT INTO staff_user (username, display_name, email, password_hash, password_must_change, setup_until, setup_code_hash, totp_secret_enc, '
                . "totp_state, is_active) VALUES (?, ?, ?, ?, 0, NOW(6) + INTERVAL ? HOUR, ?, ?, 'signup', 1)",
                [mb_substr($email, 0, 64), $name, $email, $hash, $hours, SetupCode::hash($code), $enc],
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
        return $done + ['email' => $email, 'otpauth' => Totp::uri($secret, $email), 'secret' => $secret, 'setup_code' => $code];
    }

    /**
     * A new sign-up sheet for someone who never finished setting up (the sheet was lost, or its window ran out or closed after too
     * many wrong tries; Y41): a new sign-up secret and set-up code and a new window, returned ONCE; the old sheet stops working. 409
     * already_set_up for someone who finished (resetAuthenticator / resetPassword then). The caller rules of setRoles(), never a
     * placeholder; while approvals.staff_reset is on, someone holding Admin or Reviewer needs a reviewer's OK first (result
     * `requested`).
     *
     * @return array{result: string, request: ?int, id: int, email: string, otpauth: ?string, secret: ?string, setup_code: ?string, setup_until: ?string, sessions_ended: int}
     */
    public function newSheet(Caller $caller, int $staffUserId, SecretBox $box): array
    {
        $secret = Totp::newSecret();
        $enc = $box->encrypt($secret);
        $code = SetupCode::new();
        $done = $this->db->transaction(function (Db $db) use ($caller, $staffUserId, $enc, $code): array {
            $u = $this->authorise($db, $caller, $staffUserId);
            self::refusePlaceholder($caller, $u['email'], 'given a sign-in code');
            if ($db->value('SELECT totp_state FROM staff_user WHERE id = ?', [$staffUserId]) !== 'signup') {
                throw new CwException('already_set_up', 'this person has set up their sign-in already: make a new sign-in code or let them choose a new password', 409);
            }
            $request = $this->resetGate($db, $caller, $staffUserId, $u, 'reset_code');
            if ($request !== null) {
                return ['result' => 'requested', 'request' => $request, 'email' => (string) $u['email'], 'setup_until' => null, 'sessions_ended' => 0];
            }
            $hours = Settings::number($db, 'staff.setup_hours', 48);
            $db->exec('UPDATE staff_user SET totp_secret_enc = ?, totp_last_step = NULL, password_hash = ?, password_must_change = 0, '
                . 'setup_until = NOW(6) + INTERVAL ? HOUR, setup_code_hash = ?, setup_fails = 0, setup_closed_at = NULL, ' . self::CLEAR_NEXT . ' WHERE id = ?',
                [$enc, self::unknownPassword(), $hours, SetupCode::hash($code), $staffUserId]);
            $ended = (new Sessions($db))->revokeAll($staffUserId);
            $until = (string) $db->value('SELECT setup_until FROM staff_user WHERE id = ?', [$staffUserId]);
            Audit::write($db, $caller, 'staff.reset', 'staff_user', (string) $staffUserId, null,
                ['email' => $u['email'], 'new_password' => false, 'new_totp' => true, 'new_sheet' => true, 'via' => 'screen', 'setup_until' => $until,
                    'sessions_ended' => $ended]);
            return ['result' => 'done', 'request' => null, 'email' => (string) $u['email'], 'setup_until' => $until, 'sessions_ended' => $ended];
        });
        $shown = $done['result'] === 'done';
        return ['result' => $done['result'], 'request' => $done['request'], 'id' => $staffUserId, 'email' => $done['email'],
            'otpauth' => $shown ? Totp::uri($secret, $done['email']) : null, 'secret' => $shown ? $secret : null, 'setup_code' => $shown ? $code : null,
            'setup_until' => $done['setup_until'], 'sessions_ended' => $done['sessions_ended']];
    }

    /**
     * A new sign-in code for someone who HAS set up their sign-in and lost or replaced their phone (Y22 as amended by Y43): a "new
     * sign-in code" secret, returned ONCE for its sheet; their old codes stop at once and they are signed out everywhere. That
     * secret works ONLY at /ui/login together with the person's CURRENT password, and leads straight to their own page with a fresh
     * secret they confirm (Login, Enrolment): it never works at /ui/enrol, and the admin never learns the password. Refused while a
     * set-up window is open (409 setup_open: with the window's set-up code the admin would hold both factors) and for someone who
     * never finished (409 not_set_up: newSheet()). The caller rules of setRoles() (an admin, never their own account); never a
     * placeholder (409 placeholder_account); while approvals.staff_reset is on, someone holding Admin or Reviewer needs a
     * reviewer's OK first (result `requested`, nothing changed).
     *
     * @return array{result: string, request: ?int, id: int, email: string, otpauth: ?string, secret: ?string, sessions_ended: int}
     */
    public function resetAuthenticator(Caller $caller, int $staffUserId, SecretBox $box): array
    {
        $secret = Totp::newSecret();
        $enc = $box->encrypt($secret);
        $done = $this->db->transaction(function (Db $db) use ($caller, $staffUserId, $enc): array {
            $u = $this->authorise($db, $caller, $staffUserId);
            self::refusePlaceholder($caller, $u['email'], 'given a sign-in code');
            $st = $db->one('SELECT totp_state, setup_until > NOW(6) AS open FROM staff_user WHERE id = ?', [$staffUserId]) ?? [];
            if (($st['totp_state'] ?? null) === 'signup') {
                throw new CwException('not_set_up', 'this person never finished setting up: make a new sign-up sheet instead', 409);
            }
            if ((int) ($st['open'] ?? 0) === 1) {
                throw new CwException('setup_open', 'this person may still choose a new password with the set-up code: a new sign-in code waits until that window '
                    . 'has closed (an admin never holds both a set-up code and a sign-in code of anybody)', 409);
            }
            $request = $this->resetGate($db, $caller, $staffUserId, $u, 'reset_code');
            if ($request !== null) {
                return ['result' => 'requested', 'request' => $request, 'email' => (string) $u['email'], 'sessions_ended' => 0];
            }
            $db->exec("UPDATE staff_user SET totp_secret_enc = ?, totp_last_step = NULL, totp_state = 'reset', " . self::CLOSE_WINDOW . ', ' . self::CLEAR_NEXT
                . ' WHERE id = ?', [$enc, $staffUserId]);
            $ended = (new Sessions($db))->revokeAll($staffUserId);
            Audit::write($db, $caller, 'staff.reset', 'staff_user', (string) $staffUserId, null,
                ['email' => $u['email'], 'new_password' => false, 'new_totp' => true, 'via' => 'screen', 'sessions_ended' => $ended]);
            return ['result' => 'done', 'request' => null, 'email' => (string) $u['email'], 'sessions_ended' => $ended];
        });
        $shown = $done['result'] === 'done';
        return ['result' => $done['result'], 'request' => $done['request'], 'id' => $staffUserId, 'email' => $done['email'],
            'otpauth' => $shown ? Totp::uri($secret, $done['email']) : null, 'secret' => $shown ? $secret : null, 'sessions_ended' => $done['sessions_ended']];
    }

    /**
     * Lets someone who HAS set up their sign-in choose a new password (Y22 as amended by Y43): their old password stops at once (a
     * random hash nobody knows), they are signed out everywhere, and a set-up window opens (now + staff.setup_hours) with a new
     * one-time set-up code, returned ONCE for its sheet (no QR code: the person keeps the code app they have). The person starts
     * /ui/enrol with their e-mail, that code and the 6 numbers of THEIR phone, then confirms a fresh secret and chooses the password.
     * Refused while the current secret is a sheet's (409 not_set_up for someone who never finished; 409 code_reset_open while a new
     * sign-in code is not used yet: the admin holds that secret). The caller rules of setRoles(); never a placeholder; while
     * approvals.staff_reset is on, someone holding Admin or Reviewer needs a reviewer's OK first (result `requested`).
     *
     * @return array{result: string, request: ?int, id: int, email: string, setup_code: ?string, setup_until: ?string, sessions_ended: int}
     */
    public function resetPassword(Caller $caller, int $staffUserId): array
    {
        $hash = self::unknownPassword();
        $code = SetupCode::new();
        $done = $this->db->transaction(function (Db $db) use ($caller, $staffUserId, $hash, $code): array {
            $u = $this->authorise($db, $caller, $staffUserId);
            self::refusePlaceholder($caller, $u['email'], 'given a new password');
            $state = (string) $db->value('SELECT totp_state FROM staff_user WHERE id = ?', [$staffUserId]);
            if ($state === 'signup') {
                throw new CwException('not_set_up', 'this person never finished setting up: make a new sign-up sheet instead', 409);
            }
            if ($state === 'reset') {
                throw new CwException('code_reset_open', 'this person has a new sign-in code they have not used yet: they sign in with it and their password first '
                    . '(an admin never holds both a sign-in code and a set-up code of anybody)', 409);
            }
            $request = $this->resetGate($db, $caller, $staffUserId, $u, 'reset_password');
            if ($request !== null) {
                return ['result' => 'requested', 'request' => $request, 'email' => (string) $u['email'], 'setup_until' => null, 'sessions_ended' => 0];
            }
            $hours = Settings::number($db, 'staff.setup_hours', 48);
            $db->exec('UPDATE staff_user SET password_hash = ?, password_must_change = 0, setup_until = NOW(6) + INTERVAL ? HOUR, setup_code_hash = ?, '
                . 'setup_fails = 0, setup_closed_at = NULL, ' . self::CLEAR_NEXT . ' WHERE id = ?', [$hash, $hours, SetupCode::hash($code), $staffUserId]);
            $ended = (new Sessions($db))->revokeAll($staffUserId);
            $until = (string) $db->value('SELECT setup_until FROM staff_user WHERE id = ?', [$staffUserId]);
            Audit::write($db, $caller, 'staff.reset', 'staff_user', (string) $staffUserId, null,
                ['email' => $u['email'], 'new_password' => 'chosen_by_the_person', 'new_totp' => false, 'via' => 'screen', 'setup_until' => $until,
                    'sessions_ended' => $ended]);
            return ['result' => 'done', 'request' => null, 'email' => (string) $u['email'], 'setup_until' => $until, 'sessions_ended' => $ended];
        });
        return ['result' => $done['result'], 'request' => $done['request'], 'id' => $staffUserId, 'email' => $done['email'],
            'setup_code' => $done['result'] === 'done' ? $code : null, 'setup_until' => $done['setup_until'], 'sessions_ended' => $done['sessions_ended']];
    }

    /**
     * Where a person's sign-in stands, for their page and the reviewers' request cards: whose secret it is (state own / signup /
     * reset), the set-up window (until, open, wrong tries, closed after too many), whether a fresh secret waits on their page, and
     * when and from which address they last finished setting up (/ui/enrol or a new sign-in code: audit staff.setup / staff.new_code).
     *
     * @return array{state: string, until: ?string, open: bool, fails: int, closed_at: ?string, pending: bool, finished: ?array{at: string, ip: ?string, how: string}}
     */
    public static function setupInfo(Db $db, int $staffUserId): array
    {
        $u = $db->one('SELECT totp_state, setup_until, setup_until > NOW(6) AS open, setup_fails, setup_closed_at, '
            . '(totp_next_token IS NOT NULL AND totp_next_until > NOW(6)) AS pending FROM staff_user WHERE id = ?', [$staffUserId]) ?? [];
        $f = $db->one("SELECT created_at, ip, action FROM audit_log WHERE entity_type = 'staff_user' AND entity_id = ? AND action IN ('staff.setup', 'staff.new_code') "
            . 'ORDER BY id DESC LIMIT 1', [(string) $staffUserId]);
        return ['state' => (string) ($u['totp_state'] ?? 'own'), 'until' => ($u['setup_until'] ?? null) === null ? null : (string) $u['setup_until'],
            'open' => (int) ($u['open'] ?? 0) === 1, 'fails' => (int) ($u['setup_fails'] ?? 0),
            'closed_at' => ($u['setup_closed_at'] ?? null) === null ? null : (string) $u['setup_closed_at'], 'pending' => (int) ($u['pending'] ?? 0) === 1,
            'finished' => $f === null ? null : ['at' => (string) $f['created_at'], 'ip' => $f['ip'] === null ? null : (string) $f['ip'],
                'how' => $f['action'] === 'staff.setup' ? 'enrol' : 'new_code']];
    }

    /**
     * approvals.staff_reset (Y44): null when the reset may go ahead now (the rule is off, the person holds neither Admin nor Reviewer,
     * the caller is a server tool, or a reviewer's OK of this kind waits unused: it is used now); otherwise the id of the request
     * opened for a reviewer (409 request_open when one waits already). Inside the reset's transaction, the person's row locked.
     *
     * @param array<string, mixed> $u the person's row (authorise())
     */
    private function resetGate(Db $db, Caller $caller, int $staffUserId, array $u, string $kind): ?int
    {
        $roles = StaffRoles::of($db, $staffUserId);
        if (!RoleRequests::resetApplies($db, $caller, $roles) || RoleRequests::useApprovedReset($db, $staffUserId, $kind) !== null) {
            return null;
        }
        return RoleRequests::openReset($db, $caller, $staffUserId, $u['email'] === null ? null : (string) $u['email'], $kind, $roles);
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

    /** A password hash nobody knows (a set-up window or a forgotten password: the person chooses their own). */
    private static function unknownPassword(): string
    {
        return password_hash(self::password() . bin2hex(random_bytes(16)), PASSWORD_ARGON2ID);
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
