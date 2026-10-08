<?php

declare(strict_types=1);

namespace CW\Auth;

use CW\Db;

/**
 * Staff sessions (staff_session; plan §11): a random 256-bit token lives only in the cookie, the
 * row is keyed by its sha256, so a database read never yields a usable session. A session is live
 * while it is not revoked, the second factor was completed (mfa_at), it was seen in the last
 * IDLE_SECONDS and is younger than ABSOLUTE_SECONDS. Login always creates a new session (rotation);
 * expired sessions are revoked on sight. All times are the database's UTC clock.
 */
final class Sessions
{
    public const IDLE_SECONDS = 1800;      // 30 min
    public const ABSOLUTE_SECONDS = 43200; // 12 h

    public function __construct(private readonly Db $db)
    {
    }

    /** sha256 hex of a well-formed token (43 base64url characters), else null. */
    public static function idOf(?string $token): ?string
    {
        return $token !== null && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1 ? hash('sha256', $token) : null;
    }

    /**
     * A new, fully authenticated session (call after the password and the TOTP code were checked).
     *
     * @return array{token: string, id: string}
     */
    public function create(int $staffUserId, string $ip, ?string $userAgent): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = hash('sha256', $token);
        $this->db->exec(
            'INSERT INTO staff_session (id, staff_user_id, mfa_at, ip, user_agent_hash) VALUES (?, ?, NOW(6), ?, ?)',
            [$id, $staffUserId, mb_substr($ip, 0, 45), $userAgent === null || $userAgent === '' ? null : hash('sha256', $userAgent)],
        );
        return ['token' => $token, 'id' => $id];
    }

    /** The live session behind a cookie token (and marks it seen), or null. */
    public function resolve(?string $token): ?StaffIdentity
    {
        $id = self::idOf($token);
        if ($id === null) {
            return null;
        }
        // The roles are read here, on every request (I11): a role taken away stops working on the person's next page.
        $r = $this->db->one(
            'SELECT s.staff_user_id, s.revoked, u.email, u.display_name, u.is_active, u.password_must_change, '
            . '(SELECT GROUP_CONCAT(r.role ORDER BY r.role) FROM staff_role r WHERE r.staff_user_id = u.id AND r.revoked_at IS NULL) AS roles, '
            . '(s.revoked = 0 AND s.mfa_at IS NOT NULL '
            . 'AND s.created_at > NOW(6) - INTERVAL ' . self::ABSOLUTE_SECONDS . ' SECOND '
            . 'AND s.last_seen_at > NOW(6) - INTERVAL ' . self::IDLE_SECONDS . ' SECOND) AS live '
            . 'FROM staff_session s JOIN staff_user u ON u.id = s.staff_user_id WHERE s.id = ?',
            [$id],
        );
        if ($r === null) {
            return null;
        }
        if ((int) $r['live'] !== 1 || (int) $r['is_active'] !== 1) {
            $this->revoke($id); // expired or the person was switched off: the row can never come back
            return null;
        }
        $this->db->exec('UPDATE staff_session SET last_seen_at = NOW(6) WHERE id = ? AND revoked = 0', [$id]);
        $roles = $r['roles'] === null || $r['roles'] === '' ? [] : explode(',', (string) $r['roles']);
        return new StaffIdentity((int) $r['staff_user_id'], (string) $r['email'], (string) $r['display_name'], $roles,
            (int) $r['password_must_change'] === 1, $id);
    }

    public function revoke(string $id): void
    {
        $this->db->exec('UPDATE staff_session SET revoked = 1, revoked_at = NOW(6) WHERE id = ? AND revoked = 0', [$id]);
    }

    /** Revokes a session by cookie token (login rotation, logout). */
    public function revokeToken(?string $token): void
    {
        $id = self::idOf($token);
        if ($id !== null) {
            $this->revoke($id);
        }
    }

    /**
     * Signs a person out everywhere, this browser included (password change, staff reset). Returns how many sessions were still
     * LIVE (review nit: an idle or too old session that was never revoked is closed too, but not counted as "signed out").
     */
    public function revokeAll(int $staffUserId): int
    {
        $live = $this->db->exec(
            'UPDATE staff_session SET revoked = 1, revoked_at = NOW(6) WHERE staff_user_id = ? AND revoked = 0 AND mfa_at IS NOT NULL '
            . 'AND created_at > NOW(6) - INTERVAL ' . self::ABSOLUTE_SECONDS . ' SECOND AND last_seen_at > NOW(6) - INTERVAL ' . self::IDLE_SECONDS . ' SECOND',
            [$staffUserId],
        );
        $this->db->exec('UPDATE staff_session SET revoked = 1, revoked_at = NOW(6) WHERE staff_user_id = ? AND revoked = 0', [$staffUserId]);
        return $live;
    }
}
