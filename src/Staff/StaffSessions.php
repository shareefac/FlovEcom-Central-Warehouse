<?php

declare(strict_types=1);

namespace CW\Staff;

use CW\Auth\Sessions;
use CW\Db;

/**
 * Read side of the staff sessions for the Staff and access pages (docs/decisions.md Y23): who is signed in now, on how many
 * devices, since when and from which address. A session is named on a page by the first HANDLE characters of its row id (the
 * sha256 of the cookie token, never the token itself: knowing it signs nobody in); StaffAdmin::signOut ends one or all.
 */
final class StaffSessions
{
    /** Hex characters of a session's row id that name it on a page. */
    public const HANDLE = 16;

    /**
     * The live sessions (not ended, second factor done, active in the last 30 minutes, younger than 12 hours: Sessions), newest
     * activity first; of one person, or of everyone (null).
     *
     * @return list<array{handle: string, staff_user_id: int, name: string, created_at: string, last_seen_at: string, ip: ?string}>
     */
    public static function live(Db $db, ?int $staffUserId = null): array
    {
        $out = [];
        foreach ($db->all(
            'SELECT LEFT(s.id, ' . self::HANDLE . ') AS handle, s.staff_user_id, u.display_name, s.created_at, s.last_seen_at, s.ip '
            . 'FROM staff_session s JOIN staff_user u ON u.id = s.staff_user_id '
            . 'WHERE s.revoked = 0 AND s.mfa_at IS NOT NULL AND u.is_active = 1 '
            . 'AND s.created_at > NOW(6) - INTERVAL ' . Sessions::ABSOLUTE_SECONDS . ' SECOND '
            . 'AND s.last_seen_at > NOW(6) - INTERVAL ' . Sessions::IDLE_SECONDS . ' SECOND '
            . ($staffUserId === null ? '' : 'AND s.staff_user_id = ? ') . 'ORDER BY s.last_seen_at DESC LIMIT 200',
            $staffUserId === null ? [] : [$staffUserId],
        ) as $r) {
            $out[] = ['handle' => (string) $r['handle'], 'staff_user_id' => (int) $r['staff_user_id'], 'name' => (string) $r['display_name'],
                'created_at' => (string) $r['created_at'], 'last_seen_at' => (string) $r['last_seen_at'], 'ip' => $r['ip'] === null ? null : (string) $r['ip']];
        }
        return $out;
    }

    /** Whether $handle is the shape a page names a session by. */
    public static function isHandle(mixed $handle): bool
    {
        return is_string($handle) && preg_match('/^[0-9a-f]{' . self::HANDLE . '}$/D', $handle) === 1;
    }
}
