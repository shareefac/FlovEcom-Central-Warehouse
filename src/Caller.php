<?php

declare(strict_types=1);

namespace CW;

/**
 * Who is calling a stock operation. A site calls as its channel; staff screens and system jobs
 * have no channel and are scoped by `source` instead (idempotency scope, D29).
 * `actor` is what the ledger and the audit log record: channel:<code> | staff:<id> | system:<job>.
 * `ip` (API and staff-UI calls) is the caller's REMOTE_ADDR, written to audit_log.ip.
 */
final class Caller
{
    private function __construct(
        public readonly ?int $channelId,
        public readonly string $source,
        public readonly string $actor,
        public readonly ?int $staffUserId = null,
        public readonly ?string $ip = null,
    ) {
    }

    /** $ip: the client address (REMOTE_ADDR) of an API call, recorded in audit_log.ip. */
    public static function channel(int $channelId, string $code, ?string $ip = null): self
    {
        if ($channelId <= 0 || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $code) !== 1) {
            throw new \InvalidArgumentException('invalid channel caller');
        }
        return new self($channelId, '', 'channel:' . $code, null, self::validIp($ip));
    }

    /** $ip: the client address (REMOTE_ADDR) of a /ui request, recorded in audit_log.ip. */
    public static function staff(int $staffUserId, ?string $ip = null): self
    {
        if ($staffUserId <= 0) {
            throw new \InvalidArgumentException('invalid staff user id');
        }
        return new self(null, 'staff', 'staff:' . $staffUserId, $staffUserId, self::validIp($ip));
    }

    /** $ip: for a job acting on a request it does not authenticate (a failed /ui login), see Ui\Kernel. */
    public static function system(string $job, ?string $ip = null): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,24}$/', $job) !== 1) {
            throw new \InvalidArgumentException('invalid system job name');
        }
        return new self(null, 'system:' . $job, 'system:' . $job, null, self::validIp($ip));
    }

    private static function validIp(?string $ip): ?string
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    public function isChannel(): bool
    {
        return $this->channelId !== null;
    }
}
