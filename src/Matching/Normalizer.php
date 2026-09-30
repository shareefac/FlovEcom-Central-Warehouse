<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Rules-only feature extraction for one catalogue listing (plan §7.3 step 2).
 *
 * Ported from the dormant App/app_config/modules/product_mapping.php normalize_variant (:121-277)
 * with the design fixes (matching design A.2 / S1):
 *  - pack is NULL when unknown, never 1 (the :146 bug: `$pack = 1;`);
 *  - pack and puffs are read from attributes; attribute values prefer is_variable=1 rows;
 *    "Choose an option" is dropped; several distinct values -> unknown + internal_conflict;
 *  - "6K" / "12K" are expanded to 6000 / 12000;
 *  - form is an explicit field (not a stopword);
 *  - line numbers and line modifiers are kept as their own fields;
 *  - l/I folding ("lce" -> "ice"); parenthetical descriptors do not become flavour words.
 *
 * Every extracted value carries a source tag. A value is only ever reported when its sources
 * agree; disagreement yields null plus an entry in internal_conflicts (a veto needs a known,
 * internally consistent value on both sides, design A.6).
 */
final class Normalizer
{
    public const VERSION = 'n2.0';

    public const FORMS = [
        'disposable', 'prefilled_pod', 'pod_kit', 'refill_pod_cartridge', 'e_liquid', 'nic_salt', 'shortfill',
        'nic_shot', 'coil', 'tank', 'kit', 'battery', 'accessory', 'other',
    ];

    /** Form -> veto class. Forms in one class may be the same physical item under a different label. */
    public const FORM_CLASS = [
        'disposable' => 'device', 'pod_kit' => 'device', 'kit' => 'device',
        'prefilled_pod' => 'pod_refill', 'refill_pod_cartridge' => 'pod_refill',
        'e_liquid' => 'liquid', 'nic_salt' => 'liquid', 'shortfill' => 'liquid',
        'nic_shot' => 'nic_shot', 'coil' => 'coil', 'tank' => 'tank', 'battery' => 'battery',
        'accessory' => 'accessory', 'other' => 'other',
    ];

    /** Name-token stopwords (product_mapping::STOPWORDS plus form words, which now live in `form`). */
    public const STOPWORDS = [
        'disposable', 'disposables', 'vape', 'vapes', 'vaping', 'pod', 'pods', 'kit', 'kits', 'device', 'e', 'liquid',
        'liquids', 'eliquid', 'eliquids', 'ejuice', 'shortfill', 'longfill', 'nic', 'salt', 'salts', 'nicsalt',
        'nicsalts', 'nicotine', 'edition', 'new', 'uk', 'the', 'and', 'by', 'prefilled', 'pre', 'filled', 'refill',
        'refills', 'refillable', 'replacement', 'with', 'of', 'for', 'a', 'x', 'flavour', 'flavor', 'version',
        'pack', 'puffs', 'puff', 'mg', 'ml', 'in', 'on', 'only', 'bottle', 'system', 'starter',
    ];

    /** Words that never carry flavour identity. */
    public const FLAVOUR_NOISE = [
        'and', 'with', 'the', 'of', 'on', 'in', 'n', 'by', 'x', 'a', 'flavour', 'flavor', 'flavours', 'flavors',
        'edition', 'new', 'limited', 'mg', 'ml', 'puffs', 'puff', 'k', 'vg', 'pg', 'uk', 'for', 'only', 'kit',
        'kits', 'pod', 'pods', 'prefilled', 'pre', 'filled', 'refill', 'refills', 'replacement', 'disposable',
        'vape', 'vapes', 'device', 'e', 'liquid', 'liquids', 'eliquid', 'eliquids', 'ejuice', 'nic', 'nicotine',
        'salt', 'salts', 'nicsalt', 'nicsalts', 'shortfill', 'longfill', 'shot', 'shots', 'pack', 'bottle',
        'version', 'nico', 'strength', 'free', 'zero', 'options', 'option', 'choose',
    ];

    /** Generic words stripped from brand names before family keys are taken (U4 §e). */
    public const BRAND_GENERIC = [
        'vape', 'vapes', 'vaping', 'brand', 'nic', 'salt', 'salts', 'e', 'liquid', 'liquids', 'eliquid', 'eliquids',
        'juice', 'juices', 'co', 'company', 'ltd', 'uk', 'kits', 'kit', 'accessories', 'accessory', 'pods', 'pod',
        'and', 'the', 'by', 'disposable', 'disposables', 'nicotine', 'pouches', 'pouch', 'shortfill', 'shortfills',
        'liq', 'strips', 'mods', 'n',
    ];

    /** Brand tokens too common to define a family on their own. */
    public const BRAND_WEAK = ['bar', 'bars', 'pro', 'max', 'plus', 'x', 'series', 'big', 'mini', 'my', 'dr', 'double', 'power', 'clear'];

    /**
     * Line modifiers (seed list, pending mapping-lead confirmation per family; design A.6 line_modifier).
     * A one-sided modifier from this list is a hard veto ("Bar" vs "Bar Plus").
     */
    public const LINE_MODIFIERS = [
        'plus', 'max', 'pro', 'ultra', 'mini', 'lite', 'air', 'prime', 'nano', 'turbo', 'xl', 'duo', 'se', 'neo',
        'evo', 'elite',
    ];

    public const COLOURS = [
        'black', 'white', 'red', 'blue', 'green', 'yellow', 'pink', 'purple', 'orange', 'grey', 'gray', 'silver',
        'gold', 'golden', 'brown', 'rainbow', 'gunmetal', 'navy', 'teal', 'cyan', 'camo', 'carbon', 'stainless',
        'steel', 'rose', 'violet', 'bronze', 'champagne', 'beige', 'magenta', 'turquoise', 'maroon', 'khaki', 'indigo',
    ];

    public const COLOUR_QUALIFIERS = [
        'matte', 'glossy', 'dark', 'light', 'deep', 'sky', 'ocean', 'midnight', 'metallic', 'twilight', 'space',
        'sunset', 'aurora', 'jade', 'fiber', 'fibre', 'leather', 'classic', 'mist', 'haze', 'neon', 'pastel', 'lava',
        'sapphire', 'ruby', 'emerald', 'amber', 'coral', 'pearl', 'shiny', 'transparent', 'clear', 'frosted',
        'edition', 'and', 'grey', 'gray', 'polar', 'phantom', 'cosmic', 'storm', 'glacier', 'iron', 'graphite',
    ];

    /**
     * @param array<string,mixed> $row one export line (site, variant_id, product_title, variant_title, brand, attributes, …)
     * @param array{published_siblings?:int} $ctx
     * @return array<string,mixed>
     */
    public static function normalize(array $row, array $ctx = []): array
    {
        $pt = Text::clean((string) ($row['product_title'] ?? ''));
        $vt = Text::clean((string) ($row['variant_title'] ?? ''));
        if ($vt === '') {
            $vt = $pt;
        }
        $brandRaw = Text::clean((string) ($row['brand'] ?? ''));
        $ptl = Text::lower($pt);
        $vtl = Text::lower($vt);

        // working text and the variant-specific residue (what distinguishes the variant from its shell)
        $sqV = Text::squash($vt);
        $sqP = Text::squash($pt);
        if ($sqP !== '' && $sqP !== $sqV && str_contains($sqV, $sqP)) {
            $full = $vtl;
            $residue = trim(preg_replace('/^[\s|\-:,]+|[\s|\-:,]+$/u', '', str_replace($sqP, ' ', $sqV)) ?? '');
        } elseif ($sqP === $sqV || $sqP === '') {
            $full = $vtl;
            $residue = '';
        } else {
            $full = trim($ptl . ' | ' . $vtl);
            $pTok = array_flip(Text::tokens($pt));
            $r = [];
            foreach (Text::tokens($vt) as $t) {
                if (!isset($pTok[$t])) {
                    $r[] = $t;
                }
            }
            $residue = implode(' ', $r);
        }

        $conflicts = [];
        $src = [];

        // ── attributes ────────────────────────────────────────────────────────
        $A = self::parseAttributes((array) ($row['attributes'] ?? []));

        // ── title quantities (order matters; each match is removed from $w) ──
        $w = ' ' . $full . ' ';
        $ratioInTitle = false;
        $w = preg_replace_callback('/\b\d{1,3}\s*%?\s*(?:vg|pg)\b|\b(?:vg|pg)\s*\d{1,3}\s*%?|\b\d{2}\s*\/\s*\d{2}\b/u', function () use (&$ratioInTitle) {
            $ratioInTitle = true;
            return ' ';
        }, $w) ?? $w;
        // "50-50" / "70-30" VG/PG ratios written with a dash (n2.0; they became model number "50")
        $w = preg_replace_callback('/\b(\d{2})\s*-\s*(\d{2})\b(?!\s*(?:mg|ml|%|k\b))/u', function ($m) use (&$ratioInTitle) {
            if ((int) $m[1] + (int) $m[2] !== 100) {
                return $m[0];
            }
            $ratioInTitle = true;
            return ' ';
        }, $w) ?? $w;

        $multiN = null;
        if (preg_match('/\b(\d)\s*-?\s*in\s*-?\s*1\b/u', $w, $m)) {
            $multiN = (int) $m[1];
            $w = self::cut($w, $m[0]);
        }

        // "10 x 10ml" / "3 x 10ml" multiples: pack = N, volume = per-unit ml
        $titlePack = [];
        $titleVol = [];
        $multiplier = null;
        $mulN = null;
        $mulV = null;
        if (preg_match('/\b(\d{1,3})\s*x\s*(\d+(?:\.\d+)?)\s*ml\b/u', $w, $m)) {
            [$mulN, $mulV] = [(int) $m[1], (float) $m[2]];
            $w = self::cut($w, $m[0]);
        } elseif (preg_match('/\b(\d+(?:\.\d+)?)\s*ml\s*x\s*(\d{1,3})\b(?!\s*ml)/u', $w, $m)) {
            [$mulN, $mulV] = [(int) $m[2], (float) $m[1]];
            $w = self::cut($w, $m[0]);
        }
        if ($mulN !== null) {
            if ($mulN >= 2 && $mulN <= 200) {
                $titlePack[] = $mulN;
                $multiplier = $mulN;
            }
            $titleVol[] = $mulV;
        }

        // strength (variant residue first, then the full text)
        [$tStrength, $tStrengthAmb] = self::titleStrength($residue);
        $strengthFrom = 'variant_title';
        if ($tStrength === null && !$tStrengthAmb) {
            [$tStrength, $tStrengthAmb] = self::titleStrength($w);
            $strengthFrom = 'title';
        }
        $w = self::stripStrength($w);

        // volume
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*ml\b/u', $w, $mm)) {
            foreach ($mm[1] as $v) {
                $titleVol[] = (float) $v;
            }
            $w = preg_replace('/(\d+(?:\.\d+)?)\s*ml\b/u', ' ', $w) ?? $w;
        }

        // resistance
        $titleOhm = [];
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:ohms?|Ω)(?![a-z])/u', $w, $mm)) {
            foreach ($mm[1] as $v) {
                $titleOhm[] = (float) $v;
            }
            $w = preg_replace('/(\d+(?:\.\d+)?)\s*(?:ohms?|Ω)(?![a-z])/u', ' ', $w) ?? $w;
        }

        // battery / power / misc units that must not become line numbers
        $w = preg_replace('/\b\d+(?:\.\d+)?\s*(?:mah|w|watts?|v|mm|cm|g|kg|mins?|hrs?|hours?)\b/u', ' ', $w) ?? $w;
        $w = preg_replace('/\b(?:type\s*-?\s*c|usb\s*-?\s*c)\b/u', ' ', $w) ?? $w;
        $w = preg_replace('/\b(?:no\.?|number)\s*\d+\b/u', ' ', $w) ?? $w;   // "No. 1" style names

        // pack
        foreach ([
            '/\bpack\s*of\s*(\d{1,3})\b/u',
            '/\bpack\s+(\d{1,3})\b(?!\s*(?:ml|mg|x\b|k\b|\.\d))/u',   // "(Pack 2)" (n2.0)
            '/\b(\d{1,3})\s*\/\s*pack\b/u',
            '/\b(\d{1,3})\s*-?\s*(?:packs?|pk|pcs|pieces|pce|count)\b/u',
            '/\bbox\s*of\s*(\d{1,3})\b/u',
            '/\b(\d{1,3})\s*(?:sticks|pouches|portions)\b/u',
            '/\(\s*(\d{1,2})\s*(?:x\s*)?pods?\s*\)/u',
            '/\b(\d{1,2})\s*x\s*pods?\b/u',
            '/(?<![a-z0-9])x\s+(\d{1,3})\b(?!\s*(?:ml|mg|k\b))/u',
            '/\b(\d{1,3})\s+x\b(?!\s*\d)/u',
        ] as $i => $re) {
            while (preg_match($re, $w, $m)) {
                $q = (int) $m[1];
                // "N x" forms need N >= 2 ("Kit + 1 x Pod" is a bundle line, not a pack of one)
                if ($q >= ($i >= 6 ? 2 : 1) && $q <= 200) {
                    $titlePack[] = $q;
                }
                $w = self::cut($w, $m[0]);
            }
        }

        // explicit puffs ("600 puffs", "10k puffs", "10,000 puffs", "8000+ puffs")
        $titlePuffs = [];
        $puffNums = [];
        if (preg_match_all('/(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)\s*(k)?\s*\+?\s*puffs?\b/u', $w, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $n = (float) str_replace(',', '', $m[1]);
                if (($m[2] ?? '') === 'k') {
                    $n *= 1000;
                }
                if ($n >= 100 && $n <= 200000) {
                    $titlePuffs[] = (int) round($n);
                    $puffNums[] = (int) round($n);
                }
                $w = self::cut($w, $m[0]);
            }
        }

        // K numbers ("6K", "12k", "2.5k") -> x1000
        $kNums = [];
        if (preg_match_all('/\b(\d{1,3}(?:\.\d)?)\s*k\b\+?/u', $w, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $kNums[] = (int) round(((float) $m[1]) * 1000);
                $w = self::cut($w, $m[0]);
            }
        }

        // remaining numbers are line numbers (model numbers): "bm600" -> 600, "corex 2.0" -> 2, "ivg 2400"
        $lineNums = [];
        foreach ($puffNums as $n) {
            $lineNums[Text::num($n)] = true;
        }
        foreach ($kNums as $n) {
            $lineNums[Text::num($n)] = true;
        }
        $bareNums = [];
        if (preg_match_all('/(?<![a-z0-9.])([a-z]{0,10})(\d+(?:\.\d+)?)(?![0-9.])/u', $w, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $n = (float) $m[2];
                if ($n <= 0 || $n > 200000) {
                    continue;
                }
                $lineNums[Text::num($n)] = true;
                $bareNums[] = $n;
            }
        }
        $lineNums = array_keys($lineNums);
        $lineNums = array_map('strval', $lineNums);
        sort($lineNums, SORT_STRING);

        // ── form ──────────────────────────────────────────────────────────────
        [$form, $formSub, $formSrc] = self::detectForm($full, Text::lower($brandRaw), $A, $titleOhm, $titlePuffs, $kNums);
        if ($formSub === 'conflict') {
            $formSub = null;
            $conflicts[] = 'form:refillable_with_flavour';
        }
        $formClass = $form !== null ? self::FORM_CLASS[$form] : null;
        $isDeviceish = in_array($formClass, ['device', 'pod_refill'], true) || ($form === null && ($A['puffs'] ?? []) !== []);

        // ── strength ─────────────────────────────────────────────────────────
        [$aStrength, $aStrengthAmb] = self::attrValue($A['strength'] ?? [], 'strength');
        $strength = null;
        if ($tStrengthAmb) {
            $conflicts[] = 'strength:title_multi';
        } elseif ($aStrengthAmb && $tStrength === null) {
            $conflicts[] = 'strength:attr_multi';
        }
        if (!$tStrengthAmb) {
            if ($tStrength !== null && $aStrength !== null && abs($tStrength - $aStrength) > 0.05) {
                $conflicts[] = 'strength:title_vs_attr';
            } elseif ($tStrength !== null) {
                $strength = $tStrength;
                $src['strength_mg'] = $aStrength !== null ? $strengthFrom . '+attr' : $strengthFrom;
            } elseif ($aStrength !== null) {
                $strength = $aStrength;
                $src['strength_mg'] = 'attr';
            }
        }

        // ── volume ───────────────────────────────────────────────────────────
        $tv = array_values(array_unique(array_map(fn ($v) => Text::num($v), $titleVol)));
        [$aVol, $aVolAmb] = self::attrValue($A['volume'] ?? [], 'volume');
        $volume = null;
        if (count($tv) > 1) {
            $conflicts[] = 'volume:title_multi';
        } else {
            $t = $tv === [] ? null : (float) $tv[0];
            if ($formClass === 'liquid' && $t !== null && $aVol !== null && $aVol > $t) {
                $aVol = null;   // "100ml shortfill in a 120ml bottle": the title states the fill
            }
            if ($t !== null && $aVol !== null && abs($t - $aVol) > 0.05) {
                $conflicts[] = 'volume:title_vs_attr';
            } elseif ($t !== null) {
                $volume = $t;
                $src['volume_ml'] = $aVol !== null ? 'title+attr' : 'title';
            } elseif ($aVol !== null) {
                $volume = $aVol;
                $src['volume_ml'] = 'attr';
            } elseif ($aVolAmb) {
                $conflicts[] = 'volume:attr_multi';
            }
        }

        // a 50ml+ zero-nicotine e-liquid is a shortfill even when the title does not say so
        if ($form === 'e_liquid' && $volume !== null && $volume >= 50 && ($strength === null || $strength == 0.0)) {
            $form = 'shortfill';
            $formSub = 'inferred_from_volume';
        }

        // ── resistance ───────────────────────────────────────────────────────
        $to = array_values(array_unique(array_map(fn ($v) => Text::num($v), $titleOhm)));
        [$aOhm, $aOhmAmb] = self::attrValue($A['resistance'] ?? [], 'resistance');
        $ohm = null;
        if (count($to) > 1) {
            $conflicts[] = 'resistance:title_multi';
        } else {
            $t = $to === [] ? null : (float) $to[0];
            if ($t !== null && $aOhm !== null && abs($t - $aOhm) > 0.001) {
                $conflicts[] = 'resistance:title_vs_attr';
            } elseif ($t !== null) {
                $ohm = $t;
                $src['resistance_ohm'] = $aOhm !== null ? 'title+attr' : 'title';
            } elseif ($aOhm !== null) {
                $ohm = $aOhm;
                $src['resistance_ohm'] = 'attr';
            } elseif ($aOhmAmb) {
                $conflicts[] = 'resistance:attr_multi';
            }
        }

        // ── pack (NULL when unknown — never 1) ───────────────────────────────
        $tp = array_values(array_unique($titlePack));
        [$aPack, $aPackAmb] = self::attrValue($A['pack'] ?? [], 'pack');
        $pack = null;
        if (count($tp) > 1) {
            $conflicts[] = 'pack:title_multi';
        } else {
            $t = $tp === [] ? null : (int) $tp[0];
            if ($t !== null && $aPack !== null && $t !== (int) $aPack) {
                $conflicts[] = 'pack:title_vs_attr';
            } elseif ($t !== null) {
                $pack = $t;
                $src['pack_units'] = $multiplier !== null ? 'title_multiplier' : ($aPack !== null ? 'title+attr' : 'title');
            } elseif ($aPack !== null) {
                $pack = (int) $aPack;
                $src['pack_units'] = 'attr';
            } elseif ($aPackAmb) {
                $conflicts[] = 'pack:attr_multi';
            }
        }

        // ── puffs ────────────────────────────────────────────────────────────
        [$aPuffs, $aPuffAmb] = self::attrValue($A['puffs'] ?? [], 'puffs');
        $tpu = array_values(array_unique($titlePuffs));
        if ($tpu === [] && $isDeviceish) {
            $tpu = array_values(array_unique($kNums));
        }
        $puffs = null;
        if (count($tpu) > 1) {
            $conflicts[] = 'puffs:title_multi';
        } else {
            $t = $tpu === [] ? null : (int) $tpu[0];
            if ($t !== null && $aPuffs !== null && $t !== (int) $aPuffs) {
                $conflicts[] = 'puffs:title_vs_attr';
            } elseif ($t !== null) {
                $puffs = $t;
                $src['puffs'] = $aPuffs !== null ? 'title+attr' : 'title';
            } elseif ($aPuffs !== null) {
                // attribute typo guard: a bare model number 10x / 0.1x the attribute ("6000" vs Puff Count 600)
                $typo = false;
                foreach ($bareNums as $n) {
                    if ($n >= 100 && ((int) $n === (int) $aPuffs * 10 || (int) $n * 10 === (int) $aPuffs)) {
                        $typo = true;
                    }
                }
                if ($typo) {
                    $conflicts[] = 'puffs:attr_vs_model_number';
                } else {
                    $puffs = (int) $aPuffs;
                    $src['puffs'] = 'attr';
                }
            } elseif ($aPuffAmb) {
                $conflicts[] = 'puffs:attr_multi';
            }
        }
        if ($puffs !== null && !$isDeviceish && in_array($formClass, ['liquid', 'nic_shot', 'coil', 'tank'], true)) {
            $puffs = null;   // "Bar Juice 5000" is a liquid line, not a puff count
            unset($src['puffs']);
        }

        // ── nicotine type ────────────────────────────────────────────────────
        [$nicType, $nicSrc] = self::nicType($full, Text::lower($brandRaw), $A, $form, $strength, $ratioInTitle);

        // ── brand / family ───────────────────────────────────────────────────
        $brandTokens = self::brandTokens($brandRaw);
        $family = self::familyTokens($brandTokens);

        // ── flavour and line text ────────────────────────────────────────────
        [$flavour, $flavourSrc, $lineText, $flavConflict] = self::flavour($pt, $vt, $full, $residue, $A, $brandTokens,
            $brandRaw, $form, $formSub, (array) ($ctx['line_lexicon'] ?? []));
        if ($flavConflict) {
            $conflicts[] = 'flavour:attr_vs_title';
        }
        $colour = null;
        [$aColour, $aColourAmb] = self::attrValue($A['colour'] ?? [], 'colour');
        if ($aColour !== null) {
            $colour = $aColour;
            $src['colour'] = 'attr';
        } elseif ($aColourAmb) {
            $conflicts[] = 'colour:attr_multi';
        } elseif (in_array($form, ['pod_kit', 'kit', 'tank', 'battery', 'accessory', 'refill_pod_cartridge', 'coil'], true)
            && !($form === 'pod_kit' && $formSub === 'prefilled')) {
            $c = self::colourFromTokens(Text::tokens($residue !== '' ? $residue : self::lastSegment($full)));
            if ($c !== null) {
                $colour = $c;
                $src['colour'] = $residue !== '' ? 'variant_title' : 'title_suffix';
            }
        }

        // identity tokens (everything but quantities, form/stop words)
        $idTokens = self::contentTokens($w);
        $lineTokens = $lineText !== null ? self::contentTokens(self::stripQuantities(Text::lower($lineText))) : [];
        // alphanumeric model words of the line ("bm600", "rpm80"): their letters identify the line too
        $lineModels = [];
        if ($lineText !== null) {
            foreach (Text::tokens(self::stripQuantities(Text::lower($lineText))) as $t) {
                if (preg_match('/^[a-z]{2,6}\d+[a-z]{0,2}$/', $t) && !preg_match('/^(?:ml|mg|mah|ohm|ohms|pack|pk|pcs|x)\d/', $t)) {
                    $lineModels[$t] = true;
                }
            }
        }
        $flavSet = $flavour !== null ? array_flip($flavour) : [];
        $mods = [];
        foreach (Text::tokens(self::stripQuantities($full)) as $t) {
            if (in_array($t, self::LINE_MODIFIERS, true) && !isset($flavSet[$t])) {
                $mods[$t] = true;
            }
        }
        $mods = array_keys($mods);
        sort($mods);

        // name tokens for the prescore (sorted, unique: order-free between flavour-first/flavour-last sites)
        $nameTok = [];
        foreach ($idTokens as $t) {
            $nameTok[$t] = true;
        }
        foreach ($lineNums as $n) {
            $nameTok[$n] = true;
        }
        $nameTok = array_keys($nameTok);
        $nameTok = array_map('strval', $nameTok);
        sort($nameTok, SORT_STRING);

        // price (sale only when it is a real discount; VPG holds junk sale prices like 0.01)
        $price = (float) ($row['price'] ?? 0);
        $sale = $row['sale_price'] === null ? 0.0 : (float) $row['sale_price'];
        $unitPrice = ($sale >= 0.05 && ($price <= 0 || $sale < $price)) ? $sale : ($price > 0 ? $price : null);

        // placeholder rule (design A.3 / U4): landing rows, or variable parent-like rows
        $isPlaceholder = false;
        $phWhy = null;
        if ((int) ($row['is_landing'] ?? 0) === 1) {
            $isPlaceholder = true;
            $phWhy = 'landing_row';
        } elseif (($row['product_type'] ?? '') === 'variable'
            && (int) ($ctx['published_siblings'] ?? 0) > 1
            && (int) ($row['is_default'] ?? 0) === 1
            && $sqV === $sqP
            && ($row['barcodes'] ?? []) === []
            && (int) ($row['units_30d'] ?? 0) === 0) {
            $isPlaceholder = true;
            $phWhy = 'parent_rule';
        }

        $conflictFields = [];
        foreach ($conflicts as $c) {
            $conflictFields[explode(':', $c)[0]] = true;
        }

        // bracketed words (old names / descriptors: "Cotton Candy Ice (P&B Cloud)") kept apart for alias checks
        $paren = [];
        $flavAttrText = '';
        foreach ($A['flavour'] ?? [] as $fa) {
            $flavAttrText .= ' ' . $fa['v'];
        }
        if (preg_match_all('/\(([^)]*)\)/u', Text::lower($pt . ' ' . $vt . ' ' . $flavAttrText), $pm)) {
            foreach ($pm[1] as $inner) {
                foreach (Text::tokens($inner) as $tok) {
                    $paren[$tok] = true;
                }
            }
        }

        return [
            'site' => (string) ($row['site'] ?? ''),
            'variant_id' => (int) ($row['variant_id'] ?? 0),
            'product_id' => (int) ($row['product_id'] ?? 0),
            'title' => $vt,
            'product_title' => $pt,
            'brand_raw' => $brandRaw,
            'brand_key' => implode(' ', $brandTokens),
            'brand_family' => $family,
            'form' => $form,
            'form_class' => $formClass,
            'form_sub' => $formSub,
            'strength_mg' => $strength,
            'nic_type' => $nicType,
            'volume_ml' => $volume,
            'pack_units' => $pack,
            'listing_multiplier' => $multiplier,
            'puffs' => $puffs,
            'resistance_ohm' => $ohm,
            'colour' => $colour,
            'multi_n' => $multiN,
            'line_numbers' => $lineNums,
            'line_modifiers' => $mods,
            'line_tokens' => $lineTokens,
            'line_models' => array_map('strval', array_keys($lineModels)),
            'flavour_tokens' => $flavour,
            'flavour_src' => $flavourSrc,
            'id_tokens' => $idTokens,
            'paren_tokens' => array_map('strval', array_keys($paren)),
            'full_tokens' => Text::tokens($full . ' ' . self::attrText($A)),
            'name_tokens' => $nameTok,
            'name_norm' => implode(' ', $nameTok),
            'unit_price' => $unitPrice,
            'src' => $src + ['form' => $formSrc, 'nic_type' => $nicSrc],
            'internal_conflicts' => $conflicts,
            'conflict_fields' => array_keys($conflictFields),
            'is_placeholder' => $isPlaceholder,
            'placeholder_reason' => $phWhy,
            'normalizer' => self::VERSION,
        ];
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private static function cut(string $w, string $match): string
    {
        return preg_replace('/' . preg_quote($match, '/') . '/u', ' ', $w, 1) ?? $w;
    }

    /** @return array{0:?float,1:bool} [value, ambiguous] */
    private static function titleStrength(string $s): array
    {
        if ($s === '') {
            return [null, false];
        }
        $vals = [];
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*mg(?:\s*\/\s*ml)?(?![a-z])/u', $s, $mm)) {
            foreach ($mm[1] as $v) {
                $vals[Text::num($v)] = true;
            }
        }
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*%(?!\s*(?:vg|pg))/u', $s, $mm)) {
            foreach ($mm[1] as $v) {
                if ((float) $v <= 5.0) {
                    $vals[Text::num(((float) $v) * 10)] = true;
                }
            }
        }
        if ($vals === [] && preg_match('/\bnic(?:otine)?\s*salts?\s*(\d{1,2}(?:\.\d)?)\b(?!\s*(?:ml|k\b|puff))/u', $s, $m)) {
            $vals[Text::num($m[1])] = true;
        }
        if (preg_match('/\b(?:zero\s*nic\w*|zero\s*nicotine|nicotine[\s-]*free|nic[\s-]*free|no\s*nicotine|0\s*nic(?:otine)?)\b/u', $s)) {
            $vals['0'] = true;
        }
        if ($vals === []) {
            return [null, false];
        }
        if (count($vals) > 1) {
            return [null, true];
        }
        return [(float) array_key_first($vals), false];
    }

    private static function stripStrength(string $w): string
    {
        $w = preg_replace('/(\d+(?:\.\d+)?)\s*mg(?:\s*\/\s*ml)?(?![a-z])/u', ' ', $w) ?? $w;
        $w = preg_replace('/(\d+(?:\.\d+)?)\s*%(?!\s*(?:vg|pg))/u', ' ', $w) ?? $w;
        $w = preg_replace('/\b(?:zero\s*nic\w*|zero\s*nicotine|nicotine[\s-]*free|nic[\s-]*free|no\s*nicotine|0\s*nic(?:otine)?)\b/u', ' ', $w) ?? $w;
        return $w;
    }

    /** Remove every quantity pattern (used for line/flavour text). */
    public static function stripQuantities(string $s): string
    {
        $s = ' ' . $s . ' ';
        $s = preg_replace('/\b\d{1,3}\s*%?\s*(?:vg|pg)\b|\b\d{2}\s*\/\s*\d{2}\b/u', ' ', $s) ?? $s;
        $s = preg_replace_callback('/\b(\d{2})\s*-\s*(\d{2})\b(?!\s*(?:mg|ml|%|k\b))/u',
            fn ($m) => (int) $m[1] + (int) $m[2] === 100 ? ' ' : $m[0], $s) ?? $s;
        $s = preg_replace('/\b\d\s*-?\s*in\s*-?\s*1\b/u', ' ', $s) ?? $s;
        $s = self::stripStrength($s);
        $s = preg_replace('/(\d+(?:\.\d+)?)\s*(?:ml|ohms?|mah|w|k|puffs?)\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/\bpack\s*of\s*\d+|\bpack\s+\d+\b|\b\d+\s*\/\s*pack\b|\b\d+\s*-?\s*packs?\b|\b\d+\s*x\b|\bx\s*\d+\b/u', ' ', $s) ?? $s;
        return $s;
    }

    /** Content tokens: no numbers, no stop/form words. @return list<string> */
    private static function contentTokens(string $s): array
    {
        $out = [];
        foreach (Text::tokens($s) as $t) {
            if (strlen($t) < 2 || preg_match('/\d/', $t) || in_array($t, self::STOPWORDS, true)) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * Attributes grouped by meaning. ALT attr "Strength" (id 4) also holds ohms ("1.5 Ohm"): split by value.
     *
     * @param list<array<string,mixed>> $attrs
     * @return array<string, list<array{v:string,var:int,name:string}>>
     */
    public static function parseAttributes(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $a) {
            $name = Text::lower(Text::clean((string) ($a['name'] ?? '')));
            $val = Text::clean((string) ($a['value'] ?? ''));
            $vl = Text::lower($val);
            if ($val === '' || preg_match('/^(?:choose an?\b|select\b|please\s+select|-+$|n\/?a$)/u', $vl)) {
                continue;
            }
            $sem = 'other';
            if (preg_match('/battery|charging|made in|flavou?r\s*profile|prominent|available/u', $name)) {
                $sem = 'other';
            } elseif (preg_match('/nicotine\s*strength|^strength:?$|pouch\s*strength|^nic\s*shot$/u', $name)) {
                $sem = preg_match('/ohm|Ω/u', $vl) ? 'resistance' : 'strength';
                if ($name === 'nic shot' && !preg_match('/\d\s*mg/u', $vl)) {
                    $sem = 'other';
                }
            } elseif (preg_match('/puff/u', $name)) {
                $sem = 'puffs';
            } elseif (preg_match('/bottle\s*size|e-?\s*liquid\s*capacity|tank\s*capacity|pod\s*size|^size:?$|^capacity$/u', $name)
                && !preg_match('/pouch/u', $name)) {
                $sem = 'volume';
            } elseif (preg_match('/\bpack\b|pack:|pack size/u', $name)) {
                $sem = 'pack';
            } elseif (preg_match('/colou?r/u', $name)) {
                $sem = 'colour';
            } elseif (preg_match('/^(?:e\s*liquid\s*)?flavou?rs?:?$|^pouch\s*flavou?rs?$/u', $name)) {
                $sem = 'flavour';
            } elseif (preg_match('/ohm|resistance/u', $name)) {
                $sem = 'resistance';
            } elseif (preg_match('/vg\s*\/\s*pg|pg\s*\/\s*vg/u', $name)) {
                $sem = 'vgpg';
            }
            $out[$sem][] = ['v' => $val, 'var' => (int) ($a['is_variable'] ?? 0), 'name' => $name];
        }
        return $out;
    }

    /** Attribute text for full-text cross checks (flavour and colour values only). */
    private static function attrText(array $A): string
    {
        $parts = [];
        foreach (['flavour', 'colour'] as $k) {
            foreach ($A[$k] ?? [] as $r) {
                $parts[] = $r['v'];
            }
        }
        return Text::lower(implode(' ', $parts));
    }

    /**
     * Single attribute value: is_variable=1 rows win; otherwise the only distinct value; several -> ambiguous.
     *
     * @param list<array{v:string,var:int,name:string}> $rows
     * @return array{0:mixed,1:bool}
     */
    private static function attrValue(array $rows, string $kind): array
    {
        if ($rows === []) {
            return [null, false];
        }
        $var = array_values(array_filter($rows, fn ($r) => $r['var'] === 1));
        $use = $var !== [] ? $var : $rows;
        $vals = [];
        foreach ($use as $r) {
            $p = self::parseAttrValue($r['v'], $kind);
            if ($p !== null) {
                $vals[is_float($p) || is_int($p) ? Text::num($p) : (string) $p] = $p;
            }
        }
        if ($vals === []) {
            return [null, false];
        }
        if (count($vals) > 1) {
            return [null, true];
        }
        return [array_values($vals)[0], false];
    }

    private static function parseAttrValue(string $v, string $kind): mixed
    {
        $vl = Text::lower($v);
        switch ($kind) {
            case 'strength':
                if (preg_match('/(\d+(?:\.\d+)?)\s*mg/u', $vl, $m)) {
                    return (float) $m[1];
                }
                if (preg_match('/(\d+(?:\.\d+)?)\s*%/u', $vl, $m) && (float) $m[1] <= 5.0) {
                    return (float) $m[1] * 10;
                }
                if (preg_match('/^(\d+(?:\.\d+)?)$/u', $vl, $m)) {
                    return (float) $m[1];
                }
                if (preg_match('/zero|free|^0\b/u', $vl)) {
                    return 0.0;
                }
                return null;
            case 'volume':
                if (preg_match('/(\d+(?:\.\d+)?)\s*ml/u', $vl, $m) || preg_match('/^(\d+(?:\.\d+)?)$/u', $vl, $m)) {
                    return (float) $m[1];
                }
                return null;
            case 'puffs':
                $vl = str_replace(',', '', $vl);
                if (preg_match('/(\d+(?:\.\d+)?)\s*k\b/u', $vl, $m)) {
                    return (int) round((float) $m[1] * 1000);
                }
                if (preg_match('/(\d{3,6})/u', $vl, $m)) {
                    return (int) $m[1];
                }
                return null;
            case 'pack':
                if (preg_match('/\bsingle\b/u', $vl)) {
                    return 1;
                }
                if (preg_match('/(\d{1,3})/u', $vl, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 200) {
                    return (int) $m[1];
                }
                return null;
            case 'resistance':
                if (preg_match('/(\d+(?:\.\d+)?)\s*(?:ohm|Ω)?/u', $vl, $m)) {
                    return (float) $m[1];
                }
                return null;
            case 'text':
                $t = trim(preg_replace('/\s+/u', ' ', $vl) ?? $vl);
                return $t === '' ? null : $t;
            case 'colour':
                $t = [];
                foreach (Text::tokens($vl) as $tok) {
                    if (!in_array($tok, ['kit', 'edition', 'colour', 'color', 'the'], true)) {
                        $t[] = $tok === 'grey' ? 'gray' : $tok;
                    }
                }
                return $t === [] ? null : implode(' ', $t);
        }
        return null;
    }

    /**
     * @param array<string, list<array{v:string,var:int,name:string}>> $A
     * @return array{0:?string,1:?string,2:?string} [form, form_sub, source]
     */
    private static function detectForm(string $full, string $brand, array $A, array $ohm, array $puffs, array $kNums): array
    {
        $t = ' ' . $full . ' ';
        // brand/line names that contain form words ("Juicy Pod", "Pyne Pod", "Pod Salt", "PodBar") must not decide the form
        $t = preg_replace('/\b(?:juicy|pyne|nexus)\s+pod\b|\bpod\s+salt\b|\bpod\s*mate\b|\bpodbar\b|\bpod\s*fill\b/u', ' ', $t) ?? $t;
        if ($brand !== '' && preg_match('/\bpods?\b|\bkits?\b/u', $brand)) {
            $b = trim(preg_replace('/\s+/u', ' ', $brand) ?? $brand);
            $t = str_replace(' ' . $b . ' ', ' ', $t);
        }
        $hasFlavourAttr = ($A['flavour'] ?? []) !== [];
        $hasPuffAttr = ($A['puffs'] ?? []) !== [];
        $prefilledEvidence = $puffs !== [] || $hasPuffAttr || $kNums !== [] || $hasFlavourAttr;
        $prefilled = (bool) preg_match('/\bpre[\s-]?filled\b/u', $t);
        $liquidWord = (bool) preg_match('/\be[\s-]*liquids?\b|\beliquids?\b|\bejuice\b|\bvape\s*juice\b|\bnic(?:otine)?\s*salts?\b|\bnicsalts?\b|\bsalts?\b/u', $t);
        $mlBottle = (bool) preg_match('/\b(?:10|20|30|50|60|100|120|200)\s*ml\b/u', $t);
        $podsPlural = (bool) preg_match('/\bpods\b|\bcartridges\b|\brefill\s*pack\b/u', $t);

        if (preg_match('/\bpouch(?:es)?\b|\bsnus\b/u', $t) || str_contains($brand, 'pouches')) {
            return ['other', 'nicotine_pouch', 'title'];
        }
        if (preg_match('/\bterea\b|\bheets\b|\bsticks\b|\bneostiks?\b|\bevo\s*sticks\b/u', $t)) {
            return ['other', 'heated_tobacco', 'title'];
        }
        if (preg_match('/\bstrips\b/u', $t) || str_contains($brand, 'strips')) {
            return ['other', 'nicotine_strip', 'title'];
        }
        if (preg_match('/\bnic(?:otine)?\s*shots?\b|\bnic\s*booster|\bbooster\s*shots?\b|\bice\s*shots?\b/u', $t)) {
            return ['nic_shot', null, 'title'];
        }
        if (preg_match('/\bshort\s*-?\s*fills?\b|\blong\s*-?\s*fills?\b|\bshake\s*(?:n|and|&)\s*vape\b/u', $t)) {
            return ['shortfill', preg_match('/long\s*-?\s*fill/u', $t) ? 'longfill' : null, 'title'];
        }
        if ($liquidWord && ($mlBottle || !preg_match('/\bpods?\b|\bkits?\b|\bdisposable\b|\bpuffs?\b/u', $t))) {
            if (preg_match('/\bnic(?:otine)?\s*salts?\b|\bnicsalts?\b|\bsalts?\b/u', $t)) {
                return ['nic_salt', null, 'title'];
            }
            return ['e_liquid', null, 'title'];
        }
        // coils: plural "coils" wins unless the coils are an extra of a kit/tank ("Tank with 2 coils")
        if ((preg_match('/\bcoils\b/u', $t) && !preg_match('/\b(?:tank|kit|mod)\b.*\b(?:with|incl\w*|\+)\b.*\bcoils\b/u', $t))
            || (preg_match('/\bcoil\b/u', $t) && !preg_match('/\bkits?\b|\btanks?\b|\bpods?\b/u', $t))) {
            return ['coil', null, 'title'];
        }
        // device kits
        if (preg_match('/\bpod\s*(?:vape\s*)?kits?\b|\bpod\s*system\b|\breload\s*kit\b|\bpod\s*mod\b/u', $t)
            || ($prefilled && preg_match('/\bkits?\b/u', $t))
            || (!$podsPlural && preg_match('/\bkits?\b/u', $t) && !preg_match('/\btanks?\b/u', $t))) {
            $sub = null;
            if (preg_match('/\brefillable\b|\bopen\s*pod\b/u', $t)) {
                // "Refillable" next to a flavour/puff count contradicts itself: leave prefilled-vs-refillable unknown
                $sub = ($prefilled || $prefilledEvidence) ? 'conflict' : 'refillable';
            } elseif ($prefilled || $prefilledEvidence) {
                $sub = 'prefilled';
            }
            $isPod = (bool) preg_match('/\bpods?\b|\breload\b/u', $t) || $sub === 'prefilled';
            return [$isPod ? 'pod_kit' : 'kit', $sub, 'title'];
        }
        // pods / cartridges: prefilled vs refillable is decided only on explicit evidence
        if (preg_match('/\bpods?\b|\bcartridges?\b|\brefill\s*pack\b/u', $t)) {
            if (preg_match('/\b(?:empty|refillable)\s+(?:replacement\s+)?(?:pods?|cartridges?)\b/u', $t)) {
                return ($prefilled || $prefilledEvidence) ? ['prefilled_pod', 'conflict', 'title'] : ['refill_pod_cartridge', 'refillable', 'title'];
            }
            if ($prefilled || preg_match('/\brefill\s*(?:pods?|pack)\b/u', $t) || $prefilledEvidence) {
                return ['prefilled_pod', 'prefilled', 'title'];
            }
            if ($ohm !== [] || ($A['resistance'] ?? []) !== []) {
                return ['refill_pod_cartridge', 'refillable', 'title'];
            }
            if (preg_match('/\breplacement\s+pods?\b|\bcartridges?\b/u', $t)) {
                return ['refill_pod_cartridge', null, 'title'];
            }
            return ['prefilled_pod', null, 'title'];
        }
        if (preg_match('/\bdisposable\b|\bpuff\s*bar\b|\bprefilled\s+vapes?\b|\bpre[\s-]?filled\s+vapes?\b/u', $t)) {
            return ['disposable', 'prefilled', 'title'];
        }
        if (preg_match('/\btanks?\b|\batomi[sz]ers?\b|\brt?da\b|\brdta\b|\bclearomi[sz]ers?\b|\bsub\s*ohm\s*tank\b/u', $t)
            && !preg_match('/\bkits?\b/u', $t)) {
            return ['tank', null, 'title'];
        }
        if (preg_match('/\bkits?\b|\bmods?\b|\bstarter\b/u', $t)) {
            return ['kit', ($prefilled || $puffs !== [] || $hasPuffAttr) ? 'prefilled' : null, 'title'];
        }
        if (preg_match('/\bbatter(?:y|ies)\b|\b18650\b|\b21700\b|\b20700\b/u', $t)) {
            return ['battery', null, 'title'];
        }
        if (!$prefilledEvidence && preg_match('/\bchargers?\b|\bcases?\b|\bdrip\s*tips?\b|\blanyards?\b|\bcotton\b(?!\s*(?:candy|k\b))|\bglass\b|\bwires?\b|\bcables?\b|\bstands?\b|\bbags?\b|\bskins?\b|\bsleeves?\b|\bmouthpieces?\b|\bo-?rings?\b|\bempty\s+bottles?\b|\btools?\b/u', $t)) {
            return ['accessory', null, 'title'];
        }
        // attribute-only evidence
        if ($liquidWord || ($A['vgpg'] ?? []) !== [] && ($A['volume'] ?? []) !== [] && !$hasPuffAttr) {
            if ($liquidWord && preg_match('/\bsalts?\b/u', $t)) {
                return ['nic_salt', null, 'title'];
            }
            return ['e_liquid', null, 'attr'];
        }
        if ($hasPuffAttr || $puffs !== [] || $kNums !== []) {
            return ['disposable', 'prefilled', $hasPuffAttr ? 'attr' : 'title'];
        }
        return [null, null, null];
    }

    /** @return array{0:?string,1:?string} */
    private static function nicType(string $full, string $brand, array $A, ?string $form, ?float $strength, bool $ratioInTitle): array
    {
        if ($form === 'shortfill') {
            return ['shortfill', 'form'];
        }
        if ($form === 'nic_shot') {
            return ['nic_shot', 'form'];
        }
        if (!in_array($form, ['e_liquid', 'nic_salt'], true)) {
            return [null, null];
        }
        $t = ' ' . $full . ' ';
        $saltTitle = (bool) preg_match('/\bnic(?:otine)?\s*salts?\b|\bnicsalts?\b|\bsalts?\b/u', $t);
        $freeTitle = (bool) preg_match('/\bfree\s*base\b|\bfreebase\b/u', $t);
        if ($saltTitle && $freeTitle) {
            return [null, 'conflict'];
        }
        if ($strength !== null && $strength == 0.0) {
            return ['zero', 'strength'];
        }
        if ($freeTitle) {
            return ['freebase', 'title'];
        }
        if ($saltTitle) {
            return ['salt', 'title'];
        }
        if (preg_match('/\bsalts?\b/u', $brand)) {
            return ['salt', 'brand'];
        }
        if ($strength !== null && in_array(Text::num($strength), ['3', '6', '12', '18'], true)) {
            return ['freebase', 'strength_rule'];
        }
        if ($ratioInTitle) {
            return ['freebase', 'title_ratio'];
        }
        return [null, null];
    }

    /** @return list<string> */
    public static function brandTokens(string $brandRaw): array
    {
        $b = Text::lower($brandRaw);
        $b = preg_replace('/\br\s*(?:and|&|n)\s*m\b/u', 'randm', $b) ?? $b;
        $out = [];
        $all = [];
        foreach (Text::tokens($b) as $t) {
            if (ctype_digit($t)) {
                continue;
            }
            $all[$t] = true;
            if (!in_array($t, self::BRAND_GENERIC, true)) {
                $out[] = $t;
            }
        }
        // a brand made only of generic words ("Nic Nic") keeps its own words
        return $out !== [] ? array_values(array_unique($out)) : array_keys($all);
    }

    /** @param list<string> $brandTokens @return list<string> */
    public static function familyTokens(array $brandTokens): array
    {
        $f = array_values(array_filter($brandTokens, fn ($t) => !in_array($t, self::BRAND_WEAK, true) && strlen($t) >= 2));
        if ($f === [] && $brandTokens !== []) {
            $f = [$brandTokens[0]];
        }
        return array_values(array_unique($f));
    }

    private static function lastSegment(string $full): string
    {
        $parts = preg_split('/\s[|\-]\s/u', $full) ?: [$full];
        return count($parts) > 1 ? (string) end($parts) : '';
    }

    /** @param list<string> $tokens */
    private static function colourFromTokens(array $tokens): ?string
    {
        $tokens = array_values(array_filter($tokens, fn ($t) => !in_array($t, ['kit', 'edition', 'colour', 'color', 'the', 'and'], true)));
        if ($tokens === []) {
            return null;
        }
        $hasColour = false;
        foreach ($tokens as $t) {
            if (in_array($t, self::COLOURS, true)) {
                $hasColour = true;
            } elseif (!in_array($t, self::COLOUR_QUALIFIERS, true)) {
                return null;
            }
        }
        if (!$hasColour) {
            return null;
        }
        return implode(' ', array_map(fn ($t) => $t === 'grey' ? 'gray' : $t, $tokens));
    }

    /**
     * Flavour tokens with a source, the line text they were separated from, and an attr-vs-title conflict flag.
     * Sources in order of trust: attr > variant_residue > title_by_pattern > title_segment > title_pattern; among the
     * middle three, one whose words hold no seed flavour word (Flavour::WORDS) yields to a later one that does.
     *
     * title_by_pattern  "<Flavour> Nic Salt E-Liquid by <Line> 10ml | 20mg" (Vape and Go)
     * title_segment     the last " - " / " | " segment that still holds words once quantities are removed:
     *                   "<Line> - <Flavour>", and "<Line> Nic Salts - <Flavour> - 10ml - 10mg" (n2.0: the old rule
     *                   only looked at the very last segment, so the flavour of such titles was lost and the
     *                   residual produced words like "crystal"). It beats the by-pattern only when the by-pattern's
     *                   words hold no seed flavour word ("Elfliq Nic Salt by Elf Bar - Strawberry Ice Cream - 10ml").
     * title_pattern     no separator at all: TitlePattern::split() (Electrofag "<Line> <Flavour> 10ml Nic Salt E
     *                   Liquid" and "<Flavour> <Line> Nic Salt 10ml"), flavoured forms only.
     *
     * @param list<string> $lexicon line lexicon of this listing's brand on its site
     * @return array{0:?list<string>,1:?string,2:?string,3:bool}
     */
    private static function flavour(string $pt, string $vt, string $full, string $residue, array $A, array $brandTokens,
        string $brandRaw = '', ?string $form = null, ?string $formSub = null, array $lexicon = []): array
    {
        $clean = function (string $s) use ($brandTokens): array {
            $s = preg_replace('/\([^)]*\)/u', ' ', $s) ?? $s;          // parenthetical descriptors
            $s = self::stripQuantities(Text::lower($s));
            $out = [];
            foreach (Text::tokens($s) as $t) {
                if (preg_match('/\d/', $t) || in_array($t, self::FLAVOUR_NOISE, true) || in_array($t, $brandTokens, true)) {
                    continue;
                }
                $out[] = $t;
            }
            return $out;
        };
        $hasSeedWord = function (array $toks): bool {
            foreach ($toks as $t) {
                if (Flavour::isConstWord((string) $t)) {
                    return true;
                }
            }
            return false;
        };

        $attr = null;
        [$av, $amb] = self::attrValue($A['flavour'] ?? [], 'text');
        if ($av !== null) {
            $attr = $clean((string) $av);
            if ($attr === []) {
                $attr = null;
            }
        }
        $res = $residue !== '' ? $clean($residue) : [];
        $byFlav = null;
        $byLine = null;
        if (preg_match('/^(.*?)\s*\b(?:nic(?:otine)?\s*salts?|nicsalts?|e[\s-]*liquids?|eliquids?|shortfill|freebase|vape\s*juice|salts?)\b.*?\bby\b\s+(.+)$/u', $full, $m)) {
            $f = $clean($m[1]);
            if ($f !== []) {
                $byFlav = $f;
                $byLine = $m[2];
            }
        }
        $seg = null;
        $segLine = null;
        $parts = preg_split('/\s[|\-]\s|\s\|/u', $full) ?: [];
        for ($i = count($parts) - 1; $i >= 1; $i--) {
            $f = $clean((string) $parts[$i]);
            if ($f !== []) {
                $seg = $f;
                $segLine = implode(' ', array_slice($parts, 0, $i));
                break;
            }
        }

        if ($attr !== null) {
            // the variant words of the title must name the same flavour as the attribute (design S1: disagreement -> unknown)
            if ($res !== [] && !self::allColourish($res) && Flavour::clean($res) !== [] && !Flavour::same($attr, $res)) {
                return [null, null, $pt, true];
            }
            return [$attr, 'attr', $pt, false];
        }
        // residue > by-pattern > segment, except that a source whose words hold no seed flavour word yields to a
        // later one that does ("Hayati Crystal Pro Max Nic Salts - Fresh Menthol Mojito" under the product title
        // "Fresh Menthol Mojito Nic Salt E-liquid by Hayati Pro Max": the residue is "crystal", a line word)
        if ($byFlav !== null && $seg !== null && !$hasSeedWord($byFlav) && $hasSeedWord($seg)) {
            $byFlav = null;
        }
        $ordered = [];
        if ($res !== []) {
            $ordered[] = [$res, 'variant_residue', $pt];
        }
        if ($byFlav !== null) {
            $ordered[] = [$byFlav, 'title_by_pattern', $byLine];
        }
        if ($seg !== null) {
            $ordered[] = [$seg, 'title_segment', $segLine];
        }
        if ($ordered !== []) {
            $pick = $ordered[0];
            if (!$hasSeedWord($pick[0])) {
                foreach ($ordered as $o) {
                    if ($hasSeedWord($o[0])) {
                        $pick = $o;
                        break;
                    }
                }
            }
            return [$pick[0], $pick[1], $pick[2], false];
        }
        $flavoured = in_array($form, ['e_liquid', 'nic_salt', 'shortfill', 'nic_shot', 'disposable', 'prefilled_pod'], true)
            || ($form === 'pod_kit' && $formSub === 'prefilled');
        if ($flavoured) {
            $sp = TitlePattern::split(TitlePattern::headTokens($full), TitlePattern::anchorTokens($brandRaw), $lexicon);
            if ($sp !== null) {
                $f = $clean(implode(' ', $sp['flavour']));
                if ($f !== []) {
                    return [$f, 'title_pattern', implode(' ', $sp['line']), false];
                }
            }
        }
        return [null, null, null, false];
    }

    /** @param list<string> $t */
    private static function allColourish(array $t): bool
    {
        foreach ($t as $x) {
            if (!in_array($x, self::COLOURS, true) && !in_array($x, self::COLOUR_QUALIFIERS, true)) {
                return false;
            }
        }
        return true;
    }
}
