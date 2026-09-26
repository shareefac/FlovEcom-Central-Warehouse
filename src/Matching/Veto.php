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
    public const VERSION = 'v1.0';

    public const CODES = [
        'strength', 'nic_type', 'form', 'line_number', 'line_modifier', 'flavour_superset', 'flavour_diff',
        'liquid_ml', 'puffs', 'colour', 'ohm', 'pack', 'placeholder', 'sku_state',
    ];

    public const SOFT_FLAGS = [
        'price_outlier', 'target_not_published', 'internal_conflict', 'relabelled_line_unconfirmed',
        'strength_missing', 'modifier_extra', 'flavour_extra', 'line_number_extra', 'colour_extra',
        'volume_diff_attr', 'pack_one_side', 'listing_multiplier',
        // lane-level soft flags added by the first-match tool
        'same_channel_target_shared', 'gtin_also_on_inactive_item',
    ];

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
        $lFull = Flavour::canon(self::fullTokens($l));
        $sFull = Flavour::canon(self::fullTokens($s));
        if (($l['line_tokens'] ?? []) !== [] && ($s['line_tokens'] ?? []) !== []) {
            foreach ([[$l['line_tokens'], $sFull], [$s['line_tokens'], $lFull]] as [$toks, $other]) {
                foreach ($toks as $t) {
                    if (strlen($t) >= 3 && !in_array($t, Normalizer::STOPWORDS, true) && !Text::fuzzyIn($t, $other)
                        && !in_array($t, $l['brand_family'] ?? [], true) && !in_array($t, $s['brand_family'] ?? [], true)) {
                        $flag('modifier_extra');
                        break 2;
                    }
                }
            }
        }

        // brand / line family
        $lfam = $l['brand_family'] ?? [];
        $sfam = $s['brand_family'] ?? [];
        if ($brandLine !== 'conflict') {
            if ($lfam === [] || $sfam === []) {
                $brandLine = 'unknown';
            } elseif (array_intersect($lfam, $sfam) !== []) {
                $brandLine = 'agree';
            } else {
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
                if ($cross) {
                    $brandLine = 'agree';
                } elseif (!empty($ctx['confirmed_alias'])) {
                    $brandLine = 'agree';
                } else {
                    $flag('relabelled_line_unconfirmed');
                    $brandLine = 'conflict';
                }
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
            $fields['volume'] = 'unknown';
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

    /** @return list<string> */
    private static function residual(array $x, array $other): array
    {
        $drop = [];
        foreach (array_merge($other['line_tokens'] ?? [], $x['line_tokens'] ?? [],
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
