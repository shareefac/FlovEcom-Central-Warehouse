<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Reorder\PromoDetector;
use PHPUnit\Framework\TestCase;

/** Promotion days from revenue per unit (spec §7.3, docs/decisions.md I63): an Elux-like 6-for-£10 against a 5-for-£10 normal. */
final class PromoDetectorTest extends TestCase
{
    /** @return array<int, array{0: int, 1: int}> days 1..$n at $units a day and $pence a unit */
    private static function normal(int $n, int $units = 7500, int $pence = 195, int $from = 1): array
    {
        $out = [];
        for ($d = $from; $d < $from + $n; $d++) {
            $out[$d] = [$units, $units * $pence];
        }
        return $out;
    }

    public function testAnEluxLikePromotionIsFlaggedOnItsDaysOnly(): void
    {
        $daily = self::normal(40);
        $daily[41] = [30_000, 30_000 * 165];
        $daily[42] = [29_000, 29_000 * 163];
        $daily[43] = [7_600, 7_600 * 195];
        $flags = (new PromoDetector())->flag($daily, 1, 43);
        self::assertSame([41 => true, 42 => true], $flags, '£1.65 against £1.95 at 4× the units; day 42 is judged without day 41 (already flagged)');
    }

    public function testAPriceDropWithoutUpliftOrAnUpliftWithoutAPriceDropIsNoPromotion(): void
    {
        $daily = self::normal(40);
        $daily[41] = [8_000, 8_000 * 165];
        self::assertSame([], (new PromoDetector())->flag($daily, 1, 41), 'cheaper but not 1.5× the units');
        $daily[41] = [30_000, 30_000 * 195];
        self::assertSame([], (new PromoDetector())->flag($daily, 1, 41), 'more units at the normal price (the stockpiling)');
        $daily[41] = [30_000, 30_000 * 176];
        self::assertSame([], (new PromoDetector())->flag($daily, 1, 41), '£1.76 is not 10% below £1.95 (£1.755)');
        $daily[41] = [30_000, 30_000 * 175];
        self::assertSame([41 => true], (new PromoDetector())->flag($daily, 1, 41), '£1.75 is');
        // Fewer brand units than promo_min_units (20): never.
        $small = self::normal(40, 5, 195);
        $small[41] = [19, 19 * 100];
        self::assertSame([], (new PromoDetector())->flag($small, 1, 41));
        self::assertSame([41 => true], (new PromoDetector(10))->flag($small, 1, 41), 'the minimum is a setting');
        // The thresholds are settings too.
        $daily[41] = [30_000, 30_000 * 185];
        self::assertSame([41 => true], (new PromoDetector(20, '0.05', '1.50'))->flag($daily, 1, 41));
        self::assertSame([], (new PromoDetector(20, '0.05', '5.00'))->flag($daily, 1, 41));
    }

    public function testFewerThanSevenReferenceDaysNeverFlag(): void
    {
        $daily = self::normal(6, 7500, 195, 30);
        $daily[36] = [30_000, 30_000 * 165];
        self::assertSame([], (new PromoDetector())->flag($daily, 1, 36), 'six days with sales in the 28 before');
        $daily = self::normal(7, 7500, 195, 29);
        $daily[36] = [30_000, 30_000 * 165];
        self::assertSame([36 => true], (new PromoDetector())->flag($daily, 1, 36), 'seven are enough');
        // Days without sales are not reference days, and the reference is the 28 days before only.
        $daily = self::normal(7, 7500, 195, 1);
        $daily[36] = [30_000, 30_000 * 165];
        self::assertSame([], (new PromoDetector())->flag($daily, 1, 36), 'days 1-7 lie more than 28 days before day 36');
    }

    public function testAnomalyDaysAreLeftOutOfTheReference(): void
    {
        // Days 1-29 normal, days 30-40 stockpiling (25,000 a day at the normal price), day 41 a promotion of 20,000 at £1.65.
        $daily = self::normal(29);
        foreach (range(30, 40) as $d) {
            $daily[$d] = [25_000, 25_000 * 195];
        }
        $daily[41] = [20_000, 20_000 * 165];
        $anomaly = array_fill_keys(range(30, 40), true);
        self::assertSame([41 => true], (new PromoDetector())->flag($daily, 1, 41, $anomaly), 'reference = days 13-29: 7,500 a day');
        self::assertSame([], (new PromoDetector())->flag($daily, 1, 41), 'control: with the stockpiling days in the reference (14,375 a day) 20,000 is no uplift');
    }

    public function testTheDecimalSettings(): void
    {
        self::assertSame([100_000, 1_500_000, 0, 1_000_000], [PromoDetector::e6('0.10'), PromoDetector::e6('1.5'), PromoDetector::e6('0'), PromoDetector::e6('1.000000')]);
        $this->expectException(\InvalidArgumentException::class);
        PromoDetector::e6('-0.1');
    }
}
