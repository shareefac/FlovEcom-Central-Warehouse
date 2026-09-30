<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Pure text helpers shared by the matching classes. No I/O, no state.
 */
final class Text
{
    /** Decode entities (twice: the catalogue holds "&amp;amp;"), fold typographic punctuation, collapse whitespace. */
    public static function clean(?string $s): string
    {
        $s = (string) $s;
        if ($s === '') {
            return '';
        }
        $s = html_entity_decode(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace(
            ["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}", "\u{00A0}", "\u{2122}", "\u{00AE}", "\u{03A9}", "\u{2126}"],
            ["'", "'", '"', '"', '-', '-', ' ', ' ', ' ', ' ohm', ' ohm'],
            $s
        );
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    public static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    /** Lower-case, whitespace-collapsed form for containment/equality tests. */
    public static function squash(string $s): string
    {
        $s = self::lower(self::clean($s));
        $s = preg_replace('/\s*([|\-\/(),:])\s*/u', '$1', $s) ?? $s;
        return trim($s);
    }

    /** l/I confusions seen in the catalogue ("lce" typed for "Ice"). */
    private const FOLD = [
        'lce' => 'ice', 'lced' => 'iced', 'iemonade' => 'lemonade', 'iime' => 'lime', 'iychee' => 'lychee',
    ];

    /**
     * Tokenise to lower-case alphanumeric tokens. Decimals between digits are kept ("2.0", "0.6").
     * "&" becomes "and" so it can be dropped as a stopword.
     *
     * @return list<string>
     */
    public static function tokens(string $s): array
    {
        $s = self::lower($s);
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/(?<=\d)[.,](?=\d)/u', '#', $s) ?? $s;   // protect decimals
        $s = preg_replace('/[^a-z0-9#]+/u', ' ', $s) ?? $s;
        $s = str_replace('#', '.', $s);
        $out = [];
        foreach (explode(' ', trim($s)) as $t) {
            if ($t === '') {
                continue;
            }
            $out[] = self::FOLD[$t] ?? $t;
        }
        return $out;
    }

    /** Canonical number string: "2.0" -> "2", "0.60" -> "0.6". */
    public static function num(string|float|int $n): string
    {
        $f = (float) str_replace(',', '.', (string) $n);
        $s = rtrim(rtrim(sprintf('%.3f', $f), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** Light plural stemming for flavour words ("raspberries" -> "raspberry", "grapes" -> "grape"). */
    public static function stem(string $t): string
    {
        $n = strlen($t);
        if ($n > 4 && str_ends_with($t, 'ies')) {
            return substr($t, 0, -3) . 'y';
        }
        if ($n > 4 && str_ends_with($t, 's') && !str_ends_with($t, 'ss') && !str_ends_with($t, 'us')) {
            return substr($t, 0, -1);
        }
        if ($t === 'iced') {
            return 'ice';
        }
        return $t;
    }

    /** Optimal string alignment distance (Levenshtein + adjacent transposition), early exit above $max. */
    public static function osa(string $a, string $b, int $max = 2): int
    {
        if ($a === $b) {
            return 0;
        }
        $la = strlen($a);
        $lb = strlen($b);
        if (abs($la - $lb) > $max) {
            return $max + 1;
        }
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            $rowMin = PHP_INT_MAX;
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $v = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $v = min($v, $d[$i - 2][$j - 2] + 1);
                }
                $d[$i][$j] = $v;
                if ($v < $rowMin) {
                    $rowMin = $v;
                }
            }
            if ($rowMin > $max) {
                return $max + 1;
            }
        }
        return $d[$la][$lb];
    }

    /**
     * Is $t present in token list $seq, allowing: exact, plural stem, OSA<=1 between tokens of 5+ letters when one has 6+,
     * and a compound join on the other side ("bubblegum" vs "bubble gum").
     *
     * @param list<string> $seq ordered token sequence of the other side
     */
    public static function fuzzyIn(string $t, array $seq): bool
    {
        if ($t === '') {
            return true;
        }
        $st = self::stem($t);
        foreach ($seq as $i => $s) {
            if ($s === $t) {
                return true;
            }
            $ss = self::stem($s);
            if ($ss === $st) {
                return true;
            }
            // one edit between words of 5+ letters when either has 6+ ("cloudd" = "cloud", "raspberrry" = "raspberry")
            if (min(strlen($st), strlen($ss)) >= 5 && max(strlen($st), strlen($ss)) >= 6 && !ctype_digit($st)
                && self::osa($st, $ss, 1) <= 1) {
                return true;
            }
            // compound on the other side: "bubble"+"gum" vs "bubblegum"
            if (isset($seq[$i + 1]) && $s . $seq[$i + 1] === $t) {
                return true;
            }
        }
        return false;
    }

    /**
     * Merge adjacent tokens of $seq whose concatenation is a token of $other
     * ("black currant" -> "blackcurrant" when the other side writes it as one word).
     *
     * @param list<string> $seq
     * @param list<string> $other
     * @return list<string>
     */
    public static function mergeCompounds(array $seq, array $other): array
    {
        if (count($seq) < 2 || $other === []) {
            return $seq;
        }
        $o = array_flip($other);
        $out = [];
        $n = count($seq);
        for ($i = 0; $i < $n; $i++) {
            if ($i + 1 < $n && isset($o[$seq[$i] . $seq[$i + 1]])) {
                $out[] = $seq[$i] . $seq[$i + 1];
                $i++;
                continue;
            }
            $out[] = $seq[$i];
        }
        return $out;
    }
}
