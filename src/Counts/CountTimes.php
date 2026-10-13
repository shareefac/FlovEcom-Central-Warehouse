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
 * time T without changing anything the engine works out, as long as nothing happened to its stock between the two:
 *
 *   T is at or after `since` (the last thing that happened to the line's stock at or before `at`: a stock movement, an earlier
 *   count; null when nothing ever did), and `at` is no more than $window seconds after T.
 *
 * T is never later than a line's own time: a dispatch CW hears of only after the posting, made in the gap, is then taken off once
 * too often (the stock reads low, the safe side) and never once too seldom. The window keeps that gap inside the engine's own
 * "near a count" time (Stock::NEAR_COUNT_SEC), whose dispatches it sends to a person.
 *
 * groups() finds the fewest such times (lines by their time, earliest first: a line joins the latest time that fits it, else its
 * own time becomes one: the textbook answer to "fewest points that touch every interval"). When that is more than $max, the window
 * is doubled until it fits; with no window at all and still more than $max, there is no answer (null): the sheet cannot be
 * booked in one posting.
 */
final class CountTimes
{
    /**
     * @param list<array{line: int, at: DateTimeImmutable, since: ?DateTimeImmutable}> $lines
     * @return array{window: ?int, groups: list<array{at: DateTimeImmutable, lines: list<int>}>}|null groups by time, earliest
     *         first, each with its line numbers in the order given; `window`: the seconds used (null: none was kept)
     */
    public static function groups(array $lines, int $window, int $max): ?array
    {
        if ($window < 0 || $max < 1) {
            throw new \InvalidArgumentException('a window is 0 seconds or more and a posting has room for 1 time or more');
        }
        if ($lines === []) {
            return ['window' => $window, 'groups' => []];
        }
        for ($w = $window; ; $w = max(1, $w * 2)) {
            $unbounded = $w > 366 * 86_400;
            $groups = self::stab($lines, $unbounded ? null : $w);
            if (count($groups) <= $max) {
                return ['window' => $unbounded ? null : $w, 'groups' => $groups];
            }
            if ($unbounded) {
                return null;
            }
        }
    }

    /**
     * @param list<array{line: int, at: DateTimeImmutable, since: ?DateTimeImmutable}> $lines
     * @return list<array{at: DateTimeImmutable, lines: list<int>}>
     */
    private static function stab(array $lines, ?int $window): array
    {
        $rows = [];
        foreach ($lines as $i => $l) {
            $at = self::micros($l['at']);
            $since = $l['since'] === null ? null : self::micros($l['since']);
            if ($since !== null && $since > $at) {
                throw new \InvalidArgumentException("line {$l['line']}: `since` is the last thing at or before its own time");
            }
            $low = $window === null ? $since : max($since ?? PHP_INT_MIN, $at - $window * 1_000_000);
            $rows[] = ['i' => $i, 'line' => $l['line'], 'at' => $at, 'low' => $low, 'time' => $l['at']];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['at'], $a['i']] <=> [$b['at'], $b['i']]);
        /** @var list<array{t: int, at: DateTimeImmutable, lines: array<int, int>}> $groups earliest first */
        $groups = [];
        foreach ($rows as $r) {
            // Every time so far is at or before this line's own (the lines come earliest first), and the latest is the largest:
            // when it is before the line's lower bound, every earlier one is too.
            $last = count($groups) - 1;
            if ($last >= 0 && ($r['low'] === null || $groups[$last]['t'] >= $r['low'])) {
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
