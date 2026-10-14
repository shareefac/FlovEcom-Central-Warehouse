<?php

declare(strict_types=1);

namespace CW\Admin;

use CW\Audit;
use CW\Auth\Permissions;
use CW\Auth\RoleMap;
use CW\Caller;
use CW\CwException;
use CW\Db;
use CW\Staff\StaffRoles;

/**
 * Who may do what, changed on the screen (Settings › Users › Roles and permissions; docs/decisions.md PE2-PE8): the only writer of
 * role_permission (0034). One permission at a time: the jobs that hold it, with a reason, against the version the form was drawn
 * with. Every guard of the code map still holds (Auth\Permissions::problems, checked before anything is read):
 *
 *  - a LOCKED permission (what everyone needs, the security core, the ones other code reads by job name) is never changed here;
 *  - admin, and the look-only jobs admin may be combined with, never get a permission that changes anything (I12);
 *  - a guarded job keeps its REQUIRED permissions (an admin sees the people; a reviewer gives the checks and the OKs);
 *  - every permission keeps at least one job.
 *
 * Who: an admin or a reviewer (settings.manage, re-read inside the transaction: ConfigHistory::authorise), as every setting. Giving a
 * job a permission is loosening: it needs a Reviewer (staff.approve: ApprovalRules::authoriseLoosening, I1, Y45); taking one away is
 * tightening and needs settings.manage only. Nobody gives a permission to a job they hold themselves (403 own_job): a person never
 * grants themselves more rights, whatever their other jobs. A server tool (system caller) may do all of it (the break-glass).
 *
 * Every change writes the row, its next config_change version (`permission`, the jobs as text; a permission without a row first gets
 * a baseline of MAP's holders, ConfigHistory::record) and an audit row permission.change {permission, before, after, added, removed,
 * reason, version, loosened}, in one transaction, then puts the new map in force for this process.
 */
final class RolePermissions
{
    public const AUDIT = 'permission.change';

    /**
     * @param list<mixed> $holders the jobs that should hold $perm, any order
     * @return array{changed: bool, before: list<string>, after: list<string>, added: list<string>, removed: list<string>, version: int}
     */
    public static function change(Db $db, Caller $caller, string $perm, array $holders, string $reason, ?int $seen = null): array
    {
        $problems = Permissions::problems($perm, $holders);
        if ($problems !== []) {
            throw self::refusal($problems[0], $perm);
        }
        $reason = ConfigHistory::reason($reason);
        $after = Permissions::ordered($holders);
        $r = $db->transaction(function (Db $db) use ($caller, $perm, $after, $reason, $seen): array {
            ConfigHistory::authorise($db, $caller);
            $row = $db->one('SELECT roles FROM role_permission WHERE permission = ? FOR UPDATE', [$perm]);
            ConfigHistory::checkSeen($db, 'permission', $perm, $seen);
            $stored = $row === null ? null : (string) $row['roles'];
            // What is in force now (a row's jobs read fail-closed; no row: MAP), against which "added" and "removed" are counted.
            $now = Permissions::sanitise($perm, $stored === null ? Permissions::MAP[$perm] : ($stored === '' ? [] : explode(',', $stored)));
            $version = ConfigHistory::version($db, 'permission', $perm);
            $text = implode(',', $after);
            if (($stored ?? implode(',', $now)) === $text) {
                return ['changed' => false, 'before' => $now, 'after' => $after, 'added' => [], 'removed' => [], 'version' => $version];
            }
            $added = array_values(array_diff($after, $now));
            $removed = array_values(array_diff($now, $after));
            if ($added !== []) {
                ApprovalRules::authoriseLoosening($db, $caller);
                if ($caller->staffUserId !== null) {
                    $own = array_values(array_intersect($added, StaffRoles::active($db, $caller->staffUserId)));
                    if ($own !== []) {
                        throw new CwException('own_job', 'nobody gives a permission to a job they hold themselves: ask another reviewer', 403,
                            ['roles' => $own]);
                    }
                }
            }
            $old = ConfigHistory::state($db, 'permission', $perm) ?? ['roles' => implode(',', Permissions::defaults()[$perm])];
            if ($row === null) {
                $db->exec('INSERT INTO role_permission (permission, roles, updated_actor) VALUES (?, ?, ?)', [$perm, $text, $caller->actor]);
            } else {
                $db->exec('UPDATE role_permission SET roles = ?, updated_actor = ?, updated_at = NOW(6) WHERE permission = ?', [$text, $caller->actor, $perm]);
            }
            $new = ConfigHistory::state($db, 'permission', $perm) ?? throw new \LogicException("permission {$perm} vanished");
            $v = ConfigHistory::record($db, $caller, 'permission', $perm, 'change', $old, $new, $reason);
            Audit::write($db, $caller, self::AUDIT, 'role_permission', $perm, null, ['permission' => $perm, 'before' => $now, 'after' => $after,
                'added' => $added, 'removed' => $removed, 'reason' => $reason, 'version' => $v['version']] + ($added !== [] ? ['loosened' => true] : []));
            return ['changed' => true, 'before' => $now, 'after' => $after, 'added' => $added, 'removed' => $removed, 'version' => $v['version']];
        });
        RoleMap::load($db);
        return $r;
    }

    /**
     * Every permission for the screen, in MAP's order: its holders now, whether it can be changed here, whether it only lets people
     * look, whether it still reads MAP (no row yet), its latest version (the form's `seen`).
     *
     * @return list<array{perm: string, holders: list<string>, locked: bool, read_only: bool, from_code: bool, version: int, required: list<string>}>
     */
    public static function all(Db $db): array
    {
        RoleMap::load($db);
        $rows = [];
        foreach ($db->all('SELECT permission FROM role_permission') as $r) {
            $rows[(string) $r['permission']] = true;
        }
        $versions = [];
        foreach ($db->all("SELECT subject_key, MAX(version) AS v FROM config_change WHERE subject_type = 'permission' GROUP BY subject_key") as $r) {
            $versions[(string) $r['subject_key']] = (int) $r['v'];
        }
        $out = [];
        foreach (Permissions::map() as $perm => $holders) {
            $out[] = ['perm' => $perm, 'holders' => $holders, 'locked' => in_array($perm, Permissions::LOCKED, true), 'read_only' => Permissions::isReadOnly($perm),
                'from_code' => !isset($rows[$perm]), 'version' => $versions[$perm] ?? 0, 'required' => Permissions::REQUIRED[$perm] ?? []];
        }
        return $out;
    }

    private static function refusal(string $code, string $perm): CwException
    {
        return match ($code) {
            'unknown_permission' => new CwException('unknown_permission', 'there is no permission ' . mb_substr($perm, 0, 64), 400),
            'locked' => new CwException('permission_locked', "{$perm} is never changed on the screen: every job needs it, or it guards people and settings", 409),
            'bad_role' => new CwException('bad_role', 'a job must be one of ' . implode(', ', Permissions::ROLES), 400),
            'no_holders' => new CwException('no_holders', 'at least one job must hold a permission (to stop a task, give it to the fewest jobs)', 422),
            'acting_job' => new CwException('acting_job', 'admin and the look-only jobs (' . implode(', ', Permissions::ADMIN_COMPATIBLE)
                . ') never get a permission that changes anything', 422),
            'required' => new CwException('required_job', "{$perm} always stays with " . implode(', ', Permissions::REQUIRED[$perm] ?? []), 422,
                ['required' => Permissions::REQUIRED[$perm] ?? []]),
            default => new CwException($code, $code, 422),
        };
    }
}
