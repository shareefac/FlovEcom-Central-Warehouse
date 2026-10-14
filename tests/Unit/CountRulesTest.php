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

    /**
     * @param list<array{0: int, 1: string, 2?: ?string}> $lines [line, counted at, the last thing that happened to its stock before]
     * @param int $limit the most the window may grow to (the setting counts.time_window_max_minutes, in seconds)
     */
    private static function grouped(array $lines, int $window = 600, int $max = 20, int $limit = 600): ?array
    {
        $r = CountTimes::groups(array_map(static fn (array $l): array => ['line' => $l[0], 'at' => self::at($l[1]),
            'since' => ($l[2] ?? null) === null ? null : self::at($l[2])], $lines), $window, $max, $limit);
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

    public function testMoreTimesThanAPostingHasRoomForWidenTheWindowOnlyUpToItsLimitAndThenThereIsNoAnswer(): void
    {
        // Six lines an hour apart: six times at ten minutes. With room for three and the limit at ten minutes (the default) the window
        // is not widened at all: there is no answer, and the sheet is refused (review I-1 of 14 Oct 2026: never silently wider).
        $hourly = [[1, '01:00:00'], [2, '02:00:00'], [3, '03:00:00'], [4, '04:00:00'], [5, '05:00:00'], [6, '06:00:00']];
        self::assertCount(6, self::grouped($hourly)['groups']);
        self::assertNull(self::grouped($hourly, 600, 3));
        // A limit the owner raised: the window doubles until three are enough, and never past the limit.
        self::assertSame(['window' => 4800, 'groups' => [['01:00:00', [1, 2]], ['03:00:00', [3, 4]], ['05:00:00', [5, 6]]]], self::grouped($hourly, 600, 3, 7200));
        self::assertSame(['window' => 3600, 'groups' => [['01:00:00', [1, 2]], ['03:00:00', [3, 4]], ['05:00:00', [5, 6]]]], self::grouped($hourly, 600, 3, 3600),
            'the last try is the limit itself');
        // Stock that moved before every line keeps each line on its own time whatever the window: no answer with room for two.
        $busy = [[1, '01:00:00'], [2, '02:00:00', '01:30:00'], [3, '03:00:00', '02:30:00']];
        self::assertNull(self::grouped($busy, 600, 2, 86_400));
        self::assertCount(3, self::grouped($busy, 600, 3)['groups']);
        // With room for one time only: no answer at ten minutes (the builder's first version widened to 5 h 20 min here); a limit of five
        // hours takes the first line's time for all six, exactly at the limit.
        self::assertNull(self::grouped($hourly, 600, 1));
        self::assertSame(['window' => 18000, 'groups' => [['01:00:00', [1, 2, 3, 4, 5, 6]]]], self::grouped($hourly, 600, 1, 18000));
        // Lines years apart: the limit bounds the window, so there is never an answer without one.
        self::assertNull(CountTimes::groups([['line' => 1, 'at' => new \DateTimeImmutable('2024-01-01 10:00:00', Clock::utc()), 'since' => null],
            ['line' => 2, 'at' => new \DateTimeImmutable('2026-10-12 10:00:00', Clock::utc()), 'since' => null]], 600, 1, 3600));
        // A limit below the ten minutes narrows the window itself.
        self::assertSame(['window' => 120, 'groups' => [['10:00:00', [1]], ['10:04:00', [2]]]], self::grouped([[1, '10:00:00'], [2, '10:04:00']], 600, 20, 120));
        self::assertSame(['window' => 0, 'groups' => [['10:00:00', [1, 2]], ['10:04:00', [3]]]], self::grouped([[1, '10:00:00'], [2, '10:00:00'], [3, '10:04:00']], 600, 20, 0),
            'a limit of 0: only lines counted at the same moment share it');
    }

    public function testADaysSheetIsNeverBookedFurtherBeforeItsOwnTimeThanTheLimit(): void
    {
        // Review probe P4 (14 Oct 2026): 300 lines, one every 90 seconds (7.5 hours). The first version widened the window to 2,400 s
        // and booked a line up to 2,340 s before its own time. At the ten-minute limit it is refused instead (the lines to count again
        // are marked: CountPosting), and no grouping at that limit puts a line more than ten minutes before its own time.
        $lines = [];
        $start = self::at('09:00:00');
        for ($i = 0; $i < 300; $i++) {
            $lines[] = ['line' => $i + 1, 'at' => $start->modify('+' . (90 * $i) . ' seconds'), 'since' => null];
        }
        self::assertNull(CountTimes::groups($lines, 600, 20, 600), 'more than 20 times at ten minutes: refused, not widened');
        $within = CountTimes::groupsWithin($lines, 600);
        self::assertCount(43, $within, 'seven lines in each ten minutes');
        $at = array_column($lines, 'at', 'line');
        $gap = 0;
        foreach ($within as $g) {
            foreach ($g['lines'] as $no) {
                $gap = max($gap, $at[$no]->getTimestamp() - $g['at']->getTimestamp());
            }
        }
        self::assertLessThanOrEqual(600, $gap);
        // The owner may raise the limit (the setting): then the probe's figures, and never more than the limit.
        $wide = CountTimes::groups($lines, 600, 20, 2400);
        self::assertNotNull($wide);
        self::assertSame([2400, 12], [$wide['window'], count($wide['groups'])]);
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
