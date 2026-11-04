<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Reorder\Explain;
use CW\Reorder\ReorderLines;
use CW\Reorder\ReorderList;
use CW\Reorder\ReorderMath;
use CW\Schema\SqlSplitter;
use CW\Settings;
use CW\Ui\Controller\ReorderController;
use CW\Ui\ReorderWhy;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * "Buy for N days" (docs/decisions.md BF1-BF12): the maths with N in place of the order cycle (the smallest and largest stock, packs, the
 * smallest order, steps and rounding all still applied; the reorder point, "urgent" and the safety stock never moved by N), a stored line
 * bought for N, the compact inputs, the days in words, the choices, the "Why" and the maths, the settings' ranges and words, and 0061's form.
 */
final class ReorderBuyForUnitTest extends TestCase
{
    /**
     * 12 a day, 2 lead + R + 5 safety; 60 available + 42 on order = 102; packs of 24 at £45.00.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function math(array $over = []): array
    {
        return ReorderMath::line($over + ['rate_e4' => 120_000, 'factor_e2' => 100, 'lead' => 2, 'review' => 7, 'safety' => 5, 'min_stock' => null,
            'max_stock' => null, 'available' => 60, 'on_order' => 42, 'in_drafts' => 0, 'upp' => 24, 'moq' => 1, 'mult' => 1, 'rounding' => 'up', 'never' => false,
            'pack_price_e4' => 450_000]);
    }

    /**
     * A line as ReorderList::lines() gives it (usual: R = 7), from the same inputs as math().
     *
     * @param array<string, mixed> $over inputs of math() and line fields
     * @return array<string, mixed>
     */
    private static function line(array $over = []): array
    {
        $in = $over + ['rate_e4' => 120_000, 'factor_e2' => 100, 'lead' => 2, 'review' => 7, 'safety' => 5, 'min_stock' => null, 'max_stock' => null,
            'available' => 60, 'on_order' => 42, 'in_drafts' => 0, 'upp' => 24, 'moq' => 1, 'mult' => 1, 'rounding' => 'up', 'never' => null, 'pack_price' => '45.0000'];
        $m = ReorderMath::line(['never' => $in['never'] !== null, 'pack_price_e4' => $in['pack_price'] === null ? null : (int) round((float) $in['pack_price'] * 10_000)]
            + array_intersect_key($in, array_flip(['rate_e4', 'factor_e2', 'lead', 'review', 'safety', 'min_stock', 'max_stock', 'available', 'on_order', 'in_drafts',
                'upp', 'moq', 'mult', 'rounding'])));
        return $m + ['sku_id' => 1, 'code' => 'CW-000001', 'rate_e4' => $in['rate_e4'], 'factor_e2' => $in['factor_e2'], 'lead' => $in['lead'], 'review' => $in['review'],
            'safety' => $in['safety'], 'min_stock' => $in['min_stock'], 'max_stock' => $in['max_stock'], 'available' => $in['available'], 'on_order' => $in['on_order'],
            'in_drafts' => $in['in_drafts'], 'upp' => $in['upp'], 'moq' => $in['moq'], 'mult' => $in['mult'], 'rounding' => $in['rounding'], 'never' => $in['never'],
            'pack_price' => $in['pack_price'], 'purchase_unit' => 'box', 'flags' => [], 'stock_source' => 'cw', 'no_supplier' => false, 'rate_raw_30_e4' => 0];
    }

    /** T = ⌈d·(L+N+S)⌉ in place of ⌈d·(L+R+S)⌉; the reorder point, "urgent" and the safety stock stay what they were. */
    public function testNTakesThePlaceOfTheOrderCycle(): void
    {
        $usual = self::math();
        self::assertSame([14, 168, 66, 3, 72], [$usual['cover_days'], $usual['target'], $usual['need'], $usual['packs'], $usual['units']]);
        $two = self::math(['review' => 14]); // 2 + 14 + 5 = 21 days × 12 = 252 -> need 150 -> 7 packs (6.25 up)
        self::assertSame([21, 252, 150, 7, 168, 7 * 450_000], [$two['cover_days'], $two['target'], $two['need'], $two['packs'], $two['units'], $two['value_e4']]);
        $one = self::math(['review' => 1]); // 8 days × 12 = 96 < 102: nothing to buy
        self::assertSame([8, 96, 0, 0], [$one['cover_days'], $one['target'], $one['need'], $one['packs']]);
        foreach ([1, 7, 14, 28, 45, 180] as $n) {
            $l = self::math(['review' => $n]);
            self::assertSame([$usual['rop'], $usual['urgent'], $usual['safety_stock'], $usual['below_safety'], $usual['cover_now_e1']],
                [$l['rop'], $l['urgent'], $l['safety_stock'], $l['below_safety'], $l['cover_now_e1']], "N = {$n}: the reorder point, urgent, the safety stock");
            self::assertSame((int) ceil(12 * (2 + $n + 5)), $l['target_raw'], "N = {$n}");
        }
        // Fractions round up: 0.35 a day × (2 + 10 + 5) = 5.95 -> 6.
        self::assertSame(6, self::math(['rate_e4' => 3_500, 'review' => 10, 'available' => 0, 'on_order' => 0])['target']);
    }

    /** With N: the smallest and largest stock, the smallest order, the steps, nearest rounding and "never suggested" all still apply. */
    public function testMinMaxPacksMoqStepsAndNeverStillApplyForN(): void
    {
        $l = self::math(['review' => 28, 'max_stock' => 300]); // 35 × 12 = 420 -> capped at 300 -> need 198 -> 9 packs (8.25 up)
        self::assertSame([420, 300, true, 198, 9], [$l['target_raw'], $l['target'], $l['max_applied'], $l['need'], $l['packs']]);
        $l = self::math(['review' => 1, 'min_stock' => 200]); // 8 × 12 = 96 -> the smallest stock 200 -> need 98 -> 5 packs
        self::assertSame([96, 200, true, 98, 5], [$l['target_raw'], $l['target'], $l['min_applied'], $l['need'], $l['packs']]);
        $l = self::math(['review' => 14, 'moq' => 10]); // need 150 -> 7 packs -> the smallest order 10
        self::assertSame([10, true], [$l['packs'], $l['moq_applied']]);
        $l = self::math(['review' => 14, 'mult' => 4]); // 7 -> steps of 4: 8
        self::assertSame([8, true], [$l['packs'], $l['mult_applied']]);
        $l = self::math(['review' => 14, 'moq' => 9, 'mult' => 4]); // 7 -> 9 -> 12
        self::assertSame(12, $l['packs']);
        $l = self::math(['review' => 14, 'rounding' => 'nearest']); // 150 / 24 = 6.25 -> 6
        self::assertSame([6, 144], [$l['packs'], $l['units']]);
        $l = self::math(['review' => 14, 'never' => true]);
        self::assertSame([150, 0, 0, 0], [$l['need'], $l['packs'], $l['units'], $l['value_e4']], 'never suggested: nothing, whatever N');
        self::assertSame(0, self::math(['review' => 14, 'pack_price_e4' => null, 'never' => true])['packs']);
        self::assertNull(self::math(['review' => 14, 'pack_price_e4' => null])['value_e4'], 'no price: no cost');
    }

    /**
     * A cost past what a whole number holds (the Codex re-check of 9df1a0e, MEDIUM): 1,000,000 a day, 999,999 in stock, £9,999,999.9999 a pack;
     * bought for 180 days that is 179,000,001 packs, £1.79 × 10^15, past £100 trillion: held at ReorderMath::VALUE_MAX_E4, never a float
     * (the list's cost, its total, its order and its download stay whole numbers that print). The usual figure is the same as before.
     */
    public function testACostPastTheLargestFigureIsHeldThere(): void
    {
        self::assertSame(1_000_000_000_000_000_000, ReorderMath::VALUE_MAX_E4, '£100,000,000,000,000.00');
        $in = ['rate_e4' => 10_000_000_000, 'lead' => 0, 'safety' => 0, 'available' => 999_999, 'on_order' => 0, 'upp' => 1, 'pack_price_e4' => 99_999_999_999];
        $usual = self::math(['review' => 1] + $in);
        self::assertSame([1, 99_999_999_999], [$usual['packs'], $usual['value_e4']]);
        $long = self::math(['review' => 180] + $in);
        self::assertSame([179_000_001, ReorderMath::VALUE_MAX_E4], [$long['packs'], $long['value_e4']]);
        self::assertSame(10_000 * 99_999_999_999, self::math(['review' => 1, 'available' => 999_999 - 9_999] + $in)['value_e4'], 'up to the limit: exact');
        $line = ReorderList::forDays(self::line(['review' => 1, 'available' => 999_999, 'on_order' => 0, 'upp' => 1, 'rate_e4' => 10_000_000_000, 'lead' => 0, 'safety' => 0,
            'pack_price' => '9999999.9999']), 180);
        self::assertSame(ReorderMath::VALUE_MAX_E4, $line['value_e4']);
        self::assertSame('100,000,000,000,000.00', ReorderList::money($line['value_e4']), 'the page prints it');
        self::assertSame('100000000000000.00', ReorderList::amount($line['value_e4']), 'the download too');
        self::assertSame(ReorderMath::VALUE_MAX_E4, ReorderController::addCost(ReorderMath::VALUE_MAX_E4, 450_000), 'the page\'s total is held there too');
        self::assertSame(ReorderMath::VALUE_MAX_E4, ReorderController::addCost(ReorderMath::VALUE_MAX_E4 - 1, ReorderMath::VALUE_MAX_E4));
        self::assertSame(900_000, ReorderController::addCost(450_000, 450_000));
    }

    /** A stored line bought for N: ReorderMath's own figures with N, `buy_for` and the usual cycle kept; again for another N, the same. */
    public function testALineBoughtForNIsReorderMathWithN(): void
    {
        $usual = self::line(['moq' => 2, 'min_stock' => 20, 'max_stock' => 400]);
        $two = ReorderList::forDays($usual, 14);
        $math = self::math(['review' => 14, 'moq' => 2, 'min_stock' => 20, 'max_stock' => 400]);
        foreach ($math as $k => $v) {
            self::assertSame($v, $two[$k], $k);
        }
        self::assertSame([14, 7, 14], [$two['review'], $two['usual_review'], $two['buy_for']]);
        self::assertSame(array_keys($usual), array_slice(array_keys($two), 0, count($usual)), 'the line\'s own fields, in their order, then buy_for');
        $again = ReorderList::forDays($two, 28);
        self::assertSame([28, 7, 28], [$again['review'], $again['usual_review'], $again['buy_for']], 'the usual cycle is never lost');
        self::assertSame(ReorderList::forDays($usual, 28), $again);
        $never = ReorderList::forDays(self::line(['never' => 'marked "do not reorder"']), 28);
        self::assertSame(0, $never['packs']);
        self::assertNull(ReorderList::forDays(self::line(['pack_price' => null]), 14)['value_e4']);
    }

    /** The compact inputs of a line (0061): what the SQL works N out from. */
    public function testTheCompactInputs(): void
    {
        self::assertSame(['d_e6', 'lead_days', 'safety_days', 'stock_position', 'min_stock', 'max_stock', 'units_per_pack', 'moq_packs', 'multiple_packs',
            'round_nearest', 'pack_price_e4'], ReorderLines::INPUTS);
        self::assertSame([12_000_000, 2, 5, 102, null, null, 24, 1, 1, 0, 450_000], ReorderLines::inputs(self::line()));
        self::assertSame([12_000_000, 2, 5, 102, 3, 90, 24, 2, 5, 1, null],
            ReorderLines::inputs(self::line(['min_stock' => 3, 'max_stock' => 90, 'moq' => 2, 'mult' => 5, 'rounding' => 'nearest', 'pack_price' => null])));
        self::assertSame(-8, ReorderLines::inputs(self::line(['available' => -50, 'on_order' => 42]))[3], 'stock + on order may be below 0');
    }

    /** The days in words, the choices of "Buy for", the filter's number. */
    public function testTheDaysInWordsAndTheChoices(): void
    {
        self::assertSame(['1 day', '10 days', '1 week', '2 weeks', '4 weeks', '45 days', '26 weeks'],
            array_map(ReorderController::daysWords(...), [1, 10, 7, 14, 28, 45, 182]));
        $o = ReorderController::buyForOptions([7, 14, 28], null);
        self::assertSame([['', Words::REORDER_BUY_FOR['usual'], true], ['7', '1 week', false], ['14', '2 weeks', false], ['28', '4 weeks', false]],
            array_map(static fn (array $x): array => [$x['value'], $x['words'], $x['selected']], $o));
        self::assertSame(['14'], array_column(array_filter(ReorderController::buyForOptions([7, 14, 28], 14), static fn (array $x): bool => $x['selected']), 'value'));
        $typed = ReorderController::buyForOptions([7, 14, 28], 45);
        self::assertSame(['45', '45 days', true], [end($typed)['value'], end($typed)['words'], end($typed)['selected']], 'a typed number is shown as chosen');
        self::assertSame(5, count($typed));
        foreach (['14' => 14, '1' => 1, '999' => 999, '0' => null, '1000' => null, '07' => null, '-3' => null, '2.5' => null, 'x' => null, '' => null] as $raw => $want) {
            self::assertSame($want, ReorderList::filters(['buy_for' => (string) $raw])['buy_for'], "buy_for={$raw}");
        }
        self::assertNull(ReorderList::filters([])['buy_for'], 'the usual cycles by default');
    }

    /** The "Why" and the maths say the days were chosen; the usual line says what it said. */
    public function testTheWhyAndTheMathsSayTheDaysWereChosen(): void
    {
        $usual = self::line() + ['demand_detail' => ['listings' => []]];
        $two = ReorderList::forDays($usual, 14);
        $why = ReorderWhy::sentences($two);
        self::assertContains(Words::say('WHY', 'cover_buy_for', 2, 14, 5, 21), $why);
        self::assertStringContainsString('bought for 14 days (you chose)', implode(' ', $why));
        self::assertContains(Words::say('WHY', 'cover', 2, 7, 5, 14), ReorderWhy::sentences($usual));
        self::assertStringContainsString('Cover 2 lead + 14 bought for (you chose, in place of 7 review) + 5 safety = 21 days → target 252.', Explain::supply($two));
        self::assertStringContainsString('Cover 2 lead + 7 review + 5 safety = 14 days → target 168.', Explain::supply($usual));
    }

    /** The three settings of 0061: each a range or a form, a plain name, a help text, the topic Reorder, a baseline. */
    public function testTheSettingsHaveRangesAndWords(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0061_reorder_buy_for.sql');
        foreach (['reorder.buy_for_presets', 'reorder.buy_for_min_days', 'reorder.buy_for_max_days'] as $key) {
            self::assertStringContainsString("'{$key}'", $sql);
            self::assertArrayHasKey($key, Settings::RULES, $key);
            self::assertArrayHasKey($key, Words::SETTING, $key);
            self::assertArrayHasKey($key, Words::SETTING_HELP, $key);
            self::assertSame('Reorder', Words::settingTopic($key), $key);
        }
        self::assertSame([1, 365], [Settings::RULES['reorder.buy_for_max_days']['min'], Settings::RULES['reorder.buy_for_max_days']['max']]);
        $p = Settings::RULES['reorder.buy_for_presets']['pattern'];
        foreach (['7,14,28' => 1, '7' => 1, '1,2,3,4,5,6,7,8,9,10,11,12' => 1, '1,2,3,4,5,6,7,8,9,10,11,12,13' => 0, '7,,14' => 0, '0,7' => 0, '7, 14' => 0,
            '1000' => 0, '14,7' => 1] as $v => $ok) {
            self::assertSame($ok, preg_match($p, (string) $v), (string) $v);
        }
        foreach (Words::REORDER_BUY_FOR as $k => $text) {
            self::assertSame(trim($text), $text, $k);
        }
    }

    /**
     * 0061 runs again cleanly (rule of 0056/0060): its inserts are guarded, the columns are added only while none is there (a prepared
     * statement, INSTANT), the back-fill only touches rows without inputs; nothing is deleted or dropped.
     */
    public function testTheMigrationRunsAgainCleanly(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0061_reorder_buy_for.sql'));
        foreach ($statements as $s) {
            self::assertDoesNotMatchRegularExpression('/^\s*(DELETE|DROP|TRUNCATE|CREATE\s+TABLE)\b/i', $s);
            if (preg_match('/^\s*INSERT\b/i', $s) === 1) {
                self::assertMatchesRegularExpression('/\bNOT\s+EXISTS\b/i', $s, 'guarded');
            }
            if (preg_match('/^\s*UPDATE\b/i', $s) === 1) {
                self::assertMatchesRegularExpression('/WHERE\s+rl\.d_e6\s+IS\s+NULL/i', $s, 'only rows without inputs');
            }
            self::assertDoesNotMatchRegularExpression('/^\s*ALTER\b/i', $s, 'the ALTER only inside the guarded prepared statement');
        }
        $set = implode("\n", array_filter($statements, static fn (string $s): bool => str_starts_with(ltrim($s), 'SET @cw_0061')));
        self::assertStringContainsString('ALGORITHM = INSTANT', $set);
        self::assertStringContainsString("WHEN COUNT(*) = 11 THEN 'DO 0'", $set);
        foreach (ReorderLines::INPUTS as $c) {
            self::assertStringContainsString("ADD COLUMN {$c} ", $set, $c);
            self::assertStringContainsString("'{$c}'", $set, "{$c}: counted");
        }
    }
}
