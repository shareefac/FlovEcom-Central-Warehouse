<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Hard vetoes and soft flags for one proposed pair (matching design A.6). Plain PHP the AI cannot override.
 *
 * A veto fires only when the field is known on BOTH sides and each side is internally consistent
 * (an internally conflicting field is null in the features, so it can never veto; it raises the soft
 * flag `internal_conflict` instead when the other side knows the field). A veto costs recall, never
 * precision.
 *
 * $l = listing features (the channel listing being linked), $s = central item features
 * (a provisional central id seeded from a Vape and Go listing). Both come from Normalizer::normalize().
 */
final class Veto
{
    /**
     * v2.0 (pilot-1 fixes): residual keeps the other side's line words that are flavour words; generic and
     * umbrella words ("salt", "pod", "bar") no longer make two brands/lines agree; a model number on one side
     * only is flagged; new vetoes line_word (each side has a line word the other lacks) and multipack
     * ("10 x 10ml" against a single unit); volume is n_a on pods/devices like nic_type.
     */
    public const VERSION = 'v2.0';

    public const CODES = [
        'strength', 'nic_type', 'form', 'line_number', 'line_modifier', 'line_word', 'flavour_superset', 'flavour_diff',
        'liquid_ml', 'puffs', 'colour', 'ohm', 'pack', 'multipack', 'placeholder', 'sku_state',
    ];

    public const SOFT_FLAGS = [
        'price_outlier', 'target_not_published', 'internal_conflict', 'relabelled_line_unconfirmed',
        'strength_missing', 'modifier_extra', 'flavour_extra', 'line_number_extra', 'line_number_one_side',
        'line_alias_pending', 'colour_extra', 'volume_diff_attr', 'pack_one_side', 'listing_multiplier',
        // lane-level soft flags added by the first-match tool
        'same_channel_target_shared', 'gtin_also_on_inactive_item',
    ];

    /** Retail/form words that never identify a brand or line (VPG "Pod Salt Nic Salts" must not match every salt). */
    public const GENERIC_LINE_WORDS = ['salt', 'salts', 'pod', 'pods', 'nic', 'nicotine', 'e', 'liquid', 'liquids', 'eliquid', 'eliquids', 'vape', 'vapes'];

    /** Umbrella words shared by unrelated lines ("Bar Juice 5000", "Bar Salts", "Vapes Bar Ghost", "Vapes Bars"). */
    public const UMBRELLA_LINE_WORDS = ['bar', 'bars'];

    /** Descriptive words in line text that do not name a line (for the line_word veto). */
    public const LINE_DESCRIPTORS = [
        'series', 'version', 'edition', 'edtn', 'limited', 'special', 'mesh', 'meshed', 'cartridge', 'cartridges', 'empty',
        'top', 'fill', 'refillable', 'replacement', 'starter', 'set', 'box', 'mod', 'tank', 'coil', 'coils', 'device', 'kit',
        'kits', 'bottle', 'shot', 'shots', 'shortfills', 'longfill', 'fills', 'range', 'collection', 'official',
        'uk', 'tpd', 'compliant', 'ready', 'to', 'go', 'flavours', 'flavors', 'bundle', 'deal', 'offer',
        'multipack', 'pouches', 'pouch', 'strips', 'sticks', 'co', 'company', 'ltd', 'brand', 'labs', 'lab',
    ];

    /**
     * Line aliases proposed from barcode pairs, awaiting the mapping lead (pilot-1 recommendation 8). A line_word
     * mismatch fully explained by one of these is flagged line_alias_pending (never Key, routed to Can't tell)
     * instead of vetoed. Once confirmed, pass ctx confirmed_alias => true.
     */
    public const PENDING_LINE_ALIASES = [
        ['a' => ['crystal'], 'b' => ['hayati'], 'note' => 'Electrofag "Crystal Pro Max" = Vape and Go "Hayati Pro Max"'],
        ['a' => ['oxbar'], 'b' => ['oxva'], 'note' => 'Oxbar = Oxva'],
        ['a' => ['original'], 'b' => ['bar'], 'note' => 'SKE Crystal Original = SKE Crystal Bar'],
        ['a' => ['echo'], 'b' => ['eco'], 'note' => 'Bash Echo = Bash Eco'],
    ];

    /** Switch for the line_word veto (kept only while its false-veto rate on barcode pairs is <= 0.5%). */
    public const LINE_WORD_VETO = true;

    /**
     * @param array<string,mixed> $l listing features
     * @param array<string,mixed> $s central item features
     * @param array{s_in_seed?:bool,s_product_published?:bool,s_strength_sibling?:bool,confirmed_alias?:bool} $ctx
     * @return array{vetoes:list<array{code:string,detail:string}>,flags:list<string>,fields:array<string,string>}
     */
    public static function check(array $l, array $s, int $units = 1, array $ctx = []): array
    {
        $v = [];
        $f = [];
        $fields = [];
        $veto = function (string $code, string $detail) use (&$v): void {
            $v[] = ['code' => $code, 'detail' => $detail];
        };
        $flag = function (string $code) use (&$f): void {
            $f[$code] = true;
        };
        $lc = array_flip($l['conflict_fields'] ?? []);
        $sc = array_flip($s['conflict_fields'] ?? []);
        $hidden = function (string $field, mixed $lv, mixed $sv) use ($lc, $sc, $flag): void {
            if ((isset($lc[$field]) && $sv !== null) || (isset($sc[$field]) && $lv !== null)) {
                $flag('internal_conflict');
            }
        };

        // placeholder / sku state
        if (!empty($l['is_placeholder']) || !empty($s['is_placeholder'])) {
            $veto('placeholder', !empty($l['is_placeholder']) ? 'listing is a placeholder' : 'central item is a placeholder');
        }
        if (array_key_exists('s_in_seed', $ctx) && $ctx['s_in_seed'] === false) {
            $veto('sku_state', 'central item not active (not in the seed)');
        }

        // strength
        $ls = $l['strength_mg'];
        $ss = $s['strength_mg'];
        if ($ls !== null && $ss !== null) {
            if (abs((float) $ls - (float) $ss) > 0.05) {
                $veto('strength', Text::num($ls) . 'mg vs ' . Text::num($ss) . 'mg');
                $fields['strength'] = 'conflict';
            } else {
                $fields['strength'] = 'agree';
            }
        } else {
            $fields['strength'] = 'unknown';
            $hidden('strength', $ls, $ss);
            if ($ls === null && $ss !== null && !empty($ctx['s_strength_sibling'])) {
                $flag('strength_missing');
            }
        }

        // nicotine type
        $ln = $l['nic_type'];
        $sn = $s['nic_type'];
        if ($ln !== null && $sn !== null) {
            if ($ln !== $sn) {
                $veto('nic_type', $ln . ' vs ' . $sn);
                $fields['nic_type'] = 'conflict';
            } else {
                $fields['nic_type'] = 'agree';
            }
        } else {
            $fields['nic_type'] = self::na($l, $s, ['liquid', 'nic_shot']) ? 'n_a' : 'unknown';
        }

        // form
        $lf = $l['form_class'];
        $sf = $s['form_class'];
        if ($lf !== null && $sf !== null) {
            $fields['form'] = 'agree';
            if ($lf !== $sf) {
                $veto('form', $l['form'] . ' vs ' . $s['form']);
                $fields['form'] = 'conflict';
            } elseif (in_array($l['form_sub'], ['prefilled', 'refillable'], true) && in_array($s['form_sub'], ['prefilled', 'refillable'], true)
                && $l['form_sub'] !== $s['form_sub']) {
                $veto('form', $l['form'] . '/' . $l['form_sub'] . ' vs ' . $s['form'] . '/' . $s['form_sub']);
                $fields['form'] = 'conflict';
            } elseif ($lf === 'other' && $l['form_sub'] !== null && $s['form_sub'] !== null && $l['form_sub'] !== $s['form_sub']) {
                $veto('form', $l['form_sub'] . ' vs ' . $s['form_sub']);
                $fields['form'] = 'conflict';
            }
        } else {
            $fields['form'] = 'unknown';
        }
        $hidden('form', $l['form'], $s['form']);

        // line number (after K expansion) and N-in-1
        $brandLine = 'unknown';
        $ln = $l['line_numbers'] ?? [];
        $sn = $s['line_numbers'] ?? [];
        if ($ln !== [] && $sn !== []) {
            $xa = array_diff($ln, $sn);
            $xb = array_diff($sn, $ln);
            if ($xa !== [] && $xb !== []) {
                $veto('line_number', implode('/', $ln) . ' vs ' . implode('/', $sn));
                $brandLine = 'conflict';
            } elseif ($xa !== [] || $xb !== []) {
                $flag('line_number_extra');
            }
        } else {
            // "Bar Juice 5000" vs "Bar Salts", "BM600" vs "Tappo": a model number on one side only. A number that is
            // only that side's puff count ("Finebar 1000 Puffs") is left to the puffs field.
            $own = fn (array $nums, array $x) => array_values(array_diff($nums, $x['puffs'] !== null ? [Text::num($x['puffs'])] : []));
            if (($ln === [] && $own($sn, $s) !== []) || ($sn === [] && $own($ln, $l) !== [])) {
                $flag('line_number_one_side');
            }
        }
        if ($l['multi_n'] !== null && $s['multi_n'] !== null && $l['multi_n'] !== $s['multi_n']) {
            $veto('line_number', $l['multi_n'] . '-in-1 vs ' . $s['multi_n'] . '-in-1');
            $brandLine = 'conflict';
        }

        // line modifiers: any one-sided modifier from the seed list is a hard veto
        // (compounds first: "V Prime" == "VPrime", so "prime" is not one-sided there)
        $lft = Text::mergeCompounds($l['full_tokens'] ?? [], $s['full_tokens'] ?? []);
        $sft = Text::mergeCompounds($s['full_tokens'] ?? [], $l['full_tokens'] ?? []);
        $lm = array_values(array_intersect($l['line_modifiers'] ?? [], $lft));
        $sm = array_values(array_intersect($s['line_modifiers'] ?? [], $sft));
        $xa = array_diff($lm, $sm);
        $xb = array_diff($sm, $lm);
        if ($xa !== [] || $xb !== []) {
            $d = [];
            if ($xa !== []) {
                $d[] = 'listing +' . implode('+', $xa);
            }
            if ($xb !== []) {
                $d[] = 'item +' . implode('+', $xb);
            }
            $veto('line_modifier', implode(', ', $d));
            $brandLine = 'conflict';
        }

        // one-sided line words outside the modifier list (soft)
        $lFull = Flavour::canon(self::joinInitials(self::fullTokens($l)));
        $sFull = Flavour::canon(self::joinInitials(self::fullTokens($s)));
        if (($l['line_tokens'] ?? []) !== [] && ($s['line_tokens'] ?? []) !== []) {
            foreach ([[Flavour::canon($l['line_tokens']), $sFull], [Flavour::canon($s['line_tokens']), $lFull]] as [$toks, $other]) {
                foreach ($toks as $t) {
                    if (strlen($t) >= 3 && !in_array($t, Normalizer::STOPWORDS, true) && !Text::fuzzyIn($t, $other)
                        && !in_array($t, $l['brand_family'] ?? [], true) && !in_array($t, $s['brand_family'] ?? [], true)) {
                        $flag('modifier_extra');
                        break 2;
                    }
                }
            }
        }

        // line words: each side names a line word the other side's text lacks ("IVG Original Salts" vs "IVG Intense",
        // "ELFLIQ" vs "Pod Salt Core", "Bar Juice 5000" vs "Vapes Bar Ghost"); consumables only
        if (self::consumable($l) && self::consumable($s)) {
            $lw = self::lineWords($l);
            $sw = self::lineWords($s);
            if ($lw !== [] && $sw !== []) {
                $lPres = self::presenceTokens($l);
                $sPres = self::presenceTokens($s);
                $xa = array_values(array_filter($lw, fn ($t) => !Text::fuzzyIn($t, $sPres)));
                $xb = array_values(array_filter($sw, fn ($t) => !Text::fuzzyIn($t, $lPres)));
                if ($xa !== [] && $xb !== [] && empty($ctx['confirmed_alias'])) {
                    if (self::aliasPending($xa, $xb) !== null) {
                        $flag('line_alias_pending');
                    } elseif (self::LINE_WORD_VETO) {
                        $veto('line_word', 'listing +' . implode('+', $xa) . ', item +' . implode('+', $xb));
                        $brandLine = 'conflict';
                    }
                }
            }
        }

        // brand / line family (generic and umbrella words never make two brands or lines agree)
        $exclude = array_merge(self::GENERIC_LINE_WORDS, self::UMBRELLA_LINE_WORDS);
        $lfam = array_values(array_diff($l['brand_family'] ?? [], $exclude));
        $sfam = array_values(array_diff($s['brand_family'] ?? [], $exclude));
        if ($brandLine !== 'conflict') {
            $cross = false;
            foreach ($sfam as $t) {
                if (in_array($t, $l['full_tokens'] ?? [], true)) {
                    $cross = true;
                }
            }
            foreach ($lfam as $t) {
                if (in_array($t, $s['full_tokens'] ?? [], true)) {
                    $cross = true;
                }
            }
            if ($lfam !== [] && $sfam !== [] && array_intersect($lfam, $sfam) !== []) {
                $brandLine = 'agree';
            } elseif ($cross) {
                $brandLine = 'agree';
            } elseif ($lfam === [] || $sfam === []) {
                $brandLine = 'unknown';
            } elseif (!empty($ctx['confirmed_alias'])) {
                $brandLine = 'agree';
            } else {
                $flag('relabelled_line_unconfirmed');
                $brandLine = 'conflict';
            }
        }
        $fields['brand_line'] = $brandLine;

        // flavour (a side with no separable flavour is compared through its residual identity words)
        $fc = self::flavourCompare($l, $s);
        $fields['flavour'] = $fc['state'];
        if ($fc['veto'] !== null) {
            $veto($fc['veto'], $fc['detail']);
        } elseif ($fc['flag'] !== null) {
            $flag($fc['flag']);
        }
        if (($l['flavour_tokens'] === null && isset($lc['flavour']) && $s['flavour_tokens'] !== null)
            || ($s['flavour_tokens'] === null && isset($sc['flavour']) && $l['flavour_tokens'] !== null)) {
            $flag('internal_conflict');
        }

        // volume (ml)
        $lv = $l['volume_ml'];
        $sv = $s['volume_ml'];
        if ($lv !== null && $sv !== null) {
            if (abs((float) $lv - (float) $sv) > 0.05) {
                $identity = in_array($lf, ['liquid', 'nic_shot'], true) || in_array($sf, ['liquid', 'nic_shot'], true)
                    || (str_contains((string) ($l['src']['volume_ml'] ?? ''), 'title') && str_contains((string) ($s['src']['volume_ml'] ?? ''), 'title'));
                if ($identity) {
                    $veto('liquid_ml', Text::num($lv) . 'ml vs ' . Text::num($sv) . 'ml');
                    $fields['volume'] = 'conflict';
                } else {
                    $flag('volume_diff_attr');
                    $fields['volume'] = 'unknown';
                }
            } else {
                $fields['volume'] = 'agree';
            }
        } else {
            $fields['volume'] = self::na($l, $s, ['liquid', 'nic_shot']) ? 'n_a' : 'unknown';
            $hidden('volume', $lv, $sv);
        }

        // puffs
        $lp = $l['puffs'];
        $sp = $s['puffs'];
        if ($lp !== null && $sp !== null) {
            if ((int) $lp !== (int) $sp) {
                $veto('puffs', $lp . ' vs ' . $sp);
                $fields['puffs'] = 'conflict';
            } else {
                $fields['puffs'] = 'agree';
            }
        } else {
            $fields['puffs'] = self::na($l, $s, ['device', 'pod_refill']) ? 'n_a' : 'unknown';
            $hidden('puffs', $lp, $sp);
        }

        // colour
        $lcol = $l['colour'];
        $scol = $s['colour'];
        if ($lcol !== null && $scol !== null) {
            $a = array_values(array_unique(Text::tokens((string) $lcol)));
            $b = array_values(array_unique(Text::tokens((string) $scol)));
            $a = Text::mergeCompounds($a, $b);
            $b = Text::mergeCompounds($b, $a);
            $xa = array_values(array_filter($a, fn ($t) => !self::colourIn($t, $b)));
            $xb = array_values(array_filter($b, fn ($t) => !self::colourIn($t, $a)));
            if ($xa === [] && $xb === []) {
                $fields['colour'] = 'agree';
            } elseif ($xa !== [] && $xb !== []) {
                $veto('colour', $lcol . ' vs ' . $scol);
                $fields['colour'] = 'conflict';
            } else {
                $flag('colour_extra');
                $fields['colour'] = 'unknown';
            }
        } else {
            $fields['colour'] = 'unknown';
            $hidden('colour', $lcol, $scol);
        }

        // resistance
        $lo = $l['resistance_ohm'];
        $so = $s['resistance_ohm'];
        if ($lo !== null && $so !== null) {
            if (abs((float) $lo - (float) $so) > 0.001) {
                $veto('ohm', Text::num($lo) . ' vs ' . Text::num($so) . ' ohm');
                $fields['resistance'] = 'conflict';
            } else {
                $fields['resistance'] = 'agree';
            }
        } else {
            $fields['resistance'] = self::na($l, $s, ['coil', 'pod_refill', 'tank']) ? 'n_a' : 'unknown';
            $hidden('resistance', $lo, $so);
        }

        // pack: contents of the barcoded retail unit; listing.pack must equal item.pack x u
        $lk = $l['pack_units'];
        $sk = $s['pack_units'];
        if ($lk !== null && $sk !== null) {
            if ((int) $lk !== (int) $sk * $units) {
                $veto('pack', $lk . ' vs ' . $sk . ' x u=' . $units);
                $fields['pack'] = 'conflict';
            } else {
                $fields['pack'] = 'agree';
            }
        } else {
            $fields['pack'] = 'unknown';
            $hidden('pack', $lk, $sk);
            if (($lk !== null && $lk > 1) || ($sk !== null && $sk > 1)) {
                $flag('pack_one_side');
            }
        }
        if (!empty($l['listing_multiplier']) || !empty($s['listing_multiplier'])) {
            $flag('listing_multiplier');
        }
        // multipack direction: "10 x 10ml" against a single unit is never the same retail unit. A listing multiple
        // of a single item is only acceptable with a units_per_item proposal ($units = the multiplier), when the
        // pack arithmetic above decides; an item multiple against a single listing never is.
        $single = fn (array $x): bool => empty($x['listing_multiplier']) && (($x['pack_units'] ?? null) === null || (int) $x['pack_units'] === 1);
        if (!empty($s['listing_multiplier']) && $single($l)) {
            $veto('multipack', 'item ' . $s['listing_multiplier'] . ' x unit vs single listing');
            $fields['pack'] = 'conflict';
        } elseif (!empty($l['listing_multiplier']) && $single($s) && $units !== (int) $l['listing_multiplier']) {
            $veto('multipack', 'listing ' . $l['listing_multiplier'] . ' x unit vs single item (u=' . $units . ')');
            $fields['pack'] = 'conflict';
        }

        // price per consumed unit
        $lpz = $l['unit_price'];
        $spz = $s['unit_price'];
        if ($lpz !== null && $spz !== null && $spz > 0 && $units > 0) {
            $r = (float) $lpz / ((float) $spz * $units);
            if ($r < 0.67 || $r > 1.5) {
                $flag('price_outlier');
            }
        }
        if (array_key_exists('s_product_published', $ctx) && $ctx['s_product_published'] === false) {
            $flag('target_not_published');
        }

        $out = ['strength', 'nic_type', 'form', 'brand_line', 'flavour', 'volume', 'pack', 'puffs', 'colour', 'resistance'];
        $fieldsOrdered = [];
        foreach ($out as $k) {
            $fieldsOrdered[$k] = $fields[$k] ?? 'unknown';
        }
        return ['vetoes' => $v, 'flags' => array_keys($f), 'fields' => $fieldsOrdered];
    }

    /** Both sides are known to be of classes where this field does not apply. */
    private static function na(array $l, array $s, array $classes): bool
    {
        return $l['form_class'] !== null && $s['form_class'] !== null
            && !in_array($l['form_class'], $classes, true) && !in_array($s['form_class'], $classes, true);
    }

    /** Colour token presence: exact, or one typo for words of 4+ letters ("slik" = "silk", "champange"). */
    private static function colourIn(string $t, array $other): bool
    {
        foreach ($other as $o) {
            if ($o === $t || (strlen($t) >= 4 && strlen($o) >= 4 && Text::osa($t, $o, 1) <= 1)) {
                return true;
            }
        }
        return false;
    }

    /** A flavoured consumable (liquid, nic shot, disposable, prefilled pod or prefilled pod kit). */
    public static function consumable(array $x): bool
    {
        return in_array($x['form'] ?? null, ['e_liquid', 'nic_salt', 'shortfill', 'nic_shot', 'disposable', 'prefilled_pod'], true)
            || (($x['form'] ?? null) === 'pod_kit' && ($x['form_sub'] ?? null) === 'prefilled');
    }

    /**
     * Line words of one side: its line text's words plus the letters of its model words ("bm" of "bm600"),
     * without stop/generic/descriptor/modifier/colour words, and without flavour words unless the brand itself
     * carries them ("Bar Juice 5000" keeps "juice").
     *
     * @return list<string>
     */
    public static function lineWords(array $x): array
    {
        $brand = array_flip(Text::tokens(Text::lower((string) ($x['brand_raw'] ?? ''))));
        $cands = $x['line_tokens'] ?? [];
        foreach ($x['line_models'] ?? [] as $m) {
            if (preg_match('/^([a-z]{2,6})\d/', (string) $m, $mm)) {
                $cands[] = $mm[1];
            }
        }
        static $skip = null;
        $skip ??= array_flip(array_merge(Normalizer::STOPWORDS, Normalizer::FLAVOUR_NOISE, Normalizer::LINE_MODIFIERS,
            self::GENERIC_LINE_WORDS, self::LINE_DESCRIPTORS, Normalizer::COLOURS, Normalizer::COLOUR_QUALIFIERS));
        $paren = array_flip($x['paren_tokens'] ?? []);
        $out = [];
        foreach ($cands as $t) {
            $t = (string) $t;
            if (strlen($t) < 2 || preg_match('/\d/', $t) || isset($skip[$t]) || isset($paren[$t])) {
                continue;
            }
            if (Flavour::isWord(Text::stem($t)) && !isset($brand[$t])) {
                continue;
            }
            $out[$t] = true;
        }
        return array_map('strval', array_keys($out));
    }

    /**
     * Adds the joined form of initials written apart ("R and M" / "R&M" -> "randm", "P&B" -> "pandb").
     *
     * @param list<string> $t
     * @return list<string>
     */
    public static function joinInitials(array $t): array
    {
        $n = count($t);
        for ($i = 0; $i + 2 < $n; $i++) {
            if (strlen((string) $t[$i]) === 1 && in_array($t[$i + 1], ['and', 'n'], true) && strlen((string) $t[$i + 2]) === 1
                && ctype_alpha($t[$i] . $t[$i + 2])) {
                $t[] = $t[$i] . 'and' . $t[$i + 2];
                $t[] = $t[$i] . 'n' . $t[$i + 2];
            }
        }
        return $t;
    }

    /** Everything one side says, for presence checks: text, brand, brackets, and model words split ("bm600" -> bm, 600). @return list<string> */
    private static function presenceTokens(array $y): array
    {
        $t = self::joinInitials(array_merge($y['full_tokens'] ?? [], Text::tokens((string) ($y['brand_raw'] ?? '')), $y['paren_tokens'] ?? []));
        foreach ($t as $x) {
            if (preg_match('/^([a-z]+)(\d+(?:\.\d+)?)([a-z]*)$/', (string) $x, $m)) {
                $t[] = $m[1];
                $t[] = $m[2];
            }
        }
        return array_map('strval', $t);
    }

    /**
     * The pending alias that explains a line_word mismatch, if any.
     *
     * @param list<string> $xa listing-only line words
     * @param list<string> $xb item-only line words
     */
    public static function aliasPending(array $xa, array $xb): ?string
    {
        foreach (self::PENDING_LINE_ALIASES as $al) {
            if ((array_diff($xa, $al['a']) === [] && array_diff($xb, $al['b']) === [])
                || (array_diff($xa, $al['b']) === [] && array_diff($xb, $al['a']) === [])) {
                return $al['note'];
            }
        }
        return null;
    }

    /** Full text + attribute + brand tokens of one side, for cross-checks. @return list<string> */
    private static function fullTokens(array $x): array
    {
        return array_merge($x['full_tokens'] ?? [], Text::tokens((string) ($x['brand_raw'] ?? '')));
    }

    /**
     * Flavour comparison with the full-text cross-check (see Flavour::compare). When one side has no
     * separable flavour, its residual (identity tokens minus both sides' line and brand words and the
     * line modifiers) stands in for it.
     *
     * @return array{state:string,veto:?string,flag:?string,detail:string}
     */
    public static function flavourCompare(array $l, array $s): array
    {
        $fl = $l['flavour_tokens'];
        $fs = $s['flavour_tokens'];
        if ($fl === null && $fs === null) {
            return ['state' => 'unknown', 'veto' => null, 'flag' => null, 'detail' => ''];
        }
        $fl ??= self::residual($l, $s);
        $fs ??= self::residual($s, $l);
        return Flavour::compare($fl, $fs, self::fullTokens($l), self::fullTokens($s), $l['paren_tokens'] ?? [], $s['paren_tokens'] ?? []);
    }

    /**
     * Identity words of a side with no separable flavour, minus line/brand words. v2.0: the OTHER side's line words
     * are dropped only when they are not flavour words (its line text can hold a flavour: "Elfliq Nic Salt by Elf
     * Bar - Strawberry Ice Cream" once gave the line "strawberry ice cream" and erased the flavour here).
     *
     * @return list<string>
     */
    private static function residual(array $x, array $other): array
    {
        $drop = [];
        foreach ($other['line_tokens'] ?? [] as $t) {
            if (!Flavour::isWord(Text::stem((string) $t))) {
                $drop[$t] = true;
            }
        }
        foreach (array_merge($x['line_tokens'] ?? [],
            Text::tokens((string) ($x['brand_raw'] ?? '')), Text::tokens((string) ($other['brand_raw'] ?? '')),
            $x['brand_family'] ?? [], $other['brand_family'] ?? [], Normalizer::LINE_MODIFIERS, $x['paren_tokens'] ?? []) as $t) {
            $drop[$t] = true;
        }
        $out = [];
        foreach ($x['id_tokens'] ?? [] as $t) {
            if (!isset($drop[$t])) {
                $out[] = $t;
            }
        }
        return $out;
    }
}
