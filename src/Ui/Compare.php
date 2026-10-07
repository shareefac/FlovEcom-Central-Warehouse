<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Matching\Gtin;

/**
 * The identity comparison of the review screen: what CW reads from the listing (its features, via
 * DecisionService::cardFrom) against the identity card of an item, field by field. `differs` is the
 * only state that is highlighted; a field one side does not know is `unknown` (not a conflict).
 *
 * Brands are spelt differently on each site ("Elux Vapes, Pods and E-Liquids" / "Elux Nic Salt
 * (Legend Salts) E-Liquids", "OXVA" / "OXVA Brand"): a warning on most correct matches stops being
 * read, so brands are compared on their distinctive words (brandWords: case, punctuation and shop
 * words such as vapes, e-liquids, nic salts, brand, 10K dropped; confirmed brand aliases applied).
 * Equal words are `same`; one side's words within the other's, one name a prefix of the other, or
 * the same first word is `alike` (shown as "spelt differently", not highlighted); anything else
 * `differs`. Barcodes are compared on their GTIN keys (leading zeros do not count).
 */
final class Compare
{
    /** Words a shop adds to a brand name that say nothing about who makes it. */
    private const BRAND_NOISE = ['accessories', 'accessory', 'and', 'brand', 'co', 'company', 'disposable', 'disposables', 'e', 'eliquid', 'eliquids',
        'kit', 'kits', 'liquid', 'liquids', 'ltd', 'limited', 'nic', 'nicotine', 'pod', 'pods', 'salt', 'salts', 'the', 'uk', 'vape', 'vapes',
        'vaping', 'vapor', 'vapour'];
    /** The compared fields and their names on the screen (Words::FIELD). */
    public const LABELS = [
        'brand' => Words::FIELD['brand'], 'strength_mg' => Words::FIELD['strength_mg'], 'nic_type' => Words::FIELD['nic_type'], 'line' => Words::FIELD['line'],
        'form' => Words::FIELD['form'], 'flavour' => Words::FIELD['flavour'], 'volume_ml' => Words::FIELD['volume_ml'], 'puffs' => Words::FIELD['puffs'],
        'pack_units' => Words::FIELD['pack_units'],
    ];

    /**
     * @param array<string, mixed> $listingCard DecisionService::cardFrom() of the listing
     * @param array<string, mixed> $sku a sku row
     * @param list<string> $listingBarcodes
     * @param list<string> $skuBarcodes
     * @param array<string, string> $brandAliases confirmed brand aliases, lower-case term => canonical
     * @return list<array{field: string, label: string, listing: string, item: string, state: string}>
     */
    public static function rows(array $listingCard, array $sku, array $listingBarcodes, array $skuBarcodes, array $brandAliases = []): array
    {
        $rows = [];
        foreach (self::LABELS as $field => $label) {
            $a = $listingCard[$field] ?? null;
            $b = $sku[$field] ?? null;
            $state = $field === 'brand' ? self::brandState($a, $b, $brandAliases) : self::state($field, $a, $b);
            $rows[] = ['field' => $field, 'label' => $label, 'listing' => self::show($a), 'item' => self::show($b), 'state' => $state];
        }
        $state = 'unknown';
        if ($listingBarcodes !== [] && $skuBarcodes !== []) {
            $key = static fn (string $b): string => Gtin::key($b) ?? $b;
            $state = array_intersect(array_map($key, $listingBarcodes), array_map($key, $skuBarcodes)) !== [] ? 'same' : 'differs';
        }
        $rows[] = ['field' => 'barcodes', 'label' => Words::FIELD['barcodes'], 'listing' => implode(', ', $listingBarcodes), 'item' => implode(', ', $skuBarcodes), 'state' => $state];
        return $rows;
    }

    private static function show(mixed $v): string
    {
        return $v === null ? '' : Html::dec($v);
    }

    private static function state(string $field, mixed $a, mixed $b): string
    {
        if ($a === null || $a === '' || $b === null || $b === '') {
            return 'unknown';
        }
        return self::norm($field, $a) === self::norm($field, $b) ? 'same' : 'differs';
    }

    private static function norm(string $field, mixed $v): string
    {
        return match ($field) {
            'strength_mg', 'volume_ml' => number_format((float) $v, 2, '.', ''),
            'puffs', 'pack_units' => (string) (int) $v,
            'line', 'flavour' => self::words((string) $v),
            default => mb_strtolower(trim((string) $v)),
        };
    }

    /** @param array<string, string> $aliases */
    public static function brandState(mixed $a, mixed $b, array $aliases = []): string
    {
        if ($a === null || $a === '' || $b === null || $b === '') {
            return 'unknown';
        }
        $wa = self::brandWords((string) $a, $aliases);
        $wb = self::brandWords((string) $b, $aliases);
        if ($wa === $wb) {
            return 'same';
        }
        $ca = implode('', $wa);
        $cb = implode('', $wb);
        $rawA = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $a)) ?? '';
        $rawB = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower((string) $b)) ?? '';
        if ($wa !== [] && $wb !== [] && (array_diff($wa, $wb) === [] || array_diff($wb, $wa) === [] || $wa[0] === $wb[0]
            || str_starts_with($ca, $cb) || str_starts_with($cb, $ca))) {
            return 'alike';
        }
        if ($rawA !== '' && $rawB !== '' && (str_starts_with($rawA, $rawB) || str_starts_with($rawB, $rawA))) {
            return 'alike'; // "RandM" / "R and M Tornado Salts"
        }
        return 'differs';
    }

    /**
     * The distinctive words of a brand name, in order: lower case, `&` read as "and", split on anything
     * but letters and digits, shop words and puff counts ("10K") dropped. A confirmed alias of the whole
     * name (or of its distinctive words) replaces it.
     *
     * @param array<string, string> $aliases
     * @return list<string>
     */
    public static function brandWords(string $brand, array $aliases = []): array
    {
        $lower = mb_strtolower(trim($brand));
        $split = static function (string $s): array {
            $w = preg_split('/[^\p{L}\p{N}]+/u', str_replace('&', ' and ', $s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            return array_values(array_filter($w, static fn (string $t): bool => !in_array($t, self::BRAND_NOISE, true) && preg_match('/^\d+k$/D', $t) !== 1));
        };
        $words = $split($lower);
        $alias = $aliases[$lower] ?? $aliases[implode(' ', $words)] ?? null;
        return $alias !== null ? $split($alias) : $words;
    }

    private static function words(string $s): string
    {
        $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $w = array_values(array_unique($w));
        sort($w, SORT_STRING);
        return implode(' ', $w);
    }
}
