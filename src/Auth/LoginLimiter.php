<?php

declare(strict_types=1);

namespace CW\Auth;

use CW\Db;

/**
 * Failed-login throttling on login_attempt only (per account and per client address), so an
 * unknown account is limited exactly like a known one and the answer never says which exists.
 * Rolling window: sign-in is refused while an account has ACCOUNT_MAX failures, or an address
 * IP_MAX, in the last WINDOW_SECONDS (10 failures -> 15 minutes' pause, plan §11). Refused
 * attempts are not recorded, so an attacker cannot extend a lock by hammering it. The address
 * limit is higher because several staff may share one office address.
 *
 * The check and the charge are one step (exclusive()): an attempt holds a named lock of its account
 * and of its address from the count to the record of its outcome, so attempts that arrive together
 * are counted one after another (without it, every attempt in flight when the count crosses the
 * limit still had its password and code checked: 10 at once became 19 failures). The ~330 ms of
 * argon2id per attempt then queues behind the lock; an attempt that cannot get it within
 * LOCK_WAIT_SECONDS is refused like a locked one (and, like it, not recorded).
 */
final class LoginLimiter
{
    public const ACCOUNT_MAX = 10;
    public const IP_MAX = 30;
    public const WINDOW_SECONDS = 900;
    public const LOCK_WAIT_SECONDS = 10;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Runs $fn (count, verify, record) while holding the named locks of the account ($login, when it is
     * one) and of the address, always in that order. Returns null, without running $fn, when a lock
     * cannot be had in time.
     *
     * @template T
     * @param callable(): T $fn
     * @return T|null
     */
    public function exclusive(?string $login, string $ip, callable $fn): mixed
    {
        $held = [];
        try {
            foreach (array_filter(['account' => $login, 'ip' => mb_substr($ip, 0, 45)], static fn (?string $v): bool => $v !== null) as $kind => $value) {
                $name = 'cw_login:' . sha1(implode("\0", [$this->db->settings->database ?? '', $kind, $value]));
                if ((int) $this->db->value('SELECT GET_LOCK(?, ?)', [$name, self::LOCK_WAIT_SECONDS]) !== 1) {
                    return null;
                }
                $held[] = $name;
            }
            return $fn();
        } finally {
            foreach (array_reverse($held) as $name) {
                try {
                    $this->db->value('SELECT RELEASE_LOCK(?)', [$name]);
                } catch (\Throwable) {
                    // The connection is gone, and the lock with it.
                }
            }
        }
    }

    /** 'account', 'ip' or null. $login is the normalised e-mail (null when the typed value was no e-mail). */
    public function lockedBy(?string $login, string $ip): ?string
    {
        if ($login !== null && $this->accountFailures($login) >= self::ACCOUNT_MAX) {
            return 'account';
        }
        return $this->ipFailures($ip) >= self::IP_MAX ? 'ip' : null;
    }

    /** Failures of an account in the window, not counting those before its last completed sign-in. */
    public function accountFailures(string $login): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempt WHERE login = ? AND success = 0 AND created_at > NOW(6) - INTERVAL ' . self::WINDOW_SECONDS . ' SECOND '
            . 'AND id > COALESCE((SELECT MAX(s.id) FROM login_attempt s WHERE s.login = ? AND s.success = 1), 0)',
            [$login, $login],
        );
    }

    public function ipFailures(string $ip): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempt WHERE ip = ? AND success = 0 AND created_at > NOW(6) - INTERVAL ' . self::WINDOW_SECONDS . ' SECOND',
            [mb_substr($ip, 0, 45)],
        );
    }

    /** @param 'password'|'totp' $step */
    public function record(?string $login, ?int $staffUserId, string $ip, string $step, bool $success): void
    {
        $this->db->exec(
            'INSERT INTO login_attempt (login, staff_user_id, ip, step, success) VALUES (?, ?, ?, ?, ?)',
            [$login, $staffUserId, mb_substr($ip, 0, 45), $step, $success ? 1 : 0],
        );
    }
}
