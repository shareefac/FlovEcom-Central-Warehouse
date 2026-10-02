<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\CwException;
use CW\Db;

/**
 * Read side of staff_role (0007, I10): a person's live roles, their history and how many active people hold
 * a role. Writes are StaffAdmin's (create, setRoles). A grant is live while revoked_at IS NULL; at most one
 * live grant per person and role (the generated active_staff_user_id + UNIQUE).
 */
final class StaffRoles
{
    /**
     * The live roles of an ACTIVE person, sorted: what a service checks before it acts for them (services re-read
     * the roles inside their own transaction, I11). 403 staff_not_allowed for an unknown or inactive person.
     *
     * @return list<string>
     */
    public static function active(Db $db, int $staffUserId): array
    {
        $u = $db->one(
            'SELECT u.is_active, (SELECT GROUP_CONCAT(r.role ORDER BY r.role) FROM staff_role r '
            . 'WHERE r.staff_user_id = u.id AND r.revoked_at IS NULL) AS roles FROM staff_user u WHERE u.id = ?',
            [$staffUserId],
        );
        if ($u === null || (int) $u['is_active'] !== 1) {
            throw new CwException('staff_not_allowed', 'unknown or inactive staff user', 403);
        }
        return self::split($u['roles']);
    }

    /**
     * The live roles of any person, active or not (the People screen, StaffAdmin), sorted; [] for an unknown id.
     *
     * @return list<string>
     */
    public static function of(Db $db, int $staffUserId): array
    {
        return self::split($db->value(
            'SELECT GROUP_CONCAT(role ORDER BY role) FROM staff_role WHERE staff_user_id = ? AND revoked_at IS NULL',
            [$staffUserId],
        ));
    }

    /**
     * Every grant of a person, newest first: role, granted at/by, revoked at/by (names of the admins; NULL
     * granted_by = a CLI tool or the 0007 backfill).
     *
     * @return list<array<string, mixed>>
     */
    public static function history(Db $db, int $staffUserId): array
    {
        return $db->all(
            'SELECT r.id, CAST(r.role AS CHAR) AS role, r.granted_at, r.granted_by, g.display_name AS granted_by_name, '
            . 'r.revoked_at, r.revoked_by, v.display_name AS revoked_by_name '
            . 'FROM staff_role r LEFT JOIN staff_user g ON g.id = r.granted_by LEFT JOIN staff_user v ON v.id = r.revoked_by '
            . 'WHERE r.staff_user_id = ? ORDER BY r.granted_at DESC, r.id DESC',
            [$staffUserId],
        );
    }

    /** How many ACTIVE people hold $role now (decision 3: at least two reviewers; at least one admin). */
    public static function activeCount(Db $db, string $role): int
    {
        return (int) $db->value(
            'SELECT COUNT(*) FROM staff_role r JOIN staff_user u ON u.id = r.staff_user_id '
            . 'WHERE r.role = ? AND r.revoked_at IS NULL AND u.is_active = 1',
            [$role],
        );
    }

    /**
     * Everyone, for the People and roles list: id, display_name, email, is_active, last_login_at, created_at and
     * the live roles (sorted).
     *
     * @return list<array{id: int, display_name: string, email: ?string, is_active: bool, last_login_at: mixed, created_at: mixed, roles: list<string>}>
     */
    public static function people(Db $db): array
    {
        $out = [];
        foreach ($db->all(
            'SELECT u.id, u.display_name, u.email, u.is_active, u.last_login_at, u.created_at, '
            . '(SELECT GROUP_CONCAT(r.role ORDER BY r.role) FROM staff_role r WHERE r.staff_user_id = u.id AND r.revoked_at IS NULL) AS roles '
            . 'FROM staff_user u ORDER BY u.is_active DESC, u.display_name, u.id',
        ) as $r) {
            $out[] = ['id' => (int) $r['id'], 'display_name' => (string) $r['display_name'], 'email' => $r['email'] === null ? null : (string) $r['email'],
                'is_active' => (int) $r['is_active'] === 1, 'last_login_at' => $r['last_login_at'], 'created_at' => $r['created_at'],
                'roles' => self::split($r['roles'])];
        }
        return $out;
    }

    /** @return list<string> sorted (PHP byte order: ENUM columns sort by their index in SQL) */
    private static function split(mixed $concat): array
    {
        if (!is_string($concat) || $concat === '') {
            return [];
        }
        $roles = array_values(array_unique(explode(',', $concat)));
        sort($roles);
        return $roles;
    }
}
