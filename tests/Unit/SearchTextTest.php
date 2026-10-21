<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ui\SearchText;
use PHPUnit\Framework\TestCase;

/**
 * The one reading of a product search (docs/decisions.md SX1): any word order, every word must match; spaces, hyphens and "&"
 * left out of the comparison on both sides; a number and its unit, and "R and M", read as one word; a CW number however it is
 * written; a code (an option number or a barcode, with its leading-zero forms) for the indexes.
 */
final class SearchTextTest extends TestCase
{
    public function testWordsInAnyOrderLongestFirstOnceEachAtMostSix(): void
    {
        self::assertSame(['strawberry', 'hayati', 'kiwi'], SearchText::parse('  Hayati  Strawberry KIWI ')->words);
        self::assertSame(['strawberry', 'hayati', 'kiwi'], SearchText::parse('kiwi strawberry hayati')->words, 'the order typed does not matter');
        self::assertSame(['mint'], SearchText::parse('mint MINT Mint')->words);
        self::assertCount(SearchText::MAX_WORDS, SearchText::parse('one two three four five six seven eight')->words);
        // a no-break or full-width space, a comma, a slash, a bar and brackets separate words too
        self::assertSame(['peach', 'white', '20mg'], SearchText::parse("20mg\u{00A0}|\u{3000}White/Peach,")->words);
        self::assertSame(['pack', 'pods', 'of', '3'], SearchText::parse('Pods (Pack of 3)')->words);
    }

    public function testSpacesHyphensAndTheAmpersandAreLeftOutOfTheComparison(): void
    {
        foreach (['R&M tornado', 'R and M tornado', 'RandM tornado', 'r & m TORNADO', 'R&M Tornado'] as $typed) {
            self::assertSame(['tornado', 'randm'], SearchText::parse($typed)->words, $typed);
        }
        self::assertSame(['promax'], SearchText::parse('Pro-Max')->words);
        self::assertSame(['elfbar'], SearchText::parse('ElfBar')->words);
        self::assertSame(['bar', 'elf'], SearchText::parse('elf bar')->words, 'split words stay two words: each is found in "Elfbar" too');
        // a lone "and" / "&" and punctuation alone are no word; long names joined by "and" stay apart
        self::assertSame(['strawberry', 'kiwi'], SearchText::parse('strawberry and kiwi')->words);
        self::assertSame(['strawberry', 'kiwi'], SearchText::parse('strawberry & kiwi')->words);
        self::assertSame([], SearchText::parse('- & , ( ) |')->words);
        self::assertFalse(SearchText::parse('- & , ( ) |')->hasSomething());
        self::assertSame('randmxy', SearchText::norm('R & M-X Y'));
        self::assertSame("REPLACE(REPLACE(lp.brand, ' ', ''), '-', '')", SearchText::sql('lp.brand'));
        // "&" stays in the text the statement compares, so a word of two short names is asked in both spellings
        $rm = SearchText::parse('R and M');
        self::assertSame(['%randm%', '%r&m%'], $rm->patterns(0));
        self::assertSame(['%saltandpepper%', '%salt&pepper%'], SearchText::parse('salt&pepper')->patterns(0));
        self::assertSame(['%tornado%'], SearchText::parse('tornado')->patterns(0));
    }

    public function testANumberAndItsUnitAreOneWord(): void
    {
        foreach (['20 mg' => '20mg', '20mg' => '20mg', '10 ml' => '10ml', '0.8 ohm' => '0.8ohm', '7000 puffs' => '7000puffs', '30 k' => '30k'] as $typed => $word) {
            self::assertSame([$word], SearchText::parse($typed)->words, $typed);
        }
        $s = SearchText::parse('tahiti 20 mg');
        self::assertSame(['tahiti', '20mg'], $s->words);
        self::assertSame(['tahiti', '20 mg'], $s->typed, 'the word as typed, for the attributes and features of the quick match');
        // a number before a word that is no unit stays apart
        self::assertSame(['strawberry', '600'], SearchText::parse('600 strawberry')->words);
    }

    /**
     * What the old searches found is still found: every word of a phrase of the text is found in that text the way the statement
     * compares it (a plain word in the text as it is, any other in the text with its spaces and hyphens left out, in one of its
     * patterns), whatever phrase of up to three of the text's words is typed.
     */
    public function testTheComparisonOnlyAddsToWhatAWordFoundBefore(): void
    {
        $texts = ['Hayati Pro Max Plus 6000 Prefilled Pod Kit - Strawberry Kiwi', 'R&M Tornado 7000', 'Elf-Bar 600 | 20 mg', 'Crème Brûlée (Pack of 3)',
            'R & M Tornado', 'Salt & Pepper Ice'];
        foreach ($texts as $text) {
            $raw = mb_strtolower($text);
            $norm = str_replace([' ', '-'], '', $raw); // SearchText::sql()
            $tokens = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                for ($len = 1; $len <= 3 && $i + $len <= $n; $len++) {
                    $phrase = implode(' ', array_slice($tokens, $i, $len));
                    $s = SearchText::parse($phrase);
                    foreach (array_keys($s->words) as $k) {
                        $found = false;
                        $asIs = $s->plain($k) || $s->lettersOnly($k); // kinds 1 and 2: in the text as it is (kind 2 also in the brand)
                        foreach ($asIs ? ['%' . $s->words[$k] . '%'] : $s->patterns($k) as $pattern) {
                            $found = $found || str_contains($asIs ? $raw : $norm, stripslashes(trim($pattern, '%')));
                        }
                        self::assertTrue($found, "\"{$s->words[$k]}\" of \"{$phrase}\" in \"{$text}\"");
                    }
                }
            }
        }
    }

    public function testTheCheapestWordsAreAskedFirstAndAJoinedWordIsLookedForInTheBrand(): void
    {
        $s = SearchText::parse('elfbar kiwi 600 20mg');
        self::assertSame(['elfbar', '20mg', 'kiwi', '600'], $s->words);
        self::assertSame([true, true, true, true], [$s->plain(2), $s->plain(3), $s->lettersOnly(0), !$s->plain(1) && !$s->lettersOnly(1)]);
        self::assertSame([2, 3, 0, 1], $s->order(), 'kiwi and 600 first, then the long word of letters, then the rest');
        [$sql, $params] = $s->allWords('t', 'b');
        self::assertSame("(t LIKE ? AND t LIKE ? AND (t LIKE ? OR REPLACE(REPLACE(b, ' ', ''), '-', '') LIKE ?) AND REPLACE(REPLACE(t, ' ', ''), '-', '') LIKE ?)", $sql);
        self::assertSame(['%kiwi%', '%600%', '%elfbar%', '%elfbar%', '%20mg%'], $params);
        self::assertFalse(SearchText::parse('randm')->lettersOnly(0), '"randm" is two short names: both spellings, in the whole text');
        // without a brand column a long word of letters is compared with the whole text without its spaces and hyphens
        self::assertSame("REPLACE(REPLACE(t, ' ', ''), '-', '') LIKE ?", $s->wordSql(0, 't')[0]);
    }

    public function testACwNumberHoweverItIsWritten(): void
    {
        foreach (['CW-003906', 'cw 3906', 'CW3906', 'cw-3906', '#3906', 'CW: 003906'] as $typed) {
            $s = SearchText::parse($typed);
            self::assertSame(3906, $s->cwId(), $typed);
            self::assertTrue($s->explicitCw(), $typed);
            self::assertSame(3906, $s->cwId(0), "{$typed}: written as a CW number, whatever the caller takes bare");
        }
        $bare = SearchText::parse('003906');
        self::assertFalse($bare->explicitCw());
        self::assertSame(3906, $bare->cwId(6));
        self::assertNull($bare->cwId(0));
        self::assertNull(SearchText::parse('1234567')->cwId(6), 'a bare number of 7 digits is no CW number for a list');
        self::assertSame(1234567, SearchText::parse('1234567')->cwId());
        foreach (['cw 0', '3906a', 'cw 39 06', 'CW-'] as $typed) {
            self::assertNull(SearchText::parse($typed)->cwId(), $typed);
        }
    }

    public function testACodeIsTheWholeTextWithADigitAndNoSpaceWithItsBarcodeForms(): void
    {
        $codes = SearchText::parse('5012345678900')->codes;
        self::assertContains('5012345678900', $codes);
        self::assertContains('05012345678900', $codes, 'the same barcode with a leading zero');
        self::assertContains('501234567890', SearchText::parse('0501234567890')->codes, 'and without it');
        self::assertSame(['ABC-123'], SearchText::parse('ABC-123')->codes, 'a code with letters is looked up as it is, never by its digits');
        self::assertSame([], SearchText::parse('elf bar')->codes);
        self::assertSame([], SearchText::parse('hayati')->codes, 'no digit: no code');
        self::assertSame([], SearchText::parse('20 mg')->codes, 'a space: no code');
        self::assertSame([], SearchText::parse(str_repeat('1', 65))->codes, 'longer than a barcode can be');
        self::assertTrue(SearchText::parse('5012345678900')->digitsOnly());
        self::assertFalse(SearchText::parse('CW-1')->digitsOnly());
    }

    public function testTheStatementPartAsksEveryWordWithItsWildcardsEscaped(): void
    {
        [$sql, $params] = SearchText::parse('R&M 50%_off')->allWords("CONCAT_WS(' ', a, b)");
        self::assertSame(3, substr_count($sql, 'LIKE ?'), 'the two spellings of "R&M" and the other word');
        self::assertStringContainsString(' AND ', $sql);
        self::assertSame(['%50\\%\\_off%', '%randm%', '%r&m%'], $params);
        self::assertSame(['', []], SearchText::parse('&')->allWords('x'));
        // bytes that are not UTF-8 hold no word and break nothing
        $bad = SearchText::parse("\xff\xfe");
        self::assertSame([], $bad->words);
        self::assertFalse($bad->hasSomething());
        self::assertTrue(SearchText::parse('')->isEmpty());
    }
}
