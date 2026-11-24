<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Expenses\ExpenseMoney;
use CW\Expenses\RegularCostMath;
use PHPUnit\Framework\TestCase;

/**
 * A regular cost counted per day (the owner, 24 Nov 2026: "Yes, per day"; docs/decisions.md EX5, EX6): a weekly amount / 7 a day, a monthly
 * amount / the days of that calendar month, a yearly amount / the days of that calendar year, worked out exactly and rounded ONCE per line
 * and period by the cumulative rule (a line's share of days a..b = round(what it accrued from its first day to b) - round(to the day before
 * a), half up), so a line's days add up to its month exactly, its months to its year, and 7 days of a weekly line are its amount.
 */
final class RegularCostMathTest extends TestCase
{
    private static function days(string $from, int $n): array
    {
        $out = [];
        $d = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        for ($i = 0; $i < $n; $i++) {
            $out[] = $d->modify("+{$i} days")->format('Y-m-d');
        }
        return $out;
    }

    public function testTheBriefsTwoExamples(): void
    {
        // Unit 1: GBP 207.69 a week from 1 Nov 2026 = GBP 29.67 a day; November (30 days) GBP 890.10; 4 weeks GBP 830.76 exactly.
        self::assertSame(2967, RegularCostMath::share('week', 20769, '2026-11-01', null, '2026-11-01', '2026-11-01'));
        self::assertSame(89010, RegularCostMath::share('week', 20769, '2026-11-01', null, '2026-11-01', '2026-11-30'));
        self::assertSame(83076, RegularCostMath::share('week', 20769, '2026-11-01', null, '2026-11-01', '2026-11-28'));
        // Any 28 days, any 7 days: the amount exactly, wherever they start.
        foreach (['2026-11-02', '2026-11-05', '2026-11-17', '2027-02-27'] as $start) {
            $end28 = (new \DateTimeImmutable($start))->modify('+27 days')->format('Y-m-d');
            $end7 = (new \DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d');
            self::assertSame(83076, RegularCostMath::share('week', 20769, '2026-11-01', null, $start, $end28), $start);
            self::assertSame(20769, RegularCostMath::share('week', 20769, '2026-11-01', null, $start, $end7), $start);
        }
    }

    public function testAMonthIsTheSumOfItsDaysForEveryKindOfLine(): void
    {
        $lines = [['week', 23077, '2026-11-01'], ['week', 92308, '2026-11-03'], ['month', 4999, '2026-10-17'], ['year', 120000, '2026-06-30'],
            ['week', 1, '2026-11-01'], ['month', 3333, '2027-02-01'], ['year', 99999, '2028-01-01']];
        foreach ($lines as [$every, $amount, $from]) {
            foreach ([['2026-11-01', 30], ['2026-12-01', 31], ['2027-02-01', 28], ['2028-02-01', 29]] as [$first, $n]) {
                $sum = 0;
                foreach (self::days($first, $n) as $d) {
                    $sum += RegularCostMath::share($every, $amount, $from, null, $d, $d);
                }
                $last = self::days($first, $n)[$n - 1];
                self::assertSame(RegularCostMath::share($every, $amount, $from, null, $first, $last), $sum, "{$every} {$amount} {$from} in {$first}");
            }
        }
    }

    public function testMonthlyAndYearlyLinesShareOverTheirCalendarMonthAndYear(): void
    {
        // A month's amount over a whole calendar month is the amount, whatever its length; part of a month is its days / the month's days.
        self::assertSame(30000, RegularCostMath::share('month', 30000, '2026-11-01', null, '2026-11-01', '2026-11-30'));
        self::assertSame(30000, RegularCostMath::share('month', 30000, '2026-11-01', null, '2027-02-01', '2027-02-28'));
        self::assertSame(10000, RegularCostMath::share('month', 30000, '2026-11-01', null, '2026-11-01', '2026-11-10'), '10 / 30 days');
        self::assertSame(9677, RegularCostMath::share('month', 30000, '2026-11-01', null, '2026-12-01', '2026-12-10'), '10 / 31 days: 96.774 -> 96.77');
        // A line starting mid-month counts from its first day.
        self::assertSame(15000, RegularCostMath::share('month', 30000, '2026-11-16', null, '2026-11-01', '2026-11-30'), '15 of 30 days');
        // A year's amount over a whole calendar year; a leap year shares over 366 days.
        self::assertSame(36500, RegularCostMath::share('year', 36500, '2026-01-01', null, '2026-01-01', '2026-12-31'));
        self::assertSame(100, RegularCostMath::share('year', 36500, '2026-01-01', null, '2026-03-05', '2026-03-05'));
        self::assertSame(36600, RegularCostMath::share('year', 36600, '2028-01-01', null, '2028-01-01', '2028-12-31'));
        self::assertSame(100, RegularCostMath::share('year', 36600, '2028-01-01', null, '2028-02-29', '2028-02-29'));
        // Twelve months of a yearly cost add up to the year exactly.
        $sum = 0;
        for ($m = 1; $m <= 12; $m++) {
            $first = sprintf('2027-%02d-01', $m);
            $sum += RegularCostMath::share('year', 123457, '2026-05-09', null, $first, (new \DateTimeImmutable($first))->format('Y-m-t'));
        }
        self::assertSame(123457, $sum);
    }

    public function testNothingBeforeItsFirstDayOrAfterItsLastDay(): void
    {
        // Unit 3 rent until 31 Mar 2027: nothing in April; March in full.
        self::assertSame(0, RegularCostMath::share('week', 92308, '2026-11-01', '2027-03-31', '2027-04-01', '2027-04-30'));
        self::assertSame(408792, RegularCostMath::share('week', 92308, '2026-11-01', '2027-03-31', '2027-03-01', '2027-03-31'),
            'round(151 days) - round(120 days) = 19,912.15 - 15,824.23: within a penny of 31 x 923.08 / 7 = 4,087.926 (EX6)');
        self::assertSame(0, RegularCostMath::share('week', 20769, '2026-11-01', null, '2026-10-01', '2026-10-31'));
        // A period across the first day counts from the first day only.
        self::assertSame(89010, RegularCostMath::share('week', 20769, '2026-11-01', null, '2026-10-15', '2026-11-30'));
        // Its last day counts: an end on 1 Nov is one day.
        self::assertSame(2967, RegularCostMath::share('week', 20769, '2026-11-01', '2026-11-01', '2026-11-01', '2026-11-30'));
        // A line ended and a new amount from the next day: the old days keep the old amount.
        self::assertSame(2967 * 10 + RegularCostMath::share('week', 21000, '2026-11-11', null, '2026-11-11', '2026-11-30'),
            RegularCostMath::share('week', 20769, '2026-11-01', '2026-11-10', '2026-11-01', '2026-11-30')
            + RegularCostMath::share('week', 21000, '2026-11-11', null, '2026-11-01', '2026-11-30'));
    }

    public function testTheOwnersWeeklyListInNovember(): void
    {
        // The brief's weekly list (GBP 9,771.94 a week), each from 1 Nov 2026: November per line, rounded per line; their sum is the shop's.
        $list = [20769, 23077, 92308, 10900, 2640, 3600, 15200, 2000, 13800, 4200, 1300, 17700, 96900, 276900, 207600, 188300];
        self::assertSame(977194, array_sum($list));
        $nov = array_map(static fn (int $a): int => RegularCostMath::share('week', $a, '2026-11-01', null, '2026-11-01', '2026-11-30'), $list);
        self::assertSame([89010, 98901, 395606, 46714, 11314, 15429, 65143, 8571, 59143, 18000, 5571, 75857, 415286, 1186714, 889714, 807000], $nov);
        self::assertSame(4187973, array_sum($nov), 'GBP 41,879.73: each line rounded once (the drawing\'s 41,879.74 rounded the week\'s total once)');
    }

    public function testAmountsAreTypedInPoundsAndPence(): void
    {
        self::assertSame(20769, ExpenseMoney::pence('207.69'));
        self::assertSame(20769, ExpenseMoney::pence(' £207.69 '));
        self::assertSame(276900, ExpenseMoney::pence('2,769.00'));
        self::assertSame(276900, ExpenseMoney::pence('2769'));
        self::assertSame(1250, ExpenseMoney::pence('12.5'));
        self::assertSame(0, ExpenseMoney::pence('0'));
        self::assertSame(0, ExpenseMoney::pence('0.00'));
        foreach (['', '-1.00', '12.345', 'abc', '1,23.00', '12,00', '10000000.00', '1e3', '£', '12.', '.5', "12\n"] as $bad) {
            self::assertNull(ExpenseMoney::pence($bad), var_export($bad, true));
        }
        self::assertSame(999999999, ExpenseMoney::pence('9,999,999.99'));
        self::assertSame('£41,879.73', ExpenseMoney::gbp(4187973));
        self::assertSame('£0.07', ExpenseMoney::gbp(7));
        self::assertSame('-£12.00', ExpenseMoney::gbp(-1200));
        self::assertSame('64.20', ExpenseMoney::plain(6420));
    }
}
