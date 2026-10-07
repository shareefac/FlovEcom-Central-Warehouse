<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Matching\DuplicateSweep;
use PHPUnit\Framework\TestCase;

/**
 * The rules of the wider duplicate sweep (docs/decisions.md M37; CW\Matching\DuplicateSweep): golden pairs of Vape and Go
 * listings that ARE the same product on two pages (the owner's Corex example, a differently worded IVG page without a
 * barcode, two options of one page with the flavours in another order) and the traps that are NOT (0.4 vs 0.6 ohm, 2 ml vs
 * 10 ml, 600 vs 6000, Pro vs Pro Max, kit vs pods, 10 mg vs 20 mg, Blue Razz vs Blue Razz Lemonade, a single vs a 3-pack,
 * 50/50 vs 70/30, "TPP" vs "TPP X", F1 vs F2 coils, different barcodes, another brand, double the price). ds1.1 (M43): Corex 2.0
 * Mesh and IVG 6000 pages that ARE duplicates, Riot Squad vs Black Edition and Bar Salts vs Bar Vape without barcodes that are
 * NOT, and a title's VG/PG ratio over the option row. Every pair is judged both ways round and must give the same answer.
 */
final class DuplicateSweepTest extends TestCase
{
    /** The owner's example (listings 23408 and 25772 on cw_staging, their real profiles). */
    private const COREX_A = ['variant_id' => 41615, 'product_id' => 8501, 'product_title' => 'Vaporesso Xros Corex 3.0 Pods (Pack of 4)',
        'variant_title' => 'Vaporesso Xros Corex 3.0 Pods (Pack of 4) - 0.4 ohm', 'brand' => 'Vaporesso Vape Kits & Accessories', 'price' => '9.99',
        'barcodes' => ['6943498686179', '6943498697557', '6943498698189'], 'attributes' => [
            ['name' => 'Resistance', 'value' => '0.4 ohm', 'attr_id' => 8, 'is_variable' => 1], ['name' => 'Pack Size', 'value' => '4 Pack', 'attr_id' => 19, 'is_variable' => 0],
            ['name' => 'Made In', 'value' => 'China', 'attr_id' => 22, 'is_variable' => 0], ['name' => 'Tank Capacity', 'value' => '2ml', 'attr_id' => 29, 'is_variable' => 0],
            ['name' => 'Resistance', 'value' => '0.6 ohm', 'attr_id' => 8, 'is_variable' => 0], ['name' => 'Resistance', 'value' => '0.8 ohm', 'attr_id' => 8, 'is_variable' => 0],
            ['name' => 'Resistance', 'value' => '1.0 ohm', 'attr_id' => 8, 'is_variable' => 0], ['name' => 'Resistance', 'value' => '1.2 ohm', 'attr_id' => 8, 'is_variable' => 0]]];
    private const COREX_B = ['variant_id' => 44202, 'product_id' => 2077, 'product_title' => 'Vaporesso Xros Corex Replacement Pods',
        'variant_title' => 'Vaporesso Xros Corex Replacement Pods - 0.4ohm Corex 3.0 Pod - 4 Pack', 'brand' => 'Vaporesso Vape Kits & Accessories', 'price' => '10.99',
        'barcodes' => [], 'attributes' => [
            ['name' => 'Type', 'value' => '0.4ohm Corex 3.0 Pod - 4 Pack', 'attr_id' => 18, 'is_variable' => 1], ['name' => 'Coil Resistance', 'value' => '0.4ohm', 'attr_id' => 52, 'is_variable' => 0],
            ['name' => 'Coil Resistance', 'value' => '0.6ohm', 'attr_id' => 52, 'is_variable' => 0], ['name' => 'Coil Resistance', 'value' => '0.8ohm', 'attr_id' => 52, 'is_variable' => 0],
            ['name' => 'Coil Resistance', 'value' => '1.0 ohm', 'attr_id' => 52, 'is_variable' => 0], ['name' => 'Coil Resistance', 'value' => '1.2ohm', 'attr_id' => 52, 'is_variable' => 0],
            ['name' => 'Tank Capacity', 'value' => '2ml', 'attr_id' => 29, 'is_variable' => 0], ['name' => 'Made In', 'value' => 'China', 'attr_id' => 22, 'is_variable' => 0]]];

    /** An EAN-13 with its check digit. */
    private static function ean(string $twelve): string
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }
        return $twelve . (10 - $sum % 10) % 10;
    }

    /** @param array<string, mixed> $row */
    private static function f(array $row): array
    {
        static $n = 0;
        return DuplicateSweep::features($row + ['site' => 'vapeandgo', 'variant_id' => 90000 + ++$n, 'product_id' => 70000 + $n, 'variant_title' => null,
            'attributes' => [], 'barcodes' => [], 'price' => '9.99']);
    }

    /** Judges both ways round; the answer must not depend on the order. @return array<string, mixed> */
    private static function judge(array $a, array $b): array
    {
        $x = DuplicateSweep::judge(self::f($a), self::f($b));
        $y = DuplicateSweep::judge(self::f($b), self::f($a));
        self::assertSame($x['ok'], $y['ok'], 'symmetric');
        self::assertEqualsCanonicalizing(array_column($x['blocks'], 'code'), array_column($y['blocks'], 'code'), 'the same reasons either way');
        self::assertSame($x['score'], $y['score']);
        return $x;
    }

    /** @param list<string> $codes at least one of them refuses the pair */
    private static function refused(array $a, array $b, array $codes): void
    {
        $j = self::judge($a, $b);
        self::assertFalse($j['ok'], 'refused');
        $got = array_column($j['blocks'], 'code');
        self::assertNotSame([], array_intersect($codes, $got), 'refused for ' . implode('/', $codes) . ', got ' . implode(', ', $got));
    }

    private static function liquid(string $flavour, string $strength = '20mg', string $ml = '10ml', string $brand = 'Elf Bar', string $line = 'Elfliq'): array
    {
        return ['product_title' => "{$flavour} Nic Salt E-Liquid by {$line} {$ml}", 'variant_title' => "{$flavour} Nic Salt E-Liquid by {$line} {$ml} - {$strength}",
            'brand' => $brand, 'price' => '3.99'];
    }

    public function testTheOwnersCorexPairIsADuplicateWithABarcodeOnOnePageOnly(): void
    {
        $j = self::judge(self::COREX_A, self::COREX_B);
        self::assertTrue($j['ok'], json_encode($j['blocks']));
        self::assertSame('one_side', $j['barcode']);
        self::assertContains('resistance', $j['agree']);
        self::assertContains('pack', $j['agree']);
        self::assertContains('form', $j['agree']);
        self::assertSame(0.909, $j['price_ratio']);
        self::assertGreaterThanOrEqual(50, $j['score']);
        self::assertFalse($j['same_product']);
    }

    public function testCorexAtAnotherResistanceIsNot(): void
    {
        $b = self::COREX_B;
        $b['variant_title'] = 'Vaporesso Xros Corex Replacement Pods - 0.6ohm Corex 3.0 Pod - 4 Pack';
        $b['attributes'][0]['value'] = '0.6ohm Corex 3.0 Pod - 4 Pack';
        self::refused(self::COREX_A, $b, ['veto_ohm']);
    }

    public function testASingleIsNotAThreePack(): void
    {
        self::refused(['product_title' => 'Vaporesso Xros Corex 3.0 Pod - 0.6 ohm', 'brand' => 'Vaporesso', 'price' => '3.49'],
            ['product_title' => 'Vaporesso Xros Corex 3.0 Pods (Pack of 3) - 0.6 ohm', 'brand' => 'Vaporesso', 'price' => '3.99'],
            ['veto_pack', 'flag_pack_one_side', 'pack_one_side']);
        self::refused(['product_title' => 'Vaporesso Xros Corex 3.0 Pod - 0.6 ohm (Single)', 'brand' => 'Vaporesso', 'price' => '3.49', 'attributes' => [['name' => 'Pack Size', 'value' => '1 Pack', 'is_variable' => 0]]],
            ['product_title' => 'Vaporesso Xros Corex 3.0 Pods - 0.6 ohm', 'brand' => 'Vaporesso', 'price' => '3.49', 'attributes' => [['name' => 'Pack Size', 'value' => '3 Pack', 'is_variable' => 0]]],
            ['veto_pack']);
    }

    public function testTwoMlIsNotTenMl(): void
    {
        self::refused(self::liquid('Blueberry', '20mg', '10ml'), self::liquid('Blueberry', '20mg', '2ml'), ['veto_liquid_ml']);
    }

    public function testSixHundredIsNotSixThousand(): void
    {
        self::refused(['product_title' => 'Elf Bar 600 Blueberry Disposable Vape 20mg', 'brand' => 'Elf Bar', 'price' => '4.99'],
            ['product_title' => 'Elf Bar 6000 Blueberry Disposable Vape 20mg', 'brand' => 'Elf Bar', 'price' => '5.99'], ['veto_puffs', 'veto_line_number']);
    }

    public function testProIsNotProMax(): void
    {
        self::refused(['product_title' => 'SKE Crystal Pro Prefilled Pods', 'variant_title' => 'Blue Razz Lemonade SKE Crystal Pro Prefilled Pods', 'brand' => 'SKE', 'price' => '5.99'],
            ['product_title' => 'SKE Crystal Pro Max Prefilled Pods', 'variant_title' => 'Blue Razz Lemonade SKE Crystal Pro Max Prefilled Pods', 'brand' => 'SKE', 'price' => '5.99'],
            ['veto_line_modifier']);
        self::refused(['product_title' => 'Lost Mary BM600 Pro Disposable Vape', 'variant_title' => 'Lost Mary BM600 Pro Disposable Vape - Cherry Ice 20mg', 'brand' => 'Lost Mary', 'price' => '4.99'],
            ['product_title' => 'Lost Mary BM600 Pro Max Disposable Vape', 'variant_title' => 'Lost Mary BM600 Pro Max Disposable Vape - Cherry Ice 20mg', 'brand' => 'Lost Mary', 'price' => '4.99'],
            ['veto_line_modifier', 'flag_modifier_extra']);
    }

    public function testAKitIsNotItsPods(): void
    {
        self::refused(['product_title' => 'Vaporesso Xros 3 Pod Kit', 'variant_title' => 'Vaporesso Xros 3 Pod Kit - Black', 'brand' => 'Vaporesso', 'price' => '19.99'],
            ['product_title' => 'Vaporesso Xros 3 Replacement Pods', 'variant_title' => 'Vaporesso Xros 3 Replacement Pods - 0.6 ohm', 'brand' => 'Vaporesso', 'price' => '9.99'],
            ['veto_form']);
    }

    public function testTenMgIsNotTwentyMg(): void
    {
        self::refused(self::liquid('Blue Razz Lemonade', '10mg'), self::liquid('Blue Razz Lemonade', '20mg'), ['veto_strength']);
    }

    public function testBlueRazzIsNotBlueRazzLemonade(): void
    {
        self::refused(self::liquid('Blue Razz'), self::liquid('Blue Razz Lemonade'), ['veto_flavour_superset', 'veto_flavour_diff', 'words']);
    }

    public function testTheSameLiquidWordedDifferentlyWithoutABarcodeIsADuplicate(): void
    {
        $j = self::judge(['product_title' => 'Pink Fizz Nic Salt E-Liquid by IVG Bar Salt Favourites', 'variant_title' => 'Pink Fizz Nic Salt E-Liquid by IVG Bar Salt Favourites - 10mg',
            'brand' => 'IVG', 'price' => '2.99', 'barcodes' => [self::ean('505616843640')],
            'attributes' => [['name' => 'Bottle Size', 'value' => '10ml', 'attr_id' => 4, 'is_variable' => 0], ['name' => 'PG/VG', 'value' => '50/50', 'attr_id' => 5, 'is_variable' => 0]]],
            ['product_title' => 'IVG Nic Salt 10ml', 'variant_title' => 'IVG Nic Salt 10ml - 10mg | Pink Fizz (Bar Favourites)', 'brand' => 'IVG', 'price' => '2.99']);
        self::assertTrue($j['ok'], json_encode($j['blocks']));
        self::assertSame('one_side', $j['barcode']);
        self::assertContains('strength', $j['agree']);
        self::assertContains('flavour', $j['agree']);
    }

    public function testAVgPgRatioSeparatesAndPgVgOptionsAreReadPgFirst(): void
    {
        self::refused(['product_title' => 'Heisenberg 50VG/50PG Shortfill E-Liquid by Vape and Go Jungle Fruits 100ml', 'brand' => 'Vape and Go', 'price' => '6.99'],
            ['product_title' => 'Heisenberg 70VG/30PG Shortfill E-Liquid by Vape and Go Jungle Fruits 100ml', 'brand' => 'Vape and Go', 'price' => '7.99'], ['vgpg']);
        self::refused(['product_title' => 'Mango Ice Shortfill E-Liquid by Doozy 50/50 100ml', 'brand' => 'Doozy', 'price' => '9.99'],
            ['product_title' => 'Mango Ice Shortfill E-Liquid by Doozy 100ml', 'brand' => 'Doozy', 'price' => '9.99',
                'attributes' => [['name' => 'PG/VG', 'value' => '30/70', 'attr_id' => 5, 'is_variable' => 0]]], ['vgpg']);
        self::assertSame(['70'], DuplicateSweep::ratios('30/70', true));
        self::assertSame(['70'], DuplicateSweep::ratios('hayati pro max eliquid 70/30 - banana ice - 100ml'));
        self::assertSame(['70'], DuplicateSweep::ratios('30pg/70vg'));
        self::assertSame(['max_vg'], DuplicateSweep::ratios('dark star nic shot 18mg max vg'));
        self::assertSame([], DuplicateSweep::ratios('elf bar 600 20mg 2ml'));
        // a ratio in one title only refuses; an option row on one page only does not (pages are made with different options)
        self::refused(['product_title' => 'Menthol Shortfill E-liquid by Kingston 100ml', 'brand' => 'Kingston', 'price' => '9.99'],
            ['product_title' => 'Kingston E Liquid 50/50 - Menthol - 100ml', 'brand' => 'Kingston', 'price' => '9.99'], ['vgpg_one_side']);
    }

    public function testASingleLetterOrModelCodeSeparates(): void
    {
        self::refused(['product_title' => 'Voopoo TPP Pod Tank', 'variant_title' => 'Voopoo TPP Pod Tank - Black', 'brand' => 'Voopoo', 'price' => '12.99'],
            ['product_title' => 'Voopoo TPP X Pod Tank', 'variant_title' => 'Voopoo TPP X Pod Tank - Black', 'brand' => 'Voopoo', 'price' => '12.99'], ['words']);
        self::refused(['product_title' => 'Vaporesso Armour G Vape Kit', 'variant_title' => 'Vaporesso Armour G Vape Kit | Blue', 'brand' => 'Vaporesso', 'price' => '39.99'],
            ['product_title' => 'Vaporesso Armour GS Vape Kit', 'variant_title' => 'Vaporesso Armour GS Vape Kit | Blue', 'brand' => 'Vaporesso', 'price' => '39.99'], ['words']);
        self::refused(['product_title' => 'Voopoo Argus G2 Vape Kit', 'variant_title' => 'Voopoo Argus G2 Vape Kit | Pearl White', 'brand' => 'Voopoo', 'price' => '27.99'],
            ['product_title' => 'Voopoo Argus P2 Vape Kit', 'variant_title' => 'Voopoo Argus P2 Vape Kit | Pearl White', 'brand' => 'Voopoo', 'price' => '24.99'], ['model_code']);
        self::assertSame(['f1', 'gt2', 'rpm80'], DuplicateSweep::codes(['smok', 'rpm80', '10ml', '0.6ohm', 'f1', '6k', 'gt2', '70vg', '600']));
    }

    public function testTwoOptionsOfOnePagePairOnlyWithTheSameOptionText(): void
    {
        $page = ['product_id' => 555, 'product_title' => 'HorizonTech Falcon Coils - F1/F2/F3/M1/M2/M-Triple', 'brand' => 'HorizonTech', 'price' => '12.99'];
        self::refused($page + ['variant_title' => 'HorizonTech Falcon Coils - F1/F2/F3/M1/M2/M-Triple - F1 0.2ohm'],
            $page + ['variant_title' => 'HorizonTech Falcon Coils - F1/F2/F3/M1/M2/M-Triple - F2 0.2ohm'], ['option_differs', 'unreadable']);
        $kit = ['product_id' => 556, 'product_title' => 'RandM Fumot T32000 Ultra Prefilled Pod Kit', 'brand' => 'RandM', 'price' => '12.99'];
        $j = self::judge($kit + ['variant_title' => 'Blueberry Cherry Cranberry / Cherry Ice RandM Fumot T32000 Ultra Prefilled Pod Kit'],
            $kit + ['variant_title' => 'Cherry Ice / Blueberry Cherry Cranberry RandM Fumot T32000 Ultra Prefilled Pod Kit']);
        self::assertTrue($j['ok'], json_encode($j['blocks']));
        self::assertTrue($j['same_product']);
        $pod = ['product_id' => 557, 'product_title' => 'Higo Alfa Pro 25K Prefilled Pod', 'brand' => 'Higo', 'price' => '5.99'];
        self::refused($pod + ['variant_title' => 'H Bubble / Strawberry H Bubble Higo Alfa Pro 25K Prefilled Pod'],
            $pod + ['variant_title' => 'Strawberry H Bubble Higo Alfa Pro 25K Prefilled Pod'], ['option_differs']);
    }

    public function testBarcodesBrandAndPriceMustAgree(): void
    {
        $a = self::liquid('Banana Ice') + ['barcodes' => [self::ean('505616843640')]];
        $same = DuplicateSweep::judge(self::f($a), self::f(self::liquid('Banana Ice') + ['barcodes' => [self::ean('505616843640'), self::ean('505616843641')]]));
        self::assertTrue($same['ok'], json_encode($same['blocks']));
        self::assertSame('shared', $same['barcode']);
        self::refused($a, self::liquid('Banana Ice') + ['barcodes' => [self::ean('505616843641')]], ['barcodes_differ']);
        self::refused(self::liquid('Banana Ice'), self::liquid('Banana Ice', '20mg', '10ml', 'Riot Squad', 'Riot Squad'), ['brand', 'words']);
        self::refused(self::liquid('Banana Ice'), ['price' => '7.99'] + self::liquid('Banana Ice'), ['price']);
    }

    public function testAValueOnOnePageOnlyOrAnUnreadableFieldRefuses(): void
    {
        self::refused(self::liquid('Banana Ice'), ['variant_title' => null, 'product_title' => 'Banana Ice Nic Salt E-Liquid by Elfliq 10ml'] + self::liquid('Banana Ice'),
            ['strength_one_side']);
        self::refused(['product_title' => 'Smok Nord X Replacement Pods - 2ml/6ml', 'variant_title' => 'Smok Nord X Replacement Pods - RPM 2 Pod 2ml', 'brand' => 'Smok'],
            ['product_title' => 'Smok Nord X RPM 2 Replacement Pod', 'brand' => 'Smok'], ['unreadable']);
    }

    /**
     * M43, recall: the review of 7 Oct 2026 found these refused by one soft flag only. Corex 2.0 pods on two product pages (the
     * Normalizer read "xros corex" as the flavour of the second page; the first page's "Mesh" is a descriptor): hardware, so
     * flavour_extra on descriptor words does not refuse. IVG 6000 Bar Salts against the umbrella "IVG Nic Salt" page naming the
     * range "(IVG 6000)": "bar" is an umbrella word under the same model number. Without the number it still refuses.
     */
    public function testCorexTwoMeshAndIvgSixThousandAreDuplicates(): void
    {
        $at = static fn (string $n, string $v, int $var = 0): array => ['name' => $n, 'value' => $v, 'is_variable' => $var];
        $a = ['product_id' => 6855, 'product_title' => 'Vaporesso Xros Corex 2.0 Mesh Replacement Pod(Pack of 4)', 'variant_title' => 'Vaporesso Xros Corex 2.0 Mesh Replacement Pod(Pack of 4) - 0.4ohm',
            'brand' => 'Vaporesso Vape Kits & Accessories', 'price' => '10.99', 'barcodes' => ['06943498697557'],
            'attributes' => [$at('Resistance', '0.4ohm', 1), $at('Pack Size', '4 Pack'), $at('Made In', 'China'), $at('Tank Capacity', '2ml')]];
        $b = ['product_id' => 2077, 'product_title' => 'Vaporesso Xros Corex Replacement Pods', 'variant_title' => 'Vaporesso Xros Corex 2.0 Replacement Pods - 0.4ohm | 4 Pack',
            'brand' => 'Vaporesso Vape Kits & Accessories', 'price' => '10.99', 'attributes' => [$at('Type', '0.4ohm Corex 2.0 Pod - 4 Pack', 1), $at('Coil Resistance', '0.4ohm'),
                $at('Coil Resistance', '0.6ohm'), $at('Coil Resistance', '0.8ohm'), $at('Coil Resistance', '1.0 ohm'), $at('Coil Resistance', '1.2ohm'), $at('Tank Capacity', '2ml')]];
        $j = self::judge($a, $b);
        self::assertTrue($j['ok'], json_encode($j['blocks']));
        $b6 = $b;
        $b6['variant_title'] = 'Vaporesso Xros Corex 2.0 Replacement Pods - 0.6ohm | 4 Pack';
        $b6['attributes'][0] = $at('Type', '0.6ohm Corex 2.0 Pod - 4 Pack', 1);
        self::refused($a, $b6, ['veto_ohm']);

        $ivg = ['product_title' => 'Bubblegum Berry Wave Nic Salt E-Liquid by IVG 6000 Bar Salts 10ml', 'variant_title' => 'Bubblegum Berry Wave Nic Salt E-Liquid by IVG 6000 Bar Salts 10ml - 10mg',
            'brand' => 'IVG Nic Salts', 'price' => '2.99', 'attributes' => [$at('Nicotine Strength', '10mg', 1), $at('Bottle Size', '10ml'), $at('Nicotine Strength', '20mg')]];
        $range = ['product_title' => 'IVG Nic Salt 10ml', 'variant_title' => 'IVG Nic Salt 10ml - 10mg | Bubblegum Berry Wave (IVG 6000)', 'brand' => 'IVG', 'price' => '2.99',
            'barcodes' => ['5056617564386'], 'attributes' => [$at('Nicotine Strength', '10mg', 1), $at('Flavour', 'Bubblegum Berry Wave (IVG 6000)', 1)]];
        $j = self::judge($ivg, $range);
        self::assertTrue($j['ok'], json_encode($j['blocks']));
        $noNumber = ['product_title' => str_replace('6000 ', '', $ivg['product_title']), 'variant_title' => str_replace('6000 ', '', $ivg['variant_title'])] + $ivg;
        self::refused($noNumber, $range, ['flag_modifier_extra', 'flag_line_number_one_side']);
    }

    /**
     * M43, precision: with the barcodes taken away, "Riot Squad" vs "Riot Squad Black Edition" and "Bar Salts" vs "Bar Vape" (Vape
     * and Go gives each its own EAN: different products) must still be refused, by the word "edition" and by the brand text.
     */
    public function testRiotSquadBlackEditionAndBarVapeAreNotDuplicatesWithoutBarcodes(): void
    {
        $at = static fn (string $n, string $v, int $var = 0): array => ['name' => $n, 'value' => $v, 'is_variable' => $var];
        self::refused(['product_title' => 'Rich Black Grape Nic Salt E-liquid by Riot Squad 10ml', 'variant_title' => 'Rich Black Grape Nic Salt E-liquid by Riot Squad 10ml - 10mg',
            'brand' => 'Riot Squad', 'price' => '2.99', 'attributes' => [$at('Nicotine Strength', '10mg', 1), $at('Bottle Size', '10ml'), $at('Nicotine Strength', '20mg')]],
            ['product_title' => 'Rich Black Grape Nic Salt E-Liquid by Riot Squad Black Edition 10ml', 'variant_title' => 'Rich Black Grape Nic Salt E-Liquid by Riot Squad Black Edition 10ml - 10mg',
                'brand' => 'Riot Squad', 'price' => '2.99', 'attributes' => [$at('Nicotine Strength', '10mg', 1), $at('PG/VG', '50/50'), $at('Bottle Size', '10ml')]], ['words']);
        self::refused(['product_title' => 'Blue Razz Lemonade Nic Salt E-Liquid by Bar Salts 10ml', 'variant_title' => 'Blue Razz Lemonade Nic Salt E-Liquid by Bar Salts 10ml - 10mg',
            'brand' => 'Bar Salts', 'price' => '2.99', 'attributes' => [$at('Nicotine Strength', '10mg', 1), $at('PG/VG', '50/50'), $at('Bottle Size', '10ml')]],
            ['product_title' => 'Blue Razz Lemonade Nic Salt E-Liquid by Bar Vape 10ml', 'variant_title' => 'Blue Razz Lemonade Nic Salt E-Liquid by Bar Vape 10ml | 10mg',
                'brand' => 'Bar Vape Salts', 'price' => '2.49', 'attributes' => [$at('Nicotine Strength', '10mg', 1), $at('Bottle Size', '10ml'), $at('PG/VG', '50/50')]], ['brand_text']);
        self::refused(self::liquid('Berry', '20mg', '10ml', 'SKE', 'Crystal'), self::liquid('Berry Edition', '20mg', '10ml', 'SKE', 'Crystal'), ['words', 'veto_flavour_superset', 'flag_flavour_extra']);
    }

    /** M43: a ratio the title states wins over the "PG/VG" option row, which Vape and Go does not always write PG first. */
    public function testATitleRatioWinsOverThePgVgOptionRow(): void
    {
        $f = self::f(['product_title' => 'Bubblegum 70/30 Shortfill E-Liquid by IVG 100ml', 'brand' => 'IVG', 'price' => '10.99',
            'attributes' => [['name' => 'PG/VG', 'value' => '70/30', 'is_variable' => 0], ['name' => 'E-Liquid Capacity', 'value' => '100ml', 'is_variable' => 0]]]);
        self::assertSame('70', $f['vgpg']);
        self::assertNotContains('vgpg', $f['conflict_fields'] ?? []);
        self::assertSame('title', $f['src']['vgpg']);
        $g = self::f(['product_title' => 'Mango Shortfill E-Liquid by Kingston 100ml', 'brand' => 'Kingston', 'price' => '9.99',
            'attributes' => [['name' => 'PG/VG', 'value' => '30/70', 'is_variable' => 0]]]);
        self::assertSame(['70', 'attr'], [$g['vgpg'], $g['src']['vgpg']], 'no ratio in the title: the option row, PG first');
    }
}
