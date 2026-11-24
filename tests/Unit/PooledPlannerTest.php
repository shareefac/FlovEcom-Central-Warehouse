<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Profit\PooledPlanner;
use PHPUnit\Framework\TestCase;

/**
 * The money rules of a pooled (Royal Mail) bill (docs/decisions.md DL56-DL62), pure: the day book across bills (every label the day's price
 * from the first bill, the difference one line per day split by the label holders, a later bill's tail taking back from it), Special Delivery by
 * declared-weight band or the average, wrong weight shared over its sales order's labels, the rest the main shop's; every row adds up.
 */
final class PooledPlannerTest extends TestCase
{
    private const VPG = 1;
    private const EF = 2;
    private const HAYATI = 7;

    /** @return array<string, mixed> */
    private static function ctx(): array
    {
        return ['main' => self::VPG, 'products' => ['TPL' => ['kind' => 'flat', 'service_code' => 'TPL48HV'], 'TPM' => ['kind' => 'flat', 'service_code' => 'TPM24HV'],
            'SD1' => ['kind' => 'band', 'service_code' => 'SD1'], 'RX1' => ['kind' => 'wrong_weight', 'service_code' => null],
            'RXA' => ['kind' => 'wrong_weight', 'service_code' => null], 'RW4' => ['kind' => 'surcharge', 'service_code' => null],
            'TSS' => ['kind' => 'return', 'service_code' => null]], 'bands' => ['SD1' => [100, 500, 1000, 2000, 10000, 20000]]];
    }

    /** @return array<string, mixed> */
    private static function row(int $bill, int $no, string $day, string $product, int $qty, int $net, string $refKind = 'preadvice', ?string $ref = '1', ?string $so = null): array
    {
        return ['invoice_id' => $bill, 'row_no' => $no, 'rank' => sprintf('2026-11-%02d|%d', $bill, $bill), 'service_date' => $day, 'product_code' => $product, 'qty' => $qty,
            'net' => $net, 'ref_kind' => $refKind, 'ref_no' => $refKind === 'none' ? null : $ref, 'sales_order' => $so];
    }

    /** @return array<string, mixed> */
    private static function order(int $ch, string $ref): array
    {
        return ['dest' => 'order', 'channel_id' => $ch, 'order_ref' => $ref, 'parcel_ref' => 'p' . $ref, 'party_id' => null, 'held_reason' => null];
    }

    /** @return array<string, mixed> */
    private static function label(int $id, string $service, ?string $day, array $dest, ?int $manifest = null, ?int $grams = null, ?string $so = null): array
    {
        return ['id' => $id, 'tracking_no' => sprintf('JV%09dGB', $id), 'service_code' => $service, 'manifest_day' => $day, 'manifest_no' => $manifest, 'weight_g' => $grams,
            'sales_order' => $so, 'dest' => $dest];
    }

    /** @param list<array<string, mixed>> $a @return array<string, int> "invoice|row" => pence */
    private static function byRow(array $a): array
    {
        $out = [];
        foreach ($a as $x) {
            $k = $x['invoice_id'] . '|' . $x['row_no'];
            $out[$k] = ($out[$k] ?? 0) + $x['pence'];
        }
        ksort($out);
        return $out;
    }

    /** @param list<array<string, mixed>> $a @return array<string, int> "dest|channel|party" => pence */
    private static function byHolder(array $a, ?string $kind = null): array
    {
        $out = [];
        foreach ($a as $x) {
            if ($kind !== null && $x['kind'] !== $kind) {
                continue;
            }
            $k = $x['dest'] . '|' . ($x['channel_id'] ?? '') . '|' . ($x['party_id'] ?? '') . '|' . ($x['held_reason'] ?? '');
            $out[$k] = ($out[$k] ?? 0) + $x['pence'];
        }
        ksort($out);
        return $out;
    }

    /** @return array<int, array<string, mixed>> five labels of 9 Nov's Tracked 48: three Vape and Go orders, an Electrofag one, a Hayati one */
    private static function dayLabels(): array
    {
        $l = [];
        foreach ([1 => self::order(self::VPG, 'a'), 2 => self::order(self::VPG, 'b'), 3 => self::order(self::EF, 'c'),
            4 => ['dest' => 'party', 'channel_id' => null, 'order_ref' => null, 'parcel_ref' => null, 'party_id' => self::HAYATI, 'held_reason' => null],
            5 => self::order(self::VPG, 'e')] as $id => $d) {
            $l[$id] = self::label($id, 'TPL48HV', '2026-11-09', $d);
        }
        return $l;
    }

    public function testEveryLabelOfTheDayTakesTheFirstBillsPriceAndTheDifferenceIsOneLineSplitByHolders(): void
    {
        $rows = [self::row(10, 1, '2026-11-09', 'TPL', 2, 509, 'preadvice', '943'), self::row(10, 2, '2026-11-09', 'TPL', 2, 509, 'preadvice', '7312')];
        $a = PooledPlanner::plan($rows, self::dayLabels(), self::ctx());
        self::assertSame(['10|1' => 509, '10|2' => 509], self::byRow($a), 'every row adds up to its net');
        $prices = array_values(array_filter($a, static fn (array $x): bool => $x['kind'] === 'label'));
        self::assertSame([255, 255, 255, 255, 255], array_column($prices, 'pence'), '1018p / 4 = 254.5p: 255p each, half up, on all 5 labels');
        self::assertSame([1, 2, 3, 4, 5], array_column($prices, 'label_id'));
        self::assertSame([1, 1, 2, 2, 2], array_column($prices, 'row_no'), 'two on the first row (its quantity), the rest on the last');
        self::assertSame(['order|1||' => 765, 'order|2||' => 255, 'party||7|' => 255], self::byHolder($prices));
        // 4 billed, 5 labels: the day's line is -257p (1018 - 5 x 255), split by the holders' counts (3 / 1 / 1 of 5), pence left to the main shop
        self::assertSame(['party||7|' => -51, 'shop|1||' => -155, 'shop|2||' => -51], self::byHolder($a, 'day_line'));
    }

    public function testALaterBillsTailTakesBackFromTheDaysLineAndNoPriceMoves(): void
    {
        $first = [self::row(10, 1, '2026-11-09', 'TPL', 2, 509), self::row(10, 2, '2026-11-09', 'TPL', 2, 509)];
        $tail = self::row(11, 4, '2026-11-09', 'TPL', 1, 254);
        $before = PooledPlanner::plan($first, self::dayLabels(), self::ctx());
        $after = PooledPlanner::plan([...$first, $tail], self::dayLabels(), self::ctx());
        $ids = static fn (array $a): array => array_map([PooledPlanner::class, 'identity'], array_values(array_filter($a, static fn (array $x): bool => $x['invoice_id'] === 10)));
        self::assertSame($ids($before), $ids($after), 'the first bill\'s allocations are as they were: no order\'s amount moves');
        self::assertSame(['11|4' => 254], array_intersect_key(self::byRow($after), ['11|4' => 1]));
        self::assertSame(['party||7|' => 50, 'shop|1||' => 154, 'shop|2||' => 50], self::byHolder(array_filter($after, static fn (array $x): bool => $x['invoice_id'] === 11)),
            'the tail is the day\'s line, by the same shares');
        self::assertSame(-3, array_sum(array_column(array_filter($after, static fn (array $x): bool => $x['kind'] === 'day_line'), 'pence')), '5 labels, 5 billed: 3p rounding left');
        // the first bill undone: the tail's bill is the first now, its price for every label
        $alone = PooledPlanner::plan([$tail], self::dayLabels(), self::ctx());
        self::assertSame([254, 254, 254, 254, 254], array_column(array_values(array_filter($alone, static fn (array $x): bool => $x['kind'] === 'label')), 'pence'));
        self::assertSame(['11|4' => 254], self::byRow($alone));
    }

    public function testADayWithNoLabelIsBilledWithNoLabelFoundOnTheMainShop(): void
    {
        $a = PooledPlanner::plan([self::row(10, 1, '2026-11-08', 'TPM', 3, 1014)], self::dayLabels(), self::ctx());
        self::assertSame([['day_line', 'day', 'shop', self::VPG, 1014]], array_map(static fn (array $x): array => [$x['kind'], $x['how'], $x['dest'], $x['channel_id'], $x['pence']], $a));
    }

    public function testSpecialDeliveryByDeclaredWeightBandWhenTheCountsFitElseTheAverage(): void
    {
        $labels = [];
        foreach ([21 => 80, 22 => 100, 23 => 300, 24 => 500, 25 => 120] as $id => $g) {
            $labels[$id] = self::label($id, 'SD1', '2026-11-05', self::order(self::VPG, "s{$id}"), 2281, $g, '5155465848');
        }
        $rows = [self::row(10, 1, '2026-11-05', 'SD1', 3, 3185, 'manifest', '2281', '5155465848'), self::row(10, 2, '2026-11-05', 'SD1', 2, 2007, 'manifest', '2281', '5155465848')];
        $a = PooledPlanner::plan($rows, $labels, self::ctx());
        self::assertSame(['band'], array_values(array_unique(array_column($a, 'how'))));
        $got = [];
        foreach ($a as $x) {
            $got[$x['label_id']] = [$x['row_no'], $x['pence']];
        }
        ksort($got);
        // 100g is the first band (edges inclusive): 80g and 100g take the cheaper row (2007p: 1004 + 1003), 120-500g the dearer (3185p)
        self::assertSame([21 => [2, 1004], 22 => [2, 1003], 23 => [1, 1062], 24 => [1, 1062], 25 => [1, 1061]], $got);
        // a label of another band than the rows' (a 600g one instead of 500g): the counts do not fit, every label the average of every row
        $labels[24]['weight_g'] = 600;
        $b = PooledPlanner::plan($rows, $labels, self::ctx());
        self::assertSame(['average'], array_values(array_unique(array_column($b, 'how'))));
        self::assertSame(['10|1' => 3185, '10|2' => 2007], self::byRow($b));
        $per = [];
        foreach ($b as $x) {
            $per[$x['label_id']] = ($per[$x['label_id']] ?? 0) + $x['pence'];
        }
        self::assertSame([21 => 1039, 22 => 1039, 23 => 1038, 24 => 1038, 25 => 1038], $per, '5192p over 5 labels');
        // a row naming only its sales order finds its manifest through the labels; a manifest with no label: the main shop's
        $c = PooledPlanner::plan([self::row(10, 3, '2026-11-05', 'SD1', 5, 5192, 'none', null, '5155465848')], $labels, self::ctx());
        self::assertSame(['average'], array_values(array_unique(array_column($c, 'how'))));
        $d = PooledPlanner::plan([self::row(10, 4, '2026-11-06', 'SD1', 1, 1189, 'manifest', '2290')], $labels, self::ctx());
        self::assertSame([['row', 'no_label', 'shop', self::VPG, 1189]], array_map(static fn (array $x): array => [$x['kind'], $x['how'], $x['dest'], $x['channel_id'], $x['pence']], $d));
    }

    public function testWrongWeightIsSharedOverItsSalesOrdersLabelsTheRestTheMainShops(): void
    {
        $labels = [];
        foreach ([31 => self::order(self::VPG, 'x'), 32 => self::order(self::EF, 'y'), 33 => ['dest' => 'held', 'channel_id' => null, 'order_ref' => null, 'parcel_ref' => null,
            'party_id' => null, 'held_reason' => 'shop_unknown']] as $id => $d) {
            $labels[$id] = self::label($id, 'SD1', '2026-11-05', $d, 2281, 300, '5155465848');
        }
        $rows = [self::row(10, 1, '2026-11-06', 'RX1', 3, 925, 'sales_order', '5155465848'), self::row(10, 2, '2026-11-06', 'RXA', 3, 375, 'sales_order', '5155465848'),
            self::row(10, 3, '2026-11-06', 'RX1', 1, 275, 'sales_order', '5155000000'), self::row(10, 4, '2026-11-08', 'RW4', 31, 3255, 'week', '32'),
            self::row(10, 5, '2026-11-04', 'TSS', 4, 1078, 'none', null)];
        $a = PooledPlanner::plan($rows, $labels, self::ctx());
        self::assertSame(['10|1' => 925, '10|2' => 375, '10|3' => 275, '10|4' => 3255, '10|5' => 1078], self::byRow($a));
        $ww = array_values(array_filter($a, static fn (array $x): bool => $x['kind'] === 'wrong_weight'));
        self::assertSame([308, 308, 308, 125, 125, 125], array_column($ww, 'pence'));
        self::assertSame(['held|||shop_unknown' => 433, 'order|1||' => 433, 'order|2||' => 433], self::byHolder($ww), 'each share where its label belongs');
        $rest = array_map(static fn (array $x): array => [$x['row_no'], $x['how'], $x['dest'], $x['channel_id'], $x['pence']],
            array_values(array_filter($a, static fn (array $x): bool => $x['kind'] === 'row')));
        self::assertSame([[1, 'left_over', 'shop', self::VPG, 1], [3, 'no_batch', 'shop', self::VPG, 275], [4, 'surcharge', 'shop', self::VPG, 3255],
            [5, 'return', 'shop', self::VPG, 1078]], $rest);
    }

    /** The Codex check r3, new MEDIUM 5: a first bill of the day with only a credit sets no price: the first bill with units billed does. */
    public function testACreditOnlyFirstBillSetsNoPrice(): void
    {
        $labels = array_slice(self::dayLabels(), 0, 2, true);
        $a = PooledPlanner::plan([self::row(10, 1, '2026-11-09', 'TPL', -1, -254), self::row(11, 1, '2026-11-09', 'TPL', 2, 508)], $labels, self::ctx());
        self::assertSame([254, 254], array_column(array_values(array_filter($a, static fn (array $x): bool => $x['kind'] === 'label')), 'pence'));
        self::assertSame(['10|1' => -254, '11|1' => 508], self::byRow($a));
    }

    public function testTheSameInputsGiveTheSamePlanInAnyOrder(): void
    {
        $rows = [self::row(10, 1, '2026-11-09', 'TPL', 2, 509), self::row(10, 2, '2026-11-09', 'TPL', 2, 509), self::row(11, 4, '2026-11-09', 'TPL', 1, 254)];
        $labels = self::dayLabels();
        $a = PooledPlanner::plan($rows, $labels, self::ctx());
        $b = PooledPlanner::plan(array_reverse($rows), array_reverse($labels, true), self::ctx());
        self::assertSame($a, $b);
        self::assertSame([0, 1, 2], PooledPlanner::shares(2, 3) === [1, 1, 0] ? [0, 1, 2] : [], 'a penny more to the first ones');
        self::assertSame([-1, -1, 0], PooledPlanner::shares(-2, 3));
        self::assertSame([255, -255, 254], [PooledPlanner::roundDiv(1018, 4), PooledPlanner::roundDiv(-1018, 4), PooledPlanner::roundDiv(1017, 4)]);
    }
}
