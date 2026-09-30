<?php

declare(strict_types=1);

namespace CW\Matching;

/**
 * Line / flavour split for titles that carry no separator between the two (pilot-1 fix 1). Electrofag writes
 * its liquids in two shapes, and nothing else in the title marks where the line ends:
 *
 *   line first     "Bar Juice 5000 Strawberry Ice 10ml Nic Salt E liquid - 10mg", "ELFLIQ Spearmint 10ml Nic Salt E Liquid",
 *                  "SKE Crystal Original Cherry Ice 10ml Nic Salt E Liquid", "Hayati Pro Max Cherry Ice 10ml Nic Salt E Liquid"
 *   flavour first  "Oasis Elux Legend Nic Salt 10ml - 5mg", "Melon XL Riot Squad BAR EDTN 10ml Nic Salt E Liquid",
 *                  "Vimbull Ice Hayati Pro Max 100ml Shortfill Eliquid 70/30"
 *
 * The line is located from the listing's own brand words (the anchor) and a per-brand LINE LEXICON: words that
 * 3+ products of the same brand on the same site share, that are not flavour words (Flavour::isWord). A run of
 * line words (anchor, lexicon, line modifiers, model numbers, "bar"/"edtn") starting at the first token is the
 * line and the rest is the flavour; a run that starts later is the line of a flavour-first title (it extends
 * backwards over brand/lexicon words only, so "Melon XL Riot Squad" keeps "XL" in the flavour).
 *
 * Pure functions; the lexicon is built once per site by the caller (tools/first_match/run.php) and passed to
 * Normalizer::normalize() in $ctx['line_lexicon'].
 */
final class TitlePattern
{
    public const VERSION = 'tp1.0';

    public const LEXICON_MIN_PRODUCTS = 3;

    /** Words that continue a line phrase next to a line word ("Riot Squad BAR EDTN", "Doozy Seriously Bar"). */
    public const LINE_GLUE = ['bar', 'bars', 'edtn', 'series'];

    /** Brand words that never anchor a line. */
    private const ANCHOR_SKIP = ['co', 'company', 'ltd', 'brand', 'by', 'and', 'the', 'official'];

    public static function brandKey(string $brandRaw): string
    {
        return implode(' ', Text::tokens(Text::lower(Text::clean($brandRaw))));
    }

    /**
     * Ordered content tokens of the title head: the first " - " / " | " segment, brackets removed, quantities,
     * form and stop words dropped. Model numbers ("5000", "bm600") are kept: they glue the line together.
     *
     * @return list<string>
     */
    public static function headTokens(string $fullLower): array
    {
        $parts = preg_split('/\s[|\-]\s|\s\|/u', $fullLower) ?: [$fullLower];
        $h = (string) $parts[0];
        $h = preg_replace('/\([^)]*\)/u', ' ', $h) ?? $h;
        $h = Normalizer::stripQuantities($h);
        $out = [];
        foreach (Text::tokens($h) as $t) {
            if (in_array($t, Normalizer::STOPWORDS, true) || (in_array($t, Normalizer::FLAVOUR_NOISE, true) && !preg_match('/\d/', $t))) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /** Brand words that can anchor the line. @return list<string> */
    public static function anchorTokens(string $brandRaw): array
    {
        $out = [];
        foreach (Text::tokens(Text::lower(Text::clean($brandRaw))) as $t) {
            if (strlen($t) < 2 || ctype_digit($t) || in_array($t, Normalizer::STOPWORDS, true) || in_array($t, self::ANCHOR_SKIP, true)) {
                continue;
            }
            $out[$t] = true;
        }
        return array_keys($out);
    }

    /**
     * Per-brand line lexicon of one site.
     *
     * @param iterable<array<string,mixed>> $rows export rows (the caller decides the scope)
     * @return array<string, list<string>> brandKey => words
     */
    public static function lexicon(iterable $rows): array
    {
        $seen = [];
        $count = [];
        foreach ($rows as $r) {
            $bk = self::brandKey((string) ($r['brand'] ?? ''));
            $pid = (int) ($r['product_id'] ?? 0);
            if ($bk === '' || isset($seen[$bk][$pid])) {
                continue;
            }
            $seen[$bk][$pid] = true;
            $pt = Text::lower(Text::clean((string) ($r['product_title'] ?? '')));
            foreach (array_unique(self::headTokens($pt)) as $t) {
                $count[$bk][$t] = ($count[$bk][$t] ?? 0) + 1;
            }
        }
        $out = [];
        foreach ($count as $bk => $words) {
            $lex = [];
            foreach ($words as $t => $n) {
                $t = (string) $t;
                if ($n < self::LEXICON_MIN_PRODUCTS || !preg_match('/^[a-z]{2,}$/', $t) || Flavour::isWord($t)
                    || in_array($t, Normalizer::COLOURS, true) || in_array($t, Normalizer::COLOUR_QUALIFIERS, true)
                    || in_array($t, Normalizer::FLAVOUR_NOISE, true)) {
                    continue;
                }
                $lex[] = $t;
            }
            sort($lex, SORT_STRING);
            if ($lex !== []) {
                $out[(string) $bk] = $lex;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param list<string> $head headTokens() of the title
     * @param list<string> $anchor anchorTokens() of the listing's brand
     * @param list<string> $lexicon line lexicon of the listing's brand on its site
     * @return ?array{flavour:list<string>,line:list<string>,pattern:string}
     */
    public static function split(array $head, array $anchor, array $lexicon): ?array
    {
        $n = count($head);
        if ($n < 2) {
            return null;
        }
        $strongSet = array_flip(array_merge($anchor, $lexicon));
        $anchorSet = array_flip($anchor);
        $isLine = fn (string $t): bool => isset($strongSet[$t]) || in_array($t, Normalizer::LINE_MODIFIERS, true)
            || in_array($t, self::LINE_GLUE, true) || (bool) preg_match('/\d/', $t);
        $i = null;
        foreach ($head as $k => $t) {
            if (isset($strongSet[$t])) {
                $i = $k;
                break;
            }
        }
        if ($i === null) {
            return null;
        }
        // backwards only over brand/lexicon words ("SKE Crystal Original"): a modifier before the line belongs to
        // the flavour ("Melon XL Riot Squad")
        $j = $i;
        while ($j > 0 && isset($strongSet[$head[$j - 1]])) {
            $j--;
        }
        $k = $i;
        while ($k + 1 < $n && $isLine($head[$k + 1])) {
            $k++;
        }
        if ($j === 0) {
            $line = array_slice($head, 0, $k + 1);
            $flav = array_slice($head, $k + 1);
            $pattern = 'line_first';
        } else {
            $line = array_slice($head, $j, $k - $j + 1);
            $flav = array_merge(array_slice($head, 0, $j), array_slice($head, $k + 1));
            $pattern = 'flavour_first';
        }
        $flav = array_values(array_filter($flav, fn ($t) => !preg_match('/\d/', $t) && !isset($anchorSet[$t])
            && !in_array($t, Normalizer::FLAVOUR_NOISE, true)));
        if ($flav === []) {
            return null;
        }
        return ['flavour' => $flav, 'line' => $line, 'pattern' => $pattern];
    }
}
