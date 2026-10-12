<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Matching\ArrivalRules;
use CW\Matching\Normalizer;
use CW\Mapping\Proposals;
use PHPUnit\Framework\TestCase;

/**
 * The rules of the check on arrival, build 1 (docs/decisions.md M65; ArrivalRules' own table): one case for every line of the
 * table, the order between the lines, and what the check can never say. The item's and the variant's features are the
 * Normalizer's own, so a case fails when the engine's rules (Veto) stop saying what the table relies on.
 */
final class ArrivalRulesTest extends TestCase
{
    private const ITEM = 71;
    private const OTHER = 72;

    /** @return array<string, mixed> */
    private static function features(string $title, string $option, string $brand = 'Bar Juice 5000'): array
    {
        return Normalizer::normalize(['site' => 'x', 'variant_id' => 1, 'product_title' => $title, 'variant_title' => $option, 'brand' => $brand, 'attributes' => [],
            'sale_price' => null]);
    }

    /** A variant and an item that are the same thing, with nothing against it. @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function same(): array
    {
        $f = self::features('Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid', '10mg');
        return [$f, $f];
    }

    /**
     * @param array<string, mixed> $barcode
     * @param list<array{listing_id: int, sku_id: ?int, units?: int}> $address
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function facts(array $barcode = [], array $address = [], array $items = [], ?array $variant = null, bool $mainStore = false): array
    {
        [$v, $i] = self::same();
        return ['main_store' => $mainStore, 'variant' => $variant ?? $v, 'barcode' => $barcode === [] ? ['keys' => []] : $barcode + ['keys' => ['5060769492206']],
            'address' => ['key' => $address === [] ? '' : 'bar-juice-5000-blue-razz/10mg', 'variants' => $address],
            'items' => $items + [self::ITEM => ['features' => $i, 'taken' => false], self::OTHER => ['features' => $i, 'taken' => false]]];
    }

    /** @param array<string, mixed> $a */
    private static function says(array $a): array
    {
        return [$a['band'], $a['lane'], $a['sku_id'], $a['closest_sku_id'], $a['reasons'][0] ?? $a['outcome'], $a['warned']];
    }

    public function testABarcodeOnExactlyOneItemIsALikelyMatchFlaggedRulesOnly(): void
    {
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]]]));
        self::assertSame(['Check', 'barcode', self::ITEM, null, 'arrival_barcode', false], self::says($a));
        self::assertSame(['rules_only'], $a['flags'], 'a clean match carries the one flag, and no warning');
        self::assertSame(['arrival_barcode', true, self::ITEM, 'barcode'], [$a['outcome'], $a['suggest'], $a['target'], $a['via']]);
    }

    public function testAnAddressThatIsExactlyOneItemsMainStoreVariantIsALikelyMatch(): void
    {
        $a = ArrivalRules::decide(self::facts([], [['listing_id' => 9, 'sku_id' => self::ITEM]]));
        self::assertSame(['Check', 'transfer', self::ITEM, null, 'arrival_copy', false], self::says($a));
        self::assertSame(['rules_only'], $a['flags']);
        self::assertSame('address', $a['via']);
    }

    public function testBothCluesNamingTheSameItemIsStillOnlyLikely(): void
    {
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]], 'variants' => [9]], [['listing_id' => 9, 'sku_id' => self::ITEM]]));
        self::assertSame(['Check', 'barcode', self::ITEM, null, 'arrival_barcode_and_copy', false], self::says($a));
        self::assertSame(['rules_only', 'transfer_agrees'], $a['flags'], 'two clues agreeing is no warning, and no Strong either: no second check looked');
        self::assertSame(['transfer_agrees'], $a['lane_flags']);
    }

    public function testASoftFlagMakesItAWarnedMatch(): void
    {
        // The variant states no strength and the item has one: "strength not stated" always fires here (stricter than the batch run).
        $variant = self::features('Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid', '');
        $item = self::features('Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid', '20mg');
        foreach ([[['items' => [self::ITEM => ['units_per_scan' => 1]]], [], 'barcode', 'arrival_barcode'],
            [[], [['listing_id' => 9, 'sku_id' => self::ITEM]], 'transfer', 'arrival_copy']] as [$barcode, $address, $lane, $reason]) {
            $a = ArrivalRules::decide(self::facts($barcode, $address, [self::ITEM => ['features' => $item, 'taken' => false]], $variant));
            self::assertSame(['Check', $lane, self::ITEM, null, $reason, true], self::says($a));
            self::assertSame(['arrival_warned', 'rules_only', 'strength_missing'], $a['flags']);
            self::assertSame(['strength_missing'], $a['target_soft_flags']);
        }
    }

    public function testASharedAddressSettledByTheOneVariantWithAnItemIsAWarnedMatch(): void
    {
        $a = ArrivalRules::decide(self::facts([], [['listing_id' => 9, 'sku_id' => self::ITEM], ['listing_id' => 10, 'sku_id' => null]]));
        self::assertSame(['Check', 'transfer', self::ITEM, null, 'arrival_copy', true], self::says($a));
        self::assertSame(['arrival_warned', 'rules_only', 'transfer_resolved_by_seed'], $a['flags']);
        // The barcode is also on a main-store variant that has no item (an old page of the main store).
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]], 'main_no_item' => 1, 'variants' => [11]]));
        self::assertSame(['Check', 'barcode', self::ITEM, null, 'arrival_barcode', true], self::says($a));
        self::assertContains('gtin_also_on_inactive_item', $a['flags']);
        // The address is on several items and the barcode says which.
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]]],
            [['listing_id' => 9, 'sku_id' => self::ITEM], ['listing_id' => 10, 'sku_id' => self::OTHER]]));
        self::assertSame(['Check', 'barcode', self::ITEM, null, 'arrival_barcode', true], self::says($a));
        // A sibling on a third store with the same barcode and no item yet is no warning.
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]], 'other_no_item' => 2]));
        self::assertSame(['Check', 'barcode', self::ITEM, null, 'arrival_barcode', false], self::says($a));
    }

    public function testAnItemWithNothingToCompareItWithIsAWarnedMatch(): void
    {
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]]], [], [self::ITEM => ['features' => null, 'taken' => false]]));
        self::assertSame(['Check', 'barcode', self::ITEM, null, 'arrival_barcode', true], self::says($a));
        self::assertSame(['arrival_barcode', 'arrival_not_compared'], $a['reasons']);
        $facts = self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]]]);
        $facts['variant'] = null; // the variant itself could not be read
        self::assertTrue(ArrivalRules::decide($facts)['warned']);
    }

    public function testARenamedRangeIsNotSureAndNamesTheClosestItemOnly(): void
    {
        $variant = self::features('Crystal Pro Max 4000 Blue Razz Lemonade Disposable', '20mg', 'Crystal');
        $item = self::features('Hayati Pro Max 4000 Blue Razz Lemonade Disposable', '20mg', 'Hayati');
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]]], [], [self::ITEM => ['features' => $item, 'taken' => false]], $variant));
        self::assertSame(["Can't tell", 'barcode', null, self::ITEM, 'arrival_renamed_range', true], self::says($a));
        self::assertContains('line_alias_pending', $a['flags']);
        self::assertContains('arrival_warned', $a['flags']);
    }

    public function testAHardVetoOnTheItemIsAConflictThatNamesItAsClosestOnly(): void
    {
        $variant = self::features('Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid', '10mg');
        $item = self::features('Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid', '20mg');
        foreach ([[['items' => [self::ITEM => ['units_per_scan' => 1]]], [], 'barcode'], [[], [['listing_id' => 9, 'sku_id' => self::ITEM]], 'transfer']] as [$barcode, $address, $lane]) {
            $a = ArrivalRules::decide(self::facts($barcode, $address, [self::ITEM => ['features' => $item, 'taken' => false]], $variant));
            self::assertSame(['Conflict', $lane, null, self::ITEM, 'veto_on_key', true], self::says($a));
            self::assertSame(['strength'], $a['target_vetoes'], 'the codes, as texts');
            self::assertSame(['arrival_warned', 'rules_only', 'strength'], $a['flags']);
            self::assertSame('veto_on_key', $a['outcome']);
        }
    }

    public function testTheCluesPointingAtDifferentThingsIsAConflictWithNoItem(): void
    {
        // Two items.
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]]], [['listing_id' => 9, 'sku_id' => self::OTHER]]));
        self::assertSame(['Conflict', 'barcode', null, null, 'arrival_clues_differ', true], self::says($a));
        self::assertSame(['arrival_warned', 'barcode_transfer_disagree', 'rules_only'], $a['flags']);
        // The barcode names an item; the address is a main-store variant with no item that does not carry the barcode.
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]]], [['listing_id' => 9, 'sku_id' => null]]));
        self::assertSame('arrival_clues_differ', $a['outcome']);
        // The address names an item; the barcode is on another main-store variant, which has no item.
        $a = ArrivalRules::decide(self::facts(['main_no_item' => 1, 'variants' => [12]], [['listing_id' => 9, 'sku_id' => self::ITEM]]));
        self::assertSame('arrival_clues_differ', $a['outcome']);
        // The same variant carries both: no disagreement (the address's variant has no item, the barcode's item is offered, warned).
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]], 'variants' => [9]], [['listing_id' => 9, 'sku_id' => null]]));
        self::assertSame(['Check', 'barcode', self::ITEM, null, 'arrival_barcode', true], self::says($a));
    }

    public function testABarcodeOnTwoItemsOrOnARecordMarkedNotUsableIsAConflictWithNoItem(): void
    {
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1], self::OTHER => ['units_per_scan' => 1]]]));
        self::assertSame(['Conflict', 'barcode', null, null, 'arrival_barcode_two_items', true], self::says($a));
        self::assertSame(['arrival_warned', 'gtin_on_multiple_items', 'rules_only'], $a['flags']);
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]], 'not_usable' => true]));
        self::assertSame('arrival_barcode_two_items', $a['outcome']);
        self::assertNull($a['sku_id'] ?? $a['closest_sku_id']);
        // ... whatever the address says.
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1], self::OTHER => ['units_per_scan' => 1]]], [['listing_id' => 9, 'sku_id' => self::ITEM]]));
        self::assertSame('arrival_barcode_two_items', $a['outcome']);
    }

    public function testABarcodeOnAnotherVariantOfTheSameStoreIsAConflictWithNoItem(): void
    {
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]], 'same_store' => 1]));
        self::assertSame(['Conflict', 'barcode', null, null, 'arrival_barcode_same_store', true], self::says($a));
        self::assertSame(['arrival_warned', 'gtin_dup_in_channel', 'rules_only'], $a['flags']);
        // On the main store too: the address rule is not applied to it, the barcode rules are.
        $a = ArrivalRules::decide(self::facts(['same_store' => 1], [], [], null, true));
        self::assertSame('arrival_barcode_same_store', $a['outcome']);
    }

    public function testAnItemAnotherVariantOfTheStoreAlreadyHasIsNotSure(): void
    {
        [$v, $i] = self::same();
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]]], [], [self::ITEM => ['features' => $i, 'taken' => true]]));
        self::assertSame(["Can't tell", 'barcode', null, self::ITEM, 'arrival_item_taken', true], self::says($a));
        self::assertSame(['arrival_warned', 'rules_only', 'same_channel_target_shared'], $a['flags']);
        $a = ArrivalRules::decide(self::facts([], [['listing_id' => 9, 'sku_id' => self::ITEM]], [self::ITEM => ['features' => $i, 'taken' => true]]));
        self::assertSame(["Can't tell", 'transfer', null, self::ITEM, 'arrival_item_taken', true], self::says($a));
    }

    public function testABarcodeThatStandsForMoreThanOneUnitIsNotSure(): void
    {
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 10]]]));
        self::assertSame(["Can't tell", 'barcode', null, self::ITEM, 'arrival_barcode_pack', true], self::says($a));
        // An address match does not read the barcode's units.
        $a = ArrivalRules::decide(self::facts([], [['listing_id' => 9, 'sku_id' => self::ITEM]]));
        self::assertSame('arrival_copy', $a['outcome']);
    }

    public function testAnAddressCopiedFromAVariantMatchedAsAPackIsNotSure(): void
    {
        // The main-store variant with the address is matched to the item with ten units a sale: a copy of that page is no
        // one-unit match (a suggestion carries no units, so a confirm would link one).
        $a = ArrivalRules::decide(self::facts([], [['listing_id' => 9, 'sku_id' => self::ITEM, 'units' => 10]]));
        self::assertSame(["Can't tell", 'transfer', null, self::ITEM, 'arrival_copy_pack', true], self::says($a));
        // The same when the barcode names the item too: the address still says "a pack".
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]], 'variants' => [9]], [['listing_id' => 9, 'sku_id' => self::ITEM, 'units' => 10]]));
        self::assertSame(["Can't tell", 'barcode', null, self::ITEM, 'arrival_copy_pack', true], self::says($a));
        // One unit a sale, said or not said: a match, as before.
        foreach ([['listing_id' => 9, 'sku_id' => self::ITEM, 'units' => 1], ['listing_id' => 9, 'sku_id' => self::ITEM]] as $v) {
            self::assertSame(['Check', 'arrival_copy'], [ArrivalRules::decide(self::facts([], [$v]))['band'], ArrivalRules::decide(self::facts([], [$v]))['outcome']]);
        }
        self::assertContains('arrival_copy_pack', ArrivalRules::REASONS);
    }

    public function testACluePointingAtNoItemIsNotSureWithNoItemAndNeverNew(): void
    {
        foreach ([[['main_no_item' => 1, 'variants' => [12]], [], 'barcode', 'arrival_barcode_no_item'],
            [['other_no_item' => 1], [], 'barcode', 'arrival_barcode_no_item'],
            [[], [['listing_id' => 9, 'sku_id' => null]], 'transfer', 'arrival_copy_no_item'],
            [[], [['listing_id' => 9, 'sku_id' => self::ITEM], ['listing_id' => 10, 'sku_id' => self::OTHER]], 'transfer', 'arrival_copy_several']] as [$barcode, $address, $lane, $reason]) {
            $a = ArrivalRules::decide(self::facts($barcode, $address));
            self::assertSame(["Can't tell", $lane, null, null, $reason, true], self::says($a), $reason);
            self::assertContains('arrival_warned', $a['flags']);
        }
    }

    public function testNoClueIsNoSuggestion(): void
    {
        foreach ([self::facts(), self::facts(['keys' => []]), self::facts(['keys' => ['5060769492206'], 'items' => []]),
            // a main-store variant's own address is no clue
            self::facts([], [['listing_id' => 9, 'sku_id' => self::ITEM]], [], null, true)] as $facts) {
            $a = ArrivalRules::decide($facts);
            self::assertSame(['no_clue', false, null, null, null, []], [$a['outcome'], $a['suggest'], $a['band'], $a['sku_id'], $a['closest_sku_id'], $a['flags']]);
        }
        // Facts about barcodes it does not have are not read.
        $facts = self::facts();
        $facts['barcode'] = ['keys' => [], 'items' => [self::ITEM => ['units_per_scan' => 1]], 'same_store' => 3];
        self::assertSame('no_clue', ArrivalRules::decide($facts)['outcome']);
    }

    public function testAConflictComesBeforeANotSureAndThatBeforeAMatch(): void
    {
        $strong = self::features('Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid', '20mg');
        // a veto, a pack barcode and an item already taken at once: the veto is what is said
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 10]]], [], [self::ITEM => ['features' => $strong, 'taken' => true]]));
        self::assertSame('veto_on_key', $a['outcome']);
        [$v, $i] = self::same();
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 10]]], [], [self::ITEM => ['features' => $i, 'taken' => true]]));
        self::assertSame('arrival_barcode_pack', $a['outcome']);
        // a same-store barcode beats everything about the item
        $a = ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 1]], 'same_store' => 1], [['listing_id' => 9, 'sku_id' => self::OTHER]]));
        self::assertSame('arrival_barcode_same_store', $a['outcome']);
    }

    /** Every answer the table can give, in one list: the strengths, the flags and the reasons it may use. */
    public function testItNeverSaysStrongRenamedRangeOrNewAndEverySuggestionSaysRulesOnly(): void
    {
        $veto = self::features('Bar Juice 5000 Blue Razz 10ml Nic Salt E-Liquid', '20mg');
        [$v, $i] = self::same();
        $one = ['items' => [self::ITEM => ['units_per_scan' => 1]]];
        $answers = [
            ArrivalRules::decide(self::facts($one)),
            ArrivalRules::decide(self::facts([], [['listing_id' => 9, 'sku_id' => self::ITEM]])),
            ArrivalRules::decide(self::facts($one + ['variants' => [9]], [['listing_id' => 9, 'sku_id' => self::ITEM]])),
            ArrivalRules::decide(self::facts($one, [], [self::ITEM => ['features' => null]])),
            ArrivalRules::decide(self::facts($one, [], [self::ITEM => ['features' => $veto]])),
            ArrivalRules::decide(self::facts($one, [['listing_id' => 9, 'sku_id' => self::OTHER]])),
            ArrivalRules::decide(self::facts($one + ['not_usable' => true])),
            ArrivalRules::decide(self::facts($one + ['same_store' => 1])),
            ArrivalRules::decide(self::facts($one, [], [self::ITEM => ['features' => $i, 'taken' => true]])),
            ArrivalRules::decide(self::facts(['items' => [self::ITEM => ['units_per_scan' => 6]]])),
            ArrivalRules::decide(self::facts(['other_no_item' => 1])),
            ArrivalRules::decide(self::facts([], [['listing_id' => 9, 'sku_id' => null]])),
            ArrivalRules::noLonger('transfer'),
        ];
        $bands = [];
        foreach ($answers as $a) {
            self::assertTrue($a['suggest']);
            self::assertContains($a['band'], ArrivalRules::BANDS);
            self::assertNotContains($a['band'], ['Key', 'Manual', 'New item']);
            self::assertContains($a['band'], Proposals::BANDS, 'a strength the suggestions table holds');
            self::assertContains('rules_only', $a['flags']);
            self::assertSame($a['warned'], in_array('arrival_warned', $a['flags'], true));
            self::assertSame($a['band'] === 'Check' && $a['sku_id'] !== null && !$a['warned'], $a['flags'] === ['rules_only'] || $a['flags'] === ['rules_only', 'transfer_agrees']);
            self::assertContains($a['lane'], ['barcode', 'transfer'], 'a lane the lists filter by');
            self::assertSame($a['outcome'], $a['reasons'][0]);
            self::assertLessThanOrEqual(32, strlen($a['outcome']), 'the outcome fits its column');
            foreach ($a['reasons'] as $r) {
                self::assertContains($r, ArrivalRules::REASONS);
            }
            // An item is offered only by a match; everything else names at most the closest one.
            self::assertSame($a['band'] === 'Check', $a['sku_id'] !== null);
            self::assertTrue($a['sku_id'] === null || $a['closest_sku_id'] === null);
            $bands[$a['band']] = true;
        }
        self::assertSame(['Check', "Can't tell", 'Conflict'], ArrivalRules::BANDS);
        self::assertCount(3, $bands, 'every strength it can say was said');
        self::assertSame(["Can't tell", 'transfer', null, null, 'arrival_no_longer', true], self::says(ArrivalRules::noLonger('transfer')));
        self::assertSame('barcode', ArrivalRules::noLonger(null)['lane']);
        self::assertSame('ar1.0', ArrivalRules::VERSION);
    }

    public function testTheSameAnswerIsToldFromADifferentOne(): void
    {
        $one = ['items' => [self::ITEM => ['units_per_scan' => 1]]];
        $a = ArrivalRules::decide(self::facts($one));
        self::assertTrue(ArrivalRules::same($a, ArrivalRules::decide(self::facts($one))));
        self::assertFalse(ArrivalRules::same($a, ArrivalRules::decide(self::facts(['items' => [self::OTHER => ['units_per_scan' => 1]]]))), 'another item');
        self::assertFalse(ArrivalRules::same($a, ArrivalRules::decide(self::facts($one + ['main_no_item' => 1]))), 'a warning more');
        self::assertFalse(ArrivalRules::same($a, ArrivalRules::decide(self::facts([], [['listing_id' => 9, 'sku_id' => self::ITEM]]))), 'another clue');
    }

    /** permaKey() is tools/first_match/run.php's: the tool's own function body is run on the same addresses. */
    public function testTheAddressKeyIsTheBatchRunsOwn(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/first_match/run.php');
        self::assertSame(1, preg_match('/^function permaKey\(\?string \$p\): string\s*\{\s*(return [^;{}]+;)\s*\}/m', $source, $m), 'the batch run still has permaKey()');
        /** @var \Closure(?string): string $theirs */
        $theirs = eval('return static function (?string $p): string { ' . $m[1] . ' };');
        $addresses = [null, '', ' ', '/', '//', 'bar-juice-5000-blue-bubba-10ml-nic-salt-e-liquid/5mg', 'bar-juice-5000-blue-bubba-10ml-nic-salt-e-liquid/5mg/',
            ' Bar-Juice-5000/5MG// ', "page\t", "\n page/sub/ \n", 'page//sub', '/page/', 'PAGE', 'pagé/', 'ÉLITE/', 'a/ /', '0', 'https://example.test/A/B/?x=1', 'a b/'];
        foreach ($addresses as $link) {
            self::assertSame($theirs($link), ArrivalRules::permaKey($link), var_export($link, true));
        }
        self::assertSame('bar-juice-5000/5mg', ArrivalRules::permaKey(' Bar-Juice-5000/5MG// '));
        self::assertSame('', ArrivalRules::permaKey(' / '));
    }
}
