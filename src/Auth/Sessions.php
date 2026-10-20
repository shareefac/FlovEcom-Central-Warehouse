<?php

declare(strict_types=1);

namespace CW\Auth;

use CW\Db;
use CW\Settings;

/**
 * Staff sessions (staff_session; plan §11): a random 256-bit token lives only in the cookie, the
 * row is keyed by its sha256, so a database read never yields a usable session. A session is live
 * while it is not revoked, the second factor was completed (mfa_at), it was seen within the idle
 * limit and is younger than the absolute limit (limits(): the settings session.idle_minutes and
 * session.absolute_hours, 0047, docs/decisions.md SL1-SL5). Login always creates a new session (rotation);
 * expired sessions are revoked on sight. All times are the database's UTC clock.
 */
final class Sessions
{
    /** The default idle limit (session.idle_minutes = 30): the fixed limit before 0047. */
    public const IDLE_SECONDS = 1800;      // 30 min
    /** The default absolute limit (session.absolute_hours = 12): the fixed limit before 0047. */
    public const ABSOLUTE_SECONDS = 43200; // 12 h
    public const IDLE_SETTING = 'session.idle_minutes';
    public const ABSOLUTE_SETTING = 'session.absolute_hours';

    /** @var array{idle: int, absolute: int}|null the limits in seconds, read once per instance */
    private ?array $limits = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** sha256 hex of a well-formed token (43 base64url characters), else null. */
    public static function idOf(?string $token): ?string
    {
        return $token !== null && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1 ? hash('sha256', $token) : null;
    }

    /**
     * The two limits in force, in seconds (SL2): how long a person may open no page (`idle`), and how long after signing in they are
     * signed out however active (`absolute`; also the session cookie's Max-Age, SL3). Read from the settings session.idle_minutes and
     * session.absolute_hours in one statement, once per instance (the kernel makes one per request, Ui\Context::sessions(), so a request
     * sees one set). A setting that is missing (a schema before 0047), not a whole number or outside its rule (Settings::RULES; only SQL
     * could store such a value) reads as its default, the limit of before (IDLE_SECONDS, ABSOLUTE_SECONDS); each setting on its own.
     *
     * @return array{idle: int, absolute: int}
     */
    public function limits(): array
    {
        if ($this->limits === null) {
            $stored = [];
            foreach ($this->db->all("SELECT setting_key, CAST(value_json AS CHAR) AS v FROM app_setting WHERE value_type = 'int' AND setting_key IN (?, ?)",
                [self::IDLE_SETTING, self::ABSOLUTE_SETTING]) as $r) {
                $stored[(string) $r['setting_key']] = trim((string) $r['v']);
            }
            $read = static function (string $key, int $unit, int $default) use ($stored): int {
                $raw = $stored[$key] ?? '';
                $rule = Settings::RULES[$key];
                if (preg_match('/^\d{1,9}$/D', $raw) !== 1 || (int) $raw < (int) $rule['min'] || (int) $raw > (int) $rule['max']) {
                    return $default;
                }
                return (int) $raw * $unit;
            };
            $this->limits = ['idle' => $read(self::IDLE_SETTING, 60, self::IDLE_SECONDS), 'absolute' => $read(self::ABSOLUTE_SETTING, 3600, self::ABSOLUTE_SECONDS)];
        }
        return $this->limits;
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

    /**
     * As create(), but only while the database's clock is still before $before (one statement: the check and the row together); null
     * when it is not. The sign-in without the code while 2-step is switched off (Auth\Login, docs/decisions.md TF12).
     *
     * @return array{token: string, id: string}|null
     */
    public function createBefore(int $staffUserId, string $ip, ?string $userAgent, \DateTimeImmutable $before): ?array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = hash('sha256', $token);
        $made = $this->db->exec(
            'INSERT INTO staff_session (id, staff_user_id, mfa_at, ip, user_agent_hash) SELECT ?, ?, NOW(6), ?, ? FROM DUAL WHERE NOW(6) < ?',
            [$id, $staffUserId, mb_substr($ip, 0, 45), $userAgent === null || $userAgent === '' ? null : hash('sha256', $userAgent), \CW\Clock::db($before)],
        );
        return $made === 1 ? ['token' => $token, 'id' => $id] : null;
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
            . '(' . $this->liveSql('s') . ') AS live '
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

    /**
     * The SQL condition of a live session row (alias $alias, '' for none) under the limits in force: not revoked, the second factor
     * done, seen within the idle limit, younger than the absolute limit. The limits are whole numbers (limits()), never typed text.
     */
    public function liveSql(string $alias = ''): string
    {
        if (preg_match('/^[a-z]?$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('bad alias');
        }
        $p = $alias === '' ? '' : $alias . '.';
        $l = $this->limits();
        return "{$p}revoked = 0 AND {$p}mfa_at IS NOT NULL AND {$p}created_at > NOW(6) - INTERVAL {$l['absolute']} SECOND "
            . "AND {$p}last_seen_at > NOW(6) - INTERVAL {$l['idle']} SECOND";
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
     * LIVE under the limits in force (review nit: an idle or too old session that was never revoked is closed too, but not counted as
     * "signed out").
     */
    public function revokeAll(int $staffUserId): int
    {
        $live = $this->db->exec('UPDATE staff_session SET revoked = 1, revoked_at = NOW(6) WHERE staff_user_id = ? AND ' . $this->liveSql(), [$staffUserId]);
        $this->db->exec('UPDATE staff_session SET revoked = 1, revoked_at = NOW(6) WHERE staff_user_id = ? AND revoked = 0', [$staffUserId]);
        return $live;
    }
}
