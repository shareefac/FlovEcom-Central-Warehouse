<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Reorder\Explain;
use PHPUnit\Framework\TestCase;

/** The "Why" of a reorder line (spec §7.4, docs/decisions.md I65). */
final class ExplainTest extends TestCase
{
    /** @return array<string, mixed> the spec's example: two listings, the stockpiling window, promotions, out-of-stock days, a cap */
    private static function example(): array
    {
        $anomaly = ['id' => 1, 'label' => 'Pre-duty stockpiling before the 1 Oct 2026 vaping products duty (+43% units/day)', 'from' => '2026-09-14',
            'to' => '2026-09-22', 'short' => 9, 'long' => 9];
        return [
            'rate_e4' => 124_000, 'rate_short_e4' => 131_000, 'rate_long_e4' => 117_000, 'weight_e6' => 500_000, 'short_window' => 28, 'long_window' => 91,
            'min_long' => 21, 'factor_e2' => 100, 'factor_source' => 'brand',
            'listings' => [
                ['channel' => 'vapeandgo', 'rate_e4' => 119_000, 'method' => 'blend', 'valid_short' => 19, 'valid_long' => 76,
                    'excluded' => ['nodata' => 0, 'before_first' => 2, 'anomaly' => 9, 'promo' => 2, 'oos' => 2],
                    'excluded_short' => ['nodata' => 0, 'before_first' => 0, 'anomaly' => 9, 'promo' => 0, 'oos' => 0], 'anomalies' => [$anomaly], 'cap' => 40,
                    'capped' => 1, 'r_short_e4' => 126_000, 'r_long_e4' => 112_000],
                ['channel' => 'electrofag', 'rate_e4' => 5_000, 'method' => 'blend', 'valid_short' => 28, 'valid_long' => 82,
                    'excluded' => ['nodata' => 0, 'before_first' => 0, 'anomaly' => 9, 'promo' => 0, 'oos' => 0],
                    'excluded_short' => ['nodata' => 0, 'before_first' => 0, 'anomaly' => 0, 'promo' => 0, 'oos' => 0], 'anomalies' => [$anomaly], 'cap' => 5,
                    'capped' => 0, 'r_short_e4' => 5_000, 'r_long_e4' => 5_000],
            ],
            'lead' => 2, 'review' => 7, 'safety' => 5, 'cover_days' => 14, 'target' => 174, 'target_raw' => 174, 'min_applied' => false, 'max_applied' => false,
            'stock_source' => 'cw', 'site_date' => null, 'site_channel' => 'vapeandgo', 'available' => 60, 'on_order' => 48, 'in_drafts' => 0, 'position' => 108,
            'need' => 66, 'packs' => 3, 'packs_raw' => 3, 'units' => 72, 'upp' => 24, 'purchase_unit' => 'box', 'moq' => 2, 'moq_applied' => false, 'mult' => 1,
            'mult_applied' => false, 'rounding' => 'up', 'no_supplier' => false, 'never' => null, 'urgent' => false, 'rop' => 99, 'rate_raw_30_e4' => 152_000,
        ];
    }

    public function testTheSpecExample(): void
    {
        self::assertSame('Demand 12.4/day = 0.5×13.1 (28 days: 19 valid; 9 excluded: Pre-duty stockpiling 14–22 Sep) + 0.5×11.7 (91 days: 76 valid; '
            . '15 excluded: 9 Pre-duty stockpiling 14–22 Sep, 2 promotion, 2 out of stock, 2 before the first sale; 1 day capped at 40) '
            . '[vapeandgo 11.9 + electrofag 0.50]; brand factor 1.00. Cover 2 lead + 7 review + 5 safety = 14 days → target 174. Available 60 + on order 48 '
            . '= 108 (in drafts 0). Need 66 → 3 × box of 24 = 72. Plain 30-day average 15.2/day.', Explain::text(self::example()));
    }

    public function testTheOtherCases(): void
    {
        $x = self::example();
        // A factor, the MOQ, urgent, site stock, a minimum stock.
        $x['factor_e2'] = 85;
        $x['moq_applied'] = true;
        $x['urgent'] = true;
        $x['stock_source'] = 'site';
        $x['site_date'] = '2026-10-01';
        $x['min_applied'] = true;
        $x['target_raw'] = 148;
        $t = Explain::text($x);
        self::assertStringContainsString('; brand factor 0.85 → 10.5/day.', $t);
        self::assertStringContainsString('= 72 (MOQ 2).', $t);
        self::assertStringContainsString('Below the reorder point 99 (2 lead + 5 safety days).', $t);
        self::assertStringContainsString('Site stock 60 (vapeandgo, 1 Oct 2026) + on order 48', $t);
        self::assertStringContainsString('target 174 (the minimum stock; demand alone gives 148).', $t);
        // Never suggested; nothing needed; no history; one listing on the short window only; mixed methods.
        self::assertStringContainsString('Need 66, never suggested: merged into CW-000009.', Explain::supply(['never' => 'merged into CW-000009'] + self::example()));
        self::assertStringContainsString('Need 0: nothing to order.', Explain::supply(['need' => 0, 'packs' => 0, 'units' => 0] + self::example()));
        self::assertSame('No sales history: demand 0/day; factor 1.00.', Explain::demand(['rate_e4' => 0, 'listings' => [], 'factor_source' => 'default']));
        $one = self::example();
        $one['listings'] = [['method' => 'short', 'rate_e4' => 100_000] + $one['listings'][0]];
        $one['rate_e4'] = 100_000;
        $one['rate_short_e4'] = 100_000;
        self::assertStringStartsWith('Demand 10.0/day = the short window 10.0 (28 days: 19 valid; 9 excluded: Pre-duty stockpiling 14–22 Sep; fewer than 21 valid days in 91)',
            Explain::demand($one));
        $mixed = self::example();
        $mixed['listings'][1]['method'] = 'min7';
        self::assertStringStartsWith('Demand 12.4/day = vapeandgo 11.9 (0.5×12.6 (28 days', Explain::demand($mixed));
        self::assertStringContainsString(' + electrofag 0.50 (too few valid days, averaged over at least 7 (91 days: 82 valid; 9 excluded: Pre-duty stockpiling 14–22 Sep))',
            Explain::demand($mixed));
        $no = self::example();
        $no['no_supplier'] = true;
        $no['upp'] = 1;
        $no['units'] = 66;
        $no['packs'] = 66;
        self::assertStringContainsString('Need 66 → 66 units (no preferred supplier).', Explain::supply($no));
    }

    public function testFormatting(): void
    {
        self::assertSame(['12.4', '0.05', '1.0', '0.00', '100.0'], [Explain::rate(124_000), Explain::rate(500), Explain::rate(9_996), Explain::rate(0), Explain::rate(999_999)]);
        self::assertSame(['14–22 Sep', '28 Sep–3 Oct', '1 Oct', '30 Dec 2025–2 Jan 2026', '14–22 Sep 2026'], [Explain::range('2026-09-14', '2026-09-22'),
            Explain::range('2026-09-28', '2026-10-03'), Explain::range('2026-10-01', '2026-10-01'), Explain::range('2025-12-30', '2026-01-02'),
            Explain::range('2026-09-14', '2026-09-22', true)]);
        self::assertSame(['Pre-duty stockpiling', 'Black Friday', 'Bank holiday weekend at the shop'], [
            Explain::shortLabel('Pre-duty stockpiling before the 1 Oct 2026 vaping products duty (+43% units/day on Vape and Go vs Jul-Aug)'),
            Explain::shortLabel('Black Friday (27 Nov)'), Explain::shortLabel('Bank holiday weekend at the shop')]);
    }
}
