<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Flavour token canonicalisation and comparison (design A.6 flavour_superset / flavour_diff).
 *
 * Seed lists below are PROPOSALS pending mapping-lead confirmation; they are deliberately small.
 * A difference only vetoes when both sides positively state a flavour (at least one known flavour
 * word) — a listing that simply does not name its flavour is "unknown", never a superset.
 *
 * v2 (pilot-1 fixes): a flavour word is one of WORDS, or of the vocabulary built from the Vape and Go
 * seed (FlavourVocab: names like Oasis, Rinbo, Gami, Tiger count as stated flavours), or one edit away
 * from either for words of 6+ letters ("Raspberrry").
 */
final class Flavour
{
    /** f2.1 (run3 follow-up (b)): "B Gum" / "BGum" = Bubblegum ("Blueberry B Gum" on Vape and Go = "Blueberry Bubblegum"). */
    public const VERSION = 'f2.1';

    /** Fuzzy flavour-word classification: one edit (OSA) for words of at least this many letters. */
    public const FUZZY_MIN_LEN = 6;

    /** Words never taken into the seed vocabulary: hardware, retail and packaging words. */
    public const VOCAB_EXCLUDE = [
        'mesh', 'meshed', 'coil', 'coils', 'rpm', 'mtl', 'dtl', 'rdl', 'empty', 'fill', 'starter', 'pouch', 'pouche',
        'cartridge', 'tank', 'battery', 'charger', 'replacment', 'shorfill', 'shortfil', 'eliquid', 'range',
        'colour', 'color', 'size', 'type', 'style', 'mode', 'option', 'default', 'standard', 'regular', 'strong',
        'extra', 'medium', 'mild', 'light', 'normal', 'single', 'double', 'box', 'bundle', 'offer', 'sale', 'deal',
        'multi', 'multipack', 'mix', 'mixed', 'assorted', 'random', 'various', 'sample', 'tester', 'test', 'ohm',
        // plain English words seen inside flavour names that do not name a flavour on their own
        'all', 'one', 'two', 'three', 'ten', 'day', 'end', 'over', 'top', 'very', 'long', 'hand', 'stay', 'true',
        'pure', 'hey', 'man', 'fab', 'fat', 'god', 'hit', 'key', 'mind', 'word', 'york', 'usa', 'town', 'city',
        'union', 'san', 'pan', 'mil', 'lil', 'art', 'bat', 'ape', 'dart', 'hour', 'letter', 'final', 'proper',
        'simply', 'totally', 'curiously', 'special', 'super', 'boys', 'girl', 'party', 'happy', 'lucky', 'street',
        'round', 'square', 'slim', 'soft', 'over', 'wall', 'track', 'trail', 'tune', 'tuned', 'loop', 'looper',
        'speed', 'motor', 'race', 'racing', 'digital', 'cyber', 'quantum', 'glass', 'mask', 'frame', 'mirror',
    ];

    /** @var array<string,bool> */
    private static array $wordCache = [];
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
        'hubba' => ['hubba', 'bubba'],   // "Hubba" alone is written for "Hubba Bubba" ("H Bubba")
        'bgum' => ['bubblegum'],
    ];

    /** Two-token abbreviations seen in GTIN-pair diffs ("Cotton K" = Cotton Candy, "R Berry" = Raspberry, "B Gum" = Bubblegum). */
    public const BIGRAMS = [
        'cotton k' => ['cotton', 'candy'],
        'h bubba' => ['hubba', 'bubba'],
        'r berry' => ['raspberry'],
        'b razz' => ['blue', 'razz'],
        'b gum' => ['bubblegum'],        // "Blueberry B Gum", "Watermelon B' Gum" (Hayati Pro Max Plus / Pro Ultra Plus)
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
            if (self::isWord((string) $x)) {
                return true;
            }
        }
        return false;
    }

    /** In the hand-kept seed list only (no seed vocabulary, no fuzzy match). */
    public static function isConstWord(string $t): bool
    {
        static $flip = null;
        $flip ??= array_flip(self::WORDS);
        return isset($flip[$t]) || isset($flip[Text::stem($t)]);
    }

    /**
     * Is this canonical token a flavour word? WORDS, the Vape and Go seed vocabulary (FlavourVocab), or one
     * edit away from either for words of FUZZY_MIN_LEN+ letters ("raspberrry" = "raspberry").
     */
    public static function isWord(string $t): bool
    {
        if (isset(self::$wordCache[$t])) {
            return self::$wordCache[$t];
        }
        static $all = null;
        static $long = null;
        if ($all === null) {
            $all = array_flip(array_merge(self::WORDS, FlavourVocab::WORDS));
            $long = array_values(array_filter(array_keys($all), fn ($w) => strlen((string) $w) >= self::FUZZY_MIN_LEN));
        }
        $st = Text::stem($t);
        $hit = isset($all[$t]) || isset($all[$st]);
        if (!$hit && strlen($st) >= self::FUZZY_MIN_LEN && ctype_alpha($st)
            && !in_array($st, Normalizer::FLAVOUR_NOISE, true) && !in_array($st, Normalizer::STOPWORDS, true)) {
            foreach ($long as $w) {
                $w = (string) $w;
                if (abs(strlen($w) - strlen($st)) <= 1 && Text::osa($st, $w, 1) <= 1) {
                    $hit = true;
                    break;
                }
            }
        }
        if (count(self::$wordCache) > 50000) {
            self::$wordCache = [];
        }
        return self::$wordCache[$t] = $hit;
    }

    /**
     * Build the seed flavour vocabulary from Vape and Go seed features (Normalizer output, one per seed item).
     *
     * A token enters when it is a separated flavour token of a flavoured seed item (liquids, prefilled
     * pods/devices, nic shots, pouches) whose flavour came from a reliable source (attribute, the
     * "<Flavour> Nic Salt by <Line>" pattern, the variant residue or a title segment), AND it names a
     * flavour on more distinct products than it names a line or brand (so "original", "crystal", "blood",
     * "bar" stay out: they are mostly line words), AND it is not a stop/noise/modifier/colour/hardware word.
     *
     * @param iterable<array<string,mixed>> $seed
     * @return array{words:list<string>,stats:array<string,array{flavour_products:int,line_products:int}>}
     */
    public static function vocabularyFromSeed(iterable $seed): array
    {
        $flav = [];
        $line = [];
        $reliable = ['attr', 'title_by_pattern', 'variant_residue', 'title_suffix', 'title_segment'];
        foreach ($seed as $f) {
            $pid = (int) ($f['product_id'] ?? 0);
            foreach (Text::tokens((string) ($f['brand_raw'] ?? '')) as $t) {
                $line[Text::stem($t)][$pid] = true;
            }
            if (in_array($f['flavour_src'] ?? null, ['title_by_pattern', 'title_segment'], true)) {
                foreach ($f['line_tokens'] ?? [] as $t) {
                    $line[Text::stem((string) $t)][$pid] = true;
                }
            }
            $flavoured = Form::flavoured($f['form'] ?? null, $f['form_sub'] ?? null) || ($f['form_sub'] ?? null) === 'nicotine_pouch';
            if (!$flavoured || ($f['flavour_tokens'] ?? null) === null || !in_array($f['flavour_src'] ?? null, $reliable, true)) {
                continue;
            }
            foreach (array_unique(self::clean($f['flavour_tokens'])) as $t) {
                $flav[$t][$pid] = true;
            }
        }
        $blocked = array_flip(array_merge(
            Normalizer::STOPWORDS, Normalizer::FLAVOUR_NOISE, Normalizer::LINE_MODIFIERS, Normalizer::BRAND_WEAK,
            Normalizer::BRAND_GENERIC, Normalizer::COLOURS, Normalizer::COLOUR_QUALIFIERS, self::VOCAB_EXCLUDE
        ));
        $noiseLong = array_values(array_filter(array_keys($blocked), fn ($w) => strlen((string) $w) >= 5));
        $words = [];
        $stats = [];
        foreach ($flav as $t => $pids) {
            $t = (string) $t;
            $nf = count($pids);
            $nl = count($line[$t] ?? []);
            if (!preg_match('/^[a-z]+$/', $t) || isset($blocked[$t]) || self::isConstWord($t)) {
                continue;
            }
            // short words need more evidence: 2 letters on 5+ products, 3-4 letters on 2+ products
            if (strlen($t) < 2 || (strlen($t) === 2 && $nf < 5) || (strlen($t) <= 4 && $nf < 2)) {
                continue;
            }
            if ($nf <= $nl) {
                continue;
            }
            $nearNoise = false;
            if (strlen($t) >= 5) {
                foreach ($noiseLong as $w) {
                    if (Text::osa($t, (string) $w, 1) <= 1) {
                        $nearNoise = true;
                        break;
                    }
                }
            }
            if ($nearNoise) {
                continue;
            }
            $words[] = $t;
            $stats[$t] = ['flavour_products' => $nf, 'line_products' => $nl];
        }
        sort($words, SORT_STRING);
        ksort($stats, SORT_STRING);
        return ['words' => $words, 'stats' => $stats];
    }

    /** Fuzzy presence, plus substring presence for non-flavour words ("mate" inside "podmate"). */
    private static function present(string $t, array $full): bool
    {
        if (Text::fuzzyIn($t, $full)) {
            return true;
        }
        if (strlen($t) >= 4 && !self::isConstWord($t)) {
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
        $isFlav = fn (array $x) => array_values(array_filter($x, fn ($t) => self::isWord((string) $t)));
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
