<?php

declare(strict_types=1);

namespace CW\Tests\Unit;

use CW\Ui\Assets;
use CW\Ui\Controller\ItemController;
use CW\Ui\View;
use CW\Ui\Words;
use PHPUnit\Framework\TestCase;

/**
 * The item page, stock first (docs/decisions.md IV1-IV12): "Sells a week" and how long it lasts, the words and colours of a movement's
 * solid cell and of a store's chip, the tabs and the notices that open them, and the CSS and markup the approved design needs (the strip,
 * the panels that open without the script, the bar at the bottom of a phone).
 */
final class ItemPageUnitTest extends TestCase
{
    public function testSellsAWeekAndHowLongTheFreeStockLasts(): void
    {
        $cases = [
            ['3.1429', 157, ['22', Words::say('ITEM', 'lasts_many', 7)]],   // 22.0003 a week: 157 free lasts 7 whole weeks
            ['0.0000', 50, ['0', '']],                                        // nothing sells: no "lasts"
            ['0.0500', 0, [Words::ITEM['weekly_less'], '']],                  // under half a unit a week; nothing free: no "lasts"
            ['0.0100', 5, [Words::ITEM['weekly_less'], Words::ITEM['lasts_year']]],
            ['0.5000', 5, ['4', Words::ITEM['lasts_one']]],                   // 3.5 rounds half up to 4; 5 / 3.5 = 1.4 weeks
            ['2.0000', 5, ['14', Words::ITEM['lasts_less']]],
            ['1.0000', 364, ['7', Words::ITEM['lasts_year']]],               // 52 weeks
            ['1.0000', 357, ['7', Words::say('ITEM', 'lasts_many', 51)]],
            ['100.0000', 1_400_000, ['700', Words::ITEM['lasts_year']]],
            ['1.0000', -3, ['7', '']],                                        // oversold: nothing free
        ];
        foreach ($cases as [$rate, $free, $want]) {
            self::assertSame(['n' => $want[0], 'lasts' => $want[1]], ItemController::weekly($rate, $free), "{$rate} a day, {$free} free");
        }
        self::assertSame('1,050', ItemController::weekly('150', 0)['n'], 'thousands separators');
    }

    public function testEveryMovementHasItsShortWordAndColour(): void
    {
        foreach (array_keys(Words::MOVEMENT) as $type) {
            self::assertTrue(Words::has('MOVE_KIND', $type), "{$type}: a short word for the solid cell");
            self::assertArrayHasKey($type, Words::TONE['MOVE_KIND'], "{$type}: a colour of its own");
            self::assertLessThanOrEqual(16, mb_strlen(Words::MOVE_KIND[$type]), "{$type}: short enough for the cell");
        }
        self::assertSame([], array_values(array_diff(array_keys(Words::MOVE_KIND), array_keys(Words::MOVEMENT))), 'no word for a movement that does not exist');
        // The approved design: a count purple, goods in green, a sale blue.
        self::assertSame(['review', 'done', 'info'], [Words::tone('MOVE_KIND', 'count'), Words::tone('MOVE_KIND', 'goods_in'), Words::tone('MOVE_KIND', 'commit')]);
    }

    public function testEveryStoreStateHasItsWordsAndColour(): void
    {
        self::assertSame(array_keys(Words::ITEM_SELLS), array_keys(Words::TONE['ITEM_SELLS']));
        self::assertSame(['done', 'waiting'], [Words::tone('ITEM_SELLS', 'on_sale'), Words::tone('ITEM_SELLS', 'not_linked')], 'the design: on sale green, not linked grey');
        self::assertSame('Vape and Go · matched, Draft', Words::say('ITEM', 'sells_chip', 'Vape and Go', Words::say('ITEM_SELLS', 'not_on_sale', 'Draft')));
    }

    public function testEveryTabHasItsWordAndEveryNoticeOpensOne(): void
    {
        self::assertSame(['details', 'barcodes', 'packs', 'stores', 'history'], ItemController::TABS);
        foreach (ItemController::TABS as $tab) {
            self::assertTrue(Words::has('ITEM', 'tab_' . $tab), $tab);
        }
        foreach (ItemController::NOTICE_TABS as $notice => $tab) {
            self::assertContains($tab, ItemController::TABS, $notice);
            self::assertTrue(isset(Words::CARD_NOTICE[$notice]) || isset(Words::SELLING_NOTICE[$notice]) || isset(Words::ITEMS_NOTICE[$notice]), "{$notice}: a notice of the page");
        }
        foreach (array_keys(Words::CARD_NOTICE) as $notice) {
            if (str_starts_with($notice, 'barcode_') || str_starts_with($notice, 'units_')) {
                self::assertSame('barcodes', ItemController::NOTICE_TABS[$notice] ?? null, "{$notice}: the barcodes' tab");
            }
        }
    }

    public function testTheCssAndMarkupOfTheApprovedDesign(): void
    {
        $css = (string) file_get_contents(Assets::dir() . '/app.css');
        foreach (['/^\.it-panel \{ display: none; \}/m' => 'a closed part takes no room',
            '/^\.it-panel\.open, \.it-panel:target, \.it-panel:has\(:target\) \{ display: block; \}/m' => 'the open part, and one a #fragment points into (no script)',
            '/^\.it-panels:has\(:target\) > \.it-panel\.open:not\(:target\):not\(:has\(:target\)\) \{ display: none; \}/m' => 'one part at a time',
            '/@media \(min-width: 640px\) \{ \.item-strip \{ grid-template-columns: none; grid-auto-flow: column;/' => 'the strip in one row',
            '/\.item-head \.ph-actions > \.item-bar \{\s*position: fixed;[^}]*bottom: 0;/' => 'phone: the actions at the bottom of the screen',
            '/body:has\(\.item-bar\) \.tabbar \{ display: none; \}/' => 'phone: in the tab bar\'s place',
            '/\.item-bar > details\.more > \.more-menu \{\s*position: absolute; top: auto; bottom: calc\(100% \+ 6px\);/' => 'phone: More opens upwards'] as $rule => $why) {
            self::assertSame(1, preg_match($rule, $css), $why);
        }
        $src = (string) file_get_contents(View::defaultDir() . '/item.php');
        self::assertStringContainsString('<nav class="tabs item-tabs"', $src, 'the ONE tabs component (DS2)');
        self::assertStringContainsString('<a class="tab"<?php if ($tab === $t): ?> aria-current="page"<?php else: ?> href="', $src, 'the open tab has no link (CV13)');
        self::assertStringContainsString('class="c-status doc-st <?= $e($r[\'tone\']) ?>"', $src, 'the movements\' solid cells');
        foreach (['div', 'details', 'form', 'section', 'table', 'tr', 'p', 'nav'] as $tag) {
            self::assertSame(preg_match_all('/<' . $tag . '\b/', $src), substr_count($src, '</' . $tag . '>'), "item: every <{$tag}> closed");
        }
    }
}
