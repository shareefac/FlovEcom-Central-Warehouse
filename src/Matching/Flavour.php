<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Flavour token canonicalisation and comparison (design A.6 flavour_superset / flavour_diff).
 *
 * Seed lists below are PROPOSALS pending mapping-lead confirmation; they are deliberately small.
 * A difference only vetoes when both sides positively state a flavour (at least one known flavour
 * word) — a listing that simply does not name its flavour is "unknown", never a superset.
 */
final class Flavour
{
    /** Single-token synonyms. */
    public const SYNONYMS = [
        'gb' => ['gummy', 'bear'],
        'bg' => ['gummy', 'bear'],
        'edtn' => ['edition'],
        'straw' => ['strawberry'],
        'choc' => ['chocolate'],
        'iced' => ['ice'],
        'mixed' => ['mix'],
        'bubbly' => ['bubble'],
    ];

    /** Two-token abbreviations seen in GTIN-pair diffs ("Cotton K" = Cotton Candy, "R Berry" = Raspberry). */
    public const BIGRAMS = [
        'cotton k' => ['cotton', 'candy'],
        'h bubba' => ['hubba', 'bubba'],
        'r berry' => ['raspberry'],
        'b razz' => ['blue', 'razz'],
    ];

    /**
     * Flavour words (seed). One-sided extras made only of these words are a hard veto
     * ("Blue Razz" vs "Blue Razz Lemonade"); other one-sided words are the soft flag flavour_extra.
     */
    public const WORDS = [
        'ice', 'icy', 'lemonade', 'sour', 'gummy', 'bear', 'menthol', 'mint', 'cool', 'chill', 'frost', 'frosty',
        'frozen', 'freeze', 'cola', 'soda', 'cream', 'creamy', 'candy', 'slush', 'slushie', 'burst', 'fizz', 'fizzy',
        'bubblegum', 'bubble', 'gum', 'tea', 'sherbet', 'twist', 'mojito', 'sweet', 'jelly', 'drop',
        'apple', 'apricot', 'banana', 'berry', 'blackberry', 'blackcurrant', 'currant', 'blueberry', 'cherry',
        'coconut', 'cranberry', 'dragonfruit', 'dragon', 'fruit', 'grape', 'grapefruit', 'guava', 'honeydew', 'kiwi',
        'lemon', 'lime', 'lychee', 'litchi', 'mango', 'melon', 'orange', 'passion', 'passionfruit', 'peach', 'pear',
        'pineapple', 'pomegranate', 'raspberry', 'razz', 'strawberry', 'watermelon', 'papaya', 'plum', 'acai',
        'aloe', 'citrus', 'tropical', 'mix', 'tangerine', 'mandarin', 'nectarine', 'fig', 'gooseberry',
        'elderflower', 'rhubarb', 'cantaloupe', 'yuzu', 'jasmine', 'rose', 'forest', 'red', 'blue', 'pink',
        'green', 'black', 'white', 'purple', 'golden', 'triple', 'double',
        'vanilla', 'caramel', 'custard', 'tobacco', 'coffee', 'chocolate', 'cookie', 'donut', 'doughnut', 'cake',
        'pie', 'cheesecake', 'biscuit', 'energy', 'rum', 'whisky', 'whiskey', 'punch', 'nectar', 'smoothie',
        'milkshake', 'yogurt', 'yoghurt', 'honey', 'nut', 'hazelnut', 'almond', 'peanut', 'cinnamon', 'spearmint',
        'peppermint', 'aniseed', 'liquorice', 'licorice', 'jam', 'tiramisu', 'marshmallow', 'candyfloss', 'cotton',
        'toffee', 'butterscotch', 'pudding', 'waffle', 'pancake', 'cereal', 'milk', 'latte', 'mocha', 'espresso',
        'cappuccino', 'lager', 'beer', 'cider', 'wine', 'gin', 'vodka', 'tonic', 'juice', 'juicy', 'crumble',
        'tart', 'sorbet', 'gelato', 'shake', 'cooler', 'breeze', 'blast', 'splash', 'crush', 'zest', 'eucalyptus',
        'anise', 'clove', 'ginger', 'cucumber', 'basil', 'cigar', 'skittle', 'rainbow', 'fresh',
    ];

    /** @param list<string> $tokens @return list<string> */
    public static function canon(array $tokens): array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = (string) $tokens[$i];
            if ($i + 1 < $n && isset(self::BIGRAMS[$t . ' ' . $tokens[$i + 1]])) {
                foreach (self::BIGRAMS[$t . ' ' . $tokens[$i + 1]] as $x) {
                    $out[] = $x;
                }
                $i++;
                continue;
            }
            if (isset(self::SYNONYMS[$t])) {
                foreach (self::SYNONYMS[$t] as $x) {
                    $out[] = $x;
                }
                continue;
            }
            $out[] = Text::stem($t);
        }
        return $out;
    }

    /** Canonical flavour tokens without noise/number tokens. @param list<string> $t @return list<string> */
    public static function clean(array $t): array
    {
        return array_values(array_filter(self::canon($t), fn ($x) => $x !== ''
            && !in_array($x, Normalizer::FLAVOUR_NOISE, true) && !preg_match('/\d/', $x)));
    }

    /** Does this token list positively name a flavour? @param list<string> $t */
    public static function stated(array $t): bool
    {
        foreach ($t as $x) {
            if (in_array($x, self::WORDS, true)) {
                return true;
            }
        }
        return false;
    }

    /** Fuzzy presence, plus substring presence for non-flavour words ("mate" inside "podmate"). */
    private static function present(string $t, array $full): bool
    {
        if (Text::fuzzyIn($t, $full)) {
            return true;
        }
        if (strlen($t) >= 4 && !in_array($t, self::WORDS, true)) {
            foreach ($full as $f) {
                if (strlen($f) > strlen($t) && str_contains($f, $t)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Tokens of $a absent from the other side's full text.
     *
     * @param list<string> $a canonical flavour tokens
     * @param list<string> $otherFull canonical full-text tokens of the other listing
     * @param list<string> $otherFlav canonical flavour tokens of the other listing (for compound merging)
     * @return list<string>
     */
    public static function extras(array $a, array $otherFull, array $otherFlav): array
    {
        $a = Text::mergeCompounds($a, $otherFull);
        $merged = Text::mergeCompounds($otherFull, $a);
        $x = [];
        foreach (array_unique($a) as $t) {
            if (!self::present($t, $otherFull) && !self::present($t, $merged)) {
                $x[] = $t;
            }
        }
        return $x;
    }

    /** Are two flavour token lists the same flavour (both directions, fuzzy, canonical)? */
    public static function same(array $a, array $b): bool
    {
        $a = self::clean($a);
        $b = self::clean($b);
        if ($a === [] || $b === []) {
            return false;
        }
        return self::extras($a, $b, $b) === [] && self::extras($b, $a, $a) === [];
    }

    /**
     * @param list<string> $fl listing flavour tokens (raw)
     * @param list<string> $fs item flavour tokens (raw)
     * @param list<string> $lFull listing full-text tokens (raw)
     * @param list<string> $sFull item full-text tokens (raw)
     * @param list<string> $lParen listing parenthetical tokens (old names, "(P&B Cloud)")
     * @param list<string> $sParen item parenthetical tokens
     * @return array{state:string,veto:?string,flag:?string,detail:string}
     */
    public static function compare(array $fl, array $fs, array $lFull, array $sFull, array $lParen = [], array $sParen = []): array
    {
        $none = ['state' => 'unknown', 'veto' => null, 'flag' => null, 'detail' => ''];
        $fl = self::clean($fl);
        $fs = self::clean($fs);
        if ($fl === [] || $fs === []) {
            return $none;
        }
        $lF = self::canon($lFull);
        $sF = self::canon($sFull);
        $xl = self::extras($fl, $sF, $fs);
        $xs = self::extras($fs, $lF, $fl);
        if ($xl === [] && $xs === []) {
            return ['state' => 'agree', 'veto' => null, 'flag' => null, 'detail' => ''];
        }
        // a renamed flavour with the old name kept in brackets on the other side: "P&B Cloud" = "Cotton Candy Ice (P&B Cloud)"
        $lp = self::clean($lParen);
        $sp = self::clean($sParen);
        if (($sp !== [] && self::extras($fl, $sp, $sp) === []) || ($lp !== [] && self::extras($fs, $lp, $lp) === [])) {
            return ['state' => 'agree', 'veto' => null, 'flag' => null, 'detail' => 'matched via bracketed alias'];
        }
        $isFlav = fn (array $x) => array_values(array_filter($x, fn ($t) => in_array($t, self::WORDS, true)));
        $detail = 'listing +' . implode('+', $xl) . ' / item +' . implode('+', $xs);
        $statedL = self::stated($fl);
        $statedS = self::stated($fs);
        if ($xl !== [] && $xs !== []) {
            if ($statedL && $statedS && ($isFlav($xl) !== [] || $isFlav($xs) !== [])) {
                return ['state' => 'conflict', 'veto' => 'flavour_diff', 'flag' => null, 'detail' => $detail];
            }
            return ['state' => 'unknown', 'veto' => null, 'flag' => 'flavour_extra', 'detail' => $detail];
        }
        $extra = $xl !== [] ? $xl : $xs;
        $smallerStated = $xl !== [] ? $statedS : $statedL;
        if ($smallerStated && count($isFlav($extra)) === count($extra)) {
            return ['state' => 'conflict', 'veto' => 'flavour_superset', 'flag' => null, 'detail' => $detail];
        }
        return ['state' => 'unknown', 'veto' => null, 'flag' => 'flavour_extra', 'detail' => $detail];
    }
}
