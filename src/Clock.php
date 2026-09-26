<?php

declare(strict_types=1);

namespace CW;

use DateTimeImmutable;
use DateTimeZone;

/** UTC time helpers. Every stored time is DATETIME(6) UTC ('Y-m-d H:i:s.u'). */
final class Clock
{
    public const DB = 'Y-m-d H:i:s.u';

    public static function utc(): DateTimeZone
    {
        static $tz = null;
        return $tz ??= new DateTimeZone('UTC');
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::utc());
    }

    public static function db(DateTimeImmutable $t): string
    {
        return $t->setTimezone(self::utc())->format(self::DB);
    }

    /** DB value -> ISO-8601 UTC for API bodies. */
    public static function iso(?string $db): ?string
    {
        if ($db === null) {
            return null;
        }
        return self::fromDb($db)->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function fromDb(string $db): DateTimeImmutable
    {
        $t = DateTimeImmutable::createFromFormat(self::DB, $db, self::utc())
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $db, self::utc());
        if ($t === false) {
            throw new \UnexpectedValueException('bad DATETIME value');
        }
        return $t;
    }

    /** How far ahead of CW's clock a caller's event time may be (clock skew between boxes). */
    public const MAX_AHEAD_SEC = 300;

    /**
     * Parses an API time: ISO-8601 with a zone/offset (converted to UTC) or a zone-less
     * 'Y-m-d H:i:s[.u]', which is taken as UTC (sites convert London time before sending, §6.4).
     * The date must exist on the calendar (2026-02-31 is refused, not rolled over to March), the
     * offset must be a real one (at most ±14:00) and the UTC result must stay within years 1-9999
     * (the DATETIME range). Anything else is 400 bad_time.
     */
    public static function parse(mixed $value, string $field): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(self::utc());
        }
        $bad = static fn (string $why = 'must be an ISO-8601 time'): CwException
            => new CwException('bad_time', "{$field} {$why}", 400, ['field' => $field]);
        if (!is_string($value) || $value === '' || strlen($value) > 40
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-](\d{2}):?(\d{2}))?$/', $value, $m) !== 1) {
            throw $bad();
        }
        $second = ($m[6] ?? '') === '' ? 0 : (int) $m[6];
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || $second > 59) {
            throw $bad('is not a real date and time');
        }
        if (($m[8] ?? '') !== '' && ((int) $m[8] > 14 || (int) $m[9] > 59)) {
            throw $bad('has an impossible UTC offset');
        }
        try {
            $t = (new DateTimeImmutable($value, self::utc()))->setTimezone(self::utc());
        } catch (\Exception) {
            throw $bad();
        }
        $year = (int) $t->format('Y');
        if ($year < 1 || $year > 9999) {
            throw $bad('is outside the years 1-9999 in UTC');
        }
        return $t;
    }

    /**
     * Plausibility of a time a caller reports for something that already happened (a dispatch, a
     * dispatch reset, a count, T0): at most MAX_AHEAD_SEC after CW's clock $now, and at most
     * $maxAgeSec before it. 400 bad_time otherwise (not stored: the caller fixes and resends).
     * A future count or dispatch would otherwise be "after" every real event (every real ship
     * would become pre-count and every recount stale); a very old one rewrites history.
     */
    public static function checkWindow(DateTimeImmutable $t, string $field, DateTimeImmutable $now, int $maxAgeSec,
        int $maxAheadSec = self::MAX_AHEAD_SEC): void
    {
        $ahead = self::diff($now, $t);
        $detail = ['field' => $field, 'value' => self::iso(self::db($t)), 'now' => self::iso(self::db($now))];
        if ($ahead > $maxAheadSec) {
            throw new CwException('bad_time', "{$field} is in the future (more than {$maxAheadSec} s after CW's clock)", 400,
                $detail + ['reason' => 'in_future']);
        }
        if (-$ahead > $maxAgeSec) {
            throw new CwException('bad_time', "{$field} is older than CW accepts (" . intdiv($maxAgeSec, 3600) . ' h)', 400,
                $detail + ['reason' => 'too_old']);
        }
    }

    /** Seconds between two times (b - a), with microseconds. */
    public static function diff(DateTimeImmutable $a, DateTimeImmutable $b): float
    {
        return ((float) $b->format('U.u')) - ((float) $a->format('U.u'));
    }
}
