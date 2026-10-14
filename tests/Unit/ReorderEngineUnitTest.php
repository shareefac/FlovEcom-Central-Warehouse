<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Reorder\DemandMath;
use CW\Reorder\LowStockLevels;
use CW\Reorder\ReorderMath;
use CW\Reorder\SalesFold;
use CW\Settings;
use CW\SiteWriter\SiteView;
use CW\Ui\View;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * Pack G, the reorder engine (docs/decisions.md PG1-PG16), the parts without a database: the safety stock and "below the safety stock"
 * on worked examples (ReorderMath), a day no source has is "no data" (DemandMath's gap), the UK day of CW's own sales record across
 * the clock change (SalesFold::bounds), the low-stock figure in each variant's units (SiteView), the settings' ranges and words, the
 * pack's two pages taking every word from Words, and the one cron line.
 */
final class ReorderEngineUnitTest extends TestCase
{
    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function line(array $over = []): array
    {
        return ReorderMath::line($over + ['rate_e4' => 120_000, 'factor_e2' => 100, 'lead' => 2, 'review' => 7, 'safety' => 5, 'min_stock' => null,
            'max_stock' => null, 'available' => 60, 'on_order' => 42, 'in_drafts' => 0, 'upp' => 24, 'moq' => 1, 'mult' => 1, 'rounding' => 'up', 'never' => false,
            'pack_price_e4' => 450_000]);
    }

    /**
     * The suggested quantity and the safety stock, worked by hand:
     *   12 a day, 2 lead + 7 review + 5 spare = 14 days -> aim for 168; have 60 + 42 on order = 102 -> need 66 -> 3 boxes of 24 = 72;
     *   safety stock 12 × 5 = 60; 60 available is not below 60.
     */
    public function testTheSuggestedQuantityAndTheSafetyStockOnWorkedExamples(): void
    {
        $l = self::line();
        self::assertSame([168, 102, 66, 3, 72, 60, false], [$l['target'], $l['position'], $l['need'], $l['packs'], $l['units'], $l['safety_stock'], $l['below_safety']]);
        // 59 available: below the safety stock, whatever is on order (ERPNext's "Items Below Safety Stock" reads the stock now).
        $l = self::line(['available' => 59, 'on_order' => 500]);
        self::assertSame([0, 60, true, false], [$l['packs'], $l['safety_stock'], $l['below_safety'], $l['urgent']], 'enough on order: nothing to buy, still below');
        // A demand factor of 0.85: 10.2 a day; 5 spare days -> 51 (rounded up).
        self::assertSame(51, self::line(['factor_e2' => 85])['safety_stock']);
        // 2.35 a day × 3 spare days = 7.05 -> 8.
        self::assertSame(8, self::line(['rate_e4' => 23_500, 'safety' => 3])['safety_stock']);
        // The smallest stock is a floor; the largest a ceiling.
        self::assertSame(80, self::line(['min_stock' => 80])['safety_stock']);
        self::assertSame(40, self::line(['max_stock' => 40])['safety_stock']);
        self::assertTrue(self::line(['min_stock' => 80, 'available' => 79])['below_safety']);
        // No sales, no smallest stock: 0, and below it only when the stock is under zero.
        $none = self::line(['rate_e4' => 0, 'available' => 0, 'on_order' => 0]);
        self::assertSame([0, false, 0], [$none['safety_stock'], $none['below_safety'], $none['packs']]);
        self::assertTrue(self::line(['rate_e4' => 0, 'available' => -2])['below_safety']);
        // Never suggested (discontinued, "do not reorder"): never below, never bought.
        $never = self::line(['never' => true, 'available' => 0]);
        self::assertSame([false, 0, 60], [$never['below_safety'], $never['packs'], $never['safety_stock']]);
        // Packs of 6 with a smallest order of 4 packs and steps of 2: need 115 -> 20 packs = 120 (the Lost Mary line of the scenario).
        $c = self::line(['rate_e4' => 50_000, 'lead' => 4, 'review' => 14, 'available' => 0, 'on_order' => 0, 'upp' => 6, 'moq' => 4, 'mult' => 2]);
        self::assertSame([23, 115, 115, 20, 120, 25, true], [$c['cover_days'], $c['target'], $c['need'], $c['packs'], $c['units'], $c['safety_stock'], $c['below_safety']]);
    }

    /** Pack G (PG2): a day inside the coverage that no source has is excluded as "no data", never read as a day without sales. */
    public function testADayWithoutDataIsNoDataNotZero(): void
    {
        $math = new DemandMath();
        $end = DemandMath::day('2026-10-13');
        $q = [];
        for ($d = $end - 90; $d <= $end; $d++) {
            $q[$d] = 10;
        }
        $gap = [];
        for ($d = DemandMath::day('2026-10-02'); $d <= DemandMath::day('2026-10-05'); $d++) {
            unset($q[$d]);
            $gap[$d] = true;
        }
        $with = $math->listing($end, $end - 200, $q, [], [], [], [], false, $gap);
        $without = $math->listing($end, $end - 200, $q);
        self::assertSame(100_000, $with['rate_e4'], 'the four days left out: 10 a day');
        self::assertSame(4, $with['excluded']['nodata']);
        self::assertLessThan(100_000, $without['rate_e4'], 'read as days without sales they would lower it');
        $days = $math->listing($end, $end - 200, $q + [DemandMath::day('2026-10-03') => 99], [], [], [], [], true, $gap)['days'];
        self::assertSame('nodata', $days[DemandMath::day('2026-10-03')][1], 'units on a gap day are never read');
    }

    /** A UK day in UTC (CW's own record is folded by UK day): summer time, winter time and the night the clocks go back. */
    public function testTheUkDayOfTheOwnRecord(): void
    {
        self::assertSame(['2026-10-13 23:00:00.000000', '2026-10-14 23:00:00.000000'], SalesFold::bounds(DemandMath::day('2026-10-14')));
        self::assertSame(['2026-10-24 23:00:00.000000', '2026-10-26 00:00:00.000000'], SalesFold::bounds(DemandMath::day('2026-10-25')), '25 hours');
        self::assertSame(['2026-12-01 00:00:00.000000', '2026-12-02 00:00:00.000000'], SalesFold::bounds(DemandMath::day('2026-12-01')));
    }

    /** Pack G (PG7): the item's low-stock figure goes to each variant in its own units; a figure set for one website wins. */
    public function testTheLowStockFigureOnEachVariant(): void
    {
        $rule = static fn (array $over): array => SiteView::rule($over + ['writer_on' => true, 'linked' => true, 'status' => 'mapped', 'policy' => 'legacy', 'qty' => 7,
            'blocked' => [], 'site' => null]);
        self::assertNull($rule([])['low_stock_threshold'], 'no figure anywhere: the website keeps its own');
        self::assertSame(12, $rule(['level' => 12])['low_stock_threshold']);
        self::assertSame(9, $rule(['level' => 4, 'site' => ['mode' => 'In-Stock', 'threshold' => 9]])['low_stock_threshold'], 'the website\'s own setting wins');
        self::assertSame(3, $rule(['level' => 3, 'policy' => 'strict'])['low_stock_threshold'], 'a counted item too');
        self::assertSame(['writer' => false, 'why' => 'writer_off'], $rule(['writer_on' => false, 'level' => 3]), 'nothing while the switch is off');
    }

    /** Every setting of 0037 has a range (Settings::RULES or the day rule), a plain name, a help text and a topic. */
    public function testThePacksSettingsHaveRangesAndWords(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0037_reorder_engine.sql');
        preg_match_all("/^ \\('(reorder\\.[a-z_]+)','([a-z]+)','/m", $sql, $m);
        self::assertCount(9, $m[1]);
        foreach ($m[1] as $i => $key) {
            if ($m[2][$i] === 'int') {
                self::assertTrue(isset(Settings::RULES[$key]) || str_ends_with($key, '_days'), "{$key}: a range");
            }
            self::assertArrayHasKey($key, Words::SETTING, $key);
            self::assertArrayHasKey($key, Words::SETTING_HELP, $key);
            self::assertSame('Reorder', Words::settingTopic($key), $key);
        }
        self::assertStringContainsString("'setting', setting_key, 1, 'baseline'", $sql, 'each with its version 1');
        foreach (LowStockLevels::SKIP as $why) {
            self::assertTrue(Words::has('REORDER_LOW_SKIP', $why), $why);
        }
    }

    /**
     * The pack's two pages (rules 2, 9, 10, 14, 19): every table is a `table.stack` whose cells say what they are on a phone, UK time,
     * no word typed in the template, and every POST form carries the CSRF field.
     */
    public function testThePacksPagesTakeEveryWordFromWords(): void
    {
        foreach (['reorder_suppliers', 'reorder_low_stock'] as $name) {
            $src = (string) file_get_contents(View::defaultDir() . "/{$name}.php");
            preg_match_all('/<table\b([^>]*)>/i', $src, $tables);
            self::assertNotSame([], $tables[1], $name);
            foreach ($tables[1] as $t) {
                self::assertMatchesRegularExpression('/class="[^"]*\bstack\b/', $t, "{$name}: cards on a phone");
            }
            preg_match_all('/<td\b[^>]*>/i', $src, $cells);
            foreach ($cells[0] as $td) {
                self::assertMatchesRegularExpression('/data-label=|class="[^"]*\bc-(status|next|head)\b/', $td, "{$name}: {$td}");
            }
            self::assertStringNotContainsString('$dt(', $src, $name);
            $text = (string) preg_replace(['/<\?.*?\?>/s', '/<[^>]*>/', '/&[a-z]+;/'], ' ', $src);
            self::assertSame([], preg_match_all('/[A-Za-z]{2,}/', $text, $w) > 0 ? $w[0] : [], "{$name}: a word typed in the template");
            preg_match_all('/<form\b[^>]*method="post"[^>]*>(.*?)<\/form>/is', $src, $forms);
            foreach ($forms[1] as $f) {
                self::assertStringContainsString('name="csrf"', $f, "{$name}: every POST form carries the CSRF field");
            }
        }
    }

    /** ONE cron line, every 5 minutes, without --force (a forced run is a hand run). */
    public function testTheScheduleRunsEveryFiveMinutes(): void
    {
        $cron = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/staging/cw-staging.cron');
        self::assertMatchesRegularExpression('#^\*/5 \* \* \* \*  root  cd /opt/cw-staging && php bin/reorder_refresh\.php --db=cw_staging >> /var/log/cw/reorder_refresh\.log 2>&1$#m', $cron);
        self::assertSame(1, preg_match_all('#^[^\#\n]*reorder_refresh\.php#m', $cron));
        self::assertDoesNotMatchRegularExpression('#^[^\#\n]*reorder_refresh\.php[^\n]*--force#m', $cron);
    }
}
