<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Clock;
use CW\Counts\CountTimes;
use CW\Counts\CountTolerance;
use PHPUnit\Framework\TestCase;

/**
 * The two pure rules of stock counting (pack A2a; docs/decisions.md CT3, CT6, CT8): which lines of a sheet may be booked under one
 * count time, and which differences a person must look at.
 */
final class CountRulesTest extends TestCase
{
    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-12 ' . $time, Clock::utc());
    }

    /** @param list<array{0: int, 1: string, 2?: ?string}> $lines [line, counted at, the last thing that happened to its stock before] */
    private static function grouped(array $lines, int $window = 600, int $max = 20): ?array
    {
        $r = CountTimes::groups(array_map(static fn (array $l): array => ['line' => $l[0], 'at' => self::at($l[1]),
            'since' => ($l[2] ?? null) === null ? null : self::at($l[2])], $lines), $window, $max);
        return $r === null ? null : ['window' => $r['window'], 'groups' => array_map(static fn (array $g): array => [$g['at']->format('H:i:s'), $g['lines']], $r['groups'])];
    }

    public function testLinesCountedWithinTheWindowShareTheEarliestTimeAndALaterOneStartsANewTime(): void
    {
        self::assertSame(['window' => 600, 'groups' => [['10:00:00', [1, 2, 3]], ['10:10:01', [4]]]],
            self::grouped([[1, '10:00:00'], [2, '10:04:00'], [3, '10:10:00'], [4, '10:10:01']]),
            'ten minutes after the first line is still inside; a second later is not');
        self::assertSame(['window' => 600, 'groups' => []], self::grouped([]));
        self::assertSame(['window' => 600, 'groups' => [['09:00:00', [7]]]], self::grouped([[7, '09:00:00']]));
    }

    public function testTheOrderOfTheLinesDoesNotMatterAndEveryLineIsInExactlyOneGroup(): void
    {
        $g = self::grouped([[5, '10:20:00'], [1, '10:00:00'], [9, '10:25:00'], [2, '10:01:00']]);
        self::assertSame([['10:00:00', [1, 2]], ['10:20:00', [5, 9]]], $g['groups']);
    }

    public function testALineWhoseStockMovedSinceTheEarlierTimeNeverSharesIt(): void
    {
        // Line 2's stock moved at 10:02, after 10:00 and before it was counted at 10:04: under 10:00 the engine would take that
        // movement for one "after the count". It gets a time of its own; line 3 (nothing moved) may still use either.
        $g = self::grouped([[1, '10:00:00'], [2, '10:04:00', '10:02:00'], [3, '10:05:00']]);
        self::assertSame([['10:00:00', [1]], ['10:04:00', [2, 3]]], $g['groups']);
        // Something that happened AT the earlier time is not after it: the line may share it.
        self::assertSame([['10:00:00', [1, 2]]], self::grouped([[1, '10:00:00'], [2, '10:04:00', '10:00:00']])['groups']);
        // A movement long before changes nothing.
        self::assertSame([['10:00:00', [1, 2]]], self::grouped([[1, '10:00:00'], [2, '10:04:00', '08:00:00']])['groups']);
        // A group's time is never later than any of its lines' own.
        foreach (self::grouped([[1, '10:00:00'], [2, '10:04:00', '10:02:00'], [3, '10:05:00'], [4, '10:30:00', '10:29:59']])['groups'] as [$time, $lines]) {
            self::assertContains($time, ['10:00:00', '10:04:00', '10:30:00']);
        }
    }

    public function testMoreTimesThanAPostingHasRoomForWidenTheWindowAndWhenThatCannotHelpThereIsNoAnswer(): void
    {
        // Six lines an hour apart: six times at ten minutes; with room for three the window grows until three are enough.
        $hourly = [[1, '01:00:00'], [2, '02:00:00'], [3, '03:00:00'], [4, '04:00:00'], [5, '05:00:00'], [6, '06:00:00']];
        self::assertCount(6, self::grouped($hourly)['groups']);
        $three = self::grouped($hourly, 600, 3);
        self::assertCount(3, $three['groups']);
        self::assertGreaterThan(600, $three['window']);
        self::assertSame([['01:00:00', [1, 2]], ['03:00:00', [3, 4]], ['05:00:00', [5, 6]]], $three['groups']);
        // Stock that moved before every line keeps each line on its own time whatever the window: no answer with room for two.
        $busy = [[1, '01:00:00'], [2, '02:00:00', '01:30:00'], [3, '03:00:00', '02:30:00']];
        self::assertNull(self::grouped($busy, 600, 2));
        self::assertCount(3, self::grouped($busy, 600, 3)['groups']);
        // With room for one time only, the window grows until the first line's time serves them all.
        self::assertSame(['window' => 19200, 'groups' => [['01:00:00', [1, 2, 3, 4, 5, 6]]]], self::grouped($hourly, 600, 1));
        // Lines years apart: no window is kept at all (null), and lines whose stock never moved share the first time.
        $far = CountTimes::groups([['line' => 1, 'at' => new \DateTimeImmutable('2024-01-01 10:00:00', Clock::utc()), 'since' => null],
            ['line' => 2, 'at' => new \DateTimeImmutable('2026-10-12 10:00:00', Clock::utc()), 'since' => null]], 600, 1);
        self::assertNotNull($far);
        self::assertNull($far['window']);
        self::assertSame([[1, 2]], array_column($far['groups'], 'lines'));
    }

    public function testADifferenceIsOverTheUnitsLimitOnlyAboveItAndZeroMeansEveryDifference(): void
    {
        self::assertSame([], CountTolerance::over(100, 5, 5, 0), 'at the limit');
        self::assertSame([], CountTolerance::over(100, -4, 5, 0), 'below it');
        self::assertSame(['units'], CountTolerance::over(100, 6, 5, 0), 'above it');
        self::assertSame(['units'], CountTolerance::over(100, -6, 5, 0), 'missing counts as found does');
        self::assertSame(['units'], CountTolerance::over(100, 1, 0, 0), 'a limit of 0: every difference');
        self::assertSame([], CountTolerance::over(100, 0, 0, 0), 'no difference is never over anything');
    }

    public function testADifferenceIsOverThePercentLimitOnlyAboveItAndZeroMeansThePercentIsNotUsed(): void
    {
        self::assertSame([], CountTolerance::over(50, 5, 1000, 10), 'exactly 10 percent of 50');
        self::assertSame([], CountTolerance::over(50, -4, 1000, 10), 'below it');
        self::assertSame(['percent'], CountTolerance::over(50, 6, 1000, 10), 'above it');
        self::assertSame(['percent'], CountTolerance::over(50, -6, 1000, 10));
        self::assertSame([], CountTolerance::over(50, 49, 1000, 0), 'percent 0: not used');
        self::assertSame(['units', 'percent'], CountTolerance::over(50, 20, 5, 10), 'over both');
        self::assertSame(['units'], CountTolerance::over(1000, 20, 5, 10), 'over the units only: 2 percent of 1,000');
    }

    public function testOnAnItemTheSystemHeldNothingOfAnyDifferenceIsOverThePercentLimit(): void
    {
        self::assertSame(['percent'], CountTolerance::over(0, 1, 5, 10), 'one found where none was held');
        self::assertSame(['percent'], CountTolerance::over(-3, 2, 5, 10), 'held less than nothing');
        self::assertSame([], CountTolerance::over(0, 3, 5, 0), 'the percent is not used: only the units count');
        self::assertSame(['units'], CountTolerance::over(0, 6, 5, 0));
        self::assertSame([], CountTolerance::over(0, 0, 0, 10), 'nothing held, nothing found');
    }

    public function testWhoNeedsASecondPersonFollowsTheRuleOfTheKindOfRecord(): void
    {
        self::assertFalse(CountTolerance::needsSecondPerson('none', 10, 500));
        self::assertTrue(CountTolerance::needsSecondPerson('all', null, 1));
        self::assertFalse(CountTolerance::needsSecondPerson('over_limit', 10, 10), 'at the limit');
        self::assertTrue(CountTolerance::needsSecondPerson('over_limit', 10, -11), 'above it, up or down');
        self::assertTrue(CountTolerance::needsSecondPerson('over_limit', 0, 1));
    }
}
