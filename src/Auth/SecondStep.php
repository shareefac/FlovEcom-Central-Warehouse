<?php

declare(strict_types=1);

namespace CW\Auth;

use CW\Arrivals\UkTime;
use CW\Clock;
use CW\Db;

/**
 * Whether the sign-in asks for the 6-digit code (the second step) now: the setting auth.totp_off_until (0048; the owner's decision of
 * 20 Oct 2026, against advice; docs/decisions.md TF1-TF6). It holds a UK time as typed on the Settings page ("YYYY-MM-DD HH:MM"); while
 * that time is ahead, Login takes the e-mail and the password only. Fail closed: an empty, past or unreadable value, a time that happens
 * twice or not at all in the UK, one more than MAX_DAYS ahead (only SQL could store it), or no row at all (a schema before 0048): the
 * code is asked for, exactly as before. Read on every call (a page and a sign-in each read it once): a change applies at once.
 */
final class SecondStep
{
    public const SETTING = 'auth.totp_off_until';
    /** The furthest ahead the switch may end (Settings::RULES `ahead_max_days`). */
    public const MAX_DAYS = 90;
    /** The form of the setting's text. */
    public const FORM = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D';

    /**
     * The moment (UTC) until which the sign-in asks for no code, or null: the code is asked for. $now: the clock (tests). $lock (inside a
     * transaction): the setting's row is read FOR SHARE, so a change of it waits for the transaction, and one made before is seen (TF8).
     */
    public static function offUntil(Db $db, ?\DateTimeImmutable $now = null, bool $lock = false): ?\DateTimeImmutable
    {
        $raw = $db->value("SELECT CAST(value_json AS CHAR) FROM app_setting WHERE setting_key = ? AND value_type = 'string'" . ($lock ? ' FOR SHARE' : ''),
            [self::SETTING]);
        $text = $raw === null ? null : json_decode((string) $raw, false, 4);
        $at = is_string($text) ? self::moment($text) : null;
        $now ??= Clock::now();
        if ($at === null || $at <= $now || $at > $now->modify('+' . self::MAX_DAYS . ' days')) {
            return null;
        }
        return $at;
    }

    /** A moment as the setting writes it: its UK time, "YYYY-MM-DD HH:MM" (what the audit row of a sign-in without the code says). */
    public static function text(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone(UkTime::ZONE))->format('Y-m-d H:i');
    }

    /**
     * The one moment (UTC) of a UK time typed "YYYY-MM-DD HH:MM", or null: not that form, not a real time, or one that happens twice
     * (the hour the clocks go back) or not at all (the hour they go forward) that day in the UK.
     */
    public static function moment(string $text): ?\DateTimeImmutable
    {
        if (preg_match(self::FORM, $text) !== 1) {
            return null;
        }
        $at = UkTime::moments(str_replace(' ', 'T', $text));
        return count($at) === 1 ? array_values($at)[0] : null;
    }
}
