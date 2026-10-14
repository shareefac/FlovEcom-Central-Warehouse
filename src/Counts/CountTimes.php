<?php

declare(strict_types=1);

namespace CW\Counts;

use DateTimeImmutable;

/**
 * Which lines of a count sheet may be booked together (pack A2a; docs/decisions.md CT3). Pure: no database.
 *
 * The engine takes ONE count time per movement and at most Movements::MAX_DOCUMENT_MOVEMENTS movements a posting, while every
 * line of a sheet was counted at its own moment. The engine's rule is about what happened AFTER the count time: a dispatch after
 * it comes off the counted figure, one before it does not (plan §8.2). So a line counted at `at` may be booked under an EARLIER
 * time T without changing anything the engine works out at the posting, as long as nothing happened to its stock between the two:
 *
 *   T is at or after `since` (the last thing that happened to the line's stock at or before `at`: a stock movement, an earlier
 *   count; null when nothing ever did), and `at` is no more than $window seconds after T.
 *
 * T is never later than a line's own time. What is NOT the same afterwards (review I-1, 14 Oct 2026): the item's count time is T, so
 * a dispatch or a reset CW hears of only after the posting, made in the gap (T, at], is judged against T. A late dispatch is taken
 * off once too often (the figure reads one low); a late reset is added although the count already had that unit (one HIGH). The
 * window keeps the gap inside the engine's own "near a count" time (Stock::NEAR_COUNT_SEC), whose reviews (ship_near_count,
 * unship_near_count) flag exactly those, and Stock › Counts › Differences lists them.
 *
 * groups() finds the fewest such times (lines by their time, earliest first: a line joins the latest time that fits it, else its
 * own time becomes one: the textbook answer to "fewest points that touch every interval"). When that is more than $max, the window
 * is doubled, but never past $limit (the setting counts.time_window_max_minutes, default 10 minutes: no widening at all; the last
 * try is $limit itself). When even that needs more than $max times there is no answer (null): the sheet cannot be booked in one
 * posting, and groupsWithin() at $limit says which lines started the earliest times (the ones to count again).
 */
final class CountTimes
{
    /**
     * @param list<array{line: int, at: DateTimeImmutable, since: ?DateTimeImmutable}> $lines
     * @param int $window the window to start with (seconds; the engine's "near a count" time)
     * @param int $limit the widest the window may become (seconds); a limit below $window narrows the window itself
     * @return array{window: int, groups: list<array{at: DateTimeImmutable, lines: list<int>}>}|null groups by time, earliest first, each
     *         with its line numbers in the order given; `window`: the seconds used. Null: more than $max times even at $limit.
     */
    public static function groups(array $lines, int $window, int $max, int $limit): ?array
    {
        if ($window < 0 || $limit < 0 || $max < 1) {
            throw new \InvalidArgumentException('a window and its limit are 0 seconds or more and a posting has room for 1 time or more');
        }
        $w = min($window, $limit);
        if ($lines === []) {
            return ['window' => $w, 'groups' => []];
        }
        while (true) {
            $groups = self::groupsWithin($lines, $w);
            if (count($groups) <= $max) {
                return ['window' => $w, 'groups' => $groups];
            }
            if ($w >= $limit) {
                return null;
            }
            $w = min($limit, max(1, $w * 2));
        }
    }

    /**
     * The fewest count times for one window, however many that is (groups() keeps to a posting's room).
     *
     * @param list<array{line: int, at: DateTimeImmutable, since: ?DateTimeImmutable}> $lines
     * @return list<array{at: DateTimeImmutable, lines: list<int>}> earliest first
     */
    public static function groupsWithin(array $lines, int $window): array
    {
        $rows = [];
        foreach ($lines as $i => $l) {
            $at = self::micros($l['at']);
            $since = $l['since'] === null ? null : self::micros($l['since']);
            if ($since !== null && $since > $at) {
                throw new \InvalidArgumentException("line {$l['line']}: `since` is the last thing at or before its own time");
            }
            $low = max($since ?? PHP_INT_MIN, $at - $window * 1_000_000);
            $rows[] = ['i' => $i, 'line' => $l['line'], 'at' => $at, 'low' => $low, 'time' => $l['at']];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['at'], $a['i']] <=> [$b['at'], $b['i']]);
        /** @var list<array{t: int, at: DateTimeImmutable, lines: array<int, int>}> $groups earliest first */
        $groups = [];
        foreach ($rows as $r) {
            // Every time so far is at or before this line's own (the lines come earliest first), and the latest is the largest:
            // when it is before the line's lower bound, every earlier one is too.
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last]['t'] >= $r['low']) {
                $groups[$last]['lines'][$r['i']] = $r['line'];
            } else {
                $groups[] = ['t' => $r['at'], 'at' => $r['time'], 'lines' => [$r['i'] => $r['line']]];
            }
        }
        $out = [];
        foreach ($groups as $g) {
            ksort($g['lines']);
            $out[] = ['at' => $g['at'], 'lines' => array_values($g['lines'])];
        }
        return $out;
    }

    private static function micros(DateTimeImmutable $t): int
    {
        return (int) $t->format('U') * 1_000_000 + (int) $t->format('u');
    }
}
