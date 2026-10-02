<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Reorder\DemandMath;
use PHPUnit\Framework\TestCase;

/** The demand of one listing (spec §7.3, docs/decisions.md I62): exclusions, the spike cap, the blend and its fallbacks. */
final class DemandMathTest extends TestCase
{
    private const END = '2026-10-01';

    /** @return array<int, int> day => units, $perDay on every day of [from, to] (0 = no row) */
    private static function series(string $from, string $to, int $perDay): array
    {
        $out = [];
        for ($d = DemandMath::day($from); $d <= DemandMath::day($to); $d++) {
            if ($perDay > 0) {
                $out[$d] = $perDay;
            }
        }
        return $out;
    }

    /** @return array<int, int> */
    private static function days(string $from, string $to, int $id = 1): array
    {
        return array_fill_keys(range(DemandMath::day($from), DemandMath::day($to)), $id);
    }

    public function testStockpilingDaysAreExcludedAndThePlainAverageKeepsThem(): void
    {
        $q = self::series('2026-01-01', self::END, 10);
        // 14-22 Sep at 14.3 a day on average (14, 14, 15, ...): the pre-duty stockpiling.
        foreach (range(0, 8) as $i) {
            $q[DemandMath::day('2026-09-14') + $i] = $i % 3 === 2 ? 15 : 14;
        }
        $r = (new DemandMath())->listing(DemandMath::day(self::END), DemandMath::day('2026-01-01'), $q, self::days('2026-09-14', '2026-09-22'));
        self::assertNotNull($r);
        self::assertSame(100_000, $r['rate_e4'], 'rate 10.0000: the window is left out');
        self::assertSame(DemandMath::METHOD_BLEND, $r['method']);
        self::assertSame([82, 19], [$r['valid_long'], $r['valid_short']]);
        self::assertSame(['nodata' => 0, 'before_first' => 0, 'anomaly' => 9, 'promo' => 0, 'oos' => 0], $r['excluded']);
        self::assertSame(9, $r['excluded_short']['anomaly']);
        self::assertSame([1 => [9, 9]], $r['anomalies']);
        self::assertSame([100_000, 100_000, 0, 40], [$r['r_short_e4'], $r['r_long_e4'], $r['capped'], $r['cap']]);
        // The plain 30-day average (ERPNext-like) keeps them: (21 × 10 + 129) / 30 = 11.3.
        self::assertSame(339, $r['sum_raw30']);
        self::assertSame(113_000, DemandMath::rawRateE4([$r['sum_raw30']]));
        self::assertGreaterThan($r['rate_e4'], DemandMath::rawRateE4([$r['sum_raw30']]));
    }

    public function testOutOfStockDaysOnlyWithASnapshotAndNoSale(): void
    {
        $q = self::series('2026-01-01', self::END, 10);
        $x1 = DemandMath::day('2026-09-25');
        $x2 = DemandMath::day('2026-09-26');
        $x3 = DemandMath::day('2026-09-27');
        unset($q[$x1], $q[$x2]); // no sale on x1 and x2; x3 sold
        $unsellable = [$x1 => true, $x2 => true, $x3 => true];
        $snapshot = [$x1 => true, $x3 => true]; // the snapshot did not run on x2
        $r = (new DemandMath())->listing(DemandMath::day(self::END), DemandMath::day('2026-01-01'), $q, [], [], $snapshot, $unsellable);
        self::assertNotNull($r);
        self::assertSame(1, $r['excluded']['oos'], 'x1 only: x2 has no snapshot, x3 sold (a sale proves it was available)');
        self::assertSame(90, $r['valid_long']);
        // x2 counts as a valid day without sales: 89 × 10 / 90 over the long window.
        self::assertSame(DemandMath::halfUpDiv(890 * 10_000, 90), $r['r_long_e4']);
        self::assertSame(DemandMath::halfUpDiv(260 * 10_000, 27), $r['r_short_e4']);
    }

    public function testDaysBeforeTheFirstSaleAreNotZeros(): void
    {
        // A new item at 10 a day for the last 14 days: ~10, not 140 / 91 = 1.5.
        $q = self::series('2026-09-18', self::END, 10);
        $r = (new DemandMath())->listing(DemandMath::day(self::END), DemandMath::day('2026-01-01'), $q);
        self::assertNotNull($r);
        self::assertSame(DemandMath::day('2026-09-18'), $r['first']);
        self::assertSame(77, $r['excluded']['before_first']);
        self::assertSame([14, 14], [$r['valid_long'], $r['valid_short']]);
        self::assertSame(DemandMath::METHOD_SHORT, $r['method'], 'fewer than 21 valid days in the long window: the short window alone');
        self::assertSame(100_000, $r['rate_e4']);
        // Before the history starts: no data (precedence over "before the first sale").
        $r = (new DemandMath())->listing(DemandMath::day(self::END), DemandMath::day('2026-09-01'), self::series('2026-09-10', self::END, 4));
        self::assertNotNull($r);
        self::assertSame([60, 9], [$r['excluded']['nodata'], $r['excluded']['before_first']]);
        self::assertSame(DemandMath::METHOD_BLEND, $r['method']);
        self::assertSame(40_000, $r['rate_e4']);
    }

    public function testASpikeIsCappedAtFourTimesTheMean(): void
    {
        // 2 a day for 91 days and one day of 200 (5 days before the end): μ = 380 / 91, cap = max(5, ⌈4μ⌉) = ⌈16.7⌉ = 17. (The spec's
        // example says "capped at 8", i.e. 4 × 2: that μ leaves the spike out; the formula's μ is the mean of the valid days with the
        // spike in it — docs/decisions.md I62.)
        $q = self::series('2026-01-01', self::END, 2);
        $q[DemandMath::day(self::END) - 5] = 200;
        $r = (new DemandMath())->listing(DemandMath::day(self::END), DemandMath::day('2026-01-01'), $q);
        self::assertNotNull($r);
        self::assertSame([17, 1], [$r['cap'], $r['capped']]);
        self::assertSame(DemandMath::halfUpDiv(197 * 10_000, 91), $r['r_long_e4']);
        self::assertSame(DemandMath::halfUpDiv(71 * 10_000, 28), $r['r_short_e4']);
        self::assertSame(DemandMath::halfUpDiv(500_000 * $r['r_short_e4'] + 500_000 * $r['r_long_e4'], 1_000_000), $r['rate_e4']);
        self::assertSame(23_503, $r['rate_e4'], 'half up: 23,502.5 -> 23,503');
        // The floor: 1 a day and one day of 30 -> ⌈4 × 120 / 91⌉ = 6 (above the floor 5); 1 a day and a day of 3: the floor 5, nothing capped.
        $q = self::series('2026-01-01', self::END, 1);
        $q[DemandMath::day(self::END) - 50] = 30;
        self::assertSame([6, 1], array_values(array_intersect_key((array) (new DemandMath())->listing(DemandMath::day(self::END), DemandMath::day('2026-01-01'), $q),
            ['cap' => 0, 'capped' => 0])));
        $q[DemandMath::day(self::END) - 50] = 3;
        $r = (new DemandMath())->listing(DemandMath::day(self::END), DemandMath::day('2026-01-01'), $q);
        self::assertSame([5, 0], [$r['cap'] ?? null, $r['capped'] ?? null]);
        // Another multiple and floor (the settings): k = 2, f = 0.
        $q = self::series('2026-01-01', self::END, 2);
        $q[DemandMath::day(self::END) - 5] = 200;
        $r = (new DemandMath(28, 91, 500_000, 7, 21, 2, 0))->listing(DemandMath::day(self::END), DemandMath::day('2026-01-01'), $q);
        self::assertSame(9, $r['cap'] ?? null, '⌈2 × 380 / 91⌉ = ⌈8.35⌉');
    }

    public function testTheFallbacks(): void
    {
        $m = new DemandMath();
        $end = DemandMath::day(self::END);
        // Fewer than 7 valid days: the sum over max(|V_L|, 7).
        $r = $m->listing($end, DemandMath::day('2026-01-01'), self::series('2026-09-28', self::END, 3));
        self::assertNotNull($r);
        self::assertSame([4, 4, DemandMath::METHOD_MIN7], [$r['valid_long'], $r['valid_short'], $r['method']]);
        self::assertSame(DemandMath::halfUpDiv(12 * 10_000, 7), $r['rate_e4']);
        // Only the short window has enough days.
        $r = $m->listing($end, DemandMath::day('2026-01-01'), self::series('2026-09-17', self::END, 6));
        self::assertSame([15, DemandMath::METHOD_SHORT, 60_000], [$r['valid_long'] ?? null, $r['method'] ?? null, $r['rate_e4'] ?? null]);
        // 37 long days but only 3 in the short window (the rest of it excluded): the long window over max(|V_L|, 7).
        $q = self::series('2026-08-01', self::END, 5);
        $r = $m->listing($end, DemandMath::day('2026-01-01'), $q, self::days('2026-09-07', self::END));
        self::assertSame([3, DemandMath::METHOD_MIN7], [$r['valid_short'] ?? null, $r['method'] ?? null]);
        self::assertSame(50_000, $r['rate_e4'] ?? null);
        // Every day of the long window excluded: rate 0.
        $r = $m->listing($end, DemandMath::day('2026-01-01'), self::series('2026-01-01', self::END, 5), self::days('2026-07-03', self::END));
        self::assertNotNull($r);
        self::assertSame([0, 0, DemandMath::METHOD_NONE, null], [$r['valid_long'], $r['rate_e4'], $r['method'], $r['cap']]);
        // No sale in the year read: the listing contributes nothing.
        self::assertNull($m->listing($end, DemandMath::day('2025-01-01'), self::series('2025-01-01', '2025-09-30', 5)));
        self::assertNull($m->listing($end, DemandMath::day('2025-01-01'), []));
        // Promotion days are excluded after anomalies (the first matching reason).
        $promo = array_fill_keys(range(DemandMath::day('2026-09-20'), DemandMath::day('2026-09-25')), true);
        $r = $m->listing($end, DemandMath::day('2026-01-01'), self::series('2026-01-01', self::END, 5), self::days('2026-09-14', '2026-09-22'), $promo);
        self::assertSame([9, 3], [$r['excluded']['anomaly'] ?? null, $r['excluded']['promo'] ?? null]);
    }

    public function testUnitsPerItemAndTheItemsSums(): void
    {
        // An item sold as a 10-pack listing (u = 10) at 1 a day and as single units at 5 a day: 15 central units a day.
        $m = new DemandMath();
        $end = DemandMath::day(self::END);
        $pack = $m->listing($end, DemandMath::day('2025-01-01'), self::series('2025-10-02', self::END, 1));
        $single = $m->listing($end, DemandMath::day('2025-01-01'), self::series('2025-10-02', self::END, 5));
        self::assertNotNull($pack);
        self::assertNotNull($single);
        self::assertSame(150_000, 10 * $pack['rate_e4'] + 1 * $single['rate_e4']);
        self::assertSame(150_000, DemandMath::rawRateE4([10 * $pack['sum_raw30'], $single['sum_raw30']]));
        self::assertSame(10 * 365 + 5 * 365, 10 * $pack['units_365'] + $single['units_365']);
    }

    public function testTheDayByDayView(): void
    {
        $q = self::series('2026-09-20', self::END, 3);
        $r = (new DemandMath())->listing(DemandMath::day(self::END), DemandMath::day('2026-09-01'), $q, [], [], [], [], true);
        self::assertNotNull($r);
        self::assertCount(91, $r['days']);
        self::assertSame([0, 'nodata', false], $r['days'][DemandMath::day('2026-08-31')]);
        self::assertSame([0, 'before_first', false], $r['days'][DemandMath::day('2026-09-19')]);
        self::assertSame([3, null, false], $r['days'][DemandMath::day(self::END)]);
    }

    public function testIntegerHelpers(): void
    {
        self::assertSame([3, 2, 2, -3], [DemandMath::halfUpDiv(5, 2), DemandMath::halfUpDiv(7, 4), DemandMath::halfUpDiv(3, 2), DemandMath::halfUpDiv(-5, 2)]);
        self::assertSame([3, 2, 0, -1], [DemandMath::ceilDiv(5, 2), DemandMath::ceilDiv(4, 2), DemandMath::ceilDiv(0, 3), DemandMath::ceilDiv(-3, 2)]);
        self::assertSame(['12.3400', '-0.0500', '0.0000'], [DemandMath::e4(123_400), DemandMath::e4(-500), DemandMath::e4(0)]);
        self::assertSame([123_400, -500, 5_000, 12], [DemandMath::toE4('12.34'), DemandMath::toE4('-0.05'), DemandMath::toE4('0.5'), DemandMath::toE4('0.00129')]);
        self::assertSame('2026-09-14', DemandMath::date(DemandMath::day('2026-09-14')));
        self::assertSame(1, DemandMath::day('2024-03-01') - DemandMath::day('2024-02-29'));
        $this->expectException(\InvalidArgumentException::class);
        DemandMath::day('2026-02-30');
    }
}
