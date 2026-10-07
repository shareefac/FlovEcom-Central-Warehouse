<?php

declare(strict_types=1);

namespace CW\Catalogue;

use CW\Db;
use CW\Matching\Normalizer;

/**
 * Suggestions for an item card (IM3; docs/decisions.md I104): values a person may ACCEPT with one press, never written by
 * themselves. Read-only, computed when the item page is drawn (and again when a suggestion is accepted, so an accepted value
 * is one still offered).
 *
 * Sources:
 *  - the item's identity card (sku.strength_mg, volume_ml, form, flavour, brand), which the matcher filled from the listing the
 *    item was minted from (M9);
 *  - every listing LINKED to the item (status mapped): its rules-only features (listing_profile.features, M12), or the
 *    Normalizer run on its profile when it has none; its brand;
 *  - the card's own product type, for the duty answer (a liquid, pod or single-use vape: yes; a coil, tank or accessory: no).
 *
 * Mapped onto the card: strength -> nicotine mg/ml; volume -> liquid ml (to 0.1); form -> product type (e_liquid, nic_salt ->
 * e-liquid; pod_kit, kit, battery -> device / kit; a refill pod only when it is prefilled; a "disposable" NEVER: whether an item
 * is single-use is a person's answer, and many "disposable-style" devices sold since June 2025 are rechargeable and refillable,
 * so a source that says disposable is only reported as `disposable_sources`); flavour tokens -> flavour (title case: the
 * memory note "flavour is not a field": a proposal only, a person confirms). single_use, ECID and manufacturer are never
 * proposed. A value equal to the card's is not offered; every value carries the sources that say it.
 */
final class CardProposals
{
    /** The fields that can be proposed (ItemCards::accept refuses the others). */
    public const FIELDS = ['product_type', 'liquid_ml', 'nicotine_mg', 'duty_liable', 'brand', 'flavour'];
    /** The Normalizer's forms (CW\Matching\Form::ALL) => the card's product type; the others propose nothing. */
    public const FORM_TO_TYPE = [
        'e_liquid' => 'e_liquid', 'nic_salt' => 'e_liquid', 'shortfill' => 'shortfill', 'nic_shot' => 'nic_shot', 'prefilled_pod' => 'prefilled_pod',
        'pod_kit' => 'device_kit', 'kit' => 'device_kit', 'battery' => 'device_kit', 'coil' => 'coil', 'tank' => 'tank', 'accessory' => 'accessory',
    ];
    public const MAX_LISTINGS = 25;

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string, mixed>|null $card the card as ItemCards::card() gives it (null: read it)
     * @return array{fields: array<string, list<array{value: mixed, shown: string, sources: list<string>}>>, disagree: list<string>,
     *   disposable_sources: list<string>}
     */
    public function of(int $skuId, ?array $card = null): array
    {
        $card ??= (new ItemCards($this->db))->card($skuId);
        $found = [];
        $disposable = [];
        $sku = $this->db->one('SELECT code, brand, strength_mg, volume_ml, form, flavour FROM sku WHERE id = ?', [$skuId]);
        if ($sku !== null) {
            $label = 'the item\'s identity card (filled by the matcher)';
            $this->add($found, 'nicotine_mg', $sku['strength_mg'], $label);
            $this->add($found, 'liquid_ml', $sku['volume_ml'], $label);
            $this->add($found, 'product_type', self::FORM_TO_TYPE[(string) $sku['form']] ?? null, $label);
            $this->add($found, 'flavour', self::titleCase($sku['flavour']), $label);
            $this->add($found, 'brand', $sku['brand'], $label);
            if ($sku['form'] === 'disposable') {
                $disposable[] = $label;
            }
        }
        foreach ($this->db->all(
            'SELECT cl.id, ch.code AS channel, cl.external_variant_id, lp.product_title, lp.variant_title, lp.brand, lp.attributes, lp.features '
            . "FROM channel_listing cl JOIN channel ch ON ch.id = cl.channel_id LEFT JOIN listing_profile lp ON lp.listing_id = cl.id "
            . "WHERE cl.sku_id = ? AND cl.status = 'mapped' ORDER BY cl.id LIMIT " . self::MAX_LISTINGS,
            [$skuId],
        ) as $l) {
            $title = trim((string) ($l['variant_title'] ?? '') !== '' ? (string) $l['variant_title'] : (string) ($l['product_title'] ?? ''));
            $label = "listing {$l['channel']} {$l['external_variant_id']}" . ($title === '' ? '' : ' "' . mb_substr($title, 0, 80) . '"');
            $f = self::features($l);
            $this->add($found, 'nicotine_mg', $f['strength_mg'] ?? null, $label);
            $this->add($found, 'liquid_ml', $f['volume_ml'] ?? null, $label);
            $form = is_string($f['form'] ?? null) ? $f['form'] : null;
            $type = self::FORM_TO_TYPE[(string) $form] ?? null;
            if ($form === 'refill_pod_cartridge' && ($f['form_sub'] ?? null) === 'prefilled') {
                $type = 'prefilled_pod';
            }
            $this->add($found, 'product_type', $type, $label);
            $tokens = array_values(array_filter(is_array($f['flavour_tokens'] ?? null) ? $f['flavour_tokens'] : [], 'is_string'));
            $this->add($found, 'flavour', self::titleCase($tokens === [] ? null : implode(' ', $tokens)), $label);
            $this->add($found, 'brand', $l['brand'] ?? null, $label);
            if ($form === 'disposable') {
                $disposable[] = $label;
            }
        }
        $type = $card['product_type'] ?? null;
        if (is_string($type)) {
            $why = 'its product type (' . (ItemRules::TYPES[$type] ?? $type) . ')';
            if (in_array($type, ItemRules::LIQUID_TYPES, true)) {
                $this->add($found, 'duty_liable', 'yes', $why . ': Vaping Products Duty applies to all vaping liquids from 1 Oct 2026');
            } elseif (in_array($type, ItemRules::DRY_TYPES, true)) {
                $this->add($found, 'duty_liable', 'no', $why . ': it holds no vaping liquid');
            }
        }
        $fields = [];
        $disagree = [];
        foreach (self::FIELDS as $k) {
            $values = [];
            foreach ($found[$k] ?? [] as $v) {
                if ($v['value'] === ($card[$k] ?? null)) {
                    continue; // already on the card
                }
                $values[] = $v;
            }
            usort($values, static fn (array $a, array $b): int => [count($b['sources']), (string) $a['shown']] <=> [count($a['sources']), (string) $b['shown']]);
            if ($values !== []) {
                $fields[$k] = $values;
            }
            if (count($found[$k] ?? []) > 1) {
                $disagree[] = $k;
            }
        }
        return ['fields' => $fields, 'disagree' => $disagree, 'disposable_sources' => array_values(array_unique($disposable))];
    }

    /**
     * Adds a raw value under a field when ItemCards::check accepts it (a volume is rounded to 0.1 ml first), merging sources.
     *
     * @param array<string, array<string, array{value: mixed, shown: string, sources: list<string>}>> $found
     */
    private function add(array &$found, string $field, mixed $raw, string $source): void
    {
        if ($raw === null || $raw === '' || is_array($raw) || is_bool($raw)) {
            return;
        }
        if ($field === 'liquid_ml') {
            if (!is_numeric($raw) || (float) $raw <= 0) {
                return;
            }
            $raw = number_format(round((float) $raw, 1), 1, '.', '');
        }
        if ($field === 'nicotine_mg' && is_numeric($raw)) {
            $raw = number_format(round((float) $raw, 2), 2, '.', '');
        }
        try {
            $value = ItemCards::check([$field => (string) $raw])[$field];
        } catch (\Throwable) {
            return; // a value the card would refuse is not offered
        }
        if ($value === null) {
            return;
        }
        $key = (string) $value;
        if (!isset($found[$field][$key])) {
            $found[$field][$key] = ['value' => $value, 'shown' => self::shown($field, $value), 'sources' => []];
        }
        if (!in_array($source, $found[$field][$key]['sources'], true)) {
            $found[$field][$key]['sources'][] = $source;
        }
    }

    /** How a card value reads on the screen. */
    public static function shown(string $field, mixed $value): string
    {
        return match ($field) {
            'product_type' => ItemRules::TYPES[(string) $value] ?? (string) $value,
            'liquid_ml' => (string) $value === '0.0' ? '0 ml (no tank)' : rtrim(rtrim((string) $value, '0'), '.') . ' ml',
            'nicotine_mg' => rtrim(rtrim((string) $value, '0'), '.') . ' mg/ml',
            'duty_liable', 'single_use', 'discontinued' => ItemCards::yesNoLabel($value),
            default => (string) $value,
        };
    }

    /**
     * The features of a linked listing: the stored ones, or the Normalizer's on its profile.
     *
     * @param array<string, mixed> $l
     * @return array<string, mixed>
     */
    private static function features(array $l): array
    {
        $f = is_string($l['features'] ?? null) ? json_decode((string) $l['features'], true) : null;
        if (is_array($f) && $f !== []) {
            return $f;
        }
        if ($l['product_title'] === null && $l['variant_title'] === null) {
            return [];
        }
        $attrs = is_string($l['attributes'] ?? null) ? json_decode((string) $l['attributes'], true) : null;
        $attrs = is_array($attrs) ? (is_array($attrs['items'] ?? null) ? $attrs['items'] : (array_is_list($attrs) ? $attrs : [])) : [];
        try {
            return Normalizer::normalize(['site' => (string) $l['channel'], 'variant_id' => (int) $l['external_variant_id'], 'product_title' => (string) $l['product_title'],
                'variant_title' => (string) ($l['variant_title'] ?? ''), 'brand' => (string) ($l['brand'] ?? ''), 'attributes' => $attrs, 'sale_price' => null]);
        } catch (\Throwable) {
            return [];
        }
    }

    /** "blue razz ice" -> "Blue Razz Ice" (each word's first letter; the rest kept). */
    private static function titleCase(mixed $s): ?string
    {
        if (!is_string($s) || trim($s) === '') {
            return null;
        }
        $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' ', array_map(static fn (string $w): string => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), $words));
    }
}
