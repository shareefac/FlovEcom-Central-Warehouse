<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Auth\Sessions;
use CW\Caller;
use CW\CwException;
use CW\Db;

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
     * @return array{before: list<string>, after: list<string>, added: list<string>, removed: list<string>, result: string}
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
