<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Catalogue\ItemNames;
use CW\CwException;
use CW\Ui\Controller\ItemCardsController;
use CW\Ui\Controller\ItemController;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * An item's own name (docs/decisions.md IR1-IR3): what a person may type as a CW name (trimmed, spaces made single, never empty, at most
 * the column's 255 characters, plain text on one line: no control or hidden characters), and the words the page says for each refusal and
 * each answer.
 */
final class ItemNameRulesTest extends TestCase
{
    public function testANameIsTrimmedAndItsSpacesMadeSingle(): void
    {
        self::assertSame('Elux Legend Blue Razz 20mg', ItemNames::clean('  Elux   Legend  Blue Razz 20mg  '));
        self::assertSame('Elux Legend', ItemNames::clean("\tElux Legend\n"), 'a tab or line break at either end is trimmed');
        self::assertSame('Elux Legend', ItemNames::clean("Elux\u{00A0}\u{00A0}Legend"), 'a no-break space is a space');
        self::assertSame('Elux Legend', ItemNames::clean("Elux\u{2028}Legend"), 'a Unicode line separator is a space');
        self::assertSame('IVG 2400 – Cola & Lime (10ml) 20mg/ml', ItemNames::clean('IVG 2400 – Cola & Lime (10ml) 20mg/ml'), 'case and punctuation kept');
        self::assertSame('ÉLUX 🍓 50%', ItemNames::clean('ÉLUX 🍓 50%'));
        self::assertSame(255, ItemNames::NAME_MAX, 'sku.name is VARCHAR(255)');
        $max = str_repeat('é', 254) . '🍓';
        self::assertSame($max, ItemNames::clean($max), '255 characters (not bytes) are allowed');
    }

    public function testEmptyTooLongAndHiddenCharactersAreRefused(): void
    {
        foreach (['', '   ', "\t \n", "\u{00A0}"] as $raw) {
            self::assertSame([422, 'name_empty'], self::refusal($raw), json_encode($raw));
        }
        $e = self::refused(str_repeat('a', 256));
        self::assertSame([422, 'name_too_long', ['max' => 255, 'length' => 256]], [$e->httpStatus, $e->errorCode, $e->detail]);
        self::assertSame([422, 'name_too_long'], self::refusal(str_repeat('é', 255) . 'x'));
        self::assertSame('a', ItemNames::clean('a'), 'one character is a name');
        foreach (["Elux\tLegend", "Elux\x01Legend", "Elux\x7FLegend", "Elux\u{0085}Legend", "Elux\u{200B}Legend", "Elux\u{202E}dnegeL", "\u{FEFF}Elux",
            "Elux\u{00AD}Legend", "Elux\xC3\x28Legend", "El\x00ux"] as $raw) {
            self::assertSame([422, 'name_not_plain'], self::refusal($raw), bin2hex($raw));
        }
    }

    public function testEveryRefusalAndAnswerHasItsWords(): void
    {
        foreach (['name_empty', 'name_not_plain'] as $code) {
            $text = ItemCardsController::plain(new CwException($code, 'service words', 422));
            self::assertSame(Words::CARD_ERROR[$code], $text, $code);
            self::assertMatchesRegularExpression('/Nothing was changed|Not saved/', $text, "{$code}: says nothing was saved");
        }
        self::assertSame(Words::say('CARD_ERROR', 'name_too_long', 255, 300),
            ItemCardsController::plain(new CwException('name_too_long', 'x', 422, ['max' => 255, 'length' => 300])));
        self::assertStringContainsString('300', Words::say('CARD_ERROR', 'name_too_long', 255, 300));
        $stale = ItemCardsController::plain(new CwException('name_changed', 'x', 409, ['name' => 'Elux Legend Cola']));
        self::assertSame(Words::say('CARD_ERROR', 'name_changed', 'Elux Legend Cola'), $stale);
        self::assertStringContainsString('"Elux Legend Cola"', $stale, 'the 409 names the name it has now');
        foreach (['name_saved', 'name_unchanged'] as $notice) {
            self::assertArrayHasKey($notice, ItemController::NOTICES, $notice);
        }
        self::assertStringContainsString('websites', Words::CARD_NOTICE['name_saved'], 'the websites keep their names: said where it happens');
        foreach (['title', 'change', 'new', 'hint', 'save', 'h_renamed'] as $k) {
            self::assertNotSame('', Words::of('ITEM_NAME', $k), $k);
        }
        self::assertSame('Save the name', Words::ITEM_NAME['save']);
        self::assertSame('name changed from "A" to "B"', Words::say('ITEM_NAME', 'h_renamed', 'A', 'B'));
        self::assertSame('Was called: Old', Words::say('SEARCH', 'was_called', 'Old'));
    }

    /** @return array{0: int, 1: string} the refusal's status and code */
    private static function refusal(string $raw): array
    {
        $e = self::refused($raw);
        return [$e->httpStatus, $e->errorCode];
    }

    private static function refused(string $raw): CwException
    {
        try {
            ItemNames::clean($raw);
        } catch (CwException $e) {
            return $e;
        }
        self::fail('expected a refusal of ' . bin2hex($raw));
    }
}
