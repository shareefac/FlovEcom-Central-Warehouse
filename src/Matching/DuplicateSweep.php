<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * The wider duplicate sweep of ONE site's own listings (docs/decisions.md M37): is this pair of listings, linked to two
 * different CW items, the same physical product on two pages? Rules only, high precision: a pair is a duplicate only when
 * nothing at all speaks against it, so a pair the rules cannot read is refused, never guessed.
 *
 * judge() runs the hard vetoes of CW\Matching\Veto in BOTH directions (strength, nicotine type, form, line number,
 * modifier and line word, flavour superset/difference, ml, puffs, colour, ohm, pack, multipack: any one refuses), then
 * the sweep's own rules, which a cross-site link does not need but a same-site merge does (two pages of one site name the
 * same product with the same words, so a word or a value on one page only is a reason to stop):
 *   - the soft flags that mean "this field cannot be compared" or "one side says more" refuse (BLOCKING_FLAGS), and so does
 *     a field that one page states twice with two values (Normalizer's conflict_fields: "2ml/5ml Replacement Pod - 5ml");
 *   - the brand must agree (a shared brand family word, or the same brand), the regular prices within 0.67x-1.5x;
 *   - the form must be known on both and the same (pod_kit = kit only), prefilled vs refillable not stated on one side only;
 *   - a value stated on one page only refuses for every identity field (strength, nicotine type, ml, ohm, colour, pack
 *     above 1, puffs unless the other page's model number is that puff count, an N-in-1 count, the VG/PG ratio), except
 *     a VG/PG ratio or a pod's/tank's capacity that only an option row states (pages are made with different option sets;
 *     in a title, or a liquid's bottle size, it counts);
 *   - the VG/PG ratio (50/50, 70/30, Max VG) must be the same: "Heisenberg 50/50" is not "Heisenberg 70/30";
 *   - flavoured products (liquids, disposables, prefilled pods) must agree on the flavour;
 *   - every word of each page's titles (without brand, form, stop, descriptor and shop words; single letters and model codes
 *     such as "X", "F1", "GT2" kept) must be on the other page, and a part word (glass, coil, tank, kit, pod, cartridge,
 *     battery, charger, ...) on one page only refuses;
 *   - two options of ONE product page (same product id) are a pair only when their option texts hold the same words, numbers
 *     and codes, each as often ("Cherry Ice / Blueberry" = "Blueberry / Cherry Ice"; "F1 0.2ohm" is not "F2 0.2ohm");
 *   - when both pages carry usable barcodes they must share one.
 * A pair that passes is scored 0-100 (the identity fields that agree, a shared barcode, the name overlap, the price) and
 * explained: which fields agree, which are unknown on both pages, which do not apply.
 *
 * Pure: no I/O, no state. The input is features(): Normalizer::normalize() of each listing's profile plus its usable GTIN
 * keys, regular price (the one the Duplicates screen shows), VG/PG ratio, title words, model codes and option text.
 */
final class DuplicateSweep
{
    /** ds1.1 (M43): same-site brand rule, edition/limited/special counted as words, hardware flavour_extra and umbrella
     *  modifier_extra re-read, a title's VG/PG ratio wins over the option row. */
    public const VERSION = 'ds1.1';

    public const PRICE_MIN = 0.67;
    public const PRICE_MAX = 1.5;

    /** Veto soft flags that refuse a same-site pair (Veto::SOFT_FLAGS). */
    public const BLOCKING_FLAGS = [
        'internal_conflict', 'modifier_extra', 'flavour_extra', 'line_number_extra', 'line_number_one_side', 'line_alias_pending',
        'relabelled_line_unconfirmed', 'colour_extra', 'volume_diff_attr', 'pack_one_side', 'listing_multiplier', 'price_outlier',
        'strength_missing',
    ];

    /** Identity fields and the feature that states each (a value on one page only refuses, but see judge() step 5). */
    public const FIELDS = [
        'strength' => 'strength_mg', 'nic_type' => 'nic_type', 'volume' => 'volume_ml', 'pack' => 'pack_units', 'puffs' => 'puffs',
        'colour' => 'colour', 'resistance' => 'resistance_ohm', 'vgpg' => 'vgpg',
    ];

    /** Forms that are the same product under another label on one site (a kit sold as a "pod kit"). */
    public const SAME_FORM = [['pod_kit', 'kit']];

    /** Words naming a separate part (stemmed): on one page only, the pages sell different things ("Zlide Tank" vs "Zlide Tank Glass"). */
    public const PART_WORDS = [
        'glass', 'coil', 'tank', 'kit', 'pod', 'cartridge', 'battery', 'charger', 'case', 'drip', 'tip', 'mouthpiece', 'cotton', 'wire',
        'bag', 'lanyard', 'skin', 'sleeve', 'cap', 'adapter', 'adaptor', 'bottle', 'cable', 'empty', 'mod',
    ];

    /** Shop words that name nothing about the product (besides Normalizer's and Veto's stop, noise and descriptor words). */
    public const NOISE = [
        'official', 'genuine', 'authentic', 'latest', 'brand', 'new', 'sale', 'offer', 'deal', 'cheap', 'best', 'seller',
        'bestseller', 'free', 'delivery', 'tpd', 'compliant', 'legal', 'ecig', 'cig', 'vapour', 'vapor', 'puff', 'puffs', 'flavour',
        'flavours', 'flavor', 'flavors', 'pack', 'packs', 'pcs', 'pk', 'by', 'from', 'only', 'per', 'each', 'single', 'with',
        'ml', 'mg', 'ohm', 'ohms', 'disposable', 'disposables', 'device', 'devices', 'cigarette', 'cigarettes',
        'replaceable', 'rechargeable', 'nicotine', 'nic', 'salt', 'salts', 'eliquid', 'eliquids', 'liquid', 'liquids', 'juice',
        'option', 'options', 'choose', 'version', 'the', 'and', 'of', 'for', 'in', 'on', 'vg', 'pg', 'max', 'high',
    ];

    /**
     * Words of Veto::LINE_DESCRIPTORS that DO name a different product on one site (M43): "Riot Squad" vs "Riot Squad Black
     * Edition", a "Limited" or "Special" run. words() keeps them, so a page that says one and a page that does not are refused.
     */
    public const DISTINCT_DESCRIPTORS = ['edition', 'edtn', 'limited', 'special'];

    /** Single letters that never identify a product ("e" liquid, "a", "n", the "s" of "Tom's"). Every other letter is kept ("TPP X"). */
    public const LETTER_NOISE = ['e', 'a', 'n', 's'];

    /** Units after a number: such a token is a quantity, not a model code ("10ml", "0.6ohm", "6k"). */
    private const UNIT = '(?:ml|mg|ohms?|k|w|mah|v|mm|cm|g|kg|x|pcs|pk|pack|packs|puffs?|pc|mins?|hrs?|vg|pg)';

    /**
     * Features of one listing for the sweep: Normalizer::normalize() of its profile row plus its usable GTIN keys and price,
     * the VG/PG ratio, the title words, model codes, and the option text (what the variant title adds to the product title).
     *
     * @param array<string, mixed> $row site, variant_id, product_id, product_title, variant_title, brand, attributes (list), barcodes, price
     * @param list<string> $lexicon the brand's line lexicon on the site (TitlePattern::lexicon)
     * @return array<string, mixed>
     */
    public static function features(array $row, array $lexicon = []): array
    {
        $f = Normalizer::normalize($row + ['sale_price' => null], ['line_lexicon' => $lexicon]);
        $f['gtins'] = Gtin::listingKeys(is_array($row['barcodes'] ?? null) ? $row['barcodes'] : [])['usable'];
        $p = $row['price'] ?? null;
        $f['price'] = is_numeric($p) && (float) $p > 0 ? round((float) $p, 2) : null;
        $pt = Text::lower(Text::clean((string) ($row['product_title'] ?? '')));
        $vt = Text::lower(Text::clean((string) ($row['variant_title'] ?? '')));
        $text = trim($pt . ' ' . ($vt === $pt ? '' : $vt));
        // VG/PG: a ratio the title states wins (M43: Vape and Go's "PG/VG" option row is not written PG-first on every page:
        // "PG/VG: 70/30" on 70VG pages); the option row counts only when the title states none. Two different ratios in the
        // title (or, without one, in the option rows) = unreadable.
        $inTitle = self::ratios($text);
        $ratios = $inTitle;
        if ($inTitle === []) {
            foreach (Normalizer::parseAttributes(is_array($row['attributes'] ?? null) ? $row['attributes'] : [])['vgpg'] ?? [] as $a) {
                array_push($ratios, ...self::ratios(Text::lower((string) $a['v']), str_starts_with((string) $a['name'], 'pg')));
            }
        }
        $ratios = array_values(array_unique($ratios));
        $f['vgpg'] = count($ratios) === 1 ? $ratios[0] : null;
        $f['src']['vgpg'] = count($ratios) === 1 ? ($inTitle !== [] ? 'title' : 'attr') : null;
        if (count($ratios) > 1) {
            $f['conflict_fields'] = array_values(array_unique(array_merge($f['conflict_fields'] ?? [], ['vgpg'])));
        }
        $tokens = Text::tokens(Normalizer::stripQuantities($text));
        $f['title_tokens'] = $tokens;
        $f['codes'] = self::codes(Text::tokens($text));
        // the option text: the variant title's words, numbers and codes, each as often as it occurs (two options of one product
        // page share the product title, so what differs between them is the option)
        $opt = [];
        foreach (Text::tokens($vt === '' ? $pt : $vt) as $t) {
            if (!in_array($t, self::NOISE, true) && !in_array($t, Normalizer::FLAVOUR_NOISE, true)) {
                $opt[] = $t;
            }
        }
        sort($opt, SORT_STRING);
        $f['option_tokens'] = $opt;
        return $f;
    }

    /**
     * @param array<string, mixed> $a features() of one listing
     * @param array<string, mixed> $b features() of the other
     * @return array{ok: bool, blocks: list<array{code: string, detail: string}>, score: int, agree: list<string>, unknown: list<string>,
     *               n_a: list<string>, flags: list<string>, barcode: string, price_ratio: ?float, name: float, same_product: bool}
     */
    public static function judge(array $a, array $b): array
    {
        $blocks = [];
        $seen = [];
        $block = static function (string $code, string $detail = '') use (&$blocks, &$seen): void {
            if (!isset($seen[$code])) {
                $seen[$code] = true;
                $blocks[] = ['code' => $code, 'detail' => $detail];
            }
        };

        // 1. The hard vetoes, both directions (Veto is written listing -> item; some checks are one-way).
        $r1 = Veto::check($a, $b);
        $r2 = Veto::check($b, $a);
        foreach (array_merge($r1['vetoes'], $r2['vetoes']) as $v) {
            $block('veto_' . $v['code'], $v['detail']);
        }
        $flags = array_values(array_unique(array_merge($r1['flags'], $r2['flags'])));
        sort($flags, SORT_STRING);
        foreach ($flags as $fl) {
            if (!in_array($fl, self::BLOCKING_FLAGS, true)) {
                continue;
            }
            if ($fl === 'flavour_extra' && self::flavourExtraHarmless($a, $b)) {
                continue;   // hardware: the extra words are descriptors or brand words ("Corex 2.0 Mesh" vs "Corex 2.0"), M43
            }
            if ($fl === 'modifier_extra' && self::modifierExtraHarmless($a, $b)) {
                continue;   // an umbrella word ("bar") under the same model number ("IVG 6000 Bar Salts" vs "(IVG 6000)"), M43
            }
            $block('flag_' . $fl);
        }
        $conflicts = array_values(array_unique(array_merge($a['conflict_fields'] ?? [], $b['conflict_fields'] ?? [])));
        if ($conflicts !== []) {
            sort($conflicts, SORT_STRING);
            $block('unreadable', implode('+', $conflicts));
        }
        $fields = [];
        foreach ($r1['fields'] as $k => $s) {
            $s2 = $r2['fields'][$k] ?? $s;
            $fields[$k] = in_array('conflict', [$s, $s2], true) ? 'conflict' : ($s === 'agree' || $s2 === 'agree' ? 'agree' : $s);
        }
        $va = $a['vgpg'] ?? null;
        $vb = $b['vgpg'] ?? null;
        $fields['vgpg'] = $va !== null && $vb !== null ? ($va === $vb ? 'agree' : 'conflict') : 'unknown';
        if ($fields['vgpg'] === 'conflict') {
            $block('vgpg', $va . ' vs ' . $vb);
        }

        // 2. Brand.
        $exclude = array_merge(Veto::GENERIC_LINE_WORDS, Veto::UMBRELLA_LINE_WORDS);
        $fa = array_values(array_diff($a['brand_family'] ?? [], $exclude));
        $fb = array_values(array_diff($b['brand_family'] ?? [], $exclude));
        $sameKey = ($a['brand_key'] ?? '') !== '' && ($a['brand_key'] ?? '') === ($b['brand_key'] ?? '');
        if (!$sameKey && ($fa === [] || $fb === [] || array_intersect($fa, $fb) === [])) {
            $block('brand', ($a['brand_raw'] ?? '') . ' vs ' . ($b['brand_raw'] ?? ''));
        } elseif (!self::sameSiteBrand($a, $b, $fa, $fb)) {
            // One site names one brand the same way (M43): another brand text is only the same brand when a real brand word
            // (not "bar", "salts") is shared AND the line words agree ("Bar Salts" vs "Bar Vape Salts" are two ranges).
            $block('brand_text', ($a['brand_raw'] ?? '') . ' vs ' . ($b['brand_raw'] ?? ''));
        }

        // 3. Price (regular prices).
        $ratio = null;
        $pa = $a['price'] ?? null;
        $pb = $b['price'] ?? null;
        if (is_float($pa) && is_float($pb) && $pa > 0 && $pb > 0) {
            $ratio = round($pa / $pb, 3);
            if ($ratio < self::PRICE_MIN || $ratio > self::PRICE_MAX) {
                $block('price', Text::num($pa) . ' vs ' . Text::num($pb));
            }
        } else {
            $block('price_unknown');
        }

        // 4. Form: known on both, the same (or a listed synonym), prefilled/refillable not on one side only.
        $formA = $a['form'] ?? null;
        $formB = $b['form'] ?? null;
        $labels = Form::label($formA, $a['form_sub'] ?? null) . ' vs ' . Form::label($formB, $b['form_sub'] ?? null);
        if ($formA === null || $formB === null) {
            $block('form_unknown', $labels);
        } elseif ($formA !== $formB && !self::sameForm($formA, $formB)) {
            $block('form_label', $labels);
        }
        $subA = in_array($a['form_sub'] ?? null, ['prefilled', 'refillable'], true);
        $subB = in_array($b['form_sub'] ?? null, ['prefilled', 'refillable'], true);
        if ($subA !== $subB) {
            $block('form_sub_one_side', $labels);
        }

        // 5. A value on one page only.
        foreach (self::FIELDS as $field => $key) {
            $x = $a[$key] ?? null;
            $y = $b[$key] ?? null;
            if (($x === null) === ($y === null)) {
                continue;
            }
            $known = $x ?? $y;
            if ($field === 'pack' && (int) $known === 1) {
                continue;   // "Pack of 1" against a page that does not say: one unit either way
            }
            if ($field === 'puffs' && in_array((string) (int) $known, array_map('strval', ($x === null ? $a : $b)['line_numbers'] ?? []), true)) {
                continue;   // "Elf Bar 600" against "Elf Bar 600 Puffs"
            }
            $src = (string) (($x === null ? $b : $a)['src'][$key] ?? '');
            if ($src === 'attr' && ($field === 'vgpg' || ($field === 'volume' && !in_array($a['form_class'] ?? null, ['liquid', 'nic_shot'], true)))) {
                // an option row on one page only (pages are made with different option sets): a VG/PG row, a pod's or tank's
                // "Tank Capacity"; in a title, or a liquid's bottle size, it counts
                continue;
            }
            $block($field . '_one_side', ($x === null ? '-' : self::show($x)) . ' vs ' . ($y === null ? '-' : self::show($y)));
        }
        if (($a['multi_n'] ?? null) !== ($b['multi_n'] ?? null)) {
            $block('multi_n', ($a['multi_n'] ?? '-') . '-in-1 vs ' . ($b['multi_n'] ?? '-') . '-in-1');
        }

        // 6. Flavour, for flavoured products.
        if ((Veto::consumable($a) || Veto::consumable($b)) && ($fields['flavour'] ?? 'unknown') !== 'agree') {
            $block('flavour_not_agreed', implode(' ', $a['flavour_tokens'] ?? ['-']) . ' vs ' . implode(' ', $b['flavour_tokens'] ?? ['-']));
        }

        // 7. Words and model codes on both pages; part words on both or neither.
        $xa = self::missing(self::words($a, $b), $b, $a);
        $xb = self::missing(self::words($b, $a), $a, $b);
        if ($xa !== [] || $xb !== []) {
            $block('words', '+' . implode('+', $xa) . ' / +' . implode('+', $xb));
        }
        $ca = array_values(array_filter($a['codes'] ?? [], static fn (string $c): bool => !Text::fuzzyIn($c, self::presence($b, $a))));
        $cb = array_values(array_filter($b['codes'] ?? [], static fn (string $c): bool => !Text::fuzzyIn($c, self::presence($a, $b))));
        if ($ca !== [] || $cb !== []) {
            $block('model_code', '+' . implode('+', $ca) . ' / +' . implode('+', $cb));
        }
        $pa = self::parts($a);
        $pb = self::parts($b);
        $px = array_values(array_unique(array_merge(array_diff($pa, $pb), array_diff($pb, $pa))));
        if ($px !== []) {
            sort($px, SORT_STRING);
            $block('part_word', implode('+', $px));
        }

        // 8. Two options of one product page: the same option text.
        $sameProduct = (int) ($a['product_id'] ?? 0) > 0 && (int) ($a['product_id'] ?? 0) === (int) ($b['product_id'] ?? -1);
        if ($sameProduct && ($a['option_tokens'] ?? []) !== ($b['option_tokens'] ?? [])) {
            $block('option_differs', implode(' ', $a['option_tokens'] ?? []) . ' vs ' . implode(' ', $b['option_tokens'] ?? []));
        }

        // 9. Barcodes: both pages carry usable ones -> they must share one.
        $ga = $a['gtins'] ?? [];
        $gb = $b['gtins'] ?? [];
        $barcode = array_intersect($ga, $gb) !== [] ? 'shared' : ($ga === [] && $gb === [] ? 'none' : ($ga === [] || $gb === [] ? 'one_side' : 'differ'));
        if ($barcode === 'differ') {
            $block('barcodes_differ');
        }

        // 10. Score and explanation.
        $agree = [];
        $unknown = [];
        $na = [];
        foreach ($fields as $k => $s) {
            if ($s === 'agree') {
                $agree[] = $k;
            } elseif ($s === 'n_a') {
                $na[] = $k;
            } elseif ($s === 'unknown') {
                $unknown[] = $k;
            }
        }
        $ta = array_flip($a['name_tokens'] ?? []);
        $tb = array_flip($b['name_tokens'] ?? []);
        $union = count($ta + $tb);
        $name = $union > 0 ? round(count(array_intersect_key($ta, $tb)) / $union, 3) : 0.0;
        $stated = count($agree) + count($unknown);
        $score = (int) round(
            50 * ($stated > 0 ? count($agree) / $stated : 0)
            + 20 * $name
            + ($barcode === 'shared' ? 20 : ($barcode === 'one_side' ? 8 : 5))
            + ($ratio === null ? 0 : 10 * max(0.0, 1 - abs(log($ratio)) / log(self::PRICE_MAX))),
        );
        return ['ok' => $blocks === [], 'blocks' => $blocks, 'score' => max(0, min(100, $score)), 'agree' => $agree, 'unknown' => $unknown,
            'n_a' => $na, 'flags' => $flags, 'barcode' => $barcode, 'price_ratio' => $ratio, 'name' => $name, 'same_product' => $sameProduct];
    }

    /**
     * The same brand as one site writes it (M43): the same brand text, or a shared brand word that is not generic ("salt") or an
     * umbrella word ("bar") AND every line word of each page (generic and umbrella words aside) on the other page too ("IVG Nic
     * Salts" "IVG Intense" = "IVG" "... (Intense)"; "Bar Salts" is not "Bar Vape Salts": no real brand word is left).
     *
     * @param list<string> $fa brand family of $a without generic/umbrella words
     * @param list<string> $fb the same of $b
     */
    public static function sameSiteBrand(array $a, array $b, array $fa, array $fb): bool
    {
        if (TitlePattern::brandKey((string) ($a['brand_raw'] ?? '')) === TitlePattern::brandKey((string) ($b['brand_raw'] ?? ''))) {
            return true;
        }
        if ($fa === [] || $fb === [] || array_intersect($fa, $fb) === []) {
            return false;
        }
        $drop = array_merge(Veto::GENERIC_LINE_WORDS, Veto::UMBRELLA_LINE_WORDS);
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            $seq = Flavour::canon(self::presence($y, $x));
            foreach (array_diff(Flavour::canon(array_map('strval', $x['line_tokens'] ?? [])), $drop) as $t) {
                if (!Text::fuzzyIn((string) $t, $seq)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Veto's flavour_extra on two pages of HARDWARE (neither is a flavoured product) whose extra words are only descriptors
     * ("mesh", "series"), generic, umbrella or brand words: the hardware has no flavour, the Normalizer read line words as one
     * on one page ("Vaporesso Xros Corex Replacement Pods" -> flavour "xros corex") and compared the other page's residual
     * words ("mesh"). Every title word is still checked by words() (M43).
     */
    private static function flavourExtraHarmless(array $a, array $b): bool
    {
        if (Veto::consumable($a) || Veto::consumable($b)) {
            return false;
        }
        $ok = array_flip(array_merge(Veto::LINE_DESCRIPTORS, Veto::GENERIC_LINE_WORDS, Veto::UMBRELLA_LINE_WORDS, $a['brand_family'] ?? [], $b['brand_family'] ?? [],
            Text::tokens(Text::lower((string) ($a['brand_raw'] ?? ''))), Text::tokens(Text::lower((string) ($b['brand_raw'] ?? '')))));
        foreach ([Veto::flavourCompare($a, $b), Veto::flavourCompare($b, $a)] as $fc) {
            if ($fc['flag'] !== 'flavour_extra') {
                continue;
            }
            if (preg_match('/^listing \+(.*) \/ item \+(.*)$/D', $fc['detail'], $m) !== 1) {
                return false;
            }
            foreach (array_filter(array_merge(explode('+', $m[1]), explode('+', $m[2])), static fn (string $t): bool => $t !== '') as $t) {
                if (!isset($ok[$t]) || in_array($t, self::DISTINCT_DESCRIPTORS, true) || Flavour::isWord(Text::stem($t))) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Veto's modifier_extra when every one-sided line word is an umbrella or generic word ("bar", "salts") and both pages state
     * the same model numbers ("IVG 6000 Bar Salts" vs "IVG Nic Salt ... (IVG 6000)"): the number names the range (M43).
     */
    private static function modifierExtraHarmless(array $a, array $b): bool
    {
        $na = array_map('strval', $a['line_numbers'] ?? []);
        $nb = array_map('strval', $b['line_numbers'] ?? []);
        sort($na, SORT_STRING);
        sort($nb, SORT_STRING);
        if ($na === [] || $na !== $nb) {
            return false;
        }
        $soft = array_merge(Veto::GENERIC_LINE_WORDS, Veto::UMBRELLA_LINE_WORDS);
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            $seq = Flavour::canon(self::presence($y, $x));
            foreach (Flavour::canon(array_map('strval', $x['line_tokens'] ?? [])) as $t) {
                if (strlen($t) < 3 || in_array($t, Normalizer::STOPWORDS, true) || Text::fuzzyIn($t, $seq)
                    || in_array($t, $x['brand_family'] ?? [], true) || in_array($t, $y['brand_family'] ?? [], true)) {
                    continue;
                }
                if (!in_array($t, $soft, true)) {
                    return false;
                }
            }
        }
        return true;
    }

    public static function sameForm(string $a, string $b): bool
    {
        foreach (self::SAME_FORM as $pair) {
            if (in_array($a, $pair, true) && in_array($b, $pair, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The VG/PG ratios a text states, as "<vg>" ("70" for 70VG/30PG, 70/30, 70-30, 70% VG), "max_vg" or "max_pg". A bare "a/b"
     * is VG/PG as titles write it ("Hayati 70/30"), PG/VG in an option named so ($pgFirst: Vape and Go's "PG/VG: 30/70").
     *
     * @return list<string>
     */
    public static function ratios(string $lower, bool $pgFirst = false): array
    {
        $out = [];
        $s = ' ' . $lower . ' ';
        if (preg_match_all('/\b(\d{1,3})\s*%?\s*vg\s*[\/\-:|,]?\s*(\d{1,3})\s*%?\s*pg\b/u', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = (string) (int) $x[1];
            }
            $s = preg_replace('/\b(\d{1,3})\s*%?\s*vg\s*[\/\-:|,]?\s*(\d{1,3})\s*%?\s*pg\b/u', ' ', $s) ?? $s;
        }
        if (preg_match_all('/\b(\d{1,3})\s*%?\s*pg\s*[\/\-:|,]?\s*(\d{1,3})\s*%?\s*vg\b/u', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = (string) (int) $x[2];
            }
            $s = preg_replace('/\b(\d{1,3})\s*%?\s*pg\s*[\/\-:|,]?\s*(\d{1,3})\s*%?\s*vg\b/u', ' ', $s) ?? $s;
        }
        if (preg_match_all('/\b(\d{1,3})\s*%?\s*vg\b/u', $s, $m)) {
            foreach ($m[1] as $x) {
                $out[] = (string) (int) $x;
            }
        }
        if (preg_match_all('/(?<![\d.\/])(\d{2})\s*[\/\-]\s*(\d{2})(?![\d.\/]|\s*(?:mg|ml|k\b|%))/u', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if ((int) $x[1] + (int) $x[2] === 100) {
                    $out[] = (string) (int) $x[$pgFirst ? 2 : 1];
                }
            }
        }
        if (preg_match('/\b(?:max|high|full)\s*-?\s*vg\b/u', $s)) {
            $out[] = 'max_vg';
        }
        if (preg_match('/\b(?:max|high|full)\s*-?\s*pg\b/u', $s)) {
            $out[] = 'max_pg';
        }
        return array_values(array_unique($out));
    }

    /**
     * Model codes in a title: tokens mixing letters and digits that are not a quantity ("f1", "gt2", "x2", "rpm80", "t32000",
     * "mo.15"); "10ml", "0.6ohm", "6k" are quantities.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    public static function codes(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $t) {
            $t = (string) $t;
            if (preg_match('/[a-z]/', $t) !== 1 || preg_match('/\d/', $t) !== 1) {
                continue;
            }
            if (preg_match('/^\d+(?:\.\d+)?' . self::UNIT . '$/', $t) === 1) {
                continue;
            }
            $out[$t] = true;
        }
        $out = array_map('strval', array_keys($out));
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * The words of $x's titles that must be on the other page: without numbers and codes (compared on their own), brand words
     * (both pages'), stop, form, descriptor, shop and part words, and the letters of LETTER_NOISE; compounds joined as the other
     * page writes them.
     *
     * @return list<string>
     */
    public static function words(array $x, array $other): array
    {
        static $skip = null;
        $skip ??= array_flip(array_diff(array_merge(Normalizer::STOPWORDS, Normalizer::FLAVOUR_NOISE, Veto::GENERIC_LINE_WORDS,
            Veto::UMBRELLA_LINE_WORDS, Veto::LINE_DESCRIPTORS, self::NOISE, self::PART_WORDS, self::LETTER_NOISE), ['x', ...self::DISTINCT_DESCRIPTORS]));
        $brand = [];
        foreach ([$x, $other] as $y) {
            foreach (array_merge(Text::tokens((string) ($y['brand_raw'] ?? '')), $y['brand_family'] ?? []) as $t) {
                $brand[(string) $t] = true;
            }
        }
        $raw = Text::mergeCompounds(array_map('strval', $x['title_tokens'] ?? []), self::presence($other, $x));
        $out = [];
        foreach ($raw as $t) {
            $t = (string) $t;
            if (preg_match('/\d/', $t) === 1 || isset($skip[$t]) || isset($skip[Text::stem($t)]) || isset($brand[$t])) {
                continue;
            }
            $out[$t] = true;
        }
        $out = array_map('strval', array_keys($out));
        sort($out, SORT_STRING);
        return $out;
    }

    /** The part words $x's titles name (singular; attributes are left out: "Tank Capacity" is not a tank). @return list<string> */
    public static function parts(array $x): array
    {
        $out = [];
        foreach ($x['title_tokens'] ?? [] as $t) {
            $t = (string) $t;
            $s = in_array($t, self::PART_WORDS, true) ? $t : (str_ends_with($t, 'ies') ? substr($t, 0, -3) . 'y'
                : (str_ends_with($t, 'es') && in_array(substr($t, 0, -2), self::PART_WORDS, true) ? substr($t, 0, -2) : rtrim($t, 's')));
            if (in_array($s, self::PART_WORDS, true)) {
                $out[$s] = true;
            }
        }
        $out = array_map('strval', array_keys($out));
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * The words of $words that the page $y does not say (fuzzy presence; a word that is not a flavour word also inside a longer
     * word: "mate" in "podmate", while "razz" is never found in "razzle"; single letters exactly).
     *
     * @param list<string> $words
     * @return list<string>
     */
    private static function missing(array $words, array $y, array $x): array
    {
        $seq = self::presence($y, $x);
        $out = [];
        foreach ($words as $t) {
            if (strlen($t) === 1 ? in_array($t, $seq, true) : self::present($t, $seq)) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /** Everything $y says, for presence checks: its text, brand, brackets, model words split; compounds as $x writes them. @return list<string> */
    private static function presence(array $y, array $x): array
    {
        $t = Veto::joinInitials(array_merge($y['full_tokens'] ?? [], $y['title_tokens'] ?? [], Text::tokens((string) ($y['brand_raw'] ?? '')),
            $y['paren_tokens'] ?? []));
        foreach ($t as $w) {
            if (preg_match('/^([a-z]+)(\d+(?:\.\d+)?)([a-z]*)$/', (string) $w, $m)) {
                $t[] = $m[1];
                $t[] = $m[2];
                if ($m[3] !== '') {
                    $t[] = $m[3];
                }
            }
        }
        $t = array_map('strval', $t);
        return array_merge($t, Text::mergeCompounds($t, array_merge($x['full_tokens'] ?? [], $x['title_tokens'] ?? [])));
    }

    /** @param list<string> $seq */
    private static function present(string $t, array $seq): bool
    {
        if (Text::fuzzyIn($t, $seq)) {
            return true;
        }
        if (strlen($t) >= 4 && !Flavour::isWord(Text::stem($t))) {
            foreach ($seq as $s) {
                if (strlen($s) > strlen($t) && str_contains($s, $t)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function show(mixed $v): string
    {
        return is_float($v) || is_int($v) ? Text::num($v) : (string) $v;
    }
}
