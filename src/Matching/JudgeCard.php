<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Identity card for the barcode-blind judge (design A.7 PromptSerializer allowlist, invariant I12).
 *
 * Allowlist: product title, variant title, brand, attributes (name: value), unit price, and the rules
 * extraction with its source tags. Never: barcodes, codes, ids, permalinks, stock, cost, sales, status,
 * prescores, vetoes or flags. Any run of 8+ digits in catalogue text is masked so a barcode typed into a
 * title cannot leak.
 */
final class JudgeCard
{
    /** Keys every card carries. */
    public const CORE_FIELDS = ['ref', 'product_title', 'variant_title', 'brand', 'attributes', 'unit_price_gbp', 'extracted'];

    /**
     * Allowlist. product_strengths_mg (candidate cards only, judge v2): the nicotine strengths the candidate's product
     * is sold in on its site, so the judge can apply the strength-missing rule the same way every time.
     */
    public const FIELDS = ['ref', 'product_title', 'variant_title', 'brand', 'attributes', 'unit_price_gbp', 'extracted', 'product_strengths_mg'];

    /**
     * @param array<string,mixed> $row raw export row
     * @param array<string,mixed> $f Normalizer features of the same row
     * @param ?list<float|int> $productStrengths strengths (mg) of the card's product, candidates only
     * @return array<string,mixed>
     */
    public static function card(string $ref, array $row, array $f, ?array $productStrengths = null): array
    {
        $attrs = [];
        $counts = [];
        foreach ((array) ($row['attributes'] ?? []) as $a) {
            $n = Text::clean((string) ($a['name'] ?? ''));
            $counts[$n] = ($counts[$n] ?? 0) + 1;
        }
        foreach ((array) ($row['attributes'] ?? []) as $a) {
            $n = Text::clean((string) ($a['name'] ?? ''));
            $v = Text::clean((string) ($a['value'] ?? ''));
            if ($n === '' || $v === '' || preg_match('/^choose an?\b|^select\b/i', $v) || preg_match('/^(?:made in|charging type|battery type)$/i', $n)) {
                continue;
            }
            $s = self::mask($n . ': ' . $v);
            if (($counts[$n] ?? 0) > 1 && (int) ($a['is_variable'] ?? 0) === 1) {
                $s .= ' (this variant)';
            }
            $attrs[$s] = true;
        }
        $attrs = array_slice(array_keys($attrs), 0, 16);

        $ex = [];
        $src = $f['src'] ?? [];
        foreach ([
            'form' => 'form', 'strength_mg' => 'strength_mg', 'nic_type' => 'nic_type', 'volume_ml' => 'volume_ml',
            'pack_units' => 'pack_units', 'puffs' => 'puffs', 'colour' => 'colour', 'resistance_ohm' => 'resistance_ohm',
        ] as $k => $sk) {
            if ($f[$k] !== null) {
                $val = $f[$k];
                if ($k === 'form' && ($f['form_sub'] ?? null) !== null) {
                    $val .= ' (' . $f['form_sub'] . ')';
                }
                $ex[$k] = ['value' => $val, 'source' => $src[$sk] ?? 'title'];
            }
        }
        if (($f['flavour_tokens'] ?? null) !== null) {
            $ex['flavour_words'] = ['value' => implode(' ', $f['flavour_tokens']), 'source' => (string) $f['flavour_src']];
        }
        if (($f['line_numbers'] ?? []) !== []) {
            $ex['model_numbers'] = ['value' => implode(', ', $f['line_numbers']), 'source' => 'title'];
        }
        if (($f['line_modifiers'] ?? []) !== []) {
            $ex['line_modifiers'] = ['value' => implode(', ', $f['line_modifiers']), 'source' => 'title'];
        }
        if (($f['listing_multiplier'] ?? null) !== null) {
            $ex['listing_multiplier'] = ['value' => $f['listing_multiplier'], 'source' => 'title'];
        }
        if (($f['internal_conflicts'] ?? []) !== []) {
            $ex['unresolved'] = array_values(array_unique(array_map(
                fn ($c) => str_replace(['_', ':'], [' ', ': '], (string) $c),
                $f['internal_conflicts']
            )));
        }

        $card = [
            'ref' => $ref,
            'product_title' => self::mask(Text::clean((string) ($row['product_title'] ?? ''))),
            'variant_title' => self::mask(Text::clean((string) ($row['variant_title'] ?? ''))),
            'brand' => self::mask(Text::clean((string) ($row['brand'] ?? ''))),
            'attributes' => $attrs,
            'unit_price_gbp' => $f['unit_price'] !== null ? round((float) $f['unit_price'], 2) : null,
            'extracted' => $ex,
        ];
        if ($productStrengths !== null) {
            $st = array_values(array_unique(array_map(fn ($x) => (float) $x, $productStrengths)));
            sort($st);
            $card['product_strengths_mg'] = array_map(fn ($x) => floor($x) === $x ? (int) $x : $x, $st);
        }
        return $card;
    }

    public static function mask(string $s): string
    {
        return preg_replace('/\d{8,}/', '[number]', $s) ?? $s;
    }

    /**
     * Throws if any forbidden string (barcode keys, permalinks, ids) appears in the encoded payload, or if a
     * card carries a key outside the allowlist.
     *
     * @param array<string,mixed> $payload
     * @param list<string> $forbidden
     */
    public static function assertBlind(array $payload, array $forbidden): void
    {
        $walk = function ($node) use (&$walk): void {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['ref'], $node['variant_title'])) {
                $extra = array_diff(array_keys($node), self::FIELDS);
                if ($extra !== []) {
                    throw new \RuntimeException('card carries non-allowlisted keys: ' . implode(',', $extra));
                }
            }
            foreach ($node as $k => $v) {
                if (is_string($k) && preg_match('/barcode|gtin|ean|permalink|variant_id|product_id|prescore|veto|units_|stock|cost|sku/i', $k)) {
                    throw new \RuntimeException('forbidden key in judge payload: ' . $k);
                }
                $walk($v);
            }
        };
        $walk($payload);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        if (preg_match('/\d{8,}/', $json)) {
            throw new \RuntimeException('judge payload contains an 8+ digit number');
        }
        foreach ($forbidden as $s) {
            $s = (string) $s;
            if ($s !== '' && strlen($s) >= 6 && str_contains($json, $s)) {
                throw new \RuntimeException('judge payload contains a forbidden value');
            }
        }
    }
}
