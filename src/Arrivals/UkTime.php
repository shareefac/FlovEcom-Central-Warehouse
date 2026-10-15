<?php

declare(strict_types=1);

namespace CW\Arrivals;

/**
 * A time typed on a screen in UK time (Europe/London), as the moments it can mean (pack J, review finding 6, Codex 15 Oct 2026): one
 * on most days; two in the hour the clocks go back (01:30 BST, then 01:30 GMT an hour later), which the person chooses between; none
 * in the hour the clocks go forward (01:30 does not happen that night). Pure.
 */
final class UkTime
{
    public const ZONE = 'Europe/London';
    /** The UK's two clocks and their offsets from UTC. */
    public const CLOCKS = ['BST' => '+01:00', 'GMT' => '+00:00'];

    /**
     * The moments (UTC) a "Y-m-d\TH:i" UK time can mean, keyed by the clock it is on: [] when it is not such a time or does not happen.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public static function moments(string $typed): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $typed) !== 1) {
            return [];
        }
        $london = new \DateTimeZone(self::ZONE);
        $out = [];
        foreach (self::CLOCKS as $clock => $offset) {
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:iP', $typed . $offset);
            if ($at === false) {
                continue;
            }
            $local = $at->setTimezone($london);
            if ($local->format('Y-m-d\TH:i') === $typed && $local->format('T') === $clock) {
                $out[$clock] = $at->setTimezone(new \DateTimeZone('UTC'));
            }
        }
        return $out;
    }

    /** Whether the UK time of this moment happens twice (the hour the clocks go back): the screens then say which clock it is on. */
    public static function happensTwice(\DateTimeImmutable $at): bool
    {
        return count(self::moments($at->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d\TH:i'))) > 1;
    }

    /** The clock (BST or GMT) of this moment in the UK. */
    public static function clock(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone(self::ZONE))->format('T');
    }
}
