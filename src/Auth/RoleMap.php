<?php

declare(strict_types=1);

namespace CW\Auth;

use CW\Db;

/**
 * Reads who holds which permission from role_permission (0034, docs/decisions.md PE2) and puts it in force (Permissions::useStored),
 * the way the roles themselves are read: on every /ui request (Ui\Kernel, before the session's roles are checked) and again wherever a
 * service re-reads a person's roles inside its own transaction (Staff\StaffRoles::active), so a permission taken away on the screen
 * stops working on the next page and the next action. One small read (a row per permission). It never guesses (review finding 2,
 * PE17): when the read fails for any reason, a missing table included (a restore, a deploy half done), nothing is allowed
 * (Permissions::denyAll) and the failure is the request's (503 on a page; the service's action fails). The code's defaults never
 * stand in for the table: they would give back a permission someone took away on the screen.
 */
final class RoleMap
{
    /** Set only by runWithoutTable(): the code's map, the table not read. */
    private static bool $withoutTable = false;

    /**
     * An explicit choice, never inferred from an error (PE17): today's code runs against a schema from before 0034 with the code's
     * map (MIGRATION tests that drive today's services on a scratch schema migrated part of the way). The application never sets it.
     */
    public static function runWithoutTable(bool $on): void
    {
        self::$withoutTable = $on;
        Permissions::useStored(null);
    }

    public static function load(Db $db): void
    {
        if (self::$withoutTable) {
            Permissions::useStored(null);
            return;
        }
        try {
            $rows = $db->all('SELECT permission, roles FROM role_permission');
        } catch (\Throwable $e) {
            Permissions::denyAll();
            throw $e;
        }
        $stored = [];
        foreach ($rows as $r) {
            $roles = (string) $r['roles'];
            $stored[(string) $r['permission']] = $roles === '' ? [] : explode(',', $roles);
        }
        Permissions::useStored($stored);
    }
}
