<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\Admin\ApprovalRules;
use CW\Audit;
use CW\Auth\Permissions;
use CW\Caller;
use CW\CwException;
use CW\Db;

/**
 * Giving someone Admin or Reviewer, when the owner switches that rule on (approvals.staff_grant, the Approval rules page; OFF by
 * default, the owner's Q8 answer; docs/decisions.md Y25): the admin's change of the person's jobs is not applied, it waits as a
 * staff_role_request until a reviewer who neither asked for it nor is the person says OK (the change is then applied as it was
 * asked, if the person's jobs are still what they were) or Not OK (with a note). The admin who asked, or another admin, may
 * withdraw it. One open request per person. CLI tools (bin/reset_staff.php, bin/create_staff.php) stay the break-glass and never
 * wait. Every step is audited (staff.role_request, staff.roles with approved_by, staff.role_request_reject / _withdraw).
 */
final class RoleRequests
{
    public const NOTE_MIN = 3;
    public const NOTE_MAX = 500;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Whether a change of jobs by $caller waits for a reviewer: a person at a screen, adding a guarded job, while the rule is on.
     *
     * @param list<string> $added
     */
    public static function applies(Db $db, Caller $caller, array $added): bool
    {
        return $caller->staffUserId !== null && array_intersect($added, ApprovalRules::GUARDED_ROLES) !== []
            && ApprovalRules::on($db, 'approvals.staff_grant');
    }

    /**
     * Opens the request (inside StaffAdmin's transaction, the person's row locked). 409 request_open when one waits already.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    public static function open(Db $db, Caller $caller, int $staffUserId, ?string $email, array $before, array $after): int
    {
        if ($db->value("SELECT id FROM staff_role_request WHERE staff_user_id = ? AND state = 'open'", [$staffUserId]) !== null) {
            throw new CwException('request_open', 'a change of this person\'s jobs is already waiting for a reviewer: withdraw it first', 409);
        }
        $guarded = array_values(array_intersect(array_diff($after, $before), ApprovalRules::GUARDED_ROLES));
        $id = $db->insert('INSERT INTO staff_role_request (staff_user_id, roles_before, roles_after, guarded, requested_by, requested_actor) '
            . 'VALUES (?, CAST(? AS JSON), CAST(? AS JSON), CAST(? AS JSON), ?, ?)',
            [$staffUserId, json_encode(array_values($before), JSON_THROW_ON_ERROR), json_encode(array_values($after), JSON_THROW_ON_ERROR),
                json_encode($guarded, JSON_THROW_ON_ERROR), $caller->staffUserId, $caller->actor]);
        Audit::write($db, $caller, 'staff.role_request', 'staff_user', (string) $staffUserId, null,
            ['email' => $email, 'request' => $id, 'before' => $before, 'after' => $after, 'guarded' => $guarded]);
        return $id;
    }

    /**
     * A reviewer's decision. Approving applies the change asked for, as one transaction with the person's row locked (granted_by =
     * the admin who asked; the audit row staff.roles names the reviewer as actor and approved_by), unless the person's jobs changed
     * since it was asked: then it is withdrawn ("their jobs changed meanwhile"; result `stale`) and nothing else changes. Not OK
     * needs a note of 3-500 characters (400 note_required). 403 role_not_allowed (no staff.approve: a reviewer whose job Admin does
     * not switch off), own_request (the person who asked), own_account (the person concerned); 404 unknown_request; 409
     * request_closed.
     *
     * @return array{result: string, staff_user_id: int}
     */
    public function decide(Caller $caller, int $requestId, bool $approve, ?string $note): array
    {
        $note = trim((string) $note);
        if (!$approve && (mb_strlen($note) < self::NOTE_MIN || mb_strlen($note) > self::NOTE_MAX)) {
            throw new CwException('note_required', 'say in ' . self::NOTE_MIN . ' to ' . self::NOTE_MAX . ' characters why not', 400, ['field' => 'note']);
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new CwException('note_required', 'a note is at most ' . self::NOTE_MAX . ' characters', 400, ['field' => 'note']);
        }
        if ($caller->staffUserId === null) {
            throw new CwException('staff_required', 'a reviewer decides this on the screen', 403);
        }
        return $this->db->transaction(function (Db $db) use ($caller, $requestId, $approve, $note): array {
            $me = (int) $caller->staffUserId;
            if (!Permissions::can(StaffRoles::active($db, $me), 'staff.approve')) {
                throw new CwException('role_not_allowed', 'only a reviewer decides a change of staff access', 403);
            }
            $r = $db->one('SELECT * FROM staff_role_request WHERE id = ? FOR UPDATE', [$requestId])
                ?? throw new CwException('unknown_request', 'there is no such request', 404);
            $staffId = (int) $r['staff_user_id'];
            if ($r['state'] !== 'open') {
                throw new CwException('request_closed', 'this request was decided or withdrawn already', 409);
            }
            if ($r['requested_by'] !== null && (int) $r['requested_by'] === $me) {
                throw new CwException('own_request', 'you asked for this change: another reviewer must decide it', 403);
            }
            if ($staffId === $me) {
                throw new CwException('own_account', 'this is a change of your own access: another reviewer must decide it', 403);
            }
            $u = $db->one('SELECT id, email FROM staff_user WHERE id = ? FOR UPDATE', [$staffId]) ?? throw new \LogicException('the request names nobody');
            $now = (string) $db->value('SELECT NOW(6)');
            if (!$approve) {
                $db->exec("UPDATE staff_role_request SET state = 'rejected', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?", [$me, $now, $note, $requestId]);
                Audit::write($db, $caller, 'staff.role_request_reject', 'staff_user', (string) $staffId, null, ['request' => $requestId, 'note' => $note]);
                return ['result' => 'rejected', 'staff_user_id' => $staffId];
            }
            $before = self::roles($r['roles_before']);
            $after = Permissions::checkRoleSet(self::roles($r['roles_after']));
            $live = StaffRoles::of($db, $staffId);
            if ($live !== $before) {
                $db->exec("UPDATE staff_role_request SET state = 'withdrawn', decided_at = ?, decision_note = ? WHERE id = ?",
                    [$now, 'withdrawn: the person\'s jobs changed meanwhile', $requestId]);
                Audit::write($db, $caller, 'staff.role_request_withdraw', 'staff_user', (string) $staffId, null, ['request' => $requestId, 'stale' => true, 'live' => $live]);
                return ['result' => 'stale', 'staff_user_id' => $staffId];
            }
            $added = array_values(array_diff($after, $live));
            $removed = array_values(array_diff($live, $after));
            $grantedBy = $r['requested_by'] === null ? null : (int) $r['requested_by'];
            foreach ($removed as $role) {
                $db->exec('UPDATE staff_role SET revoked_at = NOW(6), revoked_by = ? WHERE staff_user_id = ? AND role = ? AND revoked_at IS NULL', [$grantedBy, $staffId, $role]);
            }
            foreach ($added as $role) {
                $db->exec('INSERT INTO staff_role (staff_user_id, role, granted_by) VALUES (?, ?, ?)', [$staffId, $role, $grantedBy]);
            }
            $db->exec("UPDATE staff_role_request SET state = 'approved', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?",
                [$me, $now, $note === '' ? null : $note, $requestId]);
            Audit::write($db, $caller, 'staff.roles', 'staff_user', (string) $staffId, null, ['email' => $u['email'], 'before' => $live, 'after' => $after,
                'added' => $added, 'removed' => $removed, 'request' => $requestId, 'requested_by' => $grantedBy, 'approved_by' => $me]);
            return ['result' => 'approved', 'staff_user_id' => $staffId];
        });
    }

    /**
     * Withdraws an open request: the admin who asked, or another admin (staff.manage). 404 unknown_request, 409 request_closed.
     *
     * @return array{staff_user_id: int}
     */
    public function withdraw(Caller $caller, int $requestId): array
    {
        return $this->db->transaction(function (Db $db) use ($caller, $requestId): array {
            if ($caller->staffUserId !== null && !Permissions::can(StaffRoles::active($db, $caller->staffUserId), 'staff.manage')) {
                throw new CwException('role_not_allowed', 'only an admin withdraws a change of staff access', 403);
            }
            $r = $db->one('SELECT id, staff_user_id, state FROM staff_role_request WHERE id = ? FOR UPDATE', [$requestId])
                ?? throw new CwException('unknown_request', 'there is no such request', 404);
            if ($r['state'] !== 'open') {
                throw new CwException('request_closed', 'this request was decided or withdrawn already', 409);
            }
            $db->exec("UPDATE staff_role_request SET state = 'withdrawn', decided_at = NOW(6), decision_note = ? WHERE id = ?", ['withdrawn by an admin', $requestId]);
            Audit::write($db, $caller, 'staff.role_request_withdraw', 'staff_user', (string) $r['staff_user_id'], null, ['request' => $requestId]);
            return ['staff_user_id' => (int) $r['staff_user_id']];
        });
    }

    /**
     * The open requests, oldest first, with the names, for the reviewers' list; `can` = whether $me may decide it (not their own
     * request, not about themselves).
     *
     * @return list<array<string, mixed>>
     */
    public function pending(?int $me = null): array
    {
        $out = [];
        foreach ($this->db->all("SELECT r.*, u.display_name AS person, u.email, q.display_name AS requested_by_name FROM staff_role_request r "
            . 'JOIN staff_user u ON u.id = r.staff_user_id LEFT JOIN staff_user q ON q.id = r.requested_by '
            . "WHERE r.state = 'open' ORDER BY r.requested_at, r.id") as $r) {
            $out[] = self::row($r) + ['can' => $me !== null && (int) $r['staff_user_id'] !== $me && ($r['requested_by'] === null || (int) $r['requested_by'] !== $me)];
        }
        return $out;
    }

    /** @return array<string, mixed>|null the person's open request */
    public function openFor(int $staffUserId): ?array
    {
        $r = $this->db->one("SELECT r.*, u.display_name AS person, u.email, q.display_name AS requested_by_name FROM staff_role_request r "
            . 'JOIN staff_user u ON u.id = r.staff_user_id LEFT JOIN staff_user q ON q.id = r.requested_by '
            . "WHERE r.staff_user_id = ? AND r.state = 'open'", [$staffUserId]);
        return $r === null ? null : self::row($r);
    }

    /** How many open requests $me may decide (Home's card; 0 without staff.approve). @param list<string> $roles */
    public function decidableCount(int $me, array $roles): int
    {
        if (!Permissions::can($roles, 'staff.approve')) {
            return 0;
        }
        return (int) $this->db->value("SELECT COUNT(*) FROM staff_role_request WHERE state = 'open' AND staff_user_id <> ? AND (requested_by IS NULL OR requested_by <> ?)",
            [$me, $me]);
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['id'], 'staff_user_id' => (int) $r['staff_user_id'], 'person' => (string) $r['person'], 'email' => $r['email'],
            'before' => self::roles($r['roles_before']), 'after' => self::roles($r['roles_after']), 'guarded' => self::roles($r['guarded']),
            'requested_by' => $r['requested_by'] === null ? null : (int) $r['requested_by'], 'requested_by_name' => $r['requested_by_name'],
            'requested_at' => (string) $r['requested_at']];
    }

    /** @return list<string> sorted */
    private static function roles(mixed $json): array
    {
        $v = is_string($json) ? json_decode($json, true) : $json;
        $out = array_values(array_filter(is_array($v) ? $v : [], 'is_string'));
        sort($out);
        return $out;
    }
}
