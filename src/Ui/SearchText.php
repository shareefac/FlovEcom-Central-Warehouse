<?php

declare(strict_types=1);

namespace CW\Ui;

use CW\Matching\Gtin;

/**
 * What a person typed in a product search box, read the same way by every product search of the matching pages (docs/decisions.md
 * SX1-SX3): Find a product, "Pick a different product", By Item, its picker and quick match, the match-strength lists, Store
 * Products and New Variants.
 *
 *  - Case and accents never matter: the columns compare with `utf8mb4_0900_ai_ci` (so "crème" finds "Creme").
 *  - Every word must be found, in any order (at most MAX_WORDS of them, the longest asked first).
 *  - Spaces and hyphens are not part of the comparison, and "&" is "and", where it is cheap (the measured cost: SX2). Three kinds
 *    of word, asked in this order (a row the first rules out is not read for the others):
 *     1. a short word of letters (at most 4) or a number: in the text as it is ("kiwi", "600");
 *     2. a longer word of letters: in the text as it is, or in the BRAND with its spaces and hyphens left out ("elfbar" finds the
 *        brand "Elf Bar", "lostmary" "Lost Mary"; a joined word is nearly always a brand, and the brand is short);
 *     3. any other word (typed with a hyphen or "&", a number and its unit, letters and digits, "R and M"): in the whole text with
 *        its spaces and hyphens left out (sql()), a word of two short names joined by "&" or "and" in both spellings. So "pro-max"
 *        finds "Pro Max" and "ProMax", "R&M" finds "R and M" and "RandM", "20mg" finds "20 mg".
 *    Slashes, bars, commas and brackets separate words.
 *  - Three runs of words are read as one word: a number and its unit ("20 mg" is "20mg", "10 ml", "0.8 ohm", "7000 puffs"), and
 *    two short names joined by "and" or "&" ("R and M", "R & M" are "randm"). A lone "and" or "&" is no word.
 *  - A CW number however it is written: "CW-003906", "cw 3906", "CW3906", "#3906", or a bare number ("3906"); the callers say
 *    which of them they take (cwId(), explicitCw()).
 *  - A code: the whole text when it has no space, at most 64 characters and a digit (an option number or a barcode as typed);
 *    for digits only also every form a barcode may be stored in (Gtin::forms: with and without its leading zeros). The callers
 *    look codes up by their indexes, never in a scan.
 *
 * It only reads: it never changes what a search may return beyond these rules, and every rule only adds to what the words found
 * before (a word compared as it is is also found with the spaces and hyphens left out on both sides), except the two merges,
 * which ask for the number and its unit (or the two names) together instead of anywhere apart.
 */
final class SearchText
{
    public const MAX_WORDS = 6;
    /** The units a number before them is joined with ("20 mg" = "20mg"). */
    private const UNITS = ['mg', 'ml', 'k', 'puff', 'puffs', 'ohm', 'ohms', 'mah', 'w', 'cl', 'g', 'pk', 'pcs'];

    /**
     * @param list<string> $words every word, normalised (norm()), longest first
     * @param list<string> $typed the same words as typed (lower case; a merged word with its space: "20 mg"), in the same order
     * @param list<string> $codes the whole text as a code, and the barcode forms of a number (empty when it is no code)
     */
    private function __construct(
        public readonly string $text,
        public readonly array $words,
        public readonly array $typed,
        private readonly ?int $cw,
        private readonly bool $explicit,
        public readonly array $codes,
    ) {
    }

    /** Whether word $i is compared with the text as it is: a word of at most 4 letters, or a number, typed as one word (kind 1). */
    public function plain(int $i): bool
    {
        return preg_match('/^(\pL{1,4}|[0-9]+)$/uD', $this->typed[$i] ?? '') === 1;
    }

    /**
     * Whether word $i is a longer word of letters only (kind 2: the text as it is, or the brand without its spaces and hyphens); not
     * two short names joined by "and" ("randm"), which is asked in both spellings (kind 3).
     */
    public function lettersOnly(int $i): bool
    {
        return preg_match('/^\pL{5,}$/uD', $this->typed[$i] ?? '') === 1 && preg_match('/^\pL{1,2}and\pL{1,2}$/uD', $this->words[$i]) !== 1;
    }

    /**
     * The patterns of word $i (LIKE `%…%`, escaped): its normal form, and for a word of two short names joined by "&" or "and"
     * ("r&m", "randm"), or one typed with "&", the same with the other spelling, since sql() keeps the text's "&" as it is.
     *
     * @return list<string>
     */
    public function patterns(int $i): array
    {
        $w = $this->words[$i];
        $out = [$w];
        if (preg_match('/^(\pL{1,2})and(\pL{1,2})$/uD', $w, $m) === 1) {
            $out[] = $m[1] . '&' . $m[2];
        } elseif (str_contains($this->typed[$i] ?? '', '&')) {
            $out[] = str_replace([' ', '-'], '', $this->typed[$i]); // "salt&pepper" as typed, beside "saltandpepper"
        }
        return array_values(array_unique(array_map(self::like(...), $out)));
    }

    /**
     * The order the words are asked in: kind 1, then kind 2, then kind 3 (the cheapest first); each kind longest first.
     *
     * @return list<int>
     */
    public function order(): array
    {
        $kinds = [[], [], []];
        foreach (array_keys($this->words) as $i) {
            $kinds[$this->plain($i) ? 0 : ($this->lettersOnly($i) ? 1 : 2)][] = $i;
        }
        return [...$kinds[0], ...$kinds[1], ...$kinds[2]];
    }

    /**
     * "Word $i is in this text" for a statement, by its kind: `expr LIKE ?`; `(expr LIKE ? OR norm(brand) LIKE ?)` (when the caller
     * names its brand column, else kind 3); `norm(expr) LIKE ?` (two of them, OR-ed, for the two spellings of "&"); and its values.
     *
     * @return array{0: string, 1: list<string>}
     */
    public function wordSql(int $i, string $expr, ?string $brand = null): array
    {
        if ($this->plain($i)) {
            return ["{$expr} LIKE ?", [self::like($this->words[$i])]];
        }
        if ($brand !== null && $this->lettersOnly($i)) {
            return ["({$expr} LIKE ? OR " . self::sql($brand) . ' LIKE ?)', [self::like($this->words[$i]), self::like($this->words[$i])]];
        }
        $norm = self::sql($expr);
        $patterns = $this->patterns($i);
        $sql = implode(' OR ', array_fill(0, count($patterns), "{$norm} LIKE ?"));
        return [count($patterns) > 1 ? "({$sql})" : $sql, $patterns];
    }

    public static function parse(string $q): self
    {
        $text = trim($q);
        $cw = null;
        $explicit = false;
        if (preg_match('/^(cw|#)?[\s\-#:]*0*([0-9]{1,10})$/iD', $text, $m) === 1) {
            $cw = (int) $m[2];
            $explicit = $m[1] !== '';
            if ($cw === 0) {
                $cw = null;
            }
        }
        $codes = [];
        if ($text !== '' && mb_strlen($text) <= 64 && preg_match('/\s/u', $text) !== 1 && preg_match('/[0-9]/', $text) === 1) {
            $codes = preg_match('/^[0-9]+$/D', $text) === 1 ? Gtin::forms([$text]) : [$text];
        }
        [$words, $typed] = self::words($text);
        return new self($text, $words, $typed, $cw, $explicit, $codes);
    }

    /**
     * The words of a text: split on white space (any, also a no-break space), commas, semicolons, slashes, bars and brackets; the
     * merges; a lone "and" / "&" and anything with no letter or digit left out; each one normalised, once, the longest first.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function words(string $text): array
    {
        $raw = preg_split('/[\s,;\/|()\[\]{}"]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($raw)) {
            return [[], []]; // not valid UTF-8: nothing to look for
        }
        $tokens = [];
        $n = count($raw);
        for ($i = 0; $i < $n; $i++) {
            $t = $raw[$i];
            $next = $raw[$i + 1] ?? null;
            // "R and M", "R & M": two short names joined by "and" are one name
            if ($next !== null && ($next === 'and' || $next === '&') && isset($raw[$i + 2]) && preg_match('/^\pL{1,2}$/uD', $t) === 1
                && preg_match('/^\pL{1,2}$/uD', $raw[$i + 2]) === 1) {
                $tokens[] = $t . ' and ' . $raw[$i + 2];
                $i += 2;
                continue;
            }
            // "20 mg", "0.8 ohm", "7000 puffs": a number and its unit are one word
            if ($next !== null && preg_match('/^[0-9]+(?:[.,][0-9]+)?$/D', $t) === 1 && in_array($next, self::UNITS, true)) {
                $tokens[] = $t . ' ' . $next;
                $i++;
                continue;
            }
            $tokens[] = $t;
        }
        $words = [];
        foreach ($tokens as $t) {
            if ($t === 'and' || $t === '&' || preg_match('/[\pL\pN]/u', $t) !== 1) {
                continue;
            }
            $w = self::norm($t);
            if ($w !== '' && !isset($words[$w])) {
                $words[$w] = $t;
            }
        }
        // the longest first (a statement asks the words in this order, so a variant the first rules out is not read for the others)
        $keys = array_map('strval', array_keys($words));
        usort($keys, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a) ?: strcmp($a, $b));
        $keys = array_slice($keys, 0, self::MAX_WORDS);
        return [$keys, array_map(static fn (string $k): string => $words[$k], $keys)];
    }

    /** A word as the comparison sees it: lower case, no spaces or hyphens, "&" as "and" (its other spelling: patterns()). */
    public static function norm(string $word): string
    {
        return str_replace([' ', '-', '&'], ['', '', 'and'], mb_strtolower($word));
    }

    /**
     * A text column (or CONCAT_WS of columns) as the comparison sees it: spaces and hyphens left out (two REPLACEs: a third, for
     * "&", cost about a fifth more on 29,000 profiles, so "&" is asked in both spellings instead, patterns()). The case and the
     * accents are the column's collation's (ai_ci). Character by character, so a word found in the text as it is is also found in this.
     */
    public static function sql(string $expr): string
    {
        return "REPLACE(REPLACE({$expr}, ' ', ''), '-', '')";
    }

    /**
     * "Every word is in this text" for a statement, the cheapest first (order()): `(… AND …)` and its values, or ['', []] when there
     * is no word. $brand: the brand column of the text, for the joined words (kind 2).
     *
     * @return array{0: string, 1: list<string>}
     */
    public function allWords(string $expr, ?string $brand = null): array
    {
        if ($this->words === []) {
            return ['', []];
        }
        $parts = [];
        $params = [];
        foreach ($this->order() as $i) {
            [$sql, $values] = $this->wordSql($i, $expr, $brand);
            $parts[] = $sql;
            array_push($params, ...$values);
        }
        return ['(' . implode(' AND ', $parts) . ')', $params];
    }

    /** `%word%` with the LIKE wildcards of the word escaped. */
    public static function like(string $word): string
    {
        return '%' . Queries::escapeLike($word) . '%';
    }

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    /** Whether the text holds anything to look for (a word, a CW number or a code). */
    public function hasSomething(): bool
    {
        return $this->words !== [] || $this->cw !== null || $this->codes !== [];
    }

    /**
     * The CW number the text is, or null: written with "CW" (or "#") always; a bare number only with at most $bareDigits digits
     * after its leading zeros (0: never).
     */
    public function cwId(int $bareDigits = 10): ?int
    {
        if ($this->cw === null) {
            return null;
        }
        return $this->explicit || strlen((string) $this->cw) <= $bareDigits ? $this->cw : null;
    }

    /** Written as a CW number ("CW-003906", "cw 3906", "#3906"), not a bare number. */
    public function explicitCw(): bool
    {
        return $this->cw !== null && $this->explicit;
    }

    /** The whole text is digits only (a barcode, an option number or a CW number without "CW"). */
    public function digitsOnly(): bool
    {
        return preg_match('/^[0-9]+$/D', $this->text) === 1;
    }
}
