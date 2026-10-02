<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Reorder\ReorderMath;
use PHPUnit\Framework\TestCase;

/** One reorder line (spec §7.4, docs/decisions.md I64): target, reorder point, position, need, packs, flags, order. */
final class ReorderMathTest extends TestCase
{
    /**
     * 12 a day, 2 lead + 7 review + 5 safety = 14 days -> target 168; available 60 + on order 42 = 102 -> need 66.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function line(array $over = []): array
    {
        return ReorderMath::line($over + ['rate_e4' => 120_000, 'factor_e2' => 100, 'lead' => 2, 'review' => 7, 'safety' => 5, 'min_stock' => null,
            'max_stock' => null, 'available' => 60, 'on_order' => 42, 'in_drafts' => 0, 'upp' => 24, 'moq' => 1, 'mult' => 1, 'rounding' => 'up', 'never' => false,
            'pack_price_e4' => 450_000]);
    }

    public function testNeedToPacksWithMoqAndMultiple(): void
    {
        $l = self::line();
        self::assertSame([14, 168, 102, 66, 3, 72], [$l['cover_days'], $l['target'], $l['position'], $l['need'], $l['packs'], $l['units']]);
        self::assertSame(3 * 450_000, $l['value_e4'], '3 packs at £45.00');
        self::assertFalse($l['moq_applied']);
        $l = self::line(['moq' => 4]);
        self::assertSame([4, 96, true], [$l['packs'], $l['units'], $l['moq_applied']]);
        $l = self::line(['mult' => 5]);
        self::assertSame([5, true], [$l['packs'], $l['mult_applied']]);
        $l = self::line(['moq' => 4, 'mult' => 3]);
        self::assertSame(6, $l['packs'], 'MOQ 4, then a multiple of 3');
        self::assertNull(self::line(['pack_price_e4' => null])['value_e4']);
    }

    public function testNearestPackRounding(): void
    {
        // need 25 with packs of 24: 1.04 -> 1; need 11: 0.46 -> 0 (nothing ordered, MOQ not applied).
        $l = self::line(['rounding' => 'nearest', 'available' => 168 - 25, 'on_order' => 0]);
        self::assertSame([25, 1, 24], [$l['need'], $l['packs'], $l['units']]);
        $l = self::line(['rounding' => 'nearest', 'available' => 168 - 11, 'on_order' => 0, 'moq' => 2]);
        self::assertSame([11, 0, 0], [$l['need'], $l['packs'], $l['units']]);
        $l = self::line(['rounding' => 'up', 'available' => 168 - 11, 'on_order' => 0]);
        self::assertSame(1, $l['packs'], 'up: any need is a pack');
        self::assertSame(2, self::line(['rounding' => 'nearest', 'available' => 168 - 36, 'on_order' => 0])['packs'], '1.5 -> 2 (half up)');
    }

    public function testMinimumAndMaximumStock(): void
    {
        $l = self::line(['min_stock' => 200]);
        self::assertSame([200, 168, true, 98], [$l['target'], $l['target_raw'], $l['min_applied'], $l['need']]);
        $l = self::line(['max_stock' => 150]);
        self::assertSame([150, true, 48, 2], [$l['target'], $l['max_applied'], $l['need'], $l['packs']]);
        $l = self::line(['rate_e4' => 0, 'min_stock' => 30, 'available' => 10, 'on_order' => 0]);
        self::assertSame([30, 30, 20, 1, true], [$l['target'], $l['rop'], $l['need'], $l['packs'], $l['urgent']], 'no demand: the minimum stock alone');
    }

    public function testNegativeAvailableOnOrderAndDrafts(): void
    {
        $l = self::line(['available' => -20, 'on_order' => 0]);
        self::assertSame([-20, 188, 8], [$l['position'], $l['need'], $l['packs']]);
        self::assertTrue($l['urgent']);
        self::assertSame(66, self::line(['in_drafts' => 1000])['need'], 'drafts are shown, never counted');
        self::assertSame(66 - 48, self::line(['on_order' => 42 + 48])['need'], 'on order reduces the need');
        self::assertSame([0, 0], [self::line(['on_order' => 500])['need'], self::line(['on_order' => 500])['packs']]);
    }

    public function testDemandFactor(): void
    {
        // 12 × 0.85 = 10.2 a day; × 14 = 142.8 -> 143.
        $l = self::line(['factor_e2' => 85]);
        self::assertSame([10_200_000, 143, 41, 2], [$l['d_e6'], $l['target'], $l['need'], $l['packs']]);
        self::assertSame(0, self::line(['factor_e2' => 0])['target']);
    }

    public function testReorderPointUrgentAndCoverNow(): void
    {
        // ROP = ⌈12 × (2 + 5)⌉ = 84.
        $l = self::line(['available' => 80, 'on_order' => 0]);
        self::assertSame([84, true], [$l['rop'], $l['urgent']]);
        self::assertSame(67, $l['cover_now_e1'], '80 / 12 = 6.7 days');
        $l = self::line(['available' => 84, 'on_order' => 0]);
        self::assertFalse($l['urgent']);
        self::assertSame(85, self::line()['cover_now_e1'], '102 / 12 = 8.5 days');
        self::assertNull(self::line(['rate_e4' => 0])['cover_now_e1'], 'no demand: cover is infinite');
        self::assertSame(-17, self::line(['available' => -20, 'on_order' => 0])['cover_now_e1']);
        $min = self::line(['rate_e4' => 0, 'min_stock' => 50, 'available' => 60, 'on_order' => 0]);
        self::assertSame([50, false], [$min['rop'], $min['urgent']]);
        // Review finding (I80): a maximum below the cover caps the reorder point too: never urgent with nothing to order.
        $max = self::line(['rate_e4' => 100_000, 'max_stock' => 20, 'available' => 30, 'on_order' => 0]);
        self::assertSame([20, 20, 0, 0, false], [$max['target'], $max['rop'], $max['need'], $max['packs'], $max['urgent']]);
        $max = self::line(['rate_e4' => 100_000, 'max_stock' => 20, 'available' => 15, 'on_order' => 0]);
        self::assertSame([20, 5, true], [$max['rop'], $max['need'], $max['urgent']], 'below the capped point it is urgent and orders');
    }

    public function testNeverSuggested(): void
    {
        $l = self::line(['never' => true]);
        self::assertSame([66, 0, 0], [$l['need'], $l['packs'], $l['units']]);
    }

    public function testTheListOrder(): void
    {
        $rows = [
            ['sku_id' => 1, 'urgent' => false, 'cover_now_e1' => 50, 'value_e4' => 100],
            ['sku_id' => 2, 'urgent' => true, 'cover_now_e1' => 80, 'value_e4' => 100],
            ['sku_id' => 3, 'urgent' => false, 'cover_now_e1' => null, 'value_e4' => 900],
            ['sku_id' => 4, 'urgent' => false, 'cover_now_e1' => 50, 'value_e4' => 500],
            ['sku_id' => 5, 'urgent' => true, 'cover_now_e1' => -10, 'value_e4' => null],
            ['sku_id' => 6, 'urgent' => false, 'cover_now_e1' => 20, 'value_e4' => null],
        ];
        usort($rows, ReorderMath::compare(...));
        self::assertSame([5, 2, 6, 4, 1, 3], array_column($rows, 'sku_id'), 'urgent first, then cover now ascending (∞ last), then value descending');
    }
}
