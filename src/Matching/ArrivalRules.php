<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * The rules of the check on arrival, build 1 (docs/decisions.md M65): what a barcode and a copied web address say about a variant
 * nobody has looked at. Pure: no database, no clock, no state. CW\Mapping\ArrivalCheck reads the facts and writes the answer.
 *
 * Two clues, and nothing else:
 *  - the BARCODE: the variant's usable barcodes (the strict cleaner of the barcode sync, Catalogue\BarcodeSync::keys) are on an
 *    item, through the item's own barcode records or through a variant that is matched to it;
 *  - the ADDRESS: its web address equals a main-store variant's (the shops' transfer tool copies a main-store page to another
 *    store and keeps its address; permaKey() is tools/first_match/run.php's). The address rule compares WITH the main store and
 *    is never applied TO a variant of the main store.
 * An item the clues name is then compared with the variant by the matching engine's own rules (Veto::check, both sides normalised
 * afresh), with "strength not stated" always on (`s_strength_sibling`): stricter than the batch run, on purpose.
 *
 * What it answers, the first that applies (a Conflict before a "not sure", a "not sure" before a match):
 *
 *   | the facts                                                                       | strength    | item         | reason                       |
 *   |---------------------------------------------------------------------------------|-------------|--------------|------------------------------|
 *   | the barcode is on two or more items, or on a barcode record marked not usable   | Conflict    | none         | arrival_barcode_two_items    |
 *   | the barcode is on another variant of the same store                             | Conflict    | none         | arrival_barcode_same_store   |
 *   | the barcode and the address point at different things                           | Conflict    | none         | arrival_clues_differ         |
 *   | an item is named, and a rule says it cannot be that item (a hard veto)          | Conflict    | closest only | veto_on_key                  |
 *   | an item is named by a barcode that stands for more than one unit (a case)       | Can't tell  | closest only | arrival_barcode_pack         |
 *   | an item is named by an address whose main-store variant sells a pack of it      | Can't tell  | closest only | arrival_copy_pack            |
 *   | an item is named, and another variant of the same store has or is offered it    | Can't tell  | closest only | arrival_item_taken           |
 *   | an item is named, and only a renamed range stands between the two               | Can't tell  | closest only | arrival_renamed_range        |
 *   | the barcode is only on variants that have no item                               | Can't tell  | none         | arrival_barcode_no_item      |
 *   | the address is on several items                                                 | Can't tell  | none         | arrival_copy_several         |
 *   | the address is only on main-store variants that have no item                    | Can't tell  | none         | arrival_copy_no_item         |
 *   | an item is named with a small warning (a soft flag, a shared address settled by |             |              |                              |
 *   |   the one variant that has an item, nothing to compare the item with)           | Check       | the item     | the clue's, + arrival_warned |
 *   | barcode and address name the same item, nothing against it                      | Check       | the item     | arrival_barcode_and_copy     |
 *   | the barcode is on exactly one item, nothing against it                          | Check       | the item     | arrival_barcode              |
 *   | the address is exactly one item's main-store variant, nothing against it        | Check       | the item     | arrival_copy                 |
 *   | no barcode on anything and no copied address                                    | no suggestion              | outcome no_clue              |
 *
 * It never answers Key (Strong), Manual or New item: BANDS is everything it can say. A clean match is Check (Likely) and carries
 * the flag `rules_only`; everything else it writes also carries `arrival_warned` and is decided one at a time for ever. Whether a
 * variant is looked at at all (its history, a hold, a spot check: REFUSALS) is ArrivalCheck's, not a rule here.
 */
final class ArrivalRules
{
    /** ar1.0: barcode and copied address only. The name search, "New" and the website's state are build 2. */
    public const VERSION = 'ar1.0';

    public const CHECK = Band::CHECK;
    public const CANT_TELL = Band::CANT_TELL;
    public const CONFLICT = Band::CONFLICT;
    /** Every strength the check can write. Never Key, Manual or New item. */
    public const BANDS = [self::CHECK, self::CANT_TELL, self::CONFLICT];

    public const LANE_BARCODE = 'barcode';
    public const LANE_COPY = 'transfer';

    /** On every suggestion the check writes: no AI has looked at it. */
    public const RULES_ONLY = 'rules_only';
    /** On every suggestion that is not a clean match: a detail needs a look, so it is decided on its own page, whatever the setting. */
    public const WARNED = 'arrival_warned';

    /** The variant has no clue: nothing is suggested. */
    public const NO_CLUE = 'no_clue';
    /** Why a variant is not looked at (ArrivalCheck; design section 3). Nothing is suggested. */
    public const REFUSALS = ['has_history', 'not_open', 'held', 'in_spot_check', 'no_title'];
    /** Its own earlier suggestion has no clue behind it any more: replaced by a "not sure" that says so (ArrivalCheck). */
    public const NO_LONGER = 'arrival_no_longer';

    /** The reasons of a suggestion (keys of Ui\Words::BAND_REASON, each exact). */
    public const REASONS = ['arrival_barcode', 'arrival_copy', 'arrival_barcode_and_copy', 'arrival_not_compared', 'arrival_renamed_range', 'veto_on_key',
        'arrival_clues_differ', 'arrival_barcode_two_items', 'arrival_barcode_same_store', 'arrival_item_taken', 'arrival_barcode_pack', 'arrival_copy_pack',
        'arrival_barcode_no_item', 'arrival_copy_no_item', 'arrival_copy_several', self::NO_LONGER];

    /**
     * The web address as two stores' copies of one page share it: surrounding white space gone, lower case, no closing slash.
     * tools/first_match/run.php permaKey(), character for character ('' = no address).
     */
    public static function permaKey(?string $link): string
    {
        return rtrim(strtolower(trim((string) $link)), '/');
    }

    /**
     * What the clues say.
     *
     * @param array{
     *     main_store?: bool,
     *     variant?: ?array<string, mixed>,
     *     barcode?: array{keys?: list<string>, items?: array<int, array{units_per_scan?: int}>, not_usable?: bool, same_store?: int,
     *         main_no_item?: int, other_no_item?: int, variants?: list<int>},
     *     address?: array{key?: string, variants?: list<array{listing_id: int, sku_id: ?int, units?: int}>},
     *     items?: array<int, array{features?: ?array<string, mixed>, taken?: bool}>
     * } $facts
     *   main_store: the variant is on the main store (the address rule is not applied to it);
     *   variant: its features (Normalizer::normalize of its profile; null: it could not be read);
     *   barcode.keys: its usable barcodes; .items: the items they are on (each followed to the item it was joined into), with the
     *     most units one scan of that barcode stands for; .not_usable: a barcode record of one of them is marked not usable;
     *     .same_store: how many other variants of its own store carry one; .main_no_item / .other_no_item: how many variants of
     *     the main store / of a third store carry one and have no item; .variants: the main-store variants that carry one;
     *   address.key: permaKey() of its address; .variants: the main-store variants with that address, each with its item or null
     *     and the units of the item one sale of it uses (1 when not said);
     *   items: for every item named above, its representative variant's features (null: there is none to compare with) and
     *     whether another variant of this variant's store is matched to it or has it suggested.
     * @return array{outcome: string, suggest: bool, band: ?string, lane: ?string, sku_id: ?int, closest_sku_id: ?int, target: ?int,
     *     reasons: list<string>, flags: list<string>, lane_flags: list<string>, target_vetoes: list<string>, target_soft_flags: list<string>,
     *     warned: bool, via: ?string}
     */
    public static function decide(array $facts): array
    {
        $b = $facts['barcode'] ?? [];
        $keys = array_values($b['keys'] ?? []);
        $bItems = $keys === [] ? [] : array_map('intval', array_keys($b['items'] ?? []));
        $notUsable = $keys !== [] && !empty($b['not_usable']);
        $sameStore = $keys === [] ? 0 : (int) ($b['same_store'] ?? 0);
        $mainNoItem = $keys === [] ? 0 : (int) ($b['main_no_item'] ?? 0);
        $otherNoItem = $keys === [] ? 0 : (int) ($b['other_no_item'] ?? 0);
        $bVariants = $keys === [] ? [] : array_map('intval', $b['variants'] ?? []);
        $hasBarcode = $bItems !== [] || $notUsable || $sameStore > 0 || $mainNoItem > 0 || $otherNoItem > 0;

        $a = $facts['address'] ?? [];
        $aVariants = !empty($facts['main_store']) || ($a['key'] ?? '') === '' ? [] : array_values($a['variants'] ?? []);
        $aItems = [];
        $aUnits = []; // item => the most units a sale uses, of the address's variants matched to it
        $overlap = false;
        foreach ($aVariants as $v) {
            if ($v['sku_id'] !== null) {
                $aItems[(int) $v['sku_id']] = true;
                $aUnits[(int) $v['sku_id']] = max($aUnits[(int) $v['sku_id']] ?? 1, (int) ($v['units'] ?? 1));
            }
            $overlap = $overlap || in_array((int) $v['listing_id'], $bVariants, true);
        }
        $aItems = array_keys($aItems);
        $hasAddress = $aVariants !== [];

        if (!$hasBarcode && !$hasAddress) {
            return self::none(self::NO_CLUE);
        }
        // The barcode itself is in doubt: no item is named.
        if (count($bItems) >= 2 || $notUsable) {
            return self::answer(self::CONFLICT, self::LANE_BARCODE, null, null, ['arrival_barcode_two_items'],
                ['gtin_on_multiple_items', ...($sameStore > 0 ? ['gtin_dup_in_channel'] : [])]);
        }
        if ($sameStore > 0) {
            return self::answer(self::CONFLICT, self::LANE_BARCODE, null, null, ['arrival_barcode_same_store'], ['gtin_dup_in_channel']);
        }
        $bItem = count($bItems) === 1 ? $bItems[0] : null;
        // The two clues point at different things (as the batch run tells it: its flag barcode_transfer_disagree).
        $differ = $hasAddress && (
            ($bItem !== null && $aItems !== [] && !in_array($bItem, $aItems, true))
            || ($bItem !== null && $aItems === [] && !$overlap)
            || ($bItem === null && $mainNoItem > 0 && count($aItems) === 1 && !$overlap)
        );
        if ($differ) {
            return self::answer(self::CONFLICT, self::LANE_BARCODE, null, null, ['arrival_clues_differ'], ['barcode_transfer_disagree']);
        }

        // The item the clues name, if they name one.
        $laneFlags = [];
        if ($bItem !== null) {
            $target = $bItem;
            $lane = self::LANE_BARCODE;
            $reason = 'arrival_barcode';
            if ($aItems === [$bItem]) {
                $reason = 'arrival_barcode_and_copy';
                $laneFlags[] = 'transfer_agrees';
            } elseif (count($aItems) > 1) {
                $laneFlags[] = 'transfer_ambiguous';       // the address is on several items; the barcode says which
            } elseif ($hasAddress) {
                $laneFlags[] = 'transfer_target_not_in_seed'; // the address is a main-store variant with no item, which carries the barcode too
            }
            if ($mainNoItem > 0) {
                $laneFlags[] = 'gtin_also_on_inactive_item';
            }
        } elseif (count($aItems) === 1) {
            $target = $aItems[0];
            $lane = self::LANE_COPY;
            $reason = 'arrival_copy';
            if (count($aVariants) > 1) {
                $laneFlags[] = 'transfer_resolved_by_seed'; // several variants share the address; one has an item
            }
            if ($mainNoItem > 0) {
                $laneFlags[] = 'gtin_target_not_in_seed';
            }
        } elseif ($mainNoItem > 0 || $otherNoItem > 0) {
            return self::answer(self::CANT_TELL, self::LANE_BARCODE, null, null, ['arrival_barcode_no_item'], ['gtin_target_not_in_seed']);
        } elseif (count($aItems) > 1) {
            return self::answer(self::CANT_TELL, self::LANE_COPY, null, null, ['arrival_copy_several'], ['transfer_ambiguous']);
        } else {
            return self::answer(self::CANT_TELL, self::LANE_COPY, null, null, ['arrival_copy_no_item'], ['transfer_target_not_in_seed']);
        }

        // The item named, against the variant.
        $item = $facts['items'][$target] ?? [];
        $variant = $facts['variant'] ?? null;
        $itemFeatures = $item['features'] ?? null;
        $vetoes = [];
        $soft = [];
        $compared = is_array($variant) && is_array($itemFeatures);
        if ($compared) {
            $check = Veto::check($variant, $itemFeatures, 1, ['s_strength_sibling' => true]);
            $vetoes = array_values(array_unique(array_map(static fn (array $v): string => (string) $v['code'], $check['vetoes'])));
            $soft = array_values(array_map('strval', $check['flags']));
        }
        if ($vetoes !== []) {
            return self::answer(self::CONFLICT, $lane, null, $target, ['veto_on_key', $reason], $laneFlags, $vetoes, $soft, $target);
        }
        if ($lane === self::LANE_BARCODE && (int) ($b['items'][$target]['units_per_scan'] ?? 1) !== 1) {
            return self::answer(self::CANT_TELL, $lane, null, $target, ['arrival_barcode_pack', $reason], $laneFlags, [], $soft, $target);
        }
        // The address is a page that sells the item as a pack (its variant is matched with more than one unit a sale): a copy of
        // that page is no one-unit match, and a suggestion carries no units. The item is named as the closest only.
        if (($aUnits[$target] ?? 1) !== 1) {
            return self::answer(self::CANT_TELL, $lane, null, $target, ['arrival_copy_pack', $reason], $laneFlags, [], $soft, $target);
        }
        if (!empty($item['taken'])) {
            return self::answer(self::CANT_TELL, $lane, null, $target, ['arrival_item_taken', $reason], [...$laneFlags, 'same_channel_target_shared'], [], $soft, $target);
        }
        if (array_intersect($soft, Band::ALIAS_PENDING_FLAGS) !== []) {
            return self::answer(self::CANT_TELL, $lane, null, $target, ['arrival_renamed_range', $reason], $laneFlags, [], $soft, $target);
        }
        $reasons = $compared ? [$reason] : [$reason, 'arrival_not_compared'];
        // Anything beyond "the clues agree and nothing is said against it" is a warning: the flag that the two clues agree is none.
        $clean = $compared && $soft === [] && array_diff($laneFlags, ['transfer_agrees']) === [];
        return self::answer(self::CHECK, $lane, $target, null, $reasons, $laneFlags, [], $soft, $target, $clean);
    }

    /**
     * The "not sure" that replaces the check's own earlier suggestion when no clue is behind it any more (the barcode was taken
     * off, the address changed): it names no item and says why it is there.
     *
     * @return array<string, mixed> as decide()
     */
    public static function noLonger(?string $lane): array
    {
        return self::answer(self::CANT_TELL, in_array($lane, [self::LANE_BARCODE, self::LANE_COPY], true) ? $lane : self::LANE_BARCODE, null, null, [self::NO_LONGER], []);
    }

    /** No suggestion, and why ($outcome: NO_CLUE or one of REFUSALS). @return array<string, mixed> as decide() */
    public static function none(string $outcome): array
    {
        return ['outcome' => $outcome, 'suggest' => false, 'band' => null, 'lane' => null, 'sku_id' => null, 'closest_sku_id' => null, 'target' => null,
            'reasons' => [], 'flags' => [], 'lane_flags' => [], 'target_vetoes' => [], 'target_soft_flags' => [], 'warned' => false, 'via' => null];
    }

    /**
     * Whether two answers say the same thing (the check's own suggestion looked at again: the same answer is not written twice).
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function same(array $a, array $b): bool
    {
        $key = static fn (array $x): array => [$x['band'] ?? null, $x['lane'] ?? null, $x['sku_id'] ?? null, $x['closest_sku_id'] ?? null,
            array_values($x['reasons'] ?? []), self::sorted($x['flags'] ?? [])];
        return $key($a) === $key($b);
    }

    /**
     * @param list<string> $reasons the first is the outcome
     * @param list<string> $laneFlags
     * @param list<string> $vetoes
     * @param list<string> $soft
     * @return array<string, mixed>
     */
    private static function answer(string $band, string $lane, ?int $sku, ?int $closest, array $reasons, array $laneFlags, array $vetoes = [], array $soft = [],
        ?int $target = null, bool $clean = false): array
    {
        $clean = $clean && $band === self::CHECK && $sku !== null;
        $flags = [self::RULES_ONLY, ...$laneFlags, ...$soft, ...$vetoes];
        if (!$clean) {
            $flags[] = self::WARNED;
        }
        return ['outcome' => $reasons[0], 'suggest' => true, 'band' => $band, 'lane' => $lane, 'sku_id' => $sku, 'closest_sku_id' => $closest, 'target' => $target,
            'reasons' => array_values($reasons), 'flags' => self::sorted($flags), 'lane_flags' => self::sorted($laneFlags), 'target_vetoes' => self::sorted($vetoes),
            'target_soft_flags' => self::sorted($soft), 'warned' => !$clean, 'via' => $lane === self::LANE_BARCODE ? 'barcode' : 'address'];
    }

    /** @param list<string> $list @return list<string> */
    private static function sorted(array $list): array
    {
        $list = array_values(array_unique(array_map('strval', $list)));
        sort($list, SORT_STRING);
        return $list;
    }
}
